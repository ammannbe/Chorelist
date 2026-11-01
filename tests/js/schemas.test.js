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
});
