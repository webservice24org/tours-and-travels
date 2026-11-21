import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BablysAdminLayout from '@/layouts/bablys-admin-layout';

type BookingStatus = 'pending_confirmation' | 'confirmed' | 'cancelled';
type BookingRow = {
    reference: string;
    status: BookingStatus;
    payment_state: string;
    tour_title: string;
    departure_at_utc: string | null;
    adults: number;
    children: number;
    seats: number;
    currency: string;
    fraction_digits: number;
    total_minor: number;
    created_at: string | null;
};
type Metrics = {
    total_bookings: number;
    confirmed_bookings: number;
    pending_bookings: number;
    cancelled_bookings: number;
    confirmed_seats: number;
    pending_seats: number;
};
type Filters = { q: string; status: string; payment_state: string };
type Pagination = { current_page: number; last_page: number; per_page: number; total: number };

type Props = {
    bookings: BookingRow[];
    filters: Filters;
    pagination: Pagination;
    metrics: Metrics;
    read_only: true;
};

const inputClass = 'block w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700';
const buttonClass = 'inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-800 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700';

function money(minor: number, currency: string, fractionDigits: number): string {
    const digits = Math.min(3, Math.max(0, fractionDigits));
    try {
        return new Intl.NumberFormat('en-BD', {
            style: 'currency', currency, minimumFractionDigits: digits, maximumFractionDigits: digits,
        }).format(minor / (10 ** digits));
    } catch {
        return `${currency} ${(minor / (10 ** digits)).toFixed(digits)}`;
    }
}
function dateTime(value: string | null): string {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}
function statusStyle(status: string): string {
    if (status === 'confirmed') return 'bg-emerald-50 text-emerald-800 ring-emerald-200';
    if (status === 'cancelled') return 'bg-rose-50 text-rose-800 ring-rose-200';
    return 'bg-amber-50 text-amber-800 ring-amber-200';
}
function pageUrl(filters: Filters, page: number): string {
    const params = new URLSearchParams();
    if (filters.q) params.set('q', filters.q);
    if (filters.status) params.set('status', filters.status);
    if (filters.payment_state) params.set('payment_state', filters.payment_state);
    if (page > 1) params.set('page', String(page));
    const qs = params.toString();
    return qs ? `/admin/bookings?${qs}` : '/admin/bookings';
}

