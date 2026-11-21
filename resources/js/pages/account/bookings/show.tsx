import { Head, Link } from '@inertiajs/react';

import CustomerTravelLayout from '@/layouts/customer-travel-layout';
import { bookingLabel, bookingMoney, bookingWhen, type CustomerBooking } from '@/lib/bablys-customer-bookings';

type CustomerBookingDetail = CustomerBooking & {
    regular_total_minor: number;
    discount_minor: number;
    variant_code: string;
    departure_timezone: string;
    confirmed_at_utc: string | null;
    cancelled_at_utc: string | null;
    quoted_at_utc: string | null;
};
type Props = { booking: CustomerBookingDetail; request_reference: string };
function Field({ name, value }: { name: string; value: string | number }) {
    return <div className="min-w-0"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{name}</dt><dd className="mt-1 break-words text-sm font-semibold text-slate-800">{value}</dd></div>;
}

export default function CustomerBookingDetails({ booking, request_reference }: Props) {
    const money = (minor: number) => bookingMoney(minor, booking.currency, booking.fraction_digits);
    return <CustomerTravelLayout>
        <Head title={`Booking ${booking.reference} | Bablys Tours & Travels`} />
        <div className="mb-5"><Link href="/account/bookings" className="text-sm font-semibold text-emerald-800 underline">Back to my bookings</Link></div>
        <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div><h2 className="text-2xl font-bold">{booking.tour_title}</h2><p className="mt-2 break-all text-sm text-slate-600">Booking reference: {booking.reference}</p></div>
                <span className="rounded-md bg-emerald-50 px-3 py-1 text-sm font-semibold capitalize text-emerald-900">{bookingLabel(booking.status)}</span>
            </div>
            <dl className="mt-6 grid gap-5 border-t border-slate-200 pt-5 sm:grid-cols-2 lg:grid-cols-3">
                <Field name="Status" value={bookingLabel(booking.status)} />
                <Field name="Payment" value={bookingLabel(booking.payment_state)} />
                <Field name="Recorded total" value={money(booking.total_minor)} />
                <Field name="Departure" value={bookingWhen(booking.departure_at_utc)} />
                <Field name="Departure timezone" value={booking.departure_timezone} />
                <Field name="Seats" value={booking.seats} />
                <Field name="Adults" value={booking.adults} />
                <Field name="Children" value={booking.children} />
                <Field name="Pricing option" value={booking.variant_code} />
                <Field name="Regular total" value={money(booking.regular_total_minor)} />
                <Field name="Discount" value={money(booking.discount_minor)} />
                <Field name="Quoted" value={bookingWhen(booking.quoted_at_utc)} />
                <Field name="Confirmed" value={bookingWhen(booking.confirmed_at_utc)} />
                <Field name="Cancelled" value={bookingWhen(booking.cancelled_at_utc)} />
                <Field name="Created" value={bookingWhen(booking.created_at_utc)} />
            </dl>
            <div className="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                These are historical booking details, not a new price quote or payment receipt. Payment status is informational; no payment is collected by this page.
            </div>
            <p className="mt-5 text-sm text-slate-600">Linked request: <span className="break-all font-mono">{request_reference}</span>. <Link href="/account/booking-requests" className="font-semibold text-emerald-800 underline">View my requests</Link>.</p>
        </article>
    </CustomerTravelLayout>;
}
