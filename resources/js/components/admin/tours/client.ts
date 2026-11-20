import type { FieldErrors } from './types';

export class TourApiError extends Error {
    constructor(message: string, public readonly status: number, public readonly fields: FieldErrors = {}) {
        super(message);
        this.name = 'TourApiError';
    }
}

function readCookie(name: string): string | null {
    const value = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));
    if (!value) return null;
    try { return decodeURIComponent(value.slice(name.length + 1)); } catch { return null; }
}

/** P3D.1 JSON endpoints; Laravel web-session CSRF, 422 errors and abortable GET requests. */
export async function tourRequest<T>(
    url: string,
    options: { method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'; body?: unknown; signal?: AbortSignal } = {},
): Promise<T> {
    const method = options.method ?? 'GET';
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        const xsrf = readCookie('XSRF-TOKEN');
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
        else if (csrf) headers['X-CSRF-TOKEN'] = csrf;
    }
    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        cache: 'no-store',
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        signal: options.signal,
    });
    if (response.redirected && new URL(response.url).pathname === '/login') {
        throw new TourApiError('Your session expired. Sign in and try again.', 401);
    }
    const contentType = response.headers.get('content-type') ?? '';
    const raw: unknown = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;
    if (!response.ok) {
        let message = `Tour request failed (${response.status}).`;
        let fields: FieldErrors = {};
        if (raw && typeof raw === 'object') {
            const payload = raw as { message?: unknown; errors?: unknown };
            if (typeof payload.message === 'string') message = payload.message;
            if (payload.errors && typeof payload.errors === 'object' && !Array.isArray(payload.errors)) {
                fields = Object.fromEntries(Object.entries(payload.errors).filter(
                    (entry): entry is [string, string[]] =>
                        Array.isArray(entry[1]) && entry[1].every((item: unknown) => typeof item === 'string'),
                ));
            }
        }
        if (response.status === 401) message = 'Your session expired. Sign in again.';
        if (response.status === 403) message = 'You do not have permission for this tour operation.';
        if (response.status === 419) message = 'Your security token expired. Refresh this page.';
        if (response.status === 429) message = 'Too many requests. Try again shortly.';
        throw new TourApiError(message, response.status, fields);
    }
    if (response.status === 204) return undefined as T;
    if (raw === null) throw new TourApiError('Unexpected response from the tour service.', response.status);
    return raw as T;
}

export function tourErrorMessage(error: unknown): string {
    if (error instanceof TourApiError) return Object.values(error.fields).flat()[0] ?? error.message;
    return error instanceof Error ? error.message : 'Unexpected tour error. Try again.';
}
