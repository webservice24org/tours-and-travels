# P3H.5 follow-up — Admin Tour Management booking submenu

## Expected navigation

The existing native Laravel `AppSidebar` remains the only admin sidebar. The server-side `/admin/internal/navigation` response includes the existing **Tour management** group (`key: tour-packages`). Its links now show:

- **Tour packages** -> `/admin/tours` (existing `tours.view`-filtered group)
- **Booking requests** -> `/admin/booking-requests` (requires `tours.edit` AND `tours.publish`)
- **Bookings** -> `/admin/bookings` (requires `tours.edit` AND `tours.publish`)

The patch adds two **conditional** links within the existing group. It does not add a second layout/sidebar, does not modify any booking controller/service, and does not change security at the route level. Booking routes continue to require authenticated active authorized staff with BOTH permissions.

The existing group appears only to staff who possess `tours.view`. If a highly customized staff role has `tours.edit` and `tours.publish` but *not* `tours.view`, grant `tours.view` as appropriate or request a separate compatible navigation change. Do not grant booking edit/publish privileges solely to display a link.

## Browser acceptance

1. Login as a senior tour admin possessing `tours.view`, `tours.edit` and `tours.publish`.
2. Open any `/admin` page; expand **Tour management** in the existing `AppSidebar`.
3. Confirm **Tour packages**, **Booking requests** and **Bookings** appear together as sub-links.
4. Click Booking requests: opens the existing customer/guest enquiry review and authorized conversion UI.
5. Click Bookings: opens the P3H.1/P3H.2 booking dashboard and detail/confirmation UI.
6. Login as a content editor with only `tours.view` and `tours.edit` (no `tours.publish`). The Tour packages link remains, but booking links are absent. Direct GET to booking pages must still be forbidden.
7. Confirm no duplicate sidebar exists on booking pages, customer pages or the public site.
8. Refresh the page: the navigation remains visible via the existing permission-filtered navigation endpoint.

## Regression commands

Only run Laravel tests against your **disposable** `bablys_suite_clean_test` MySQL database under `APP_ENV=testing`; never from a normal development CMD session.

```
php artisan test --filter=AdminBookingSidebarNavigationTest --stop-on-failure
php artisan test --filter=AdminToursUiTest --stop-on-failure
php artisan test --filter=AdminBookingActionControlsTest --stop-on-failure
php artisan test
```

Development-only static checks: `vendor\bin\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build`.

## Installer compatibility

This patch targets the known P3D.2 server-side group structure containing `key => tour-packages` and `['label' => 'Tour packages', 'href' => '/admin/tours']`. Because the actual current source file from your local Windows project was not uploaded, the installer identifies the **one** matching navigation source under `app/` and requires the exact structural anchor. If your navigation source has been customized, the installer **refuses to patch**. Upload the current navigation PHP source for a precise compatibility patch; never bypass this preflight by replacing the file wholesale.

The installer makes an original-file backup under `storage/app/bablys-install-backups` and supports `-WhatIf`. No migrations or route additions.
