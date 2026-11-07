{{-- Settings, notifications, holidays and install instructions (part of the app shell, available offline). --}}
<section x-show="route.view === 'settings'" class="space-y-6">
    {{-- Logged in: "Manage account" at the bottom (partials/account.blade.php) --}}
    <div x-show="!loggedIn">
        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Account')"></h2>
        <p class="rounded-lg border border-stone-200 bg-white p-4">
            <span x-text="$t('Log in to sync your lists across devices and share them.')"></span>
            <a href="/login" data-online class="font-medium underline" x-text="$t('Log in')"></a> ·
            <a href="/register" data-online class="font-medium underline" x-text="$t('Register')"></a>
        </p>
    </div>

    <div class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
        {{-- Households page is server rendered (needs a connection) --}}
        <a x-show="loggedIn" href="/households" data-online class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
            <span>
                <span class="block">
                    <span x-text="`🏠 ${$t('Households')}`"></span>
                    <span x-show="$store.sync.user?.household_invitations" class="ml-1 rounded-full bg-sky-700 px-2 py-0.5 text-xs text-white"
                        x-text="$store.sync.user?.household_invitations === 1 ? $t('1 invitation') : $t(':count invitations', { count: $store.sync.user?.household_invitations })"></span>
                </span>
                <span class="block text-sm text-stone-500" x-text="myHouseholds.length
                    ? myHouseholds.map((household) => household.name).join(', ')
                    : $t('Share lists with your family or flat in one go')"></span>
            </span>
            <span aria-hidden="true">›</span>
        </a>
        <a href="#/notifications" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
            <span>
                <span class="block" x-text="`🔔 ${$t('Notifications')}`"></span>
                <span class="block text-sm text-stone-500" x-text="$t('Push or email per kind, reminder time')"></span>
            </span>
            <span aria-hidden="true">›</span>
        </a>
        <a href="#/absences" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
            <span>
                <span class="block" x-text="`🏖️ ${$t('Holidays')}`"></span>
                <span class="block text-sm text-stone-500" x-text="$t('No reminders while you are away, fair ranking')"></span>
            </span>
            <span aria-hidden="true">›</span>
        </a>
        <a href="#/install" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
            <span>
                <span class="block" x-text="`📲 ${$t('Install app')}`"></span>
                <span class="block text-sm text-stone-500" x-text="$store.install.installed ? $t('The app is installed on this device.') : $t('How to add :app to your home screen or dock')"></span>
            </span>
            <span aria-hidden="true">›</span>
        </a>
    </div>

    <div class="grid gap-4 min-[480px]:grid-cols-2">
        <label class="block">
            <span class="mb-2 block text-sm font-medium text-stone-600" x-text="$t('Language')"></span>
            <select class="field w-full" @change="setLocale($event.target.value)">
                <template x-for="(label, code) in $store.i18n.locales" :key="code">
                    <option :value="code" x-text="label" :selected="code === $store.i18n.locale"></option>
                </template>
            </select>
        </label>
        <label class="block">
            <span class="mb-2 block text-sm font-medium text-stone-600" x-text="$t('Appearance')"></span>
            <select class="field w-full" @change="$store.prefs.set('theme', $event.target.value)">
                <template x-for="option in [{ value: 'system', label: $t('Like the device') }, { value: 'light', label: $t('Light') }, { value: 'dark', label: $t('Dark') }]" :key="option.value">
                    <option :value="option.value" x-text="option.label" :selected="option.value === $store.prefs.theme"></option>
                </template>
            </select>
        </label>
    </div>

    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-200 bg-white p-4">
        <input type="checkbox" class="mt-1 size-4 accent-sky-700" :checked="$store.prefs.recommendations" @change="$store.prefs.set('recommendations', $event.target.checked)">
        <span>
            <span class="block" x-text="$t('Suggestions for me')"></span>
            <span class="block text-sm text-stone-500" x-text="$t('Shows which tasks are worth doing next (urgent ones, and points to catch up). Only you see them.')"></span>
        </span>
    </label>

    <fieldset>
        <legend class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Actions on touch devices')"></legend>
        <div class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
            <template x-for="option in [
                { value: 'swipe', label: $t('Swipe left'), hint: $t('Done, skip, postpone, edit and delete appear next to the row.') },
                { value: 'longpress', label: $t('Long press'), hint: $t('A menu with these actions opens.') },
            ]" :key="option.value">
                <label class="flex cursor-pointer items-start gap-3 p-4 hover:bg-stone-50">
                    <input type="radio" name="row-actions" class="mt-1 accent-sky-700" :value="option.value"
                        :checked="$store.prefs.row_actions === option.value" @change="$store.prefs.set('row_actions', option.value)">
                    <span>
                        <span class="block" x-text="option.label"></span>
                        <span class="block text-sm text-stone-500" x-text="option.hint"></span>
                    </span>
                </label>
            </template>
        </div>
    </fieldset>

    {{-- Calendar subscription (read only) --}}
    <div x-show="loggedIn">
        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="`📅 ${$t('Calendar subscription')}`"></h2>
        <div class="space-y-3 rounded-lg border border-stone-200 bg-white p-4 text-sm">
            <p class="text-stone-600" x-text="$t('Shows the dates of your tasks in your calendar app (Apple, Google, Outlook). Read only, updated every few hours.')"></p>
            <template x-if="$store.sync.user?.ical_url">
                <div class="space-y-2">
                    <input class="field w-full font-mono text-xs" readonly :value="$store.sync.user.ical_url" @focus="$event.target.select()" :aria-label="$t('Calendar link')">
                    <div class="flex flex-wrap gap-2">
                        <a :href="webcalUrl" class="button" x-text="$t('Subscribe')"></a>
                        <button type="button" class="button-secondary" @click="copy($store.sync.user.ical_url)" x-text="$t('Copy link')"></button>
                        <button type="button" class="button-secondary text-red-700" @click="revokeIcal()" :disabled="busy" x-text="$t('New link')"></button>
                    </div>
                    <p class="text-xs text-stone-500" x-text="$t('Anyone with this link sees your tasks. “New link” makes the old one stop working.')"></p>
                </div>
            </template>
            <button type="button" x-show="!$store.sync.user?.ical_url" class="button-secondary" @click="createIcal()" :disabled="busy" x-text="$t('Create calendar link')"></button>
        </div>
    </div>

    <div>
        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Data')"></h2>
        <div class="space-y-2 rounded-lg border border-stone-200 bg-white p-4 text-sm">
            <p class="text-stone-600" x-text="$t('Download your lists, tasks and history as a JSON file.')"></p>
            <button type="button" class="button-secondary" @click="exportData()" x-text="$t('Export data')"></button>
        </div>
    </div>

    @include('partials.account')
