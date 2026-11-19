<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Plugin;

use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;

/**
 * Loads and instantiates Enabled plugins, lazily (M3 spec section 4.3, ADR
 * 0015 decision 3, P113). Security-critical: this is the only place that
 * runs plugin PHP.
 *
 * Before any plugin file runs, the loader checks again what the scanner
 * checked (kind, id, the class name is one segment inside the plugin's
 * namespace, `src/` is a real directory inside the plugin directory, the
 * class file is a regular file inside `src/`) and reads the class file as
 * tokens to see that it declares that class `final` in that namespace
 * (Reflection is not used: tests/core/schema/SqlStringsTest.php forbids it
 * under src/Core/Io/). It then registers, once per process for each
 * plugin, an autoloader that handles only names starting with the plugin's
 * namespace prefix, maps each further segment (`[A-Z][A-Za-z0-9]{0,63}`,
 * at most MAX_SEGMENTS) to a path under `src/`, and requires the file only
 * when it is a regular file inside `src/` (Fs::isInside resolves links).
 * The class must exist after autoloading, implement the contract interface
 * for its kind, and be instantiable with no arguments; the instance must be
 * of exactly that class (not a class_alias of another one).
 *
 * The one computed `new` and the one `require` of this file are the
 * exemptions of SqlStringsTest::RULE_EXEMPTIONS and
 * CompositionSeamTest::PLUGIN_LOADER.
 *
 * Any failure throws PluginFailure(REASON_FAILED) with a detail for the
 * server log; the caller logs it with the plugin's id. A plugin that is not
 * Enabled, or whose kind does not match the method, is \LogicException:
 * callers rescan first (section 4.3), so a request never reaches it. A
 * plugin is loaded at most once per loader (one loader per request).
 */
final class PluginLoader
{
    public const MAX_SEGMENTS = 8;

    /** @var array<string, Importer|Exporter> '<kind>/<id>' => instance */
    private array $loaded = [];

    public function __construct(private readonly string $pluginsRoot)
    {
    }

    public function importer(PluginInfo $plugin): Importer
    {
        $instance = $this->load($plugin, 'import');
        if (!$instance instanceof Importer) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin class is not an Importer.');
        }

