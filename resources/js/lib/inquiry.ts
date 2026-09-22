import { t } from '@/lib/i18n';
import type { AuditRow, TimelineStep } from '@/types/inquiry';

/**
 * A ledger position as the inquiry shows it: the translated fixed positions, and `STEP:<id>` as
 * "<seq>. <operation>" when the step is on the route (its bare id otherwise: the step was removed).
 */
export function positionLabel(
    position: string | null,
    steps: Pick<TimelineStep, 'id' | 'seq' | 'operation'>[],
): string {
    if (position === null || position === '') {
        return '—';
    }
    if (position.startsWith('STEP:')) {
        const id = Number(position.slice(5));
        const step = steps.find((s) => s.id === id);
        return step
            ? `${step.seq}. ${step.operation}`
            : `${t('production.positions.step')} #${id}`;
    }
    return t(`production.positions.${position.toLowerCase()}`);
}

/** The audit row's changes as "field: old → new" lines (created rows list the new values only, deletes the old). */
export function auditChanges(row: Pick<AuditRow, 'old' | 'new'>): string[] {
    const keys = Array.from(
        new Set([...Object.keys(row.old), ...Object.keys(row.new)]),
    );
    return keys.map((k) => {
        const before = k in row.old ? auditValue(row.old[k]) : null;
        const after = k in row.new ? auditValue(row.new[k]) : null;
        if (before === null) {
            return `${k}: ${after}`;
        }
        if (after === null) {
            return `${k}: ${before} → —`;
        }
        return `${k}: ${before} → ${after}`;
    });
}

function auditValue(v: unknown): string {
    if (v === null || v === undefined || v === '') {
        return '—';
    }
    if (typeof v === 'boolean') {
        return v ? 'true' : 'false';
    }
    if (
        typeof v === 'string' ||
        typeof v === 'number' ||
        typeof v === 'bigint'
    ) {
        return String(v);
    }
    return JSON.stringify(v);
}

/** "Showing n of m" when a capped tab holds fewer rows than exist, else an empty string. */
export function showingText(shown: number, total: number): string {
    return shown < total
        ? t('inquiry.lot.showing', { n: shown, m: total })
        : '';
}
