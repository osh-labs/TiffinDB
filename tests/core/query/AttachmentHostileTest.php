<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Query\Attachments;
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Storage\AttachmentStore;
use TiffinDB\Core\Storage\MediaType;
use TiffinDB\Core\Storage\StoredUpload;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * M4 security review of WP1 and WP2 (docs/build/reviews/M4-sec-wp1-wp2.md):
 * a hostile corpus run against the attachment store and the records layer.
 * Names, symbolic links in the attachments folders and the staging path,
 * a failed upload, cross-record and trashed-record operations, and two
 * links to one file through emptying the trash. All data is synthetic.
 */
#[CoversClass(Attachments::class)]
#[CoversClass(AttachmentStore::class)]
#[CoversClass(MediaType::class)]
#[CoversClass(Trash::class)]
final class AttachmentHostileTest extends TestCase
{
    use RecordsFixture;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-attachment-hostile-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function trash(): Trash
    {
        return new Trash($this->db, $this->clock);
    }

    public function testNames(): void
    {
        $cases = [
            '../../etc/passwd' => '.._.._etc_passwd',
            "a\r\nSet-Cookie: x=1" => 'aSet-Cookie: x=1',
            "\x1b[31mred" => '[31mred',
            "\u{202E}gnp.exe" => 'gnp.exe',
            "\u{200B}\u{FEFF}\u{2028}" => 'file',
            "\xC3\x28bad" => 'file',
            "\xED\xA0\x80" => 'file',
            '' => 'file',
            '   ' => 'file',
            "C:\\Windows\\x" => 'C:_Windows_x',
        ];
        foreach ($cases as $in => $out) {
            self::assertSame($out, Attachments::cleanName((string) $in), bin2hex((string) $in));
        }
        $long = Attachments::cleanName(str_repeat("\u{00E9}", 5000));
        self::assertSame(255, mb_strlen($long));
        self::assertTrue(mb_check_encoding($long, 'UTF-8'));
        // Combining characters: cut by code point can split a grapheme; still valid UTF-8.
        $comb = Attachments::cleanName(str_repeat("e\u{0301}", 300));
        self::assertTrue(mb_check_encoding($comb, 'UTF-8'));
        // Name with quote and semicolon kept as data (header encoding is the web layer's job).
        self::assertSame('a";b.html', Attachments::cleanName('a";b.html'));
        // NUL
        self::assertSame('ab', Attachments::cleanName("a\0b"));
    }

