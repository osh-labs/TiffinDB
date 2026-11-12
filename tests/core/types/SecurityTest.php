<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Types;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Tests\Support\SeededRandom;

/**
 * T-TYP-7 and the other security properties of the types: web addresses
 * keep to http and https with a host, email validation stays fast on long
 * input, every type refuses invalid UTF-8 and control characters (P61),
 * stored values are checked again when read, and parsing does not depend
 * on the locale.
 */
#[CoversNamespace('TiffinDB\\Core\\Schema\\Type')]
final class SecurityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function refusedUrls(): iterable
    {
        $urls = [
            // T-TYP-7, as listed in the spec.
            'javascript:alert(1)',
            'JaVaScRiPt:x',
            'data:text/html,x',
            'vbscript:x',
            '//example.com',
            'http:/example.com',
            'https://exa mple.com',
            "https://exa\tmple.com",
            "https://example.com/\nx",
            'https://example.com/' . str_repeat('a', 2049 - 20),
            // Other schemes and scheme tricks.
            'javascript://example.com/%0Aalert(1)',
            'javascript:https://example.com',
            ' javascript:alert(1)',
            "\x01https://example.com",
            "java\0script:alert(1)",
            "jav\tascript:alert(1)",
            "\u{200B}https://example.com",
            "https\u{200B}://example.com",
            "https://example.com\u{202E}",
            'file:///etc/passwd',
            'ftp://example.com/',
            'mailto:user@example.com',
            'tel:5550100',
            'blob:https://example.com/x',
            'about:blank',
            'httpx://example.com',
            'https:example.com',
            'https:/example.com',
            'https:\\\\example.com',
            'https:/\\example.com',
            'https://example.com\\@evil.example',
            '\\\\example.com',
            '/\\example.com',
            '/relative/path',
            '#fragment',
            '?query',
            // User information before the host.
            'https://user@example.com/',
            'https://user:password@example.com/',
            'https://example.com@evil.example/',
            'https://:@example.com/',
            // Hosts.
            'https://',
            'https:///path',
            'https://:443/',
            'https://例え.jp/',
            'https://exämple.com/',
            'https://example.com/päth',
            'https://exa_mple.com/',
            'https://-example.com/',
            'https://example..com/',
            'https://example.com:99999/',
            'https://example.com:80:80/',
            // Encodings.
            "\xEF\xBC\xA8ttps://example.com",
            "https://example.com/\xC3",
            "https://example.com/\xED\xA0\x80",
            "https://example.com/\x7F",
        ];
        foreach ($urls as $i => $url) {
            yield '#' . $i . ' ' . json_encode($url, JSON_INVALID_UTF8_SUBSTITUTE) => [$url];
        }
    }

    #[DataProvider('refusedUrls')]
    public function testUrlRefusals(string $url): void
    {
        $type = self::types()->get('url');
        foreach ([false, true] as $lenient) {
            $checked = $type->parse($url, [], $lenient);
            self::assertFalse($checked->isOk(), ($lenient ? 'Lenient' : 'Strict') . ' accepted ' . var_export($checked->isOk() ? $checked->value() : null, true));
            self::assertSame('problem.url.invalid', $checked->problemKey());
        }
        self::assertFalse($type->check($url, [])->isOk());
        self::assertNull($type->fromStorage($url, []), 'A stored bad address reads as empty.');
    }

    public function testUrlAcceptedFormStoresTheSchemeLowerCase(): void
    {
        $type = self::types()->get('url');
        self::assertSame('https://Example.com/a?b=1#c', $type->parse('HTTPS://Example.com/a?b=1#c', [])->value());
        self::assertSame('http://Example.com/', $type->parse('hTTp://Example.com/', [])->value());
        self::assertSame('https://Example.com/a?b=1#c', $type->toStorage($type->parse('HTTPS://Example.com/a?b=1#c', [])->value(), []));
        self::assertNull($type->fromStorage('HTTPS://Example.com/', []), 'Stored values are in canonical form.');
        self::assertSame('https://example.com/', $type->fromStorage('https://example.com/', []));
    }

    public function testUrlLengthLimit(): void
    {
        $type = self::types()->get('url');
        $prefix = 'https://example.com/';
        $exact = $prefix . str_repeat('a', 2048 - strlen($prefix));
        self::assertSame(2048, strlen($exact));
        self::assertSame($exact, $type->parse($exact, [])->value());
        self::assertSame($exact, $type->parse('  ' . $exact . "\n", [])->value(), 'Surrounding white space is trimmed before counting.');
        self::assertFalse($type->parse($exact . 'a', [])->isOk());
        $lenient = 'example.com/' . str_repeat('a', 2048 - strlen('https://example.com/'));
        self::assertSame(2048, strlen('https://' . $lenient));
        self::assertSame('https://' . $lenient, $type->parse($lenient, [], true)->value());
        self::assertFalse($type->parse($lenient . 'a', [], true)->isOk(), 'The added scheme counts towards the limit.');
    }

    public function testUrlLongInputIsQuick(): void
    {
        $type = self::types()->get('url');
        $inputs = [str_repeat('a', 1000000), 'https://' . str_repeat('a.', 500000), str_repeat('https://', 100000), str_repeat('%', 1000000), 'https://example.com/' . str_repeat("\u{00E9}", 300000)];
        $start = hrtime(true);
        foreach ($inputs as $input) {
            self::assertFalse($type->parse($input, [])->isOk());
            self::assertFalse($type->parse($input, [], true)->isOk());
        }
        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedEmails(): iterable
    {
        $emails = [
            'javascript:alert(1)@example.com',
            "user\n@example.com",
            "user@exa\x00mple.com",
            "us\u{200B}er@example.com",
            "user@example.com\u{202E}",
            'user@例え.jp',
            'üser@example.com',
            '<script>@example.com',
            'user@example.com>',
            'user@<example.com>',
            'user name@example.com',
            '"user name"@example.com',
            'user@',
            '@example.com',
            'user',
            'user@example',
            'user@.example.com',
            'user@example..com',
            '.user@example.com',
            'user.@example.com',
            'us..er@example.com',
            str_repeat('a', 65) . '@example.com',
            "user@example.com\xC3",
        ];
        foreach ($emails as $i => $email) {
            yield '#' . $i . ' ' . json_encode($email, JSON_INVALID_UTF8_SUBSTITUTE) => [$email];
        }
    }

    #[DataProvider('refusedEmails')]
    public function testEmailRefusals(string $email): void
    {
        $type = self::types()->get('email');
        foreach ([false, true] as $lenient) {
            $checked = $type->parse($email, [], $lenient);
            self::assertFalse($checked->isOk(), 'Accepted ' . var_export($checked->isOk() ? $checked->value() : null, true));
            self::assertSame('problem.email.invalid', $checked->problemKey());
        }
        self::assertNull($type->fromStorage($email, []));
    }

    /**
     * P67: addresses filter_var accepts but TiffinDB refuses: quoted local
     * parts and local parts holding < > " ' ` \ ( ) , ; : [ ].
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedLocalParts(): iterable
    {
        $emails = [
            '"a<b>"@example.com',
            '"user"@example.com',
            '"a@b"@example.com',
            '"a\\"b"@example.com',
            '"<script>alert(1)</script>"@example.com',
            '"a,b;c:d"@example.com',
            '"(x)[y]"@example.com',
            "o'brien@example.com",
            "'@example.com",
            '`whoami`@example.com',
            'a`b@example.com',
        ];
        foreach ($emails as $i => $email) {
            yield '#' . $i . ' ' . json_encode($email) => [$email];
        }
    }

    #[DataProvider('refusedLocalParts')]
    public function testEmailLocalPartsRefusedBeyondFilterVar(string $email): void
    {
        self::assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL), 'filter_var alone accepts this address.');
        $type = self::types()->get('email');
        foreach ([false, true] as $lenient) {
            $checked = $type->parse($email, [], $lenient);
            self::assertFalse($checked->isOk(), 'Accepted ' . var_export($checked->isOk() ? $checked->value() : null, true));
            self::assertSame('problem.email.invalid', $checked->problemKey());
        }
        self::assertFalse($type->check($email, [])->isOk());
        self::assertNull($type->fromStorage($email, []));
    }

    /** P67: each refused character in an otherwise unquoted local part, whether or not filter_var accepts it. */
    public function testEmailLocalPartCharacters(): void
    {
        $type = self::types()->get('email');
        foreach (['<', '>', '"', "'", '`', '\\', '(', ')', ',', ';', ':', '[', ']'] as $character) {
            foreach (['a' . $character . 'b@example.com', '"a' . $character . 'b"@example.com', '"x y"@example.com'] as $email) {
                self::assertFalse($type->parse($email, [])->isOk(), $email);
                self::assertFalse($type->parse($email, [], true)->isOk(), $email);
                self::assertNull($type->fromStorage($email, []), $email);
            }
        }
        foreach (['user@example.com', 'a.b+c_d-e@example.com', 'user@[192.0.2.1]', "!#$%&*+/=?^_{|}~-@example.com"] as $email) {
            self::assertSame($email, $type->parse($email, [])->value(), $email);
            self::assertSame($email, $type->fromStorage($email, []), $email);
        }
    }

    public function testEmailLengthLimit(): void
    {
        $type = self::types()->get('email');
        $domain = str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 57) . '.com';
        $longest = str_repeat('a', 64) . '@' . $domain;
        self::assertSame(254, strlen($longest));
        self::assertSame($longest, $type->parse($longest, [])->value());
        self::assertFalse($type->parse(str_repeat('a', 64) . '@e' . $domain, [])->isOk());
    }

    public function testEmailLongInputIsQuick(): void
    {
        $type = self::types()->get('email');
        $inputs = [
            str_repeat('a', 100000) . '@example.com',
            str_repeat('a.', 50000) . '@example.com',
            'user@' . str_repeat('a.', 50000) . 'com',
            '"' . str_repeat('\\a', 50000) . '"@example.com',
            str_repeat('a', 63) . '@' . str_repeat('a-', 95) . 'a',
            '"' . str_repeat('a', 120) . '"@' . str_repeat('b.', 60),
            str_repeat('!#$%&\'*+/=?^_`{|}~-', 13) . '@example.com',
            str_repeat('a', 64) . '@' . str_repeat(str_repeat('b', 62) . '-.', 3),
        ];
        $start = hrtime(true);
        for ($round = 0; $round < 20; $round++) {
            foreach ($inputs as $input) {
                $type->parse($input, []);
            }
        }
        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9, '160 checks of long input stay fast.');
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function valuesPerType(): iterable
    {
        yield 'text' => ['text', ['max_length' => 500], 'Example'];
        yield 'long_text' => ['long_text', ['max_length' => 500], 'Example'];
        yield 'number' => ['number', ['decimals' => 2, 'min' => null, 'max' => null], '12.5'];
        yield 'currency' => ['currency', ['code' => 'USD', 'minor_digits' => 2], '12.50'];
        yield 'date' => ['date', [], '2026-03-14'];
        yield 'time' => ['time', ['seconds' => false], '14:30'];
        yield 'date_time' => ['date_time', ['seconds' => false], '2026-03-14T14:30'];
        yield 'duration' => ['duration', ['seconds' => false], '1:30'];
        yield 'checkbox' => ['checkbox', [], 'yes'];
        yield 'choice' => ['choice', ['choices' => [['key' => 'aaaaaaaaaaaa', 'label' => 'Example']]], 'Example'];
        yield 'rating' => ['rating', ['max' => 10], '10'];
        yield 'url' => ['url', [], 'https://example.com/'];
        yield 'email' => ['email', [], 'user@example.com'];
        yield 'phone' => ['phone', [], '555-0100'];
        yield 'auto_number' => ['auto_number', ['start' => 1, 'next' => 1], '12'];
    }

    /**
     * P61: control characters (Unicode Cc, Zl, Zp, Cf except U+200C and
     * U+200D) and invalid UTF-8 inside a value are refused by every type, in
     * both modes. Tab and line breaks are allowed where the type says so.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('valuesPerType')]
    public function testControlCharactersAndInvalidUtf8AreRefused(string $type, array $options, string $valid): void
    {
        $fieldType = self::types()->get($type);
        self::assertTrue($fieldType->parse($valid, $options, lenient: $type === 'auto_number')->isOk(), 'The sample is valid.');
        $bad = [
            "\x00", "\x01", "\x08", "\x0E", "\x1B", "\x1F", "\x7F", "\u{0080}", "\u{009F}",
            "\u{00AD}", "\u{061C}", "\u{180E}", "\u{200B}", "\u{200E}", "\u{2028}", "\u{2029}", "\u{202A}", "\u{202E}",
            "\u{2060}", "\u{2066}", "\u{FEFF}", "\u{E0001}", "\u{E007F}",
            "\xC3", "\xC0\xAF", "\xED\xA0\x80", "\xF4\x90\x80\x80", "\xFF", "\x80",
        ];
        foreach ($bad as $character) {
            $text = $valid[0] . $character . substr($valid, 1);
            foreach ([false, true] as $lenient) {
                $checked = $fieldType->parse($text, $options, $lenient);
                self::assertFalse($checked->isOk(), $type . ' accepted ' . bin2hex($character) . ($lenient ? ' (lenient)' : ''));
            }
            self::assertFalse($fieldType->check($text, $options)->isOk(), $type . ' check() accepted ' . bin2hex($character));
        }
    }

    public function testWhiteSpaceThatIsAControlCharacterIsRefusedInPhoneNumbers(): void
    {
        $phone = self::types()->get('phone');
        foreach (["\u{0085}", "\u{180E}", "\u{2028}", "\u{2029}", "\x0B", "\x0C"] as $character) {
            self::assertFalse($phone->parse('555' . $character . '0100', [])->isOk(), bin2hex($character));
        }
        self::assertSame('555 0100', $phone->parse("555\r\n\t 0100", [])->value());
    }

    public function testSurroundingWhiteSpaceIsTrimmedNotStored(): void
    {
        $types = self::types();
        self::assertSame('user@example.com', $types->get('email')->parse("\tuser@example.com\n", [])->value());
        self::assertSame('https://example.com/', $types->get('url')->parse("\u{00A0}https://example.com/\r\n", [])->value());
    }

    public function testJoinersAreAllowedInText(): void
    {
        $text = self::types()->get('text');
        self::assertSame("a\u{200C}b\u{200D}c", $text->parse("a\u{200C}b\u{200D}c", ['max_length' => 10])->value());
    }

    /** No locale changes how numbers, amounts, dates, or words are read or written. */
    public function testParsingIgnoresTheLocale(): void
    {
        $previous = setlocale(LC_ALL, '0');
        try {
            foreach ([['C'], ['de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'German'], ['tr_TR.UTF-8', 'tr_TR.utf8', 'tr_TR', 'Turkish'], ['fr_FR.UTF-8', 'fr_FR']] as $locales) {
                setlocale(LC_ALL, $locales);
                $this->assertLocaleIndependent();
            }
        } finally {
            setlocale(LC_ALL, $previous === false ? 'C' : $previous);
        }
    }

    public function testStoredValuesOfTheWrongShapeReadAsEmpty(): void
    {
        $types = self::types();
        self::assertNull($types->get('date')->fromStorage('2026-02-30', []));
        self::assertNull($types->get('date')->fromStorage('14/03/2026', []));
        self::assertNull($types->get('time')->fromStorage('14:30', []), 'Stored times have seconds.');
        self::assertNull($types->get('date_time')->fromStorage('2026-03-14 14:30:00', []));
        self::assertNull($types->get('duration')->fromStorage(-1, []));
        self::assertNull($types->get('currency')->fromStorage('abc', ['minor_digits' => 2]));
        self::assertNull($types->get('number')->fromStorage('abc', ['decimals' => 0]));
        self::assertSame(12, $types->get('number')->fromStorage('12', ['decimals' => 0]));
        self::assertSame(12.5, $types->get('number')->fromStorage(12.5, ['decimals' => 0]), 'Digits beyond a lowered limit are kept.');
        self::assertSame('12.5', $types->get('number')->toText(12.5, ['decimals' => 0]));
        self::assertSame('1.2345', $types->get('number')->toText(1.2345, ['decimals' => 2]));
        self::assertSame(12.0, $types->get('number')->fromStorage(12, ['decimals' => 2]));
        self::assertNull($types->get('rating')->fromStorage(0, ['max' => 5]));
        self::assertNull($types->get('auto_number')->fromStorage(-3, []));
        self::assertNull($types->get('relation')->fromStorage('1,2', []));
        self::assertNull($types->get('attachment')->toStorage([1, 2], []));
    }

    public function testTypesBuildNoSqlAndUseNoLocaleOrSuppression(): void
    {
        $directory = dirname(__DIR__, 3) . '/src/Core/Schema/Type';
        $files = glob($directory . '/*.php');
        self::assertNotFalse($files);
        self::assertGreaterThanOrEqual(19, count($files));
        foreach ([...$files, dirname($directory) . '/FieldTypes.php'] as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);
            self::assertDoesNotMatchRegularExpression('/\b(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|CREATE)\b/', $source, $name . ' builds no SQL.');
            self::assertDoesNotMatchRegularExpression('/\b(Database|PDO|Sqlite)\b/', $source, $name . ' touches no database.');
            self::assertDoesNotMatchRegularExpression('/\b(setlocale|localeconv|strftime|number_format|floatval|strval|mb_\w+)\s*\(/i', $source, $name);
            self::assertDoesNotMatchRegularExpression('/%(\.\d+|\.\'\s*\.\s*\$\w+\s*\.\s*\')?f/', $source, $name . ' uses %F, never the locale-aware %f.');
            foreach (token_get_all($source) as $token) {
                self::assertNotSame('@', $token, $name . ' uses no @ operator.');
            }
        }
    }

    private function assertLocaleIndependent(): void
    {
        $types = self::types();
        $number = $types->get('number');
        self::assertSame(1234.5, $number->parse('1,234.5', ['decimals' => 1])->value());
        self::assertSame('1234.50', $number->toText(1234.5, ['decimals' => 2]));
        self::assertFalse($number->parse('1.234,5', ['decimals' => 1])->isOk());
        self::assertSame('0.000001', $number->toText(0.000001, ['decimals' => 6]));
        self::assertSame(['min' => '0.5'], $number->parse('0.4', ['decimals' => 1, 'min' => 0.5])->problemParams());
        self::assertSame(123456, $types->get('currency')->parse('1,234.56', ['code' => 'EUR', 'minor_digits' => 2])->value());
        self::assertSame('1234.56', $types->get('currency')->toText(123456, ['minor_digits' => 2]));
        self::assertTrue($types->get('checkbox')->parse('YES', [])->value());
        self::assertTrue($types->get('checkbox')->parse('ON', [])->value(), 'ASCII case folding, also in a Turkish locale.');
        self::assertSame('Index', $types->get('choice')->parse('INDEX', ['choices' => [['key' => 'aaaaaaaaaaaa', 'label' => 'Index']]])->value());
        self::assertSame('2026-03-14T14:30:00', $types->get('date_time')->parse('2026-03-14 14:30', [])->value());
        self::assertSame('XIN', $types->get('currency')->normaliseOptions(['code' => 'xin'])['code']);
    }

    private static function types(): FieldTypes
    {
        return FieldTypes::standard(new Keys(new SeededRandom('security-test')));
    }
}
