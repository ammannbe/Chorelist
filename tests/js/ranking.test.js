import { describe, expect, it } from 'vitest';
import { periodRange, presentDays, ranking, recommendations, weeklyPoints } from '../../resources/js/ranking.js';

const list = { id: 'l', ranking_enabled: true, ranking_period: 'month', members: [{ email: 'a@x', name: 'Anna' }, { email: 'b@x', name: 'Ben' }] };
const done = (email, points, doneOn, type = 'done') => ({ task_list_id: 'l', user_email: email, points, done_on: doneOn, type });

describe('ranking', () => {
    it('sums the points of the period', () => {
        const completions = [done('a@x', 3, '2026-11-02'), done('b@x', 5, '2026-11-03'), done('a@x', 4, '2026-10-30'), done('a@x', 2, '2026-11-04', 'skipped')];
        expect(ranking(list, completions, [], 'month', '2026-11-04').map((r) => [r.email, r.points, r.count])).toEqual([['b@x', 5, 1], ['a@x', 3, 1]]);
        expect(ranking(list, completions, [], 'all', '2026-11-04')[0]).toMatchObject({ email: 'a@x', points: 7 });
    });

    it('splits the fair share by days present', () => {
        const absences = [{ user_email: 'b@x', starts_on: '2026-10-25', ends_on: '2026-11-02' }];
        // 1–4 November: Anna 4 days, Ben 2 days present.
        expect(presentDays('b@x', absences, '2026-11-01', '2026-11-04')).toBe(2);
        const rows = ranking(list, [done('a@x', 6, '2026-11-02')], absences, 'month', '2026-11-04');
        expect(rows.map((r) => [r.email, r.fair])).toEqual([['a@x', 4], ['b@x', 2]]);
    });

    it('starts weeks on Monday', () => {
        expect(periodRange('week', '2026-11-04')).toEqual({ from: '2026-11-02', to: '2026-11-04' });
        expect(periodRange('month', '2026-11-04')).toEqual({ from: '2026-11-01', to: '2026-11-04' });
    });
});

describe('recommendations', () => {
    const task = (id, points, extra = {}) => ({ id, task_list_id: 'l', points, ...extra });

    it('weighs urgency and points (more when behind) and names who would be overtaken', () => {
        const entries = [
            { task: task('later', 5), state: { status: 'upcoming', start: '2026-11-20' } },
            { task: task('overdue', 1), state: { status: 'overdue', due: '2026-11-01' } },
            { task: task('today', 4), state: { status: 'due', due: '2026-11-04' } },
        ];
        const result = recommendations({ email: 'a@x', lists: [list], entries, completions: [done('b@x', 3, '2026-11-02')], absences: [], today: '2026-11-04' });

        // Anna is behind Ben: the 4 points today beat the overdue 1 point task.
        expect(result.map((r) => r.task.id)).toEqual(['today', 'overdue']);
        expect(result[0].overtakes).toMatchObject({ email: 'b@x' });
        expect(result[1].overtakes).toBeNull();

        // Not behind: urgency wins.
        const ahead = recommendations({ email: 'a@x', lists: [list], entries, completions: [done('a@x', 3, '2026-11-02')], absences: [], today: '2026-11-04' });
        expect(ahead.map((r) => r.task.id)).toEqual(['overdue', 'today']);
    });

    it('suggests on demand tasks once they were not done for a while', () => {
        const entries = [{ task: task('trash', 1, { remind_after_days: 3 }), state: { status: 'on_demand', last_done: '2026-11-02' } }];
        expect(recommendations({ email: 'a@x', lists: [list], entries, completions: [], absences: [], today: '2026-11-04' })).toEqual([]);
        expect(recommendations({ email: 'a@x', lists: [list], entries, completions: [], absences: [], today: '2026-11-05' })).toHaveLength(1);
    });
});

describe('weeklyPoints', () => {
    it('groups by week', () => {
        const result = weeklyPoints('l', [done('a@x', 3, '2026-11-02'), done('a@x', 1, '2026-11-08'), done('b@x', 2, '2026-10-28')], '2026-11-04', 2);
        expect(result).toEqual([{ week: '2026-10-26', points: { 'b@x': 2 } }, { week: '2026-11-02', points: { 'a@x': 4 } }]);
    });
});
