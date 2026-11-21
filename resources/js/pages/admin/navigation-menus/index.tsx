import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BablysAdminLayout from '@/layouts/bablys-admin-layout';

type MenuSummary = {
    id: number;
    key: string;
    name: string;
    draft_version: number;
    published_version: number;
    updated_at: string | null;
};
type Props = { menus: MenuSummary[] };
const fieldClass = 'mt-1 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-700';

export default function NavigationMenusIndex({ menus }: Props) {
    const [key, setKey] = useState('');
    const [name, setName] = useState('');
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    function create(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.post('/admin/navigation-menus', { menu_key: key, name }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: setErrors,
        });
    }

    return <BablysAdminLayout>
        <Head title="Navigation menus · Bablys Admin" />
        <main className="mx-auto w-full max-w-6xl min-w-0 space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header className="space-y-2">
                <p className="text-xs font-bold uppercase tracking-wide text-teal-700">Global CMS · P4A.2</p>
                <h1 className="text-2xl font-bold text-slate-900 sm:text-3xl">Navigation menus</h1>
                <p className="max-w-2xl text-sm text-slate-600">Create header, footer and other website menus. These are private drafts; publishing is introduced in P4A.3.</p>
                <Link href="/admin/site-settings" className="inline-flex min-h-11 items-center text-sm font-semibold text-teal-800 underline">Back to global site settings</Link>
            </header>
            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="create-menu">
                <h2 id="create-menu" className="text-lg font-semibold text-slate-900">Create a menu</h2>
                <form onSubmit={create} className="mt-4 grid items-end gap-4 md:grid-cols-[1fr_1.5fr_auto]" noValidate>
                    <div className="min-w-0"><label htmlFor="new-menu-key" className="block text-sm font-medium">Stable menu key</label>
                        <input id="new-menu-key" className={fieldClass} value={key} maxLength={48} placeholder="main-header" onChange={event => setKey(event.target.value)} aria-invalid={Boolean(errors.menu_key)} aria-describedby={errors.menu_key ? 'new-menu-key-error' : undefined} />
                        {errors.menu_key && <p id="new-menu-key-error" role="alert" className="text-xs text-red-700">{errors.menu_key}</p>}
                    </div>
                    <div className="min-w-0"><label htmlFor="new-menu-name" className="block text-sm font-medium">Menu name</label>
                        <input id="new-menu-name" className={fieldClass} value={name} maxLength={100} placeholder="Main website header" onChange={event => setName(event.target.value)} aria-invalid={Boolean(errors.name)} aria-describedby={errors.name ? 'new-menu-name-error' : undefined} />
                        {errors.name && <p id="new-menu-name-error" role="alert" className="text-xs text-red-700">{errors.name}</p>}
                    </div>
                    <button type="submit" disabled={saving} className="min-h-11 rounded-lg bg-teal-800 px-5 text-sm font-bold text-white hover:bg-teal-900 disabled:opacity-50">{saving ? 'Creating…' : 'Create draft menu'}</button>
                </form>
                <p className="mt-2 text-xs text-slate-500">The key is permanent. Use lowercase letters, digits and hyphens; for example <code>main-header</code> or <code>footer-links</code>.</p>
            </section>
            <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="menus-list">
                <h2 id="menus-list" className="border-b border-slate-100 bg-slate-50 px-5 py-4 text-lg font-semibold">Saved menu drafts ({menus.length})</h2>
                {menus.length === 0 ? <p className="p-5 text-sm text-slate-600">No navigation menus created yet.</p> :
                    <ul className="divide-y divide-slate-100">
                        {menus.map(menu => <li key={menu.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div className="min-w-0"><p className="font-semibold text-slate-900">{menu.name}</p><p className="break-all text-xs text-slate-500">Key: {menu.key} · Draft v{menu.draft_version} · Published v{menu.published_version}</p></div>
                            <Link href={`/admin/navigation-menus/${menu.id}`} className="inline-flex min-h-11 items-center rounded-lg border border-teal-700 px-4 text-sm font-semibold text-teal-800 hover:bg-teal-50">Edit menu</Link>
                        </li>)}
                    </ul>}
            </section>
        </main>
    </BablysAdminLayout>;
}
