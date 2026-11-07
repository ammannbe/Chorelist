import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { decodeQuotedPrintable, groupEvents, localDate, parseCalendar, suggestIcon, unescapeText, unfold } from '../../resources/js/ical-import.js';

const fixture = (name) => readFileSync(new URL(`../fixtures/${name}`, import.meta.url), 'utf-8');
const calendar = (...lines) => ['BEGIN:VCALENDAR', 'VERSION:2.0', ...lines, 'END:VCALENDAR'].join('\r\n');
const event = (...lines) => ['BEGIN:VEVENT', ...lines, 'END:VEVENT'];

// Waste collection calendar of REAL Luzern 2027 (first events), as iCalendar 2.0 and vCalendar 1.0.
const LUZERN = [
    { title: 'Kehrichtsammlung', dates: ['2027-01-05', '2027-01-12', '2027-01-19', '2027-01-26', '2027-02-02'] },
    { title: 'Kartonsammlung', dates: ['2027-01-07', '2027-02-04'] },
    { title: 'Grüngutsammlung', dates: ['2027-01-13', '2027-01-27'] },
    { title: 'Papiersammlung', dates: ['2027-01-18'] },
    { title: 'Alteisen/Metallsammlung', dates: ['2027-03-08'] },
];

describe('waste calendar files', () => {
    it('reads the iCalendar file (TZID, alarms ignored)', () => {
        const { events, skipped } = parseCalendar(fixture('waste-calendar-luzern.ics'));
        expect(skipped).toBe(0);
        expect(events).toHaveLength(11);
        expect(groupEvents(events)).toEqual(LUZERN);
    });

    it('reads the vCalendar 1.0 file (quoted-printable) the same', () => {
        expect(groupEvents(parseCalendar(fixture('waste-calendar-luzern.vcs')).events)).toEqual(LUZERN);
    });
});

describe('parseCalendar', () => {
    it('takes summary and date of the event, not of its alarm', () => {
        const text = calendar(...event(
            'DTSTART;VALUE=DATE:20270105',
            'BEGIN:VALARM', 'SUMMARY:Alarm', 'DESCRIPTION:Erinnerung', 'TRIGGER:-PT12H', 'END:VALARM',
            'SUMMARY;LANGUAGE=de:Papier\\, Karton',
        ));
        expect(parseCalendar(text).events).toEqual([{ summary: 'Papier, Karton', date: '2027-01-05' }]);
    });

    it('unfolds folded lines and accepts LF line ends', () => {
        const text = ['BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'DTSTART:20270105T064500', 'SUMMARY:Grüngut', ' sammlung', 'END:VEVENT', 'END:VCALENDAR'].join('\n');
        expect(parseCalendar(text).events).toEqual([{ summary: 'Grüngutsammlung', date: '2027-01-05' }]);
    });

    it('converts UTC times to the Swiss date', () => {
        const text = calendar(...event('DTSTART:20270104T230000Z', 'SUMMARY:Kehricht'), ...event('DTSTART:20270704T215959Z', 'SUMMARY:Kehricht'));
        expect(parseCalendar(text).events.map((each) => each.date)).toEqual(['2027-01-05', '2027-07-04']);
    });

    it('skips cancelled events and expands repeating ones', () => {
        const text = calendar(
            ...event('DTSTART;VALUE=DATE:20270105', 'SUMMARY:Abgesagt', 'STATUS:CANCELLED'),
            ...event('DTSTART;TZID=Europe/Zurich:20270105T070000', 'RRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=3', 'EXDATE;TZID=Europe/Zurich:20270119T070000', 'SUMMARY:Karton'),
        );
        expect(groupEvents(parseCalendar(text).events)).toEqual([{ title: 'Karton', dates: ['2027-01-05', '2027-02-02'] }]);
    });

    it('counts rules it cannot expand and keeps their start', () => {
        const { events, skipped } = parseCalendar(calendar(...event('DTSTART;VALUE=DATE:20270105', 'RRULE:FREQ=SOMETIMES', 'SUMMARY:Glas')));
        expect(skipped).toBe(1);
        expect(events).toEqual([{ summary: 'Glas', date: '2027-01-05' }]);
    });

    it('finds nothing in other files', () => {
        expect(parseCalendar('Hello').events).toEqual([]);
        expect(parseCalendar(calendar('BEGIN:VTODO', 'SUMMARY:x', 'DTSTART:20270105', 'END:VTODO')).events).toEqual([]);
    });
});

describe('helpers', () => {
    it('decode quoted-printable with soft line breaks', () => {
        expect(decodeQuotedPrintable('Gr=C3=BCngutsammlung')).toBe('Grüngutsammlung');
        expect(decodeQuotedPrintable('Gr=C3=\r\n=BCngut')).toBe('Grüngut');
        expect(decodeQuotedPrintable('Gr=FCn', 'ISO-8859-1')).toBe('Grün');
    });

    it('join quoted-printable soft line breaks of vCalendar 1.0', () => {
        expect(unfold('SUMMARY;ENCODING=QUOTED-PRINTABLE:Gr=C3=\r\n=BCngut\r\nEND:VEVENT')).toEqual(['SUMMARY;ENCODING=QUOTED-PRINTABLE:Gr=C3=BCngut', 'END:VEVENT']);
    });

    it('unescape text', () => {
        expect(unescapeText('a\\, b\\; c\\nd\\\\e')).toBe('a, b; c\nd\\e');
    });

    it('read dates', () => {
        expect(localDate('20270105')).toBe('2027-01-05');
        expect(localDate('20270105T064500')).toBe('2027-01-05');
        expect(localDate('20271231T230000Z')).toBe('2028-01-01');
        expect(localDate('tomorrow')).toBeNull();
    });

    it('suggest an icon by keyword', () => {
        expect(suggestIcon('Kehrichtsammlung')).toBe('🗑️');
        expect(suggestIcon('Papiersammlung')).toBe('📰');
        expect(suggestIcon('Kartonsammlung')).toBe('📦');
        expect(suggestIcon('Grüngutsammlung')).toBe('🌿');
        expect(suggestIcon('Alteisen/Metallsammlung')).toBe('🔩');
        expect(suggestIcon('Sonderabfall')).toBe('☣️');
        expect(suggestIcon('Häckseldienst')).toBe('🌳');
        expect(suggestIcon('Christbaumabfuhr')).toBe('🎄');
        expect(suggestIcon('Altglas')).toBe('🍾');
        expect(suggestIcon('Textilien')).toBe('♻️');
    });
});
