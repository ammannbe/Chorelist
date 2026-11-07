<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\HouseholdInvitationController;
use App\Http\Controllers\IcalController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\ListHouseholdController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SyncController;
use App\Models\Household;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// App shell: public and cacheable by the service worker, all data is loaded client side.
Route::view('/', 'app')->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

// Optional OpenID Connect login (config/login.php).
Route::middleware('throttle:20,1')->group(function () {
    Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->name('oidc.callback');
});

// Calendar subscription, authenticated by the secret token in the URL.
Route::get('ical/{token}.ics', [IcalController::class, 'show'])->middleware('throttle:60,1')->name('ical.show');

Route::middleware('auth')->group(function () {
    Route::get('me', fn (Request $request) => [
        'email' => $request->user()->email,
        'name' => $request->user()->name,
        'verified' => $request->user()->hasVerifiedEmail(),
        'locale' => $request->user()->locale,
        'timezone' => $request->user()->timezone,
        'preferences' => $request->user()->preferences(),
        'notification_kinds' => PreferenceController::NOTIFICATIONS,
        'vapid_public_key' => config('webpush.vapid.public_key'),
        'push_devices' => $request->user()->pushSubscriptions()->count(),
        'ical_url' => IcalController::url($request->user()),
        // For sharing lists with a household, and a hint about open invitations.
        'households' => $request->user()->households()->orderBy('name')->get()
            ->map(fn (Household $household) => ['id' => $household->uuid, 'name' => $household->name, 'role' => $household->pivot->role])->all(),
        'household_invitations' => $request->user()->householdInvitations()->count(),
    ])->name('me');
    Route::put('preferences', [PreferenceController::class, 'update'])->name('preferences.update');

    // Server rendered account pages (need a connection).
    Route::get('account', fn (Request $request) => view('account', [
        'passkeyCount' => $request->user()->passkeys()->count(),
    ]))->name('account');
    Route::delete('account/oidc', [OidcController::class, 'destroy'])->name('oidc.unlink');
    Route::get('account/passkeys', fn (Request $request) => view('account-passkeys', [
        'passkeys' => $request->user()->passkeys()->latest()->get(),
    ]))->middleware('password.confirm')->name('account.passkeys');
    Route::delete('account', [AccountController::class, 'destroy'])->name('account.destroy');

    // Invitation links work before the email address is verified (the link proves the invitation).
    Route::get('invite/{token}', [InviteController::class, 'show'])->name('invites.show');
    Route::post('invite/{token}', [InviteController::class, 'accept'])->middleware('throttle:20,1')->name('invites.accept');

    Route::middleware('verified')->group(function () {
        Route::get('sync/{collection}/pull', [SyncController::class, 'pull'])->name('sync.pull');
        Route::post('sync/{collection}/push', [SyncController::class, 'push'])->name('sync.push');

        Route::post('lists/{list}/members', [MemberController::class, 'store'])->middleware('throttle:20,1')->name('members.store');
        Route::delete('lists/{list}/members/{email}', [MemberController::class, 'destroy'])->name('members.destroy');
        Route::post('lists/{list}/invites', [InviteController::class, 'store'])->middleware('throttle:20,1')->name('invites.store');
        Route::delete('lists/{list}/invites', [InviteController::class, 'destroy'])->name('invites.destroy');
        Route::post('lists/{list}/households', [ListHouseholdController::class, 'store'])->name('list-households.store');
        Route::delete('lists/{list}/households/{household}', [ListHouseholdController::class, 'destroy'])->name('list-households.destroy');

        // Households (server rendered, config/households.php).
        Route::get('households', [HouseholdController::class, 'index'])->name('households.index');
        Route::post('households', [HouseholdController::class, 'store'])->middleware('throttle:20,1')->name('households.store');
        Route::put('households/{household}', [HouseholdController::class, 'update'])->name('households.update');
        Route::delete('households/{household}', [HouseholdController::class, 'destroy'])->name('households.destroy');
        Route::put('households/{household}/members/{member}', [HouseholdController::class, 'updateMember'])->name('households.members.update');
        Route::delete('households/{household}/members/{member}', [HouseholdController::class, 'destroyMember'])->name('households.members.destroy');
        Route::post('households/{household}/invitations', [HouseholdInvitationController::class, 'store'])->middleware('throttle:20,1')->name('households.invitations.store');
        Route::delete('households/{household}/invitations/{invitation}', [HouseholdInvitationController::class, 'destroy'])->name('households.invitations.destroy');
        // Invitations for the user: link in the mail, or from the households page.
        Route::get('household-invitations/{token}', [HouseholdInvitationController::class, 'show'])->name('household-invitations.show');
        Route::post('household-invitations/{token}', [HouseholdInvitationController::class, 'acceptToken'])->middleware('throttle:20,1')->name('household-invitations.accept-token');
        Route::post('household-invitations/{invitation}/accept', [HouseholdInvitationController::class, 'acceptOpen'])->middleware('throttle:20,1')->name('household-invitations.accept');
        Route::delete('household-invitations/{invitation}', [HouseholdInvitationController::class, 'decline'])->name('household-invitations.decline');

        Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push.store');
        Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');

        Route::post('ical', [IcalController::class, 'store'])->name('ical.store');
        Route::delete('ical', [IcalController::class, 'destroy'])->name('ical.destroy');
    });
});
