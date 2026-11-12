<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\SystemClock;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Tests\Support\FixedClock;

/** T-STO-5: Timestamp round trip and strictness; clocks. */
#[CoversClass(Timestamp::class)]
#[CoversClass(SystemClock::class)]
final class TimestampTest extends TestCase
{
    public function testRoundTrip(): void
    {
        foreach (['2026-11-12T03:15:00Z', '2000-02-29T23:59:59Z', '1999-01-01T00:00:00Z'] as $text) {
            $time = Timestamp::parse($text);
            self::assertSame('UTC', $time->getTimezone()->getName());
            self::assertSame($text, Timestamp::format($time));
        }
    }

    public function testFormatConvertsToUtcAndDropsFractions(): void
    {
        $local = new DateTimeImmutable('2026-03-14 14:30:05.987654', new DateTimeZone('America/New_York'));
        self::assertSame('2026-03-14T18:30:05Z', Timestamp::format($local));
    }

    /** @return iterable<string, array{string}> */
    public static function otherForms(): iterable
    {
        yield 'no zone' => ['2026-11-12T03:15:00'];
        yield 'offset' => ['2026-11-12T03:15:00+00:00'];
        yield 'lower-case z' => ['2026-11-12T03:15:00z'];
        yield 'space separator' => ['2026-11-12 03:15:00Z'];
        yield 'fraction' => ['2026-11-12T03:15:00.5Z'];
        yield 'no seconds' => ['2026-11-12T03:15Z'];
        yield 'date only' => ['2026-11-12'];
        yield 'trailing newline' => ["2026-11-12T03:15:00Z\n"];
        yield 'leading space' => [' 2026-11-12T03:15:00Z'];
        yield 'impossible day' => ['2026-02-30T00:00:00Z'];
        yield 'impossible hour' => ['2026-11-12T24:00:00Z'];
        yield 'impossible minute' => ['2026-11-12T03:60:00Z'];
        yield 'month 13' => ['2026-13-01T00:00:00Z'];
        yield 'empty' => [''];
    }

    #[DataProvider('otherForms')]
    public function testParseRejectsOtherForms(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        Timestamp::parse($text);
    }

    public function testSystemClockIsUtc(): void
    {
        $now = (new SystemClock())->now();
        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    public function testFixedClock(): void
    {
        $clock = new FixedClock('2026-01-15T09:30:00Z');
        self::assertSame('2026-01-15T09:30:00Z', Timestamp::format($clock->now()));
        $clock->advance(90);
        self::assertSame('2026-01-15T09:31:30Z', Timestamp::format($clock->now()));
        $clock->set(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('Europe/Paris')));
        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
        self::assertSame('2026-06-01T10:00:00Z', Timestamp::format($clock->now()));
    }
}
