import { useState } from 'react';

import { bookingPost } from '@/pages/public/booking-requests/http';

type QuoteSnapshot = {
    revision: number;
    fingerprint: string;
    currency: string;
    fraction_digits: number;
    regular_total_minor: string;
    discount_minor: string;
    total_minor: string;
    accepted: boolean;
    nonbinding: true;
    inventory_reserved: false;
};

type Props = { reference: string; accessToken?: string };

function displayMinor(minor: string, digits: number, currency: string): string {
    if (!/^\d+$/.test(minor) || digits < 0 || digits > 3) return `${minor} ${currency}`;
    const padded = minor.padStart(digits + 1, '0');
    const split = digits > 0 ? padded.length - digits : padded.length;
    const whole = padded.slice(0, split).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${currency} ${whole}${digits > 0 ? `.${padded.slice(split)}` : ''}`;
}

/** Nonbinding current-quote preview and explicit owner acceptance. */
export default function QuoteApprovalPanel({ reference, accessToken }: Props) {
    const [quote, setQuote] = useState<QuoteSnapshot | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const path = accessToken
        ? '/booking-requests/guest'
        : `/account/booking-requests/${encodeURIComponent(reference)}`;
    const payload = accessToken ? { reference, access_token: accessToken } : {};

    async function refresh() {
        setBusy(true);
        setError('');
        setQuote(null);
        try {
            const result = await bookingPost<{ quote: QuoteSnapshot }>(`${path}/quote-review`, payload);
            setQuote(result.quote);
        } catch (err) { setError(err instanceof Error ? err.message : 'Unable to review the current quote.'); }
        finally { setBusy(false); }
    }

    async function approve() {
        if (!quote || busy || quote.accepted) return;
        setBusy(true);
        setError('');
        try {
            const result = await bookingPost<{ quote: QuoteSnapshot }>(`${path}/quote-accept`, {
                ...payload, revision: quote.revision, fingerprint: quote.fingerprint,
            });
            setQuote(result.quote);
        } catch (err) {
            setQuote(null);
            setError(`${err instanceof Error ? err.message : 'Quote acceptance failed.'} Review the latest quote before retrying.`);
        } finally { setBusy(false); }
    }

    return <section aria-label="Quote approval" className="mt-4 rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm text-slate-800">
        <h3 className="font-bold text-teal-900">Review and approve current quote</h3>
        <p className="mt-1 text-slate-600">This is a request, not a confirmed booking. Seats are not reserved and payment is not collected. Prices may change until staff conversion.</p>
        <button type="button" disabled={busy} onClick={() => void refresh()}
            className="mt-3 rounded-lg border border-teal-700 bg-white px-3 py-2 font-semibold text-teal-900 disabled:opacity-50">
            {busy ? 'Please wait…' : quote ? 'Refresh latest quote' : 'Review current price'}
        </button>
        {error && <p role="alert" className="mt-3 text-red-800">{error}</p>}
        {quote && <div className="mt-4 space-y-2">
            <p><strong>Current total:</strong> {displayMinor(quote.total_minor, quote.fraction_digits, quote.currency)}</p>
            <p><strong>Regular total:</strong> {displayMinor(quote.regular_total_minor, quote.fraction_digits, quote.currency)}</p>
            <p><strong>Discount:</strong> {displayMinor(quote.discount_minor, quote.fraction_digits, quote.currency)}</p>
            <p className="text-slate-600">Quote revision #{quote.revision}. Approval applies to this exact price, currency, traveler count and pricing option.</p>
            {quote.accepted ? <p role="status" className="font-semibold text-green-800">Approved for staff review at this quoted amount. Still not booked.</p>
                : <button type="button" disabled={busy} onClick={() => void approve()}
                    className="rounded-lg bg-teal-800 px-4 py-2.5 font-semibold text-white disabled:opacity-50">
                    I approve this exact quote
                </button>}
        </div>}
    </section>;
}
