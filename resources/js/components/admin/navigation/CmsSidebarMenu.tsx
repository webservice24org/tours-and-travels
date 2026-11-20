import { Link } from '@inertiajs/react';

type CmsNavLink = { label: string; href: string };
export type CmsNavGroup = { key: string; title: string; links: CmsNavLink[] };
export type CmsNavState = 'loading' | 'ready' | 'error';

interface CmsSidebarMenuProps {
    groups: CmsNavGroup[];
    state: CmsNavState;
    pathname: string;
    onNavigate?: () => void;
}

/**
 * Presentational only. The server-provided navigation is permission-filtered,
 * while actual authorization always remains in Laravel middleware and policies.
 */
export function CmsSidebarMenu({ groups, state, pathname, onNavigate }: CmsSidebarMenuProps) {
    if (state === 'loading') {
        return <p className="px-4 py-4 text-sm text-slate-500" role="status">Loading authorized navigation…</p>;
    }

    if (state === 'error') {
        return (
            <div className="px-4 py-4 text-sm text-red-700" role="alert">
                Navigation is unavailable. <Link className="font-semibold underline" href="/admin" onClick={onNavigate}>Go to admin home</Link>.
            </div>
        );
    }

    return (
        <nav aria-label="Bablys CMS sidebar" className="space-y-6 px-3 py-5">
            {groups.map((group) => (
                <div key={group.key}>
                    <p className="px-3 pb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500">
                        {group.title}
                    </p>
                    <ul className="space-y-1">
                        {group.links.map((link) => {
                            const path = link.href.split('?')[0].replace(/\/$/, '') || '/';
                            const active = pathname === path;

                            return (
                                <li key={link.href}>
                                    <Link
                                        href={link.href}
                                        aria-current={active ? 'page' : undefined}
                                        onClick={onNavigate}
                                        className={`group flex min-w-0 items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600 ${active
                                            ? 'bg-teal-800 text-white shadow-sm'
                                            : 'text-slate-700 hover:bg-teal-50 hover:text-teal-900'}`}
                                    >
                                        <span aria-hidden="true" className={`h-1.5 w-1.5 shrink-0 rounded-full ${active ? 'bg-white' : 'bg-slate-300 group-hover:bg-teal-600'}`} />
                                        <span className="min-w-0 break-words">{link.label}</span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
            {groups.length === 0 && <p className="px-3 text-sm text-slate-600">No CMS modules are available for this account.</p>}
        </nav>
    );
}
