import { Head, Link } from '@inertiajs/react';

export default function BookingRequestSubmitted({ reference, guest }: { reference: string; guest: boolean }) {
    return <main className="min-h-screen bg-slate-50 px-4 py-16 text-slate-900">
        <Head title="Booking request received | Bablys Tours & Travels" />
        <section className="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
            <p className="text-sm font-bold uppercase tracking-wide text-teal-800">Bablys Tours &amp; Travels</p>
            <h1 className="mt-3 text-2xl font-bold">Booking request update</h1>
            <p className="mt-4">{guest ? 'If your email address can receive messages, check your inbox for verification and private access links. Check spam folders too.' : 'Your account booking request was received. You can review it in your account.'}</p>
            {reference && <p className="mt-4 break-all text-sm text-slate-700">Reference: <strong>{reference}</strong></p>}
            <p className="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">This is not a confirmed booking. No seats are held and no payment has been taken.</p>
            <div className="mt-6 flex flex-wrap gap-4 text-sm font-semibold text-teal-800">
                <Link href="/tours" className="hover:underline">Browse tours</Link>
                {!guest && <Link href="/account/booking-requests" className="hover:underline">My requests</Link>}
            </div>
        </section>
    </main>;
}
