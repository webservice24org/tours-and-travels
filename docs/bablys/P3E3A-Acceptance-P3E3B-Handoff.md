# P3E.3A acceptance and P3E.3B editor handoff

## Installation

1. Back up the Bablys database; extract ZIP **outside** the project directory.
2. Run `install-p3e3a.ps1 -ProjectPath C:\xampp\htdocs\bablys-travel -WhatIf`, review the planned additions, then rerun without `-WhatIf`.
3. Run `php artisan migrate:status`, `php artisan migrate --pretend`, `php artisan migrate`.
4. Use a dedicated disposable test DB: run `php artisan test --filter=TourPricingSchemaTest`, then `TourPricingRulesTest`, `TourPriceCalculatorTest`, `TourPricingManagerTest` and `php artisan test`.
5. Run `vendor\bin\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build` and `php artisan bablys:audit:readiness`.

## Acceptance gates

- [ ] All 33 focused tests pass.
- [ ] Full existing Bablys test suite passes.
- [ ] PHPStan has zero errors; TS and Vite build remain green.
- [ ] Both new tables migrated successfully, including FKs and unique constraints.
- [ ] No existing Tour Editor, sidebar, routes, Tiptap, itinerary, shared ConfirmDialog or toast was replaced.
- [ ] An administrator can use `TourPricingManager` to store and preview adult/child and group variants without loss of cents.
- [ ] Content editors cannot modify a published tour without `tours.publish`.
- [ ] No quote is described as a guaranteed booking, departure or final checkout total.

## Next: P3E.3B — React TSX Tour Pricing Editor

- Add the protected GET/PUT `/admin/internal/tours/{tour}/pricing` endpoint and optional staff-only quote preview, backed exclusively by `TourPricingManager`.
- Add `TourPricingEditor.tsx` to the **existing** 9-column editor content area, or a suitable right-side pricing summary card; do not add a new sidebar/layout.
- UI supports add/duplicate/remove/reorder options; BDT/USD/EUR/GBP/JPY selection, adult/child and group amount fields, participant limits, sale amount and time windows.
- Display major-unit strings via precise integer/decimal-string conversion; never compute money through JavaScript floats.
- Keep an explicit **Save Pricing** control to avoid conflicting with Tour Details and Itinerary independent save actions.
- Reuse `confirmAction()` for remove actions and shared toast messages for success/failure.
- Preserve existing Tour `id` requirement: save a new tour before configuring pricing.
- Do not expose public selling prices, booking capacity or checkout until P3E.4/P3E.5 and catalog disclosure checks are complete.
