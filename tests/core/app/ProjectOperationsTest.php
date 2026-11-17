<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\ProjectOperations;
use TiffinDB\Core\App\ProjectSummary;
use TiffinDB\Core\App\SharingRow;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\System\ProjectRecord;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-10 to T-APP-13: projects. */
#[CoversClass(ProjectOperations::class)]
final class ProjectOperationsTest extends AppTestCase
{
    private const KEY_SEED = 'tiffindb-app-project-key-seed-1';

    public function testAdminCreatesAProject(): void
    {
        $project = $this->ops->projects()->create($this->admin, '  Example Garden  ', null);

        self::assertSame('Example Garden', $project->name);
        self::assertSame(ProjectRecord::STATE_ACTIVE, $project->state);
        self::assertSame($this->admin->userId, $project->createdBy);
        $folder = $this->fx->projectFolder($project->key);
        self::assertFileExists($folder . '/project.tiffin');
        self::assertDirectoryExists($folder . '/attachments');
        self::assertDirectoryExists($folder . '/snapshots');
        $db = ProjectFile::open($folder . '/project.tiffin', $project->key, new Snapshots(new FixedClock(), new Keys(new SeededRandom('s'))));
        $schema = new Schema($db, FieldTypes::standard());
        self::assertSame('Example Garden', $schema->projectName());
        self::assertSame($this->admin->userKey, $schema->meta('created_by'));
        self::assertSame([], $schema->tables());
        $db->close();

        $entry = $this->lastAudit('project.created');
        self::assertSame(['project', $project->key, $project->id, $this->admin->userId], [$entry->targetKind, $entry->targetKey, $entry->projectId, $entry->actorUserId]);
        self::assertNotContains('schema.table_created', $this->auditActions());
    }

    public function testAFirstTableIsCreatedWithItsNameField(): void
    {
        $project = $this->ops->projects()->create($this->admin, 'Example Recipes', 'Recipes');
        $tables = $this->ops->schema()->tables($this->admin, $project->key);

        self::assertCount(1, $tables);
        self::assertSame(['Recipes', 'item'], [$tables[0]->table->name, $tables[0]->table->itemName]);
        $design = $this->ops->schema()->design($this->admin, $project->key, $tables[0]->table->key);
        self::assertCount(1, $design->fields);
        self::assertSame(['Name', 'text'], [$design->fields[0]->field->name, $design->fields[0]->field->type]);
        self::assertSame($design->fields[0]->field->key, $design->table->titleFieldKey);
        $entry = $this->lastAudit('schema.table_created');
        self::assertSame(['table', $tables[0]->table->key, $project->id], [$entry->targetKind, $entry->targetKey, $entry->projectId]);
        self::assertSame(['project.created', 'schema.table_created'], array_slice($this->auditActions(), -2));
    }

    public function testNamesAreValidatedBeforeAnythingIsWritten(): void
    {
        $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $before = $this->fx->systemRows()['projects'];

        self::assertSame([['name', 'problem.project_name.taken', []]], self::problems(fn () => $this->ops->projects()->create($this->admin, 'EXAMPLE garden', null)));
        self::assertSame(
            [['name', 'problem.name.empty', []], ['first_table', 'problem.name.too_long', ['max' => 60]]],
            self::problems(fn () => $this->ops->projects()->create($this->admin, '   ', str_repeat('t', 61))),
        );
        self::assertSame([['name', 'problem.name.too_long', ['max' => 80]]], self::problems(fn () => $this->ops->projects()->create($this->admin, str_repeat('n', 81), '')));
        self::assertSame([['name', 'problem.text.control', []]], self::problems(fn () => $this->ops->projects()->create($this->admin, "Bad\u{0007}Name", null)));
        self::assertSame($before, $this->fx->systemRows()['projects']);
        self::assertCount(1, glob($this->fx->data->path() . '/projects/*') ?: []);
    }

