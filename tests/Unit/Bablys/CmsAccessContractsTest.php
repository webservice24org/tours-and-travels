<?php

declare(strict_types=1);

namespace Tests\Unit\Bablys;

use App\Domain\Cms\Access\RbacCatalog;
use PHPUnit\Framework\TestCase;

final class CmsAccessContractsTest extends TestCase
{
    public function test_administration_permissions_remain_a_named_allowlist(): void
    {
        $keys = array_keys(RbacCatalog::permissions());
        self::assertCount(28, $keys);
        self::assertContains('roles.manage', $keys);
        self::assertContains('audit.view', $keys);
        self::assertNotContains('admin.everything', $keys);
    }
}
