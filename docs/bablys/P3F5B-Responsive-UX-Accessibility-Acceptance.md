# P3F.5B — Responsive UX & Accessibility Acceptance (Bablys Only)

Scope: public website shell, `/tours`, `/tours/{slug}`, `/tours/{slug}/booking-request`.
No admin layout or backend request/quote/payment/seat modifications are permitted.
This is **not** a claim of WCAG certification: PHP source tests validate checked-in JSX contracts; real browser and assistive-technology testing remains mandatory.

## Browser matrix and scenarios

- Check 320, 375, 390, 768, 1024 and 1440 CSS-pixel viewports, 200% and 400% zoom. No horizontal scrolling at 320 CSS px except for intentionally scrollable galleries. Confirm header text does not cover mobile toggle.
- Navigate using only Tab, Shift+Tab, Enter, Space and Escape. Confirm skip-to-content moves keyboard focus to the tour page, and menu Escape closes and returns focus to the toggle. Tab order must remain logical.
- Verify mobile menu opens/closes with correct accessible name and `aria-expanded`. Menu links should close it after selecting a destination. Desktop links should be visible without opening a drawer.
- Tour listing: keyboard-operate search/category/destination/sort controls and apply/clear links, use Previous/Next, inspect no-results message, and confirm full-query pagination retains current filters. Check long tour names and summaries without overflow.
- Tour detail: use ArrowLeft, ArrowRight, Home and End on gallery thumbnails, and previous/next buttons. Check selected `aria-pressed`, announced position, broken-image fallback and descriptive hero image alt. Follow anchor links to itinerary/departures/enquiry. Check empty itinerary/photos/departures.
- Enquiry: submit invalid fields, confirm one error summary appears, keyboard focus moves to it, and each error link reaches its corresponding field. Confirm form fields expose `aria-invalid` and `aria-describedby`, and selected departure notice is visible.
- Check stale/closed departure query hint requires a new selection, and quote preview/submission remain disabled until a valid departure is chosen. Do not leak internal departure IDs in URL.
- Confirm `Enquire Now` still opens a *nonbinding* request. No seat is reserved, no payment taken, and no confirmed price is implied by public views.
- Verify keyboard focus contrast; default, hover and disabled contrast in a real browser. Prefer reduced motion in OS settings and confirm large image hover animation is suppressed.
- Run a screen-reader pass (NVDA + Firefox/Chrome on Windows recommended) over all four pages. Confirm landmark names, mobile nav announcements, field errors and form status. No automatic source test can substitute for this.
- Check public pages use Bablys header/footer and **never** the Laravel dashboard sidebar. Verify admin pages still use their existing admin shell.

## Technical QA

Run focused source-contract tests and prior public tour/booking regressions on dedicated `bablys_suite_clean_test` (APP_ENV=testing; race opt-in disabled). Then run full `php artisan test`. Separately run `vendor\bin\phpstan analyse`, `npm.cmd run types:check` and `npm.cmd run build` on a normal development terminal. Keep the two test databases separate from `bablys_travel`.

## Release gate

Manual browser/accessibility review must be signed off separately from automated tests. Do not declare P3F.5 complete until P3F.5B browser sign-off and the planned P3F.5C closure checks.
