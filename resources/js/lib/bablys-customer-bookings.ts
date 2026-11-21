/** Allowlisted server-driven, per-customer booking DTO. */
export type CustomerBooking = {
    reference: string;
    status: string;
    payment_state: string;
    tour_title: string;
    departure_at_utc: string | null;
    seats: number;
    adults: number;
    children: number;
    currency: string;
    fraction_digits: number;
    total_minor: number;
    created_at_utc: string | null;
};

export function bookingMoney(minor: number, currency: string, fractionDigits: number) {
    const digits = Math.max(0, Math.min(3, fractionDigits));
    try {
        return new Intl.NumberFormat('en-BD', {
            style: 'currency', currency,
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(minor / (10 ** digits));
    } catch {
        return `${currency} ${(minor / (10 ** digits)).toFixed(digits)}`;
    }
}

export function bookingWhen(value: string | null) {
    if (!value) return 'Not recorded';
    const normalized = value.endsWith('Z') || /[+-]\d\d:\d\d$/.test(value)
        ? value : `${value.replace(' ', 'T')}Z`;
    const parsed = new Date(normalized);
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
}

export function bookingLabel(value: string) {
    return value.replaceAll('_', ' ');
}
