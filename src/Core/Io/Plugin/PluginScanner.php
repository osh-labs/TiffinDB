<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Plugin;

use TiffinDB\Core\Storage\Fs;

/**
 * Plugin discovery (M3 spec section 4.2, ADR 0015 decision 3, P113,
 * P114). Security-critical. A scan lists `<root>/import/` and
 * `<root>/export/` with Fs::entries (which never follows a link), reads
 * each manifest with ManifestReader, and executes no plugin PHP: no file of
 * a plugin is included and no plugin class is named to the autoloader.
 *
 * Entries that are links, files, or names off the id pattern are ignored
 * (not listed). In each kind directory the first MAX_PLUGINS directories by
 * name are read and the rest listed as Broken (`too-many`), so a flood of
 * directories cannot make a scan unbounded. Two plugins of one kind whose
 * namespaces differ only in letter case (PHP class names ignore case, so
 * they would share classes) are Broken (`namespace-clash`), except that a
 * built-in plugin keeps its namespace. Any failure while reading one plugin
 * makes that plugin Broken and never stops the scan.
 *
 * Status: Broken when the manifest is invalid; else Unavailable when a
 * required PHP extension is not loaded; else Enabled when the stored
 * override says so, or when there is none and the plugin is built in;
 * else Disabled.
 */
final class PluginScanner
{
    public const BUILT_IN = ['import/csv', 'import/xlsx', 'export/csv', 'export/xlsx', 'export/json'];
    public const MAX_PLUGINS = 100;
    public const KINDS = Manifest::KINDS;
    public const REASON_TOO_MANY = 'too-many';
    public const REASON_NAMESPACE_CLASH = 'namespace-clash';
    public const REASON_UNREADABLE = 'unreadable';

    private readonly \Closure $extensionLoaded;
    private readonly ManifestReader $reader;

    /** @param \Closure(string): bool|null $extensionLoaded default extension_loaded (a test seam) */
    public function __construct(private readonly string $pluginsRoot, ?\Closure $extensionLoaded = null)
    {
        $this->extensionLoaded = $extensionLoaded ?? static fn (string $name): bool => extension_loaded($name);
        $this->reader = new ManifestReader();
    }

    /**
     * @param array<string, bool> $enabledOverrides '<kind>/<id>' => enabled, from PluginStates
     * @return list<PluginInfo> import plugins then export plugins, each by id
     */
    public function scan(array $enabledOverrides): array
    {
        $found = [];
        foreach (self::KINDS as $kind) {
            foreach ($this->scanKind($kind, $enabledOverrides) as $info) {
                $found[] = $info;
            }
        }

        return $found;
    }

    /**
     * @param array<string, bool> $overrides
     * @return list<PluginInfo>
     */
    private function scanKind(string $kind, array $overrides): array
    {
        $dir = $this->pluginsRoot . '/' . $kind;
        $ids = [];
        try {
            if (Fs::isDir($dir)) {
                foreach (Fs::entries($dir) as $name) {
                    if (preg_match(Manifest::ID_PATTERN, $name) === 1 && Fs::isDir($dir . '/' . $name)) {
                        $ids[] = $name;
                    }
                }
            }
        } catch (\RuntimeException) {
            return [];
        }

        /** @var array<string, Manifest|string> $read id => manifest, or the broken reason */
        $read = [];
        foreach ($ids as $index => $id) {
            if ($index >= self::MAX_PLUGINS) {
                $read[$id] = self::REASON_TOO_MANY;
                continue;
            }
            try {
                $read[$id] = $this->reader->read($dir . '/' . $id, $kind, $id);
            } catch (ManifestInvalid $error) {
                $read[$id] = $error->reason;
            } catch (\Throwable) {
                $read[$id] = self::REASON_UNREADABLE;
            }
        }
        foreach (self::clashes($kind, $read) as $id) {
            $read[$id] = self::REASON_NAMESPACE_CLASH;
        }

        $infos = [];
        foreach ($read as $id => $result) {
            $infos[] = $this->info($kind, $id, $result, $overrides);
        }

        return $infos;
    }

    /**
     * Ids whose namespace equals another's ignoring case. Of a group with a
     * built-in plugin, all but the built-in one; of any other group, all.
     *
     * @param array<string, Manifest|string> $read
     * @return list<string>
     */
    private static function clashes(string $kind, array $read): array
    {
        $groups = [];
        foreach ($read as $id => $result) {
            if ($result instanceof Manifest) {
                $groups[strtolower(Manifest::namespaceFor($kind, $id))][] = $id;
            }
        }
        $clashing = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                if (!in_array($kind . '/' . $id, self::BUILT_IN, true)) {
                    $clashing[] = $id;
                }
            }
        }

        return $clashing;
    }

    /** @param array<string, bool> $overrides */
    private function info(string $kind, string $id, Manifest|string $result, array $overrides): PluginInfo
    {
        $ref = $kind . '/' . $id;
        $builtIn = in_array($ref, self::BUILT_IN, true);
        if (!$result instanceof Manifest) {
            return new PluginInfo($kind, $id, null, PluginStatus::Broken, $builtIn, $result, []);
        }
        $missing = [];
        foreach ($result->requiresExtensions as $extension) {
            if (!self::isLoaded($this->extensionLoaded, $extension)) {
                $missing[] = $extension;
            }
        }
        if ($missing !== []) {
            return new PluginInfo($kind, $id, $result, PluginStatus::Unavailable, $builtIn, null, $missing);
        }
        $override = $overrides[$ref] ?? null;
        $enabled = is_bool($override) ? $override : $builtIn;

        return new PluginInfo($kind, $id, $result, $enabled ? PluginStatus::Enabled : PluginStatus::Disabled, $builtIn, null, []);
    }

    /** @param \Closure(string): bool $check */
    private static function isLoaded(\Closure $check, string $extension): bool
    {
        return $check($extension) === true;
    }
}
