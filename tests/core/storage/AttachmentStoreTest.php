<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\AttachmentStore;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\MediaType;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\Storage\StoredUpload;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-STO-10 to T-STO-13 and T-STO-16 (the source scan; the SQL part is in
 * tests/core/schema/SqlStringsTest.php): the attachment store of M4 spec
 * section 3.2, with failures injected at each step of a placement.
 */
#[CoversClass(AttachmentStore::class)]
#[CoversClass(StoredUpload::class)]
final class AttachmentStoreTest extends TestCase
{
    private TempDir $temp;
    private string $project;
    private Database $db;
    private AttachmentStore $store;
    private string|false $previousLog = false;
    private int $staged = 0;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->project = $this->temp->path('projects/p1');
        mkdir($this->project, 0750, true);
        mkdir($this->temp->path('staging/uploads'), 0750, true);
        $this->db = Sqlite::create($this->project . '/project.tiffin');
        $this->store = new AttachmentStore($this->project);
    }

    /** Sends error_log() to a file for the rest of the test (PHPUnit sets its own log after setUp). */
    private function captureLog(): void
    {
        $this->previousLog = ini_set('error_log', $this->temp->path('error.log'));
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== false) {
            ini_set('error_log', $this->previousLog);
        }
        $this->db->close();
        $this->temp->remove();
    }

    /** Writes $bytes to a new staged upload.bin and returns its path. */
    private function stage(string $bytes): string
    {
        $folder = $this->temp->path('staging/uploads/job' . (string) ++$this->staged);
        mkdir($folder, 0750);
        file_put_contents($folder . '/upload.bin', $bytes);

        return $folder . '/upload.bin';
    }

    private function place(StoredUpload $upload): bool
    {
        return $this->db->write(fn (Database $db): bool => $this->store->place($db, $upload));
    }

    private function target(string $sha256): string
    {
        return $this->project . '/attachments/' . substr($sha256, 0, 2) . '/' . $sha256;
    }

    private function log(): string
    {
        clearstatcache();

        return is_file($this->temp->path('error.log')) ? (string) file_get_contents($this->temp->path('error.log')) : '';
    }

    /** @param callable(): mixed $call */
    private static function assertRefused(string $exception, callable $call, string $message = ''): void
    {
        try {
            $call();
            self::fail('Not refused: ' . $message);
        } catch (\Throwable $error) {
            self::assertInstanceOf($exception, $error, $message . ' ' . $error->getMessage());
        }
    }

    /** @return list<string> every path under the project folder except the database files */
    private function projectTree(): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && !str_starts_with($entry->getFilename(), 'project.tiffin')) {
                $paths[] = substr($entry->getPathname(), strlen($this->project) + 1) . ($entry->isLink() ? ' (link)' : '');
            }
        }
        sort($paths);

        return $paths;
    }

    public function testTheProjectFolderMustBeARealDirectory(): void
    {
        file_put_contents($this->temp->path('file'), 'synthetic');
        symlink($this->project, $this->temp->path('link'));
        foreach (['file', 'link', 'missing', ''] as $name) {
            self::assertRefused(\InvalidArgumentException::class, fn (): AttachmentStore => new AttachmentStore($name === '' ? '' : $this->temp->path($name)), $name);
        }
        self::assertRefused(\InvalidArgumentException::class, fn (): AttachmentStore => new AttachmentStore($this->project . "\0"));
        self::assertSame('attachments', AttachmentStore::DIR);
    }

    /** T-STO-10 */
    public function testInspect(): void
    {
        $cases = [
            [FileFixture::png(3, 2), MediaType::PNG],
            [FileFixture::jpeg(), MediaType::JPEG],
            [FileFixture::gif(), MediaType::GIF],
            [FileFixture::webp(), MediaType::WEBP],
            [FileFixture::pdf(), MediaType::PDF],
            ['<svg xmlns="http://www.w3.org/2000/svg"/>', MediaType::OTHER],
            [FileFixture::bytes(70000), MediaType::OTHER],
        ];
        foreach ($cases as [$bytes, $type]) {
            $path = $this->stage($bytes);
            $upload = $this->store->inspect($path);
            self::assertSame($path, $upload->path);
            self::assertSame(hash_file('sha256', $path), $upload->sha256);
            self::assertSame(strlen($bytes), $upload->size);
            self::assertSame($type, $upload->mediaType);
        }
        $empty = $this->store->inspect($this->stage(''));
        self::assertSame([0, hash('sha256', '')], [$empty->size, $empty->sha256], 'An empty file is inspected; refusing it is the caller\'s job.');
    }

    /** T-STO-10 */
    public function testInspectRefusesALinkAFolderAndNothing(): void
    {
        $real = $this->stage(FileFixture::png());
        symlink($real, $this->temp->path('staging/uploads/linked.bin'));
        mkdir($this->temp->path('staging/uploads/folder.bin'));
        foreach (['linked.bin', 'folder.bin', 'missing.bin'] as $name) {
            self::assertRefused(\RuntimeException::class, fn (): StoredUpload => $this->store->inspect($this->temp->path('staging/uploads/' . $name)), $name);
        }
        self::assertRefused(\InvalidArgumentException::class, fn (): StoredUpload => $this->store->inspect($real . "\0"));
    }

    public function testStoredUploadRefusesABadHash(): void
    {
        foreach ([str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65), '../' . str_repeat('a', 61), str_repeat('a', 63) . "\0", str_repeat('a', 64) . "\n"] as $bad) {
            self::assertRefused(\InvalidArgumentException::class, static fn (): StoredUpload => new StoredUpload('/x', $bad, 1, MediaType::OTHER), $bad);
        }
        self::assertRefused(\InvalidArgumentException::class, static fn (): StoredUpload => new StoredUpload('/x', str_repeat('a', 64), -1, MediaType::OTHER));
    }

    /** T-STO-11 */
    public function testPlaceMovesAnAbsentTarget(): void
    {
        $old = umask(0077);
        try {
            $upload = $this->store->inspect($this->stage(FileFixture::png(2, 2)));
            self::assertFalse(Fs::exists($this->project . '/attachments'), 'A new project has no attachments folder yet.');
            self::assertTrue($this->place($upload));
        } finally {
            umask($old);
        }
        $target = $this->target($upload->sha256);
        self::assertTrue(Fs::isFile($target));
        self::assertFalse(Fs::exists($upload->path), 'The staged file was moved, not copied.');
        self::assertSame(FileFixture::png(2, 2), file_get_contents($target));
        clearstatcache();
        self::assertSame(0640, fileperms($target) & 0777);
        self::assertSame(0750, fileperms(dirname($target)) & 0777);
        self::assertSame(0750, fileperms($this->project . '/attachments') & 0777);
        self::assertSame($target, $this->store->path($upload->sha256));
        self::assertSame([$upload->sha256], $this->store->storedHashes());
    }

    /** T-STO-11 */
    public function testIdenticalContentIsStoredOnce(): void
    {
        $first = $this->store->inspect($this->stage(FileFixture::gif()));
        self::assertTrue($this->place($first));
        $before = filemtime($this->target($first->sha256));
        $second = $this->store->inspect($this->stage(FileFixture::gif()));
        self::assertSame($first->sha256, $second->sha256);
        self::assertFalse($this->place($second), 'Identical content is not moved twice.');
        self::assertTrue(Fs::isFile($second->path), 'The staged file is left for the caller to remove.');
        self::assertSame($before, filemtime($this->target($first->sha256)));
        self::assertSame([$first->sha256], $this->store->storedHashes());
    }

    /** T-STO-11: a link, a folder, and a different-size file at the target are refused with nothing moved. */
    public function testPlaceRefusesAnythingElseAtTheTarget(): void
    {
        $outside = $this->temp->path('outside.bin');
        file_put_contents($outside, FileFixture::pdf());
        $squatters = [
            'link' => static fn (string $target): bool => symlink($outside, $target),
            'dangling link' => fn (string $target): bool => symlink($this->temp->path('nowhere'), $target),
            'folder' => static fn (string $target): bool => mkdir($target),
            'different size' => static fn (string $target): bool => file_put_contents($target, 'x') === 1,
        ];
        foreach ($squatters as $label => $squat) {
            $bytes = FileFixture::bytes(100, $label);
            $upload = $this->store->inspect($this->stage($bytes));
            $target = $this->target($upload->sha256);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0750, true);
            }
            self::assertTrue($squat($target));
            $tree = $this->projectTree();
            self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($upload), $label);
            self::assertSame($bytes, file_get_contents($upload->path), $label . ': the staged file is untouched.');
            self::assertSame($tree, $this->projectTree(), $label . ': nothing moved.');
            self::assertSame(FileFixture::pdf(), file_get_contents($outside), $label . ': the link target is untouched.');
            self::assertSame($label === 'different size' ? $target : null, $this->store->path($upload->sha256), $label);
        }
    }

    /** T-STO-11: a folder on the way that is not a real directory refuses the placement (injected failures). */
    public function testPlaceRefusesALinkedOrBlockedFolder(): void
    {
        $outside = $this->temp->path('outside');
        mkdir($outside);
        $upload = $this->store->inspect($this->stage(FileFixture::jpeg()));
        $prefix = substr($upload->sha256, 0, 2);

        symlink($outside, $this->project . '/attachments');
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($upload), 'linked attachments folder');
        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
        unlink($this->project . '/attachments');

        file_put_contents($this->project . '/attachments', 'synthetic');
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($upload), 'a file named attachments');
        unlink($this->project . '/attachments');

        mkdir($this->project . '/attachments', 0750);
        symlink($outside, $this->project . '/attachments/' . $prefix);
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($upload), 'linked prefix folder');
        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
        unlink($this->project . '/attachments/' . $prefix);

        file_put_contents($this->project . '/attachments/' . $prefix, 'synthetic');
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($upload), 'a file named as the prefix folder');
        unlink($this->project . '/attachments/' . $prefix);

        self::assertTrue(Fs::isFile($upload->path), 'Every refusal left the staged file.');
        self::assertTrue($this->place($upload), 'With the way clear the same upload is placed.');
    }

    /** T-STO-11: the staged file must still be the inspected regular file. */
    public function testPlaceRefusesAChangedStagedFile(): void
    {
        $upload = $this->store->inspect($this->stage(FileFixture::png()));
        file_put_contents($upload->path, 'tampered');
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($upload), 'size changed');

        $gone = $this->store->inspect($this->stage(FileFixture::gif()));
        unlink($gone->path);
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($gone), 'removed');

        $swapped = $this->store->inspect($this->stage(FileFixture::webp()));
        $elsewhere = $this->temp->path('elsewhere.bin');
        file_put_contents($elsewhere, FileFixture::webp());
        unlink($swapped->path);
        symlink($elsewhere, $swapped->path);
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($swapped), 'swapped for a link');
        self::assertTrue(is_file($elsewhere));
        self::assertSame([], $this->store->storedHashes());
        self::assertFalse(Fs::exists($this->project . '/attachments/' . substr($swapped->sha256, 0, 2) . '/' . $swapped->sha256));
    }

    /** Review L1: a same-size file whose content does not match its hash name is replaced, not trusted. */
    public function testPlaceReplacesASameSizeFileWithOtherContent(): void
    {
        $bytes = FileFixture::png(3, 3);
        $upload = $this->store->inspect($this->stage($bytes));
        $target = $this->target($upload->sha256);
        mkdir(dirname($target), 0750, true);
        file_put_contents($target, str_repeat('X', strlen($bytes)));
        chmod($target, 0600);
        self::assertTrue($this->place($upload), 'The planted file was replaced.');
        self::assertSame($bytes, file_get_contents($target));
        self::assertSame($upload->sha256, hash_file('sha256', $target));
        self::assertFalse(Fs::exists($upload->path));
        clearstatcache();
        self::assertSame(0640, fileperms($target) & 0777);
        self::assertSame([$upload->sha256], $this->store->storedHashes());

        // The genuine file is then trusted: a second identical upload is not moved.
        $again = $this->store->inspect($this->stage($bytes));
        self::assertFalse($this->place($again));
        self::assertTrue(Fs::isFile($again->path));
    }

    /** Review L2: place() hashes and types the staged file again, so a swap after inspect or a hand-built upload is refused. */
    public function testPlaceRechecksTheHashAndTheType(): void
    {
        $bytes = FileFixture::png(2, 2);
        $swapped = $this->store->inspect($this->stage($bytes));
        $html = '<html><script>alert(1)</script></html>';
        file_put_contents($swapped->path, str_pad($html, strlen($bytes), ' '));
        self::assertSame($swapped->size, filesize($swapped->path), 'The swap keeps the size.');
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($swapped), 'same-size content swap');
        self::assertFalse(Fs::exists($this->target($swapped->sha256)));

        $arbitrary = $this->stage('synthetic arbitrary content');
        $madeUp = new StoredUpload($arbitrary, str_repeat('a', 64), (int) filesize($arbitrary), MediaType::PNG);
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($madeUp), 'made-up hash');
        $rightHash = new StoredUpload($arbitrary, hash_file('sha256', $arbitrary), (int) filesize($arbitrary), MediaType::PNG);
        self::assertRefused(\RuntimeException::class, fn (): bool => $this->place($rightHash), 'made-up type');
        self::assertTrue(Fs::isFile($arbitrary), 'Every refusal left the staged file.');
        self::assertSame([], $this->store->storedHashes(), 'Nothing was stored.');

        $honest = new StoredUpload($arbitrary, hash_file('sha256', $arbitrary), (int) filesize($arbitrary), MediaType::OTHER);
        self::assertTrue($this->place($honest), 'A hand-built upload whose values match the file is placed.');
        self::assertSame([$honest->sha256], $this->store->storedHashes());
    }

    /** T-STO-11: outside a write, or with another project's database, \LogicException and nothing moved. */
    public function testPlaceAndDeleteNeedThisProjectsWrite(): void
    {
        $upload = $this->store->inspect($this->stage(FileFixture::png()));
        self::assertRefused(\LogicException::class, fn (): bool => $this->store->place($this->db, $upload), 'outside a write');
        self::assertRefused(\LogicException::class, fn () => $this->store->delete($this->db, $upload->sha256), 'delete outside a write');

        mkdir($this->temp->path('projects/p2'));
        $other = Sqlite::create($this->temp->path('projects/p2/project.tiffin'));
        try {
            self::assertRefused(\LogicException::class, fn (): bool => $other->write(fn (Database $db): bool => $this->store->place($db, $upload)), 'another project');
            self::assertRefused(\LogicException::class, fn () => $other->write(fn (Database $db) => $this->store->delete($db, $upload->sha256)), 'delete in another project');
        } finally {
            $other->close();
        }
        $memory = Sqlite::memory();
        self::assertRefused(\LogicException::class, fn (): bool => $memory->write(fn (Database $db): bool => $this->store->place($db, $upload)), 'a memory database');
        self::assertFalse(Fs::exists($this->project . '/attachments'));
        self::assertTrue(Fs::isFile($upload->path));
    }

    /**
     * Crash order (ADR 0018 decision 6): a placement whose transaction then
     * rolls back leaves the file as an orphan the sweep can find, never a
     * row without its file.
     */
    public function testARolledBackPlacementLeavesAnOrphanFile(): void
    {
        $this->db->run('CREATE TABLE "t" ("sha256" TEXT NOT NULL)');
        $upload = $this->store->inspect($this->stage(FileFixture::png(5, 5)));
        try {
            $this->db->write(function (Database $db) use ($upload): void {
                self::assertTrue($this->store->place($db, $upload));
                $db->run('INSERT INTO "t" ("sha256") VALUES (?)', [$upload->sha256]);

                throw new \RuntimeException('Injected failure after the placement.');
            });
            self::fail('The injected failure was lost.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected failure after the placement.', $error->getMessage());
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM "t"'), 'The row was rolled back.');
        self::assertSame([$upload->sha256], $this->store->storedHashes(), 'The orphan file is listed for the sweep.');
        $this->db->write(fn (Database $db) => $this->store->delete($db, $upload->sha256));
        self::assertSame([], $this->store->storedHashes());
    }

    public function testDelete(): void
    {
        $upload = $this->store->inspect($this->stage(FileFixture::gif()));
        $this->place($upload);
        $this->db->write(fn (Database $db) => $this->store->delete($db, $upload->sha256));
        self::assertNull($this->store->path($upload->sha256));
        self::assertFalse(Fs::exists($this->target($upload->sha256)));
        $this->db->write(fn (Database $db) => $this->store->delete($db, $upload->sha256));
        $this->db->write(fn (Database $db) => $this->store->delete($db, str_repeat('0', 64)));

        // Not TiffinDB's: refused, nothing removed.
        $outside = $this->temp->path('outside.bin');
        file_put_contents($outside, 'synthetic');
        $link = str_repeat('a', 64);
        mkdir($this->project . '/attachments/aa', 0750, true);
        symlink($outside, $this->project . '/attachments/aa/' . $link);
        $folder = str_repeat('b', 64);
        mkdir($this->project . '/attachments/bb/' . $folder, 0750, true);
        self::assertRefused(\RuntimeException::class, fn () => $this->db->write(fn (Database $db) => $this->store->delete($db, $link)), 'link');
        self::assertRefused(\RuntimeException::class, fn () => $this->db->write(fn (Database $db) => $this->store->delete($db, $folder)), 'folder');
        self::assertTrue(is_link($this->project . '/attachments/aa/' . $link));
        self::assertDirectoryExists($this->project . '/attachments/bb/' . $folder);
        self::assertFileExists($outside);

        $linkedPrefix = str_repeat('c', 64);
        mkdir($this->temp->path('outside-folder'));
        file_put_contents($this->temp->path('outside-folder/' . $linkedPrefix), 'synthetic');
        symlink($this->temp->path('outside-folder'), $this->project . '/attachments/cc');
        self::assertRefused(\RuntimeException::class, fn () => $this->db->write(fn (Database $db) => $this->store->delete($db, $linkedPrefix)), 'linked prefix folder');
        self::assertFileExists($this->temp->path('outside-folder/' . $linkedPrefix));
    }

    /** T-STO-12 */
    public function testPathsOnlyFromValidHashes(): void
    {
        $valid = str_repeat('d', 64);
        $bad = [
            'upper case' => strtoupper($valid),
            'mixed case' => 'D' . substr($valid, 1),
            '63' => substr($valid, 1),
            '65' => $valid . 'd',
            'dot dot' => '../' . substr($valid, 3),
            'dot dot inside' => 'dd/../' . substr($valid, 6),
            'slash' => 'dd/' . substr($valid, 3),
            'nul' => substr($valid, 1) . "\0",
            'newline' => $valid . "\n",
            'non-hex' => str_repeat('g', 64),
            'empty' => '',
        ];
        $upload = $this->store->inspect($this->stage(FileFixture::png()));
        foreach ($bad as $label => $hash) {
            self::assertRefused(\InvalidArgumentException::class, fn (): ?string => $this->store->path($hash), 'path ' . $label);
            self::assertRefused(\InvalidArgumentException::class, fn () => $this->db->write(fn (Database $db) => $this->store->delete($db, $hash)), 'delete ' . $label);
            self::assertRefused(\InvalidArgumentException::class, static fn (): StoredUpload => new StoredUpload($upload->path, $hash, $upload->size, $upload->mediaType), 'upload ' . $label);
        }
        self::assertFalse(Fs::exists($this->project . '/attachments'));
    }

    /** T-STO-12: path() is null for a link, a folder, a missing file, and a linked folder on the way. */
    public function testPathIsNullForAnythingButAStoredFile(): void
    {
        $outside = $this->temp->path('outside');
        mkdir($outside);
        file_put_contents($outside . '/x', 'synthetic');
        $hashes = ['link' => str_repeat('1', 64), 'folder' => str_repeat('2', 64), 'missing' => str_repeat('3', 64), 'linked prefix' => str_repeat('4', 64), 'file' => str_repeat('5', 64)];
        foreach (['11', '22', '33', '55'] as $prefix) {
            mkdir($this->project . '/attachments/' . $prefix, 0750, true);
        }
        symlink($outside . '/x', $this->project . '/attachments/11/' . $hashes['link']);
        mkdir($this->project . '/attachments/22/' . $hashes['folder']);
        file_put_contents($outside . '/' . $hashes['linked prefix'], 'synthetic');
        symlink($outside, $this->project . '/attachments/44');
        file_put_contents($this->project . '/attachments/55/' . $hashes['file'], 'synthetic');
        foreach (['link', 'folder', 'missing', 'linked prefix'] as $label) {
            self::assertNull($this->store->path($hashes[$label]), $label);
        }
        self::assertSame($this->project . '/attachments/55/' . $hashes['file'], $this->store->path($hashes['file']));

        // A linked attachments folder hides every file.
        rename($this->project . '/attachments', $this->temp->path('moved'));
        symlink($this->temp->path('moved'), $this->project . '/attachments');
        self::assertNull($this->store->path($hashes['file']));
    }

    /** T-STO-13 */
    public function testStoredHashesListsOnlyValidEntries(): void
    {
        $this->captureLog();
        $kept = [];
        foreach (['png', 'gif', 'jpeg'] as $kind) {
            $upload = $this->store->inspect($this->stage(match ($kind) {
                'png' => FileFixture::png(),
                'gif' => FileFixture::gif(),
                default => FileFixture::jpeg(),
            }));
            $this->place($upload);
            $kept[] = $upload->sha256;
        }
        sort($kept);
        $root = $this->project . '/attachments';
        $wrongFolder = str_repeat('e', 64);
        $strays = [
            'notes.txt' => static fn (string $p): mixed => file_put_contents($p, 'synthetic'),
            'zz' => static fn (string $p): mixed => mkdir($p),
            'AB' => static fn (string $p): mixed => mkdir($p),
            'ff' => static fn (string $p): mixed => file_put_contents($p, 'synthetic'),
            'ab' => static fn (string $p): mixed => mkdir($p),
            'ab/' . $wrongFolder => static fn (string $p): mixed => file_put_contents($p, 'synthetic'),
            'ab/notes.txt' => static fn (string $p): mixed => file_put_contents($p, 'synthetic'),
            'ab/' . strtoupper('ab' . str_repeat('f', 62)) => static fn (string $p): mixed => file_put_contents($p, 'synthetic'),
            'ab/ab' . str_repeat('1', 61) => static fn (string $p): mixed => file_put_contents($p, 'synthetic'),
            'ab/ab' . str_repeat('2', 62) => fn (string $p): mixed => symlink($this->temp->path('error.log'), $p),
            'ab/ab' . str_repeat('3', 62) => static fn (string $p): mixed => mkdir($p),
            'cd' => fn (string $p): mixed => symlink($this->temp->path('staging'), $p),
        ];
        foreach ($strays as $name => $make) {
            $make($root . '/' . $name);
        }
        $tree = $this->projectTree();
        self::assertSame($kept, $this->store->storedHashes());
        self::assertSame($tree, $this->projectTree(), 'Strays are skipped and left in place.');
        self::assertSame(1, substr_count($this->log(), 'TiffinDB: the attachments folder holds entries that are not stored files; skipped=11'), $this->log());
        self::assertStringNotContainsString('notes.txt', $this->log(), 'No entry name is logged.');

        // A clean folder logs nothing.
        $clean = TempDir::create();
        try {
            $store = new AttachmentStore($clean->path());
            self::assertSame([], $store->storedHashes());
            mkdir($clean->path('attachments'));
            self::assertSame([], $store->storedHashes());
        } finally {
            $clean->remove();
        }
        self::assertSame(1, substr_count($this->log(), 'TiffinDB:'));
    }

    public function testALinkedAttachmentsFolderIsNotListed(): void
    {
        $this->captureLog();
        $outside = $this->temp->path('outside');
        $hash = str_repeat('a', 64);
        mkdir($outside . '/aa', 0750, true);
        file_put_contents($outside . '/aa/' . $hash, 'synthetic');
        symlink($outside, $this->project . '/attachments');
        self::assertRefused(\RuntimeException::class, fn (): array => $this->store->storedHashes());
        self::assertFileExists($outside . '/aa/' . $hash);
        self::assertSame(1, substr_count($this->log(), 'TiffinDB: the attachments folder could not be listed'));
    }

    /**
     * T-STO-16 (source scan): every path in the store is built by
     * folderPath(), filePath(), or prefixPath(), each of which checks its
     * argument first; the constructor joins only the fixed folder name.
     * No superglobal, no other string joined with a slash.
     */
    public function testPathsAreBuiltOnlyFromValidatedHashes(): void
    {
        $source = RepoFiles::read('src/Core/Storage/AttachmentStore.php');
        $tokens = token_get_all($source);
        $function = null;
        $slashIn = [];
        $depth = 0;
        $functionDepth = null;
        $next = false;
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $next = true;

                continue;
            }
            if ($next && is_array($token) && $token[0] === T_STRING) {
                $function = $token[1];
                $functionDepth = $depth;
                $next = false;

                continue;
            }
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
                if ($functionDepth !== null && $depth === $functionDepth) {
                    $function = null;
                    $functionDepth = null;
                }
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($token[1], '/') && !str_starts_with($token[1], "'/^")) {
                $slashIn[] = (string) $function;
            }
            if (is_array($token) && $token[0] === T_VARIABLE) {
                self::assertNotContains($token[1], ['$_GET', '$_POST', '$_FILES', '$_REQUEST', '$_COOKIE', '$_SERVER', '$GLOBALS'], 'No request data in the store.');
            }
            self::assertFalse(is_array($token) && in_array($token[0], [T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC], true), 'No interpolated strings in the store.');
        }
        self::assertSame(['__construct', '__construct', 'filePath', 'prefixPath'], $slashIn, 'A slash is joined only where a path is built.');
        foreach (['folderPath' => 'self::requireHash($sha256);', 'filePath' => 'self::requireHash($sha256);', 'prefixPath' => 'if (preg_match(self::PREFIX, $prefix) !== 1) {'] as $method => $first) {
            self::assertMatchesRegularExpression('/private function ' . $method . '\(string \$\w+\): string\n    \{\n        ' . preg_quote($first, '/') . '/', $source, $method . ' checks its argument first.');
        }
        self::assertSame('/^[0-9a-f]{64}\z/', AttachmentStore::HASH);
        // Fs calls take only the root, a built folder or target, or the staged path.
        preg_match_all('/Fs::\w+\(([^()]*(?:\([^()]*\))?[^()]*)\)/', $source, $calls);
        $arguments = array_values(array_unique($calls[1]));
        sort($arguments);
        self::assertSame(['$directory', '$folder', '$stagedPath', '$target', '$this->filePath($name)', '$this->prefixPath($prefix)', '$this->root', '$trimmed', '$upload->path', '$upload->path, $target'], $arguments);
    }
}
