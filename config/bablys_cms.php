<?php

declare(strict_types=1);

/**
 * Bablys-only CMS architecture defaults.
 * These values are declarations for upcoming phases, not feature switches.
 */
return [
    'schema_version' => 1,
    'public_route_prefixes' => [
        'tours' => '/tours',
        'destinations' => '/destinations',
        'blog' => '/blog',
        'galleries' => '/galleries',
    ],
    'admin_route_prefix' => '/admin',
    'reserved_public_segments' => [
        'admin', 'login', 'register', 'logout', 'dashboard',
        'tours', 'destinations', 'blog', 'galleries',
        'api', 'storage', 'build', 'up',
    ],
    'builder' => [
        'max_top_level_blocks' => 100,
        'allow_custom_html' => false,
        'allow_custom_javascript' => false,
        'preview_requires_authorization' => true,
    ],
    'seo' => [
        'ai_auto_publish' => false,
        'canonical_host' => null, // set during approved launch configuration
    ],
    'review' => [
        'default_status' => 'pending',
        'require_moderation' => true,
        'verified_badge_requires_booking' => true,
    ],
];
