import { Link, router } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import PublicSiteLayout from '@/layouts/public-site-layout';

/** Customer-only shell: public Bablys header/footer, never the staff sidebar. */
export default function CustomerTravelLayout({ children }: PropsWithChildren) {
    return <PublicSiteLayout>
        <main className="mx-auto w-full max-w-6xl px-4 py-8 text-slate-900 sm:px-6 sm:py-10 lg:px-8">
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-widest text-emerald-800">Bablys Tours &amp; Travels</p>
                    <h1 className="mt-1 text-2xl font-bold sm:text-3xl">My travel account</h1>
                    <p className="mt-1 text-sm text-slate-600">Private booking information for your verified account.</p>
                </div>
                <button type="button" onClick={() => router.post('/logout')} className="min-h-11 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Sign out</button>
            </div>
            <nav aria-label="Customer account navigation" className="mb-6 flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
                <Link href="/account/travel" className="inline-flex min-h-11 items-center rounded-lg px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Overview</Link>
                <Link href="/account/bookings" className="inline-flex min-h-11 items-center rounded-lg px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">My bookings</Link>
                <Link href="/account/booking-requests" className="inline-flex min-h-11 items-center rounded-lg px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">My requests</Link>
                <Link href="/tours" className="inline-flex min-h-11 items-center rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Explore tours</Link>
            </nav>
            {children}
        </main>
    </PublicSiteLayout>;
}
