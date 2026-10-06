/**
 * JSON calls outside Inertia (the sheet screen's lot context and batch split). Laravel's CSRF middleware accepts
 * the `XSRF-TOKEN` cookie echoed back as the `X-XSRF-TOKEN` header, which is what Inertia's own requests do.
 */
export function xsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);
    return match ? decodeURIComponent(match[1]) : null;
}

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
        message?: string,
    ) {
        super(message ?? `Request failed: ${status}`);
    }
}

type CallOptions = {
    method: 'GET' | 'POST';
    headers?: Record<string, string>;
    body?: string;
    signal?: AbortSignal;
};

/**
 * True for the rejection an abort produces.
 *
 * A screen that starts a new request abandons the one before it; that abandoned request must not be allowed
 * to paint "could not be loaded" over a load which is still perfectly healthy. Callers therefore test for this
 * and drop the error silently, rather than treating every rejection as a failed request.
 */
export function isAbortError(error: unknown): boolean {
    return (
        typeof error === 'object' &&
        error !== null &&
        (error as { name?: unknown }).name === 'AbortError'
    );
}

async function call<T>(url: string, init: CallOptions): Promise<T> {
    const token = xsrfToken();
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...init.headers,
    };
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }
    const res = await fetch(url, {
        credentials: 'same-origin',
        method: init.method,
        body: init.body,
        headers,
        signal: init.signal,
    });
    if (!res.ok) {
        let body: { message?: string; errors?: Record<string, string[]> } = {};
        try {
            body = (await res.json()) as typeof body;
        } catch {
            // no JSON body: the status is all we know
        }
        throw new ApiError(res.status, body.errors ?? {}, body.message);
    }
    return (await res.json()) as T;
}

/**
 * `signal` lets a screen abandon a read it no longer wants — the range filter that was changed twice in a
 * second, or a page left before its figures arrived. The rejection is an AbortError (`isAbortError`), not an
 * `ApiError`, so it is distinguishable from a request the server actually refused.
 */
export function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    return call<T>(url, { method: 'GET', signal });
}

export function postJson<T>(url: string, body: unknown): Promise<T> {
    return call<T>(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
}
