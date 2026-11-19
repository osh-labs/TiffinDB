<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Archive\ArchiveReader;
use TiffinDB\Core\Io\Archive\ArchiveRefused;
use TiffinDB\Core\Io\Archive\ZipCheck;
use TiffinDB\Core\Io\IoLimits;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * T-ARC-3: the hostile zip corpus. Each archive is written byte by byte
 * (ZipBytes) around a valid manifest and project file, then broken in one
 * way: traversal and foreign names, a NUL byte, a backslash, a duplicate,
 * an encrypted entry, bzip2, a size header smaller than the data, too many
 * entries, a symbolic link, missing entries, a decompression bomb, sizes
 * over the caps and over the free space. Each is refused with its reason
 * before anything outside the job folder is touched, and nothing is
 * extracted for a refused name.
 */
#[CoversClass(ZipCheck::class)]
#[CoversClass(ArchiveReader::class)]
final class ZipCorpusTest extends TestCase
{
    use ArchiveFixture;

    private string $manifest = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-archive-zip-corpus-test-1');
        $this->buildSource();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->export(), \ZipArchive::RDONLY));
        $this->manifest = (string) $zip->getFromName(ZipCheck::MANIFEST);
        $this->project = (string) $zip->getFromName(ZipCheck::PROJECT);
        $zip->close();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    /** @return iterable<string, array{string}> */
    public static function foreignNames(): iterable
    {
        $sha = hash('sha256', 'synthetic');
        foreach ([
            'parent folder' => '../x',
            'absolute' => '/x',
            'absolute project' => '/project.tiffin',
            'attachment traversal' => 'attachments/../../x',
            'attachment traversal with hash' => 'attachments/' . substr($sha, 0, 2) . '/../../' . $sha,
            'backslashes' => 'attachments\\' . substr($sha, 0, 2) . '\\' . $sha,
            'NUL byte' => "project.tiffin\0.txt",
            'upper case' => 'Project.tiffin',
            'upper-case hash' => 'attachments/' . strtoupper(substr($sha, 0, 2)) . '/' . strtoupper($sha),
            'folder differs from hash' => 'attachments/' . (substr($sha, 0, 2) === 'aa' ? 'bb' : 'aa') . '/' . $sha,
            'short hash' => 'attachments/' . substr($sha, 0, 2) . '/' . substr($sha, 0, 63),
            'extra entry' => 'notes.txt',
            'wal file' => 'project.tiffin-wal',
            'snapshot folder' => 'snapshots/',
            'snapshot' => 'snapshots/20261112T031500Z-manual-k3m7.tiffin',
            'trailing space' => 'project.tiffin ',
            'dot segment' => './project.tiffin',
            'empty name' => '',
        ] as $label => $name) {
            yield $label => [$name];
        }
    }

    #[DataProvider('foreignNames')]
    public function testForeignNamesAreRefused(string $name): void
    {
        $zip = $this->valid()->add($name, 'synthetic')->write($this->jobFolder() . '/upload.zip');
        $job = $this->temp->path('jobs/job' . ($this->jobs + 1));
        self::assertSame(ArchiveRefused::ENTRY_NAME, $this->refusal($zip));
        self::assertSame([], array_values(array_diff(scandir($job) ?: [], ['.', '..'])), 'Nothing was extracted.');
        self::assertFileDoesNotExist($this->temp->path('x'));
        self::assertFileDoesNotExist($this->temp->path('jobs/x'));
    }

    public function testDuplicateNameIsRefused(): void
    {
        $zip = $this->valid()->add(ZipCheck::PROJECT, 'synthetic second copy')->write($this->jobFolder() . '/upload.zip');
        self::assertSame(ArchiveRefused::DUPLICATE, $this->refusal($zip));
    }

    public function testEncryptedEntryIsRefused(): void
    {
        $zip = (new ZipBytes())
            ->add(ZipCheck::MANIFEST, $this->manifest)
            ->add(ZipCheck::PROJECT, $this->project, ['flags' => 1])
            ->write($this->jobFolder() . '/upload.zip');
        self::assertSame(ArchiveRefused::ENCRYPTED, $this->refusal($zip));
    }

    public function testOtherCompressionMethodsAreRefused(): void
    {
        foreach ([12, 14, 93] as $method) {
            $zip = (new ZipBytes())
                ->add(ZipCheck::MANIFEST, $this->manifest)
                ->add(ZipCheck::PROJECT, $this->project, ['method' => $method])
                ->write($this->jobFolder() . '/upload.zip');
            self::assertSame(ArchiveRefused::COMPRESSION, $this->refusal($zip), 'method ' . $method);
        }
        // AES (99) without its extra field: libzip itself refuses to open the archive.
        $zip = (new ZipBytes())
            ->add(ZipCheck::MANIFEST, $this->manifest)
            ->add(ZipCheck::PROJECT, $this->project, ['method' => 99])
            ->write($this->jobFolder() . '/upload.zip');
        self::assertContains($this->refusal($zip), [ArchiveRefused::COMPRESSION, ArchiveRefused::MISSING_ENTRY]);
    }

    public function testSizeHeaderSmallerThanTheDataIsRefusedWhileStreaming(): void
    {
        foreach ([0, 8] as $method) {
            $zip = (new ZipBytes())
                ->add(ZipCheck::MANIFEST, $this->manifest)
                ->add(ZipCheck::PROJECT, $this->project, ['size' => 1024, 'method' => $method])
                ->write($this->jobFolder() . '/upload.zip');
            $job = $this->temp->path('jobs/job' . ($this->jobs + 1));
            self::assertSame(ArchiveRefused::TOO_LARGE, $this->refusal($zip));
            self::assertLessThanOrEqual(1024 + 65536, filesize($job . '/' . ArchiveReader::PROJECT_FILE), 'The copy stops near the declared size.');
        }
        $zip = (new ZipBytes())
            ->add(ZipCheck::MANIFEST, $this->manifest, ['size' => 10])
            ->add(ZipCheck::PROJECT, $this->project)
            ->write($this->jobFolder() . '/upload.zip');
        self::assertSame(ArchiveRefused::TOO_LARGE, $this->refusal($zip));
    }

    public function testEntryCountOverTheCapIsRefused(): void
    {
        self::assertSame(IoLimits::ARCHIVE_MAX_ENTRIES, 100000);
        $bytes = $this->valid();
        for ($i = 0; $i < 3; $i++) {
            $sha = hash('sha256', 'synthetic ' . $i);
            $bytes->add('attachments/' . substr($sha, 0, 2) . '/' . $sha, 'synthetic ' . $i);
        }
        $zip = $bytes->write($this->jobFolder() . '/upload.zip');
        self::assertSame(ArchiveRefused::TOO_LARGE, $this->refusal($zip, new ZipCheck(4)));
        $this->check($zip, new ZipCheck(5));
    }

    public function testSymbolicLinksAreRefused(): void
    {
        $sha = hash('sha256', 'synthetic');
        $cases = [
            [ZipCheck::PROJECT, $this->project, 0120777],
            ['attachments/' . substr($sha, 0, 2) . '/' . $sha, '/etc/passwd', 0120777],
            ['attachments/', '', 0120777],
            ['attachments/' . substr($sha, 0, 2) . '/' . $sha, 'synthetic', 0020644],
            [ZipCheck::PROJECT, $this->project, 0040755],
        ];
        foreach ($cases as [$name, $content, $mode]) {
            $bytes = (new ZipBytes())->add(ZipCheck::MANIFEST, $this->manifest);
            if ($name !== ZipCheck::PROJECT) {
                $bytes->add(ZipCheck::PROJECT, $this->project);
            }
            $zip = $bytes->add($name, $content, ['mode' => $mode, 'method' => 0])->write($this->jobFolder() . '/upload.zip');
            self::assertSame(ArchiveRefused::ENTRY_NAME, $this->refusal($zip), $name . ' ' . decoct($mode));
        }
    }

    public function testLinkModeIsRefusedForEveryCreatorSystem(): void
    {
        foreach ([0, 1, 6, 10, 11, 19, 30] as $system) {
            $zip = (new ZipBytes())
                ->add(ZipCheck::MANIFEST, $this->manifest, ['system' => $system])
                ->add(ZipCheck::PROJECT, $this->project, ['system' => $system, 'mode' => 0120777, 'method' => 0])
                ->write($this->jobFolder() . '/upload.zip');
            self::assertSame(ArchiveRefused::ENTRY_NAME, $this->refusal($zip), 'system ' . $system);
        }
        $zip = (new ZipBytes())
            ->add(ZipCheck::MANIFEST, $this->manifest, ['system' => 0, 'mode' => 0])
            ->add(ZipCheck::PROJECT, $this->project, ['system' => 0, 'mode' => 0])
            ->write($this->jobFolder() . '/upload.zip');
        self::assertSame(strlen($this->project), $this->check($zip)[0]->manifest->projectSize, 'An MS-DOS archive without mode bits is read.');
    }

    public function testMissingEntriesAndNonArchivesAreRefused(): void
    {
        $cases = [
            'no manifest' => (new ZipBytes())->add(ZipCheck::PROJECT, $this->project)->bytes(),
            'no project' => (new ZipBytes())->add(ZipCheck::MANIFEST, $this->manifest)->bytes(),
            'empty zip' => (new ZipBytes())->bytes(),
            'not a zip' => 'synthetic text that is not a zip file',
            'empty file' => '',
            'truncated zip' => substr($this->valid()->bytes(), 0, 200),
        ];
        foreach ($cases as $label => $bytes) {
            $path = $this->jobFolder() . '/upload.zip';
            file_put_contents($path, $bytes);
            self::assertSame(ArchiveRefused::MISSING_ENTRY, $this->refusal($path), $label);
        }
    }

    public function testDecompressionBombIsRefusedBeforeItExpands(): void
    {
        $bomb = str_repeat("\0", 20 * 1048576);
        $zip = $this->valid()->add('attachments/' . substr(hash('sha256', $bomb), 0, 2) . '/' . hash('sha256', $bomb), $bomb)->write($this->jobFolder() . '/upload.zip');
        unset($bomb);
        self::assertLessThan(1048576, filesize($zip));
        $job = $this->temp->path('jobs/job' . ($this->jobs + 1));
        self::assertSame(ArchiveRefused::TOO_LARGE, $this->refusal($zip));
        self::assertSame([], array_values(array_diff(scandir($job) ?: [], ['.', '..'])));
    }

    public function testDeclaredSizesOverTheCapsAreRefused(): void
    {
        $zip = $this->valid()->write($this->jobFolder() . '/upload.zip');
        self::assertSame(ArchiveRefused::TOO_LARGE, $this->refusal($zip, new ZipCheck(100, strlen($this->project) - 1)));
        self::assertSame(ArchiveRefused::TOO_LARGE, $this->refusal($zip, new ZipCheck(100, strlen($this->project), strlen($this->project))));
        self::assertSame(ArchiveRefused::TOO_LARGE, $this->refusal($zip, null, IoLimits::DISK_RESERVE_BYTES + 100));
        $huge = (new ZipBytes())
            ->add(ZipCheck::MANIFEST, $this->manifest)
            ->add(ZipCheck::PROJECT, $this->project, ['size' => IoLimits::ARCHIVE_MAX_PROJECT_BYTES + 1])
            ->write($this->jobFolder() . '/upload.zip');
        self::assertSame(ArchiveRefused::TOO_LARGE, $this->refusal($huge));
        $bigManifest = (new ZipBytes())
            ->add(ZipCheck::MANIFEST, str_pad($this->manifest, 16385, ' '))
            ->add(ZipCheck::PROJECT, $this->project)
            ->write($this->jobFolder() . '/upload.zip');
        self::assertSame(ArchiveRefused::TOO_LARGE, $this->refusal($bigManifest));
        self::assertSame($this->check($zip, null, null)[0]->manifest->projectSize, strlen($this->project));
    }

    public function testFolderEntriesAreAcceptedAndIgnored(): void
    {
        $sha1 = hash('sha256', self::FILE_ONE);
        $zip = (new ZipBytes())
            ->add('attachments/', '', ['mode' => 0040755, 'method' => 0])
            ->add(ZipCheck::MANIFEST, $this->manifest)
            ->add(ZipCheck::PROJECT, $this->project)
            ->add('attachments/' . substr($sha1, 0, 2) . '/', '', ['mode' => 0040755, 'method' => 0])
            ->add('attachments/' . substr($sha1, 0, 2) . '/' . $sha1, self::FILE_ONE, ['method' => 0])
            ->write($this->jobFolder() . '/upload.zip');
        [$report, , $folder] = $this->import($zip);
        self::assertSame(1, $report->filesMissing);
        self::assertSame(self::FILE_ONE, file_get_contents($folder . '/attachments/' . substr($sha1, 0, 2) . '/' . $sha1));

        $withContent = $this->valid()->add('attachments/', 'synthetic', ['method' => 0])->write($this->jobFolder() . '/upload.zip');
        self::assertSame(ArchiveRefused::ENTRY_NAME, $this->refusal($withContent));
    }

    /** The manifest and project entries of the exported archive, as ZipBytes. */
    private function valid(): ZipBytes
    {
        return (new ZipBytes())->add(ZipCheck::MANIFEST, $this->manifest)->add(ZipCheck::PROJECT, $this->project);
    }
}
