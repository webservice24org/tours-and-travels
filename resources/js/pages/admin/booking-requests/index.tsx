import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import BablysAdminLayout from '@/layouts/bablys-admin-layout';
import { type BookingRequestView, when } from '../../public/booking-requests/http';

type StaffRow = BookingRequestView & { name: string; email: string; phone: string | null };

export default function AdminBookingRequests({ requests }: { requests: StaffRow[]; review_only: true }) {
    const [selected, setSelected] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    function convert(reference: string) {
        if (selected !== reference || busy) return;
        setError('');
        router.post(`/admin/booking-requests/${encodeURIComponent(reference)}/convert`, {}, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setSelected(null),
            onError: (issues) => setError(Object.values(issues).join(' ') || 'Conversion was rejected. The current quote may need traveler approval.'),
        });
    }
    return <BablysAdminLayout>
        <Head title="Booking request review | Bablys Tours & Travels" />
        <main className="min-w-0 bg-slate-50 px-4 py-8 text-slate-900 sm:px-6 lg:px-10">
            <div className="mx-auto max-w-6xl">
                <nav className="flex flex-wrap gap-4 text-sm font-semibold" aria-label="Booking administration">
                    <Link href="/admin/bookings" className="text-teal-800 hover:underline">Booking dashboard</Link>
                    <Link href="/admin/tours" className="text-teal-800 hover:underline">Tour management</Link>
                </nav>
                <h1 className="mt-4 text-3xl font-bold">Booking request review</h1>
                <p className="mt-3 text-sm text-slate-600">Verification alone never reserves seats. Conversion requires an active verified request and the traveler’s approval of the current server quote. It commits seats and creates a pending booking; it does not confirm payment.</p>
                {error && <p role="alert" className="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-800">{error}</p>}
                <div className="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table className="w-full min-w-[980px] divide-y divide-slate-200 text-left text-sm">
                        <thead className="bg-slate-50 text-slate-700"><tr>
                            {['Reference / status', 'Tour / departure', 'Travelers', 'Private contact', 'Expiry', 'Action'].map((cell) => <th key={cell} scope="col" className="px-4 py-3 font-semibold">{cell}</th>)}
                        </tr></thead>
                        <tbody className="divide-y divide-slate-100">{requests.map((item) => {
                            const eligible = item.status === 'verified' && !item.has_booking && new Date(item.expires_at_utc).getTime() > Date.now();
                            return <tr key={item.reference} className="align-top">
                                <td className="px-4 py-3"><span className="break-all font-semibold">{item.reference}</span><span className="mt-1 block text-slate-600">{item.status.replaceAll('_', ' ')}</span></td>
                                <td className="px-4 py-3"><span className="font-medium">{item.tour_title}</span><span className="mt-1 block text-slate-600">{item.departure_name}</span></td>
                                <td className="px-4 py-3">{item.adults} adult(s), {item.children} child(ren)<span className="block text-slate-600">{item.variant_code}</span></td>
                                <td className="px-4 py-3"><span className="block font-medium">{item.name}</span><span className="block break-all">{item.email}</span>{item.phone && <span className="block">{item.phone}</span>}</td>
                                <td className="px-4 py-3 text-slate-600">{when(item.expires_at_utc)}</td>
                                <td className="px-4 py-3">
                                    {eligible && selected !== item.reference && <button type="button" disabled={busy} onClick={() => { setSelected(item.reference); setError(''); }} className="min-h-11 rounded-lg border border-teal-700 px-3 py-2 font-semibold text-teal-800 disabled:opacity-50">Review conversion</button>}
                                    {eligible && selected === item.reference && <div className="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                        <p className="text-xs text-amber-900">Convert only when the traveler has approved the current quote. The server will reject a stale or missing approval.</p>
                                        <button type="button" disabled={busy} onClick={() => convert(item.reference)} className="min-h-11 rounded-lg bg-teal-800 px-3 py-2 font-semibold text-white disabled:opacity-50">{busy ? 'Processing…' : 'Convert approved request'}</button>
                                        <button type="button" disabled={busy} onClick={() => setSelected(null)} className="block min-h-11 text-sm font-semibold text-slate-700 underline">Leave unchanged</button>
                                    </div>}
                                    {!eligible && <span className="text-xs text-slate-500">Not eligible for conversion</span>}
                                </td>
                            </tr>;
                        })}</tbody>
                    </table>
                    {requests.length === 0 && <p className="p-6 text-slate-600">No booking requests have been submitted.</p>}
                </div>
                <p className="mt-4 text-xs text-slate-500">Shows the newest 100 requests. Private contacts and approved quote state must not be copied or shared. Conversion is irreversible after seat allocation; cancelled bookings are managed separately.</p>
            </div>
        </main>
    </BablysAdminLayout>;
}
