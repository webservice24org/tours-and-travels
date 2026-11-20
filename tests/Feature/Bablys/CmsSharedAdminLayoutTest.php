<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Tests\TestCase;

/**
 * Source-level guard: Bablys admin pages share one shell, and server-authorized
 * navigation is provided by the existing P3B.5 endpoint.
 */
final class CmsSharedAdminLayoutTest extends TestCase
{
    /** @return list<string> */
    private function adminPages(): array
    {
        return [
            'resources/js/pages/admin/index.tsx',
            'resources/js/pages/admin/staff-users/index.tsx',
            'resources/js/pages/admin/roles/index.tsx',
            'resources/js/pages/admin/audit-logs/index.tsx',
            'resources/js/pages/admin/tour-categories/index.tsx',
            'resources/js/pages/admin/destinations/index.tsx',
        ];
    }

    public function test_all_six_admin_pages_use_the_shared_layout(): void
    {
        foreach ($this->adminPages() as $page) {
            $source = (string) file_get_contents(base_path($page));
            self::assertStringContainsString('import BablysAdminLayout', $source, $page);
            self::assertStringContainsString('<BablysAdminLayout>', $source, $page);
            self::assertStringContainsString('</BablysAdminLayout>', $source, $page);
        }
    }

    public function test_admin_pages_do_not_render_the_legacy_horizontal_menu(): void
    {
        foreach ($this->adminPages() as $page) {
            $source = (string) file_get_contents(base_path($page));
            self::assertStringNotContainsString('<CmsAdminNavigation />', $source, $page);
            self::assertSame(1, substr_count($source, '<BablysAdminLayout>'), $page);
        }
    }

    public function test_existing_laravel_sidebar_owns_cms_navigation(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/layouts/bablys-admin-layout.tsx'));
        $sidebar = (string) file_get_contents(base_path('resources/js/components/app-sidebar.tsx'));
        self::assertStringNotContainsString('<aside', $layout);
        self::assertStringNotContainsString('<CmsSidebarMenu', $layout);
        self::assertStringContainsString('Skip to content', $layout);
        self::assertStringContainsString('<BablysNativeSidebarNav />', $sidebar);
        self::assertStringContainsString('<Sidebar ', $sidebar);
    }

    public function test_native_sidebar_fetches_permission_filtered_groups(): void
    {
        $native = (string) file_get_contents(base_path('resources/js/components/admin/navigation/BablysNativeSidebarNav.tsx'));
        self::assertStringContainsString("fetch('/admin/internal/navigation'", $native);
        self::assertStringContainsString("cache: 'no-store'", $native);
        self::assertStringContainsString('credentials:', $native);
        self::assertStringContainsString('controller.abort()', $native);
    }
}
