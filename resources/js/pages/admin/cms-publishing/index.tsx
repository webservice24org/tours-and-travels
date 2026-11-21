import { Head, Link } from '@inertiajs/react';
import BablysAdminLayout from '@/layouts/bablys-admin-layout';

type Summary = { draft_version: number; published_version: number; publication_serial: number; published_at: string | null };
type Menu = Summary & { id: number; key: string; name: string };
type Props = { settings: Summary; menus: Menu[] };

export default function CmsPublishingCenter({ settings, menus }: Props) {
    return <BablysAdminLayout>
        <Head title="CMS publishing · Bablys Admin" />
        <main className="mx-auto w-full max-w-6xl min-w-0 space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header>
                <p className="text-xs font-bold uppercase tracking-wider text-teal-700">Bablys CMS · P4A.3</p>
                <h1 className="mt-1 text-2xl font-bold text-slate-900 sm:text-3xl">Publishing center</h1>
                <p className="mt-2 max-w-3xl text-sm text-slate-600">Preview draft content beside the current published content. Publication and restoration require explicit confirmation. The existing website header/footer will consume these published snapshots in P4A.4.</p>
            </header>
            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="site-heading">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div><h2 id="site-heading" className="text-lg font-bold text-slate-900">Global site settings</h2>
                        <p className="mt-1 text-sm text-slate-600">Draft v{settings.draft_version} · Published from draft v{settings.published_version} · Publication #{settings.publication_serial}</p>
                        <p className="mt-1 text-xs text-slate-500">{settings.published_at ? `Last published: ${settings.published_at}` : 'Not published yet'}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href="/admin/site-settings" className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-800">Edit draft</Link>
                        <Link href="/admin/cms-publishing/site-settings/preview" className="inline-flex min-h-11 items-center rounded-lg bg-teal-800 px-4 text-sm font-semibold text-white">Preview / publish</Link>
                    </div>
                </div>
            </section>
            <section aria-labelledby="menus-heading" className="space-y-3">
                <div className="flex flex-wrap justify-between gap-3"><h2 id="menus-heading" className="text-lg font-bold text-slate-900">Navigation menus</h2>
                    <Link href="/admin/navigation-menus" className="inline-flex min-h-11 items-center text-sm font-semibold text-teal-800 underline">Manage menu drafts</Link></div>
                {menus.length === 0 && <p className="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-600">No menus have been created yet.</p>}
                {menus.map(menu => <article key={menu.id} className="flex min-w-0 flex-wrap items-start justify-between gap-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div><h3 className="font-semibold text-slate-900">{menu.name}</h3><p className="text-xs text-slate-600">Key: {menu.key}</p>
                        <p className="mt-1 text-sm text-slate-600">Draft v{menu.draft_version} · Published from draft v{menu.published_version} · Publication #{menu.publication_serial}</p>
                        <p className="text-xs text-slate-500">{menu.published_at ? `Last published: ${menu.published_at}` : 'Not published yet'}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={`/admin/navigation-menus/${menu.id}`} className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-800">Edit draft</Link>
                        <Link href={`/admin/cms-publishing/navigation-menus/${menu.id}/preview`} className="inline-flex min-h-11 items-center rounded-lg bg-teal-800 px-4 text-sm font-semibold text-white">Preview / publish</Link>
                    </div>
                </article>)}
            </section>
        </main>
    </BablysAdminLayout>;
}
