<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Export\Json;

use TiffinDB\Core\Io\Contract\ExportField;
use TiffinDB\Core\Io\Contract\ExportValue;
use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * JSON text for the export format (docs/formats/json-export.md, M3 spec
 * section 10.3). Strings go through json_encode with FLAGS: UTF-8 is
 * written as is, `/` is not escaped, U+2028 and U+2029 are escaped (PHP
 * does so unless JSON_UNESCAPED_LINE_TERMINATORS is given), and each
 * invalid UTF-8 sequence becomes U+FFFD so the file is always valid UTF-8
 * (the same rule as the core CSV reader). Numbers are never passed through
 * json_encode as floats: `number` values are the field type's own text
 * (plain decimal, never an exponent, never `12.0`), currency amounts are
 * built from the minor units with integer and string arithmetic, and
 * floats inside field options are written as the shortest plain decimal
 * that reads back to the same float.
 *
 * @internal
 */
final class JsonText
{
    public const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** A JSON number token: optional minus, no leading zeros, optional fraction, no exponent. */
    private const NUMBER = '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?\z/';
    private const MAX_FRACTION_DIGITS = 20;

    private function __construct()
    {
    }

    public static function string(string $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    public static function nullableString(?string $value): string
    {
        return $value === null ? 'null' : self::string($value);
    }

    /**
     * Any options value: objects for string-keyed arrays (an empty array at
     * the top is the empty object), lists for lists.
     */
    public static function options(array $options): string
    {
        return $options === [] ? '{}' : self::any($options);
    }

    /** @param list<int> $ids */
    public static function ids(array $ids): string
    {
        $parts = [];
        foreach ($ids as $id) {
            $parts[] = (string) $id;
        }

        return '[' . implode(',', $parts) . ']';
    }

    /** The JSON value of one record value for a field of type $field->type (section 4 of the format). */
    public static function value(ExportField $field, ?ExportValue $value): string
    {
        $type = $field->type;
        $raw = $value?->value;
        switch ($type) {
            case 'text':
            case 'long_text':
            case 'date':
            case 'time':
            case 'date_time':
            case 'choice':
            case 'url':
            case 'email':
            case 'phone':
                return is_string($raw) ? self::string($raw) : 'null';
            case 'number':
                return $raw === null || $value === null ? 'null' : self::numberText($value->text);
            case 'currency':
                return is_int($raw) ? self::string(self::currency($raw, self::minorDigits($field->options))) : 'null';
            case 'duration':
            case 'rating':
            case 'auto_number':
                return is_int($raw) ? (string) $raw : 'null';
            case 'checkbox':
                return $raw === true ? 'true' : 'false';
            case 'relation':
            case 'attachment':
                return self::ids($value === null ? [] : $value->ids);
        }

        throw new PluginFailure(PluginFailure::REASON_UNSUPPORTED, 'The JSON format has no form for this field type.');
    }

    /**
     * The decimal text of $minor minor units with exactly $digits fraction
     * digits and no separators: "329.00", "-5.50", "1200" for 0 digits.
     */
    public static function currency(int $minor, int $digits): string
    {
        $text = (string) $minor;
        $negative = str_starts_with($text, '-');
        $magnitude = $negative ? substr($text, 1) : $text;
        if ($digits > 0) {
            $magnitude = str_pad($magnitude, $digits + 1, '0', STR_PAD_LEFT);
            $magnitude = substr($magnitude, 0, -$digits) . '.' . substr($magnitude, -$digits);
        }

        return ($negative ? '-' : '') . $magnitude;
    }

    /** The field type's text of a number, checked to be a plain JSON number token. */
    public static function numberText(string $text): string
    {
        if (preg_match(self::NUMBER, $text) !== 1) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'A number value has no plain decimal text.');
        }

        return $text;
    }

    /** The shortest plain decimal (no exponent) that reads back to $value. */
    public static function float(float $value): string
    {
        if (!is_finite($value)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'An option holds a number that is not finite.');
        }
        for ($digits = 0; $digits <= self::MAX_FRACTION_DIGITS; $digits++) {
            $text = sprintf('%.' . $digits . 'F', $value);
            if ((float) $text === $value) {
                return $text === '-0' ? '0' : $text;
            }
        }

        return sprintf('%.' . self::MAX_FRACTION_DIGITS . 'F', $value);
    }

    /** @param array<string, mixed> $options */
    private static function minorDigits(array $options): int
    {
        $digits = $options['minor_digits'] ?? 2;

        return is_int($digits) && $digits >= 0 && $digits <= 9 ? $digits : 2;
    }

    private static function any(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::float($value);
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if (is_array($value)) {
            $parts = [];
            if (array_is_list($value)) {
                foreach ($value as $item) {
                    $parts[] = self::any($item);
                }

                return '[' . implode(',', $parts) . ']';
            }
            foreach ($value as $key => $item) {
                $parts[] = self::string((string) $key) . ':' . self::any($item);
            }

            return '{' . implode(',', $parts) . '}';
        }

        throw new PluginFailure(PluginFailure::REASON_FAILED, 'An option holds a value JSON cannot hold.');
    }
}
