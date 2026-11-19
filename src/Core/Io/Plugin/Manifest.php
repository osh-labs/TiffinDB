<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Plugin;

/**
 * A plugin manifest that ManifestReader has validated (M3 spec section 4.1,
 * ADR 0015 decision 2). Every text is plain text for people (P133): the
 * web layer escapes it and never treats it as a message key or markup.
 *
 * The constructor checks nothing; only ManifestReader makes manifests from
 * files, and PluginLoader validates the kind, id, and class again before it
 * loads anything.
 */
final class Manifest
{
    public const API = 1;
    public const KINDS = ['import', 'export'];
    public const ID_PATTERN = '/^[a-z][a-z0-9-]{1,31}\z/';
    /** One class name segment: `[A-Z][A-Za-z0-9]{0,63}`. */
    public const CLASS_SEGMENT = '/^[A-Z][A-Za-z0-9]{0,63}\z/';
    public const ROOT_NAMESPACE = 'TiffinDB\\Plugin\\';

    /**
     * @param list<string> $extensions lower-case file name extensions (export: exactly one)
     * @param list<string> $scopes export only: `table`, `project`
     * @param list<string> $requiresExtensions PHP extension names
     */
    public function __construct(
        public readonly int $api,
        public readonly string $id,
        public readonly string $kind,
        public readonly string $name,
        public readonly string $version,
        public readonly string $class,
        public readonly string $licence,
        public readonly array $extensions,
        public readonly ?string $mediaType,
        public readonly array $scopes,
        public readonly array $requiresExtensions,
        public readonly string $description,
    ) {
    }

    /** '<kind>/<id>' */
    public function ref(): string
    {
        return $this->kind . '/' . $this->id;
    }

    /** The plugin's namespace without a trailing backslash. */
    public function namespace(): string
    {
        return self::namespaceFor($this->kind, $this->id);
    }

    /**
     * `TiffinDB\Plugin\<Kind>\<Id>` with both parts in StudlyCase and the
     * hyphens of the id removed (ADR 0015 decision 2): `import/csv` is
     * `TiffinDB\Plugin\Import\Csv`, `export/my-format` is
     * `TiffinDB\Plugin\Export\MyFormat`. The caller passes a valid kind and id.
     */
    public static function namespaceFor(string $kind, string $id): string
    {
        $studly = '';
        foreach (explode('-', $id) as $part) {
            $studly = $studly . ucfirst($part);
        }

        return self::ROOT_NAMESPACE . ucfirst($kind) . '\\' . $studly;
    }

    /** Whether $kind and $id are a valid plugin kind and id. */
    public static function isValidRef(string $kind, string $id): bool
    {
        return in_array($kind, self::KINDS, true) && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * The short class name when $class is the plugin namespace followed by
     * exactly one class name segment, else null.
     */
    public static function shortClass(string $class, string $kind, string $id): ?string
    {
        $prefix = self::namespaceFor($kind, $id) . '\\';
        if (!str_starts_with($class, $prefix)) {
            return null;
        }
        $short = substr($class, strlen($prefix));

        return preg_match(self::CLASS_SEGMENT, $short) === 1 ? $short : null;
    }
}
