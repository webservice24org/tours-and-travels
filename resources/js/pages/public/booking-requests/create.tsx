import type { ReactNode } from 'react';
import PublicSiteLayout from '../../../layouts/public-site-layout';

import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';

import { bookingPost } from './http';

type SeatSummary = {
    total_seats: number;
    confirmed_seats: number;
    confirmed_bookings: number;
    pending_seats: number;
    pending_bookings: number;
    other_committed_seats: number;
    available_seats: number;
};

type Props = {
    tour: { id: number; title: string; slug: string };
    departures: { id: number; name: string; starts_at: string; timezone: string; seat_summary: SeatSummary }[];
    variants: { code: string; name: string; currency: string }[];
    customer: { name: string; email: string; email_verified: boolean } | null;
    nonbinding: true; submission_id: string;
    departure_selection?: { requested: boolean; selected_id: number | null; notice: string | null };
};
type QuoteResponse = { nonbinding: true; inventory_reserved: false; quote: {
    currency: string; fraction_digits: number; total_minor: number;
    regular_total_minor: number; discount_minor: number;
}};
const field = 'mt-1 block min-h-11 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900 focus:border-teal-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2';
const label = 'block text-sm font-semibold text-slate-700';
const button = 'inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-teal-800 px-5 py-3 text-center font-semibold text-white hover:bg-teal-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';
const errorFieldIds: Record<string, string> = { departure_id: 'departure', variant_code: 'option', adults: 'adults', children: 'children', contact_name: 'contact_name', contact_email: 'contact_email', contact_phone: 'contact_phone', consent: 'consent' };
function priceString(quote: QuoteResponse['quote']): string {
    const fraction = Math.max(0, Math.min(3, quote.fraction_digits));
    return `${quote.currency} ${(quote.total_minor / (10 ** fraction)).toLocaleString(undefined, { minimumFractionDigits: fraction, maximumFractionDigits: fraction })}`;
}

