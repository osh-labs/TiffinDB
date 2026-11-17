<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Context;
use TiffinDB\Web\Controller\AccountController;
use TiffinDB\Web\Controller\SignInController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * M1-WP10: sign in, sign out, and change your password
 * (docs/design/m1/sign-in-and-projects.md sections 2 and 4) through the
 * kernel with the real templates, strings, and core operations over a
 * CoreFixture. Synthetic data only; no sockets.
 */
#[CoversClass(SignInController::class)]
#[CoversClass(AccountController::class)]
final class SignInScreenTest extends TestCase
{
    private const ANON = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const SOURCE_TEXT = 'Source code';
    private const LICENCE_TEXT = 'TiffinDB, free software under AGPL-3.0-or-later';
    private const NEW_PASSWORD = 'plum orbit candle 7';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;

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
     * @param array<string, mixed>  $post
     * @param array<string, string> $server
     */
    private function send(string $method, string $uri, array $post = [], ?string $token = null, array $server = [], array $config = []): Response
    {
        $cookies = [];
        if ($token !== null) {
            $cookies[Context::SESSION_COOKIE] = $token;
            if ($method === 'POST' && !array_key_exists('_csrf', $post)) {
                $post['_csrf'] = $this->services->sessions[$token]->session->csrfToken;
            }
        } else {
            $cookies[Context::CSRF_COOKIE] = self::ANON;
            if ($method === 'POST' && !array_key_exists('_csrf', $post)) {
                $post['_csrf'] = self::ANON;
            }
        }
        $kernel = new Kernel($config + Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
        [$response] = Fixtures::handle($kernel, Fixtures::fullRequest($method, $uri, $post, $cookies, $server + ['REMOTE_ADDR' => '192.0.2.20']));

        return $response;
    }

    /** Registers a core session with the fake kernel services, as resume() would find it. */
    private function register(string $token): void
    {
        $signedIn = $this->ops->auth()->resume($token);
        self::assertNotNull($signedIn);
        $this->services->withSession($signedIn, $token);
    }

    private function signInAs(string $username, string $password = CoreFixture::PASSWORD): string
    {
        $token = $this->core->signIn($this->ops, $username, $password)->token();
        $this->register($token);

        return $token;
    }

    private static function assertRedirect(Response $response, string $location): void
    {
        self::assertSame(303, $response->status);
        self::assertSame($location, $response->header('Location'));
    }

    private static function assertPage(Response $response, int $status, string $title, string $h1): DOMXPath
    {
        self::assertSame($status, $response->status, $response->body);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, self::LICENCE_TEXT, self::SOURCE_TEXT);
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);
        self::assertSame($title, HtmlAssertions::text($response->body, '//title'));
        self::assertSame($h1, HtmlAssertions::text($response->body, '//h1'));

