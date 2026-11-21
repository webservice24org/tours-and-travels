import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';

import BablysAdminLayout from '@/layouts/bablys-admin-layout';

type MenuItem = {
    id: string;
    label: string;
    kind: 'internal' | 'external';
    url: string;
    parent_id: string | null;
    new_tab: boolean;
};
type Menu = {
    id: number;
    key: string;
    name: string;
    draft_items: MenuItem[];
    draft_version: number;
    published_version: number;
    updated_at: string | null;
    can_publish: false;
};
type Props = { menu: Menu };
const inputClass = 'mt-1 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-700';
const smallButton = 'min-h-11 rounded-lg border border-slate-300 px-3 text-xs font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-700';

function depthOf(item: MenuItem, all: MenuItem[]): number {
    let depth = 0;
    let parentId = item.parent_id;
    const visited = new Set<string>([item.id]);
    while (parentId && depth < 4) {
        if (visited.has(parentId)) break;
        visited.add(parentId);
        const parent = all.find(entry => entry.id === parentId);
        if (!parent) break;
        depth += 1;
        parentId = parent.parent_id;
    }
    return depth;
}

export default function NavigationMenusShow({ menu }: Props) {
    const [name, setName] = useState(menu.name);
    const [items, setItems] = useState<MenuItem[]>(menu.draft_items);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [deleting, setDeleting] = useState(false);
    useEffect(() => { setName(menu.name); setItems(menu.draft_items); setErrors({}); }, [menu]);

    function change(id: string, key: keyof MenuItem, value: string | boolean | null) {
        setItems(prev => prev.map(item => {
            if (item.id !== id) return item;
            if (key === 'kind') {
                const nextKind = value === 'external' ? 'external' : 'internal';
                return { ...item, kind: nextKind, url: nextKind === 'internal' ? '/' : 'https://', new_tab: nextKind === 'external' };
            }
            return { ...item, [key]: value } as MenuItem;
        }));
    }

    function add(parentId: string | null = null) {
        if (items.length >= 80) return;
        const item: MenuItem = { id: crypto.randomUUID(), label: '', kind: 'internal', url: '/', parent_id: parentId, new_tab: false };
        setItems(prev => [...prev, item]);
        setSaved(false);
    }

    function remove(id: string) {
        const removed = new Set<string>([id]);
        let changed = true;
        while (changed) {
            changed = false;
            for (const item of items) {
                if (item.parent_id && removed.has(item.parent_id) && !removed.has(item.id)) {
                    removed.add(item.id);
                    changed = true;
                }
            }
        }
        setItems(prev => prev.filter(item => !removed.has(item.id)));
        setSaved(false);
    }

    function move(id: string, direction: -1 | 1) {
        setItems(prev => {
            const index = prev.findIndex(item => item.id === id);
            if (index < 0) return prev;
            const siblingIndexes = prev.map((item, i) => item.parent_id === prev[index].parent_id ? i : -1).filter(i => i >= 0);
            const siblingPosition = siblingIndexes.indexOf(index);
            const targetIndex = siblingIndexes[siblingPosition + direction];
            if (targetIndex === undefined) return prev;
            const next = [...prev];
            [next[index], next[targetIndex]] = [next[targetIndex], next[index]];
            return next;
        });
        setSaved(false);
    }

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setSaved(false);
        router.put(`/admin/navigation-menus/${menu.id}`, { expected_version: menu.draft_version, name, items }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: setErrors,
            onSuccess: () => { setErrors({}); setSaved(true); },
        });
    }

    function deleteDraft() {
        if (menu.published_version > 0 || deleting) return;
        if (!window.confirm(`Delete the unpublished menu "${menu.name}" and all its draft revisions?`)) return;
        router.visit(`/admin/navigation-menus/${menu.id}`, {
            method: 'delete',
            data: { expected_version: menu.draft_version },
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onError: setErrors,
        });
    }

    return <BablysAdminLayout>
        <Head title={`${menu.name} · Navigation · Bablys Admin`} />
        <main className="mx-auto w-full max-w-6xl min-w-0 space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header className="space-y-2">
                <Link href="/admin/navigation-menus" className="inline-flex min-h-11 items-center text-sm font-semibold text-teal-800 underline">All navigation menus</Link>
                <p className="text-xs font-bold uppercase tracking-wide text-teal-700">Global CMS · P4A.2</p>
                <h1 className="break-words text-2xl font-bold text-slate-900 sm:text-3xl">Edit {menu.name}</h1>
                <p className="text-sm text-slate-600">Key: <code>{menu.key}</code> · Draft v{menu.draft_version} · Published v{menu.published_version}</p>
                <p className="text-sm font-semibold text-amber-800">Private draft — not published. Public menus are unchanged until P4A.3.</p>
            </header>
            <form onSubmit={save} noValidate className="space-y-5">
                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <label htmlFor="edit-menu-name" className="block text-sm font-semibold">Menu name</label>
                    <input id="edit-menu-name" maxLength={100} className={inputClass} value={name} onChange={e => setName(e.target.value)} aria-invalid={Boolean(errors.name)} />
                    {errors.name && <p className="text-sm text-red-700" role="alert">{errors.name}</p>}
                </section>
                <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" aria-labelledby="nav-items-heading">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
                        <div><h2 id="nav-items-heading" className="text-lg font-bold">Navigation links ({items.length}/80)</h2><p className="text-xs text-slate-600">Add, edit, nest or reorder links. Maximum nesting: three levels.</p></div>
                        <button type="button" onClick={() => add(null)} disabled={items.length >= 80} className="min-h-11 rounded-lg bg-teal-800 px-4 text-sm font-bold text-white disabled:opacity-50">Add root link</button>
                    </div>
                    {errors.items && <p className="mt-3 text-sm font-semibold text-red-700" role="alert">{errors.items}</p>}
                    {items.length === 0 && <p className="py-5 text-sm text-slate-600">This menu has no links yet.</p>}
                    <div className="space-y-3 pt-4">
                        {items.map((item, index) => {
                            const depth = depthOf(item, items);
                            const prefix = `items.${index}`;
                            return <article key={item.id} className="min-w-0 rounded-xl border border-slate-200 bg-slate-50 p-4" aria-label={`Menu link ${index + 1}`}>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Level {depth + 1} · Link {index + 1}</p>
                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" className={smallButton} onClick={() => move(item.id, -1)} aria-label={`Move ${item.label || 'link'} up`}>Up</button>
                                        <button type="button" className={smallButton} onClick={() => move(item.id, 1)} aria-label={`Move ${item.label || 'link'} down`}>Down</button>
                                        <button type="button" className={smallButton} onClick={() => add(item.id)} disabled={depth >= 2 || items.length >= 80}>Add child</button>
                                        <button type="button" className={`${smallButton} border-red-200 text-red-800`} onClick={() => remove(item.id)}>Remove</button>
                                    </div>
                                </div>
                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    <div className="min-w-0"><label className="block text-sm font-medium" htmlFor={`menu-label-${item.id}`}>Link label</label>
                                        <input id={`menu-label-${item.id}`} value={item.label} maxLength={100} className={inputClass} onChange={e => change(item.id, 'label', e.target.value)} aria-invalid={Boolean(errors[`${prefix}.label`])} />
                                        {errors[`${prefix}.label`] && <p className="text-xs text-red-700" role="alert">{errors[`${prefix}.label`]}</p>}
                                    </div>
                                    <div className="min-w-0"><label className="block text-sm font-medium" htmlFor={`menu-kind-${item.id}`}>Link type</label>
                                        <select id={`menu-kind-${item.id}`} className={inputClass} value={item.kind} onChange={e => change(item.id, 'kind', e.target.value)}><option value="internal">Internal website path</option><option value="external">External HTTPS URL</option></select>
                                    </div>
                                    <div className="min-w-0"><label className="block text-sm font-medium" htmlFor={`menu-url-${item.id}`}>Destination</label>
                                        <input id={`menu-url-${item.id}`} value={item.url} maxLength={350} className={inputClass} onChange={e => change(item.id, 'url', e.target.value)} placeholder={item.kind === 'internal' ? '/tours' : 'https://example.com'} aria-invalid={Boolean(errors[`${prefix}.url`])} />
                                        {errors[`${prefix}.url`] && <p className="text-xs text-red-700" role="alert">{errors[`${prefix}.url`]}</p>}
                                    </div>
                                    <div className="min-w-0"><label className="block text-sm font-medium" htmlFor={`menu-parent-${item.id}`}>Parent link</label>
                                        <select id={`menu-parent-${item.id}`} value={item.parent_id ?? ''} className={inputClass} onChange={e => change(item.id, 'parent_id', e.target.value || null)}>
                                            <option value="">Top level</option>
                                            {items.filter(candidate => candidate.id !== item.id).map(candidate => <option key={candidate.id} value={candidate.id}>{candidate.label || '(untitled)'}</option>)}
                                        </select>
                                        {errors[`${prefix}.parent_id`] && <p className="text-xs text-red-700" role="alert">{errors[`${prefix}.parent_id`]}</p>}
                                    </div>
                                </div>
                                {item.kind === 'external' && <label className="mt-3 flex min-h-11 items-center gap-2 text-sm font-medium"><input type="checkbox" checked={item.new_tab} onChange={e => change(item.id, 'new_tab', e.target.checked)} />Open external link in new tab (noopener, noreferrer)</label>}
                            </article>;
                        })}
                    </div>
                </section>
                {errors.expected_version && <p className="rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{errors.expected_version}</p>}
                {saved && <p className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800" role="status">Draft saved. Public navigation unchanged.</p>}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
                    <p className="text-xs text-slate-500">Publishing and rollback arrive in P4A.3.</p>
                    <button type="submit" disabled={saving} className="min-h-11 rounded-lg bg-teal-800 px-5 text-sm font-bold text-white disabled:opacity-50">{saving ? 'Saving…' : 'Save private menu draft'}</button>
                </div>
            </form>
            {menu.published_version === 0 && <div className="border-t border-slate-200 pt-4">
                <button type="button" onClick={deleteDraft} disabled={deleting} className="min-h-11 rounded-lg border border-red-300 px-4 text-sm font-semibold text-red-800 hover:bg-red-50">{deleting ? 'Deleting…' : 'Delete this unpublished menu'}</button>
                {errors.menu && <p role="alert" className="text-sm text-red-700">{errors.menu}</p>}
            </div>}
        </main>
    </BablysAdminLayout>;
}
