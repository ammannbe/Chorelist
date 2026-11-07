import { describe, expect, it } from 'vitest';
import fixtures from '../fixtures/schedule.json';
import { describeSchedule, formatDate, fromSchedule, missed, occurrenceId, occurrences, taskState, toSchedule, upcoming } from '../../resources/js/schedule.js';

// Same cases as tests/Unit/ScheduleTest.php: client and server must agree.
describe('taskState', () => {
    it.each(fixtures.state)('$name', ({ task, completions, today, expect: expected }) => {
        expect(taskState(task, completions, today)).toMatchObject(expected);
    });
});

describe('occurrences', () => {
    it.each(fixtures.occurrences)('$name', ({ task, from, to, expect: expected }) => {
        expect(occurrences(task, from, to)).toEqual(expected);
    });
});

describe('missed', () => {
    it.each(fixtures.missed)('$name', ({ task, completions, from, today, expect: expected }) => {
        expect(missed(task, completions, from, today)).toEqual(expected);
    });
});

describe('upcoming', () => {
    it('lists the occurrences after the open one', () => {
        const task = { mode: 'fixed', rrule: 'FREQ=WEEKLY;BYDAY=MO', starts_on: '2026-11-02' };
        expect(upcoming(task, [], '2026-11-01', 2)).toEqual(['2026-11-09', '2026-11-16']);
    });

    it('lists the dates after the open one', () => {
        const task = { mode: 'dates', starts_on: '2026-11-03', dates: ['2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24'], paused_until: '2026-11-08' };
        expect(upcoming(task, [], '2026-11-01', 3)).toEqual(['2026-11-17', '2026-11-24']);
    });
});

describe('occurrenceId', () => {
    it('is the UUID v5 of task and date, same as the server', async () => {
        // Ramsey\Uuid\Uuid::uuid5(Uuid::NAMESPACE_URL, 'task:2026-11-02')
        expect(await occurrenceId('task', '2026-11-02')).toBe('f9cc1f2b-92f9-5ce1-9383-17eee3b7f125');
        expect(await occurrenceId('a', '2026-11-02')).not.toBe(await occurrenceId('b', '2026-11-02'));
    });
});

describe('schedule form', () => {
    const cases = [
        [{ repeat: 'weekly', interval: 2, weekdays: ['TH', 'MO'] }, { mode: 'fixed', rrule: 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH' }],
        [{ repeat: 'weekly', interval: 1, weekdays: [] }, { mode: 'fixed', rrule: 'FREQ=WEEKLY;BYDAY=WE' }],
        [{ repeat: 'monthly', interval: 1, monthly: 'weekday', nth: 4, nthDay: 'MO' }, { mode: 'fixed', rrule: 'FREQ=MONTHLY;BYDAY=4MO' }],
        [{ repeat: 'monthly', interval: 1, monthly: 'last' }, { mode: 'fixed', rrule: 'FREQ=MONTHLY;BYMONTHDAY=-1' }],
        [{ repeat: 'monthly', interval: 3, monthly: 'day', monthDay: null }, { mode: 'fixed', rrule: 'FREQ=MONTHLY;INTERVAL=3;BYMONTHDAY=4' }],
        [{ repeat: 'after_completion', interval: 10, unit: 'daily' }, { mode: 'after_completion', rrule: 'FREQ=DAILY;INTERVAL=10' }],
        [{ repeat: 'on_demand' }, { mode: 'on_demand', rrule: null }],
        [{ repeat: 'dates', dates: ['2027-01-12', '2027-01-05', '2027-01-12', ''] }, { mode: 'dates', rrule: null, dates: ['2027-01-05', '2027-01-12'] }],
    ];

    it.each(cases)('%o', (form, expected) => {
        // 2026-11-04 is a Wednesday.
        expect(toSchedule(form, '2026-11-04')).toEqual(expected);
    });

    it('reads the rule back', () => {
        expect(fromSchedule({ mode: 'fixed', rrule: 'FREQ=MONTHLY;BYDAY=4MO' })).toMatchObject({ repeat: 'monthly', monthly: 'weekday', nth: 4, nthDay: 'MO' });
        expect(fromSchedule({ mode: 'fixed', rrule: 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH' })).toMatchObject({ repeat: 'weekly', interval: 2, weekdays: ['MO', 'TH'] });
        expect(fromSchedule({ mode: 'after_completion', rrule: 'FREQ=DAILY;INTERVAL=3' })).toMatchObject({ repeat: 'after_completion', unit: 'daily', interval: 3 });
        expect(fromSchedule({ mode: 'dates', rrule: null, dates: ['2027-01-05'] })).toMatchObject({ repeat: 'dates', dates: ['2027-01-05'] });
        expect(fromSchedule({ mode: 'fixed', rrule: 'FREQ=YEARLY', dates: null })).toMatchObject({ dates: [] });
    });

    it('describes the schedule', () => {
        const t = (key, replace = {}) => Object.entries(replace).reduce((text, [name, value]) => text.replaceAll(`:${name}`, value), key);
        expect(describeSchedule({ mode: 'fixed', rrule: 'FREQ=MONTHLY;BYDAY=4MO' }, t, 'en')).toBe('Every month on the fourth Monday');
        expect(describeSchedule({ mode: 'fixed', rrule: 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH' }, t, 'en')).toBe('Every :n weeks on Monday, Thursday'.replace(':n', 2));
        expect(describeSchedule({ mode: 'on_demand' }, t, 'en')).toBe('When needed');
        const dates = { mode: 'dates', dates: ['2027-01-05', '2027-01-14', '2027-02-02'] };
        expect(describeSchedule(dates, t, 'de_CH', '2027-01-06')).toBe('3 dates, next 14.01.2027');
        expect(describeSchedule(dates, t, 'de_CH', '2027-03-01')).toBe('3 dates, none left');
        expect(describeSchedule({ mode: 'dates', dates: ['2027-01-05'] }, t, 'de_CH', '2027-01-01')).toBe('1 date, on 05.01.2027');
        expect(describeSchedule({ mode: 'dates', dates: [] }, t, 'en', '2027-01-01')).toBe('No dates yet');
    });
});

describe('formatDate', () => {
    it('drops abbreviation dots and the comma of short names', () => {
        expect(formatDate('2026-10-14', 'de_CH')).toBe('Mi 14. Okt');
        expect(formatDate('2026-05-06', 'de_CH')).toBe('Mi 6. Mai');
        expect(formatDate('2026-10-14', 'de_CH', { weekday: 'long', day: 'numeric', month: 'long' })).toBe('Mittwoch, 14. Oktober');
    });
});
