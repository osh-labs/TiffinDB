<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

/**
 * The six appearance settings and the root-element classes they produce
 * (ADR 0008 decision 11; M0 spec section 7). This is the only place that
 * knows the class names.
 *
 * Request values are used only as lookup keys into the constant maps
 * below (strict, case-sensitive). They are never stored, echoed, or logged:
 * only the mapped class names leave this object.
 */
final class Appearance
{
    /** Query parameter => (accepted value => root class). Defaults emit no class. */
    public const MAP = [
        'theme' => ['dark' => 't-dark', 'hc-light' => 't-hcl', 'hc-dark' => 't-hcd'],
        'accent' => ['indigo' => 'a-indigo', 'plum' => 'a-plum', 'graphite' => 'a-graphite'],
        'font' => [
            'atkinson' => 'f-atk',
            'lexend' => 'f-lexend',
            'source-serif' => 'f-serif4',
            'device-sans' => 'f-sans',
            'device-serif' => 'f-serif',
        ],
        'size' => ['small' => 's-small', 'large' => 's-large', 'larger' => 's-larger', 'largest' => 's-largest'],
        'spacing' => ['roomy' => 'l-roomy', 'very-roomy' => 'l-very-roomy'],
        'motion' => ['reduce' => 'm-reduce'],
    ];

    /**
     * Query parameter => `user_settings` column holding the same values
     * (reduce_motion, an integer, is mapped separately). The stored defaults
     * (`light`, `teal`, `inter`, `standard`) are not in MAP and give no class.
     */
    private const SETTING_COLUMNS = [
        'theme' => 'theme',
        'accent' => 'accent',
        'font' => 'typeface',
        'size' => 'text_size',
        'spacing' => 'line_spacing',
    ];

    /** Theme names for the four themes, by root class ('' is the default light theme). */
    private const THEME_NAMES = ['' => 'light', 't-dark' => 'dark', 't-hcl' => 'hc-light', 't-hcd' => 'hc-dark'];

    private const HIGH_CONTRAST = ['t-hcl', 't-hcd'];

    /** @param array<string, string> $classes Setting => chosen class ('' for the default). */
    private function __construct(private readonly array $classes)
    {
    }

    public static function default(): self
    {
        return new self(array_fill_keys(array_keys(self::MAP), ''));
    }

    /** @param array<array-key, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $classes = [];
        foreach (self::MAP as $parameter => $values) {
            $value = $query[$parameter] ?? null;
            $classes[$parameter] = is_string($value) && array_key_exists($value, $values) ? $values[$value] : '';
        }

        return new self($classes);
    }

    /**
     * The appearance stored for a signed-in person: a `user_settings` row
     * (M1 spec part 1 section 5.5; part 2 section 2.7). Each column is
     * mapped through the same allowlist as the query parameters; a value
     * outside the lists, a missing column, or a value of the wrong type
     * gives that setting's default. `reduce_motion` 1 gives `m-reduce`.
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromSettings(array $row): self
    {
        $classes = [];
        foreach (self::SETTING_COLUMNS as $parameter => $column) {
            $value = $row[$column] ?? null;
            $values = self::MAP[$parameter];
            $classes[$parameter] = is_string($value) && array_key_exists($value, $values) ? $values[$value] : '';
        }
        $motion = $row['reduce_motion'] ?? null;
        $classes['motion'] = $motion === 1 || $motion === '1' ? self::MAP['motion']['reduce'] : '';

        return new self($classes);
    }

    /** @return list<string> Classes in the order theme, accent, typeface, size, spacing, motion. */
    public function rootClasses(): array
    {
        $result = [];
        foreach ($this->classes as $setting => $class) {
            if ($class === '') {
                continue;
            }
            if ($setting === 'accent' && $this->isHighContrast()) {
                continue;
            }
            $result[] = $class;
        }

        return $result;
    }

    /** The current theme: `light`, `dark`, `hc-light`, or `hc-dark`. */
    public function theme(): string
    {
        return self::THEME_NAMES[$this->classes['theme']];
    }

    private function isHighContrast(): bool
    {
        return in_array($this->classes['theme'], self::HIGH_CONTRAST, true);
    }
}
