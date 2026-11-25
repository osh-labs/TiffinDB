<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use RuntimeException;
use TiffinDB\Core\Storage\ProjectLock;

/**
 * A second PHP process that holds a lock (M7 spec section 16): started
 * with proc_open (an argument array, no shell), it takes the lock, prints
 * one line (`held`, `busy`, `none`, or `unavailable`), and waits until its
 * standard input is closed, then releases the lock and exits.
 *
 * Modes: `shared` and `exclusive` take ProjectLock on a project folder;
 * `flock` takes a plain exclusive flock on an existing file.
 */
final class LockHolder
{
    /** @param resource $process @param array<int, resource> $pipes */
    private function __construct(private $process, private array $pipes, public readonly string $answer)
    {
    }

    /** Starts a holder and waits for its line. */
    public static function start(string $mode, string $path): self
    {
        $process = proc_open(
            [PHP_BINARY, __FILE__, $mode, $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the lock holder.');
        }
        $line = fgets($pipes[1]);
        if ($line === false) {
            $error = stream_get_contents($pipes[2]);
            throw new RuntimeException('The lock holder said nothing: ' . $error);
        }

        return new self($process, $pipes, trim($line));
    }

    /** Closes the holder's input, so it releases the lock, and waits for it to end. */
    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($this->process);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** The holder process itself. @param list<string> $argv */
    public static function main(array $argv): int
    {
        require dirname(__DIR__, 2) . '/vendor/autoload.php';
        [, $mode, $path] = $argv + [null, '', ''];
        $lock = null;
        $handle = null;
        // The holder's own waits are short: it only ever reports what it got.
        ProjectLock::$waitScaleForTests = 0.02;
        try {
            if ($mode === 'shared') {
                $lock = ProjectLock::shared($path);
                $answer = $lock === null ? 'none' : 'held';
            } elseif ($mode === 'exclusive') {
                $lock = ProjectLock::exclusive($path);
                $answer = 'held';
            } elseif ($mode === 'flock') {
                $handle = fopen($path, 'r');
                $answer = is_resource($handle) && flock($handle, LOCK_EX | LOCK_NB) ? 'held' : 'busy';
            } else {
                $answer = 'unknown mode';
            }
        } catch (\TiffinDB\Core\Error\Busy) {
            $answer = 'busy';
        } catch (\TiffinDB\Core\Storage\LocksUnavailable) {
            $answer = 'unavailable';
        }
        fwrite(STDOUT, $answer . "\n");
        fflush(STDOUT);
        while (!feof(STDIN)) {
            fgets(STDIN);
        }
        $lock?->release();
        if (is_resource($handle)) {
            fclose($handle);
        }

        return 0;
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(LockHolder::main($argv));
}
