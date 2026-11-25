<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tools\Release;

use RuntimeException;

/**
 * What a release archive may hold (M7 release spec sections 3.1, 3.2, 3.5;
 * ADR 0021 decisions 1, 2, 4).
 *
 * Plain PHP with no dependency on src/ or vendor/: the build tool loads it
 * with require_once from a checkout that has no autoloader, and the tests
 * load it the same way. Every path handled here is relative to the archive's
 * top folder and uses "/" as its separator.
 *
 * The SPDX header rule of tests/repo/SpdxHeaderTest.php lives here (rule R5),
 * moved unchanged, so the release check and the repository test can never
 * differ.
 */
final class ReleaseRules
{
    /**
     * Section 3.1: what ships from the tracked files. A prefix ending in "/"
     * is a folder; anything else is an exact file. Names no plugin and no
     * admin tool.
     *
     * @var list<string>
     */
    public const INCLUDE = [
        'public/',
        'src/',
        'templates/',
        'strings/',
        'plugins/',
        'config/app.php',
        'config/notices.json',
        '.htaccess',
        'composer.json',
        'LICENSE',
        'README.md',
        'CHANGELOG.md',
        'THIRD-PARTY-NOTICES.md',
        'tools/admin/',
        'tools/host-check/',
        'docs/host/',
        'docs/user/',
        'docs/formats/',
        'docs/dependencies.md',
    ];

    /** @var list<string> */
    public const RULES = ['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8'];

    /** R1: test and development paths, never shipped. @var list<string> */
    public const DEVELOPMENT_PREFIXES = [
        'tests/',
        'tools/dev/',
        'tools/discovery/',
        'tools/release/',
        'docs/build/',
        'docs/specs/',
        'docs/decisions/',
        'docs/design/',
        'docs/brand/',
        '.github/',
    ];

    /** R1: development files, never shipped. @var list<string> */
    public const DEVELOPMENT_FILES = ['CLAUDE.md', 'phpunit.xml.dist', '.gitignore'];

    /** R1: folder names refused anywhere below vendor/. @var list<string> */
    public const VENDOR_REFUSED_SEGMENTS = ['tests', 'test', 'Tests', 'examples', '.github'];

    /** Section 3.2: folders left out of a shipped package, at any depth. @var list<string> */
    public const VENDOR_SKIPPED_FOLDERS = ['tests', 'test', 'Tests', 'examples', '.github', 'docs'];

    /** Section 3.2: files left out of a shipped package, at any depth. @var list<string> */
    public const VENDOR_SKIPPED_FILES = [
        'phpunit.xml',
        'phpunit.xml.dist',
        '.gitattributes',
        '.gitignore',
        '.editorconfig',
        'composer.lock',
        'Makefile',
    ];

    /** Section 3.2: file names always shipped from a package. @var list<string> */
    public const LICENCE_FILE_PATTERNS = ['LICENSE*', 'LICENCE*', 'NOTICE*', 'COPYING*'];

    /** R3: the data patterns of .gitignore, matched against the last segment. @var list<string> */
    public const DATA_PATTERNS = ['*.db', '*.sqlite*', '*.tiffin', '*.tiffindb.zip', '.env*'];

    /** R4: the only .htaccess files that may hold a rewrite directive. @var list<string> */
    public const REWRITE_ALLOWED = ['.htaccess', 'public/.htaccess'];

    public const NOTICES = 'config/notices.json';
    public const DEPENDENCIES = 'docs/dependencies.md';

    /** The shipped tables of docs/dependencies.md, by section heading. */
    public const RUNTIME_SECTION = 'Runtime dependencies (shipped)';
    public const FONTS_SECTION = 'Bundled font assets (shipped)';
    public const LOADER_SECTION = 'Composer class loader (shipped)';

    // ------------------------------------------------------------ SPDX (R5)

    private const SPDX = 'SPDX-License-Identifier: AGPL-3.0-or-later';
    private const COPYRIGHT = 'Copyright (C) TiffinDB contributors';
    private const WINDOW = 6;
    private const HTML_RELAXED_DIRECTORY = 'docs/design/model-ui/canvas-source/';

