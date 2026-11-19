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
 * zip:// stream wrapper (nothing is extracted to disk). Parts are opened
 * only after WorkbookCheck has refused any prolog that declares a DOCTYPE
 * or entity and has counted every entry's real bytes, so a part never
 * yields more bytes than its (capped) declared size.
 *
 * Parser options: LIBXML_NONET and LIBXML_COMPACT only: no entity
 * substitution, no DTD loading or validation, no XInclude, and no
 * LIBXML_PARSEHUGE, so libxml keeps its own limits on text node size
 * (10 MB), names, and nesting depth. LIBXML_NONET is defence in depth: no
 * part can declare an external entity or DTD after the prolog check, but
 * the parser is still never allowed to fetch one.
 *
 * Never on untrusted subtrees: XMLReader::readString(), readInnerXml(),
 * readOuterXml(), and expand() build the whole subtree in libxml's memory,
 * outside PHP's memory_limit (a few megabytes of a workbook can hold
 * hundreds of megabytes of child elements). Text is collected here from
 * text nodes one read() at a time, up to MAX_STRING_BYTES, and elements
 * where text is expected are skipped with next() without being built.
 *
 * Work limit: every read() and next() goes through this class and counts
 * one step against the current phase's budget (phase(), budget()); a
 * phase that passes its budget is a PluginFailure(REASON_TOO_LARGE), so
 * the parsing work of one call is bounded whatever the file holds.
 * steps() is the total since the process started (tests measure with it).
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

    /** How many run() calls are open. */
    private static int $depth = 0;
    /** Steps left in the current phase. */
    private static int $remaining = 0;
    /** Steps taken since the process started. */
    private static int $steps = 0;

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
     * Runs $work with a budget of $steps reads; the enclosing budget is
     * restored afterwards (the steps of this phase do not count against it).
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function phase(int $steps, \Closure $work): mixed
    {
        $outer = self::$remaining;
        self::$remaining = $steps;
        try {
            return $work->__invoke();
        } finally {
            self::$remaining = $outer;
        }
    }

    /** Starts a new budget of $steps reads (for a row generator, which cannot wrap its yields in phase()). */
    public static function budget(int $steps): void
    {
        self::$remaining = $steps;
    }

    /** Steps taken since the process started. */
    public static function steps(): int
    {
        return self::$steps;
    }

    /** XMLReader::read(), counted. */
    public static function read(\XMLReader $reader): bool
    {
        self::step();

        return $reader->read();
    }

    /** XMLReader::next(), counted: moves past the current node's subtree without building it. */
    public static function next(\XMLReader $reader): bool
    {
        self::step();

        return $reader->next();
    }

    private static function step(): void
    {
        self::$steps++;
        if (--self::$remaining < 0) {
            throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'The workbook needs more reading work than the limit allows.');
        }
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
     * The text of the element the reader is on (an `<si>` or `<is>`): its
     * `<t>` children, directly or inside rich text runs `<r>`; phonetic runs
     * (`<rPh>`), run properties, and anything else are skipped. Kept to
     * MAX_STRING_BYTES. Leaves the reader on the element's end (or on it
     * when it is empty).
     */
    public static function text(\XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }
        $depth = $reader->depth;
        $text = '';
        $more = self::read($reader);
        while ($more && !($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth)) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                $name = $reader->localName;
                $inRun = $reader->depth === $depth + 2;
                if ($name === 't' && ($reader->depth === $depth + 1 || $inRun)) {
                    $text .= self::content($reader, Limits::MAX_STRING_BYTES + 1 - strlen($text));
                    $more = self::read($reader);
                    continue;
                }
                if ($name !== 'r' || $reader->depth !== $depth + 1) {
                    $more = self::next($reader);
                    continue;
                }
            }
            $more = self::read($reader);
        }

        return self::cut($text);
    }

    /**
     * The character data directly inside the element the reader is on (a
     * `<t>` or `<v>`): text, CDATA, and white space nodes, kept while fewer
     * than $room bytes are held (cut() trims the last one). Child elements
     * are skipped with next(), never built. Leaves the reader on the
     * element's end (or on it when it is empty).
     */
    public static function content(\XMLReader $reader, int $room = Limits::MAX_STRING_BYTES + 1): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }
        $depth = $reader->depth;
        $text = '';
        $more = self::read($reader);
        while ($more && !($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth)) {
            $type = $reader->nodeType;
            if ($type === \XMLReader::ELEMENT) {
                $more = self::next($reader);
                continue;
            }
            if (strlen($text) < $room && ($type === \XMLReader::TEXT || $type === \XMLReader::CDATA
                || $type === \XMLReader::SIGNIFICANT_WHITESPACE || $type === \XMLReader::WHITESPACE)) {
                $text .= $reader->value;
            }
            $more = self::read($reader);
        }

        return $text;
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
