<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * The zip check that runs before any XML of an uploaded workbook is parsed
 * (M3 spec section 9.1, ADR 0016 decision 4). Uses only PHP's ZipArchive.
 * Two parts: directory() reads the zip directory only and runs on every
 * call; contents() reads every entry's bytes and runs once per job (its
 * result is kept in the job cache, JobCache).
 *
 * directory(). Refused as unreadable: not a zip (an encrypted workbook is
 * an OLE file, not a zip), damaged, `[Content_Types].xml` or
 * `xl/workbook.xml` absent, an entry name containing `..`, a leading `/`,
 * `\` or NUL, or two entries whose names are equal ignoring case (package
 * part names are case-insensitive). Refused as too large: more than 10,000
 * entries, an entry declaring more than 256 MiB, more than 512 MiB in
 * total, or a declared ratio over 200 for an entry larger than 1 MiB.
 * Refused as unsupported: an encrypted entry, a compression method other
 * than stored or deflated, and macros by part name (`vbaProject.bin`,
 * `vbaProjectSignature.bin`, `vbaData.xml`, anything under a `macrosheets`
 * or `dialogsheets` folder).
 *
 * contents(). Every entry is read to its end in fixed-size chunks and its
 * real bytes are counted: more bytes than declared (libzip streams past a
 * lying size header) is refused as unreadable at the first extra chunk,
 * and so is a damaged entry (a CRC or inflate error). After this pass the
 * declared sizes, which the caps above hold, are the real ones. The prolog
 * of every entry (the bytes before the first element, at most 4,096) is
 * read as it would be parsed: an entry in an encoding other than UTF-8 (a
 * UTF-16 or UTF-32 byte order mark or signature, EBCDIC, or an XML
 * declaration naming another encoding) is unsupported, and the prolog may
 * hold only the XML declaration, comments, processing instructions, and
 * white space. A DOCTYPE, an ENTITY declaration, or anything else, and a
 * prolog longer than 4,096 bytes, is refused as unsupported, so no part
 * this plugin reads can declare an entity (no external entity can be
 * fetched and no internal entity expanded, whatever the host's libxml
 * does). Every entry is checked, not only `.xml` and `.rels` ones, because
 * a relationship may name a part of any name. Entries that do not start
 * like XML (images, other binary parts) are left alone: an XML parser
 * refuses them before reading any declaration. Last, `[Content_Types].xml`
 * is parsed (so character references are decoded) and any macro content
 * type (macro-enabled workbook, VBA project, VBA data, macro sheet) is
 * refused as unsupported.
 *
 * @internal
 */
final class WorkbookCheck
{
    public const CONTENT_TYPES = '[Content_Types].xml';
    public const WORKBOOK = 'xl/workbook.xml';
    /** Words that mark a macro part in a content type (lower case). */
    public const MACRO_TYPES = ['macroenabled', 'vbaproject', 'vbadata', 'macrosheet', 'dialogsheet'];

    /** Leading bytes of XML in an encoding that is not ASCII-compatible (XML 1.0 appendix F). */
    private const OTHER_ENCODINGS = [
        "\x00\x00\xFE\xFF", "\xFF\xFE\x00\x00", "\x00\x00\x00\x3C", "\x3C\x00\x00\x00", "\x00\x00\x3C\x00", "\x00\x3C\x00\x00",
        "\x4C\x6F\xA7\x94", "\xFE\xFF", "\xFF\xFE", "\x00\x3C", "\x3C\x00", "\xDD\x73\x66\x73", "\x2B\x2F\x76",
    ];
    private const MACRO_NAMES = '#(^|/)(vbaProject\.bin|vbaProjectSignature\.bin|vbaData\.xml)\z|(^|/)(macrosheets|dialogsheets)/#i';

    private function __construct()
    {
    }

    /** Both checks; returns directory()'s result. */
    public static function check(string $path): array
    {
        $names = self::directory($path);
        self::contents($path);

        return $names;
    }

    /**
     * The zip directory checks; throws PluginFailure when the workbook is
     * refused. Returns lower-case entry name => entry name.
     *
     * @return array<string, string>
     */
    public static function directory(string $path): array
    {
        $zip = self::openZip($path);
        try {
            $count = $zip->count();
            if ($count > Limits::MAX_ENTRIES) {
                throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'The workbook has too many entries.');
            }
            $names = [];
            $total = 0;
            for ($i = 0; $i < $count; $i++) {
                $stat = self::stat($zip, $i);
                $name = (string) $stat['name'];
                $lower = strtolower($name);
                if ($name === '' || str_contains($name, '..') || $name[0] === '/' || str_contains($name, '\\') || str_contains($name, "\0") || isset($names[$lower])) {
                    throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'An entry has a name that is not allowed.');
                }
                $names[$lower] = $name;
                if ((int) $stat['encryption_method'] !== \ZipArchive::EM_NONE) {
                    throw new PluginFailure(PluginFailure::REASON_UNSUPPORTED, 'An entry is encrypted.');
                }
                $method = (int) $stat['comp_method'];
                if ($method !== \ZipArchive::CM_STORE && $method !== \ZipArchive::CM_DEFLATE) {
                    throw new PluginFailure(PluginFailure::REASON_UNSUPPORTED, 'An entry uses a compression method that is not read.');
                }
                $size = (int) $stat['size'];
                $packed = (int) $stat['comp_size'];
                if ($size < 0 || $packed < 0 || $size > Limits::MAX_ENTRY_BYTES) {
                    throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'An entry is larger than the limit.');
                }
                $total += $size;
                if ($total > Limits::MAX_TOTAL_BYTES) {
                    throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'The entries are larger than the limit in total.');
                }
                if ($size > Limits::RATIO_FREE_BYTES && ($packed === 0 || $size > $packed * Limits::MAX_RATIO)) {
                    throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'An entry is compressed more than the limit allows.');
                }
                if (preg_match(self::MACRO_NAMES, $name) === 1) {
                    throw new PluginFailure(PluginFailure::REASON_UNSUPPORTED, 'The workbook holds macros.');
                }
            }
            if (($names[strtolower(self::CONTENT_TYPES)] ?? null) !== self::CONTENT_TYPES || ($names[self::WORKBOOK] ?? null) !== self::WORKBOOK) {
                throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The workbook parts are missing.');
            }

            return $names;
        } finally {
            $zip->close();
        }
    }

    /**
     * The checks of every entry's bytes (real sizes, prologs, content
     * types); throws PluginFailure when the workbook is refused. Run
     * directory() first.
     */
    public static function contents(string $path): void
    {
        $zip = self::openZip($path);
        try {
            for ($i = 0, $count = $zip->count(); $i < $count; $i++) {
                $head = self::scan($zip, $i, (int) self::stat($zip, $i)['size']);
                $problem = self::prologProblem($head, strlen($head) < Limits::PROLOG_BYTES);
                if ($problem !== null) {
                    throw new PluginFailure($problem, 'An XML part has a prolog that is not read.');
                }
            }
        } finally {
            $zip->close();
        }
        Xml::phase(Limits::STEPS_STRUCTURE, static fn () => Xml::run(static function () use ($path): void {
            $reader = Xml::open($path, self::CONTENT_TYPES);
            try {
                while (Xml::read($reader)) {
                    if ($reader->nodeType !== \XMLReader::ELEMENT) {
                        continue;
                    }
                    $type = strtolower((string) $reader->getAttribute('ContentType'));
                    foreach (self::MACRO_TYPES as $word) {
                        if (str_contains($type, $word)) {
                            throw new PluginFailure(PluginFailure::REASON_UNSUPPORTED, 'The workbook holds macros.');
                        }
                    }
                }
            } finally {
                $reader->close();
            }
        }));
    }

    private static function openZip(string $path): \ZipArchive
    {
        if (str_contains($path, '#') || !is_file($path)) {
            // The zip:// wrapper splits at '#'; the application never chooses such a name.
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The uploaded file cannot be opened.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The file is not a readable zip archive.');
        }

        return $zip;
    }

    /** @return array<string, mixed> */
    private static function stat(\ZipArchive $zip, int $index): array
    {
        $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
        if ($stat === false) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'An entry cannot be read.');
        }

        return $stat;
    }

    /**
     * Reads entry $index to its end in READ_CHUNK pieces, counting its real
     * bytes against $declared; returns its first PROLOG_BYTES bytes.
     */
    private static function scan(\ZipArchive $zip, int $index, int $declared): string
    {
        $stream = $zip->getStreamIndex($index, \ZipArchive::FL_UNCHANGED);
        if ($stream === false) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'An entry cannot be read.');
        }
        $head = '';
        $real = 0;
        $damaged = false;
        set_error_handler(static function () use (&$damaged): bool {
            $damaged = true;

            return true;
        });
        try {
            while (!$damaged && !feof($stream)) {
                $chunk = fread($stream, Limits::READ_CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $real += strlen($chunk);
                if ($real > $declared) {
                    break;
                }
                if (strlen($head) < Limits::PROLOG_BYTES) {
                    $head .= substr($chunk, 0, Limits::PROLOG_BYTES - strlen($head));
                }
            }
            fclose($stream);
        } finally {
            restore_error_handler();
        }
        if ($real > $declared) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'An entry holds more bytes than its header declares.');
        }
        if ($damaged || $real !== $declared) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'An entry is damaged.');
        }

        return $head;
    }

    /**
     * Why the first bytes of an entry are refused (a PluginFailure reason),
     * or null when they are not XML or are XML with a plain prolog.
     * $complete: $head is the whole entry.
     */
    public static function prologProblem(string $head, bool $complete): ?string
    {
        if (strlen($head) > Limits::PROLOG_BYTES) {
            $head = substr($head, 0, Limits::PROLOG_BYTES);
            $complete = false;
        }
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }
        foreach (self::OTHER_ENCODINGS as $signature) {
            if (str_starts_with($head, $signature)) {
                return PluginFailure::REASON_UNSUPPORTED;
            }
        }
        if (preg_match('/^[ \t\r\n]*</', $head) !== 1) {
            return null;
        }
        $at = 0;
        $length = strlen($head);
        if (str_starts_with($head, '<?xml') && preg_match('/^<\?xml[ \t\r\n]/', $head) === 1) {
            $end = strpos($head, '?>');
            if ($end === false) {
                return PluginFailure::REASON_UNSUPPORTED;
            }
            $declaration = substr($head, 0, $end);
            if (preg_match('/[ \t\r\n]encoding[ \t\r\n]*=[ \t\r\n]*(["\'])([^"\']*)\1/', $declaration, $m) === 1
                && !in_array(strtolower($m[2]), ['utf-8', 'utf8'], true)) {
                return PluginFailure::REASON_UNSUPPORTED;
            }
            $at = $end + 2;
        }
        while (true) {
            $at += strspn($head, " \t\r\n", $at);
            if ($at >= $length) {
                // The prolog runs past what was read, or the entry ends without an element.
                return $complete ? null : PluginFailure::REASON_UNSUPPORTED;
            }
            if ($head[$at] !== '<') {
                return PluginFailure::REASON_UNREADABLE;
            }
            $rest = substr($head, $at, 4);
            if ($rest === '<!--') {
                $end = strpos($head, '-->', $at + 4);
                if ($end === false) {
                    return PluginFailure::REASON_UNSUPPORTED;
                }
                $at = $end + 3;
                continue;
            }
            if (str_starts_with($rest, '<?')) {
                $end = strpos($head, '?>', $at + 2);
                if ($end === false) {
                    return PluginFailure::REASON_UNSUPPORTED;
                }
                $at = $end + 2;
                continue;
            }
            if ($at + 1 >= $length) {
                return $complete ? PluginFailure::REASON_UNREADABLE : PluginFailure::REASON_UNSUPPORTED;
            }
            $next = $head[$at + 1];
            if (preg_match('/^[A-Za-z_:\x80-\xFF]\z/', $next) === 1) {
                return null;
            }

            // `<!DOCTYPE`, `<!ENTITY`, `<![CDATA[` or anything else before the first element.
            return PluginFailure::REASON_UNSUPPORTED;
        }
    }
}