        return $instance;
    }

    public function exporter(PluginInfo $plugin): Exporter
    {
        $instance = $this->load($plugin, 'export');
        if (!$instance instanceof Exporter) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin class is not an Exporter.');
        }

        return $instance;
    }

    private function load(PluginInfo $plugin, string $kind): Importer|Exporter
    {
        $manifest = $plugin->manifest;
        if ($plugin->status !== PluginStatus::Enabled || $manifest === null) {
            throw new \LogicException('Only an enabled plugin can be loaded.');
        }
        if ($plugin->kind !== $kind || $manifest->kind !== $kind || $manifest->id !== $plugin->id) {
            throw new \LogicException('The plugin is not of the kind asked for.');
        }
        $ref = $kind . '/' . $plugin->id;
        if (isset($this->loaded[$ref])) {
            return $this->loaded[$ref];
        }

        $interface = $kind === 'import' ? Importer::class : Exporter::class;
        $class = $manifest->class;
        $short = Manifest::isValidRef($kind, $manifest->id) ? Manifest::shortClass($class, $kind, $manifest->id) : null;
        if ($short === null) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin class name is not valid.');
        }
        $namespace = Manifest::namespaceFor($kind, $manifest->id);
        $src = $this->sourceDirectory($kind, $manifest->id);
        $classFile = self::classFile($class, $namespace . '\\', $src);
        if ($classFile === null) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin class file is missing.');
        }
        if (!self::declaresFinalClass(self::readSource($classFile), $namespace, $short)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin class file does not declare the final class.');
        }
        self::registerAutoloader($namespace . '\\', $src);

        try {
            if (!class_exists($class, true)) {
                throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin class does not exist.');
            }
            $interfaces = class_implements($class, false);
            if (!is_array($interfaces) || !in_array($interface, $interfaces, true)) {
                throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin class does not implement the contract.');
            }
            $instance = new $class();
        } catch (PluginFailure $failure) {
            throw $failure;
        } catch (\Throwable $error) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'Loading the plugin failed: ' . $error::class, $error);
        }
        if (!is_object($instance) || get_class($instance) !== $class || (!$instance instanceof Importer && !$instance instanceof Exporter)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin instance is not of the manifest class.');
        }
        $this->loaded[$ref] = $instance;

        return $instance;
    }

    /** The resolved `src/` directory of the plugin: a real directory inside a real plugin directory. */
    private function sourceDirectory(string $kind, string $id): string
    {
        $kindDir = $this->pluginsRoot . '/' . $kind;
        $pluginDir = $kindDir . '/' . $id;
        $src = $pluginDir . '/src';
        if (!Fs::isDir($kindDir) || !Fs::isDir($pluginDir) || !Fs::isDir($src) || !Fs::isInside($pluginDir, $kindDir) || !Fs::isInside($src, $pluginDir)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin directory layout is not valid.');
        }
        $real = realpath($src);
        if ($real === false) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin source directory cannot be resolved.');
        }

        return $real;
    }

    /**
     * The file for class $name when it lies under $prefix (exact case) and
     * every further segment is a class name segment, and the file is a
     * regular file inside $src; else null.
     */
    private static function classFile(string $name, string $prefix, string $src): ?string
    {
        if (!str_starts_with($name, $prefix)) {
            return null;
        }
        $segments = explode('\\', substr($name, strlen($prefix)));
        if (count($segments) > self::MAX_SEGMENTS) {
            return null;
        }
        $path = $src;
        foreach ($segments as $segment) {
            if (preg_match(Manifest::CLASS_SEGMENT, $segment) !== 1) {
                return null;
            }
            $path = $path . '/' . $segment;
        }
        $path = $path . '.php';
        if (!Fs::isFile($path) || !Fs::isInside($path, $src)) {
            return null;
        }

        return $path;
    }

    /** Registers the plugin's autoloader once per process; a prefix taken by another directory is a failure. */
    private static function registerAutoloader(string $prefix, string $src): void
    {
        // Lower-case namespace prefix => resolved src directory, for this process (a static
        // variable: SqlStringsTest refuses `self::$name`, a member named by a variable).
        /** @var array<string, string> $registered */
        static $registered = [];
        $key = strtolower($prefix);
        if (isset($registered[$key])) {
            if ($registered[$key] !== $src) {
                throw new PluginFailure(PluginFailure::REASON_FAILED, 'Another plugin directory has the same namespace.');
            }

            return;
        }
        $autoloader = static function (string $name) use ($prefix, $src): void {
            $file = self::classFile($name, $prefix, $src);
            if ($file !== null) {
                require $file;
            }
        };
        spl_autoload_register($autoloader);
        $registered[$key] = $src;
    }

    private static function readSource(string $file): string
    {
        try {
            $source = ErrorTrap::run(static fn (): string|false => file_get_contents($file), 'Cannot read the plugin class file.');
        } catch (\RuntimeException $error) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin class file cannot be read.', $error);
        }
        if (!is_string($source)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin class file cannot be read.');
        }

        return $source;
    }

    /**
     * Whether the source declares class $short in namespace $namespace
     * exactly once, as `final` and not `abstract`, and nowhere without
     * `final`. Read with token_get_all; nothing is executed.
     */
    private static function declaresFinalClass(string $source, string $namespace, string $short): bool
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = $token;
            }
        }
        $current = '';
        $final = 0;
        $other = 0;
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;
                $current = is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true) ? $next[1] : '';
                continue;
            }
            if ($token[0] !== T_CLASS) {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            $name = $tokens[$i + 1] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            if (!is_array($name) || $name[0] !== T_STRING || strcasecmp($name[1], $short) !== 0 || strcasecmp($current, $namespace) !== 0) {
                continue;
            }
            $isFinal = false;
            $isAbstract = false;
            for ($j = $i - 1; $j >= 0; $j--) {
                $modifier = $tokens[$j];
                if (!is_array($modifier) || !in_array($modifier[0], [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                    break;
                }
                $isFinal = $isFinal || $modifier[0] === T_FINAL;
                $isAbstract = $isAbstract || $modifier[0] === T_ABSTRACT;
            }
            if ($isFinal && !$isAbstract && $name[1] === $short && $current === $namespace) {
                $final++;
            } else {
                $other++;
            }
        }

        return $final === 1 && $other === 0;
    }
}
