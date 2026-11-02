<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** A page of Client::cars(). */
final class CarsPage
{
    public function __construct(
        /** @var list<Car> */
        public readonly array $items,
        /** Pass it as cursor for the next page; null on the last one. */
        public readonly ?string $nextCursor,
        /** Cars matching the filters when there are at most 10,000; null above that. */
        public readonly ?int $count,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(Car::fromArray(...), Field::objects($data, 'items')),
            Field::string($data, 'next_cursor'),
            Field::int($data, 'count'),
        );
    }
}