        return new DOMXPath(HtmlAssertions::assertWellFormed($response->body));
    }

    private function auditCount(string $action): int
    {
        $count = 0;
        foreach ($this->core->systemRows()['audit_log'] as $row) {
            if ($row['action'] === $action) {
                $count++;
            }
        }

        return $count;
    }

    // ---------------------------------------------------------------- sign in

    public function testEmptySignInPage(): void
    {
        $response = $this->send('GET', '/sign-in');
        $x = self::assertPage($response, 200, 'Sign in - TiffinDB', 'Sign in');

        self::assertSame(1, $x->query('//header[@class="lockup-bar"]')->length, 'Plain shell.');
        self::assertSame(1, $x->query('//main[@class="page page-plain"]')->length);
        self::assertSame(0, $x->query('//*[@role="status"] | //*[@role="alert"]')->length, 'No notice.');
        $form = $x->query('//form[@class="form form-card form-narrow"][@method="post"][@action="/sign-in"]');
        self::assertSame(1, $form->length);
        // Element order inside the card.
        $children = [];
        foreach ($x->query('./*', $form->item(0)) as $child) {
            $children[] = $child->nodeName . '.' . $child->getAttribute('class');
        }
        self::assertSame(['input.', 'h1.', 'div.field', 'div.field', 'button.button button-primary button-large button-block', 'p.form-note'], $children);
        $user = $x->query('//input[@id="signin-username"]')->item(0);
        self::assertSame('', $user->getAttribute('value'));
        self::assertSame('username', $user->getAttribute('autocomplete'));
        self::assertSame('none', $user->getAttribute('autocapitalize'));
        self::assertSame('false', $user->getAttribute('spellcheck'));
        self::assertSame('required', $user->getAttribute('required'));
        self::assertFalse($user->hasAttribute('aria-describedby'));
        $pass = $x->query('//input[@id="signin-password"]')->item(0);
        self::assertSame('password', $pass->getAttribute('type'));
        self::assertSame('current-password', $pass->getAttribute('autocomplete'));
        self::assertFalse($pass->hasAttribute('value'));
        self::assertSame('Show password', HtmlAssertions::text($response->body, '//div[contains(@class,"show-password")]'));
        self::assertSame('Username', HtmlAssertions::text($response->body, '//label[@for="signin-username"]'));
        self::assertSame('Password', HtmlAssertions::text($response->body, '//label[@for="signin-password"]'));
        self::assertSame('Forgotten your password? Ask the person who looks after this TiffinDB to reset it.', HtmlAssertions::text($response->body, '//p[@class="form-note"]'));
        self::assertSame(1, $x->query('//script[contains(@src,"js/show-password.js")]')->length, 'The page loads show-password.');
    }

    public function testSignedOutNotice(): void
    {
        $response = $this->send('GET', '/sign-in?signed-out=1');
        $x = self::assertPage($response, 200, 'Sign in - TiffinDB', 'Sign in');
        self::assertSame('You have signed out.', HtmlAssertions::text($response->body, '//main/div[@class="form-narrow"]/div[@class="notice"][@role="status"]'));
        self::assertSame(0, $x->query('//*[@id="signin-error"]')->length);

        $other = $this->send('GET', '/sign-in?signed-out=yes');
        self::assertStringNotContainsString('You have signed out.', $other->body);
    }

    public function testWrongPasswordKeepsUsernameAndNeverPrintsThePassword(): void
    {
        $response = $this->send('POST', '/sign-in', ['username' => 'member', 'password' => 'wrong secret 99']);
        $x = self::assertPage($response, 422, 'Error: Sign in - TiffinDB', 'Sign in');

        self::assertSame(
            'That username and password do not match. Check them and try again.',
            HtmlAssertions::text($response->body, '//div[@id="signin-error"][@class="notice notice-warn"][@role="alert"]'),
        );
        self::assertSame('member', $x->query('//input[@id="signin-username"]')->item(0)->getAttribute('value'));
        foreach (['signin-username', 'signin-password'] as $id) {
            self::assertSame('signin-error', $x->query("//input[@id=\"{$id}\"]")->item(0)->getAttribute('aria-describedby'));
        }
        self::assertStringNotContainsString('wrong secret 99', $response->body);
        self::assertNull($response->cookie(Context::SESSION_COOKIE));
        // The error sits in the card after the h1; no error summary.
        self::assertSame(1, $x->query('//form/h1/following-sibling::*[1][@id="signin-error"]')->length);
        self::assertSame(0, $x->query('//*[@class="error-summary"]')->length);
    }

    public function testFailureIsTheSameForUnknownAndKnownUsernames(): void
    {
        $known = $this->send('POST', '/sign-in', ['username' => 'member', 'password' => 'wrong secret 99']);
        $unknown = $this->send('POST', '/sign-in', ['username' => 'nobody', 'password' => 'wrong secret 99']);
        $inactive = $this->deactivated('member2');
        $stopped = $this->send('POST', '/sign-in', ['username' => $inactive, 'password' => CoreFixture::PASSWORD]);

        self::assertSame(422, $unknown->status);
        self::assertSame(422, $stopped->status);
        $normal = str_replace('value="member"', 'value="X"', $known->body);
        self::assertSame($normal, str_replace('value="nobody"', 'value="X"', $unknown->body));
        self::assertSame($normal, str_replace('value="member2"', 'value="X"', $stopped->body));
    }

    private function deactivated(string $username): string
    {
        $this->ops->people()->deactivate($this->core->actor('admin'), $this->core->person($username)->key);

        return $username;
    }

    public function testThrottlingIsTheSameForUnknownAndKnownUsernames(): void
    {
        $responses = [];
        foreach (['member' => '192.0.2.31', 'nobody' => '192.0.2.32'] as $username => $address) {
            for ($i = 0; $i < 5; $i++) {
                $this->send('POST', '/sign-in', ['username' => $username, 'password' => 'wrong secret 99'], null, ['REMOTE_ADDR' => $address]);
            }
            $responses[$username] = $this->send('POST', '/sign-in', ['username' => $username, 'password' => 'wrong secret 99'], null, ['REMOTE_ADDR' => $address]);
        }

        $known = $responses['member'];
        self::assertSame(429, $known->status);
        self::assertNotNull($known->header('Retry-After'));
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', (string) $known->header('Retry-After'));
        $x = self::assertPage($known, 429, 'Error: Sign in - TiffinDB', 'Sign in');
        self::assertMatchesRegularExpression(
            '/^Too many tries\. Wait [0-9]+ (second|seconds|minute|minutes), then try again\.$/',
            HtmlAssertions::text($known->body, '//div[@id="signin-error"]'),
        );
        self::assertSame('member', $x->query('//input[@id="signin-username"]')->item(0)->getAttribute('value'));

        $unknown = $responses['nobody'];
        self::assertSame(429, $unknown->status);
        self::assertSame($known->header('Retry-After'), $unknown->header('Retry-After'));
        self::assertSame(
            str_replace('value="member"', 'value="X"', $known->body),
            str_replace('value="nobody"', 'value="X"', $unknown->body),
        );

        // The right password does not get through while throttled.
        $right = $this->send('POST', '/sign-in', ['username' => 'member', 'password' => CoreFixture::PASSWORD], null, ['REMOTE_ADDR' => '192.0.2.31']);
        self::assertSame(429, $right->status);
        self::assertNull($right->cookie(Context::SESSION_COOKIE));
    }

    public function testSuccessSetsTheSessionCookieAndRedirects(): void
    {
        $response = $this->send('POST', '/sign-in', ['username' => 'member', 'password' => CoreFixture::PASSWORD]);

        self::assertRedirect($response, '/projects');
        $cookie = $response->cookie(Context::SESSION_COOKIE);
        self::assertNotNull($cookie);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $cookie->value);
        self::assertSame('/', $cookie->path);
        self::assertSame('Lax', $cookie->sameSite);
        self::assertSame(2592000, $cookie->maxAge);
        self::assertFalse($cookie->secure);
        self::assertSame("tdb_session={$cookie->value}; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax", $cookie->header());
        $csrf = $response->cookie(Context::CSRF_COOKIE);
        self::assertNotNull($csrf);
        self::assertSame(0, $csrf->maxAge, 'The anonymous CSRF cookie is deleted.');
        self::assertNotNull($this->ops->auth()->resume($cookie->value), 'The cookie names a live session.');
        self::assertStringNotContainsString(CoreFixture::PASSWORD, $response->body);
    }

    public function testSuccessOverHttpsSetsASecureCookie(): void
    {
        $response = $this->send('POST', '/sign-in', ['username' => 'member', 'password' => CoreFixture::PASSWORD], null, ['HTTPS' => 'on']);
        self::assertRedirect($response, '/projects');
        self::assertTrue($response->cookie(Context::SESSION_COOKIE)?->secure);
        self::assertStringContainsString('; Secure; HttpOnly; SameSite=Lax', (string) $response->cookie(Context::SESSION_COOKIE)?->header());

        $configured = $this->send('POST', '/sign-in', ['username' => 'member', 'password' => CoreFixture::PASSWORD], null, [], ['secure_cookies' => true]);
        self::assertTrue($configured->cookie(Context::SESSION_COOKIE)?->secure);
    }

    public function testSignInEndsTheSessionNamedByAStaleCookie(): void
    {
        $old = $this->core->signIn($this->ops, 'member2')->token();
        $kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
        [$response] = Fixtures::handle($kernel, Fixtures::fullRequest(
            'POST',
            '/sign-in',
            ['username' => 'member', 'password' => CoreFixture::PASSWORD, '_csrf' => self::ANON],
            [Context::SESSION_COOKIE => $old, Context::CSRF_COOKIE => self::ANON],
        ));
        self::assertRedirect($response, '/projects');
        self::assertContains('endSession:' . $old, $this->services->calls);
    }

    public function testFormExpiredIsRefused(): void
    {
        $response = $this->send('POST', '/sign-in', ['username' => 'member', 'password' => CoreFixture::PASSWORD, '_csrf' => str_repeat('f', 64)]);
        self::assertSame(403, $response->status);
        self::assertStringContainsString('This form has expired', $response->body);
        self::assertNull($response->cookie(Context::SESSION_COOKIE));
    }

    public function testSignedInPersonIsSentToTheProjectList(): void
    {
        $token = $this->signInAs('member');
        self::assertRedirect($this->send('GET', '/sign-in', [], $token), '/projects');
    }

    // ---------------------------------------------------------------- sign out

    public function testSignOutEndsTheSessionOnceAndDeletesTheCookie(): void
    {
        $token = $this->signInAs('member');
        $before = $this->auditCount('auth.sign_out');
        $response = $this->send('POST', '/sign-out', [], $token);

        self::assertRedirect($response, '/sign-in?signed-out=1');
        self::assertSame(0, $response->cookie(Context::SESSION_COOKIE)?->maxAge);
        self::assertNull($this->ops->auth()->resume($token));
        self::assertSame($before + 1, $this->auditCount('auth.sign_out'), 'auth.sign_out recorded once, by the core.');
    }

    public function testSignOutNeedsTheSessionCsrfToken(): void
    {
        $token = $this->signInAs('member');
        $response = $this->send('POST', '/sign-out', ['_csrf' => self::ANON], $token);
        self::assertSame(403, $response->status);
        self::assertNotNull($this->ops->auth()->resume($token));
    }

    // ---------------------------------------------------------------- own password

    public function testNormalPasswordPage(): void
    {
        $token = $this->signInAs('member');
        $response = $this->send('GET', '/account/password', [], $token);
        $x = self::assertPage($response, 200, 'Change your password - TiffinDB', 'Change your password');

        self::assertSame(1, $x->query('//a[@class="user-chip"][@aria-current="page"]')->length, 'nav = account.');
        self::assertSame(0, $x->query('//main//*[@role="status"]')->length, 'No forced notice.');
        self::assertSame('Choose a password that only you know.', HtmlAssertions::text($response->body, '//main/div/p[@class="lead"]'));
        $form = '//form[@class="form form-card form-narrow"][@action="/account/password"]';
        self::assertSame(1, $x->query($form)->length);
        $fields = [
            ['account-current', 'current', 'current-password', 'Current password'],
            ['account-new', 'password', 'new-password', 'New password'],
            ['account-repeat', 'repeat', 'new-password', 'Type the new password again'],
        ];
        foreach ($fields as [$id, $name, $autocomplete, $label]) {
            $input = $x->query("//input[@id=\"{$id}\"]")->item(0);
            self::assertSame('password', $input->getAttribute('type'));
            self::assertSame($name, $input->getAttribute('name'));
            self::assertSame($autocomplete, $input->getAttribute('autocomplete'));
            self::assertSame($label, HtmlAssertions::text($response->body, "//label[@for=\"{$id}\"]"));
        }
        self::assertSame('account-new-help', $x->query('//input[@id="account-new"]')->item(0)->getAttribute('aria-describedby'));
        self::assertSame('At least 10 characters. A few unrelated words make a good password.', HtmlAssertions::text($response->body, '//p[@id="account-new-help"]'));
        self::assertSame('Show passwords', HtmlAssertions::text($response->body, '//div[contains(@class,"show-password")]'));
        self::assertSame('Change password', HtmlAssertions::text($response->body, $form . '//button'));
        self::assertSame('/projects', $x->query($form . '//div[@class="form-actions"]/a')->item(0)?->getAttribute('href'));
        self::assertSame('Cancel', HtmlAssertions::text($response->body, $form . '//div[@class="form-actions"]/a'));
    }

    public function testMismatchIsCheckedBeforeTheCore(): void
    {
        $token = $this->signInAs('member');
        $before = $this->core->systemRows()['audit_log'];
        $response = $this->send('POST', '/account/password', ['current' => 'anything at all', 'password' => self::NEW_PASSWORD, 'repeat' => 'something else 1'], $token);
        $x = self::assertPage($response, 422, 'Error: Change your password - TiffinDB', 'Change your password');

        self::assertSame('There is a problem', HtmlAssertions::text($response->body, '//div[@class="error-summary"]/h2'));
        self::assertSame('#account-repeat', $x->query('//div[@class="error-summary"]//a')->item(0)?->getAttribute('href'));
        self::assertSame('The two new passwords are different.', HtmlAssertions::text($response->body, '//p[@id="account-repeat-error"]'));
        self::assertSame('true', $x->query('//input[@id="account-repeat"]')->item(0)->getAttribute('aria-invalid'));
        self::assertSame(1, $x->query('//main/div[@class="error-summary"]/following-sibling::div/h1')->length, 'Summary before the h1.');
        foreach (['anything at all', self::NEW_PASSWORD, 'something else 1'] as $secret) {
            self::assertStringNotContainsString($secret, $response->body);
        }
        self::assertSame($before, $this->core->systemRows()['audit_log'], 'The core was not called.');
    }

    public function testCoreProblemsMapToTheirFields(): void
    {
        $token = $this->signInAs('member');
        $response = $this->send('POST', '/account/password', ['current' => 'wrong secret 99', 'password' => 'short', 'repeat' => 'short'], $token);
        $x = self::assertPage($response, 422, 'Error: Change your password - TiffinDB', 'Change your password');

        self::assertSame('That is not your current password.', HtmlAssertions::text($response->body, '//p[@id="account-current-error"]'));
        self::assertSame('Use at least 10 characters.', HtmlAssertions::text($response->body, '//p[@id="account-new-error"]'));
        self::assertSame('account-new-help account-new-error', $x->query('//input[@id="account-new"]')->item(0)->getAttribute('aria-describedby'));
        $links = [];
        foreach ($x->query('//div[@class="error-summary"]//a') as $a) {
            $links[] = $a->getAttribute('href');
        }
        self::assertSame(['#account-current', '#account-new'], $links);
        self::assertSame(0, $x->query('//p[@id="account-repeat-error"]')->length);
        self::assertStringNotContainsString('wrong secret 99', $response->body);
    }

    public function testTooManyWrongCurrentPasswords(): void
    {
        $token = $this->signInAs('member');
        for ($i = 0; $i < 5; $i++) {
            $this->send('POST', '/account/password', ['current' => 'wrong secret 99', 'password' => self::NEW_PASSWORD, 'repeat' => self::NEW_PASSWORD], $token);
        }
        $response = $this->send('POST', '/account/password', ['current' => CoreFixture::PASSWORD, 'password' => self::NEW_PASSWORD, 'repeat' => self::NEW_PASSWORD], $token);
        self::assertPage($response, 422, 'Error: Change your password - TiffinDB', 'Change your password');
        self::assertMatchesRegularExpression('/^Too many tries\. Wait .+, then try again\.$/', HtmlAssertions::text($response->body, '//p[@id="account-current-error"]'));
    }

    public function testMustChangeFlow(): void
    {
        $admin = $this->core->actor('admin');
        $this->ops->people()->create($admin, 'newcomer', 'Example Newcomer', Role::Member, 'temporary words 42', []);

        // Sign-in sends the person to the password page.
        $signIn = $this->send('POST', '/sign-in', ['username' => 'newcomer', 'password' => 'temporary words 42']);
        self::assertRedirect($signIn, '/account/password');
        $token = (string) $signIn->cookie(Context::SESSION_COOKIE)?->value;
        $this->register($token);

        // Every other page sends them back.
        self::assertRedirect($this->send('GET', '/projects', [], $token), '/account/password');
        self::assertRedirect($this->send('GET', '/admin/people', [], $token), '/account/password');

        // Forced page: notice, no Cancel.
        $page = $this->send('GET', '/account/password', [], $token);
        $x = self::assertPage($page, 200, 'Change your password - TiffinDB', 'Change your password');
        self::assertSame(
            'Before you go on, choose your own password. The one you were given was only for signing in the first time.',
            HtmlAssertions::text($page->body, '//main/div[@class="notice"][@role="status"]'),
        );
        self::assertSame(0, $x->query('//div[@class="form-actions"]/a')->length, 'No Cancel.');
        self::assertSame('Change password', HtmlAssertions::text($page->body, '//div[@class="form-actions"]/button[@class="button button-large button-primary"]'));

        // A failure keeps the forced variant.
        $failed = $this->send('POST', '/account/password', ['current' => 'temporary words 42', 'password' => 'newcomer', 'repeat' => 'newcomer'], $token);
        $fx = self::assertPage($failed, 422, 'Error: Change your password - TiffinDB', 'Change your password');
        self::assertSame(0, $fx->query('//div[@class="form-actions"]/a')->length);

        // Success: new session cookie, old session ended, flash on the new session.
        $done = $this->send('POST', '/account/password', ['current' => 'temporary words 42', 'password' => self::NEW_PASSWORD, 'repeat' => self::NEW_PASSWORD], $token);
        self::assertRedirect($done, '/projects');
        $new = $done->cookie(Context::SESSION_COOKIE);
        self::assertNotNull($new);
        self::assertNotSame($token, $new->value);
        self::assertSame(2592000, $new->maxAge);
        self::assertNull($this->ops->auth()->resume($token));
        $resumed = $this->ops->auth()->resume($new->value);
        self::assertNotNull($resumed);
        self::assertFalse($resumed->mustChangePassword);
        self::assertSame(['key' => 'notice.password_changed', 'params' => []], $this->services->flash[$new->value] ?? null);
        self::assertStringNotContainsString(self::NEW_PASSWORD, $done->body);
    }
}
