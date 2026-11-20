import BablysAdminLayout from '@/layouts/bablys-admin-layout';
import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import { auditError, auditGet } from '@/components/admin/audit/client';
import type {
    AuditDetail, AuditDetailResponse, AuditEvent, AuditOptionsResponse,
    AuditPageMeta, AuditPageResponse,
} from '@/components/admin/audit/types';

const API = '/admin/internal/audit-logs';
const emptyMeta: AuditPageMeta = { current_page: 1, last_page: 1, per_page: 25, total: 0 };
const field = 'block w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-100';
const button = 'inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40';

function readable(value: string): string {
    return value.replaceAll('.', ' · ').replaceAll('_', ' ');
}

function when(timestamp: string): string {
    const date = new Date(timestamp);
    return Number.isNaN(date.getTime()) ? timestamp : date.toLocaleString();
}

function EventDetail({ event, onClose }: { event: AuditDetail; onClose: () => void }) {
    return (
        <section aria-labelledby="audit-detail-title" className="min-w-0 rounded-xl border border-teal-200 bg-white p-4 shadow-sm sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-bold uppercase tracking-wider text-teal-700">Read-only event</p>
                    <h2 id="audit-detail-title" className="mt-1 break-words text-xl font-bold text-slate-900">{readable(event.action)}</h2>
                </div>
                <button type="button" onClick={onClose} className={button} aria-label="Close audit event details">Close details</button>
            </div>
            <dl className="mt-4 grid min-w-0 gap-3 text-sm sm:grid-cols-2">
                <div><dt className="font-semibold text-slate-500">Occurred</dt><dd className="mt-1 text-slate-900">{when(event.occurred_at)}</dd></div>
                <div><dt className="font-semibold text-slate-500">Actor</dt><dd className="mt-1 break-words text-slate-900">{event.actor_name}{event.actor_id !== null ? ` (#${event.actor_id})` : ''}</dd></div>
                <div><dt className="font-semibold text-slate-500">Subject</dt><dd className="mt-1 break-words text-slate-900">{readable(event.subject_type)} {event.subject_id ? `#${event.subject_id}` : ''}</dd></div>
                <div><dt className="font-semibold text-slate-500">IP address</dt><dd className="mt-1 break-words text-slate-900">{event.ip_address ?? 'Not recorded'}</dd></div>
                <div className="sm:col-span-2"><dt className="font-semibold text-slate-500">Event ID</dt><dd className="mt-1 break-all font-mono text-xs text-slate-800">{event.id}</dd></div>
                <div className="sm:col-span-2"><dt className="font-semibold text-slate-500">User agent</dt><dd className="mt-1 break-all text-slate-700">{event.user_agent ?? 'Not recorded'}</dd></div>
            </dl>
            <div className="mt-5 min-w-0">
                <h3 className="font-semibold text-slate-900">Recorded changes</h3>
                <p className="mt-1 text-xs text-slate-500">Curated audit metadata, redacted by the server. No editing or deletion controls are available.</p>
                <pre className="mt-2 max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-950 p-4 text-xs leading-relaxed text-slate-50">{JSON.stringify(event.metadata, null, 2)}</pre>
            </div>
        </section>
    );
}

