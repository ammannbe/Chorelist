<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ListInvite;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Invitation links: the owner creates one (or shares the list with an existing user), whoever
 * opens it while logged in sees the list and its owner and joins only after confirming.
 */
class InviteController extends Controller
{
    public function store(Request $request, TaskList $list): JsonResponse
    {
        abort_unless($list->isOwnedBy($request->user()), 403);

        [$invite, $token] = ListInvite::issue($list, $request->user());

        return response()->json([
            'url' => route('invites.show', $token),
            'expires_at' => $invite->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Revokes all open links of the list (personal invitations stay).
     */
    public function destroy(Request $request, TaskList $list): Response
    {
        abort_unless($list->isOwnedBy($request->user()), 403);
        ListInvite::where('task_list_id', $list->id)->whereNull('user_id')->delete();

        return response()->noContent();
    }

    /**
     * Not logged in: login or registration first, then back here (intended URL).
     */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $invite = $this->find($request->user(), $token);
        if ($invite === null) {
            return redirect('/#/invite-invalid');
        }
        if ($invite->taskList->hasMember($request->user())) {
            return redirect("/#/list/{$invite->task_list_id}");
        }

        return view('invite', ['invite' => $invite, 'token' => $token]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invite = $this->find($request->user(), $token);
        if ($invite === null) {
            return redirect('/#/invite-invalid');
        }

        $list = $invite->taskList;
        $user = $request->user();
        DB::transaction(function () use ($invite, $list, $user) {
            if (! $list->hasMember($user)) {
                $list->addMember($user);
                Activity::record($list, $user, 'member.joined', ['email' => $user->email]);
            }
            if ($invite->user_id !== null) {
                $invite->delete();
            }
        });

        return redirect("/#/list/{$list->id}");
    }

    /**
     * Valid invitation for the user: not expired, list not deleted, personal ones only for their user.
     */
    private function find(User $user, string $token): ?ListInvite
    {
        $invite = ListInvite::findByToken($token);

        return $invite !== null && $invite->taskList !== null && ($invite->user_id === null || (int) $invite->user_id === $user->id)
            ? $invite
            : null;
    }
}
