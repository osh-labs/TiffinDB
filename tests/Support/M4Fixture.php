<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use TiffinDB\Core\App\Operations;

/**
 * The M4 synthetic data set (M4 spec part 1 section 12): the M2 data set
 * (M2Fixture, "Home Inventory") with real attachment files and saved
 * collections, built through the core operations.
 *
 * Files. M2Fixture inserts its attachment rows with a dummy hash and no
 * file; M4Fixture removes those rows and links (plain SQL, before anything
 * else touches them) and adds every one again through
 * AttachmentOperations::add as the Member, at the same field, record, and
 * position, so each row has its file under `attachments/<2>/<64>`. Each
 * receipt is a small PDF that names its file (FileFixture::pdf() with the
 * name in a comment line, so every receipt has its own bytes); Stand
 * mixer's photo is `stand-mixer.png`, a 96 by 64 PNG. Then the four image
 * types and the active-content cases are added: a WebP photo on
 * "Espresso machine", a GIF on "Robot vacuum", a JPEG on "Cordless drill",
 * and on "Sewing machine" an HTML file (`manual.html`) and an SVG with a
 * script (`diagram.svg`), both stored as application/octet-stream; and
 * "Dutch oven" gets `receipt-copy.pdf`, the same bytes as Stand mixer's
 * receipt, so two rows share one stored file. The Member adds the files,
 * so "Last changed today by Example Member" on Stand mixer holds.
 *
 * Collections (as the Admin, through CollectionOperations::save), on Items:
 * - "Under warranty" (all): Under warranty is ticked; Purchased is after
 *   2024-01-01 (the two rules of docs/design/model-ui/collection.html, the
 *   second as "is after", M4 D-40); sorted by Purchased, newest first.
 * - "Kitchen or study" (any): Room links to Kitchen; Room links to Study.
 * - "Retired items" (all): Retired is ticked, where Retired is a checkbox
 *   field added for the rule and then deleted, so the rule is broken
 *   (collection.broken.field); Value is more than 300.00.
 *
 * All names and values are synthetic.
 */
final class M4Fixture
{
    /** The collections, by name, in creation order. */
    public const COLLECTIONS = ['Under warranty', 'Kitchen or study', 'Retired items'];

    /**
     * @param array<string, int> $attachmentIds File name => attachment id (the last row of that name).
     * @param array<string, string> $files File name => bytes.
     * @param array<string, string> $collections Collection name => key.
     */
    private function __construct(
        public readonly M2Fixture $m2,
        public readonly array $attachmentIds,
        public readonly array $files,
        public readonly array $collections,
    ) {
    }

    /** Builds the data set in $core with $ops (default: new operations of $core). */
    public static function build(CoreFixture $core, ?Operations $ops = null): self
    {
        $ops ??= $core->operations();
        $m2 = M2Fixture::build($core, $ops);
        $admin = $core->actor('admin');
        $member = $core->actor('member');
        $p = $m2->project;
        $f = $m2->itemFields;
        $db = $core->projectDb($p);

        // The M2 rows (no files), in field, record, position order.
        $rows = $db->all(
            'SELECT "l"."field_key", "l"."record_id", "l"."position", "a"."original_name" FROM "_tiffin_attachment_links" AS "l"'
            . ' JOIN "_tiffin_attachments" AS "a" ON "a"."id" = "l"."attachment_id" ORDER BY "l"."record_id", "l"."field_key", "l"."position"',
        );
        $db->write(static function () use ($db): void {
            $db->run('DELETE FROM "_tiffin_attachment_links"');
            $db->run('DELETE FROM "_tiffin_attachments"');
        });

        $files = [];
        $ids = [];
        $add = static function (string $field, int $record, string $name, string $bytes) use ($core, $ops, $member, $p, $m2, &$files, &$ids): void {
            $added = $ops->attachments()->add($member, $p, $m2->items, (string) $record, $field, $core->upload($bytes), $name, null);
            $files[$name] = $bytes;
            $ids[$name] = $added->id;
        };
        foreach ($rows as $row) {
            $name = (string) $row['original_name'];
            if ($name === 'stand-mixer.jpg') {
                $add((string) $row['field_key'], (int) $row['record_id'], 'stand-mixer.png', FileFixture::png(96, 64, 'stand-mixer'));
            } else {
                $add((string) $row['field_key'], (int) $row['record_id'], $name, self::receipt($name));
            }
        }
        $item = static fn (string $name): int => $m2->itemIds[$name];
        $add($f['Photo'], $item('Espresso machine'), 'espresso-machine.webp', FileFixture::webp('espresso-machine'));
        $add($f['Photo'], $item('Robot vacuum'), 'robot-vacuum.gif', FileFixture::gif('robot-vacuum'));
        $add($f['Photo'], $item('Cordless drill'), 'cordless-drill.jpg', FileFixture::jpeg('cordless-drill'));
        $add($f['Receipt'], $item('Sewing machine'), 'manual.html', self::HTML);
        $add($f['Receipt'], $item('Sewing machine'), 'diagram.svg', self::SVG);
        $add($f['Receipt'], $item('Dutch oven'), 'receipt-copy.pdf', self::receipt('receipt-stand-mixer.pdf'));

        // Collections.
        $collections = $ops->collections();
        $keys = [];
        $keys['Under warranty'] = $collections->save($admin, $p, $m2->items, null, [
            'name' => 'Under warranty',
            'match' => 'all',
            'sort' => $f['Purchased'] . '.desc',
            'rules' => [
                ['field' => $f['Under warranty'], 'test' => 'ticked'],
                ['field' => $f['Purchased'], 'test' => 'after', 'value' => '2024-01-01'],
            ],
        ]);
        $keys['Kitchen or study'] = $collections->save($admin, $p, $m2->items, null, [
            'name' => 'Kitchen or study',
            'match' => 'any',
            'rules' => [
                ['field' => $f['Room'], 'test' => 'links-to', 'record' => (string) $m2->roomIds['Kitchen']],
                ['field' => $f['Room'], 'test' => 'links-to', 'record' => (string) $m2->roomIds['Study']],
            ],
        ]);
        $schema = $ops->schema();
        $retired = $schema->addField($admin, $p, $m2->items, 'Retired', 'checkbox', [], false, '')->key;
        $keys['Retired items'] = $collections->save($admin, $p, $m2->items, null, [
            'name' => 'Retired items',
            'match' => 'all',
            'rules' => [
                ['field' => $retired, 'test' => 'ticked'],
                ['field' => $f['Value'], 'test' => 'more', 'value' => '300.00'],
            ],
        ]);
        $schema->deleteField($admin, $p, $m2->items, $retired);

        return new self($m2, $ids, $files, $keys);
    }

