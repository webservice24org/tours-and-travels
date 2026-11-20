# Bablys P3E.3A — Tour pricing and currencies: domain contract

## Scope

This package is the **additive backend foundation** of P3E.3. P3E.3B will add protected HTTP endpoints and a pricing panel to the existing WordPress-style 9/3-column tour editor. It does not touch current routes, TourManager, itinerary editor, Laravel native sidebar, shared confirmation dialog, toast system, rich-text editor, tour publication, or public catalog.

## Storage

- `travel_tour_price_variants`: tour-specific named pricing offers, stable `code` unique per tour, ISO currency, `per_person` or `per_group`, participant limits, active flag and ordering.
- `travel_tour_price_rates`: one adult and optional child rate for `per_person`, or exactly one group rate for `per_group`. Each rate has a regular amount and optional sale amount with optional UTC sale start/end timestamps.
- **All prices are integer minor units**, not floats/DB decimals. For BDT/USD/EUR/GBP: 5000 means 50.00; for JPY: 5000 means 5000. The known currency exponent is exposed by `TourPricingRules::fractionDigits()`.
- Currency whitelist for this milestone: BDT, USD, EUR, GBP, JPY. No currency conversion or FX rate is performed. Store independent BDT and USD price variants if you want both.
- `MAX_AMOUNT_MINOR = 1_000_000_000` per rate and 1–1000 travelers avoid integer multiplication overflow on 64-bit PHP.
- First migration follows already accepted P3E.1 migration 050001. No existing table alterations. Parent tour **force** delete cascades pricing; soft delete retains it for restore.

## Domain API

`TourPricingRules::normalize($variants)` accepts an ordered list (maximum 30) of objects with these keys:

```json
[
  {
    "code": "bdt-standard",
    "name": "Standard BDT",
    "currency": "BDT",
    "pricing_model": "per_person",
    "min_travelers": 1,
    "max_travelers": 10,
    "is_active": true,
    "rates": [
      {"traveler_type": "adult", "regular_minor": 1500000, "sale_minor": 1400000,
       "sale_starts_at": "2027-01-01T00:00:00Z", "sale_ends_at": "2027-02-01T00:00:00Z"},
      {"traveler_type": "child", "regular_minor": 800000}
    ]
  },
  {
    "code": "usd-private-group",
    "name": "USD Private Group",
    "currency": "USD",
    "pricing_model": "per_group",
    "min_travelers": 2,
    "max_travelers": 8,
    "is_active": true,
    "rates": [
      {"traveler_type": "group", "regular_minor": 125000}
    ]
  }
]
```

- All `*_minor` values must be PHP integers, never floats or numeric strings. In an HTTP form a future controller must explicitly parse human-readable major units using decimal-string arithmetic, **never** `(float)` or JavaScript floating-point multiplication. This package does not expose an endpoint yet.
- An adult or group regular amount must be positive. Child amount may be zero (free).
- Sale amount must be less than the regular amount; a free child rate cannot have a sale amount. Omitted sale dates = always-on promotion; specified dates must have a timezone, include seconds, and form a valid interval.
- **Start is inclusive, end is exclusive**. All timestamps are normalized to UTC.
- Variant `code` is lowercased and acts as the stable identity when updating; changing its code deletes the former option and creates a new one. Future bookings must snapshot a chosen rate/amount and must not rely on current mutable rows.
- Unknown fields (especially client-provided totals) are rejected rather than silently accepted.
- Children require a child rate; adults must be at least one for P3E.3A quotes. Per-group quotes charge one fixed group rate while respecting min/max travelers.

### Staff-only service

- `TourPricingManager::replace(User $actor, Tour $tour, array $variants): void` locks tour row, rechecks `tours.edit`, also `tours.publish` for live/scheduled tours, atomically replaces variants and nested rates, keeps row IDs when keys remain the same, and records one redacted `tour.pricing.changed` audit event when the contents changed.
- `TourPricingManager::forStaff(User $actor, Tour $tour): array` requires `tours.view`.
- `TourPricingManager::previewForStaff(...)` returns currency, fraction digits, regular total, effective total, discount and rate breakdown. This is **only an informational estimate**; no tax, service fee, FX conversion, departure inventory or booking is created or reserved.
- `TourPriceCalculator::quote(...)` is the pure calculation behind staff preview.
- The audit payload contains before/after counts and changed variant codes; it intentionally excludes detailed amounts, sale schedules and personally identifying traveler data.

## Security / follow-up

1. This package exposes **no new public prices or routes**. P3E.3B controllers must enforce staff middleware, CSRF, Gate checks and request validation; it should not allow unpublished prices in public catalog.
2. Existing `travel_tours.status` and `PublishedTourCatalog` remain unchanged; pricing does not automatically activate a tour or promise departure availability.
3. Race safety uses the tour row as the authoritative lock in all writes through this service; future writes must not bypass this service.
4. `travel_tour_price_variants` table has no attached ISO currency metadata table; if expanding supported currencies, update whitelist and exponent tests at the same time.
5. Group pricing currently represents one flat price for the entire group (not a sliding per-person tier). Tiered prices, single supplements, infant rates, taxes, traveler-age definitions, FX conversions, deposits, cancellations, departure-based price overrides and booking price snapshots are separate future domain decisions.
