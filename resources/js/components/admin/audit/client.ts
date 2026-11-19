/** Same-origin session-authenticated, read-only audit API. No mutation methods. */
export class AuditApiError extends Error {
    constructor(message: string, readonly status: number) {
        super(message);
        this.name = 'AuditApiError';
    }
}

export async function auditGet<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        method: 'GET',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        redirect: 'follow',
        signal,
    });
    if (response.redirected && new URL(response.url).pathname === '/login') {
        throw new AuditApiError('Your session expired. Sign in again.', 401);
    }
    if (!response.ok) {
        if (response.status === 403) throw new AuditApiError('Audit access is restricted to authorized owners.', 403);
        if (response.status === 401) throw new AuditApiError('Your session expired. Sign in again.', 401);
        if (response.status === 404) throw new AuditApiError('Audit event not found.', 404);
        if (response.status === 422) throw new AuditApiError('Invalid filter. Review the date range and values.', 422);
        if (response.status === 429) throw new AuditApiError('Too many requests. Try again shortly.', 429);
        throw new AuditApiError(`Audit request failed (${response.status}).`, response.status);
    }
    if (!(response.headers.get('content-type') ?? '').includes('application/json')) {
        throw new AuditApiError('Unexpected response from the audit service.', response.status);
    }
    return (await response.json()) as T;
}

export function auditError(error: unknown): string {
    return error instanceof Error ? error.message : 'Unable to load audit data.';
}