    public function testAFailedCreateLeavesNoRowAndNoNewFiles(): void
    {
        $key = (new Keys(new SeededRandom(self::KEY_SEED)))->generate();
        $ops = $this->fx->operations(new SeededRandom(self::KEY_SEED));
        $folder = $this->fx->projectFolder($key);
        mkdir(dirname($folder), 0750);
        mkdir($folder, 0750);
        file_put_contents($folder . '/keep.txt', 'synthetic');

        $this->assertThrows(\RuntimeException::class, fn () => $ops->projects()->create($this->admin, 'Example Garden', 'Plants'));

        self::assertSame([], $this->fx->systemRows()['projects']);
        self::assertSame(['keep.txt'], array_values(array_diff(scandir($folder) ?: [], ['.', '..'])));
        self::assertSame([basename($folder)], array_values(array_diff(scandir(dirname($folder)) ?: [], ['.', '..'])));
        self::assertNotContains('project.created', $this->auditActions());
        // The name is free again.
        self::assertSame('Example Garden', $this->ops->projects()->create($this->admin, 'Example Garden', null)->name);
    }

    public function testAFailureInTheFolderStepRemovesTheRegistryRow(): void
    {
        $key = (new Keys(new SeededRandom(self::KEY_SEED)))->generate();
        $ops = $this->fx->operations(new SeededRandom(self::KEY_SEED));
        // projects/ is a file, so no project folder can be made below it.
        file_put_contents($this->fx->data->path() . '/projects', 'synthetic');

        $this->assertThrows(\RuntimeException::class, fn () => $ops->projects()->create($this->admin, 'Example Garden', null));

        self::assertSame([], $this->fx->systemRows()['projects']);
        self::assertSame('synthetic', file_get_contents($this->fx->data->path() . '/projects'));
        self::assertFileDoesNotExist($this->fx->projectFolder($key));
    }

    public function testAbandonedCreationsAreClearedByTheNextCreate(): void
    {
        $this->captureLog();
        $projects = $this->fx->data->path() . '/projects';
        mkdir($projects, 0750);
        $old = $this->creatingRow('aaaaaaaaaaaa', 'Example Old', '2026-03-02T08:59:59Z');
        mkdir("$projects/$old/attachments", 0750, true);
        mkdir("$projects/$old/snapshots", 0750);
        file_put_contents("$projects/$old/project.tiffin", 'synthetic');
        file_put_contents("$projects/$old/project.tiffin-wal", 'synthetic');
        $kept = $this->creatingRow('bbbbbbbbbbbb', 'Example Kept Folder', '2026-03-02T08:00:00Z');
        mkdir("$projects/$kept/snapshots", 0750, true);
        file_put_contents("$projects/$kept/snapshots/other.txt", 'synthetic');
        $young = $this->creatingRow('cccccccccccc', 'Example Young', '2026-03-02T09:00:01Z');
        mkdir("$projects/$young", 0750);
        file_put_contents("$projects/$young/project.tiffin", 'synthetic');

        // An hour and a second after the old row: the old name is free again.
        $created = $this->ops->projects()->create($this->admin, 'example old', null);

        self::assertSame('example old', $created->name);
        $keys = array_column($this->fx->systemRows()['projects'], 'key');
        sort($keys);
        $expected = [$young, $created->key];
        sort($expected);
        self::assertSame($expected, $keys);
        self::assertFileDoesNotExist("$projects/$old");
        self::assertFileExists("$projects/$kept/snapshots/other.txt");
        self::assertStringContainsString('abandoned project creation ' . $kept . ' left its folder in place', $this->errorLog());
        self::assertStringNotContainsString($projects, $this->errorLog());
        self::assertFileExists("$projects/$young/project.tiffin");
        self::assertSame([['name', 'problem.project_name.taken', []]], self::problems(fn () => $this->ops->projects()->create($this->admin, 'Example Young', null)));
    }

