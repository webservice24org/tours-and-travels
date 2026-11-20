import type { ReactNode } from 'react';
import PublicSiteLayout from '../../../layouts/public-site-layout';

import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Only fragments contain secrets; the server never receives them on GET. */
export default function VerifyBookingRequest() {
    const form = useForm({ reference: '', token: '' });
    const [missing, setMissing] = useState(false);
    useEffect(() => {
        const values = new URLSearchParams(window.location.hash.slice(1));
        const reference = values.get('reference') ?? '';
        const token = values.get('token') ?? '';
        window.history.replaceState(null, '', window.location.pathname);
        if (!/^BRQ-[a-f0-9]{32}$/.test(reference) || !/^[a-f0-9]{64}$/.test(token)) { setMissing(true); return; }
        form.setData({ reference, token });
        // A one-time proof must require a user gesture; mail/link crawlers cannot verify using GET.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return <main className="min-h-screen bg-slate-50 px-4 py-16 text-slate-900">
        <Head title="Verify booking request | Bablys Tours & Travels" />
        <section className="mx-auto max-w-lg rounded-2xl bg-white p-6 shadow-sm sm:p-9">
            <h1 className="text-2xl font-bold">Verify your email address</h1>
            <p className="mt-3 text-slate-600">Verifying email ownership does not confirm a booking or reserve any places.</p>
            {missing ? <p role="alert" className="mt-5 text-red-700">This private link is missing or incomplete. Open the link directly from your email.</p>
                : <form className="mt-6" onSubmit={(event) => { event.preventDefault(); form.post('/booking-requests/verify'); }}>
                    <button type="submit" disabled={form.processing || !form.data.token} className="rounded-lg bg-teal-800 px-5 py-3 font-semibold text-white disabled:opacity-50">{form.processing ? 'Verifying…' : 'Verify email'}</button>
                    {Object.entries(form.errors).map(([key, error]) => <p role="alert" className="mt-3 text-sm text-red-700" key={key}>{error}</p>)}
                </form>}
        </section>
    </main>;
}

// Public enquiry/customer request pages never inherit the CMS/dashboard sidebar.
VerifyBookingRequest.layout = (page: ReactNode) => <PublicSiteLayout>{page}</PublicSiteLayout>;
