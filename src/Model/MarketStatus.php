<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** Plan access and data freshness of one market. */
final class MarketStatus
{
    public function __construct(
        public readonly string $market,
        public readonly ?string $source,
        public readonly bool $availableInPlan,
        public readonly string $status,
        public readonly ?string $dataUpdatedAt,
        public readonly ?int $dataAgeSeconds,
        public readonly int $staleAfterSeconds,
        public readonly bool $stale,
        public readonly int $activeCars,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'market') ?? '',
            Field::string($data, 'source'),
            Field::bool($data, 'available_in_plan'),
            Field::string($data, 'status') ?? '',
            Field::string($data, 'data_updated_at'),
            Field::int($data, 'data_age_seconds'),
            Field::int($data, 'stale_after_seconds') ?? 0,
            Field::bool($data, 'stale'),
            Field::int($data, 'active_cars') ?? 0,
        );
    }
}
