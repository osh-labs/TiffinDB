<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Plugin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Plugin\ManifestInvalid;
use TiffinDB\Core\Io\Plugin\PluginInfo;
use TiffinDB\Core\Io\Plugin\PluginLoader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/PluginTree.php';

/**
 * T-PLG-3 (statuses, ordering, ignored entries, the 100-plugin cap) and
 * T-PLG-4 (a scan executes no plugin PHP).
 */
#[CoversClass(PluginScanner::class)]
#[CoversClass(PluginInfo::class)]
final class PluginScannerTest extends TestCase
{
    private TempDir $temp;
    private PluginTree $tree;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->tree = new PluginTree($this->temp);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    /**
     * @param array<string, bool> $overrides
     * @return array<string, PluginInfo> ref => info, in scan order
     */
    private function scan(array $overrides = [], ?\Closure $loaded = null): array
    {
        $byRef = [];
        foreach ((new PluginScanner($this->tree->root, $loaded))->scan($overrides) as $info) {
            $this->assertArrayNotHasKey($info->ref(), $byRef);
            $byRef[$info->ref()] = $info;
        }

        return $byRef;
    }

    private function addBuiltInCsv(): void
    {
        $this->tree->add('import', 'csv', ['class' => 'TiffinDB\\Plugin\\Import\\Csv\\CsvImporter'], "<?php\n", 'CsvImporter');
    }

    public function testStatuses(): void
    {
        $this->addBuiltInCsv();
        $this->tree->add('import', 'third');
        $this->tree->add('import', 'needs-ext', ['requires_extensions' => ['json', 'missingext']]);
        $this->tree->add('import', 'damaged', ['api' => 2]);
        $this->tree->add('export', 'json', ['class' => 'TiffinDB\\Plugin\\Export\\Json\\JsonExporter'], "<?php\n", 'JsonExporter');

        $loaded = static fn (string $name): bool => $name !== 'missingext';
        $plugins = $this->scan([], $loaded);

        $csv = $plugins['import/csv'];
        $this->assertSame(PluginStatus::Enabled, $csv->status, 'A built-in plugin is on by default.');
        $this->assertTrue($csv->builtIn);
        $this->assertNotNull($csv->manifest);
        $this->assertNull($csv->brokenReason);

        $third = $plugins['import/third'];
        $this->assertSame(PluginStatus::Disabled, $third->status, 'Any other plugin is off by default.');
        $this->assertFalse($third->builtIn);

        $needs = $plugins['import/needs-ext'];
        $this->assertSame(PluginStatus::Unavailable, $needs->status);
        $this->assertSame(['missingext'], $needs->missingExtensions);
        $this->assertNotNull($needs->manifest);

        $damaged = $plugins['import/damaged'];
        $this->assertSame(PluginStatus::Broken, $damaged->status);
        $this->assertSame(ManifestInvalid::BAD_API, $damaged->brokenReason);
        $this->assertNull($damaged->manifest);
        $this->assertSame([], $damaged->missingExtensions);

        $this->assertSame(PluginStatus::Enabled, $plugins['export/json']->status);
        $this->assertTrue($plugins['export/json']->builtIn);
    }

    public function testOverridesWorkBothWays(): void
    {
        $this->addBuiltInCsv();
        $this->tree->add('import', 'third');
        $plugins = $this->scan(['import/csv' => false, 'import/third' => true]);
        $this->assertSame(PluginStatus::Disabled, $plugins['import/csv']->status);
        $this->assertSame(PluginStatus::Enabled, $plugins['import/third']->status);

        $plugins = $this->scan(['import/csv' => true, 'import/third' => false]);
        $this->assertSame(PluginStatus::Enabled, $plugins['import/csv']->status);
        $this->assertSame(PluginStatus::Disabled, $plugins['import/third']->status);
    }

