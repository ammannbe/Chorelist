<?php

namespace App\Http\Controllers;

use App\Auth\OidcAccount;
use App\Auth\OidcLabel;
use App\Models\User;
use App\Services\UserDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    /**
     * Deletes the account (App\Services\UserDeletion, also used for user.deleted of the AccountManager).
     * Confirmed with the password, a linked account (no password) with an OIDC login within
     * OidcAccount::SIGN_IN_TIMEOUT (confirmation at the provider from the account page).
     */
    public function destroy(Request $request, OidcAccount $accounts, UserDeletion $deletion): RedirectResponse
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

        $deletion->delete($user);

        return redirect('/?deleted=1');
    }
}
