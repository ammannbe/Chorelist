<?php

namespace App\Sync;

use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\TaskChanged;
use App\Support\Notify;
use App\Support\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Tasks of the user's lists. Every member may add, change and delete them (the history shows who).
 */
class TaskResource extends SyncResource
{
    protected function pullQuery(User $user): Builder
    {
        return Task::withTrashed()
            ->whereIn('task_list_id', $user->accessibleListIds())
            ->with('creator');
    }

    public function rules(): array
    {
        $required = 'required_unless:*.newDocumentState._deleted,true';

        return [
            'task_list_id' => [$required, 'nullable', 'uuid'],
            'title' => [$required, 'nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'max:16'],
            'category' => ['nullable', 'string', 'max:100'],
            'points' => ['nullable', 'integer', 'min:1', 'max:5'],
            'mode' => [$required, 'nullable', Rule::in(Schedule::MODES)],
            'rrule' => ['nullable', 'string', 'max:255'],
            'starts_on' => [$required, 'nullable', 'date_format:Y-m-d'],
            'window_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'reminders' => ['nullable', 'array', 'max:5'],
            'reminders.*' => ['integer', 'min:0', 'max:60'],
            'remind_after_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'paused_until' => ['nullable', 'date_format:Y-m-d'],
            'archived' => ['nullable', 'boolean'],
            'checklist' => ['nullable', 'array', 'max:30'],
            'checklist.*.id' => ['required', 'string', 'max:36'],
            'checklist.*.text' => ['required', 'string', 'max:255'],
            'checklist.*.done' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  Task  $model
     */
    public function document(Model $model, User $user): array
    {
        return [
            'id' => $model->id,
            'task_list_id' => $model->task_list_id,
            'title' => $model->title,
            'notes' => $model->notes,
            'icon' => $model->icon,
            'category' => $model->category,
            'points' => $model->points,
            'mode' => $model->mode,
            'rrule' => $model->rrule,
            'starts_on' => $model->starts_on->format('Y-m-d'),
            'window_days' => $model->window_days,
            'ends_on' => $model->ends_on?->format('Y-m-d'),
            'reminders' => $model->reminders ?? [],
            'remind_after_days' => $model->remind_after_days,
            'paused_until' => $model->paused_until?->format('Y-m-d'),
            'archived' => $model->archived,
            'checklist' => $model->checklist ?? [],
            'creator_email' => $model->creator?->email,
            'version' => $model->version,
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed(),
        ];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        /** @var Task|null $task */
        $task = $this->findForUpdate(Task::class, $new['id']);
        $listId = $task->task_list_id ?? $new['task_list_id'] ?? null;

        if ($listId === null || ! $this->canAccessList($user, $listId)) {
            return null;
        }

        if ($task === null) {
            if (! empty($new['_deleted'])) {
                return null;
            }

            $task = new Task($this->attributes($new));
            $task->id = $new['id'];
            $task->task_list_id = $listId;
            $task->creator()->associate($user);
            $task->save();
            Activity::record($listId, $user, 'task.created', ['title' => $task->title], $task->id);
            Notify::members($task->taskList, $user, 'task_changed', new TaskChanged($user, $task, 'created'));

            return null;
        }

        if ($task->trashed() || $this->isConflict($task, $assumed)) {
            return $this->document($task, $user);
        }

        $task->version = $this->nextVersion($task, $new);

        if (! empty($new['_deleted'])) {
            $task->save();
            $task->delete();
            Activity::record($listId, $user, 'task.deleted', ['title' => $task->title], $task->id);
            Notify::members($task->taskList, $user, 'task_changed', new TaskChanged($user, $task, 'deleted'));

            return null;
        }

        $task->fill($this->attributes($new));
        $changes = collect($task->getDirty())
            ->only(Task::TRACKED)
            ->map(fn ($value, $field) => [self::plain($task->getOriginal($field)), self::plain($task->{$field})])
            ->reject(fn (array $change) => $change[0] == $change[1])
            ->all();
        $task->save();

        if ($changes) {
            Activity::record($listId, $user, 'task.updated', ['title' => $task->title, 'changes' => $changes], $task->id);
            Notify::members($task->taskList, $user, 'task_changed', new TaskChanged($user, $task, 'updated'));
        }

        return null;
    }

    /**
     * @throws RetryLaterException when the list was created offline and not pushed yet
     */
    private function canAccessList(User $user, string $listId): bool
    {
        if (! TaskList::withTrashed()->whereKey($listId)->exists()) {
            throw new RetryLaterException("Unknown task list {$listId}.");
        }

        return $user->accessibleListIds()->whereKey($listId)->exists();
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function attributes(array $doc): array
    {
        $mode = $doc['mode'];
        $rrule = in_array($mode, ['fixed', 'after_completion'], true) && Schedule::validRule($doc['rrule'] ?? null)
            ? $doc['rrule']
            : ($mode === 'fixed' || $mode === 'after_completion' ? 'FREQ=WEEKLY' : null);

        return [
            'title' => $doc['title'],
            'notes' => $doc['notes'] ?? null,
            'icon' => $doc['icon'] ?? null,
            'category' => $doc['category'] ?? null,
            'points' => (int) ($doc['points'] ?? 1),
            'mode' => $mode,
            'rrule' => $rrule,
            'starts_on' => $doc['starts_on'],
            'window_days' => (int) ($doc['window_days'] ?? 0),
            'ends_on' => $doc['ends_on'] ?? null,
            'reminders' => array_values(array_unique(array_map('intval', $doc['reminders'] ?? []))),
            'remind_after_days' => $doc['remind_after_days'] ?? null,
            'paused_until' => $doc['paused_until'] ?? null,
            'archived' => (bool) ($doc['archived'] ?? false),
            'checklist' => array_map(fn (array $step) => [
                'id' => (string) $step['id'],
                'text' => (string) $step['text'],
                'done' => (bool) ($step['done'] ?? false),
            ], array_values($doc['checklist'] ?? [])),
        ];
    }

    private static function plain(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
