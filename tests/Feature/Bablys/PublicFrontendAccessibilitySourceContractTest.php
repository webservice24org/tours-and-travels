<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Tests\TestCase;

/**
 * P3F.5B source-contract regression checks. They verify the checked-in
 * accessibility structure, NOT computed browser accessibility or WCAG conformance.
 * Manual keyboard, zoom and screen-reader acceptance is required separately.
 */
final class PublicFrontendAccessibilitySourceContractTest extends TestCase
{
    private function source(string $relative): string
    {
        $source = file_get_contents(resource_path('js/'.$relative));
        self::assertIsString($source, 'Required frontend source is missing: '.$relative);

        return $source;
    }

    public function test_public_shell_never_imports_dashboard_sidebar(): void
    {
        $page = $this->source('layouts/public-site-layout.tsx');
        self::assertStringContainsString('data-bablys-public-site="true"', $page);
        self::assertStringNotContainsString('AppSidebar', $page);
        self::assertStringNotContainsString('AppLayout', $page);
    }

    public function test_public_shell_has_focusable_skip_target(): void
    {
        $page = $this->source('layouts/public-site-layout.tsx');
        self::assertStringContainsString('href="#main-content"', $page);
        self::assertStringContainsString('id="main-content" tabIndex={-1}', $page);
    }

    public function test_mobile_menu_has_button_expanded_and_controlled_region(): void
    {
        $page = $this->source('layouts/public-site-layout.tsx');
        self::assertStringContainsString('aria-controls="bablys-public-mobile-nav"', $page);
        self::assertStringContainsString('aria-expanded={menuOpen}', $page);
        self::assertStringContainsString('id="bablys-public-mobile-nav"', $page);
        self::assertStringContainsString('type="button"', $page);
        self::assertStringContainsString('md:hidden', $page);
    }

    public function test_mobile_menu_esc_returns_keyboard_focus_to_trigger(): void
    {
        $page = $this->source('layouts/public-site-layout.tsx');
        self::assertStringContainsString("event.key === 'Escape'", $page);
        self::assertStringContainsString('menuToggleRef.current?.focus()', $page);
    }

    public function test_listing_form_has_described_filter_controls(): void
    {
        $page = $this->source('pages/public/tours/index.tsx');
        self::assertStringContainsString('role="search"', $page);
        self::assertStringContainsString('aria-describedby="tour-category-help"', $page);
        self::assertStringContainsString('aria-describedby="tour-destination-help"', $page);
        self::assertStringContainsString('aria-label="Matching published tour packages"', $page);
    }

    public function test_listing_has_results_and_pagination_announcements(): void
    {
        $page = $this->source('pages/public/tours/index.tsx');
        self::assertStringContainsString('aria-live="polite"', $page);
        self::assertStringContainsString('aria-label="Tour results pagination"', $page);
        self::assertStringContainsString('aria-current="page"', $page);
        self::assertStringContainsString('motion-reduce:transition-none', $page);
    }

    public function test_gallery_controls_are_named_and_target_the_hero(): void
    {
        $page = $this->source('pages/public/tours/show.tsx');
        self::assertStringContainsString('id="tour-gallery-hero"', $page);
        self::assertStringContainsString('aria-controls="tour-gallery-hero"', $page);
        self::assertStringContainsString('aria-pressed={index === visibleIndex}', $page);
        self::assertStringContainsString('onThumbnailKeyDown', $page);
    }

    public function test_gallery_has_politely_announced_position_and_preserves_seo(): void
    {
        $page = $this->source('pages/public/tours/show.tsx');
        self::assertStringContainsString('role="status"', $page);
        self::assertStringContainsString('aria-atomic="true"', $page);
        self::assertStringContainsString('rel="canonical"', $page);
        self::assertStringContainsString('og:url', $page);
    }

    public function test_enquiry_form_has_focusable_linked_error_summary(): void
    {
        $page = $this->source('pages/public/booking-requests/create.tsx');
        self::assertStringContainsString('id="booking-errors-heading"', $page);
        self::assertStringContainsString('errorSummaryRef.current?.focus()', $page);
        self::assertStringContainsString('href={`#${errorFieldIds[name]}`}', $page);
    }

    public function test_enquiry_field_errors_are_aria_linked(): void
    {
        $page = $this->source('pages/public/booking-requests/create.tsx');
        foreach (['departure', 'option', 'adults', 'children', 'contact_name', 'contact_email', 'contact_phone'] as $id) {
            self::assertStringContainsString('id="'.$id.'"', $page);
            self::assertStringContainsString('id="'.$id.'-error"', $page);
        }
        self::assertStringContainsString('aria-invalid={Boolean(form.errors.departure_id)}', $page);
        self::assertStringContainsString('aria-invalid={Boolean(form.errors.consent)}', $page);
    }

    public function test_nonbinding_enquiry_warnings_are_retained(): void
    {
        $page = $this->source('pages/public/booking-requests/create.tsx');
        self::assertStringContainsString('No seats are reserved', $page);
        self::assertStringContainsString('noDepartureSelected', $page);
        self::assertStringContainsString('departure-selection-notice', $page);
        self::assertStringContainsString('role="status"', $page);
        self::assertStringContainsString('onError:', $page);
    }
}
