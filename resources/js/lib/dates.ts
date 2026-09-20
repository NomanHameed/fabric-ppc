/** Dates display as dd-mm-yyyy everywhere (HANDOFF §7); numbers stay Latin in both locales. */
export function formatDmy(d: Date): string {
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return `${dd}-${mm}-${d.getFullYear()}`;
}

export function todayDmy(): string {
    return formatDmy(new Date());
}

/** Fixed 2-dp metres for grids and read-only fields. */
export function formatQty(value: unknown): string {
    const n = Number(value);
    return Number.isFinite(n) ? n.toFixed(2) : '';
}
