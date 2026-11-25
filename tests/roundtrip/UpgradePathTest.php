<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PDOException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Schema\FormatMigrations;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Core\System\SystemSchema;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\HistoricFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\EndToEndClient;
use TiffinDB\Tests\Web\EndToEndTest;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Tests\Web\NoProbeServices;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;

require_once __DIR__ . '/../web/fixtures/Fixtures.php';
require_once __DIR__ . '/../web/EndToEndTest.php';

/**
 * T-UPG-1, T-UPG-2, and T-UPG-5 to T-UPG-8 (M7 spec part 1 section 12.3,
 * ADR 0021 decision 6): installations as earlier TiffinDB versions left
 * them (HistoricFixture: system schema 1 or 2, project format 1 to 4,
 * built from the documented SQL) open under the current code, upgrade
 * once with one copy or snapshot each, and keep their data; simulated next
 * steps run through the test seams of SystemSchema::migrate and
 * FormatMigrations. T-UPG-3 and T-UPG-4 (newer data refused) are in
 * tests/web/NewerDataTest.php.
 *
 * T-UPG-5 as specified ("a version 4 file goes to 5") cannot be run: the
 * FormatMigrations seam replaces the steps up to ProjectFile::VERSION and
 * runs nothing past it, and ProjectFile::open does not call it for a file
 * already at that version. The test here simulates the next step as the
 * last step the seam can run (version 3 to 4); the gap is reported to the
 * owner of FormatMigrations.
 */
#[CoversNothing]
final class UpgradePathTest extends TestCase
{
    private const START = '2026-11-25T12:00:00Z';

