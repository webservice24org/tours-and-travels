/** Browser-only, same-origin requests to the P2B.3A session-authenticated JSON API. */
export class StaffApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
        readonly fields: Record<string, string[]>,
    ) {
        super(message);
        this.name = 'StaffApiError';
    }
}

function readCookie(name: string): string | null {
    const prefix = `${name}=`;
    const raw = document.cookie.split('; ').find((part) => part.startsWith(prefix));
    if (!raw) return null;
    try {
        return decodeURIComponent(raw.substring(prefix.length));
    } catch {
        return null;
    }
}

export async function staffRequest<T>(
    url: string,
    options: { method?: 'GET' | 'POST' | 'PUT' | 'PATCH'; body?: unknown; signal?: AbortSignal } = {},
): Promise<T> {
    const method = options.method ?? 'GET';
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        const xsrf = readCookie('XSRF-TOKEN');
        const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
        else if (meta) headers['X-CSRF-TOKEN'] = meta;
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        redirect: 'follow',
        signal: options.signal,
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    if (response.redirected && new URL(response.url).pathname === '/login') {
        throw new StaffApiError('Your session expired. Sign in and reload the page.', 401, {});
    }
    const contentType = response.headers.get('content-type') ?? '';
    const payload: unknown = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (!response.ok) {
        let message = `Request failed (${response.status}).`;
        let fields: Record<string, string[]> = {};
        if (payload && typeof payload === 'object') {
            const body = payload as { message?: unknown; errors?: unknown };
            if (typeof body.message === 'string') message = body.message;
            if (body.errors && typeof body.errors === 'object') {
                fields = Object.fromEntries(
                    Object.entries(body.errors).filter((item): item is [string, string[]] =>
                        Array.isArray(item[1]) && item[1].every((value: unknown) => typeof value === 'string'),
                    ),
                );
            }
        }
        if (response.status === 419) message = 'Security token expired. Reload the page and try again.';
        if (response.status === 403) message = 'You do not have permission to perform this action.';
        if (response.status === 401) message = 'Your session expired. Please sign in again.';
        throw new StaffApiError(message, response.status, fields);
    }
    if (!payload) throw new StaffApiError('Unexpected server response. Check the Laravel logs.', response.status, {});
    return payload as T;
}

export function describeStaffError(error: unknown): string {
    if (error instanceof StaffApiError) {
        const firstField = Object.values(error.fields).flat()[0];
        return firstField ?? error.message;
    }
    if (error instanceof Error) return error.message;
    return 'An unexpected error occurred.';
}
