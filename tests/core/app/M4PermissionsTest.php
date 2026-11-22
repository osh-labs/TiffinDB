<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\AttachmentOperations;
use TiffinDB\Core\App\CollectionOperations;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\ProjectOperations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\ValidationFailed;

require_once __DIR__ . '/M4AppTestCase.php';

/**
 * T-APP-150 (M4 spec section 10.7): the permission of every new method of
 * AttachmentOperations and CollectionOperations, and of
 * ProjectOperations::attachmentTotals (RecordPermissionMapTest covers the
 * new RecordOperations and TrashOperations methods). As there, each
 * method's source names one permission for its single open(), and its
 * behaviour matches: a Member with no grant gets the Authorizer's
 * NotFound, a granted Member passes exactly when the permission is open
 * to granted Members (else AccessDenied), an Admin passes.
 */
#[CoversClass(AttachmentOperations::class)]
#[CoversClass(CollectionOperations::class)]
#[CoversClass(ProjectOperations::class)]
final class M4PermissionsTest extends M4AppTestCase
{
    private const AUTHORIZER_NOT_FOUND = 'Not found.';

    private string $collection;
    private int $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collection = $this->ops->collections()->save($this->admin, $this->p, $this->items, null, ['name' => 'Example all', 'rules' => []]);
        $this->file = $this->attach($this->f['files'], 'Example lamp', self::png())->id;
    }

    /** @return iterable<string, array{0: class-string, 1: string, 2: Permission}> */
    public static function methods(): iterable
    {
        $a = AttachmentOperations::class;
        $c = CollectionOperations::class;
        yield 'attachments uploadScreen' => [$a, 'uploadScreen', Permission::EditRecords];
        yield 'attachments add' => [$a, 'add', Permission::EditRecords];
        yield 'attachments file' => [$a, 'file', Permission::ViewRecords];
        yield 'attachments remove' => [$a, 'remove', Permission::EditRecords];
        yield 'attachments serve' => [$a, 'serve', Permission::ViewRecords];
        yield 'collections summaries' => [$c, 'summaries', Permission::ViewRecords];
        yield 'collections listing' => [$c, 'listing', Permission::ViewRecords];
        yield 'collections editor' => [$c, 'editor', Permission::ManageCollections];
        yield 'collections save' => [$c, 'save', Permission::ManageCollections];
        yield 'collections deleteScreen' => [$c, 'deleteScreen', Permission::ManageCollections];
        yield 'collections delete' => [$c, 'delete', Permission::ManageCollections];
    }

    public function testTheMapCoversEveryPublicMethod(): void
    {
        $mapped = [];
        foreach (self::methods() as [$class, $method]) {
            $mapped[] = $class . '::' . $method;
        }
        $public = [];
        foreach ([AttachmentOperations::class, CollectionOperations::class] as $class) {
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
    public function testTheSourceChecksOnePermissionFirst(string $class, string $method, Permission $permission): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, (int) $reflection->getStartLine() - 1, (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1));
        self::assertSame(1, preg_match_all('/\$this->open\(\$actor, \$projectKey, Permission::([A-Za-z]+)\)/', $body, $matches));
        self::assertSame($permission->name, $matches[1][0]);
        self::assertSame(1, substr_count($body, '$this->open('));
        self::assertSame(0, preg_match('/->require\(|->project\(/', $body));
        // open() is the first statement: nothing is looked at before the permission check.
        self::assertMatchesRegularExpression('/\{\s*\[[^\]]*\] = \$this->open\(/', $body);
    }

    /** @param class-string $class */
    #[DataProvider('methods')]
    public function testTheMethodBehavesAsItsPermission(string $class, string $method, Permission $permission): void
    {
        $call = $this->call($class . '::' . $method);
        $outcome = self::outcome(fn () => $call($this->member2));
        self::assertInstanceOf(NotFound::class, $outcome);
        self::assertSame(self::AUTHORIZER_NOT_FOUND, $outcome->getMessage());
        $outcome = self::outcome(fn () => $call($this->member));
        if ($permission->isOpenToGrantedMembers()) {
            self::assertPassed($outcome);
        } else {
            self::assertInstanceOf(AccessDenied::class, $outcome);
        }
        self::assertPassed(self::outcome(fn () => $call($this->admin)));
    }

    public function testProjectTotalsAreForAdmins(): void
    {
        self::assertSame(1, $this->ops->projects()->attachmentTotals($this->admin, $this->p)->files);
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->projects()->attachmentTotals($this->member, $this->p));
        $this->assertThrows(NotFound::class, fn () => $this->ops->projects()->attachmentTotals($this->member2, $this->p));
    }

    public function testADeactivatedActorIsRefusedByFileOperations(): void
    {
        $this->fx->setActive('member', false);
        foreach (['uploadScreen', 'add', 'file', 'remove', 'serve'] as $method) {
            $this->assertThrows(AccessDenied::class, fn () => $this->call(AttachmentOperations::class . '::' . $method)($this->member));
        }
    }

    /** @return \Closure(Actor): mixed */
    private function call(string $name): \Closure
    {
        $p = $this->p;
        $t = $this->items;
        $lamp = (string) $this->ids['Example lamp'];
        $files = $this->f['files'];
        $file = (string) $this->file;
        $c = $this->collection;
        $a = $this->ops->attachments();
        $collections = $this->ops->collections();

        return match ($name) {
            AttachmentOperations::class . '::uploadScreen' => static fn (Actor $x): mixed => $a->uploadScreen($x, $p, $t, $lamp, $files, null),
            AttachmentOperations::class . '::add' => fn (Actor $x): mixed => $a->add($x, $p, $t, $lamp, $files, $this->fx->upload('bytes'), 'x.bin', null),
            AttachmentOperations::class . '::file' => static fn (Actor $x): mixed => $a->file($x, $p, $t, $lamp, $files, $file),
            AttachmentOperations::class . '::remove' => static fn (Actor $x): mixed => $a->remove($x, $p, $t, $lamp, $files, '999'),
            AttachmentOperations::class . '::serve' => static fn (Actor $x): mixed => $a->serve($x, $p, $file, false),
            CollectionOperations::class . '::summaries' => static fn (Actor $x): mixed => $collections->summaries($x, $p),
            CollectionOperations::class . '::listing' => static fn (Actor $x): mixed => $collections->listing($x, $p, $c, ListRequest::none()),
            CollectionOperations::class . '::editor' => static fn (Actor $x): mixed => $collections->editor($x, $p, null, $c, null, 'show'),
            CollectionOperations::class . '::save' => static fn (Actor $x): mixed => $collections->save($x, $p, null, $c, ['name' => '']),
            CollectionOperations::class . '::deleteScreen' => static fn (Actor $x): mixed => $collections->deleteScreen($x, $p, $c),
            CollectionOperations::class . '::delete' => static fn (Actor $x): mixed => $collections->delete($x, $p, 'k2xq7vn4mz3t'),
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

    private static function assertPassed(?\Throwable $outcome): void
    {
        self::assertNotInstanceOf(AccessDenied::class, $outcome);
        if ($outcome instanceof NotFound) {
            self::assertNotSame(self::AUTHORIZER_NOT_FOUND, $outcome->getMessage());
        } elseif ($outcome !== null) {
            self::assertInstanceOf(ValidationFailed::class, $outcome, $outcome::class);
        }
    }
}
