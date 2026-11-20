import { Link } from '@inertiajs/react';

/** Safe reusable CTA for an approved tour detail section or dynamic builder block. */
export default function BookingRequestCta({ slug, title }: { slug: string; title: string }) {
    return <div className="min-w-0 rounded-xl border border-teal-200 bg-teal-50 p-4 sm:p-5">
        <p className="text-sm text-teal-900">Want to travel on this tour? Send a request. No payment is collected and no seats are reserved yet.</p>
        <Link href={`/tours/${encodeURIComponent(slug)}/booking-request`} className="mt-3 inline-flex max-w-full items-center justify-center rounded-lg bg-teal-800 px-5 py-3 text-center text-sm font-semibold text-white hover:bg-teal-900">
            Request a booking for {title}
        </Link>
    </div>;
}
