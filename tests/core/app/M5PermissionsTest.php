<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\FormOperations;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\System\ProjectRecord;

require_once __DIR__ . '/M4AppTestCase.php';

/**
 * T-APP-160 (M5 spec sections 9 and 9.7): every FormOperations method is
 * for Admins. A granted Member is AccessDenied before the project is read
 * (shown with the project file moved away: the Member is still refused,
 * the Admin then reaches the file and gets Unavailable); an ungranted
 * person, an unknown or malformed project key, an inactive project, and a
 * table key that is not a table of the project are NotFound; an Admin
 * passes. Nothing is written by a refused call.
 */
#[CoversClass(FormOperations::class)]
final class M5PermissionsTest extends M4AppTestCase
{
    private const AUTHORIZER_NOT_FOUND = 'Not found.';
    private const UNKNOWN_KEY = 'k2xq7vn4mz3t';

    private string $draft;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $screen = $this->ops->forms()->editor($this->admin, $this->p, $this->items);
        $this->draft = $screen->draft;
        $this->base = $screen->base;
    }

    /** @return iterable<string, array{0: string}> */
    public static function calls(): iterable
    {
        foreach (['editor', 'editor-apply', 'editor-save', 'source', 'source-check', 'source-save', 'uploadScreen', 'upload', 'download', 'resetScreen', 'reset'] as $name) {
            yield $name => [$name];
        }
    }

    public function testTheCallsCoverEveryPublicMethod(): void
    {
        $methods = [];
        foreach ((new \ReflectionClass(FormOperations::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isConstructor()) {
                $methods[] = $method->getName();
            }
        }
        $called = array_values(array_unique(array_map(static fn (array $call): string => explode('-', $call[0])[0], iterator_to_array(self::calls(), false))));
        self::assertEqualsCanonicalizing($methods, $called);
    }

    public function testEveryMethodChecksTheFormPermissionFirst(): void
    {
        foreach ((new \ReflectionClass(FormOperations::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            $lines = file((string) $method->getFileName());
            self::assertIsArray($lines);
            $body = implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
            self::assertSame(1, substr_count($body, '$this->open('), $method->getName());
            // open() is the first statement, with forms.edit: nothing is looked at before the permission check.
            self::assertMatchesRegularExpression('/\{\s*\[[^\]]*\] = \$this->open\(\$actor, \$projectKey, Permission::EditForms, \$tableKey\);/', $body, $method->getName());
            self::assertSame(0, preg_match('/->require\(|->project\(|->opener->/', $body), $method->getName());
        }
    }

    #[DataProvider('calls')]
    public function testAGrantedMemberIsRefusedBeforeTheProjectIsRead(string $name): void
    {
        $before = $this->state();
        self::assertInstanceOf(AccessDenied::class, self::outcome(fn () => $this->call($name, $this->ops)($this->member, $this->p, $this->items)));
        self::assertSame($before, $this->state());

        // With the project file gone, a fresh instance still refuses the Member at once; the Admin goes on to the file.
        $this->ops->close();
        $file = $this->fx->projectFolder($this->p) . '/project.tiffin';
        rename($file, $file . '.away');
        try {
            $fresh = $this->fx->operations();
            self::assertInstanceOf(AccessDenied::class, self::outcome(fn () => $this->call($name, $fresh)($this->member, $this->p, $this->items)));
            self::assertFileDoesNotExist($file);
            $fresh->close();
            $fresh = $this->fx->operations();
            self::assertInstanceOf(Unavailable::class, self::outcome(fn () => $this->call($name, $fresh)($this->admin, $this->p, $this->items)));
            $fresh->close();
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rename($file . '.away', $file);
        }
    }

    #[DataProvider('calls')]
    public function testOthersAreNotFoundAndAnAdminPasses(string $name): void
    {
        $call = $this->call($name, $this->ops);
        $other = $this->ops->projects()->create($this->admin, 'Example Other', 'Boxes')->key;
        $otherTable = $this->ops->schema()->tables($this->admin, $other)[0]->table->key;
        $inactive = $this->ops->projects()->create($this->admin, 'Example Inactive', 'Shelves')->key;
        $inactiveTable = $this->ops->schema()->tables($this->admin, $inactive)[0]->table->key;
        $this->ops->projects()->setSharing($this->admin, $inactive, [$this->member->userKey]);
        $db = $this->fx->system->db();
        $db->write(static fn () => $db->run('UPDATE projects SET state = ? WHERE key = ?', [ProjectRecord::STATE_CREATING, $inactive]));
        $before = $this->state();

        $this->assertAuthorizerNotFound(self::outcome(fn () => $call($this->member2, $this->p, $this->items)));
        foreach ([$this->admin, $this->member] as $actor) {
            $this->assertAuthorizerNotFound(self::outcome(fn () => $call($actor, $inactive, $inactiveTable)));
        }
        foreach ([self::UNKNOWN_KEY, 'not a key', ''] as $projectKey) {
            self::assertInstanceOf(NotFound::class, self::outcome(fn () => $call($this->admin, $projectKey, $this->items)));
        }
        foreach ([self::UNKNOWN_KEY, 'NOT-A-KEY', '', $otherTable, $this->f['name']] as $tableKey) {
            $outcome = self::outcome(fn () => $call($this->admin, $this->p, $tableKey));
            self::assertInstanceOf(NotFound::class, $outcome, $tableKey);
        }
        self::assertSame($before, $this->state());

        self::assertNull(self::outcome(fn () => $call($this->admin, $this->p, $this->items)));
    }

    private function assertAuthorizerNotFound(?\Throwable $outcome): void
    {
        self::assertInstanceOf(NotFound::class, $outcome);
        self::assertSame(self::AUTHORIZER_NOT_FOUND, $outcome->getMessage());
    }

    /** The forms rows and the audit actions: what a refused call must leave as it was. @return array{0: list<array<string, mixed>>, 1: list<string>} */
    private function state(): array
    {
        return [$this->fx->projectDb($this->p)->all('SELECT * FROM "_tiffin_forms" ORDER BY "key"'), $this->auditActions()];
    }

    /** @return \Closure(Actor, string, string): mixed */
    private function call(string $name, Operations $ops): \Closure
    {
        $forms = $ops->forms();
        $hide = ['draft' => $this->draft, 'base' => $this->base, 'do' => 'hide-1-5'];
        $draft = $this->draft;
        $base = $this->base;
        $text = '<div data-tiffin-form="1"><p>Example text</p></div>';
        $upload = $this->fx->upload($text);

        return match ($name) {
            'editor' => static fn (Actor $x, string $p, string $t): mixed => $forms->editor($x, $p, $t),
            'editor-apply' => static fn (Actor $x, string $p, string $t): mixed => $forms->editor($x, $p, $t, ['draft' => $draft, 'do' => 'apply']),
            'editor-save' => static fn (Actor $x, string $p, string $t): mixed => $forms->editor($x, $p, $t, [...$hide, 'do' => 'save']),
            'source' => static fn (Actor $x, string $p, string $t): mixed => $forms->source($x, $p, $t),
            'source-check' => static fn (Actor $x, string $p, string $t): mixed => $forms->source($x, $p, $t, ['text' => $text, 'do' => 'check']),
            'source-save' => static fn (Actor $x, string $p, string $t): mixed => $forms->source($x, $p, $t, ['text' => $text, 'base' => $base, 'do' => 'save']),
            'uploadScreen' => static fn (Actor $x, string $p, string $t): mixed => $forms->uploadScreen($x, $p, $t),
            'upload' => static fn (Actor $x, string $p, string $t): mixed => $forms->upload($x, $p, $t, $upload),
            'download' => static fn (Actor $x, string $p, string $t): mixed => $forms->download($x, $p, $t),
            'resetScreen' => static fn (Actor $x, string $p, string $t): mixed => $forms->resetScreen($x, $p, $t),
            'reset' => static fn (Actor $x, string $p, string $t): mixed => $forms->reset($x, $p, $t),
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
}
