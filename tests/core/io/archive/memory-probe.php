<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

/*
 * Run by MemoryBoundsTest in a separate PHP process with a low
 * memory_limit: checks and rebuilds one database file and prints the
 * outcome as JSON. A PHP fatal error (memory exhausted) ends the process
 * without that line, which the test reports as a failure.
 *
 * Arguments: autoloader, a small valid archive (for the reader), an empty
 * job folder, the database to check, the folder to rebuild into.
 */

use TiffinDB\Core\Io\Archive\ArchiveReader;
use TiffinDB\Core\Io\Archive\ArchiveRefused;
use TiffinDB\Core\Io\Archive\ProjectRebuilder;
use TiffinDB\Core\Io\Archive\UntrustedProject;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;

require $argv[1];

$types = FieldTypes::standard(new Keys(new SeededRandom('memory-probe-choices')));
$reader = ArchiveReader::open($argv[2], $argv[3], 1 << 40);
try {
    $source = UntrustedProject::check($argv[4], 3, $types);
    $report = (new ProjectRebuilder($types, new FixedClock('2026-11-19T10:15:00Z'), new Keys(new SeededRandom('memory-probe')), '0.3.0-dev'))
        ->rebuild($source, $reader, $argv[5], 'n3wk2yq7zx4b', 'Imported Project', 'p5rk3wq2zn7x', 'example.zip');
    $source->close();
    $result = ['outcome' => 'rebuilt', 'formsDropped' => $report->formsDropped, 'valuesEmptied' => $report->valuesEmptiedTotal(), 'records' => $report->records];
} catch (ArchiveRefused $refused) {
    $result = ['outcome' => 'refused', 'reason' => $refused->reason];
}
$reader->close();
$result['peak'] = memory_get_peak_usage(true);
echo json_encode($result), "\n";
