<?php

namespace App\Http\Controllers;

use App\Auth\OidcAccount;
use App\Auth\OidcLabel;
use App\Models\Household;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    /**
     * Deletes the account: own lists are deleted for all members, shared ones are left.
     * Completions in other lists stay (without the user), so their ranking keeps its history.
     * Confirmed with the password, a linked account (no password) with an OIDC login within
     * OidcAccount::SIGN_IN_TIMEOUT (confirmation at the provider from the account page).
     */
    public function destroy(Request $request, OidcAccount $accounts): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isLinkedToOidc()) {
            $request->validateWithBag('deleteAccount', [
                'password' => ['required', 'current_password'],
            ]);
        } elseif (! $accounts->hasRecentSignIn()) {
            return redirect()->to(route('account').'#delete-account')->withErrors([
                'password' => __('For your security, confirm with :provider first.', ['provider' => OidcLabel::get()]),
            ], 'deleteAccount');
        }

        // Before deleting: logging out saves a new remember token, which would recreate the user.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        DB::transaction(function () use ($user) {
            // Another member takes over as admin, empty households are deleted (mirrored ones: the AccountManager decides).
            $user->households()->get()->each(fn (Household $household) => $household->removeUser($user, handOver: ! $household->isMirrored()));
            TaskList::where('owner_id', $user->id)->get()->each(function (TaskList $list) {
                $list->delete();
                $list->touchForMembers();
            });
            $user->memberships()->get()->each(fn ($membership) => $membership->taskList->removeMember($user));
            $user->delete();
        });

        return redirect('/?deleted=1');
    }
}
