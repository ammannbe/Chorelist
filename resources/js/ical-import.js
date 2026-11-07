import { RRule } from 'rrule';
import { addDays, normalizeDates, todayIn } from './schedule.js';

// Import of waste collection calendars (Entsorgungskalender) from iCalendar (.ics, RFC 5545) and
// vCalendar 1.0 (.vcs) files, on the device only. Every event is a collection on its local date;
// events with the same summary become one task of the mode `dates` (see the import dialog in app.js).

// Dates of the calendars are Swiss: UTC times are converted to this time zone.
const TIME_ZONE = 'Europe/Zurich';
// Repeating events are expanded up to this many occurrences, at most two years from their start.
const MAX_REPEATS = 400;
const REPEAT_DAYS = 731;

/**
 * Events of a calendar file: { summary, date (YYYY-MM-DD) }. VALARMs and other components are
 * ignored, cancelled events too. `skipped` counts repeating events that could not be expanded.
 *
 * @param {string} text content of the file
 * @returns {{ events: { summary: string, date: string }[], skipped: number }}
 */
export function parseCalendar(text) {
    const events = [];
    let skipped = 0;
    const stack = [];
    let event = null;

    for (const line of unfold(text)) {
        const property = parseLine(line);
        if (!property) continue;
        const value = property.value.trim();
        if (property.name === 'BEGIN') {
            stack.push(value.toUpperCase());
            if (value.toUpperCase() === 'VEVENT') event = { summary: '', start: null, rrule: null, exdates: [], cancelled: false };
            continue;
        }
        if (property.name === 'END') {
            if (stack.pop() === 'VEVENT' && event) {
                const dates = event.cancelled || !event.start ? [] : expand(event);
                if (dates === null) skipped++;
                for (const date of dates ?? [event.start]) events.push({ summary: event.summary, date });
                event = null;
            }
            continue;
        }
        // Only properties of the event itself (not of its alarms).
        if (!event || stack.at(-1) !== 'VEVENT') continue;
        switch (property.name) {
            case 'SUMMARY': event.summary = unescapeText(decodeValue(property)).replace(/\s+/g, ' ').trim(); break;
            case 'DTSTART': event.start = localDate(value); break;
            case 'RRULE': event.rrule = value; break;
            case 'EXDATE': event.exdates.push(...value.split(',').map(localDate).filter(Boolean)); break;
            case 'STATUS': event.cancelled = value.toUpperCase() === 'CANCELLED'; break;
            default:
        }
    }

    return { events, skipped };
}

/**
 * Events grouped by their summary (case and spaces ignored): { title, dates (sorted, each once) },
 * ordered by the first date.
 */
export function groupEvents(events) {
    const groups = new Map();
    for (const { summary, date } of events) {
        const key = summary.toLowerCase();
        if (!groups.has(key)) groups.set(key, { title: summary, dates: [] });
        groups.get(key).dates.push(date);
    }

    return [...groups.values()]
        .map((group) => ({ ...group, dates: normalizeDates(group.dates) }))
        .sort((a, b) => a.dates[0].localeCompare(b.dates[0]) || a.title.localeCompare(b.title));
}

// Keywords (lower case) of the waste types, the first match wins.
const ICONS = [
    [['sonderabfall', 'sondermüll', 'giftstoff'], '☣️'],
    [['christbaum', 'weihnachtsbaum', 'tannenbaum'], '🎄'],
    [['häcksel'], '🌳'],
    [['alteisen', 'metall', 'eisen', 'alu'], '🔩'],
    [['glas'], '🍾'],
    [['papier'], '📰'],
    [['karton'], '📦'],
    [['grüngut', 'grün', 'bio', 'kompost', 'garten'], '🌿'],
    [['kehricht', 'abfall', 'müll', 'sperrgut'], '🗑️'],
];

/** Emoji for a waste type by keyword, ♻️ otherwise. */
export function suggestIcon(title) {
    const text = title.toLowerCase();

    return ICONS.find(([keywords]) => keywords.some((keyword) => text.includes(keyword)))?.[1] ?? '♻️';
}

/**
 * Logical lines: folded lines (CRLF + space or tab) joined, and quoted-printable values of vCalendar
 * 1.0 continued after a soft line break ("=" at the end of the line).
 */
