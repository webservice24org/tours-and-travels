<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Tests\TestCase;

/** P4A.4B design-parity source contracts; does not mutate booking or CMS tables. */
final class PublicHeaderFooterDesignParityTest extends TestCase
{
    private function layout(): string
    {
        $raw = file_get_contents(base_path('resources/js/layouts/public-site-layout.tsx'));
        self::assertIsString($raw);
        return $raw;
    }

    public function test_reference_logo_is_local_png_not_a_remote_hotlink(): void
    {
        $path = public_path('images/bablys-club-reference-logo.png');
        self::assertFileExists($path);
        $image = getimagesize($path);
        self::assertIsArray($image);
        self::assertSame(IMAGETYPE_PNG, $image[2]);
        self::assertGreaterThanOrEqual(190, $image[0]);
        self::assertStringContainsString("const brandLogo = '/images/bablys-club-reference-logo.png'", $this->layout());
    }

    public function test_two_part_header_matches_reference_structure(): void
    {
        $code = $this->layout();
        foreach (['data-bablys-utility-bar', 'Follow Us:', 'Book Now', 'Desktop website navigation',
            'Mobile website navigation', 'bg-[#03143a]', 'bg-[#ff9800]', 'SmallIcon name="phone"',
            'SmallIcon name="mail"', 'SmallIcon name="pin"'] as $fragment) {
            self::assertStringContainsString($fragment, $code);
        }
    }

    public function test_four_column_footer_has_reference_groups_and_cms_routes(): void
    {
        $code = $this->layout();
        foreach (['data-bablys-reference-footer', 'Domestic Tours', 'Global Destinations',
            'Head Office', "chrome.menus['footer-domestic']", "chrome.menus['footer-global']",
            "chrome.menus['footer-links']", 'FooterColumn'] as $fragment) {
            self::assertStringContainsString($fragment, $code);
        }
        self::assertStringContainsString('footerDescription', $code);
    }

    public function test_cms_published_only_data_and_fallbacks_are_preserved(): void
    {
        $code = $this->layout();
        foreach (["fetch('/api/public/site-chrome'", "cache: 'no-store'", 'decodePublishedChrome(payload)',
            'fallbackChrome()', 'fallbackHeader', "chrome.menus['main-header']",
            'settings.branding.site_name', 'settings.contact.office_address',
            'settings.legal.privacy_url'] as $fragment) {
            self::assertStringContainsString($fragment, $code);
        }
        self::assertStringNotContainsString('/admin/cms-publishing', $code);
        self::assertStringNotContainsString('draft_payload', $code);
    }

    public function test_keyboard_mobile_accessibility_and_skip_link_remain(): void
    {
        $code = $this->layout();
        foreach (['href="#main-content"', 'id="main-content" tabIndex={-1}',
            'aria-controls="bablys-public-mobile-nav"', 'aria-expanded={menuOpen}',
            "event.key === 'Escape'", 'menuToggleRef.current?.focus()',
            'aria-label="Desktop website navigation"', 'aria-label="Footer website navigation"',
            'focus-visible:ring-2'] as $fragment) {
            self::assertStringContainsString($fragment, $code);
        }
    }

    public function test_account_and_nonbinding_booking_boundaries_are_retained(): void
    {
        $code = $this->layout();
        self::assertStringContainsString('/account/travel', $code);
        self::assertStringContainsString('Find a Tour', $code);
        self::assertStringContainsString('Tour requests are enquiries', $code);
        self::assertStringNotContainsString('AppSidebar', $code);
        self::assertStringNotContainsString('AppLayout', $code);
        self::assertStringNotContainsString('seat_allocation_id', $code);
    }

    public function test_external_links_keep_safe_navigation_attributes(): void
    {
        $code = $this->layout();
        self::assertStringContainsString('rel="noopener noreferrer"', $code);
        self::assertStringContainsString('item.new_tab', $code);
        self::assertStringContainsString('decodePublishedChrome', $code);
        self::assertStringNotContainsString('dangerouslySetInnerHTML', $code);
        self::assertStringNotContainsString('javascript:', $code);
    }

    public function test_reference_fallback_links_use_existing_safe_public_tours_route(): void
    {
        $code = $this->layout();
        self::assertStringContainsString("referenceItem('domestic-group', 'Domestic Group Tours', '/tours')", $code);
        self::assertStringContainsString("referenceItem('global-dubai', 'Dubai Luxury Tours', '/tours')", $code);
        self::assertStringNotContainsString('http://localhost', $code);
    }
}
