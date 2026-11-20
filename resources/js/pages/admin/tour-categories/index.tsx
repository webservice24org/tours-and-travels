import BablysAdminLayout from '@/layouts/bablys-admin-layout';
import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';

import { CategoryApiError, categoryErrorMessage, categoryRequest } from '@/components/admin/tour-categories/client';
import type {
    CategoryListResponse, CategoryOption, CategoryOptionsResponse, CategoryResponse,
    CategoryStatusFilter, FieldErrors, PaginationMeta, TourCategory, WritableCategoryStatus,
} from '@/components/admin/tour-categories/types';

const API = '/admin/internal/tour-categories';
const emptyMeta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };
const field = 'mt-1 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100';
const primary = 'inline-flex items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50';
const secondary = 'inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50';
const statuses: WritableCategoryStatus[] = ['draft', 'in_review', 'published', 'archived'];
const labelStatus = (status: string) => status.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const slugSuggestion = (name: string) => name.toLowerCase().normalize('NFKD').replace(/[^a-z0-9\s-]/g, '').trim().replace(/[\s_-]+/g, '-').replace(/^-|-$/g, '').slice(0, 160);

type Editor = { kind: 'create' } | { kind: 'edit'; category: TourCategory } | null;
type Draft = { name: string; slug: string; parent_id: string; summary: string; description: string; status: WritableCategoryStatus; sort_order: string };
const freshDraft = (): Draft => ({ name: '', slug: '', parent_id: '', summary: '', description: '', status: 'draft', sort_order: '0' });

function FieldError({ errors, name }: { errors: FieldErrors; name: string }) {
    const error = errors[name]?.[0];
    return error ? <p role="alert" className="mt-1 text-xs text-red-700">{error}</p> : null;
}

