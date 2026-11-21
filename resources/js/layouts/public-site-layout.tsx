import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type KeyboardEvent, type PropsWithChildren } from 'react';
import {
    buildMenuTree, decodePublishedChrome, fallbackChrome, fallbackHeader,
    type NavNode, type PublicChrome, type PublicNavItem,
} from '@/lib/bablys-public-chrome';

/** P4A.4B: visual parity with the owner's bablys.club reference, without changing CMS contracts. */
const brandLogo = '/images/bablys-club-reference-logo.png';
const fallbackOffice = 'House 20/22, Road 15, Gulshan 1, Dhaka -1212, Bangladesh';
const fallbackPhone = '+880 1895-635517';
const fallbackEmail = 'bablystravel@gmail.com';
const footerDescription = 'Bably’s Tours & Travels promotes eco-friendly, responsible tourism with safe, comfortable, affordable travel for families, friends, colleagues, and groups.';

/** Reference links are temporary public-safe fallbacks until matching CMS menus are published. */
function referenceItem(id: string, label: string, url: string, parent_id: string | null = null): PublicNavItem {
    return { id, label, url, parent_id, kind: 'internal', new_tab: false };
}
const domesticFallback = [
    referenceItem('domestic-group', 'Domestic Group Tours', '/tours'),
    referenceItem('domestic-business', 'Business & Corporate', '/tours'),
    referenceItem('domestic-education', 'Educational Trips', '/tours'),
    referenceItem('domestic-family', 'Family Beach Getaways', '/tours'),
    referenceItem('domestic-sajek', 'Sajek Valley Packages', '/tours'),
];
const internationalFallback = [
    referenceItem('global-dubai', 'Dubai Luxury Tours', '/tours'),
    referenceItem('global-maldives', 'Maldives Resort Booking', '/tours'),
    referenceItem('global-thailand', 'Thailand Island Hopping', '/tours'),
    referenceItem('global-bali', 'Bali Vacation Packages', '/tours'),
    referenceItem('global-europe', 'Europe Schengen Tours', '/tours'),
];
const referenceHeader: PublicNavItem[] = [
    ...fallbackHeader.filter((item) => item.id === 'default-home'),
    referenceItem('reference-domestic', 'Domestic Tour', '/tours'),
    ...domesticFallback.map((item) => ({ ...item, id: 'header-' + item.id, parent_id: 'reference-domestic' })),
    referenceItem('reference-international', 'Global / International', '/tours'),
    ...internationalFallback.map((item) => ({ ...item, id: 'header-' + item.id, parent_id: 'reference-international' })),
    referenceItem('reference-about', 'About Us', '/#about'),
    referenceItem('reference-contact', 'Contact Us', '/#contact'),
];
const referenceBottom = [
    referenceItem('bottom-home', 'Home', '/'),
    referenceItem('bottom-about', 'About Us', '/#about'),
    referenceItem('bottom-contact', 'Contact Us', '/#contact'),
    referenceItem('bottom-gallery', 'Photo Gallery', '/#gallery'),
    referenceItem('bottom-reviews', 'Customer Reviews', '/#reviews'),
];

function PublicNavigationLink({ item, onNavigate, className, activePath }: {
    item: PublicNavItem;
    onNavigate?: () => void;
    className: string;
    activePath?: string;
}) {
    // Every external URL was validated by decodePublishedChrome.
    if (item.kind === 'external') {
        return <a href={item.url} target={item.new_tab ? '_blank' : undefined}
            rel="noopener noreferrer" onClick={onNavigate} className={className}>{item.label}</a>;
    }
    const isCurrent = activePath === item.url || (item.url !== '/' && activePath === item.url.split('?')[0]);
    return <Link href={item.url} onClick={onNavigate} aria-current={isCurrent ? 'page' : undefined}
        className={className}>{item.label}</Link>;
}

