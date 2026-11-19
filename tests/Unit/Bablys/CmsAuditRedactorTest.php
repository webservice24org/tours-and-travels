<?php

declare(strict_types=1);

namespace Tests\Unit\Bablys;

use App\Domain\Cms\Audit\CmsAuditRedactor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CmsAuditRedactorTest extends TestCase
{
    public function test_nested_sensitive_keys_are_redacted_but_safe_business_metadata_remains(): void
    {
        $result = (new CmsAuditRedactor())->sanitize([
            'role_keys' => ['booking_agent'],
            'headers' => ['Authorization' => 'Bearer abc', 'x_reason' => 'staff-requested'],
            'changes' => [['field' => 'cms_staff_disabled_at', 'value' => true]],
            'access_token' => 'secret',
        ]);

        self::assertSame(['booking_agent'], $result['role_keys']);
        self::assertSame('[REDACTED]', $result['headers']['Authorization']);
        self::assertSame('staff-requested', $result['headers']['x_reason']);
        self::assertSame('[REDACTED]', $result['access_token']);
        self::assertTrue($result['changes'][0]['value']);
    }

    public function test_unknown_objects_are_rejected_instead_of_automatically_serialized(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CmsAuditRedactor())->sanitize(['user' => (object) ['password' => 'secret']]);
    }
}
