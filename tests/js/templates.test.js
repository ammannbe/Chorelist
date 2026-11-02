import { describe, expect, it } from 'vitest';
import { addDays, describeSchedule, taskState } from '../../resources/js/schedule.js';
import { TEMPLATES, templateTask } from '../../resources/js/templates.js';

let ids = 0;
const newId = () => `id-${++ids}`;

describe('templates', () => {
    it('have unique ids and unique task titles', () => {
        expect(new Set(TEMPLATES.map((template) => template.id)).size).toBe(TEMPLATES.length);
        for (const template of TEMPLATES) {
            const titles = template.tasks.map((task) => task.title);
            expect(new Set(titles).size, template.id).toBe(titles.length);
        }
    });

    it('have valid tasks with a schedule', () => {
        for (const template of TEMPLATES) {
            for (const task of template.tasks) {
                const fields = templateTask(task, 'list', '2026-11-02', addDays, newId);
                expect(['fixed', 'after_completion', 'once', 'on_demand'], task.title).toContain(fields.mode);
                expect(fields.points, task.title).toBeGreaterThanOrEqual(1);
                expect(fields.points, task.title).toBeLessThanOrEqual(5);
                expect(fields.title.length, task.title).toBeLessThanOrEqual(255);
                expect(describeSchedule(fields, (key) => key, 'de_CH'), task.title).toBeTruthy();
                if (fields.mode !== 'on_demand') expect(taskState(fields, [], '2026-11-02').start, task.title).toBeTruthy();
            }
        }
    });

    it('build task fields with spread dates and new checklist ids', () => {
        const task = { title: 'Bad', points: 4, mode: 'after_completion', rrule: 'FREQ=WEEKLY', window_days: 3, start_in_days: 7, checklist: ['WC', 'Lavabo'] };
        const fields = templateTask(task, 'list-1', '2026-11-02', addDays, newId);

        expect(fields).toMatchObject({
            task_list_id: 'list-1', title: 'Bad', notes: null, starts_on: '2026-11-09', reminders: [0], remind_after_days: null, ends_on: null,
        });
        expect(fields.checklist.map((step) => step.text)).toEqual(['WC', 'Lavabo']);
        expect(fields.checklist.every((step) => step.id && step.done === false)).toBe(true);
        expect(templateTask(task, 'list-1', '2026-11-02', addDays, newId).checklist[0].id).not.toBe(fields.checklist[0].id);
    });

    it('start tasks on demand on the import day', () => {
        const fields = templateTask({ title: 'Recycling', points: 2, mode: 'on_demand', start_in_days: 30 }, 'list', '2026-11-02', addDays, newId);

        expect(fields.starts_on).toBe('2026-11-02');
    });
});