</section>

<section x-show="route.view === 'notifications'" class="space-y-6">
    <template x-if="!loggedIn">
        <p class="notice" x-text="$t('Notifications need an account.')"></p>
    </template>

    {{-- Push on this device --}}
    <div class="space-y-3 rounded-lg border border-stone-200 bg-white p-4">
        <h2 class="font-semibold" x-text="$t('Push on this device')"></h2>
        <template x-if="!$store.push.supported">
            <p class="text-sm text-stone-600" x-text="isIos && !$store.install.installed
                ? $t('On iPhone and iPad, push notifications only work once the app is added to the home screen (iOS 16.4 or newer).')
                : $t('This browser does not support push notifications.')"></p>
        </template>
        <template x-if="$store.push.supported">
            <div class="space-y-2">
                <p class="text-sm" :class="$store.push.subscribed ? 'text-emerald-700' : 'text-stone-600'"
                    x-text="$store.push.subscribed ? $t('Push notifications are on for this device.') : $store.push.permission === 'denied'
                        ? $t('Notifications are blocked. Allow them in the settings of the browser or device.')
                        : $t('Push notifications are off for this device.')"></p>
                <button type="button" x-show="!$store.push.subscribed" class="button" :disabled="$store.push.busy || !loggedIn || !$store.sync.online" @click="enablePush()" x-text="$t('Turn on push')"></button>
                <button type="button" x-show="$store.push.subscribed" class="button-secondary" :disabled="$store.push.busy" @click="disablePush()" x-text="$t('Turn off push')"></button>
                <p class="text-xs text-stone-500" x-text="$t('Push is set up per device (phone, computer). Devices with push: :count', { count: $store.sync.user?.push_devices ?? 0 })"></p>
            </div>
        </template>
    </div>

    {{-- Channels per kind --}}
    <div>
        <div class="mb-2 grid grid-cols-[1fr_4rem_4rem] items-end gap-2 px-4 text-xs font-medium text-stone-600">
            <span></span>
            <span class="text-center" x-text="$t('Push')"></span>
            <span class="text-center" x-text="$t('Email')"></span>
        </div>
        <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
            <template x-for="entry in notificationKinds" :key="entry.kind">
                <li class="grid grid-cols-[1fr_4rem_4rem] items-center gap-2 px-4 py-3">
                    <span>
                        <span class="block" x-text="entry.label"></span>
                        <span class="block text-xs text-stone-500" x-text="entry.hint"></span>
                    </span>
                    <template x-for="channel in ['push', 'mail']" :key="channel">
                        <label class="grid cursor-pointer place-items-center">
                            <input type="checkbox" class="size-5 accent-sky-700" :disabled="!loggedIn"
                                :checked="($store.prefs.notifications[entry.kind] ?? []).includes(channel)"
                                @change="$store.prefs.toggleChannel(entry.kind, channel)"
                                :aria-label="`${entry.label}: ${channel === 'push' ? $t('Push') : $t('Email')}`">
                        </label>
                    </template>
                </li>
            </template>
        </ul>
        <p class="mt-2 text-xs text-stone-500" x-text="$t('Neither checked: no notification of this kind. Muted lists send nothing (list settings).')"></p>
    </div>

    <label class="flex items-center justify-between gap-3 rounded-lg border border-stone-200 bg-white p-4">
        <span>
            <span class="block" x-text="$t('Reminder time')"></span>
            <span class="block text-sm text-stone-500" x-text="$t('Reminders and the weekly summary are sent from this time on.')"></span>
        </span>
        <input type="time" class="field" :value="$store.prefs.reminder_time" @change="$event.target.value && $store.prefs.set('reminder_time', $event.target.value)" :disabled="!loggedIn">
    </label>
