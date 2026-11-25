<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Storage\ProjectLock;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\LockHolder;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\SnapshotController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;
use TiffinDB\Web\Strings;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-85, T-WEB-87, and T-WEB-90 (snapshot routes) of the M7 screens spec
 * section 11.1 (M7-WP4): the snapshots screen and the restore confirmation
 * of docs/design/m7/snapshots.md, through the kernel with the real
 * templates and strings and real core operations over a CoreFixture
 * instance. The list with rows in order and reasons in words, the empty
 * list, taking a snapshot (303, notice, one new manual snapshot), the
 * confirmation with its counts, a malformed `{snapshot}` (404 before any
 * core call: a Member gets 404, not 403), a damaged snapshot (422, no
 * form), a server that cannot lock files, a restore (303, notice, the
 * restored data), a project in use (503 with Retry-After), a Member's 403,
 * an ungranted person's 404, and every refused POST (CSRF token, Origin,
 * Sec-Fetch-Site) changing nothing. Synthetic data only.
 */
#[CoversClass(SnapshotController::class)]
final class SnapshotScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const STRANGER = 'tokenCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $itemName;
    /** @var list<LockHolder> */
    private array $holders = [];

    protected function setUp(): void
    {
        ProjectLock::$waitScaleForTests = 0.02;
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $this->ops = $this->core->operations();
        $this->core->addPerson('stranger', 'Example Stranger', Role::Member);
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->services->sessions[self::STRANGER] = $this->signedIn('stranger');
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
        $this->project = $this->ops->projects()->create($this->admin(), 'Home Inventory', 'Items')->key;
        $this->items = $this->ops->schema()->tables($this->admin(), $this->project)[0]->table->key;
        $this->itemName = $this->ops->schema()->design($this->admin(), $this->project, $this->items)->fields[0]->field->key;
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);
    }

    protected function tearDown(): void
    {
        foreach ($this->holders as $holder) {
            $holder->stop();
        }
        ProjectLock::$waitScaleForTests = 1.0;
        ProjectLock::$flockDisabledForTests = false;
        $this->core->remove();
    }

    // The list (snapshots.md section 2).

    public function testTheEmptyList(): void
    {
        $x = $this->xpath($this->page(self::ADMIN, $this->s()));

        self::assertSame(['Home Inventory', 'Snapshots'], $this->crumbs($x));
        self::assertSame(['/projects/' . $this->project], $this->texts($x, '//nav[@class="breadcrumb"]/a/@href'));
        self::assertSame('page page-settings', $this->attr($x, '//main', 'class'));
        self::assertSame('Snapshots', $this->text($x, '//main//h1'));
        self::assertStringStartsWith('A snapshot is a saved copy of this project', $this->text($x, '//main/p[@class="lead"]'));
        self::assertStringStartsWith('Snapshots do not hold the stored files.', $this->text($x, '//main/p[@class="help"]'));
        self::assertSame('No snapshots yet.', $this->text($x, '//div[@class="empty-state"]/p[@class="empty-state-title"]'));
        self::assertSame(0, $x->query('//table | //div[contains(@class, "notice-warn")]')->length);
        $form = '//main/form';
        self::assertSame($this->s(), $this->attr($x, $form, 'action'));
        self::assertSame(['Take a snapshot now'], $this->texts($x, $form . '/div[@class="form-actions"]/button[@class="button button-primary"][@type="submit"]'));
        self::assertSame(0, $x->query($form . '//a')->length, 'No Cancel.');
    }

    public function testTheListNewestFirstWithReasonsInWords(): void
    {
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $this->core->clock->set('2026-11-03T11:30:00Z');
        $this->trash($this->add('Stand mixer'));
        $emptied = $this->ops->trash()->empty($this->admin(), $this->project, null, 10);
        self::assertSame(1, $emptied->records);

        $x = $this->xpath($this->page(self::ADMIN, $this->s()));
        $rows = '//main/div[@class="table-scroll"]/table[@class="data-table snapshot-table"]/tbody/tr';
        self::assertSame('Snapshots, newest first', $this->text($x, '//table/caption[@class="visually-hidden"]'));
        self::assertSame(['Saved', 'Why', 'Size', 'Action'], $this->texts($x, '//table/thead/tr/th'));
        self::assertSame(['3 Nov 2026, 11:30', '3 Nov 2026, 10:00'], $this->texts($x, $rows . '/td[1]/time'));
        self::assertSame(['2026-11-03T11:30:00Z', '2026-11-03T10:00:00Z'], $this->texts($x, $rows . '/td[1]/time/@datetime'));
        self::assertSame(['Before the trash was emptied', 'Taken by an Admin'], $this->texts($x, $rows . '/td[2]'));
        self::assertSame(['num', 'num'], $this->texts($x, $rows . '/td[3]/@class'));
        foreach ($this->texts($x, $rows . '/td[3]') as $size) {
            self::assertMatchesRegularExpression('/^\d[\d,.]* (bytes|KB|MB)$/', $size);
        }
        self::assertSame(['Restore…', 'Restore…'], $this->texts($x, $rows . '/td[4]/a'));
        self::assertSame(['Restore the snapshot saved 3 Nov 2026, 11:30', 'Restore the snapshot saved 3 Nov 2026, 10:00'], $this->texts($x, $rows . '/td[4]/a/@aria-label'));
        self::assertSame($this->restorePath($manual), $this->texts($x, $rows . '/td[4]/a/@href')[1]);
        self::assertSame(0, $x->query('//div[@class="empty-state"]')->length);
    }

    public function testEveryReasonHasWordsAndTheFormatVersionIsTheCores(): void
    {
        $strings = new Strings(Fixtures::appRoot() . '/strings/en.php');
        foreach (SnapshotReason::cases() as $reason) {
            self::assertTrue($strings->has('snapshots.reason.' . $reason->value), $reason->value);
        }
        self::assertSame(ProjectFile::VERSION, SnapshotController::FORMAT_VERSION);
    }

    public function testTakingASnapshot(): void
    {
        $this->page(self::ADMIN, $this->s());
        $response = $this->request(self::ADMIN, 'POST', $this->s());
        self::assertSame(303, $response->status);
        self::assertSame($this->s(), $response->header('Location'));
        self::assertSame(['key' => 'notice.snapshot_taken', 'params' => ['when' => '3 Nov 2026, 10:00']], $this->services->flash[self::ADMIN]);
        $list = $this->ops->snapshots()->list($this->admin(), $this->project);
        self::assertCount(1, $list);
        self::assertSame(SnapshotReason::Manual, $list[0]->reason);

        $x = $this->xpath($this->page(self::ADMIN, $this->s()));
        self::assertSame('A snapshot was saved: 3 Nov 2026, 10:00.', $this->text($x, '//main/div[@class="notice"][@role="status"]/p'));
        self::assertSame(['Taken by an Admin'], $this->texts($x, '//table/tbody/tr/td[2]'));
    }

    public function testTheListWhereLocksDoNotWork(): void
    {
        $this->ops->snapshots()->take($this->admin(), $this->project);
        $this->ops->close();
        ProjectLock::$flockDisabledForTests = true;

        [$response, $log] = $this->handle(self::ADMIN, 'GET', $this->s());
        self::assertSame(200, $response->status);
        self::assertStringContainsString('cannot lock files', $log);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame(
            'This server cannot lock files, so restoring in TiffinDB is turned off here. The host documentation describes restoring by hand.',
            $this->text($x, '//main/div[@class="notice notice-warn"][@role="alert"]/p'),
        );
        self::assertSame(['Saved', 'Why', 'Size'], $this->texts($x, '//table/thead/tr/th'));
        self::assertSame(0, $x->query('//table//a')->length, 'No Restore links.');
        self::assertSame(1, $x->query('//main/form[@action="' . $this->s() . '"]')->length, 'Taking a snapshot still works.');
    }

    // The confirmation (snapshots.md section 3).

    public function testTheConfirmationWithItsCounts(): void
    {
        $field = $this->ops->schema()->addField($this->admin(), $this->project, $this->items, 'Photo', 'attachment', [], false, '')->key;
        $mixer = $this->add('Stand mixer');
        $old = $this->ops->attachments()->add($this->admin(), $this->project, $this->items, (string) $mixer, $field, $this->core->upload('example old file'), 'old.txt', null);
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $this->ops->attachments()->remove($this->admin(), $this->project, $this->items, (string) $mixer, $field, (string) $old->id);
        $this->ops->attachments()->add($this->admin(), $this->project, $this->items, (string) $mixer, $field, $this->core->upload('example new file'), 'new.txt', null);
        $this->add('Kettle');
        $this->add('Lamp');

        $x = $this->xpath($this->page(self::ADMIN, $this->restorePath($manual)));
        self::assertSame('Restore this snapshot? - TiffinDB', $this->text($x, '//title'));
        self::assertSame(['Home Inventory', 'Snapshots', 'Restore'], $this->crumbs($x));
        self::assertSame(['/projects/' . $this->project, $this->s()], $this->texts($x, '//nav[@class="breadcrumb"]/a/@href'));
        self::assertSame('page page-record page-record-narrow', $this->attr($x, '//main', 'class'));
        self::assertSame(['Saved', 'Why', 'Records'], $this->texts($x, '//main/dl[@class="facts"]/div/dt'));
        self::assertSame(['3 Nov 2026, 10:00', 'Taken by an Admin', '1 record in the snapshot, 3 records now'], $this->texts($x, '//main/dl[@class="facts"]/div/dd'));
        self::assertSame(
            [
                'Home Inventory will go back to how it was when this snapshot was saved. Everything changed since then will be undone: records, tables and fields, forms, collections, and the trash. TiffinDB first saves a snapshot of the project as it is now, so you can come back to it.',
                '1 stored file was added after this snapshot and will be deleted.',
                '1 file this snapshot names is no longer stored and will show as missing.',
                'People using this project now may need to reload their page. If someone is saving a change at this moment, you will be asked to try again.',
            ],
            $this->texts($x, '//main/p'),
        );
        $form = '//main/div[@class="danger-zone"]/form';
        self::assertSame($this->restorePath($manual), $this->attr($x, $form, 'action'));
        self::assertSame(['_csrf' => FakeServices::SESSION_CSRF], $this->hidden($x, $form));
        self::assertSame(['Restore this snapshot'], $this->texts($x, $form . '/div[@class="form-actions"]/button[@class="button button-large button-danger"]'));
        self::assertSame($this->s(), $this->attr($x, $form . '/div[@class="form-actions"]/a[@class="form-cancel"]', 'href'));
        self::assertSame(0, $x->query('//button[contains(@class, "button-primary")]')->length);
        self::assertCount(1, $this->ops->snapshots()->list($this->admin(), $this->project), 'Showing the page changed nothing.');
    }

    public function testAnOlderSnapshotSaysItWillBeBroughtUpToDate(): void
    {
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $file = $this->core->projectFolder($this->project) . '/snapshots/' . $manual->file;
        $db = new \PDO('sqlite:' . $file);
        $db->exec('PRAGMA user_version = ' . (ProjectFile::VERSION - 1));
        $db->exec('UPDATE "_tiffin_meta" SET "value" = \'' . (ProjectFile::VERSION - 1) . '\' WHERE "key" = \'format_version\'');
        $db = null;

        $x = $this->xpath($this->page(self::ADMIN, $this->restorePath($manual)));
        self::assertSame(
            ['This snapshot was saved by an earlier version of TiffinDB. It will be brought up to date after the restore.', 'People using this project now may need to reload their page. If someone is saving a change at this moment, you will be asked to try again.'],
            $this->texts($x, '//main/p[@class="help"]'),
        );
    }

    public function testAMalformedSnapshotSegmentIsNotFoundBeforeTheCore(): void
    {
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $id = substr($manual->file, 0, -strlen('.tiffin'));
        foreach (
            [
                'a', $id . '.tiffin', strtoupper($id), $id . 'x', '20261103T100000Z-manual-abc1', '20261103T100000Z--abcd',
                '20261103T100000Z-Manual-abcd', '2026110T100000Z-manual-abcd',
            ] as $segment
        ) {
            foreach (['GET', 'POST'] as $method) {
                // A Member would get 403 from the core: 404 shows the core was not asked.
                self::assertSame(404, $this->request(self::MEMBER, $method, $this->s() . '/' . rawurlencode($segment) . '/restore')->status, "{$method} {$segment}");
                self::assertSame(404, $this->request(self::ADMIN, $method, $this->s() . '/' . rawurlencode($segment) . '/restore')->status, "{$method} {$segment}");
            }
        }
        // The right shape, but no such snapshot: the core says 404.
        self::assertSame(404, $this->request(self::ADMIN, 'GET', $this->s() . '/20261103T100000Z-manual-abcd/restore')->status);
        self::assertSame(404, $this->request(self::ADMIN, 'POST', $this->s() . '/20261103T100000Z-manual-abcd/restore')->status);
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->s() . '/20261103T100000Z-manual-abcd/restore')->status);
        self::assertSame([$manual->file], array_map(static fn (SnapshotInfo $s): string => $s->file, $this->ops->snapshots()->list($this->admin(), $this->project)));
    }

    public function testADamagedSnapshotIsRefused(): void
    {
        $this->add('Stand mixer');
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $this->add('Kettle');
        file_put_contents($this->core->projectFolder($this->project) . '/snapshots/' . $manual->file, str_repeat('x', 8192));

        foreach (['GET', 'POST'] as $method) {
            $response = $this->request(self::ADMIN, $method, $this->restorePath($manual));
            self::assertSame(422, $response->status, $method);
            $this->assertScreen($response->body);
            $x = $this->xpath($response->body);
            self::assertSame('Error: Restore this snapshot? - TiffinDB', $this->text($x, '//title'));
            self::assertSame(['This snapshot cannot be used. It is damaged, or it belongs to another project.'], $this->texts($x, '//div[@class="error-summary"]//li'));
            self::assertSame(0, $x->query('//div[@class="error-summary"]//a')->length, 'No field to link to.');
            self::assertSame(['3 Nov 2026, 10:00', 'Taken by an Admin'], $this->texts($x, '//main/dl[@class="facts"]/div/dd'));
            self::assertSame(0, $x->query('//main//form | //main/p')->length, 'No form and no counts.');
        }
        self::assertSame(['Kettle', 'Stand mixer'], $this->names());
        self::assertCount(1, $this->ops->snapshots()->list($this->admin(), $this->project), 'No pre-restore snapshot.');
    }

    public function testARestoreWhereLocksDoNotWorkIsRefused(): void
    {
        $this->add('Stand mixer');
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $this->add('Kettle');
        $this->ops->close();
        ProjectLock::$flockDisabledForTests = true;

        foreach (['GET', 'POST'] as $method) {
            [$response] = $this->handle(self::ADMIN, $method, $this->restorePath($manual));
            self::assertSame(422, $response->status, $method);
            $this->assertScreen($response->body);
            $x = $this->xpath($response->body);
            self::assertSame(
                ['This server cannot lock files, so TiffinDB cannot do this safely here. Nothing was changed. The host documentation describes how to do it by hand.'],
                $this->texts($x, '//div[@class="error-summary"]//li'),
            );
            self::assertSame(0, $x->query('//main//form')->length);
        }
        ProjectLock::$flockDisabledForTests = false;
        self::assertSame(['Kettle', 'Stand mixer'], $this->names());
        self::assertCount(1, $this->ops->snapshots()->list($this->admin(), $this->project));
    }

    // Restoring (snapshots.md section 4).

    public function testARestoreGoesBackAndSaysSo(): void
    {
        $this->add('Stand mixer');
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $this->core->clock->set('2026-11-03T12:00:00Z');
        $this->add('Kettle');
        $this->page(self::ADMIN, $this->restorePath($manual));

        $response = $this->request(self::ADMIN, 'POST', $this->restorePath($manual));
        self::assertSame(303, $response->status);
        self::assertSame($this->s(), $response->header('Location'));
        self::assertSame(['key' => 'notice.snapshot_restored', 'params' => ['project' => 'Home Inventory', 'date' => '3 Nov 2026, 10:00']], $this->services->flash[self::ADMIN]);
        self::assertSame(['Stand mixer'], $this->names());

        $x = $this->xpath($this->page(self::ADMIN, $this->s()));
        self::assertSame(
            'Home Inventory is back to how it was on 3 Nov 2026, 10:00. A snapshot of how it was just before was saved first.',
            $this->text($x, '//main/div[@class="notice"][@role="status"]/p'),
        );
        self::assertSame(['Before a restore', 'Taken by an Admin'], $this->texts($x, '//table/tbody/tr/td[2]'), 'The pre-restore snapshot is at the top.');
    }

    public function testARestoreWithMissingFilesSaysHowMany(): void
    {
        $field = $this->ops->schema()->addField($this->admin(), $this->project, $this->items, 'Photo', 'attachment', [], false, '')->key;
        $mixer = $this->add('Stand mixer');
        foreach (['one', 'two'] as $n) {
            $this->ops->attachments()->add($this->admin(), $this->project, $this->items, (string) $mixer, $field, $this->core->upload('example file ' . $n), $n . '.txt', null);
        }
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $this->trash($mixer);
        $this->ops->trash()->empty($this->admin(), $this->project, null, 10);

        $response = $this->request(self::ADMIN, 'POST', $this->restorePath($manual));
        self::assertSame(303, $response->status);
        self::assertSame(
            ['key' => 'notice.snapshot_restored_missing', 'params' => ['project' => 'Home Inventory', 'date' => '3 Nov 2026, 10:00', 'count' => 2]],
            $this->services->flash[self::ADMIN],
        );
        $x = $this->xpath($this->page(self::ADMIN, $this->s()));
        self::assertSame(
            'Home Inventory is back to how it was on 3 Nov 2026, 10:00. A snapshot of how it was just before was saved first. 2 files are missing from that copy.',
            $this->text($x, '//main/div[@class="notice"][@role="status"]/p'),
        );
    }

    public function testARestoreWhileTheProjectIsInUseIsBusy(): void
    {
        $this->add('Stand mixer');
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $this->add('Kettle');
        $this->ops->close();
        $holder = LockHolder::start('shared', $this->core->projectFolder($this->project));
        $this->holders[] = $holder;
        self::assertSame('held', $holder->answer);

        $response = $this->request(self::ADMIN, 'POST', $this->restorePath($manual));
        self::assertSame(503, $response->status);
        self::assertNotNull($response->header('Retry-After'));
        self::assertArrayNotHasKey(self::ADMIN, $this->services->flash);
        $holder->stop();
        self::assertSame(['Kettle', 'Stand mixer'], $this->names());
        self::assertCount(1, $this->ops->snapshots()->list($this->admin(), $this->project), 'No pre-restore snapshot.');
    }

    // Access and hostile requests.

    public function testMembersAreRefusedAndUngrantedPeopleFindNothing(): void
    {
        $this->add('Stand mixer');
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $routes = [['GET', $this->s()], ['POST', $this->s()], ['GET', $this->restorePath($manual)], ['POST', $this->restorePath($manual)]];
        foreach ($routes as [$method, $uri]) {
            self::assertSame(403, $this->request(self::MEMBER, $method, $uri)->status, "Member {$method} {$uri}");
            self::assertSame(404, $this->request(self::STRANGER, $method, $uri)->status, "Stranger {$method} {$uri}");
        }
        self::assertSame(404, $this->request(self::ADMIN, 'GET', '/projects/aaaaaaaaaaaa/snapshots')->status);
        self::assertSame(404, $this->request(self::ADMIN, 'POST', '/projects/aaaaaaaaaaaa/snapshots')->status);
        self::assertCount(1, $this->ops->snapshots()->list($this->admin(), $this->project));
        self::assertSame(['Stand mixer'], $this->names());
        self::assertSame([], $this->services->flash);
    }

    public function testRefusedPostsChangeNothing(): void
    {
        $this->add('Stand mixer');
        $manual = $this->ops->snapshots()->take($this->admin(), $this->project);
        $this->add('Kettle');
        foreach ([$this->s(), $this->restorePath($manual)] as $uri) {
            foreach ([null, '', str_repeat('b', 64), ['x']] as $token) {
                $post = $token === null ? [] : ['_csrf' => $token];
                $r = Fixtures::fullRequest('POST', $uri, $post, ['tdb_session' => self::ADMIN]);
                self::assertSame(403, Fixtures::handle($this->kernel, $r)[0]->status, $uri);
            }
            foreach (['https://evil.example', 'null', "http://tiffindb.example\t", "http://tiffindb.example\xFF", 'http://tiffindb.example/' . str_repeat('a', 2048)] as $origin) {
                $server = ['HTTP_HOST' => 'tiffindb.example', 'HTTP_ORIGIN' => $origin];
                $r = Fixtures::fullRequest('POST', $uri, ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => self::ADMIN], $server);
                self::assertSame(403, Fixtures::handle($this->kernel, $r)[0]->status, $uri . ' ' . $origin);
            }
            $site = ['HTTP_HOST' => 'tiffindb.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
            $r = Fixtures::fullRequest('POST', $uri, ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => self::ADMIN], $site);
            self::assertSame(403, Fixtures::handle($this->kernel, $r)[0]->status, $uri);
        }
        self::assertCount(1, $this->ops->snapshots()->list($this->admin(), $this->project), 'No snapshot was taken.');
        self::assertSame(['Kettle', 'Stand mixer'], $this->names(), 'Nothing was restored.');
        self::assertSame([], $this->services->flash);

        // The same request with the token and the own origin works.
        $own = ['HTTP_HOST' => 'tiffindb.example', 'HTTP_ORIGIN' => 'http://tiffindb.example'];
        $r = Fixtures::fullRequest('POST', $this->s(), ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => self::ADMIN], $own);
        self::assertSame(303, Fixtures::handle($this->kernel, $r)[0]->status);
    }

    // Helpers.

    private function admin(): Actor
    {
        return $this->core->actor('admin');
    }

    private function signedIn(string $username): SignedIn
    {
        $actor = $this->core->actor($username);

        return new SignedIn(
            $actor,
            new SessionRecord(str_repeat('0', 64), $actor->userId, FakeServices::SESSION_CSRF, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'),
            false,
        );
    }

    private function add(string $name): int
    {
        return $this->ops->records()->create($this->admin(), $this->project, $this->items, [$this->itemName => $name]);
    }

    private function trash(int $id): void
    {
        $this->ops->records()->trash($this->admin(), $this->project, $this->items, (string) $id);
    }

    /** @return list<string> the names of the live items, sorted */
    private function names(): array
    {
        $db = $this->core->projectDb($this->project);
        $names = [];
        foreach ($db->all('SELECT ' . Identifier::column($this->itemName) . ' AS "n" FROM ' . Identifier::table($this->items) . ' WHERE "_trashed_at" IS NULL') as $row) {
            $names[] = (string) $row['n'];
        }
        $db->close();
        sort($names);

        return $names;
    }

    private function s(): string
    {
        return '/projects/' . $this->project . '/snapshots';
    }

    private function restorePath(SnapshotInfo $snapshot): string
    {
        return $this->s() . '/' . substr($snapshot->file, 0, -strlen('.tiffin')) . '/restore';
    }

    /** @return array{0: Response, 1: string} */
    private function handle(string $token, string $method, string $uri): array
    {
        $post = $method === 'POST' ? ['_csrf' => FakeServices::SESSION_CSRF] : [];

        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]));
    }

    private function request(string $token, string $method, string $uri): Response
    {
        [$response, $log] = $this->handle($token, $method, $uri);
        self::assertSame('', $log, "No log output for {$method} {$uri}.");
        self::assertNotSame(500, $response->status, "{$method} {$uri}");

        return $response;
    }

    private function page(string $token, string $uri): string
    {
        $response = $this->request($token, 'GET', $uri);
        self::assertSame(200, $response->status, $uri);
        $this->assertScreen($response->body);

        return $response->body;
    }

    private function assertScreen(string $html): void
    {
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($html, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);
    }

    private function xpath(string $html): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
    }

    private function attr(DOMXPath $x, string $query, string $name): ?string
    {
        $node = $x->query($query)->item(0);
        self::assertInstanceOf(\DOMElement::class, $node, $query);

        return $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    /** @return array<string, string> the hidden inputs of a form, by name */
    private function hidden(DOMXPath $x, string $form): array
    {
        $values = [];
        foreach ($x->query($form . '//input[@type="hidden"]') as $input) {
            self::assertInstanceOf(\DOMElement::class, $input);
            $values[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $values;
    }

    private function text(DOMXPath $x, string $query): string
    {
        $texts = $this->texts($x, $query);
        self::assertNotSame([], $texts, $query);

        return $texts[0];
    }

    /** @return list<string> */
    private function crumbs(DOMXPath $x): array
    {
        return $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]');
    }

    /** @return list<string> */
    private function texts(DOMXPath $x, string $query): array
    {
        $texts = [];
        foreach ($x->query($query) as $node) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
            if ($text !== '' || !$node instanceof \DOMText) {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