    public function testAMemberCannotCreateAProject(): void
    {
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->projects()->create($this->member, 'Example Garden', null));
        self::assertSame([], $this->fx->systemRows()['projects']);
    }

    public function testAnAdminDemotedAfterResumingCannotCreate(): void
    {
        $this->fx->system->db()->write(fn () => $this->fx->system->db()->run("UPDATE users SET role = 'member' WHERE username = 'admin'"));
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->projects()->create($this->admin, 'Example Garden', null));
        self::assertSame([], $this->fx->systemRows()['projects']);
    }

    public function testListForShowsAdminsEverythingAndMembersTheirGrants(): void
    {
        $garden = $this->ops->projects()->create($this->admin, 'Example Garden', 'Plants');
        $books = $this->ops->projects()->create($this->admin, 'Example Books', 'Books');
        $missing = $this->ops->projects()->create($this->admin, 'Example Missing', null);
        $this->ops->projects()->setSharing($this->admin, $garden->key, [$this->member->userKey, $this->member2->userKey]);
        $this->ops->projects()->setSharing($this->admin, $missing->key, [$this->member->userKey]);

        $plants = $this->ops->schema()->tables($this->admin, $garden->key)[0]->table;
        $this->ops->schema()->createTable($this->admin, $garden->key, 'Beds', 'bed', false);
        $this->fx->insertRecord($garden->key, $plants->key);
        $this->fx->insertRecord($garden->key, $plants->key);
        $this->fx->insertRecord($garden->key, $plants->key, [], true);
        $this->fx->clock->advance(60);
        $this->ops->projects()->rename($this->admin, $garden->key, 'Example Garden');
        $this->ops->close();
        unlink($this->fx->projectFolder($missing->key) . '/project.tiffin');

        $admin = $this->ops->projects()->listFor($this->admin);
        self::assertSame(['Example Books', 'Example Garden', 'Example Missing'], array_map(static fn (ProjectSummary $s): string => $s->project->name, $admin));
        [$bookRow, $gardenRow, $missingRow] = $admin;
        self::assertTrue($gardenRow->available);
        self::assertSame(['Plants', 'Beds'], $gardenRow->tableNames);
        self::assertSame(2, $gardenRow->recordCount);
        self::assertSame('2026-03-02T10:01:00Z', $gardenRow->updatedAt);
        self::assertSame('Example Admin', $gardenRow->updatedByName);
        self::assertSame(2, $gardenRow->memberCount);
        self::assertSame([0, 0], [$bookRow->recordCount, $bookRow->memberCount]);
        self::assertFalse($missingRow->available);
        self::assertSame([[], 0, null, null, 1], [$missingRow->tableNames, $missingRow->recordCount, $missingRow->updatedAt, $missingRow->updatedByName, $missingRow->memberCount]);

        $member = $this->ops->projects()->listFor($this->member);
        self::assertSame(['Example Garden', 'Example Missing'], array_map(static fn (ProjectSummary $s): string => $s->project->name, $member));
        self::assertSame(['Example Garden'], array_map(static fn (ProjectSummary $s): string => $s->project->name, $this->ops->projects()->listFor($this->member2)));

        // A deactivated Member's grant no longer counts.
        $this->ops->people()->deactivate($this->admin, $this->member2->userKey);
        self::assertSame(1, $this->ops->projects()->listFor($this->admin)[1]->memberCount);
    }

    public function testRenameUpdatesTheFileAndTheRegistry(): void
    {
        $project = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $other = $this->ops->projects()->create($this->admin, 'Example Books', null);
        $this->fx->clock->advance(5);

        $renamed = $this->ops->projects()->rename($this->admin, $project->key, ' Example Allotment ');

        self::assertSame('Example Allotment', $renamed->name);
        self::assertSame('Example Allotment', $this->ops->projects()->get($this->admin, $project->key)->name);
        $db = $this->fx->projectDb($project->key);
        self::assertSame('Example Allotment', $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'project_name\''));
        self::assertSame('2026-03-02T10:00:05Z', $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'updated_at\''));
        self::assertSame([['name', 'problem.project_name.taken', []]], self::problems(fn () => $this->ops->projects()->rename($this->admin, $project->key, 'EXAMPLE BOOKS')));
        // Same name in another case is the project's own name: allowed.
        self::assertSame('example allotment', $this->ops->projects()->rename($this->admin, $project->key, 'example allotment')->name);
        self::assertSame('Example Books', $this->ops->projects()->get($this->admin, $other->key)->name);
        $entry = $this->lastAudit('project.renamed');
        self::assertSame(['project', $project->key, $project->id], [$entry->targetKind, $entry->targetKey, $entry->projectId]);
        $this->assertThrows(AccessDenied::class, fn () => $this->grantedMemberRenames($project->key));
    }

    public function testSharingAddsAndRemovesGrantsForActiveMembersOnly(): void
    {
        $project = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $projects = $this->ops->projects();

        $projects->setSharing($this->admin, $project->key, [$this->member->userKey, $this->member2->userKey, $this->admin->userKey, 'zzzzzzzzzzzz', 'not a key']);
        self::assertSame(['Another Member' => true, 'Example Member' => true], $this->sharingMap($project->key));
        self::assertSame(['added' => 2, 'removed' => 0], $this->lastAudit('project.sharing_changed')->detail);
        self::assertSame(2, (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM project_grants'));

        // An inactive Member keeps the grant even when left out.
        $this->ops->people()->deactivate($this->admin, $this->member2->userKey);
        $projects->setSharing($this->admin, $project->key, []);
        self::assertSame(['Another Member' => true, 'Example Member' => false], $this->sharingMap($project->key));
        self::assertSame(['added' => 0, 'removed' => 1], $this->lastAudit('project.sharing_changed')->detail);
        $entry = $this->lastAudit('project.sharing_changed');
        self::assertSame(['project', $project->key, $project->id], [$entry->targetKind, $entry->targetKey, $entry->projectId]);
        // An inactive Member cannot be given a grant either.
        $this->ops->people()->reactivate($this->admin, $this->member2->userKey);
        $projects->setSharing($this->admin, $project->key, [$this->member->userKey]);
        self::assertSame(['Another Member' => false, 'Example Member' => true], $this->sharingMap($project->key));

        $rows = $projects->sharing($this->admin, $project->key);
        self::assertContainsOnlyInstancesOf(SharingRow::class, $rows);
        $this->assertThrows(AccessDenied::class, fn () => $projects->sharing($this->member, $project->key));
        $this->assertThrows(AccessDenied::class, fn () => $projects->setSharing($this->member, $project->key, [$this->member->userKey]));
    }

    public function testAnUngrantedMemberGetsNotFound(): void
    {
        $project = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $projects = $this->ops->projects();

        $this->assertThrows(NotFound::class, fn () => $projects->get($this->member, $project->key));
        $this->assertThrows(NotFound::class, fn () => $projects->rename($this->member, $project->key, 'Example Other'));
        $this->assertThrows(NotFound::class, fn () => $projects->sharing($this->member, $project->key));
        $this->assertThrows(NotFound::class, fn () => $projects->get($this->admin, 'zzzzzzzzzzzz'));
        $this->assertThrows(NotFound::class, fn () => $projects->get($this->admin, '../../etc'));
        $projects->setSharing($this->admin, $project->key, [$this->member->userKey]);
        self::assertSame($project->key, $projects->get($this->member, $project->key)->key);
    }

    private function grantedMemberRenames(string $projectKey): void
    {
        $this->ops->projects()->setSharing($this->admin, $projectKey, [$this->member->userKey]);
        $this->ops->projects()->rename($this->member, $projectKey, 'Example Other');
    }

    /** @return array<string, bool> display name => granted */
    private function sharingMap(string $projectKey): array
    {
        $map = [];
        foreach ($this->ops->projects()->sharing($this->admin, $projectKey) as $row) {
            $map[$row->member->displayName] = $row->granted;
        }

        return $map;
    }

    private function creatingRow(string $key, string $name, string $createdAt): string
    {
        $db = $this->fx->system->db();
        $db->write(static fn () => $db->run(
            'INSERT INTO projects (key, name, state, created_at, created_by) VALUES (?, ?, ?, ?, NULL)',
            [$key, $name, ProjectRecord::STATE_CREATING, $createdAt],
        ));

        return $key;
    }
}
