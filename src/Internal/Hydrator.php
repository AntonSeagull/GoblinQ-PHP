<?php

declare(strict_types=1);

namespace GoblinQ\Internal;

use DateTimeImmutable;
use Exception;
use GoblinQ\Exception\GoblinQException;

/**
 * Разбор JSON-ответов сервера в типизированные значения.
 *
 * @internal
 */
final class Hydrator
{
    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw self::invalid($key, 'строку');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw self::invalid($key, 'строку или null');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw self::invalid($key, 'целое число');
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }
        if (!is_bool($data[$key])) {
            throw self::invalid($key, 'логическое значение');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    public static function date(array $data, string $key): DateTimeImmutable
    {
        return self::parseDate($key, self::string($data, $key));
    }

    /** @param array<string, mixed> $data */
    public static function nullableDate(array $data, string $key): ?DateTimeImmutable
    {
        $value = self::nullableString($data, $key);

        return $value === null ? null : self::parseDate($key, $value);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw self::invalid($key, 'массив');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $data, string $key): array
    {
        $items = self::array($data, $key);
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw self::invalid($key, 'массив объектов');
            }
            /** @var array<string, mixed> $item */
            $result[] = $item;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    public static function value(array $data, string $key): mixed
    {
        return $data[$key] ?? null;
    }

    private static function parseDate(string $key, string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception $e) {
            throw new GoblinQException(
                sprintf('Некорректная дата в поле %s: %s', $key, $value),
                0,
                $e,
            );
        }
    }

    private static function invalid(string $key, string $expected): GoblinQException
    {
        return new GoblinQException(
            sprintf('Некорректный ответ GoblinQ: поле %s должно содержать %s', $key, $expected),
        );
    }
}
