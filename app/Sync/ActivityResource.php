<?php

namespace App\Sync;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * History of changes, written by the server: pull only.
 */
class ActivityResource extends SyncResource
{
    protected function pullQuery(User $user): Builder
    {
        return Activity::withTrashed()
            ->whereIn('task_list_id', $user->accessibleListIds())
            ->with('user');
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * @param  Activity  $model
     */
    public function document(Model $model, User $user): array
    {
        return [
            'id' => $model->id,
            'task_list_id' => $model->task_list_id,
            'task_id' => $model->task_id,
            'user_email' => $model->user?->email,
            'action' => $model->action,
            'data' => $model->data ?? (object) [],
            'created_at' => $model->created_at->format(Activity::SYNC_DATE_FORMAT),
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed(),
        ];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        return null;
    }
}
