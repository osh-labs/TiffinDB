<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Install\RecoveryCode;
use TiffinDB\Core\Install\SetupCode;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** The recovery code file (M7 spec section 9.1): T-REC-1, T-REC-9, T-REC-14 at the file level. */
#[CoversClass(RecoveryCode::class)]
final class RecoveryCodeTest extends TestCase
{
    private const START = '2026-03-02T10:00:00Z';

    private TempDir $temp;
    private string $data;
    private string $file;
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->data = $this->temp->path('data');
        mkdir($this->data, 0750);
        $this->file = $this->data . '/' . RecoveryCode::FILE;
    }

    /** PHPUnit captures error_log() from the start of the test body on; this sends it to a file instead. */
    private function captureLog(): void
    {
        $this->previousLog = ini_set('error_log', $this->temp->path('error.log'));
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== false) {
            ini_set('error_log', $this->previousLog);
        }
        $this->temp->remove();
    }

    private static function at(int $seconds = 0): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::START))->modify(sprintf('%+d seconds', $seconds));
    }

    private function log(): string
    {
        $path = $this->temp->path('error.log');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public function testStartWritesTheExactTextWithAFreshCode(): void
    {
        self::assertTrue(RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-1'), self::at()));
        $code = SetupCode::generate(new SeededRandom('recovery-code-seed-1'));
        self::assertSame(
            "TiffinDB Admin recovery code\n{$code}\nCreated 2026-03-02T10:00:00Z\n"
            . "This code works once, for 30 minutes. TiffinDB deletes this file when it is used.\n",
            file_get_contents($this->file),
        );
        self::assertSame(0640, fileperms($this->file) & 0777);
        self::assertSame($code, RecoveryCode::current($this->data, self::at(1)));
    }

    public function testAValidCodeIsKeptAndAnExpiredOneReplaced(): void
    {
        self::assertTrue(RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-1'), self::at()));
        $first = RecoveryCode::current($this->data, self::at());
        self::assertFalse(RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-2'), self::at(1799)));
        self::assertSame($first, RecoveryCode::current($this->data, self::at(1799)));

        self::assertNull(RecoveryCode::current($this->data, self::at(1800)), 'Expired after exactly 30 minutes.');
        self::assertTrue(RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-2'), self::at(1800)));
        $second = RecoveryCode::current($this->data, self::at(1800));
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
    }

    public function testACodeFromTheFutureIsNotValid(): void
    {
        RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-1'), self::at(600));
        self::assertNull(RecoveryCode::current($this->data, self::at()));
    }

    public function testNoFileMeansNoCode(): void
    {
        self::assertNull(RecoveryCode::current($this->data, self::at()));
        RecoveryCode::remove($this->data);
        self::assertFileDoesNotExist($this->file);
    }

    public function testRemoveDeletesTheFile(): void
    {
        RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-1'), self::at());
        RecoveryCode::remove($this->data);
        self::assertFileDoesNotExist($this->file);
        self::assertNull(RecoveryCode::current($this->data, self::at()));
    }

    /** @return iterable<string, array{0: string}> */
    public static function badFiles(): iterable
    {
        $good = RecoveryCode::text('ABCD-EFGH-JKMN-PQRS', self::at());
        yield 'too large' => [$good . str_repeat('x', RecoveryCode::MAX_FILE_BYTES)];
        yield 'not UTF-8' => [str_replace('Admin', "Adm\xffn", $good)];
        yield 'empty' => [''];
        yield 'code alone' => ["ABCD-EFGH-JKMN-PQRS\n"];
        yield 'lower-case code' => [RecoveryCode::text('abcd-efgh-jkmn-pqrs', self::at())];
        yield 'letters outside the alphabet' => [RecoveryCode::text('ABCD-EFGH-IJKL-OPQR', self::at())];
        yield 'missing last line' => [substr($good, 0, (int) strrpos(rtrim($good), "\n") + 1)];
        yield 'extra line' => [$good . "more\n"];
        yield 'bad time' => [str_replace('2026-03-02T10:00:00Z', '2026-13-02T10:00:00Z', $good)];
        yield 'carriage returns' => [str_replace("\n", "\r\n", $good)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function testAMalformedFileGivesNoCodeAndStartReplacesIt(string $contents): void
    {
        file_put_contents($this->file, $contents);
        self::assertNull(RecoveryCode::current($this->data, self::at()));
        self::assertTrue(RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-1'), self::at()));
        self::assertNotNull(RecoveryCode::current($this->data, self::at()));
    }

    public function testTheFileAtTheLimitIsRead(): void
    {
        RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-1'), self::at());
        self::assertLessThanOrEqual(RecoveryCode::MAX_FILE_BYTES, filesize($this->file));
    }

    public function testALinkAtThePathIsNeverFollowed(): void
    {
        $this->captureLog();
        // A valid code file elsewhere, linked in: not read, not replaced, its target untouched.
        $target = $this->temp->path('elsewhere.txt');
        $text = RecoveryCode::text('ABCD-EFGH-JKMN-PQRS', self::at());
        file_put_contents($target, $text);
        symlink($target, $this->file);

        self::assertNull(RecoveryCode::current($this->data, self::at()));
        self::assertFalse(RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-1'), self::at()));
        self::assertTrue(is_link($this->file));
        self::assertSame($text, file_get_contents($target));
        self::assertStringContainsString('symbolic link', $this->log());
        self::assertStringNotContainsString($this->data, $this->log(), 'No path is logged.');
    }

    public function testADanglingLinkIsRefusedAndNothingIsWrittenThroughIt(): void
    {
        $this->captureLog();
        $target = $this->temp->path('planted.txt');
        symlink($target, $this->file);
        self::assertFalse(RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-1'), self::at()));
        self::assertFileDoesNotExist($target);
    }

    public function testADirectoryAtThePathIsRefusedWithoutAnError(): void
    {
        $this->captureLog();
        mkdir($this->file, 0750);
        self::assertNull(RecoveryCode::current($this->data, self::at()));
        self::assertFalse(RecoveryCode::start($this->data, new SeededRandom('recovery-code-seed-1'), self::at()));
        self::assertDirectoryExists($this->file);
        self::assertStringNotContainsString($this->data, $this->log());
    }

    public function testAMissingDataFolderIsRefusedWithoutAnError(): void
    {
        $this->captureLog();
        self::assertFalse(RecoveryCode::start($this->data . '/missing', new SeededRandom('recovery-code-seed-1'), self::at()));
        self::assertStringNotContainsString($this->data, $this->log());
    }
}
