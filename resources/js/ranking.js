import { addDays, daysBetween, weekStart } from './schedule.js';

// Points ranking per list and the personal suggestions which task to do next. Everything is
// computed on the device from the synced completions; suggestions are never stored or shared.

export const SCORED = ['done', 'extra'];

/**
 * First and last day of a ranking period ("all": from the first completion).
 */
export function periodRange(period, today, completions = []) {
    if (period === 'week') return { from: weekStart(today), to: today };
    if (period === 'month') return { from: `${today.slice(0, 8)}01`, to: today };
    const first = completions.reduce((min, c) => (min === null || c.done_on < min ? c.done_on : min), null);

    return { from: first ?? today, to: today };
}

/**
 * Days of a period a member was not absent.
 */
export function presentDays(email, absences, from, to) {
    const total = daysBetween(from, to) + 1;
    let absent = 0;
    for (const absence of absences.filter((a) => a.user_email === email)) {
        const start = absence.starts_on > from ? absence.starts_on : from;
        const end = absence.ends_on < to ? absence.ends_on : to;
        if (start <= end) absent += daysBetween(start, end) + 1;
    }

    return Math.max(0, total - absent);
}

/**
 * Ranking of a list: points and completions per member in the period. `fair` is the share of
 * all points a member would have by days present, so holidays do not count against anyone.
 *
 * @returns {{ email: string, name: string|null, points: number, count: number, fair: number }[]}
 */
export function ranking(list, completions, absences, period, today) {
    const own = completions.filter((c) => c.task_list_id === list.id && SCORED.includes(c.type));
    const { from, to } = periodRange(period, today, own);
    const inPeriod = own.filter((c) => c.done_on >= from && c.done_on <= to);
    const total = inPeriod.reduce((sum, c) => sum + c.points, 0);
    const members = list.members?.length ? list.members : [];
    const present = new Map(members.map((m) => [m.email, presentDays(m.email, absences, from, to)]));
    const presentTotal = [...present.values()].reduce((sum, days) => sum + days, 0);

    const rows = members.map((member) => {
        const done = inPeriod.filter((c) => c.user_email === member.email);
        return {
            email: member.email,
            name: member.name,
            points: done.reduce((sum, c) => sum + c.points, 0),
            count: done.length,
            fair: presentTotal ? Math.round(total * (present.get(member.email) / presentTotal) * 10) / 10 : 0,
        };
    });
    // Former members who still have points in the period.
    for (const completion of inPeriod) {
        if (completion.user_email && !rows.some((row) => row.email === completion.user_email)) {
            const done = inPeriod.filter((c) => c.user_email === completion.user_email);
            rows.push({ email: completion.user_email, name: null, points: done.reduce((sum, c) => sum + c.points, 0), count: done.length, fair: 0 });
        }
    }

    return rows.sort((a, b) => b.points - a.points || a.email.localeCompare(b.email));
}

const URGENCY = { overdue: 100, due: 80, open: 60, upcoming: 30, on_demand: 50 };

/**
 * Personal suggestions: urgent tasks first; with a ranking, tasks with more points weigh more,
 * even more when the user is behind their fair share.
 *
 * @param {object} options
 * @param {string} options.email the user
 * @param {object[]} options.lists
 * @param {{ task: object, state: object }[]} options.entries tasks with their state (open, not archived)
 * @param {object[]} options.completions
 * @param {object[]} options.absences
 * @param {string} options.today
 * @returns {{ task: object, state: object, list: object, overtakes: object|null, points: number|null }[]}
 */
export function recommendations({ email, lists, entries, completions, absences, today, limit = 3 }) {
    const byList = new Map(lists.map((list) => [list.id, list]));
    const rankings = new Map(lists.filter((list) => list.ranking_enabled)
        .map((list) => [list.id, ranking(list, completions, absences, list.ranking_period, today)]));

    const scored = [];
    for (const { task, state } of entries) {
        const list = byList.get(task.task_list_id);
        if (!list) continue;
        let urgency = URGENCY[state.status];
        if (urgency === undefined) continue;
        if (state.status === 'overdue') urgency += Math.min(30, daysBetween(state.due, today));
        if (state.status === 'open') urgency -= Math.min(30, daysBetween(today, state.due));
        if (state.status === 'upcoming') {
            const days = daysBetween(today, state.start);
            if (days > 2) continue;
            urgency -= days * 5;
        }
        if (state.status === 'on_demand') {
            const since = state.last_done ? daysBetween(state.last_done, today) : Infinity;
            if (!task.remind_after_days || since < task.remind_after_days) continue;
        }

        const rows = rankings.get(list.id);
        const me = rows?.find((row) => row.email === email);
        let score = urgency;
        let overtakes = null;
        if (rows && me) {
            const behind = me.points < me.fair;
            score += task.points * (behind ? 10 : 4);
            // The closest member ahead the task would overtake.
            overtakes = rows.filter((row) => row.email !== email && row.points >= me.points && me.points + task.points > row.points)
                .sort((a, b) => a.points - b.points)[0] ?? null;
        }
        scored.push({ task, state, list, score, overtakes, points: rows ? task.points : null });
    }

    return scored.sort((a, b) => b.score - a.score).slice(0, limit);
}

/**
 * Points per member and week for the statistics (oldest week first).
 *
 * @returns {{ week: string, points: Record<string, number> }[]}
 */
export function weeklyPoints(listId, completions, today, weeks = 8) {
    const first = addDays(weekStart(today), -7 * (weeks - 1));
    const result = Array.from({ length: weeks }, (_, i) => ({ week: addDays(first, 7 * i), points: {} }));
    for (const c of completions) {
        if (c.task_list_id !== listId || !SCORED.includes(c.type) || c.done_on < first || !c.user_email) continue;
        const entry = result[Math.floor(daysBetween(first, c.done_on) / 7)];
        if (entry) entry.points[c.user_email] = (entry.points[c.user_email] ?? 0) + c.points;
    }

    return result;
}
