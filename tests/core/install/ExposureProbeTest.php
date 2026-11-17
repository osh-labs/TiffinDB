<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Install\ExposureProbe;
use TiffinDB\Core\Install\HttpProbe;
use TiffinDB\Core\Install\ProbeResponse;
use TiffinDB\Core\Install\ProbeTarget;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-INS-4 (verdicts with a fake HttpProbe) and T-INS-5 (probe paths). */
#[CoversClass(ExposureProbe::class)]
#[CoversClass(ProbeTarget::class)]
#[CoversClass(ProbeResponse::class)]
final class ExposureProbeTest extends TestCase
{
    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        mkdir($this->temp->path('www/my site/app/data'), 0755, true);
        mkdir($this->temp->path('www/my site/app/public/assets/css'), 0755, true);
        file_put_contents($this->temp->path('www/my site/app/public/assets/css/fonts.css'), "/* synthetic fonts.css */\n");
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private function app(): string
    {
        return $this->temp->path('www/my site/app');
    }

    private function target(?string $documentRoot, string $basePath = ''): ProbeTarget
    {
        return new ProbeTarget('example.test', '192.0.2.10', 80, false, $documentRoot, $basePath);
    }

    /**
     * A fake whose answer for each exposure path comes from $answer(path,
     * nonce), the nonce read from the probe file while it exists. The
     * positive control (`<basePath>/<name>`) is answered like a working
     * server: 200 with the control file from `<app>/public/`.
     *
     * @param \Closure(string, string): ?ProbeResponse $answer
     */
    private static function fake(string $dataDir, \Closure $answer): HttpProbe
    {
        return new class ($dataDir, $answer) implements HttpProbe {
            /** @var list<string> */
            public array $paths = [];
            /** @var list<string> */
            public array $controlPaths = [];
            /** @var list<bool> whether the probe file existed during each call */
            public array $fileExisted = [];

            public function __construct(private readonly string $dataDir, private readonly \Closure $answer)
            {
            }

            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                $control = dirname($this->dataDir) . '/public/' . basename($path);
                if ($path === rtrim($target->basePath, '/') . '/' . basename($path) && preg_match('/^probe-[0-9a-f]{16}\.txt\z/', basename($path)) === 1 && is_file($control)) {
                    $this->controlPaths[] = $path;

                    return new ProbeResponse(200, (string) file_get_contents($control));
                }
                $this->paths[] = $path;
                $files = glob($this->dataDir . '/probe-*.txt') ?: [];
                $this->fileExisted[] = count($files) === 1;
                $nonce = $files === [] ? '' : (string) file_get_contents($files[0]);

                return ($this->answer)($path, $nonce);
            }
        };
    }

    /** @return list<string> */
    private function probeFiles(): array
    {
        return [...(glob($this->app() . '/data/probe-*') ?: []), ...(glob($this->app() . '/public/probe-*') ?: [])];
    }

    private function runProbe(HttpProbe $http, ?ProbeTarget $target = null): string
    {
        return (new ExposureProbe(new SeededRandom('tiffindb-exposure-seed-1')))
            ->run($this->app() . '/data', $this->app(), $target ?? $this->target($this->temp->path('www')), $http);
    }

    /** @return iterable<string, array{\Closure(string, string): ?ProbeResponse, string}> */
    public static function verdicts(): iterable
    {
        yield 'served nonce' => [static fn (string $p, string $n): ?ProbeResponse => new ProbeResponse(200, "x{$n}y"), 'exposed'];
        yield '404' => [static fn (): ?ProbeResponse => new ProbeResponse(404, 'Not Found'), 'safe'];
        yield '403' => [static fn (): ?ProbeResponse => new ProbeResponse(403, 'Forbidden'), 'safe'];
        yield '200 without nonce' => [static fn (): ?ProbeResponse => new ProbeResponse(200, '<html>front controller</html>'), 'safe'];
        yield '206 with nonce' => [static fn (string $p, string $n): ?ProbeResponse => new ProbeResponse(206, $n), 'exposed'];
        yield '302' => [static fn (): ?ProbeResponse => new ProbeResponse(302, ''), 'inconclusive'];
        yield '500' => [static fn (): ?ProbeResponse => new ProbeResponse(500, ''), 'inconclusive'];
        yield '101' => [static fn (): ?ProbeResponse => new ProbeResponse(101, ''), 'inconclusive'];
        yield 'no answer' => [static fn (): ?ProbeResponse => null, 'inconclusive'];
    }

    /** @param \Closure(string, string): ?ProbeResponse $answer */
    #[DataProvider('verdicts')]
    public function testVerdicts(\Closure $answer, string $verdict): void
    {
        $fake = self::fake($this->app() . '/data', $answer);

        $this->assertSame($verdict, $this->runProbe($fake));
        $this->assertNotSame([], $fake->paths);
        $this->assertCount(1, $fake->controlPaths, 'The positive control is asked once, at <basePath>/<name>.');
        $this->assertMatchesRegularExpression('#^/probe-[0-9a-f]{16}\.txt\z#', $fake->controlPaths[0]);
        $this->assertNotContains(false, $fake->fileExisted, 'The file exists while it is asked for.');
        $this->assertSame([], $this->probeFiles(), 'The probe file is deleted.');
    }

    public function testOneServedAmongRefusedIsExposed(): void
    {
        $fake = self::fake($this->app() . '/data', static fn (string $path, string $nonce): ?ProbeResponse => str_contains($path, '/public/')
            ? new ProbeResponse(200, $nonce)
            : new ProbeResponse(404, ''));
        $verdict = $this->runProbe($fake, $this->target($this->temp->path('www'), '/my%20site/app/public'));

        $this->assertSame('exposed', $verdict);
        $this->assertCount(2, $fake->paths);
    }

    public function testOneUnclearAmongRefusedIsInconclusive(): void
    {
        $fake = self::fake($this->app() . '/data', static fn (string $path): ?ProbeResponse => str_contains($path, '/public/')
            ? null
            : new ProbeResponse(404, ''));

        $this->assertSame('inconclusive', $this->runProbe($fake, $this->target($this->temp->path('www'), '/my%20site/app/public')));
        $this->assertCount(2, $fake->paths);
    }

    public function testTheProbeFileIsDeletedWhenTheFakeThrows(): void
    {
        $fake = self::fake($this->app() . '/data', static function (): ?ProbeResponse {
            throw new \RuntimeException('fake failure');
        });
        try {
            $this->runProbe($fake);
            $this->fail('The exception should propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('fake failure', $error->getMessage());
        }
        $this->assertSame([], $this->probeFiles());
    }

    /**
     * Name-based virtual hosting, as in the review (F1): the real host serves
     * any file below the document root (no deny rule, so the data folder is
     * exposed), and `<basePath>/x` from `<app>/public/x`; an unknown Host
     * lands on a default site that answers 404, or 200 with its own page.
     */
    private function virtualHosts(): HttpProbe
    {
        return new class ($this->temp->path('www'), $this->app() . '/public') implements HttpProbe {
            public function __construct(private readonly string $root, private readonly string $public)
            {
            }

            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                if ($target->host === 'other.example:8080') {
                    return new ProbeResponse(200, '<html>Default site</html>');
                }
                if ($target->host !== 'example.test') {
                    return new ProbeResponse(404, 'Not Found');
                }
                $decoded = rawurldecode($path);
                $base = rawurldecode(rtrim($target->basePath, '/'));
                $candidates = [$this->root . $decoded];
                if (str_starts_with($decoded, $base . '/')) {
                    $candidates[] = $this->public . substr($decoded, strlen($base));
                }
                foreach ($candidates as $file) {
                    if (is_file($file)) {
                        return new ProbeResponse(200, (string) file_get_contents($file));
                    }
                }

                return new ProbeResponse(404, 'Not Found');
            }
        };
    }

    public function testAForgedOrUnknownHostIsNeverSafe(): void
    {
        foreach (['example.test' => 'exposed', 'unknown.example' => 'inconclusive', 'other.example:8080' => 'inconclusive'] as $host => $verdict) {
            $target = new ProbeTarget($host, '192.0.2.10', 80, false, $this->temp->path('www'), '/my%20site/app');
            $this->assertSame($verdict, $this->runProbe($this->virtualHosts(), $target), $host);
            $this->assertSame([], $this->probeFiles(), $host);
        }
    }

    public function testTheRealHostWithADenyRuleIsSafe(): void
    {
        // The same server, now refusing the data folder as the deny rule does.
        $deny = new class ($this->virtualHosts()) implements HttpProbe {
            public function __construct(private readonly HttpProbe $inner)
            {
            }

            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                return str_contains($path, '/data/') ? new ProbeResponse(403, 'Forbidden') : $this->inner->get($target, $path);
            }
        };
        $target = new ProbeTarget('example.test', '192.0.2.10', 80, false, $this->temp->path('www'), '/my%20site/app');

        $this->assertSame('safe', $this->runProbe($deny, $target));
    }

    /** The control file name the probe will use with seed tiffindb-exposure-seed-1. */
    private static function expectedControlName(): string
    {
        $random = new SeededRandom('tiffindb-exposure-seed-1');
        ExposureProbe::probeName($random);
        $random->bytes(16);

        return ExposureProbe::probeName($random);
    }

    /** @param \Closure(string): ?ProbeResponse $staticAnswer */
    private function scripted(\Closure $staticAnswer): HttpProbe
    {
        return new class ($staticAnswer) implements HttpProbe {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(private readonly \Closure $staticAnswer)
            {
            }

            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                $this->asked[] = $path;
                if (str_ends_with($path, '/assets/css/fonts.css')) {
                    return ($this->staticAnswer)($path);
                }

                return new ProbeResponse(404, 'Not Found');
            }
        };
    }

    public function testAControlWriteFailureFallsBackToTheStaticFile(): void
    {
        // Something already at the control file's path makes Fs::writeNew fail.
        $blocker = $this->app() . '/public/' . self::expectedControlName();
        mkdir($blocker);
        $static = (string) file_get_contents($this->app() . '/public/assets/css/fonts.css');
        $fake = $this->scripted(static fn (): ?ProbeResponse => new ProbeResponse(200, $static));

        $verdict = $this->runProbe($fake, $this->target($this->temp->path('www'), '/my%20site/app'));

        $this->assertSame('safe', $verdict);
        $this->assertSame('/my%20site/app/assets/css/fonts.css', $fake->asked[0]);
        $this->assertCount(2, $fake->asked);
        $this->assertDirectoryExists($blocker, 'What the probe did not write is not removed.');
        $this->assertSame([], glob($this->app() . '/data/probe-*') ?: []);
    }

    /** @return iterable<string, array{?ProbeResponse}> */
    public static function badStaticAnswers(): iterable
    {
        yield 'different body' => [new ProbeResponse(200, "/* another site's fonts.css */\n")];
        yield 'body with extra bytes' => [new ProbeResponse(200, "/* synthetic fonts.css */\n\n")];
        yield '404' => [new ProbeResponse(404, 'Not Found')];
        yield '304' => [new ProbeResponse(304, '')];
        yield 'no answer' => [null];
    }

    #[DataProvider('badStaticAnswers')]
    public function testAStaticFileThatDoesNotMatchIsInconclusive(?ProbeResponse $answer): void
    {
        mkdir($this->app() . '/public/' . self::expectedControlName());
        $fake = $this->scripted(static fn (): ?ProbeResponse => $answer);

        $this->assertSame('inconclusive', $this->runProbe($fake));
        $this->assertCount(1, $fake->asked, 'A failed control stops before the exposure paths.');
    }

    public function testAMissingStaticFileIsInconclusive(): void
    {
        mkdir($this->app() . '/public/' . self::expectedControlName());
        unlink($this->app() . '/public/assets/css/fonts.css');
        $fake = $this->scripted(static fn (): ?ProbeResponse => new ProbeResponse(200, ''));

        $this->assertSame('inconclusive', $this->runProbe($fake));
        $this->assertSame([], $fake->asked);
    }

    public function testTheControlFileIsRemovedWhenTheControlRequestThrows(): void
    {
        $fake = new class implements HttpProbe {
            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                throw new \RuntimeException('control failure');
            }
        };
        try {
            $this->runProbe($fake);
            $this->fail('The exception should propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('control failure', $error->getMessage());
        }
        $this->assertSame([], $this->probeFiles());
    }

    public function testProbeFileNameAndNonce(): void
    {
        $seen = [];
        $fake = self::fake($this->app() . '/data', static function (string $path, string $nonce) use (&$seen): ?ProbeResponse {
            $seen[] = [$path, $nonce];

            return new ProbeResponse(404, '');
        });
        $this->runProbe($fake);

        $this->assertMatchesRegularExpression('#/probe-[0-9a-f]{16}\.txt\z#', $seen[0][0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', $seen[0][1]);
    }

    public function testPathInsideAKnownDocumentRootIsEncoded(): void
    {
        $paths = ExposureProbe::paths(
            $this->app() . '/data',
            $this->temp->path('other-app'),
            $this->target($this->temp->path('www')),
            'probe-0123456789abcdef.txt',
        );

        $this->assertSame(['/my%20site/app/data/probe-0123456789abcdef.txt'], $paths);
    }

    public function testInTreeFolderWithAPublicBasePath(): void
    {
        $paths = ExposureProbe::paths($this->app() . '/data', $this->app(), $this->target(null, '/x/public'), 'probe-0123456789abcdef.txt');

        $this->assertSame(['/x/public/data/probe-0123456789abcdef.txt', '/x/data/probe-0123456789abcdef.txt'], $paths);
    }

    public function testDuplicatesAreRemoved(): void
    {
        // Document root is the application folder, base path empty: both rules give /data/<name>.
        $paths = ExposureProbe::paths($this->app() . '/data', $this->app(), $this->target($this->app()), 'probe-0123456789abcdef.txt');

        $this->assertSame(['/data/probe-0123456789abcdef.txt'], $paths);
    }

    public function testDataFolderIsTheDocumentRoot(): void
    {
        $data = $this->app() . '/data';
        $paths = ExposureProbe::paths($data, $this->temp->path('other-app'), $this->target($data), 'probe-0123456789abcdef.txt');

        $this->assertSame(['/probe-0123456789abcdef.txt'], $paths);
    }

    public function testNoPathsIsInconclusive(): void
    {
        mkdir($this->temp->path('outside'));
        $fake = self::fake($this->temp->path('outside'), static fn (): ?ProbeResponse => new ProbeResponse(404, ''));
        $verdict = (new ExposureProbe(new SeededRandom('tiffindb-exposure-seed-2')))
            ->run($this->temp->path('outside'), $this->app(), $this->target($this->app()), $fake);

        $this->assertSame('inconclusive', $verdict);
        $this->assertSame([], $fake->paths);
        $this->assertSame([], glob($this->temp->path('outside') . '/probe-*') ?: []);
    }

    /** @return iterable<string, array{string, string, int, ?string, string}> */
    public static function badTargets(): iterable
    {
        yield 'host with a space' => ['example.test evil', '192.0.2.10', 80, null, ''];
        yield 'host with CR LF' => ["example.test\r\nX: y", '192.0.2.10', 80, null, ''];
        yield 'host with a path' => ['example.test/x', '192.0.2.10', 80, null, ''];
        yield 'server address a name' => ['example.test', 'localhost', 80, null, ''];
        yield 'port 0' => ['example.test', '192.0.2.10', 0, null, ''];
        yield 'port too high' => ['example.test', '192.0.2.10', 65536, null, ''];
        yield 'relative document root' => ['example.test', '192.0.2.10', 80, 'www', ''];
        yield 'base path with a space' => ['example.test', '192.0.2.10', 80, null, '/a b'];
        yield 'base path with a query' => ['example.test', '192.0.2.10', 80, null, '/a?b'];
        yield 'base path without a slash' => ['example.test', '192.0.2.10', 80, null, 'a'];
        yield 'base path with a trailing slash' => ['example.test', '192.0.2.10', 80, null, '/a/'];
    }

    #[DataProvider('badTargets')]
    public function testBadTargetsAreRefused(string $host, string $address, int $port, ?string $root, string $base): void
    {
        $this->assertNull(ProbeTarget::tryCreate($host, $address, $port, false, $root, $base));
        $this->expectException(\InvalidArgumentException::class);
        new ProbeTarget($host, $address, $port, false, $root, $base);
    }

    public function testGoodTargets(): void
    {
        $this->assertSame('example.test', ProbeTarget::tryCreate('example.test:8080', '192.0.2.10', '8080', true, null, '/x/public')?->hostName());
        $this->assertSame('2001:db8::1', ProbeTarget::tryCreate('[2001:db8::1]:8443', '2001:db8::1', 8443, true, '/srv/www', '')?->hostName());
        $this->assertNull(ProbeTarget::tryCreate(null, '192.0.2.10', 80, false, null, ''));
        $this->assertNull(ProbeTarget::tryCreate('example.test', null, 80, false, null, ''));
        $this->assertNull(ProbeTarget::tryCreate('example.test', '192.0.2.10', null, false, null, ''));
        $this->assertNull(ProbeTarget::tryCreate('example.test', '192.0.2.10', '80x', false, null, ''));
    }
}
