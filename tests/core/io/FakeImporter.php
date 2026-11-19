<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\Inspection;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Setting;
use TiffinDB\Core\Io\Contract\Settings;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Core\Io\Contract\SourceTable;

/**
 * An importer over rows held in memory, for the pipeline tests. It records
 * every rows() call, can fail at a given row, and can yield more rows than
 * asked (a misbehaving plugin) or rows of the wrong shape.
 */
final class FakeImporter implements Importer
{
    /** @var list<array{0: int, 1: int}> from, limit of each rows() call */
    public array $calls = [];
    public ?int $failAt = null;
    public ?\Throwable $failure = null;
    public bool $overYield = false;

    /**
     * @param list<string> $columns
     * @param list<array<mixed>> $rows
     */
    public function __construct(public array $columns, public array $rows)
    {
    }

    public function detect(SourceFile $file): Settings
    {
        return new Settings([new Setting('heading', 'First row holds headings', 'boolean', '1')]);
    }

    public function inspect(SourceFile $file, Settings $settings): Inspection
    {
        return new Inspection([new SourceTable('Sheet', $this->columns, count($this->rows))], 'Synthetic rows');
    }

    public function rows(SourceFile $file, Settings $settings, int $table, int $from, int $limit): iterable
    {
        $this->calls[] = [$from, $limit];
        $end = $this->overYield ? count($this->rows) : min(count($this->rows), $from + $limit);
        for ($i = $from; $i < $end; $i++) {
            if ($this->failAt !== null && $i >= $this->failAt) {
                throw $this->failure ?? new PluginFailure(PluginFailure::REASON_UNREADABLE, 'Injected.');
            }
            yield $this->rows[$i];
        }
    }

    public function table(): SourceTable
    {
        return new SourceTable('Sheet', $this->columns, count($this->rows));
    }
}
