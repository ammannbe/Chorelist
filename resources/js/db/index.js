import { addRxPlugin, createRxDatabase, removeRxDatabase } from 'rxdb/plugins/core';
import { RxDBCleanupPlugin } from 'rxdb/plugins/cleanup';
import { RxDBMigrationSchemaPlugin } from 'rxdb/plugins/migration-schema';
import { replicateRxCollection } from 'rxdb/plugins/replication';
import { Subject, map } from 'rxjs';
import * as schemas from './schemas.js';

// Local documents are migrated when a schema version increases (schemas.migrations).
addRxPlugin(RxDBMigrationSchemaPlugin);
// Deleted documents are kept for the replication, then removed from the device for good.
addRxPlugin(RxDBCleanupPlugin);

export const SYNCED_COLLECTIONS = ['lists', 'tasks', 'completions', 'activities', 'absences'];
// Written by the server only.
const PULL_ONLY = ['activities'];

// Replication run per collection (default 1). After a deploy the old app version keeps syncing
// until the update is accepted: it pulls server fields it does not know yet and its checkpoint
// moves past them. When a schema version adds fields the server already fills, increase the
// collection's number here as well, so every client pulls the collection once more.
const REPLICATION_RUNS = {};

/**
 * Local database. Everything the UI shows is read from here, the
 * replication syncs it with the server whenever a connection is available.
 *
 * @param {object} options
 * @param {import('rxdb').RxStorage} options.storage Dexie (IndexedDB) in the browser
 */
export async function createDatabase({ storage, name = 'chorelist', multiInstance = true }) {
    const db = await createRxDatabase({
        name,
        storage,
        multiInstance,
        closeDuplicates: true,
        cleanupPolicy: { minimumDeletedTime: 1000 * 60 * 60 * 24 * 7 },
    });

    await db.addCollections(Object.fromEntries(
        SYNCED_COLLECTIONS.map((collection) => [collection, { schema: schemas[collection], migrationStrategies: schemas.migrations[collection] ?? {} }]),
    ));

    // Every local change gets a new version, the server detects conflicts with it.
    for (const collection of SYNCED_COLLECTIONS.filter((each) => !PULL_ONLY.includes(each))) {
        db[collection].preSave((data, doc) => {
            data.version = (doc.version ?? 0) + 1;
        }, false);
    }

    return db;
}

// Ids of documents removed on this device only (removeLocally), their deletion is not pushed.
const localOnly = new Set();

/**
 * Removes documents from this device without deleting them on the server, e.g. the data of a
 * list the user left.
 *
 * @param {import('rxdb').RxDocument[]} docs
 */
export function removeLocally(docs) {
    for (const doc of docs) localOnly.add(doc.id);

    return Promise.all(docs.map((doc) => doc.remove()));
}

const skipLocalOnly = (doc) => (doc._deleted && localOnly.has(doc.id) ? null : doc);

/**
 * Deletes the local database (last resort when it cannot be opened anymore).
 */
export function removeDatabase({ storage, name = 'chorelist' }) {
    return removeRxDatabase(name, storage);
}

/**
 * Starts the live replication of all collections (RxDB HTTP replication).
 *
 * @param {import('rxdb').RxDatabase} db
 * @param {ReturnType<import('../api.js').createApi>} api
 */
export function startReplication(db, api, { retryTime = 5000 } = {}) {
    const resync$ = new Subject();

    const states = SYNCED_COLLECTIONS.map((collection) => replicateRxCollection({
        collection: db[collection],
        replicationIdentifier: `chorelist-${collection}-v${REPLICATION_RUNS[collection] ?? 1}`,
        live: true,
        retryTime,
        waitForLeadership: true,
        pull: {
            batchSize: 100,
            handler: (checkpoint, batchSize) => api.get(`/sync/${collection}/pull?${query({ ...checkpoint, limit: batchSize })}`),
            // The server has no push channel: re-check on demand (online, app visible, interval).
            stream$: resync$.pipe(map(() => 'RESYNC')),
        },
        push: PULL_ONLY.includes(collection) ? undefined : {
            batchSize: 50,
            handler: (rows) => api.post(`/sync/${collection}/push`, rows),
            modifier: skipLocalOnly,
        },
    }));

    return {
        states,
        resync: () => resync$.next(),
        awaitInSync: () => Promise.all(states.map((state) => state.awaitInSync())),
        cancel: () => Promise.all(states.map((state) => state.cancel())),
    };
}

function query(params) {
    return new URLSearchParams(Object.entries(params).filter(([, value]) => value !== null && value !== undefined));
}
