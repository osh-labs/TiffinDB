# Release build tool

`build.php` writes the release archive `tiffindb-<version>.zip` and its `tiffindb-<version>.zip.sha256` file (ADR 0021; `docs/specs/M7-release.md` section 3). It downloads nothing, runs no shell, uses `git` only to list tracked files and check the tree, and runs without `vendor/autoload.php`. It is not shipped.

```
php tools/release/build.php --vendor=<dir> --out=<dir> [--test-build] [--allow-dirty]
```

- `--vendor`: a production `vendor/` prepared as below. The tool refuses one that holds a development package, or a package that is not a shipped row of `docs/dependencies.md` at the same version and commit.
- `--out`: where the archive goes. It must be outside the repository, or in a git-ignored folder of it such as `build/`. An existing archive of the same name is never overwritten.
- `--test-build`: also accept a `-dev` version (`config/app.php`). Release builds leave it out.
- `--allow-dirty`: build although an included path has uncommitted changes. The archive is then not reproducible from a commit, and the tool says so.

Exit status: 0 built; 1 refused (one plain line per problem on standard error); 2 not run from the command line, or an unknown or missing option.

On success the tool prints the SHA-256, the file count, the archive size, and the PHP, libzip, and zlib versions. Two builds from the same commit and the same `vendor/` give the same bytes on the same machine, in any time zone; to compare builds across machines, compare those versions first.

What ships and the rules every build checks are in `ReleaseRules.php` (`INCLUDE`, the vendor filter, rules R1 to R8); the archive is written by `ArchiveBuilder.php`. The repository test `tests/repo/ReleaseContentsTest.php` runs the same rules on the current tree, and `tests/tools/ReleaseBuildTest.php` covers the tool.

## Preparing `vendor/`

Where Composer can download (CI does this, see `.github/workflows/ci.yml`), from the repository root:

```
mkdir -p build/release-prep
cp composer.json build/release-prep/composer.json
composer install --working-dir=build/release-prep --no-dev --prefer-dist --no-interaction --no-progress --no-scripts
mv build/release-prep/vendor build/release-vendor
```

Neither this nor the procedure below uses `--classmap-authoritative` or `--optimize-autoloader`: the folder holds no `src/`, so an authoritative class map would list no TiffinDB class. The plain PSR-4 map (`TiffinDB\` to `src/`) is what ships.

### Without downloads (the build environment)

Composer cannot download packages there (`docs/build/orchestration.md` section 9). Start from the shared offline `vendor/` that holds OpenSpout (`<offline-vendor>` below), in a new folder `<prep>`. These are the commands that were run (Composer 2.8.12):

```
mkdir -p <prep>/vendor/openspout <prep>/vendor/composer
cd <prep>
cp <repository>/composer.json .
cp -r <offline-vendor>/openspout/openspout vendor/openspout/
php -r '$d = json_decode(file_get_contents($argv[1]), true);
  $p = array_values(array_filter($d["packages"], fn ($x) => $x["name"] === "openspout/openspout"));
  file_put_contents("vendor/composer/installed.json", json_encode(["packages" => $p, "dev" => false, "dev-package-names" => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' \
  <offline-vendor>/composer/installed.json
COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev
cp <offline-vendor>/composer/InstalledVersions.php vendor/composer/
```

Two steps go beyond the spec's outline (section 3.3), because without them the result does not work:

- `composer dump-autoload` maps only the packages listed in `vendor/composer/installed.json`; with no such file, OpenSpout's classes are left out of the class map. The file written above lists the one package. It is not shipped (it holds package authors' names and email addresses).
- `dump-autoload` writes neither `installed.php` nor `InstalledVersions.php`, but the class map it writes names `InstalledVersions.php`. The copy comes from the same Composer version.

Then write `vendor/composer/installed.php` by hand from the OpenSpout row of `docs/dependencies.md` (root `dev` false; one package entry):

```php
<?php return array(
    'root' => array(
        'name' => 'tiffindb/tiffindb',
        'pretty_version' => '1.0.0+no-version-set',
        'version' => '1.0.0.0',
        'reference' => null,
        'type' => 'project',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'openspout/openspout' => array(
            'pretty_version' => 'v5.2.2',
            'version' => '5.2.2.0',
            'reference' => '95403917927ef252f7ae82d919c55286a642962a',
            'type' => 'library',
            'install_path' => __DIR__ . '/../openspout/openspout',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'tiffindb/tiffindb' => array(
            'pretty_version' => '1.0.0+no-version-set',
            'version' => '1.0.0.0',
            'reference' => null,
            'type' => 'project',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
```

The offline copy of OpenSpout is a full checkout of the package, so it ships more of the package's own development files (benchmarks, tool configuration) than a `--prefer-dist` install would; the fixed filter of section 3.2 still removes the test, example, CI, and documentation folders. Release builds should use a `vendor/` prepared with downloads where possible.

## Building

Test build (any version, including `-dev`), twice, compared:

```
php tools/release/build.php --test-build --vendor=<prep>/vendor --out=<out-1>
TZ=America/New_York php tools/release/build.php --test-build --vendor=<prep>/vendor --out=<out-2>
cmp <out-1>/tiffindb-<version>.zip.sha256 <out-2>/tiffindb-<version>.zip.sha256
```

Release-candidate build, from the merged tree after the version commit (`config/app.php` `version` set to `1.0.0-rc.1`), with no uncommitted changes:

```
php tools/release/build.php --vendor=<prep>/vendor --out=<out>
```

Creating a git tag or a hosted release is the maintainer's decision alone.
