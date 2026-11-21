import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import BablysAdminLayout from '@/layouts/bablys-admin-layout';

type Section = 'overview' | 'header' | 'footer';
type Group = 'branding' | 'contact' | 'social' | 'legal';
type Settings = Record<Group, Record<string, string>>;
type Snapshot = { draft: Settings; published: Settings; draft_version: number; published_version: number };
type Item = { id: string; label: string; kind: 'internal' | 'external'; url: string; parent_id: string | null; new_tab: boolean };
type Menu = { id: number; key: string; name: string; draft_items: Item[]; draft_version: number; published_version: number; publication_serial: number };
type Slot = { key: string; label: string; menu: Menu | null };
type Props = { section: Section; settings: Snapshot; settings_publication_serial: number; menus: Slot[] };

const fieldClass = 'mt-1 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700';
const button = 'inline-flex min-h-11 items-center justify-center rounded-xl px-4 text-sm font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:cursor-not-allowed disabled:opacity-50';
const sections: { name: string; href: string; note: string }[] = [
    { name: 'Header', href: '/admin/global-settings/header', note: 'Brand, contact strip and top navigation' },
    { name: 'Footer', href: '/admin/global-settings/footer', note: 'Contact, legal information and footer links' },
    { name: 'Site Settings', href: '/admin/site-settings', note: 'All website-wide fields in one editor' },
    { name: 'Navigation Menus', href: '/admin/navigation-menus', note: 'Advanced menu management' },
    { name: 'Publishing & History', href: '/admin/cms-publishing', note: 'Preview, audit and restore published versions' },
];

