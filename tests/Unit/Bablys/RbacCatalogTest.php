<?php

declare(strict_types=1);

namespace Tests\Unit\Bablys;

use App\Domain\Cms\Access\RbacCatalog;
use PHPUnit\Framework\TestCase;

final class RbacCatalogTest extends TestCase
{
    public function test_catalog_matches_p2a_matrix(): void
    {
        $roles = RbacCatalog::roles();
        $permissions = RbacCatalog::permissions();

        self::assertCount(7, $roles);
        self::assertCount(28, $permissions);
        self::assertArrayHasKey('super_admin', $roles);
        self::assertArrayHasKey('seo.ai.review', $permissions);
        self::assertArrayHasKey('reviews.moderate', $permissions);
        self::assertArrayHasKey('galleries.publish', $permissions);

        foreach ($permissions as $key => $definition) {
            self::assertSame(
                $key,
                $definition['module'].'.'.$definition['action'],
            );
            self::assertContains('super_admin', $definition['roles']);
            self::assertSame(
                array_values(array_unique($definition['roles'])),
                $definition['roles'],
            );
            foreach ($definition['roles'] as $roleKey) {
                self::assertArrayHasKey($roleKey, $roles);
            }
        }
    }
}