export default function AdminBookingsIndex({ bookings, filters, pagination, metrics }: Props) {
    const [draft, setDraft] = useState<Filters>({ ...filters });
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(pageUrl(draft, 1), {}, { preserveState: true, preserveScroll: true });
    }

    return <BablysAdminLayout>
        <Head title="Bookings | Bablys Tours & Travels" />
        <main className="mx-auto w-full max-w-7xl space-y-6 px-4 py-8 text-slate-900 sm:px-6" id="booking-admin-content">
            <header className="space-y-2">
                <p className="text-sm font-semibold text-teal-800">Travel operations · Authorized staff</p>
                <h1 className="text-3xl font-bold tracking-tight">Booking management</h1>
                <p className="max-w-3xl text-sm text-slate-600">Review real booking records and historical server-calculated quotes. Open an individual record to confirm or cancel it through the authorized, audited lifecycle.</p>
                <nav className="flex flex-wrap gap-4 text-sm font-semibold" aria-label="Booking administration">
                    <Link href="/admin/booking-requests" className="text-teal-800 hover:underline">Review booking requests</Link>
                    <Link href="/admin/tours" className="text-teal-800 hover:underline">Manage tours</Link>
                </nav>
            </header>

            <section aria-labelledby="booking-metrics" className="space-y-3">
                <h2 id="booking-metrics" className="text-lg font-bold">Booking overview</h2>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
                    {[
                        ['Bookings', metrics.total_bookings],
                        ['Confirmed', metrics.confirmed_bookings],
                        ['Pending confirmation', metrics.pending_bookings],
                        ['Cancelled', metrics.cancelled_bookings],
                        ['Confirmed traveler seats', metrics.confirmed_seats],
                        ['Pending traveler seats', metrics.pending_seats],
                    ].map(([label, value]) => <div key={label} className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium text-slate-600">{label}</p>
                        <p className="mt-2 text-2xl font-bold tabular-nums">{value}</p>
                    </div>)}
                </div>
                <p className="text-xs text-slate-600">Pending and confirmed bookings both hold inventory. These totals do not include any standalone seat allocations; authoritative departure availability is managed separately.</p>
            </section>

            <form onSubmit={submit} className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-4" aria-label="Filter bookings">
                <label className="block space-y-1 text-sm font-semibold">Reference or tour
                    <input className={inputClass} type="search" maxLength={80} value={draft.q} placeholder="Search bookings" onChange={(e) => setDraft({ ...draft, q: e.target.value })} />
                </label>
                <label className="block space-y-1 text-sm font-semibold">Booking status
                    <select className={inputClass} value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                        <option value="">All statuses</option><option value="pending_confirmation">Pending confirmation</option>
                        <option value="confirmed">Confirmed</option><option value="cancelled">Cancelled</option>
                    </select>
                </label>
                <label className="block space-y-1 text-sm font-semibold">Payment status
                    <select className={inputClass} value={draft.payment_state} onChange={(e) => setDraft({ ...draft, payment_state: e.target.value })}>
                        <option value="">All payment states</option><option value="not_collected">Not collected</option><option value="paid">Paid (offline verified)</option>
                    </select>
                </label>
                <div className="flex items-end gap-2">
                    <button className={buttonClass} type="submit">Apply filters</button>
                    <Link href="/admin/bookings" className="inline-flex min-h-11 items-center px-3 text-sm font-semibold text-teal-800 hover:underline">Reset</Link>
                </div>
            </form>

            <section aria-labelledby="booking-records-title" className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                    <h2 id="booking-records-title" className="font-bold">Booking records</h2>
                    <span className="text-sm text-slate-600">{pagination.total} matching · page {pagination.current_page} of {pagination.last_page}</span>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[850px] divide-y divide-slate-200 text-left text-sm">
                        <thead className="bg-slate-50 text-slate-700"><tr>{['Reference','Tour and departure','Travelers','Booking status','Payment','Total','Details'].map((name) => <th key={name} scope="col" className="px-4 py-3 font-semibold">{name}</th>)}</tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {bookings.map((row) => <tr key={row.reference} className="align-top">
                                <td className="px-4 py-3 font-mono text-xs break-all">{row.reference}</td>
                                <td className="px-4 py-3"><span className="font-medium">{row.tour_title}</span><span className="block text-xs text-slate-500">{dateTime(row.departure_at_utc)}</span></td>
                                <td className="px-4 py-3 tabular-nums">{row.seats} seats<span className="block text-xs text-slate-500">{row.adults} adults · {row.children} children</span></td>
                                <td className="px-4 py-3"><span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ring-1 ${statusStyle(row.status)}`}>{row.status.replaceAll('_', ' ')}</span></td>
                                <td className="px-4 py-3">{row.payment_state.replaceAll('_', ' ')}</td>
                                <td className="px-4 py-3 font-medium tabular-nums">{money(row.total_minor, row.currency, row.fraction_digits)}</td>
                                <td className="px-4 py-3"><Link className="font-semibold text-teal-800 underline underline-offset-2 focus-visible:outline focus-visible:outline-2" href={`/admin/bookings/${encodeURIComponent(row.reference)}`}>View details</Link></td>
                            </tr>)}
                        </tbody>
                    </table>
                </div>
                {bookings.length === 0 && <p className="p-6 text-sm text-slate-600" role="status">No bookings match these filters.</p>}
                <nav className="flex items-center justify-between gap-4 border-t border-slate-200 px-4 py-3 text-sm" aria-label="Booking pages">
                    {pagination.current_page > 1 ? <Link href={pageUrl(filters, pagination.current_page - 1)} className="font-semibold text-teal-800 underline">Previous</Link> : <span className="text-slate-400">Previous</span>}
                    <span aria-current="page">Page {pagination.current_page} / {pagination.last_page}</span>
                    {pagination.current_page < pagination.last_page ? <Link href={pageUrl(filters, pagination.current_page + 1)} className="font-semibold text-teal-800 underline">Next</Link> : <span className="text-slate-400">Next</span>}
                </nav>
            </section>
        </main>
    </BablysAdminLayout>;
}
