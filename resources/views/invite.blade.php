<x-guest-layout :title="__('Invitation')">
    <p class="mb-2 text-sm">
        {{ __(':name invites you to the task list ":list".', ['name' => $invite->creator?->displayName() ?? '?', 'list' => $invite->taskList->name]) }}
    </p>
    <p class="mb-6 text-sm text-stone-500">
        {{ __('The members see your name, what you have done and your holidays.') }}
    </p>

    <form method="POST" action="{{ route('invites.accept', $token) }}">
        @csrf
        <x-button>{{ __('Join list') }}</x-button>
    </form>

    <p class="mt-6 text-sm">
        <a class="underline" href="/">{{ __('Not now') }}</a>
    </p>
</x-guest-layout>
