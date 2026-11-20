import type { ReactNode } from 'react';
import PublicSiteLayout from '../../../layouts/public-site-layout';

import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { bookingPost, type BookingRequestView, when } from './http';
import QuoteApprovalPanel from '@/components/public/booking-requests/QuoteApprovalPanel';

/** Raw access secret only lives in component state after the URL fragment is removed. */
export default function GuestBookingRequestManage() {
    const [access, setAccess] = useState<{ reference: string; access_token: string } | null>(null);
    const [data, setData] = useState<BookingRequestView | null>(null);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [invalid, setInvalid] = useState(false);

    useEffect(() => {
        const params = new URLSearchParams(window.location.hash.slice(1));
        const reference = params.get('reference') ?? '';
        const access_token = params.get('access') ?? '';
        window.history.replaceState(null, '', window.location.pathname);
        if (!/^BRQ-[a-f0-9]{32}$/.test(reference) || !/^[a-f0-9]{64}$/.test(access_token)) {
            setInvalid(true); return;
        }
        setAccess({ reference, access_token });
    }, []);

    async function load() {
        if (!access) return;
        setBusy(true); setNotice('');
        try {
            const response = await bookingPost<{ data: BookingRequestView }>('/booking-requests/guest/status', access);
            setData(response.data);
        } catch (error) { setData(null); setNotice(error instanceof Error ? error.message : 'Unable to load request.'); }
        finally { setBusy(false); }
    }

    async function withdraw() {
        if (!access || !data || !data.can_withdraw || !window.confirm('Withdraw this booking request? This cannot cancel any separately confirmed booking.')) return;
        setBusy(true); setNotice('');
        try {
            const response = await bookingPost<{ message: string }>('/booking-requests/guest/withdraw', access);
            setNotice(response.message); setData(null); setAccess(null);
        } catch (error) { setNotice(error instanceof Error ? error.message : 'Unable to withdraw request.'); }
        finally { setBusy(false); }
    }

    return <main className="min-h-screen bg-slate-50 px-4 py-10 text-slate-900 sm:py-16">
        <Head title="Manage booking request | Bablys Tours & Travels" />
        <div className="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-9">
            <p className="text-sm font-bold text-teal-800">Bablys Tours &amp; Travels</p>
            <h1 className="mt-2 text-2xl font-bold">Manage guest booking request</h1>
            <p className="mt-3 text-sm text-slate-600">Status is available after email verification. Your private link must never be shared or pasted into a support chat.</p>
            {invalid && <p role="alert" className="mt-4 text-sm text-red-700">The private email link is missing or invalid.</p>}
            {access && <button type="button" disabled={busy} onClick={() => void load()} className="mt-5 rounded-lg bg-teal-800 px-4 py-2.5 font-semibold text-white disabled:opacity-50">{busy ? 'Loading…' : 'View my request status'}</button>}
            {notice && <p role="status" className="mt-4 rounded-lg bg-slate-100 p-3 text-sm">{notice}</p>}
            {data && <div className="mt-5 space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                <p className="break-all"><strong>Reference:</strong> {data.reference}</p>
                <p><strong>Status:</strong> {data.status.replaceAll('_', ' ')}</p>
                <p><strong>Tour:</strong> {data.tour_title}</p>
                <p><strong>Departure:</strong> {data.departure_name}</p>
                <p><strong>Start:</strong> {when(data.departure_starts_at_utc)}</p>
                <p><strong>Travelers:</strong> {data.adults} adult(s), {data.children} child(ren)</p>
                <p className="text-amber-900">This request does not reserve seats or guarantee prices.</p>
                {access && data.status === 'verified' && <QuoteApprovalPanel reference={data.reference} accessToken={access.access_token} />}
                {data.can_withdraw && <button type="button" disabled={busy} onClick={() => void withdraw()} className="mt-3 rounded-lg border border-red-600 px-4 py-2 font-semibold text-red-800 disabled:opacity-50">Withdraw request</button>}
            </div>}
        </div>
    </main>;
}

// Public enquiry/customer request pages never inherit the CMS/dashboard sidebar.
GuestBookingRequestManage.layout = (page: ReactNode) => <PublicSiteLayout>{page}</PublicSiteLayout>;
