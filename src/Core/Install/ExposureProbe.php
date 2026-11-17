<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Random;

/**
 * The data-folder exposure test (M1 spec section 10.4, P48): writes a file
 * holding a random nonce into the data folder, asks this same server for
 * it at every path where it could be served, and deletes it again.
 *
 * Verdict: `exposed` when any path served the nonce; `safe` when there was
 * at least one path and every path was refused (4xx, or 2xx without the
 * nonce); `inconclusive` otherwise (no path, no answer, 1xx, 3xx, 5xx).
 * An exception from the HttpProbe propagates, after the file is deleted.
 */
final class ExposureProbe
{
    public const SAFE = 'safe';
    public const EXPOSED = 'exposed';
    public const INCONCLUSIVE = 'inconclusive';

    public function __construct(private readonly Random $random)
    {
    }

    /** 'safe' | 'exposed' | 'inconclusive' */
    public function run(string $dataDir, string $appRoot, ProbeTarget $target, HttpProbe $http): string
    {
        $name = 'probe-' . bin2hex($this->random->bytes(8)) . '.txt';
        $nonce = bin2hex($this->random->bytes(16));
        $file = rtrim($dataDir, '/') . '/' . $name;
        Fs::writeNew($file, $nonce);
        try {
            $paths = self::paths($dataDir, $appRoot, $target, $name);
            if ($paths === []) {
                return self::INCONCLUSIVE;
            }
            $served = false;
            $unclear = false;
            foreach ($paths as $path) {
                $answer = self::classify($http->get($target, $path), $nonce);
                if ($answer === 'served') {
                    $served = true;
                } elseif ($answer === 'unclear') {
                    $unclear = true;
                }
            }
            if ($served) {
                return self::EXPOSED;
            }

            return $unclear ? self::INCONCLUSIVE : self::SAFE;
        } finally {
            Fs::remove($file);
        }
    }

    /**
     * The URL paths to try, duplicates removed, in order: the folder's
     * place below a known document root (each segment rawurlencoded); and,
     * for `<app>/data`, `<basePath>/data/<name>` and, when the base path
     * ends with `/public`, the same without `/public`.
     *
     * @return list<string>
     */
    public static function paths(string $dataDir, string $appRoot, ProbeTarget $target, string $name): array
    {
        $file = '/' . rawurlencode($name);
        $paths = [];
        $data = Fs::nearestExisting($dataDir);
        if ($target->documentRoot !== null && $data !== null) {
            $root = Fs::nearestExisting($target->documentRoot);
            if ($root !== null && Fs::isInside($data, $root)) {
                $relative = substr($data, strlen(rtrim($root, '/')));
                $segments = array_values(array_filter(explode('/', $relative), static fn (string $s): bool => $s !== ''));
                $prefix = $segments === [] ? '' : '/' . implode('/', array_map('rawurlencode', $segments));
                $paths[] = $prefix . $file;
            }
        }
        if (self::isInTree($dataDir, $appRoot)) {
            $base = rtrim($target->basePath, '/');
            $paths[] = $base . '/' . DataDirectory::IN_TREE . $file;
            if (str_ends_with($base, '/public')) {
                $paths[] = substr($base, 0, -strlen('/public')) . '/' . DataDirectory::IN_TREE . $file;
            }
        }

        return array_values(array_unique($paths));
    }

    /** 'served' | 'refused' | 'unclear' */
    public static function classify(?ProbeResponse $response, string $nonce): string
    {
        if ($response === null) {
            return 'unclear';
        }
        if ($response->status >= 200 && $response->status <= 299) {
            return str_contains($response->body, $nonce) ? 'served' : 'refused';
        }
        if ($response->status >= 400 && $response->status <= 499) {
            return 'refused';
        }

        return 'unclear';
    }

    private static function isInTree(string $dataDir, string $appRoot): bool
    {
        $inTree = DataDirectory::candidates($appRoot, null)[1]->path;
        $given = (new DataDirectory($dataDir, 'data'))->path();
        if ($given === $inTree) {
            return true;
        }
        $realGiven = Fs::nearestExisting($dataDir);

        return $realGiven !== null && $realGiven === Fs::nearestExisting($inTree) && is_dir($inTree);
    }
}