/** Native details/summary permits click, Enter, Space and keyboard focus without custom menu traps. */
function PublicNavigationNodes({ nodes, mobile, onNavigate, depth = 0, activePath }: {
    nodes: NavNode[];
    mobile: boolean;
    onNavigate?: () => void;
    depth?: number;
    activePath?: string;
}) {
    const desktopRoot = !mobile && depth === 0;
    return <ul className={desktopRoot ? 'flex min-w-0 flex-wrap items-center justify-center gap-1.5' : 'flex min-w-0 flex-col gap-1'}>
        {nodes.map((node) => <li key={node.item.id} className="relative min-w-0">
            {node.children.length > 0 ? <details className="group relative min-w-0">
                <summary className={`flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800] [&::-webkit-details-marker]:hidden ${mobile ? 'text-base text-[#21334e] hover:bg-blue-50' : 'text-[#334159] hover:bg-[#e9f1ff] hover:text-[#0d5dba]'}`}>
                    <span>{node.item.label}</span>
                    <svg aria-hidden="true" viewBox="0 0 24 24" className="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="m6 9 6 6 6-6" /></svg>
                </summary>
                <div className={desktopRoot ? 'absolute left-0 top-full z-40 min-w-56 max-w-[min(90vw,330px)] rounded-xl border border-slate-200 bg-white p-2 shadow-[0_14px_35px_rgba(1,20,56,.16)]' : 'min-w-0 border-l-2 border-orange-200 pl-3'}>
                    <PublicNavigationLink item={node.item} onNavigate={onNavigate} activePath={activePath} className="flex min-h-11 items-center rounded-md px-3 py-2 text-sm font-medium text-[#123967] hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800]" />
                    <PublicNavigationNodes nodes={node.children} mobile={mobile} depth={depth + 1} onNavigate={onNavigate} activePath={activePath} />
                </div>
            </details> : <PublicNavigationLink item={node.item} onNavigate={onNavigate} activePath={activePath}
                className={`flex min-h-11 min-w-0 items-center rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800] ${desktopRoot ? 'text-[#334159] hover:bg-[#e9f1ff] hover:text-[#0d5dba] [&[aria-current=page]]:bg-[#e7f0fd] [&[aria-current=page]]:text-[#115cb6]' : 'text-[#21334e] hover:bg-blue-50 [&[aria-current=page]]:text-blue-700'}`} />}
        </li>)}
    </ul>;
}

type IconName = 'phone' | 'mail' | 'pin' | 'compass' | 'facebook' | 'instagram' | 'youtube' | 'linkedin';
function SmallIcon({ name, className = 'h-4 w-4' }: { name: IconName; className?: string }) {
    const common = { className, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.9, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true as const };
    if (name === 'phone') return <svg {...common}><path d="M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-5-3-2 2a14 14 0 0 1-6-6l2-2-3-5Z" /></svg>;
    if (name === 'mail') return <svg {...common}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg>;
    if (name === 'pin') return <svg {...common}><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></svg>;
    if (name === 'compass') return <svg {...common}><circle cx="12" cy="12" r="9" /><path d="m15.5 8.5-2.2 5-4.8 2 2.2-5 4.8-2Z" /></svg>;
    if (name === 'facebook') return <svg {...common}><path d="M14 21v-8h3l.5-4H14V7.4c0-1.2.4-1.9 1.9-1.9H18V2.2A26 26 0 0 0 15.4 2C12.4 2 11 3.6 11 6.6V9H8v4h3v8" /></svg>;
    if (name === 'instagram') return <svg {...common}><rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4" /><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none" /></svg>;
    if (name === 'youtube') return <svg {...common}><rect x="2" y="5" width="20" height="14" rx="4" /><path d="m10 9 5 3-5 3Z" /></svg>;
    return <svg {...common}><rect x="3" y="3" width="18" height="18" rx="2" /><path d="M8 10v7m0-10v.2m4 9.8v-7h0m0 3c0-4 6-4 6 0v4" /></svg>;
}

function FooterColumn({ title, nodes }: { title: string; nodes: NavNode[] }) {
    return <section className="min-w-0">
        <h2 className="mb-5 inline-block border-b border-[#ff9800]/45 pb-3 text-base font-bold uppercase tracking-[.015em] text-white">{title}</h2>
        <nav aria-label={title}>
            <ul className="space-y-3 text-[15px] leading-5 text-[#e7edf9]">
                {nodes.map((node) => <li key={node.item.id}>
                    <PublicNavigationLink item={node.item} className="break-words hover:text-[#ffa20b] focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ffa20b]" />
                    {node.children.length > 0 && <ul className="mt-2 space-y-2 border-l border-white/20 pl-3">{node.children.map((child) => <li key={child.item.id}><PublicNavigationLink item={child.item} className="hover:text-[#ffa20b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ffa20b]" /></li>)}</ul>}
                </li>)}
            </ul>
        </nav>
    </section>;
}

