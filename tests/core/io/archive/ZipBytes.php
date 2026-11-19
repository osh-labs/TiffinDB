<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

/**
 * Writes zip files byte by byte for the hostile-archive corpus (M3 spec
 * section 15.5, T-ARC-3): every header field can be set to a value no
 * well-behaved zip tool would write (a duplicate or traversal name, a
 * lying size, an unknown method, an encryption flag, a symbolic-link mode
 * in the external attributes). No data descriptors, no zip64.
 */
final class ZipBytes
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    /**
     * Adds one entry. Options: method (0 stored, 8 deflate, or any number;
     * the data is deflated only for 8), size and compSize (override the
     * declared sizes in both headers), crc (override), flags (general
     * purpose bits, for example 1 for encryption), mode (Unix mode in the
     * external attributes, for example 0120777 for a symbolic link), system
     * (the creator system in "version made by": 3 Unix, 0 MS-DOS), and
     * localName (a different name in the local header).
     *
     * @param array<string, int|string> $options
     */
    public function add(string $name, string $data, array $options = []): self
    {
        $method = (int) ($options['method'] ?? 8);
        $stored = $method === 8 ? (string) gzdeflate($data) : $data;
        $this->entries[] = [
            'name' => $name,
            'localName' => (string) ($options['localName'] ?? $name),
            'method' => $method,
            'data' => $stored,
            'crc' => (int) ($options['crc'] ?? crc32($data)),
            'size' => (int) ($options['size'] ?? strlen($data)),
            'compSize' => (int) ($options['compSize'] ?? strlen($stored)),
            'flags' => (int) ($options['flags'] ?? 0),
            'mode' => (int) ($options['mode'] ?? 0100644),
            'system' => (int) ($options['system'] ?? 3),
        ];

        return $this;
    }

    public function bytes(): string
    {
        $local = '';
        $central = '';
        foreach ($this->entries as $entry) {
            $offset = strlen($local);
            $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, $entry['flags'], $entry['method'], 0, 0x5b21, $entry['crc'], $entry['compSize'], $entry['size'], strlen($entry['localName']), 0)
                . $entry['localName'] . $entry['data'];
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, ($entry['system'] << 8) | 0x14, 20, $entry['flags'], $entry['method'], 0, 0x5b21, $entry['crc'], $entry['compSize'], $entry['size'], strlen($entry['name']), 0, 0, 0, 0, ($entry['mode'] << 16) & 0xffffffff, $offset)
                . $entry['name'];
        }
        $count = count($this->entries);

        return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
    }

    public function write(string $path): string
    {
        file_put_contents($path, $this->bytes());

        return $path;
    }
}
