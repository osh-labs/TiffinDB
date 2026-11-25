<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SignInOutcome;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Context;
use TiffinDB\Web\Controller\RecoveryController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-91 and T-WEB-90 (M7 screens spec section 11.1, M7-WP5): the Admin
 * recovery page (docs/design/m7/recovery.md) through the kernel with the
 * real templates, strings, and core operations over a CoreFixture, signed
 * out with the anonymous CSRF cookie.
 *
 * No response says whether a username exists, is an Admin, or is active,
 * or whether a code file was written; the code never appears in a body;
 * with `admin_recovery` off every route is the 404 page; hostile and
 * tampered requests never give a 500 and change nothing. Synthetic data
 * only; no sockets.
 */
#[CoversClass(RecoveryController::class)]
final class RecoveryScreenTest extends TestCase
{
    private const ANON = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const HOST = 'tiffindb.example';
    private const NEW_PASSWORD = 'plum orbit candle 7';
    private const TITLE = 'Get back into an Admin account';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;

    /** @var list<string> Every response body, searched for the code at the end. */
    private array $bodies = [];

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // ---------------------------------------------------------------- harness

    /**
     * A signed-out request with the anonymous CSRF cookie and, for a POST,
     * its token (unless $post names `_csrf`).
     *
     * @param array<string, mixed>  $post
     * @param array<string, string> $server
     * @param array<string, mixed>  $config
     */
    private function send(string $method, string $uri, array $post = [], array $server = [], array $config = [], ?string $session = null): Response
    {
        $cookies = [Context::CSRF_COOKIE => self::ANON];
        if ($session !== null) {
            $cookies = [Context::SESSION_COOKIE => $session];
        }
        if ($method === 'POST' && !array_key_exists('_csrf', $post)) {
            $post['_csrf'] = self::ANON;
        }
        $kernel = new Kernel($config + Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
        [$response, $log] = Fixtures::handle($kernel, Fixtures::fullRequest($method, $uri, $post, $cookies, $server + ['REMOTE_ADDR' => '192.0.2.20', 'HTTP_HOST' => self::HOST]));
        self::assertNotSame(500, $response->status, "{$method} {$uri}: " . $log);
        $this->bodies[] = $response->body;

        return $response;
    }

    /** @param array<string, mixed> $post */
    private function recover(array $post): Response
    {
        return $this->send('POST', '/recover', $post);
    }

    /** @return array<string, string> */
    private static function attempt(string $username, string $code, string $password = self::NEW_PASSWORD, ?string $again = null): array
    {
        return ['username' => $username, 'code' => $code, 'password' => $password, 'password_again' => $again ?? $password];
    }

    private function codeFile(): string
    {
        return $this->core->data->path() . '/recovery-code.txt';
    }

    /** Starts recovery through the page; returns the code written. */
    private function startAndReadCode(): string
    {
        $response = $this->send('POST', '/recover/start');
        self::assertSame(303, $response->status);
        $lines = explode("\n", (string) file_get_contents($this->codeFile()));
        self::assertMatchesRegularExpression('/^[A-Z0-9-]{16,19}\z/', $lines[1]);

        return $lines[1];
    }

    /** Clears every throttle bucket, so one test can try several causes. */
    private function clearThrottle(): void
    {
        $this->core->system->db()->write(static function ($db): void {
            $db->run('DELETE FROM login_throttle');
        });
    }

    private function passwordHash(string $username): string
    {
        return (string) $this->core->system->db()->value('SELECT password_hash FROM users WHERE username = ?', [$username]);
    }

    private static function x(Response $response): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($response->body));
    }

    private static function assertPage(Response $response, int $status): DOMXPath
    {
        self::assertSame($status, $response->status, $response->body);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);
        self::assertSame(self::TITLE, HtmlAssertions::text($response->body, '//h1'));

        return self::x($response);
    }

    /** The body without the CSRF token's value (the same here, but never part of the comparison). */
    private static function withoutToken(string $body): string
    {
        return (string) preg_replace('/name="_csrf" value="[^"]*"/', 'name="_csrf" value=""', $body);
    }

    // ---------------------------------------------------------------- the page

    public function testThePageStartsInThePlainShellWithBothSteps(): void
    {
        $x = self::assertPage($this->send('GET', '/recover'), 200);
        self::assertSame('Get back into an Admin account - TiffinDB', HtmlAssertions::text($this->bodies[0], '//title'));
        self::assertSame(1, $x->query('//header[@class="lockup-bar"]')->length, 'Plain shell.');
        self::assertSame(1, $x->query('//main[@class="page page-plain"]')->length);
        self::assertSame(['1. Ask for a code', '2. Set a new password'], array_map(static fn ($n): string => trim((string) $n->textContent), iterator_to_array($x->query('//main//h2'))));
        self::assertSame(1, $x->query('//form[@method="post"][@action="/recover/start"]//button[@class="button"][normalize-space(.)="Write a code"]')->length);
        self::assertSame(1, $x->query('//form[@method="post"][@action="/recover"]//button[contains(@class, "button-primary")][normalize-space(.)="Change the password"]')->length);
        self::assertSame(1, $x->query('//form[@action="/recover"]//a[@href="/sign-in"][normalize-space(.)="Back to sign in"]')->length);
        self::assertSame(2, $x->query('//input[@name="_csrf"][@value="' . self::ANON . '"]')->length, 'Both forms carry the guest token.');
        self::assertSame(0, $x->query('//main//*[@role="status"]')->length, 'No started text before starting.');
        $code = $x->query('//input[@id="recover-code"]')->item(0);
        self::assertNotNull($code);
        self::assertSame('off', $code->attributes?->getNamedItem('autocomplete')?->nodeValue);
        self::assertSame(['new-password', 'new-password'], array_map(static fn ($n): string => (string) $n->nodeValue, iterator_to_array($x->query('//input[@type="password"]/@autocomplete'))));
        self::assertFileDoesNotExist($this->codeFile(), 'Showing the page writes nothing.');
    }

    public function testStartAnswersTheSame303WhetherACodeWasWrittenOrNot(): void
    {
        $first = $this->send('POST', '/recover/start');
        self::assertFileExists($this->codeFile());
        $code = (string) file_get_contents($this->codeFile());
        $second = $this->send('POST', '/recover/start');
        self::assertSame($code, (string) file_get_contents($this->codeFile()), 'A waiting code is kept.');
        for ($i = 0; $i < 6; $i++) {
            $throttled = $this->send('POST', '/recover/start');
        }
        foreach ([$first, $second, $throttled] as $response) {
            self::assertSame(303, $response->status);
            self::assertSame('/recover?started=1', $response->header('Location'));
            self::assertSame($first->body, $response->body);
        }

        $x = self::assertPage($this->send('GET', '/recover?started=1'), 200);
        self::assertSame(
            'If recovery is allowed on this server, a code is now in recovery-code.txt in the data folder. If a code was already waiting there, it is still the one to use.',
            trim((string) $x->query('//main//section//div[@role="status"]')->item(0)?->textContent),
        );
        $this->assertCodeNeverShown(trim(explode("\n", $code)[1]));
    }

    public function testTheRightCodeChangesThePasswordAndSendsToSignIn(): void
    {
        $before = $this->passwordHash('admin');
        $session = $this->core->signIn($this->ops, 'admin')->token();
        $code = $this->startAndReadCode();

        $done = $this->recover(self::attempt('admin', strtolower(str_replace('-', ' ', $code))));
        self::assertSame(303, $done->status, $done->body);
        self::assertSame('/sign-in?recovered=1', $done->header('Location'));
        self::assertSame([], array_filter($done->cookies, static fn ($c): bool => $c->name === Context::SESSION_COOKIE && $c->maxAge !== 0), 'Nobody is signed in.');
        self::assertNotSame($before, $this->passwordHash('admin'));
        self::assertFileDoesNotExist($this->codeFile());
        self::assertNull($this->ops->auth()->resume($session), 'Every session of the account ended.');
        self::assertSame(SignInOutcome::Success, $this->ops->auth()->signIn('admin', self::NEW_PASSWORD, '192.0.2.30')->outcome);

        // The code works once.
        $again = $this->recover(self::attempt('admin', $code, 'another long phrase 8'));
        self::assertSame(422, $again->status);
        self::assertSame(SignInOutcome::Success, $this->ops->auth()->signIn('admin', self::NEW_PASSWORD, '192.0.2.30')->outcome);
        $this->assertCodeNeverShown($code);
    }

    public function testEveryFailedCauseGivesTheSameBytes(): void
    {
        $this->core->addPerson('admin2', 'Second Admin', Role::Admin);
        $this->core->setActive('admin2', false);
        $hashes = [$this->passwordHash('admin'), $this->passwordHash('member'), $this->passwordHash('admin2')];
        $code = $this->startAndReadCode();

        $bodies = [];
        foreach (['unknown user' => 'nobody-here', 'Member' => 'member', 'inactive Admin' => 'admin2'] as $cause => $username) {
            $this->clearThrottle();
            $response = $this->recover(self::attempt($username, $code));
            self::assertSame(422, $response->status, $cause);
            $bodies[$cause] = self::withoutToken($response->body);
        }
        $this->clearThrottle();
        $wrong = $this->recover(self::attempt('admin', 'AAAA-BBBB-CCCC-DDDD'));
        $bodies['wrong code'] = self::withoutToken($wrong->body);
        $this->clearThrottle();
        $this->core->clock->advance(1801);
        $bodies['expired code'] = self::withoutToken($this->recover(self::attempt('admin', $code))->body);
        $this->clearThrottle();
        self::assertTrue(unlink($this->codeFile()));
        $bodies['no file'] = self::withoutToken($this->recover(self::attempt('admin', $code))->body);

        foreach ($bodies as $cause => $body) {
            self::assertSame($bodies['unknown user'], $body, "The page for {$cause} is the same.");
        }
        $x = self::assertPage($wrong, 422);
        self::assertSame('Error: Get back into an Admin account - TiffinDB', HtmlAssertions::text($bodies['wrong code'], '//title'));
        self::assertSame(['That did not work. Check the username and the code, or start again for a new code.'], array_map(static fn ($n): string => trim((string) $n->textContent), iterator_to_array($x->query('//div[@class="error-summary"]//a[@href="#recover-code"]'))));
        self::assertSame('true', (string) $x->query('//input[@id="recover-code"]/@aria-invalid')->item(0)?->nodeValue);
        self::assertSame('', (string) $x->query('//input[@id="recover-username"]/@value')->item(0)?->nodeValue, 'The username is not put back.');
        self::assertSame(0, $x->query('//input[@id="recover-code"][@value]')->length, 'The code is not put back.');
        self::assertSame(0, $x->query('//input[@type="password"][@value]')->length);
        self::assertSame($hashes, [$this->passwordHash('admin'), $this->passwordHash('member'), $this->passwordHash('admin2')], 'Nothing changed.');
        $this->assertCodeNeverShown($code);
    }

    public function testPasswordProblemsKeepTheUsernameAndNeverTheCodeOrPasswords(): void
    {
        $code = $this->startAndReadCode();
        $mismatch = $this->recover(self::attempt('admin', $code, self::NEW_PASSWORD, 'plum orbit candle 8'));
        $x = self::assertPage($mismatch, 422);
        self::assertSame(['The two new passwords are different.'], array_map(static fn ($n): string => trim((string) $n->textContent), iterator_to_array($x->query('//div[@class="error-summary"]//a[@href="#recover-again"]'))));
        self::assertSame('admin', (string) $x->query('//input[@id="recover-username"]/@value')->item(0)?->nodeValue);
        self::assertStringNotContainsString(self::NEW_PASSWORD, $mismatch->body);
        self::assertStringNotContainsString('plum orbit candle 8', $mismatch->body);

        $short = $this->recover(self::attempt('admin', $code, 'short'));
        $x = self::assertPage($short, 422);
        self::assertSame(1, $x->query('//div[@class="error-summary"]//a[@href="#recover-password"]')->length);
        self::assertSame('recover-password-help recover-password-error', (string) $x->query('//input[@id="recover-password"]/@aria-describedby')->item(0)?->nodeValue);
        self::assertFileExists($this->codeFile(), 'A password problem costs no attempt and keeps the code.');
        $this->assertCodeNeverShown($code);
    }

    public function testTooManyTriesWaitWith429AndRetryAfter(): void
    {
        $code = $this->startAndReadCode();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->recover(self::attempt('admin', 'AAAA-BBBB-CCCC-DDDD'))->status);
        }
        self::assertFileDoesNotExist($this->codeFile(), 'Five failures remove the code.');
        $wait = $this->recover(self::attempt('admin', $code));
        $x = self::assertPage($wait, 429);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*\z/', (string) $wait->header('Retry-After'));
        self::assertMatchesRegularExpression('/^Too many tries\. Wait [0-9]+ (second|seconds|minute|minutes), then try again\.\z/', trim((string) $x->query('//main//div[@role="alert"][contains(@class, "notice-warn")]')->item(0)?->textContent));
        self::assertSame(0, $x->query('//div[@class="error-summary"]')->length);
        self::assertSame('Error: Get back into an Admin account - TiffinDB', HtmlAssertions::text($wait->body, '//title'));
        $this->assertCodeNeverShown($code);
    }

    // ---------------------------------------------------------------- turned off

    public function testWithRecoveryOffEveryRouteIsNotFoundAndNothingIsWritten(): void
    {
        $off = ['admin_recovery' => false];
        self::assertSame(404, $this->send('GET', '/recover', [], [], $off)->status);
        self::assertSame(404, $this->send('GET', '/recover?started=1', [], [], $off)->status);
        self::assertSame(404, $this->send('POST', '/recover/start', [], [], $off)->status);
        self::assertSame(404, $this->send('POST', '/recover', self::attempt('admin', 'AAAA-BBBB-CCCC-DDDD'), [], $off)->status);
        self::assertFileDoesNotExist($this->codeFile());

        $signIn = $this->send('GET', '/sign-in', [], [], $off);
        self::assertSame(200, $signIn->status);
        self::assertSame(0, self::x($signIn)->query('//a[@href="/recover"]')->length, 'No link when recovery is off.');
        self::assertSame(1, self::x($this->send('GET', '/sign-in'))->query('//a[@href="/recover"]')->length, 'The link by default.');
    }

    public function testASignedInPersonIsSentAwayFromRecovery(): void
    {
        $token = $this->core->signIn($this->ops, 'admin')->token();
        $signedIn = $this->ops->auth()->resume($token);
        self::assertNotNull($signedIn);
        $this->services->withSession($signedIn, $token);
        self::assertSame(303, $this->send('GET', '/recover', [], [], [], $token)->status);
        self::assertSame(303, $this->send('POST', '/recover/start', ['_csrf' => $signedIn->session->csrfToken], [], [], $token)->status);
        self::assertFileDoesNotExist($this->codeFile());
    }

    // ---------------------------------------------------------------- hostile requests

    /** @return iterable<string, array{string, array<string, string>, string|list<string>|null}> */
    public static function refusedPosts(): iterable
    {
        foreach (['start' => '/recover/start', 'recover' => '/recover'] as $name => $route) {
            yield "{$name}: no token" => [$route, [], null];
            yield "{$name}: wrong token" => [$route, [], str_repeat('b', 64)];
            yield "{$name}: empty token" => [$route, [], ''];
            yield "{$name}: token as a list" => [$route, [], [self::ANON]];
            yield "{$name}: foreign Origin" => [$route, ['HTTP_ORIGIN' => 'https://attacker.example'], self::ANON];
            yield "{$name}: null Origin" => [$route, ['HTTP_ORIGIN' => 'null'], self::ANON];
            yield "{$name}: malformed Origin" => [$route, ['HTTP_ORIGIN' => "http://tiffindb.example\t"], self::ANON];
            yield "{$name}: cross-site fetch" => [$route, ['HTTP_SEC_FETCH_SITE' => 'cross-site'], self::ANON];
            yield "{$name}: same-site fetch" => [$route, ['HTTP_SEC_FETCH_SITE' => 'same-site'], self::ANON];
        }
    }

    /**
     * T-WEB-90: each recovery POST refuses a missing or wrong CSRF token, a
     * foreign, `null`, or malformed Origin, and a cross-site fetch, with
     * 403 and nothing changed.
     *
     * @param array<string, string>    $server
     * @param string|list<string>|null $token  the `_csrf` field sent; null sends none
     */
    #[DataProvider('refusedPosts')]
    public function testHostilePostsAreRefusedAndChangeNothing(string $route, array $server, string|array|null $token): void
    {
        $code = $this->startAndReadCode();
        $file = (string) file_get_contents($this->codeFile());
        $hash = $this->passwordHash('admin');
        $post = self::attempt('admin', $code);
        $kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
        if ($token !== null) {
            $post['_csrf'] = $token;
        }
        [$response] = Fixtures::handle($kernel, Fixtures::fullRequest('POST', $route, $post, [Context::CSRF_COOKIE => self::ANON], $server + ['REMOTE_ADDR' => '192.0.2.20', 'HTTP_HOST' => self::HOST]));
        $this->bodies[] = $response->body;

        self::assertSame(403, $response->status);
        self::assertSame($file, (string) file_get_contents($this->codeFile()), 'The code file is unchanged.');
        self::assertSame($hash, $this->passwordHash('admin'), 'The password is unchanged.');
        $this->assertCodeNeverShown($code);
    }

    /** Tampered fields (lists, huge values, missing fields, odd bytes) give a page, never a 500, and change nothing. */
    public function testTamperedFieldsNeverFail(): void
    {
        $code = $this->startAndReadCode();
        $hash = $this->passwordHash('admin');
        $cases = [
            'lists' => ['username' => ['admin'], 'code' => [$code], 'password' => [self::NEW_PASSWORD], 'password_again' => [self::NEW_PASSWORD]],
            'nested' => ['username' => ['a' => ['b' => 'admin']], 'code' => $code, 'password' => self::NEW_PASSWORD, 'password_again' => self::NEW_PASSWORD],
            'nothing' => [],
            'huge' => self::attempt(str_repeat('a', 100000), str_repeat('Z', 100000)),
            'bytes' => self::attempt("admin\0\xFF\u{202E}", "\x00" . $code),
            'markup' => self::attempt('<script>alert(1)</script>', '"><b>x</b>', 'a"><b>pw</b> long enough'),
        ];
        foreach ($cases as $name => $post) {
            $this->clearThrottle();
            $response = $this->recover($post);
            self::assertContains($response->status, [422, 429], $name);
            HtmlAssertions::assertWellFormed($response->body);
            HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
            self::assertStringNotContainsString('<script>alert', $response->body, $name);
            self::assertStringNotContainsString('<b>', $response->body, $name);
        }
        self::assertSame($hash, $this->passwordHash('admin'));
        $this->assertCodeNeverShown($code);
    }

    /**
     * WP2 security review: the client address the controller hands the
     * core is REMOTE_ADDR, never a forwarded header, so a visitor cannot
     * choose the address bucket a failure counts in.
     */
    public function testTheAddressCountedIsRemoteAddrNotAForwardedHeader(): void
    {
        $this->startAndReadCode();
        $forwarded = ['HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_REAL_IP' => '198.51.100.8', 'HTTP_FORWARDED' => 'for=198.51.100.9', 'HTTP_CLIENT_IP' => '198.51.100.10'];
        self::assertSame(422, $this->send('POST', '/recover', self::attempt('admin', 'AAAA-BBBB-CCCC-DDDD'), $forwarded)->status);

        $buckets = [];
        foreach ($this->core->system->db()->all('SELECT bucket FROM login_throttle') as $row) {
            $buckets[] = $row['bucket'];
        }
        self::assertContains(LoginThrottle::addressBucket($this->core->system, '192.0.2.20'), $buckets, 'The failure counts against REMOTE_ADDR.');
        foreach (['198.51.100.7', '198.51.100.8', '198.51.100.9', '198.51.100.10'] as $address) {
            self::assertNotContains(LoginThrottle::addressBucket($this->core->system, $address), $buckets, "No bucket for the forwarded {$address}.");
        }

        // Changing only the forwarded headers does not escape the address limit once it is reached.
        $this->core->system->db()->write(static function ($db): void {
            $db->run("DELETE FROM login_throttle WHERE bucket NOT LIKE 'a:%'");
        });
        for ($i = 0; $i < LoginThrottle::ADDRESS_LIMIT + 1; $i++) {
            $this->core->system->db()->write(static function ($db): void {
                $db->run("DELETE FROM login_throttle WHERE bucket NOT LIKE 'a:%'");
            });
            $last = $this->send('POST', '/recover', self::attempt('admin', 'AAAA-BBBB-CCCC-DDDD'), ['HTTP_X_FORWARDED_FOR' => '198.51.100.' . ($i + 20)]);
        }
        self::assertSame(429, $last->status, 'A new X-Forwarded-For each time still counts against one address.');
    }

    /** The code, in any spelling the person might type, is in no response body. */
    private function assertCodeNeverShown(string $code): void
    {
        $bare = strtoupper(str_replace(['-', ' '], '', $code));
        self::assertSame(16, strlen($bare));
        foreach ($this->bodies as $body) {
            $flat = strtoupper(str_replace(['-', ' '], '', $body));
            self::assertStringNotContainsString($bare, $flat, 'The code never appears in a response.');
        }
    }
}
