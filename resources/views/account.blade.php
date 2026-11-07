@php
    $user = auth()->user();
    $oidc = config('login.oidc.enabled');
    $linked = $user->isLinkedToOidc();
    // Unlinking and deleting a linked account need a recent OIDC login (App\Auth\OidcAccount).
    $confirmed = $linked && app(App\Auth\OidcAccount::class)->hasRecentSignIn();
@endphp
<x-guest-layout :title="__('Account')">
    <p class="mb-6 text-sm text-stone-600">{{ __('Logged in as :email', ['email' => $user->email]) }}</p>

    @if ($oidc || $linked)
        @php($provider = App\Auth\OidcLabel::get())
        <section id="oidc" class="mb-10">
            <h2 class="mb-2 text-lg font-semibold">{{ $provider }}</h2>
            @if ($linked)
                {{-- Linked accounts log in with the provider only: no password, no passkeys. --}}
                <p class="mb-1 font-medium">{{ __('Connected with :provider', ['provider' => $provider]) }}</p>
                <p class="mb-4 text-sm text-stone-600">{{ __('Signing in with a password is turned off for this account.') }}</p>

                @if ($oidc)
                    @if ($errors->unlinkOidc->any())
                        <ul class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">
                            @foreach ($errors->unlinkOidc->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($confirmed)
                        <p class="mb-4 text-sm text-stone-600">{{ __('Choose a password. You then sign in with your e-mail address and this password instead of :provider.', ['provider' => $provider]) }}</p>
                        <form method="POST" action="{{ route('oidc.unlink') }}">
                            @csrf
                            @method('DELETE')
                            <input type="text" name="email" value="{{ $user->email }}" autocomplete="username" hidden>
                            <x-input :label="__('New Password')" name="password" type="password" required autocomplete="new-password" />
                            <x-input :label="__('Confirm Password')" name="password_confirmation" type="password" required autocomplete="new-password" />
                            <button type="submit" class="button-secondary w-full">{{ __('Unlink :provider', ['provider' => $provider]) }}</button>
                        </form>
                    @else
                        <p class="mb-4 text-sm text-stone-600">{{ __('For your security, confirm with :provider first. You then choose a new password.', ['provider' => $provider]) }}</p>
                        <a href="{{ route('oidc.redirect', ['confirm' => 1]) }}" class="button-secondary flex w-full items-center justify-center">
                            {{ __('Unlink :provider', ['provider' => $provider]) }}
                        </a>
                    @endif
                @endif
            @else
                <p class="mb-4 text-sm text-stone-600">{{ __('Link your account to log in with :provider.', ['provider' => $provider]) }}
                    {{ __('You then sign in with :provider only: your password and passkeys are removed and your other devices are signed out.', ['provider' => $provider]) }}</p>

                @if ($errors->linkOidc->any())
                    <ul class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">
                        @foreach ($errors->linkOidc->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif

                {{-- Linking removes the password and the passkeys, so it needs the current password (accounts without one skip it). --}}
                <form method="POST" action="{{ route('oidc.link') }}">
                    @csrf
                    @if ($user->password !== null)
                        <input type="text" name="email" value="{{ $user->email }}" autocomplete="username" hidden>
                        <x-input :label="__('Current Password')" name="password" type="password" required autocomplete="current-password" />
                    @endif
                    <button type="submit" class="button-secondary w-full">{{ __('Link :provider', ['provider' => $provider]) }}</button>
                </form>
                <x-oidc-signup class="mt-3" />
            @endif
        </section>
    @endif

    <section class="mb-10">
        <h2 class="mb-2 text-lg font-semibold">{{ $linked ? __('Name') : __('Name and email') }}</h2>
        <p class="mb-4 text-sm text-stone-600">{{ __('The members of your lists see your name (otherwise your email address).') }}</p>

        @if ($errors->updateProfileInformation->any())
            <ul class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">
                @foreach ($errors->updateProfileInformation->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        @if (session('status') === 'profile-information-updated')
            <p class="mb-4 rounded-lg bg-green-100 px-4 py-3 text-sm text-green-800">{{ __('Saved.') }}</p>
        @endif

        <form method="POST" action="{{ route('user-profile-information.update') }}">
            @csrf
            @method('PUT')
            <x-input :label="__('Name')" name="name" :value="old('name', $user->name)" autocomplete="name" maxlength="255" />
            @if ($linked)
                {{-- The address of the provider login (UpdateUserProfileInformation keeps it). --}}
                <label class="mb-4 block">
                    <span class="label">{{ __('Email') }}</span>
                    <input type="email" name="email" value="{{ $user->email }}" readonly class="field w-full bg-stone-100 text-stone-600">
                    <span class="mt-1 block text-sm text-stone-600">{{ __('To change it, unlink :provider first.', ['provider' => $provider]) }}</span>
                </label>
            @else
                <x-input :label="__('Email')" name="email" type="email" :value="old('email', $user->email)" required autocomplete="email" />
            @endif
            <x-button>{{ __('Save') }}</x-button>
        </form>
    </section>

    {{-- Linked accounts have neither a password nor passkeys. --}}
    @if (! $linked && $user->password === null)
        <section class="mb-10">
            <h2 class="mb-2 text-lg font-semibold">{{ __('Password') }}</h2>
            <p class="text-sm text-stone-600">{{ __('Your account has no password yet. You can set one with "Forgot your password?" on the login page.') }}</p>
        </section>
    @elseif (! $linked)
    <section class="mb-10">
        <h2 class="mb-4 text-lg font-semibold">{{ __('Change password') }}</h2>

        @if ($errors->updatePassword->any())
            <ul class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">
                @foreach ($errors->updatePassword->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('user-password.update') }}">
            @csrf
            @method('PUT')
            <input type="text" name="email" value="{{ $user->email }}" autocomplete="username" hidden>
            <x-input :label="__('Current Password')" name="current_password" type="password" required autocomplete="current-password" />
            <x-input :label="__('New Password')" name="password" type="password" required autocomplete="new-password" />
            <x-input :label="__('Confirm Password')" name="password_confirmation" type="password" required autocomplete="new-password" />
            <x-button>{{ __('Change password') }}</x-button>
        </form>
    </section>

    <section class="mb-10">
        <h2 class="mb-2 text-lg font-semibold">{{ __('Passkeys') }}</h2>
        <p class="mb-4 text-sm text-stone-600">{{ __('Log in with Face ID, Touch ID, Windows Hello or your device PIN instead of a password.') }}</p>
        <a href="{{ route('account.passkeys') }}" class="button-secondary flex w-full items-center justify-center gap-2">
            <x-passkey-icon />
            {{ trans_choice('{0} Set up passkey|{1} Manage passkey (:count)|[2,*] Manage passkeys (:count)', $passkeyCount, ['count' => $passkeyCount]) }}
        </a>
    </section>
    @endif

    <section id="delete-account" class="mb-10">
        <h2 class="mb-2 text-lg font-semibold text-red-800">{{ __('Delete account') }}</h2>
        <p class="mb-4 text-sm text-stone-600">{{ __('Your own lists are deleted for all members, you leave the shared ones. Points you made in other lists stay there without your name.') }}</p>

        @if ($errors->deleteAccount->any())
            <ul class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">
                @foreach ($errors->deleteAccount->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @if ($linked && $confirmed)
            {{-- Without a password a recent confirmation with the provider counts (AccountController::destroy). --}}
            <form method="POST" action="{{ route('account.destroy') }}" onsubmit="return confirm(@js(__('Delete your account for good?')))">
                @csrf
                @method('DELETE')
                <button class="w-full rounded-lg bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800">{{ __('Delete account') }}</button>
            </form>
        @elseif ($linked && $oidc)
            <p class="mb-4 text-sm text-stone-600">{{ __('For your security, confirm with :provider first.', ['provider' => $provider]) }}</p>
            <a href="{{ route('oidc.redirect', ['confirm' => 1, 'for' => 'delete']) }}" class="button-secondary flex w-full items-center justify-center">
                {{ __('Continue with :provider', ['provider' => $provider]) }}
            </a>
        @elseif ($linked)
            <p class="text-sm text-stone-600">{{ __('Deleting the account needs a confirmation with :provider, which is turned off at the moment.', ['provider' => $provider]) }}</p>
        @elseif ($user->password === null)
            {{-- Deleting needs the password as confirmation. --}}
            <p class="text-sm text-stone-600">{{ __('To delete your account, first set a password with "Forgot your password?" on the login page.') }}</p>
        @else
            <form method="POST" action="{{ route('account.destroy') }}" onsubmit="return confirm(@js(__('Delete your account for good?')))">
                @csrf
                @method('DELETE')
                <x-input :label="__('Current Password')" name="password" type="password" required autocomplete="current-password" />
                <button class="w-full rounded-lg bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800">{{ __('Delete account') }}</button>
            </form>
        @endif
    </section>

    <p class="text-sm"><a class="underline" href="/#/settings">{{ __('Back to the app') }}</a></p>
</x-guest-layout>
