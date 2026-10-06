/** Dates display as dd-mm-yyyy everywhere (HANDOFF §7); numbers stay Latin in both locales. */
export function formatDmy(d: Date): string {
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return `${dd}-${mm}-${d.getFullYear()}`;
}

export function todayDmy(): string {
    return formatDmy(new Date());
}

/**
 * The mill's own clock, for *instants*.
 *
 * `formatDmy` above reads a Date through the browser's zone, which is right for a date-only value built as
 * local midnight and wrong for a timestamp: the server sends ISO-8601 with `+05:00`, and a viewer on UTC
 * (a laptop abroad, a CI box, a container with no TZ) turned an event at 02:00 PKT into 21:00 on the previous
 * day — the timeline then opened a day heading for a day the mill never worked. HANDOFF §7 pins the app to
 * Asia/Karachi, so these two resolve the instant on that calendar and that clock, whatever the viewer's.
 *
 * `en-GB` is chosen for the digits, not the country: it keeps numerals Latin (as `lib/i18n` requires in both
 * locales) and orders the date dd/mm/yyyy, which only needs its separators swapped for the app's dd-mm-yyyy.
 */
export const MILL_TIMEZONE = 'Asia/Karachi';

const millDate = new Intl.DateTimeFormat('en-GB', {
    timeZone: MILL_TIMEZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

const millClock = new Intl.DateTimeFormat('en-GB', {
    timeZone: MILL_TIMEZONE,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

function instant(value: string | Date): Date | null {
    const d = typeof value === 'string' ? new Date(value) : value;
    return Number.isNaN(d.getTime()) ? null : d;
}

/** dd-mm-yyyy for an instant, on the mill's calendar day. Empty string for anything unparseable. */
export function formatDmyAt(value: string | Date): string {
    const d = instant(value);
    return d === null ? '' : millDate.format(d).replace(/\//g, '-');
}

/** HH:mm (24-hour) for an instant, on the mill's clock. Empty string for anything unparseable. */
export function formatTimeAt(value: string | Date): string {
    const d = instant(value);
    return d === null ? '' : millClock.format(d);
}

/** Fixed 2-dp metres for grids and read-only fields. */
export function formatQty(value: unknown): string {
    const n = Number(value);
    return Number.isFinite(n) ? n.toFixed(2) : '';
}