</section>

<section x-show="route.view === 'absences'" class="space-y-6">
    <p class="text-stone-600" x-text="$t('While you are away you get no reminders, and the ranking compares the points per day present.')"></p>
    <form @submit.prevent="addAbsence()" class="space-y-3 rounded-lg border border-stone-200 bg-white p-4">
        <div class="grid grid-cols-2 gap-3">
            <label class="block">
                <span class="label" x-text="$t('From')"></span>
                <input type="date" x-model="absenceForm.starts_on" class="field w-full" required>
            </label>
            <label class="block">
                <span class="label" x-text="$t('Until')"></span>
                <input type="date" x-model="absenceForm.ends_on" class="field w-full" :min="absenceForm.starts_on">
            </label>
        </div>
        <label class="block">
            <span class="label" x-text="$t('Note (only for you)')"></span>
            <input x-model="absenceForm.note" class="field w-full" maxlength="255" :placeholder="$t('e.g. Skiing holidays')">
        </label>
        <button class="button" x-text="$t('Add')"></button>
    </form>

    <ul x-show="absences.length" class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
        <template x-for="absence in absences" :key="absence.id">
            <li class="flex items-center gap-3 px-4 py-3">
                <span class="min-w-0 flex-1">
                    <span class="block" x-text="`${date(absence.starts_on)} – ${date(absence.ends_on)}`"></span>
                    <span class="block truncate text-sm text-stone-500" x-text="absence.is_own ? (absence.note ?? '') : memberName(absence.user_email)"></span>
                </span>
                <button type="button" x-show="absence.is_own" class="text-sm text-red-700 underline" @click="deleteAbsence(absence)" x-text="$t('Delete')"></button>
            </li>
        </template>
    </ul>
</section>

{{-- Install instructions (offline available, part of the app shell) --}}
<section x-show="route.view === 'install'" class="space-y-6">
    <p class="text-stone-600" x-text="$t('Installed, :app starts like an app from the home screen, in full screen and without a connection, and can send push reminders.')"></p>

    <div class="rounded-lg border border-stone-200 bg-white p-4">
        <h2 class="mb-3 font-semibold" x-text="`${$t('This device')}: ${$store.install.current.name}`"></h2>
        <p x-show="$store.install.installed" class="text-emerald-700" x-text="$t('The app is installed on this device.')"></p>
        <template x-if="!$store.install.installed">
            <div>
                <button type="button" x-show="$store.install.prompt" class="button mb-4 w-full" @click="$store.install.install()" x-text="$t('Install now')"></button>
                <ol class="list-decimal space-y-1 pl-5">
                    <template x-for="text in $store.install.current.steps" :key="text"><li x-text="$t(text)"></li></template>
                </ol>
                <p x-show="$store.install.current.note" class="mt-3 text-sm text-stone-500" x-text="$t($store.install.current.note ?? '')"></p>
            </div>
        </template>
    </div>

    <div>
        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Other devices and browsers')"></h2>
        <div class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
            <template x-for="[id, platform] in $store.install.others" :key="id">
                <details class="group/details">
                    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 hover:bg-stone-50">
                        <span x-text="platform.name"></span>
                        <span class="transition-transform group-open/details:rotate-90" aria-hidden="true">›</span>
                    </summary>
                    <div class="px-4 pb-4">
                        <ol class="list-decimal space-y-1 pl-5 text-sm">
                            <template x-for="text in platform.steps" :key="text"><li x-text="$t(text)"></li></template>
                        </ol>
                        <p x-show="platform.note" class="mt-2 text-sm text-stone-500" x-text="$t(platform.note ?? '')"></p>
                    </div>
                </details>
            </template>
        </div>
    </div>
</section>
