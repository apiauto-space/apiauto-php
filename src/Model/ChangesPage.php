<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** A page of Client::changes(). */
final class ChangesPage
{
    public function __construct(
        /** @var list<Change> */
        public readonly array $items,
        /** Pass it as cursor for the next page; null on the last one. */
        public readonly ?string $nextCursor,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(Change::fromArray(...), Field::objects($data, 'items')),
            Field::string($data, 'next_cursor'),
        );
    }
}
