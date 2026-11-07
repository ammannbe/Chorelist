<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Household;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\ListShared;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The owner shares a list with a household they belong to: all its members get access, also
 * the ones joining later. Possible with both household drivers (the app decides what is shared).
 */
class ListHouseholdController extends Controller
{
    public function store(Request $request, TaskList $list): JsonResponse
    {
        $user = $request->user();
        abort_unless($list->isOwnedBy($user), 403);

        $uuid = $request->validate(['household' => ['required', 'uuid']])['household'];
        $household = Household::where('uuid', $uuid)->first();
        abort_unless($household !== null && $household->hasUser($user), 404);

        if ($list->households()->whereKey($household->id)->exists()) {
            return response()->json(['status' => 'shared']);
        }

        $granted = DB::transaction(function () use ($list, $household, $user) {
            $list->households()->attach($household, ['shared_by' => $user->id]);
            Activity::record($list, $user, 'household.shared', ['name' => $household->name]);
            $granted = $list->syncHouseholdMembers();
            $list->touchForMembers();

            return $granted;
        });
        $granted->each(fn (User $member) => $member->notify(new ListShared($user, $list, false)));

        return response()->json(['status' => 'shared']);
    }

    public function destroy(Request $request, TaskList $list, Household $household): Response
    {
        $user = $request->user();
        abort_unless($list->isOwnedBy($user), 403);
        abort_unless($list->households()->whereKey($household->id)->exists(), 404);

        DB::transaction(function () use ($list, $household, $user) {
            $list->households()->detach($household);
            Activity::record($list, $user, 'household.unshared', ['name' => $household->name]);
            $list->syncHouseholdMembers();
            $list->touchForMembers();
        });

        return response()->noContent();
    }
}
