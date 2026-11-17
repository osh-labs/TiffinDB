<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Web\Format;
use TiffinDB\Web\Strings;

/**
 * T-WEB-20: display formatting (M1 spec part 2 section 5.3), with the real
 * strings and a fixed clock. No test depends on the machine's clock or
 * time zone.
 */
#[CoversClass(Format::class)]
final class FormatTest extends TestCase
{
    private static function format(string $now = '2026-11-12T10:00:00Z', string $zone = 'UTC'): Format
    {
        return new Format(
            new Strings(RepoFiles::path('strings/en.php')),
            new DateTimeZone($zone),
            static fn (): DateTimeImmutable => new DateTimeImmutable($now),
        );
    }

    // relativeDate

    /** @return iterable<string, array{string, string, string, string}> */
    public static function relativeDates(): iterable
    {
        // Pacific/Auckland is UTC+13 in November (daylight saving time).
        $nz = 'Pacific/Auckland';
        // Now: 2026-11-12 23:30 in Auckland (10:30 UTC).
        $late = '2026-11-12T10:30:00Z';
        yield 'same local day, earlier' => [$late, $nz, '2026-11-11T11:00:00Z', 'Today'];
        yield 'same local day, just after midnight' => [$late, $nz, '2026-11-11T11:00:01Z', 'Today'];
        yield 'one second before local midnight' => [$late, $nz, '2026-11-11T10:59:59Z', 'Yesterday'];
        yield 'local yesterday, early' => [$late, $nz, '2026-11-10T11:00:00Z', 'Yesterday'];
        yield 'two local days ago' => [$late, $nz, '2026-11-10T10:59:59Z', '10 Nov 2026'];
        // The same instants seen in UTC give different words.
        yield 'UTC: same instant is yesterday in UTC terms' => [$late, 'UTC', '2026-11-11T11:00:00Z', 'Yesterday'];
        yield 'UTC: today' => [$late, 'UTC', '2026-11-12T00:00:00Z', 'Today'];
        // Now: 2026-11-13 00:30 in Auckland (11:30 UTC on the 12th): just after midnight.
        $early = '2026-11-12T11:30:00Z';
        yield 'after local midnight: 31 minutes ago is yesterday' => [$early, $nz, '2026-11-12T10:59:00Z', 'Yesterday'];
        yield 'after local midnight: 30 minutes ago is today' => [$early, $nz, '2026-11-12T11:00:00Z', 'Today'];
        // A zone behind UTC.
        yield 'New York: late UTC evening is the same local day' => ['2026-11-13T03:00:00Z', 'America/New_York', '2026-11-12T14:00:00Z', 'Today'];
        yield 'New York: earlier local day' => ['2026-11-13T03:00:00Z', 'America/New_York', '2026-11-12T04:59:59Z', 'Yesterday'];
        // Across a clock change (Europe/London leaves summer time on 25 Oct 2026).
        yield 'London: across the clock change' => ['2026-10-26T00:30:00Z', 'Europe/London', '2026-10-25T00:30:00Z', 'Yesterday'];
        yield 'London: before the clock change' => ['2026-10-26T00:30:00Z', 'Europe/London', '2026-10-24T22:59:59Z', '24 Oct 2026'];
        // Day format: no leading zero; every month.
        yield 'day without leading zero' => [$late, 'UTC', '2026-03-03T12:00:00Z', '3 Mar 2026'];
        yield 'earlier year' => [$late, 'UTC', '2025-12-31T23:59:59Z', '31 Dec 2025'];
        yield 'future date' => [$late, 'UTC', '2026-11-20T12:00:00Z', '20 Nov 2026'];
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        foreach ($months as $i => $month) {
            $m = sprintf('%02d', $i + 1);
            yield "month {$m}" => [$late, 'UTC', "2024-{$m}-15T12:00:00Z", "15 {$month} 2024"];
        }
    }

    #[DataProvider('relativeDates')]
    public function testRelativeDate(string $now, string $zone, string $timestamp, string $expected): void
    {
        self::assertSame($expected, self::format($now, $zone)->relativeDate($timestamp));
    }

    /** @return iterable<string, array{string}> */
    public static function badTimestamps(): iterable
    {
        yield 'empty' => [''];
        yield 'no zone' => ['2026-11-12T10:00:00'];
        yield 'offset' => ['2026-11-12T10:00:00+01:00'];
        yield 'space' => ['2026-11-12 10:00:00Z'];
        yield 'fraction' => ['2026-11-12T10:00:00.5Z'];
        yield 'impossible date' => ['2026-02-30T10:00:00Z'];
        yield 'impossible time' => ['2026-11-12T25:00:00Z'];
        yield 'words' => ['yesterday'];
        yield 'trailing newline' => ["2026-11-12T10:00:00Z\n"];
    }

