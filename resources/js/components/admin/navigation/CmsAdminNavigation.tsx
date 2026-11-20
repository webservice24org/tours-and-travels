import { Link, usePage } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';

type NavLink = { label: string; href: string };
type NavGroup = { key: string; title: string; links: NavLink[] };
type NavigationResponse = { data: NavGroup[] };

/**
 * One responsive navigation component across Bablys CMS pages.
 * All menu items are produced by a server-filtered, non-cacheable endpoint.
 * Backend middleware remains authoritative; hiding a link is never security.
 */
export function CmsAdminNavigation() {
    const { url } = usePage();
    const [groups, setGroups] = useState<NavGroup[]>([]);
    const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
    const [open, setOpen] = useState(false);
    const navigationId = useId();
    const current = url.split('?')[0].replace(/\/$/, '') || '/';

    useEffect(() => {
        const controller = new AbortController();

        async function loadNavigation() {
            try {
                const response = await fetch('/admin/internal/navigation', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    signal: controller.signal,
                });

                if (!response.ok) throw new Error('Navigation access was not granted.');

                const payload = (await response.json()) as NavigationResponse;
                if (!Array.isArray(payload.data)) throw new Error('Unexpected navigation response.');

                if (!controller.signal.aborted) {
                    setGroups(payload.data);
                    setState('ready');
                }
            } catch {
                if (!controller.signal.aborted) setState('error');
            }
        }

        void loadNavigation();
        return () => controller.abort();
    }, []);

    const links = (
        <div className="grid min-w-0 gap-3 lg:flex lg:flex-wrap lg:items-start lg:gap-x-6 lg:gap-y-3">
            {groups.map((group) => (
                <div key={group.key} className="min-w-0" aria-label={group.title}>
                    <p className="mb-1 text-[11px] font-bold uppercase tracking-widest text-slate-500">
                        {group.title}
                    </p>
                    <div className="flex min-w-0 flex-wrap gap-1.5">
                        {group.links.map((link) => {
                            const active = current === (link.href.replace(/\/$/, '') || '/');
                            return (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    aria-current={active ? 'page' : undefined}
                                    onClick={() => setOpen(false)}
                                    className={`inline-flex max-w-full items-center rounded-lg px-3 py-2 text-sm font-medium outline-offset-2 transition-colors focus-visible:outline-2 focus-visible:outline-teal-700 ${active
                                        ? 'bg-teal-800 text-white'
                                        : 'bg-white text-slate-700 hover:bg-teal-50 hover:text-teal-900'}`}
                                >
                                    <span className="truncate">{link.label}</span>
                                </Link>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );

    return (
        <nav aria-label="Bablys CMS navigation" className="border-b border-slate-200 bg-white/95 shadow-sm">
            <div className="mx-auto max-w-7xl px-4 py-3 sm:px-6">
                <div className="flex items-center justify-between gap-3 lg:hidden">
                    <span className="text-sm font-bold text-teal-900">Bablys CMS navigation</span>
                    <button
                        type="button"
                        aria-controls={navigationId}
                        aria-expanded={open}
                        onClick={() => setOpen((previous) => !previous)}
                        className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-teal-700"
                    >
                        {open ? 'Hide menu' : 'Open menu'}
                    </button>
                </div>

                {state === 'loading' && <p role="status" className="py-2 text-sm text-slate-500">Loading authorized navigation…</p>}
                {state === 'error' && (
                    <p role="alert" className="py-2 text-sm text-red-700">
                        The CMS navigation could not be loaded. <Link href="/admin" className="underline">Return to admin</Link>.
                    </p>
                )}
                {state === 'ready' && (
                    <div id={navigationId} className={`${open ? 'block' : 'hidden'} pt-3 lg:block lg:pt-0`}>
                        {links}
                    </div>
                )}
            </div>
        </nav>
    );
}
