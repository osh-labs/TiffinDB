<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Csv;

use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * Streaming CSV record reader (M3 spec section 8.1; RFC 4180 with common
 * variations). Security-critical: it parses uploaded files.
 *
 * - Records end at LF, CRLF, or CR outside quotes.
 * - A field starting with `"` runs to the next `"` not followed by `"`;
 *   `""` is one `"`; line breaks inside are kept, CRLF and CR becoming LF.
 *   Text after the closing quote up to the delimiter is appended as is; a
 *   quote inside an unquoted field is literal. A quote left open runs to
 *   the end of the file.
 * - NUL becomes U+FFFD. The byte order mark of the chosen character set is
 *   skipped.
 *
 * Bounds: the file is read in pieces of CHUNK_BYTES and never as a whole;
 * a record of more than $maxRecordBytes (decoded) bytes stops reading with
 * PluginFailure(REASON_TOO_LARGE), so the buffer never holds much more than
 * one record and one piece; fields past $maxFields are scanned but not
 * kept (fieldCount() still counts them), so a line of a million
 * delimiters costs no memory.
 *
 * Resumption: checkpoint() gives, for the record just returned, the input
 * offset where a fresh decoder can start and the number of decoded bytes
 * to skip from there; a reader built with that checkpoint returns the same
 * records from that one on.
 */
final class CsvReader
{
    public const CHUNK_BYTES = 65536;
    /** One record at most (IoLimits::MAX_CSV_RECORD_BYTES; this namespace uses only the contract, so the figure is repeated and a test keeps the two equal). */
    public const MAX_RECORD_BYTES = 1048576;
    /**
     * The most decoded bytes a checkpoint may skip: one decoded piece is at
     * most three times CHUNK_BYTES (Windows-1252 and replaced bytes grow to
     * three bytes each) plus a few held bytes.
     */
    public const MAX_SKIP = 4 * self::CHUNK_BYTES;

    private const START = 0;
    private const PLAIN = 1;
    private const QUOTED = 2;
    private const QUOTE_SEEN = 3;

    /** @var resource|null */
    private $handle;
    private readonly \Closure $decode;
    private int $replaced = 0;
    private int $held = 0;
    private string $buffer = '';
    private int $length = 0;
    private int $pos = 0;
    private bool $eof = false;
    /** Input offset of the next byte to read from the file. */
    private int $source;
    /** @var list<array{0: int, 1: int}> [buffer offset, input offset] where each decoded piece starts */
    private array $segments = [];
    private int $discard;
    private bool $skipLf = false;
    private readonly string $stops;
    /** @var array{0: int, 1: int} */
    private array $checkpoint = [0, 0];
    private int $fieldCount = 0;
    private bool $extra = false;
    private int $records = 0;

