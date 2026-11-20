# Bablys P3E.4A — Departure & Capacity Domain Architecture

**Project:** Bablys Tours & Travels Laravel 13 + Inertia React TSX (never the e-commerce/news/ERP sites).

## Purpose and boundaries

P3E.4A adds **staff-managed departure schedules and internal inventory snapshots**. It does **not** enable bookings, seat holds, deposits, payments, booking confirmation, public availability promises, or public endpoints. Those require P3E.5 and downstream booking modules. A stored `status=open` does not mean publicly bookable.

There is one new table, `travel_tour_departures`. Tours may have up to 200 departure records through the domain API. Each departure has a unique **stable code within its tour**. The model stores local time zone **IANA name**, UTC start/end/cutoff as SQL `DATETIME` strings, staff-facing meeting point/notes, status (`draft`, `open`, `closed`, `cancelled`), total capacity and **system-controlled** `seats_committed` (initially zero). There are no monetary amounts in departure records; variant pricing remains in P3E.3.

The underlying DB column `seats_committed` is **not fillable**, not included in the replace input contract, and must only be changed later by an atomic booking-allocation service. A future booking service must lock `travel_tours` **first**, then departure rows, within a transaction; check status, dates, cutoff and remaining capacity; update booked/held seat allocation atomically; and provide idempotency, cancellations, reconciliations and appropriate booking audits. Do not expose `seats_committed` as an admin edit field.

## Replacement payload (domain, not an HTTP API yet)

```json
[
  {
    "code": "summer-2030-a",
    "name": "June departure",
    "timezone_name": "Asia/Dhaka",
    "starts_at": "2030-06-10T08:00:00+06:00",
    "ends_at": "2030-06-12T08:00:00+06:00",
    "booking_closes_at": "2030-06-09T18:00:00+06:00",
    "capacity_total": 25,
    "status": "open",
    "meeting_point": "Dhaka Airport",
    "internal_notes": "Optional staff-only guidance"
  }
]
```

All optional fields may be omitted or null. `starts_at` is required. ISO 8601 input must include **seconds and explicit offset**, which must match the IANA timezone at that instant; this avoids silently misinterpreting DST transitions. Input dates are stored in UTC; server reads return `YYYY-MM-DDTHH:MM:SSZ`, while the IANA zone is preserved for the future editor to display local times correctly. Never parse a `datetime-local` value as UTC without explicitly resolving the selected IANA timezone/offset.

### Domain methods

- `TourDepartureRules::normalize(array $departures)` validates strict field allowlist, unique codes, date ordering, cutoff, timezone offset, integer capacity `1..10000`, statuses, plain-text fields. Records are canonicalized by start time then code.
- `TourDepartureManager::replace(User $actor, Tour $tour, array $departures): void` requires `tours.edit`, plus `tours.publish` for published/scheduled tours; rechecks permissions and parent state under transaction/lock. Preserves row IDs by code, preserves committed seats, blocks capacity below committed seats, committed departure deletion, and moving dates/timezone or resetting to draft/cancelled with committed seats. Repeating identical payload does not re-audit. Removal of empty inventory is supported.
- `TourDepartureManager::forStaff(User $actor, Tour $tour, DateTimeInterface $at): array` requires `tours.view`, lists data in chronological order, including `seats_committed`, `remaining_capacity`, `capacity_state` and `public_bookable=false`. **Never use it as a public API or booking acceptance decision.**
- `TourDepartureAvailability::snapshot(...)` calculates `remaining_capacity` and staff-facing states `draft`, `closed`, `cancelled`, `departed`, `cutoff`, `full`, `capacity_remaining`, with `public_bookable=false` in all cases.

### Transaction and audit

All saves lock the parent tour row before the related departure rows. On invalid input or a change conflicting with committed seats, the transaction rolls back. Audit event `tour.departures.changed` includes counts and changed *codes*, **not** departure dates, meeting points, internal notes, capacity totals, or identities. Audit records are inserted via existing `CmsMutationAudit` inside the same transaction.

### Invariants for P3E.4B/P3E.5

- New/admin UI goes into the **existing full-page 9/3 Tour Editor**, inside the main 9-column area, using the **single original Laravel AppSidebar**, shared `confirmAction`, and toast system. No additional sidebar or admin layout.
- New tours must be saved before editing departures. Use independently saved departure drafts, not accidental submit of entire tour form, similar to P3E.2 itinerary and P3E.3B pricing.
- The UI can show an internal `remaining_capacity` but must not present it as a guarantee or allow editing `seats_committed`.
- P3E.5 must add price-variant qualification, publication eligibility, real reservation/booking allocation, limits per traveler (not simply seats), atomic capacity reduction, releases and payment workflows before any public booking claims.
- Tour soft deletion hides a tour and its departures; parent hard deletion cascades. P3E.5 must prevent deleting booked tours and reconcile external booking records before physical deletion.
- Capacity_total and pricing variant `max_travelers` represent different concepts; do not mix group-size eligibility with total departure seat inventory.
