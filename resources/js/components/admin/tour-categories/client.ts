import type { FieldErrors } from './types';

export class CategoryApiError extends Error {
    constructor(message: string, readonly status: number, readonly fields: FieldErrors = {}) {
        super(message);
        this.name = 'CategoryApiError';
    }
}

function cookie(name: string): string | null {
    const prefix = `${name}=`;
    const value = document.cookie.split('; ').find((part) => part.startsWith(prefix));
    if (!value) return null;
    try { return decodeURIComponent(value.slice(prefix.length)); } catch { return null; }
}

export async function categoryRequest<T>(
    url: string,
    options: { method?: 'GET' | 'POST' | 'PUT' | 'DELETE'; body?: unknown; signal?: AbortSignal } = {},
): Promise<T> {
    const method = options.method ?? 'GET';
    const headers: Record<string, string> = {
        Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
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
        throw new CategoryApiError('Your session expired. Please sign in again.', 401);
    }
    const contentType = response.headers.get('content-type') ?? '';
    const raw: unknown = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;
    if (!response.ok) {
        let message = `Request failed (${response.status}).`;
        let fields: FieldErrors = {};
        if (raw && typeof raw === 'object') {
            const object = raw as { message?: unknown; errors?: unknown };
            if (typeof object.message === 'string') message = object.message;
            if (object.errors && typeof object.errors === 'object') {
                fields = Object.fromEntries(Object.entries(object.errors).filter(
                    (entry): entry is [string, string[]] =>
                        Array.isArray(entry[1]) && entry[1].every((item: unknown) => typeof item === 'string'),
                ));
            }
        }
        if (response.status === 403) message = 'Your role does not permit category management.';
        if (response.status === 401) message = 'Your session expired. Please sign in again.';
        if (response.status === 419) message = 'Security token expired. Reload this page.';
        throw new CategoryApiError(message, response.status, fields);
    }
    if (response.status === 204) return undefined as T;
    if (raw === null) throw new CategoryApiError('Unexpected API response. Check Laravel logs.', response.status);
    return raw as T;
}

export function categoryErrorMessage(error: unknown): string {
    if (error instanceof CategoryApiError) return Object.values(error.fields).flat()[0] ?? error.message;
    return error instanceof Error ? error.message : 'Unexpected error. Please retry.';
}