function SettingsEditor({ section, snapshot, serial }: { section: 'header' | 'footer'; snapshot: Snapshot; serial: number }) {
    const [value, setValue] = useState<Settings>(snapshot.draft);
    const [changed, setChanged] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    useEffect(() => { setValue(snapshot.draft); setChanged(false); setErrors({}); }, [snapshot.draft_version]);
    const fields: { group: Group; key: string; label: string; hint?: string; type?: string }[] = section === 'header'
        ? [
            { group: 'branding', key: 'site_name', label: 'Website name' },
            { group: 'branding', key: 'tagline', label: 'Tagline' },
            { group: 'contact', key: 'phone', label: 'Top bar phone' },
            { group: 'contact', key: 'email', label: 'Top bar email', type: 'email' },
            { group: 'contact', key: 'office_address', label: 'Top bar address' },
        ] : [
            { group: 'contact', key: 'phone', label: 'Contact phone' },
            { group: 'contact', key: 'email', label: 'Contact email', type: 'email' },
            { group: 'contact', key: 'whatsapp', label: 'WhatsApp' },
            { group: 'contact', key: 'office_address', label: 'Head office address' },
            { group: 'contact', key: 'business_hours', label: 'Business hours' },
            { group: 'social', key: 'facebook', label: 'Facebook URL', type: 'url' },
            { group: 'social', key: 'instagram', label: 'Instagram URL', type: 'url' },
            { group: 'social', key: 'youtube', label: 'YouTube URL', type: 'url' },
            { group: 'social', key: 'linkedin', label: 'LinkedIn URL', type: 'url' },
            { group: 'legal', key: 'copyright_notice', label: 'Copyright notice' },
            { group: 'legal', key: 'privacy_url', label: 'Privacy policy URL', type: 'url' },
            { group: 'legal', key: 'terms_url', label: 'Terms URL', type: 'url' },
        ];
    function change(group: Group, key: string, next: string) {
        setValue(prev => ({ ...prev, [group]: { ...prev[group], [key]: next } }));
        setChanged(true);
    }
    function save(e: FormEvent<HTMLFormElement>) {
        e.preventDefault(); setErrors({});
        router.put(`/admin/global-settings/${section}/settings`, { ...value, expected_version: snapshot.draft_version }, {
            preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: setErrors,
        });
    }
    function publish() {
        if (changed || snapshot.draft_version < 1 || busy) return;
        if (!window.confirm('Publish the saved website settings? This updates published branding and contact details across the website.')) return;
        router.post(`/admin/global-settings/${section}/settings/publish`, {
            expected_draft_version: snapshot.draft_version, expected_publication_serial: serial,
        }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: setErrors });
    }
    return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="site-fields-heading">
        <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div><h2 id="site-fields-heading" className="text-lg font-bold">{section === 'header' ? 'Header identity and top bar' : 'Footer contact and information'}</h2>
                <p className="mt-1 text-sm text-slate-600">Draft v{snapshot.draft_version} · Published v{snapshot.published_version}. Shared contact fields affect both header and footer when published.</p>
            </div>
            <Link href="/admin/cms-publishing/site-settings/preview" className="text-sm font-semibold text-teal-800 underline">Preview & restore</Link>
        </div>
        <form onSubmit={save} className="space-y-4" noValidate>
            <div className="grid gap-4 sm:grid-cols-2">{fields.map(f => {
                const id = `${f.group}-${f.key}`; const message = errors[`${f.group}.${f.key}`];
                return <div key={id} className={f.key === 'office_address' ? 'sm:col-span-2' : ''}>
                    <label htmlFor={id} className="text-sm font-semibold text-slate-800">{f.label}</label>
                    <input id={id} value={value[f.group][f.key] ?? ''} type={f.type ?? 'text'} className={fieldClass}
                        onChange={e => change(f.group, f.key, e.target.value)} aria-invalid={Boolean(message)} aria-describedby={message ? `${id}-error` : undefined} />
                    {message && <p id={`${id}-error`} className="mt-1 text-xs text-red-700" role="alert">{message}</p>}
                </div>;
            })}</div>
            {(errors.expected_version || errors.expected_draft_version || errors.expected_publication_serial) && <p role="alert" className="text-sm text-red-700">{errors.expected_version || errors.expected_draft_version || errors.expected_publication_serial} Reload before retrying.</p>}
            <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                <button type="submit" disabled={busy || !changed} className={`${button} bg-slate-900 text-white`}>Save draft</button>
                <button type="button" disabled={busy || changed || snapshot.draft_version < 1} onClick={publish} className={`${button} bg-teal-700 text-white`}>Publish saved settings</button>
                {changed && <span className="text-sm text-amber-800">Unsaved edits — save before publishing.</span>}
            </div>
        </form>
        <p className="mt-3 text-xs text-slate-500">Logo and favicon media uploads will be added in the Media Library phase. Other site settings are available in the advanced editor.</p>
    </section>;
}

