import Alpine from 'alpinejs';
import { addRxPlugin } from 'rxdb/plugins/core';
import { getRxStorageDexie } from 'rxdb/plugins/storage-dexie';
import { createApi, HttpError } from './api.js';
import { createDatabase, removeDatabase, removeLocally, startReplication } from './db/index.js';
import { compareBy, nextPosition, normalizeName, uuid7 } from './helpers.js';
import { hasPendingLocale, htmlLang, initialLocale, LOCALES, storeLocale, translate } from './i18n.js';
import { createInstallStore } from './install.js';
import { closeLayersOnBack } from './layers.js';
import { applyTheme, createPrefsStore } from './prefs.js';
import { currentSubscription, pushPermission, pushSupported, subscribePush, unsubscribePush } from './push.js';
import { registerServiceWorker } from './pwa.js';
import { periodRange, ranking, recommendations, SCORED, weeklyPoints } from './ranking.js';
import {
    addDays, daysBetween, describeSchedule, formatDate, fromSchedule, missed, nthLabel, occurrenceId, taskState, toSchedule, todayIn, upcoming, WEEKDAYS,
} from './schedule.js';
import { swipeRow } from './swipe.js';
import { createUndoStore } from './undo.js';

const RESYNC_INTERVAL = 30_000;
const OWNER_KEY = 'chorelist.owner';
const USER_KEY = 'chorelist.user';
// Category filter per list (device only): { listId: category }.
const CATEGORY_FILTER_KEY = 'chorelist.category-filter';
// Days back the history shows missed occurrences.
const MISSED_DAYS = 60;
// Emojis offered for lists and tasks (any other can be typed).
const ICONS = ['🏠', '🛁', '🚽', '🧹', '🧽', '🧺', '👕', '🍽️', '🧊', '🗑️', '♻️', '📦', '🪴', '🌱', '🐈', '🐕', '🛒', '🍳', '🪟', '🛏️', '🚗', '🚲', '💡', '🔧', '📬', '💸', '🧴', '🪣', '🌿', '❄️'];

const api = createApi();
let db = null;
let replication = null;

// ---------------------------------------------------------------- stores

Alpine.store('i18n', { locale: initialLocale(), locales: LOCALES });
// status: starting | local (not logged in) | unverified | expired | offline | syncing | synced | error | broken (local database)
Alpine.store('sync', {
    status: 'starting',
    user: null,
    online: navigator.onLine,

    /**
     * The local database could not be opened (e.g. schema changed without migration):
     * start over with an empty one, synced data comes back from the server.
     */
    async resetLocalData() {
        await removeDatabase({ storage: getRxStorageDexie() });
        location.reload();
    },
});
Alpine.store('data', { lists: [], tasks: [], completions: [], activities: [], absences: [] });
// The day changes while the app is open (e.g. overnight): states are computed for "today".
Alpine.store('clock', { today: todayIn() });
Alpine.store('undo', createUndoStore());
Alpine.store('prefs', createPrefsStore(api));
Alpine.store('install', createInstallStore());
Alpine.store('push', { supported: pushSupported(), permission: pushPermission(), subscribed: false, busy: false });
// New app version ready (service worker waiting).
Alpine.store('update', {
    apply: null,
    async reload() {
        // Deletions waiting for undo must not get lost.
        Alpine.store('undo').flush();
        await this.apply?.();
    },
});

// Short notice at the bottom, hides itself.
Alpine.store('notice', {
    text: null,
    timer: null,
    show(text) {
        clearTimeout(this.timer);
        this.text = text;
        this.timer = setTimeout(() => { this.text = null; }, 4000);
    },
});

Alpine.magic('t', () => (key, replace) => translate(Alpine.store('i18n').locale, key, replace));
const t = (key, replace) => translate(Alpine.store('i18n').locale, key, replace);

document.documentElement.lang = htmlLang(Alpine.store('i18n').locale);
applyTheme(Alpine.store('prefs').theme);
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme(Alpine.store('prefs').theme));

async function setLocale(locale) {
    Alpine.store('i18n').locale = locale;
    document.documentElement.lang = htmlLang(locale);
    try {
        await api.put('/locale', { locale });
        storeLocale(locale, { pending: false });
    } catch {
        // Offline: sent again with the next start.
        storeLocale(locale, { pending: true });
    }
}

Alpine.data('swipeRow', swipeRow);

// ---------------------------------------------------------------- task states (cached per data change)

let stateCache = { tasks: null, completions: null, today: null, byTask: new Map(), completionsByTask: new Map() };

/**
 * Completions grouped by task and the state of every task, computed once per change of the data.
 */
function states() {
    const { tasks, completions } = Alpine.store('data');
    const today = Alpine.store('clock').today;
    if (stateCache.tasks === tasks && stateCache.completions === completions && stateCache.today === today) return stateCache;

    const completionsByTask = new Map();
    for (const completion of completions) {
        if (!completionsByTask.has(completion.task_id)) completionsByTask.set(completion.task_id, []);
        completionsByTask.get(completion.task_id).push(completion);
    }
    const byTask = new Map();
    for (const task of tasks) {
        try {
            byTask.set(task.id, taskState(task, completionsByTask.get(task.id) ?? [], today));
        } catch (error) {
            console.error(error);
            byTask.set(task.id, { status: 'finished', start: null, due: null, occurrence: null, last_done: null });
        }
    }
    stateCache = { tasks, completions, today, byTask, completionsByTask };

    return stateCache;
}

// Order of the groups in the overview and in a list.
const GROUPS = ['overdue', 'today', 'week', 'later', 'on_demand', 'paused', 'finished'];

function groupOf(task, state, today) {
    if (task.paused_until && task.paused_until > today && state.status === 'upcoming') return 'paused';
    switch (state.status) {
        case 'overdue': return 'overdue';
        case 'due': return 'today';
        case 'open': return daysBetween(today, state.due) <= 0 ? 'today' : daysBetween(today, state.due) <= 6 ? 'week' : 'later';
        case 'upcoming': return daysBetween(today, state.start) <= 6 ? 'week' : 'later';
        case 'on_demand': return 'on_demand';
        default: return 'finished';
    }
}

const byName = (a, b) => a.name.localeCompare(b.name, Alpine.store('i18n').locale.replace('_', '-'));
const SORTS = ['manual', 'name_asc', 'name_desc', 'newest', 'oldest'];

// Empty task form (resources/js/schedule.js fromSchedule/toSchedule).
function emptyForm(listId, today) {
    return {
        id: null,
        task_list_id: listId,
        title: '',
        icon: '',
        category: '',
        notes: '',
        points: 2,
        starts_on: today,
        window_days: 0,
        ends_on: '',
        reminders: [0],
        remind_after_days: '',
        checklist: [],
        newStep: '',
        ...fromSchedule({ mode: 'fixed', rrule: `FREQ=WEEKLY;BYDAY=${WEEKDAYS[(new Date(`${today}T00:00:00Z`).getUTCDay() + 6) % 7]}` }),
    };
}