function StatusPill({ status }: { status: WritableCategoryStatus }) {
    const colors: Record<WritableCategoryStatus, string> = {
        draft: 'bg-slate-100 text-slate-700',
        in_review: 'bg-amber-50 text-amber-800',
        published: 'bg-emerald-50 text-emerald-800',
        archived: 'bg-zinc-100 text-zinc-700',
    };
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${colors[status]}`}>{labelStatus(status)}</span>;
}

function ancestorsOf(id: number, options: CategoryOption[]): Set<number> {
    const byId = new Map(options.map((item) => [item.id, item]));
    const parents = new Set<number>();
    let cursor = byId.get(id)?.parent_id ?? null;
    while (cursor !== null && !parents.has(cursor)) {
        parents.add(cursor);
        cursor = byId.get(cursor)?.parent_id ?? null;
    }
    return parents;
}

function descendantsOf(id: number, options: CategoryOption[]): Set<number> {
    const descendants = new Set<number>([id]);
    let previous = -1;
    while (previous !== descendants.size) {
        previous = descendants.size;
        for (const option of options) if (option.parent_id !== null && descendants.has(option.parent_id)) descendants.add(option.id);
    }
    return descendants;
}

export default function TourCategoriesIndex() {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<CategoryStatusFilter>('all');
    const [rootOnly, setRootOnly] = useState(false);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);
    const [categories, setCategories] = useState<TourCategory[]>([]);
    const [meta, setMeta] = useState<PaginationMeta>(emptyMeta);
    const [options, setOptions] = useState<CategoryOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [listError, setListError] = useState('');
    const [optionsError, setOptionsError] = useState('');
    const [revision, setRevision] = useState(0);
    const [editor, setEditor] = useState<Editor>(null);
    const [draft, setDraft] = useState<Draft>(freshDraft);
    const [slugTouched, setSlugTouched] = useState(false);
    const [errors, setErrors] = useState<FieldErrors>({});
    const [busy, setBusy] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);

    const query = useMemo(() => {
        const params = new URLSearchParams({ page: String(page), per_page: String(perPage), status });
        if (search.trim()) params.set('search', search.trim());
        if (rootOnly) params.set('root_only', '1');
        return `${API}?${params.toString()}`;
    }, [page, perPage, rootOnly, search, status]);

    const loadList = useCallback(async (signal: AbortSignal) => {
        setLoading(true); setListError('');
        try {
            const result = await categoryRequest<CategoryListResponse>(query, { signal });
            if (signal.aborted) return;
            if (result.meta.current_page > result.meta.last_page) {
                setPage(Math.max(1, result.meta.last_page));
                return;
            }
            setCategories(result.data); setMeta(result.meta);
        } catch (error) {
            if (signal.aborted) return;
            setCategories([]); setMeta(emptyMeta); setListError(categoryErrorMessage(error));
        } finally { if (!signal.aborted) setLoading(false); }
    }, [query]);

    const loadOptions = useCallback(async (signal?: AbortSignal) => {
        try {
            const result = await categoryRequest<CategoryOptionsResponse>(`${API}/options`, { signal });
            if (signal?.aborted) return;
            setOptions(result.data); setOptionsError('');
        } catch (error) {
            if (signal?.aborted) return;
            setOptions([]); setOptionsError(categoryErrorMessage(error));
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => void loadList(controller.signal), 250);
        return () => { window.clearTimeout(timeout); controller.abort(); };
    }, [loadList, revision]);
    useEffect(() => {
        const controller = new AbortController();
        void loadOptions(controller.signal);
        return () => controller.abort();
    }, [loadOptions]);
    useEffect(() => {
        if (!editor) return;
        const onEscape = (event: KeyboardEvent) => { if (event.key === 'Escape' && !busy) setEditor(null); };
        window.addEventListener('keydown', onEscape);
        return () => window.removeEventListener('keydown', onEscape);
    }, [busy, editor]);

    const byId = useMemo(() => new Map(options.map((item) => [item.id, item])), [options]);
    const parentOptions = useMemo(() => {
        const forbidden = editor?.kind === 'edit' ? descendantsOf(editor.category.id, options) : new Set<number>();
        return options.filter((item) => !forbidden.has(item.id)).map((item) => {
            const depth = ancestorsOf(item.id, options).size;
            return { ...item, label: `${'— '.repeat(Math.min(depth, 5))}${item.name} (${labelStatus(item.status)})` };
        });
    }, [editor, options]);

    function startCreate() {
         setErrors({}); setSlugTouched(false); setDraft(freshDraft()); setEditor({ kind: 'create' });
    }
    function startEdit(category: TourCategory) {
         setErrors({}); setSlugTouched(true);
        setDraft({ name: category.name, slug: category.slug,
            parent_id: category.parent_id === null ? '' : String(category.parent_id),
            summary: category.summary ?? '', description: category.description ?? '',
            status: category.status, sort_order: String(category.sort_order) });
        setEditor({ kind: 'edit', category });
    }
    function handleError(error: unknown) {
        if (error instanceof CategoryApiError) setErrors(error.fields);
        toast.error(categoryErrorMessage(error));
    }

    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!editor || busy) return;
        setBusy(true); setErrors({}); 
        try {
            const body = {
                name: draft.name.trim(), parent_id: draft.parent_id ? Number(draft.parent_id) : null,
                summary: draft.summary.trim() || null, description: draft.description.trim() || null,
                status: draft.status, sort_order: Number(draft.sort_order),
                ...(editor.kind === 'create' ? { slug: draft.slug.trim() } : {}),
            };
            const target = editor.kind === 'create' ? API : `${API}/${editor.category.id}`;
            const result = await categoryRequest<CategoryResponse>(target, {
                method: editor.kind === 'create' ? 'POST' : 'PUT', body,
            });
            toast.success(`${result.data.name} ${editor.kind === 'create' ? 'created' : 'updated'} successfully.`);
            setEditor(null); setPage(1); setSearch(''); setStatus('all'); setRootOnly(false);
            setRevision((value) => value + 1); await loadOptions();
        } catch (error) { handleError(error); }
        finally { setBusy(false); }
    }

    async function remove(category: TourCategory) {
        if (busy || deletingId !== null || category.children_count > 0) return;
        if (!(await confirmAction({ title: 'Delete tour category?', description: `Soft-delete “${category.name}”? The slug will remain reserved and the action will be audited.`, confirmLabel: 'Delete category', tone: 'danger' }))) return;
        setDeletingId(category.id); 
        try {
            await categoryRequest<void>(`${API}/${category.id}`, { method: 'DELETE' });
            toast.success(`${category.name} soft-deleted.`);
            setRevision((value) => value + 1); await loadOptions();
        } catch (error) {
            toast.error(categoryErrorMessage(error));
        } finally { setDeletingId(null); }
    }

    return (
        <>
            <Head title="Tour Categories · Bablys CMS" />
            <BablysAdminLayout>
                <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:py-10">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700">Bablys Tours & Travels · Content Management</p>
                            <h1 className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Tour categories</h1>
                            <p className="mt-2 max-w-2xl text-sm text-slate-600">Organize domestic and international tour categories, parent/child relationships, and publication status.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Link href="/admin" className={secondary}>Back to admin</Link>
                            <button type="button" onClick={startCreate} className={primary}>+ New category</button>
                        </div>
                    </header>
                    <section className="mt-6 rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm text-teal-900" aria-label="Publishing guidance">
                        <strong>Publishing rule:</strong> A child can be published only if its parents are published. Existing slugs stay fixed until URL redirects are introduced. Deletes are soft deletes.
                    </section>
                    <div className={`mt-6 grid gap-6 ${editor ? 'lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]' : ''}`}>
                        <section className="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm" aria-label="Category directory">
                            <div className="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
                                <label className="block text-xs font-semibold text-slate-700 sm:col-span-2">Search categories
                                    <input className={field} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Name or slug" maxLength={100} />
                                </label>
                                <label className="block text-xs font-semibold text-slate-700">Status
                                    <select className={field} value={status} onChange={(e) => { setStatus(e.target.value as CategoryStatusFilter); setPage(1); }}>
                                        <option value="all">All statuses</option>
                                        {statuses.map((s) => <option key={s} value={s}>{labelStatus(s)}</option>)}
                                    </select>
                                </label>
                                <label className="flex items-center gap-2 self-end rounded-lg border border-slate-200 px-3 py-3 text-sm font-medium text-slate-700">
                                    <input type="checkbox" className="size-4 accent-teal-700" checked={rootOnly} onChange={(e) => { setRootOnly(e.target.checked); setPage(1); }} /> Root categories only
                                </label>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 text-sm text-slate-600">
                                <span>{loading ? 'Loading…' : `${meta.total} categor${meta.total === 1 ? 'y' : 'ies'}`}</span>
                                <label className="flex items-center gap-2">Rows
                                    <select className="rounded-md border border-slate-300 px-2 py-1" value={perPage} onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1); }}>
                                        {[10, 20, 50].map((n) => <option key={n} value={n}>{n}</option>)}
                                    </select>
                                </label>
                            </div>
                            {listError && <p role="alert" className="p-4 text-sm text-red-700">{listError}</p>}
                            <div className="hidden overflow-x-auto md:block">
                                <table className="w-full min-w-[640px] text-left text-sm">
                                    <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-600"><tr>
                                        <th scope="col" className="p-4">Category</th><th scope="col" className="p-4">Parent</th><th scope="col" className="p-4">Status</th><th scope="col" className="p-4">Order / children</th><th scope="col" className="p-4 text-right">Actions</th>
                                    </tr></thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {categories.map((item) => <tr key={item.id}>
                                            <td className="p-4"><p className="font-semibold text-slate-900">{item.name}</p><p className="break-all text-xs text-slate-500">/{item.slug}</p></td>
                                            <td className="p-4 text-slate-600">{item.parent_id === null ? 'Root' : byId.get(item.parent_id)?.name ?? `#${item.parent_id}`}</td>
                                            <td className="p-4"><StatusPill status={item.status} /></td>
                                            <td className="p-4 text-slate-600">{item.sort_order} / {item.children_count}</td>
                                            <td className="p-4"><div className="flex justify-end gap-2"><button type="button" className={secondary} onClick={() => startEdit(item)}>Edit</button><button type="button" className="rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:opacity-40" disabled={deletingId !== null || busy || item.children_count > 0} title={item.children_count > 0 ? 'Remove child categories first' : 'Soft-delete category'} onClick={() => void remove(item)}>Delete</button></div></td>
                                        </tr>)}
                                    </tbody>
                                </table>
                            </div>
                            <div className="divide-y divide-slate-100 md:hidden">
                                {categories.map((item) => <article key={item.id} className="space-y-3 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2"><div className="min-w-0"><p className="font-semibold">{item.name}</p><p className="break-all text-xs text-slate-500">/{item.slug}</p></div><StatusPill status={item.status} /></div>
                                    <p className="text-xs text-slate-600">Parent: {item.parent_id === null ? 'Root' : byId.get(item.parent_id)?.name ?? `#${item.parent_id}`} · Order {item.sort_order} · {item.children_count} children</p>
                                    <div className="flex flex-wrap gap-2"><button type="button" className={secondary} onClick={() => startEdit(item)}>Edit</button><button type="button" className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700 disabled:opacity-40" disabled={deletingId !== null || busy || item.children_count > 0} onClick={() => void remove(item)}>Delete</button></div>
                                </article>)}
                            </div>
                            {!loading && !listError && categories.length === 0 && <p className="p-8 text-center text-sm text-slate-500">No matching categories. Create one or adjust your filters.</p>}
                            <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm">
                                <span className="text-slate-600">Page {meta.current_page} of {meta.last_page}</span>
                                <div className="flex gap-2"><button type="button" className={secondary} disabled={loading || page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Previous</button><button type="button" className={secondary} disabled={loading || page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</button></div>
                            </footer>
                        </section>
                        {editor && <aside className="min-w-0 self-start rounded-xl border border-teal-200 bg-white p-5 shadow-sm lg:sticky lg:top-5" aria-label="Category editor">
                            <div className="flex items-center justify-between gap-3"><h2 className="text-lg font-bold">{editor.kind === 'create' ? 'Create category' : 'Edit category'}</h2><button type="button" className={secondary} disabled={busy} onClick={() => setEditor(null)}>Close</button></div>
                            <form className="mt-5 space-y-4" onSubmit={(event) => void save(event)} noValidate>
                                <label className="block text-sm font-semibold text-slate-700">Name <span className="text-red-700">*</span>
                                    <input className={field} maxLength={180} required value={draft.name} onChange={(e) => {
                                        const name = e.target.value;
                                        setDraft((old) => ({ ...old, name, slug: editor.kind === 'create' && !slugTouched ? slugSuggestion(name) : old.slug }));
                                    }} />
                                    <FieldError errors={errors} name="name" />
                                </label>
                                <label className="block text-sm font-semibold text-slate-700">Slug <span className="text-red-700">*</span>
                                    <input className={field} required maxLength={160} readOnly={editor.kind === 'edit'} value={draft.slug} onChange={(e) => { setSlugTouched(true); setDraft((old) => ({ ...old, slug: e.target.value })); }} />
                                    <span className="mt-1 block text-xs font-normal text-slate-500">{editor.kind === 'edit' ? 'Locked to prevent broken URLs; a redirect manager will come later.' : 'Suggested from the English name; edit as needed. Slugs must be URL-safe.'}</span>
                                    <FieldError errors={errors} name="slug" />
                                </label>
                                <label className="block text-sm font-semibold text-slate-700">Parent category
                                    <select className={field} value={draft.parent_id} onChange={(e) => setDraft((old) => ({ ...old, parent_id: e.target.value }))}>
                                        <option value="">None — top-level category</option>
                                        {parentOptions.map((item) => <option key={item.id} value={String(item.id)}>{item.label}</option>)}
                                        {draft.parent_id && !parentOptions.some((item) => String(item.id) === draft.parent_id) && <option value={draft.parent_id}>Current parent unavailable — choose another</option>}
                                    </select>
                                    <FieldError errors={errors} name="parent_id" />
                                    {optionsError && <span role="alert" className="mt-1 block text-xs font-normal text-red-700">{optionsError}</span>}
                                    {options.length >= 200 && <span className="mt-1 block text-xs font-normal text-amber-700">Parent selector shows the first 200 active categories; manage large hierarchies in the next UX milestone.</span>}
                                </label>
                                <label className="block text-sm font-semibold text-slate-700">Summary
                                    <textarea className={field} rows={2} maxLength={500} value={draft.summary} onChange={(e) => setDraft((old) => ({ ...old, summary: e.target.value }))} />
                                    <FieldError errors={errors} name="summary" />
                                </label>
                                <label className="block text-sm font-semibold text-slate-700">Description
                                    <textarea className={field} rows={5} maxLength={20000} value={draft.description} onChange={(e) => setDraft((old) => ({ ...old, description: e.target.value }))} />
                                    <FieldError errors={errors} name="description" />
                                </label>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                    <label className="block text-sm font-semibold text-slate-700">Status
                                        <select className={field} value={draft.status} onChange={(e) => setDraft((old) => ({ ...old, status: e.target.value as WritableCategoryStatus }))}>
                                            {statuses.map((s) => <option key={s} value={s}>{labelStatus(s)}</option>)}
                                        </select><FieldError errors={errors} name="status" />
                                    </label>
                                    <label className="block text-sm font-semibold text-slate-700">Sort order
                                        <input className={field} type="number" min="0" max="1000000" step="1" value={draft.sort_order} onChange={(e) => setDraft((old) => ({ ...old, sort_order: e.target.value }))} />
                                        <FieldError errors={errors} name="sort_order" />
                                    </label>
                                </div>
                                {errors.category?.[0] && <p role="alert" className="text-sm text-red-700">{errors.category[0]}</p>}
                                <button type="submit" className={`${primary} w-full`} disabled={busy}>{busy ? 'Saving…' : editor.kind === 'create' ? 'Create category' : 'Save changes'}</button>
                            </form>
                        </aside>}
                    </div>
                </div>
            </BablysAdminLayout>
        </>
    );
}
