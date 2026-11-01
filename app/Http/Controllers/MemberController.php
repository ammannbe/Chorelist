<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ListInvite;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\ListShared;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sharing needs a connection: the server has to look up or invite users.
 */
class MemberController extends Controller
{
    /**
     * Unknown and unverified addresses are added right away (they get a link to choose a password,
     * nothing of theirs is visible yet). Existing users get a personal invitation and join only
     * after confirming, since the members would see their name and absences.
     */
    public function store(Request $request, TaskList $list): JsonResponse
    {
        abort_unless($list->isOwnedBy($request->user()), 403);

        $email = Str::lower($request->validate([
            'email' => ['required', 'email', 'max:255'],
        ])['email']);

        $member = User::firstWhere('email', $email);
        if ($member !== null && $list->hasMember($member)) {
            return response()->json(['status' => 'member']);
        }

        if ($member?->hasVerifiedEmail()) {
            [, $token] = ListInvite::issue($list, $request->user(), $member);
            Activity::record($list, $request->user(), 'member.invited', ['email' => $member->email]);
            $member->notify(new ListShared($request->user(), $list, false, route('invites.show', $token, false)));

            return response()->json(['status' => 'invited']);
        }

        $member = DB::transaction(function () use ($list, $email, $member, $request) {
            // Unknown users get an account and set their password via the invitation link, which
            // also ends the sessions of whoever registered an unverified address before.
            $member ??= User::create([
                'email' => $email,
                'password' => Str::random(64),
                'locale' => $list->owner->locale,
            ]);
            $list->addMember($member);
            Activity::record($list, $request->user(), 'member.added', ['email' => $member->email]);

            return $member;
        });
        $member->notify(new ListShared($request->user(), $list, true));

        return response()->json(['status' => 'added']);
    }

    /**
     * The owner removes a member, or a member leaves the list.
     */
    public function destroy(Request $request, TaskList $list, string $email): Response
    {
        // Checked before looking up the address, so nobody learns which addresses have an account.
        abort_unless($list->isOwnedBy($request->user()) || Str::lower($email) === $request->user()->email, 403);
        $member = User::where('email', Str::lower($email))->firstOrFail();

        abort_if($list->isOwnedBy($member), 422, 'The owner cannot be removed.');
        abort_unless($list->hasMember($member), 404);

        DB::transaction(function () use ($list, $member, $request) {
            $list->removeMember($member);
            Activity::record($list, $request->user(), $member->is($request->user()) ? 'member.left' : 'member.removed', ['email' => $member->email]);
        });

        return response()->noContent();
    }
}
