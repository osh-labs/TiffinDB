<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * Builds every link and asset address (ADR 0008 decision 3). All results
 * are path-absolute and carry the detected base path; the Host header is
 * never used. Results are unescaped; the view helpers escape them.
 */
final class UrlGenerator
{
    private const ASSET_NAME = '#^[a-z0-9][a-z0-9./-]*\z#';

    /** The base path, percent-encoded segment by segment. */
    private readonly string $basePath;

    /**
     * @param string $basePath The decoded base path (Request::$basePath).
     *                         Each segment is percent-encoded on output, so
     *                         an install directory containing a space, `%`,
     *                         `?`, `#`, or non-ASCII letters still links
     *                         correctly.
     */
    public function __construct(
        string $basePath,
        private readonly string $version,
    ) {
        // Empty, or `/segment` parts with no empty segment: a leading `//`
        // would turn every link into a protocol-relative external address.
        if (preg_match('#^(/[^/\\\\\x00-\x1F\x7F]+)*\z#', $basePath) !== 1) {
            throw new InvalidArgumentException('Invalid base path.');
        }
        $encoded = '';
        if ($basePath !== '') {
            foreach (explode('/', substr($basePath, 1)) as $segment) {
                $encoded .= '/' . rawurlencode($segment);
            }
        }
        $this->basePath = $encoded;
    }

    /**
     * Address of an application path, with an optional query.
     *
     * @param array<string, string> $query
     */
    public function to(string $path, array $query = []): string
    {
        if (
            !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || preg_match('/[?#\\\\\x00-\x20\x7F]/', $path) === 1
        ) {
            throw new InvalidArgumentException('Not an application path.');
        }

        $url = $this->basePath . $path;
        if ($query === []) {
            return $url;
        }
        foreach ($query as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidArgumentException('Query keys and values must be strings.');
            }
        }

        return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Address of a static file under public/assets/, with the version parameter. */
    public function asset(string $file): string
    {
        if (preg_match(self::ASSET_NAME, $file) !== 1 || str_contains($file, '..')) {
            throw new InvalidArgumentException('Invalid asset name.');
        }

        return $this->basePath . '/assets/' . $file . '?v=' . rawurlencode($this->version);
    }
}
