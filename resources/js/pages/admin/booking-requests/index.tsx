import { Head, Link } from '@inertiajs/react';

import { type BookingRequestView, when } from '../../public/booking-requests/http';

type StaffRow = BookingRequestView & { name: string; email: string; phone: string | null };

export default function AdminBookingRequests({ requests }: { requests: StaffRow[]; review_only: true }) {
    return <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-900 sm:px-6 lg:px-10">
        <Head title="Booking request review | Bablys Tours & Travels" />
        <div className="mx-auto max-w-6xl">
            <Link href="/admin/tours" className="text-sm font-semibold text-teal-800 hover:underline">Tour management</Link>
            <h1 className="mt-4 text-3xl font-bold">Booking request review</h1>
            <p className="mt-3 text-sm text-slate-600">Restricted travel staff view. Read only: verification is not a reservation. Do not convert at a changed price without recorded customer approval.</p>
            <div className="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table className="min-w-[780px] w-full divide-y divide-slate-200 text-left text-sm">
                    <thead className="bg-slate-50 text-slate-700"><tr>
                        {['Reference / status', 'Tour / departure', 'Travelers', 'Private contact', 'Expiry'].map((cell) => <th key={cell} scope="col" className="px-4 py-3 font-semibold">{cell}</th>)}
                    </tr></thead>
                    <tbody className="divide-y divide-slate-100">{requests.map((item) => <tr key={item.reference} className="align-top">
                        <td className="px-4 py-3"><span className="break-all font-semibold">{item.reference}</span><span className="mt-1 block text-slate-600">{item.status.replaceAll('_', ' ')}</span></td>
                        <td className="px-4 py-3"><span className="font-medium">{item.tour_title}</span><span className="mt-1 block text-slate-600">{item.departure_name}</span></td>
                        <td className="px-4 py-3">{item.adults} adult(s), {item.children} child(ren)<span className="block text-slate-600">{item.variant_code}</span></td>
                        <td className="px-4 py-3"><span className="block font-medium">{item.name}</span><span className="block break-all">{item.email}</span>{item.phone && <span className="block">{item.phone}</span>}</td>
                        <td className="px-4 py-3 text-slate-600">{when(item.expires_at_utc)}</td>
                    </tr>)}</tbody>
                </table>
                {requests.length === 0 && <p className="p-6 text-slate-600">No booking requests have been submitted.</p>}
            </div>
            <p className="mt-4 text-xs text-slate-500">Displays up to the newest 100 requests. Traveler details are restricted; do not export or share them without authorization.</p>
        </div>
    </main>;
}
