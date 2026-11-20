import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Circle, ClipboardList, Globe2, House, LayoutDashboard, MapPin, Plane, ShieldCheck, Tags, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

type CmsNavLink = { label: string; href: string };
type CmsNavGroup = { key: string; title: string; links: CmsNavLink[] };
type CmsNavigationResponse = { data: CmsNavGroup[] };

type LoadState = 'loading' | 'ready' | 'error';

const menuIcons: Record<string, LucideIcon> = {
    '/admin': House,
    '/dashboard': LayoutDashboard,
    '/': Globe2,
    '/admin/tour-categories': Tags,
    '/admin/destinations': MapPin,
    '/admin/tours': Plane,
    '/admin/staff-users': Users,
    '/admin/roles': ShieldCheck,
    '/admin/audit-logs': ClipboardList,
};

/**
 * Bablys CMS navigation rendered INSIDE the existing Laravel AppSidebar.
 * Permissions come from the server; hiding a link is not authorization.
 * Backend middleware and policies remain the source of truth.
 */
export function BablysNativeSidebarNav() {
    const { url } = usePage();
    const currentPath = url.split('?')[0].replace(/\/$/, '') || '/';
    const [groups, setGroups] = useState<CmsNavGroup[]>([]);
    const [status, setStatus] = useState<LoadState>('loading');

    useEffect(() => {
        const controller = new AbortController();
        setStatus('loading');
        setGroups([]);

        async function fetchNavigation() {
            try {
                const response = await fetch('/admin/internal/navigation', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    signal: controller.signal,
                });

                if (!response.ok) throw new Error('Unauthorized navigation response');
                const payload = (await response.json()) as CmsNavigationResponse;
                if (!Array.isArray(payload.data)) throw new Error('Invalid navigation response');

                if (!controller.signal.aborted) {
                    setGroups(payload.data);
                    setStatus('ready');
                }
            } catch {
                if (!controller.signal.aborted) {
                    setGroups([]);
                    setStatus('error');
                }
            }
        }

        void fetchNavigation();
        return () => controller.abort();
    }, [currentPath]);

    if (status === 'loading') {
        return <p className="px-4 py-3 text-xs text-muted-foreground" role="status">Loading CMS navigation…</p>;
    }

    if (status === 'error') {
        return <p className="px-4 py-3 text-xs text-destructive" role="alert">CMS navigation is unavailable. Refresh or contact an administrator.</p>;
    }

    return (
        <>
            {groups.map((group) => (
                <SidebarGroup key={group.key}>
                    <SidebarGroupLabel>{group.title}</SidebarGroupLabel>
                    <SidebarMenu>
                        {group.links.map((item) => {
                            const hrefPath = item.href.split('?')[0].replace(/\/$/, '') || '/';
                            const active = hrefPath === currentPath;
                            const Icon = menuIcons[hrefPath] ?? Circle;
                            return (
                                <SidebarMenuItem key={item.href}>
                                    <SidebarMenuButton asChild isActive={active} tooltip={item.label}>
                                        <Link href={item.href} aria-current={active ? 'page' : undefined} prefetch>
                                            <Icon aria-hidden="true" className="size-4 shrink-0" />
                                            <span className="min-w-0 truncate">{item.label}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
            {groups.length === 0 && <p className="px-4 py-3 text-xs text-muted-foreground">No CMS modules are available.</p>}
        </>
    );
}
