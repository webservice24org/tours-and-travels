<?php

declare(strict_types=1);

namespace App\Domain\Cms\Audit;

use InvalidArgumentException;

/**
 * Defense in depth for deliberately selected event metadata. NEVER pass raw
 * HTTP requests, full models, passwords, tokens, or entire before/after rows.
 */
final class CmsAuditRedactor
{
    private const REDACTED = '[REDACTED]';

    /**
     * @param array<array-key, mixed> $metadata
     * @return array<array-key, mixed>
     */
    public function sanitize(array $metadata): array
    {
        return $this->sanitizeArray($metadata, 0);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function sanitizeArray(array $values, int $depth): array
    {
        if ($depth > 8) {
            throw new InvalidArgumentException('Audit metadata nesting is too deep.');
        }

        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && (
                $key === '' || strlen($key) > 80 || preg_match('/[\\x00-\\x1f]/', $key) === 1
            )) {
                throw new InvalidArgumentException('Audit metadata contains an invalid key.');
            }

            if (is_string($key) && $this->isSensitiveKey($key)) {
                $clean[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeArray($value, $depth + 1);
                continue;
            }

            if (is_string($value)) {
                if (strlen($value) > 1000) {
                    throw new InvalidArgumentException('Audit metadata string exceeds 1000 bytes.');
                }
                $clean[$key] = $this->looksLikeEmbeddedSecret($value)
                    ? self::REDACTED
                    : $value;
                continue;
            }

            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                if (is_float($value) && ! is_finite($value)) {
                    throw new InvalidArgumentException('Audit metadata cannot contain non-finite numbers.');
                }
                $clean[$key] = $value;
                continue;
            }

            throw new InvalidArgumentException('Audit metadata may contain only JSON scalar values and arrays.');
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/(?:password|passwd|passphrase|token|secret|authorization|credential|api[_-]?key|private[_-]?key|cookie|session|otp|recovery|reset|cvv|cvc|card[_-]?number)/i',
            $key
        ) === 1;
    }

    private function looksLikeEmbeddedSecret(string $value): bool
    {
        return preg_match(
            '/(?:\\bbearer\\s+\\S+|\\b(?:password|passwd|token|secret|api[_-]?key|authorization)\\s*[:=]\\s*\\S+)/i',
            $value
        ) === 1;
    }
}
