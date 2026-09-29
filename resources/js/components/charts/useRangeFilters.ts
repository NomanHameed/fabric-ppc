import { reactive } from 'vue';

/**
 * The date-range + dimension filters the dashboard and the capacity screen share.
 *
 * Two rules the screens depend on:
 *
 *  - **The server does the filtering.** `apply()` only re-issues the request; nothing here slices a dataset
 *    that was already fetched, so a year-long range never has to arrive in the browser to be narrowed.
 *  - **The filters live in the URL.** A range someone is looking at survives a refresh and can be pasted to a
 *    colleague. `history.replaceState` is used rather than an Inertia visit because the page's own props do
 *    not change — only the JSON the page fetches does.
 */
export type RangeFilters = {
    from: string;
    to: string;
    department_id: string;
    operation_id: string;
};

export type RangePreset = 'week' | 'month' | 'quarter';

function iso(date: Date): string {
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${mm}-${dd}`;
}

export function presetRange(
    preset: RangePreset,
    today = new Date(),
): { from: string; to: string } {
    const to = iso(today);
    if (preset === 'month') {
        return {
            from: iso(new Date(today.getFullYear(), today.getMonth(), 1)),
            to,
        };
    }
    const back = preset === 'week' ? 6 : 89;
    const from = new Date(
        today.getFullYear(),
        today.getMonth(),
        today.getDate() - back,
    );
    return { from: iso(from), to };
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

function fromUrl(search: string, defaults: RangeFilters): RangeFilters {
    const params = new URLSearchParams(search);
    const read = (
        key: keyof RangeFilters,
        validate: (v: string) => boolean,
    ): string => {
        const value = params.get(key);
        return value !== null && validate(value) ? value : defaults[key];
    };

    const filters: RangeFilters = {
        from: read('from', (v) => ISO_DATE.test(v)),
        to: read('to', (v) => ISO_DATE.test(v)),
        department_id: read('department_id', (v) => /^\d+$/.test(v)),
        operation_id: read('operation_id', (v) => /^\d+$/.test(v)),
    };
    // A backwards range would be rejected by the server; correcting it here keeps the inputs honest.
    if (filters.to < filters.from) {
        filters.to = filters.from;
    }
    return filters;
}

export function useRangeFilters(
    options: { preset?: RangePreset; dimensions?: boolean } = {},
): {
    filters: RangeFilters;
    query: () => string;
    apply: () => void;
    adopt: (from: string, to: string) => boolean;
    reset: () => void;
    setPreset: (preset: RangePreset) => void;
} {
    const dimensions = options.dimensions ?? true;
    const base = presetRange(options.preset ?? 'month');
    const defaults: RangeFilters = {
        ...base,
        department_id: '',
        operation_id: '',
    };

    const initial =
        typeof window === 'undefined'
            ? defaults
            : fromUrl(window.location.search, defaults);
    const filters = reactive<RangeFilters>({ ...initial });

    function query(): string {
        const params = new URLSearchParams();
        params.set('from', filters.from);
        params.set('to', filters.to);
        if (dimensions && filters.department_id !== '') {
            params.set('department_id', filters.department_id);
        }
        if (dimensions && filters.operation_id !== '') {
            params.set('operation_id', filters.operation_id);
        }
        return params.toString();
    }

    function syncUrl(): void {
        if (typeof window === 'undefined') {
            return;
        }
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}?${query()}`,
        );
    }

    return {
        filters,
        query,
        apply(): void {
            if (filters.to < filters.from) {
                filters.to = filters.from;
            }
            syncUrl();
        },
        /**
         * Take the range the server actually ran.
         *
         * Every one of these endpoints caps an unbounded range (a year), so what came back is not always what
         * was asked for. Without this the inputs say one thing and the figures beside them another, and the
         * URL someone copies does not reproduce what they were looking at. Returns true when the server
         * narrowed the range, so the screen can say so out loud instead of quietly changing the answer.
         */
        adopt(from: string, to: string): boolean {
            if (filters.from === from && filters.to === to) {
                return false;
            }
            filters.from = from;
            filters.to = to;
            syncUrl();

            return true;
        },
        reset(): void {
            Object.assign(filters, defaults);
            syncUrl();
        },
        setPreset(preset: RangePreset): void {
            Object.assign(filters, presetRange(preset));
        },
    };
}
