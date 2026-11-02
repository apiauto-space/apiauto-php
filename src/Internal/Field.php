<?php

declare(strict_types=1);

namespace Apiauto\Internal;

/**
 * Typed reads of decoded JSON: a missing or null value stays null, so a new or changed API field
 * never breaks a client.
 *
 * @internal
 */
final class Field
{
    /** @param array<mixed> $data */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /** @param array<mixed> $data */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        return is_float($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }

    /** @param array<mixed> $data */
    public static function bool(array $data, string $key): bool
    {
        return ($data[$key] ?? false) === true;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    public static function objects(array $data, string $key): array
    {
        return array_values(array_filter(self::object($data, $key), 'is_array'));
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        return array_values(array_filter(self::object($data, $key), 'is_string'));
    }
}
