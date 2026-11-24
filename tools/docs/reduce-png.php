<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) TiffinDB contributors

/*
 * Reduces documentation screenshots to an indexed palette (ADR 0020
 * decision 3), in place, with the GD extension that ships with PHP.
 *
 * Usage: php tools/docs/reduce-png.php <file.png> [<file.png> ...]
 *
 * A file is rewritten only when the indexed version is smaller. Screenshots
 * of the application use few colours, so 256 colours without dithering keeps
 * text sharp. Look at every file afterwards all the same.
 *
 * Documentation tooling only: not used by the application or the tests.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "reduce-png: the PHP gd extension is required.\n");
    exit(2);
}
$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "Usage: php tools/docs/reduce-png.php <file.png> [<file.png> ...]\n");
    exit(2);
}

$before = 0;
$after = 0;
$failed = 0;
foreach ($files as $file) {
    $info = is_file($file) ? getimagesize($file) : false;
    if ($info === false || $info[2] !== IMAGETYPE_PNG) {
        fwrite(STDERR, "reduce-png: not a PNG file: {$file}\n");
        $failed++;
        continue;
    }
    $size = (int) filesize($file);
    $before += $size;
    $image = imagecreatefrompng($file);
    if ($image === false) {
        fwrite(STDERR, "reduce-png: cannot read {$file}\n");
        $failed++;
        $after += $size;
        continue;
    }
    if (!imageistruecolor($image)) {
        printf("%s: already indexed (%d bytes)\n", $file, $size);
        $after += $size;
        continue;
    }
    imagetruecolortopalette($image, false, 256);
    $temp = $file . '.reduce-tmp';
    $ok = imagepng($image, $temp, 9);
    if (!$ok) {
        fwrite(STDERR, "reduce-png: cannot write {$temp}\n");
        $failed++;
        $after += $size;
        continue;
    }
    $newSize = (int) filesize($temp);
    if ($newSize < $size) {
        rename($temp, $file);
        printf("%s: %d -> %d bytes\n", $file, $size, $newSize);
        $after += $newSize;
    } else {
        unlink($temp);
        printf("%s: kept (%d bytes; indexed was %d)\n", $file, $size, $newSize);
        $after += $size;
    }
}
printf("Total: %d -> %d bytes\n", $before, $after);
exit($failed > 0 ? 1 : 0);
