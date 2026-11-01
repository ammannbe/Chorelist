/**
 * UUID v7 (RFC 9562): time ordered, so sorting by id keeps the order of creation.
 */
export function uuid7() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    const time = BigInt(Date.now());
    for (let i = 0; i < 6; i++) {
        bytes[i] = Number((time >> BigInt(8 * (5 - i))) & 0xffn);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x70;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** A CSV cell: quoted when needed. */
export function csvValue(value) {
    const text = String(value ?? '');

    return /[",;\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/**
 * Comparable form of a product name: case and accents are ignored ("apfel" finds "Äpfel").
 */
export function normalizeName(name) {
    return String(name ?? '').trim().replace(/\s+/g, ' ').toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');
}

/**
 * Same product: case and spaces are ignored, accents not ("Apfel" is not "Äpfel").
 */
export function sameName(a, b) {
    const clean = (name) => String(name ?? '').trim().replace(/\s+/g, ' ').toLowerCase();

    return clean(a) === clean(b);
}

/**
 * Manual order first (position), entries without position after them in their default order.
 */
export function byPosition(fallback) {
    return (a, b) => (a.position ?? Infinity) - (b.position ?? Infinity) || fallback(a, b);
}

/**
 * Comparator for a sort option: manual (position), name_asc, name_desc, newest, oldest.
 * IDs are UUID v7, so they follow the creation time.
 */
export function compareBy(sort, byName, fallback) {
    const byId = (a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);
    switch (sort) {
        case 'name_asc': return byName;
        case 'name_desc': return (a, b) => byName(b, a);
        case 'newest': return (a, b) => byId(b, a);
        case 'oldest': return byId;
        default: return byPosition(fallback);
    }
}

/**
 * Next position at the end, null as long as nothing was sorted manually.
 */
export function nextPosition(docs) {
    const positions = docs.map((doc) => doc.position).filter((position) => position !== null && position !== undefined);

    return positions.length ? Math.max(...positions) + 1 : null;
}