    private TempDir $temp;
    private FixedClock $clock;
    /** @var list<Operations> */
    private array $operations = [];
    /** @var list<SystemDatabase> */
    private array $systems = [];

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-upgrade-path-');
        $this->clock = new FixedClock(self::START);
        mkdir($this->temp->path('data'), 0750);
    }

    protected function tearDown(): void
    {
        foreach ($this->operations as $operations) {
            $operations->close();
        }
        foreach ($this->systems as $system) {
            $system->db()->close();
        }
        $this->temp->remove();
    }

    private function build(int $systemVersion, int $projectVersion): HistoricFixture
    {
        return HistoricFixture::build($this->temp->path('data'), $systemVersion, $projectVersion);
    }

    /** Opens the installation as the release code does on a request, and lists Items as the Member. */
    private function openAndList(HistoricFixture $fixture): Operations
    {
        $system = SystemDatabase::open($fixture->data);
        $this->systems[] = $system;
        $ops = new Operations($system, $fixture->data, $this->clock, new SeededRandom('tiffindb-upgrade-path-test-1'), new PasswordHasher(['bcrypt' => 4]), '9.9.9-test');
        $this->operations[] = $ops;
        $listing = $ops->records()->listing($fixture->actor('member'), $fixture->projectKey, HistoricFixture::ITEMS, ListRequest::none());
        self::assertSame(2, $listing->total, 'The two live Items are listed.');

        return $ops;
    }

    /** @return list<string> the system database copies beside system.sqlite, sorted */
    private function systemCopies(): array
    {
        $copies = glob($this->temp->path('data') . '/system-before-v*.sqlite') ?: [];
        sort($copies);

        return $copies;
    }

    private function snapshots(): Snapshots
    {
        return new Snapshots($this->clock, new Keys(new SeededRandom('tiffindb-upgrade-path-snapshots-1')));
    }

    /** @return array<string, mixed> $dump without the entries named */
    private static function without(array $dump, string ...$names): array
    {
        foreach ($names as $name) {
            unset($dump[$name]);
        }

        return $dump;
    }

    /** @return iterable<string, array{int, int}> */
    public static function versions(): iterable
    {
        foreach ([1, 2] as $system) {
            foreach ([1, 2, 3, 4] as $project) {
                yield "system {$system}, project {$project}" => [$system, $project];
            }
        }
    }

    // ------------------------------------------------------------ T-UPG-1, T-UPG-2

    #[DataProvider('versions')]
    public function testAnEarlierInstallationOpensUpgradesOnceAndKeepsItsData(int $systemVersion, int $projectVersion): void
    {
        $fixture = $this->build($systemVersion, $projectVersion);
        $systemFile = $fixture->data->systemDatabase();
        $projectFile = $fixture->projectFile();
        self::assertSame($systemVersion, HistoricFixture::systemVersion($systemFile));
        self::assertSame($projectVersion, HistoricFixture::userVersion($projectFile));
        $systemBefore = HistoricFixture::dump($systemFile);
        $projectBefore = HistoricFixture::dump($projectFile);

        $ops = $this->openAndList($fixture);

        // The receipt is served with its bytes.
        $attachmentId = (string) $projectBefore['_tiffin_attachments'][0]['id'];
        $served = $ops->attachments()->serve($fixture->actor('member'), $fixture->projectKey, $attachmentId, false);
        self::assertSame(HistoricFixture::RECEIPT, $served->fileName);
        self::assertSame($fixture->receiptBytes, (string) file_get_contents($served->path));
        $ops->close();
        foreach ($this->systems as $system) {
            $system->db()->close();
        }
        $this->operations = [];
        $this->systems = [];

        // The system database: at version 2, its rows kept, one copy when it started at 1.
        self::assertSame(SystemSchema::VERSION, HistoricFixture::systemVersion($systemFile));
        $systemAfter = HistoricFixture::dump($systemFile);
        $metaBefore = $systemBefore['meta'];
        foreach ($metaBefore as $i => $row) {
            if ($row['key'] === 'schema_version') {
                $metaBefore[$i]['value'] = (string) SystemSchema::VERSION;
            }
        }
        self::assertSame($metaBefore, $systemAfter['meta']);
        foreach (self::without($systemBefore, '_schema', 'meta') as $table => $rows) {
            self::assertSame($rows, $systemAfter[$table], "System table {$table} is unchanged.");
        }
        foreach (array_diff_key(self::without($systemAfter, '_schema'), $systemBefore) as $table => $rows) {
            self::assertSame([], $rows, "Added system table {$table} is empty.");
        }
        $copies = $this->systemCopies();
        if ($systemVersion === 1) {
            self::assertCount(1, $copies, 'One copy before the system step.');
            self::assertMatchesRegularExpression('#/system-before-v2-\d{8}T\d{6}Z\.sqlite\z#', $copies[0]);
            self::assertSame(1, HistoricFixture::systemVersion($copies[0]));
            self::assertSame($systemBefore, HistoricFixture::dump($copies[0]), 'The copy holds the version 1 database.');
        } else {
            self::assertSame([], $copies, 'No copy when nothing was upgraded.');
        }

        // The project file: at version 4, its data kept, one snapshot of the old version when upgraded.
        self::assertSame(ProjectFile::VERSION, HistoricFixture::userVersion($projectFile));
        $projectAfter = HistoricFixture::dump($projectFile);
        $projectMeta = $projectBefore['_tiffin_meta'];
        foreach ($projectMeta as $i => $row) {
            if ($row['key'] === 'format_version') {
                $projectMeta[$i]['value'] = (string) ProjectFile::VERSION;
            }
        }
        self::assertSame($projectMeta, $projectAfter['_tiffin_meta']);
        foreach (self::without($projectBefore, '_schema', '_user_version', '_tiffin_meta') as $table => $rows) {
            self::assertSame($rows, $projectAfter[$table], "Project table {$table} is unchanged.");
        }
        foreach (['t_' . HistoricFixture::ITEMS, 't_' . HistoricFixture::ROOMS, 'j_' . HistoricFixture::RELATION, '_tiffin_attachments', '_tiffin_attachment_links'] as $table) {
            self::assertNotSame([], $projectAfter[$table], "{$table} holds the fixture rows.");
        }
        foreach (array_diff_key(self::without($projectAfter, '_schema', '_user_version'), $projectBefore) as $table => $rows) {
            self::assertSame([], $rows, "Added project table {$table} is empty.");
        }
        $snapshots = $this->snapshots()->list($fixture->projectFolder());
        if ($projectVersion < ProjectFile::VERSION) {
            self::assertCount(1, $snapshots, 'Exactly one snapshot.');
            self::assertSame(SnapshotReason::FormatUpgrade, $snapshots[0]->reason);
            $copy = $fixture->projectFolder() . '/snapshots/' . $snapshots[0]->file;
            self::assertSame($projectVersion, HistoricFixture::userVersion($copy), 'The snapshot holds the old version.');
            self::assertSame($projectBefore, HistoricFixture::dump($copy), 'The snapshot holds the file as it was.');
        } else {
            self::assertSame([], $snapshots, 'No snapshot when nothing was upgraded.');
        }

        // T-UPG-2: opening again changes nothing and takes no snapshot.
        $this->openAndList($fixture);
        foreach ($this->operations as $operations) {
            $operations->close();
        }
        foreach ($this->systems as $system) {
            $system->db()->close();
        }
        $this->operations = [];
        $this->systems = [];
        self::assertSame($systemAfter, HistoricFixture::dump($systemFile));
        self::assertSame($projectAfter, HistoricFixture::dump($projectFile));
        self::assertSame($copies, $this->systemCopies());
        self::assertEquals($snapshots, $this->snapshots()->list($fixture->projectFolder()));
    }

    // ------------------------------------------------------------ T-UPG-5

    public function testASimulatedNextProjectStepRunsThroughTheSeamAfterOneSnapshot(): void
    {
        $fixture = $this->build(2, 3);
        $file = $fixture->projectFile();
        $before = HistoricFixture::dump($file);
        $next = new FormatMigrations([4 => static function (Database $db): void {
            foreach (FormatMigrations::STEPS[4] as $statement) {
                $db->run($statement);
            }
            $db->run('CREATE TABLE "_tiffin_next_example" ("key" TEXT PRIMARY KEY, "note" TEXT NOT NULL) WITHOUT ROWID');
            $db->run('INSERT INTO "_tiffin_next_example" VALUES (\'k2mq7vn3xbpa\', \'added by the simulated step\')');
        }]);
        $snapshots = $this->snapshots();

        $db = ProjectFile::open($file, $fixture->projectKey, $snapshots, $next);
        self::assertSame(4, $db->value('PRAGMA user_version'));
        self::assertSame(1, $db->value('SELECT COUNT(*) FROM "_tiffin_next_example"'));
        $db->close();
        $list = $snapshots->list($fixture->projectFolder());
        self::assertCount(1, $list);
        self::assertSame(SnapshotReason::FormatUpgrade, $list[0]->reason);
        self::assertSame(3, HistoricFixture::userVersion($fixture->projectFolder() . '/snapshots/' . $list[0]->file));
        $after = HistoricFixture::dump($file);
        foreach (self::without($before, '_schema', '_user_version', '_tiffin_meta') as $table => $rows) {
            self::assertSame($rows, $after[$table], "{$table} is unchanged.");
        }

        // Opened again with the same steps: the step does not run twice, and no snapshot is taken.
        $this->clock->advance(60);
        ProjectFile::open($file, $fixture->projectKey, $snapshots, $next)->close();
        self::assertSame($after, HistoricFixture::dump($file));
        self::assertCount(1, $snapshots->list($fixture->projectFolder()));
    }

    // ------------------------------------------------------------ T-UPG-6

    public function testASimulatedNextSystemStepRunsOnceAfterOneCopy(): void
    {
        $fixture = $this->build(2, 4);
        $file = $fixture->data->systemDatabase();
        $before = HistoricFixture::dump($file);
        $steps = SystemSchema::STEPS + [3 => [
            'CREATE TABLE next_example (id INTEGER PRIMARY KEY, note TEXT NOT NULL)',
            "INSERT INTO next_example (note) VALUES ('added by the simulated step')",
        ]];

        $db = Sqlite::open($file);
        SystemSchema::migrate($db, $steps, $this->clock);
        self::assertSame(3, SystemSchema::currentVersion($db));
        self::assertSame(1, $db->value('SELECT COUNT(*) FROM next_example'));
        $copies = $this->systemCopies();
        self::assertSame([$this->temp->path('data') . '/system-before-v3-20261125T120000Z.sqlite'], $copies);
        self::assertSame($before, HistoricFixture::dump($copies[0]), 'The copy holds the version 2 database.');

        // Again, later: nothing runs and no copy is taken.
        $this->clock->advance(3600);
        SystemSchema::migrate($db, $steps, $this->clock);
        self::assertSame(1, $db->value('SELECT COUNT(*) FROM next_example'));
        $db->close();
        self::assertSame($copies, $this->systemCopies());

        // The release code, which knows only version 2, now refuses the database.
        try {
            SystemDatabase::open($fixture->data);
            self::fail('A system database at version 3 was opened.');
        } catch (Unavailable $e) {
            self::assertSame(Unavailable::SYSTEM_NEWER, $e->reason);
        }
    }

    // ------------------------------------------------------------ T-UPG-7

    /** @return iterable<string, array{int}> */
    public static function earlierProjectVersions(): iterable
    {
        foreach ([1, 2, 3] as $version) {
            yield "project {$version}" => [$version];
        }
    }

    #[DataProvider('earlierProjectVersions')]
    public function testAFailingStepOpenedThreeTimesTakesOneSnapshotAndThenUpgradesTheOriginalData(int $projectVersion): void
    {
        $fixture = $this->build(2, $projectVersion);
        $file = $fixture->projectFile();
        $before = HistoricFixture::dump($file);
        $failing = $projectVersion + 1;
        $steps = [];
        foreach (array_keys(FormatMigrations::STEPS) as $version) {
            $steps[$version] = static function (Database $db) use ($version, $failing): void {
                foreach (FormatMigrations::STEPS[$version] as $statement) {
                    $db->run($statement);
                }
                if ($version === $failing) {
                    $db->run('INSERT INTO "_tiffin_missing_example" VALUES (1)');
                }
            };
        }
        $broken = new FormatMigrations($steps);
        $snapshots = $this->snapshots();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->clock->advance(3600);
            try {
                ProjectFile::open($file, $fixture->projectKey, $snapshots, $broken);
                self::fail('The failing step was not reported.');
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
            self::assertSame($projectVersion, HistoricFixture::userVersion($file), "Attempt {$attempt} leaves the old version.");
            $list = $snapshots->list($fixture->projectFolder());
            self::assertCount(1, $list, "Attempt {$attempt}: exactly one snapshot.");
            self::assertSame(SnapshotReason::FormatUpgrade, $list[0]->reason);
        }
        self::assertSame($before, HistoricFixture::dump($file), 'The failed attempts changed nothing.');

        // The working steps upgrade the file; the data is the original.
        $this->clock->advance(3600);
        ProjectFile::open($file, $fixture->projectKey, $snapshots)->close();
        self::assertSame(ProjectFile::VERSION, HistoricFixture::userVersion($file));
        self::assertCount(1, $snapshots->list($fixture->projectFolder()));
        $after = HistoricFixture::dump($file);
        foreach (self::without($before, '_schema', '_user_version', '_tiffin_meta') as $table => $rows) {
            self::assertSame($rows, $after[$table], "{$table} is the original.");
        }
        self::assertSame($before, HistoricFixture::dump($fixture->projectFolder() . '/snapshots/' . $snapshots->list($fixture->projectFolder())[0]->file));
    }

    // ------------------------------------------------------------ T-UPG-8

    public function testAVersionOneInstallationSignsInAndShowsItsProjectAndARecordThroughTheKernel(): void
    {
        $fixture = $this->build(1, 1);
        $config = Fixtures::config(false) + ['data_dir' => $fixture->data->path()];
        $services = new NoProbeServices(new CoreServices(
            $config,
            EndToEndTest::appRoot(),
            $this->clock,
            new SeededRandom('tiffindb-upgrade-path-kernel-1'),
            new PasswordHasher(['bcrypt' => 4]),
        ));
        $member = new EndToEndClient(new Kernel($config, EndToEndTest::appRoot(), null, $services));

        self::assertSame(200, $member->get('/sign-in')->status);
        $signedIn = $member->submit('/sign-in', ['username' => 'member', 'password' => HistoricFixture::PASSWORD]);
        self::assertSame(303, $signedIn->status, $signedIn->body);
        self::assertSame(SystemSchema::VERSION, HistoricFixture::systemVersion($fixture->data->systemDatabase()));
        self::assertCount(1, $this->systemCopies());

        $projects = $member->get('/projects');
        self::assertSame(200, $projects->status);
        self::assertContains(HistoricFixture::PROJECT_NAME, $member->texts('//main//a'));

        $record = $member->get($fixture->recordPath('Toaster'));
        self::assertSame(200, $record->status, $record->body);
        self::assertSame('Toaster', HtmlAssertions::text($record->body, '//main//h1'));
        self::assertStringContainsString('Kitchen', $record->body);
        self::assertStringContainsString(HistoricFixture::RECEIPT, $record->body);
        self::assertSame(ProjectFile::VERSION, HistoricFixture::userVersion($fixture->projectFile()));
        self::assertCount(1, $this->snapshots()->list($fixture->projectFolder()));
        self::assertSame(0, $services->probeRequests);
    }
}
