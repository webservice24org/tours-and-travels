import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';
import { tourErrorMessage, tourRequest } from '@/components/admin/tours/client';
import type { TourCapabilities, TourListResponse, TourRow, TourStatus, TourStatusFilter } from '@/components/admin/tours/types';
import BablysAdminLayout from '@/layouts/bablys-admin-layout';

const API = '/admin/internal/tours';
const statuses: TourStatus[] = ['draft', 'in_review', 'scheduled', 'published', 'archived'];
const control = 'w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200';
const button = 'inline-flex items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50';
const primary = 'inline-flex items-center justify-center rounded-lg bg-teal-800 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-900';

export default function ToursIndex({ can }: { can: TourCapabilities }) {
    const [tours, setTours] = useState<TourRow[]>([]);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<TourStatusFilter>('all');
    const [featured, setFeatured] = useState('all');
    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0, per_page: 20 });
    const [loading, setLoading] = useState(true);
    const [listError, setListError] = useState('');
    const [revision, setRevision] = useState(0);
    const [deleting, setDeleting] = useState<number | null>(null);

    const query = useMemo(() => {
        const params = new URLSearchParams({ page: String(page), per_page: '20', status });
        if (search.trim()) params.set('search', search.trim());
        if (featured !== 'all') params.set('featured', featured === 'yes' ? '1' : '0');
        return `${API}?${params.toString()}`;
    }, [search, status, featured, page]);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        tourRequest<TourListResponse>(query, { signal: controller.signal })
            .then((result) => {
                if (controller.signal.aborted) return;
                setTours(result.data);
                setMeta(result.meta);
                setListError('');
            })
            .catch((error: unknown) => {
                if (!controller.signal.aborted) setListError(tourErrorMessage(error));
            })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [query, revision]);

    async function remove(tour: TourRow) {
        if (!can.edit || deleting !== null || ['published', 'scheduled'].includes(tour.status)) return;
        const agreed = await confirmAction({
            title: 'Delete tour?',
            description: `Soft-delete “${tour.title}”? Its slug remains reserved and the action will be audited.`,
            confirmLabel: 'Delete tour', tone: 'danger',
        });
        if (!agreed) return;
        setDeleting(tour.id);
        try {
            await tourRequest<void>(`${API}/${tour.id}`, { method: 'DELETE' });
            toast.success(`Tour “${tour.title}” deleted.`);
            setRevision((value) => value + 1);
        } catch (error) {
            toast.error(tourErrorMessage(error));
        } finally {
            setDeleting(null);
        }
    }

    function actions(tour: TourRow) {
        const writable = can.edit && (!['published', 'scheduled'].includes(tour.status) || can.publish);
        return <div className="flex flex-wrap items-center gap-2">
            {writable && <Link className={button} href={`/admin/tours/${tour.id}/edit`}>Edit tour</Link>}
            {can.edit && !['published', 'scheduled'].includes(tour.status) && <button type="button" className={`${button} text-rose-700`} disabled={deleting !== null} onClick={() => void remove(tour)}>
                {deleting === tour.id ? 'Deleting…' : 'Delete'}
            </button>}
        </div>;
    }

    return <>
        <Head title="Tours · Bablys CMS" />
        <BablysAdminLayout>
            <main className="mx-auto w-full min-w-0 max-w-7xl px-4 py-7 sm:px-6 lg:px-8">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-widest text-teal-700">Bablys CMS · Travel</p>
                        <h1 className="mt-1 text-3xl font-bold text-slate-900">Tour Packages</h1>
                        <p className="mt-2 text-sm text-slate-600">Manage tours with the full-page WordPress-style editor.</p>
                    </div>
                    {can.create && <Link href="/admin/tours/create" className={primary}>+ Add New Tour</Link>}
                </header>
                <section className="mt-6 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3" aria-label="Tour filters">
                    <label className="text-sm font-semibold text-slate-700">Search tours
                        <input className={`mt-1 ${control}`} value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} maxLength={100} placeholder="Title or slug" />
                    </label>
                    <label className="text-sm font-semibold text-slate-700">Status
                        <select className={`mt-1 ${control}`} value={status} onChange={(event) => { setStatus(event.target.value as TourStatusFilter); setPage(1); }}>
                            <option value="all">All statuses</option>{statuses.map((item) => <option key={item} value={item}>{item.replaceAll('_', ' ')}</option>)}
                        </select>
                    </label>
                    <label className="text-sm font-semibold text-slate-700">Featured
                        <select className={`mt-1 ${control}`} value={featured} onChange={(event) => { setFeatured(event.target.value); setPage(1); }}>
                            <option value="all">All tours</option><option value="yes">Featured only</option><option value="no">Not featured</option>
                        </select>
                    </label>
                </section>
                {listError && <p role="alert" className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{listError}</p>}
                <section className="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white" aria-label="Tour directory">
                    <div className="border-b border-slate-200 px-4 py-3 text-sm text-slate-600">{loading ? 'Loading tours…' : `${meta.total} tour(s)`}</div>
                    {!loading && tours.length === 0 && <p className="p-6 text-sm text-slate-500">No tours match your filters.</p>}
                    <div className="divide-y divide-slate-100 md:hidden">
                        {tours.map((tour) => <article key={tour.id} className="space-y-2 p-4">
                            <h2 className="font-semibold text-slate-900">{tour.title}</h2>
                            <p className="break-words text-xs text-slate-500">/{tour.slug} · {tour.status} · {tour.duration_days} day(s)</p>
                            {actions(tour)}
                        </article>)}
                    </div>
                    <div className="hidden w-full overflow-x-auto md:block">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-600"><tr>
                                <th className="p-4">Tour</th><th className="p-4">Status</th><th className="p-4">Duration</th><th className="p-4">Categories / Destinations</th><th className="p-4">Actions</th>
                            </tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {tours.map((tour) => <tr key={tour.id}>
                                    <td className="p-4"><div className="font-semibold">{tour.title}</div><div className="break-all text-xs text-slate-500">/{tour.slug}</div></td>
                                    <td className="p-4 capitalize">{tour.status.replaceAll('_', ' ')}{tour.is_featured && <span className="ml-2 text-xs text-teal-700">Featured</span>}</td>
                                    <td className="p-4">{tour.duration_days} days</td>
                                    <td className="p-4">{tour.category_count} / {tour.destination_count}</td>
                                    <td className="p-4">{actions(tour)}</td>
                                </tr>)}
                            </tbody>
                        </table>
                    </div>
                </section>
                <div className="mt-4 flex items-center justify-end gap-3">
                    <button type="button" className={button} onClick={() => setPage((value) => Math.max(1, value - 1))} disabled={page <= 1 || loading}>Previous</button>
                    <span className="text-sm text-slate-600">Page {meta.current_page} of {meta.last_page}</span>
                    <button type="button" className={button} onClick={() => setPage((value) => value + 1)} disabled={page >= meta.last_page || loading}>Next</button>
                </div>
            </main>
        </BablysAdminLayout>
    </>;
}
