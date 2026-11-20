import type { ReactNode } from 'react';
import { AdminFeedbackHost } from '@/components/admin/feedback/AdminFeedbackHost';

interface BablysAdminLayoutProps {
    children: ReactNode;
}

/**
 * Bablys page-level content wrapper.
 * The Laravel starter-kit AppLayout / AppSidebar owns ALL navigation,
 * including the mobile drawer. Never nest a second admin sidebar here.
 */
export default function BablysAdminLayout({ children }: BablysAdminLayoutProps) {
    return (
        <div className="min-w-0 w-full overflow-x-clip bg-slate-50 text-slate-900">
            <a href="#bablys-admin-content" className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:p-3 focus:text-teal-900">
                Skip to content
            </a>
            <main id="bablys-admin-content" className="min-w-0 w-full">
                {children}
                <AdminFeedbackHost />
            </main>
        </div>
    );
}
