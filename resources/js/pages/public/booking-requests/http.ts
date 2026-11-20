/** Browser-only, same-origin JSON post; never put guest tokens in GET URLs. */
export async function bookingPost<T>(path: string, data: Record<string, unknown>): Promise<T> {
    const raw = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
    const xsrf = raw ? decodeURIComponent(raw.substring('XSRF-TOKEN='.length)) : '';
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : { 'X-CSRF-TOKEN': meta }),
        },
        body: JSON.stringify(data),
    });
    const result: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        const body = result as { message?: string; errors?: Record<string, string[]> } | null;
        const first = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined;
        throw new Error(response.status === 419 ? 'Your session expired. Reload the page and retry.'
            : response.status === 404 ? 'The request was not found or its private link is no longer valid.'
            : first ?? body?.message ?? 'Unable to complete the request.');
    }
    return result as T;
}

export type BookingRequestView = {
    reference: string; status: string; tour_title: string; departure_name: string;
    departure_starts_at_utc: string | null; variant_code: string;
    adults: number; children: number; expires_at_utc: string;
    can_withdraw: boolean; has_booking: boolean;
};

export function when(value: string | null): string {
    if (!value) return 'To be confirmed';
    const normalized = value.includes('T') || value.endsWith('Z') ? value : `${value.replace(' ', 'T')}Z`;
    const parsed = new Date(normalized);
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
}