function depthOf(item: Item, items: Item[]): number {
    let depth = 0, parent = item.parent_id; const visited = new Set([item.id]);
    while (parent && depth < 4 && !visited.has(parent)) { visited.add(parent); const next = items.find(i => i.id === parent); if (!next) break; depth++; parent = next.parent_id; }
    return depth;
}
function MenuEditor({ slot, section }: { slot: Slot; section: 'header' | 'footer' }) {
    const menu = slot.menu;
    const [items, setItems] = useState<Item[]>(menu?.draft_items ?? []);
    const [changed, setChanged] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    useEffect(() => { setItems(menu?.draft_items ?? []); setChanged(false); setErrors({}); }, [menu?.id, menu?.draft_version]);
    function add(parent_id: string | null) {
        if (items.length >= 80) return;
        const next: Item = { id: crypto.randomUUID(), label: '', kind: 'internal', url: '/', parent_id, new_tab: false };
        setItems(prev => [...prev, next]); setChanged(true);
    }
    function edit(id: string, patch: Partial<Item>) {
        setItems(prev => prev.map(i => i.id === id ? { ...i, ...patch } : i)); setChanged(true);
    }
    function remove(id: string) {
        const ids = new Set([id]);
        let didChange = true;
        while (didChange) { didChange = false; for (const item of items) if (item.parent_id && ids.has(item.parent_id) && !ids.has(item.id)) { ids.add(item.id); didChange = true; } }
        setItems(prev => prev.filter(i => !ids.has(i.id))); setChanged(true);
    }
    function move(id: string, direction: number) {
        setItems(prev => { const idx = prev.findIndex(i => i.id === id); if (idx < 0) return prev;
            const siblings = prev.map((i, n) => i.parent_id === prev[idx].parent_id ? n : -1).filter(n => n >= 0);
            const other = siblings[siblings.indexOf(idx) + direction]; if (other === undefined) return prev;
            const copy = [...prev]; [copy[idx], copy[other]] = [copy[other], copy[idx]]; return copy; });
        setChanged(true);
    }
    function create() {
        router.post(`/admin/global-settings/${section}/menus`, { menu_key: slot.key }, {
            preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: setErrors,
        });
    }
    function save(e: FormEvent<HTMLFormElement>) {
        e.preventDefault(); if (!menu) return;
        router.put(`/admin/global-settings/${section}/menus/${menu.id}`, { expected_version: menu.draft_version, name: menu.name, items }, {
            preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: setErrors,
        });
    }
    function publish() {
        if (!menu || changed || menu.draft_version < 1 || busy) return;
        if (!window.confirm(`Publish ${slot.label}? This replaces the live menu with the saved draft.`)) return;
        router.post(`/admin/global-settings/${section}/menus/${menu.id}/publish`, {
            expected_draft_version: menu.draft_version, expected_publication_serial: menu.publication_serial,
        }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: setErrors });
    }
    return <section className="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label={`${slot.label} editor`}>
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div><h2 className="text-lg font-bold">{slot.label}</h2><p className="text-xs text-slate-600">{slot.key}{menu ? ` · Draft v${menu.draft_version} · Published v${menu.published_version}` : ' · Not created yet'}</p></div>
            {menu && <Link href={`/admin/cms-publishing/navigation-menus/${menu.id}/preview`} className="text-sm font-semibold text-teal-800 underline">Preview / restore</Link>}
        </div>
        {!menu ? <div className="space-y-3"><p className="text-sm text-slate-600">Create this menu once. Its location is automatically assigned in the website design.</p>
            {errors.menu_key && <p className="text-xs text-red-700" role="alert">{errors.menu_key}</p>}
            <button type="button" className={`${button} bg-teal-700 text-white`} onClick={create} disabled={busy}>Create {slot.label} menu</button></div>
            : <form onSubmit={save} className="space-y-4">
                {items.length === 0 && <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">No links yet. Use “Add link” below.</p>}
                {items.map((item, index) => {
                    const depth = depthOf(item, items);
                    return <div key={item.id} className="rounded-xl border border-slate-200 bg-slate-50 p-3" style={{ marginLeft: Math.min(depth, 2) * 12 }}>
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <p className="text-xs font-semibold text-slate-600">Level {depth + 1} · Link {index + 1}</p>
                            <div className="flex flex-wrap gap-1.5">
                                <button type="button" onClick={() => move(item.id, -1)} className={`${button} border border-slate-300 bg-white text-slate-800`}>↑ Up</button>
                                <button type="button" onClick={() => move(item.id, 1)} className={`${button} border border-slate-300 bg-white text-slate-800`}>↓ Down</button>
                                <button type="button" onClick={() => add(item.id)} disabled={depth >= 2 || items.length >= 80} className={`${button} border border-slate-300 bg-white text-slate-800`}>Add submenu</button>
                                <button type="button" onClick={() => remove(item.id)} className={`${button} border border-red-200 bg-white text-red-800`}>Remove</button>
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-xs font-semibold">Link label
                                <input className={fieldClass} value={item.label} onChange={e => edit(item.id, { label: e.target.value })} maxLength={100}/>
                            </label>
                            <label className="text-xs font-semibold">Destination
                                <input className={fieldClass} value={item.url} onChange={e => edit(item.id, { url: e.target.value })} placeholder="/tours or https://example.com" />
                            </label>
                            <label className="text-xs font-semibold">Link type
                                <select className={fieldClass} value={item.kind} onChange={e => edit(item.id, { kind: e.target.value === 'external' ? 'external' : 'internal', url: e.target.value === 'external' ? 'https://' : '/', new_tab: e.target.value === 'external' })}>
                                    <option value="internal">Website page</option><option value="external">External HTTPS link</option>
                                </select>
                            </label>
                            <label className="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" checked={item.new_tab} disabled={item.kind !== 'external'} onChange={e => edit(item.id, { new_tab: e.target.checked })}/> Open in new tab</label>
                        </div>
                        {errors[`items.${index}.url`] && <p role="alert" className="mt-1 text-xs text-red-700">{errors[`items.${index}.url`]}</p>}
                    </div>;
                })}
                {errors.items && <p role="alert" className="text-xs text-red-700">{errors.items}</p>}
                {errors.expected_version && <p role="alert" className="text-xs text-red-700">{errors.expected_version}</p>}
                {(errors.expected_draft_version || errors.expected_publication_serial) && <p role="alert" className="text-xs text-red-700">{errors.expected_draft_version || errors.expected_publication_serial}</p>}
                <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                    <button type="button" onClick={() => add(null)} disabled={items.length >= 80 || busy} className={`${button} border border-teal-700 bg-white text-teal-800`}>+ Add link</button>
                    <button type="submit" disabled={busy || !changed} className={`${button} bg-slate-900 text-white`}>Save menu draft</button>
                    <button type="button" onClick={publish} disabled={busy || changed || menu.draft_version < 1} className={`${button} bg-teal-700 text-white`}>Publish menu</button>
                    {changed && <span className="text-xs text-amber-800">Save first to publish.</span>}
                </div>
            </form>}
    </section>;
}

