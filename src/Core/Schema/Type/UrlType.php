<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * A web address (M1 spec section 8.2). Security-relevant: the value is
 * later written into links, so only `http` and `https` addresses with a
 * host are accepted, and the rules are checked again when a value is read
 * from storage, so a hand-edited file cannot smuggle in another scheme.
 *
 * Rules, in order, after trimming:
 * - only printable ASCII (U+0021 to U+007E): no white space, no control
 *   characters, no invisible or non-ASCII characters. Host names outside
 *   ASCII must be given in their `xn--` form; FILTER_VALIDATE_URL refuses
 *   them anyway, and the `intl` extension is not required;
 * - at most 2048 characters;
 * - no backslash (browsers read it as a slash, PHP's parser does not);
 * - no `"`, `<`, `>`, or backtick anywhere (P72); `'` is allowed;
 * - scheme `http` or `https` in any case, stored lower-case, then `://`;
 * - passes filter_var(FILTER_VALIDATE_URL) and parse_url() finds a host;
 * - no user name or password before the host (`https://a@b` reads as a
 *   link to `b`, which misleads);
 * - the authority is a host of letters, digits, `.` and `-`, or a bracketed
 *   IPv6 address, then optionally `:` and a port of 1 to 5 digits from 1 to
 *   65535, then `/`, `?`, `#`, or the end;
 * - no host that browsers read as an IPv4 address written another way: a
 *   label starting with `0x`, a single integer, or all-digit labels other
 *   than exactly four decimal labels of 0 to 255 without leading zeros.
 *
 * Lenient parsing first puts `https://` in front of a value that has no
 * scheme, starts with a letter or digit, and has a dot before any `/`. The
 * result must still pass every rule above.
 */
final class UrlType implements FieldType
{
    public const MAX_LENGTH = 2048;
    /** Review S2: host name or bracketed IPv6, optional port, then a path, query, fragment, or the end. */
    private const AUTHORITY = '~^https?://(?<host>[A-Za-z0-9.\-]+|\[[0-9A-Fa-f:.]+\])(?::(?<port>\d{1,5}))?(?=[/?#]|\z)~';

    public function id(): string
    {
        return 'url';
    }

    public function columnDefinition(): ?string
    {
        return 'TEXT';
    }

    public function defaultOptions(): array
    {
        return [];
    }

    public function normaliseOptions(array $options): array
    {
        return [];
    }

    public function fromStorage(mixed $stored, array $options): mixed
    {
        if (!is_string($stored)) {
            return null;
        }
        $checked = $this->parse($stored, $options);

        return $checked->isOk() && $checked->value() === $stored ? $stored : null;
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        return TextValues::toStorage($value);
    }

    public function check(mixed $value, array $options): Checked
    {
        if ($value === null) {
            return Checked::ok(null);
        }

        return is_string($value) ? $this->parse($value, $options) : Checked::problem('problem.url.invalid');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.url.invalid');
        }
        $text = Text::trim($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        if ($lenient && self::lacksScheme($text)) {
            $text = 'https://' . $text;
        }
        $url = self::valid($text);

        return $url === null ? Checked::problem('problem.url.invalid') : Checked::ok($url);
    }

    public function toText(mixed $value, array $options): string
    {
        return TextValues::toText($value);
    }

    public function canBeTitle(): bool
    {
        return true;
    }

    public function isEditable(): bool
    {
        return true;
    }

    /** The canonical address (scheme lower-cased), or null when any rule fails. */
    private static function valid(string $text): ?string
    {
        if (preg_match('/^[\x21-\x7E]+\z/', $text) !== 1 || strlen($text) > self::MAX_LENGTH) {
            return null;
        }
        if (strpbrk($text, '\\"<>`') !== false) {
            return null;
        }
        if (preg_match('~^(https?)://~i', $text, $m) !== 1) {
            return null;
        }
        $url = strtolower($m[1]) . substr($text, strlen($m[1]));
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }
        if (preg_match(self::AUTHORITY, $url, $a) !== 1) {
            return null;
        }
        $port = $a['port'] ?? '';
        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
            return null;
        }
        if (!str_starts_with($a['host'], '[') && self::looksLikeOtherIpv4($a['host'])) {
            return null;
        }

        return $url;
    }

    /** A host browsers would read as an IPv4 address in a form other than four plain decimal labels. */
    private static function looksLikeOtherIpv4(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if (stripos($label, '0x') === 0) {
                return true;
            }
        }
        if (preg_match('/^[0-9.]+\z/', $host) !== 1) {
            return false;
        }
        $octet = '(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)';

        return preg_match('/^' . $octet . '(?:\.' . $octet . '){3}\z/', $host) !== 1;
    }

    /** True when the text starts with a letter or digit, has no scheme, and has a dot before any slash. */
    private static function lacksScheme(string $text): bool
    {
        if (preg_match('/^[A-Za-z0-9]/', $text) !== 1) {
            return false;
        }
        // A scheme is letters, digits, + . - then a colon; "host:8080" (a digit after the colon) is a port, not a scheme.
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:(?!\d)/', $text) === 1) {
            return false;
        }
        $dot = strpos($text, '.');
        $slash = strpos($text, '/');

        return $dot !== false && ($slash === false || $dot < $slash);
    }
}
