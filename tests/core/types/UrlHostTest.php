<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\Type\UrlType;

/**
 * T-CF-2: the hexadecimal-host rule of the web address type. A label that
 * only starts with `0x` is an ordinary name; a hexadecimal number as a
 * label, and a host whose last label is a number, are read by browsers as
 * an IPv4 address and refused. Synthetic addresses only.
 */
#[CoversClass(UrlType::class)]
final class UrlHostTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function acceptedHosts(): array
    {
        return [
            '0x name, first label' => ['https://0xford.example/'],
            '0x name, upper case' => ['https://0XFORD.example/'],
            '0x name, inner label' => ['https://www.0xide.example/a'],
            '0x name, last label' => ['https://example.0xg/'],
            'plain IPv4' => ['https://192.0.2.1/'],
            'digits not last' => ['https://123.example/'],
        ];
    }

    #[DataProvider('acceptedHosts')]
    public function testOrdinaryNamesAreAccepted(string $url): void
    {
        $type = new UrlType();
        self::assertSame($url, $type->parse($url, [])->value(), 'strict');
        self::assertSame($url, $type->parse($url, [], true)->value(), 'lenient');
        self::assertSame($url, $type->fromStorage($url, []), 'stored');
    }

    /** @return array<string, array{string}> */
    public static function refusedHosts(): array
    {
        return [
            'hex integer' => ['https://0x7f000001/'],
            'hex last label' => ['https://a.0x7f/'],
            'hex last label, upper case' => ['https://a.0X7F/'],
            'hex inner label' => ['https://a.0x1.example/'],
            'bare 0x last label' => ['https://a.0x/'],
            'hex then decimal' => ['https://0x7f.1/'],
            'name then number' => ['https://example.1/'],
            'trailing dot after a number' => ['https://example.1./'],
            'IPv4 with a trailing dot' => ['https://192.0.2.1./'],
            'decimal integer' => ['https://2130706433/'],
            'short form' => ['https://127.1/'],
            'octal label' => ['https://0177.0.0.1/'],
            'five labels' => ['https://1.2.3.4.5/'],
        ];
    }

    #[DataProvider('refusedHosts')]
    public function testHostsReadAsIpv4AreRefused(string $url): void
    {
        $type = new UrlType();
        foreach ([false, true] as $lenient) {
            $checked = $type->parse($url, [], $lenient);
            self::assertFalse($checked->isOk(), $lenient ? 'lenient' : 'strict');
            self::assertSame('problem.url.invalid', $checked->problemKey());
        }
        self::assertNull($type->fromStorage($url, []));
    }
}
