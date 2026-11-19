<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Csv;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Csv\Charset;

/**
 * T-CSV-1: character set detection and streaming decoding (M3 spec section
 * 8.1): byte order marks, UTF-16 surrogate pairs and unpaired surrogates,
 * every Windows-1252 byte, ill-formed UTF-8 replaced and counted, and
 * sequences split across piece boundaries.
 */
#[CoversClass(Charset::class)]
final class CharsetTest extends TestCase
{
    private const R = "\u{FFFD}";

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function heads(): iterable
    {
        yield 'utf-8 bom' => ["\xEF\xBB\xBFName,Note\r\n", Charset::UTF8_BOM];
        yield 'utf-16le bom' => ["\xFF\xFEN\x00a\x00", Charset::UTF16LE];
        yield 'utf-16be bom' => ["\xFE\xFF\x00N\x00a", Charset::UTF16BE];
        yield 'plain ascii' => ["Name,Note\r\nLamp,ok\r\n", Charset::UTF8];
        yield 'utf-8 letters' => ["Name\r\nCaf\u{E9},\u{20AC}5\r\n", Charset::UTF8];
        yield 'utf-8 cut at the end' => ["Name\r\nCaf\u{E9}\xE2\x82", Charset::UTF8];
        yield 'latin 1 bytes' => ["Name\r\nCaf\xE9 cr\xE8me\r\n", Charset::WINDOWS_1252];
        yield 'windows quotes' => ["\x93quoted\x94\r\n", Charset::WINDOWS_1252];
        yield 'empty' => ['', Charset::UTF8];
        yield 'only a bom' => ["\xEF\xBB\xBF", Charset::UTF8_BOM];
    }

    #[DataProvider('heads')]
    public function testDetect(string $head, string $expected): void
    {
        self::assertSame($expected, Charset::detect($head));
    }

    public function testDetectLooksAtTheSampleOnly(): void
    {
        $head = str_repeat('a', Charset::SAMPLE_BYTES) . "\xE9";
        self::assertSame(Charset::UTF8, Charset::detect($head), 'Bytes past the sample are not looked at.');
        self::assertSame('', Charset::bom(Charset::WINDOWS_1252));
        self::assertSame("\xEF\xBB\xBF", Charset::bom(Charset::UTF8));
    }

    public function testUtf16LittleAndBigEndianWithSurrogates(): void
    {
        $text = "Lamp \u{1F600} caf\u{E9} \u{20AC}\n";
        foreach ([Charset::UTF16LE => 'v*', Charset::UTF16BE => 'n*'] as $charset => $format) {
            $bytes = pack($format, ...self::units($text));
            self::assertSame($text, self::decodeAll($charset, $bytes, 1), $charset . ' byte by byte');
            self::assertSame($text, self::decodeAll($charset, $bytes, 3), $charset . ' in threes');
            self::assertSame($text, self::decodeAll($charset, $bytes, strlen($bytes)), $charset . ' whole');
        }
    }

    public function testUtf16UnpairedSurrogatesAndOddLength(): void
    {
        $replaced = 0;
        $decode = Charset::decoder(Charset::UTF16LE, $replaced);
        // A lone low surrogate, a high surrogate followed by a letter, then a high surrogate at the very end.
        $out = $decode(pack('v*', 0x41, 0xDC00, 0x42, 0xD800, 0x43, 0xD801), false) . $decode('', true);
        self::assertSame('A' . self::R . 'B' . self::R . 'C' . self::R, $out);
        self::assertSame(3, $replaced);

        $replaced = 0;
        $decode = Charset::decoder(Charset::UTF16BE, $replaced);
        $out = $decode("\x00A\x00", false) . $decode('', true);
        self::assertSame('A' . self::R, $out, 'A lone byte at the end is half a code unit.');
        self::assertSame(1, $replaced);
    }

    public function testHeldBytesAreReported(): void
    {
        $held = 0;
        $decode = Charset::decoder(Charset::UTF16LE, $replaced, $held);
        self::assertSame('', $decode("\x3D", false));
        self::assertSame(1, $held);
        self::assertSame('', $decode("\xD8", false), 'A high surrogate waits for its pair.');
        self::assertSame(2, $held);
        self::assertSame("\u{1F600}", $decode("\x00\xDE", false));
        self::assertSame(0, $held);

        $decode = Charset::decoder(Charset::UTF8, $replaced, $held);
        self::assertSame('a', $decode("a\xF0\x9F", false));
        self::assertSame(2, $held);
        self::assertSame("\u{1F600}", $decode("\x98\x80", false));
        self::assertSame(0, $held);
    }

