import { RRule } from 'rrule';

// When a task is due. Same rules as app/Support/Schedule.php (tests/fixtures/schedule.json is run
// by both test suites). Dates are "YYYY-MM-DD" strings, computed as UTC midnight.
//
// Modes: fixed (calendar, RRULE from starts_on), after_completion (interval after the last
// completion), once, on_demand (no due date). An occurrence starts on its date and is due
// window_days later (or on the date it was postponed to). Only one occurrence is open at a time.

export const MODES = ['fixed', 'after_completion', 'once', 'on_demand'];
const MAX_AHEAD = 60;

/**
 * @param {{ mode: string, rrule?: string|null, starts_on: string, window_days?: number, ends_on?: string|null, paused_until?: string|null }} task
 * @param {{ occurrence: string|null, done_on: string, type: string, postponed_to?: string|null }[]} completions of this task
 * @param {string} today
 * @returns {{ occurrence: string|null, start: string|null, due: string|null, status: string, last_done: string|null }}
 */
export function taskState(task, completions, today) {
    completions = completions.filter((c) => c.type !== 'undone');
    const base = { occurrence: null, start: null, due: null, last_done: lastDone(completions) };

    if (task.mode === 'on_demand') return { ...base, status: 'on_demand' };

    let start;
    if (task.mode === 'once') start = isResolved(completions, task.starts_on) ? null : task.starts_on;
    else if (task.mode === 'after_completion') start = afterCompletionStart(task, completions);
    else start = fixedStart(task, completions, today);

    if (start === null) return { ...base, status: 'finished' };

    const postponed = find(completions, start, 'postponed');
    const due = postponed?.postponed_to ?? addDays(start, Number(task.window_days ?? 0));

    return { ...base, occurrence: start, start, due, status: status(start, due, today) };
}

/**
 * Occurrence dates of a fixed (or once) task between two dates, inclusive.
 */
export function occurrences(task, from, to) {
    if (task.mode === 'once') return task.starts_on >= from && task.starts_on <= to ? [task.starts_on] : [];
    if (task.mode !== 'fixed') return [];

    return rule(task).between(toDate(from), toDate(to), true).map(fromDate);
}

/**
 * Occurrences of a fixed task before the open one that were neither done nor skipped.
 */
export function missed(task, completions, from, today) {
    if (task.mode !== 'fixed') return [];
    completions = completions.filter((c) => c.type !== 'undone');
    const open = taskState(task, completions, today).start ?? addDays(today, 1);
    const until = open < today ? open : today;

    return occurrences(task, from, until)
        .filter((date) => date < open && !isResolved(completions, date) && !isPaused(task, date));
}

/**
 * The next occurrences (start dates) after the open one, e.g. for the task details.
 */
export function upcoming(task, completions, today, count = 3) {
    const state = taskState(task, completions, today);
    if (!state.start) return [];
    if (task.mode === 'after_completion') {
        // Counted from the open date, or from today when it is late (done today).
        const dates = [];
        let date = state.start > today ? state.start : today;
        for (let i = 0; i < count; i++) dates.push(date = addInterval(date, task.rrule));
        return task.ends_on ? dates.filter((each) => each <= task.ends_on) : dates;
    }
    if (task.mode !== 'fixed') return [];
    const dates = [];
    let cursor = toDate(state.start);
    while (dates.length < count) {
        cursor = rule(task).after(cursor, false);
        if (!cursor) break;
        const date = fromDate(cursor);
        if (!isPaused(task, date)) dates.push(date);
    }

    return dates;
}

export function validRule(text) {
    if (!text) return false;
    try {
        const options = RRule.parseString(text);
        return options.freq !== undefined;
    } catch {
        return false;
    }
}

/** Today in a time zone as YYYY-MM-DD (the device's by default). */
export function todayIn(timeZone = undefined, now = new Date()) {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
    const get = (type) => parts.find((part) => part.type === type).value;

    return `${get('year')}-${get('month')}-${get('day')}`;
}

export function addDays(date, days) {
    const d = toDate(date);
    d.setUTCDate(d.getUTCDate() + days);

    return fromDate(d);
}

/** Days from a to b (b later: positive). */
export function daysBetween(a, b) {
    return Math.round((toDate(b) - toDate(a)) / 86_400_000);
}

/** Monday of the week of a date. */
export function weekStart(date) {
    const day = (toDate(date).getUTCDay() + 6) % 7;

    return addDays(date, -day);
}

/**
 * Completions of an occurrence get the same id on every device (UUID v5 of task and date), so
 * members completing it offline at the same time end up with one document.
 */
export async function occurrenceId(taskId, occurrence) {
    return uuid5(`${taskId}:${occurrence}`);
}

// RFC 4122 URL namespace, same as App\Models\Completion::NAMESPACE.
const NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

