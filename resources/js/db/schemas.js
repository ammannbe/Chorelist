// RxDB schemas, mirroring the documents of app/Sync/*Resource.php.
// `version` is increased on every local change (see db/index.js), `updated_at` is set by the server.

const uuid = { type: 'string', maxLength: 36 };
const date = { type: ['string', 'null'], maxLength: 10 };
const syncFields = {
    version: { type: 'integer', minimum: 1, default: 1 },
    updated_at: { type: ['string', 'null'] },
};

export const lists = {
    version: 1,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        name: { type: 'string', maxLength: 255 },
        icon: { type: ['string', 'null'] },
        ranking_enabled: { type: 'boolean', default: true },
        // week, month, all
        ranking_period: { type: 'string', default: 'month' },
        owner_email: { type: ['string', 'null'] },
        is_owner: { type: 'boolean', default: true },
        // All members, the user included: { email, name, direct } (direct false: only through a household).
        members: {
            type: 'array',
            default: [],
            items: { type: 'object', properties: { email: { type: 'string' }, name: { type: ['string', 'null'] }, direct: { type: 'boolean' } }, required: ['email'] },
        },
        // Households the list is shared with: { id (uuid), name }.
        households: {
            type: 'array',
            default: [],
            items: { type: 'object', properties: { id: { type: 'string' }, name: { type: 'string' } }, required: ['id', 'name'] },
        },
        // False: the user is a member only through a household.
        direct: { type: 'boolean', default: true },
        // Personal: manual order (null: sorted by name after the positioned lists), no notifications.
        position: { type: ['number', 'null'] },
        muted: { type: 'boolean', default: false },
        ...syncFields,
    },
    required: ['id', 'name'],
};

export const tasks = {
    version: 1,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        task_list_id: uuid,
        title: { type: 'string', maxLength: 255 },
        notes: { type: ['string', 'null'] },
        icon: { type: ['string', 'null'] },
        category: { type: ['string', 'null'] },
        points: { type: 'integer', minimum: 1, maximum: 5, default: 1 },
        // fixed, after_completion, once, dates, on_demand (resources/js/schedule.js)
        mode: { type: 'string' },
        rrule: { type: ['string', 'null'] },
        starts_on: { type: 'string', maxLength: 10 },
        window_days: { type: 'integer', minimum: 0, default: 0 },
        ends_on: date,
        // Mode `dates`: sorted YYYY-MM-DD dates of the occurrences (starts_on is the first one).
        dates: { type: ['array', 'null'], items: { type: 'string' } },
        // Days before the due date.
        reminders: { type: 'array', items: { type: 'integer' }, default: [] },
        remind_after_days: { type: ['integer', 'null'] },
        paused_until: date,
        archived: { type: 'boolean', default: false },
        checklist: {
            type: 'array',
            default: [],
            items: { type: 'object', properties: { id: { type: 'string' }, text: { type: 'string' }, done: { type: 'boolean' } }, required: ['id', 'text'] },
        },
        creator_email: { type: ['string', 'null'] },
        ...syncFields,
    },
    required: ['id', 'task_list_id', 'title', 'mode', 'starts_on'],
    indexes: ['task_list_id'],
};

export const completions = {
    version: 0,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        task_id: uuid,
        task_list_id: uuid,
        task_title: { type: ['string', 'null'] },
        // Who gets the points, and who entered it.
        user_email: { type: ['string', 'null'] },
        entered_by_email: { type: ['string', 'null'] },
        occurrence: date,
        done_on: { type: 'string', maxLength: 10 },
        // done, extra, skipped, postponed, undone
        type: { type: 'string' },
        postponed_to: date,
        points: { type: 'integer', default: 0 },
        note: { type: ['string', 'null'] },
        ...syncFields,
    },
    required: ['id', 'task_id', 'task_list_id', 'done_on', 'type'],
    indexes: ['task_id'],
};

// Pulled only, written by the server.
export const activities = {
    version: 0,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        task_list_id: uuid,
        task_id: { type: ['string', 'null'] },
        user_email: { type: ['string', 'null'] },
        action: { type: 'string' },
        data: { type: 'object' },
        created_at: { type: 'string' },
        ...syncFields,
    },
    required: ['id', 'task_list_id', 'action'],
};

export const absences = {
    version: 0,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        user_email: { type: ['string', 'null'] },
        is_own: { type: 'boolean', default: true },
        starts_on: { type: 'string', maxLength: 10 },
        ends_on: { type: 'string', maxLength: 10 },
        note: { type: ['string', 'null'] },
        ...syncFields,
    },
    required: ['id', 'starts_on', 'ends_on'],
};

// Schema migrations of the local documents, keyed by collection and schema version.
export const migrations = {
    lists: {
        // Households (filled by the next pull, see REPLICATION_RUNS).
        1: (doc) => ({ ...doc, households: [], direct: true }),
    },
    tasks: {
        // Schedule mode `dates`.
        1: (doc) => ({ ...doc, dates: doc.dates ?? null }),
    },
};
