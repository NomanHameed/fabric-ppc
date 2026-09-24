import { formatDmy } from '@/lib/dates';

/**
 * Timestamps for the approval queue and the action log. Dates stay dd-mm-yyyy (HANDOFF §7) and the clock is
 * 24-hour, both rendered from the browser's own zone — the server sends ISO-8601 with an offset.
 * Digits are Latin in every locale, so the string is built by hand rather than through `toLocaleString`.
 */
export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) {
        return '';
    }
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${formatDmy(d)} ${hh}:${mm}`;
}

export function formatDate(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : formatDmy(d);
}
