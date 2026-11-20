import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import { bookingPost } from './http';

type Props = {
    tour: { id: number; title: string; slug: string };
    departures: { id: number; name: string; starts_at: string; timezone: string }[];
    variants: { code: string; name: string; currency: string }[];
    customer: { name: string; email: string; email_verified: boolean } | null;
    nonbinding: true; submission_id: string;
};
type QuoteResponse = { nonbinding: true; inventory_reserved: false; quote: {
    currency: string; fraction_digits: number; total_minor: number;
    regular_total_minor: number; discount_minor: number;
}};
const field = 'mt-1 block w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900 focus:border-teal-600 focus:ring-2 focus:ring-teal-200';
const label = 'block text-sm font-semibold text-slate-700';
const button = 'rounded-lg bg-teal-800 px-5 py-3 font-semibold text-white hover:bg-teal-900 disabled:cursor-not-allowed disabled:opacity-50';
function priceString(quote: QuoteResponse['quote']): string {
    const fraction = Math.max(0, Math.min(3, quote.fraction_digits));
    return `${quote.currency} ${(quote.total_minor / (10 ** fraction)).toLocaleString(undefined, { minimumFractionDigits: fraction, maximumFractionDigits: fraction })}`;
}

export default function CreateBookingRequest({ tour, departures, variants, customer, submission_id }: Props) {
    const form = useForm({
        tour_id: tour.id,
        departure_id: departures[0]?.id ?? 0,
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
    const [quoteError, setQuoteError] = useState('');
    const [quoteBusy, setQuoteBusy] = useState(false);
    const disabled = !departures.length || !variants.length || (!!customer && !customer.email_verified);

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
        if (disabled) return;
        form.post('/booking-requests', { preserveScroll: true });
    }

    return <main className="min-h-screen bg-slate-50 text-slate-900">
        <Head title={`Request ${tour.title} | Bablys Tours & Travels`} />
        <div className="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6 sm:py-12">
            <Link href="/tours" className="text-sm font-semibold text-teal-800 hover:underline">All tours</Link>
            <header className="mt-4">
                <p className="text-sm font-bold uppercase tracking-wide text-teal-800">Bablys Tours &amp; Travels</p>
                <h1 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Request a place on {tour.title}</h1>
                <p className="mt-3 text-slate-600">This is a request only. No seats are reserved, no booking is confirmed, and no payment is collected. Staff will check availability and the current price before confirming any booking.</p>
            </header>
            <form onSubmit={submit} className="mt-7 space-y-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
                {disabled && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                    {customer && !customer.email_verified ? 'Verify your account email to make an account booking request.' : 'No currently requestable departure or pricing option is available.'}
                </p>}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2"><label className={label} htmlFor="departure">Departure</label>
                        <select id="departure" className={field} value={form.data.departure_id} onChange={(e) => { form.setData('departure_id', Number(e.target.value)); setQuote(null); }} disabled={disabled} required>
                            {departures.map((d) => <option key={d.id} value={d.id}>{d.name} · {d.starts_at} UTC ({d.timezone})</option>)}
                        </select>{form.errors.departure_id && <p className="mt-1 text-sm text-red-700">{form.errors.departure_id}</p>}
                    </div>
                    <div className="sm:col-span-2"><label className={label} htmlFor="option">Pricing option</label>
                        <select id="option" className={field} value={form.data.variant_code} onChange={(e) => { form.setData('variant_code', e.target.value); setQuote(null); }} disabled={disabled} required>
                            {variants.map((v) => <option key={v.code} value={v.code}>{v.name} ({v.currency})</option>)}
                        </select>{form.errors.variant_code && <p className="mt-1 text-sm text-red-700">{form.errors.variant_code}</p>}
                    </div>
                    <div><label className={label} htmlFor="adults">Adults</label>
                        <input id="adults" className={field} type="number" min="1" max="30" value={form.data.adults} onChange={(e) => { form.setData('adults', Number(e.target.value)); setQuote(null); }} required />
                        {form.errors.adults && <p className="mt-1 text-sm text-red-700">{form.errors.adults}</p>}
                    </div>
                    <div><label className={label} htmlFor="children">Children</label>
                        <input id="children" className={field} type="number" min="0" max="30" value={form.data.children} onChange={(e) => { form.setData('children', Number(e.target.value)); setQuote(null); }} required />
                        {form.errors.children && <p className="mt-1 text-sm text-red-700">{form.errors.children}</p>}
                    </div>
                </div>
                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div className="flex flex-wrap items-center gap-3">
                        <button className="rounded-lg border border-teal-700 px-4 py-2 font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50" type="button" disabled={disabled || quoteBusy} onClick={() => void preview()}>{quoteBusy ? 'Calculating…' : 'Preview current price'}</button>
                        {quote && <strong className="text-lg text-teal-900">{priceString(quote)}</strong>}
                    </div>
                    {quoteError && <p role="alert" className="mt-2 text-sm text-red-700">{quoteError}</p>}
                    <p className="mt-2 text-sm text-slate-600">Price is a nonbinding server-calculated preview; inventory and price may change before staff review.</p>
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label className={label} htmlFor="contact_name">Contact name</label>
                        <input id="contact_name" className={field} autoComplete="name" maxLength={120} value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} readOnly={!!customer} required={!customer} />
                        {form.errors.contact_name && <p className="mt-1 text-sm text-red-700">{form.errors.contact_name}</p>}
                    </div>
                    <div><label className={label} htmlFor="contact_email">Email address</label>
                        <input id="contact_email" className={field} type="email" autoComplete="email" maxLength={254} value={form.data.contact_email} onChange={(e) => form.setData('contact_email', e.target.value)} readOnly={!!customer} required={!customer} />
                        {form.errors.contact_email && <p className="mt-1 text-sm text-red-700">{form.errors.contact_email}</p>}
                    </div>
                    <div className="sm:col-span-2"><label className={label} htmlFor="contact_phone">Phone (optional)</label>
                        <input id="contact_phone" className={field} type="tel" autoComplete="tel" maxLength={25} value={form.data.contact_phone} onChange={(e) => form.setData('contact_phone', e.target.value)} />
                        {form.errors.contact_phone && <p className="mt-1 text-sm text-red-700">{form.errors.contact_phone}</p>}
                    </div>
                </div>
                <label className="flex items-start gap-3 text-sm leading-6 text-slate-700">
                    <input type="checkbox" className="mt-1 h-4 w-4 accent-teal-800" checked={form.data.consent} onChange={(e) => form.setData('consent', e.target.checked)} />
                    <span>I consent to Bablys Tours &amp; Travels using these contact details to process and respond to my booking request. I understand this does not reserve a seat or authorize payment.</span>
                </label>
                {form.errors.consent && <p role="alert" className="text-sm text-red-700">{form.errors.consent}</p>}
                <button type="submit" className={button} disabled={disabled || form.processing || !form.data.consent}>
                    {form.processing ? 'Sending request…' : 'Send booking request'}
                </button>
                {Object.entries(form.errors).filter(([key]) => !['departure_id', 'variant_code', 'adults', 'children', 'contact_name', 'contact_email', 'contact_phone', 'consent'].includes(key)).map(([key, error]) =>
                    <p key={key} role="alert" className="text-sm text-red-700">{error}</p>)}
                <p className="text-xs text-slate-500">Guest customers receive separate email verification and private management links. Signed-in customers must have verified their account email.</p>
            </form>
        </div>
    </main>;
}
