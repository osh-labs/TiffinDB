<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\IssuedSession;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\Sessions;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\FixtureController;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\HomeController;
use TiffinDB\Web\Context;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Fallback;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\UploadLimits;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-17: the kernel driven end to end against the fixture project root
 * (status codes, error pages, and what error responses must never
 * reveal), the real `/` redirect, and the real CoreServices over a
 * temporary data folder. Synthetic data only.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(HomeController::class)]
#[CoversClass(CoreServices::class)]
#[CoversClass(Response::class)]
final class KernelTest extends TestCase
{
    /** @return array{0: Response, 1: string} */
    private static function get(string $uri, string $method = 'GET', array $query = []): array
    {
        return Fixtures::handle(Fixtures::kernel(null, Fixtures::routes()), Fixtures::request($method, $uri, $query));
    }

    private static function assertNoLeak(Response $response, string $log = ''): void
    {
        foreach (
            [
                Fixtures::root(), dirname(__DIR__, 2), '/fixture/secret', 'secret', '.php', 'RuntimeException',
                'ErrorException', 'Exception', 'Stack trace', '#0 ', 'Controller failure', 'Template failure',
                'Undefined', 'source_url', 'appearance_preview',
            ] as $needle
        ) {
            self::assertStringNotContainsString($needle, $response->body, "Body must not contain {$needle}");
        }
    }