Alpine.data('chorelist', () => ({
    route: { view: 'home', id: null },
    newListName: '',
    busy: false,
    menu: null,
    // Task form (#/new/<list>, #/edit/<task>).
    form: null,
    // Dialogs: list settings, complete (who/when), postpone, ask (done in advance or extra).
    listDialog: null,
    shareEmail: '',
    shareError: null,
    inviteLink: null,
    completeDialog: null,
    postponeDialog: null,
    askDialog: null,
    absenceForm: { starts_on: '', ends_on: '', note: '' },
    historyFilter: { member: '', task: '', missed: true },
    statsPeriod: 'all',
    categoryFilters: JSON.parse(localStorage.getItem(CATEGORY_FILTER_KEY) ?? '{}'),
    showLater: false,
    showArchived: false,
    icons: ICONS,
    weekdays: WEEKDAYS,
    // Where the back button of the settings leads (the page they were opened from).
    settingsBack: '#/',
    // Hashes visited in this tab, to make "back" go up in the structure (goBack).
    navStack: [],
    navReplacing: false,

    init() {
        const layers = closeLayersOnBack({ menuOpen: () => this.menu !== null, closeMenu: () => { this.menu = null; } });
        this.$watch('menu', (menu) => (menu ? layers.opened() : layers.closed()));
        const parse = (event) => {
            this.trackNavigation(Boolean(event));
            const [, view, id = null] = location.hash.split('/');
            if (view === 'settings' && event) {
                const from = new URL(event.oldURL).hash;
                if (!/^#\/(notifications|absences|install)/.test(from)) this.settingsBack = from || '#/';
            }
            // Any other path means the service worker served the app shell offline.
            this.route = location.pathname === '/' ? { view: view || 'home', id } : { view: 'offline', id: null };
            this.menu = null;
            this.form = null;
            if (this.route.view === 'new') this.form = emptyForm(id ?? this.lists[0]?.id ?? null, this.today);
            if (this.route.view === 'edit') this.openForm(id);
            if (this.route.view === 'stats') this.statsPeriod = this.listById(id)?.ranking_period ?? 'all';
            if (this.route.view === 'history') this.historyFilter = { member: '', task: '', missed: true };
            window.scrollTo(0, 0);
        };
        window.addEventListener('hashchange', parse);
        parse();
        // The form of #/edit/<id> needs the task, which arrives with the local database.
        this.$watch('$store.data.tasks', () => {
            if (this.route.view === 'edit' && !this.form) this.openForm(this.route.id);
        });
    },

    // ---- data helpers

    get today() {
        return Alpine.store('clock').today;
    },
    get me() {
        return Alpine.store('sync').user?.email ?? null;
    },
    get loggedIn() {
        return Alpine.store('sync').user !== null;
    },
    get lists() {
        return Alpine.store('data').lists
            .filter((list) => !Alpine.store('undo').hidden(list.id))
            .sort(compareBy(Alpine.store('prefs').list_sort, byName, byName));
    },
    listById(id) {
        return this.lists.find((list) => list.id === id) ?? null;
    },
    get list() {
        return ['list', 'history', 'stats'].includes(this.route.view) ? this.listById(this.route.id) : null;
    },
    taskById(id) {
        return Alpine.store('data').tasks.find((task) => task.id === id && !Alpine.store('undo').hidden(task.id)) ?? null;
    },
    get task() {
        return this.route.view === 'task' ? this.taskById(this.route.id) : null;
    },
    stateOf(task) {
        return states().byTask.get(task.id) ?? taskState(task, [], this.today);
    },
    completionsOf(taskId) {
        return states().completionsByTask.get(taskId) ?? [];
    },
    /** Tasks of the lists the user has (not deleted, not hidden by undo). */
    get visibleTasks() {
        const lists = new Set(this.lists.map((list) => list.id));

        return Alpine.store('data').tasks.filter((task) => lists.has(task.task_list_id) && !Alpine.store('undo').hidden(task.id));
    },
    memberName(email, listId = null) {
        if (!email) return this.$t('Someone');
        if (email === this.me) return this.$t('You');
        const lists = listId ? [this.listById(listId)] : this.lists;
        for (const list of lists) {
            const member = list?.members?.find((m) => m.email === email);
            if (member) return member.name || email;
        }

        return email;
    },
    initials(email, listId = null) {
        return (this.memberName(email, listId) || '?').trim().slice(0, 1).toUpperCase();
    },

    /**
     * Tasks grouped for display: { key, label, entries: [{ task, state }] }.
     */
    grouped(tasks) {
        const today = this.today;
        const groups = Object.fromEntries(GROUPS.map((key) => [key, []]));
        for (const task of tasks) {
            if (task.archived) continue;
            const state = this.stateOf(task);
            groups[groupOf(task, state, today)].push({ task, state });
        }
        const sortKey = (entry) => entry.state.due ?? entry.state.start ?? entry.state.last_done ?? '';
        for (const key of GROUPS) {
            groups[key].sort((a, b) => (key === 'on_demand'
                ? (a.state.last_done ?? '').localeCompare(b.state.last_done ?? '')
                : sortKey(a).localeCompare(sortKey(b))) || a.task.title.localeCompare(b.task.title));
        }

        return GROUPS.map((key) => ({ key, label: this.groupLabel(key), entries: groups[key] })).filter((group) => group.entries.length);
    },
    groupLabel(key) {
        return {
            overdue: this.$t('Overdue'), today: this.$t('Today'), week: this.$t('Next 7 days'), later: this.$t('Later'),
            on_demand: this.$t('When needed'), paused: this.$t('Paused'), finished: this.$t('Done for good'),
        }[key];
    },
    /** Overview: all lists, with the personal suggestions on top. */
    get agenda() {
        return this.grouped(this.visibleTasks);
    },
    get listGroups() {
        if (!this.list) return [];
        const filter = this.categoryFilter;

        return this.grouped(this.visibleTasks.filter((task) => task.task_list_id === this.list.id && (!filter || task.category === filter)));
    },
    get archivedTasks() {
        return this.list ? this.visibleTasks.filter((task) => task.task_list_id === this.list.id && task.archived) : [];
    },
    get listCategories() {
        if (!this.list) return [];

        return [...new Set(this.visibleTasks.filter((task) => task.task_list_id === this.list.id && task.category).map((task) => task.category))].sort();
    },
    get categoryFilter() {
        const filter = this.categoryFilters[this.route.id] ?? '';

        return this.listCategories.includes(filter) ? filter : '';
    },
    setCategoryFilter(category) {
        this.categoryFilters = { ...this.categoryFilters, [this.route.id]: category };
        localStorage.setItem(CATEGORY_FILTER_KEY, JSON.stringify(this.categoryFilters));
    },
    /** Open tasks (overdue or due today) of a list, for the badges. */
    dueCount(listId) {
        return this.visibleTasks.filter((task) => task.task_list_id === listId && !task.archived
            && ['overdue', 'due'].includes(this.stateOf(task).status)).length;
    },
    overdueCount(listId) {
        return this.visibleTasks.filter((task) => task.task_list_id === listId && !task.archived && this.stateOf(task).status === 'overdue').length;
    },

    get suggestions() {
        if (!Alpine.store('prefs').recommendations) return [];
        const entries = this.visibleTasks.filter((task) => !task.archived).map((task) => ({ task, state: this.stateOf(task) }));

        return recommendations({
            email: this.me,
            lists: this.lists,
            entries,
            completions: Alpine.store('data').completions,
            absences: Alpine.store('data').absences,
            today: this.today,
        });
    },
    suggestionText(suggestion) {
        const { task, overtakes, points } = suggestion;
        const replace = { points, task: task.title, name: overtakes ? this.memberName(overtakes.email, task.task_list_id) : '' };
        if (overtakes) return points === 1 ? this.$t('Get 1 point with “:task” and overtake :name!', replace) : this.$t('Get :points points with “:task” and overtake :name!', replace);
        if (points) return points === 1 ? this.$t('Get 1 point with “:task”.', replace) : this.$t('Get :points points with “:task”.', replace);

        return task.title;
    },

    // ---- labels

    dueLabel(task, state = this.stateOf(task)) {
        const today = this.today;
        const date = (value) => formatDate(value, Alpine.store('i18n').locale);
        switch (state.status) {
            case 'overdue': {
                const days = daysBetween(state.due, today);
                return days === 1 ? this.$t('Overdue since yesterday') : this.$t('Overdue for :days days', { days });
            }
            case 'due': return this.$t('Due today');
            case 'open': return daysBetween(today, state.due) === 1 ? this.$t('Until tomorrow') : this.$t('Until :date', { date: date(state.due) });
            case 'upcoming': {
                const days = daysBetween(today, state.start);
                if (days === 1) return this.$t('Tomorrow');
                return days <= 6 ? date(state.start) : this.$t('From :date', { date: date(state.start) });
            }
            case 'on_demand': {
                if (!state.last_done) return this.$t('Not done yet');
                const days = daysBetween(state.last_done, today);
                return days === 0 ? this.$t('Done today') : days === 1 ? this.$t('Done yesterday') : this.$t('Done :days days ago', { days });
            }
            default: return this.$t('No more dates');
        }
    },
    dueClass(state) {
        return {
            overdue: 'text-red-700',
            due: 'text-amber-700 font-medium',
            open: 'text-amber-700',
        }[state.status] ?? 'text-stone-500';
    },
    schedule(task) {
        return describeSchedule(task, (key, replace) => this.$t(key, replace), Alpine.store('i18n').locale);
    },
    date(value, options) {
        return formatDate(value, Alpine.store('i18n').locale, options);
    },
    nextDates(task) {
        return upcoming(task, this.completionsOf(task.id), this.today, 3);
    },

    // ---- context menu (hover "…" on desktop) and swipe actions share these definitions

    listActions(list) {
        return [
            { label: this.$t('Settings'), icon: 'edit', run: () => this.openListDialog(list) },
            list.is_owner === false
                ? { label: this.$t('Leave list'), icon: 'leave', danger: true, online: true, run: () => this.leaveList(list.id) }
                : { label: this.$t('Delete'), icon: 'delete', danger: true, run: () => this.deleteList(list.id) },
        ];
    },

    taskActions(task) {
        const state = this.stateOf(task);
        const scheduled = state.occurrence !== null;

        return [
            ...(state.status !== 'finished' || task.mode === 'on_demand' ? [{ label: this.$t('Done …'), icon: 'check', run: () => this.openComplete(task) }] : []),
            ...(scheduled ? [{ label: this.$t('Skip'), icon: 'skip', run: () => this.skip(task) }] : []),
            ...(scheduled ? [{ label: this.$t('Postpone'), icon: 'postpone', run: () => this.openPostpone(task) }] : []),
            { label: this.$t('Edit'), icon: 'edit', run: () => { location.hash = `#/edit/${task.id}`; } },
            { label: this.$t('Delete'), icon: 'delete', danger: true, run: () => this.deleteTask(task) },
        ];
    },

    /** Keeps navStack in step with the browser history (push, back, or replaced entry). */
    trackNavigation(changed) {
        const hash = location.hash || '#/';
        if (!changed) this.navStack = [hash];
        else if (this.navReplacing) this.navStack[this.navStack.length - 1] = hash;
        else if (this.navStack.at(-2) === hash) this.navStack.pop();
        else this.navStack.push(hash);
        this.navReplacing = false;
    },

    /**
     * Back button: up in the structure (backLink). If that is the previous history entry, go
     * back in the history, otherwise replace the current entry, so the system back gesture
     * does not return to the page just left.
     */
    goBack(target = this.backLink) {
        if (this.navStack.at(-2) === target) {
            history.back();
            return;
        }
        this.navReplacing = true;
        location.replace(target);
    },

    get backLink() {
        const view = this.route.view;
        if (['notifications', 'absences', 'install'].includes(view)) return '#/settings';
        if (['history', 'stats'].includes(view)) return `#/list/${this.route.id}`;
        if (view === 'task') return this.task ? `#/list/${this.task.task_list_id}` : '#/';
        if (view === 'edit') return `#/task/${this.route.id}`;
        if (view === 'new') return this.route.id ? `#/list/${this.route.id}` : '#/';
        if (view === 'settings') return this.settingsBack;

        return view === 'list' ? '#/lists' : '#/';
    },

    openMenu(target, actions) {
        const rect = target.getBoundingClientRect();
        const below = rect.bottom + 48 * actions.length + 16 < window.innerHeight;
        this.menu = {
            actions,
            style: `right: ${window.innerWidth - rect.right}px; ${below ? `top: ${rect.bottom + 4}px` : `bottom: ${window.innerHeight - rect.top + 4}px`}`,
        };
        this.$nextTick(() => this.$refs.menu?.querySelector('button')?.focus());
    },

    runMenu(action) {
        this.menu = null;
        action.run();
    },

    // ---- lists

    async createList() {
        const name = this.newListName.trim();
        if (!name) return;
        const id = uuid7();
        this.newListName = '';
        const user = Alpine.store('sync').user;
        await db.lists.insert({
            id,
            name,
            icon: null,
            ranking_enabled: true,
            ranking_period: 'all',
            owner_email: user?.email ?? null,
            is_owner: true,
            members: user ? [{ email: user.email, name: user.name ?? null }] : [],
            position: nextPosition(this.lists),
            muted: false,
        });
        location.hash = `#/list/${id}`;
    },

    openListDialog(list = this.list) {
        this.listDialog = {
            id: list.id, name: list.name, icon: list.icon ?? '', ranking_enabled: list.ranking_enabled, ranking_period: list.ranking_period, muted: list.muted,
        };
        this.shareEmail = '';
        this.shareError = null;
        this.inviteLink = null;
        this.$nextTick(() => this.$refs.listDialog.showModal());
    },
    get dialogList() {
        return this.listDialog ? this.listById(this.listDialog.id) : null;
    },

    async saveList() {
        const { id, name, icon, ranking_enabled, ranking_period, muted } = this.listDialog;
        const doc = await db.lists.findOne(id).exec();
        if (doc && name.trim()) {
            await doc.incrementalPatch(doc.is_owner === false
                ? { muted: Boolean(muted) }
                : { name: name.trim(), icon: icon.trim() || null, ranking_enabled: Boolean(ranking_enabled), ranking_period, muted: Boolean(muted) });
        }
        this.$refs.listDialog.close();
    },

    deleteList(id = this.route.id) {
        if (!confirm(this.$t('Delete this list with all its tasks for all members?'))) return;
        this.$refs.listDialog?.close();
        if (this.route.id === id) this.goBack('#/lists');
        Alpine.store('undo').schedule(id, this.$t('List deleted'), async () => {
            await (await db.lists.findOne(id).exec())?.remove();
        });
    },

    async leaveList(id = this.route.id) {
        if (!this.requireOnline()) return;
        if (!confirm(this.$t('Leave this list?'))) return;
        await this.online(async () => {
            await api.delete(`/lists/${id}/members/${encodeURIComponent(this.me)}`);
            this.$refs.listDialog?.close();
            replication?.resync();
            this.goBack('#/lists');
        });
    },

    async share() {
        const email = this.shareEmail.trim();
        if (!email) return;
        this.shareError = null;
        await this.online(async () => {
            const { status } = await api.post(`/lists/${this.listDialog.id}/members`, { email });
            this.shareEmail = '';
            // Existing users join after confirming the invitation.
            if (status === 'invited') Alpine.store('notice').show(this.$t('Invitation sent to :email.', { email }));
            replication?.resync();
        }, (error) => { this.shareError = error; });
    },

    async removeMember(email) {
        if (!confirm(this.$t('Remove :name from the list?', { name: this.memberName(email, this.listDialog.id) }))) return;
        await this.online(async () => {
            await api.delete(`/lists/${this.listDialog.id}/members/${encodeURIComponent(email)}`);
            replication?.resync();
        }, (error) => { this.shareError = error; });
    },

    async createInviteLink() {
        await this.online(async () => {
            this.inviteLink = (await api.post(`/lists/${this.listDialog.id}/invites`)).url;
        }, (error) => { this.shareError = error; });
    },

    async shareInviteLink() {
        const text = this.$t('Join my task list “:list”:', { list: this.dialogList?.name ?? '' });
        if (navigator.share) {
            try {
                await navigator.share({ title: 'Chorelist', text, url: this.inviteLink });
                return;
            } catch {
                // Cancelled or not allowed: copy instead.
            }
        }
        await this.copy(this.inviteLink);
    },

    async revokeInviteLinks() {
        await this.online(async () => {
            await api.delete(`/lists/${this.listDialog.id}/invites`);
            this.inviteLink = null;
            Alpine.store('notice').show(this.$t('All invitation links of this list are invalid now.'));
        }, (error) => { this.shareError = error; });
    },

    async copy(text) {
        try {
            await navigator.clipboard.writeText(text);
            Alpine.store('notice').show(this.$t('Copied.'));
        } catch {
            prompt(this.$t('Copy the link:'), text);
        }
    },

    // ---- task form

    openForm(id) {
        const task = this.taskById(id);
        if (!task) return;
        this.form = {
            ...emptyForm(task.task_list_id, this.today),
            ...fromSchedule(task),
            id: task.id,
            task_list_id: task.task_list_id,
            title: task.title,
            icon: task.icon ?? '',
            category: task.category ?? '',
            notes: task.notes ?? '',
            points: task.points,
            starts_on: task.starts_on,
            window_days: task.window_days ?? 0,
            ends_on: task.ends_on ?? '',
            reminders: [...(task.reminders ?? [])],
            remind_after_days: task.remind_after_days ?? '',
            checklist: (task.checklist ?? []).map((step) => ({ ...step })),
        };
    },

    /** Schedule of the form as task fields (for the preview and saving). */
    get formSchedule() {
        if (!this.form) return null;
        const { mode, rrule } = toSchedule(this.form, this.form.starts_on || this.today);

        return {
            mode,
            rrule,
            starts_on: this.form.starts_on || this.today,
            window_days: mode === 'on_demand' ? 0 : Math.max(0, Number(this.form.window_days) || 0),
            ends_on: ['fixed', 'after_completion'].includes(mode) ? this.form.ends_on || null : null,
        };
    },
    get formPreview() {
        const schedule = this.formSchedule;
        if (!schedule || schedule.mode === 'on_demand') return [];
        try {
            const state = taskState(schedule, this.form.id ? this.completionsOf(this.form.id) : [], this.today);
            return [state.start, ...upcoming(schedule, this.form.id ? this.completionsOf(this.form.id) : [], this.today, 3)].filter(Boolean);
        } catch {
            return [];
        }
    },
    /** Categories already used in the form's list, matching what was typed (form suggestions). */
    get categories() {
        if (!this.form) return [];
        const typed = (this.form.category ?? '').trim().toLowerCase();
        const used = this.visibleTasks.filter((task) => task.task_list_id === this.form.task_list_id && task.category).map((task) => task.category);

        return [...new Set(used)].filter((category) => category.toLowerCase().includes(typed) && category !== this.form.category).sort();
    },
    toggleWeekday(day) {
        this.form.weekdays = this.form.weekdays.includes(day) ? this.form.weekdays.filter((each) => each !== day) : [...this.form.weekdays, day];
    },
    toggleReminder(days) {
        const reminders = this.form.reminders.includes(days) ? this.form.reminders.filter((each) => each !== days) : [...this.form.reminders, days];
        this.form.reminders = reminders.sort((a, b) => b - a);
    },
    weekdayName(code, style = 'short') {
        return new Intl.DateTimeFormat(Alpine.store('i18n').locale.replace('_', '-'), { weekday: style, timeZone: 'UTC' })
            .format(new Date(Date.UTC(2024, 0, 1 + WEEKDAYS.indexOf(code))));
    },
    addStep() {
        const text = this.form.newStep.trim();
        if (!text) return;
        this.form.checklist = [...this.form.checklist, { id: uuid7(), text: text.slice(0, 255), done: false }];
        this.form.newStep = '';
    },
    removeStep(id) {
        this.form.checklist = this.form.checklist.filter((step) => step.id !== id);
    },

    async saveForm() {
        const form = this.form;
        const title = form.title.trim();
        form.task_list_id ||= this.lists[0]?.id ?? null;
        if (!title || !form.task_list_id) return;
        const schedule = this.formSchedule;
        // Plain values: RxDB cannot clone Alpine's proxies (BroadcastChannel to other tabs).
        const fields = {
            title: title.slice(0, 255),
            icon: form.icon.trim() || null,
            category: form.category.trim().slice(0, 100) || null,
            notes: form.notes.trim() || null,
            points: Math.min(5, Math.max(1, Number(form.points) || 1)),
            ...schedule,
            reminders: schedule.mode === 'on_demand' ? [] : [...form.reminders],
            remind_after_days: schedule.mode === 'on_demand' && Number(form.remind_after_days) > 0 ? Math.round(Number(form.remind_after_days)) : null,
            checklist: form.checklist.map(({ id, text, done }) => ({ id, text, done: Boolean(done) })),
        };
        if (form.id) {
            const doc = await db.tasks.findOne(form.id).exec();
            if (doc && doc.task_list_id !== form.task_list_id) {
                // The server keeps a task in its list: moving is a new task (the history stays with
                // the old one). Unless the schedule changed, it starts with the next open occurrence
                // instead of being due at once without the old completions.
                const old = doc.toJSON();
                const start = this.stateOf(old).start;
                const sameSchedule = ['mode', 'rrule', 'starts_on', 'window_days'].every((key) => (fields[key] ?? null) === (old[key] ?? null));
                const startsOn = sameSchedule && start && start > fields.starts_on ? start : fields.starts_on;
                const id = await this.insertTask({ ...old, ...fields, starts_on: startsOn, task_list_id: form.task_list_id });
                await doc.remove();
                // Back to where the old task was opened, which shows the new one instead.
                if (this.navStack.at(-2) === `#/task/${form.id}`) {
                    window.addEventListener('hashchange', () => { this.navReplacing = true; location.replace(`#/task/${id}`); }, { once: true });
                    history.back();
                } else {
                    this.navReplacing = true;
                    location.replace(`#/task/${id}`);
                }
                return;
            }
            await doc?.incrementalPatch(fields);
            this.goBack(`#/task/${form.id}`);
            return;
        }
        const id = await this.insertTask({ ...fields, task_list_id: form.task_list_id });
        location.replace(`#/list/${form.task_list_id}`);
        Alpine.store('notice').show(this.$t('“:task” added.', { task: fields.title }));
        return id;
    },

    async insertTask(fields) {
        const id = uuid7();
        const keys = ['task_list_id', 'title', 'notes', 'icon', 'category', 'points', 'mode', 'rrule', 'starts_on', 'window_days', 'ends_on', 'reminders', 'remind_after_days', 'paused_until', 'archived', 'checklist'];
        await db.tasks.insert({
            ...Object.fromEntries(keys.map((key) => [key, fields[key] ?? null])),
            id,
            window_days: fields.window_days ?? 0,
            reminders: [...(fields.reminders ?? [])],
            checklist: (fields.checklist ?? []).map(({ id: stepId, text, done }) => ({ id: stepId, text, done: Boolean(done) })),
            archived: Boolean(fields.archived),
            creator_email: this.me,
        });

        return id;
    },

    deleteTask(task) {
        if (this.route.view === 'task' || this.route.view === 'edit') this.goBack(`#/list/${task.task_list_id}`);
        Alpine.store('undo').schedule(task.id, this.$t('“:name” deleted', { name: task.title }), async () => {
            await (await db.tasks.findOne(task.id).exec())?.remove();
        });
    },

    async patchTask(task, fields) {
        await (await db.tasks.findOne(task.id).exec())?.incrementalPatch(fields);
    },

    async toggleStep(task, stepId) {
        await this.patchTask(task, {
            checklist: task.checklist.map((step) => ({ id: step.id, text: step.text, done: step.id === stepId ? !step.done : Boolean(step.done) })),
        });
    },

    async pause(task, until) {
        await this.patchTask(task, { paused_until: until || null });
    },

    async setArchived(task, archived) {
        await this.patchTask(task, { archived });
        Alpine.store('notice').show(archived ? this.$t('“:task” archived.', { task: task.title }) : this.$t('“:task” restored.', { task: task.title }));
    },

    // ---- completing, skipping, postponing

    /**
     * The check button: completes the open occurrence right away (undoable). Before it started,
     * asks first: done in advance (for that date) or as extra (more points).
     */
    check(task) {
        const state = this.stateOf(task);
        if (state.status === 'upcoming' || state.status === 'finished') {
            this.askDialog = { task, state };
            this.$nextTick(() => this.$refs.askDialog.showModal());
            return;
        }
        this.complete(task, {});
    },

    /**
     * @param {object} options type (done, extra, skipped, postponed), user email, day it was done, note, postponed_to
     */
    async complete(task, { type = 'done', email = this.me, doneOn = this.today, note = null, postponedTo = null, quiet = false }) {
        const state = this.stateOf(task);
        const occurrence = type === 'extra' || task.mode === 'on_demand' ? null : state.occurrence;
        if (type !== 'extra' && task.mode !== 'on_demand' && !occurrence) return;
        const id = occurrence ? await occurrenceId(task.id, occurrence) : uuid7();
        const scored = SCORED.includes(type);
        const fields = {
            task_id: task.id,
            task_list_id: task.task_list_id,
            task_title: task.title,
            user_email: email,
            entered_by_email: this.me,
            occurrence,
            done_on: doneOn,
            type,
            postponed_to: type === 'postponed' ? postponedTo : null,
            points: scored ? task.points : 0,
            note: note?.trim() || null,
        };
        const existing = await db.completions.findOne(id).exec();
        const previous = existing ? { ...existing.toJSON() } : null;
        if (existing) await existing.incrementalPatch(fields);
        else await db.completions.insert({ id, ...fields });

        // Steps start over for the next time.
        if (scored && task.checklist?.some((step) => step.done)) {
            await this.patchTask(task, { checklist: task.checklist.map(({ id: stepId, text }) => ({ id: stepId, text, done: false })) });
        }
        if (quiet) return;

        const ranked = this.listById(task.task_list_id)?.ranking_enabled;
        const message = {
            done: ranked ? this.$t('“:task” done (+:points)', { task: task.title, points: task.points }) : this.$t('“:task” done', { task: task.title }),
            extra: this.$t('“:task” done once more (+:points)', { task: task.title, points: task.points }),
            skipped: this.$t('“:task” skipped', { task: task.title }),
            postponed: this.$t('“:task” postponed to :date', { task: task.title, date: this.date(postponedTo) }),
        }[type];
        // Undo: back to the previous state (completions are never deleted, see CompletionResource).
        Alpine.store('undo').schedule([], message, async () => {}, async () => {
            const doc = await db.completions.findOne(id).exec();
            if (!doc) return;
            await doc.incrementalPatch(previous
                ? { type: previous.type, user_email: previous.user_email, done_on: previous.done_on, postponed_to: previous.postponed_to, points: previous.points, note: previous.note }
                : { type: 'undone', points: 0 });
        });
    },

    async completeInAdvance() {
        const { task } = this.askDialog;
        this.$refs.askDialog.close();
        await this.complete(task, {});
    },
    async completeExtra() {
        const { task } = this.askDialog;
        this.$refs.askDialog.close();
        await this.complete(task, { type: 'extra' });
    },

    /** "Done …": who and when (also for another member, or a day in the past). */
    openComplete(task) {
        const state = this.stateOf(task);
        const extraOnly = state.status === 'finished' && task.mode !== 'on_demand';
        this.completeDialog = { task, email: this.me ?? '', doneOn: this.today, note: '', extra: extraOnly || state.status === 'upcoming', extraOnly };
        this.$nextTick(() => this.$refs.completeDialog.showModal());
    },
    async saveComplete() {
        const { task, email, doneOn, note, extra } = this.completeDialog;
        this.$refs.completeDialog.close();
        await this.complete(task, { type: extra ? 'extra' : 'done', email: email || this.me, doneOn: doneOn || this.today, note });
    },
    membersOf(listId) {
        return this.listById(listId)?.members ?? [];
    },

    async skip(task) {
        await this.complete(task, { type: 'skipped' });
    },

    openPostpone(task) {
        const state = this.stateOf(task);
        // Until the day before the next occurrence starts (only one occurrence is open at a time).
        const next = task.mode === 'fixed' ? upcoming(task, this.completionsOf(task.id), this.today, 1)[0] : null;
        const min = state.due > this.today ? addDays(state.due, 1) : addDays(this.today, 1);
        const max = next ? addDays(next, -1) : '';
        // The next occurrence starts before the earliest possible day: nothing to postpone to.
        this.postponeDialog = { task, date: max && max < min ? '' : min, min, max, impossible: Boolean(max && max < min) };
        this.$nextTick(() => this.$refs.postponeDialog.showModal());
    },
    async savePostpone() {
        const { task, date } = this.postponeDialog;
        this.$refs.postponeDialog.close();
        if (date) await this.complete(task, { type: 'postponed', postponedTo: date });
    },

    // ---- history

    /**
     * Timeline of a list (or one task): completions, changes and missed occurrences, newest first.
     */
    history(listId, taskId = null) {
        const { member, task: taskFilter, missed: showMissed } = taskId ? { member: '', task: taskId, missed: true } : this.historyFilter;
        const entries = [];
        for (const c of Alpine.store('data').completions) {
            if (c.task_list_id !== listId || !['done', 'extra', 'skipped'].includes(c.type)) continue;
            if (taskFilter && c.task_id !== taskFilter) continue;
            if (member && c.user_email !== member) continue;
            entries.push({ id: c.id, day: c.done_on, time: c.updated_at ?? '', kind: c.type, text: this.completionText(c), who: this.completionWho(c) });
        }
        for (const a of Alpine.store('data').activities) {
            if (a.task_list_id !== listId || a.action === 'completion.skipped') continue;
            if (taskFilter && a.task_id !== taskFilter) continue;
            if (member && a.user_email !== member) continue;
            entries.push({ id: a.id, day: a.created_at?.slice(0, 10), time: a.created_at ?? '', kind: 'activity', text: this.activityText(a), who: this.memberName(a.user_email, a.task_list_id) });
        }
        if (showMissed && !member) {
            const from = addDays(this.today, -MISSED_DAYS);
            const tasks = this.visibleTasks.filter((task) => task.task_list_id === listId && (!taskFilter || task.id === taskFilter) && !task.archived);
            for (const task of tasks) {
                for (const day of missed(task, this.completionsOf(task.id), task.starts_on > from ? task.starts_on : from, this.today)) {
                    entries.push({ id: `missed-${task.id}-${day}`, day, time: '', kind: 'missed', text: this.$t('“:task” was not done', { task: task.title }), who: '' });
                }
            }
        }

        return entries.sort((a, b) => b.day.localeCompare(a.day) || b.time.localeCompare(a.time));
    },
    /** History grouped by day for display. */
    historyDays(listId, taskId = null) {
        const days = [];
        for (const entry of this.history(listId, taskId).slice(0, 300)) {
            if (days.at(-1)?.day !== entry.day) days.push({ day: entry.day, entries: [] });
            days.at(-1).entries.push(entry);
        }

        return days;
    },
    completionText(c) {
        const title = this.taskById(c.task_id)?.title ?? c.task_title ?? this.$t('(deleted task)');
        const points = this.listById(c.task_list_id)?.ranking_enabled && c.points ? ` (+${c.points})` : '';

        return {
            done: this.$t('“:task” done', { task: title }),
            extra: this.$t('“:task” done once more', { task: title }),
            skipped: this.$t('“:task” skipped', { task: title }),
        }[c.type] + points;
    },
    /** Who did it (and who entered it for them), below the history entry. */
    completionWho(c) {
        const name = this.memberName(c.user_email, c.task_list_id);
        const entered = c.entered_by_email && c.entered_by_email !== c.user_email
            ? ` · ${this.$t('entered by :name', { name: this.memberName(c.entered_by_email, c.task_list_id) })}` : '';

        return `${name}${entered}${c.note ? ` · ${c.note}` : ''}`;
    },
    activityText(a) {
        const data = a.data ?? {};
        const task = data.title ?? this.taskById(a.task_id)?.title ?? '';
        switch (a.action) {
            case 'list.created': return this.$t('List created');
            case 'list.updated': return this.$t('List changed: :changes', { changes: this.changesText(data.changes) });
            case 'task.created': return this.$t('“:task” added', { task });
            case 'task.updated': return this.$t('“:task” changed: :changes', { task, changes: this.changesText(data.changes) });
            case 'task.deleted': return this.$t('“:task” deleted', { task });
            case 'member.added': return this.$t(':member added to the list', { member: this.memberName(data.email, a.task_list_id) });
            case 'member.removed': return this.$t(':member removed from the list', { member: data.email });
            case 'member.left': return this.$t(':member left the list', { member: data.email ?? '' });
            case 'member.invited': return this.$t(':member invited to the list', { member: data.email });
            case 'member.joined': return this.$t('Joined the list');
            case 'completion.postponed': return this.$t('“:task” postponed to :date', { task, date: this.date(data.postponed_to) });
            case 'completion.undone': return this.$t('“:task” taken back', { task });
            default: return a.action;
        }
    },
    changesText(changes = {}) {
        const labels = {
            name: this.$t('Name'), title: this.$t('Title'), icon: this.$t('Icon'), notes: this.$t('Notes'), category: this.$t('Category'),
            points: this.$t('Points'), mode: this.$t('Repeat'), rrule: this.$t('Repeat'), starts_on: this.$t('First date'),
            window_days: this.$t('Time to do it'), ends_on: this.$t('End'), reminders: this.$t('Reminders'), remind_after_days: this.$t('Reminders'),
            paused_until: this.$t('Paused until'), archived: this.$t('Archived'), ranking_enabled: this.$t('Ranking'), ranking_period: this.$t('Ranking'),
        };
        const plain = (value) => (Array.isArray(value) ? value.join(', ') : value === true ? '✓' : value === false ? '✗' : value ?? '–');
        const shown = new Set();

        return Object.entries(changes ?? {}).map(([field, [from, to]]) => {
            const label = labels[field] ?? field;
            if (shown.has(label)) return null;
            shown.add(label);
            if (['notes', 'rrule', 'mode', 'reminders'].includes(field)) return label;
            return `${label} ${plain(from)} → ${plain(to)}`;
        }).filter(Boolean).join(', ');
    },

    // ---- ranking and statistics

    rankingOf(list, period = list.ranking_period) {
        return ranking(list, Alpine.store('data').completions, Alpine.store('data').absences, period, this.today);
    },
    periodLabel(period) {
        return { week: this.$t('This week'), month: this.$t('This month'), all: this.$t('All time') }[period];
    },
    periodSince(list, period) {
        const own = Alpine.store('data').completions.filter((c) => c.task_list_id === list.id);

        return periodRange(period, this.today, own).from;
    },
    get statsWeeks() {
        return this.list ? weeklyPoints(this.list.id, Alpine.store('data').completions, this.today, 8) : [];
    },
    get statsMax() {
        return Math.max(1, ...this.statsWeeks.map((week) => Object.values(week.points).reduce((sum, p) => sum + p, 0)));
    },
    memberColor(email) {
        const members = this.list?.members?.map((m) => m.email) ?? [];
        const colors = ['bg-sky-600', 'bg-amber-500', 'bg-emerald-600', 'bg-rose-500', 'bg-violet-500', 'bg-stone-500'];

        return colors[Math.max(0, members.indexOf(email)) % colors.length];
    },
    /** Per task: how often done in the last 90 days, missed, last time. */
    get taskStats() {
        if (!this.list) return [];
        const from = addDays(this.today, -90);

        return this.visibleTasks.filter((task) => task.task_list_id === this.list.id && !task.archived).map((task) => {
            const completions = this.completionsOf(task.id).filter((c) => SCORED.includes(c.type) && c.done_on >= from);
            const missedCount = missed(task, this.completionsOf(task.id), task.starts_on > from ? task.starts_on : from, this.today).length;
            const byMember = {};
            for (const c of completions) byMember[c.user_email] = (byMember[c.user_email] ?? 0) + 1;
            const top = Object.entries(byMember).sort((a, b) => b[1] - a[1])[0];

            return { task, count: completions.length, missed: missedCount, last: this.stateOf(task).last_done, top: top ? top[0] : null };
        }).sort((a, b) => b.count - a.count || a.task.title.localeCompare(b.task.title));
    },

    // ---- holidays

    get absences() {
        return Alpine.store('data').absences
            .filter((a) => !Alpine.store('undo').hidden(a.id) && a.ends_on >= addDays(this.today, -30))
            .sort((a, b) => a.starts_on.localeCompare(b.starts_on));
    },
    async addAbsence() {
        const { starts_on, ends_on, note } = this.absenceForm;
        if (!starts_on) return;
        const end = ends_on || starts_on;
        await db.absences.insert({
            id: uuid7(), user_email: this.me, is_own: true, starts_on: starts_on < end ? starts_on : end, ends_on: starts_on < end ? end : starts_on, note: note.trim() || null,
        });
        this.absenceForm = { starts_on: '', ends_on: '', note: '' };
    },
    deleteAbsence(absence) {
        Alpine.store('undo').schedule(absence.id, this.$t('Holidays deleted'), async () => {
            await (await db.absences.findOne(absence.id).exec())?.remove();
        });
    },
    absentToday(email) {
        return Alpine.store('data').absences.some((a) => a.user_email === email && a.starts_on <= this.today && a.ends_on >= this.today);
    },

    // ---- settings: notifications, calendar, export

    get notificationKinds() {
        return [
            { kind: 'reminder', label: this.$t('Reminders'), hint: this.$t('As set per task, e.g. on the due date or a day before.') },
            { kind: 'overdue', label: this.$t('Overdue tasks'), hint: this.$t('Once, the day after a task was due.') },
            { kind: 'completed', label: this.$t('Done by others'), hint: this.$t('When another member did a task.') },
            { kind: 'task_changed', label: this.$t('Changed tasks'), hint: this.$t('When another member adds, changes or deletes a task.') },
            { kind: 'list_shared', label: this.$t('Shared lists'), hint: this.$t('When someone shares a list with you.') },
            { kind: 'weekly_summary', label: this.$t('Weekly summary'), hint: this.$t('Mondays: what is due this week and last week’s points.') },
        ];
    },
    get isIos() {
        return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    },
    async refreshPush() {
        const push = Alpine.store('push');
        push.permission = pushPermission();
        push.subscribed = Boolean(await currentSubscription().catch(() => null));
    },
    async enablePush() {
        const push = Alpine.store('push');
        push.busy = true;
        try {
            await subscribePush(api, Alpine.store('sync').user?.vapid_public_key);
            Alpine.store('notice').show(this.$t('Push notifications are on for this device.'));
        } catch (error) {
            Alpine.store('notice').show(error?.message === 'denied'
                ? this.$t('Notifications are blocked. Allow them in the settings of the browser or device.')
                : this.$t('Push notifications could not be turned on.'));
        } finally {
            push.busy = false;
            await this.refreshPush();
        }
    },
    async disablePush() {
        Alpine.store('push').busy = true;
        try {
            await unsubscribePush(api);
        } catch {
            Alpine.store('notice').show(this.$t('This needs an internet connection.'));
        } finally {
            Alpine.store('push').busy = false;
            await this.refreshPush();
        }
    },
    async createIcal() {
        await this.online(async () => {
            const { url } = await api.post('/ical');
            Alpine.store('sync').user = { ...Alpine.store('sync').user, ical_url: url };
        });
    },
    async revokeIcal() {
        if (!confirm(this.$t('The current calendar link stops working. Continue?'))) return;
        await this.online(async () => {
            await api.delete('/ical');
            Alpine.store('sync').user = { ...Alpine.store('sync').user, ical_url: null };
        });
    },
    get webcalUrl() {
        return Alpine.store('sync').user?.ical_url?.replace(/^https?:/, 'webcal:') ?? null;
    },

    /** All own data of this device as JSON (lists, tasks, history, holidays). */
    exportData() {
        const { lists, tasks, completions, activities, absences } = Alpine.store('data');
        // Only what belongs to the user's lists (data of left lists may not be removed yet).
        const ids = new Set(lists.map((list) => list.id));
        const ofLists = (docs) => docs.filter((doc) => ids.has(doc.task_list_id));
        const json = JSON.stringify({
            exported_at: new Date().toISOString(), user: this.me, lists,
            tasks: ofLists(tasks), completions: ofLists(completions), activities: ofLists(activities),
            absences: absences.filter((a) => a.is_own),
        }, null, 2);
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([json], { type: 'application/json' }));
        link.download = `chorelist-${this.today}.json`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    },

    // ---- sorting of the lists overview (per user)

    sorts: SORTS,
    sortLabel(sort) {
        return { manual: this.$t('Custom'), name_asc: 'A–Z', name_desc: 'Z–A', newest: this.$t('Newest'), oldest: this.$t('Oldest') }[sort];
    },
    /** A row was dragged (resources/js/swipe.js): the shown order becomes the custom order. */
    async moveRow(kind, { from, to }) {
        const ids = this.lists.map((doc) => doc.id);
        ids.splice(to, 0, ...ids.splice(from, 1));
        const docs = await db.lists.findByIds(ids).exec();
        await Promise.all(ids.map((id, position) => {
            const doc = docs.get(id);
            return doc && doc.position !== position ? doc.incrementalPatch({ position }) : null;
        }));
        if (Alpine.store('prefs').list_sort !== 'manual') Alpine.store('prefs').set('list_sort', 'manual');
    },

    // ---- needs a connection

    requireOnline() {
        if (navigator.onLine) return true;
        Alpine.store('notice').show(this.$t('This needs an internet connection.'));

        return false;
    },

    setLocale,

    async logout() {
        await this.online(async () => {
            await unsubscribePush(api).catch(() => {});
            await api.post('/logout');
            await wipeLocalData();
            location.replace('/');
        });
    },

    /**
     * Runs an action that needs the server and reports errors.
     */
    async online(action, onError = (message) => Alpine.store('notice').show(message)) {
        if (!navigator.onLine) return onError(this.$t('This needs an internet connection.'));
        this.busy = true;
        try {
            await action();
        } catch (error) {
            onError(error instanceof HttpError
                ? Object.values(error.body?.errors ?? {})[0]?.[0] ?? error.message
                : this.$t('This needs an internet connection.'));
        } finally {
            this.busy = false;
        }
    },

    normalizeName,
    addDays,
    nthLabel,
}));

