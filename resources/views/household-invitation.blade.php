<x-guest-layout :title="__('Invitation')">
    <p class="mb-2 text-sm">
        {{ __(':name invites you to the household ":household".', ['name' => $invitation->inviter?->displayName() ?? '?', 'household' => $invitation->household->name]) }}
    </p>
    <p class="mb-6 text-sm text-stone-500">
        {{ __('You see the task lists shared with the household. Their members see your name, what you have done and your holidays.') }}
    </p>

    @if (! $invitation->isFor(auth()->user()))
        <p class="notice mb-6">{{ __('This invitation is for :email. Log in with that address.', ['email' => $invitation->email]) }}</p>
    @elseif ($local)
        <form method="POST" action="{{ route('household-invitations.accept-token', $token) }}">
            @csrf
            <x-button>{{ __('Join household') }}</x-button>
        </form>
    @endif

    <p class="mt-6 text-sm">
        <a class="underline" href="{{ route('households.index') }}">{{ __('Not now') }}</a>
    </p>
</x-guest-layout>
