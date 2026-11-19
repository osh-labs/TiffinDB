<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * Streaming XML reading of workbook parts with PHP's XMLReader, over the
 * zip:// stream wrapper (nothing is extracted to disk).
 *
 * Parser options: LIBXML_NONET and LIBXML_COMPACT only: no entity
 * substitution, no DTD loading or validation, no XInclude, and no
 * LIBXML_PARSEHUGE, so libxml keeps its own limits on text node size,
 * names, and nesting depth. Parts are opened only after WorkbookCheck has
 * refused any prolog that declares a DOCTYPE or entity.
 *
 * Errors: each piece of parsing runs inside run(), which turns libxml
 * errors and PHP warnings into PluginFailure(REASON_UNREADABLE) and
 * restores the previous error state before it returns, so no state is left
 * changed while a row generator is suspended. A run() inside another one
 * runs in the outer one's scope.
 *
 * @internal
 */
final class Xml
{
    private const OPTIONS = LIBXML_NONET | LIBXML_COMPACT;

    /** How many run() calls are open (an inner call runs inside the outer one's scope). */
    private static int $depth = 0;

    private function __construct()
    {
    }

    /** Opens part $part of the checked workbook at $zipPath; call inside run(). */
    public static function open(string $zipPath, string $part): \XMLReader
    {
        $reader = new \XMLReader();
        if ($reader->open('zip://' . $zipPath . '#' . $part, null, self::OPTIONS) !== true) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'A workbook part cannot be opened.');
        }

        return $reader;
    }

    /**
     * Runs $work with libxml errors collected instead of reported; any libxml
     * error or PHP warning raised meanwhile makes it a PluginFailure.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function run(\Closure $work): mixed
    {
        if (self::$depth > 0) {
            return $work->__invoke();
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $warning = false;
        set_error_handler(static function () use (&$warning): bool {
            $warning = true;

            return true;
        });
        self::$depth++;
        try {
            $result = $work->__invoke();
            $errors = libxml_get_errors();
        } finally {
            self::$depth--;
            restore_error_handler();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        foreach ($errors as $error) {
            if ($error->level !== LIBXML_ERR_WARNING) {
                throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'A workbook part is not well-formed XML.');
            }
        }
        if ($warning) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'A workbook part could not be read.');
        }

        return $result;
    }

    /**
     * The text of the element the reader is on (an `<si>` or `<is>`): the
     * `<t>` children, directly or inside rich text runs `<r>`; phonetic runs
     * (`<rPh>`) and run properties are left out. Kept to MAX_STRING_BYTES.
     * Leaves the reader on the element's end (or on it when it is empty).
     */
    public static function text(\XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }
        $depth = $reader->depth;
        $text = '';
        $more = $reader->read();
        while ($more && !($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth)) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                $name = $reader->localName;
                if ($name === 't') {
                    if (strlen($text) <= Limits::MAX_STRING_BYTES) {
                        $text .= $reader->readString();
                    }
                    $more = $reader->next();
                    continue;
                }
                if ($name !== 'r') {
                    $more = $reader->next();
                    continue;
                }
            }
            $more = $reader->read();
        }

        return self::cut($text);
    }

    /** $text kept to MAX_STRING_BYTES, cut at a character boundary. */
    public static function cut(string $text): string
    {
        if (strlen($text) <= Limits::MAX_STRING_BYTES) {
            return $text;
        }
        $text = substr($text, 0, Limits::MAX_STRING_BYTES);
        // Drop a character cut in half: up to three continuation bytes after a lead byte.
        for ($back = 1; $back <= 4 && $back <= strlen($text); $back++) {
            $byte = ord($text[strlen($text) - $back]);
            if ($byte < 0x80) {
                break;
            }
            if ($byte >= 0xC0) {
                $need = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);
                if ($need > $back) {
                    $text = substr($text, 0, -$back);
                }
                break;
            }
        }

        return $text;
    }

    /**
     * The value of attribute $local of the current element when it is in a
     * namespace (any prefix): used for `r:id`, whose namespace URI differs
     * between transitional and strict workbooks.
     */
    public static function prefixedAttribute(\XMLReader $reader, string $local): ?string
    {
        $value = null;
        if ($reader->moveToFirstAttribute()) {
            do {
                if ($reader->localName === $local && $reader->prefix !== '' && $reader->prefix !== 'xmlns') {
                    $value = $reader->value;
                    break;
                }
            } while ($reader->moveToNextAttribute());
            $reader->moveToElement();
        }

        return $value;
    }
}
