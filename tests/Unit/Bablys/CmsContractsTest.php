<?php

declare(strict_types=1);

namespace Tests\Unit\Bablys;

use App\Domain\Cms\Enums\BuilderTemplateKind;
use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Enums\ReviewStatus;
use App\Domain\Cms\Support\BlockRegistry;
use PHPUnit\Framework\TestCase;

final class CmsContractsTest extends TestCase
{
    public function test_only_published_content_is_public(): void
    {
        self::assertTrue(PublicationStatus::Published->isPublic());
        self::assertFalse(PublicationStatus::Scheduled->isPublic());
        self::assertFalse(PublicationStatus::Draft->isPublic());
        self::assertFalse(PublicationStatus::InReview->isPublic());
        self::assertFalse(PublicationStatus::Archived->isPublic());
    }

    public function test_only_approved_reviews_are_public(): void
    {
        self::assertTrue(ReviewStatus::Approved->isPublic());
        self::assertFalse(ReviewStatus::Pending->isPublic());
        self::assertFalse(ReviewStatus::Rejected->isPublic());
        self::assertFalse(ReviewStatus::Hidden->isPublic());
    }

    public function test_header_and_footer_have_distinct_template_kinds(): void
    {
        self::assertSame('header', BuilderTemplateKind::Header->value);
        self::assertSame('footer', BuilderTemplateKind::Footer->value);
    }

    public function test_known_builder_document_is_valid_at_contract_level(): void
    {
        $errors = (new BlockRegistry)->validateDocument([
            'schema_version' => 1,
            'blocks' => [
                [
                    'id' => 'b-1',
                    'type' => 'tour_grid',
                    'props' => ['title' => 'International Tours'],
                    'data_source' => 'published_tours',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    public function test_unknown_blocks_and_arbitrary_sources_are_rejected(): void
    {
        $errors = (new BlockRegistry)->validateDocument([
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'b-1',
                'type' => 'custom_php',
                'props' => [],
                'data_source' => 'raw_sql',
            ]],
        ]);

        self::assertContains('blocks.0.type is not allow-listed.', $errors);
        self::assertContains('blocks.0.data_source is not allow-listed.', $errors);
    }

    public function test_duplicate_block_ids_are_rejected(): void
    {
        $registry = new BlockRegistry;
        $document = [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'a', 'type' => 'hero', 'props' => []],
                ['id' => 'a', 'type' => 'cta', 'props' => []],
            ],
        ];

        self::assertContains('blocks.1.id must be unique.', $registry->validateDocument($document));
    }
}
