<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\RecordOperations;
use TiffinDB\Core\App\TrashOperations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\Conflict;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

require_once __DIR__ . '/AppTestCase.php';

/**
 * T-CF-8 (M4 CF-8, an M2 test gap): which record permission each M2
 * method of RecordOperations and TrashOperations checks. WP4 extends the
 * map to the M4 methods (T-APP-150).
 *
 * Authorizer and Context are final, so the spy is in two parts: the
 * method's source names exactly one permission for its `open()` (the
 * single authorization point of both classes, which calls
 * Context::project), and the method behaves as that permission says for
 * three people: an Admin passes, a Member with no grant gets the
 * Authorizer's NotFound, and a granted Member passes exactly when the
 * permission is open to granted Members, else gets AccessDenied.
 */
#[CoversClass(RecordOperations::class)]
#[CoversClass(TrashOperations::class)]
final class RecordPermissionMapTest extends AppTestCase
{
    /** The Authorizer's NotFound message (Context::project says "No such project."). */
    private const AUTHORIZER_NOT_FOUND = 'Not found.';

    private ProjectRecord $project;
    private TableDef $rooms;
    private int $live;
    private int $trashed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Home', null);
        $this->rooms = $this->ops->schema()->createTable($this->admin, $this->project->key, 'Rooms', 'room', true);
        $name = $this->ops->schema()->design($this->admin, $this->project->key, $this->rooms->key)->fields[0]->field->key;
        $this->live = $this->fx->addRecord($this->project->key, $this->rooms->key, [$name => 'Example kitchen']);
        $this->trashed = $this->fx->addRecord($this->project->key, $this->rooms->key, [$name => 'Example cellar']);
        $this->ops->trash()->moveToTrash($this->admin, $this->project->key, $this->rooms->key, [$this->trashed]);
        $this->ops->projects()->setSharing($this->admin, $this->project->key, [$this->member->userKey]);
    }

    /** @return iterable<string, array{0: class-string, 1: string, 2: Permission}> */
    public static function methods(): iterable
    {
        $records = RecordOperations::class;
        $trash = TrashOperations::class;
        yield 'records listing' => [$records, 'listing', Permission::ViewRecords];
        yield 'records filterOptions' => [$records, 'filterOptions', Permission::ViewRecords];
        yield 'records blank' => [$records, 'blank', Permission::EditRecords];
        yield 'records screen' => [$records, 'screen', Permission::ViewRecords];
        yield 'records redisplay' => [$records, 'redisplay', Permission::EditRecords];
        yield 'records create' => [$records, 'create', Permission::EditRecords];
        yield 'records update' => [$records, 'update', Permission::EditRecords];
        yield 'records conflict' => [$records, 'conflict', Permission::EditRecords];
        yield 'records trash' => [$records, 'trash', Permission::TrashRecords];
        yield 'records restore' => [$records, 'restore', Permission::TrashRecords];
        yield 'records setLinks' => [$records, 'setLinks', Permission::EditRecords];
        yield 'records search' => [$records, 'search', Permission::ViewRecords];
        yield 'trash moveToTrash' => [$trash, 'moveToTrash', Permission::TrashRecords];
        yield 'trash restore' => [$trash, 'restore', Permission::TrashRecords];
        yield 'trash list' => [$trash, 'list', Permission::ViewRecords];
        yield 'trash count' => [$trash, 'count', Permission::ViewRecords];
        yield 'trash empty' => [$trash, 'empty', Permission::EmptyTrash];
    }

    /** Every public method of the two classes is in the map, so a new one cannot be left out silently. */
    public function testTheMapCoversEveryPublicMethod(): void
    {
        $mapped = [];
        foreach (self::methods() as [$class, $method]) {
            $mapped[] = $class . '::' . $method;
        }
        $public = [];
        foreach ([RecordOperations::class, TrashOperations::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isConstructor()) {
                    $public[] = $class . '::' . $method->getName();
                }
            }
        }
        self::assertEqualsCanonicalizing($public, $mapped);
    }

    /** @param class-string $class */
    #[DataProvider('methods')]
    public function testTheSourceChecksOnePermission(string $class, string $method, Permission $permission): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, (int) $reflection->getStartLine() - 1, (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1));

        self::assertSame(1, preg_match_all('/\$this->open\(\$actor, \$projectKey, Permission::([A-Za-z]+)\)/', $body, $matches), 'One open() call.');
        self::assertSame($permission->name, $matches[1][0]);
        self::assertSame(1, substr_count($body, '$this->open('), 'open() is the only way in.');
        self::assertSame(0, preg_match('/->require\(|->project\(|->requireCurrent/', $body), 'No other authorization point.');
    }

    /** @param class-string $class */
    #[DataProvider('methods')]
    public function testTheMethodBehavesAsItsPermission(string $class, string $method, Permission $permission): void
    {
        $call = $this->call($class, $method);

        $outcome = self::outcome(fn () => $call($this->member2));
        self::assertInstanceOf(NotFound::class, $outcome, 'A Member with no grant gets "not found".');
        self::assertSame(self::AUTHORIZER_NOT_FOUND, $outcome->getMessage());

        $outcome = self::outcome(fn () => $call($this->member));
        if ($permission->isOpenToGrantedMembers()) {
            self::assertPassedTheCheck($outcome, 'granted Member');
        } else {
            self::assertInstanceOf(AccessDenied::class, $outcome, 'A granted Member is refused.');
        }

        self::assertPassedTheCheck(self::outcome(fn () => $call($this->admin)), 'Admin');
    }

    /**
     * Calls $method with arguments that name the project, the table, and
     * a live record (a trashed one for the restores).
     *
     * @param class-string $class
     * @return \Closure(Actor): mixed
     */
    private function call(string $class, string $method): \Closure
    {
        $p = $this->project->key;
        $t = $this->rooms->key;
        $live = (string) $this->live;
        $request = ListRequest::none();
        $records = $this->ops->records();
        $trash = $this->ops->trash();

        return match ($class . '::' . $method) {
            RecordOperations::class . '::listing' => static fn (Actor $a): mixed => $records->listing($a, $p, $t, $request),
            RecordOperations::class . '::filterOptions' => static fn (Actor $a): mixed => $records->filterOptions($a, $p, $t, null),
            RecordOperations::class . '::blank' => static fn (Actor $a): mixed => $records->blank($a, $p, $t, $request),
            RecordOperations::class . '::screen' => static fn (Actor $a): mixed => $records->screen($a, $p, $t, $live, $request),
            RecordOperations::class . '::redisplay' => static fn (Actor $a): mixed => $records->redisplay($a, $p, $t, $live, [], [], $request),
            RecordOperations::class . '::create' => static fn (Actor $a): mixed => $records->create($a, $p, $t, []),
            RecordOperations::class . '::update' => static fn (Actor $a): mixed => $records->update($a, $p, $t, $live, [], 'stale'),
            RecordOperations::class . '::conflict' => static fn (Actor $a): mixed => $records->conflict($a, $p, $t, $live, [], $request),
            RecordOperations::class . '::trash' => static fn (Actor $a): mixed => $records->trash($a, $p, $t, $live),
            RecordOperations::class . '::restore' => fn (Actor $a): mixed => $records->restore($a, $p, $t, (string) $this->trashed),
            RecordOperations::class . '::setLinks' => static fn (Actor $a): mixed => $records->setLinks($a, $p, $t, $live, 'nokey0000000', []),
            RecordOperations::class . '::search' => static fn (Actor $a): mixed => $records->search($a, $p, 'kitchen'),
            TrashOperations::class . '::moveToTrash' => fn (Actor $a): mixed => $trash->moveToTrash($a, $p, $t, [$this->live]),
            TrashOperations::class . '::restore' => fn (Actor $a): mixed => $trash->restore($a, $p, $t, [$this->trashed]),
            TrashOperations::class . '::list' => static fn (Actor $a): mixed => $trash->list($a, $p, $t, 10, 0),
            TrashOperations::class . '::count' => static fn (Actor $a): mixed => $trash->count($a, $p, null),
            TrashOperations::class . '::empty' => static fn (Actor $a): mixed => $trash->empty($a, $p, null),
        };
    }

    private static function outcome(\Closure $work): ?\Throwable
    {
        try {
            $work();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }

    /** Past the permission check: no AccessDenied and not the Authorizer's NotFound (a later NotFound, such as an unknown field, is fine). */
    private static function assertPassedTheCheck(?\Throwable $outcome, string $who): void
    {
        self::assertNotInstanceOf(AccessDenied::class, $outcome, $who);
        if ($outcome instanceof NotFound) {
            self::assertNotSame(self::AUTHORIZER_NOT_FOUND, $outcome->getMessage(), $who);
        }
        if ($outcome !== null && !$outcome instanceof NotFound) {
            // Validation and conflicts come after the check; anything else is a broken call.
            self::assertTrue($outcome instanceof ValidationFailed || $outcome instanceof Conflict, $who . ': ' . $outcome::class);
        }
    }
}