export default function CreateBookingRequest({ tour, departures, variants, customer, submission_id, departure_selection }: Props) {
    const form = useForm({
        tour_id: tour.id,
        // A stale linked departure must not silently become a different date.
        departure_id: departure_selection?.requested
            ? (departure_selection.selected_id ?? 0)
            : (departures[0]?.id ?? 0),
        variant_code: variants[0]?.code ?? '',
        adults: 1,
        children: 0,
        contact_name: customer?.name ?? '',
        contact_email: customer?.email ?? '',
        contact_phone: '',
        consent: false,
        submission_id,
    });
    const [quote, setQuote] = useState<QuoteResponse['quote'] | null>(null);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const [quoteError, setQuoteError] = useState('');
    const [quoteBusy, setQuoteBusy] = useState(false);
    const [selectionNotice, setSelectionNotice] = useState(departure_selection?.notice ?? '');
    const disabled = !departures.length || !variants.length || (!!customer && !customer.email_verified);
    const noDepartureSelected = form.data.departure_id <= 0;
    const selectedDeparture = departures.find((row) => row.id === form.data.departure_id);
    const validationErrors = Object.entries(form.errors).filter(([, message]) => Boolean(message));

    async function preview() {
        setQuoteBusy(true); setQuoteError(''); setQuote(null);
        try {
            const result = await bookingPost<QuoteResponse>('/booking-requests/quote', {
                tour_id: form.data.tour_id,
                departure_id: form.data.departure_id,
                variant_code: form.data.variant_code,
                adults: form.data.adults,
                children: form.data.children,
            });
            setQuote(result.quote);
        } catch (e) { setQuoteError(e instanceof Error ? e.message : 'Quote unavailable.'); }
        finally { setQuoteBusy(false); }
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (disabled || noDepartureSelected) return;
        form.post('/booking-requests', {
            preserveScroll: true,
            onError: () => {
                // Wait for the error summary to render, then put keyboard focus there.
                window.requestAnimationFrame(() => errorSummaryRef.current?.focus());
            },
        });
    }

    return <main className="min-h-screen bg-slate-50 text-slate-900">
        <Head title={`Request ${tour.title} | Bablys Tours & Travels`} />
        <div className="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6 sm:py-12">
            <nav aria-label="Enquiry breadcrumbs" className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm font-semibold text-teal-800">
                <Link href={`/tours/${encodeURIComponent(tour.slug)}`} className="hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600">← Tour details</Link>
                <Link href="/tours" className="hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600">All tours</Link>
            </nav>
            <header className="mt-4">
                <p className="text-sm font-bold uppercase tracking-wide text-teal-800">Bablys Tours &amp; Travels</p>
                <h1 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Request a place on {tour.title}</h1>
                <p className="mt-3 text-slate-600">This is a request only. No seats are reserved, no booking is confirmed, and no payment is collected. Staff will check availability and the current price before confirming any booking.</p>
            </header>
            <form onSubmit={submit} noValidate={false} aria-label="Booking enquiry request" className="mt-7 min-w-0 space-y-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
                {validationErrors.length > 0 && (
                    <div ref={errorSummaryRef} tabIndex={-1} role="alert" aria-labelledby="booking-errors-heading" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">
                        <h2 id="booking-errors-heading" className="text-base font-bold">Please correct the highlighted fields</h2>
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            {validationErrors.map(([name, message]) => (
                                <li key={name}>
                                    {errorFieldIds[name] ? <a href={`#${errorFieldIds[name]}`} className="underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{message}</a> : <span>{message}</span>}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {disabled && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                    {customer && !customer.email_verified ? 'Verify your account email to make an account booking request.' : 'No currently requestable departure or pricing option is available.'}
                </p>}
                {selectionNotice && <p id="departure-selection-notice" role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-900">{selectionNotice}</p>}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2"><label className={label} htmlFor="departure">Departure</label>
                        <select id="departure" className={field} aria-invalid={Boolean(form.errors.departure_id)} aria-describedby={[selectionNotice ? 'departure-selection-notice' : '', selectedDeparture ? 'departure-context' : '', form.errors.departure_id ? 'departure-error' : ''].filter(Boolean).join(' ') || undefined} value={form.data.departure_id} onChange={(e) => { form.setData('departure_id', Number(e.target.value)); setQuote(null); setQuoteError(''); setSelectionNotice(''); }} disabled={disabled} required>
                            <option value={0} disabled>Choose an available departure</option>
                            {departures.map((d) => <option key={d.id} value={d.id}>{d.name} · {d.starts_at} UTC ({d.timezone}) · {d.seat_summary.available_seats} seats available</option>)}
                        </select>
                        {selectedDeparture && <div id="departure-context" aria-live="polite" className="mt-2 rounded-lg border border-teal-100 bg-teal-50 p-3 text-sm text-teal-950">
                            <p>Enquiry preference: {selectedDeparture.name} · {selectedDeparture.starts_at} UTC ({selectedDeparture.timezone}). No seat is reserved.</p>
                            <dl className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <div><dt>Total seats</dt><dd className="font-bold tabular-nums">{selectedDeparture.seat_summary.total_seats}</dd></div>
                                <div><dt>Confirmed seats</dt><dd className="font-bold tabular-nums">{selectedDeparture.seat_summary.confirmed_seats}</dd></div>
                                <div><dt>Pending seats</dt><dd className="font-bold tabular-nums">{selectedDeparture.seat_summary.pending_seats}</dd></div>
                                <div><dt>Available seats</dt><dd className="font-bold tabular-nums">{selectedDeparture.seat_summary.available_seats}</dd></div>
                            </dl>
                            <p className="mt-2 text-xs">{selectedDeparture.seat_summary.confirmed_bookings} confirmed booking(s), {selectedDeparture.seat_summary.pending_bookings} pending booking(s). Counts may change before staff review.</p>
                            {selectedDeparture.seat_summary.other_committed_seats > 0 && <p className="mt-1 text-xs">Other inventory commitments: {selectedDeparture.seat_summary.other_committed_seats} seat(s).</p>}
                        </div>}
                        {form.errors.departure_id && <p id="departure-error" className="mt-1 text-sm text-red-700">{form.errors.departure_id}</p>}
                    </div>
                    <div className="sm:col-span-2"><label className={label} htmlFor="option">Pricing option</label>
                        <select id="option" className={field} aria-invalid={Boolean(form.errors.variant_code)} aria-describedby={form.errors.variant_code ? 'option-error' : undefined} value={form.data.variant_code} onChange={(e) => { form.setData('variant_code', e.target.value); setQuote(null); }} disabled={disabled} required>
                            {variants.map((v) => <option key={v.code} value={v.code}>{v.name} ({v.currency})</option>)}
                        </select>{form.errors.variant_code && <p id="option-error" className="mt-1 text-sm text-red-700">{form.errors.variant_code}</p>}
                    </div>
                    <div><label className={label} htmlFor="adults">Adults</label>
                        <input id="adults" className={field} aria-invalid={Boolean(form.errors.adults)} aria-describedby={form.errors.adults ? 'adults-error' : undefined} type="number" min="1" max="30" value={form.data.adults} onChange={(e) => { form.setData('adults', Number(e.target.value)); setQuote(null); }} required />
                        {form.errors.adults && <p id="adults-error" className="mt-1 text-sm text-red-700">{form.errors.adults}</p>}
                    </div>
                    <div><label className={label} htmlFor="children">Children</label>
                        <input id="children" className={field} aria-invalid={Boolean(form.errors.children)} aria-describedby={form.errors.children ? 'children-error' : undefined} type="number" min="0" max="30" value={form.data.children} onChange={(e) => { form.setData('children', Number(e.target.value)); setQuote(null); }} required />
                        {form.errors.children && <p id="children-error" className="mt-1 text-sm text-red-700">{form.errors.children}</p>}
                    </div>
                </div>
                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div className="flex flex-wrap items-center gap-3">
                        <button className="inline-flex min-h-11 items-center justify-center rounded-lg border border-teal-700 px-4 py-2 font-semibold text-teal-800 hover:bg-teal-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:opacity-50" type="button" disabled={disabled || noDepartureSelected || quoteBusy} onClick={() => void preview()}>{quoteBusy ? 'Calculating…' : 'Preview current price'}</button>
                        {quote && <strong role="status" aria-live="polite" className="text-lg text-teal-900">{priceString(quote)}</strong>}
                    </div>
                    {quoteError && <p role="alert" className="mt-2 text-sm text-red-700">{quoteError}</p>}
                    <p className="mt-2 text-sm text-slate-600">Price is a nonbinding server-calculated preview; inventory and price may change before staff review.</p>
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label className={label} htmlFor="contact_name">Contact name</label>
                        <input id="contact_name" className={field} aria-invalid={Boolean(form.errors.contact_name)} aria-describedby={form.errors.contact_name ? 'contact_name-error' : undefined} autoComplete="name" maxLength={120} value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} readOnly={!!customer} required={!customer} />
                        {form.errors.contact_name && <p id="contact_name-error" className="mt-1 text-sm text-red-700">{form.errors.contact_name}</p>}
                    </div>
                    <div><label className={label} htmlFor="contact_email">Email address</label>
                        <input id="contact_email" className={field} aria-invalid={Boolean(form.errors.contact_email)} aria-describedby={form.errors.contact_email ? 'contact_email-error' : undefined} type="email" autoComplete="email" maxLength={254} value={form.data.contact_email} onChange={(e) => form.setData('contact_email', e.target.value)} readOnly={!!customer} required={!customer} />
                        {form.errors.contact_email && <p id="contact_email-error" className="mt-1 text-sm text-red-700">{form.errors.contact_email}</p>}
                    </div>
                    <div className="sm:col-span-2"><label className={label} htmlFor="contact_phone">Phone (optional)</label>
                        <input id="contact_phone" className={field} aria-invalid={Boolean(form.errors.contact_phone)} aria-describedby={form.errors.contact_phone ? 'contact_phone-error' : undefined} type="tel" autoComplete="tel" maxLength={25} value={form.data.contact_phone} onChange={(e) => form.setData('contact_phone', e.target.value)} />
                        {form.errors.contact_phone && <p id="contact_phone-error" className="mt-1 text-sm text-red-700">{form.errors.contact_phone}</p>}
                    </div>
                </div>
                <label className="flex items-start gap-3 text-sm leading-6 text-slate-700">
                    <input id="consent" type="checkbox" required aria-invalid={Boolean(form.errors.consent)} aria-describedby={form.errors.consent ? 'consent-error' : undefined} className="mt-1 h-5 w-5 shrink-0 accent-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600" checked={form.data.consent} onChange={(e) => form.setData('consent', e.target.checked)} />
                    <span>I consent to Bablys Tours &amp; Travels using these contact details to process and respond to my booking request. I understand this does not reserve a seat or authorize payment.</span>
                </label>
                {form.errors.consent && <p id="consent-error" role="alert" className="text-sm text-red-700">{form.errors.consent}</p>}
                <button type="submit" className={button} disabled={disabled || noDepartureSelected || form.processing || !form.data.consent}>
                    {form.processing ? 'Sending request…' : 'Send booking request'}
                </button>
                {Object.entries(form.errors).filter(([key]) => !['departure_id', 'variant_code', 'adults', 'children', 'contact_name', 'contact_email', 'contact_phone', 'consent'].includes(key)).map(([key, error]) =>
                    <p key={key} role="alert" className="text-sm text-red-700">{error}</p>)}
                <p className="text-xs text-slate-500">Guest customers receive separate email verification and private management links. Signed-in customers must have verified their account email.</p>
            </form>
        </div>
    </main>;
}

// Public enquiry/customer request pages never inherit the CMS/dashboard sidebar.
CreateBookingRequest.layout = (page: ReactNode) => <PublicSiteLayout>{page}</PublicSiteLayout>;
