import { formatDmy } from '@/lib/dates';
import type { TimelineEvent } from '@/types/analytics';

/**
 * Turning the timeline endpoint's flat list into the story the screen tells.
 *
 * The server already writes each event's sentence in the current locale (`TimelineEvent::title()`), so nothing
 * here composes copy. What it does is group the events by day and give each one a tone, because a lot's history
 * is read as "this day, then this day", and because a loss and a dispatch should not look alike.
 */
export type TimelineTone =
    | 'neutral'
    | 'brand'
    | 'success'
    | 'warning'
    | 'danger'
    | 'info';

const TONES: Record<string, TimelineTone> = {
    loaded: 'info',
    issued: 'info',
    moved: 'brand',
    processed: 'brand',
    reprocessed: 'warning',
    dispatched: 'success',
    loss: 'danger',
    adjusted: 'neutral',
    reversed: 'warning',
    state_changed: 'warning',
    colour_status_changed: 'info',
    colour_override_approved: 'success',
    action: 'neutral',
};

export function toneFor(type: string): TimelineTone {
    return TONES[type] ?? 'neutral';
}

/** HH:mm in the browser's zone, which the app pins to Asia/Karachi. */
export function formatTime(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) {
        return '';
    }
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export type TimelineDay = {
    key: string;
    label: string;
    events: (TimelineEvent & { tone: TimelineTone; time: string })[];
};

/**
 * One group per calendar day, oldest first — the order a story is told in, and the order the endpoint already
 * returns. An unparseable timestamp keeps its own group rather than being dropped: a timeline that quietly
 * loses an event is worse than one with an odd heading.
 */
export function groupByDay(events: TimelineEvent[]): TimelineDay[] {
    const days: TimelineDay[] = [];
    for (const event of events) {
        const d = new Date(event.occurred_at);
        const valid = !Number.isNaN(d.getTime());
        const key = valid ? formatDmy(d) : event.occurred_at;
        let day = days.at(-1);
        if (day === undefined || day.key !== key) {
            day = { key, label: key, events: [] };
            days.push(day);
        }
        day.events.push({
            ...event,
            tone: toneFor(event.type),
            time: valid ? formatTime(event.occurred_at) : '',
        });
    }
    return days;
}

/** "Issued to floor → Dyeing", or just one side when only one is known. Null when neither is. */
export function movement(
    event: TimelineEvent,
    template: (r: { from: string; to: string }) => string,
): string | null {
    if (event.from === null && event.to === null) {
        return null;
    }
    if (event.from !== null && event.to !== null && event.from !== event.to) {
        return template({ from: event.from, to: event.to });
    }
    return event.to ?? event.from;
}
