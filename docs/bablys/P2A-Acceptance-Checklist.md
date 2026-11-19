# Bablys P2A — Local Acceptance Checklist

## Install safely

1. Back up or commit the working P1 code first. Keep the previous P1 overlay package untouched.
2. Extract this new P2A ZIP separately (outside `bablys-travel`).
3. In Windows PowerShell 5.1 preview the operation using `-WhatIf`, then run without `-WhatIf`:

```powershell
powershell -ExecutionPolicy Bypass -File "C:\path\to\Bablys_P2A_Architecture_Pack\scripts\install-p2a.ps1" -ProjectPath "C:\xampp\htdocs\bablys-travel" -WhatIf
powershell -ExecutionPolicy Bypass -File "C:\path\to\Bablys_P2A_Architecture_Pack\scripts\install-p2a.ps1" -ProjectPath "C:\xampp\htdocs\bablys-travel"
```

The installer **preflights all paths**. If a destination contains different content, it fails before copying anything. Identical files are skipped. Reruns are safe. No .env, routes, migrations, homepage or login files are touched.

## Test gates

```powershell
cd C:\xampp\htdocs\bablys-travel
php artisan test --filter=CmsContractsTest
php artisan test --filter=PublicHomeTest
php artisan test
npm run types:check
npm run build
php artisan route:list
```

If `npm run types:check` is unavailable in your package scripts, use the project's existing TypeScript checker command. `php artisan migrate` is NOT required by P2A; there are no new migrations in this planning scaffold.

## Owner review

- [ ] Correct Bablys-only domain boundaries and route naming.
- [ ] Agree on P2B authorization approach (native roles/permissions or a package) before migration.
- [ ] Approve tour/taxonomy/media/gallery/review/blog/SEO key fields and relationships.
- [ ] Approve safe default: moderated reviews, builder preset-first, human-reviewed SEO, inquiry-first bookings.
- [ ] No regression in `GET /`, registration, login or dashboard.

**Acceptance record:** P2A remains IN PROGRESS until verified by the user on their local installation.
