# P3E.4A Local Acceptance / P3E.4B Handoff

1. Back up the **Bablys** database. Extract ZIP outside `C:\xampp\htdocs\bablys-travel`.
2. Dry-run `install-p3e4a.ps1 -ProjectPath ... -WhatIf` and verify no modified files are overwritten. Apply the same installer without `-WhatIf`.
3. Inspect `php artisan migrate --pretend`, then migrate. Verify `travel_tour_departures` exists, its per-tour-code uniqueness and tour FK work.
4. Run focused tests (schema, rules, manager, availability), then full Laravel suite on a **separate disposable testing database**, then PHPStan, TypeScript check and Vite build.
5. Confirm CMS audit readiness and no new routes or screens are registered. P3E.4A adds no UI yet.
6. Confirm existing tour editing, itinerary editing, pricing editing, Tiptap, shared confirmation/toast, and the unified native sidebar still work.

## Next P3E.4B

Add a protected `GET`/`PUT` `/admin/internal/tours/{tour}/departures` thin controller + route integration using `TourDepartureManager`, server-filtered perms, current server time. Mount a React TSX `TourDepartureEditor` in the **existing 9-column main Tour Editor** with add/duplicate/reorder/remove, UTC/timezone-safe date-time input, meeting point, capacity, status, internal notes, Save/Discard, toasts and confirmations. Never accept `seats_committed` in API payloads. Add feature/security/browser tests, including IDOR, published permission, role revocation, timezone offsets, and committed-seat constraints. P3E.5 will create booking-ready authority, so do not claim bookings are enabled after P3E.4B.
