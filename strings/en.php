<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * User interface strings, English (ADR 0008 decision 5, amended by P40;
 * M1 spec part 2 section 5.1). Returns one flat map of 'dotted.key' =>
 * 'text', merged from every `en/*.php` part in file-name order. Each part
 * returns such a map; a key defined by two parts is an error. Placeholders
 * are {name}; the view escapes the text and every substituted value.
 */

declare(strict_types=1);

return (static function (string $dir): array {
    $files = glob($dir . '/*.php');
    if ($files === false || $files === []) {
        throw new LogicException('No string files found.');
    }
    sort($files, SORT_STRING);

    $merged = [];
    foreach ($files as $file) {
        $part = (static fn (string $path): mixed => require $path)($file);
        if (!is_array($part)) {
            throw new LogicException('String file ' . basename($file) . ' must return an array.');
        }
        foreach ($part as $key => $text) {
            if (!is_string($key) || !is_string($text)) {
                throw new LogicException('String file ' . basename($file) . ' must map string keys to string text.');
            }
            if (array_key_exists($key, $merged)) {
                throw new LogicException("Duplicate string key {$key} in " . basename($file) . '.');
            }
            $merged[$key] = $text;
        }
    }

    return $merged;
})(__DIR__ . '/en');
