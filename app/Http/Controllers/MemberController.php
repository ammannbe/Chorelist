<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\ListShared;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sharing needs a connection: the server has to look up or invite users.
 */
class MemberController extends Controller
{
    public function store(Request $request, TaskList $list): Response
    {
        abort_unless($list->isOwnedBy($request->user()), 403);

        $email = Str::lower($request->validate([
            'email' => ['required', 'email', 'max:255'],
        ])['email']);

        [$member, $invited] = DB::transaction(function () use ($list, $email, $request) {
            $member = User::firstWhere('email', $email);
            // Unverified accounts get the invitation too: choosing a password proves the address
            // and ends the sessions of whoever registered it.
            $invited = $member === null || ! $member->hasVerifiedEmail();

            // Unknown users get an account and set their password via the invitation link.
            $member ??= User::create([
                'email' => $email,
                'password' => Str::random(64),
                'locale' => $list->owner->locale,
            ]);
            if (! $list->hasMember($member)) {
                $list->addMember($member);
                Activity::record($list, $request->user(), 'member.added', ['email' => $member->email]);
            }

            return [$member, $invited];
        });

        if (! $member->is($request->user())) {
            $member->notify(new ListShared($request->user(), $list, $invited));
        }

        return response()->noContent();
    }

    /**
     * The owner removes a member, or a member leaves the list.
     */
    public function destroy(Request $request, TaskList $list, string $email): Response
    {
        $member = User::where('email', Str::lower($email))->firstOrFail();

        abort_unless($list->isOwnedBy($request->user()) || $member->is($request->user()), 403);
        abort_if($list->isOwnedBy($member), 422, 'The owner cannot be removed.');
        abort_unless($list->hasMember($member), 404);

        DB::transaction(function () use ($list, $member, $request) {
            $list->removeMember($member);
            Activity::record($list, $request->user(), $member->is($request->user()) ? 'member.left' : 'member.removed', ['email' => $member->email]);
        });

        return response()->noContent();
    }
}
