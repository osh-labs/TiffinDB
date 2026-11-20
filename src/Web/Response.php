<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * An immutable HTTP response: status, headers, cookies, and body. send() is
 * the only code in the application that calls header(), apart from the
 * last-resort Fallback.
 *
 * Cookies are kept apart from the headers and added only through
 * withCookie(); a `Set-Cookie` header cannot be set with withHeader().
 *
 * public/index.php also loads this file directly (without the autoloader)
 * to report missing dependencies, so it must not need other classes to be
 * loaded: Cookie is only named in type checks and `instanceof`, which load
 * nothing, and Fallback only when a download's file has gone (index.php
 * has loaded it by then).
 *
 * A download (M3 spec part 2 section 2.2) has a file body instead of a
 * string: download() checks the file and builds its headers, send()
 * streams it in chunks, and a callback set with withAfterSend() runs once
 * after sending (it asks the core to remove the export job folder; the web
 * layer never deletes a file itself).
 */
final class Response
{
    /** Bytes read and sent at a time when streaming a file body. */
    public const CHUNK_BYTES = 65536;

    /** Media types download() accepts. */
    private const MEDIA_TYPE = '/^[a-z]+\/[a-z0-9.+-]+\z/';

    /** The only media types file() may send inline (M4 spec part 2 section 2). */
    public const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Media types sent with `; charset=utf-8`. */
    private const UTF8_TYPES = ['text/csv', 'application/json'];

    /** The file sent as the body of a download, or null for a string body. */
    private ?string $file = null;

    /** Whether the download's file is to be removed after sending (by the after-send callback). */
    private bool $removeAfter = false;

    /** Runs at most once, after a download is sent. */
    private ?Closure $afterSend = null;

    /**
     * Whether the response is a stored file (file()): SecurityHeaders
     * then sends its sandboxed policy instead of the page policy.
     */
    private bool $sandboxed = false;

    /** @var array<string, string> Header name to value; names unique ignoring case. */
    public readonly array $headers;

    /** @var list<Cookie> In the order added; names unique. */
    public readonly array $cookies;

    /**
     * @param array<string, string> $headers
     * @param list<Cookie>          $cookies
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
        array $cookies = [],
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Invalid HTTP status.');
        }
        $checked = [];
        foreach ($headers as $name => $value) {
            $checked = self::put($checked, (string) $name, $value);
        }
        $this->headers = $checked;

        $list = [];
        foreach ($cookies as $cookie) {
            if (!$cookie instanceof Cookie) {
                throw new InvalidArgumentException('Cookies must be Cookie objects.');
            }
            $list = self::putCookie($list, $cookie);
        }
        $this->cookies = $list;
    }

    /**
     * A 303 See Other to an application address built with UrlGenerator::to
     * (path-absolute, never `//`, no back-slash or control character).
     */
    public static function redirect(string $location): self
    {
        if (
            !str_starts_with($location, '/')
            || str_starts_with($location, '//')
            || str_contains($location, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $location) === 1
        ) {
            throw new InvalidArgumentException('Invalid redirect location.');
        }

        return new self(303, ['Location' => $location]);
    }

    /**
     * A 200 response whose body is the file at $path, sent as an
     * attachment named $fileName (M3 spec part 2 section 2.2).
     *
     * Headers: `Content-Type` ($mediaType, with `; charset=utf-8` for
     * text/csv and application/json), `Content-Length` (the file size),
     * `Content-Disposition` (contentDisposition()), and `Cache-Control:
     * no-store`; the kernel adds the security headers (nosniff among them)
     * as for every response.
     *
     * @param string $path       An absolute path to a regular file the application wrote.
     * @param string $mediaType  `type/subtype`, lower case (MEDIA_TYPE).
     * @param string $fileName   The name offered to the browser, valid UTF-8; never used as a path.
     * @param bool   $removeAfter Whether the after-send callback removes the file (withAfterSend()).
     * @throws InvalidArgumentException for a bad media type, path, or name
     */
    public static function download(string $path, string $mediaType, string $fileName, bool $removeAfter): self
    {
        if (preg_match(self::MEDIA_TYPE, $mediaType) !== 1) {
            throw new InvalidArgumentException('Invalid media type.');
        }
        $size = self::fileSize($path);
        $type = in_array($mediaType, self::UTF8_TYPES, true) ? $mediaType . '; charset=utf-8' : $mediaType;

        $response = new self(200, [
            'Content-Type' => $type,
            'Content-Length' => (string) $size,
            'Content-Disposition' => self::contentDisposition($fileName),
            'Cache-Control' => 'no-store',
        ]);
        $response->file = $path;
        $response->removeAfter = $removeAfter;

        return $response;
    }

