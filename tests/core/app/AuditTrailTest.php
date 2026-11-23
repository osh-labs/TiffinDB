<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\AuditOperations;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditEntry;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FileFixture;

require_once __DIR__ . '/AppTestCase.php';
require_once __DIR__ . '/ImportOperationsTest.php';

/**
 * T-APP-60: every action of M1 spec section 5.6 is written by the
 * operation that should write it, with its actor, project, target, and
 * detail. T-AUD-2: no password or session token reaches the system
 * database outside the hashes made for them.
 */
#[CoversClass(AuditOperations::class)]
#[CoversClass(Operations::class)]
final class AuditTrailTest extends AppTestCase
{
    private const NEW = 'a new horse 22';
    private const TEMPORARY = 'temporary pass 7';

    public function testEveryActionIsWrittenByItsOperation(): void
    {
        $seen = [];
        $expect = function (string $action, ?int $actor, ?int $project, ?string $kind, ?string $key, array $detail = []) use (&$seen): void {
            $entry = $this->ops->audit()->recent($this->admin, 1)[0];
            self::assertSame(
                [$action, $actor, $project, $kind, $key, $detail],
                [$entry->action, $entry->actorUserId, $entry->projectId, $entry->targetKind, $entry->targetKey, $entry->detail],
                $action,
            );
            $seen[$action] = true;
        };
        $auth = $this->ops->auth();
        $admin = $this->admin->userId;

        $session = $this->fx->signIn($this->ops, 'member');
        $expect(AuditAction::SIGN_IN, $this->member->userId, null, 'user', $this->member->userKey);
        $auth->signIn('member', 'wrong horse 1', CoreFixture::ADDRESS);
        $expect(AuditAction::SIGN_IN_FAILED, $this->member->userId, null, 'user', $this->member->userKey, ['reason' => 'bad-password']);
        $auth->signIn('nobody', 'wrong horse 1', CoreFixture::ADDRESS);
        $expect(AuditAction::SIGN_IN_FAILED, null, null, null, null, ['reason' => 'unknown-user']);
        $issued = $auth->changeOwnPassword($this->member, $session->token(), CoreFixture::PASSWORD, self::NEW);
        $expect(AuditAction::PASSWORD_CHANGED, $this->member->userId, null, 'user', $this->member->userKey);
        $auth->signOut($issued->token());
        $expect(AuditAction::SIGN_OUT, $this->member->userId, null, 'user', $this->member->userKey);

        $people = $this->ops->people();
        $cook = $people->create($this->admin, 'example.cook', 'Example Cook', Role::Member, self::TEMPORARY, []);
        $expect(AuditAction::PEOPLE_CREATED, $admin, null, 'user', $cook->key, ['role' => 'member']);
        $people->update($this->admin, $cook->key, 'example.chef', 'Example Cook', Role::Admin);
        $expect(AuditAction::PEOPLE_UPDATED, $admin, null, 'user', $cook->key, ['role' => 'admin', 'username_changed' => true, 'name_changed' => false]);
        $people->update($this->admin, $cook->key, 'example.chef', 'Example Chef', Role::Member);
        $people->setTemporaryPassword($this->admin, $cook->key, self::TEMPORARY);
        $expect(AuditAction::PEOPLE_PASSWORD_SET, $admin, null, 'user', $cook->key);
        $people->deactivate($this->admin, $cook->key);
        $expect(AuditAction::PEOPLE_DEACTIVATED, $admin, null, 'user', $cook->key);
        $people->reactivate($this->admin, $cook->key);
        $expect(AuditAction::PEOPLE_REACTIVATED, $admin, null, 'user', $cook->key);

        $projects = $this->ops->projects();
        $project = $projects->create($this->admin, 'Example Garden', null);
        $expect(AuditAction::PROJECT_CREATED, $admin, $project->id, 'project', $project->key);
        $people->setProjectAccess($this->admin, $cook->key, [$project->key]);
        $expect(AuditAction::PEOPLE_ACCESS_CHANGED, $admin, null, 'user', $cook->key, ['added' => 1, 'removed' => 0]);
        $projects->rename($this->admin, $project->key, 'Example Allotment');
        $expect(AuditAction::PROJECT_RENAMED, $admin, $project->id, 'project', $project->key);
        $projects->setSharing($this->admin, $project->key, [$this->member->userKey]);
        $expect(AuditAction::PROJECT_SHARING_CHANGED, $admin, $project->id, 'project', $project->key, ['added' => 1, 'removed' => 1]);

        $schema = $this->ops->schema();
        $p = $project->key;
        $plants = $schema->createTable($this->admin, $p, 'Plants', 'plant', true);
        $expect(AuditAction::TABLE_CREATED, $admin, $project->id, 'table', $plants->key);
        $schema->updateTable($this->admin, $p, $plants->key, 'Herbs', 'herb', null);
        $expect(AuditAction::TABLE_CHANGED, $admin, $project->id, 'table', $plants->key);
        $height = $schema->addField($this->admin, $p, $plants->key, 'Height', 'text', [], false, '');
        $expect(AuditAction::FIELD_CREATED, $admin, $project->id, 'field', $height->key, ['type' => 'text']);
        $schema->updateField($this->admin, $p, $plants->key, $height->key, 'Height (cm)', [], false, '', 0);
        $expect(AuditAction::FIELD_CHANGED, $admin, $project->id, 'field', $height->key, ['type' => 'text']);
        $this->fx->insertRecord($p, $plants->key, [$height->key => 'tall']);
        $schema->changeFieldType($this->admin, $p, $plants->key, $height->key, 'number', [], 1);
        $expect(AuditAction::FIELD_CHANGED, $admin, $project->id, 'field', $height->key, ['type' => 'number', 'type_from' => 'text', 'values_cleared' => 1]);
        $before = $this->ops->audit()->recent($this->admin, 2)[1];
        self::assertSame([AuditAction::SNAPSHOT_TAKEN, ['reason' => 'field-type-change']], [$before->action, $before->detail]);
        $schema->moveField($this->admin, $p, $plants->key, $height->key, -1);
        $expect(AuditAction::FIELD_MOVED, $admin, $project->id, 'field', $height->key, ['type' => 'number']);
        $beds = $schema->createTable($this->admin, $p, 'Beds', 'bed', true);
        $link = $schema->addRelationField($this->admin, $p, $plants->key, 'Bed', $beds->key, Cardinality::One, Cardinality::Many, 'Herbs', false, '');
        $expect(AuditAction::FIELD_CREATED, $admin, $project->id, 'field', $link->key, ['type' => 'relation']);
        $schema->deleteField($this->admin, $p, $plants->key, $link->key);
        $expect(AuditAction::FIELD_DELETED, $admin, $project->id, 'field', $link->key, ['type' => 'relation']);
        $snapshot = $this->ops->snapshots()->take($this->admin, $p);
        $expect(AuditAction::SNAPSHOT_TAKEN, $admin, $project->id, 'snapshot', $snapshot->file, ['reason' => 'manual']);
        $this->fx->insertRecord($p, $plants->key, [], true);
        $this->ops->trash()->empty($this->admin, $p, null);
        $expect(AuditAction::TRASH_EMPTIED, $admin, $project->id, null, null, ['records' => 1, 'files' => 0]);
        $schema->deleteTable($this->admin, $p, $beds->key);
        $expect(AuditAction::TABLE_DELETED, $admin, $project->id, 'table', $beds->key, ['records' => 0]);

        // M3 (spec section 13.6): no entry holds a cell value or a file name.
        $plugins = $this->ops->plugins();
        $plugins->disable($this->admin, 'export', 'json');
        $expect(AuditAction::PLUGIN_DISABLED, $admin, null, 'plugin', 'export/json');
        $plugins->enable($this->admin, 'export', 'json');
        $expect(AuditAction::PLUGIN_ENABLED, $admin, null, 'plugin', 'export/json');
        $imports = $this->ops->imports();
        $job = $imports->start($this->admin, $p, $this->fx->upload("Name\nSynthetic basil\n"), 'basil.csv', null);
        $imports->saveMapping($this->admin, $p, $job, ImportOperationsTest::input($imports->review($this->admin, $p, $job, null, [])));
        while (!$imports->prepareChunk($this->admin, $p, $job)->finished) {
        }
        $report = $imports->report($this->admin, $p, $imports->commit($this->admin, $p, $job));
        $expect(AuditAction::IMPORT_COMPLETED, $admin, $project->id, 'table', $report->report->tableKey, ['plugin' => 'import/csv', 'rows' => 1, 'new_table' => true]);
        $exports = $this->ops->exports();
        $file = $exports->export($this->admin, $p, 'export/csv', $plants->key, []);
        $expect(AuditAction::EXPORT_CREATED, $admin, $project->id, 'table', $plants->key, ['format' => 'export/csv', 'scope' => 'table', 'records' => 1]);
        $exports->finish($this->admin, $p, $file->jobKey);
        $archive = $exports->archive($this->admin, $p);
        $expect(AuditAction::ARCHIVE_EXPORTED, $admin, $project->id, 'project', $p, ['attachments' => 0]);
        $copy = $this->ops->archives()->import($this->admin, $this->fx->upload((string) file_get_contents($archive->path)), 'garden.zip', 'Example Garden copy');
        $expect(AuditAction::ARCHIVE_IMPORTED, $admin, $copy->project->id, 'project', $copy->project->key, [
            'tables' => 2, 'records' => 2, 'values_emptied' => 0, 'links_dropped' => 0, 'forms_dropped' => 0, 'files_missing' => 0,
        ]);
        $exports->finish($this->admin, $p, $archive->jobKey);

        // M4 (spec section 10.8, P169): ids, sizes, and counts only; never a file name or a rule value.
        $photos = $schema->addField($this->admin, $p, $plants->key, 'Photos', 'attachment', [], false, '');
        $plant = $this->fx->insertRecord($p, $plants->key, []);
        $png = FileFixture::png();
        $attachments = $this->ops->attachments();
        $file = $attachments->add($this->admin, $p, $plants->key, (string) $plant, $photos->key, $this->fx->upload($png), 'synthetic-leaf.png', null);
        $fileDetail = ['field' => $photos->key, 'record' => $plant, 'attachment' => $file->id, 'size' => strlen($png)];
        $expect(AuditAction::ATTACHMENT_ADDED, $admin, $project->id, 'table', $plants->key, $fileDetail);
        $attachments->remove($this->admin, $p, $plants->key, (string) $plant, $photos->key, (string) $file->id);
        $expect(AuditAction::ATTACHMENT_REMOVED, $admin, $project->id, 'table', $plants->key, $fileDetail);
        $collections = $this->ops->collections();
        $draft = ['name' => 'Synthetic tall herbs', 'match' => 'any', 'rules' => [['field' => $height->key, 'test' => 'more', 'value' => '987']]];
        $collection = $collections->save($this->admin, $p, $plants->key, null, $draft);
        $expect(AuditAction::COLLECTION_SAVED, $admin, $project->id, 'collection', $collection, ['table' => $plants->key, 'rules' => 1, 'match' => 'any']);
        $collections->delete($this->admin, $p, $collection);
        $expect(AuditAction::COLLECTION_DELETED, $admin, $project->id, 'collection', $collection, ['table' => $plants->key, 'rules' => 1, 'match' => 'any']);

        // M5 (spec section 9.6, T-APP-175): the table, the source, and the generated flag; never form text.
        $forms = $this->ops->forms();
        $screen = $forms->source($this->admin, $p, $plants->key, ['text' => '<div data-tiffin-form="1"><p>Synthetic form note</p></div>', 'base' => $forms->source($this->admin, $p, $plants->key)->base, 'do' => 'save']);
        $formKey = (string) $this->fx->projectDb($p)->value('SELECT "key" FROM "_tiffin_forms" WHERE "is_default" = 1 AND "table_key" = ?', [$plants->key]);
        self::assertNotNull($screen->saved);
        $expect(AuditAction::FORM_SAVED, $admin, $project->id, 'form', $formKey, ['table' => $plants->key, 'source' => 'source', 'generated' => 0]);
        self::assertTrue($forms->reset($this->admin, $p, $plants->key));
        $expect(AuditAction::FORM_RESET, $admin, $project->id, 'form', $formKey, ['table' => $plants->key]);

        // M7 (spec section 11.2): file names and counts; project.deleted alone keeps a name (the project's).
        $restored = $this->ops->snapshots()->restore($this->admin, $p, substr($snapshot->file, 0, -strlen('.tiffin')));
        $expect(AuditAction::SNAPSHOT_RESTORED, $admin, $project->id, 'snapshot', $snapshot->file, [
            'pre_restore' => $restored->preRestore->file, 'files_missing' => $restored->filesMissing, 'files_removed' => $restored->filesRemoved, 'from_version' => 4,
        ]);
        $shed = $projects->create($this->admin, 'Example Shed', null);
        $projects->delete($this->admin, $shed->key, 'example shed', 0);
        $expect(AuditAction::PROJECT_DELETED, $admin, null, 'project', $shed->key, ['name' => 'Example Shed', 'records' => 0, 'trashed' => 0, 'files' => 0, 'snapshots' => 0]);

        $all = json_encode($this->auditEntries(), JSON_THROW_ON_ERROR);
        foreach (['Synthetic basil', 'basil.csv', 'garden.zip', '.csv', 'synthetic-leaf', 'Synthetic tall', '987', 'Synthetic form note'] as $text) {
            self::assertStringNotContainsString($text, $all);
        }

        // setup.complete is the installer's (M1-WP6); every other action is written above.
        self::assertSame(
            array_values(array_diff(AuditAction::ALL, [AuditAction::SETUP_COMPLETE])),
            array_values(array_intersect(AuditAction::ALL, array_keys($seen))),
        );
    }

