<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ListInvite;
use App\Models\TaskList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invitation links: the owner creates one, whoever opens it while logged in joins the list.
 */
class InviteController extends Controller
{
    public function store(Request $request, TaskList $list): JsonResponse
    {
        abort_unless($list->isOwnedBy($request->user()), 403);

        $invite = ListInvite::create([
            'task_list_id' => $list->id,
            'created_by' => $request->user()->id,
            'token' => Str::random(40),
            'expires_at' => now()->addDays(ListInvite::DAYS),
        ]);

        return response()->json([
            'url' => route('invites.show', $invite->token),
            'expires_at' => $invite->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Revokes all open links of the list.
     */
    public function destroy(Request $request, TaskList $list): Response
    {
        abort_unless($list->isOwnedBy($request->user()), 403);
        ListInvite::where('task_list_id', $list->id)->delete();

        return response()->noContent();
    }

    /**
     * Not logged in: login or registration first, then back here (intended URL).
     */
    public function show(Request $request, string $token): RedirectResponse
    {
        $invite = ListInvite::where('token', $token)->where('expires_at', '>', now())->first();
        $list = $invite?->taskList;
        if ($list === null) {
            return redirect('/#/invite-invalid');
        }

        $user = $request->user();
        if (! $list->hasMember($user)) {
            DB::transaction(function () use ($list, $user) {
                $list->addMember($user);
                Activity::record($list, $user, 'member.joined', ['email' => $user->email]);
            });
        }

        return redirect("/#/list/{$list->id}");
    }
}
