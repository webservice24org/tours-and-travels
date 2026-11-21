import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import BablysAdminLayout from '@/layouts/bablys-admin-layout';

type PriceLine = {
    traveler_type: string; quantity: number; regular_unit_minor: number;
    unit_minor: number; line_total_minor: number;
};
type BookingDetail = {
    reference: string;
    status: string;
    payment_state: string;
    tour_title: string;
    departure_at_utc: string | null;
    departure_code_snapshot: string;
    departure_timezone: string;
    adults: number; children: number; seats: number;
    currency: string; fraction_digits: number;
    total_minor: number; regular_total_minor: number; discount_minor: number;
    variant_code: string;
    created_at: string | null;
    quoted_at_utc: string | null;
    confirmed_at: string | null;
    cancelled_at: string | null;
    confirmation_due_at_utc: string | null;
    timeout_review_required_at_utc: string | null;
    price_lines: PriceLine[];
};
type Contact = { reference: string; name: string; email: string; phone: string | null };
type Inventory = { departure_name: string; capacity_total: number; seats_committed: number; remaining: number };

type Props = { booking: BookingDetail; request_contact: Contact | null; inventory: Inventory | null; read_only: true };

function money(minor: number, currency: string, digits: number) {
    const decimals = Math.max(0, Math.min(3, digits));
    try {
        return new Intl.NumberFormat('en-BD', { style: 'currency', currency, minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(minor / 10 ** decimals);
    } catch {
        return `${currency} ${(minor / 10 ** decimals).toFixed(decimals)}`;
    }
}
function dateTime(date: string | null) {
    if (!date) return '—';
    const parsed = new Date(date.endsWith('Z') || date.includes('+') ? date : `${date.replace(' ', 'T')}Z`);
    return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleString();
}
function Field({ label, value }: { label: string; value: string | number }) {
    return <div className="min-w-0"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt><dd className="mt-1 break-words text-sm font-medium text-slate-900">{value}</dd></div>;
}

export default function AdminBookingShow({ booking, request_contact, inventory }: Props) {
    const [action, setAction] = useState<'confirm' | 'cancel' | null>(null);
    const [typedReference, setTypedReference] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    function submitAction() {
        if (!action || busy) return;
        if (action === 'cancel' && typedReference !== booking.reference) return;
        setError('');
        router.post(`/admin/bookings/${encodeURIComponent(booking.reference)}/${action}`, {}, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => { setAction(null); setTypedReference(''); },
            onError: (issues) => setError(Object.values(issues).join(' ') || 'The booking action was rejected.'),
        });
    }

    return <BablysAdminLayout>
        <Head title={`Booking ${booking.reference} | Bablys`} />
        <main className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8 text-slate-900 sm:px-6">
            <header>
                <Link href="/admin/bookings" className="text-sm font-semibold text-teal-800 underline underline-offset-2">Back to bookings</Link>
                <h1 className="mt-3 break-all text-3xl font-bold">Booking {booking.reference}</h1>
                <p className="mt-2 text-sm text-slate-600">Restricted booking operations. Confirmations and cancellations are audited; payment collection and refunds are separate processes.</p>
            </header>
            <section className="space-y-4 rounded-xl border border-teal-200 bg-white p-5 shadow-sm" aria-labelledby="booking-actions">
                <h2 id="booking-actions" className="text-xl font-bold">Manage booking</h2>
                <p className="text-sm text-slate-600">This record already holds {booking.seats} traveler seat(s) unless cancelled. Confirming does not allocate seats again or collect payment.</p>
                <div className="flex flex-wrap gap-3">
                    {booking.status === 'pending_confirmation' && <button type="button" disabled={busy} onClick={() => { setAction('confirm'); setError(''); }} className="min-h-11 rounded-lg bg-teal-800 px-4 py-2 font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50">Review confirmation</button>}
                    {booking.status !== 'cancelled' && booking.payment_state === 'not_collected' && <button type="button" disabled={busy} onClick={() => { setAction('cancel'); setTypedReference(''); setError(''); }} className="min-h-11 rounded-lg border border-rose-300 px-4 py-2 font-semibold text-rose-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50">Review cancellation</button>}
                </div>
                {booking.status !== 'cancelled' && booking.payment_state !== 'not_collected' && <p role="status" className="text-sm font-semibold text-amber-800">Payment has been recorded. Cancellation is blocked pending a separately approved refund policy.</p>}
                {booking.status === 'cancelled' && <p role="status" className="text-sm text-slate-600">This booking is cancelled. Seat allocation has been released and cannot be recommitted.</p>}
                {action && <div className="space-y-3 rounded-lg border border-slate-300 bg-slate-50 p-4" role="group" aria-label="Confirm booking operation">
                    <p className="font-semibold">{action === 'confirm' ? 'Confirm this booking?' : 'Cancel this booking and release its seats?'}</p>
                    <p className="text-sm text-slate-700">{action === 'confirm' ? 'Only its status changes. No additional seats are reserved and no payment is collected.' : 'Cancellation is irreversible. It releases committed inventory once. This action is not a refund.'}</p>
                    {action === 'cancel' && <label className="block max-w-md text-sm font-semibold text-slate-800">Type the complete booking reference to authorize cancellation
                        <input autoComplete="off" className="mt-1 block w-full rounded-lg border border-slate-400 px-3 py-2" value={typedReference} onChange={(event) => setTypedReference(event.target.value)} aria-label="Type booking reference to cancel" />
                    </label>}
                    <div className="flex flex-wrap gap-3">
                        <button type="button" disabled={busy || (action === 'cancel' && typedReference !== booking.reference)} onClick={submitAction} className="min-h-11 rounded-lg bg-teal-800 px-4 py-2 font-semibold text-white disabled:opacity-50">{busy ? 'Processing…' : action === 'confirm' ? 'Confirm booking' : 'Cancel booking and release seats'}</button>
                        <button type="button" disabled={busy} onClick={() => { setAction(null); setError(''); }} className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 font-semibold">Keep booking unchanged</button>
                    </div>
                </div>}
                {error && <p role="alert" className="text-sm font-semibold text-rose-700">{error}</p>}
            </section>
            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="booking-summary">
                <h2 id="booking-summary" className="text-xl font-bold">Booking summary</h2>
                <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label="Tour" value={booking.tour_title} />
                    <Field label="Status" value={booking.status.replaceAll('_', ' ')} />
                    <Field label="Payment status" value={booking.payment_state.replaceAll('_', ' ')} />
                    <Field label="Travelers" value={`${booking.adults} adults · ${booking.children} children · ${booking.seats} seats`} />
                    <Field label="Departure at (your timezone)" value={dateTime(booking.departure_at_utc)} />
                    <Field label="Departure timezone" value={booking.departure_timezone} />
                    <Field label="Saved departure code" value={booking.departure_code_snapshot} />
                    <Field label="Pricing option" value={booking.variant_code} />
                    <Field label="Created" value={dateTime(booking.created_at)} />
                    <Field label="Quote timestamp" value={dateTime(booking.quoted_at_utc)} />
                    <Field label="Confirmed" value={dateTime(booking.confirmed_at)} />
                    <Field label="Cancelled" value={dateTime(booking.cancelled_at)} />
                    <Field label="Confirmation due (UTC)" value={booking.confirmation_due_at_utc ?? '—'} />
                    <Field label="Timeout review required (UTC)" value={booking.timeout_review_required_at_utc ?? '—'} />
                </dl>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="booking-prices">
                <h2 id="booking-prices" className="text-xl font-bold">Immutable price snapshot</h2>
                <dl className="mt-4 grid gap-4 sm:grid-cols-3">
                    <Field label="Regular total" value={money(booking.regular_total_minor, booking.currency, booking.fraction_digits)} />
                    <Field label="Discount" value={money(booking.discount_minor, booking.currency, booking.fraction_digits)} />
                    <Field label="Recorded total" value={money(booking.total_minor, booking.currency, booking.fraction_digits)} />
                </dl>
                {booking.price_lines.length > 0 && <div className="mt-5 overflow-x-auto">
                    <table className="w-full min-w-[530px] text-left text-sm"><thead><tr className="border-b border-slate-200">{['Traveler','Quantity','Unit price','Line total'].map((h) => <th key={h} scope="col" className="py-2 pr-4">{h}</th>)}</tr></thead>
                        <tbody>{booking.price_lines.map((line, i) => <tr key={`${line.traveler_type}-${i}`} className="border-b border-slate-100">
                            <td className="py-2 pr-4 capitalize">{line.traveler_type}</td><td className="py-2 pr-4">{line.quantity}</td>
                            <td className="py-2 pr-4">{money(line.unit_minor, booking.currency, booking.fraction_digits)}</td>
                            <td className="py-2 pr-4">{money(line.line_total_minor, booking.currency, booking.fraction_digits)}</td>
                        </tr>)}</tbody>
                    </table>
                </div>}
            </section>

            <section className="grid gap-5 lg:grid-cols-2">
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="text-xl font-bold">Current departure inventory</h2>
                    {inventory ? <dl className="mt-4 grid grid-cols-2 gap-4">
                        <Field label="Departure" value={inventory.departure_name} />
                        <Field label="Capacity" value={inventory.capacity_total} />
                        <Field label="Committed seats" value={inventory.seats_committed} />
                        <Field label="Currently remaining" value={inventory.remaining} />
                    </dl> : <p className="mt-4 text-sm text-slate-600">The original departure has been removed. Historical booking snapshots remain available.</p>}
                    <p className="mt-4 text-xs text-slate-600">Capacity is a live snapshot and may include commitments from pending bookings and other allocations. It is not a payment confirmation.</p>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="text-xl font-bold">Linked enquiry</h2>
                    {request_contact ? <dl className="mt-4 space-y-3">
                        <Field label="Request reference" value={request_contact.reference} />
                        <Field label="Contact name" value={request_contact.name} />
                        <Field label="Email" value={request_contact.email} />
                        {request_contact.phone && <Field label="Phone" value={request_contact.phone} />}
                    </dl> : <p className="mt-4 text-sm text-slate-600">This booking was created directly by staff or has no linked request.</p>}
                </div>
            </section>
            <p className="text-xs text-slate-500">Private financial and traveler information. Do not share this staff-only URL or screenshots without authorization.</p>
        </main>
    </BablysAdminLayout>;
}
