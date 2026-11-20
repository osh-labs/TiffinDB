<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

/**
 * Upload files built byte by byte in the test (M4 spec section 12): no
 * binary fixture is committed. Each image is a minimal valid file of its
 * format (signature and structure; crc32 and gzcompress for PNG chunks),
 * with pixel values drawn from SeededRandom under SEED and a label, so the
 * same call gives the same bytes on every run. Synthetic data only.
 */
final class FileFixture
{
    public const SEED = 20261120;

    private function __construct()
    {
    }

    /** $length deterministic bytes for the label. */
    public static function bytes(int $length, string $label = 'bytes'): string
    {
        return self::random($label)->bytes($length);
    }

    /** A truecolour PNG (IHDR, one IDAT, IEND) with seeded pixels. */
    public static function png(int $width = 1, int $height = 1, string $label = 'png'): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('A PNG needs at least one pixel.');
        }
        $random = self::random($label);
        $rows = '';
        for ($y = 0; $y < $height; $y++) {
            $rows .= "\x00" . $random->bytes(3 * $width);
        }
        $compressed = gzcompress($rows, 9);
        if ($compressed === false) {
            throw new \RuntimeException('gzcompress failed.');
        }

        return "\x89PNG\r\n\x1A\n"
            . self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . self::pngChunk('IDAT', $compressed)
            . self::pngChunk('IEND', '');
    }

    /** A 1 by 1 GIF89a with a two-colour global table (the first colour seeded) and one LZW-coded pixel. */
    public static function gif(string $label = 'gif'): string
    {
        $colours = self::random($label)->bytes(3) . "\xFF\xFF\xFF";

        return 'GIF89a'
            . pack('vvCCC', 1, 1, 0x80, 0, 0)     // screen 1x1, global table of 2 entries
            . $colours
            . "\x2C" . pack('vvvvC', 0, 0, 1, 1, 0) // image descriptor
            . "\x02\x02\x44\x01\x00"              // LZW minimum code size 2; codes clear, 0, end
            . "\x3B";
    }

    /**
     * A 1 by 1 baseline greyscale JPEG: SOI, DQT (all ones), SOF0, one DC
     * and one AC Huffman table of a single one-bit code, SOS, two bits of
     * scan data (DC difference 0, end of block), EOI. The grey level is
     * fixed by the format (difference 0), so the label changes a comment.
     */
    public static function jpeg(string $label = 'jpeg'): string
    {
        $comment = bin2hex(self::random($label)->bytes(4));

        return "\xFF\xD8"
            . self::jpegSegment("\xFE", 'synthetic ' . $comment)
            . self::jpegSegment("\xDB", "\x00" . str_repeat("\x01", 64))
            . self::jpegSegment("\xC0", "\x08" . pack('nn', 1, 1) . "\x01" . "\x01\x11\x00")
            . self::jpegSegment("\xC4", "\x00" . "\x01" . str_repeat("\x00", 15) . "\x00")
            . self::jpegSegment("\xC4", "\x10" . "\x01" . str_repeat("\x00", 15) . "\x00")
            . self::jpegSegment("\xDA", "\x01" . "\x01\x00" . "\x00\x3F\x00")
            . "\x3F"
            . "\xFF\xD9";
    }

    /**
     * A 1 by 1 lossless WebP (RIFF, WEBP, one VP8L chunk). The bit stream:
     * signature, size, no alpha, version 0, no transform, no colour cache,
     * no meta prefix codes, then five simple prefix codes of one symbol
     * each (green, red, blue, alpha, distance), so the one pixel takes no
     * bits. Green, red, and blue are seeded; alpha is 255.
     */
    public static function webp(string $label = 'webp'): string
    {
        [$red, $green, $blue] = array_values(unpack('C3', self::random($label)->bytes(3)) ?: [0, 0, 0]);
        $bits = new class () {
            private int $value = 0;
            private int $count = 0;
            private string $bytes = '';

            public function put(int $value, int $width): void
            {
                for ($i = 0; $i < $width; $i++) {
                    $this->value |= (($value >> $i) & 1) << $this->count;
                    $this->count++;
                    if ($this->count === 8) {
                        $this->bytes .= chr($this->value);
                        $this->value = 0;
                        $this->count = 0;
                    }
                }
            }

            public function bytes(): string
            {
                return $this->count === 0 ? $this->bytes : $this->bytes . chr($this->value);
            }
        };
        $bits->put(0x2F, 8);
        $bits->put(0, 14);   // width - 1
        $bits->put(0, 14);   // height - 1
        $bits->put(0, 1);    // alpha not used
        $bits->put(0, 3);    // version 0
        $bits->put(0, 1);    // no transform
        $bits->put(0, 1);    // no colour cache
        $bits->put(0, 1);    // no meta prefix codes
        foreach ([$green, $red, $blue, 255] as $symbol) {
            $bits->put(1, 1); // simple code
            $bits->put(0, 1); // one symbol
            $bits->put(1, 1); // eight-bit symbol
            $bits->put((int) $symbol, 8);
        }
        $bits->put(1, 1);    // distance: simple code
        $bits->put(0, 1);    // one symbol
        $bits->put(0, 1);    // one-bit symbol
        $bits->put(0, 1);    // symbol 0
        $data = $bits->bytes();
        $chunk = 'VP8L' . pack('V', strlen($data)) . $data . (strlen($data) % 2 === 1 ? "\x00" : '');

        return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
    }

    /** A one-page PDF with no content stream (download-only type). */
    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 72 72] >> endobj\n"
            . "trailer << /Root 1 0 R >>\n%%EOF\n";
    }

    private static function random(string $label): SeededRandom
    {
        return new SeededRandom('tiffindb-file-fixture-' . (string) self::SEED . '-' . $label);
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function jpegSegment(string $marker, string $payload): string
    {
        return "\xFF" . $marker . pack('n', strlen($payload) + 2) . $payload;
    }
}
