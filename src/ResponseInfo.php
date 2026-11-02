<?php

declare(strict_types=1);

namespace Apiauto;

use Apiauto\Http\HttpResponse;

/** Quota and rate-limit headers of a response. A header the response did not carry is null. */
final class ResponseInfo
{
    public function __construct(
        public readonly int $status,
        /** The period quota (X-Quota-Limit). */
        public readonly ?int $quotaLimit = null,
        /** What is left of the quota (X-Quota-Remaining). */
        public readonly ?int $quotaRemaining = null,
        /** When the quota renews, ISO 8601 (X-Quota-Reset). */
        public readonly ?string $quotaReset = null,
        /** Requests per second the plan allows (X-RateLimit-Limit). */
        public readonly ?int $rateLimit = null,
        /** Requests left in the current second (X-RateLimit-Remaining). */
        public readonly ?int $rateRemaining = null,
        /** Seconds until the rate window resets (X-RateLimit-Reset). */
        public readonly ?int $rateReset = null,
        /** Age of the market copy that answered (X-Data-Age-Seconds). */
        public readonly ?int $dataAgeSeconds = null,
    ) {
    }

    public static function fromResponse(HttpResponse $response): self
    {
        return new self(
            status: $response->status,
            quotaLimit: self::int($response->header('X-Quota-Limit')),
            quotaRemaining: self::int($response->header('X-Quota-Remaining')),
            quotaReset: $response->header('X-Quota-Reset'),
            rateLimit: self::int($response->header('X-RateLimit-Limit')),
            rateRemaining: self::int($response->header('X-RateLimit-Remaining')),
            rateReset: self::int($response->header('X-RateLimit-Reset')),
            dataAgeSeconds: self::int($response->header('X-Data-Age-Seconds')),
        );
    }

    private static function int(?string $value): ?int
    {
        return $value !== null && is_numeric($value) ? (int) $value : null;
    }
}