// ---------------------------------------------------------------- database + sync

async function wipeLocalData() {
    await replication?.cancel();
    await db?.remove();
    localStorage.removeItem(OWNER_KEY);
    localStorage.removeItem(USER_KEY);
}

async function boot() {
    let storage = getRxStorageDexie();

    // Dev mode and schema validation only in development, they add a lot of bundle size.
    if (import.meta.env.DEV) {
        const { RxDBDevModePlugin } = await import('rxdb/plugins/dev-mode');
        const { wrappedValidateAjvStorage } = await import('rxdb/plugins/validate-ajv');
        addRxPlugin(RxDBDevModePlugin);
        storage = wrappedValidateAjvStorage({ storage });
    }

    try {
        db = await createDatabase({ storage });
    } catch (error) {
        console.error(error);
        Alpine.store('sync').status = 'broken';
        return;
    }
    navigator.storage?.persist?.();

    const sync = Alpine.store('sync');
    const data = Alpine.store('data');

    // The UI only renders the local database, with or without a connection.
    for (const collection of ['lists', 'tasks', 'completions', 'activities', 'absences']) {
        db[collection].find().$.subscribe((docs) => { data[collection] = docs.map((doc) => doc.toJSON()); });
    }

    // A list disappeared (left, removed or deleted): its data goes from this device too.
    let listIds = null;
    db.lists.find().$.subscribe(async (docs) => {
        const ids = new Set(docs.map((doc) => doc.id));
        const gone = listIds ? [...listIds].filter((id) => !ids.has(id)) : [];
        listIds = ids;
        if (gone.length) await removeListData(gone, docs);
    });

    window.addEventListener('online', () => { sync.online = true; });
    window.addEventListener('offline', () => {
        sync.online = false;
        if (['synced', 'syncing', 'error'].includes(sync.status)) sync.status = 'offline';
    });

    await connect();
}

