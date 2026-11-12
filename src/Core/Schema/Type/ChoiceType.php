<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Storage\Keys;

/**
 * One of a list of labels; the label itself is stored (M1 spec section 8.2,
 * P19). Option `choices` is a list of `{"key": <key>, "label": <text>}`:
 * 1 to 200 entries, labels 1 to 100 characters after trimming, no control
 * characters, unique ignoring ASCII case. A given entry may be a label
 * string or an array with `label` and an optional `key`; blank labels are
 * dropped (a form posts one choice per line). A valid key not already used
 * by an earlier entry is kept; otherwise a new key is generated.
 */
final class ChoiceType implements FieldType
{
    public const MAX_CHOICES = 200;
    public const MAX_LABEL = 100;

    public function __construct(private readonly Keys $keys)
    {
    }

    public function id(): string
    {
        return 'choice';
    }

    public function columnDefinition(): ?string
    {
        return 'TEXT';
    }

    public function defaultOptions(): array
    {
        return ['choices' => []];
    }

    public function normaliseOptions(array $options): array
    {
        $reader = new Options($options);
        $given = $reader->raw('choices') ?? [];
        if (!is_array($given)) {
            $reader->add('choices', 'problem.options.invalid');
            $given = [];
        }
        $entries = [];
        $seen = [];
        $reported = [];
        $flagged = [];
        foreach ($given as $entry) {
            $key = null;
            if (is_array($entry) && is_string($entry['label'] ?? null)) {
                $label = $entry['label'];
                $key = Keys::isValid($entry['key'] ?? null) ? $entry['key'] : null;
            } elseif (is_string($entry)) {
                $label = $entry;
            } else {
                self::once($reader, $flagged, 'problem.options.invalid');
                continue;
            }
            if (!Text::isUtf8($label)) {
                self::once($reader, $flagged, 'problem.text.control');
                continue;
            }
            $label = Text::trim($label);
            if ($label === '') {
                continue;
            }
            if (Text::hasControl($label)) {
                self::once($reader, $flagged, 'problem.text.control');
                continue;
            }
            if (Text::length($label) > self::MAX_LABEL) {
                self::once($reader, $flagged, 'problem.options.choice_too_long', ['max' => self::MAX_LABEL]);
                continue;
            }
            $folded = strtolower($label);
            if (isset($seen[$folded])) {
                if (!isset($reported[$folded])) {
                    $reader->add('choices', 'problem.options.choices_duplicate', ['label' => $label]);
                    $reported[$folded] = true;
                }
                continue;
            }
            $seen[$folded] = true;
            $entries[] = ['key' => $key, 'label' => $label];
        }
        if (count($entries) > self::MAX_CHOICES) {
            $reader->add('choices', 'problem.options.choices_too_many', ['max' => self::MAX_CHOICES]);
        }
        if ($entries === [] && $flagged === [] && $reported === []) {
            $reader->add('choices', 'problem.options.choices_empty');
        }
        $reader->finish([]);

        return ['choices' => $this->withKeys($entries)];
    }

    public function fromStorage(mixed $stored, array $options): mixed
    {
        return TextValues::fromStorage($stored);
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        return TextValues::toStorage($value);
    }

    public function check(mixed $value, array $options): Checked
    {
        if ($value === null) {
            return Checked::ok(null);
        }

        return is_string($value) ? $this->parse($value, $options) : Checked::problem('problem.choice.invalid');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.choice.invalid');
        }
        $text = Text::trim($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        $labels = self::labels($options);
        if (in_array($text, $labels, true)) {
            return Checked::ok($text);
        }
        foreach ($labels as $label) {
            // strcasecmp folds ASCII letters only (PHP 8.2 and later).
            if (strcasecmp($label, $text) === 0) {
                return Checked::ok($label);
            }
        }

        return Checked::problem('problem.choice.invalid');
    }

    public function toText(mixed $value, array $options): string
    {
        return TextValues::toText($value);
    }

    public function canBeTitle(): bool
    {
        return true;
    }

    public function isEditable(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private static function labels(array $options): array
    {
        $labels = [];
        foreach (is_array($options['choices'] ?? null) ? $options['choices'] : [] as $choice) {
            if (is_array($choice) && is_string($choice['label'] ?? null)) {
                $labels[] = $choice['label'];
            }
        }

        return $labels;
    }

    /**
     * @param list<array{key: ?string, label: string}> $entries
     * @return list<array{key: string, label: string}>
     */
    private function withKeys(array $entries): array
    {
        $used = [];
        foreach ($entries as $i => $entry) {
            if ($entry['key'] !== null && !isset($used[$entry['key']])) {
                $used[$entry['key']] = $i;
            }
        }
        $result = [];
        foreach ($entries as $i => $entry) {
            $key = $entry['key'];
            if ($key === null || $used[$key] !== $i) {
                $key = $this->keys->generateUnique(static fn (string $k): bool => isset($used[$k]));
                $used[$key] = $i;
            }
            $result[] = ['key' => $key, 'label' => $entry['label']];
        }

        return $result;
    }

    /**
     * @param array<string, true> $flagged
     * @param array<string, string|int> $params
     */
    private static function once(Options $reader, array &$flagged, string $key, array $params = []): void
    {
        if (!isset($flagged[$key])) {
            $reader->add('choices', $key, $params);
            $flagged[$key] = true;
        }
    }
}
