<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Plugin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Plugin\Manifest;
use TiffinDB\Core\Io\Plugin\PluginInfo;
use TiffinDB\Core\Io\Plugin\PluginLoader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/PluginTree.php';

/**
 * T-PLG-5: PluginLoader refuses plugins that are not Enabled, loads only
 * classes under the plugin's prefix, does not load names with path tricks,
 * fails for a class that is not final, does not implement the interface,
 * or is not the manifest's class, and loads a plugin once per loader.
 *
 * Class names are global to the test process, so every plugin id here is
 * used by one test only. Hostile class files write a marker file when they
 * run, so each test can show the code never ran.
 */
#[CoversClass(PluginLoader::class)]
final class PluginLoaderTest extends TestCase
{
    private TempDir $temp;
    private PluginTree $tree;
    private string $marker;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->tree = new PluginTree($this->temp);
        $this->marker = $this->temp->path('marker');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    /** PHP that appends $label to the marker file. */
    private function mark(string $label): string
    {
        return 'file_put_contents(' . var_export($this->marker, true) . ', ' . var_export($label . "\n", true) . ', FILE_APPEND);';
    }

    private function marks(): string
    {
        return is_file($this->marker) ? (string) file_get_contents($this->marker) : '';
    }

    /** @param array<string, bool> $overrides */
    private function info(string $kind, string $id, array $overrides = []): PluginInfo
    {
        foreach ((new PluginScanner($this->tree->root))->scan($overrides + [$kind . '/' . $id => true]) as $info) {
            if ($info->ref() === $kind . '/' . $id) {
                return $info;
            }
        }
        $this->fail('The plugin was not found.');
    }

    private function loader(): PluginLoader
    {
        return new PluginLoader($this->tree->root);
    }

    private function assertLoadFails(PluginInfo $info, string $kind = 'import', ?string $detail = null): void
    {
        try {
            $kind === 'import' ? $this->loader()->importer($info) : $this->loader()->exporter($info);
            $this->fail('The plugin loaded.');
        } catch (PluginFailure $failure) {
            $this->assertSame(PluginFailure::REASON_FAILED, $failure->reason);
            if ($detail !== null) {
                $this->assertStringContainsString($detail, $failure->getMessage(), $info->ref());
            }
        }
    }

    public function testAnEnabledImporterAndExporterLoad(): void
    {
        $this->tree->add('import', 'load-ok');
        $this->tree->add('export', 'load-ok');
        $importer = $this->loader()->importer($this->info('import', 'load-ok'));
        $exporter = $this->loader()->exporter($this->info('export', 'load-ok'));
        $this->assertInstanceOf(Importer::class, $importer);
        $this->assertInstanceOf(Exporter::class, $exporter);
        $this->assertSame('TiffinDB\\Plugin\\Import\\LoadOk\\Plugin', $importer::class);
        $this->assertSame('TiffinDB\\Plugin\\Export\\LoadOk\\Plugin', $exporter::class);
    }

