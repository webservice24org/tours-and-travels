import { Head, Link } from '@inertiajs/react';

interface AdminLandingProps {
    staff: { name: string };
    roles: string[];
    permissions: string[];
}

const sections: { title: string; description: string; capabilities: string[] }[] = [
    { title: 'Tours & destinations', description: 'Tour packages, schedules and destination taxonomy', capabilities: ['tours.view', 'tours.create', 'tours.edit', 'tours.publish', 'taxonomy.manage'] },
    { title: 'Page & layout builders', description: 'Pages, menus, headers, footers and reusable blocks', capabilities: ['builders.edit', 'builders.publish', 'pages.edit', 'pages.publish', 'menus.manage'] },
    { title: 'Gallery & media', description: 'Photo collections, reusable assets and published galleries', capabilities: ['media.upload', 'media.delete', 'galleries.edit', 'galleries.publish'] },
    { title: 'Traveler reviews', description: 'Private feedback, moderation and approved replies', capabilities: ['reviews.view_private', 'reviews.moderate', 'reviews.reply'] },
    { title: 'Blog & SEO', description: 'Editorial work and approved AI-assisted SEO', capabilities: ['blog.edit', 'blog.publish', 'seo.edit', 'seo.publish', 'seo.ai.propose', 'seo.ai.review'] },
    { title: 'Bookings', description: 'Booking requests and operations', capabilities: ['bookings.view', 'bookings.manage'] },
    { title: 'Administration', description: 'Global settings, audit access and future staff management', capabilities: ['settings.manage', 'roles.manage', 'audit.view'] },
];

function readable(permission: string): string {
    return permission.replaceAll('.', ' · ').replaceAll('_', ' ');
}

export default function BablysAdminIndex({ staff, roles, permissions }: AdminLandingProps) {
    const allowed = new Set(permissions);
    const visible = sections.map((section) => ({
        ...section,
        granted: section.capabilities.filter((permission) => allowed.has(permission)),
    })).filter((section) => section.granted.length > 0);

    return (
        <>
            <Head title="Bablys CMS · Admin" />
            <main className="min-h-screen bg-slate-50 text-slate-900">
                <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:py-12">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700">Bablys Tours & Travels</p>
                            <h1 className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">CMS administration</h1>
                            <p className="mt-2 text-sm text-slate-600">Welcome, {staff.name}. Your access is controlled by the server.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Link href="/" className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-white">Public site</Link>
                            {permissions.includes('roles.manage') && roles.includes('super_admin') && (
                                <Link href="/admin/staff-users" className="rounded-lg border border-teal-700 bg-white px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50">Staff users</Link>
                            )}
                            {permissions.includes('roles.manage') && roles.includes('super_admin') && (
                                <Link href="/admin/roles" className="rounded-lg border border-teal-700 bg-white px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50">Roles &amp; permissions</Link>
                            )}
                                                        {permissions.includes('audit.view') && roles.includes('super_admin') && (
                                <Link href="/admin/audit-logs" className="rounded-lg border border-teal-700 bg-white px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50">Audit logs</Link>
                            )}
                            <Link href="/dashboard" className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">Dashboard</Link>
                        </div>
                    </div>
                    <section className="mt-8 rounded-xl border border-teal-200 bg-teal-50 px-5 py-4">
                        <h2 className="text-base font-semibold text-teal-900">Authorization foundation is active</h2>
                        <p className="mt-1 text-sm leading-relaxed text-teal-900/80">This screen lists only your permitted modules. Management screens, editing workflows, and sidebar navigation will be added in P2B.3–P2B.5. A hidden card does not replace backend authorization.</p>
                        <p className="mt-3 break-words text-xs text-teal-800"><span className="font-semibold">Roles:</span> {roles.length ? roles.join(', ') : 'None'}</p>
                    </section>
                    <section className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="Authorized modules">
                        {visible.map((section) => (
                            <article key={section.title} className="min-w-0 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                                <h2 className="text-lg font-semibold">{section.title}</h2>
                                <p className="mt-1 text-sm text-slate-600">{section.description}</p>
                                <ul className="mt-4 flex flex-wrap gap-2" aria-label={`${section.title} granted permissions`}>
                                    {section.granted.map((permission) => (
                                        <li key={permission} className="max-w-full break-all rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-700">{readable(permission)}</li>
                                    ))}
                                </ul>
                            </article>
                        ))}
                    </section>
                    <footer className="mt-8 text-xs text-slate-500">P2B.2 security milestone · No live CMS content can be edited from this page yet.</footer>
                </div>
            </main>
        </>
    );
}
