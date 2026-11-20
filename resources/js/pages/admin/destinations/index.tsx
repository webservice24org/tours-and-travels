import BablysAdminLayout from '@/layouts/bablys-admin-layout';
import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';

import { DestinationApiError, destinationErrorMessage, destinationRequest } from '@/components/admin/destinations/client';
import type {
    CoordinatesFilter,
    Destination,
    DestinationListResponse,
    DestinationResponse,
    DestinationStatus,
    FieldErrors,
    PaginationMeta,
    StatusFilter,
} from '@/components/admin/destinations/types';

const API = '/admin/internal/destinations';
const emptyMeta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };
const statuses: DestinationStatus[] = ['draft', 'in_review', 'published', 'archived'];
const input = 'mt-1 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100';
const primary = 'inline-flex items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50';
const secondary = 'inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50';
const danger = 'rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40';
const labelStatus = (status: string): string => status.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const suggestSlug = (name: string): string => name.toLowerCase().normalize('NFKD').replace(/[^a-z0-9\s-]/g, '').trim().replace(/[\s_-]+/g, '-').replace(/^-|-$/g, '').slice(0, 160);

interface Draft {
    name: string;
    slug: string;
    country_code: string;
    region_name: string;
    city_name: string;
    summary: string;
    description: string;
    latitude: string;
    longitude: string;
    status: DestinationStatus;
    sort_order: string;
}
type Editor = { kind: 'create' } | { kind: 'edit'; destination: Destination } | null;

const freshDraft = (): Draft => ({
    name: '', slug: '', country_code: 'BD', region_name: '', city_name: '', summary: '', description: '',
    latitude: '', longitude: '', status: 'draft', sort_order: '0',
});

function FieldError({ name, errors }: { name: string; errors: FieldErrors }) {
    return errors[name]?.[0] ? <p className="mt-1 text-xs text-red-700" role="alert">{errors[name][0]}</p> : null;
}