    public function testRecentIsAdminOnlyAndBounded(): void
    {
        $this->fx->signIn($this->ops, 'member');
        $audit = $this->ops->audit();
        $this->assertThrows(AccessDenied::class, fn () => $audit->recent($this->member));
        self::assertCount(1, $audit->recent($this->admin));
        self::assertCount(1, $audit->recent($this->admin, 500));
        self::assertCount(0, $audit->recent($this->admin, 10, 1));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $audit->recent($this->admin, 501));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $audit->recent($this->admin, 0));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $audit->recent($this->admin, 10, -1));
    }

    public function testNoPasswordOrTokenReachesTheSystemDatabase(): void
    {
        $auth = $this->ops->auth();
        $tokens = [];
        $member = $this->fx->signIn($this->ops, 'member');
        $tokens[] = $member->token();
        $tokens[] = $this->fx->signIn($this->ops, 'admin')->token();
        $auth->signIn('member', 'wrong horse 9', CoreFixture::ADDRESS);
        $issued = $auth->changeOwnPassword($this->member, $member->token(), CoreFixture::PASSWORD, self::NEW);
        $tokens[] = $issued->token();
        $this->ops->people()->setTemporaryPassword($this->admin, $this->member2->userKey, self::TEMPORARY);
        $tokens[] = $this->fx->signIn($this->ops, 'member2', self::TEMPORARY)->token();
        $auth->setFlash($tokens[3], 'notice.saved');
        $auth->signOut($tokens[1]);
        $secrets = [CoreFixture::PASSWORD, 'wrong horse 9', self::NEW, self::TEMPORARY, ...$tokens];

        $rows = $this->fx->systemRows();
        self::assertNotEmpty($rows['audit_log']);
        self::assertNotEmpty($rows['sessions']);
        foreach ($rows as $table => $tableRows) {
            foreach ($tableRows as $row) {
                foreach ($row as $column => $value) {
                    foreach ($secrets as $secret) {
                        self::assertStringNotContainsString($secret, (string) $value, $table . '.' . $column);
                        self::assertStringNotContainsString(strtolower($secret), strtolower((string) $value), $table . '.' . $column);
                    }
                }
            }
        }
        foreach ($this->ops->audit()->recent($this->admin, 500) as $entry) {
            $detail = json_encode($entry->detail, JSON_THROW_ON_ERROR);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $detail);
            }
        }
        self::assertContainsOnlyInstancesOf(AuditEntry::class, $this->ops->audit()->recent($this->admin));
    }
}
