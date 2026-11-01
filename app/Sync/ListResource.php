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
 * are personal (membership).
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
            'version' => $list->version,
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

        if ($list->trashed() || $this->isConflict($list, $assumed)) {
            return $this->document($membership->setRelation('taskList', $list), $user);
        }

        if (! empty($new['_deleted'])) {
            if ($list->isOwnedBy($user)) {
                $list->version = $this->nextVersion($list, $new);
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

        $membership->update(['position' => $new['position'] ?? null, 'muted' => (bool) ($new['muted'] ?? false)]);

        // Only the owner changes the list itself, members their order and muting. The version
        // follows the client either way, so its next change does not conflict.
        $shared = $this->shared($new);
        $changes = [];
        if ($list->isOwnedBy($user)) {
            $list->fill($shared);
            $changes = $this->changes($list);
        }
        $list->version = $this->nextVersion($list, $new);
        $list->save();
        $list->touchForMembers();
        if ($changes) {
            Activity::record($list, $user, 'list.updated', ['changes' => $changes]);
        }

        // A member's change of the shared fields is not kept: send the list back.
        $kept = $shared == array_intersect_key($list->only(self::SHARED), $shared);

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
