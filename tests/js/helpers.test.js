import { describe, expect, it } from 'vitest';
import { compareBy, csvValue, nextPosition, normalizeName, sameName, uuid7 } from '../../resources/js/helpers.js';

describe('names', () => {
    it('compares accents only when searching', () => {
        expect(normalizeName('  Äpfel ')).toBe('apfel');
        expect(sameName('Bad', ' bad')).toBe(true);
        expect(sameName('Bad', 'Bäd')).toBe(false);
    });
});

describe('sorting', () => {
    const byName = (a, b) => a.name.localeCompare(b.name);
    const docs = [{ id: '2', name: 'B', position: null }, { id: '1', name: 'C', position: 1 }, { id: '3', name: 'A', position: 0 }];

    it('sorts manually, by name or by creation', () => {
        expect([...docs].sort(compareBy('manual', byName, byName)).map((d) => d.name)).toEqual(['A', 'C', 'B']);
        expect([...docs].sort(compareBy('name_desc', byName, byName)).map((d) => d.name)).toEqual(['C', 'B', 'A']);
        expect([...docs].sort(compareBy('newest', byName, byName)).map((d) => d.id)).toEqual(['3', '2', '1']);
        expect(nextPosition(docs)).toBe(2);
        expect(nextPosition([{ position: null }])).toBeNull();
    });

    it('creates time ordered ids', () => {
        expect(uuid7()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    });
});

describe('CSV', () => {
    it('quotes values when needed', () => {
        expect(['Bad', 'Bad, Küche', 'A;B', 'Er sagt "Hallo"'].map(csvValue)).toEqual(['Bad', '"Bad, Küche"', '"A;B"', '"Er sagt ""Hallo"""']);
    });
});