    public function testAnOverrideNeverEnablesAnUnavailableOrBrokenPlugin(): void
    {
        $this->tree->add('import', 'needs-ext', ['requires_extensions' => ['missingext']]);
        $this->tree->add('import', 'damaged', ['licence' => 'GPL-2.0-only']);
        $plugins = $this->scan(['import/needs-ext' => true, 'import/damaged' => true], static fn (string $name): bool => false);
        $this->assertSame(PluginStatus::Unavailable, $plugins['import/needs-ext']->status);
        $this->assertSame(PluginStatus::Broken, $plugins['import/damaged']->status);
        $this->assertSame(ManifestInvalid::BAD_LICENCE, $plugins['import/damaged']->brokenReason);
    }

    public function testTheExtensionSeamDefaultsToExtensionLoaded(): void
    {
        $this->tree->add('import', 'has-ext', ['requires_extensions' => ['json', 'pdo_sqlite']]);
        $this->tree->add('import', 'lacks-ext', ['requires_extensions' => ['json', 'tiffindbnonexistent']]);
        $plugins = $this->scan(['import/has-ext' => true]);
        $this->assertSame(PluginStatus::Enabled, $plugins['import/has-ext']->status);
        $this->assertSame(PluginStatus::Unavailable, $plugins['import/lacks-ext']->status);
        $this->assertSame(['tiffindbnonexistent'], $plugins['import/lacks-ext']->missingExtensions);
    }

    public function testOrderIsImportThenExportEachById(): void
    {
        foreach (['zeta', 'alpha', 'm-id', 'm1'] as $id) {
            $this->tree->add('export', $id);
            $this->tree->add('import', $id);
        }
        $this->assertSame(
            ['import/alpha', 'import/m-id', 'import/m1', 'import/zeta', 'export/alpha', 'export/m-id', 'export/m1', 'export/zeta'],
            array_keys($this->scan()),
        );
    }

    public function testLinksFilesAndOtherNamesAreIgnored(): void
    {
        $real = $this->tree->add('import', 'real');
        symlink($real, $this->tree->root . '/import/linked');
        $outside = $this->temp->path('outside-plugin');
        mkdir($outside);
        file_put_contents($outside . '/plugin.json', json_encode(PluginTree::manifest('import', 'outside'), JSON_THROW_ON_ERROR));
        symlink($outside, $this->tree->root . '/import/outside');
        symlink($this->temp->path('nowhere'), $this->tree->root . '/import/dangling');
        file_put_contents($this->tree->root . '/import/stray', 'a file');
        mkdir($this->tree->root . '/import/Bad_Name');
        mkdir($this->tree->root . '/import/.hidden');
        mkdir($this->tree->root . '/import/x');
        mkdir($this->tree->root . '/other');
        $this->tree->add('import', 'unused', [], '');
        rename($this->tree->root . '/import/unused', $this->tree->root . '/other/unused');

        $this->assertSame(['import/real'], array_keys($this->scan()));
    }

    public function testAMissingOrLinkedKindDirectoryListsNothing(): void
    {
        $this->assertSame([], (new PluginScanner($this->temp->path('no-plugins')))->scan([]));

        $elsewhere = $this->temp->path('elsewhere');
        $this->tree->add('import', 'real');
        mkdir($elsewhere);
        rename($this->tree->root . '/import', $elsewhere . '/import');
        symlink($elsewhere . '/import', $this->tree->root . '/import');
        $this->assertSame([], $this->scan());
    }

    public function testMoreThanOneHundredPluginsAreCapped(): void
    {
        $ids = [];
        for ($i = 0; $i < PluginScanner::MAX_PLUGINS + 2; $i++) {
            $id = sprintf('p%03d', $i);
            $ids[] = $id;
            $this->tree->add('import', $id);
        }
        $plugins = $this->scan();
        $this->assertCount(102, $plugins);
        foreach ($ids as $index => $id) {
            $info = $plugins['import/' . $id];
            if ($index < PluginScanner::MAX_PLUGINS) {
                $this->assertSame(PluginStatus::Disabled, $info->status, $id);
            } else {
                $this->assertSame(PluginStatus::Broken, $info->status, $id);
                $this->assertSame(PluginScanner::REASON_TOO_MANY, $info->brokenReason);
                $this->assertNull($info->manifest);
            }
        }
    }

