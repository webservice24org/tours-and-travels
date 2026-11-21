import { Head, Link } from '@inertiajs/react';

import CustomerTravelLayout from '@/layouts/customer-travel-layout';
import { bookingLabel, bookingMoney, bookingWhen, type CustomerBooking } from '@/lib/bablys-customer-bookings';

type Props = {
    bookings: CustomerBooking[];
    pagination: { current_page: number; last_page: number; total: number };
};

export default function CustomerBookings({ bookings, pagination }: Props) {
    return <CustomerTravelLayout>
        <Head title="My bookings | Bablys Tours & Travels" />
        <header className="mb-6">
            <h2 className="text-2xl font-bold">My bookings</h2>
            <p className="mt-2 text-sm text-slate-600">Only bookings linked to requests submitted from this verified customer account are displayed. Staff-created and guest-only bookings are not automatically claimed by email.</p>
        </header>
        {bookings.length === 0 ? <div className="rounded-xl border border-slate-200 bg-white p-6 text-slate-600">You don't have linked bookings yet. <Link href="/account/booking-requests" className="font-semibold text-emerald-800 underline">Review your requests</Link>.</div> :
            <div className="grid gap-4">{bookings.map((row) => <article key={row.reference} className="min-w-0 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h3 className="text-lg font-bold text-slate-900">{row.tour_title}</h3>
                        <p className="mt-1 break-all text-sm text-slate-600">Booking: {row.reference}</p>
                    </div>
                    <span className="rounded-md bg-emerald-50 px-3 py-1 text-sm font-semibold capitalize text-emerald-900">{bookingLabel(row.status)}</span>
                </div>
                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt className="text-slate-500">Departure</dt><dd className="font-semibold">{bookingWhen(row.departure_at_utc)}</dd></div>
                    <div><dt className="text-slate-500">Travelers</dt><dd className="font-semibold">{row.adults} adult(s), {row.children} child(ren)</dd></div>
                    <div><dt className="text-slate-500">Recorded total</dt><dd className="font-semibold">{bookingMoney(row.total_minor, row.currency, row.fraction_digits)}</dd></div>
                </dl>
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <span className="text-sm text-slate-600">Payment: {bookingLabel(row.payment_state)}</span>
                    <Link href={`/account/bookings/${encodeURIComponent(row.reference)}`} className="inline-flex min-h-11 items-center rounded-lg bg-emerald-800 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">View booking</Link>
                </div>
            </article>)}</div>}
        {pagination.last_page > 1 && <nav aria-label="Booking history pages" className="mt-6 flex flex-wrap items-center justify-between gap-3">
            <span className="text-sm text-slate-600">Page {pagination.current_page} of {pagination.last_page} · {pagination.total} bookings</span>
            <div className="flex gap-2">
                {pagination.current_page > 1 && <Link href={`/account/bookings?page=${pagination.current_page - 1}`} className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 py-2 font-semibold">Previous</Link>}
                {pagination.current_page < pagination.last_page && <Link href={`/account/bookings?page=${pagination.current_page + 1}`} className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 py-2 font-semibold">Next</Link>}
            </div>
        </nav>}
    </CustomerTravelLayout>;
}
