<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Plugin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Plugin\Manifest;
use TiffinDB\Core\Io\Plugin\ManifestInvalid;
use TiffinDB\Core\Io\Plugin\ManifestReader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/PluginTree.php';

/**
 * T-PLG-1 (the five shipped manifests and a synthetic third-party one are
 * accepted) and T-PLG-2 (the rejection corpus, with reason codes). Every
 * manifest is synthetic or one of the repository's own.
 */
#[CoversClass(ManifestReader::class)]
#[CoversClass(Manifest::class)]
#[CoversClass(ManifestInvalid::class)]
final class ManifestReaderTest extends TestCase
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

    private function read(string $kind, string $id): Manifest
    {
        return (new ManifestReader())->read($this->tree->root . '/' . $kind . '/' . $id, $kind, $id);
    }

    private function assertRefused(string $reason, string $kind, string $id): void
    {
        try {
            $this->read($kind, $id);
            $this->fail('The manifest was accepted; expected ' . $reason . '.');
        } catch (ManifestInvalid $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertStringNotContainsString('Example', $error->getMessage(), 'The message never holds manifest text.');
        }
    }

    // ------------------------------------------------------------- T-PLG-1

    /** @return list<string> repository paths of the shipped manifests */
    private static function shippedManifests(): array
    {
        $found = [];
        foreach (Manifest::KINDS as $kind) {
            foreach (glob(RepoFiles::path('plugins/' . $kind) . '/*/plugin.json') ?: [] as $path) {
                $found[] = substr($path, strlen(RepoFiles::root()) + 1);
            }
        }
        sort($found);

        return $found;
    }

    public function testExactlyTheFiveBuiltInPluginsShipAManifest(): void
    {
        $refs = array_map(static fn (string $path): string => basename(dirname($path, 2)) . '/' . basename(dirname($path)), self::shippedManifests());
        $expected = PluginScanner::BUILT_IN;
        sort($expected);
        sort($refs);
        $this->assertSame($expected, $refs);
    }

    public function testTheShippedManifestsAreAccepted(): void
    {
        $classes = [];
        foreach (self::shippedManifests() as $path) {
            $kind = basename(dirname($path, 2));
            $id = basename(dirname($path));
            $bytes = RepoFiles::read($path);
            $data = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
            $short = substr($data['class'], strrpos($data['class'], '\\') + 1);
            // The classes come with their own work packages: the manifest is read in a copy of the
            // plugin directory with a placeholder class file (no plugin code runs here).
            $this->tree->add($kind, $id, $bytes, "<?php\n", $short);
            $manifest = $this->read($kind, $id);

            $this->assertSame($kind . '/' . $id, $manifest->ref());
            $this->assertSame(1, $manifest->api);
            $this->assertSame(Manifest::namespaceFor($kind, $id) . '\\' . $short, $manifest->class);
            $this->assertSame('AGPL-3.0-or-later', $manifest->licence, 'Shipped plugins carry the application licence.');
            $this->assertSame('1.0.0', $manifest->version);
            $this->assertNotSame('', $manifest->name);
            $this->assertNotSame('', $manifest->description);
            if ($kind === 'export') {
                $this->assertCount(1, $manifest->extensions);
                $this->assertNotNull($manifest->mediaType);
                $this->assertNotSame([], $manifest->scopes);
            } else {
                $this->assertNull($manifest->mediaType);
                $this->assertSame([], $manifest->scopes);
            }
            $classes[] = $manifest->class;

            // Once a work package adds the class, the manifest reads in place too.
            if (is_file(RepoFiles::path('plugins/' . $kind . '/' . $id . '/src/' . $short . '.php'))) {
                $inPlace = (new ManifestReader())->read(RepoFiles::path('plugins/' . $kind . '/' . $id), $kind, $id);
                $this->assertEquals($manifest, $inPlace);
            }
        }
        $this->assertSame([
            'TiffinDB\\Plugin\\Export\\Csv\\CsvExporter',
            'TiffinDB\\Plugin\\Export\\Json\\JsonExporter',
            'TiffinDB\\Plugin\\Export\\Xlsx\\XlsxExporter',
            'TiffinDB\\Plugin\\Import\\Csv\\CsvImporter',
            'TiffinDB\\Plugin\\Import\\Xlsx\\XlsxImporter',
        ], $classes);
    }

    public function testTheShippedXlsxPluginsNeedTheFiveExtensionsOfAdr0016(): void
    {
        foreach (['import', 'export'] as $kind) {
            $data = json_decode(RepoFiles::read('plugins/' . $kind . '/xlsx/plugin.json'), true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame(['zip', 'xmlreader', 'dom', 'filter', 'libxml'], $data['requires_extensions']);
        }
    }

    public function testASyntheticThirdPartyManifestIsAccepted(): void
    {
        $this->tree->add('import', 'example-format', [
            'name' => "  Example format \u{00E9}  ",
            'description' => '',
            'extensions' => ['exf', 'ex2'],
            'requires_extensions' => ['json', 'mbstring'],
            'licence' => 'MPL-2.0',
            'version' => '0.10.99999',
        ], '', 'ExampleReader');

        $manifest = $this->read('import', 'example-format');
        $this->assertSame('import/example-format', $manifest->ref());
        $this->assertSame('TiffinDB\\Plugin\\Import\\ExampleFormat', $manifest->namespace());
        $this->assertSame('TiffinDB\\Plugin\\Import\\ExampleFormat\\ExampleReader', $manifest->class);
        $this->assertSame("Example format \u{00E9}", $manifest->name, 'Names are trimmed.');
        $this->assertSame('', $manifest->description);
        $this->assertSame(['exf', 'ex2'], $manifest->extensions);
        $this->assertSame(['json', 'mbstring'], $manifest->requiresExtensions);
        $this->assertSame('0.10.99999', $manifest->version);
    }

    public function testMarkupInTextIsKeptAsPlainText(): void
    {
        // P133: plugin text is data; the web layer escapes it. It is neither refused nor changed here.
        $this->tree->add('import', 'markup', ['name' => '<script>x</script>', 'description' => '{plugins.title} &amp; <b>']);
        $manifest = $this->read('import', 'markup');
        $this->assertSame('<script>x</script>', $manifest->name);
        $this->assertSame('{plugins.title} &amp; <b>', $manifest->description);
    }

    public function testASyntheticExportManifestIsAccepted(): void
    {
        $this->tree->add('export', 'ex-out', ['scopes' => ['project', 'table'], 'media_type' => 'application/vnd.example+json']);
        $manifest = $this->read('export', 'ex-out');
        $this->assertSame('TiffinDB\\Plugin\\Export\\ExOut\\Plugin', $manifest->class);
        $this->assertSame(['project', 'table'], $manifest->scopes);
        $this->assertSame('application/vnd.example+json', $manifest->mediaType);
    }

    public function testEscapedTagsBracketsAndRepeatedValuesAreNotDuplicates(): void
    {
        // `<` escaped by JSON is not an open tag; equal names in different objects or inside
        // strings are not duplicate members.
        $data = PluginTree::manifest('import', 'escapes', ['description' => 'a {"api":1} [x] "q" \\ <b>']);
        $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG);
        $this->assertStringContainsString('\\u003C', $bytes);
        $this->tree->add('import', 'escapes', str_replace('"description"', '"description" ', $bytes));
        $this->assertSame('a {"api":1} [x] "q" \\ <b>', $this->read('import', 'escapes')->description);

        $this->tree->add('import', 'tagged', json_encode(PluginTree::manifest('import', 'tagged', ['description' => '<?php x']), JSON_THROW_ON_ERROR | JSON_HEX_TAG));
        $this->assertStringStartsWith('<?php', $this->read('import', 'tagged')->description, 'An escaped tag is plain text, never PHP.');
    }

    public function testLimitsAreInclusive(): void
    {
        $this->tree->add('import', 'limits', [
            'name' => str_repeat('n', 60),
            'description' => str_repeat('d', 200),
            'extensions' => array_map(static fn (int $i): string => 'e' . $i, range(0, 9)),
            'requires_extensions' => array_map(static fn (int $i): string => 'x' . $i, range(0, 19)),
            'class' => Manifest::namespaceFor('import', 'limits') . '\\P' . str_repeat('a', 63),
        ], '', 'P' . str_repeat('a', 63));
        $this->assertSame(60, strlen($this->read('import', 'limits')->name));

        $data = json_encode(PluginTree::manifest('import', 'size'), JSON_THROW_ON_ERROR);
        $padded = $data . str_repeat(' ', ManifestReader::MAX_BYTES - strlen($data));
        $this->tree->add('import', 'size', $padded);
        $this->assertSame('import/size', $this->read('import', 'size')->ref(), 'Exactly 16,384 bytes is allowed.');
    }

    public function testNamespaces(): void
    {
        $this->assertSame('TiffinDB\\Plugin\\Import\\Csv', Manifest::namespaceFor('import', 'csv'));
        $this->assertSame('TiffinDB\\Plugin\\Export\\MyFormat2', Manifest::namespaceFor('export', 'my-format2'));
        $this->assertSame('TiffinDB\\Plugin\\Export\\A1b', Manifest::namespaceFor('export', 'a-1b'));
    }

    // ------------------------------------------------------------- T-PLG-2

    /** @return iterable<string, array{string, string, array<string, mixed>|string}> reason, kind, changes or raw bytes */
    public static function refusedContents(): iterable
    {
        $valid = json_encode(PluginTree::manifest('import', 'example'), JSON_THROW_ON_ERROR);

        yield 'not JSON' => [ManifestInvalid::NOT_JSON, 'import', '{"api": 1,'];
        yield 'empty file' => [ManifestInvalid::NOT_JSON, 'import', ''];
        yield 'JSON with a byte order mark' => [ManifestInvalid::NOT_JSON, 'import', "\u{FEFF}" . $valid];
        yield 'too large' => [ManifestInvalid::TOO_LARGE, 'import', $valid . str_repeat(' ', ManifestReader::MAX_BYTES - strlen($valid) + 1)];
        yield 'depth over 8' => [ManifestInvalid::TOO_DEEP, 'import', '{"api": 1, "x": [[[[[[[[1]]]]]]]]}'];
        yield 'deeply nested object' => [ManifestInvalid::TOO_DEEP, 'import', str_repeat('{"a":', 200) . '1' . str_repeat('}', 200)];
        yield 'PHP open tag in a member' => [ManifestInvalid::PHP_TAG, 'import', ['description' => "<?php echo 'x'; __halt_compiler();"]];
        yield 'short open tag' => [ManifestInvalid::PHP_TAG, 'import', ['name' => 'a <?= 1 ?>']];
        yield 'PHP open tag outside the object' => [ManifestInvalid::PHP_TAG, 'import', '<?php exit; ?>' . $valid];
        yield 'duplicate member' => [ManifestInvalid::DUPLICATE_MEMBER, 'import', '{"class":"Evil\\\\X",' . substr($valid, 1)];
        yield 'duplicate member, escaped name' => [ManifestInvalid::DUPLICATE_MEMBER, 'import', '{"cl\\u0061ss":"Evil\\\\X",' . substr($valid, 1)];
        yield 'duplicate member, same value' => [ManifestInvalid::DUPLICATE_MEMBER, 'import', '{"api":1,' . substr($valid, 1)];
        yield 'not UTF-8' => [ManifestInvalid::NOT_UTF8, 'import', str_replace('Example format', "Example \xff", $valid)];
        yield 'a list' => [ManifestInvalid::NOT_AN_OBJECT, 'import', '[1, 2]'];
        yield 'an empty list' => [ManifestInvalid::NOT_AN_OBJECT, 'import', '[]'];
        yield 'a string' => [ManifestInvalid::NOT_AN_OBJECT, 'import', '"text"'];
        yield 'null' => [ManifestInvalid::NOT_AN_OBJECT, 'import', 'null'];
        yield 'empty object' => [ManifestInvalid::MISSING_MEMBER, 'import', '{}'];
        yield 'numeric member name only' => [ManifestInvalid::NOT_AN_OBJECT, 'import', '{"0": 1}'];
        yield 'numeric member name' => [ManifestInvalid::UNKNOWN_MEMBER, 'import', '{"api": 1, "7": 1}'];
        yield 'unknown member' => [ManifestInvalid::UNKNOWN_MEMBER, 'import', ['autoload' => 'src/boot.php']];
        yield 'member in another case' => [ManifestInvalid::UNKNOWN_MEMBER, 'import', ['Class' => 'X']];
        yield 'import with a media type' => [ManifestInvalid::UNKNOWN_MEMBER, 'import', ['media_type' => 'text/csv']];
        yield 'import with scopes' => [ManifestInvalid::UNKNOWN_MEMBER, 'import', ['scopes' => ['table']]];
        foreach (ManifestReader::MEMBERS as $member) {
            yield 'missing ' . $member => [ManifestInvalid::MISSING_MEMBER, 'import', [$member => null]];
        }
        yield 'export without media type' => [ManifestInvalid::MISSING_MEMBER, 'export', ['media_type' => null]];
        yield 'export without scopes' => [ManifestInvalid::MISSING_MEMBER, 'export', ['scopes' => null]];
        yield 'api 2' => [ManifestInvalid::BAD_API, 'import', ['api' => 2]];
        yield 'api as a string' => [ManifestInvalid::BAD_API, 'import', ['api' => '1']];
        yield 'api as a float' => [ManifestInvalid::BAD_API, 'import', '{' . substr(str_replace('"api":1', '"api":1.0', $valid), 1)];
        yield 'api true' => [ManifestInvalid::BAD_API, 'import', ['api' => true]];
        yield 'id not the directory name' => [ManifestInvalid::ID_MISMATCH, 'import', ['id' => 'other']];
        yield 'bad id pattern' => [ManifestInvalid::BAD_ID, 'import', ['id' => 'Example']];
        yield 'id with a path' => [ManifestInvalid::BAD_ID, 'import', ['id' => '../example']];
        yield 'id as a number' => [ManifestInvalid::BAD_ID, 'import', ['id' => 7]];
        yield 'kind mismatch' => [ManifestInvalid::KIND_MISMATCH, 'import', ['kind' => 'export']];
        yield 'kind in capitals' => [ManifestInvalid::KIND_MISMATCH, 'import', ['kind' => 'Import']];
        yield 'empty name' => [ManifestInvalid::BAD_NAME, 'import', ['name' => '   ']];
        yield 'long name' => [ManifestInvalid::BAD_NAME, 'import', ['name' => str_repeat('n', 61)]];
        yield 'name with a control character' => [ManifestInvalid::BAD_NAME, 'import', ['name' => "Example\u{202E}tamrof"]];
        yield 'name with a new line' => [ManifestInvalid::BAD_NAME, 'import', ['name' => "Example\nformat"]];
        yield 'name as a list' => [ManifestInvalid::BAD_NAME, 'import', ['name' => ['Example']]];
        yield 'long description' => [ManifestInvalid::BAD_DESCRIPTION, 'import', ['description' => str_repeat('d', 201)]];
        yield 'description with a NUL' => [ManifestInvalid::BAD_DESCRIPTION, 'import', ['description' => "A\u{0000}B"]];
        yield 'description null' => [ManifestInvalid::BAD_DESCRIPTION, 'import', '{' . substr(str_replace('"description":"A synthetic plugin for tests."', '"description":null', $valid), 1)];
        yield 'version with two parts' => [ManifestInvalid::BAD_VERSION, 'import', ['version' => '1.0']];
        yield 'version with a leading zero' => [ManifestInvalid::BAD_VERSION, 'import', ['version' => '01.0.0']];
        yield 'version with a suffix' => [ManifestInvalid::BAD_VERSION, 'import', ['version' => '1.0.0-beta']];
        yield 'version with a new line' => [ManifestInvalid::BAD_VERSION, 'import', ['version' => "1.0.0\n"]];
        $ns = Manifest::namespaceFor('import', 'example');
        yield 'class outside the namespace' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => 'TiffinDB\\Core\\Storage\\Fs']];
        yield 'class of another plugin' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => 'TiffinDB\\Plugin\\Import\\Csv\\CsvImporter']];
        yield 'class of the other kind' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => 'TiffinDB\\Plugin\\Export\\Example\\Plugin']];
        yield 'class with \\..' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => $ns . '\\..\\..\\Core\\Plugin']];
        yield 'class with ..' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => $ns . '\\..']];
        yield 'class with a slash' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => $ns . '\\a/../Plugin']];
        yield 'class in a sub-namespace' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => $ns . '\\Sub\\Plugin']];
        yield 'class with a leading backslash' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => '\\' . $ns . '\\Plugin']];
        yield 'class in lower case' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => $ns . '\\plugin']];
        yield 'class with .php' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => $ns . '\\Plugin.php']];
        yield 'class with a NUL' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => $ns . "\\Plugin\u{0000}"]];
        yield 'class name too long' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => $ns . '\\P' . str_repeat('a', 64)]];
        yield 'namespace in another case' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => 'tiffindb\\plugin\\import\\example\\Plugin']];
        yield 'only the namespace' => [ManifestInvalid::BAD_CLASS, 'import', ['class' => $ns]];
        yield 'licence off the list' => [ManifestInvalid::BAD_LICENCE, 'import', ['licence' => 'GPL-2.0-only']];
        yield 'proprietary licence' => [ManifestInvalid::BAD_LICENCE, 'import', ['licence' => 'LicenseRef-Proprietary']];
        yield 'licence in lower case' => [ManifestInvalid::BAD_LICENCE, 'import', ['licence' => 'mit']];
        yield 'licence expression' => [ManifestInvalid::BAD_LICENCE, 'import', ['licence' => 'MIT OR GPL-2.0-only']];
        yield 'extension with a dot' => [ManifestInvalid::BAD_EXTENSIONS, 'import', ['extensions' => ['.csv']]];
        yield 'extension in capitals' => [ManifestInvalid::BAD_EXTENSIONS, 'import', ['extensions' => ['CSV']]];
        yield 'no extensions' => [ManifestInvalid::BAD_EXTENSIONS, 'import', ['extensions' => []]];
        yield 'eleven extensions' => [ManifestInvalid::BAD_EXTENSIONS, 'import', ['extensions' => array_map(static fn (int $i): string => 'e' . $i, range(0, 10))]];
        yield 'duplicate extensions' => [ManifestInvalid::BAD_EXTENSIONS, 'import', ['extensions' => ['csv', 'csv']]];
        yield 'extensions as an object' => [ManifestInvalid::BAD_EXTENSIONS, 'import', ['extensions' => ['a' => 'csv']]];
        yield 'extensions as a string' => [ManifestInvalid::BAD_EXTENSIONS, 'import', ['extensions' => 'csv']];
        yield 'export with two extensions' => [ManifestInvalid::BAD_EXTENSIONS, 'export', ['extensions' => ['csv', 'txt']]];
        yield 'required extension in capitals' => [ManifestInvalid::BAD_REQUIRES_EXTENSIONS, 'import', ['requires_extensions' => ['Zip']]];
        yield 'too many required extensions' => [ManifestInvalid::BAD_REQUIRES_EXTENSIONS, 'import', ['requires_extensions' => array_map(static fn (int $i): string => 'x' . $i, range(0, 20))]];
        yield 'required extension as a number' => [ManifestInvalid::BAD_REQUIRES_EXTENSIONS, 'import', ['requires_extensions' => [5]]];
        yield 'bad media type' => [ManifestInvalid::BAD_MEDIA_TYPE, 'export', ['media_type' => 'text/CSV']];
        yield 'media type with parameters' => [ManifestInvalid::BAD_MEDIA_TYPE, 'export', ['media_type' => 'text/csv; charset=utf-8']];
        yield 'media type with a header break' => [ManifestInvalid::BAD_MEDIA_TYPE, 'export', ['media_type' => "text/csv\r\nX-Evil: 1"]];
        yield 'empty scopes' => [ManifestInvalid::BAD_SCOPES, 'export', ['scopes' => []]];
        yield 'unknown scope' => [ManifestInvalid::BAD_SCOPES, 'export', ['scopes' => ['all']]];
        yield 'duplicate scopes' => [ManifestInvalid::BAD_SCOPES, 'export', ['scopes' => ['table', 'table']]];
        yield 'scopes as a string' => [ManifestInvalid::BAD_SCOPES, 'export', ['scopes' => 'table']];
    }

    /** @param array<string, mixed>|string $manifest */
    #[DataProvider('refusedContents')]
    public function testRefusedContents(string $reason, string $kind, array|string $manifest): void
    {
        $this->tree->add($kind, 'example', $manifest);
        $this->assertRefused($reason, $kind, 'example');
    }

    public function testAnInvalidDirectoryNameOrKindIsRefused(): void
    {
        $this->assertRefused(ManifestInvalid::BAD_ID, 'import', 'Example');
        $this->assertRefused(ManifestInvalid::BAD_ID, 'import', '..');
        $this->assertRefused(ManifestInvalid::BAD_ID, 'other', 'example');
    }

    public function testAMissingManifestIsRefused(): void
    {
        $this->tree->dir('import', 'example');
        $this->assertRefused(ManifestInvalid::MANIFEST_MISSING, 'import', 'example');
        $this->assertRefused(ManifestInvalid::MANIFEST_MISSING, 'import', 'nothing-here');
    }

    public function testAManifestThatIsADirectoryIsRefused(): void
    {
        mkdir($this->tree->dir('import', 'example') . '/plugin.json');
        $this->assertRefused(ManifestInvalid::MANIFEST_MISSING, 'import', 'example');
    }

    public function testAManifestThatIsASymbolicLinkIsRefused(): void
    {
        $outside = $this->temp->path('elsewhere.json');
        file_put_contents($outside, json_encode(PluginTree::manifest('import', 'example'), JSON_THROW_ON_ERROR));
        $dir = $this->tree->add('import', 'example');
        unlink($dir . '/plugin.json');
        symlink($outside, $dir . '/plugin.json');
        $this->assertRefused(ManifestInvalid::MANIFEST_LINK, 'import', 'example');

        unlink($dir . '/plugin.json');
        symlink($this->temp->path('missing.json'), $dir . '/plugin.json');
        $this->assertRefused(ManifestInvalid::MANIFEST_LINK, 'import', 'example');
    }

    public function testAPluginDirectoryThatIsASymbolicLinkIsRefused(): void
    {
        $real = $this->tree->add('import', 'example');
        symlink($real, $this->tree->root . '/import/linked');
        $this->assertRefused(ManifestInvalid::MANIFEST_MISSING, 'import', 'linked');
    }

    public function testSrcMustBeARealDirectoryInsideThePlugin(): void
    {
        $dir = $this->tree->add('import', 'example', [], null);
        $this->assertRefused(ManifestInvalid::SRC_MISSING, 'import', 'example');

        file_put_contents($dir . '/src', 'not a directory');
        $this->assertRefused(ManifestInvalid::SRC_MISSING, 'import', 'example');
        unlink($dir . '/src');

        // src as a link out of the plugin directory, to a directory that holds the class file.
        $outside = $this->temp->path('outside-src');
        mkdir($outside);
        file_put_contents($outside . '/Plugin.php', PluginTree::importerSource('import', 'example'));
        symlink($outside, $dir . '/src');
        $this->assertRefused(ManifestInvalid::SRC_OUTSIDE, 'import', 'example');
        unlink($dir . '/src');

        // A link is refused even when it points inside the plugin directory.
        mkdir($dir . '/real-src');
        file_put_contents($dir . '/real-src/Plugin.php', PluginTree::importerSource('import', 'example'));
        symlink($dir . '/real-src', $dir . '/src');
        $this->assertRefused(ManifestInvalid::SRC_OUTSIDE, 'import', 'example');
    }

    public function testTheClassFileMustBeARegularFileInsideSrc(): void
    {
        $dir = $this->tree->add('import', 'example', [], null);
        mkdir($dir . '/src');
        $this->assertRefused(ManifestInvalid::CLASS_FILE_MISSING, 'import', 'example');

        mkdir($dir . '/src/Plugin.php');
        $this->assertRefused(ManifestInvalid::CLASS_FILE_MISSING, 'import', 'example');
        rmdir($dir . '/src/Plugin.php');

        $outside = $this->temp->path('Outside.php');
        file_put_contents($outside, PluginTree::importerSource('import', 'example'));
        symlink($outside, $dir . '/src/Plugin.php');
        $this->assertRefused(ManifestInvalid::CLASS_FILE_MISSING, 'import', 'example');
    }

    public function testEveryReasonIsAKnownCode(): void
    {
        foreach (ManifestInvalid::REASONS as $reason) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]{1,40}\z/', $reason);
            $this->assertSame($reason, (new ManifestInvalid($reason))->reason);
        }
        $this->assertSame(ManifestInvalid::REASONS, array_values(array_unique(ManifestInvalid::REASONS)));
        $this->expectException(\LogicException::class);
        new ManifestInvalid('made-up');
    }
}
