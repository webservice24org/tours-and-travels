import BablysAdminLayout from '@/layouts/bablys-admin-layout';
import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';

import { CmsRoleApiError, roleErrorMessage, roleRequest } from '@/components/admin/roles/client';
import type {
    CatalogResponse, CmsRole, PaginationMeta, PermissionDefinition,
    RoleListResponse, RoleResponse, RoleTypeFilter,
} from '@/components/admin/roles/types';

const API = '/admin/internal/cms-roles';
const emptyMeta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };
const input = 'mt-1 block w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-100';
const primary = 'inline-flex items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50';
const secondary = 'inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50';
type Draft = { key: string; name: string; description: string; permission_keys: string[] };
type Drawer = { mode: 'create' } | { mode: 'edit'; role: CmsRole } | null;
const newDraft = (): Draft => ({ key: 'custom_', name: '', description: '', permission_keys: [] });

function FieldError({ errors, name }: { errors: Record<string, string[]>; name: string }) {
    const message = errors[name]?.[0];
    return message ? <p role="alert" className="mt-1 text-xs text-red-700">{message}</p> : null;
}

function PermissionPicker({ catalog, selected, disabled, onChange }: {
    catalog: PermissionDefinition[]; selected: string[]; disabled?: boolean;
    onChange: (next: string[]) => void;
}) {
    const modules = useMemo(() => {
        const groups = new Map<string, PermissionDefinition[]>();
        for (const permission of catalog) {
            groups.set(permission.module, [...(groups.get(permission.module) ?? []), permission]);
        }
        return [...groups.entries()];
    }, [catalog]);
    const selectedSet = useMemo(() => new Set(selected), [selected]);

    return (
        <div className="grid gap-3 sm:grid-cols-2" aria-label="Permission groups">
            {modules.map(([module, permissions]) => {
                const allowed = permissions.filter((item) => item.assignable);
                const checkedAll = allowed.length > 0 && allowed.every((item) => selectedSet.has(item.key));
                return (
                    <fieldset key={module} className="min-w-0 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <legend className="px-1 text-xs font-bold uppercase tracking-wider text-teal-800">{module.replaceAll('.', ' / ')}</legend>
                        {!disabled && allowed.length > 0 && (
                            <label className="mb-2 flex cursor-pointer items-center gap-2 border-b border-slate-200 pb-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" className="size-4 accent-teal-700" checked={checkedAll}
                                    onChange={(event) => {
                                        const keys = new Set(selected);
                                        for (const permission of allowed) {
                                            if (event.target.checked) keys.add(permission.key);
                                            else keys.delete(permission.key);
                                        }
                                        onChange([...keys]);
                                    }} />
                                Select all assignable
                            </label>
                        )}
                        <div className="space-y-2">
                            {permissions.map((permission) => {
                                const isLocked = disabled || !permission.assignable;
                                return (
                                    <label key={permission.key} className={`flex min-w-0 items-start gap-2 text-sm ${isLocked ? 'cursor-not-allowed text-slate-500' : 'cursor-pointer text-slate-800'}`}>
                                        <input type="checkbox" className="mt-1 size-4 shrink-0 accent-teal-700"
                                            disabled={isLocked} checked={selectedSet.has(permission.key)}
                                            onChange={(event) => onChange(event.target.checked
                                                ? [...selected, permission.key]
                                                : selected.filter((key) => key !== permission.key))} />
                                        <span className="min-w-0">
                                            <span className="block break-words font-semibold">{permission.label}</span>
                                            <span className="block break-all text-[11px] text-slate-500">{permission.key}</span>
                                            {!permission.assignable && !disabled && (
                                                <span className="block text-[11px] text-amber-800">Owner-only; cannot grant to custom roles</span>
                                            )}
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>
                );
            })}
            {modules.length === 0 && <p className="text-sm text-slate-600">No permission catalog loaded.</p>}
        </div>
    );
}

export default function CmsRolesIndex() {
    const [search, setSearch] = useState('');
    const [type, setType] = useState<RoleTypeFilter>('all');
    const [page, setPage] = useState(1);
    const [roles, setRoles] = useState<CmsRole[]>([]);
    const [meta, setMeta] = useState<PaginationMeta>(emptyMeta);
    const [catalog, setCatalog] = useState<PermissionDefinition[]>([]);
    const [loading, setLoading] = useState(true);
    const [listError, setListError] = useState('');
    const [drawer, setDrawer] = useState<Drawer>(null);
    const [draft, setDraft] = useState<Draft>(newDraft);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);

    const query = useMemo(() => {
        const params = new URLSearchParams({ page: String(page), type, per_page: '20' });
        if (search.trim()) params.set('search', search.trim());
        return `${API}?${params.toString()}`;
    }, [page, search, type]);

    const refresh = useCallback(async (signal?: AbortSignal) => {
        setLoading(true);
        setListError('');
        try {
            const result = await roleRequest<RoleListResponse>(query, { signal });
            if (signal?.aborted) return;
            if (result.meta.current_page > result.meta.last_page) {
                setPage(Math.max(1, result.meta.last_page));
                return;
            }
            setRoles(result.data);
            setMeta(result.meta);
        } catch (error) {
            if (signal?.aborted) return;
            setRoles([]); setMeta(emptyMeta);
            setListError(roleErrorMessage(error));
        } finally { if (!signal?.aborted) setLoading(false); }
    }, [query]);

    useEffect(() => {
        const abort = new AbortController();
        const timer = window.setTimeout(() => void refresh(abort.signal), 250);
        return () => { window.clearTimeout(timer); abort.abort(); };
    }, [refresh]);

    useEffect(() => {
        const abort = new AbortController();
        void roleRequest<CatalogResponse>(`${API}/catalog`, { signal: abort.signal })
            .then((data) => { if (!abort.signal.aborted) setCatalog(data.data); })
            .catch((error: unknown) => { if (!abort.signal.aborted) setListError(roleErrorMessage(error)); });
        return () => abort.abort();
    }, []);

    useEffect(() => {
        if (!drawer) return;
        const handler = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !busy) setDrawer(null);
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [busy, drawer]);

    function openCreate() {
        setErrors({});  setDraft(newDraft()); setDrawer({ mode: 'create' });
    }
    function openEdit(role: CmsRole) {
        if (!role.can_edit || role.is_system) return;
        setErrors({}); 
        setDraft({ key: role.key, name: role.name, description: role.description ?? '', permission_keys: [...role.permission_keys] });
        setDrawer({ mode: 'edit', role });
    }
    function errorFrom(error: unknown) {
        if (error instanceof CmsRoleApiError) setErrors(error.fields);
        toast.error(roleErrorMessage(error));
    }

    async function submitDetails(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!drawer || busy) return;
        setBusy(true);  setErrors({});
        try {
            if (drawer.mode === 'create') {
                const result = await roleRequest<RoleResponse>(API, {
                    method: 'POST',
                    body: { key: draft.key.trim(), name: draft.name.trim(), description: draft.description.trim() || null,
                        permission_keys: draft.permission_keys },
                });
                toast.success(`${result.data.name} created. You can assign it from Staff Users.`);
                setType('custom'); setPage(1); setSearch('');
            } else {
                const result = await roleRequest<RoleResponse>(`${API}/${drawer.role.id}`, {
                    method: 'PUT', body: { name: draft.name.trim(), description: draft.description.trim() || null },
                });
                toast.success(`${result.data.name} updated.`);
            }
            setDrawer(null);
            await refresh();
        } catch (error) { errorFrom(error); }
        finally { setBusy(false); }
    }

    async function savePermissions() {
        if (!drawer || drawer.mode !== 'edit' || busy) return;
        if (!draft.permission_keys.length && !(await confirmAction({ title: 'Remove all permissions?', description: `Remove all permissions from ${drawer.role.name}? Assigned users will lose those capabilities immediately.`, confirmLabel: 'Remove permissions', tone: 'danger' }))) return;
        setBusy(true);  setErrors({});
        try {
            const result = await roleRequest<RoleResponse>(`${API}/${drawer.role.id}/permissions`, {
                method: 'PUT', body: { permission_keys: draft.permission_keys },
            });
            setDrawer({ mode: 'edit', role: result.data });
            toast.success(`Permissions updated for ${result.data.name}.`);
            await refresh();
        } catch (error) { errorFrom(error); }
        finally { setBusy(false); }
    }

    async function deleteRole(role: CmsRole) {
        if (!role.can_edit || role.is_system || role.user_count > 0 || deletingId !== null) return;
        if (!(await confirmAction({ title: 'Delete custom role?', description: `Delete ${role.name} (${role.key})? This cannot be undone.`, confirmLabel: 'Delete role', tone: 'danger' }))) return;
        setDeletingId(role.id); 
        try {
            await roleRequest<void>(`${API}/${role.id}`, { method: 'DELETE' });
            toast.success(`${role.name} deleted.`);
            await refresh();
        } catch (error) { toast.error(roleErrorMessage(error)); }
        finally { setDeletingId(null); }
    }

    return (
        <>
            <Head title="Roles & permissions · Bablys CMS" />
            <BablysAdminLayout>
                <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0">
                            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700">Bablys Tours & Travels / CMS</p>
                            <h1 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Roles & permissions</h1>
                            <p className="mt-2 max-w-2xl text-sm text-slate-600">Create custom roles and assign approved capabilities. System roles are protected and read-only.</p>
                        </div>
                        <nav className="flex flex-wrap items-center gap-2" aria-label="CMS administration">
                            <Link href="/admin" className={secondary}>Admin home</Link>
                            <Link href="/admin/staff-users" className={secondary}>Staff users</Link>
                            <button className={primary} type="button" onClick={openCreate}>+ Custom role</button>
                        </nav>
                    </header>

                    <section aria-label="Permission safeguards" className="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        System roles cannot be edited here. Owner-only grants (role management, global settings and audit access) cannot be assigned to custom roles. Changes to permissions take effect at the next authorization check.
                    </section>

                    <section className="mt-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-label="Role filters">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="min-w-0 text-sm font-semibold">Search roles
                                <input className={input} type="search" placeholder="Name or role key" value={search} maxLength={100}
                                    onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
                            </label>
                            <label className="min-w-0 text-sm font-semibold">Role type
                                <select className={input} value={type} onChange={(event) => { setType(event.target.value as RoleTypeFilter); setPage(1); }}>
                                    <option value="all">All roles</option>
                                    <option value="system">System roles</option>
                                    <option value="custom">Custom roles</option>
                                </select>
                            </label>
                        </div>
                    </section>
                    <section className="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-label="CMS roles">
                        {listError && <p className="p-4 text-sm text-red-700" role="alert">{listError}</p>}
                        {loading ? <p className="p-6 text-sm text-slate-600">Loading roles…</p> : roles.length === 0 ?
                            <p className="p-6 text-sm text-slate-600">No matching roles.</p> : (
                                <div className="grid gap-px bg-slate-100 md:grid-cols-2">
                                    {roles.map((role) => (
                                        <article key={role.id} className="min-w-0 space-y-3 bg-white p-5">
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <h2 className="break-words text-base font-bold">{role.name}</h2>
                                                    <p className="break-all font-mono text-xs text-slate-500">{role.key}</p>
                                                </div>
                                                <span className={`rounded-md px-2 py-1 text-xs font-semibold ${role.is_system ? 'bg-slate-100 text-slate-700' : 'bg-teal-50 text-teal-800'}`}>{role.is_system ? 'System · read-only' : 'Custom'}</span>
                                            </div>
                                            <p className="min-h-8 text-sm text-slate-600">{role.description || 'No description'}</p>
                                            <div className="flex flex-wrap gap-2 text-xs text-slate-600">
                                                <span className="rounded-md bg-slate-100 px-2 py-1">{role.user_count} assigned users</span>
                                                <span className="rounded-md bg-slate-100 px-2 py-1">{role.permission_keys.length} permissions</span>
                                            </div>
                                            <div className="flex flex-wrap gap-1.5" aria-label="Permissions">
                                                {role.permission_keys.slice(0, 7).map((key) => <span key={key} className="max-w-full break-all rounded-md bg-slate-50 px-2 py-1 font-mono text-[11px] text-slate-600">{key}</span>)}
                                                {role.permission_keys.length > 7 && <span className="rounded-md bg-slate-50 px-2 py-1 text-xs">+{role.permission_keys.length - 7} more</span>}
                                            </div>
                                            <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                                                {role.can_edit && !role.is_system ? <>
                                                    <button className={secondary} type="button" onClick={() => openEdit(role)}>Edit role</button>
                                                    <button className={secondary} type="button" disabled={role.user_count > 0 || deletingId !== null} onClick={() => void deleteRole(role)}>
                                                        {deletingId === role.id ? 'Deleting…' : 'Delete'}
                                                    </button>
                                                </> : <p className="text-xs text-slate-500">Protected system role</p>}
                                            </div>
                                            {role.user_count > 0 && role.can_edit && <p className="text-xs text-amber-800">Reassign users before deleting this role.</p>}
                                        </article>
                                    ))}
                                </div>
                            )}
                        <nav aria-label="Role pagination" className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4">
                            <p className="text-xs text-slate-600">Page {meta.current_page} of {meta.last_page} · {meta.total} roles</p>
                            <div className="flex gap-2">
                                <button type="button" className={secondary} disabled={loading || page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Previous</button>
                                <button type="button" className={secondary} disabled={loading || page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</button>
                            </div>
                        </nav>
                    </section>
                    <p className="mt-5 text-xs text-slate-500">P2B.4B · Role UI and staff role integration · Audit logging follows in P2B.5.</p>
                </div>
            </BablysAdminLayout>

            {drawer && <div className="fixed inset-0 z-50 flex justify-end bg-slate-950/50" role="presentation">
                <section role="dialog" aria-modal="true" aria-labelledby="role-dialog-title" className="flex h-full w-full max-w-3xl flex-col overflow-y-auto bg-white p-4 shadow-xl sm:p-7">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-teal-700">Bablys CMS</p>
                            <h2 id="role-dialog-title" className="mt-1 text-xl font-bold">{drawer.mode === 'create' ? 'Create custom role' : `Edit ${drawer.role.name}`}</h2>
                        </div>
                        <button type="button" className={secondary} disabled={busy} aria-label="Close role editor" onClick={() => setDrawer(null)}>Close</button>
                    </div>
                    <form className="mt-6 space-y-4" onSubmit={(event) => void submitDetails(event)}>
                        <div>
                            <label htmlFor="role-name" className="text-sm font-semibold">Role name</label>
                            <input id="role-name" className={input} required minLength={2} maxLength={150} autoFocus value={draft.name}
                                onChange={(event) => setDraft((item) => ({ ...item, name: event.target.value }))} />
                            <FieldError errors={errors} name="name" />
                        </div>
                        <div>
                            <label htmlFor="role-key" className="text-sm font-semibold">Role key</label>
                            <input id="role-key" className={input} required disabled={drawer.mode === 'edit'} maxLength={60}
                                pattern="custom_[a-z][a-z0-9_]{2,48}" value={draft.key}
                                onChange={(event) => setDraft((item) => ({ ...item, key: event.target.value }))} />
                            <p className="mt-1 text-xs text-slate-500">Use lowercase letters, numbers and underscores; must begin with custom_. The key cannot change after creation.</p>
                            <FieldError errors={errors} name="key" />
                        </div>
                        <div>
                            <label htmlFor="role-description" className="text-sm font-semibold">Description</label>
                            <textarea id="role-description" className={input} maxLength={2000} rows={2} value={draft.description}
                                onChange={(event) => setDraft((item) => ({ ...item, description: event.target.value }))} />
                            <FieldError errors={errors} name="description" />
                        </div>
                        {drawer.mode === 'create' && <>
                            <h3 className="text-base font-bold">Initial permissions</h3>
                            <PermissionPicker catalog={catalog} selected={draft.permission_keys}
                                onChange={(next) => setDraft((item) => ({ ...item, permission_keys: next }))} />
                            <FieldError errors={errors} name="permission_keys" />
                        </>}
                        <button className={primary} type="submit" disabled={busy || (drawer.mode === 'create' && catalog.length === 0)}>
                            {busy ? 'Saving…' : drawer.mode === 'create' ? 'Create custom role' : 'Save role details'}
                        </button>
                    </form>
                    {drawer.mode === 'edit' && <div className="mt-7 border-t border-slate-200 pt-6">
                        <h3 className="text-base font-bold">Role permissions</h3>
                        <p className="mt-1 mb-4 text-xs text-slate-600">Editing permissions changes authorization for every assigned user. Save explicitly.</p>
                        <PermissionPicker catalog={catalog} selected={draft.permission_keys}
                            onChange={(next) => setDraft((item) => ({ ...item, permission_keys: next }))} />
                        <FieldError errors={errors} name="permission_keys" />
                        <FieldError errors={errors} name="role" />
                        <button type="button" className={`${primary} mt-4`} disabled={busy || catalog.length === 0} onClick={() => void savePermissions()}>
                            {busy ? 'Saving…' : 'Save permissions'}
                        </button>
                    </div>}
                    <p className="mt-7 border-t border-slate-200 pt-4 text-xs text-slate-500">All changes are validated server-side. Owner-only permissions cannot be delegated through this screen.</p>
                </section>
            </div>}
        </>
    );
}