/**
 * Checks the session and starts the sync. Started offline, it retries as soon as
 * the device is online again.
 */
async function connect() {
    const sync = Alpine.store('sync');

    try {
        sync.user = await api.get('/me');
    } catch (error) {
        if (error instanceof HttpError && error.status === 401) {
            localStorage.removeItem(USER_KEY);
            sync.user = null;
            sync.status = localStorage.getItem(OWNER_KEY) ? 'expired' : 'local';
        } else {
            // Offline: keep working with the local data and the last known account.
            sync.user = JSON.parse(localStorage.getItem(USER_KEY) ?? 'null');
            sync.status = 'offline';
            window.addEventListener('online', connect, { once: true });
        }
        return;
    }

    // Another account logged in on this device: never push the previous user's data.
    const owner = localStorage.getItem(OWNER_KEY);
    if (owner && owner !== sync.user.email) {
        await wipeLocalData();
        location.reload();
        return;
    }
    localStorage.setItem(USER_KEY, JSON.stringify(sync.user));

    await Alpine.store('prefs').connect(sync.user);

    if (hasPendingLocale()) {
        await setLocale(Alpine.store('i18n').locale);
    } else if (sync.user.locale in LOCALES && sync.user.locale !== Alpine.store('i18n').locale) {
        Alpine.store('i18n').locale = sync.user.locale;
        document.documentElement.lang = htmlLang(sync.user.locale);
        storeLocale(sync.user.locale, { pending: false });
    }

    if (!sync.user.verified) {
        sync.status = 'unverified';
        return;
    }

    // Lists created before logging in (guest) get the user as member.
    for (const list of await db.lists.find().exec()) {
        if (!list.members?.length) await list.incrementalPatch({ owner_email: sync.user.email, members: [{ email: sync.user.email, name: sync.user.name ?? null }] });
    }

    localStorage.setItem(OWNER_KEY, sync.user.email);
    if (!replication) startSync();
}

