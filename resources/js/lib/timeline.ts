import { formatDmyAt, formatTimeAt } from '@/lib/dates';
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

/**
 * HH:mm on the mill's clock, not the viewer's.
 *
 * The server sends the instant with its `+05:00` offset, so reading it back through the browser's local
 * getters showed a Karachi event in whatever zone the browser happens to be in. What a lot's history means is
 * what the clock on the mill wall said.
 */
export function formatTime(iso: string): string {
    return formatTimeAt(iso);
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
 *
 * The day is the *mill's* day. Grouping on the viewer's calendar put a 02:00 PKT event under the previous
 * day's heading for anyone whose browser ran on UTC, which is exactly the kind of quiet wrongness a timeline
 * exists to rule out.
 */
export function groupByDay(events: TimelineEvent[]): TimelineDay[] {
    const days: TimelineDay[] = [];
    for (const event of events) {
        const day = formatDmyAt(event.occurred_at);
        const valid = day !== '';
        const key = valid ? day : event.occurred_at;
        let group = days.at(-1);
        if (group === undefined || group.key !== key) {
            group = { key, label: key, events: [] };
            days.push(group);
        }
        group.events.push({
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
