<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * The shared strings of a workbook, held compactly: one string of all the
 * texts and one string of 32-bit offsets, so memory is the text plus four
 * bytes a string (an array of PHP strings would cost several times more).
 *
 * Defences against a shared strings bomb: at most MAX_SHARED_STRINGS
 * strings and MAX_SHARED_BYTES bytes of text, else REASON_TOO_LARGE; each
 * string kept to MAX_STRING_BYTES. The `count` and `uniqueCount` attributes
 * are not trusted (nothing is allocated from them). Rich text runs are
 * joined; phonetic runs are left out.
 *
 * @internal
 */
final class SharedStrings
{
    private function __construct(private readonly string $text, private readonly string $offsets, private readonly int $count)
    {
    }

    public static function none(): self
    {
        return new self('', pack('V', 0), 0);
    }

    /** Reads part $part of the checked workbook at $zipPath; call inside Xml::run(). */
    public static function read(string $zipPath, string $part): self
    {
        $reader = Xml::open($zipPath, $part);
        $text = '';
        $offsets = '';
        $count = 0;
        try {
            $more = Xml::read($reader);
            while ($more) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                    if ($count >= Limits::MAX_SHARED_STRINGS) {
                        throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'The workbook has more shared strings than the limit.');
                    }
                    $offsets .= pack('V', strlen($text));
                    $text .= Xml::text($reader);
                    $count++;
                    if (strlen($text) > Limits::MAX_SHARED_BYTES) {
                        throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'The shared strings are larger than the limit.');
                    }
                    $more = Xml::next($reader);
                    continue;
                }
                $more = Xml::read($reader);
            }
        } finally {
            $reader->close();
        }

        return new self($text, $offsets . pack('V', strlen($text)), $count);
    }

    /** A table from cached parts (JobCache); null when the parts do not fit together. */
    public static function fromParts(string $text, string $offsets, int $count): ?self
    {
        if ($count < 0 || strlen($offsets) !== 4 * ($count + 1)) {
            return null;
        }

        return new self($text, $offsets, $count);
    }

    /** @return array{0: string, 1: string, 2: int} the text, the offsets, the count */
    public function parts(): array
    {
        return [$this->text, $this->offsets, $this->count];
    }

    public function count(): int
    {
        return $this->count;
    }

    /** String $index as stored (escapes not yet resolved), or null when there is no such string. */
    public function get(int $index): ?string
    {
        if ($index < 0 || $index >= $this->count) {
            return null;
        }
        /** @var array{1: int, 2: int} $bounds */
        $bounds = unpack('V2', $this->offsets, 4 * $index);
        if ($bounds === false || $bounds[1] > $bounds[2] || $bounds[2] > strlen($this->text)) {
            return null;
        }

        return substr($this->text, $bounds[1], $bounds[2] - $bounds[1]);
    }
}
