<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** A price in the market's currency. */
final class Price
{
    public function __construct(
        /** Decimal string such as "23900000.00", kept as sent so no precision is lost. */
        public readonly ?string $amount,
        /** KRW, CNY or USD. */
        public readonly string $currency,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'amount'),
            Field::string($data, 'currency') ?? '',
        );
    }
}
