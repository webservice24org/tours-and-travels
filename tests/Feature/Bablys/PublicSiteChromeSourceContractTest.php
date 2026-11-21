<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Source contracts for the P4A.4 client integration and existing page boundaries. */
final class PublicSiteChromeSourceContractTest extends TestCase
{
    private function source(string $path): string
    {
        $raw = file_get_contents(base_path($path));
        self::assertIsString($raw, $path);
        return $raw;
    }

    public function test_public_endpoint_is_get_only_and_does_not_require_a_customer_login(): void
    {
        self::assertTrue(Route::has('bablys.public.site-chrome'));
        $route = Route::getRoutes()->getByName('bablys.public.site-chrome');
        self::assertNotNull($route);
        self::assertSame('api/public/site-chrome', $route->uri());
        self::assertContains('GET', $route->methods());
        self::assertNotContains('POST', $route->methods());
    }

    public function test_chrome_controller_reads_only_published_domain_snapshots(): void
    {
        $code = $this->source('app/Http/Controllers/Public/BablysPublicChromeController.php');
        self::assertStringContainsString('->publicSnapshot()', $code);
        self::assertStringContainsString("'no-store, max-age=0'", $code);
        self::assertStringNotContainsString('editorSnapshot()', $code);
        self::assertStringNotContainsString('draft_payload', $code);
        self::assertStringNotContainsString('published_by', $code);
    }

    public function test_public_layout_fetches_only_published_chrome_and_keeps_safe_defaults(): void
    {
        $layout = $this->source('resources/js/layouts/public-site-layout.tsx');
        self::assertStringContainsString("fetch('/api/public/site-chrome'", $layout);
        self::assertStringContainsString("cache: 'no-store'", $layout);
        self::assertStringContainsString('fallbackChrome()', $layout);
        self::assertStringNotContainsString('/admin/cms-publishing', $layout);
        self::assertStringNotContainsString('draft_items', $layout);
    }

    public function test_mobile_navigation_keyboard_and_skip_link_still_exist(): void
    {
        $layout = $this->source('resources/js/layouts/public-site-layout.tsx');
        foreach (['PublicSiteLayout', 'aria-expanded', 'aria-controls', 'Escape', 'Skip to main content',
            'bablys-public-mobile-nav', 'onMobileNavigationKeyDown', 'menuToggleRef.current?.focus()'] as $fragment) {
            self::assertStringContainsString($fragment, $layout);
        }
        self::assertStringNotContainsString('AppSidebar', $layout);
        self::assertStringNotContainsString('AppLayout', $layout);
    }

    public function test_header_uses_published_main_header_and_preserves_customer_account_access(): void
    {
        $layout = $this->source('resources/js/layouts/public-site-layout.tsx');
        self::assertStringContainsString("chrome.menus['main-header']", $layout);
        self::assertStringContainsString('fallbackHeader', $layout);
        self::assertStringContainsString('/account/travel', $layout);
        self::assertStringContainsString('Find a Tour', $layout);
        self::assertStringContainsString('Desktop website navigation', $layout);
    }

    public function test_footer_uses_published_links_and_brand_contact_and_legal_fields(): void
    {
        $layout = $this->source('resources/js/layouts/public-site-layout.tsx');
        foreach (['footer-links', 'settings.branding.site_name', 'settings.contact.office_address',
            'settings.contact.email', 'settings.legal.privacy_url', 'settings.social',
            'Tour requests are enquiries'] as $fragment) {
            self::assertStringContainsString($fragment, $layout);
        }
    }

    public function test_links_reject_unsafe_schemes_and_new_tabs_use_protective_rel(): void
    {
        $client = $this->source('resources/js/lib/bablys-public-chrome.ts');
        $layout = $this->source('resources/js/layouts/public-site-layout.tsx');
        self::assertStringContainsString('safePublishedUrl', $client);
        self::assertStringContainsString("parsed.protocol === 'https:'", $client);
        self::assertStringContainsString('parsed.username', $client);
        self::assertStringContainsString('buildMenuTree', $client);
        self::assertStringContainsString('noopener noreferrer', $layout);
        self::assertStringNotContainsString('dangerouslySetInnerHTML', $layout);
    }

    public function test_public_site_does_not_edit_bookings_prices_or_permission_routes(): void
    {
        $routes = $this->source('routes/bablys_public_chrome.php');
        self::assertStringContainsString('BablysPublicChromeController', $routes);
        self::assertStringNotContainsString('booking', strtolower($routes));
        $layout = $this->source('resources/js/layouts/public-site-layout.tsx');
        self::assertStringNotContainsString('/admin/booking', $layout);
        self::assertStringNotContainsString('seat_allocation_id', $layout);
    }
}
