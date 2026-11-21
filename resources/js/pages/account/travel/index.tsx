import { Head, Link } from '@inertiajs/react';

import CustomerTravelLayout from '@/layouts/customer-travel-layout';
import { bookingLabel, bookingMoney, bookingWhen, type CustomerBooking } from '@/lib/bablys-customer-bookings';
import { type BookingRequestView } from '../../public/booking-requests/http';

type Props = {
    summary: { requests: number; bookings: number; confirmed: number; pending: number };
    recent_requests: BookingRequestView[];
    recent_bookings: CustomerBooking[];
};

export default function CustomerTravelOverview({ summary, recent_requests, recent_bookings }: Props) {
    return <CustomerTravelLayout>
        <Head title="My travel account | Bablys Tours & Travels" />
        <section aria-label="My travel overview" className="grid grid-cols-2 gap-3 md:grid-cols-4">
            {[
                ['Requests', summary.requests], ['Bookings', summary.bookings],
                ['Confirmed', summary.confirmed], ['Awaiting staff confirmation', summary.pending],
            ].map(([label, value]) => <div key={String(label)} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="text-2xl font-bold tabular-nums text-emerald-900">{value}</div>
                <div className="mt-1 text-sm text-slate-600">{label}</div>
            </div>)}
        </section>
        <div className="mt-7 grid gap-6 lg:grid-cols-2">
            <section className="min-w-0 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="recent-customer-bookings">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="recent-customer-bookings" className="text-xl font-bold">Recent bookings</h2>
                    <Link href="/account/bookings" className="text-sm font-semibold text-emerald-800 underline">View all</Link>
                </div>
                {recent_bookings.length === 0 ? <p className="mt-4 text-sm text-slate-600">No linked bookings yet. Approved requests appear here after authorized staff conversion.</p> :
                    <ul className="mt-4 space-y-3">{recent_bookings.map((booking) => <li key={booking.reference} className="rounded-lg border border-slate-100 bg-slate-50 p-3">
                        <Link href={`/account/bookings/${encodeURIComponent(booking.reference)}`} className="font-semibold text-emerald-800 underline">{booking.tour_title}</Link>
                        <p className="mt-1 text-sm text-slate-600">{bookingLabel(booking.status)} · {bookingWhen(booking.departure_at_utc)}</p>
                        <p className="mt-1 text-sm text-slate-700">{bookingMoney(booking.total_minor, booking.currency, booking.fraction_digits)} · {booking.seats} seat(s)</p>
                    </li>)}</ul>}
            </section>
            <section className="min-w-0 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="recent-customer-requests">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="recent-customer-requests" className="text-xl font-bold">Recent requests</h2>
                    <Link href="/account/booking-requests" className="text-sm font-semibold text-emerald-800 underline">Review quotes &amp; requests</Link>
                </div>
                {recent_requests.length === 0 ? <p className="mt-4 text-sm text-slate-600">No booking requests yet.</p> :
                    <ul className="mt-4 space-y-3">{recent_requests.map((row) => <li key={row.reference} className="rounded-lg border border-slate-100 bg-slate-50 p-3">
                        <p className="font-semibold text-slate-900">{row.tour_title}</p>
                        <p className="mt-1 text-sm text-slate-600">{bookingLabel(row.status)} · {row.departure_name}</p>
                        <p className="mt-1 text-xs text-slate-600">Request ref: {row.reference}</p>
                    </li>)}</ul>}
            </section>
        </div>
        <p className="mt-6 text-sm text-slate-600">Requests are non-binding until staff converts an approved quote. A pending booking may already commit seats; confirmation does not collect payment. Guest-only requests remain accessible through their original private email links.</p>
    </CustomerTravelLayout>;
}
