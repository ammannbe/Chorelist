// Task templates other users can import into a new or existing list (offline, client only).
// Task fields as in the tasks collection; `start_in_days` spreads the first dates from the day
// the import starts on, so not everything is due at once. Titles stay German (the household
// template comes from a Swiss household).

export const TEMPLATES = [
    {
        id: 'household',
        name: 'Household',
        icon: '🏠',
        description: 'Recurring household chores: cleaning, laundry and recycling.',
        tasks: [
            { title: 'Staubsaugen', icon: '🧹', category: 'Putzen', points: 3, mode: 'after_completion', rrule: 'FREQ=WEEKLY', window_days: 6, start_in_days: 0 },
            {
                title: 'Bad putzen',
                icon: '🛁',
                category: 'Putzen',
                points: 4,
                mode: 'after_completion',
                rrule: 'FREQ=WEEKLY;INTERVAL=2',
                window_days: 3,
                start_in_days: 0,
                checklist: ['WC', 'Lavabo', 'Spiegel', 'Badewanne', 'Kleines Bad'],
            },
            { title: 'Küche putzen', icon: '🍳', category: 'Putzen', points: 3, mode: 'after_completion', rrule: 'FREQ=MONTHLY', window_days: 6, start_in_days: 7 },
            { title: 'Abstauben', icon: '🪶', category: 'Putzen', points: 3, mode: 'after_completion', rrule: 'FREQ=WEEKLY;INTERVAL=4', window_days: 6, start_in_days: 14 },
            { title: 'Boden nass aufnehmen', icon: '🪣', category: 'Putzen', points: 4, mode: 'fixed', rrule: 'FREQ=MONTHLY;INTERVAL=4;BYDAY=1FR', window_days: 13, start_in_days: 32 },
            { title: 'Backofen putzen', icon: '🔥', category: 'Putzen', points: 4, mode: 'fixed', rrule: 'FREQ=MONTHLY;INTERVAL=6;BYDAY=1FR', window_days: 13, start_in_days: 0 },
            { title: 'Kühlschrank putzen', icon: '❄️', category: 'Putzen', points: 5, mode: 'fixed', rrule: 'FREQ=MONTHLY;INTERVAL=6;BYDAY=1FR', window_days: 13, start_in_days: 32 },
            { title: 'Geschirrspüler putzen', icon: '🍽️', category: 'Putzen', points: 3, mode: 'fixed', rrule: 'FREQ=MONTHLY;INTERVAL=6;BYDAY=1FR', window_days: 6, start_in_days: 151 },
            { title: 'Fenster putzen', icon: '🪟', category: 'Putzen', points: 5, mode: 'fixed', rrule: 'FREQ=YEARLY', window_days: 13, start_in_days: 151 },
            { title: 'Geschirrspülersieb', icon: '🧽', category: null, points: 2, mode: 'fixed', rrule: 'FREQ=WEEKLY;BYDAY=FR', window_days: 3, start_in_days: 0 },
            { title: 'Bettwäsche wechseln', icon: '🛏️', category: 'Waschen', points: 2, mode: 'after_completion', rrule: 'FREQ=WEEKLY;INTERVAL=2', window_days: 3, start_in_days: 3 },
            { title: 'Kissen & Decken waschen', icon: '🧺', category: 'Waschen', points: 2, mode: 'fixed', rrule: 'FREQ=MONTHLY;INTERVAL=4;BYDAY=1FR', window_days: 13, start_in_days: 32 },
            {
                title: 'Recycling',
                icon: '♻️',
                category: 'Entsorgen',
                points: 2,
                mode: 'on_demand',
                rrule: null,
                window_days: 0,
                reminders: [],
                remind_after_days: 30,
                checklist: ['Glas', 'Dosen', 'PET', 'Plastik'],
            },
        ],
    },
];

/**
 * Task fields of a template task for insertTask(): first date `start_in_days` after `startsOn`,
 * checklist steps get new ids.
 *
 * @param {object} task template task
 * @param {string} listId
 * @param {string} startsOn Y-m-d
 * @param {(date: string, days: number) => string} addDays
 * @param {() => string} newId
 */
export function templateTask(task, listId, startsOn, addDays, newId) {
    return {
        task_list_id: listId,
        title: task.title,
        icon: task.icon ?? null,
        category: task.category ?? null,
        notes: null,
        points: task.points,
        mode: task.mode,
        rrule: task.rrule ?? null,
        starts_on: addDays(startsOn, task.mode === 'on_demand' ? 0 : task.start_in_days ?? 0),
        window_days: task.window_days ?? 0,
        ends_on: null,
        reminders: [...(task.reminders ?? [0])],
        remind_after_days: task.remind_after_days ?? null,
        checklist: (task.checklist ?? []).map((text) => ({ id: newId(), text, done: false })),
    };
}
