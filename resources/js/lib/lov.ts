export type LovRow = { id: number; code: string; label: string; [col: string]: unknown };
export type LovResponse = { columns: { key: string; label: string }[]; data: LovRow[] };

export async function fetchLov(
    name: string,
    params: { q?: string; exact?: string; filters?: Record<string, string | number | null | undefined> } = {},
): Promise<LovResponse> {
    const qs = new URLSearchParams();
    if (params.q) {
        qs.set('q', params.q);
    }
    if (params.exact) {
        qs.set('exact', params.exact);
    }
    for (const [k, v] of Object.entries(params.filters ?? {})) {
        if (v !== null && v !== undefined && v !== '') {
            qs.set(`filters[${k}]`, String(v));
        }
    }
    const res = await fetch(`/lov/${name}?${qs.toString()}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!res.ok) {
        throw new Error(`LOV ${name} failed: ${res.status}`);
    }
    return (await res.json()) as LovResponse;
}
