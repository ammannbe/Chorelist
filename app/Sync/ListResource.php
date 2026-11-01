<?php

namespace App\Sync;

use App\Models\Activity;
use App\Models\Membership;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Lists are pulled through the user's memberships, so revoked access and deleted lists both
 * arrive as deleted documents. Name, icon and ranking belong to the owner, order and muting
 * are personal (membership). The document's version is the sum of both versions, so personal
 * changes do not conflict with the changes of the others.
 */
class ListResource extends SyncResource
{
    private const SHARED = ['name', 'icon', 'ranking_enabled', 'ranking_period'];

    protected function pullQuery(User $user): Builder
    {
        return Membership::withTrashed()
            ->where('user_id', $user->id)
            ->with(['taskList.owner', 'taskList.memberships.user']);
    }

    protected function checkpointIdColumn(): string
    {
        return 'task_list_id';
    }

    public function rules(): array
    {
        return [
            'name' => ['required_unless:*.newDocumentState._deleted,true', 'nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:16'],
            'ranking_enabled' => ['nullable', 'boolean'],
            'ranking_period' => ['nullable', Rule::in(TaskList::RANKING_PERIODS)],
            'position' => ['nullable', 'numeric'],
            'muted' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  Membership  $model
     */
    public function document(Model $model, User $user): array
    {
        $list = $model->taskList;

        return [
            'id' => $list->id,
            'name' => $list->name,
            'icon' => $list->icon,
            'ranking_enabled' => $list->ranking_enabled,
            'ranking_period' => $list->ranking_period,
            'owner_email' => $list->owner?->email,
            'is_owner' => $list->isOwnedBy($user),
            // All members see each other (ranking, history), the user included.
            'members' => $list->memberships
                ->map(fn (Membership $membership) => ['email' => $membership->user->email, 'name' => $membership->user->name])
                ->sortBy('email')->values()->all(),
            'position' => $model->position,
            'muted' => $model->muted,
            'version' => $list->version + $model->version,
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed() || $list->trashed(),
        ];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        /** @var TaskList|null $list */
        $list = $this->findForUpdate(TaskList::class, $new['id']);

        if ($list === null) {
            if (! empty($new['_deleted'])) {
                return null;
            }

            $list = new TaskList($this->shared($new));
            $list->id = $new['id'];
            $list->owner()->associate($user);
            $list->save();
            $list->addMember($user)->update(['position' => $new['position'] ?? null, 'muted' => (bool) ($new['muted'] ?? false)]);
            Activity::record($list, $user, 'list.created', ['name' => $list->name]);

            return null;
        }

        $membership = Membership::withTrashed()
            ->where('task_list_id', $list->id)
            ->where('user_id', $user->id)
            ->first();

        // No access (anymore): the client removes the list after the next pull.
        if ($membership === null || $membership->trashed()) {
            return null;
        }

        if ($list->trashed()) {
            return $this->document($membership->setRelation('taskList', $list), $user);
        }

        $current = $list->version + $membership->version;
        $conflict = $assumed === null || (int) ($assumed['version'] ?? 0) !== $current;
        $target = max((int) ($new['version'] ?? 0), $current + 1);

        if (! empty($new['_deleted']) && ! $conflict) {
            if ($list->isOwnedBy($user)) {
                $list->version = $target - $membership->version;
                $list->save();
                $list->delete();
                $list->touchForMembers();
            } else {
                // Members cannot delete a list, they leave it.
                $list->removeMember($user);
                Activity::record($list, $user, 'member.left', ['email' => $user->email]);
            }

            return null;
        }

        // Order and muting are the user's own, they are kept even when the list changed meanwhile.
        $personal = ['position' => $new['position'] ?? null, 'muted' => (bool) ($new['muted'] ?? false)];
        $membership->fill($personal);
        $personalChanged = $membership->isDirty();

        // Only the owner changes the list itself, and only without conflict.
        $shared = $this->shared($new);
        $changes = [];
        if ($list->isOwnedBy($user) && ! $conflict) {
            $list->fill($shared);
            $changes = $this->changes($list);
        }

        // The version follows the client (sum of both), so its next change does not conflict.
        // Changes of the shared fields always increase the list's part, which the others see.
        if ($changes) {
            $list->version = max($list->version + 1, $target - $membership->version - ($personalChanged ? 1 : 0));
            $list->save();
            Activity::record($list, $user, 'list.updated', ['changes' => $changes]);
        }
        if (! $conflict) {
            $membership->version = $target - $list->version;
        } elseif ($personalChanged) {
            $membership->version++;
        }
        $membership->save();
        if ($changes) {
            $list->touchForMembers();
        }

        // Not everything was kept (conflict, or a member changed the shared fields): send the list back.
        $kept = ! $conflict && $shared == array_intersect_key($list->only(self::SHARED), $shared);

        return $kept ? null : $this->document($membership->fresh()->setRelation('taskList', $list), $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(array $doc): array
    {
        return [
            'name' => $doc['name'],
            'icon' => $doc['icon'] ?? null,
            'ranking_enabled' => (bool) ($doc['ranking_enabled'] ?? true),
            'ranking_period' => $doc['ranking_period'] ?? 'month',
        ];
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function changes(TaskList $list): array
    {
        return collect($list->getDirty())
            ->only(self::SHARED)
            ->map(fn ($value, $field) => [$list->getOriginal($field), $list->{$field}])
            ->all();
    }
}
