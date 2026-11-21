<?php

declare(strict_types=1);

namespace App\Domain\Cms\Navigation;

use App\Domain\Cms\Access\CmsAccess;
use App\Models\User;

/**
 * Bablys CMS navigation is only a discovery surface, never an authorization grant.
 * Compute link visibility from the authoritative database-backed CMS access service.
 */
final class CmsAdminNavigation
{
    /**
     * @return list<array{key: string, title: string, links: list<array{label: string, href: string}>}>
     */
    public function groupsFor(User $user, CmsAccess $access): array
    {
        $granted = array_fill_keys($access->permissionKeysFor($user), true);
        $owner = in_array('super_admin', $access->roleKeysFor($user), true);

        $groups = [
            [
                'key' => 'overview',
                'title' => 'Overview',
                'links' => [
                    ['label' => 'Admin home', 'href' => '/admin'],
                    ['label' => 'My dashboard', 'href' => '/dashboard'],
                    ['label' => 'Public website', 'href' => '/'],
                ],
            ],
        ];

        if (isset($granted['taxonomy.manage'])) {
            $groups[] = [
                'key' => 'travel',
                'title' => 'Travel content',
                'links' => [
                    ['label' => 'Tour categories', 'href' => '/admin/tour-categories'],
                    ['label' => 'Destinations', 'href' => '/admin/destinations'],
                ],
            ];
        }

        // P3D.2: append Tour Packages using the user's current database-backed permission.
        // Keep this independent of taxonomy.manage: roles may have tours.view alone.
        if (isset($granted['tours.view'])) {
            $groups[] = [
                'key' => 'tour-packages',
                'title' => 'Tour management',
                'links' => [
                    ['label' => 'Tour packages', 'href' => '/admin/tours'],
                    // P3H.5 BOOKING-SUBMENU: booking pages require BOTH travel permissions.
                    ...(isset($granted['tours.edit'], $granted['tours.publish']) ? [
                        ['label' => 'Booking requests', 'href' => '/admin/booking-requests'],
                        ['label' => 'Bookings', 'href' => '/admin/bookings'],
                    ] : []),
                ],
            ];
        }

        // Both the Super Administrator identity AND the live permission are required.
        // Non-owners must never get owner navigation, even if grants are tampered with.
        $ownerLinks = [];
        if ($owner && isset($granted['roles.manage'])) {
            $ownerLinks[] = ['label' => 'Staff users', 'href' => '/admin/staff-users'];
            $ownerLinks[] = ['label' => 'Roles & permissions', 'href' => '/admin/roles'];
        }

        if ($owner && isset($granted['audit.view'])) {
            $ownerLinks[] = ['label' => 'Audit logs', 'href' => '/admin/audit-logs'];
        }

        if ($ownerLinks !== []) {
            $groups[] = [
                'key' => 'security',
                'title' => 'Administration',
                'links' => $ownerLinks,
            ];
        }

        return $groups;
    }
}