export default function CmsAuditLogsIndex() {
    const [search, setSearch] = useState('');
    const [action, setAction] = useState('');
    const [actorId, setActorId] = useState('');
    const [subjectType, setSubjectType] = useState('');
    const [subjectId, setSubjectId] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [perPage, setPerPage] = useState<10 | 25 | 50>(25);
    const [page, setPage] = useState(1);
    const [events, setEvents] = useState<AuditEvent[]>([]);
    const [meta, setMeta] = useState<AuditPageMeta>(emptyMeta);
    const [actions, setActions] = useState<string[]>([]);
    const [subjectTypes, setSubjectTypes] = useState<string[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [details, setDetails] = useState<AuditDetail | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState('');

    const query = useMemo(() => {
        const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
        if (search.trim()) params.set('search', search.trim());
        if (action) params.set('action', action);
        if (actorId.trim()) params.set('actor_id', actorId.trim());
        if (subjectType) params.set('subject_type', subjectType);
        if (subjectId.trim()) params.set('subject_id', subjectId.trim());
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        return `${API}?${params.toString()}`;
    }, [page, perPage, search, action, actorId, subjectType, subjectId, dateFrom, dateTo]);

    const refresh = useCallback(async (signal?: AbortSignal) => {
        setLoading(true);
        setError('');
        try {
            const response = await auditGet<AuditPageResponse>(query, signal);
            if (signal?.aborted) return;
            if (response.meta.current_page > response.meta.last_page) {
                setPage(Math.max(1, response.meta.last_page));
                return;
            }
            setEvents(response.data);
            setMeta(response.meta);
        } catch (reason) {
            if (signal?.aborted) return;
            setEvents([]);
            setMeta(emptyMeta);
            setError(auditError(reason));
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [query]);

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => void refresh(controller.signal), 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [refresh]);

    useEffect(() => {
        const controller = new AbortController();
        void auditGet<AuditOptionsResponse>(`${API}/options`, controller.signal)
            .then((result) => {
                if (!controller.signal.aborted) {
                    setActions(result.data.actions);
                    setSubjectTypes(result.data.subject_types);
                }
            })
            .catch((reason: unknown) => { if (!controller.signal.aborted) setError(auditError(reason)); });
        return () => controller.abort();
    }, []);

    useEffect(() => {
        if (!details) return;
        const closeOnEscape = (event: KeyboardEvent) => { if (event.key === 'Escape') setDetails(null); };
        window.addEventListener('keydown', closeOnEscape);
        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [details]);

    async function inspect(id: string) {
        setDetailLoading(true);
        setDetailError('');
        setDetails(null);
        try {
            const response = await auditGet<AuditDetailResponse>(`${API}/${encodeURIComponent(id)}`);
            setDetails(response.data);
        } catch (reason) {
            setDetailError(auditError(reason));
        } finally { setDetailLoading(false); }
    }

    function reset() {
        setSearch(''); setAction(''); setActorId(''); setSubjectType(''); setSubjectId('');
        setDateFrom(''); setDateTo(''); setPage(1); setDetails(null);
    }

    return (
        <>
            <Head title="Bablys CMS · Audit logs" />
            <BablysAdminLayout>
                <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:py-10">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700">Bablys Tours &amp; Travels</p>
                            <h1 className="mt-2 text-3xl font-bold tracking-tight">Administrative audit logs</h1>
                            <p className="mt-2 max-w-2xl text-sm text-slate-600">Review security-sensitive staff and role changes. Audit records are read-only and dates are filtered in UTC.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Link href="/admin" className={button}>CMS administration</Link>
                            <Link href="/admin/staff-users" className={button}>Staff users</Link>
                            <Link href="/admin/roles" className={button}>Roles</Link>
                        </div>
                    </div>

                    <section aria-labelledby="audit-filters-title" className="mt-7 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 id="audit-filters-title" className="font-bold">Filter events</h2>
                            <button type="button" onClick={reset} className={button}>Clear filters</button>
                        </div>
                        <div className="mt-4 grid min-w-0 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <label className="min-w-0 text-sm font-semibold">Search events or actors
                                <input className={`${field} mt-1`} value={search} maxLength={100} placeholder="Action, actor, subject, event ID" onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
                            </label>
                            <label className="min-w-0 text-sm font-semibold">Action
                                <select className={`${field} mt-1`} value={action} onChange={(event) => { setAction(event.target.value); setPage(1); }}>
                                    <option value="">All actions</option>
                                    {actions.map((option) => <option key={option} value={option}>{readable(option)}</option>)}
                                </select>
                            </label>
                            <label className="min-w-0 text-sm font-semibold">Actor user ID
                                <input className={`${field} mt-1`} type="number" min={1} step={1} value={actorId} placeholder="Any administrator" onChange={(event) => { setActorId(event.target.value); setPage(1); }} />
                            </label>
                            <label className="min-w-0 text-sm font-semibold">Subject type
                                <select className={`${field} mt-1`} value={subjectType} onChange={(event) => { setSubjectType(event.target.value); setPage(1); }}>
                                    <option value="">All subjects</option>
                                    {subjectTypes.map((option) => <option key={option} value={option}>{readable(option)}</option>)}
                                </select>
                            </label>
                            <label className="min-w-0 text-sm font-semibold">Subject ID
                                <input className={`${field} mt-1`} value={subjectId} maxLength={64} placeholder="Affected record" onChange={(event) => { setSubjectId(event.target.value); setPage(1); }} />
                            </label>
                            <label className="min-w-0 text-sm font-semibold">From (UTC)
                                <input className={`${field} mt-1`} type="date" value={dateFrom} onChange={(event) => { setDateFrom(event.target.value); setPage(1); }} />
                            </label>
                            <label className="min-w-0 text-sm font-semibold">Through (UTC)
                                <input className={`${field} mt-1`} type="date" min={dateFrom || undefined} value={dateTo} onChange={(event) => { setDateTo(event.target.value); setPage(1); }} />
                            </label>
                            <label className="min-w-0 text-sm font-semibold">Rows per page
                                <select className={`${field} mt-1`} value={perPage} onChange={(event) => { setPerPage(Number(event.target.value) as 10 | 25 | 50); setPage(1); }}>
                                    <option value={10}>10</option><option value={25}>25</option><option value={50}>50</option>
                                </select>
                            </label>
                        </div>
                    </section>

                    <section className="mt-6 min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="audit-list-title">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
                            <h2 id="audit-list-title" className="font-bold">Events <span className="font-normal text-slate-500">({meta.total})</span></h2>
                            <button type="button" className={button} onClick={() => void refresh()} disabled={loading}>Refresh</button>
                        </div>
                        {error && <p role="alert" className="m-4 rounded-lg bg-red-50 p-3 text-sm text-red-800">{error}</p>}
                        {loading ? <p role="status" className="p-5 text-sm text-slate-600">Loading audit records…</p> : events.length === 0
                            ? <p className="p-5 text-sm text-slate-600">No events match these filters.</p>
                            : (
                                <>
                                    <div className="hidden overflow-x-auto md:block">
                                        <table className="w-full min-w-[780px] table-auto border-collapse text-left text-sm">
                                            <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>
                                                <th scope="col" className="px-4 py-3">Occurred</th><th scope="col" className="px-4 py-3">Action</th>
                                                <th scope="col" className="px-4 py-3">Actor</th><th scope="col" className="px-4 py-3">Subject</th>
                                                <th scope="col" className="px-4 py-3">Details</th>
                                            </tr></thead>
                                            <tbody className="divide-y divide-slate-100">{events.map((event) => (
                                                <tr key={event.id}>
                                                    <td className="whitespace-nowrap px-4 py-3 text-slate-600">{when(event.occurred_at)}</td>
                                                    <td className="px-4 py-3 font-semibold text-teal-800">{readable(event.action)}</td>
                                                    <td className="px-4 py-3">{event.actor_name}</td>
                                                    <td className="px-4 py-3">{readable(event.subject_type)} {event.subject_id ? `#${event.subject_id}` : ''}</td>
                                                    <td className="px-4 py-3"><button type="button" className={button} onClick={() => void inspect(event.id)}>View</button></td>
                                                </tr>
                                            ))}</tbody>
                                        </table>
                                    </div>
                                    <div className="divide-y divide-slate-100 md:hidden">{events.map((event) => (
                                        <article key={event.id} className="min-w-0 p-4">
                                            <p className="text-xs text-slate-500">{when(event.occurred_at)}</p>
                                            <h3 className="mt-1 break-words font-semibold text-teal-800">{readable(event.action)}</h3>
                                            <p className="mt-2 break-words text-sm">{event.actor_name} · {readable(event.subject_type)} {event.subject_id ? `#${event.subject_id}` : ''}</p>
                                            <button type="button" className={`${button} mt-3`} onClick={() => void inspect(event.id)}>View details</button>
                                        </article>
                                    ))}</div>
                                </>
                            )}
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-4 text-sm sm:px-5">
                            <span className="text-slate-600">Page {meta.current_page} of {meta.last_page}</span>
                            <div className="flex gap-2">
                                <button type="button" className={button} disabled={loading || page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>Previous</button>
                                <button type="button" className={button} disabled={loading || page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Next</button>
                            </div>
                        </div>
                    </section>
                    {detailLoading && <p role="status" className="mt-5 text-sm text-slate-600">Loading event details…</p>}
                    {detailError && <p role="alert" className="mt-5 rounded-lg bg-red-50 p-4 text-sm text-red-800">{detailError}</p>}
                    {details && <div className="mt-6"><EventDetail event={details} onClose={() => setDetails(null)} /></div>}
                    <p className="mt-6 text-xs text-slate-500">Audit history is append-only through normal CMS operations. Production immutability and retention require separate database and infrastructure controls.</p>
                </div>
            </BablysAdminLayout>
        </>
    );
}