export default function GlobalSettingsWorkspace({ section, settings, settings_publication_serial, menus }: Props) {
    const pageTitle = section === 'overview' ? 'Global Settings' : section === 'header' ? 'Header Settings' : 'Footer Settings';
    return <BablysAdminLayout>
        <Head title={`${pageTitle} · Bablys Admin`} />
        <main className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header className="space-y-2">
                <p className="text-xs font-bold uppercase tracking-wide text-teal-700">Website appearance · Global settings</p>
                <h1 className="text-2xl font-bold text-slate-900 sm:text-3xl">{pageTitle}</h1>
                <p className="max-w-3xl text-sm text-slate-600">Edit like a website CMS: choose a section, save a private draft, then publish the approved version. No code editing needed.</p>
            </header>
            <nav aria-label="Global settings sections" className="flex flex-wrap gap-2">
                {sections.map(s => <Link key={s.href} href={s.href} className={`inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-semibold ${s.href === `/admin/global-settings/${section}` ? 'bg-teal-700 text-white' : 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50'}`}>{s.name}</Link>)}
            </nav>
            {section === 'overview' ? <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {sections.map(s => <Link key={s.href} href={s.href} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-teal-500 focus-visible:outline-2 focus-visible:outline-teal-700">
                    <h2 className="text-lg font-bold text-slate-900">{s.name}</h2><p className="mt-2 text-sm text-slate-600">{s.note}</p>
                    <p className="mt-4 text-sm font-bold text-teal-800">Open editor →</p>
                </Link>)}
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950"><h2 className="font-bold">Publication safety</h2><p className="mt-2">Saving never changes the public website. Publishing requires a separate confirmation, and earlier published versions remain restorable.</p></div>
            </div> : <div className="space-y-6">
                <SettingsEditor section={section} snapshot={settings} serial={settings_publication_serial}/>
                <div className="grid min-w-0 gap-5 lg:grid-cols-2">
                    {menus.map(slot => <MenuEditor key={slot.key} slot={slot} section={section}/>)}
                </div>
                <p className="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Live website: published values only. To restore an old version, open the Preview / restore link above. Advanced editors remain available for additional settings and menus.</p>
            </div>}
        </main>
    </BablysAdminLayout>;
}
