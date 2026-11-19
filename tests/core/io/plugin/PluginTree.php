<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Plugin;

use TiffinDB\Core\Io\Plugin\Manifest;
use TiffinDB\Tests\Support\TempDir;

/**
 * Builds synthetic plugin trees (`<root>/<kind>/<id>/plugin.json` and
 * `src/`) in a temporary directory for the plugin tests. Every plugin it
 * makes is synthetic; nothing is copied from real data.
 *
 * PHP class names live for the whole test process, so tests that load a
 * plugin give it an id no other test uses.
 */
final class PluginTree
{
    public readonly string $root;

    public function __construct(private readonly TempDir $temp)
    {
        $this->root = $temp->path('plugins');
        foreach (['', '/import', '/export'] as $dir) {
            if (!is_dir($this->root . $dir)) {
                mkdir($this->root . $dir, 0700);
            }
        }
    }

    /**
     * A valid manifest for a synthetic plugin, with $changes applied (a
     * value of null removes the member).
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public static function manifest(string $kind, string $id, array $changes = [], string $class = 'Plugin'): array
    {
        $data = [
            'api' => 1,
            'id' => $id,
            'kind' => $kind,
            'name' => 'Example format',
            'version' => '1.2.3',
            'class' => Manifest::namespaceFor($kind, $id) . '\\' . $class,
            'licence' => 'MIT',
            'extensions' => ['exf'],
            'requires_extensions' => [],
            'description' => 'A synthetic plugin for tests.',
        ];
        if ($kind === 'export') {
            $data['media_type'] = 'application/x-example';
            $data['scopes'] = ['table'];
        }
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** The plugin directory, made when missing. */
    public function dir(string $kind, string $id): string
    {
        $dir = $this->root . '/' . $kind . '/' . $id;
        if (!is_dir($dir)) {
            mkdir($dir, 0700);
        }

        return $dir;
    }

    /**
     * Writes a plugin: its manifest (an array, encoded, or raw bytes) and,
     * unless $classSource is null, `src/<class>.php`.
     *
     * @param array<string, mixed>|string $manifest
     */
    public function add(string $kind, string $id, array|string $manifest = [], ?string $classSource = '', string $class = 'Plugin'): string
    {
        $dir = $this->dir($kind, $id);
        if (is_array($manifest)) {
            $manifest = json_encode(self::manifest($kind, $id, $manifest, $class), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        file_put_contents($dir . '/plugin.json', $manifest);
        if ($classSource !== null) {
            if (!is_dir($dir . '/src')) {
                mkdir($dir . '/src', 0700);
            }
            file_put_contents($dir . '/src/' . $class . '.php', $classSource === '' ? self::importerSource($kind, $id, $class) : $classSource);
        }

        return $dir;
    }

    /**
     * Source of a working plugin class of the given kind. $topLevel is code
     * run when the file is included (before the class).
     */
    public static function importerSource(string $kind, string $id, string $class = 'Plugin', string $topLevel = '', string $modifiers = 'final ', string $body = ''): string
    {
        $namespace = Manifest::namespaceFor($kind, $id);
        if ($kind === 'export') {
            $implements = '\\TiffinDB\\Core\\Io\\Contract\\Exporter';
            $methods = <<<'PHP'
                public function settings(string $scope): \TiffinDB\Core\Io\Contract\Settings { return new \TiffinDB\Core\Io\Contract\Settings([]); }
                public function write(\TiffinDB\Core\Io\Contract\ExportSource $source, \TiffinDB\Core\Io\Contract\Settings $settings, string $targetPath): void {}
            PHP;
        } else {
            $implements = '\\TiffinDB\\Core\\Io\\Contract\\Importer';
            $methods = <<<'PHP'
                public function detect(\TiffinDB\Core\Io\Contract\SourceFile $file): \TiffinDB\Core\Io\Contract\Settings { return new \TiffinDB\Core\Io\Contract\Settings([]); }
                public function inspect(\TiffinDB\Core\Io\Contract\SourceFile $file, \TiffinDB\Core\Io\Contract\Settings $settings): \TiffinDB\Core\Io\Contract\Inspection
                { return new \TiffinDB\Core\Io\Contract\Inspection([new \TiffinDB\Core\Io\Contract\SourceTable('Sheet', ['A'], 0)], 'Example'); }
                public function rows(\TiffinDB\Core\Io\Contract\SourceFile $file, \TiffinDB\Core\Io\Contract\Settings $settings, int $table, int $from, int $limit): iterable { return []; }
            PHP;
        }

        return "<?php\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n * Copyright (C) TiffinDB contributors\n */\n\ndeclare(strict_types=1);\n\n"
            . "namespace {$namespace};\n\n{$topLevel}\n\n{$modifiers}class {$class} implements {$implements}\n{\n{$body}\n{$methods}\n}\n";
    }
}
