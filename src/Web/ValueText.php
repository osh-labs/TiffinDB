<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;
use TiffinDB\Core\Schema\FieldDef;

/**
 * Field values as plain display text (M2 spec part 2 section 5.2) and as
 * the `value` of a record form input (section 5.1). Every result is plain,
 * unescaped text; templates reach it through the view-scope helpers
 * value() and inputValue(), which escape it, and controllers pass it to
 * t() or e() (text is escaped once, at output).
 *
 * Values are the core's canonical values (M2 spec part 1 section 3.1):
 * strings for text, dates (`YYYY-MM-DD`), times (`HH:MM:SS`), date-times
 * (`YYYY-MM-DDTHH:MM:SS`) and choice labels; ints for currency minor
 * units, durations (seconds), ratings, and auto-numbers; int or float for
 * numbers; bool for checkboxes. For display only, an attachment value is
 * the number of files and a relation value is the list of the linked
 * records' display titles. null (or '') is empty.
 */
final class ValueText
{
    /** Text longer than this is cut in a list cell, with an ellipsis. */
    public const CELL_LENGTH = 80;

    public const MODES = ['text', 'cell'];

    /** Field types that have an input on the record form (section 5.1). */
    public const EDITABLE = [
        'text', 'long_text', 'number', 'currency', 'date', 'time', 'date_time', 'duration',
        'checkbox', 'choice', 'rating', 'url', 'email', 'phone',
    ];

    /** Relation titles shown before "and N more". */
    private const LINKS_SHOWN = 3;

    public function __construct(
        private readonly Strings $strings,
        private readonly Format $format,
    ) {
    }

    /** The display text of a value in `text` or `cell` mode. */
    public function display(FieldDef $field, mixed $value, string $mode = 'text'): string
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Unknown display mode.');
        }
        $cell = $mode === 'cell';
        $options = $field->options;

        switch ($field->type) {
            case 'checkbox':
                return $this->strings->get($value === true ? 'value.yes' : 'value.no');
            case 'attachment':
                $count = $value === null ? 0 : self::int($value);

                return $count > 0 ? $this->strings->get('value.files', ['count' => $count]) : $this->strings->get('value.none');
            case 'relation':
                return $this->links(is_array($value) ? $value : ($value === null ? [] : throw new InvalidArgumentException('A relation value is a list of titles.')));
        }
        if ($value === null || $value === '') {
            return '';
        }

        return match ($field->type) {
            'text', 'url', 'email', 'phone' => $cell ? self::cut(self::string($value)) : self::string($value),
            'long_text' => $cell ? self::cut(self::firstLine(self::string($value))) : self::string($value),
            'choice' => self::string($value),
            'number' => $this->format->decimal(self::number($value), self::option($options, 'decimals', 0)),
            'currency' => $this->format->money(
                self::int($value),
                is_string($options['code'] ?? null) ? $options['code'] : 'USD',
                self::option($options, 'minor_digits', 2),
            ),
            'date' => $this->format->date(self::string($value)),
            'time' => $this->format->time(self::string($value), ($options['seconds'] ?? false) === true),
            'date_time' => $this->format->dateTime(self::string($value), ($options['seconds'] ?? false) === true),
            'duration' => $this->format->duration(self::int($value), ($options['seconds'] ?? false) === true),
            'rating' => $this->strings->get('value.rating', ['value' => self::int($value), 'max' => self::option($options, 'max', 5)]),
            'auto_number' => (string) self::int($value),
            default => throw new InvalidArgumentException('Unknown field type.'),
        };
    }

    /**
     * The `value` of a record form input: typed text unchanged when it was
     * submitted, else the bound text with the seconds trimmed for time,
     * date-time, and duration fields without the `seconds` option.
     */
    public function input(FieldDef $field, string $text, bool $submitted): string
    {
        if ($submitted || ($field->options['seconds'] ?? false) === true) {
            return $text;
        }

        return match ($field->type) {
            'time' => (string) preg_replace('/^(\d{2}:\d{2}):\d{2}\z/', '$1', $text),
            'date_time' => (string) preg_replace('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}):\d{2}\z/', '$1', $text),
            'duration' => (string) preg_replace('/^(\d+:\d{2}):\d{2}\z/', '$1', $text),
            default => $text,
        };
    }

    /**
     * What the field-input partial needs to know about a field, as plain
     * values (templates cannot read objects): `type`, `editable`,
     * `maxLength` (text and long text), `decimals` (number), `seconds`
     * (time, date-time, duration), `choices` (labels in list order), `max`
     * (rating), and `code` (currency).
     *
     * @return array{type: string, editable: bool, maxLength: int, decimals: int, seconds: bool, choices: list<string>, max: int, code: string}
     */
    public function facts(FieldDef $field): array
    {
        $options = $field->options;
        $choices = [];
        foreach (is_array($options['choices'] ?? null) ? $options['choices'] : [] as $choice) {
            if (is_array($choice) && is_string($choice['label'] ?? null)) {
                $choices[] = $choice['label'];
            } elseif (is_string($choice)) {
                $choices[] = $choice;
            }
        }

        return [
            'type' => $field->type,
            'editable' => in_array($field->type, self::EDITABLE, true),
            'maxLength' => self::option($options, 'max_length', $field->type === 'long_text' ? 20000 : 500),
            'decimals' => self::option($options, 'decimals', 0),
            'seconds' => ($options['seconds'] ?? false) === true,
            'choices' => $choices,
            'max' => self::option($options, 'max', 5),
            'code' => is_string($options['code'] ?? null) ? $options['code'] : 'USD',
        ];
    }

    /** The name of a currency (`currency.<CODE>`) when the strings define one, else the code. */
    public function currencyName(string $code): string
    {
        $key = 'currency.' . $code;

        return preg_match('/^[A-Z]{3}\z/', $code) === 1 && $this->strings->has($key) ? $this->strings->get($key) : $code;
    }

    /**
     * Up to three titles joined by ", ", then " and N more"; "None" when
     * there are none.
     *
     * @param array<array-key, mixed> $titles
     */
    public function links(array $titles): string
    {
        $titles = array_values($titles);
        if ($titles === []) {
            return $this->strings->get('value.none');
        }
        $shown = [];
        foreach (array_slice($titles, 0, self::LINKS_SHOWN) as $title) {
            $shown[] = self::string($title);
        }
        $text = implode(', ', $shown);
        $more = count($titles) - count($shown);

        return $more > 0 ? $text . ' ' . $this->strings->get('value.more_links', ['count' => $more]) : $text;
    }

    /** The text cut to CELL_LENGTH characters in all, ending in "…" when cut. */
    public static function cut(string $text): string
    {
        if (preg_match_all('/./su', $text) <= self::CELL_LENGTH) {
            return $text;
        }
        preg_match('/^.{' . (self::CELL_LENGTH - 1) . '}/su', $text, $m);

        return rtrim($m[0] ?? '') . "\u{2026}";
    }

    private static function firstLine(string $text): string
    {
        return preg_split('/\r\n|\r|\n/', $text, 2)[0] ?? '';
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Expected a text value.');
        }

        return $value;
    }

    private static function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException('Expected a whole-number value.');
        }

        return $value;
    }

    private static function number(mixed $value): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Expected a number value.');
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private static function option(array $options, string $name, int $default): int
    {
        return is_int($options[$name] ?? null) ? $options[$name] : $default;
    }
}
