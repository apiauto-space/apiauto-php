<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** The plan, its term and how much of the quota is spent. */
final class Usage
{
    public function __construct(
        public readonly string $plan,
        /** demo, active or grace. */
        public readonly string $status,
        public readonly string $expiresAt,
        /** YYYY-MM, or demo for the whole demo term. */
        public readonly string $period,
        public readonly string $resetAt,
        /** records on paid plans, requests on the demo. */
        public readonly string $quotaUnit,
        public readonly int $quotaLimit,
        public readonly int $quotaUsed,
        public readonly int $recordsLimit,
        public readonly int $recordsUsed,
        public readonly int $liveUsLimit,
        public readonly int $liveUsUsed,
        /** @var list<UsageMarket> */
        public readonly array $byMarket,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'plan') ?? '',
            Field::string($data, 'status') ?? '',
            Field::string($data, 'expires_at') ?? '',
            Field::string($data, 'period') ?? '',
            Field::string($data, 'reset_at') ?? '',
            Field::string($data, 'quota_unit') ?? '',
            Field::int($data, 'quota_limit') ?? 0,
            Field::int($data, 'quota_used') ?? 0,
            Field::int($data, 'records_limit') ?? 0,
            Field::int($data, 'records_used') ?? 0,
            Field::int($data, 'live_us_limit') ?? 0,
            Field::int($data, 'live_us_used') ?? 0,
            array_map(UsageMarket::fromArray(...), Field::objects($data, 'by_market')),
        );
    }
}
