import { Link, usePage } from '@inertiajs/react';
import { useRef, useState, type KeyboardEvent, type PropsWithChildren } from 'react';

/**
 * Bablys public website shell; never wraps a CMS/dashboard sidebar.
 * The mobile menu is a true button-controlled navigation region with an
 * explicit Escape path back to the menu toggle and visible focus states.
 */
export default function PublicSiteLayout({ children }: PropsWithChildren) {
    const [menuOpen, setMenuOpen] = useState(false);
    // The account route itself enforces auth + verified email. Do not trust
    // shared Inertia props for authorization; they only affect link labels.
    const shared = usePage().props as { auth?: { user?: { id?: number } | null } };
    const signedIn = Boolean(shared.auth?.user);
    const menuToggleRef = useRef<HTMLButtonElement>(null);

    function onMobileNavigationKeyDown(event: KeyboardEvent<HTMLElement>) {
        if (event.key === 'Escape') {
            setMenuOpen(false);
            menuToggleRef.current?.focus();
        }
    }

    return (
        <div data-bablys-public-site="true" className="flex min-h-screen w-full min-w-0 flex-col overflow-x-clip bg-[#f7f9fb] text-slate-900">
            <a href="#main-content" className="sr-only rounded bg-white p-3 font-semibold text-emerald-900 shadow focus:not-sr-only focus:absolute focus:left-4 focus:top-3 focus:z-50 focus:outline-none focus:ring-2 focus:ring-emerald-600">
                Skip to main content
            </a>
            <header className="relative z-20 w-full border-b border-slate-200 bg-white shadow-[0_3px_20px_rgba(16,36,45,0.03)]">
                <div className="mx-auto flex w-full max-w-[1440px] items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
                    <Link href="/" className="inline-flex min-w-0 items-center gap-3 rounded-lg text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2" aria-label="Bably's Club homepage">
                        <span aria-hidden="true" className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-emerald-700 text-lg font-bold text-white">B</span>
                        <span className="flex min-w-0 flex-col leading-tight"><strong className="truncate text-base tracking-tight sm:text-lg">Bably's Club</strong><span className="text-xs font-medium text-slate-500">Tours &amp; Travels</span></span>
                    </Link>
                    <button
                        ref={menuToggleRef}
                        type="button"
                        aria-label={menuOpen ? 'Close website menu' : 'Open website menu'}
                        aria-controls="bablys-public-mobile-nav"
                        aria-expanded={menuOpen}
                        onClick={() => setMenuOpen((open) => !open)}
                        className="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-lg border border-slate-300 px-3 text-sm font-semibold text-slate-800 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 md:hidden"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                            <path d={menuOpen ? 'M5 5l14 14M19 5 5 19' : 'M4 6h16M4 12h16M4 18h16'} />
                        </svg>
                        <span className="sr-only">Website navigation</span>
                    </button>
                    <nav aria-label="Desktop website navigation" className="hidden items-center gap-2 md:flex">
                        <Link href="/" className="inline-flex min-h-11 items-center rounded-md px-3 py-2 text-sm font-semibold text-slate-700 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Home</Link>
                        <Link href="/tours" className="inline-flex min-h-11 items-center rounded-md px-3 py-2 text-sm font-semibold text-slate-700 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Explore Tours</Link>
                        <Link href="/account/travel" className="inline-flex min-h-11 items-center rounded-md px-3 py-2 text-sm font-semibold text-slate-700 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">{signedIn ? 'My Account' : 'Account / Login'}</Link>
                        <Link href="/tours" className="inline-flex min-h-11 items-center rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">Find a Tour</Link>
                    </nav>
                </div>
                <nav
                    id="bablys-public-mobile-nav"
                    aria-label="Mobile website navigation"
                    onKeyDown={onMobileNavigationKeyDown}
                    className={`${menuOpen ? 'block' : 'hidden'} border-t border-slate-200 bg-white px-4 py-3 sm:px-6 md:hidden`}
                >
                    <div className="mx-auto flex w-full max-w-[1440px] flex-col gap-1">
                        <Link href="/" onClick={() => setMenuOpen(false)} className="flex min-h-12 items-center rounded-lg px-4 py-3 text-base font-semibold text-slate-800 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Home</Link>
                        <Link href="/tours" onClick={() => setMenuOpen(false)} className="flex min-h-12 items-center rounded-lg px-4 py-3 text-base font-semibold text-slate-800 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Explore Tours</Link>
                        <Link href="/account/travel" onClick={() => setMenuOpen(false)} className="flex min-h-12 items-center rounded-lg px-4 py-3 text-base font-semibold text-slate-800 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">{signedIn ? 'My Account' : 'Account / Login'}</Link>
                    </div>
                </nav>
            </header>
            <div id="main-content" tabIndex={-1} className="w-full min-w-0 flex-1 focus:outline-none">{children}</div>
            <footer className="mt-auto w-full border-t border-slate-200 bg-white">
                <div className="mx-auto flex max-w-[1440px] flex-wrap items-center justify-between gap-x-8 gap-y-3 px-4 py-7 text-sm text-slate-600 sm:px-6 lg:px-8">
                    <p><strong className="text-slate-800">Bably's Club</strong> · Tours &amp; Travels</p>
                    <p>Tour requests are enquiries. Availability and prices require confirmation.</p>
                </div>
            </footer>
        </div>
    );
}
