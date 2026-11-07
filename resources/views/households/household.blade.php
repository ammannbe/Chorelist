{{-- One household on the households page: collapsed name, role and member count; opened members, invitations, shared lists and actions. --}}
@php($label = App\Households\AccountManagerLabel::get())
@php($admin = $local && $household->pivot->role === 'admin')
@php($admins = $household->users->where('pivot.role', 'admin')->count())
<details id="household-{{ $household->uuid }}" class="group/details scroll-mt-4 overflow-hidden rounded-lg border border-stone-200 bg-white"{{ $open ? ' open' : '' }}>
    <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 hover:bg-stone-50">
        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
                <span class="truncate font-medium">{{ $household->name }}</span>
                <span class="shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600">{{ $household->pivot->role === 'admin' ? __('Admin') : __('Member') }}</span>
            </span>
            <span class="block text-xs text-stone-500">{{ trans_choice('{1} :count member|[2,*] :count members', $household->users->count(), ['count' => $household->users->count()]) }}</span>
            @if (! $local && $household->account_manager_household_uuid === null)
                <span class="block text-xs text-stone-500">
                    {{ __('Only in this app, not managed in “:name”.', ['name' => $label]) }}
                    @if ($household->account_manager_import_status === 'pending')
                        {{ __('Proposed for “:name”, waiting for confirmation.', ['name' => $label]) }}
                    @elseif ($household->account_manager_import_status === 'rejected')
                        {{ __('Not taken over into “:name”.', ['name' => $label]) }}
                    @endif
                </span>
            @endif
        </span>
        <span class="transition-transform group-open/details:rotate-90" aria-hidden="true">›</span>
    </summary>

    <div class="space-y-6 border-t border-stone-200 p-4">
        <section>
            <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Members') }}</h3>
            <ul class="divide-y divide-stone-200 rounded-lg border border-stone-200">
                @foreach ($household->users as $member)
                    @php($isMe = $member->is(auth()->user()))
                    <li class="px-3 py-2">
                        <span class="block break-all">{{ $member->displayName() }}@if ($isMe) <span class="text-stone-500">({{ __('you') }})</span>@endif</span>
                        <span class="block break-all text-xs text-stone-500">@if ($member->name){{ $member->email }} · @endif{{ $member->pivot->role === 'admin' ? __('Admin') : __('Member') }}</span>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm empty:hidden">
                            @if (! $isMe)
                                @if ($admin)
                                    <form method="POST" action="{{ route('households.members.update', [$household, $member]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="_household" value="{{ $household->uuid }}">
                                        <input type="hidden" name="role" value="{{ $member->pivot->role === 'admin' ? 'member' : 'admin' }}">
                                        <button class="underline">{{ $member->pivot->role === 'admin' ? __('Make member') : __('Make admin') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('households.members.destroy', [$household, $member]) }}"
                                        onsubmit="return confirm(@js(__('Remove :name from the household? They lose access to everything shared with the household, and their own lists are no longer shared with it (the lists stay with them).', ['name' => $member->displayName()])))">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="_household" value="{{ $household->uuid }}">
                                        <button class="text-red-700 underline">{{ __('Remove') }}</button>
                                    </form>
                                @endif
                            @else
                                @if ($admin)
                                    @if ($member->pivot->role === 'admin' && $admins > 1)
                                        <form method="POST" action="{{ route('households.members.update', [$household, $member]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="_household" value="{{ $household->uuid }}">
                                            <input type="hidden" name="role" value="member">
                                            <button class="underline">{{ __('Make member') }}</button>
                                        </form>
                                    @endif
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if (! $local && $household->pending_members_count)
                <p class="mt-2 text-xs text-stone-500">{{ $household->pending_members_count === 1 ? __('1 more member has not used the app yet.') : __(':count more members have not used the app yet.', ['count' => $household->pending_members_count]) }}</p>
            @endif
        </section>

        @if ($admin)
            <section>
                <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Invite') }}</h3>
                @if ($household->invitations->isNotEmpty())
                    <ul class="mb-3 divide-y divide-stone-200 rounded-lg border border-stone-200">
                        @foreach ($household->invitations as $invitation)
                            <li class="flex items-center gap-3 px-3 py-2">
                                <span class="min-w-0 flex-1">
                                    <span class="block break-all">{{ $invitation->email }}</span>
                                    <span class="block text-xs text-stone-500">
                                        {{ $invitation->role === 'admin' ? __('Admin') : __('Member') }} ·
                                        {{ __('Invited, valid until :date', ['date' => $invitation->expires_at->locale(app()->getLocale())->isoFormat('LL')]) }}
                                    </span>
                                </span>
                                <form method="POST" action="{{ route('households.invitations.destroy', [$household, $invitation]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-sm text-red-700 underline">{{ __('Withdraw') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ route('households.invitations.store', $household) }}" class="space-y-2">
                    @csrf
                    <input type="hidden" name="_household" value="{{ $household->uuid }}">
                    <input type="email" name="email" value="{{ $open ? old('email') : '' }}" class="field w-full" required autocomplete="email" placeholder="{{ __('Invite by email') }}" aria-label="{{ __('Invite by email') }}">
                    <div class="flex gap-2">
                        <select name="role" class="field min-w-0 flex-1" aria-label="{{ __('Role') }}">
                            <option value="member" @selected(! $open || old('role') !== 'admin')>{{ __('Member') }}</option>
                            <option value="admin" @selected($open && old('role') === 'admin')>{{ __('Admin') }}</option>
                        </select>
                        <button class="button">{{ __('Invite') }}</button>
                    </div>
                    <p class="text-xs text-stone-500">{{ __('Admins invite and remove members, rename and delete the household.') }}</p>
                </form>
            </section>
        @endif

        <section>
            <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Shared lists') }}</h3>
            @if ($household->taskLists->isNotEmpty())
                <ul class="divide-y divide-stone-200 rounded-lg border border-stone-200">
                    @foreach ($household->taskLists as $list)
                        <li class="px-3 py-2">
                            <a href="/#/list/{{ $list->id }}" class="block truncate underline">{{ trim(($list->icon ?? '').' '.$list->name) }}</a>
                            @if ($list->owner)
                                <span class="block truncate text-xs text-stone-500">{{ __('Shared by :name', ['name' => $list->owner->displayName()]) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-stone-500">{{ __('No list is shared with this household yet. Share one in the settings of the list.') }}</p>
            @endif
        </section>

        @if ($admin)
            <section>
                <h3 class="mb-2 text-sm font-medium text-stone-600">{{ __('Name') }}</h3>
                <form method="POST" action="{{ route('households.update', $household) }}" class="flex gap-2">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_household" value="{{ $household->uuid }}">
                    <input name="name" value="{{ $household->name }}" class="field min-w-0 flex-1" required maxlength="100" aria-label="{{ __('Name') }}">
                    <button class="button-secondary">{{ __('Rename') }}</button>
                </form>
            </section>
        @endif

        @if ($local)
            <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                <form method="POST" action="{{ route('households.members.destroy', [$household, auth()->user()]) }}"
                    onsubmit="return confirm(@js(__('Leave this household? You lose access to everything shared with it, and your own lists are no longer shared with it (the lists stay with you).')))">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="_household" value="{{ $household->uuid }}">
                    <button class="text-red-700 underline">{{ __('Leave household') }}</button>
                </form>
                @if ($admin)
                    <form method="POST" action="{{ route('households.destroy', $household) }}"
                        onsubmit="return confirm(@js(__('Delete this household? The members lose access to the shared lists, the lists stay with their owners.')))">
                        @csrf
                        @method('DELETE')
                        <button class="text-red-700 underline">{{ __('Delete household') }}</button>
                    </form>
                @endif
            </div>
        @endif

        {{-- Mirrored households are managed in the AccountManager. --}}
        @if (! $local && $accountManagerUrl && $household->account_manager_household_uuid !== null)
            <p class="text-sm"><a class="underline" href="{{ $accountManagerUrl }}">{{ __('Manage households in “:name”', ['name' => $label]) }}</a></p>
        @endif
    </div>
</details>
