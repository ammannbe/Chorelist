<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\IcalController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// App shell: public and cacheable by the service worker, all data is loaded client side.
Route::view('/', 'app')->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

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
    ])->name('me');
    Route::put('preferences', [PreferenceController::class, 'update'])->name('preferences.update');

    // Server rendered account pages (need a connection).
    Route::get('account', fn (Request $request) => view('account', [
        'passkeyCount' => $request->user()->passkeys()->count(),
    ]))->name('account');
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

        Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push.store');
        Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');

        Route::post('ical', [IcalController::class, 'store'])->name('ical.store');
        Route::delete('ical', [IcalController::class, 'destroy'])->name('ical.destroy');
    });
});
