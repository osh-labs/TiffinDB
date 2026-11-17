<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/**
 * The only network code in the application (M1 spec section 10.4, P48):
 * one HTTP/1.0 GET to the server that is running it, at SERVER_ADDR and
 * SERVER_PORT, never to the address named by the Host header.
 *
 * Never follows redirects; sends no cookies or credentials. Every call
 * that can warn (connect, TLS, write, read, close) runs under a local error
 * handler, restored in `finally`; any warning, timeout, TLS failure, or
 * unparseable answer gives null (P56), so the verdict is `inconclusive`.
 *
 * Each request has an overall deadline of DEADLINE_SECONDS on top of the
 * per-read timeout, so a server that trickles bytes cannot hold setup.
 *
 * Test seam: the connector closure (uri, context options, timeout) returns
 * a stream or false; tests return one end of a stream_socket_pair (T-INS-8).
 */
final class LoopbackHttpProbe implements HttpProbe
{
    public const TIMEOUT_SECONDS = 5;
    /** Overall limit for one request, connection included (F6). */
    public const DEADLINE_SECONDS = 10;
    public const MAX_BYTES = 65536;
    public const USER_AGENT = 'TiffinDB-setup-check';

    private readonly \Closure $connector;
    private readonly \Closure $seconds;

    /**
     * @param (\Closure(string, array<string, mixed>, float): mixed)|null $connector
     * @param (\Closure(): float)|null $seconds a monotonic clock in seconds (test seam); hrtime by default
     */
    public function __construct(?\Closure $connector = null, ?\Closure $seconds = null)
    {
        $this->seconds = $seconds ?? static fn (): float => hrtime(true) / 1e9;
        $this->connector = $connector ?? static function (string $uri, array $options, float $timeout): mixed {
            $errorCode = 0;
            $errorText = '';

            return stream_socket_client(
                $uri,
                $errorCode,
                $errorText,
                $timeout,
                STREAM_CLIENT_CONNECT,
                stream_context_create($options),
            );
        };
    }

    public function get(ProbeTarget $target, string $path): ?ProbeResponse
    {
        if (preg_match('#^/[\x21-\x7e]*\z#', $path) !== 1) {
            return null;
        }
        $address = filter_var($target->serverAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $target->serverAddress . ']'
            : $target->serverAddress;
        $uri = ($target->secure ? 'tls://' : 'tcp://') . $address . ':' . $target->serverPort;
        $options = $target->secure ? ['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $target->hostName(),
            'SNI_enabled' => true,
        ]] : [];
        $request = 'GET ' . $path . " HTTP/1.0\r\n"
            . 'Host: ' . $target->host . "\r\n"
            . 'User-Agent: ' . self::USER_AGENT . "\r\n"
            . "Connection: close\r\n\r\n";

        $raw = self::quietly(fn (): ?string => $this->exchange($uri, $options, $request));

        return $raw === null ? null : self::parse($raw);
    }

    /** The status and body of a raw answer, or null when it is not an HTTP/1.x answer. */
    public static function parse(string $raw): ?ProbeResponse
    {
        if (preg_match('#^HTTP/1\.\d (\d{3})(?: [^\r\n]*)?\r\n#', $raw, $match) !== 1) {
            return null;
        }
        $split = strpos($raw, "\r\n\r\n");
        if ($split === false) {
            return null;
        }

        return new ProbeResponse((int) $match[1], substr($raw, $split + 4));
    }

    /** @param array<string, mixed> $options */
    private function exchange(string $uri, array $options, string $request): ?string
    {
        $deadline = ($this->seconds)() + self::DEADLINE_SECONDS;
        $stream = ($this->connector)($uri, $options, (float) self::TIMEOUT_SECONDS);
        if (!is_resource($stream)) {
            return null;
        }
        try {
            if (!stream_set_timeout($stream, self::TIMEOUT_SECONDS)) {
                return null;
            }
            $sent = 0;
            while ($sent < strlen($request)) {
                $written = fwrite($stream, substr($request, $sent));
                if ($written === false || $written === 0) {
                    return null;
                }
                $sent += $written;
            }
            $raw = '';
            while (strlen($raw) < self::MAX_BYTES && !feof($stream)) {
                $left = $deadline - ($this->seconds)();
                if ($left <= 0) {
                    return null;
                }
                if (!stream_set_timeout($stream, (int) min(self::TIMEOUT_SECONDS, ceil($left)))) {
                    return null;
                }
                $chunk = fread($stream, self::MAX_BYTES - strlen($raw));
                if ($chunk === false) {
                    return null;
                }
                if (stream_get_meta_data($stream)['timed_out']) {
                    return null;
                }
                $raw .= $chunk;
            }
            return substr($raw, 0, self::MAX_BYTES);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Runs $call under a local error handler; any PHP warning or notice
     * gives null. The handler is restored in `finally`.
     *
     * @param callable(): ?string $call
     */
    private static function quietly(callable $call): ?string
    {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        });
        try {
            $result = $call();
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }

        return $warned ? null : $result;
    }
}