/**
 * Removes tasks, completions and history of the lists, and the absences of people no list is
 * shared with anymore (local only, see removeLocally).
 */
async function removeListData(listIds, lists) {
    const inLists = { selector: { task_list_id: { $in: listIds } } };
    const members = new Set(lists.flatMap((list) => (list.members ?? []).map((member) => member.email)));
    const absences = (await db.absences.find().exec()).filter((absence) => !absence.is_own && !members.has(absence.user_email));
    await Promise.all([
        ...['tasks', 'completions', 'activities'].map(async (collection) => removeLocally(await db[collection].find(inLists).exec())),
        removeLocally(absences),
    ]);
}

function startSync() {
    const sync = Alpine.store('sync');
    replication = startReplication(db, api);

    replication.states.forEach((state) => {
        state.active$.subscribe((active) => {
            if (active) sync.status = 'syncing';
        });
        state.error$.subscribe((error) => {
            const status = error?.parameters?.errors?.[0]?.status ?? error?.parameters?.error?.status;
            if (status === 401 || status === 419) sync.status = 'expired';
            else sync.status = navigator.onLine ? 'error' : 'offline';
        });
    });

    const settle = () => replication.awaitInSync().then(() => {
        if (['starting', 'syncing', 'offline', 'error'].includes(sync.status)) sync.status = 'synced';
    });
    settle();

    const resync = () => {
        if (!navigator.onLine || document.visibilityState !== 'visible') return;
        replication.resync();
        settle();
    };
    window.addEventListener('online', resync);
    document.addEventListener('visibilitychange', resync);
    setInterval(resync, RESYNC_INTERVAL);
}

