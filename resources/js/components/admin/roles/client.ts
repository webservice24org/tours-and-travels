/** Same-origin, cookie/session authenticated JSON API, with Laravel CSRF protection. */
export class CmsRoleApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
        readonly fields: Record<string, string[]>,
    ) {
        super(message);
        this.name = 'CmsRoleApiError';
    }
}

function cookie(name: string): string | null {
    const prefix = `${name}=`;
    const entry = document.cookie.split('; ').find((part) => part.startsWith(prefix));
    if (!entry) return null;
    try { return decodeURIComponent(entry.slice(prefix.length)); } catch { return null; }
}

export async function roleRequest<T>(
    url: string,
    options: { method?: 'GET' | 'POST' | 'PUT' | 'DELETE'; body?: unknown; signal?: AbortSignal } = {},
): Promise<T> {
    const method = options.method ?? 'GET';
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        const xsrf = cookie('XSRF-TOKEN');
        const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
        else if (meta) headers['X-CSRF-TOKEN'] = meta;
    }
    const response = await fetch(url, {
        method, headers, credentials: 'same-origin', redirect: 'follow',
        body: options.body === undefined ? undefined : JSON.stringify(options.body), signal: options.signal,
    });
    if (response.redirected && new URL(response.url).pathname === '/login') {
        throw new CmsRoleApiError('Session expired. Sign in again.', 401, {});
    }
    const contentType = response.headers.get('content-type') ?? '';
    const payload: unknown = contentType.includes('application/json')
        ? await response.json().catch(() => null) : null;
    if (!response.ok) {
        let message = `Request failed (${response.status}).`;
        let fields: Record<string, string[]> = {};
        if (payload && typeof payload === 'object') {
            const result = payload as { message?: unknown; errors?: unknown };
            if (typeof result.message === 'string') message = result.message;
            if (result.errors && typeof result.errors === 'object') {
                fields = Object.fromEntries(Object.entries(result.errors).filter(
                    (item): item is [string, string[]] => Array.isArray(item[1]) &&
                        item[1].every((part: unknown) => typeof part === 'string'),
                ));
            }
        }
        if (response.status === 403) message = 'This action requires Super Administrator access.';
        if (response.status === 401) message = 'Session expired. Sign in again.';
        if (response.status === 419) message = 'Security token expired. Reload the page.';
        throw new CmsRoleApiError(message, response.status, fields);
    }
    if (response.status === 204) return undefined as T;
    if (!payload) throw new CmsRoleApiError('Unexpected API response. Check Laravel logs.', response.status, {});
    return payload as T;
}

export function roleErrorMessage(error: unknown): string {
    if (error instanceof CmsRoleApiError) return Object.values(error.fields).flat()[0] ?? error.message;
    return error instanceof Error ? error.message : 'An unexpected error occurred.';
}
