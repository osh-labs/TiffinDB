<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/**
 * The server that received the current request, as the exposure probe sees
 * it (M1 spec section 10.4).
 *
 * Every value is checked here, because the host and the base path end up
 * in the probe's request text and the address and port decide where it
 * connects: the host must match HOST or HOST_IPV6, the server address must
 * be an IP address (never a name, so nothing is looked up), the port
 * 1 to 65535, the base path empty or `/`-separated URL path segments with
 * no white space or control characters, and the document root absolute or
 * null. The constructor throws \InvalidArgumentException otherwise;
 * tryCreate() returns null instead, for the web layer, which passes a null
 * target when a value is missing or invalid.
 */
final class ProbeTarget
{
    public const HOST = '/^[A-Za-z0-9.-]+(:\d{1,5})?\z/';
    public const HOST_IPV6 = '/^\[[0-9A-Fa-f:.]+\](:\d{1,5})?\z/';
    public const BASE_PATH = '#^(?:/[A-Za-z0-9._~!$&\'()*+,;=:@%-]+)*\z#';

    public function __construct(
        public readonly string $host,
        public readonly string $serverAddress,
        public readonly int $serverPort,
        public readonly bool $secure,
        public readonly ?string $documentRoot,
        public readonly string $basePath,
    ) {
        if (preg_match(self::HOST, $host) !== 1 && preg_match(self::HOST_IPV6, $host) !== 1) {
            throw new \InvalidArgumentException('The Host value is not acceptable for the probe.');
        }
        if (filter_var($serverAddress, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('The server address must be an IP address.');
        }
        if ($serverPort < 1 || $serverPort > 65535) {
            throw new \InvalidArgumentException('The server port must be 1 to 65535.');
        }
        if ($documentRoot !== null && ($documentRoot === '' || str_contains($documentRoot, "\0") || !str_starts_with($documentRoot, '/'))) {
            throw new \InvalidArgumentException('The document root must be an absolute path or null.');
        }
        if (preg_match(self::BASE_PATH, $basePath) !== 1) {
            throw new \InvalidArgumentException('The base path is not acceptable for the probe.');
        }
    }

    /** The target, or null when any value fails the checks of the constructor. */
    public static function tryCreate(
        ?string $host,
        ?string $serverAddress,
        int|string|null $serverPort,
        bool $secure,
        ?string $documentRoot,
        string $basePath,
    ): ?self {
        if ($host === null || $serverAddress === null || $serverPort === null) {
            return null;
        }
        if (is_string($serverPort)) {
            if (preg_match('/^\d{1,5}\z/', $serverPort) !== 1) {
                return null;
            }
            $serverPort = (int) $serverPort;
        }
        try {
            return new self($host, $serverAddress, $serverPort, $secure, $documentRoot, $basePath);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** The host name without its port and without IPv6 brackets: the TLS peer name. */
    public function hostName(): string
    {
        if (str_starts_with($this->host, '[')) {
            return substr($this->host, 1, (int) strpos($this->host, ']') - 1);
        }
        $colon = strpos($this->host, ':');

        return $colon === false ? $this->host : substr($this->host, 0, $colon);
    }
}