// The day may change while the app is open.
const tick = () => {
    const today = todayIn();
    if (today !== Alpine.store('clock').today) Alpine.store('clock').today = today;
};
setInterval(tick, 60_000);
document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && tick());

// Links to server pages (login, account, …) need a connection.
document.addEventListener('click', (event) => {
    if (navigator.onLine || !event.target.closest('a[data-online]')) return;
    event.preventDefault();
    Alpine.store('notice').show(t('This needs an internet connection.'));
});

// A push notification was clicked while the app was open (public/push-sw.js).
navigator.serviceWorker?.addEventListener('message', (event) => {
    if (event.data?.type === 'navigate') location.href = event.data.url;
});

window.Alpine = Alpine;
Alpine.start();
// Back from deleting the account (AccountController): remove its data from this device too.
if (new URLSearchParams(location.search).get('deleted')) {
    history.replaceState(null, '', `/${location.hash}`);
    localStorage.removeItem(OWNER_KEY);
    localStorage.removeItem(USER_KEY);
    removeDatabase({ storage: getRxStorageDexie() }).catch(() => {}).finally(() => {
        Alpine.store('notice').show(t('Your account has been deleted.'));
        boot();
    });
} else {
    boot();
}
registerServiceWorker((apply) => { Alpine.store('update').apply = apply; });
pushSupported() && currentSubscription().then((subscription) => { Alpine.store('push').subscribed = Boolean(subscription); }).catch(() => {});