export async function uuid5(name) {
    const ns = NAMESPACE.replace(/-/g, '').match(/../g).map((hex) => parseInt(hex, 16));
    const data = new Uint8Array([...ns, ...new TextEncoder().encode(name)]);
    const hash = new Uint8Array(await crypto.subtle.digest('SHA-1', data)).slice(0, 16);
    hash[6] = (hash[6] & 0x0f) | 0x50;
    hash[8] = (hash[8] & 0x3f) | 0x80;
    const hex = [...hash].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// ---------------------------------------------------------------- rule editing (task form)

export const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

/**
 * Form fields of a task's schedule, see toSchedule().
 */
export function fromSchedule(task) {
    const parts = parseRule(task.rrule);
    const form = {
        repeat: task.mode === 'fixed' ? (parts.FREQ ?? 'WEEKLY').toLowerCase() : task.mode,
        interval: Number(parts.INTERVAL ?? 1),
        unit: (parts.FREQ ?? 'WEEKLY').toLowerCase(),
        weekdays: parts.BYDAY && !/\d/.test(parts.BYDAY) ? parts.BYDAY.split(',') : [],
        monthly: 'day',
        monthDay: Number(parts.BYMONTHDAY ?? 0) || null,
        nth: 1,
        nthDay: 'MO',
    };
    if (task.mode === 'fixed' && parts.FREQ === 'MONTHLY' && parts.BYDAY) {
        const match = parts.BYDAY.match(/^([+-]?\d)?(MO|TU|WE|TH|FR|SA|SU)$/);
        if (match) Object.assign(form, { monthly: 'weekday', nth: Number(match[1] ?? 1), nthDay: match[2] });
    }
    if (task.mode === 'fixed' && parts.FREQ === 'MONTHLY' && parts.BYMONTHDAY === '-1') form.monthly = 'last';

    return form;
}

/**
 * Mode and RRULE from the form fields (repeat: once, daily, weekly, monthly, yearly,
 * after_completion, on_demand).
 *
 * @returns {{ mode: string, rrule: string|null }}
 */
export function toSchedule(form, startsOn) {
    const interval = Math.max(1, Math.min(999, Math.round(Number(form.interval) || 1)));
    const every = interval > 1 ? `;INTERVAL=${interval}` : '';
    switch (form.repeat) {
        case 'once':
        case 'on_demand':
            return { mode: form.repeat, rrule: null };
        case 'after_completion':
            return { mode: 'after_completion', rrule: `FREQ=${(form.unit || 'weekly').toUpperCase()}${every}` };
        case 'weekly': {
            const days = WEEKDAYS.filter((day) => form.weekdays?.includes(day));
            const byday = days.length ? days : [WEEKDAYS[(toDate(startsOn).getUTCDay() + 6) % 7]];
            return { mode: 'fixed', rrule: `FREQ=WEEKLY${every};BYDAY=${byday.join(',')}` };
        }
        case 'monthly':
            if (form.monthly === 'weekday') return { mode: 'fixed', rrule: `FREQ=MONTHLY${every};BYDAY=${Number(form.nth) || 1}${form.nthDay || 'MO'}` };
            if (form.monthly === 'last') return { mode: 'fixed', rrule: `FREQ=MONTHLY${every};BYMONTHDAY=-1` };
            return { mode: 'fixed', rrule: `FREQ=MONTHLY${every};BYMONTHDAY=${Number(form.monthDay) || toDate(startsOn).getUTCDate()}` };
        case 'yearly':
            return { mode: 'fixed', rrule: `FREQ=YEARLY${every}` };
        default:
            return { mode: 'fixed', rrule: `FREQ=DAILY${every}` };
    }
}

/**
 * Human readable schedule, e.g. "Every 2 weeks on Monday" / "Jeden 4. Montag im Monat".
 *
 * @param {(key: string, replace?: object) => string} t translator
 */
export function describeSchedule(task, t, locale) {
    const parts = parseRule(task.rrule);
    const interval = Number(parts.INTERVAL ?? 1);
    const lang = locale.replace('_', '-');
    const dayName = (code) => new Intl.DateTimeFormat(lang, { weekday: 'long', timeZone: 'UTC' })
        .format(toDate(addDays('2024-01-01', WEEKDAYS.indexOf(code))));
    const date = (value) => formatDate(value, locale, { day: 'numeric', month: 'long' });

    if (task.mode === 'on_demand') return t('When needed');
    if (task.mode === 'once') return t('Once on :date', { date: formatDate(task.starts_on, locale) });
    const unit = {
        DAILY: [t('Every day'), t('Every :n days', { n: interval })],
        WEEKLY: [t('Every week'), t('Every :n weeks', { n: interval })],
        MONTHLY: [t('Every month'), t('Every :n months', { n: interval })],
        YEARLY: [t('Every year'), t('Every :n years', { n: interval })],
    }[parts.FREQ ?? 'WEEKLY'];
    const every = interval > 1 ? unit[1] : unit[0];
    if (task.mode === 'after_completion') return t(':every after the last time', { every });

    if (parts.FREQ === 'WEEKLY' && parts.BYDAY) {
        return t(':every on :days', { every, days: parts.BYDAY.split(',').map(dayName).join(', ') });
    }
    if (parts.FREQ === 'MONTHLY' && parts.BYDAY) {
        const match = parts.BYDAY.match(/^([+-]?\d)?(MO|TU|WE|TH|FR|SA|SU)$/);
        const nth = Number(match?.[1] ?? 1);
        return t(':every on the :nth :day', { every, nth: nthLabel(nth, t), day: dayName(match?.[2] ?? 'MO') });
    }
    if (parts.FREQ === 'MONTHLY' && parts.BYMONTHDAY) {
        return parts.BYMONTHDAY === '-1'
            ? t(':every on the last day', { every })
            : t(':every on day :day', { every, day: parts.BYMONTHDAY });
    }
    if (parts.FREQ === 'YEARLY') return t(':every on :date', { every, date: date(task.starts_on) });

    return every;
}

/** "first" … "fourth", "last" (n = -1) of a weekday in the month. */
export function nthLabel(n, t) {
    return n === -1 ? t('last') : t(['first', 'second', 'third', 'fourth', 'fifth'][n - 1] ?? 'first');
}

export function formatDate(date, locale, options = { weekday: 'short', day: 'numeric', month: 'short' }) {
    if (!date) return '';

    return new Intl.DateTimeFormat(locale.replace('_', '-'), { ...options, timeZone: 'UTC' }).format(toDate(date));
}

// ---------------------------------------------------------------- internals

function fixedStart(task, completions, today) {
    const r = rule(task);
    const lastDate = r.before(toDate(today), true);
    const last = lastDate ? fromDate(lastDate) : null;
    if (last !== null && !isPaused(task, last) && !isResolved(completions, last)) return last;

    let cursor = toDate(last ?? addDays(task.starts_on, -1));
    for (let i = 0; i < MAX_AHEAD; i++) {
        cursor = r.after(cursor, false);
        if (!cursor) return null;
        const date = fromDate(cursor);
        if (!isPaused(task, date) && !isResolved(completions, date)) return date;
    }

    return null;
}

function afterCompletionStart(task, completions) {
    const resolved = completions.filter((c) => ['done', 'skipped'].includes(c.type) && c.occurrence !== null);
    const last = resolved.reduce((max, c) => (max === null || c.done_on > max ? c.done_on : max), null);
    let start = last === null ? task.starts_on : addInterval(last, task.rrule);
    if (task.paused_until && start < task.paused_until) start = task.paused_until;

    return task.ends_on && start > task.ends_on ? null : start;
}

function addInterval(date, ruleText) {
    const parts = parseRule(ruleText ?? 'FREQ=WEEKLY');
    const interval = Math.max(1, Number(parts.INTERVAL ?? 1));
    const d = toDate(date);
    const day = d.getUTCDate();
    switch (parts.FREQ) {
        case 'DAILY': d.setUTCDate(day + interval); break;
        case 'MONTHLY':
        case 'YEARLY': {
            // Month ends do not overflow: 31 Jan + 1 month = 28/29 Feb (same as PHP).
            const months = parts.FREQ === 'YEARLY' ? 12 * interval : interval;
            d.setUTCDate(1);
            d.setUTCMonth(d.getUTCMonth() + months);
            const lastDay = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate();
            d.setUTCDate(Math.min(day, lastDay));
            break;
        }
        default: d.setUTCDate(day + 7 * interval);
    }

    return fromDate(d);
}

function status(start, due, today) {
    if (today < start) return 'upcoming';
    if (today > due) return 'overdue';
    if (today === due) return 'due';

    return 'open';
}

function lastDone(completions) {
    return completions
        .filter((c) => ['done', 'extra'].includes(c.type))
        .reduce((max, c) => (max === null || c.done_on > max ? c.done_on : max), null);
}

function isResolved(completions, occurrence) {
    return completions.some((c) => c.occurrence === occurrence && ['done', 'skipped'].includes(c.type));
}

function find(completions, occurrence, type) {
    return completions.find((c) => c.occurrence === occurrence && c.type === type) ?? null;
}

function isPaused(task, date) {
    return Boolean(task.paused_until) && date < task.paused_until;
}

function rule(task) {
    const options = RRule.parseString(task.rrule || 'FREQ=DAILY');
    options.dtstart = toDate(task.starts_on);
    if (task.ends_on) options.until = toDate(task.ends_on);

    return new RRule(options);
}

export function parseRule(text) {
    const parts = {};
    for (const part of String(text ?? '').replace(/^RRULE:/i, '').split(';').filter(Boolean)) {
        const [key, value = ''] = part.split('=');
        parts[key.trim().toUpperCase()] = value.trim().toUpperCase();
    }

    return parts;
}

function toDate(date) {
    const [y, m, d] = date.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d));
}

function fromDate(date) {
    return date.toISOString().slice(0, 10);
}
