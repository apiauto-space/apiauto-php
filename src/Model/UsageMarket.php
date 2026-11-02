<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** Usage of one market in the current period. */
final class UsageMarket
{
    public function __construct(
        public readonly string $market,
        public readonly int $records,
        public readonly int $requests,
        public readonly int $liveUsRequests,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'market') ?? '',
            Field::int($data, 'records') ?? 0,
            Field::int($data, 'requests') ?? 0,
            Field::int($data, 'live_us_requests') ?? 0,
        );
    }
}
