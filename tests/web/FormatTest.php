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
 * T-WEB-20 and T-WEB-25: display formatting (M1 spec part 2 section 5.3;
 * M2 spec part 2 section 7), with the real strings and a fixed clock. No test depends on the machine's clock or
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

    // M2 (T-WEB-25)

    public function testDate(): void
    {
        self::assertSame('14 Mar 2025', self::format()->date('2025-03-14'));
        self::assertSame('1 Jan 2000', self::format()->date('2000-01-01'));
        self::assertSame('29 Feb 2024', self::format()->date('2024-02-29'));
    }

    /** @return iterable<string, array{string}> */
    public static function badDates(): iterable
    {
        yield 'not a day' => ['2025-02-30'];
        yield 'short' => ['2025-3-14'];
        yield 'with time' => ['2025-03-14T00:00:00'];
        yield 'trailing newline' => ["2025-03-14\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('badDates')]
    public function testDateRefusesOtherForms(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::format()->date($value);
    }

    public function testTime(): void
    {
        self::assertSame('14:30', self::format()->time('14:30:15', false));
        self::assertSame('14:30:15', self::format()->time('14:30:15', true));
        self::assertSame('09:05', self::format()->time('09:05:00', false));
        self::assertSame('09:05:00', self::format()->time('09:05', true));
        self::assertSame('00:00', self::format()->time('00:00:00', false));
    }

    public function testTimeRefusesOtherForms(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::format()->time('24:00:00', false);
    }

    public function testDateTime(): void
    {
        self::assertSame('14 Mar 2025, 14:30', self::format()->dateTime('2025-03-14T14:30:15', false));
        self::assertSame('14 Mar 2025, 14:30:15', self::format()->dateTime('2025-03-14T14:30:15', true));
    }

    public function testDateTimeRefusesOtherForms(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::format()->dateTime('2025-03-14 14:30:15', false);
    }

    /** @return iterable<string, array{int, bool, string}> */
    public static function durations(): iterable
    {
        yield 'zero' => [0, false, '0:00'];
        yield 'zero with seconds' => [0, true, '0:00:00'];
        yield 'an hour and a half' => [5400, false, '1:30'];
        yield 'with seconds' => [5415, true, '1:30:15'];
        yield 'seconds dropped' => [5415, false, '1:30'];
        yield 'past 24 hours' => [90000, false, '25:00'];
        yield 'past 100 hours' => [360061, true, '100:01:01'];
    }

    #[DataProvider('durations')]
    public function testDuration(int $seconds, bool $withSeconds, string $expected): void
    {
        self::assertSame($expected, self::format()->duration($seconds, $withSeconds));
    }

    public function testNegativeDurationIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::format()->duration(-1, false);
    }

    public function testDecimal(): void
    {
        self::assertSame('1,234.5', self::format()->decimal(1234.5, 1));
        self::assertSame('1,235', self::format()->decimal(1234.5, 0));
        self::assertSame('1,234,567', self::format()->decimal(1234567, 0));
        self::assertSame('-12.30', self::format()->decimal(-12.3, 2));
        self::assertSame('0.0', self::format()->decimal(-0.04, 1), 'No negative zero.');
    }

    /** @return iterable<string, array{int, string, int, string}> */
    public static function amounts(): iterable
    {
        yield 'dollars' => [32900, 'USD', 2, '$329.00'];
        yield 'euros' => [123456, 'EUR', 2, "\u{20AC}1,234.56"];
        yield 'pounds' => [5, 'GBP', 2, "\u{00A3}0.05"];
        yield 'yen, no minor digits' => [1234567, 'JPY', 0, "\u{00A5}1,234,567"];
        yield 'other code' => [1200, 'CHF', 2, 'CHF 12.00'];
        yield 'negative dollars' => [-500, 'USD', 2, '-$5.00'];
        yield 'negative other code' => [-123456789, 'SEK', 2, '-SEK 1,234,567.89'];
        yield 'three digits' => [1234, 'KWD', 3, 'KWD 1.234'];
        yield 'zero' => [0, 'USD', 2, '$0.00'];
        yield 'largest' => [PHP_INT_MAX, 'USD', 2, '$92,233,720,368,547,758.07'];
        yield 'smallest' => [PHP_INT_MIN, 'USD', 2, '-$92,233,720,368,547,758.08'];
    }

    #[DataProvider('amounts')]
    public function testMoney(int $minor, string $code, int $digits, string $expected): void
    {
        self::assertSame($expected, self::format()->money($minor, $code, $digits));
    }

    public function testMoneyRefusesABadCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::format()->money(1, 'usd', 2);
    }

    /** @return iterable<string, array{int, string}> */
    public static function fileSizes(): iterable
    {
        yield 'zero' => [0, '0 bytes'];
        yield 'one byte' => [1, '1 byte'];
        yield 'below a kilobyte' => [1023, '1,023 bytes'];
        yield 'one kilobyte' => [1024, '1 KB'];
        yield 'rounded up' => [1025, '2 KB'];
        yield 'below a megabyte' => [1048575, '1,024 KB'];
        yield 'one megabyte' => [1048576, '1.0 MB'];
        yield 'one and a half megabytes' => [1572864, '1.5 MB'];
        yield 'large' => [5368709120, '5,120.0 MB'];
    }

    #[DataProvider('fileSizes')]
    public function testFileSize(int $bytes, string $expected): void
    {
        self::assertSame($expected, self::format()->fileSize($bytes));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function savedAts(): iterable
    {
        $nz = 'Pacific/Auckland';
        // Now: 2026-11-13 00:30 in Auckland (11:30 UTC on the 12th), just after local midnight.
        $now = '2026-11-12T11:30:00Z';
        yield 'just after local midnight' => [$now, $nz, '2026-11-12T11:05:00Z', 'today at 00:05'];
        yield 'just before local midnight' => [$now, $nz, '2026-11-12T10:59:00Z', 'yesterday at 23:59'];
        yield 'two local days ago' => [$now, $nz, '2026-11-10T22:15:00Z', '11 Nov 2026 at 11:15'];
        // The same instants in UTC.
        yield 'UTC: today' => [$now, 'UTC', '2026-11-12T10:59:00Z', 'today at 10:59'];
        yield 'UTC: yesterday' => [$now, 'UTC', '2026-11-11T23:59:59Z', 'yesterday at 23:59'];
    }

    #[DataProvider('savedAts')]
    public function testSavedAt(string $now, string $zone, string $timestamp, string $expected): void
    {
        self::assertSame($expected, self::format($now, $zone)->savedAt($timestamp));
    }

    public function testSavedAtRefusesOtherForms(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::format()->savedAt('2026-11-12 10:00:00');
    }
}
