<!DOCTYPE html>
<html lang="de-CH">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ config('app.name') }}</title>
    <meta name="theme-color" content="#0369a1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    @include('partials.favicon')
    @include('partials.meta')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    App shell: cached by the service worker and used offline. It must not contain user data
    or server side translations, the UI translates itself ($t) and reads the local database.
--}}
<body class="min-h-dvh bg-stone-50 text-stone-900 antialiased" :class="$store.sync.online || 'is-offline'" x-data="chorelist" x-cloak>
    <header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur">
        <div class="mx-auto flex h-14 max-w-xl items-center gap-2 px-3">
            <a :href="backLink" @click.prevent="goBack()" x-show="!['home', 'lists', 'offline'].includes(route.view)" class="icon-button" :aria-label="$t('Back')">
                <svg viewBox="0 0 24 24" class="size-6"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <h1 class="min-w-0 flex-1 truncate text-lg font-semibold">
                <span x-show="['home', 'lists'].includes(route.view)" class="flex items-center gap-2">
                    <img src="/favicon-96x96.png" alt="" class="size-7 rounded-md">
                    <span>Chorelist</span>
                </span>
                <span x-show="route.view === 'list' || route.view === 'history' || route.view === 'stats'" class="block truncate"
                    x-text="list ? `${list.icon ? list.icon + ' ' : ''}${list.name}` : ''"></span>
                <span x-show="route.view === 'task'" class="block truncate" x-text="task?.title ?? ''"></span>
                <span x-show="route.view === 'new'" class="block truncate" x-text="$t('New task')"></span>
                <span x-show="route.view === 'edit'" class="block truncate" x-text="$t('Edit task')"></span>
                <span x-show="route.view === 'settings'" class="block truncate" x-text="$t('Settings')"></span>
                <span x-show="route.view === 'notifications'" class="block truncate" x-text="$t('Notifications')"></span>
                <span x-show="route.view === 'absences'" class="block truncate" x-text="$t('Holidays')"></span>
                <span x-show="route.view === 'install'" class="block truncate" x-text="$t('Install app')"></span>
                <span x-show="route.view === 'offline'" class="block truncate" x-text="$t('Offline')"></span>
                <span x-show="route.view === 'invite-invalid'" class="block truncate" x-text="$t('Invitation')"></span>
            </h1>

            {{-- Sync state --}}
            <span class="flex shrink-0 items-center gap-1.5 text-xs text-stone-500" :title="$t('Sync status')">
                <span class="size-2 rounded-full"
                    :class="{
                        'bg-emerald-500': $store.sync.status === 'synced',
                        'bg-amber-400 animate-pulse': ['starting', 'syncing'].includes($store.sync.status),
                        'bg-stone-400': ['offline', 'local'].includes($store.sync.status),
                        'bg-red-500': ['error', 'expired', 'blocked', 'unverified', 'broken'].includes($store.sync.status),
                    }"></span>
                <span class="hidden min-[400px]:inline" x-text="{
                    starting: $t('Starting …'),
                    syncing: $t('Syncing …'),
                    synced: $t('Synced'),
                    offline: $t('Offline'),
                    local: $t('Only on this device'),
                    expired: $t('Not synced'),
                    blocked: $t('Not synced'),
                    unverified: $t('Not synced'),
                    error: $t('Sync error'),
                    broken: $t('Error'),
                }[$store.sync.status]"></span>
            </span>

            <button type="button" x-show="list && route.view === 'list'" class="icon-button" :aria-label="$t('List settings')" @click="openListDialog()">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>
            </button>
            <a href="#/settings" x-show="['home', 'lists', 'list'].includes(route.view)" class="icon-button" :aria-label="$t('Settings')">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10.3 4.3c.4-1.7 3-1.7 3.4 0a1.7 1.7 0 0 0 2.6 1.1c1.5-.9 3.3.8 2.4 2.4a1.7 1.7 0 0 0 1 2.5c1.8.4 1.8 3 0 3.4a1.7 1.7 0 0 0-1 2.6c.9 1.5-.9 3.3-2.4 2.4a1.7 1.7 0 0 0-2.6 1c-.4 1.8-3 1.8-3.4 0a1.7 1.7 0 0 0-2.5-1c-1.6.9-3.3-.9-2.4-2.4a1.7 1.7 0 0 0-1.1-2.6c-1.7-.4-1.7-3 0-3.4a1.7 1.7 0 0 0 1.1-2.5c-.9-1.6.8-3.3 2.4-2.4a1.7 1.7 0 0 0 2.5-1.1z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
        </div>

        {{-- Overview tabs --}}
        <nav x-show="['home', 'lists'].includes(route.view)" class="mx-auto flex max-w-xl gap-1 px-3 pb-2" :aria-label="$t('Overview')">
            <a href="#/" class="tab" :class="route.view === 'home' && 'tab-active'" :aria-current="route.view === 'home' && 'page'" x-text="$t('Upcoming')"></a>
            <a href="#/lists" class="tab" :class="route.view === 'lists' && 'tab-active'" :aria-current="route.view === 'lists' && 'page'" x-text="$t('Lists')"></a>
        </nav>
    </header>

    {{-- Account hints --}}
    <div class="mx-auto max-w-xl px-3">
        <div x-show="$store.sync.status === 'broken'" class="notice mt-3 space-y-2" role="alert">
            <p x-text="$t('The data on this device could not be opened.')"></p>
            <p x-text="$t('Resetting deletes it from this device. Synced lists come back from the server, changes that were not synced yet are lost.')"></p>
            <button type="button" class="font-medium underline" @click="confirm($t('Reset the data on this device?')) && $store.sync.resetLocalData()" x-text="$t('Reset data on this device')"></button>
        </div>
        <p x-show="$store.sync.status === 'local'" class="notice mt-3">
            <span x-text="$t('Log in to sync your lists across devices and share them.')"></span>
            <a href="/login" data-online class="font-medium underline" x-text="$t('Log in')"></a> ·
            <a href="/register" data-online class="font-medium underline" x-text="$t('Register')"></a>
        </p>
        <p x-show="$store.sync.status === 'expired'" class="notice mt-3">
            <span x-text="$t('Your session has expired. Please log in again.')"></span>
            <a href="/login" data-online class="font-medium underline" x-text="$t('Log in')"></a>
        </p>
        <p x-show="$store.sync.status === 'blocked'" class="notice mt-3" role="alert" x-text="$store.sync.blocked"></p>
        <p x-show="$store.sync.status === 'unverified'" class="notice mt-3">
            <span x-text="$t('Please verify your email address to sync.')"></span>
            <a href="/email/verify" data-online class="font-medium underline" x-text="$t('Resend Verification Email')"></a>
        </p>
    </div>

    <main x-show="$store.sync.status !== 'broken'" class="mx-auto max-w-xl px-3 pt-4 pb-[calc(6rem+env(safe-area-inset-bottom))]">
        {{-- Upcoming: suggestions and all tasks by due date --}}
        <section x-show="route.view === 'home'" class="space-y-6">
            <template x-if="!lists.length">
                <div class="space-y-3 py-6 text-center">
                    <p class="text-lg font-semibold" x-text="$t('Welcome to Chorelist!')"></p>
                    <p class="text-stone-600" x-text="$t('Create a list for your household, add the chores and share the list with your flatmates or family.')"></p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <a href="#/lists" class="button inline-block" x-text="$t('Create a list')"></a>
                        <button type="button" class="button-secondary" @click="openTemplateDialog()" x-text="`📋 ${$t('Import from a template')}`"></button>
                    </div>
                </div>
            </template>

            {{-- Personal suggestions, only shown to the user --}}
            <div x-show="suggestions.length" class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                <h2 class="mb-2 flex items-center gap-2 font-semibold text-sky-900">
                    <span aria-hidden="true">✨</span>
                    <span x-text="$t('For you')"></span>
                </h2>
                <ul class="space-y-2">
                    <template x-for="suggestion in suggestions" :key="suggestion.task.id">
                        <li class="flex items-center gap-3">
                            <button type="button" class="check !m-0 bg-white" @click="check(suggestion.task)" :aria-label="$t('Mark “:task” as done', { task: suggestion.task.title })">
                                <svg viewBox="0 0 24 24" class="size-4 text-stone-300" aria-hidden="true"><path d="M5 12l5 5 9-10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <a :href="`#/task/${suggestion.task.id}`" class="min-w-0 flex-1">
                                <span class="block text-sm" x-text="suggestionText(suggestion)"></span>
                                <span class="block text-xs" :class="dueClass(suggestion.state)" x-text="`${dueLabel(suggestion.task, suggestion.state)} · ${suggestion.list.name}`"></span>
                            </a>
                        </li>
                    </template>
                </ul>
            </div>

            <template x-for="group in agenda.filter((group) => group.key !== 'later' || showLater)" :key="group.key">
                <div>
                    <h2 class="mb-2 text-sm font-medium" :class="group.key === 'overdue' ? 'text-red-700' : 'text-stone-600'"
                        x-text="`${group.label} (${group.entries.length})`"></h2>
                    <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                        <template x-for="entry in group.entries" :key="entry.task.id">
                            <x-task-row show-list />
                        </template>
                    </ul>
                </div>
            </template>
            <button type="button" x-show="agenda.some((group) => group.key === 'later')" class="text-sm font-medium text-stone-600 underline"
                @click="showLater = !showLater" x-text="showLater ? $t('Hide later tasks') : $t('Show later tasks (:count)', { count: agenda.find((group) => group.key === 'later')?.entries.length ?? 0 })"></button>
            <p x-show="lists.length && !agenda.length" class="py-8 text-center text-stone-500" x-text="$t('No tasks yet.')"></p>
        </section>

        {{-- Lists --}}
        <section x-show="route.view === 'lists'">
            <form @submit.prevent="createList()" class="mb-4 flex gap-2">
                <input x-model="newListName" class="field min-w-0 flex-1" :placeholder="$t('New list, e.g. Household')" :aria-label="$t('New list')" enterkeyhint="done" maxlength="255">
                <button class="button shrink-0" x-text="$t('Add')"></button>
            </form>
            <button type="button" class="mb-4 -mt-2 text-sm font-medium text-stone-600 underline" @click="openTemplateDialog()" x-text="`📋 ${$t('Import from a template')}`"></button>

            <p x-show="!lists.length" class="py-8 text-center text-stone-500" x-text="$t('No lists yet.')"></p>

            <x-sort-select x-show="lists.length > 1" value="$store.prefs.list_sort" change="$store.prefs.set('list_sort', $event.target.value)" />

            {{-- Hold a row and move it (touch) or drag it (mouse) to change the order. --}}
            <ul x-show="lists.length" class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white" @row-move="moveRow('lists', $event.detail)">
                <template x-for="item in lists" :key="item.id">
                    <x-swipe-row actions="listActions(item)" x-bind:data-sort-id="item.id">
                        <a :href="`#/list/${item.id}`" class="flex min-w-0 flex-1 items-center gap-3 py-3 pl-4 pointer-coarse:pr-4 pointer-fine:pr-3" draggable="false">
                            <span class="w-7 shrink-0 text-center text-xl" aria-hidden="true" x-text="item.icon || '📋'"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium" x-text="item.name"></span>
                                <span x-show="item.members?.length > 1" class="block truncate text-xs text-stone-500"
                                    x-text="item.members.filter((m) => m.email !== me).map((m) => m.name || m.email).join(', ')"></span>
                            </span>
                            <span x-show="item.muted" class="text-stone-400" :title="$t('Muted')" aria-hidden="true">🔕</span>
                            <span x-show="overdueCount(item.id)" class="rounded-full bg-red-100 px-2 py-0.5 text-sm tabular-nums text-red-800" :title="$t('Overdue')" x-text="overdueCount(item.id)"></span>
                            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-sm tabular-nums text-stone-600" :title="$t('Due today')" x-text="dueCount(item.id)"></span>
                        </a>
                    </x-swipe-row>
                </template>
            </ul>
        </section>

        {{-- One list --}}
        <section x-show="route.view === 'list'">
            <template x-if="!list">
                <p class="py-8 text-center text-stone-500">
                    <span x-text="$t('This list does not exist (anymore).')"></span>
                    <a href="#/lists" class="underline" x-text="$t('Back')"></a>
                </p>
            </template>

            <template x-if="list">
                <div class="space-y-6">
                    {{-- Ranking --}}
                    <template x-if="list.ranking_enabled && list.members.length">
                        <a :href="`#/stats/${list.id}`" class="block rounded-xl border border-stone-200 bg-white p-4 hover:bg-stone-50">
                            <span class="mb-2 flex items-center justify-between text-sm">
                                <span class="font-semibold" x-text="`🏆 ${$t('Ranking')} · ${periodLabel(list.ranking_period)}`"></span>
                                <span class="text-stone-500" aria-hidden="true">›</span>
                            </span>
                            <ol class="space-y-1.5">
                                <template x-for="(row, index) in rankingOf(list)" :key="row.email">
                                    <li class="flex items-center gap-3 text-sm">
                                        <span class="w-5 text-center" x-text="['🥇', '🥈', '🥉'][index] ?? `${index + 1}.`"></span>
                                        <span class="min-w-0 flex-1 truncate" :class="row.email === me && 'font-semibold'">
                                            <span x-text="memberName(row.email, list.id)"></span>
                                            <span x-show="absentToday(row.email)" class="text-xs text-stone-500" x-text="`🏖️ ${$t('away')}`"></span>
                                        </span>
                                        <span class="tabular-nums" x-text="`${row.points} ★`"></span>
                                    </li>
                                </template>
                            </ol>
                        </a>
                    </template>

                    <div class="flex flex-wrap gap-2 text-sm">
                        <a :href="`#/history/${list.id}`" class="chip" x-text="`🕘 ${$t('History')}`"></a>
                        <a :href="`#/stats/${list.id}`" class="chip" x-text="`📊 ${$t('Statistics')}`"></a>
                        <button type="button" class="chip" @click="openListDialog()" x-text="loggedIn && list.is_owner ? `👥 ${$t('Share')}` : `⚙️ ${$t('Settings')}`"></button>
                    </div>

                    {{-- Filter by category (this device) --}}
                    <div x-show="listCategories.length" class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="$t('Filter by category')">
                        <button type="button" class="filter-chip" :class="!categoryFilter && 'filter-chip-active'" @click="setCategoryFilter('')" x-text="$t('All')"></button>
                        <template x-for="category in listCategories" :key="category">
                            <button type="button" class="filter-chip" :class="categoryFilter === category && 'filter-chip-active'" @click="setCategoryFilter(category)" x-text="category"></button>
                        </template>
                    </div>

                    <div x-show="!listGroups.length && !categoryFilter" class="space-y-3 py-6 text-center">
                        <p class="text-stone-500" x-text="$t('No tasks yet. Add the first one with +.')"></p>
                        <button type="button" class="button-secondary" @click="openTemplateDialog(list.id)" x-text="`📋 ${$t('Import from a template')}`"></button>
                    </div>
                    <p x-show="!listGroups.length && categoryFilter" class="py-6 text-center text-stone-500" x-text="$t('No tasks yet. Add the first one with +.')"></p>

                    <template x-for="group in listGroups" :key="group.key">
                        <div>
                            <h2 class="mb-2 text-sm font-medium" :class="group.key === 'overdue' ? 'text-red-700' : 'text-stone-600'"
                                x-text="`${group.label} (${group.entries.length})`"></h2>
                            <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                                <template x-for="entry in group.entries" :key="entry.task.id">
                                    <x-task-row />
                                </template>
                            </ul>
                        </div>
                    </template>

                    <div x-show="archivedTasks.length">
                        <button type="button" class="mb-2 text-sm font-medium text-stone-600" @click="showArchived = !showArchived"
                            x-text="`${$t('Archived')} (${archivedTasks.length}) ${showArchived ? '▾' : '▸'}`"></button>
                        <ul x-show="showArchived" class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white text-stone-500">
                            <template x-for="task in archivedTasks" :key="task.id">
                                <li class="flex items-center gap-3 px-4 py-3">
                                    <a :href="`#/task/${task.id}`" class="min-w-0 flex-1 truncate" x-text="`${task.icon ?? ''} ${task.title}`"></a>
                                    <button type="button" class="text-sm underline" @click="setArchived(task, false)" x-text="$t('Restore')"></button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>
        </section>

        {{-- Task details --}}
        <section x-show="route.view === 'task'">
            <template x-if="!task">
                <p class="py-8 text-center text-stone-500">
                    <span x-text="$t('This task does not exist (anymore).')"></span>
                    <a href="#/" class="underline" x-text="$t('Back')"></a>
                </p>
            </template>
            <template x-if="task">
                <div class="space-y-5" x-data="{ get state() { return stateOf(task) } }">
                    <div class="flex items-start gap-3">
                        <span x-show="task.icon" class="text-4xl" aria-hidden="true" x-text="task.icon"></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium" :class="dueClass(state)" x-text="dueLabel(task, state)"></p>
                            <p class="text-sm text-stone-600" x-text="schedule(task)"></p>
                            <p x-show="task.window_days && state.start && task.mode !== 'on_demand'" class="text-sm text-stone-600"
                                x-text="$t(':start to :due', { start: date(state.start), due: date(state.due) })"></p>
                            <p class="mt-1 flex flex-wrap gap-x-3 text-sm text-stone-500">
                                <a :href="`#/list/${task.task_list_id}`" class="underline" x-text="listById(task.task_list_id)?.name"></a>
                                <span x-show="listById(task.task_list_id)?.ranking_enabled" x-text="`${task.points} ★`"></span>
                                <span x-show="task.category" x-text="task.category"></span>
                            </p>
                        </div>
                    </div>

                    <p x-show="task.archived" class="notice" x-text="$t('This task is archived.')"></p>
                    <p x-show="task.paused_until && task.paused_until > today" class="notice" x-text="$t('Paused until :date.', { date: date(task.paused_until) })"></p>

                    {{-- Main actions --}}
                    <div class="flex flex-wrap gap-2" x-show="!task.archived">
                        <button type="button" class="button flex items-center gap-2" @click="check(task)">
                            <svg viewBox="0 0 24 24" class="size-5" aria-hidden="true"><path d="M5 12l5 5 9-10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span x-text="$t('Done')"></span>
                        </button>
                        <button type="button" class="button-secondary" @click="openComplete(task)" x-text="$t('Done …')"></button>
                        <button type="button" x-show="state.occurrence" class="button-secondary" @click="skip(task)" x-text="$t('Skip')"></button>
                        <button type="button" x-show="state.occurrence" class="button-secondary" @click="openPostpone(task)" x-text="$t('Postpone')"></button>
                    </div>

                    {{-- Steps --}}
                    <div x-show="task.checklist?.length">
                        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Steps')"></h2>
                        <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                            <template x-for="step in task.checklist" :key="step.id">
                                <li>
                                    <label class="flex cursor-pointer items-center gap-3 px-4 py-3">
                                        <input type="checkbox" class="size-5 accent-sky-700" :checked="step.done" @change="toggleStep(task, step.id)">
                                        <span :class="step.done && 'text-stone-400 line-through'" x-text="step.text"></span>
                                    </label>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div x-show="task.notes">
                        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Notes')"></h2>
                        <p class="whitespace-pre-line break-words rounded-lg border border-stone-200 bg-white p-4" x-text="task.notes"></p>
                    </div>

                    <div x-show="nextDates(task).length">
                        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Next dates')"></h2>
                        <p class="text-sm" x-text="nextDates(task).map((day) => date(day)).join(' · ')"></p>
                    </div>

                    <div>
                        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('History')"></h2>
                        <x-history-days list="task.task_list_id" task="task.id" />
                    </div>

                    <div class="flex flex-wrap gap-x-5 gap-y-2 border-t border-stone-200 pt-4 text-sm">
                        <a :href="`#/edit/${task.id}`" class="font-medium underline" x-text="$t('Edit')"></a>
                        <template x-if="task.mode !== 'on_demand' && !task.archived">
                            <label class="flex items-center gap-2">
                                <span x-text="$t('Pause until')"></span>
                                <input type="date" class="rounded border border-stone-300 bg-white px-2 py-1" :min="addDays(today, 1)" :value="task.paused_until ?? ''" @change="pause(task, $event.target.value)">
                                <button type="button" x-show="task.paused_until" class="underline" @click="pause(task, null)" x-text="$t('Resume')"></button>
                            </label>
                        </template>
                        <button type="button" class="underline" @click="setArchived(task, !task.archived)" x-text="task.archived ? $t('Restore') : $t('Archive')"></button>
                        <button type="button" class="text-red-700 underline" @click="deleteTask(task)" x-text="$t('Delete')"></button>
                    </div>
                    <p x-show="task.creator_email" class="text-xs text-stone-500" x-text="task.creator_email === me ? $t('Created by you') : $t('Created by :name', { name: memberName(task.creator_email, task.task_list_id) })"></p>
                </div>
            </template>
        </section>

        {{-- New or changed task --}}
        @include('partials.task-form')

        {{-- History of a list --}}
        <section x-show="route.view === 'history'" class="space-y-4">
            <template x-if="list">
                <div class="space-y-4">
                    <div class="flex flex-wrap gap-2">
                        <select class="field min-w-0 flex-1" x-model="historyFilter.member" :aria-label="$t('Member')">
                            <option value="" x-text="$t('All members')"></option>
                            <template x-for="member in list.members" :key="member.email">
                                <option :value="member.email" x-text="memberName(member.email, list.id)"></option>
                            </template>
                        </select>
                        <select class="field min-w-0 flex-1" x-model="historyFilter.task" :aria-label="$t('Task')">
                            <option value="" x-text="$t('All tasks')"></option>
                            <template x-for="each in visibleTasks.filter((t) => t.task_list_id === list.id)" :key="each.id">
                                <option :value="each.id" x-text="each.title"></option>
                            </template>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-stone-600">
                        <input type="checkbox" class="accent-sky-700" x-model="historyFilter.missed">
                        <span x-text="$t('Show missed dates')"></span>
                    </label>
                    <x-history-days list="list.id" />
                </div>
            </template>
        </section>

        {{-- Statistics of a list --}}
        <section x-show="route.view === 'stats'">
            <template x-if="list">
                <div class="space-y-6">
                    <div x-show="list.ranking_enabled">
                        <div class="mb-2 flex flex-wrap gap-1.5" role="group" :aria-label="$t('Period')">
                            <template x-for="period in ['week', 'month', 'all']" :key="period">
                                <button type="button" class="filter-chip" :class="statsPeriod === period && 'filter-chip-active'" @click="statsPeriod = period" x-text="periodLabel(period)"></button>
                            </template>
                        </div>
                        <p class="mb-2 text-xs text-stone-500" x-text="$t('Since :date', { date: date(periodSince(list, statsPeriod), { day: 'numeric', month: 'long', year: 'numeric' }) })"></p>
                        <table class="w-full overflow-hidden rounded-lg border border-stone-200 bg-white text-sm">
                            <thead class="bg-stone-100 text-left text-stone-600">
                                <tr>
                                    <th class="px-3 py-2 font-medium" x-text="$t('Member')"></th>
                                    <th class="px-3 py-2 text-right font-medium" x-text="$t('Points')"></th>
                                    <th class="px-3 py-2 text-right font-medium" x-text="$t('Done')"></th>
                                    <th class="px-3 py-2 text-right font-medium" :title="$t('Fair share by days present (holidays do not count)')" x-text="$t('Fair share')"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200">
                                <template x-for="row in rankingOf(list, statsPeriod)" :key="row.email">
                                    <tr>
                                        <td class="px-3 py-2">
                                            <span class="mr-1 inline-block size-2.5 rounded-full" :class="memberColor(row.email)"></span>
                                            <span x-text="memberName(row.email, list.id)"></span>
                                        </td>
                                        <td class="px-3 py-2 text-right font-semibold tabular-nums" x-text="row.points"></td>
                                        <td class="px-3 py-2 text-right tabular-nums" x-text="row.count"></td>
                                        <td class="px-3 py-2 text-right tabular-nums" :class="row.points < row.fair ? 'text-amber-700' : 'text-stone-500'" x-text="row.fair"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Points per week')"></h2>
                        <div class="flex h-40 items-end gap-2 rounded-lg border border-stone-200 bg-white p-3">
                            <template x-for="week in statsWeeks" :key="week.week">
                                <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
                                    <div class="flex w-full flex-col-reverse overflow-hidden rounded-t" :title="Object.entries(week.points).map(([email, points]) => `${memberName(email, list.id)}: ${points}`).join(', ')">
                                        <template x-for="[email, points] in Object.entries(week.points)" :key="email">
                                            <div :class="memberColor(email)" :style="`height: ${Math.round(points / statsMax * 104)}px`"></div>
                                        </template>
                                    </div>
                                    <span class="text-[10px] text-stone-500" x-text="date(week.week, { day: 'numeric', month: 'numeric' })"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div>
                        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Tasks in the last 90 days')"></h2>
                        <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white text-sm">
                            <template x-for="row in taskStats" :key="row.task.id">
                                <li class="flex items-center gap-3 px-4 py-2.5">
                                    <a :href="`#/task/${row.task.id}`" class="min-w-0 flex-1">
                                        <span class="block truncate font-medium" x-text="`${row.task.icon ?? ''} ${row.task.title}`"></span>
                                        <span class="block truncate text-xs text-stone-500" x-text="[
                                            row.last ? $t('last :date', { date: date(row.last) }) : $t('never done'),
                                            row.top ? $t('mostly :name', { name: memberName(row.top, list.id) }) : null,
                                        ].filter(Boolean).join(' · ')"></span>
                                    </a>
                                    <span class="shrink-0 tabular-nums" :title="$t('Done')" x-text="`✓ ${row.count}`"></span>
                                    <span x-show="row.missed" class="shrink-0 tabular-nums text-red-700" :title="$t('Missed')" x-text="`✗ ${row.missed}`"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>
        </section>

        @include('partials.settings')

        {{-- A server page (login, account, …) was opened without a connection: the service worker served the app shell. --}}
        <section x-show="route.view === 'offline'" class="space-y-4 py-8 text-center">
            <p class="text-lg font-semibold" x-text="$t('This page needs an internet connection.')"></p>
            <p class="text-stone-600" x-text="$t('Your lists are still available offline.')"></p>
            <div class="flex flex-wrap justify-center gap-2 pt-2">
                <button type="button" class="button-secondary" @click="location.reload()" x-text="$t('Try again')"></button>
                <a href="/" class="button" x-text="$t('Back to the lists')"></a>
            </div>
        </section>

        <section x-show="route.view === 'invite-invalid'" class="space-y-4 py-8 text-center">
            <p class="text-lg font-semibold" x-text="$t('This invitation link is not valid (anymore).')"></p>
            <p class="text-stone-600" x-text="$t('Ask the owner of the list for a new one.')"></p>
            <a href="#/" class="button inline-block" x-text="$t('Back to the lists')"></a>
        </section>
    </main>

    {{-- New task --}}
    <a x-show="['home', 'list'].includes(route.view) && lists.length" :href="`#/new/${route.view === 'list' ? route.id : ''}`"
        class="fixed right-[max(1rem,env(safe-area-inset-right))] bottom-[calc(1rem+env(safe-area-inset-bottom))] z-20 grid size-14 place-items-center rounded-full bg-sky-700 text-white shadow-lg hover:bg-sky-800"
        :class="($store.undo.toast || $store.notice.text) && 'bottom-[calc(5rem+env(safe-area-inset-bottom))]'"
        :aria-label="$t('New task')">
        <svg viewBox="0 0 24 24" class="size-7"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
    </a>

    {{-- First visit: install hint for the current platform --}}
    <div x-show="$store.install.showHint && route.view === 'home' && !$store.undo.toast && !$store.notice.text" x-transition
        class="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-30 mx-auto max-w-xl rounded-lg bg-stone-900 p-4 text-sm text-white shadow-lg">
        <p class="mb-1 font-semibold" x-text="$t('Install Chorelist as an app')"></p>
        <p x-show="$store.install.prompt" x-text="$t('Works offline, starts from your home screen and can send push reminders.')"></p>
        <p x-show="!$store.install.prompt" x-text="$t($store.install.current.steps[0]) + ' ' + ($store.install.current.steps[1] ? $t($store.install.current.steps[1]) : '')"></p>
        <p class="mt-2 text-xs text-stone-300" x-text="$t('You can find these instructions in the settings at any time.')"></p>
        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
            <button type="button" x-show="$store.install.prompt" class="rounded-lg bg-white px-3 py-1.5 font-medium text-stone-900" @click="$store.install.install()" x-text="$t('Install now')"></button>
            <a href="#/install" class="font-medium underline" @click="$store.install.dismiss()" x-text="$t('Instructions')"></a>
            <button type="button" class="ml-auto font-medium underline" @click="$store.install.dismiss()" x-text="$t('Got it')"></button>
        </div>
    </div>

    @include('partials.dialogs')

    {{-- "…" menu of a row (desktop) --}}
    <div x-ref="menu" x-show="menu" x-transition.opacity.duration.100ms role="menu" :style="menu?.style"
        class="fixed z-30 w-52 overflow-hidden rounded-lg border border-stone-200 bg-white py-1 shadow-lg"
        @click.outside="menu = null" @keydown.escape.window="menu = null" @scroll.window="menu = null" @resize.window="menu = null">
        <template x-for="action in menu?.actions ?? []" :key="action.label">
            <button type="button" role="menuitem" class="menu-item" :class="[action.danger && 'text-red-700', action.online && !$store.sync.online && 'opacity-50']" @click="runMenu(action)" x-text="action.label"></button>
        </template>
    </div>

    {{-- New version available --}}
    <div x-show="$store.update.apply && !$store.undo.toast" x-transition role="status" aria-live="polite"
        class="fixed inset-x-3 top-[calc(4rem+env(safe-area-inset-top))] z-40 mx-auto flex max-w-xl items-center gap-3 rounded-lg bg-sky-800 px-4 py-3 text-sm text-white shadow-lg">
        <span class="min-w-0 flex-1" x-text="$t('A new version is available.')"></span>
        <button type="button" class="shrink-0 font-semibold underline" @click="$store.update.reload()" x-text="$t('Reload')"></button>
    </div>

    {{-- Short notices (e.g. offline) --}}
    <div x-show="$store.notice.text" x-transition role="alert"
        class="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-50 mx-auto max-w-xl rounded-lg bg-stone-900 px-4 py-3 text-sm text-white shadow-lg"
        x-text="$store.notice.text" @click="$store.notice.text = null"></div>

    {{-- Undo (deletions, completions) --}}
    <div x-show="$store.undo.toast" x-transition role="status" aria-live="polite"
        class="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-40 mx-auto flex max-w-xl items-center gap-3 rounded-lg bg-stone-900 px-4 py-3 text-sm text-white shadow-lg">
        <span class="min-w-0 flex-1 truncate" x-text="$store.undo.toast?.message"></span>
        <button type="button" class="shrink-0 font-semibold text-sky-300 uppercase" @click="$store.undo.undo()" x-text="$t('Undo')"></button>
    </div>
</body>
</html>
