<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Csv;

use TiffinDB\Core\Io\Csv\CsvDialect;
use TiffinDB\Core\Io\Csv\CsvReader;
use TiffinDB\Tests\Support\TempDir;

/** Temporary CSV files for the CSV tests (synthetic content only). */
trait CsvFiles
{
    private ?TempDir $temp = null;
    private int $fileCount = 0;

    protected function tearDown(): void
    {
        $this->temp?->remove();
        $this->temp = null;
    }

    private function dir(): string
    {
        $this->temp ??= TempDir::create('tiffindb-csv-test-');

        return $this->temp->path();
    }

    private function file(string $bytes): string
    {
        $path = $this->dir() . '/f' . (++$this->fileCount) . '.csv';
        file_put_contents($path, $bytes);

        return $path;
    }

    /** @return list<list<string>> every record of the file */
    private function records(string $bytes, string $charset = 'utf-8', string $delimiter = ',', int $maxFields = 201, int $maxRecordBytes = CsvReader::MAX_RECORD_BYTES): array
    {
        $reader = new CsvReader($this->file($bytes), new CsvDialect($charset, $delimiter), $maxFields, $maxRecordBytes);
        $out = [];
        while (($record = $reader->next()) !== null) {
            $out[] = $record;
        }

        return $out;
    }

    /** Runs PHP code (after the autoloader) in a subprocess with the PCRE JIT off; returns its output. */
    public static function runWithoutJit(string $code): string
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $process = proc_open(
            [PHP_BINARY, '-d', 'pcre.jit=0', '-d', 'display_errors=1', '-d', 'memory_limit=256M', '-r', 'require ' . var_export($autoload, true) . '; ' . $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim((string) $output);
    }
}