    public function testPluginsThatAreNotEnabledAreRefused(): void
    {
        $this->tree->add('import', 'load-off', [], PluginTree::importerSource('import', 'load-off', 'Plugin', $this->mark('off')));
        $this->tree->add('import', 'load-unavail', ['requires_extensions' => ['tiffindbnonexistent']]);
        $this->tree->add('import', 'load-broken', ['api' => 2]);

        $off = $this->info('import', 'load-off', ['import/load-off' => false]);
        $this->assertSame(PluginStatus::Disabled, $off->status);
        foreach ([$off, $this->info('import', 'load-unavail'), $this->info('import', 'load-broken')] as $info) {
            try {
                $this->loader()->importer($info);
                $this->fail('A plugin that is not enabled loaded: ' . $info->ref());
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('', $this->marks());
    }

    public function testTheKindMustMatch(): void
    {
        $this->tree->add('export', 'load-kind');
        $this->expectException(\LogicException::class);
        $this->loader()->importer($this->info('export', 'load-kind'));
    }

    public function testAHandMadeManifestIsCheckedAgain(): void
    {
        // A PluginInfo that did not come from a scan cannot name a class outside the plugin.
        $this->tree->add('import', 'load-forged');
        $forged = static fn (string $class, string $id = 'load-forged'): PluginInfo => new PluginInfo('import', $id, new Manifest(
            1, $id, 'import', 'Forged', '1.0.0', $class, 'MIT', ['exf'], null, [], [], '',
        ), PluginStatus::Enabled, false, null, []);

        foreach ([
            'TiffinDB\\Core\\Storage\\Fs',
            'TiffinDB\\Plugin\\Import\\LoadForged\\..\\Plugin',
            'TiffinDB\\Plugin\\Import\\LoadForged\\Sub\\Plugin',
            'TiffinDB\\Plugin\\Import\\Other\\Plugin',
        ] as $class) {
            $this->assertLoadFails($forged($class));
        }
        $this->assertLoadFails($forged('TiffinDB\\Plugin\\Import\\Bad\\Plugin', '../bad'));
        $this->assertLoadFails($forged('TiffinDB\\Plugin\\Import\\NotThere\\Plugin', 'not-there'));
    }

    public function testOnlyClassesUnderThePrefixAreLoaded(): void
    {
        $dir = $this->tree->add('import', 'load-prefix');
        file_put_contents($dir . '/src/Helper.php', "<?php\nnamespace TiffinDB\\Plugin\\Import\\LoadPrefix;\n" . $this->mark('helper') . "\nfinal class Helper {}\n");
        mkdir($dir . '/src/Sub');
        file_put_contents($dir . '/src/Sub/Deep.php', "<?php\nnamespace TiffinDB\\Plugin\\Import\\LoadPrefix\\Sub;\n" . $this->mark('deep') . "\nfinal class Deep {}\n");
        // Files a name outside the prefix would map to if the loader only stripped a shorter prefix.
        file_put_contents($dir . '/src/Other.php', "<?php\n" . $this->mark('other') . "\n");
        $this->loader()->importer($this->info('import', 'load-prefix'));

        $this->assertTrue(class_exists('TiffinDB\\Plugin\\Import\\LoadPrefix\\Helper'));
        $this->assertTrue(class_exists('TiffinDB\\Plugin\\Import\\LoadPrefix\\Sub\\Deep'));
        $this->assertSame("helper\ndeep\n", $this->marks());

        foreach ([
            'TiffinDB\\Plugin\\Import\\LoadPrefixOther',
            'TiffinDB\\Plugin\\Import\\LoadPrefixx\\Other',
            'TiffinDB\\Plugin\\Import\\Other',
            'TiffinDB\\Plugin\\Export\\LoadPrefix\\Other',
            'tiffindb\\plugin\\import\\loadprefix\\Other',
            'Other',
        ] as $name) {
            $this->assertFalse(class_exists($name), $name);
        }
        $this->assertSame("helper\ndeep\n", $this->marks(), 'No file outside the prefix ran.');
    }

    public function testNamesWithPathTricksAreNotLoaded(): void
    {
        $dir = $this->tree->add('import', 'load-tricks');
        $outside = $this->tree->root . '/import/load-tricks';
        file_put_contents($outside . '/Outside.php', "<?php\n" . $this->mark('outside') . "\n");
        file_put_contents($this->temp->path('Far.php'), "<?php\n" . $this->mark('far') . "\n");
        file_put_contents($dir . '/src/lower.php', "<?php\n" . $this->mark('lower') . "\n");
        file_put_contents($dir . '/src/Dot.php.php', "<?php\n" . $this->mark('dot') . "\n");
        symlink($this->temp->path('Far.php'), $dir . '/src/Linked.php');
        symlink($dir . '/src/lower.php', $dir . '/src/Inner.php');
        mkdir($this->temp->path('far-dir'));
        file_put_contents($this->temp->path('far-dir/Thing.php'), "<?php\n" . $this->mark('far-dir') . "\n");
        symlink($this->temp->path('far-dir'), $dir . '/src/Away');
        $deep = $dir . '/src';
        for ($i = 0; $i < PluginLoader::MAX_SEGMENTS; $i++) {
            $deep = $deep . '/D';
            mkdir($deep);
        }
        file_put_contents($deep . '/Last.php', "<?php\n" . $this->mark('deep') . "\n");
        $this->loader()->importer($this->info('import', 'load-tricks'));

        $prefix = 'TiffinDB\\Plugin\\Import\\LoadTricks\\';
        foreach ([
            '..\\Outside',
            '..\\..\\..\\Far',
            'lower',
            'Dot.php',
            'Linked',
            'Inner',
            'Away\\Thing',
            "Plugin\0",
            '\\Plugin2',
            'A/../../Outside',
            'Sub\\',
            '',
            str_repeat('D\\', PluginLoader::MAX_SEGMENTS) . 'Last',
        ] as $rest) {
            $this->assertFalse(class_exists($prefix . $rest), $rest);
        }
        $this->assertSame('', $this->marks(), 'No file was included.');
    }

    public function testAClassThatIsNotFinalFailsBeforeItsFileRuns(): void
    {
        $this->tree->add('import', 'load-open', [], PluginTree::importerSource('import', 'load-open', 'Plugin', $this->mark('open'), ''));
        $this->tree->add('import', 'load-abstract', [], PluginTree::importerSource('import', 'load-abstract', 'Plugin', $this->mark('abstract'), 'abstract '));
        $this->tree->add('import', 'load-twice', [], PluginTree::importerSource('import', 'load-twice', 'Plugin', $this->mark('twice') . "\nif (false) { class Plugin {} }"));
        $this->tree->add('import', 'load-other-ns', [], str_replace('namespace TiffinDB\\Plugin\\Import\\LoadOtherNs;', 'namespace TiffinDB\\Plugin\\Import\\Elsewhere;', PluginTree::importerSource('import', 'load-other-ns', 'Plugin', $this->mark('other-ns'))));
        foreach (['load-open', 'load-abstract', 'load-twice', 'load-other-ns'] as $id) {
            $this->assertLoadFails($this->info('import', $id), 'import', 'does not declare the final class');
        }
        $this->assertSame('', $this->marks());
    }

    public function testAClassThatDoesNotImplementTheInterfaceFails(): void
    {
        $this->tree->add('import', 'load-iface', [], "<?php\nnamespace TiffinDB\\Plugin\\Import\\LoadIface;\nfinal class Plugin {}\n");
        $this->assertLoadFails($this->info('import', 'load-iface'), 'import', 'does not implement the contract');

        // An import plugin whose class is an Exporter.
        $source = str_replace('namespace TiffinDB\\Plugin\\Export\\LoadWrong;', 'namespace TiffinDB\\Plugin\\Import\\LoadWrong;', PluginTree::importerSource('export', 'load-wrong'));
        $this->tree->add('import', 'load-wrong', [], $source);
        $this->assertLoadFails($this->info('import', 'load-wrong'), 'import', 'does not implement the contract');
    }

    public function testAClassAliasOfAnotherClassFails(): void
    {
        $source = PluginTree::importerSource('import', 'load-alias', 'Real')
            . "if (false) {\n    final class Plugin {}\n}\nclass_alias(Real::class, __NAMESPACE__ . '\\\\Plugin');\n";
        $this->tree->add('import', 'load-alias', [], $source);
        $this->assertLoadFails($this->info('import', 'load-alias'), 'import', 'not of the manifest class');
    }

    public function testFailuresWhileLoadingArePluginFailures(): void
    {
        $this->tree->add('import', 'load-syntax', [], "<?php\nnamespace TiffinDB\\Plugin\\Import\\LoadSyntax;\nfinal class Plugin {\n");
        $this->tree->add('import', 'load-args', [], PluginTree::importerSource('import', 'load-args', body: 'public function __construct(int $required) {}'));
        $this->tree->add('import', 'load-throws', [], PluginTree::importerSource('import', 'load-throws', body: 'public function __construct() { throw new \\RuntimeException("no"); }'));
        $this->tree->add('import', 'load-top-throws', [], PluginTree::importerSource('import', 'load-top-throws', 'Plugin', 'throw new \\RuntimeException("no");'));
        $this->tree->add('import', 'load-missing', [], "<?php\nnamespace TiffinDB\\Plugin\\Import\\LoadMissing;\n// final class Plugin is never declared\n");
        foreach (['load-syntax' => 'ParseError', 'load-args' => 'ArgumentCountError', 'load-throws' => 'RuntimeException', 'load-top-throws' => 'RuntimeException'] as $id => $error) {
            $this->assertLoadFails($this->info('import', $id), 'import', 'Loading the plugin failed: ' . $error);
        }
        $this->assertLoadFails($this->info('import', 'load-missing'), 'import', 'does not declare the final class');
    }

    public function testTheLayoutIsCheckedAgainBeforeLoading(): void
    {
        $dir = $this->tree->add('import', 'load-swapped', [], PluginTree::importerSource('import', 'load-swapped', 'Plugin', $this->mark('swapped')));
        $info = $this->info('import', 'load-swapped');
        // After the scan, src/ is replaced by a link to a directory elsewhere with the same class file.
        rename($dir . '/src', $this->temp->path('moved-src'));
        symlink($this->temp->path('moved-src'), $dir . '/src');
        $this->assertLoadFails($info, 'import', 'layout is not valid');
        $this->assertSame('', $this->marks());
    }

    public function testAPluginIsLoadedOncePerLoader(): void
    {
        $this->tree->add('import', 'load-once', [], PluginTree::importerSource('import', 'load-once', 'Plugin', $this->mark('once')));
        $info = $this->info('import', 'load-once');
        $loader = $this->loader();
        $first = $loader->importer($info);
        $this->assertSame($first, $loader->importer($info));
        $this->assertSame($first, $loader->importer($this->info('import', 'load-once')));
        $this->assertSame("once\n", $this->marks());

        // Another loader in the same process makes a new instance; the file is not included again.
        $other = $this->loader()->importer($info);
        $this->assertNotSame($first, $other);
        $this->assertSame("once\n", $this->marks());
    }

    public function testANamespaceServedFromAnotherDirectoryFails(): void
    {
        $this->tree->add('import', 'load-dup');
        $this->loader()->importer($this->info('import', 'load-dup'));

        $second = TempDir::create();
        try {
            $tree = new PluginTree($second);
            $tree->add('import', 'load-dup', [], PluginTree::importerSource('import', 'load-dup', 'Plugin', $this->mark('dup')));
            $info = null;
            foreach ((new PluginScanner($tree->root))->scan(['import/load-dup' => true]) as $found) {
                $info = $found;
            }
            $this->assertNotNull($info);
            try {
                (new PluginLoader($tree->root))->importer($info);
                $this->fail('A second directory served the same namespace.');
            } catch (PluginFailure $failure) {
                $this->assertSame(PluginFailure::REASON_FAILED, $failure->reason);
            }
            $this->assertSame('', $this->marks());
        } finally {
            $second->remove();
        }
    }
}
