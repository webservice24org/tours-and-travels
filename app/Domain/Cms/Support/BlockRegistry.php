<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

/**
 * P2A contract only. No rendering, dynamic queries, or untrusted code execution.
 * These are planned, allow-listed block types for later builder implementation.
 */
final class BlockRegistry
{
    /** @var list<string> */
    private const TYPES = [
        'hero',
        'rich_text',
        'image',
        'cta',
        'faq',
        'tour_grid',
        'tour_carousel',
        'destination_grid',
        'gallery_grid',
        'gallery_lightbox',
        'review_list',
        'review_summary',
        'blog_grid',
        'contact_form',
        'navigation_menu',
        'site_branding',
        'footer_columns',
    ];

    /** @var list<string> */
    private const DATA_SOURCES = [
        'static',
        'published_tours',
        'published_destinations',
        'published_galleries',
        'approved_reviews',
        'published_blog_posts',
        'current_tour',
        'current_destination',
        'current_blog_post',
    ];

    /** @return list<string> */
    public function types(): array
    {
        return self::TYPES;
    }

    public function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public function supportsSource(string $source): bool
    {
        return in_array($source, self::DATA_SOURCES, true);
    }

    /**
     * Validate only the outer shape, block identity, and allow-listed source.
     * Deep per-block props, style tokens, query filters, authorization, and
     * output encoding must be implemented before any document is rendered.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    public function validateDocument(array $document): array
    {
        $errors = [];

        if (($document['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be 1.';
        }

        $blocks = $document['blocks'] ?? null;
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return [...$errors, 'blocks must be a list.'];
        }

        if (count($blocks) > 100) {
            $errors[] = 'A document cannot exceed 100 top-level blocks.';
        }

        $ids = [];
        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                $errors[] = "blocks.$index must be an object.";

                continue;
            }

            $id = $block['id'] ?? null;
            if (! is_string($id) || trim($id) === '' || strlen($id) > 100) {
                $errors[] = "blocks.$index.id must be a nonempty string (max 100 chars).";
            } elseif (isset($ids[$id])) {
                $errors[] = "blocks.$index.id must be unique.";
            } else {
                $ids[$id] = true;
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || ! $this->supports($type)) {
                $errors[] = "blocks.$index.type is not allow-listed.";
            }

            if (! isset($block['props']) || ! is_array($block['props']) || array_is_list($block['props']) && $block['props'] !== []) {
                $errors[] = "blocks.$index.props must be an object (or empty array).";
            }

            if (isset($block['data_source']) && (
                ! is_string($block['data_source']) || ! $this->supportsSource($block['data_source'])
            )) {
                $errors[] = "blocks.$index.data_source is not allow-listed.";
            }
        }

        return $errors;
    }
}