    /**
     * A 200 response whose body is a stored file (an attachment), for the
     * serving route (M4 spec part 2 section 2).
     *
     * Headers: `Content-Type` ($mediaType exactly, no charset),
     * `Content-Length`, `Content-Disposition` (contentDisposition() with
     * $inline), and `Cache-Control: no-store`. The response is sandboxed:
     * SecurityHeaders sends SecurityHeaders::FILE_CSP for it. The file is
     * never removed after sending, and withAfterSend() refuses it.
     *
     * @param string $path      An absolute path to a regular file (the core's ServedFile path).
     * @param string $mediaType `type/subtype`, lower case (MEDIA_TYPE).
     * @param string $fileName  The name offered to the browser, valid UTF-8; never used as a path.
     * @param bool   $inline    Shown in the browser; only for INLINE_TYPES.
     * @throws InvalidArgumentException for a bad media type, path, or name, or inline for another type
     */
    public static function file(string $path, string $mediaType, string $fileName, bool $inline): self
    {
        if (preg_match(self::MEDIA_TYPE, $mediaType) !== 1) {
            throw new InvalidArgumentException('Invalid media type.');
        }
        if ($inline && !in_array($mediaType, self::INLINE_TYPES, true)) {
            throw new InvalidArgumentException('Only the four image types may be sent inline.');
        }
        $size = self::fileSize($path);

        $response = new self(200, [
            'Content-Type' => $mediaType,
            'Content-Length' => (string) $size,
            'Content-Disposition' => self::contentDisposition($fileName, $inline),
            'Cache-Control' => 'no-store',
        ]);
        $response->file = $path;
        $response->sandboxed = true;

        return $response;
    }

