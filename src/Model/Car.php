<?php

declare(strict_types=1);

namespace Apiauto\Model;

use Apiauto\Internal\Field;

/** One car record, the same fields in every market.
 *
 * id is source:source_id (encar:43020893) and never changes. photos are signed links that work for
 * about a day: store the id, not the links. raw holds the fields only the source has, as it sends
 * them. Prices are decimal strings. A value the source does not provide is null. */
final class Car
{
    public function __construct(
        public readonly string $id,
        /** kr, cn or us. */
        public readonly string $market,
        /** encar, che168, copart or iaai. */
        public readonly string $source,
        /** The listing or lot number at the source. */
        public readonly string $sourceId,
        public readonly string $title,
        public readonly ?string $brand,
        public readonly ?string $model,
        /** Korea only. */
        public readonly ?string $generation,
        public readonly ?string $trim,
        public readonly ?int $year,
        public readonly ?int $mileageKm,
        public readonly ?string $fuel,
        public readonly ?string $transmission,
        public readonly ?int $engineCc,
        public readonly ?string $bodyType,
        public readonly ?string $color,
        public readonly ?string $vin,
        public readonly Price $price,
        /** The price in dollars at the ECB daily rate. */
        public readonly ?string $priceUsd,
        public readonly Location $location,
        /** @var list<string> */
        public readonly array $photos,
        public readonly int $photoCount,
        public readonly ?string $listingUrl,
        public readonly bool $isActive,
        public readonly string $firstSeenAt,
        public readonly string $lastSeenAt,
        public readonly ?string $disappearedAt,
        public readonly string $updatedAt,
        /** replica, or live for Client::usLive(). */
        public readonly string $sourceMode,
        /** @var array<mixed> Source-only fields, such as damage_main or sale_date. */
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data A decoded API object. */
    public static function fromArray(array $data): self
    {
        return new self(
            Field::string($data, 'id') ?? '',
            Field::string($data, 'market') ?? '',
            Field::string($data, 'source') ?? '',
            Field::string($data, 'source_id') ?? '',
            Field::string($data, 'title') ?? '',
            Field::string($data, 'brand'),
            Field::string($data, 'model'),
            Field::string($data, 'generation'),
            Field::string($data, 'trim'),
            Field::int($data, 'year'),
            Field::int($data, 'mileage_km'),
            Field::string($data, 'fuel'),
            Field::string($data, 'transmission'),
            Field::int($data, 'engine_cc'),
            Field::string($data, 'body_type'),
            Field::string($data, 'color'),
            Field::string($data, 'vin'),
            Price::fromArray(Field::object($data, 'price')),
            Field::string($data, 'price_usd'),
            Location::fromArray(Field::object($data, 'location')),
            Field::strings($data, 'photos'),
            Field::int($data, 'photo_count') ?? 0,
            Field::string($data, 'listing_url'),
            Field::bool($data, 'is_active'),
            Field::string($data, 'first_seen_at') ?? '',
            Field::string($data, 'last_seen_at') ?? '',
            Field::string($data, 'disappeared_at'),
            Field::string($data, 'updated_at') ?? '',
            Field::string($data, 'source_mode') ?? 'replica',
            Field::object($data, 'raw'),
        );
    }
}
