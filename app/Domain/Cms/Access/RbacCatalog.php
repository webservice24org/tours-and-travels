<?php

declare(strict_types=1);

namespace App\Domain\Cms\Access;

/**
 * Canonical Bablys P2A permission matrix for first-install staff roles.
 * These proposed grants become actual permissions ONLY after a role is
 * explicitly attached to a user by an authorized future workflow.
 */
final class RbacCatalog
{
    /** @return array<string, array{name: string, description: string}> */
    public static function roles(): array
    {
        return [
            'super_admin' => ['name' => 'Super Administrator', 'description' => 'Highest-trust CMS administrator; explicitly assigned only.'],
            'administrator' => ['name' => 'Administrator', 'description' => 'Manages published content and routine operations, excluding owner-only controls.'],
            'content_editor' => ['name' => 'Content Editor', 'description' => 'Creates and edits tours, pages, blogs and other draft content.'],
            'designer' => ['name' => 'Website Designer', 'description' => 'Edits visual builders, menus, media and presentation.'],
            'review_moderator' => ['name' => 'Review Moderator', 'description' => 'Reviews and moderates traveler reviews.'],
            'booking_agent' => ['name' => 'Booking Agent', 'description' => 'Views and manages booking requests.'],
            'seo_editor' => ['name' => 'SEO Editor', 'description' => 'Edits, reviews and publishes approved SEO changes.'],
        ];
    }

