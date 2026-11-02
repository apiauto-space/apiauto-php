<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** Where the car is. */
final class Location
{
    public function __construct(
        /** ISO 3166 code: KR, CN or US. */
        public readonly string $country,
        /** Province or US state. */
        public readonly ?string $region,
        public readonly ?string $city,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'country') ?? '',
            Field::string($data, 'region'),
            Field::string($data, 'city'),
        );
    }
}