    /**
     * @param array{0: int, 1: int}|null $from a checkpoint() of an earlier reader of the same file and dialect
     * @param bool $keepInvalidUtf8 for UTF-8 only: pass ill-formed bytes through unchanged (the caller replaces and counts them)
     */
    public function __construct(
        string $path,
        private readonly CsvDialect $dialect,
        private readonly int $maxFields = 201,
        private readonly int $maxRecordBytes = self::MAX_RECORD_BYTES,
        ?array $from = null,
        bool $keepInvalidUtf8 = false,
    ) {
        if ($maxFields < 1 || $maxRecordBytes < 1) {
            throw new \InvalidArgumentException('Reader limits are positive.');
        }
        $this->stops = $dialect->delimiter . "\r\n";
        $handle = self::open($path);
        $this->handle = $handle;
        if ($from === null) {
            $bom = Charset::bom($dialect->charset);
            $head = $bom === '' ? '' : (string) fread($handle, strlen($bom));
            $start = $bom !== '' && $head === $bom ? strlen($bom) : 0;
            $skip = 0;
        } else {
            [$start, $skip] = $from;
            if (!is_int($start) || !is_int($skip) || $start < 0 || $skip < 0 || $skip > self::MAX_SKIP) {
                throw new \InvalidArgumentException('A checkpoint is an input offset and a bounded skip.');
            }
        }
        if (fseek($handle, $start) !== 0) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The file could not be read.');
        }
        $this->source = $start;
        $this->discard = $skip;
        if ($keepInvalidUtf8 && $dialect->charset === Charset::UTF8) {
            $this->decode = static fn (string $bytes, bool $final): string => $bytes;
        } else {
            $this->decode = Charset::decoder($dialect->charset, $this->replaced, $this->held);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    /**
     * The next record's fields (at most $maxFields of them), or null at the
     * end of the file.
     *
     * @return list<string>|null
     */
    public function next(): ?array
    {
        $this->compact();
        if ($this->discard > 0) {
            while ($this->length < $this->discard && $this->fill()) {
            }
            $this->pos = min($this->discard, $this->length);
            $this->discard = 0;
        }
        if ($this->skipLf) {
            $this->skipLf = false;
            if ($this->ensure() && $this->buffer[$this->pos] === "\n") {
                $this->pos++;
            }
        }
        if (!$this->ensure()) {
            return null;
        }
        $start = $this->pos;
        $this->checkpoint = $this->checkpointAt($start);
        $this->extra = false;
        $fields = [];
        $count = 0;
        // The field being read: where its text starts, whether it is quoted, and where its closing quote is.
        $fieldStart = $start;
        $quoted = false;
        $closeQuote = -1;
        $state = self::START;
        $delimiter = $this->dialect->delimiter;
        while (true) {
            if ($this->pos - $start > $this->maxRecordBytes) {
                throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'A record is longer than the limit.');
            }
            if ($this->pos >= $this->length) {
                if ($this->fill()) {
                    continue;
                }
                // The end of the file ends the record (and a quote left open).
                $fields = $this->finish($fields, $count, $fieldStart, $quoted, $closeQuote, $this->length);
                $count++;
                break;
            }
            if ($state === self::START) {
                $fieldStart = $this->pos;
                $quoted = false;
                $closeQuote = -1;
                if ($this->buffer[$this->pos] === '"') {
                    $quoted = true;
                    $this->pos++;
                    $fieldStart = $this->pos;
                    $state = self::QUOTED;
                } else {
                    $state = self::PLAIN;
                }
            } elseif ($state === self::PLAIN) {
                $this->pos += strcspn($this->buffer, $this->stops, $this->pos);
                if ($this->pos >= $this->length) {
                    continue;
                }
                $char = $this->buffer[$this->pos];
                $fields = $this->finish($fields, $count, $fieldStart, $quoted, $closeQuote, $this->pos);
                $count++;
                $this->pos++;
                if ($char === $delimiter) {
                    $state = self::START;
                    continue;
                }
                $this->skipLf = $char === "\r";
                break;
            } elseif ($state === self::QUOTED) {
                $quote = strpos($this->buffer, '"', $this->pos);
                if ($quote === false) {
                    $this->pos = $this->length;
                    continue;
                }
                $closeQuote = $quote;
                $this->pos = $quote + 1;
                $state = self::QUOTE_SEEN;
            } elseif ($this->buffer[$this->pos] === '"') {
                // QUOTE_SEEN: a doubled quote is one quote, and the field goes on.
                $closeQuote = -1;
                $this->pos++;
                $state = self::QUOTED;
            } else {
                // The quote was the closing one; any text after it up to the delimiter is kept as is.
                $state = self::PLAIN;
            }
        }
        $this->fieldCount = $count;
        $this->records++;

        return $fields;
    }

    /** Fields in the record just returned, including those past $maxFields. */
    public function fieldCount(): int
    {
        return $this->fieldCount;
    }

    /** Whether the record just returned had a non-empty field past $maxFields. */
    public function hadExtra(): bool
    {
        return $this->extra;
    }

    /** Records returned so far. */
    public function records(): int
    {
        return $this->records;
    }

    /** Characters replaced by U+FFFD by the decoder so far. */
    public function replaced(): int
    {
        return $this->replaced;
    }

    /**
     * Where the record just returned starts: [input offset, decoded bytes
     * to skip]; pass it as $from to resume there.
     *
     * @return array{0: int, 1: int}
     */
    public function checkpoint(): array
    {
        return $this->checkpoint;
    }

