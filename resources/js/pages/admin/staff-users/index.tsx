import BablysAdminLayout from '@/layouts/bablys-admin-layout';
import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { confirmAction, toast } from '@/components/admin/feedback/admin-feedback';

import { describeStaffError, StaffApiError, staffRequest } from '@/components/admin/staff-users/client';
import type {
    PageMeta, RoleOption, StaffApiResponse, StaffListResponse,
    StaffStatusFilter, StaffUser,
} from '@/components/admin/staff-users/types';

interface Props {
    viewerId: number;
    roleOptions: RoleOption[];
}

type Drawer = { mode: 'create' } | { mode: 'edit'; user: StaffUser } | null;
type StaffDraft = { name: string; email: string; role_keys: string[] };

const API = '/admin/internal/staff-users';
const emptyDraft: StaffDraft = { name: '', email: '', role_keys: [] };
const emptyMeta: PageMeta = { current_page: 1, last_page: 1, per_page: 15, total: 0 };
const inputClass = 'w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-teal-600 focus:ring-2 focus:ring-teal-100';
const secondaryButton = 'inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50';
const primaryButton = 'inline-flex items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50';

function FieldError({ errors, name }: { errors: Record<string, string[]>; name: string }) {
    const message = errors[name]?.[0];
    return message ? <p className="mt-1 text-xs text-red-700" role="alert">{message}</p> : null;
}

function StatusLabel({ user }: { user: StaffUser }) {
    if (user.role_keys.length === 0) {
        return <span className="rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600">Customer</span>;
    }
    if (!user.cms_staff_enabled) {
        return <span className="rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-800">Suspended</span>;
    }
    if (!user.email_verified) {
        return <span className="rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800">Pending verification</span>;
    }
    return <span className="rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-800">Active staff</span>;
}