    public function testNamespacesThatDifferOnlyInCaseAreBroken(): void
    {
        $this->tree->add('import', 'foo-bar');
        $this->tree->add('import', 'foobar');
        $this->tree->add('export', 'foobar');
        $this->addBuiltInCsv();
        $this->tree->add('import', 'c-sv');

        $plugins = $this->scan(['import/foo-bar' => true, 'import/c-sv' => true]);
        $this->assertSame(PluginScanner::REASON_NAMESPACE_CLASH, $plugins['import/foo-bar']->brokenReason);
        $this->assertSame(PluginScanner::REASON_NAMESPACE_CLASH, $plugins['import/foobar']->brokenReason);
        $this->assertSame(PluginScanner::REASON_NAMESPACE_CLASH, $plugins['import/c-sv']->brokenReason, 'A plugin cannot take a built-in namespace.');
        $this->assertSame(PluginStatus::Enabled, $plugins['import/csv']->status, 'The built-in plugin keeps its namespace.');
        $this->assertSame(PluginStatus::Disabled, $plugins['export/foobar']->status, 'Kinds have separate namespaces.');
    }

    public function testABrokenPluginNeverStopsTheScan(): void
    {
        $this->tree->add('import', 'aaa', '{');
        $this->tree->add('import', 'bbb', str_repeat('[', 100000));
        $this->tree->add('import', 'ccc', "\xff\xfe");
        $this->tree->add('import', 'ddd');
        $plugins = $this->scan(['import/ddd' => true]);
        $this->assertSame(PluginStatus::Broken, $plugins['import/aaa']->status);
        $this->assertSame(PluginStatus::Broken, $plugins['import/bbb']->status);
        $this->assertSame(PluginStatus::Broken, $plugins['import/ccc']->status);
        $this->assertSame(PluginStatus::Enabled, $plugins['import/ddd']->status);
    }

    public function testTheRepositoryShipsTheFiveBuiltInPlugins(): void
    {
        $plugins = (new PluginScanner(RepoFiles::path('plugins')))->scan([]);
        $refs = array_map(static fn (PluginInfo $p): string => $p->ref(), $plugins);
        $this->assertSame(['import/csv', 'import/xlsx', 'export/csv', 'export/json', 'export/xlsx'], $refs);
        foreach ($plugins as $plugin) {
            $this->assertTrue($plugin->builtIn);
            // Until WP4 to WP6 add the classes the manifests are complete but the class files are not there yet.
            if ($plugin->status === PluginStatus::Broken) {
                $this->assertContains($plugin->brokenReason, [ManifestInvalid::SRC_MISSING, ManifestInvalid::CLASS_FILE_MISSING], $plugin->ref());
            }
        }
    }

    // ------------------------------------------------------------- T-PLG-4

    public function testScanningExecutesNoPluginCode(): void
    {
        $marker = $this->temp->path('marker');
        $top = 'file_put_contents(' . var_export($marker, true) . ', "ran\n", FILE_APPEND);';
        $this->tree->add('import', 'side-effect', [], PluginTree::importerSource('import', 'side-effect', 'Plugin', $top));
        // A helper class beside it, with the same side effect, is not touched either.
        file_put_contents(
            $this->tree->root . '/import/side-effect/src/Helper.php',
            "<?php\nnamespace TiffinDB\\Plugin\\Import\\SideEffect;\n{$top}\nfinal class Helper {}\n",
        );
        $class = 'TiffinDB\\Plugin\\Import\\SideEffect\\Plugin';

        $scanner = new PluginScanner($this->tree->root);
        $plugins = $scanner->scan(['import/side-effect' => true]);
        $scanner->scan([]);
        $this->assertFileDoesNotExist($marker, 'A scan ran plugin code.');
        $this->assertFalse(class_exists($class, false), 'A scan declared the plugin class.');
        $this->assertCount(1, $plugins);
        $this->assertSame(PluginStatus::Enabled, $plugins[0]->status);

        $importer = (new PluginLoader($this->tree->root))->importer($plugins[0]);
        $this->assertInstanceOf($class, $importer);
        $this->assertSame("ran\n", file_get_contents($marker), 'The class file ran once, when the enabled plugin was used.');
        $this->assertFalse(class_exists('TiffinDB\\Plugin\\Import\\SideEffect\\Helper', false), 'Only the class asked for was loaded.');
    }
}
