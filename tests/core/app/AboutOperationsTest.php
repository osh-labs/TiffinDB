<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\AboutComponent;
use TiffinDB\Core\App\AboutFacts;
use TiffinDB\Core\App\AboutOperations;
use TiffinDB\Core\App\AboutPlugin;
use TiffinDB\Core\App\Context;
use TiffinDB\Core\App\LicenceText;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Tests\Support\CoreFixture;

require_once __DIR__ . '/AppTestCase.php';

/** The About facts (M7 spec section 10): T-ABT-1 to T-ABT-3. */
#[CoversClass(AboutOperations::class)]
#[CoversClass(AboutFacts::class)]
#[CoversClass(AboutComponent::class)]
#[CoversClass(AboutPlugin::class)]
#[CoversClass(LicenceText::class)]
final class AboutOperationsTest extends AppTestCase
{
    private const NOTICES = [
        ['id' => 'tiffindb', 'name' => 'TiffinDB', 'version' => '', 'licence' => 'AGPL-3.0-or-later', 'files' => ['LICENSE']],
        ['id' => 'example-lib', 'name' => 'Example Library', 'version' => '1.2.3', 'licence' => 'MIT AND Apache-2.0', 'files' => ['vendor/example/lib/LICENSE', 'vendor/example/lib/NOTICE.txt']],
        ['id' => 'example-font', 'name' => 'Example Sans', 'version' => 'v2', 'licence' => 'OFL-1.1', 'files' => ['public/assets/fonts/example-sans/LICENSE.txt']],
    ];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops->usePluginsRoot($this->fx->pluginsRoot());
        $this->root = $this->fx->temp->path('app');
        foreach (['config', 'vendor/example/lib', 'public/assets/fonts/example-sans'] as $dir) {
            mkdir($this->root . '/' . $dir, 0750, true);
        }
        file_put_contents($this->root . '/LICENSE', "Synthetic licence text one.\n");
        file_put_contents($this->root . '/vendor/example/lib/LICENSE', "Synthetic licence text two.\n");
        file_put_contents($this->root . '/vendor/example/lib/NOTICE.txt', "Synthetic notice <b>&amp;</b>.\n");
        file_put_contents($this->root . '/public/assets/fonts/example-sans/LICENSE.txt', "Synthetic font licence.\n");
        $this->writeNotices(self::NOTICES);
    }

    /** @param array<mixed> $notices */
    private function writeNotices(array $notices): void
    {
        file_put_contents($this->root . '/config/notices.json', json_encode($notices, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function about(): AboutOperations
    {
        $context = (new \ReflectionProperty($this->ops, 'context'))->getValue($this->ops);
        self::assertInstanceOf(Context::class, $context);

        return new AboutOperations($context, $this->root);
    }

    // T-ABT-1

    public function testAnAdminSeesEveryReadablePluginAndTheServer(): void
    {
        $this->ops->plugins()->disable($this->admin, 'export', 'json');
        $facts = $this->about()->about($this->admin);

        self::assertSame(CoreFixture::VERSION, $facts->version);
        self::assertEquals([
            new AboutComponent('tiffindb', 'TiffinDB', '', 'AGPL-3.0-or-later'),
            new AboutComponent('example-lib', 'Example Library', '1.2.3', 'MIT AND Apache-2.0'),
            new AboutComponent('example-font', 'Example Sans', 'v2', 'OFL-1.1'),
        ], $facts->components);

        $expected = [];
        $broken = 0;
        foreach ((new \ReflectionProperty($this->ops, 'context'))->getValue($this->ops)->plugins() as $info) {
            if ($info->status === PluginStatus::Broken) {
                $broken++;
                continue;
            }
            $m = $info->manifest;
            self::assertNotNull($m);
            $expected[] = new AboutPlugin($m->name, $m->version, $m->kind, $m->licence, $info->status === PluginStatus::Enabled);
        }
        self::assertSame(1, $broken, 'The fixture has one broken plugin, which is left out.');
        self::assertEquals($expected, $facts->plugins);
        $names = array_map(static fn (AboutPlugin $p): string => $p->name, $facts->plugins);
        self::assertContains('Needs an extension', $names, 'An unavailable plugin was read, so it is listed.');
        $json = array_values(array_filter($facts->plugins, static fn (AboutPlugin $p): bool => $p->kind === 'export' && $p->name === 'JSON'));
        self::assertCount(1, $json);
        self::assertFalse($json[0]->enabled);

        self::assertNotNull($facts->server);
        self::assertSame(PHP_VERSION, $facts->server['php']);
        self::assertMatchesRegularExpression('/^3\.\d+\.\d+/', $facts->server['sqlite']);
    }

    public function testAMemberSeesEnabledPluginsOnlyAndNoServerFacts(): void
    {
        $this->ops->plugins()->disable($this->admin, 'export', 'json');
        $facts = $this->about()->about($this->member);

        self::assertNull($facts->server);
        self::assertCount(3, $facts->components);
        self::assertNotEmpty($facts->plugins);
        foreach ($facts->plugins as $plugin) {
            self::assertTrue($plugin->enabled, $plugin->name);
            self::assertNotSame('JSON', $plugin->name);
        }
        $admin = $this->about()->about($this->admin);
        self::assertEquals(array_values(array_filter($admin->plugins, static fn (AboutPlugin $p): bool => $p->enabled)), $facts->plugins);
    }

    public function testThePersonMustStillBeWhoTheSessionSays(): void
    {
        $this->fx->setActive('member', false);
        $this->assertThrows(AccessDenied::class, fn () => $this->about()->about($this->member));
        $this->assertThrows(AccessDenied::class, fn () => $this->about()->licence($this->member, 'tiffindb'));
        $this->fx->system->db()->write(function (): void {
            $this->fx->users->update($this->admin->userId, 'admin', 'Example Admin', \TiffinDB\Core\Auth\Role::Member);
        });
        $this->assertThrows(AccessDenied::class, fn () => $this->about()->about($this->admin), 'A demoted Admin is not given the server facts.');
    }

    public function testTheShippedNoticesAreReadable(): void
    {
        $about = $this->ops->about();
        self::assertSame($about, $this->ops->about());
        $facts = $about->about($this->member);
        $ids = array_map(static fn (AboutComponent $c): string => $c->id, $facts->components);
        self::assertContains('tiffindb', $ids);
        foreach ($ids as $id) {
            $licence = $about->licence($this->member, $id);
            self::assertNotEmpty($licence->files, $id);
            foreach ($licence->files as $file) {
                self::assertNotSame('', trim($file['text']), $id . ' ' . $file['file']);
                self::assertFalse($file['cut'], $id . ' ' . $file['file']);
            }
        }
        self::assertStringContainsString('GNU AFFERO GENERAL PUBLIC LICENSE', $about->licence($this->member, 'tiffindb')->files[0]['text']);
    }

    // T-ABT-2

    public function testLicenceGivesEveryFileAsData(): void
    {
        $licence = $this->about()->licence($this->member, 'example-lib');
        self::assertSame('Example Library', $licence->name);
        self::assertSame([
            ['file' => 'vendor/example/lib/LICENSE', 'text' => "Synthetic licence text two.\n", 'cut' => false],
            ['file' => 'vendor/example/lib/NOTICE.txt', 'text' => "Synthetic notice <b>&amp;</b>.\n", 'cut' => false],
        ], $licence->files);
        self::assertSame("Synthetic font licence.\n", $this->about()->licence($this->admin, 'example-font')->files[0]['text']);
    }

    public function testAnUnknownIdIsNotFound(): void
    {
        foreach (['nothing', '', 'TIFFINDB', '../tiffindb', 'tiffindb '] as $id) {
            $this->assertThrows(NotFound::class, fn () => $this->about()->licence($this->member, $id));
        }
    }

    public function testALongLicenceIsCutAndInvalidUtf8Replaced(): void
    {
        file_put_contents($this->root . '/LICENSE', str_repeat('a', AboutOperations::MAX_LICENCE_BYTES - 1) . "\xc3\xa9tail");
        $file = $this->about()->licence($this->member, 'tiffindb')->files[0];
        self::assertTrue($file['cut']);
        self::assertTrue(mb_check_encoding($file['text'], 'UTF-8'));
        self::assertStringEndsWith("a\u{FFFD}", $file['text'], 'The split character becomes U+FFFD.');

        file_put_contents($this->root . '/LICENSE', "Bad \xff byte \xc3 here");
        $file = $this->about()->licence($this->member, 'tiffindb')->files[0];
        self::assertSame(["Bad \u{FFFD} byte \u{FFFD} here", false], [$file['text'], $file['cut']]);

        file_put_contents($this->root . '/LICENSE', str_repeat('b', AboutOperations::MAX_LICENCE_BYTES));
        self::assertFalse($this->about()->licence($this->member, 'tiffindb')->files[0]['cut'], 'Exactly the limit is not cut.');
    }

    /** @return iterable<string, array{0: string}> */
    public static function refusedPaths(): iterable
    {
        yield 'parent segment' => ['vendor/../config/notices.json'];
        yield 'parent segment at the end' => ['vendor/example/..'];
        yield 'current segment' => ['vendor/./example/lib/LICENSE'];
        yield 'empty segment' => ['vendor//example/lib/LICENSE'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'absolute into the root' => ['/LICENSE'];
        yield 'outside the roots' => ['config/notices.json'];
        yield 'source file' => ['src/Core/App/AboutOperations.php'];
        yield 'fonts outside a family' => ['public/assets/fonts/LICENSE.txt'];
        yield 'fonts too deep' => ['public/assets/fonts/example-sans/sub/LICENSE.txt'];
        yield 'other assets' => ['public/assets/app.css'];
        yield 'licence with a suffix' => ['LICENSE.bak'];
        yield 'NUL byte' => ["vendor/example/lib/LICENSE\0"];
        yield 'backslash' => ['vendor\\example\\lib\\LICENSE'];
        yield 'missing file' => ['vendor/example/lib/MISSING'];
        yield 'a directory' => ['vendor/example/lib'];
        yield 'link to a file' => ['vendor/example/lib/LINKED'];
        yield 'file under a linked folder' => ['vendor/linked/LICENSE'];
        yield 'dangling link' => ['vendor/example/lib/DANGLING'];
    }

    #[DataProvider('refusedPaths')]
    public function testABadLicencePathIsABrokenRelease(string $path): void
    {
        file_put_contents($this->fx->temp->path('secret.txt'), 'outside the application');
        symlink($this->fx->temp->path('secret.txt'), $this->root . '/vendor/example/lib/LINKED');
        symlink($this->root . '/vendor/example/lib', $this->root . '/vendor/linked');
        symlink($this->fx->temp->path('nothing.txt'), $this->root . '/vendor/example/lib/DANGLING');
        $notices = self::NOTICES;
        $notices[1]['files'] = ['vendor/example/lib/LICENSE', $path];
        $this->writeNotices($notices);

        $this->assertThrows(\RuntimeException::class, fn () => $this->about()->licence($this->member, 'example-lib'));
        self::assertSame("Synthetic font licence.\n", $this->about()->licence($this->member, 'example-font')->files[0]['text'], 'Other components still read.');
    }

    // T-ABT-3

    /** @return iterable<string, array{0: string}> */
    public static function malformedNotices(): iterable
    {
        $good = self::NOTICES[0];
        $with = static fn (array $changes): string => json_encode([array_merge($good, $changes)], JSON_THROW_ON_ERROR);
        yield 'not JSON' => ['[{"id": "tiffindb",'];
        yield 'empty file' => [''];
        yield 'an object' => [json_encode($good, JSON_THROW_ON_ERROR)];
        yield 'an empty list' => ['[]'];
        yield 'a string element' => ['["tiffindb"]'];
        yield 'a list element' => ['[["tiffindb"]]'];
        yield 'missing key' => [json_encode([array_diff_key($good, ['files' => 1])], JSON_THROW_ON_ERROR)];
        yield 'extra key' => [$with(['url' => 'https://example.com/'])];
        yield 'bad id' => [$with(['id' => 'Tiffin DB'])];
        yield 'long id' => [$with(['id' => str_repeat('a', 41)])];
        yield 'number id' => [$with(['id' => 7])];
        yield 'empty name' => [$with(['name' => ''])];
        yield 'control in name' => [$with(['name' => "Tiffin\nDB"])];
        yield 'null version' => [$with(['version' => null])];
        yield 'bad licence' => [$with(['licence' => 'MIT; rm -rf'])];
        yield 'empty licence' => [$with(['licence' => ''])];
        yield 'no files' => [$with(['files' => []])];
        yield 'files not a list' => [$with(['files' => ['a' => 'LICENSE']])];
        yield 'file not a string' => [$with(['files' => [7]])];
        yield 'duplicate id' => [json_encode([$good, $good], JSON_THROW_ON_ERROR)];
        yield 'too large' => [json_encode([$good], JSON_THROW_ON_ERROR) . str_repeat(' ', AboutOperations::MAX_NOTICES_BYTES)];
        yield 'too deep' => ['[' . str_repeat('[', 20) . str_repeat(']', 20) . ']'];
    }

    #[DataProvider('malformedNotices')]
    public function testAMalformedNoticesFileIsABrokenRelease(string $json): void
    {
        file_put_contents($this->root . '/config/notices.json', $json);
        $this->assertThrows(\RuntimeException::class, fn () => $this->about()->about($this->member));
        $this->assertThrows(\RuntimeException::class, fn () => $this->about()->licence($this->member, 'tiffindb'));
    }

    public function testAMissingOrLinkedNoticesFileIsABrokenRelease(): void
    {
        $path = $this->root . '/config/notices.json';
        rename($path, $this->root . '/config/real.json');
        $this->assertThrows(\RuntimeException::class, fn () => $this->about()->about($this->admin));
        symlink($this->root . '/config/real.json', $path);
        $this->assertThrows(\RuntimeException::class, fn () => $this->about()->about($this->admin));
    }
}
