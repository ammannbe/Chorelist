<?php

namespace App\Sync;

use App\Models\Absence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Own holidays, and the ones of the members of shared lists (read only, for the ranking).
 */
class AbsenceResource extends SyncResource
{
    protected function pullQuery(User $user): Builder
    {
        return Absence::withTrashed()
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhereIn('user_id', $user->coMemberIds()))
            ->with('user');
    }

    public function rules(): array
    {
        $required = 'required_unless:*.newDocumentState._deleted,true';

        return [
            'starts_on' => [$required, 'nullable', 'date_format:Y-m-d'],
            'ends_on' => [$required, 'nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  Absence  $model
     */
    public function document(Model $model, User $user): array
    {
        return [
            'id' => $model->id,
            'user_email' => $model->user->email,
            'is_own' => $model->user_id === $user->id,
            'starts_on' => $model->starts_on->format('Y-m-d'),
            'ends_on' => $model->ends_on->format('Y-m-d'),
            'note' => $model->user_id === $user->id ? $model->note : null,
            'version' => $model->version,
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed(),
        ];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        /** @var Absence|null $absence */
        $absence = $this->findForUpdate(Absence::class, $new['id']);

        if ($absence === null) {
            if (empty($new['_deleted'])) {
                $absence = new Absence($this->attributes($new));
                $absence->id = $new['id'];
                $absence->user()->associate($user);
                $absence->save();
            }

            return null;
        }

        if ($absence->user_id !== $user->id || $absence->trashed() || $this->isConflict($absence, $assumed)) {
            return $this->document($absence, $user);
        }

        $absence->version = $this->nextVersion($absence, $new);
        if (! empty($new['_deleted'])) {
            $absence->save();
            $absence->delete();

            return null;
        }
        $absence->fill($this->attributes($new))->save();

        return null;
    }

    private function attributes(array $doc): array
    {
        [$from, $to] = [$doc['starts_on'], $doc['ends_on']];

        return ['starts_on' => min($from, $to), 'ends_on' => max($from, $to), 'note' => $doc['note'] ?? null];
    }
}