    public function testSniff(): void
    {
        self::assertSame(MediaType::OTHER, MediaType::sniff('<html><script>alert(1)</script>'));
        self::assertSame(MediaType::OTHER, MediaType::sniff('<?xml version="1.0"?><svg onload="alert(1)"/>'));
        self::assertSame(MediaType::OTHER, MediaType::sniff('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
        self::assertSame(MediaType::OTHER, MediaType::sniff(''));
        self::assertSame(MediaType::OTHER, MediaType::sniff("\xEF\xBB\xBF<html>"));
        self::assertSame(MediaType::GIF, MediaType::sniff('GIF89a<html><script>alert(1)</script>'));
        self::assertSame(MediaType::OTHER, MediaType::sniff('gif89a'));
        self::assertSame(MediaType::OTHER, MediaType::sniff('RIFF1234WEB'));
        self::assertSame(MediaType::WEBP, MediaType::sniff('RIFF1234WEBP'));
        self::assertFalse(MediaType::isInline('image/svg+xml'));
        self::assertFalse(MediaType::isInline('image/png; charset=x'));
    }

    public function testHashPaths(): void
    {
        $store = $this->store();
        foreach (['../' . str_repeat('a', 61), str_repeat('A', 64), str_repeat('a', 64) . "\n", str_repeat('a', 63), "aa/" . str_repeat('a', 61), str_repeat('a', 63) . "\0"] as $bad) {
            try {
                $store->path($bad);
                self::fail('accepted ' . bin2hex($bad));
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new StoredUpload('/etc/passwd', '../' . str_repeat('a', 61), 1, MediaType::OTHER);
    }

    public function testSymlinkedPrefixFolderNotFollowed(): void
    {
        // The sweep reports the linked folder as a stray entry (one log line, no path).
        $this->expectErrorLog();
        $content = 'hello symlink';
        $hash = hash('sha256', $content);
        $outside = $this->temp->path('outside');
        mkdir($outside);
        file_put_contents($outside . '/' . $hash, $content);
        mkdir($this->folder . '/attachments', 0750);
        symlink($outside, $this->folder . '/attachments/' . substr($hash, 0, 2));
        self::assertNull($this->store()->path($hash));
        $item = $this->item(['name' => 'X']);
        try {
            $this->attach($item, $content);
            self::fail('placed through a symlinked folder');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachments"'));
        // delete and sweep leave the outside file
        $this->db->write(fn ($db) => (function () use ($db, $hash): void {
            try {
                $this->store()->delete($db, $hash);
            } catch (\RuntimeException) {
            }
        })());
        $this->attachments()->sweep();
        self::assertFileExists($outside . '/' . $hash);
    }

    public function testSymlinkedRootNotFollowed(): void
    {
        $outside = $this->temp->path('outside2');
        mkdir($outside);
        symlink($outside, $this->folder . '/attachments');
        $item = $this->item(['name' => 'X']);
        try {
            $this->attach($item, 'abc');
            self::fail('placed through symlinked root');
        } catch (\RuntimeException) {
        }
        self::assertSame([], scandir($outside) === false ? [] : array_values(array_diff(scandir($outside), ['.', '..'])));
    }

    public function testSymlinkStagedRefused(): void
    {
        $target = $this->temp->path('secret.txt');
        file_put_contents($target, 'secret');
        $link = $this->temp->path('link.bin');
        symlink($target, $link);
        $this->expectException(\RuntimeException::class);
        $this->store()->inspect($link);
    }

    public function testFailedUploadLeavesNoRowAndOrphanIsSwept(): void
    {
        $item = $this->item(['name' => 'X']);
        $this->db->run('CREATE TRIGGER "probe_fail" BEFORE INSERT ON "_tiffin_attachment_links" BEGIN SELECT RAISE(ABORT, \'probe\'); END');
        $content = 'fail-content';
        try {
            $this->attach($item, $content);
            self::fail('no failure');
        } catch (\Throwable) {
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachments"'));
        $hash = hash('sha256', $content);
        self::assertNotNull($this->store()->path($hash), 'orphan left');
        $this->db->run('DROP TRIGGER "probe_fail"');
        self::assertSame(1, $this->attachments()->sweep());
        self::assertNull($this->store()->path($hash));
    }

    public function testAuthorizationInCore(): void
    {
        $a = $this->item(['name' => 'A']);
        $b = $this->item(['name' => 'B']);
        $att = $this->attach($a, 'shared-one');
        // replaceId of another record
        try {
            $this->attachments()->add($this->f['files']->key, $b, $this->stage('new'), 'n', self::USER_KEY, $att);
            self::fail('replace across records');
        } catch (NotFound) {
        }
        // remove via other record
        try {
            $this->attachments()->remove($this->f['files']->key, $b, $att, self::USER_KEY);
            self::fail('remove across records');
        } catch (NotFound) {
        }
        // relation field as attachment field
        try {
            $this->attachments()->add($this->f['room']->key, $a, $this->stage('z'), 'n', self::USER_KEY, null);
            self::fail('relation as attachment');
        } catch (NotFound) {
        }
        // trashed record
        $this->trashRecord($this->items, $a);
        try {
            $this->attachments()->add($this->f['files']->key, $a, $this->stage('y'), 'n', self::USER_KEY, null);
            self::fail('add to trashed');
        } catch (NotFound) {
        }
        try {
            $this->attachments()->remove($this->f['files']->key, $a, $att, self::USER_KEY);
            self::fail('remove from trashed');
        } catch (NotFound) {
        }
        self::assertFalse($this->attachments()->isServable($att));
        // Records links to trashed / missing targets
        $room = $this->room('R');
        $this->trashRecord($this->rooms, $room);
        try {
            $this->records()->addLink($this->f['stored']->key, $b, $room, self::USER_KEY);
            self::fail('link to trashed');
        } catch (ValidationFailed) {
        }
        try {
            $this->records()->addLink($this->f['stored']->key, $b, 999999, self::USER_KEY);
            self::fail('link to absent');
        } catch (ValidationFailed) {
        }
        try {
            $this->records()->addLink($this->f['stored']->key, $a, $this->room('S'), self::USER_KEY);
            self::fail('link from trashed');
        } catch (NotFound) {
        }
        try {
            $this->records()->addLink($this->f['files']->key, $b, 1, self::USER_KEY);
            self::fail('attachment field as relation');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\Error::class, $e);
        }
    }

    public function testTwoLinksOneRowAndTrashEmpty(): void
    {
        $a = $this->item(['name' => 'A']);
        $b = $this->item(['name' => 'B']);
        $c = $this->item(['name' => 'C']);
        $att = $this->attach($a, 'shared-content');
        $hash = hash('sha256', 'shared-content');
        // second link to the same row (as an archive may hold)
        $this->db->run('INSERT INTO "_tiffin_attachment_links" ("field_key","record_id","attachment_id","position") VALUES (?,?,?,1)', [$this->f['files']->key, $b, $att]);
        // a third record has its own row with the same content
        $this->attach($c, 'shared-content');
        $removed = $this->attachments()->remove($this->f['files']->key, $a, $att, self::USER_KEY);
        self::assertSame([], $removed->freedHashes);
        self::assertNotNull($this->store()->path($hash));
        // trash b and empty: row freed, but file kept for c
        $this->trashRecord($this->items, $b);
        $fields = [$this->items->key => [$this->f['files']], $this->rooms->key => []];
        $impact = $this->trash()->impact([$this->items, $this->rooms], $fields);
        self::assertSame(0, $impact->files);
        $emptied = $this->trash()->empty([$this->items, $this->rooms], $fields, self::USER_KEY);
        self::assertSame([$hash], $emptied->freedHashes);
        self::assertSame(0, $this->attachments()->deleteFiles($emptied->freedHashes));
        self::assertNotNull($this->store()->path($hash));
        self::assertSame(0, $this->attachments()->sweep());
        self::assertNotNull($this->store()->path($hash));
        // c's file still served
        $cRow = (int) $this->db->value('SELECT "attachment_id" FROM "_tiffin_attachment_links" WHERE "record_id" = ?', [$c]);
        self::assertTrue($this->attachments()->isServable($cRow));
    }

    public function testSweepKeepsFilesOfTrashedRecords(): void
    {
        $a = $this->item(['name' => 'A']);
        $this->attach($a, 'trashed-content');
        $this->trashRecord($this->items, $a);
        self::assertSame(0, $this->attachments()->sweep());
        self::assertNotNull($this->store()->path(hash('sha256', 'trashed-content')));
    }

    public function testSwapToSymlinkAfterInspect(): void
    {
        $item = $this->item(['name' => 'X']);
        $up = $this->stage('abcdef');
        $secret = $this->temp->path('secret2');
        file_put_contents($secret, 'zzzzzz');
        unlink($up->path);
        symlink($secret, $up->path);
        try {
            $this->attachments()->add($this->f['files']->key, $item, $up, 'x', self::USER_KEY, null);
            self::fail('symlink placed');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachments"'));
    }
}