function StatusPill({ status }: { status: DestinationStatus }) {
    const variants: Record<DestinationStatus, string> = {
        draft: 'bg-slate-100 text-slate-700',
        in_review: 'bg-amber-50 text-amber-800',
        published: 'bg-emerald-50 text-emerald-800',
        archived: 'bg-zinc-100 text-zinc-700',
    };
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${variants[status]}`}>{labelStatus(status)}</span>;
}

export default function DestinationsIndex() {
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
    const [countryFilter, setCountryFilter] = useState('');
    const [coordinatesFilter, setCoordinatesFilter] = useState<CoordinatesFilter>('all');
    const [perPage, setPerPage] = useState(20);
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [destinations, setDestinations] = useState<Destination[]>([]);
    const [meta, setMeta] = useState<PaginationMeta>(emptyMeta);
    const [loading, setLoading] = useState(true);
    const [listError, setListError] = useState('');
    const [editor, setEditor] = useState<Editor>(null);
    const [draft, setDraft] = useState<Draft>(freshDraft);
    const [slugTouched, setSlugTouched] = useState(false);
    const [errors, setErrors] = useState<FieldErrors>({});
    const [busy, setBusy] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);

    const query = useMemo(() => {
        const params = new URLSearchParams({
            page: String(page), per_page: String(perPage), status: statusFilter,
        });
        if (search.trim()) params.set('search', search.trim());
        // The API only accepts exactly two-letter country codes. Never submit a partial filter.
        if (/^[A-Z]{2}$/.test(countryFilter)) params.set('country_code', countryFilter);
        if (coordinatesFilter !== 'all') params.set('has_coordinates', coordinatesFilter === 'yes' ? '1' : '0');
        return `${API}?${params.toString()}`;
    }, [page, perPage, statusFilter, search, countryFilter, coordinatesFilter]);

    const load = useCallback(async (signal: AbortSignal) => {
        setLoading(true);
        setListError('');
        try {
            const result = await destinationRequest<DestinationListResponse>(query, { signal });
            if (signal.aborted) return;
            if (result.meta.current_page > result.meta.last_page) {
                setPage(Math.max(1, result.meta.last_page));
                return;
            }
            setDestinations(result.data);
            setMeta(result.meta);
        } catch (error) {
            if (signal.aborted) return;
            setDestinations([]);
            setMeta(emptyMeta);
            setListError(destinationErrorMessage(error));
        } finally {
            if (!signal.aborted) setLoading(false);
        }
    }, [query]);

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => void load(controller.signal), 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [load, revision]);

    useEffect(() => {
        if (!editor) return;
        const onEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !busy) setEditor(null);
        };
        window.addEventListener('keydown', onEscape);
        return () => window.removeEventListener('keydown', onEscape);
    }, [editor, busy]);

    function setField<K extends keyof Draft>(key: K, value: Draft[K]): void {
        setDraft((old) => ({ ...old, [key]: value }));
    }

    function startCreate() {
         setErrors({}); setSlugTouched(false);
        setDraft(freshDraft()); setEditor({ kind: 'create' });
    }

    function startEdit(destination: Destination) {
         setErrors({}); setSlugTouched(true);
        setDraft({
            name: destination.name,
            slug: destination.slug,
            country_code: destination.country_code,
            region_name: destination.region_name ?? '',
            city_name: destination.city_name ?? '',
            summary: destination.summary ?? '',
            description: destination.description ?? '',
            latitude: destination.latitude ?? '',
            longitude: destination.longitude ?? '',
            status: destination.status,
            sort_order: String(destination.sort_order),
        });
        setEditor({ kind: 'edit', destination });
    }

    function recordError(error: unknown) {
        if (error instanceof DestinationApiError) setErrors(error.fields);
        toast.error(destinationErrorMessage(error));
    }

    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!editor || busy) return;
        setErrors({}); 

        const latitude = draft.latitude.trim();
        const longitude = draft.longitude.trim();
        if ((latitude === '') !== (longitude === '')) {
            const message = 'Provide both latitude and longitude, or leave both blank.';
            setErrors({ latitude: [message], longitude: [message] });
            toast.error(message);
            return;
        }

        setBusy(true);
        try {
            const payload = {
                name: draft.name.trim(),
                country_code: draft.country_code.trim().toUpperCase(),
                region_name: draft.region_name.trim() || null,
                city_name: draft.city_name.trim() || null,
                summary: draft.summary.trim() || null,
                description: draft.description.trim() || null,
                latitude: latitude || null,
                longitude: longitude || null,
                status: draft.status,
                sort_order: Number(draft.sort_order),
                ...(editor.kind === 'create' ? { slug: draft.slug.trim() } : {}),
            };
            const target = editor.kind === 'create' ? API : `${API}/${editor.destination.id}`;
            const response = await destinationRequest<DestinationResponse>(target, {
                method: editor.kind === 'create' ? 'POST' : 'PUT', body: payload,
            });
            toast.success(`${response.data.name} ${editor.kind === 'create' ? 'created' : 'updated'} successfully.`);
            setEditor(null); setPage(1); setSearch(''); setCountryFilter('');
            setStatusFilter('all'); setCoordinatesFilter('all');
            setRevision((value) => value + 1);
        } catch (error) {
            recordError(error);
        } finally {
            setBusy(false);
        }
    }

    async function remove(destination: Destination) {
        if (busy || deletingId !== null || destination.status === 'published') return;
        if (!(await confirmAction({ title: 'Delete destination?', description: `Soft-delete “${destination.name}”? The slug will remain reserved, and this action will be audited.`, confirmLabel: 'Delete destination', tone: 'danger' }))) return;
         setDeletingId(destination.id);
        try {
            await destinationRequest<void>(`${API}/${destination.id}`, { method: 'DELETE' });
            toast.success(`${destination.name} soft-deleted successfully.`);
            setRevision((value) => value + 1);
        } catch (error) {
            toast.error(destinationErrorMessage(error));
        } finally {
            setDeletingId(null);
        }
    }

    const editOpen = editor !== null;

    return (
        <>
            <Head title="Destinations · Bablys CMS" />
            <BablysAdminLayout>
                <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:py-10">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700">Bablys Tours &amp; Travels · Content Management</p>
                            <h1 className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Destinations</h1>
                            <p className="mt-2 max-w-2xl text-sm text-slate-600">Manage countries, regions, cities, descriptions, coordinates, and publication status from one place.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Link href="/admin" className={secondary}>Back to admin</Link>
                            <Link href="/admin/tour-categories" className={secondary}>Tour categories</Link>
                            <button type="button" className={primary} onClick={startCreate}>+ New destination</button>
                        </div>
                    </header>

                    <section className="mt-6 rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm text-teal-900" aria-label="Destination publishing guidance">
                        <strong>Publishing rule:</strong> Destinations can be published independently. Archive a published destination before deleting it. Slugs cannot be changed until URL redirects are supported; deleted slugs stay reserved.
                    </section>

                    <div className={`mt-6 grid gap-6 ${editOpen ? 'lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]' : ''}`}>
                        <section className="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm" aria-label="Destination directory">
                            <div className="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 xl:grid-cols-4">
                                <label className="block text-xs font-semibold text-slate-700">Search destinations
                                    <input className={input} maxLength={100} placeholder="Name, city, region or slug" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
                                </label>
                                <label className="block text-xs font-semibold text-slate-700">Status
                                    <select className={input} value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value as StatusFilter); setPage(1); }}>
                                        <option value="all">All statuses</option>
                                        {statuses.map((status) => <option key={status} value={status}>{labelStatus(status)}</option>)}
                                    </select>
                                </label>
                                <label className="block text-xs font-semibold text-slate-700">Country code
                                    <input className={input} maxLength={2} placeholder="BD, TH, NP…" value={countryFilter} onChange={(event) => { setCountryFilter(event.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2)); setPage(1); }} />
                                    {countryFilter.length === 1 && <span className="mt-1 block text-xs font-normal text-amber-700">Enter both letters to activate the country filter.</span>}
                                </label>
                                <label className="block text-xs font-semibold text-slate-700">Coordinates
                                    <select className={input} value={coordinatesFilter} onChange={(event) => { setCoordinatesFilter(event.target.value as CoordinatesFilter); setPage(1); }}>
                                        <option value="all">All destinations</option>
                                        <option value="yes">With coordinates</option>
                                        <option value="no">Without coordinates</option>
                                    </select>
                                </label>
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 text-sm text-slate-600">
                                <span>{loading ? 'Loading destinations…' : `${meta.total} destination${meta.total === 1 ? '' : 's'}`}</span>
                                <div className="flex flex-wrap items-center gap-3">
                                    <button type="button" className="text-sm font-semibold text-teal-800 hover:underline" onClick={() => { setSearch(''); setCountryFilter(''); setStatusFilter('all'); setCoordinatesFilter('all'); setPage(1); }}>Clear filters</button>
                                    <label className="flex items-center gap-2">Rows
                                        <select className="rounded-lg border border-slate-300 px-2 py-1" value={perPage} onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1); }}>
                                            {[10, 20, 50].map((n) => <option key={n} value={n}>{n}</option>)}
                                        </select>
                                    </label>
                                </div>
                            </div>

                            {listError && <p className="p-4 text-sm text-red-700" role="alert">{listError}</p>}
                            <div className="hidden overflow-x-auto md:block">
                                <table className="w-full min-w-[650px] text-left text-sm">
                                    <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-600">
                                        <tr><th scope="col" className="p-4">Destination</th><th scope="col" className="p-4">Country / area</th><th scope="col" className="p-4">Status</th><th scope="col" className="p-4">Coordinates</th><th scope="col" className="p-4 text-right">Actions</th></tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {destinations.map((destination) => <tr key={destination.id}>
                                            <td className="p-4"><p className="font-semibold text-slate-900">{destination.name}</p><p className="break-all text-xs text-slate-500">/{destination.slug}</p></td>
                                            <td className="p-4 text-slate-700"><p className="font-semibold">{destination.country_code}</p><p className="text-xs text-slate-500">{[destination.region_name, destination.city_name].filter(Boolean).join(' · ') || 'No area specified'}</p></td>
                                            <td className="p-4"><StatusPill status={destination.status} /></td>
                                            <td className="p-4 text-slate-600">{destination.latitude !== null && destination.longitude !== null ? <span title="Latitude, longitude">{destination.latitude}, {destination.longitude}</span> : <span className="text-slate-400">Not set</span>}</td>
                                            <td className="p-4"><div className="flex justify-end gap-2">
                                                <button type="button" className={secondary} onClick={() => startEdit(destination)} disabled={busy || deletingId !== null}>Edit</button>
                                                <button type="button" className={danger} disabled={busy || deletingId !== null || destination.status === 'published'} title={destination.status === 'published' ? 'Archive before deleting' : 'Soft-delete destination'} onClick={() => void remove(destination)}>Delete</button>
                                            </div></td>
                                        </tr>)}
                                    </tbody>
                                </table>
                            </div>
                            <div className="divide-y divide-slate-100 md:hidden">
                                {destinations.map((destination) => <article className="space-y-3 p-4" key={destination.id}>
                                    <div className="flex flex-wrap items-start justify-between gap-2"><div className="min-w-0"><p className="font-semibold">{destination.name}</p><p className="break-all text-xs text-slate-500">/{destination.slug}</p></div><StatusPill status={destination.status} /></div>
                                    <p className="text-xs text-slate-700">{destination.country_code} · {[destination.region_name, destination.city_name].filter(Boolean).join(' / ') || 'Area not set'} · Order {destination.sort_order}</p>
                                    <p className="text-xs text-slate-600">Coordinates: {destination.latitude !== null && destination.longitude !== null ? `${destination.latitude}, ${destination.longitude}` : 'not set'}</p>
                                    <div className="flex flex-wrap gap-2"><button type="button" className={secondary} onClick={() => startEdit(destination)} disabled={busy || deletingId !== null}>Edit</button><button type="button" className={danger} disabled={busy || deletingId !== null || destination.status === 'published'} title={destination.status === 'published' ? 'Archive before deleting' : 'Soft-delete destination'} onClick={() => void remove(destination)}>Delete</button></div>
                                </article>)}
                            </div>
                            {!loading && !listError && destinations.length === 0 && <p className="p-8 text-center text-sm text-slate-500">No destinations match these filters. Create one or adjust your search.</p>}
                            <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm">
                                <span className="text-slate-600">Page {meta.current_page} of {meta.last_page}</span>
                                <div className="flex gap-2">
                                    <button type="button" className={secondary} disabled={loading || page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Previous</button>
                                    <button type="button" className={secondary} disabled={loading || page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</button>
                                </div>
                            </footer>
                        </section>

                        {editor && <aside aria-label="Destination editor" className="min-w-0 self-start rounded-xl border border-teal-200 bg-white p-5 shadow-sm lg:sticky lg:top-5">
                            <div className="flex items-center justify-between gap-3"><h2 className="text-lg font-bold">{editor.kind === 'create' ? 'Create destination' : 'Edit destination'}</h2><button type="button" className={secondary} disabled={busy} onClick={() => setEditor(null)}>Close</button></div>
                            <form noValidate className="mt-5 space-y-4" onSubmit={(event) => void save(event)}>
                                <label className="block text-sm font-semibold text-slate-700">Name <span className="text-red-700">*</span>
                                    <input className={input} required maxLength={180} value={draft.name} onChange={(event) => { const name = event.target.value; setDraft((old) => ({ ...old, name, slug: editor.kind === 'create' && !slugTouched ? suggestSlug(name) : old.slug })); }} />
                                    <FieldError errors={errors} name="name" />
                                </label>
                                <label className="block text-sm font-semibold text-slate-700">Slug <span className="text-red-700">*</span>
                                    <input className={input} required maxLength={160} value={draft.slug} readOnly={editor.kind === 'edit'} onChange={(event) => { setSlugTouched(true); setField('slug', event.target.value); }} />
                                    <span className="mt-1 block text-xs font-normal text-slate-500">{editor.kind === 'edit' ? 'Locked to preserve existing URLs.' : 'Suggested from the English name; you can edit it.'}</span>
                                    <FieldError errors={errors} name="slug" />
                                </label>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                    <label className="block text-sm font-semibold text-slate-700">Country code <span className="text-red-700">*</span>
                                        <input className={input} required maxLength={2} placeholder="BD" value={draft.country_code} onChange={(event) => setField('country_code', event.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2))} />
                                        <span className="mt-1 block text-xs font-normal text-slate-500">ISO two-letter code (BD, TH, NP).</span>
                                        <FieldError errors={errors} name="country_code" />
                                    </label>
                                    <label className="block text-sm font-semibold text-slate-700">Sort order
                                        <input className={input} type="number" step="1" min="0" max="1000000" value={draft.sort_order} onChange={(event) => setField('sort_order', event.target.value)} />
                                        <FieldError errors={errors} name="sort_order" />
                                    </label>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                    <label className="block text-sm font-semibold text-slate-700">Region / state
                                        <input className={input} maxLength={160} value={draft.region_name} onChange={(event) => setField('region_name', event.target.value)} />
                                        <FieldError errors={errors} name="region_name" />
                                    </label>
                                    <label className="block text-sm font-semibold text-slate-700">City / town
                                        <input className={input} maxLength={160} value={draft.city_name} onChange={(event) => setField('city_name', event.target.value)} />
                                        <FieldError errors={errors} name="city_name" />
                                    </label>
                                </div>
                                <label className="block text-sm font-semibold text-slate-700">Summary
                                    <textarea className={input} rows={2} maxLength={500} value={draft.summary} onChange={(event) => setField('summary', event.target.value)} />
                                    <FieldError errors={errors} name="summary" />
                                </label>
                                <label className="block text-sm font-semibold text-slate-700">Description
                                    <textarea className={input} rows={5} maxLength={20000} value={draft.description} onChange={(event) => setField('description', event.target.value)} />
                                    <FieldError errors={errors} name="description" />
                                </label>
                                <fieldset className="rounded-xl border border-slate-200 p-3">
                                    <legend className="px-1 text-sm font-semibold text-slate-700">GPS location (optional)</legend>
                                    <p className="mb-2 text-xs text-slate-500">Provide both coordinates or leave both blank. Latitude −90 to 90; longitude −180 to 180; up to seven decimal places.</p>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                        <label className="block text-sm font-semibold text-slate-700">Latitude
                                            <input className={input} type="number" step="any" min="-90" max="90" placeholder="23.8103" value={draft.latitude} onChange={(event) => setField('latitude', event.target.value)} />
                                            <FieldError errors={errors} name="latitude" />
                                        </label>
                                        <label className="block text-sm font-semibold text-slate-700">Longitude
                                            <input className={input} type="number" step="any" min="-180" max="180" placeholder="90.4125" value={draft.longitude} onChange={(event) => setField('longitude', event.target.value)} />
                                            <FieldError errors={errors} name="longitude" />
                                        </label>
                                    </div>
                                    <button type="button" className="mt-2 text-xs font-semibold text-teal-800 hover:underline" onClick={() => { setField('latitude', ''); setField('longitude', ''); }} disabled={busy}>Clear both coordinates</button>
                                </fieldset>
                                <label className="block text-sm font-semibold text-slate-700">Publication status
                                    <select className={input} value={draft.status} onChange={(event) => setField('status', event.target.value as DestinationStatus)}>
                                        {statuses.map((status) => <option key={status} value={status}>{labelStatus(status)}</option>)}
                                    </select>
                                    <FieldError errors={errors} name="status" />
                                </label>
                                {errors.destination?.[0] && <p className="text-sm text-red-700" role="alert">{errors.destination[0]}</p>}
                                <button type="submit" className={`${primary} w-full`} disabled={busy}>{busy ? 'Saving…' : editor.kind === 'create' ? 'Create destination' : 'Save changes'}</button>
                            </form>
                        </aside>}
                    </div>
                </div>
            </BablysAdminLayout>
        </>
    );
}