    /** @return array<string, array{module: string, action: string, label: string, description: string, roles: list<string>}> */
    public static function permissions(): array
    {
        return [
            'tours.view' => [
                'module' => 'tours',
                'action' => 'view',
                'label' => 'Tours View',
                'description' => 'Public visibility separate from admin read',
                'roles' => ['super_admin', 'administrator', 'content_editor', 'designer'],
            ],
            'tours.create' => [
                'module' => 'tours',
                'action' => 'create',
                'label' => 'Tours Create',
                'description' => 'Bablys CMS permission: tours.create.',
                'roles' => ['super_admin', 'administrator', 'content_editor'],
            ],
            'tours.edit' => [
                'module' => 'tours',
                'action' => 'edit',
                'label' => 'Tours Edit',
                'description' => 'Bablys CMS permission: tours.edit.',
                'roles' => ['super_admin', 'administrator', 'content_editor'],
            ],
            'tours.publish' => [
                'module' => 'tours',
                'action' => 'publish',
                'label' => 'Tours Publish',
                'description' => 'Separate permission',
                'roles' => ['super_admin', 'administrator'],
            ],
            'taxonomy.manage' => [
                'module' => 'taxonomy',
                'action' => 'manage',
                'label' => 'Taxonomy Manage',
                'description' => 'Category/destination integrity',
                'roles' => ['super_admin', 'administrator', 'content_editor'],
            ],
            'media.upload' => [
                'module' => 'media',
                'action' => 'upload',
                'label' => 'Media Upload',
                'description' => 'Bablys CMS permission: media.upload.',
                'roles' => ['super_admin', 'administrator', 'content_editor', 'designer'],
            ],
            'media.delete' => [
                'module' => 'media',
                'action' => 'delete',
                'label' => 'Media Delete',
                'description' => 'Block referenced assets',
                'roles' => ['super_admin', 'administrator'],
            ],
            'galleries.edit' => [
                'module' => 'galleries',
                'action' => 'edit',
                'label' => 'Galleries Edit',
                'description' => 'Bablys CMS permission: galleries.edit.',
                'roles' => ['super_admin', 'administrator', 'content_editor', 'designer'],
            ],
            'galleries.publish' => [
                'module' => 'galleries',
                'action' => 'publish',
                'label' => 'Galleries Publish',
                'description' => 'Bablys CMS permission: galleries.publish.',
                'roles' => ['super_admin', 'administrator'],
            ],
            'reviews.view_private' => [
                'module' => 'reviews',
                'action' => 'view_private',
                'label' => 'Reviews View Private',
                'description' => 'Sensitive content',
                'roles' => ['super_admin', 'administrator', 'review_moderator'],
            ],
            'reviews.moderate' => [
                'module' => 'reviews',
                'action' => 'moderate',
                'label' => 'Reviews Moderate',
                'description' => 'Pending hidden from public',
                'roles' => ['super_admin', 'administrator', 'review_moderator'],
            ],
            'reviews.reply' => [
                'module' => 'reviews',
                'action' => 'reply',
                'label' => 'Reviews Reply',
                'description' => 'Bablys CMS permission: reviews.reply.',
                'roles' => ['super_admin', 'administrator', 'review_moderator'],
            ],
            'builders.edit' => [
                'module' => 'builders',
                'action' => 'edit',
                'label' => 'Builders Edit',
                'description' => 'Draft only',
                'roles' => ['super_admin', 'administrator', 'designer'],
            ],
            'builders.publish' => [
                'module' => 'builders',
                'action' => 'publish',
                'label' => 'Builders Publish',
                'description' => 'Global changes audited',
                'roles' => ['super_admin', 'administrator'],
            ],
            'menus.manage' => [
                'module' => 'menus',
                'action' => 'manage',
                'label' => 'Menus Manage',
                'description' => 'Bablys CMS permission: menus.manage.',
                'roles' => ['super_admin', 'administrator', 'designer'],
            ],
            'pages.edit' => [
                'module' => 'pages',
                'action' => 'edit',
                'label' => 'Pages Edit',
                'description' => 'Bablys CMS permission: pages.edit.',
                'roles' => ['super_admin', 'administrator', 'content_editor', 'designer'],
            ],
            'pages.publish' => [
                'module' => 'pages',
                'action' => 'publish',
                'label' => 'Pages Publish',
                'description' => 'Bablys CMS permission: pages.publish.',
                'roles' => ['super_admin', 'administrator'],
            ],
            'blog.edit' => [
                'module' => 'blog',
                'action' => 'edit',
                'label' => 'Blog Edit',
                'description' => 'Bablys CMS permission: blog.edit.',
                'roles' => ['super_admin', 'administrator', 'content_editor'],
            ],
            'blog.publish' => [
                'module' => 'blog',
                'action' => 'publish',
                'label' => 'Blog Publish',
                'description' => 'Bablys CMS permission: blog.publish.',
                'roles' => ['super_admin', 'administrator'],
            ],
            'bookings.view' => [
                'module' => 'bookings',
                'action' => 'view',
                'label' => 'Bookings View',
                'description' => 'Data minimization',
                'roles' => ['super_admin', 'administrator', 'booking_agent'],
            ],
            'bookings.manage' => [
                'module' => 'bookings',
                'action' => 'manage',
                'label' => 'Bookings Manage',
                'description' => 'Notes restricted',
                'roles' => ['super_admin', 'administrator', 'booking_agent'],
            ],
            'seo.edit' => [
                'module' => 'seo',
                'action' => 'edit',
                'label' => 'Seo Edit',
                'description' => 'Bablys CMS permission: seo.edit.',
                'roles' => ['super_admin', 'administrator', 'seo_editor'],
            ],
            'seo.publish' => [
                'module' => 'seo',
                'action' => 'publish',
                'label' => 'Seo Publish',
                'description' => 'Publication audited',
                'roles' => ['super_admin', 'administrator', 'seo_editor'],
            ],
            'seo.ai.propose' => [
                'module' => 'seo.ai',
                'action' => 'propose',
                'label' => 'Seo Ai Propose',
                'description' => 'AI costs controlled',
                'roles' => ['super_admin', 'administrator', 'seo_editor'],
            ],
            'seo.ai.review' => [
                'module' => 'seo.ai',
                'action' => 'review',
                'label' => 'Seo Ai Review',
                'description' => 'No auto publishing',
                'roles' => ['super_admin', 'administrator', 'seo_editor'],
            ],
            'settings.manage' => [
                'module' => 'settings',
                'action' => 'manage',
                'label' => 'Settings Manage',
                'description' => 'Higher-risk settings',
                'roles' => ['super_admin'],
            ],
            'roles.manage' => [
                'module' => 'roles',
                'action' => 'manage',
                'label' => 'Roles Manage',
                'description' => 'Escalation protected',
                'roles' => ['super_admin'],
            ],
            'audit.view' => [
                'module' => 'audit',
                'action' => 'view',
                'label' => 'Audit View',
                'description' => 'Sensitive event redaction',
                'roles' => ['super_admin'],
            ],
        ];
    }
}