    public function testEveryWindows1252Byte(): void
    {
        $undefined = [0x81, 0x8D, 0x8F, 0x90, 0x9D];
        $high = [
            0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6,
            0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
            0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
            0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
        ];
        $replaced = 0;
        $decode = Charset::decoder(Charset::WINDOWS_1252, $replaced);
        for ($byte = 0; $byte <= 0xFF; $byte++) {
            $expected = match (true) {
                in_array($byte, $undefined, true) => self::R,
                $byte >= 0x80 && $byte <= 0x9F => self::chr($high[$byte]),
                default => self::chr($byte),
            };
            self::assertSame($expected, $decode(chr($byte), false), sprintf('byte 0x%02X', $byte));
        }
        self::assertSame(5, $replaced);
        self::assertSame("Caf\u{E9} \u{201C}x\u{201D}", $decode("Caf\xE9 \x93x\x94", true));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int}> */
    public static function illFormed(): iterable
    {
        yield 'lone continuation' => ["a\x80b", 'a' . self::R . 'b', 1];
        yield 'invalid byte' => ["a\xFFb", 'a' . self::R . 'b', 1];
        yield 'truncated three-byte' => ["a\xE2\x82b", 'a' . self::R . 'b', 1];
        yield 'truncated four-byte' => ["\xF0\x9F\x98a", self::R . 'a', 1];
        yield 'overlong' => ["\xC0\xAF", self::R . self::R, 2];
        yield 'encoded surrogate' => ["\xED\xA0\x80", self::R . self::R . self::R, 3];
        yield 'past U+10FFFF' => ["\xF4\x90\x80\x80", self::R . self::R . self::R . self::R, 4];
        yield 'valid around invalid' => ["caf\u{E9}\xFE\u{20AC}", "caf\u{E9}" . self::R . "\u{20AC}", 1];
    }

    #[DataProvider('illFormed')]
    public function testIllFormedUtf8IsReplacedAndCounted(string $bytes, string $expected, int $count): void
    {
        foreach ([1, 2, strlen($bytes)] as $piece) {
            $replaced = 0;
            self::assertSame($expected, self::decodeAll(Charset::UTF8, $bytes, $piece, $replaced), 'pieces of ' . $piece);
            self::assertSame($count, $replaced, 'pieces of ' . $piece);
        }
        self::assertSame($expected, Charset::scrub($bytes, $n));
        self::assertSame($count, $n);
    }

    public function testUtf8SplitAcrossPiecesAndCutAtTheEnd(): void
    {
        $text = "Caf\u{E9} \u{20AC} \u{1F600} end";
        for ($piece = 1; $piece <= 5; $piece++) {
            $replaced = 0;
            self::assertSame($text, self::decodeAll(Charset::UTF8, $text, $piece, $replaced));
            self::assertSame(0, $replaced);
        }
        $replaced = 0;
        $decode = Charset::decoder(Charset::UTF8, $replaced);
        self::assertSame('a', $decode("a\xE2\x82", false));
        self::assertSame(self::R, $decode('', true), 'A sequence cut by the end of the input is replaced.');
        self::assertSame(1, $replaced);
    }

    public function testCut(): void
    {
        self::assertSame('ab', Charset::cut('ab', 5));
        self::assertSame("a\u{E9}", Charset::cut("a\u{E9}\u{20AC}", 4), 'A character is never split.');
        self::assertSame('a', Charset::cut("a\u{E9}", 2));
    }

    public function testUnknownCharset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Charset::decoder('ebcdic');
    }

    private static function decodeAll(string $charset, string $bytes, int $piece, ?int &$replaced = null): string
    {
        $decode = Charset::decoder($charset, $replaced);
        $out = '';
        foreach (str_split($bytes, max(1, $piece)) as $part) {
            $out .= $decode($part, false);
        }

        return $out . $decode('', true);
    }

    /** @return list<int> UTF-16 code units of UTF-8 text */
    private static function units(string $text): array
    {
        $units = [];
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $point = self::ord($char);
            if ($point >= 0x10000) {
                $point -= 0x10000;
                $units[] = 0xD800 + ($point >> 10);
                $units[] = 0xDC00 + ($point & 0x3FF);
            } else {
                $units[] = $point;
            }
        }

        return $units;
    }

    private static function ord(string $char): int
    {
        $bytes = array_values((array) unpack('C*', $char));
        $n = count($bytes);

        return match ($n) {
            1 => $bytes[0],
            2 => (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F),
            3 => (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F),
            default => (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F),
        };
    }

    private static function chr(int $point): string
    {
        return (string) json_decode(sprintf('"\\u%04x"', $point));
    }
}
