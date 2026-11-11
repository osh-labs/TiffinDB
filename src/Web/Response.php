<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * An immutable HTTP response. send() is the only code in the application
 * that calls header().
 *
 * public/index.php also loads this file directly (without the autoloader)
 * to report missing dependencies, so it must not depend on other classes.
 */
final class Response
{
    /** @var array<string, string> Header name to value; names unique ignoring case. */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Invalid HTTP status.');
        }
        $checked = [];
        foreach ($headers as $name => $value) {
            $checked = self::put($checked, (string) $name, $value);
        }
        $this->headers = $checked;
    }

    /** A copy with the header set, replacing any header of the same name (ignoring case). */
    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, self::put($this->headers, $name, $value), $this->body);
    }

    /** The value of a header, looked up ignoring case, or null. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $existing => $value) {
            if (strcasecmp($existing, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Emits status, headers, and (unless the request was HEAD) the body.
     *
     * $emit is a test seam: when given, it receives each step instead of
     * PHP's header functions, as `('remove', header name)`, `('status',
     * code)`, and `('header', full header line)`. The command-line SAPI
     * that runs the tests does not record headers, so this is the only way
     * to observe them in process. Production code never passes it.
     *
     * @param (callable(string, string|int): void)|null $emit
     */
    public function send(bool $headRequest = false, ?callable $emit = null): void
    {
        $emit ??= static function (string $step, string|int $value): void {
            match ($step) {
                'remove' => header_remove((string) $value),
                'status' => http_response_code((int) $value),
                'header' => header((string) $value, true),
            };
        };

        $emit('remove', 'X-Powered-By');
        $emit('status', $this->status);
        foreach ($this->headers as $name => $value) {
            $emit('header', $name . ': ' . $value);
        }
        if (!$headRequest) {
            echo $this->body;
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function put(array $headers, string $name, mixed $value): array
    {
        // Header names are RFC 9110 tokens; values may not break the line.
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid header name.');
        }
        if (!is_string($value) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Invalid header value.');
        }
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
        $headers[$name] = $value;

        return $headers;
    }
}
