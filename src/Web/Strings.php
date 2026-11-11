<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;
use OutOfBoundsException;
use UnexpectedValueException;

/**
 * UI strings (ADR 0008 decision 5): a flat `'dotted.key' => 'text'` array
 * loaded once, on first use, from a file the kernel names. Placeholders are
 * `{name}`.
 */
final class Strings
{
    private const PLACEHOLDER = '/\{([A-Za-z0-9_.-]+)\}/';

    /** @var array<string, string>|null */
    private ?array $strings = null;

    public function __construct(private readonly string $file)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->strings());
    }

    /**
     * The raw text with placeholders replaced.
     *
     * @param array<string, string|int|float> $params
     * @throws OutOfBoundsException on an unknown key or a missing parameter
     */
    public function get(string $key, array $params = []): string
    {
        return $this->format($key, $params, static fn (string $text): string => $text);
    }

    /**
     * The text with placeholders replaced, where both the literal text and
     * each substituted value are passed through $escape (once, in a single
     * pass, so a value is never re-scanned for placeholders).
     *
     * @param array<string, string|int|float> $params
     * @param callable(string): string        $escape
     * @throws OutOfBoundsException on an unknown key or a missing parameter
     */
    public function format(string $key, array $params, callable $escape): string
    {
        $strings = $this->strings();
        if (!array_key_exists($key, $strings)) {
            throw new OutOfBoundsException("Unknown string key: {$key}");
        }

        $parts = preg_split(self::PLACEHOLDER, $strings[$key], -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new UnexpectedValueException("Cannot parse string: {$key}");
        }

        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $out .= $escape($part);
                continue;
            }
            if (!array_key_exists($part, $params)) {
                throw new OutOfBoundsException("Missing parameter {$part} for string key: {$key}");
            }
            $value = $params[$part];
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException("Parameter {$part} must be a string or a number.");
            }
            $out .= $escape((string) $value);
        }

        return $out;
    }

    /** @return array<string, string> */
    private function strings(): array
    {
        if ($this->strings !== null) {
            return $this->strings;
        }
        if (!is_file($this->file)) {
            throw new UnexpectedValueException('The strings file is missing.');
        }

        $loaded = require $this->file;
        if (!is_array($loaded)) {
            throw new UnexpectedValueException('The strings file must return an array.');
        }
        foreach ($loaded as $key => $text) {
            if (!is_string($key) || !is_string($text)) {
                throw new UnexpectedValueException('The strings file must map string keys to string text.');
            }
        }

        /** @var array<string, string> $loaded */
        return $this->strings = $loaded;
    }
}