/** Published-only CMS public shell, with reference colors and responsive geometry. */
export default function PublicSiteLayout({ children }: PropsWithChildren) {
    const [menuOpen, setMenuOpen] = useState(false);
    const [chrome, setChrome] = useState<PublicChrome>(() => fallbackChrome());
    const page = usePage();
    const shared = page.props as { auth?: { user?: { id?: number } | null } };
    const signedIn = Boolean(shared.auth?.user);
    const activePath = typeof page.url === 'string' ? page.url.split('?')[0] : '/';
    const menuToggleRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        const controller = new AbortController();
        void fetch('/api/public/site-chrome', {
            method: 'GET', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json' }, signal: controller.signal,
        }).then((response) => {
            if (!response.ok) throw new Error('Public CMS header unavailable');
            return response.json() as Promise<unknown>;
        }).then((payload) => {
            if (!controller.signal.aborted) setChrome(decodePublishedChrome(payload));
        }).catch(() => {
            // Preserve safe fallback chrome when the published-only API is unreachable.
        });
        return () => controller.abort();
    }, []);

    function onMobileNavigationKeyDown(event: KeyboardEvent<HTMLElement>) {
        if (event.key === 'Escape') {
            setMenuOpen(false);
            menuToggleRef.current?.focus();
        }
    }

    const settings = chrome.settings;
    const headerMenu = chrome.menus['main-header'];
    const headerNodes = buildMenuTree(headerMenu ? headerMenu.items : referenceHeader);
    const footerMenu = chrome.menus['footer-links'] ?? chrome.menus.footer;
    const footerNodes = buildMenuTree(footerMenu ? footerMenu.items : referenceBottom);
    const domesticMenu = chrome.menus['footer-domestic'];
    const globalMenu = chrome.menus['footer-global'];
    const domesticHeader = headerNodes.find((n) => /domestic/i.test(n.item.label));
    const globalHeader = headerNodes.find((n) => /global|international/i.test(n.item.label));
    const domesticNodes = buildMenuTree(domesticMenu ? domesticMenu.items : domesticHeader?.children.map((n) => ({ ...n.item, parent_id: null })) ?? domesticFallback);
    const globalNodes = buildMenuTree(globalMenu ? globalMenu.items : globalHeader?.children.map((n) => ({ ...n.item, parent_id: null })) ?? internationalFallback);
    // The reference contact values are used only when there is no published contact information at all.
    const hasCmsContact = Boolean(settings.contact.phone || settings.contact.email || settings.contact.office_address);
    const phone = hasCmsContact ? settings.contact.phone : fallbackPhone;
    const email = hasCmsContact ? settings.contact.email : fallbackEmail;
    const office = hasCmsContact ? settings.contact.office_address : fallbackOffice;
    const phoneDigits = phone.replace(/[^0-9+]/g, '');
    const whatsappDigits = settings.contact.whatsapp.replace(/\D/g, '');
    const socials = Object.entries(settings.social).filter(([, url]) => url !== '');
    const brandIsReference = /bably/i.test(settings.branding.site_name);

    return <div data-bablys-public-site="true" className="flex min-h-screen w-full min-w-0 flex-col overflow-x-clip bg-[#f7f9fb] text-[#152643]">
        <a href="#main-content" className="sr-only rounded bg-white p-3 font-semibold text-blue-900 shadow focus:not-sr-only focus:absolute focus:left-4 focus:top-3 focus:z-50 focus:outline-none focus:ring-2 focus:ring-[#ff9900]">Skip to main content</a>
        <header className="relative z-30 w-full bg-white shadow-[0_3px_16px_rgba(1,17,45,.06)]">
            <div data-bablys-utility-bar="true" className="bg-[#03143a] text-white">
                <div className="mx-auto flex min-h-10 w-full max-w-[1600px] flex-wrap items-center justify-between gap-x-5 gap-y-1 px-4 py-2 text-[13px] sm:px-6 lg:py-1.5 xl:px-8">
                    <div className="flex min-w-0 flex-wrap items-center gap-x-6 gap-y-2">
                        {phoneDigits && <a href={`tel:${phoneDigits}`} className="inline-flex min-h-6 items-center gap-2 hover:text-[#ffb443] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800]"><span className="text-[#ff9900]"><SmallIcon name="phone" /></span>{phone}</a>}
                        {email && <a href={`mailto:${email}`} className="inline-flex min-h-6 min-w-0 items-center gap-2 break-all hover:text-[#ffb443] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800]"><span className="text-[#ff9900]"><SmallIcon name="mail" /></span>{email}</a>}
                        {office && <span className="inline-flex min-h-6 min-w-0 items-center gap-2"><span className="shrink-0 text-[#ff3345]"><SmallIcon name="pin" /></span>{office}</span>}
                    </div>
                    <div className="flex items-center gap-2"><span>Follow Us:</span>
                        {socials.map(([name, url]) => <a key={name} href={url} target="_blank" rel="noopener noreferrer" aria-label={`Bablys ${name}`} className="inline-flex h-7 w-7 items-center justify-center rounded-full text-white hover:bg-white/15 hover:text-[#ffa20b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800]"><SmallIcon name={name as 'facebook' | 'instagram' | 'youtube' | 'linkedin'} /></a>)}
                    </div>
                </div>
            </div>
            <div className="mx-auto flex min-h-[94px] w-full max-w-[1600px] items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:gap-5 xl:px-8">
                <Link href="/" aria-label={`${settings.branding.site_name} homepage`} className="flex min-w-0 shrink-0 items-center rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800] focus-visible:ring-offset-2">
                    {brandIsReference ? <img src={brandLogo} width="230" height="58" alt="Bably's Club Travel & Tours" className="h-auto w-[176px] max-w-full object-contain sm:w-[210px]" /> : <span className="flex max-w-[240px] flex-col leading-tight"><strong className="text-xl font-extrabold text-[#094b99]">{settings.branding.site_name}</strong><span className="text-xs text-slate-600">{settings.branding.tagline}</span></span>}
                </Link>
                <nav aria-label="Desktop website navigation" className="hidden min-w-0 flex-1 items-center justify-center xl:flex">
                    <PublicNavigationNodes nodes={headerNodes} mobile={false} activePath={activePath} />
                </nav>
                <div className="hidden shrink-0 items-center gap-2 xl:flex">
                    <Link href="/account/travel" className="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-[#243954] hover:text-[#0d5dba] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800]">{signedIn ? 'My Account' : 'Account / Login'}</Link>
                    <Link href="/tours" aria-label="Find a Tour — Book Now" className="inline-flex min-h-12 items-center gap-2 rounded-full bg-[#ff9800] px-5 py-3 text-sm font-bold text-white shadow-[0_6px_16px_rgba(255,152,0,.18)] transition-colors hover:bg-[#ea8a00] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800] focus-visible:ring-offset-2"><SmallIcon name="compass" /><span>Book Now</span></Link>
                </div>
                <button ref={menuToggleRef} type="button" aria-label={menuOpen ? 'Close website menu' : 'Open website menu'} aria-controls="bablys-public-mobile-nav" aria-expanded={menuOpen} onClick={() => setMenuOpen((open) => !open)} className="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-lg border border-slate-300 px-3 text-sm font-semibold text-[#243954] hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800] xl:hidden">
                    <svg aria-hidden="true" className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d={menuOpen ? 'M5 5l14 14M19 5 5 19' : 'M4 6h16M4 12h16M4 18h16'} /></svg>
                    <span className="md:hidden">Menu</span>
                </button>
            </div>
            <nav id="bablys-public-mobile-nav" aria-label="Mobile website navigation" onKeyDown={onMobileNavigationKeyDown} className={`${menuOpen ? 'block' : 'hidden'} border-t border-slate-200 bg-white px-4 py-3 sm:px-6 xl:hidden`}>
                <div className="mx-auto flex w-full max-w-[1600px] flex-col gap-2">
                    <PublicNavigationNodes nodes={headerNodes} mobile={true} activePath={activePath} onNavigate={() => setMenuOpen(false)} />
                    <Link href="/account/travel" onClick={() => setMenuOpen(false)} className="flex min-h-11 items-center rounded-lg px-3 py-2 font-semibold text-[#243954] hover:bg-blue-50">{signedIn ? 'My Account' : 'Account / Login'}</Link>
                    <Link href="/tours" onClick={() => setMenuOpen(false)} className="flex min-h-11 items-center justify-center gap-2 rounded-full bg-[#ff9800] px-5 py-3 font-bold text-white"><SmallIcon name="compass" />Book Now</Link>
                </div>
            </nav>
        </header>
        <div id="main-content" tabIndex={-1} className="w-full min-w-0 flex-1 focus:outline-none">{children}</div>
        <footer data-bablys-reference-footer="true" className="mt-auto w-full bg-[#03143a] text-[#9fb2d3]">
            <div className="mx-auto w-full max-w-[1520px] px-4 pb-8 pt-14 sm:px-6 lg:px-8 lg:pt-20">
                <div className="grid grid-cols-1 gap-x-9 gap-y-10 sm:grid-cols-2 xl:grid-cols-[1.9fr_.9fr_.9fr_1fr] xl:gap-x-16">
                    <section className="min-w-0 space-y-5">
                        <h2 className="text-3xl font-bold italic tracking-tight text-white">{brandIsReference ? <>Bably’s <span className="text-[#ff9800]">Club</span></> : settings.branding.site_name}</h2>
                        <p className="max-w-[490px] text-[15px] leading-[1.8] text-[#9eb3d5]">{brandIsReference ? footerDescription : settings.branding.tagline}</p>
                        {socials.length > 0 && <nav aria-label="Social media" className="flex flex-wrap gap-3">{socials.map(([name, url]) => <a key={name} href={url} target="_blank" rel="noopener noreferrer" aria-label={`Bablys ${name}`} className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white hover:bg-[#ff9800] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800]"><SmallIcon name={name as 'facebook' | 'instagram' | 'youtube' | 'linkedin'} className="h-5 w-5" /></a>)}</nav>}
                        <div className="space-y-2 text-[14px] text-white">
                            {phone && <p><strong>Contact:</strong> <a href={`tel:${phoneDigits}`} className="hover:text-[#ffa20b]">{phone}</a></p>}
                            {email && <p><strong>Email:</strong> <a href={`mailto:${email}`} className="break-all hover:text-[#ffa20b]">{email}</a></p>}
                            {whatsappDigits && <p><strong>WhatsApp:</strong> <a href={`https://wa.me/${whatsappDigits}`} target="_blank" rel="noopener noreferrer" className="hover:text-[#ffa20b]">{settings.contact.whatsapp}</a></p>}
                        </div>
                    </section>
                    <FooterColumn title="Domestic Tours" nodes={domesticNodes} />
                    <FooterColumn title="Global Destinations" nodes={globalNodes} />
                    <section className="min-w-0">
                        <h2 className="mb-5 inline-block border-b border-[#ff9800]/45 pb-3 text-base font-bold uppercase text-white">Head Office</h2>
                        <div className="space-y-3 text-[15px] leading-[1.6]">
                            {office && <p className="flex items-start gap-3"><span className="mt-1 shrink-0 text-[#ff9800]"><SmallIcon name="pin" /></span><span className="whitespace-pre-line">{office}</span></p>}
                            {phone && <p className="flex items-start gap-3"><span className="mt-1 shrink-0 text-[#ff9800]"><SmallIcon name="phone" /></span><a href={`tel:${phoneDigits}`} className="hover:text-[#ffa20b]">{phone}</a></p>}
                            {email && <p className="flex items-start gap-3"><span className="mt-1 shrink-0 text-[#ff9800]"><SmallIcon name="mail" /></span><a href={`mailto:${email}`} className="min-w-0 break-all hover:text-[#ffa20b]">{email}</a></p>}
                            {settings.contact.business_hours && <p>{settings.contact.business_hours}</p>}
                        </div>
                    </section>
                </div>
                <div className="mt-12 flex flex-col gap-5 border-t border-white/10 py-8 text-sm text-[#8196bc] lg:flex-row lg:items-center lg:justify-between">
                    <p>{settings.legal.copyright_notice || `© ${new Date().getFullYear()} Bably's Travel & Tour (Bablys.club). All Rights Reserved.`}</p>
                    <nav aria-label="Legal navigation" className="flex flex-wrap gap-x-6 gap-y-3">
                        {settings.legal.privacy_url && <a href={settings.legal.privacy_url} target="_blank" rel="noopener noreferrer" className="hover:text-[#ffa20b]">Privacy Policy</a>}
                        {settings.legal.terms_url && <a href={settings.legal.terms_url} target="_blank" rel="noopener noreferrer" className="hover:text-[#ffa20b]">Terms of Service</a>}
                    </nav>
                </div>
                <nav aria-label="Footer website navigation" className="border-t border-white/10 pt-6">
                    <ul className="flex flex-wrap gap-x-7 gap-y-3 text-sm text-[#e8eefa]">
                        {footerNodes.map((node) => <li key={node.item.id}><PublicNavigationLink item={node.item} className="hover:text-[#ffa20b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ff9800]" /></li>)}
                    </ul>
                </nav>
                <p className="mt-7 text-xs text-[#8399bf]">Tour requests are enquiries. Availability and prices require staff confirmation.</p>
            </div>
        </footer>
    </div>;
}
