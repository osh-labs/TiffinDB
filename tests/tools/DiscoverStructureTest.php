<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Tools;

use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Runs tools/discovery/discover-structure.php against an adversarial synthetic
 * package built at runtime. All fixture values are obviously synthetic.
 */
final class DiscoverStructureTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../tools/discovery/discover-structure.php';

    /**
     * Every row value, DDL literal, personal-looking identifier and file name
     * part in the fixture. None may appear in a default report.
     */
    private const SECRETS = [
        // Row values.
        'Example Person',
        'Sample Human',
        'user@example.com',
        'other@example.com',
        '555-0100',
        'Synthetic Label',
        'kind:v2',
        'code#07',
        'walrow',
        // Literals inside CREATE statements.
        'Synthetic Default',
        'dqDefaultLiteral',
        'dqCheckLiteral',
        'dqIndexLiteral',
        'dqViewLiteral',
        'sqViewLiteral',
        'dqTriggerLiteral',
        'sqTriggerLiteral',
        'commentLiteral',
        '5550101',
        '5550102',
        '5550103',
        '5550104',
        '5550105',
        '5550106',
        '5550107',
        '5550108',
        '69,120',
        '80,101',
        // Personal-looking identifiers.
        '555-0199',
        "Person's notes",
        'Bracket Column',
        'Quoted Alias',
        'field_1042',
        // File names and name parts.
        'holiday snapshot',
        '.Person',
        'example.com',
        '.last',
    ];

    private string $dir = '';
    private string $package = '';
    private string $database = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->dir = sys_get_temp_dir() . '/tiffindb-discover-test-' . bin2hex(random_bytes(6));
        $this->package = $this->dir . '/data/Example Person Library';
        mkdir($this->package . '/Attachments', 0700, true);
        mkdir($this->dir . '/out', 0700);
        mkdir($this->dir . '/tmp', 0700);
        $this->database = $this->package . '/store.db';
        $this->createDatabase($this->database);

        file_put_contents($this->package . '/Attachments/holiday snapshot.jpg', "\xFF\xD8\xFF\xE0" . str_repeat('j', 32));
        file_put_contents($this->package . '/Attachments/Example.Person', 'user@example.com');
        file_put_contents($this->package . '/Attachments/user@example.com', 'x');
        file_put_contents($this->package . '/Info.plist', "<?xml version=\"1.0\"?>\n<plist version=\"1.0\"><dict/></plist>\n");
        file_put_contents($this->package . '/damaged.sqlite', "SQLite format 3\0" . str_repeat("\x01", 600));
        file_put_contents($this->package . '/store.db-journal', 'synthetic journal bytes');

        // A database whose newest table exists only in its write-ahead log.
        $source = $this->dir . '/wal-source.db';
        $wal = new PDO('sqlite:' . $source);
        $wal->exec('PRAGMA journal_mode = WAL');
        $wal->exec('CREATE TABLE early (a); INSERT INTO early VALUES (1)');
        $wal->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $wal->exec("CREATE TABLE late (b TEXT); INSERT INTO late VALUES ('walrow')");
        copy($source, $this->package . '/logged.db');
        copy($source . '-wal', $this->package . '/logged.db-wal');
        $wal = null;

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            $zip->open($this->package . '/Sample Human export.zip', ZipArchive::CREATE);
            $zip->addFile($this->database, 'Sample Human/inner.db');
            $zip->addFromString('Sample Human/first.last', 'x');
            $zip->close();
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->removeTree($this->dir);
        }
    }

    public function testDefaultOutputContainsStructureButNoRowValues(): void
    {
        [$code, $out, $err] = $this->discover([$this->package]);

        // The damaged database is reported, which gives exit code 1.
        self::assertSame(1, $code);
        self::assertSame('', $err);
        self::assertStringContainsString('discover-structure.php', $out);
        self::assertStringContainsString('no row values', $out);
        $this->assertNoSecrets($out);

        self::assertStringContainsString('-- table contacts rows: 3', $out);
        self::assertStringContainsString('-- table field_meta rows: 3', $out);
        foreach (['contact_id', 'display_name', 'email', 'phone', 'score', 'picture', 'type_code'] as $column) {
            self::assertMatchesRegularExpression('/column \d+: ' . $column . ' \|/', $out);
        }
        self::assertStringContainsString('user_version=7', $out);
        self::assertStringContainsString('encoding=UTF-8', $out);
        self::assertStringContainsString('page_size=', $out);
        self::assertStringContainsString('idx_contacts_email', $out);
        self::assertStringContainsString('contact_ref -> contacts(contact_id)', $out);

        // display_name: 3 text values of 14, 12 and 14 bytes.
        self::assertStringContainsString(
            'null=0 non-null=3 | integer=0 real=0 text=3 blob=0 | text_bytes min=12 max=14 avg=13.3',
            $out
        );
        // score: one NULL, one REAL, one INTEGER; no lengths for numbers.
        self::assertStringContainsString("null=1 non-null=2 | integer=1 real=1 text=0 blob=0\n", $out);
        // picture: one 4-byte BLOB.
        self::assertStringContainsString('blob_bytes min=4 max=4 avg=4', $out);
        // Generated columns are listed.
        self::assertMatchesRegularExpression('/column \d+: shouted \|.*\| generated-virtual/', $out);

        self::assertStringContainsString('SQLITE_NOTADB', $out);
        self::assertStringContainsString('rollback journal present and ignored', $out);
    }

    public function testLiteralsInCreateStatementsAreMasked(): void
    {
        [, $out] = $this->discover([$this->package]);

        // Defaults are reported by kind only.
        self::assertMatchesRegularExpression('/column \d+: display_name \|.*default=yes \(text-or-blob\)/', $out);
        self::assertMatchesRegularExpression('/column \d+: pin \|.*default=yes \(numeric\)/', $out);
        self::assertMatchesRegularExpression('/column \d+: email \|.*default=no/', $out);

        self::assertStringContainsString("DEFAULT '<text>'", $out);
        self::assertStringContainsString('DEFAULT "<text>"', $out);
        self::assertStringContainsString('DEFAULT <num>', $out);
        self::assertStringContainsString('DEFAULT (<num>)', $out);
        self::assertStringContainsString('CHECK (status IN ("<text>", "<text>"))', $out);
        self::assertStringContainsString('CHECK (phone <> <num>)', $out);
        self::assertStringContainsString('WHERE email <> "<text>" AND pin = <num>', $out);
        self::assertStringContainsString('"<text>" AS dq, \'<text>\' AS sq, <num> AS n', $out);
        self::assertStringContainsString('SELECT "<text>"; UPDATE contacts SET pin = <num>, status = \'<text>\'', $out);
        self::assertStringContainsString('/* <comment> */', $out);
        // Function arguments can be data (char() code points, timestamps): always masked.
        self::assertStringContainsString('DEFAULT (char(<num>,<num>,<num>,<num>)) CHECK (letters <> char(<num>,<num>,<num>))', $out);
        self::assertStringContainsString("DEFAULT (datetime(<num>, '<text>'))", $out);
        self::assertStringContainsString('time(stamp) >= time(<num>)', $out);
        // Type sizes appear only in the declared-type field, never in the statement.
        self::assertStringContainsString('code VARCHAR(<num>)', $out);
        self::assertStringContainsString('amount DECIMAL(<num>,<num>)', $out);
        self::assertMatchesRegularExpression('/column \d+: code \| type=VARCHAR\(255\) \|/', $out);
        self::assertMatchesRegularExpression('/column \d+: amount \| type=DECIMAL\(10,2\) \|/', $out);

        foreach ([['--json'], ['--redact-identifiers=all'], ['--redact-identifiers=all', '--json']] as $options) {
            [, $other] = $this->discover([...$options, $this->package]);
            $this->assertNoSecrets($other);
            self::assertStringContainsString('(<num>,<num>,<num>,<num>)', $other);
            self::assertStringContainsString('(<num>,<num>,<num>)', $other);
        }
        // A quoted name that really is a column is still treated as an identifier.
        self::assertStringContainsString('"display_name" TEXT', $out);

        foreach (['--show-identifiers', '--redact-identifiers=all', '--show-names'] as $option) {
            [, $other] = $this->discover([$option, $this->package]);
            foreach (['Literal', 'Synthetic Default', '555010'] as $literal) {
                self::assertStringNotContainsString($literal, $other, $option);
            }
        }
    }

    /**
     * Property-style check: every place in a schema that can hold user text
     * carries a unique marker ("zqm<n>z" or a number starting with 8800), and
     * no marker may appear in the default, JSON or fully redacted reports.
     */
    public function testNoSchemaPositionLeaksUserTextByDefault(): void
    {
        $directory = $this->dir . '/data/markers';
        mkdir($directory, 0700);
        $path = $directory . '/markers.db';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE \"zqm1z tbl\" (
                c TEXT DEFAULT 'zqm2z' CHECK (c <> 'zqm3z' AND c <> \"zqm4z\" AND length(c) < 880000005),
                \"zqm6z col\" TEXT DEFAULT \"zqm7z\",
                `zqm8z col` INTEGER DEFAULT 880000009,
                [zqm10z col] BLOB DEFAULT x'8800000011',
                d zqm12z zqm13z(880000014),
                e \"zqm15z type\",
                f [zqm16z type](12),
                g TEXT DEFAULT (char(880000017, 880000018)) CHECK (g <> char(880000019)),
                h TEXT DEFAULT (datetime(880000020, 'zqm21z')),
                i REAL DEFAULT 880000022.5 CHECK (i <> -880000023 AND i <> 0x8800000024),
                j TEXT GENERATED ALWAYS AS (c || 'zqm25z' || \"zqm26z\" || 880000027) VIRTUAL, -- zqm28z
                k TEXT COLLATE NOCASE /* zqm29z */
            )"
        );
        $pdo->exec(
            "CREATE INDEX \"zqm30z idx\" ON \"zqm1z tbl\" (c) "
            . "WHERE c <> 'zqm31z' AND c <> \"zqm32z\" AND i > 880000033"
        );
        $pdo->exec(
            "CREATE VIEW \"zqm34z view\" AS WITH zqm35z AS (SELECT 880000036 AS zqm37z) "
            . "SELECT t.c AS \"zqm38z alias\", 'zqm39z' AS a, \"zqm40z\" AS b, 880000041 AS n, "
            . "substr(t.c, 880000042) AS s, `zqm43z`.zqm37z AS w "
            . "FROM \"zqm1z tbl\" AS t, zqm35z AS `zqm43z` WHERE t.c LIKE 'zqm44z%'"
        );
        $pdo->exec(
            "CREATE TRIGGER \"zqm45z trg\" AFTER DELETE ON \"zqm1z tbl\" WHEN OLD.c <> 'zqm46z' BEGIN "
            . "SELECT 'zqm47z', \"zqm48z\", 880000049, char(880000050), x'8800000051' AS zqm52z; "
            . "UPDATE \"zqm1z tbl\" SET c = 'zqm53z' WHERE i = 880000054; END"
        );
        $pdo->exec("INSERT INTO \"zqm1z tbl\" (c, k) VALUES ('zqm55z', 'zqm56z')");
        $pdo = null;
        file_put_contents($directory . '/zqm57z.zqm58z', 'zqm59z');

        foreach ([[], ['--json'], ['--redact-identifiers=all'], ['--redact-identifiers=all', '--json'], ['--apply-wal']] as $options) {
            [$code, $out, $err] = $this->discover([...$options, $directory]);
            $label = implode(' ', $options);
            self::assertSame(0, $code, $label);
            self::assertMatchesRegularExpression('/rows: 1|"row_count": 1/', $out, $label);
            self::assertSame(0, preg_match('/zqm\d*z?|8800\d{3}/i', $out . $err, $m), $label . ': ' . ($m[0] ?? ''));
        }

        // The reported repro: no partial rendering of a non-standard declared type.
        [, $out] = $this->discover([$directory]);
        self::assertMatchesRegularExpression('/column \d+: d \| type=<type-[0-9a-f]{8}> \|/', $out);
        self::assertMatchesRegularExpression('/column \d+: e \| type=<type-[0-9a-f]{8}> \|/', $out);
        self::assertMatchesRegularExpression('/column \d+: f \| type=<type-[0-9a-f]{8}> \|/', $out);
        self::assertStringContainsString('d <word> <word>(<num>)', $out);
        self::assertStringContainsString('e "<text>"', $out);
        self::assertStringContainsString('f [<word>](<num>)', $out);
        self::assertStringContainsString("DEFAULT <blob>", $out);
        self::assertStringContainsString('WITH <word> AS (SELECT <num> AS <word>)', $out);

        // --show-identifiers shows names but never literals or numbers.
        [, $shown] = $this->discover(['--show-identifiers', $directory]);
        self::assertStringContainsString('"zqm6z col" TEXT', $shown);
        self::assertStringContainsString('type=zqm12z zqm13z(<num>)', $shown);
        self::assertSame(0, preg_match('/8800\d{3}/', $shown, $m), $m[0] ?? '');
        foreach ([2, 3, 4, 7, 21, 25, 26, 28, 29, 31, 32, 39, 40, 44, 46, 47, 48, 53, 55, 56, 59] as $n) {
            self::assertStringNotContainsString('zqm' . $n . 'z', $shown);
        }
    }

    public function testPersonalLookingIdentifiersAreHashedByDefault(): void
    {
        [, $out] = $this->discover([$this->package]);

        self::assertMatchesRegularExpression('/-- table id_[0-9a-f]{10} rows: 0\n\s+sql: CREATE TABLE "id_[0-9a-f]{10}" \(`id_[0-9a-f]{10}` TEXT, \[id_[0-9a-f]{10}\] TEXT, id_[0-9a-f]{10} REAL\)/', $out);
        self::assertStringContainsString('Plain-word identifiers are SHOWN', $out);
        self::assertStringContainsString('REVIEW LIST', $out);

        [, $shown] = $this->discover(['--show-identifiers', $this->package]);
        self::assertStringContainsString("`Example Person's notes` TEXT, [Bracket Column] TEXT, field_1042 REAL", $shown);
        self::assertStringContainsString('WARNING: --show-identifiers was used', $shown);
        // A quote inside a quoted identifier is written back doubled.
        self::assertStringContainsString('"quo""te" TEXT', $shown);

        [, $all] = $this->discover(['--redact-identifiers=all', $this->package]);
        self::assertStringNotContainsString('display_name', $all);
        self::assertStringNotContainsString('field_meta', $all);
        self::assertStringNotContainsString('REVIEW LIST', $all);
        self::assertMatchesRegularExpression('/-- table (id_[0-9a-f]{10}) rows: 3\n\s+sql: CREATE TABLE \1 \(/', $all);
        $this->assertNoSecrets($all);

        self::assertSame(2, $this->discover(['--show-identifiers', '--redact-identifiers=all', $this->package])[0]);
    }

    public function testFileNamesAndUnknownExtensionsAreRedactedByDefault(): void
    {
        [, $out] = $this->discover([$this->package]);

        $this->assertNoSecrets($out);
        self::assertMatchesRegularExpression('/image-jpeg\s+36\s+Attachments\/<name-\d+>\.jpg/', $out);
        self::assertMatchesRegularExpression('/text\s+16\s+Attachments\/<name-\d+>\.<ext-\d+>/', $out);
        self::assertMatchesRegularExpression('/plist-xml\s+\d+\s+Info\.plist/', $out);
        self::assertMatchesRegularExpression('/sqlite\s+\d+\s+<name-\d+>\.db\n/', $out);
        self::assertMatchesRegularExpression('/sqlite-wal\s+\d+\s+<name-\d+>\.db-wal/', $out);

        [, $shown] = $this->discover(['--show-names', $this->package]);
        self::assertStringContainsString('Attachments/holiday snapshot.jpg', $shown);
        self::assertStringContainsString('Attachments/Example.Person', $shown);
        self::assertStringContainsString('WARNING: --show-names was used', $shown);

        [, $single] = $this->discover([$this->database]);
        self::assertMatchesRegularExpression('/sqlite\s+\d+\s+<input>\.db\n/', $single);
    }

    public function testZipArchivesAreListedAndTheirDatabasesAnalysed(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('The zip extension is not available.');
        }
        [, $out] = $this->discover([$this->package]);

        self::assertMatchesRegularExpression('/sqlite\s+\d+\s+<name-\d+>\.zip!\/<name-\d+>\/<name-\d+>\.db/', $out);
        self::assertMatchesRegularExpression('/text\s+1\s+<name-\d+>\.zip!\/<name-\d+>\/<name-\d+>\.<ext-\d+>/', $out);
        self::assertSame(2, substr_count($out, '-- table contacts rows: 3'));
        $this->assertNoSecrets($out);
    }

    public function testJsonOutputIsValidDeterministicAndFreeOfValues(): void
    {
        [, $first] = $this->discover(['--json', $this->package]);
        [, $second] = $this->discover(['--json', $this->package]);

        self::assertSame($first, $second);
        $this->assertNoSecrets($first);
        $report = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('discover-structure.php', $report['tool']);

        $tables = [];
        foreach ($report['databases'] as $database) {
            foreach ($database['tables'] ?? [] as $table) {
                $tables[$table['name']] = $table;
            }
        }
        self::assertSame(3, $tables['contacts']['row_count']);
        self::assertSame('email', $tables['contacts']['columns'][2]['name']);
        self::assertSame(['present' => false, 'kind' => null], $tables['contacts']['columns'][2]['default']);
        self::assertSame(1, $tables['contacts']['columns'][2]['statistics']['null']);
        self::assertSame(2, $tables['contacts']['columns'][2]['statistics']['storage_classes']['text']);
    }

    public function testValueShapesMasksLettersAndDigitsForTheNamedTableOnly(): void
    {
        [, $out] = $this->discover(['--value-shapes', 'field_meta', $this->package]);

        self::assertStringContainsString('WARNING: --value-shapes was used', $out);
        self::assertStringContainsString('2 x  [aaaa:a9]', $out);
        self::assertStringContainsString('3 x  [Aaaaaaaaa Aaaaa]', $out);
        // A pattern seen once is counted but not listed.
        self::assertStringNotContainsString('[aaaa#99]', $out);
        self::assertStringContainsString('seen once and not listed=1', $out);
        // Nothing from the other table: no shape of an e-mail address or phone number.
        self::assertStringNotContainsString('@', $out);
        self::assertStringNotContainsString('[999-9999]', $out);
        $this->assertNoSecrets($out);

        [, $singletons] = $this->discover(['--value-shapes', 'field_meta', '--value-shapes-singletons', $this->package]);
        self::assertStringContainsString('1 x  [aaaa#99]', $singletons);
        $this->assertNoSecrets($singletons);

        [, $default] = $this->discover([$this->package]);
        self::assertStringNotContainsString('VALUE SHAPES', $default);
        self::assertStringNotContainsString('[aaaa:a9]', $default);

        // <n>:<table> limits the option to one database of the report.
        self::assertSame(1, preg_match('/== Database (\d+): <name-\d+>\.db ==\nheader: [^\n]*user_version=7/', $default, $m));
        [, $one] = $this->discover(['--value-shapes', $m[1] . ':field_meta', $this->package]);
        self::assertSame(1, substr_count($one, 'VALUE SHAPES'));
    }

    public function testInputIsNotModifiedAndWriteAheadLogIsOnlyAppliedOnACopy(): void
    {
        $before = $this->snapshot();

        [, $plain] = $this->discover([$this->package]);
        self::assertStringContainsString('-- table early rows: 1', $plain);
        self::assertStringNotContainsString('-- table late', $plain);
        self::assertStringContainsString('present but NOT applied', $plain);

        [, $applied] = $this->discover(['--apply-wal', $this->package]);
        self::assertStringContainsString('-- table late rows: 1', $applied);
        self::assertStringContainsString('applied on a temporary private copy', $applied);
        $this->assertNoSecrets($applied);

        $this->discover(['--json', '--value-shapes', 'field_meta', '--apply-wal', $this->package]);
        $this->discover([$this->database]);

        self::assertSame($before, $this->snapshot());
        self::assertSame([], glob($this->dir . '/tmp/*') ?: []);
    }

    public function testNoAbsoluteOrTemporaryPathsAppear(): void
    {
        foreach ([[], ['--json'], ['--show-names'], ['--apply-wal'], ['--value-shapes', 'field_meta']] as $options) {
            [, $out, $err] = $this->discover([...$options, $this->package]);
            foreach ([$this->dir, sys_get_temp_dir(), 'tiffindb-discover', dirname(self::SCRIPT, 3)] as $path) {
                self::assertStringNotContainsString($path, $out . $err);
            }
        }
    }

    public function testOutOptionCreatesAPrivateFileAndRefusesUnsafeTargets(): void
    {
        // Inside the package.
        [$code, , $err] = $this->discover(['--out', $this->package . '/report.txt', $this->package]);
        self::assertSame(3, $code);
        self::assertStringContainsString('Refusing', $err);
        self::assertFileDoesNotExist($this->package . '/report.txt');

        // Beside a single input file.
        self::assertSame(3, $this->discover(['--out', $this->package . '/report.txt', $this->database])[0]);

        // Through a dangling symbolic link that points into the package.
        $link = $this->dir . '/out/link.txt';
        symlink($this->package . '/planted.txt', $link);
        [$code, , $err] = $this->discover(['--out', $link, $this->package]);
        self::assertSame(3, $code);
        self::assertStringContainsString('symbolic link', $err);
        self::assertFileDoesNotExist($this->package . '/planted.txt');
        unlink($link);

        // The documented layout: output directory outside the copy.
        $target = $this->dir . '/out/report.txt';
        [, $out] = $this->discover(['--out', $target, $this->package]);
        self::assertSame('', $out);
        self::assertStringContainsString('-- table contacts rows: 3', (string) file_get_contents($target));
        self::assertSame(0600, fileperms($target) & 0777);

        // Never overwrites.
        [$code, , $err] = $this->discover(['--out', $target, $this->package]);
        self::assertSame(3, $code);
        self::assertStringContainsString('already exists', $err);

        // Works for a single input file too.
        self::assertSame(0, $this->discover(['--out', $this->dir . '/out/single.txt', $this->database])[0]);
    }

    public function testUsageAndMissingInputExitCodes(): void
    {
        self::assertSame(2, $this->discover([])[0]);
        self::assertSame(2, $this->discover(['--no-such-option', $this->package])[0]);
        self::assertSame(3, $this->discover([$this->dir . '/does-not-exist'])[0]);
        self::assertSame(1, $this->discover(['--value-shapes', 'no_such_table', $this->database])[0]);
    }

    private function createDatabase(string $path): void
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA user_version = 7');
        $pdo->exec(
            "CREATE TABLE contacts (
                contact_id INTEGER PRIMARY KEY,
                \"display_name\" TEXT NOT NULL DEFAULT 'Synthetic Default',
                email TEXT,
                phone TEXT CHECK (phone <> 5550101),
                score NUMERIC DEFAULT 5550102,
                picture BLOB,
                status TEXT DEFAULT \"dqDefaultLiteral\" CHECK (status IN (\"dqCheckLiteral\", \"Sample Human\")),
                pin INTEGER DEFAULT (5550103),
                code VARCHAR(255),
                amount DECIMAL(10,2),
                \"quo\"\"te\" TEXT,
                letters TEXT DEFAULT (char(69,120,97,109)) CHECK (letters <> char(80,101,114)),
                stamp TEXT DEFAULT (datetime(5550107, 'unixepoch')) CHECK (stamp IS NULL OR time(stamp) >= time(5550108) OR stamp <> ''),
                shouted TEXT GENERATED ALWAYS AS (upper(email)) VIRTUAL /* commentLiteral */
            )"
        );
        $pdo->exec(
            'CREATE TABLE field_meta (
                meta_id INTEGER PRIMARY KEY,
                label TEXT,
                type_code TEXT,
                contact_ref INTEGER REFERENCES contacts(contact_id)
            )'
        );
        $pdo->exec(
            "CREATE TABLE \"Example Person\nLibrary 555-0199\" (`Example Person's notes` TEXT, [Bracket Column] TEXT, field_1042 REAL)"
        );
        $pdo->exec('CREATE INDEX idx_contacts_email ON contacts(email) WHERE email <> "dqIndexLiteral" AND pin = 5550104');
        $pdo->exec(
            'CREATE VIEW contact_view AS SELECT display_name AS "Quoted Alias", "dqViewLiteral" AS dq, '
            . "'sqViewLiteral' AS sq, 5550105 AS n FROM contacts"
        );
        $pdo->exec(
            'CREATE TRIGGER contacts_touch AFTER DELETE ON contacts BEGIN SELECT "dqTriggerLiteral"; '
            . "UPDATE contacts SET pin = 5550106, status = 'sqTriggerLiteral' WHERE contact_id = OLD.contact_id; END"
        );
        $pdo->exec(
            "INSERT INTO contacts (contact_id, display_name, email, phone, score, picture, status) VALUES
                (1, 'Example Person', 'user@example.com', '555-0100', 2.5, x'00010203', 'dqCheckLiteral'),
                (2, 'Sample Human', 'other@example.com', NULL, NULL, NULL, 'Sample Human'),
                (3, 'Example Person', NULL, NULL, 4, NULL, 'dqCheckLiteral')"
        );
        $pdo->exec(
            "INSERT INTO field_meta VALUES
                (1, 'Synthetic Label', 'kind:v2', 1),
                (2, 'Synthetic Label', 'kind:v2', 2),
                (3, 'Synthetic Label', 'code#07', 2)"
        );
        $pdo = null;
    }

    private function assertNoSecrets(string $output): void
    {
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{0:int,1:string,2:string} exit code, stdout, stderr
     */
    private function discover(array $arguments): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, self::SCRIPT], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            // A private temporary directory, so leftovers can be checked without
            // being confused by other runs on the same machine.
            array_merge(getenv(), ['TMPDIR' => $this->dir . '/tmp'])
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    /** @return array<string,string> relative path => SHA-256 of every file in the package */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->package, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $files[substr($file->getPathname(), strlen($this->package))] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return $files;
    }

    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
