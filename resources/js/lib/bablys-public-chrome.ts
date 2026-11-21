/**
 * Defensive projection for published-only CMS header/footer data.
 * CMS input is still treated as untrusted at the browser boundary.
 */
export type PublicSettings = {
    branding: { site_name: string; tagline: string; legal_name: string };
    contact: { email: string; phone: string; whatsapp: string; office_address: string; business_hours: string };
    social: { facebook: string; instagram: string; youtube: string; linkedin: string };
    legal: { copyright_notice: string; privacy_url: string; terms_url: string };
};

export type PublicNavItem = {
    id: string;
    label: string;
    kind: 'internal' | 'external';
    url: string;
    parent_id: string | null;
    new_tab: boolean;
};
export type PublicMenu = { name: string; items: PublicNavItem[] };
export type PublicChrome = { settings: PublicSettings; menus: Record<string, PublicMenu> };
export type NavNode = { item: PublicNavItem; children: NavNode[] };

const text = (value: unknown, fallback = '', max = 500): string =>
    typeof value === 'string' && value.trim().length <= max ? value.trim() : fallback;

function record(value: unknown): Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
        ? value as Record<string, unknown> : {};
}

/** Do not allow javascript:, protocol-relative, credentialed or unsafe links. */
export function safePublishedUrl(kind: 'internal' | 'external', value: string): boolean {
    if (value.length === 0 || value.length > 350 || /[<>\\\s\x00-\x1f\x7f]/.test(value)) return false;
    if (kind === 'internal') return /^\/(?!\/)[A-Za-z0-9/_~.\-?=&%#]*$/.test(value);
    try {
        const parsed = new URL(value);
        return parsed.protocol === 'https:' && parsed.hostname.length > 0
            && parsed.username === '' && parsed.password === '';
    } catch {
        return false;
    }
}

const fallbackSettings: PublicSettings = {
    branding: { site_name: "Bably's Club", tagline: 'Tours & Travels', legal_name: '' },
    contact: { email: '', phone: '', whatsapp: '', office_address: '', business_hours: '' },
    social: { facebook: '', instagram: '', youtube: '', linkedin: '' },
    legal: { copyright_notice: '', privacy_url: '', terms_url: '' },
};

export function fallbackChrome(): PublicChrome {
    return { settings: {
        branding: { ...fallbackSettings.branding }, contact: { ...fallbackSettings.contact },
        social: { ...fallbackSettings.social }, legal: { ...fallbackSettings.legal },
    }, menus: {} };
}

export const fallbackHeader: PublicNavItem[] = [
    { id: 'default-home', label: 'Home', kind: 'internal', url: '/', parent_id: null, new_tab: false },
    { id: 'default-tours', label: 'Explore Tours', kind: 'internal', url: '/tours', parent_id: null, new_tab: false },
];

export function decodePublishedChrome(value: unknown): PublicChrome {
    const top = record(value);
    const groups = record(top.settings);
    const base = fallbackChrome();
    const branding = record(groups.branding);
    const contact = record(groups.contact);
    const social = record(groups.social);
    const legal = record(groups.legal);
    base.settings.branding.site_name = text(branding.site_name, base.settings.branding.site_name, 80) || base.settings.branding.site_name;
    base.settings.branding.tagline = text(branding.tagline, base.settings.branding.tagline, 160);
    base.settings.branding.legal_name = text(branding.legal_name, '', 120);
    base.settings.contact.email = text(contact.email, '', 254);
    base.settings.contact.phone = text(contact.phone, '', 40);
    base.settings.contact.whatsapp = text(contact.whatsapp, '', 40);
    base.settings.contact.office_address = text(contact.office_address, '', 500);
    base.settings.contact.business_hours = text(contact.business_hours, '', 240);
    for (const key of ['facebook', 'instagram', 'youtube', 'linkedin'] as const) {
        const url = text(social[key], '', 300);
        base.settings.social[key] = safePublishedUrl('external', url) ? url : '';
    }
    base.settings.legal.copyright_notice = text(legal.copyright_notice, '', 240);
    for (const key of ['privacy_url', 'terms_url'] as const) {
        const url = text(legal[key], '', 300);
        base.settings.legal[key] = safePublishedUrl('external', url) ? url : '';
    }
    const rawMenus = record(top.menus);
    for (const [key, value] of Object.entries(rawMenus)) {
        if (!/^[a-z][a-z0-9-]{2,47}$/.test(key)) continue;
        const rawMenu = record(value);
        if (!Array.isArray(rawMenu.items) || rawMenu.items.length > 80) continue;
        const items: PublicNavItem[] = [];
        const ids = new Set<string>();
        for (const rawItem of rawMenu.items) {
            const item = record(rawItem);
            const id = text(item.id, '', 60);
            const label = text(item.label, '', 100);
            const kind = item.kind;
            const url = text(item.url, '', 350);
            const parent = item.parent_id;
            if (!id || ids.has(id) || !label || (kind !== 'internal' && kind !== 'external')
                || !safePublishedUrl(kind, url)
                || (parent !== null && typeof parent !== 'string')
                || (kind === 'internal' && item.new_tab === true)) continue;
            ids.add(id);
            items.push({ id, label, kind, url, parent_id: parent, new_tab: kind === 'external' && item.new_tab === true });
        }
        base.menus[key] = { name: text(rawMenu.name, key, 100), items };
    }
    return base;
}

/** Deterministic sibling order, orphan/cycle protection, three-level maximum. */
export function buildMenuTree(items: PublicNavItem[]): NavNode[] {
    const childrenOf = (parent: string | null, seen: ReadonlySet<string>, depth: number): NavNode[] => {
        if (depth > 3) return [];
        const result: NavNode[] = [];
        for (const item of items) {
            if (item.parent_id !== parent || seen.has(item.id)) continue;
            const nextSeen = new Set(seen);
            nextSeen.add(item.id);
            result.push({ item, children: childrenOf(item.id, nextSeen, depth + 1) });
        }
        return result;
    };
    return childrenOf(null, new Set<string>(), 1);
}
