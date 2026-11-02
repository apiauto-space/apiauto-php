<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** A make or model as the catalog spells it, with the number of active cars. */
final class ReferenceItem
{
    public function __construct(
        public readonly string $name,
        public readonly int $count,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'name') ?? '',
            Field::int($data, 'count') ?? 0,
        );
    }
}
