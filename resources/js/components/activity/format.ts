import { formatDmyAt, formatTimeAt } from '@/lib/dates';

/**
 * Timestamps for the approval queue and the action log.
 *
 * Dates stay dd-mm-yyyy and the clock is 24-hour (HANDOFF §7), and both are resolved on the *mill's* zone
 * rather than the viewer's. The server sends ISO-8601 with a `+05:00` offset; reading that back through the
 * browser's local getters showed a signup recorded at 02:00 in Karachi as the previous evening to anyone
 * whose machine ran on UTC — and a log whose timestamps depend on who is reading it cannot be used to settle
 * what happened. Digits stay Latin in every locale.
 */
export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }
    const date = formatDmyAt(iso);

    return date === '' ? '' : `${date} ${formatTimeAt(iso)}`;
}

export function formatDate(iso: string | null | undefined): string {
    return iso ? formatDmyAt(iso) : '';
}