export default function CmsStaffUsersIndex({ viewerId, roleOptions }: Props) {
    const [search, setSearch] = useState('');
    const [role, setRole] = useState('');
    const [status, setStatus] = useState<StaffStatusFilter>('all');
    const [perPage, setPerPage] = useState(15);
    const [page, setPage] = useState(1);
    const [users, setUsers] = useState<StaffUser[]>([]);
    const [meta, setMeta] = useState<PageMeta>(emptyMeta);
    const [loading, setLoading] = useState(true);
    const [listError, setListError] = useState('');
    const [drawer, setDrawer] = useState<Drawer>(null);
    const [draft, setDraft] = useState<StaffDraft>(emptyDraft);
    const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [pendingId, setPendingId] = useState<number | null>(null);

    const roleNames = useMemo(() => new Map(roleOptions.map((item) => [item.key, item.name])), [roleOptions]);
    const query = useMemo(() => {
        const params = new URLSearchParams({ page: String(page), per_page: String(perPage), status });
        if (search.trim()) params.set('search', search.trim());
        if (role) params.set('role', role);
        return `${API}?${params.toString()}`;
    }, [page, perPage, role, search, status]);

    const loadUsers = useCallback(async (signal?: AbortSignal) => {
        setLoading(true);
        setListError('');
        try {
            const result = await staffRequest<StaffListResponse>(query, { signal });
            if (signal?.aborted) return;
            if (result.meta.current_page > result.meta.last_page) {
                setPage(Math.max(1, result.meta.last_page));
                return;
            }
            setUsers(result.data);
            setMeta(result.meta);
        } catch (error) {
            if (signal?.aborted) return;
            // Fail closed: do not leave previously loaded account details visible after denial.
            setUsers([]);
            setMeta(emptyMeta);
            setListError(describeStaffError(error));
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [query]);

    useEffect(() => {
        const controller = new AbortController();
        // Debounce search changes; cancel stale responses when filters change.
        const timer = window.setTimeout(() => void loadUsers(controller.signal), 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [loadUsers]);

    useEffect(() => {
        if (!drawer) return;
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !busy) setDrawer(null);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [drawer, busy]);

    const editable = (user: StaffUser) => user.id !== viewerId && !user.role_keys.includes('super_admin');
    const canInvite = (user: StaffUser) => editable(user) && user.role_keys.length > 0 && !user.email_verified && user.cms_staff_enabled;

    function openCreate() {
        setDraft({ ...emptyDraft, role_keys: [] });
        setFormErrors({});
        
        setDrawer({ mode: 'create' });
    }

    function openEdit(user: StaffUser) {
        if (!editable(user)) return;
        setDraft({ name: user.name, email: user.email, role_keys: [...user.role_keys] });
        setFormErrors({});
        
        setDrawer({ mode: 'edit', user });
    }

    function toggleDraftRole(key: string) {
        setDraft((current) => ({
            ...current,
            role_keys: current.role_keys.includes(key)
                ? current.role_keys.filter((candidate) => candidate !== key)
                : [...current.role_keys, key],
        }));
    }

    async function submitProfile(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!drawer || busy) return;
        setBusy(true);
        setFormErrors({});
        
        try {
            if (drawer.mode === 'create') {
                const result = await staffRequest<StaffApiResponse>(API, {
                    method: 'POST',
                    body: { name: draft.name.trim(), email: draft.email.trim(), role_keys: draft.role_keys },
                });
                toast.success(`${result.data.name} created as pending staff. Use “Send setup link” to invite them.`);
            } else {
                const result = await staffRequest<StaffApiResponse>(`${API}/${drawer.user.id}`, {
                    method: 'PUT',
                    body: { name: draft.name.trim(), email: draft.email.trim() },
                });
                toast.success(`${result.data.name}'s profile was updated.`);
            }
            setDrawer(null);
            await loadUsers();
        } catch (error) {
            if (error instanceof StaffApiError) setFormErrors(error.fields);
            toast.error(describeStaffError(error));
        } finally {
            setBusy(false);
        }
    }

    async function saveRoles() {
        if (!drawer || drawer.mode !== 'edit' || busy) return;
        const user = drawer.user;
        if (draft.role_keys.length === 0 && !(await confirmAction({ title: 'Remove all CMS roles?', description: `Remove all CMS roles from ${user.name}? This will revoke CMS access.`, confirmLabel: 'Remove roles', tone: 'danger' }))) return;
        setBusy(true);
        setFormErrors({});
        
        try {
            const result = await staffRequest<StaffApiResponse>(`${API}/${user.id}/roles`, {
                method: 'PUT', body: { role_keys: draft.role_keys },
            });
            toast.success(`Roles updated for ${result.data.name}.`);
            setDrawer({ mode: 'edit', user: result.data });
            await loadUsers();
        } catch (error) {
            if (error instanceof StaffApiError) setFormErrors(error.fields);
            toast.error(describeStaffError(error));
        } finally {
            setBusy(false);
        }
    }

    async function updateAccess(user: StaffUser) {
        if (!editable(user) || user.role_keys.length === 0 || pendingId !== null) return;
        const enabled = !user.cms_staff_enabled;
        const action = enabled ? 'restore' : 'suspend';
        if (!(await confirmAction({ title: `${enabled ? 'Restore' : 'Suspend'} CMS access?`, description: `Are you sure you want to ${action} CMS access for ${user.name}?`, confirmLabel: enabled ? 'Restore access' : 'Suspend access', tone: enabled ? 'normal' : 'danger' }))) return;
        setPendingId(user.id);
        
        try {
            await staffRequest<StaffApiResponse>(`${API}/${user.id}/access`, {
                method: 'PATCH', body: { enabled },
            });
            toast.success(`${user.name}'s CMS access was ${enabled ? 'restored' : 'suspended'}.`);
            await loadUsers();
        } catch (error) {
            toast.error(describeStaffError(error));
        } finally {
            setPendingId(null);
        }
    }

    async function sendSetupLink(user: StaffUser) {
        if (!canInvite(user) || pendingId !== null) return;
        if (!(await confirmAction({ title: 'Send staff setup link?', description: `Send a one-time password setup link to ${user.email}? Ensure the mail configuration is correct.`, confirmLabel: 'Send setup link', tone: 'warning' }))) return;
        setPendingId(user.id);
        
        try {
            const result = await staffRequest<{ message: string }>(`${API}/${user.id}/setup-link`, { method: 'POST', body: {} });
            toast.success(result.message);
        } catch (error) {
            toast.error(describeStaffError(error));
        } finally {
            setPendingId(null);
        }
    }

    return (
        <>
            <Head title="Staff users · Bablys CMS" />
            <BablysAdminLayout>
                <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0">
                            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700">Bablys Tours & Travels / CMS</p>
                            <h1 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Staff & user management</h1>
                            <p className="mt-2 max-w-2xl text-sm text-slate-600">Manage staff roles and CMS access. All changes are authorized by Laravel on the server.</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href="/admin" className={secondaryButton}>Admin home</Link>
                            <button type="button" className={primaryButton} onClick={openCreate}>+ Create staff</button>
                        </div>
                    </header>

                    <section className="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" aria-label="Security guidance">
                        New staff receive no shared password. Create a pending account, then send a password setup link. They must also verify their email before receiving CMS access. Super Administrator accounts cannot be modified here.
                    </section>

                    <section className="mt-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-label="User filters">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <label className="block min-w-0 text-sm font-semibold">
                                Search
                                <input className={`${inputClass} mt-1`} type="search" placeholder="Name or email" value={search} maxLength={100}
                                    onChange={(event) => { setPage(1); setSearch(event.target.value); }} />
                            </label>
                            <label className="block min-w-0 text-sm font-semibold">
                                Role
                                <select className={`${inputClass} mt-1`} value={role} onChange={(event) => { setPage(1); setRole(event.target.value); }}>
                                    <option value="">All roles</option>
                                    <option value="super_admin">Super Administrator</option>
                                    {roleOptions.map((item) => <option key={item.key} value={item.key}>{item.name}</option>)}
                                </select>
                            </label>
                            <label className="block min-w-0 text-sm font-semibold">
                                Account status
                                <select className={`${inputClass} mt-1`} value={status} onChange={(event) => { setPage(1); setStatus(event.target.value as StaffStatusFilter); }}>
                                    <option value="all">All accounts</option>
                                    <option value="active">Active staff</option>
                                    <option value="pending">Pending verification</option>
                                    <option value="disabled">CMS suspended</option>
                                    <option value="customer">Customers (no CMS role)</option>
                                </select>
                            </label>
                            <label className="block min-w-0 text-sm font-semibold">
                                Per page
                                <select className={`${inputClass} mt-1`} value={perPage} onChange={(event) => { setPage(1); setPerPage(Number(event.target.value)); }}>
                                    {[10, 15, 25, 50].map((count) => <option key={count} value={count}>{count}</option>)}
                                </select>
                            </label>
                        </div>
                    </section>

                    <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-label="User records">
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-4 sm:px-5">
                            <h2 className="font-semibold">Accounts <span className="ml-1 text-sm font-normal text-slate-500">({meta.total})</span></h2>
                            <button type="button" className={secondaryButton} disabled={loading} onClick={() => void loadUsers()}>Refresh</button>
                        </div>
                        {listError && <p className="m-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{listError}</p>}
                        {loading && <p className="p-6 text-sm text-slate-500" role="status">Loading accounts…</p>}
                        {!loading && users.length === 0 && !listError && <p className="p-8 text-center text-sm text-slate-500">No matching accounts.</p>}
                        {!loading && users.length > 0 && (
                            <>
                                <div className="hidden overflow-x-auto md:block">
                                    <table className="w-full min-w-[720px] text-left text-sm">
                                        <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                            <tr><th scope="col" className="px-5 py-3">User</th><th scope="col" className="px-5 py-3">Roles</th><th scope="col" className="px-5 py-3">Status</th><th scope="col" className="px-5 py-3">Actions</th></tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {users.map((user) => (
                                                <tr key={user.id}>
                                                    <td className="px-5 py-4"><p className="font-semibold">{user.name}</p><p className="max-w-[270px] break-all text-xs text-slate-500">{user.email}</p></td>
                                                    <td className="px-5 py-4"><RoleLabels user={user} names={roleNames} /></td>
                                                    <td className="px-5 py-4"><StatusLabel user={user} /></td>
                                                    <td className="px-5 py-4"><UserActions user={user} editable={editable(user)} canInvite={canInvite(user)} busy={pendingId !== null} onEdit={() => openEdit(user)} onAccess={() => void updateAccess(user)} onInvite={() => void sendSetupLink(user)} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="divide-y divide-slate-100 md:hidden">
                                    {users.map((user) => (
                                        <article key={user.id} className="space-y-3 p-4">
                                            <div><p className="font-semibold">{user.name}</p><p className="break-all text-xs text-slate-500">{user.email}</p></div>
                                            <RoleLabels user={user} names={roleNames} />
                                            <StatusLabel user={user} />
                                            <UserActions user={user} editable={editable(user)} canInvite={canInvite(user)} busy={pendingId !== null} onEdit={() => openEdit(user)} onAccess={() => void updateAccess(user)} onInvite={() => void sendSetupLink(user)} />
                                        </article>
                                    ))}
                                </div>
                            </>
                        )}
                        <nav aria-label="User pagination" className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 sm:px-5">
                            <p className="text-xs text-slate-600">Page {meta.current_page} of {meta.last_page} · {meta.total} total</p>
                            <div className="flex gap-2">
                                <button className={secondaryButton} type="button" disabled={loading || page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>Previous</button>
                                <button className={secondaryButton} type="button" disabled={loading || page >= meta.last_page} onClick={() => setPage((current) => current + 1)}>Next</button>
                            </div>
                        </nav>
                    </section>
                    <p className="mt-5 text-xs text-slate-500">P2B.3B · Staff management UI · Sensitive changes are server-validated. Activity logging will be added in P2B.5.</p>
                </div>
            </BablysAdminLayout>

            {drawer && (
                <div className="fixed inset-0 z-50 flex items-start justify-end bg-slate-950/50" role="presentation">
                    <section role="dialog" aria-modal="true" aria-labelledby="staff-dialog-title" className="flex h-full w-full max-w-lg flex-col overflow-y-auto bg-white p-5 shadow-xl sm:p-7">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-bold uppercase tracking-widest text-teal-700">Bablys CMS</p>
                                <h2 id="staff-dialog-title" className="mt-1 text-xl font-bold">{drawer.mode === 'create' ? 'Create staff account' : `Edit ${drawer.user.name}`}</h2>
                            </div>
                            <button type="button" className={secondaryButton} disabled={busy} aria-label="Close user editor" onClick={() => setDrawer(null)}>Close</button>
                        </div>
                        <form className="mt-6 space-y-4" onSubmit={(event) => void submitProfile(event)}>
                            <div>
                                <label className="block text-sm font-semibold" htmlFor="staff-name">Full name</label>
                                <input id="staff-name" className={`${inputClass} mt-1`} required minLength={2} maxLength={255} autoFocus value={draft.name} onChange={(event) => setDraft((item) => ({ ...item, name: event.target.value }))} />
                                <FieldError errors={formErrors} name="name" />
                            </div>
                            <div>
                                <label className="block text-sm font-semibold" htmlFor="staff-email">Email address</label>
                                <input id="staff-email" className={`${inputClass} mt-1`} type="email" required maxLength={255} value={draft.email} onChange={(event) => setDraft((item) => ({ ...item, email: event.target.value }))} />
                                <FieldError errors={formErrors} name="email" />
                                {drawer.mode === 'edit' && <p className="mt-1 text-xs text-slate-500">Changing an email requires the new address to be verified before CMS access.</p>}
                            </div>
                            {drawer.mode === 'create' && (
                                <div>
                                    <p className="text-sm font-semibold">Initial staff roles <span className="text-red-700">*</span></p>
                                    <p className="my-2 text-xs text-slate-500">Select at least one; Super Administrator cannot be assigned here.</p>
                                    <RoleCheckboxes options={roleOptions} selected={draft.role_keys} onToggle={toggleDraftRole} />
                                    <FieldError errors={formErrors} name="role_keys" />
                                </div>
                            )}
                            <button type="submit" className={primaryButton} disabled={busy || (drawer.mode === 'create' && draft.role_keys.length === 0)}>
                                {busy ? 'Saving…' : drawer.mode === 'create' ? 'Create pending staff' : 'Save profile'}
                            </button>
                        </form>
                        {drawer.mode === 'edit' && (
                            <div className="mt-8 border-t border-slate-200 pt-6">
                                <h3 className="text-base font-bold">CMS roles</h3>
                                <p className="mt-1 text-xs text-slate-500">Saving an empty role selection converts this account to a regular customer.</p>
                                <div className="mt-3"><RoleCheckboxes options={roleOptions} selected={draft.role_keys} onToggle={toggleDraftRole} /></div>
                                <FieldError errors={formErrors} name="role_keys" />
                                <FieldError errors={formErrors} name="user" />
                                <button type="button" className={`${secondaryButton} mt-4`} disabled={busy} onClick={() => void saveRoles()}>Save roles</button>
                            </div>
                        )}
                        <p className="mt-8 border-t border-slate-200 pt-4 text-xs text-slate-500">No passwords are collected here. Password setup uses a separate emailed link.</p>
                    </section>
                </div>
            )}
        </>
    );
}

function RoleLabels({ user, names }: { user: StaffUser; names: Map<string, string> }) {
    if (user.role_keys.length === 0) return <span className="text-xs text-slate-400">No CMS roles</span>;
    return (
        <div className="flex flex-wrap gap-1.5">
            {user.role_keys.map((key) => <span key={key} className="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-700">{names.get(key) ?? (key === 'super_admin' ? 'Super Administrator' : key)}</span>)}
        </div>
    );
}

function RoleCheckboxes({ options, selected, onToggle }: { options: RoleOption[]; selected: string[]; onToggle: (key: string) => void }) {
    return (
        <div className="grid gap-2">
            {options.map((option) => (
                <label key={option.key} className="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">
                    <input className="mt-1 size-4 accent-teal-700" type="checkbox" checked={selected.includes(option.key)} onChange={() => onToggle(option.key)} />
                    <span className="min-w-0"><span className="block text-sm font-semibold">{option.name}</span><span className="block text-xs leading-relaxed text-slate-500">{option.description}</span></span>
                </label>
            ))}
        </div>
    );
}

function UserActions({ user, editable, canInvite, busy, onEdit, onAccess, onInvite }: {
    user: StaffUser; editable: boolean; canInvite: boolean; busy: boolean;
    onEdit: () => void; onAccess: () => void; onInvite: () => void;
}) {
    if (!editable) return <span className="text-xs text-slate-500">Protected</span>;
    return (
        <div className="flex flex-wrap gap-2">
            <button type="button" className={secondaryButton} disabled={busy} onClick={onEdit}>Edit</button>
            {canInvite && <button type="button" className={secondaryButton} disabled={busy} onClick={onInvite}>Send setup link</button>}
            {user.role_keys.length > 0 && (
                <button type="button" className={secondaryButton} disabled={busy} onClick={onAccess}>
                    {user.cms_staff_enabled ? 'Suspend CMS' : 'Restore CMS'}
                </button>
            )}
        </div>
    );
}
