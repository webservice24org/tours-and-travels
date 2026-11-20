import type { ReactNode } from 'react';
import PublicSiteLayout from '../../../layouts/public-site-layout';

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { type BookingRequestView, when } from '../../public/booking-requests/http';
import QuoteApprovalPanel from '@/components/public/booking-requests/QuoteApprovalPanel';

export default function CustomerBookingRequests({ requests }: { requests: BookingRequestView[]; nonbinding: true }) {
    const [busy, setBusy] = useState<string | null>(null);
    function withdraw(row: BookingRequestView) {
        if (!row.can_withdraw || !window.confirm('Withdraw this booking request?')) return;
        setBusy(row.reference);
        router.post(`/account/booking-requests/${encodeURIComponent(row.reference)}/withdraw`, {}, {
            preserveScroll: true, onFinish: () => setBusy(null),
        });
    }
    return <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-900 sm:py-12">
        <Head title="My booking requests | Bablys Tours & Travels" />
        <div className="mx-auto max-w-4xl">
            <Link href="/tours" className="text-sm font-semibold text-teal-800 hover:underline">Browse tours</Link>
            <h1 className="mt-4 text-3xl font-bold">My booking requests</h1>
            <p className="mt-3 text-sm text-slate-600">Booking requests are nonbinding. Staff must recheck current pricing and available seats before converting a request into a booking.</p>
            {requests.length === 0 ? <div className="mt-7 rounded-xl bg-white p-6 text-slate-600">You have not submitted any booking requests.</div>
                : <div className="mt-7 grid gap-4">{requests.map((row) => <article key={row.reference} className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <div className="flex flex-wrap items-start justify-between gap-3"><h2 className="text-lg font-semibold">{row.tour_title}</h2><span className="rounded-md bg-slate-100 px-2.5 py-1 text-sm font-medium">{row.status.replaceAll('_', ' ')}</span></div>
                    <p className="mt-2 break-all text-sm text-slate-600">Reference: {row.reference}</p>
                    <p className="mt-1 text-sm text-slate-700">{row.departure_name} · {when(row.departure_starts_at_utc)}</p>
                    <p className="mt-1 text-sm text-slate-700">{row.adults} adult(s), {row.children} child(ren) · {row.variant_code}</p>
                    {row.status === 'verified' && <QuoteApprovalPanel reference={row.reference} />}
                    {row.can_withdraw && <button type="button" onClick={() => withdraw(row)} disabled={busy !== null} className="mt-4 rounded-lg border border-red-600 px-3 py-2 text-sm font-semibold text-red-800 disabled:opacity-50">{busy === row.reference ? 'Processing…' : 'Withdraw request'}</button>}
                </article>)}</div>}
        </div>
    </main>;
}

// Public enquiry/customer request pages never inherit the CMS/dashboard sidebar.
CustomerBookingRequests.layout = (page: ReactNode) => <PublicSiteLayout>{page}</PublicSiteLayout>;
