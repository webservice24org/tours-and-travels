<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Tests\TestCase;

/**
 * P3G source integration tripwires. These do not establish browser WCAG or SSR
 * conformance; run the separate manual launch acceptance checklist too.
 */
final class PublicTourReleaseSourceContractTest extends TestCase
{
    private function source(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        self::assertIsString($contents, 'Expected checked-in source missing: '.$path);

        return $contents;
    }

    public function test_public_shell_and_pages_do_not_import_the_dashboard_sidebar(): void
    {
        foreach ([
            'resources/js/layouts/public-site-layout.tsx',
            'resources/js/pages/public/tours/index.tsx',
            'resources/js/pages/public/tours/show.tsx',
            'resources/js/pages/public/booking-requests/create.tsx',
            'resources/js/pages/public/booking-requests/manage.tsx',
            'resources/js/pages/public/booking-requests/verify.tsx',
            'resources/js/pages/public/booking-requests/submitted.tsx',
            'resources/js/pages/account/booking-requests/index.tsx',
        ] as $path) {
            $contents = $this->source($path);
            self::assertStringNotContainsString('AppSidebar', $contents);
            self::assertStringNotContainsString("from '@/layouts/app-layout'", $contents);
        }
    }

    public function test_public_site_has_skip_link_and_keyboard_mobile_menu(): void
    {
        $site = $this->source('resources/js/layouts/public-site-layout.tsx');
        self::assertStringContainsString('href="#main-content"', $site);
        self::assertStringContainsString('aria-expanded={menuOpen}', $site);
        self::assertStringContainsString('aria-controls="bablys-public-mobile-nav"', $site);
        self::assertStringContainsString('menuToggleRef.current?.focus()', $site);
    }

    public function test_detail_supports_accessible_gallery_and_canonical_metadata(): void
    {
        $detail = $this->source('resources/js/pages/public/tours/show.tsx');
        self::assertStringContainsString('aria-pressed={index === visibleIndex}', $detail);
        self::assertStringContainsString('rel="canonical"', $detail);
        self::assertStringContainsString('og:url', $detail);
        self::assertStringContainsString('onThumbnailKeyDown', $detail);
    }

    public function test_enquiry_provides_error_focus_and_stale_departure_notice(): void
    {
        $form = $this->source('resources/js/pages/public/booking-requests/create.tsx');
        self::assertStringContainsString('errorSummaryRef.current?.focus()', $form);
        self::assertStringContainsString('departure-selection-notice', $form);
        self::assertStringContainsString('aria-invalid={Boolean(form.errors.departure_id)}', $form);
        self::assertStringContainsString('No seats are reserved', $form);
    }

    public function test_owner_scoped_retry_and_locked_idempotent_conversion_are_retained(): void
    {
        $public = $this->source('app/Http/Controllers/Public/Travel/PublicBookingRequestController.php');
        $manager = $this->source('app/Domain/Travel/Bookings/TourBookingRequestManager.php');
        self::assertStringContainsString('bablys:booking-request:v2|', $public);
        self::assertStringContainsString('$submissionOwner', $public);
        self::assertStringNotContainsString('$sessionIdentifier', $public);
        self::assertStringContainsString('departure_selection', $public);
        self::assertStringContainsString('lockForUpdate()', $manager);
    }

    public function test_listing_crawler_policy_includes_header_and_canonical(): void
    {
        $controller = $this->source('app/Http/Controllers/Public/Travel/PublicTourIndexController.php');
        $listing = $this->source('resources/js/pages/public/tours/index.tsx');
        self::assertStringContainsString("'X-Robots-Tag', 'noindex, follow'", $controller);
        self::assertStringContainsString("'canonical_url' =>", $controller);
        self::assertStringContainsString('rel="canonical"', $listing);
        self::assertStringContainsString('name="robots"', $listing);
    }
}