    /**
     * The register of exceptions to the SPDX header rule (M0 spec section 13;
     * M7 release spec section 17 adds the two JSON entries at the end).
     * Kinds: `path` is an exact repository path; `glob` is matched against
     * the whole path (`*` does not cross `/`); `name` is matched against the
     * file name in any directory.
     *
     * @var list<array{string, string, string}>
     */
    public const SPDX_EXEMPTIONS = [
        ['path', 'composer.json', 'JSON has no comments; the licence is in its license field (T-REPO-2).'],
        ['path', 'docs/design/model-ui/canvas-source/canvas.json', 'JSON has no comments; design canvas data.'],
        ['glob', 'plugins/*/*/plugin.json', 'JSON has no comments and a plugin manifest allows no other members; the licence is in its licence member (M3 spec section 4.1).'],
        ['name', '*.woff2', 'Binary third-party font; covered by the LICENSE.txt beside it and docs/dependencies.md.'],
        ['glob', 'public/assets/fonts/*/LICENSE.txt', 'Third-party licence text, reproduced verbatim.'],
        ['path', 'LICENSE', 'Canonical licence text; never edited.'],
        ['name', '*.md', 'Documentation, not source; covered by the repository licence.'],
        ['name', '.gitignore', 'Git control file.'],
        ['name', '.gitattributes', 'Git control file.'],
        ['path', 'config/notices.json', 'JSON has no comments; the third-party notices data (M7 release spec section 3.6).'],
        ['path', 'public/assets/app.webmanifest', 'JSON has no comments; the web manifest (ADR 0021 decision 8).'],
    ];

    private function __construct()
    {
    }

