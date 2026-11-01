import Alpine from 'alpinejs';

// App settings per user (server: PUT /preferences, GET /me). Cached on the device, so
// they work offline and for guests; changes made offline are sent with the next start.

const KEY = 'chorelist.prefs';
const PENDING_KEY = 'chorelist.prefs-pending';

// Same as PreferenceController::DEFAULTS.
export const DEFAULTS = {
    row_actions: 'swipe',
    list_sort: 'name_asc',
    theme: 'system',
    reminder_time: '08:00',
    recommendations: true,
    notifications: {
        reminder: ['push'], overdue: ['push'], completed: [], task_changed: [], list_shared: ['mail'], weekly_summary: [],
    },
};

export function createPrefsStore(api) {
    const stored = JSON.parse(localStorage.getItem(KEY) ?? '{}');

    return {
        ...structuredClone(DEFAULTS),
        ...stored,

        async set(key, value) {
            this[key] = value;
            this.save();
            if (key === 'theme') applyTheme(value);
            const pending = { ...JSON.parse(localStorage.getItem(PENDING_KEY) ?? '{}'), [key]: value };
            if (!Alpine.store('sync').user) return;
            try {
                await api.put('/preferences', pending);
                localStorage.removeItem(PENDING_KEY);
            } catch {
                localStorage.setItem(PENDING_KEY, JSON.stringify(pending));
            }
        },

        /** Channels of a kind of notification: toggles push or mail. */
        toggleChannel(kind, channel) {
            const current = this.notifications[kind] ?? [];
            const next = current.includes(channel) ? current.filter((each) => each !== channel) : [...current, channel];
            this.set('notifications', { ...JSON.parse(JSON.stringify(this.notifications)), [kind]: next });
        },

        save() {
            localStorage.setItem(KEY, JSON.stringify(Object.fromEntries(Object.keys(DEFAULTS).map((key) => [key, this[key]]))));
        },

        /**
         * After login: send changes made offline, otherwise take the account's settings.
         */
        async connect(user) {
            const pending = JSON.parse(localStorage.getItem(PENDING_KEY) ?? 'null');
            if (pending && Object.keys(pending).length) {
                try {
                    await api.put('/preferences', pending);
                    localStorage.removeItem(PENDING_KEY);
                } catch {
                    // Next start.
                }
            } else {
                Object.keys(DEFAULTS).forEach((key) => {
                    if (user.preferences?.[key] !== undefined) this[key] = user.preferences[key];
                });
                this.save();
            }
            applyTheme(this.theme);
            // Reminders are sent on the user's day: keep the time zone of the device.
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (timezone && timezone !== user.timezone) api.put('/preferences', { timezone }).catch(() => {});
        },
    };
}

/**
 * Dark mode: the class on <html> (resources/css/app.css), "system" follows the device.
 */
export function applyTheme(theme) {
    const dark = theme === 'dark' || (theme !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#1c1917' : '#0369a1');
}
