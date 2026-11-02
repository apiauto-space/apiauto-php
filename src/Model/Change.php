<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** One event of the change feed. */
final class Change
{
    public function __construct(
        public readonly int $id,
        /** The car's id, as in Client::car(); its prefix tells the source. */
        public readonly string $carId,
        public readonly string $market,
        /** added, disappeared, price_drop or price_up. */
        public readonly string $type,
        /** ISO 8601 in UTC. */
        public readonly string $at,
        /** Price before, a decimal string; price events only. */
        public readonly ?string $oldPrice,
        /** Price after, a decimal string; price events only. */
        public readonly ?string $newPrice,
        /** Size of the price change in percent, always positive; the direction is in type. */
        public readonly ?string $pct,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::int($data, 'id') ?? 0,
            Field::string($data, 'car_id') ?? '',
            Field::string($data, 'market') ?? '',
            Field::string($data, 'type') ?? '',
            Field::string($data, 'at') ?? '',
            Field::string($data, 'old_price'),
            Field::string($data, 'new_price'),
            Field::string($data, 'pct'),
        );
    }
}