    /** The header rule that applies to a path, or null when no rule applies. */
    public static function spdxRuleFor(string $path): ?string
    {
        $name = basename($path);
        if ($name === '.htaccess') {
            return 'htaccess';
        }
        $lower = strtolower($name);
        if (str_ends_with($lower, '.xml.dist') || str_ends_with($lower, '.xml')) {
            return 'xml';
        }

        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'css' => 'css',
            'js' => 'js',
            'html' => 'html',
            'svg' => 'svg',
            'sh' => 'shell',
            'yml', 'yaml' => 'yaml',
            default => null,
        };
    }

    /** The exemption-register reason for a path, or null if it is not exempt. */
    public static function spdxExemptionFor(string $path): ?string
    {
        foreach (self::SPDX_EXEMPTIONS as [$kind, $pattern, $reason]) {
            $matches = match ($kind) {
                'path' => $path === $pattern,
                'glob' => fnmatch($pattern, $path, FNM_PATHNAME),
                'name' => fnmatch($pattern, basename($path)),
                default => throw new RuntimeException("Unknown exemption kind: {$kind}"),
            };
            if ($matches) {
                return $reason;
            }
        }

        return null;
    }

    /** Why a file breaks the SPDX header convention, or null if it complies. */
    public static function spdxProblem(string $path, string $contents): ?string
    {
        if (self::spdxExemptionFor($path) !== null) {
            return null;
        }
        $rule = self::spdxRuleFor($path);
        if ($rule === null) {
            return 'no header rule and no exemption covers this file type (add a rule or an exemption with a reason)';
        }

        $lines = array_slice(preg_split('/\r\n|\n|\r/', $contents) ?: [], 0, self::WINDOW + 1);
        $line = static fn (int $i): string => $lines[$i] ?? '';

        if ($rule === 'php') {
            $start = str_starts_with($line(0), '#!') ? 1 : 0;
            if ($line($start) !== '<?php') {
                return $start === 1
                    ? 'the line after "#!" must be exactly "<?php"'
                    : 'must start with a line that is exactly "<?php" (or "#!" then "<?php")';
            }
            $expected = ['/*', ' * ' . self::SPDX, ' * ' . self::COPYRIGHT];
            foreach ($expected as $offset => $text) {
                if ($line($start + 1 + $offset) !== $text) {
                    return 'the block comment "/*", " * ' . self::SPDX . '", " * ' . self::COPYRIGHT
                        . '" must follow "<?php" immediately';
                }
            }

            return null;
        }

        $wrap = match ($rule) {
            'css' => static fn (string $s): string => "/* {$s} */",
            'js' => static fn (string $s): string => "// {$s}",
            'html', 'svg', 'xml' => static fn (string $s): string => "<!-- {$s} -->",
            'htaccess', 'shell', 'yaml' => static fn (string $s): string => "# {$s}",
        };

        $at = null;
        for ($i = 0; $i + 1 < self::WINDOW; $i++) {
            if ($line($i) === $wrap(self::SPDX) && $line($i + 1) === $wrap(self::COPYRIGHT)) {
                $at = $i;
                break;
            }
        }
        if ($at === null) {
            return 'the lines "' . $wrap(self::SPDX) . '" and "' . $wrap(self::COPYRIGHT)
                . '" must appear, consecutively, within the first ' . self::WINDOW . ' lines';
        }

        $xmlDeclaration = str_starts_with($line(0), '<?xml');

        return match ($rule) {
            'css', 'js', 'htaccess', 'yaml' => $at === 0 ? null : 'the header must be the first two lines',
            'svg' => ($at === 0 || ($at === 1 && $xmlDeclaration))
                ? null
                : 'the header must be the first two lines, or follow the XML declaration immediately',
            'xml' => ($at === 1 && $xmlDeclaration) ? null : 'the header must follow the XML declaration immediately',
            'shell' => ($at === 1 && str_starts_with($line(0), '#!')) ? null : 'the header must follow the "#!" line immediately',
            'html' => match (true) {
                preg_match('/^<!doctype html>$/i', $line(0)) !== 1 => 'the file must start with the doctype line',
                $at === 1, str_starts_with($path, self::HTML_RELAXED_DIRECTORY) => null,
                default => 'the header must follow the doctype line immediately',
            },
        };
    }

    // ------------------------------------------------------ include (3.1)

    /** Whether a tracked path is selected by the include list. */
    public static function included(string $path): bool
    {
        foreach (self::INCLUDE as $entry) {
            if (str_ends_with($entry, '/') ? str_starts_with($path, $entry) : $path === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tracked paths the include list selects, in their given order.
     *
     * @param list<string> $tracked
     * @return list<string>
     */
    public static function select(array $tracked): array
    {
        return array_values(array_filter($tracked, [self::class, 'included']));
    }

    // ------------------------------------------- the dependency record

    /**
     * The Markdown tables of a document with the `## ` section each sits in.
     * Cells are trimmed and stripped of backticks (as T-DEP-1 parses them).
     *
     * @return list<array{section: string, header: list<string>, rows: list<list<string>>}>
     */
    public static function tables(string $markdown): array
    {
        $tables = [];
        $section = '';
        $current = null;
        foreach (preg_split('/\r\n|\n|\r/', $markdown) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '|')) {
                $cells = array_map(
                    static fn (string $cell): string => trim(str_replace('`', '', $cell)),
                    explode('|', trim($line, '|')),
                );
                if ($current === null) {
                    $current = ['section' => $section, 'header' => $cells, 'rows' => []];
                } elseif (preg_match('/^\|?(\s*:?-+:?\s*\|)*\s*:?-+:?\s*\|?$/', $line) !== 1) {
                    $current['rows'][] = $cells;
                }
                continue;
            }
            if ($current !== null) {
                $tables[] = $current;
                $current = null;
            }
            if (str_starts_with($line, '## ')) {
                $section = trim(substr($line, 3));
            }
        }
        if ($current !== null) {
            $tables[] = $current;
        }

        return $tables;
    }

    /**
     * The shipped packages of the runtime table: name => version cell,
     * commit cell, and the licence files listed for it (as archive paths).
     *
     * @return array<string, array{version: string, commit: string, licenceFiles: list<string>}>
     */
    public static function shippedPackages(string $record): array
    {
        $packages = [];
        foreach (self::tables($record) as $table) {
            if ($table['section'] !== self::RUNTIME_SECTION) {
                continue;
            }
            $version = array_search('Version', $table['header'], true);
            $commit = array_search('Commit', $table['header'], true);
            $files = array_search('Licence files (SHA-256)', $table['header'], true);
            foreach ($table['rows'] as $row) {
                $name = $row[0] ?? '';
                $licenceFiles = [];
                if ($files !== false) {
                    preg_match_all('/([A-Za-z0-9._-]+) \(([A-Za-z0-9.-]+)\) ([0-9a-f]{64})(?![0-9a-f])/', $row[$files] ?? '', $entries, PREG_SET_ORDER);
                    foreach ($entries as $entry) {
                        $licenceFiles[] = 'vendor/' . $name . '/' . $entry[1];
                    }
                }
                $packages[$name] = [
                    'version' => $version === false ? '' : ($row[$version] ?? ''),
                    'commit' => $commit === false ? '' : ($row[$commit] ?? ''),
                    'licenceFiles' => $licenceFiles,
                ];
            }
        }

        return $packages;
    }

    /**
     * Every shipped row of the record (packages, font families, the class
     * loader) with the licence files it names, as archive paths. A row that
     * names no licence file is returned with an empty list, which R7 refuses.
     *
     * @return list<array{row: string, licenceFiles: list<string>}>
     */
    public static function shippedRows(string $record): array
    {
        $rows = [];
        foreach (self::shippedPackages($record) as $name => $package) {
            $rows[] = ['row' => $name, 'licenceFiles' => $package['licenceFiles']];
        }
        foreach (self::tables($record) as $table) {
            if (!in_array($table['section'], [self::FONTS_SECTION, self::LOADER_SECTION], true)) {
                continue;
            }
            $column = array_search('Licence file shipped', $table['header'], true);
            foreach ($table['rows'] as $row) {
                $files = [];
                if ($column !== false) {
                    preg_match_all('#[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)+#', $row[$column] ?? '', $found);
                    $files = $found[0];
                }
                $rows[] = ['row' => $row[0] ?? '', 'licenceFiles' => $files];
            }
        }

        return $rows;
    }

    /** Whether a version cell gives the installed version (as T-DEP-1 matches it). */
    public static function versionCellGives(string $cell, string $version): bool
    {
        if ($version === '') {
            return false;
        }
        $pattern = '/(?<![A-Za-z0-9_.-])' . preg_quote($version, '/') . '(?![A-Za-z0-9_-]|\.\d)/';

        return preg_match($pattern, $cell) === 1;
    }

    // ---------------------------------------------- production vendor (3.2)

    /**
     * Reads `<vendor>/composer/installed.php` (Composer-generated; included
     * only on the build machine). Returns null when it is missing, a link,
     * or not in the Composer 2 shape.
     *
     * @return array{root: array<string, mixed>, versions: array<string, array<string, mixed>>}|null
     */
    public static function installed(string $vendor): ?array
    {
        $file = $vendor . '/composer/installed.php';
        if (is_link($file) || !is_file($file)) {
            return null;
        }
        $data = (static fn (string $path): mixed => include $path)($file);
        if (!is_array($data) || !is_array($data['root'] ?? null) || !is_array($data['versions'] ?? null)) {
            return null;
        }

        return $data;
    }

    /**
     * The refusals of section 3.2 for a prepared vendor folder.
     *
     * @return list<string>
     */
    public static function vendorProblems(string $vendor, string $record): array
    {
        $installed = self::installed($vendor);
        if ($installed === null) {
            return ['vendor: composer/installed.php is missing or not in the Composer 2 form'];
        }
        $problems = [];
        if (($installed['root']['dev'] ?? true) !== false) {
            $problems[] = 'vendor: installed.php says the install includes development packages (root dev is not false)';
        }
        $rootName = is_string($installed['root']['name'] ?? null) ? $installed['root']['name'] : '';
        $shipped = self::shippedPackages($record);

        foreach ($installed['versions'] as $name => $package) {
            $name = (string) $name;
            if (!is_array($package) || ($package['dev_requirement'] ?? true) !== false) {
                $problems[] = "vendor: {$name} is a development package";
                continue;
            }
            if ($name === $rootName || str_starts_with($name, 'composer/')) {
                continue;
            }
            $row = $shipped[$name] ?? null;
            if ($row === null) {
                $problems[] = "vendor: {$name} is not a shipped row of " . self::DEPENDENCIES;
                continue;
            }
            $version = is_string($package['pretty_version'] ?? null) ? $package['pretty_version'] : '';
            if (!self::versionCellGives($row['version'], $version)) {
                $problems[] = "vendor: {$name} version {$version} differs from the Version column of " . self::DEPENDENCIES;
            }
            $reference = is_string($package['reference'] ?? null) ? $package['reference'] : '';
            if ($reference === '' || $reference !== $row['commit']) {
                $problems[] = "vendor: {$name} reference {$reference} differs from the Commit column of " . self::DEPENDENCIES;
            }
        }
        foreach (array_keys($shipped) as $name) {
            if (!isset($installed['versions'][$name])) {
                $problems[] = "vendor: {$name} is a shipped row of " . self::DEPENDENCIES . ' but is not installed';
            }
        }

        if (!is_file($vendor . '/autoload.php') || is_link($vendor . '/autoload.php')) {
            $problems[] = 'vendor: autoload.php is missing';
        }

        $filesList = $vendor . '/composer/autoload_files.php';
        if (file_exists($filesList) || is_link($filesList)) {
            $files = is_link($filesList) ? null : (static fn (string $path): mixed => include $path)($filesList);
            if (!is_array($files)) {
                $problems[] = 'vendor: composer/autoload_files.php does not return a list of files';
            } else {
                $vendorReal = realpath($vendor);
                foreach ($files as $file) {
                    $real = is_string($file) ? realpath($file) : false;
                    $inside = false;
                    if ($real !== false && $vendorReal !== false) {
                        foreach (array_keys($shipped) as $name) {
                            if (str_starts_with($real, $vendorReal . '/' . $name . '/')) {
                                $inside = true;
                                break;
                            }
                        }
                    }
                    if (!$inside) {
                        $shown = is_string($file) && $vendorReal !== false && str_starts_with($file, $vendorReal . '/')
                            ? substr($file, strlen($vendorReal) + 1)
                            : basename(is_string($file) ? $file : '');
                        $problems[] = "vendor: composer/autoload_files.php names {$shown}, which is outside a shipped package folder";
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * The archive paths shipped from a prepared vendor folder (section 3.2):
     * `vendor/autoload.php`, `vendor/composer/` except `installed.json`, and
     * each shipped package folder without its test, example, CI, and
     * documentation folders and development files; licence files always
     * ship unless they sit in a folder R1 refuses. Links are listed, not
     * followed, so the archive step refuses them.
     *
     * @return list<string>
     */
    public static function vendorFiles(string $vendor, string $record): array
    {
        $paths = [];
        if (file_exists($vendor . '/autoload.php') || is_link($vendor . '/autoload.php')) {
            $paths[] = 'vendor/autoload.php';
        }
        foreach (self::walk($vendor, 'composer') as $relative) {
            if ($relative !== 'composer/installed.json') {
                $paths[] = 'vendor/' . $relative;
            }
        }
        foreach (array_keys(self::shippedPackages($record)) as $name) {
            if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $name) !== 1 || str_starts_with($name, 'composer/')) {
                continue;
            }
            foreach (self::walk($vendor, $name) as $relative) {
                $inside = explode('/', substr($relative, strlen($name) + 1));
                $file = array_pop($inside);
                $isLicence = false;
                foreach (self::LICENCE_FILE_PATTERNS as $pattern) {
                    $isLicence = $isLicence || fnmatch($pattern, $file);
                }
                $refused = array_intersect($inside, self::VENDOR_REFUSED_SEGMENTS) !== [];
                $skipped = array_intersect($inside, self::VENDOR_SKIPPED_FOLDERS) !== []
                    || in_array($file, self::VENDOR_SKIPPED_FILES, true);
                if ($refused || ($skipped && !$isLicence)) {
                    continue;
                }
                $paths[] = 'vendor/' . $relative;
            }
        }
        sort($paths, SORT_STRING);

        return array_values(array_unique($paths));
    }

    /**
     * Every non-folder entry below `<base>/<start>`, relative to base, with
     * links listed and never followed.
     *
     * @return list<string>
     */
    private static function walk(string $base, string $start): array
    {
        $found = [];
        $pending = [$start];
        while ($pending !== []) {
            $relative = array_pop($pending);
            $path = $base . '/' . $relative;
            if (!is_link($path) && is_dir($path)) {
                $names = scandir($path);
                if ($names === false) {
                    throw new RuntimeException("Cannot list vendor/{$relative}");
                }
                foreach ($names as $name) {
                    if ($name !== '.' && $name !== '..') {
                        $pending[] = $relative . '/' . $name;
                    }
                }
            } elseif (is_link($path) || file_exists($path)) {
                $found[] = $relative;
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }

    // -------------------------------------------------------- rules (3.5)

    /**
     * The violations of the release rules for an archive file list. Files
     * outside vendor/ are read from `$root`, files below vendor/ from
     * `$vendor` (default `$root/vendor`). `$rules` limits the check to some
     * rules (default: all). Each message names its rule and a relative path.
     *
     * @param list<string> $paths
     * @param list<string>|null $rules
     * @return list<string>
     */
    public static function violations(array $paths, string $root, ?string $vendor = null, ?array $rules = null): array
    {
        $vendor ??= $root . '/vendor';
        $run = static fn (string $rule): bool => $rules === null || in_array($rule, $rules, true);
        $source = static fn (string $path): string => str_starts_with($path, 'vendor/')
            ? $vendor . '/' . substr($path, 7)
            : $root . '/' . $path;
        $read = static function (string $path) use ($source): ?string {
            if (!self::safePath($path)) {
                return null;
            }
            $file = $source($path);
            if (is_link($file) || !is_file($file)) {
                return null;
            }
            $contents = file_get_contents($file);

            return $contents === false ? null : $contents;
        };

        $found = [];
        foreach ($paths as $path) {
            $segments = explode('/', $path);
            $name = end($segments);
            $shown = self::printable($path);
            $isVendor = $segments[0] === 'vendor';

            if ($run('R1')) {
                $development = in_array($path, self::DEVELOPMENT_FILES, true);
                foreach (self::DEVELOPMENT_PREFIXES as $prefix) {
                    $development = $development || str_starts_with($path, $prefix);
                }
                if ($isVendor && array_intersect(array_slice($segments, 1, -1), self::VENDOR_REFUSED_SEGMENTS) !== []) {
                    $development = true;
                }
                if ($development) {
                    $found[] = "R1: {$shown} is a test or development file";
                }
            }

            if ($run('R2') && ($path === 'config/local.php' || str_starts_with($path, 'data/') || str_starts_with($path, 'local/'))) {
                $found[] = "R2: {$shown} is local configuration or data";
            }

            if ($run('R3')) {
                foreach (self::DATA_PATTERNS as $pattern) {
                    if (fnmatch($pattern, str_replace("\0", '?', $name), FNM_CASEFOLD)) {
                        $found[] = "R3: {$shown} matches the data pattern {$pattern}";
                        break;
                    }
                }
            }

            $safe = self::safePath($path);

            if ($run('R4') && $safe && strcasecmp($name, '.htaccess') === 0 && !in_array($path, self::REWRITE_ALLOWED, true)) {
                $contents = $read($path);
                $rewrite = $contents === null;
                foreach (preg_split('/\r\n|\n|\r/', $contents ?? '') ?: [] as $line) {
                    $line = ltrim($line);
                    if (!str_starts_with($line, '#') && stripos($line, 'rewrite') !== false) {
                        $rewrite = true;
                    }
                }
                if ($rewrite) {
                    $found[] = "R4: {$shown} is a nested .htaccess with a rewrite directive";
                }
            }

            if ($run('R5') && $safe && !$isVendor) {
                $contents = $read($path);
                $problem = $contents === null ? 'cannot be read' : self::spdxProblem($path, $contents);
                if ($problem !== null) {
                    $found[] = "R5: {$shown} breaks the SPDX header rule: {$problem}";
                }
            }

            if ($run('R8') && !$safe) {
                $found[] = "R8: {$shown} holds an unsafe path segment or character";
            }
        }

        $record = null;
        if ($run('R6') || $run('R7')) {
            $record = in_array(self::DEPENDENCIES, $paths, true) ? $read(self::DEPENDENCIES) : null;
            if ($record === null) {
                $found[] = ($run('R6') ? 'R6: ' : 'R7: ') . self::DEPENDENCIES . ' is missing from the archive or unreadable';
            }
        }
        if ($run('R6') && $record !== null) {
            array_push($found, ...self::vendorRule($paths, $vendor, $record));
        }
        if ($run('R7')) {
            array_push($found, ...self::noticesRule($paths, in_array(self::NOTICES, $paths, true) ? $read(self::NOTICES) : null, $record ?? ''));
        }

        return $found;
    }

    /**
     * R6: the packages of the vendor paths are exactly the shipped rows, at
     * the recorded version and commit.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function vendorRule(array $paths, string $vendor, string $record): array
    {
        $found = [];
        $shipped = self::shippedPackages($record);
        $installed = self::installed($vendor);
        $present = [];
        foreach ($paths as $path) {
            if (!str_starts_with($path, 'vendor/') || $path === 'vendor/autoload.php') {
                continue;
            }
            $segments = explode('/', $path);
            if ($segments[1] === 'composer' && count($segments) > 2) {
                if ($path === 'vendor/composer/installed.json') {
                    $found[] = "R6: {$path} is shipped (it is never shipped)";
                }
                continue;
            }
            if (count($segments) < 4) {
                $found[] = "R6: {$path} is not inside a package folder";
                continue;
            }
            $present[$segments[1] . '/' . $segments[2]] = true;
        }
        foreach (array_keys($present) as $name) {
            $row = $shipped[$name] ?? null;
            $package = $installed['versions'][$name] ?? null;
            $version = is_array($package) && is_string($package['pretty_version'] ?? null) ? $package['pretty_version'] : '';
            $reference = is_array($package) && is_string($package['reference'] ?? null) ? $package['reference'] : '';
            if ($row === null) {
                $found[] = "R6: vendor/{$name}/ is not a shipped row of " . self::DEPENDENCIES;
            } elseif (!self::versionCellGives($row['version'], $version) || $reference === '' || $reference !== $row['commit']) {
                $found[] = "R6: vendor/{$name}/ differs from the version or commit of its row in " . self::DEPENDENCIES;
            }
        }
        foreach (array_keys($shipped) as $name) {
            if (!isset($present[$name])) {
                $found[] = "R6: vendor/{$name}/ is a shipped row of " . self::DEPENDENCIES . ' but has no files';
            }
        }

        return $found;
    }

    /**
     * R7: every component of the notices names only shipped licence files,
     * and every shipped row of the record has a component naming its
     * licence files.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function noticesRule(array $paths, ?string $json, string $record): array
    {
        $components = $json === null ? null : self::components($json);
        if ($components === null) {
            return ['R7: ' . self::NOTICES . ' is missing or malformed'];
        }
        $found = [];
        $listed = array_flip($paths);
        $named = [];
        foreach ($components as $component) {
            foreach ($component['files'] as $file) {
                $named[$component['id']][] = $file;
                if (!isset($listed[$file])) {
                    $found[] = 'R7: ' . self::NOTICES . " component {$component['id']} names " . self::printable($file) . ', which is not in the archive';
                }
            }
        }
        foreach (self::shippedRows($record) as $row) {
            $covered = false;
            foreach ($named as $files) {
                if ($row['licenceFiles'] !== [] && array_diff($row['licenceFiles'], $files) === []) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $found[] = "R7: {$row['row']} is a shipped row of " . self::DEPENDENCIES . ' with no component in ' . self::NOTICES;
            }
        }

        return $found;
    }

    /**
     * The components of a notices document (section 3.6), or null when the
     * document or any element is malformed.
     *
     * @return list<array{id: string, name: string, version: string, licence: string, files: list<string>}>|null
     */
    public static function components(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !array_is_list($data) || $data === []) {
            return null;
        }
        $components = [];
        $ids = [];
        foreach ($data as $element) {
            if (!is_array($element) || array_is_list($element)) {
                return null;
            }
            $keys = array_keys($element);
            sort($keys);
            if ($keys !== ['files', 'id', 'licence', 'name', 'version']) {
                return null;
            }
            foreach (['id', 'name', 'version', 'licence'] as $key) {
                if (!is_string($element[$key])) {
                    return null;
                }
            }
            if (preg_match('/^[a-z0-9-]{1,40}\z/', $element['id']) !== 1 || isset($ids[$element['id']])
                || trim($element['name']) === '' || trim($element['licence']) === ''
                || !is_array($element['files']) || !array_is_list($element['files']) || $element['files'] === []) {
                return null;
            }
            foreach ($element['files'] as $file) {
                if (!is_string($file)) {
                    return null;
                }
            }
            $ids[$element['id']] = true;
            $components[] = $element;
        }

        return $components;
    }

    /** R8: no `.`, `..`, or empty segment, no NUL, backslash, or character outside printable ASCII. */
    public static function safePath(string $path): bool
    {
        if (preg_match('/^[\x20-\x7e]+\z/', $path) !== 1 || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** A path made safe to print on one line. */
    public static function printable(string $path): string
    {
        return (string) preg_replace_callback('/[^\x20-\x7e]/', static fn (array $m): string => sprintf('\\x%02x', ord($m[0])), $path);
    }
}
