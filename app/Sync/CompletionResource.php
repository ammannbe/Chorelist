<?php

namespace App\Sync;

use App\Models\Activity;
use App\Models\Completion;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCompleted;
use App\Support\Notify;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Done, skipped, postponed and taken back occurrences. Completions of an occurrence have a
 * deterministic id (Completion::occurrenceId), so they are never deleted: taking one back sets
 * the type "undone" and completing it again changes the type back.
 */
class CompletionResource extends SyncResource
{
    protected function pullQuery(User $user): Builder
    {
        return Completion::withTrashed()
            ->whereIn('task_list_id', $user->accessibleListIds())
            ->with(['user', 'enteredBy', 'task']);
    }

    public function rules(): array
    {
        $required = 'required_unless:*.newDocumentState._deleted,true';

        return [
            'task_id' => [$required, 'nullable', 'uuid'],
            'user_email' => ['nullable', 'email', 'max:255'],
            'occurrence' => ['nullable', 'date_format:Y-m-d'],
            'done_on' => [$required, 'nullable', 'date_format:Y-m-d'],
            'type' => [$required, 'nullable', Rule::in(Completion::TYPES)],
            'postponed_to' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  Completion  $model
     */
    public function document(Model $model, User $user): array
    {
        return [
            'id' => $model->id,
            'task_id' => $model->task_id,
            'task_list_id' => $model->task_list_id,
            // Title when the task was deleted meanwhile (history, ranking).
            'task_title' => $model->task?->title,
            'user_email' => $model->user?->email,
            'entered_by_email' => $model->enteredBy?->email,
            'occurrence' => $model->occurrence?->format('Y-m-d'),
            'done_on' => $model->done_on->format('Y-m-d'),
            'type' => $model->type,
            'postponed_to' => $model->postponed_to?->format('Y-m-d'),
            'points' => $model->points,
            'note' => $model->note,
            'version' => $model->version,
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed(),
        ];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        /** @var Completion|null $completion */
        $completion = $this->findForUpdate(Completion::class, $new['id']);
        $taskId = $completion->task_id ?? $new['task_id'] ?? null;
        if ($taskId === null) {
            return null;
        }

        $task = Task::withTrashed()->find($taskId);
        if ($task === null) {
            // Task created offline and not pushed yet.
            throw new RetryLaterException("Unknown task {$taskId}.");
        }
        if (! $user->accessibleListIds()->whereKey($task->task_list_id)->exists()) {
            return null;
        }

        if ($completion === null) {
            if (! empty($new['_deleted']) || ! $this->validId($new)) {
                return null;
            }

            $completion = new Completion;
            $completion->id = $new['id'];
            $completion->task()->associate($task);
            $completion->task_list_id = $task->task_list_id;
            $this->apply($completion, $task, $user, $new);

            return null;
        }

        if ($completion->trashed() || $this->isConflict($completion, $assumed)) {
            return $this->document($completion, $user);
        }

        // Completions are never deleted (a deleted occurrence id could not be completed again),
        // and the points of another member stay theirs.
        if (! empty($new['_deleted']) || ! $this->mayChange($completion, $user)) {
            return $this->document($completion, $user);
        }

        $completion->version = $this->nextVersion($completion, $new);

        $this->apply($completion, $task, $user, $new);

        return null;
    }

    /**
     * A completion with points may only be changed by the member it counts for or the one who
     * entered it. Others may change it once it is taken back, skipped or postponed.
     */
    private function mayChange(Completion $completion, User $user): bool
    {
        return ! in_array($completion->type, Completion::SCORED, true)
            || $completion->user()->is($user)
            || $completion->enteredBy()->is($user);
    }

    private function apply(Completion $completion, Task $task, User $user, array $new): void
    {
        $previous = $completion->exists ? $completion->type : null;
        $credited = $this->creditedUser($task, $user, $new['user_email'] ?? null);

        $completion->fill([
            // The occurrence is part of the id, so it never changes.
            'occurrence' => $completion->exists ? $completion->occurrence : ($new['occurrence'] ?? null),
            'done_on' => $new['done_on'],
            'type' => $new['type'],
            'postponed_to' => $new['type'] === 'postponed' ? ($new['postponed_to'] ?? null) : null,
            // The task's points at the time, so changing them later keeps the ranking.
            'points' => in_array($new['type'], Completion::SCORED, true)
                ? ($previous === $new['type'] && $completion->exists ? $completion->points : $task->points)
                : 0,
            'note' => $new['note'] ?? null,
        ]);
        $completion->user()->associate($credited);
        if ($previous !== $new['type']) {
            $completion->enteredBy()->associate($user);
        }
        $completion->save();

        if ($previous === $new['type']) {
            return;
        }
        if (in_array($new['type'], ['postponed', 'undone'], true) || ($previous !== null && $new['type'] === 'skipped')) {
            Activity::record($task->task_list_id, $user, "completion.{$new['type']}", array_filter([
                'title' => $task->title,
                'occurrence' => $completion->occurrence?->format('Y-m-d'),
                'postponed_to' => $completion->postponed_to?->format('Y-m-d'),
                'previous' => $previous,
            ]), $task->id);
        }
        if (in_array($new['type'], Completion::SCORED, true)) {
            Notify::members($task->taskList, $user, 'completed', new TaskCompleted($credited, $task, $completion->points));
        }
    }

    /**
     * Occurrence completions must have the id derived from task and date (one per occurrence).
     */
    private function validId(array $new): bool
    {
        if (($new['type'] ?? null) === 'extra' || empty($new['occurrence'])) {
            return true;
        }

        return $new['id'] === Completion::occurrenceId($new['task_id'], $new['occurrence']);
    }

    /**
     * Members may enter a completion for another member, otherwise it counts for the user.
     */
    private function creditedUser(Task $task, User $user, ?string $email): User
    {
        if ($email === null || $email === $user->email) {
            return $user;
        }

        $member = User::where('email', $email)->first();

        return $member !== null && $task->taskList()->withTrashed()->first()?->hasMember($member) ? $member : $user;
    }
}