    /** The table key of the version 3 file's Items table (docs/formats/tiffin.md section 5). */
    public const V3_ITEMS = 'h3kq7vn2xbma';

    /** Field keys of the version 3 file: Name (title, text), Photo (images only), Receipt (any file). */
    public const V3_FIELDS = ['Name' => 'm4pz7k2qsw3n', 'Photo' => 'p5wq2xk7mzna', 'Receipt' => 'r6nb3vk2qxza'];

    /**
     * Writes a version 3 project file at $file, as M3 wrote it: the first
     * three SQL blocks of docs/formats/tiffin.md section 4 (version 1, 2,
     * and 3 tables, read from the document), the user-table statements of
     * section 5, and `user_version` 3; with Items (Name, Photo, Receipt),
     * live records "Toaster" (a PNG photo and a PDF receipt) and "Mower"
     * (a JPEG photo), and "Old kettle" in the trash (a GIF photo). The
     * files are written under `<folder of $file>/attachments/<2>/<64>`.
     *
     * @return array<string, array{id: int, bytes: string, type: string, record: int}> file name => its row
     */
    public static function buildVersionThree(string $file, string $projectKey, string $docRoot): array
    {
        $time = '2026-11-03T09:00:00Z';
        $doc = (string) file_get_contents($docRoot . '/docs/formats/tiffin.md');
        $section = static function (string $from, string $to) use ($doc): array {
            $start = (int) strpos($doc, $from);
            $end = (int) strpos($doc, $to, $start);
            preg_match_all('/```sql\n(.*?)```/s', substr($doc, $start, $end - $start), $blocks);

            return $blocks[1];
        };
        $statements = static fn (string $sql): array => array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== ''));
        $meta = $section('## 4. Metadata tables', '## 5. User tables');
        if (count($meta) !== 4) {
            throw new \LogicException('docs/formats/tiffin.md section 4 has four SQL blocks.');
        }
        $ddl = [...$statements($meta[0]), ...$statements($meta[1]), ...$statements($meta[2])];
        $userDdl = $statements($section('## 5. User tables', '## 6. Field types')[0]);

        $pdo = new \PDO('sqlite:' . $file);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA application_id = 1413891138');
        $pdo->exec('PRAGMA user_version = 3');
        $pdo->beginTransaction();
        foreach ([...$ddl, ...$userDdl] as $statement) {
            $pdo->exec($statement);
        }
        $insert = static function (string $sql, array $params) use ($pdo): void {
            $pdo->prepare($sql)->execute($params);
        };
        $values = ['format' => 'tiffindb-project', 'format_version' => '3', 'project_key' => $projectKey, 'project_name' => 'Home Inventory',
            'created_at' => $time, 'created_by' => 'm2kq7vn3xbpa', 'created_with' => '0.1.0-dev', 'updated_at' => $time, 'updated_by' => 'm2kq7vn3xbpa'];
        foreach ($values as $key => $value) {
            $insert('INSERT INTO "_tiffin_meta" ("key", "value") VALUES (?, ?)', [$key, $value]);
        }
        $table = 't_' . self::V3_ITEMS;
        $name = self::V3_FIELDS['Name'];
        $pdo->exec('ALTER TABLE "' . $table . '" ADD COLUMN "f_' . $name . '" TEXT');
        $insert('INSERT INTO "_tiffin_tables" VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [self::V3_ITEMS, $table, 'Items', 'item', 1, $name, $time, $time]);
        $fields = [
            [$name, 'f_' . $name, 'Name', 'text', '{"max_length":500}', 1],
            [self::V3_FIELDS['Photo'], null, 'Photo', 'attachment', '{"images_only":true}', 2],
            [self::V3_FIELDS['Receipt'], null, 'Receipt', 'attachment', '{}', 3],
        ];
        foreach ($fields as [$key, $sqlName, $label, $type, $options, $position]) {
            $insert(
                'INSERT INTO "_tiffin_fields" ("key", "table_key", "sql_name", "name", "type", "options", "position", "created_at", "updated_at") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$key, self::V3_ITEMS, $sqlName, $label, $type, $options, $position, $time, $time],
            );
        }
        $records = [];
        foreach ([['Toaster', null], ['Mower', null], ['Old kettle', $time]] as [$title, $trashed]) {
            $insert('INSERT INTO "' . $table . '" ("_created_at", "_updated_at", "_trashed_at", "f_' . $name . '") VALUES (?, ?, ?, ?)', [$time, $time, $trashed, $title]);
            $records[$title] = (int) $pdo->lastInsertId();
        }
        $attached = [
            'toaster.png' => [self::V3_FIELDS['Photo'], $records['Toaster'], FileFixture::png(4, 3, 'v3-toaster'), 'image/png'],
            'receipt-toaster.pdf' => [self::V3_FIELDS['Receipt'], $records['Toaster'], self::receipt('receipt-toaster.pdf'), 'application/pdf'],
            'mower.jpg' => [self::V3_FIELDS['Photo'], $records['Mower'], FileFixture::jpeg('v3-mower'), 'image/jpeg'],
            'kettle.gif' => [self::V3_FIELDS['Photo'], $records['Old kettle'], FileFixture::gif('v3-kettle'), 'image/gif'],
        ];
        $rows = [];
        $folder = dirname($file);
        foreach ($attached as $fileName => [$field, $record, $bytes, $type]) {
            $sha = hash('sha256', $bytes);
            $dir = $folder . '/attachments/' . substr($sha, 0, 2);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            file_put_contents($dir . '/' . $sha, $bytes);
            $insert('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at", "created_by") VALUES (?, ?, ?, ?, ?, ?)', [$sha, strlen($bytes), $type, $fileName, $time, 'm2kq7vn3xbpa']);
            $id = (int) $pdo->lastInsertId();
            $insert('INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, ?)', [$field, $record, $id, 1]);
            $rows[$fileName] = ['id' => $id, 'bytes' => $bytes, 'type' => $type, 'record' => $record];
        }
        $pdo->commit();
        $pdo = null;

        return $rows;
    }

    /** An HTML file with a script: stored and served only as a download. */
    public const HTML = "<!DOCTYPE html>\n<html><head><title>Manual</title></head><body><script>alert(1)</script><p>Synthetic manual.</p></body></html>\n";

    /** An SVG with a script: stored and served only as a download. */
    public const SVG = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"10\" height=\"10\"><script>alert(1)</script><rect width=\"10\" height=\"10\"/></svg>\n";

    /** A one-page PDF whose bytes name the file, so each receipt has its own content. */
    public static function receipt(string $name): string
    {
        $pdf = FileFixture::pdf();

        return substr($pdf, 0, 9) . '% ' . $name . "\n" . substr($pdf, 9);
    }

    /** The path of a file under the project folder for an attachment's hash. */
    public static function storedPath(CoreFixture $core, string $project, string $bytes): string
    {
        $sha = hash('sha256', $bytes);

        return $core->projectFolder($project) . '/attachments/' . substr($sha, 0, 2) . '/' . $sha;
    }

    /** The URL path of the serving route for a file of the data set. */
    public function servePath(string $name): string
    {
        return $this->m2->projectPath() . '/files/' . ($this->attachmentIds[$name] ?? throw new \LogicException('No such fixture file.'));
    }

    /** The URL path of a collection's view. */
    public function collectionPath(string $name): string
    {
        return $this->m2->projectPath() . '/collections/' . ($this->collections[$name] ?? throw new \LogicException('No such fixture collection.'));
    }
}
