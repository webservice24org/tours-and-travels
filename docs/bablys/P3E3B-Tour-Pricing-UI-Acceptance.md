# P3E.3B — Pricing React TSX UI, acceptance and safety handoff

## Contract

This milestone assumes P3E.3A completed and P3E.2 TourItineraryEditor mounted in the full-page WordPress-style 9/3 Tour Editor. The new pricing panel is inserted into the existing **9-column main content area**, before itinerary. No second admin sidebar, layout or editor route is created.

- New protected staff endpoints: `GET`/`PUT /admin/internal/tours/{tour}/pricing`; `POST /admin/internal/tours/{tour}/pricing/preview`.
- Backend uses existing `TourPricingManager` (with lock/authorization/audit), `TourPricingRules` and `TourPriceCalculator`. Client totals are never trusted or persisted.
- Rate inputs are major-unit decimal **strings** (`15000.50`, `125.00`, or for JPY `500`); a BigInt converter sends integer minor units in JSON. Zero allowed only for child regular prices. Invalid fractional amounts/overflow fail before HTTP.
- Separate Save Pricing/Discard actions (never submit the parent Tour Information form). No automatic saving of unsaved tour description/itinerary. Existing Tour must be saved before pricing.
- Prices can have active flags, participant limits, optional sale amount and start/end dates, per-person adult with optional child, or fixed group amount. Sale schedule is entered using the browser's local timezone and stored as UTC ISO 8601.
- Read-only staff preview quotes **saved pricing** at server current time, shows discount and rate lines, and never creates booking, capacity reservation, tax or currency conversion.
- Update published/scheduled pricing requires the separate `tours.publish` gate in the locked domain service.
- Uses shared `confirmAction` to remove/discard, toast success/error, and original Laravel AppSidebar.

## Browser acceptance checklist

1. Open `/admin/tours/create`: pricing displays an instruction to first create the tour and doesn't permit saving unsaved prices.
2. Create a draft tour, open edit, add **Standard BDT** per-person option, set adult 15000.50, child 8000.00, min 1 max 10, save, reload. Verify exact decimals and no double multiplication.
3. Add **Private Group USD** with fixed 1250.00 for 2–6; save/reload. Duplicate and move options; ensure new code is unique.
4. Set optional sale price + local start/end dates; save/reload; preview before/after sale boundaries only when scheduled time is appropriate. Preview always uses current *server time* and *saved prices*.
5. Change currency to JPY and set **whole number** prices; make sure fractional values cause validation errors and are never silently rounded.
6. Delete an option via shared confirmation; **Cancel** keeps the option; **Confirm** marks it unsaved; **Save Pricing** commits removal; **Discard Changes** restores saved pricing.
7. Confirm a `content_editor` cannot update pricing of a published/scheduled tour. An `administrator` with `tours.publish` can.
8. Confirm `/admin/tours/{id}/edit` still contains Tiptap, itinerary save, tags, media, featured image and a single native Laravel sidebar on mobile and desktop.
9. Confirm toast notifications; check field validation errors, denied 403, stale/pricing conflicts, and HTTP response cache policy (`private`, `no-store`).
10. Run on a disposable testing database: `php artisan test --filter=AdminTourPricingUiTest`, the four P3E.3A suites, full `php artisan test`, PHPStan, TS check and Vite build.

## What P3E.3B does not do

No public pricing disclosure, booking capacity, departure inventory, currency FX conversion, tax calculation, checkout hold or guaranteed quote. These require later P3E.4 / P3E.5 policy and booking decisions. Backend allows up to 30 variants per tour; all variants are private staff data at this phase.

## Installer behavior

`install-p3e3b.ps1 -ProjectPath ... -WhatIf` verifies the package checksum manifest, refuses unexpected existing-file modifications, checks the two exact P3E.2 editor insertion anchors, then previews additions. The real run creates timestamped backups before changing the editor and route registry; added files and changed files are rolled back on write failure. Installer reruns are idempotent, but **application migrations are not needed** for this milestone.
