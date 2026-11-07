import { describe, expect, it } from 'vitest';
import { SYNCED_COLLECTIONS } from '../../resources/js/db/index.js';
import * as schemas from '../../resources/js/db/schemas.js';

describe('schemas', () => {
    it('exist for every synced collection', () => {
        for (const collection of SYNCED_COLLECTIONS) {
            expect(schemas[collection], collection).toMatchObject({ primaryKey: 'id', type: 'object' });
        }
    });

    it('have local migrations for every schema version', () => {
        for (const collection of SYNCED_COLLECTIONS) {
            const version = schemas[collection].version;
            for (let v = 1; v <= version; v++) expect(schemas.migrations[collection]?.[v], `${collection} ${v}`).toBeTypeOf('function');
        }
    });

    it('migrate lists to the household fields', () => {
        const doc = { id: '0190c1e2-0000-7000-8000-000000000000', name: 'WG', members: [{ email: 'a@example.com' }] };
        expect(schemas.migrations.lists[1](doc)).toEqual({ ...doc, households: [], direct: true });
        expect(Object.keys(schemas.lists.properties)).toEqual(expect.arrayContaining(['households', 'direct']));
    });

    it('migrate tasks to the dates of the schedule', () => {
        const doc = { id: '0190c1e2-0000-7000-8000-000000000000', title: 'Bad', mode: 'fixed' };
        expect(schemas.migrations.tasks[1](doc)).toEqual({ ...doc, dates: null });
        expect(schemas.tasks.properties.dates).toBeDefined();
    });
});