export function unfold(text) {
    const lines = [];
    for (const raw of String(text).replace(/^﻿/, '').split(/\r\n|\r|\n/)) {
        const last = lines.at(-1);
        if (last !== undefined && /^[ \t]/.test(raw)) lines[lines.length - 1] = last + raw.slice(1);
        else if (last !== undefined && /QUOTED-PRINTABLE/i.test(last.split(':')[0]) && last.endsWith('=')) lines[lines.length - 1] = last.slice(0, -1) + raw;
        else lines.push(raw);
    }

    return lines.filter((line) => line.trim() !== '');
}

/** Decodes quoted-printable text (soft line breaks removed) with a charset, UTF-8 by default. */
export function decodeQuotedPrintable(text, charset = 'utf-8') {
    const soft = text.replace(/=(\r\n|\r|\n)/g, '').replace(/=$/, '');
    const bytes = [];
    for (let i = 0; i < soft.length; i++) {
        if (soft[i] === '=' && /^[0-9A-Fa-f]{2}$/.test(soft.slice(i + 1, i + 3))) {
            bytes.push(parseInt(soft.slice(i + 1, i + 3), 16));
            i += 2;
        } else {
            bytes.push(...new TextEncoder().encode(soft[i]));
        }
    }
    try {
        return new TextDecoder(charset).decode(new Uint8Array(bytes));
    } catch {
        return new TextDecoder().decode(new Uint8Array(bytes));
    }
}

/** Unescapes iCalendar text: \, \; \n \\ */
export function unescapeText(text) {
    return text.replace(/\\([\\,;nN])/g, (match, char) => (char === 'n' || char === 'N' ? '\n' : char));
}

/**
 * Local date of a DATE or DATE-TIME value: floating and TZID times as written, UTC times (Z) in
 * Swiss time. Null for anything else.
 */
export function localDate(value) {
    const match = String(value).trim().match(/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})?(Z)?)?$/i);
    if (!match) return null;
    const [, y, m, d, hh, mm, ss, utc] = match;
    if (utc) return todayIn(TIME_ZONE, new Date(Date.UTC(+y, m - 1, +d, +hh, +mm, +(ss ?? 0))));

    return `${y}-${m}-${d}`;
}

// ---------------------------------------------------------------- internals

/** Name, parameters (upper case keys) and raw value of a content line. */
function parseLine(line) {
    // The value starts after the first colon outside of quoted parameter values.
    let quoted = false;
    let colon = -1;
    for (let i = 0; i < line.length; i++) {
        if (line[i] === '"') quoted = !quoted;
        else if (line[i] === ':' && !quoted) {
            colon = i;
            break;
        }
    }
    if (colon < 0) return null;
    const [name, ...rest] = line.slice(0, colon).split(';');
    const params = {};
    for (const param of rest) {
        const [key, value] = param.includes('=') ? [param.slice(0, param.indexOf('=')), param.slice(param.indexOf('=') + 1)] : [param];
        // vCalendar 1.0 allows bare values, e.g. SUMMARY;QUOTED-PRINTABLE:…
        if (value === undefined) params[/^(QUOTED-PRINTABLE|BASE64|8BIT|7BIT)$/i.test(key) ? 'ENCODING' : key.toUpperCase()] = key;
        else params[key.toUpperCase()] = value.replace(/^"|"$/g, '');
    }

    return { name: name.trim().toUpperCase(), params, value: line.slice(colon + 1) };
}

function decodeValue({ params, value }) {
    return /^QUOTED-PRINTABLE$/i.test(params.ENCODING ?? '') ? decodeQuotedPrintable(value, params.CHARSET || 'utf-8') : value;
}

/**
 * Dates of a repeating event (null: the rule could not be expanded, only its start date counts);
 * [start] without a rule.
 */
function expand(event) {
    if (!event.rrule) return [event.start];
    try {
        const toDate = (date) => new Date(`${date}T00:00:00Z`);
        const options = RRule.parseString(event.rrule.replace(/^RRULE:/i, ''));
        options.dtstart = toDate(event.start);
        // UNTIL as a local date (the time does not matter for whole days).
        if (options.until) options.until = toDate(options.until.toISOString().slice(0, 10));
        const rule = new RRule(options);
        const dates = rule.between(options.dtstart, toDate(addDays(event.start, REPEAT_DAYS)), true)
            .slice(0, MAX_REPEATS)
            .map((date) => date.toISOString().slice(0, 10));

        return dates.filter((date) => !event.exdates.includes(date));
    } catch {
        return null;
    }
}