    /**
     * Adds the field that ends at buffer offset $end to $fields (when it is
     * one of the first $maxFields), or notes that a field past them is not
     * empty.
     *
     * @param list<string> $fields
     * @return list<string>
     */
    private function finish(array $fields, int $count, int $fieldStart, bool $quoted, int $closeQuote, int $end): array
    {
        if ($count >= $this->maxFields) {
            $this->extra = $this->extra || $quoted || $end > $fieldStart;

            return $fields;
        }
        if (!$quoted) {
            $field = substr($this->buffer, $fieldStart, $end - $fieldStart);
        } else {
            // Inside the quotes every quote is doubled; a quote left open runs to $end.
            $inner = $closeQuote >= 0 ? $closeQuote : $end;
            $field = (string) preg_replace('/\r\n?/', "\n", (string) preg_replace('/""/', '"', substr($this->buffer, $fieldStart, $inner - $fieldStart)));
            if ($closeQuote >= 0 && $end > $closeQuote + 1) {
                $field = $field . substr($this->buffer, $closeQuote + 1, $end - $closeQuote - 1);
            }
        }
        if (str_contains($field, "\0")) {
            $field = (string) preg_replace('/\x00/', Charset::REPLACEMENT, $field);
        }
        $fields[] = $field;

        return $fields;
    }

    /** Whether at least one byte is buffered after the position (reading more when needed). */
    private function ensure(): bool
    {
        while ($this->pos >= $this->length) {
            if (!$this->fill()) {
                return false;
            }
        }

        return true;
    }

    /** Reads and decodes one more piece; false at the end of the file. */
    private function fill(): bool
    {
        if ($this->eof || !is_resource($this->handle)) {
            return false;
        }
        $bytes = fread($this->handle, self::CHUNK_BYTES);
        $final = $bytes === false || $bytes === '';
        if ($final) {
            $this->eof = true;
            $bytes = '';
        }
        $startsAt = $this->source - $this->held;
        $this->source += strlen($bytes);
        $decode = $this->decode;
        $text = $decode($bytes, $final);
        if ($text !== '') {
            $this->segments[] = [$this->length, $startsAt];
            $this->buffer = $this->buffer . $text;
            $this->length += strlen($text);
        }

        return !$final || $text !== '';
    }

    /** Drops the consumed part of the buffer (between records only). */
    private function compact(): void
    {
        if ($this->pos < self::CHUNK_BYTES) {
            return;
        }
        $this->buffer = substr($this->buffer, $this->pos);
        $this->length -= $this->pos;
        foreach ($this->segments as $i => $segment) {
            $this->segments[$i][0] = $segment[0] - $this->pos;
        }
        while (count($this->segments) > 1 && $this->segments[1][0] <= 0) {
            array_shift($this->segments);
        }
        $this->pos = 0;
    }

    /** @return array{0: int, 1: int} */
    private function checkpointAt(int $offset): array
    {
        for ($i = count($this->segments) - 1; $i >= 0; $i--) {
            if ($this->segments[$i][0] <= $offset) {
                return [$this->segments[$i][1], $offset - $this->segments[$i][0]];
            }
        }

        return [$this->source, 0];
    }

    /**
     * The headings of a CSV file read with $dialect (CsvFile::columns()).
     *
     * @return list<string>
     */
    public static function columnsOf(string $path, CsvDialect $dialect, ?string $tempFolder = null): array
    {
        return (new CsvFile($path, $dialect, $tempFolder))->columns();
    }

    /**
     * Data rows of a CSV file from data row $from (CsvFile::rows()).
     *
     * @return \Generator<int, list<?string>>
     */
    public static function rowsOf(string $path, CsvDialect $dialect, ?string $tempFolder, int $from, int $limit): \Generator
    {
        return (new CsvFile($path, $dialect, $tempFolder))->rows($from, $limit);
    }

    /**
     * Runs a file-system call with PHP warnings turned into a
     * \RuntimeException($what) (instead of the `@` operator; the warning
     * text, which can hold paths, is kept only as the previous exception).
     *
     * @internal for the classes of this namespace
     */
    public static function quietly(\Closure $call, string $what): mixed
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });
        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }
        if ($warning !== null) {
            throw new \RuntimeException($what, 0, new \ErrorException($warning));
        }

        return $result;
    }

    /** @return resource */
    private static function open(string $path)
    {
        try {
            $handle = self::quietly(static fn () => fopen($path, 'rb'), 'The file could not be opened.');
        } catch (\RuntimeException $error) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The file could not be opened.', $error);
        }
        if (!is_resource($handle)) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The file could not be opened.');
        }

        return $handle;
    }
}