    public function testHomePage(): void
    {
        [$response] = self::get('/');

        self::assertSame(200, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringStartsWith('<!DOCTYPE html>', $response->body);
        self::assertStringContainsString('<title>TiffinDB</title>', $response->body);
        self::assertStringContainsString('href="' . Fixtures::SOURCE_URL . '"', $response->body);
        self::assertStringContainsString('aria-label="Preview a theme"', $response->body);
        self::assertStringContainsString('<a href="/" aria-current="true">Light</a>', $response->body);
    }

    public function testHeadGivesTheSameResponse(): void
    {
        [$get] = self::get('/');
        [$head] = self::get('/', 'HEAD');

        self::assertSame(200, $head->status);
        self::assertSame($get->headers, $head->headers);
    }

    public function testBasePathWithSpecialCharactersIsEncodedInLinks(): void
    {
        [$response] = Fixtures::handle(
            Fixtures::kernel(),
            Fixtures::request('GET', '/my%20dir/100%25/', [], '/my dir/100%/public/index.php'),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('href="/my%20dir/100%25/assets/css/app.css?v=', $response->body);
        self::assertStringContainsString('href="/my%20dir/100%25/?theme=dark"', $response->body);
        self::assertStringNotContainsString('/my dir', $response->body);
    }

    public function testBasePathIsUsedForLinks(): void
    {
        [$response] = Fixtures::handle(
            Fixtures::kernel(),
            Fixtures::request('GET', '/sub/', [], '/sub/public/index.php'),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('href="/sub/assets/css/app.css?v=' . Fixtures::VERSION . '"', $response->body);
        self::assertStringContainsString('href="/sub/?theme=dark"', $response->body);
    }

    /** @return array<string, array{string, string, int, string}> */
    public static function errorCases(): array
    {
        return [
            'not found' => ['GET', '/nope', 404, 'Page not found'],
            'trailing slash' => ['GET', '/throws/', 404, 'Page not found'],
            'double slash' => ['GET', '//', 404, 'Page not found'],
            'dot segments' => ['GET', '/a/../', 404, 'Page not found'],
            'encoded traversal' => ['GET', '/%2e%2e/%2e%2e/config/app.php', 404, 'Page not found'],
            'path info' => ['GET', '/index.php/extra', 404, 'Page not found'],
            'front controller' => ['GET', '/index.php', 404, 'Page not found'],
            'directory' => ['GET', '/assets/', 404, 'Page not found'],
            'NUL' => ['GET', '/a%00b', 400, 'That address is not valid'],
            'invalid UTF-8' => ['GET', '/%C3%28', 400, 'That address is not valid'],
            'overlong' => ['GET', '/' . str_repeat('x', 5000), 400, 'That address is not valid'],
            'POST' => ['POST', '/', 405, 'That action is not available here'],
            'PUT' => ['PUT', '/', 405, 'That action is not available here'],
            'DELETE unknown path' => ['DELETE', '/nope', 405, 'That action is not available here'],
            'bad method token' => ["GE T", '/', 405, 'That action is not available here'],
            'route method' => ['GET', '/post-only', 405, 'That action is not available here'],
            'throws' => ['GET', '/throws', 500, 'Something went wrong'],
            'template throws' => ['GET', '/partial-failure', 500, 'Something went wrong'],
            'template warning' => ['GET', '/warning', 500, 'Something went wrong'],
            'not a response' => ['GET', '/not-a-response', 500, 'Something went wrong'],
            'bad template name' => ['GET', '/bad-template-name', 500, 'Something went wrong'],
        ];
    }

    #[DataProvider('errorCases')]
    public function testErrorPagesUseTheShell(string $method, string $uri, int $status, string $title): void
    {
        [$response] = self::get($uri, $method);

        self::assertSame($status, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringStartsWith('<!DOCTYPE html>', $response->body);
        self::assertStringContainsString("<title>{$title} - TiffinDB</title>", $response->body);
        self::assertStringContainsString("<h1>{$title}</h1>", $response->body);
        self::assertStringContainsString('<a href="/">Go to the start page</a>', $response->body);
        self::assertStringContainsString('href="' . Fixtures::SOURCE_URL . '"', $response->body);
        self::assertNoLeak($response);
    }

    public function testRequestPathIsNotReflected(): void
    {
        $marker = 'reflected-marker-' . str_repeat('z', 8);
        foreach (['/' . $marker, '/%3Cscript%3E' . $marker] as $uri) {
            [$response] = self::get($uri);
            self::assertStringNotContainsString($marker, $response->body);
            self::assertStringNotContainsString('<script', $response->body);
        }
    }

    public function testAllowHeaders(): void
    {
        [$response] = self::get('/', 'POST');
        self::assertSame('GET, HEAD', $response->header('Allow'));

        [$response] = self::get('/post-only');
        self::assertSame('POST', $response->header('Allow'));

        [$response] = self::get('/');
        self::assertNull($response->header('Allow'));
    }

    public function testRouteParametersReachTheController(): void
    {
        [$response] = self::get('/items/abc-1');

        self::assertSame(200, $response->status);
        self::assertSame('id=abc-1', $response->body);
    }

    public function testFailureIsLoggedWithoutRequestData(): void
    {
        [$response, $log] = self::get('/throws', 'GET', ['q' => 'query-marker']);

        self::assertSame(500, $response->status);
        self::assertStringContainsString('RuntimeException', $log);
        self::assertStringContainsString(FixtureController::SECRET_MESSAGE, $log);
        self::assertStringNotContainsString('query-marker', $log);
        self::assertSame(1, substr_count(trim($log), "\n") + 1, 'One log line.');
    }

    public function testTemplateFailureDiscardsPartialOutput(): void
    {
        $level = ob_get_level();
        [$response] = self::get('/partial-failure');

        self::assertSame($level, ob_get_level());
        self::assertStringNotContainsString('PARTIAL-OUTPUT-MARKER', $response->body);
        self::assertStringNotContainsString('NESTED-PARTIAL-MARKER', $response->body);
        self::assertStringNotContainsString('WARNING-OUTPUT-MARKER', self::get('/warning')[0]->body);
    }

    public function testErrorHandlerIsRestored(): void
    {
        $before = set_error_handler(null);
        restore_error_handler();

        self::get('/warning');

        $after = set_error_handler(null);
        restore_error_handler();
        self::assertSame($before, $after);
    }

    public function testAllowHeaderForUnknownMethods(): void
    {
        [$response] = self::get('/', 'DELETE');

        self::assertSame('GET, HEAD, POST', $response->header('Allow'));
    }

    public function testFallbackWhenTheErrorPageCannotRender(): void
    {
        $kernel = new Kernel(Fixtures::config(), sys_get_temp_dir() . '/tiffindb-no-such-root', null, new FakeServices());
        [$response] = Fixtures::handle($kernel, Fixtures::request('GET', '/'));

        self::assertSame(500, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        self::assertStringNotContainsString('tiffindb-no-such-root', $response->body);
        self::assertNoLeak($response);
    }

    public function testMissingApplicationFilesGiveTheFallbackEvenForRedirects(): void
    {
        // The real `/` would only redirect, but an installation without its
        // templates and strings is broken and fails closed.
        $kernel = new Kernel(Fixtures::config(), sys_get_temp_dir() . '/tiffindb-no-such-root', null, new FakeServices());
        [$response] = Fixtures::handle($kernel, Fixtures::request('GET', '/'));

        self::assertSame(500, $response->status);
        self::assertSame(Fallback::BODY, $response->body);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badConfigs(): array
    {
        $good = Fixtures::config();

        return [
            'empty' => [[]],
            'version missing' => [array_diff_key($good, ['version' => 1])],
            'source not https' => [['source_url' => 'javascript:alert(1)'] + $good],
            'source with quote' => [['source_url' => 'https://example.com/"x'] + $good],
            'source with trailing newline' => [['source_url' => "https://example.com/x\n"] + $good],
            'preview not bool' => [['appearance_preview' => 'yes'] + $good],
            'relative data_dir' => [['data_dir' => 'data'] + $good],
            'data_dir not a string' => [['data_dir' => ['x']] + $good],
            'unknown time zone' => [['timezone' => 'Nowhere/Example'] + $good],
            'time zone not a string' => [['timezone' => 1] + $good],
            'secure_cookies not a bool' => [['secure_cookies' => 1] + $good],
            'probe_port zero' => [['probe_port' => 0] + $good],
            'probe_port as text' => [['probe_port' => '8080'] + $good],
            'admin_recovery not a bool' => [['admin_recovery' => 0] + $good],
        ];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('badConfigs')]
    public function testInvalidConfigurationGivesThePlainFallback(array $config): void
    {
        [$response] = Fixtures::handle(Fixtures::kernel($config), Fixtures::request('GET', '/'));

        self::assertSame(500, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        self::assertStringNotContainsString('alert', $response->body);
        self::assertNoLeak($response);
    }

    // ------------------------------------------------ local configuration

    public function testLocalConfigurationIsMergedAfterChecking(): void
    {
        $merged = Kernel::withLocalConfig(Fixtures::config(), ['timezone' => 'Europe/Berlin', 'secure_cookies' => true, 'data_dir' => '/srv/example-data']);

        self::assertSame('Europe/Berlin', $merged['timezone']);
        self::assertTrue($merged['secure_cookies']);
        self::assertSame('/srv/example-data', $merged['data_dir']);
        self::assertSame(Fixtures::VERSION, $merged['version']);
        self::assertSame(Fixtures::config(), Kernel::withLocalConfig(Fixtures::config(), []));
    }

    /** T-CF7-2: the two M7 keys are accepted with their checked values. */
    public function testProbePortAndAdminRecoveryAreLocalKeys(): void
    {
        self::assertContains('probe_port', Kernel::LOCAL_KEYS);
        self::assertContains('admin_recovery', Kernel::LOCAL_KEYS);
        foreach ([1, 8080, 65535] as $port) {
            self::assertSame($port, Kernel::withLocalConfig(Fixtures::config(), ['probe_port' => $port])['probe_port']);
        }
        self::assertFalse(Kernel::withLocalConfig(Fixtures::config(), ['admin_recovery' => false])['admin_recovery']);
        self::assertTrue(Kernel::withLocalConfig(Fixtures::config(), ['admin_recovery' => true])['admin_recovery']);
    }

    /** @return array<string, array{mixed}> */
    public static function badLocalConfigs(): array
    {
        return [
            'not an array' => ['x'],
            'unknown key' => [['version' => '1']],
            'list' => [['/srv/data']],
            'relative data_dir' => [['data_dir' => 'data']],
            'NUL in data_dir' => [["data_dir" => "/srv/\0x"]],
            'time zone' => [['timezone' => 'Mars/Base']],
            'secure_cookies' => [['secure_cookies' => 'true']],
            'probe_port zero' => [['probe_port' => 0]],
            'probe_port negative' => [['probe_port' => -80]],
            'probe_port too high' => [['probe_port' => 65536]],
            'probe_port as text' => [['probe_port' => '8080']],
            'probe_port as float' => [['probe_port' => 8080.0]],
            'probe_port as bool' => [['probe_port' => true]],
            'probe_port as array' => [['probe_port' => [8080]]],
            'admin_recovery as text' => [['admin_recovery' => 'false']],
            'admin_recovery as int' => [['admin_recovery' => 0]],
        ];
    }

    #[DataProvider('badLocalConfigs')]
    public function testBadLocalConfigurationIsRefused(mixed $local): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Kernel::withLocalConfig(Fixtures::config(), $local);
    }

    // ------------------------------------------------ the real `/`

    public function testStartPageRedirectsByInstallStateAndSession(): void
    {
        $routes = Fixtures::applicationRoutes();
        foreach (
            [
                [new FakeServices(InstallState::NeedsDataFolder), [], '/setup'],
                [new FakeServices(InstallState::NeedsAdmin), [], '/setup'],
                [new FakeServices(), [], '/sign-in'],
                [(new FakeServices())->withSession(FakeServices::signedIn(true)), ['tdb_session' => FakeServices::TOKEN], '/projects'],
            ] as [$services, $cookies, $location]
        ) {
            [$response] = Fixtures::handle(
                Fixtures::kernel(Fixtures::config(false), $routes, $services),
                Fixtures::fullRequest('GET', '/', [], $cookies),
            );
            self::assertSame(303, $response->status);
            self::assertSame($location, $response->header('Location'));
            self::assertSame('', $response->body);
        }
    }

    public function testHeadOfTheStartPageRedirectsToo(): void
    {
        [$response] = Fixtures::handle(
            Fixtures::kernel(Fixtures::config(false), Fixtures::applicationRoutes()),
            Fixtures::request('HEAD', '/'),
        );

        self::assertSame(303, $response->status);
        self::assertSame('/sign-in', $response->header('Location'));
    }

    // ------------------------------------------------ CoreServices

    private ?TempDir $temp = null;

    protected function tearDown(): void
    {
        $this->temp?->remove();
        $this->temp = null;
    }

    /** @return array<string, mixed> */
    private function dataConfig(): array
    {
        $this->temp ??= TempDir::create();

        return Fixtures::config(false) + ['data_dir' => $this->temp->path()];
    }

    private static function services(array $config, ?FixedClock $clock = null): CoreServices
    {
        return new CoreServices(
            $config,
            Fixtures::appRoot(),
            $clock ?? new FixedClock('2026-11-03T10:00:00Z'),
            new SeededRandom('tiffindb-kernel-test-seed-1'),
            new PasswordHasher(['bcrypt' => 4]),
        );
    }

    /**
     * An installed system database with one Member; returns their session.
     *
     * @return array{0: IssuedSession, 1: int}
     */
    private function install(array $config, bool $installed = true): array
    {
        $clock = new FixedClock('2026-11-03T10:00:00Z');
        $random = new SeededRandom('tiffindb-kernel-install-seed');
        $system = SystemDatabase::create(new DataDirectory((string) $config['data_dir'], 'data'), $clock, $random);
        $users = new Users($system, new Keys($random), $clock);
        $sessions = new Sessions($system, $clock, $random);
        try {
            return $system->db()->write(static function () use ($system, $users, $sessions, $installed): array {
                if ($installed) {
                    $system->setMeta(SystemDatabase::META_INSTALLED_AT, '2026-11-03T10:00:00Z');
                }
                $person = $users->create('member', 'Example Member', Role::Member, 'x', false);

                return [$sessions->start($person->id), $person->id];
            });
        } finally {
            $system->db()->close();
        }
    }

    public function testCoreServicesBeforeInstallation(): void
    {
        $config = $this->dataConfig();
        $services = self::services($config);

        self::assertSame(InstallState::NeedsDataFolder, $services->installState());
        self::assertNull($services->resume(str_repeat('a', 43)));
        self::assertNull($services->takeFlash(str_repeat('a', 43)));
        $services->endSession(str_repeat('a', 43));
        self::assertNotNull($services->installer());
        try {
            $services->operations();
            self::fail('operations() before installation');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $services->newCsrfToken());
        self::assertSame('2026-11-03T10:00:00+00:00', $services->now()->format('c'));
        $services->close();
        self::assertSame([], array_values(array_diff((array) scandir((string) $config['data_dir']), ['.', '..'])), 'Nothing written.');
    }

    /** M3 spec part 2 section 2.3: CoreServices reports the running PHP's upload limits. */
    public function testCoreServicesReportThePhpUploadLimits(): void
    {
        $limits = self::services($this->dataConfig())->uploadLimits();

        self::assertEquals(UploadLimits::fromIni(), $limits);
        self::assertSame(UploadLimits::parse(ini_get('post_max_size')), $limits->postMaxBytes());
    }

    public function testCoreServicesWhileTheFirstAdminIsMissing(): void
    {
        $config = $this->dataConfig();
        [$session] = $this->install($config, false);
        $services = self::services($config);

        self::assertSame(InstallState::NeedsAdmin, $services->installState());
        self::assertNull($services->resume($session->token()), 'No sessions before installation.');
        $services->close();
    }

    public function testCoreServicesResumeFlashSettingsAndEndSession(): void
    {
        $config = $this->dataConfig();
        [$session, $userId] = $this->install($config);
        $services = self::services($config);
        $token = $session->token();

        self::assertSame(InstallState::Installed, $services->installState());
        $signedIn = $services->resume($token);
        self::assertNotNull($signedIn);
        self::assertSame('Example Member', $signedIn->actor->displayName);
        self::assertSame($session->record->csrfToken, $signedIn->session->csrfToken);
        self::assertNull($services->resume(str_repeat('b', 43)));
        self::assertNull($services->resume('not a token'));

        $services->setFlash($token, 'notice.password_changed', ['n' => 1]);
        self::assertSame(['key' => 'notice.password_changed', 'params' => ['n' => 1]], $services->takeFlash($token));
        self::assertNull($services->takeFlash($token));

        self::assertSame('light', $services->userSettings($userId)['theme']);

        try {
            $services->installer();
            self::fail('installer() once installed');
        } catch (\LogicException) {
            self::assertTrue(true);
        }

        $services->endSession($token);
        self::assertNull($services->resume($token));
        $services->close();
    }

    /**
     * P79 (P69 style): with no notice stored, takeFlash() opens no write
     * transaction. Another connection holds the write lock; a write would
     * wait for the busy timeout and fail, a plain read does not.
     */
    public function testTakeFlashWithoutANoticeTakesNoWriteLock(): void
    {
        $config = $this->dataConfig();
        [$session] = $this->install($config);
        $token = $session->token();
        $services = self::services($config);
        self::assertNotNull($services->resume($token));

        $other = new \PDO('sqlite:' . $config['data_dir'] . '/' . DataDirectory::SYSTEM_DATABASE);
        $other->exec('BEGIN IMMEDIATE');
        try {
            $started = microtime(true);
            self::assertNull($services->takeFlash($token));
            self::assertLessThan(2.0, microtime(true) - $started, 'No wait for the write lock.');
        } finally {
            $other->exec('ROLLBACK');
        }

        // With a notice, the write happens (and clears it), re-checked inside.
        $services->setFlash($token, 'notice.password_changed');
        self::assertSame('notice.password_changed', $services->takeFlash($token)['key'] ?? null);
        self::assertNull($services->takeFlash($token));
        $services->close();
    }

    public function testCoreServicesForgetEverythingOnClose(): void
    {
        $config = $this->dataConfig();
        $services = self::services($config);
        self::assertSame(InstallState::NeedsDataFolder, $services->installState());
        $services->close();

        [$session] = $this->install($config);
        self::assertSame(InstallState::Installed, $services->installState(), 'State is computed again after close().');
        self::assertNotNull($services->resume($session->token()));
        $services->close();
        $services->close();
    }

    public function testKernelWithRealCoreServices(): void
    {
        $config = $this->dataConfig();
        [$session] = $this->install($config);
        $routes = [
            ['GET', '/', [HomeController::class, 'show'], 'any'],
            ['GET', '/projects', [FixtureController::class, 'show'], 'user'],
            ['POST', '/projects', [FixtureController::class, 'show'], 'user'],
        ];
        $kernel = new Kernel($config, Fixtures::appRoot(), $routes, self::services($config));
        $cookies = ['tdb_session' => $session->token()];

        [$response] = Fixtures::handle($kernel, Fixtures::fullRequest('GET', '/', [], $cookies));
        self::assertSame('/projects', $response->header('Location'));

        [$response] = Fixtures::handle($kernel, Fixtures::fullRequest('GET', '/projects', [], $cookies));
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('csrf=' . $session->record->csrfToken, $response->body);

        [$response] = Fixtures::handle($kernel, Fixtures::fullRequest('POST', '/projects', ['_csrf' => str_repeat('0', 64)], $cookies));
        self::assertSame(403, $response->status);

        [$response] = Fixtures::handle($kernel, Fixtures::fullRequest('POST', '/projects', ['_csrf' => $session->record->csrfToken], $cookies));
        self::assertSame(200, $response->status);

        [$response] = Fixtures::handle($kernel, Fixtures::fullRequest('GET', '/projects', [], ['tdb_session' => str_repeat('c', 43)]));
        self::assertSame('/sign-in', $response->header('Location'));
        self::assertSame(0, $response->cookie('tdb_session')?->maxAge);
    }

    public function testKernelWithRealCoreServicesBeforeInstallation(): void
    {
        $kernel = new Kernel($this->dataConfig(), Fixtures::appRoot());

        [$response] = Fixtures::handle($kernel, Fixtures::request('GET', '/'));
        self::assertSame(303, $response->status);
        self::assertSame('/setup', $response->header('Location'));

        [$response] = Fixtures::handle($kernel, Fixtures::request('GET', '/projects'));
        self::assertSame('/setup', $response->header('Location'));
    }

    // ------------------------------------------------ M7 carried-forward items

    private const HOST = ['HTTP_HOST' => 'example.test'];

    /** @return list<mixed> */
    private static function m7Routes(): array
    {
        return [
            ['GET', '/sign-in', [FixtureController::class, 'show'], 'guest'],
            ['GET', '/projects', [FixtureController::class, 'show'], 'user'],
            ['POST', '/projects', [FixtureController::class, 'show'], 'user'],
            ['POST', '/sign-in', [FixtureController::class, 'show'], 'guest'],
            ['GET', '/recovery-probe', [KernelRecoveryProbeController::class, 'show'], 'guest'],
        ];
    }

    /**
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $cookies
     * @param array<string, mixed>    $server
     * @param array<string, mixed>    $config
     * @return array{0: Response, 1: string}
     */
    private static function sendM7(
        FakeServices $services,
        string $method,
        string $uri,
        array $post = [],
        array $cookies = [],
        array $server = [],
        array $config = [],
    ): array {
        $kernel = new Kernel($config + Fixtures::config(false), Fixtures::appRoot(), self::m7Routes(), $services);

        return Fixtures::handle($kernel, Fixtures::fullRequest($method, $uri, $post, $cookies, $server));
    }

    private static function memberServices(): FakeServices
    {
        return (new FakeServices())->withSession(FakeServices::signedIn(false, false));
    }

    private static function assertCsrfPage(Response $response): void
    {
        self::assertSame(403, $response->status);
        self::assertStringContainsString('<h1>This form has expired</h1>', $response->body);
        self::assertStringNotContainsString('handled ', $response->body, 'The controller must not run.');
    }

    /** @return array<string, array{string}> */
    public static function malformedOrigins(): array
    {
        return [
            'over 2048 characters' => ['http://example.test/' . str_repeat('a', 2048)],
            'exactly one character too long' => [str_repeat('h', Request::MAX_ORIGIN + 1)],
            'tab' => ["http://example.test\t"],
            'tab inside' => ["http://exa\tmple.test"],
            'newline' => ["http://example.test\n"],
            'DEL' => ["http://example.test\x7F"],
            'non-ASCII UTF-8' => ["http://ex\u{00E4}mple.test"],
            'invalid byte' => ["http://example.test\xFF"],
            'NUL byte' => ["http://example.test\0"],
        ];
    }

    /**
     * T-CF7-1: a sent but malformed Origin is no longer read as absent: an
     * unsafe-method request carrying one is refused with the CSRF page,
     * before the token check (a right token does not help), whatever
     * Sec-Fetch-Site says.
     */
    #[DataProvider('malformedOrigins')]
    public function testMalformedOriginIsRefusedOnPost(string $origin): void
    {
        $request = Fixtures::fullRequest('POST', '/projects', [], [], self::HOST + ['HTTP_ORIGIN' => $origin]);
        self::assertTrue($request->originMalformed);
        self::assertNull($request->origin);

        foreach ([null, 'same-origin', 'none'] as $site) {
            $server = self::HOST + ['HTTP_ORIGIN' => $origin];
            if ($site !== null) {
                $server['HTTP_SEC_FETCH_SITE'] = $site;
            }
            $services = self::memberServices();
            [$response] = self::sendM7($services, 'POST', '/projects', ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => FakeServices::TOKEN], $server);
            self::assertCsrfPage($response);
        }

        // An anonymous form is refused too.
        $token = str_repeat('a', 64);
        [$response] = self::sendM7(new FakeServices(), 'POST', '/sign-in', ['_csrf' => $token], ['tdb_csrf' => $token], self::HOST + ['HTTP_ORIGIN' => $origin]);
        self::assertCsrfPage($response);
    }

    /** T-CF7-1: safe methods never check the Origin, malformed or not. */
    #[DataProvider('malformedOrigins')]
    public function testMalformedOriginDoesNotAffectGetOrHead(string $origin): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            [$response] = self::sendM7(self::memberServices(), $method, '/projects', [], ['tdb_session' => FakeServices::TOKEN], self::HOST + ['HTTP_ORIGIN' => $origin]);
            self::assertSame(200, $response->status, $method);
        }
    }

    /** T-CF7-1: the as-built rules still hold for well-formed values. */
    public function testWellFormedOriginsKeepTheirRules(): void
    {
        $cookies = ['tdb_session' => FakeServices::TOKEN];
        $post = ['_csrf' => FakeServices::SESSION_CSRF];

        [$foreign] = self::sendM7(self::memberServices(), 'POST', '/projects', $post, $cookies, self::HOST + ['HTTP_ORIGIN' => 'http://attacker.example']);
        self::assertCsrfPage($foreign);
        [$null] = self::sendM7(self::memberServices(), 'POST', '/projects', $post, $cookies, self::HOST + ['HTTP_ORIGIN' => 'null']);
        self::assertCsrfPage($null);

        [$own] = self::sendM7(self::memberServices(), 'POST', '/projects', $post, $cookies, self::HOST + ['HTTP_ORIGIN' => 'http://example.test']);
        self::assertSame(200, $own->status);
        [$absent] = self::sendM7(self::memberServices(), 'POST', '/projects', $post, $cookies, self::HOST);
        self::assertSame(200, $absent->status);

        // A malformed Origin with a cross-site fetch is still the plain 403 of step 4.
        [$crossSite] = self::sendM7(self::memberServices(), 'POST', '/projects', $post, $cookies, self::HOST + ['HTTP_ORIGIN' => "x\t", 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertSame(403, $crossSite->status);
    }

    /** T-CF7-2: the kernel passes admin_recovery to the web Context; absent means true. */
    public function testAdminRecoveryReachesTheContext(): void
    {
        [$default] = self::sendM7(new FakeServices(), 'GET', '/recovery-probe');
        self::assertSame('recovery=on', $default->body);

        [$on] = self::sendM7(new FakeServices(), 'GET', '/recovery-probe', [], [], [], ['admin_recovery' => true]);
        self::assertSame('recovery=on', $on->body);

        [$off] = self::sendM7(new FakeServices(), 'GET', '/recovery-probe', [], [], [], ['admin_recovery' => false]);
        self::assertSame('recovery=off', $off->body);
    }

    /** T-CF7-6: a newer system database gets its own 503 page, not the project wording. */
    public function testSystemNewerIsShownAsItsOwnPage(): void
    {
        $services = new FakeServices();
        $services->installStateThrows = new Unavailable(Unavailable::SYSTEM_NEWER);
        [$response, $log] = self::sendM7($services, 'GET', '/sign-in');

        self::assertSame(503, $response->status);
        self::assertStringContainsString('<h1>This TiffinDB needs a newer version</h1>', $response->body);
        self::assertStringContainsString('Its data folder was written by a newer version of TiffinDB. Nothing was changed.', $response->body);
        self::assertStringNotContainsString('This project needs a newer TiffinDB', $response->body);
        self::assertNull($response->header('Retry-After'));
        self::assertSame('', $log);
    }

    /** T-CF7-6 end to end: a real system database at a higher version. */
    public function testSystemNewerWithRealCoreServices(): void
    {
        $config = $this->dataConfig();
        $this->install($config);
        $system = SystemDatabase::open(new DataDirectory((string) $config['data_dir'], 'data'));
        $system->db()->run("UPDATE meta SET value = '999' WHERE key = 'schema_version'");
        $system->db()->close();
        $before = hash_file('sha256', $config['data_dir'] . '/system.sqlite');

        $kernel = new Kernel($config, Fixtures::appRoot(), self::m7Routes(), self::services($config));
        [$response] = Fixtures::handle($kernel, Fixtures::request('GET', '/sign-in'));

        self::assertSame(503, $response->status);
        self::assertStringContainsString('<h1>This TiffinDB needs a newer version</h1>', $response->body);
        self::assertSame($before, hash_file('sha256', $config['data_dir'] . '/system.sqlite'));
    }
}

/** Prints Context::adminRecovery() (T-CF7-2). */
final class KernelRecoveryProbeController
{
    public function __construct(private readonly Context $context)
    {
    }

    public function show(Request $request): Response
    {
        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], 'recovery=' . ($this->context->adminRecovery() ? 'on' : 'off'));
    }
}