    /**
     * The size of the file at $path, which must be an absolute path to a
     * regular file that is not a link.
     *
     * @throws InvalidArgumentException otherwise
     */
    private static function fileSize(string $path): int
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) !== 1
            || !is_file($path)
            || is_link($path)
        ) {
            throw new InvalidArgumentException('A download needs an absolute path to a regular file.');
        }
        $size = filesize($path);
        if ($size === false) {
            throw new InvalidArgumentException('The download file cannot be read.');
        }

        return $size;
    }

    /**
     * `attachment; filename="<ascii>"; filename*=UTF-8''<percent-encoded>`
     * (RFC 6266 with RFC 8187): <ascii> is the name with every byte outside
     * printable ASCII, and `"`, `\`, `/`, and `;`, replaced by `_`; the
     * second form is rawurlencode() of the UTF-8 name, so it holds only
     * letters, digits, `-`, `.`, `_`, `~`, and `%`. Neither can end the
     * header line, close the quoted string, or add a parameter. With
     * $inline the first word is `inline` instead of `attachment`.
     *
     * @throws InvalidArgumentException for an empty name or one that is not valid UTF-8
     */
    public static function contentDisposition(string $fileName, bool $inline = false): string
    {
        if ($fileName === '' || preg_match('//u', $fileName) !== 1) {
            throw new InvalidArgumentException('A download name is non-empty UTF-8 text.');
        }
        $ascii = (string) preg_replace('/[^\x20-\x7E]|["\\\\\/;]/', '_', $fileName);

        return ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($fileName);
    }

    /**
     * A copy of a download with $done set to run once after the body (or,
     * for HEAD, the headers) has been sent, even if sending fails part way.
     * For a download made with $removeAfter, $done asks the core to remove
     * the file and its job folder (ExportOperations::finish).
     *
     * @param Closure(): void $done
     * @throws LogicException when the response is not a download, or is a stored file (file())
     */
    public function withAfterSend(Closure $done): self
    {
        if ($this->file === null || $this->sandboxed) {
            throw new LogicException('Only a download takes an after-send callback.');
        }
        $ran = false;
        $copy = clone $this;
        $copy->afterSend = static function () use (&$ran, $done): void {
            if ($ran) {
                return;
            }
            $ran = true;
            $done();
        };

        return $copy;
    }

    /** True for a stored file sent by file(): it takes the sandboxed policy. */
    public function isSandboxed(): bool
    {
        return $this->sandboxed;
    }

    /** True for a response with a file body (download() or file()). */
    public function isDownload(): bool
    {
        return $this->file !== null;
    }

    /** Whether the download's file is to be removed after sending. */
    public function removesAfterSend(): bool
    {
        return $this->removeAfter;
    }

    /**
     * The body as a string: the string body, or for a download the file's
     * contents (for tests; send() streams instead of reading it whole).
     */
    public function body(): string
    {
        if ($this->file === null) {
            return $this->body;
        }
        $contents = file_get_contents($this->file);
        if ($contents === false) {
            throw new LogicException('The download file cannot be read.');
        }

        return $contents;
    }

    /** A copy with the header set, replacing any header of the same name (ignoring case). */
    public function withHeader(string $name, string $value): self
    {
        return $this->carryBody(new self($this->status, self::put($this->headers, $name, $value), $this->body, $this->cookies));
    }

    /** A copy with the cookie added; a cookie of the same name added earlier is replaced. */
    public function withCookie(Cookie $cookie): self
    {
        return $this->carryBody(new self($this->status, $this->headers, $this->body, self::putCookie($this->cookies, $cookie)));
    }

    /** $copy with this response's file body and after-send callback. */
    private function carryBody(self $copy): self
    {
        $copy->file = $this->file;
        $copy->removeAfter = $this->removeAfter;
        $copy->afterSend = $this->afterSend;
        $copy->sandboxed = $this->sandboxed;

        return $copy;
    }

    /** The cookie of that name, or null. */
    public function cookie(string $name): ?Cookie
    {
        foreach ($this->cookies as $cookie) {
            if ($cookie->name === $name) {
                return $cookie;
            }
        }

        return null;
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
     * Each cookie is one more `('cookie', 'Set-Cookie: ...')` step, sent
     * with header($line, false) so that cookies do not replace each other.
     *
     * A download's file is streamed in CHUNK_BYTES pieces: in production
     * after ending every output buffer, with flush() after each piece and
     * ignore_user_abort() set so that the after-send callback still runs
     * when the browser goes away; with $emit, each piece is one more
     * `('chunk', bytes)` step and output buffers are left alone. The
     * after-send callback runs after the body, or after the headers for
     * HEAD, even when streaming fails.
     *
     * @param (callable(string, string|int): void)|null $emit
     */
    public function send(bool $headRequest = false, ?callable $emit = null): void
    {
        $production = $emit === null;
        $emit ??= static function (string $step, string|int $value): void {
            match ($step) {
                'remove' => header_remove((string) $value),
                'status' => http_response_code((int) $value),
                'header' => header((string) $value, true),
                'cookie' => header((string) $value, false),
            };
        };

        // A download's file is opened before any header is emitted, so a
        // file that has gone fails cleanly: the fixed 500 goes out instead,
        // with none of the download's headers (Content-Length,
        // Content-Disposition) and no PHP message in the body.
        $handle = null;
        if ($this->file !== null) {
            $handle = self::open($this->file);
            if ($handle === null) {
                try {
                    Fallback::log(new LogicException('The download file cannot be opened.'));
                    Fallback::response()->send($headRequest, $production ? null : $emit);
                } finally {
                    $this->runAfterSend();
                }

                return;
            }
        }

        $emit('remove', 'X-Powered-By');
        $emit('status', $this->status);
        foreach ($this->headers as $name => $value) {
            $emit('header', $name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            $emit('cookie', 'Set-Cookie: ' . $cookie->header());
        }
        if ($this->file === null) {
            if (!$headRequest) {
                echo $this->body;
            }

            return;
        }

        try {
            if (!$headRequest && $handle !== null) {
                $this->stream($handle, $production, $emit);
            }
        } finally {
            if ($handle !== null) {
                fclose($handle);
            }
            $this->runAfterSend();
        }
    }

    private function runAfterSend(): void
    {
        if ($this->afterSend !== null) {
            ($this->afterSend)();
        }
    }

    /**
     * The file opened for reading, or null when it is gone or unreadable.
     * PHP's warning is kept out of the output (which would send the
     * headers early) and out of the error log; the caller logs the failure.
     *
     * @return resource|null
     */
    private static function open(string $path)
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        set_error_handler(static fn (): bool => true);
        try {
            $handle = fopen($path, 'rb');
        } finally {
            restore_error_handler();
        }

        return $handle === false ? null : $handle;
    }

    /**
     * @param resource                           $handle
     * @param callable(string, string|int): void $emit
     */
    private function stream($handle, bool $production, callable $emit): void
    {
        if ($production) {
            ignore_user_abort(true);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            if ($production) {
                echo $chunk;
                flush();
            } else {
                $emit('chunk', $chunk);
            }
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
        if (strcasecmp($name, 'Set-Cookie') === 0) {
            throw new InvalidArgumentException('Cookies are added with withCookie().');
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

    /**
     * @param list<Cookie> $cookies
     * @return list<Cookie>
     */
    private static function putCookie(array $cookies, Cookie $cookie): array
    {
        $kept = [];
        foreach ($cookies as $existing) {
            if ($existing->name !== $cookie->name) {
                $kept[] = $existing;
            }
        }
        $kept[] = $cookie;

        return $kept;
    }
}
