import type { FieldErrors } from './types';

export class DestinationApiError extends Error {
    constructor(message: string, readonly status: number, readonly fields: FieldErrors = {}) {
        super(message);
        this.name = 'DestinationApiError';
    }
}

function cookie(name: string): string | null {
    const prefix = `${name}=`;
    const value = document.cookie.split('; ').find((part) => part.startsWith(prefix));
    if (!value) return null;
    try { return decodeURIComponent(value.slice(prefix.length)); } catch { return null; }
}

/** Uses Laravel's web session, XSRF cookie, JSON errors and an abort signal for stale searches. */
export async function destinationRequest<T>(
    url: string,
    options: { method?: 'GET' | 'POST' | 'PUT' | 'DELETE'; body?: unknown; signal?: AbortSignal } = {},
): Promise<T> {
    const method = options.method ?? 'GET';
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        const xsrf = cookie('XSRF-TOKEN');
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
        else if (csrf) headers['X-CSRF-TOKEN'] = csrf;
    }
    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        redirect: 'follow',
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        signal: options.signal,
    });
    if (response.redirected && new URL(response.url).pathname === '/login') {
        throw new DestinationApiError('Your session has expired. Sign in again.', 401);
    }

    const contentType = response.headers.get('content-type') ?? '';
    const raw: unknown = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (!response.ok) {
        let message = `Request failed (${response.status}).`;
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
        if (response.status === 401) message = 'Your session has expired. Sign in again.';
        if (response.status === 403) message = 'Your role does not permit destination management.';
        if (response.status === 419) message = 'Your security token has expired. Reload this page.';
        throw new DestinationApiError(message, response.status, fields);
    }
    if (response.status === 204) return undefined as T;
    if (raw === null) throw new DestinationApiError('Unexpected API response. Check Laravel logs.', response.status);
    return raw as T;
}

export function destinationErrorMessage(error: unknown): string {
    if (error instanceof DestinationApiError) return Object.values(error.fields).flat()[0] ?? error.message;
    return error instanceof Error ? error.message : 'Unexpected error. Please try again.';
}
