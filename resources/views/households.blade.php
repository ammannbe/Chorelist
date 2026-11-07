<x-guest-layout :title="__('Households')">
    <p class="mb-6 text-sm text-stone-600">{{ __('Share task lists with a household: all its members see them, also the ones joining later. Whoever leaves the household loses access, the lists stay with their owners.') }}</p>

    @unless ($local)
        <p class="notice mb-6">
            {{ __('Households are managed in your account. Here you only see them.') }}
            @if ($kontoUrl)
                <a class="underline" href="{{ $kontoUrl }}">{{ __('Manage households in your account') }}</a>
            @endif
        </p>
    @endunless

    @if ($invitations->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-2 text-lg font-semibold">{{ __('Invitations') }}</h2>
            <ul class="space-y-3">
                @foreach ($invitations as $invitation)
                    <li class="rounded-lg border border-stone-200 bg-white p-4 text-sm">
                        <p class="mb-3">{{ __(':name invites you to the household ":household".', ['name' => $invitation->inviter?->displayName() ?? '?', 'household' => $invitation->household->name]) }}</p>
                        @if ($local)
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('household-invitations.accept', $invitation) }}" class="flex-1">
                                    @csrf
                                    <x-button>{{ __('Join household') }}</x-button>
                                </form>
                                <form method="POST" action="{{ route('household-invitations.decline', $invitation) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="button-secondary">{{ __('Decline') }}</button>
                                </form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @foreach ($households as $household)
        @php($me = $household->users->firstWhere('id', auth()->id()))
        @php($admin = $me?->pivot->role === App\Models\Household::ADMIN)
        <section class="mb-10 space-y-4" id="household-{{ $household->uuid }}">
            <h2 class="text-lg font-semibold">🏠 {{ $household->name }}</h2>

            @if (! $local && $household->konto_household_uuid === null)
                <p class="text-sm text-stone-500">
                    {{ __('Only in this app, not managed in your account.') }}
                    @if ($household->konto_import_status === 'pending')
                        {{ __('Proposed for your account, waiting for confirmation.') }}
                    @elseif ($household->konto_import_status === 'rejected')
                        {{ __('Not taken over into your account.') }}
                    @endif
                </p>
            @endif

            @if ($local && $admin)
                <form method="POST" action="{{ route('households.update', $household) }}" class="flex gap-2">
                    @csrf
                    @method('PUT')
                    <input name="name" value="{{ $household->name }}" class="field min-w-0 flex-1" required maxlength="100" aria-label="{{ __('Name') }}">
                    <button class="button-secondary">{{ __('Rename') }}</button>
                </form>
            @endif

            <div>
                <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Members') }}</h3>
                <ul class="divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white text-sm">
                    @foreach ($household->users as $member)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <span class="min-w-0">
                                <span class="block truncate">{{ $member->displayName() }}</span>
                                @if ($member->name)
                                    <span class="block truncate text-xs text-stone-500">{{ $member->email }}</span>
                                @endif
                            </span>
                            @if ($local && $admin)
                                <span class="flex items-center gap-3">
                                    <form method="POST" action="{{ route('households.members.update', [$household, $member]) }}">
                                        @csrf
                                        @method('PUT')
                                        <select name="role" class="field py-1 text-sm" onchange="this.form.submit()" aria-label="{{ __('Role') }}">
                                            <option value="admin" @selected($member->pivot->role === 'admin')>{{ __('Admin') }}</option>
                                            <option value="member" @selected($member->pivot->role === 'member')>{{ __('Member') }}</option>
                                        </select>
                                        <noscript><button class="underline">{{ __('Save') }}</button></noscript>
                                    </form>
                                    @unless ($member->is(auth()->user()))
                                        <form method="POST" action="{{ route('households.members.destroy', [$household, $member]) }}" onsubmit="return confirm(@js(__('Remove :name from the household? They lose access to everything shared with the household, and their own lists are no longer shared with it (the lists stay with them).', ['name' => $member->displayName()])))">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-red-700 underline">{{ __('Remove') }}</button>
                                        </form>
                                    @endunless
                                </span>
                            @else
                                <span class="text-xs text-stone-500">{{ $member->pivot->role === 'admin' ? __('Admin') : __('Member') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if (! $local && $household->pending_members_count)
                    <p class="mt-2 text-xs text-stone-500">{{ $household->pending_members_count === 1 ? __('1 more member has not used the app yet.') : __(':count more members have not used the app yet.', ['count' => $household->pending_members_count]) }}</p>
                @endif
            </div>

            @if ($local && $admin)
                @if ($household->invitations->isNotEmpty())
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Open invitations') }}</h3>
                        <ul class="divide-y divide-stone-200 rounded-lg border border-stone-200 bg-white text-sm">
                            @foreach ($household->invitations as $invitation)
                                <li class="flex items-center justify-between gap-2 px-3 py-2">
                                    <span class="min-w-0 truncate">{{ $invitation->email }}</span>
                                    <form method="POST" action="{{ route('households.invitations.destroy', [$household, $invitation]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-red-700 underline">{{ __('Revoke') }}</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('households.invitations.store', $household) }}" class="space-y-2">
                    @csrf
                    <div class="flex gap-2">
                        <input type="email" name="email" class="field min-w-0 flex-1" required autocomplete="email" placeholder="{{ __('Invite by email') }}" aria-label="{{ __('Invite by email') }}">
                        <select name="role" class="field" aria-label="{{ __('Role') }}">
                            <option value="member">{{ __('Member') }}</option>
                            <option value="admin">{{ __('Admin') }}</option>
                        </select>
                    </div>
                    <x-button>{{ __('Invite') }}</x-button>
                </form>
            @endif

            <div>
                <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Shared lists') }}</h3>
                @if ($household->taskLists->isEmpty())
                    <p class="text-sm text-stone-500">{{ __('No list is shared with this household yet. Share one in the settings of the list.') }}</p>
                @else
                    <ul class="list-inside list-disc text-sm">
                        @foreach ($household->taskLists->sortBy('name') as $list)
                            <li><a class="underline" href="/#/list/{{ $list->id }}">{{ trim(($list->icon ?? '').' '.$list->name) }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($local)
                <div class="flex flex-wrap gap-4 text-sm">
                    <form method="POST" action="{{ route('households.members.destroy', [$household, auth()->user()]) }}" onsubmit="return confirm(@js(__('Leave this household? You lose access to everything shared with it, and your own lists are no longer shared with it (the lists stay with you).')))">
                        @csrf
                        @method('DELETE')
                        <button class="text-red-700 underline">{{ __('Leave household') }}</button>
                    </form>
                    @if ($admin)
                        <form method="POST" action="{{ route('households.destroy', $household) }}" onsubmit="return confirm(@js(__('Delete this household? The members lose access to the shared lists, the lists stay with their owners.')))">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-700 underline">{{ __('Delete household') }}</button>
                        </form>
                    @endif
                </div>
            @endif
        </section>
    @endforeach

    @if ($local)
        <section class="mb-10">
            <h2 class="mb-2 text-lg font-semibold">{{ __('New household') }}</h2>
            <form method="POST" action="{{ route('households.store') }}">
                @csrf
                <x-input :label="__('Name')" name="name" required maxlength="100" :placeholder="__('e.g. Family Muster')" />
                <x-button>{{ __('Create household') }}</x-button>
            </form>
        </section>
    @endif

    <p class="text-sm"><a class="underline" href="/#/settings">{{ __('Back to the app') }}</a></p>
</x-guest-layout>
