<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Tests\TestCase;

/** Structural regression guard against reintroducing a nested second sidebar. */
final class CmsUnifiedNativeSidebarTest extends TestCase
{
    public function test_bablys_admin_layout_has_no_own_sidebar_or_header_shell(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/layouts/bablys-admin-layout.tsx'));

        self::assertStringContainsString('id="bablys-admin-content"', $source);
        self::assertStringNotContainsString('<aside', $source);
        self::assertStringNotContainsString('<CmsSidebarMenu', $source);
        self::assertStringNotContainsString('fetch(', $source);
        self::assertStringNotContainsString('<header', $source);
    }

    public function test_existing_laravel_sidebar_owns_bablys_navigation(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/components/app-sidebar.tsx'));

        self::assertStringContainsString('<Sidebar ', $source);
        self::assertStringContainsString('BablysNativeSidebarNav', $source);
        self::assertStringContainsString('<BablysNativeSidebarNav />', $source);
        self::assertStringContainsString("'/admin'", $source);
        self::assertStringContainsString('bablysAdminSidebarActive', $source);
    }

    public function test_native_sidebar_navigation_uses_permission_filtered_read_only_api(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/components/admin/navigation/BablysNativeSidebarNav.tsx'));

        self::assertStringContainsString("fetch('/admin/internal/navigation'", $source);
        self::assertStringContainsString("cache: 'no-store'", $source);
        self::assertStringContainsString("credentials: 'same-origin'", $source);
        self::assertStringContainsString('SidebarMenuButton', $source);
        self::assertStringContainsString('controller.abort()', $source);
    }

    public function test_starter_dashboard_remains_outside_cms_menu(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/components/app-sidebar.tsx'));

        self::assertStringContainsString('bablysAdminSidebarActive ?', $source);
        self::assertStringContainsString('<NavMain items={mainNavItems} />', $source);
        self::assertStringContainsString('<NavUser />', $source);
    }
}