    #[DataProvider('badTimestamps')]
    public function testRelativeDateRefusesOtherForms(string $timestamp): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::format()->relativeDate($timestamp);
    }

    public function testAClockThatReturnsSomethingElseIsAnError(): void
    {
        $format = new Format(new Strings(RepoFiles::path('strings/en.php')), new DateTimeZone('UTC'), static fn (): string => 'now');

        $this->expectException(LogicException::class);
        $format->relativeDate('2026-11-12T10:00:00Z');
    }

    // wait

    /** @return iterable<string, array{int, string}> */
    public static function waits(): iterable
    {
        yield '1' => [1, '1 second'];
        yield '2' => [2, '2 seconds'];
        yield '30' => [30, '30 seconds'];
        yield '59' => [59, '59 seconds'];
        yield '60' => [60, '1 minute'];
        yield '61' => [61, '2 minutes'];
        yield '119' => [119, '2 minutes'];
        yield '120' => [120, '2 minutes'];
        yield '121' => [121, '3 minutes'];
        yield '900' => [900, '15 minutes'];
        yield '0' => [0, '1 second'];
        yield 'negative' => [-5, '1 second'];
    }

    #[DataProvider('waits')]
    public function testWait(int $seconds, string $expected): void
    {
        self::assertSame($expected, self::format()->wait($seconds));
    }

    // count and number

    /** @return iterable<string, array{int, string}> */
    public static function counts(): iterable
    {
        yield '0' => [0, 'There are 0 problems'];
        yield '1' => [1, 'There is a problem'];
        yield '2' => [2, 'There are 2 problems'];
        yield '1234' => [1234, 'There are 1,234 problems'];
        yield '1234567' => [1234567, 'There are 1,234,567 problems'];
    }

    #[DataProvider('counts')]
    public function testCount(int $n, string $expected): void
    {
        self::assertSame($expected, self::format()->count('count.problems', $n));
    }

    public function testCountOfAnUnknownKeyBaseThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        self::format()->count('count.no_such_thing', 2);
    }

    public function testNumber(): void
    {
        $format = self::format();

        self::assertSame('0', $format->number(0));
        self::assertSame('69', $format->number(69));
        self::assertSame('1,234', $format->number(1234));
        self::assertSame('-1,234', $format->number(-1234));
    }

    // problems

    public function testProblemTextWithParameters(): void
    {
        $format = self::format();

        self::assertSame('Use at most 100 characters.', $format->problemText('problem.name.too_long', ['max' => 100]));
        self::assertSame('The choice "<b>" is listed twice.', $format->problemText('problem.options.choices_duplicate', ['label' => '<b>']));
        self::assertSame('Enter a name.', $format->problemText('problem.name.empty', ['ignored' => 'x']));
    }

    /** @return iterable<string, array{int, string}> */
    public static function throttles(): iterable
    {
        yield '30 seconds' => [30, 'Too many tries. Wait 30 seconds, then try again.'];
        yield '1 second' => [1, 'Too many tries. Wait 1 second, then try again.'];
        yield '60 seconds' => [60, 'Too many tries. Wait 1 minute, then try again.'];
        yield '900 seconds' => [900, 'Too many tries. Wait 15 minutes, then try again.'];
    }

    #[DataProvider('throttles')]
    public function testThrottledProblemBuildsTheWait(int $seconds, string $expected): void
    {
        self::assertSame($expected, self::format()->problemText('problem.throttled', ['seconds' => $seconds]));
    }

    public function testThrottledProblemNeedsSeconds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::format()->problemText('problem.throttled', []);
    }

    public function testProblemTextRefusesOtherKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::format()->problemText('shell.sign_out', []);
    }

    public function testProblemTextNeedsItsParameters(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        self::format()->problemText('problem.name.too_long', []);
    }

    public function testProblemObject(): void
    {
        if (!class_exists(Problem::class)) {
            self::markTestSkipped('TiffinDB\Core\Error\Problem arrives with M1-WP1; problemText() is tested meanwhile.');
        }

        $format = self::format();
        self::assertSame('Use at most 40 characters.', $format->problem(new Problem('name', 'problem.name.too_long', ['max' => 40])));
        self::assertSame('Too many tries. Wait 2 minutes, then try again.', $format->problem(new Problem(null, 'problem.throttled', ['seconds' => 61])));
    }
}
