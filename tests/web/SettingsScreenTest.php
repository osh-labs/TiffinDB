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
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\SettingsController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-31 (M2-WP8): reading and display settings
 * (docs/design/m2/settings.md), through the kernel with the real
 * templates and strings and real core operations over a CoreFixture
 * instance. The scripted services read each person's appearance from the
 * same core the operations write, as CoreServices does, so a saved
 * setting reaches the next page's root classes. Synthetic data only.
 */
#[CoversClass(SettingsController::class)]
final class SettingsScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    /** Group => [value, data-preview] in page order. */
    private const RADIOS = [
        'theme' => [['light', ''], ['dark', 't-dark'], ['hc-light', 't-hcl'], ['hc-dark', 't-hcd']],
        'accent' => [['teal', ''], ['indigo', 'a-indigo'], ['plum', 'a-plum'], ['graphite', 'a-graphite']],
        'text_size' => [['small', 'pv-s-small'], ['standard', 'pv-s-standard'], ['large', 'pv-s-large'], ['larger', 'pv-s-larger'], ['largest', 'pv-s-largest']],
        'line_spacing' => [['standard', 'pv-l-standard'], ['roomy', 'pv-l-roomy'], ['very-roomy', 'pv-l-very-roomy']],
        'typeface' => [['atkinson', 'f-atk'], ['lexend', 'f-lexend'], ['inter', ''], ['source-serif', 'f-serif4'], ['device-sans', 'f-sans'], ['device-serif', 'f-serif']],
    ];

    private const DEFAULTS = ['theme' => 'light', 'accent' => 'teal', 'typeface' => 'inter', 'text_size' => 'standard', 'line_spacing' => 'standard', 'reduce_motion' => ''];

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    public function testTheFormWithTheStandardSettings(): void
    {
        $html = $this->page(self::MEMBER, '/settings');
        $x = $this->xpath($html);

        self::assertSame('Reading and display - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertNull(HtmlAssertions::rootClass($html));
        self::assertSame(1, $x->query('//main[@id="main"][@class="page page-settings"]')->length);
        self::assertSame('page', $x->query('//nav[@class="account-nav"]/a[@href="/settings"]/@aria-current')->item(0)?->nodeValue);
        self::assertSame('Reading and display', HtmlAssertions::text($html, '//main/div/h1'));
        self::assertSame(
            'These settings are yours. They follow you to any device you sign in on, and they apply to every project and form.',
            HtmlAssertions::text($html, '//main/div/p[@class="lead"]'),
        );

        $form = '//main/div[@class="settings-layout"]/form[@id="settings-form"][@class="form settings-form"][@method="post"][@action="/settings"]';
        self::assertSame(1, $x->query($form)->length);
        self::assertSame(
            ['settings-theme', 'settings-accent', 'settings-size', 'settings-spacing', 'settings-lettering'],
            $this->texts($x, $form . '/fieldset/@id'),
        );
        self::assertSame(['Colours', 'Accent colour', 'Text size', 'Space between lines', 'Lettering and movement'], $this->texts($x, $form . '/fieldset/legend'));
        self::assertSame(
            [
                'fieldset-card settings-section',
                'fieldset-card settings-section',
                'fieldset-card settings-section settings-section-tight',
                'fieldset-card settings-section settings-section-tight',
                'fieldset-card settings-section settings-section-tight',
            ],
            $this->texts($x, $form . '/fieldset/@class'),
        );
        foreach (self::RADIOS as $name => $radios) {
            $inputs = $x->query('//input[@type="radio"][@name="' . $name . '"]');
            $seen = [];
            foreach ($inputs as $input) {
                $seen[] = [$input->getAttribute('value'), $input->getAttribute('data-preview')];
            }
            self::assertSame($radios, $seen, $name);
        }
        self::assertSame(['Light', 'Dark', 'Strong contrast, light', 'Strong contrast, dark'], $this->texts($x, '//fieldset[@id="settings-theme"]//label/span[not(@aria-hidden)]'));
        self::assertSame(['swatch', 'swatch t-dark', 'swatch t-hcl', 'swatch t-hcd'], $this->texts($x, '//fieldset[@id="settings-theme"]//span[@aria-hidden="true"]/@class'));
        self::assertSame(['Aa', 'Aa', 'Aa', 'Aa'], $this->texts($x, '//fieldset[@id="settings-theme"]//span[@aria-hidden="true"]'));
        self::assertSame('radio-grid radio-grid-210', $x->query('//fieldset[@id="settings-theme"]/div/@class')->item(0)?->nodeValue);
        self::assertSame(['Teal', 'Indigo', 'Plum', 'Graphite'], $this->texts($x, '//fieldset[@id="settings-accent"]//label/span[not(@aria-hidden)]'));
        self::assertSame(['swatch-dot', 'swatch-dot a-indigo', 'swatch-dot a-plum', 'swatch-dot a-graphite'], $this->texts($x, '//fieldset[@id="settings-accent"]//span[@aria-hidden="true"]/@class'));
        self::assertSame(
            'Used for buttons, links, and the sidebar. The strong contrast colours do not use an accent colour.',
            HtmlAssertions::text($html, '//fieldset[@id="settings-accent"]/p[@class="help"]'),
        );
        self::assertSame(['Small', 'Standard', 'Large', 'Larger', 'Largest'], $this->texts($x, '//fieldset[@id="settings-size"]//label'));
        self::assertSame(
            ['check size-option size-small', 'check size-option size-standard', 'check size-option size-large', 'check size-option size-larger', 'check size-option size-largest'],
            $this->texts($x, '//fieldset[@id="settings-size"]//label/@class'),
        );
        self::assertSame(['Standard', 'Roomy', 'Very roomy'], $this->texts($x, '//fieldset[@id="settings-spacing"]//label'));
        self::assertSame(
            ['Atkinson Hyperlegible', 'Lexend', 'Inter', 'Source Serif', 'This device, sans-serif', 'This device, serif'],
            $this->texts($x, '//fieldset[@id="settings-lettering"]//span[@class="face-name"]'),
        );
        self::assertSame(
            ['Made for easy reading', 'Wide and open letters', 'Standard. Plain and compact', 'Book-style letters with serifs', 'The plain lettering your device uses', 'The book-style lettering your device uses'],
            $this->texts($x, '//fieldset[@id="settings-lettering"]//span[@class="face-hint"]'),
        );
        self::assertSame(
            ['radio-card face-option f-atk', 'radio-card face-option f-lexend', 'radio-card face-option', 'radio-card face-option f-serif4', 'radio-card face-option f-sans', 'radio-card face-option f-serif'],
            $this->texts($x, '//fieldset[@id="settings-lettering"]//label[contains(@class, "face-option")]/@class'),
        );
        self::assertSame('Reduce movement and animation', HtmlAssertions::text($html, '//label[@class="check settings-divider"]'));
        self::assertSame('1', $x->query('//input[@type="checkbox"][@name="reduce_motion"]/@value')->item(0)?->nodeValue);

        self::assertSame($this->checked(self::DEFAULTS), $this->checkedIn($x));

        self::assertSame(['Save settings', 'Back to standard'], $this->texts($x, $form . '/div[@class="form-actions"]/button[@type="submit"]'));
        self::assertSame(['button button-large button-primary', 'button button-large'], $this->texts($x, $form . '/div[@class="form-actions"]/button/@class'));
        self::assertSame(['/settings/reset'], $this->texts($x, '//button/@formaction'));

        $preview = '//main/div[@class="settings-layout"]/section[@class="settings-preview"][@aria-labelledby="preview-title"]';
        self::assertSame('Preview: how a record will look', HtmlAssertions::text($html, $preview . '/h2[@id="preview-title"][@class="preview-heading"]'));
        self::assertSame('preview-body pv-s-standard pv-l-standard', $this->previewClass($x));
        self::assertSame('Stand mixer', HtmlAssertions::text($html, '//div[@id="settings-preview"]/div[@class="preview-title"]'));
        self::assertSame(['Room', 'Purchased', 'Notes'], $this->texts($x, '//div[@id="settings-preview"]//div[@class="preview-label"]'));
        self::assertSame(
            ['Kitchen', '14 March 2025', 'Comes with dough hook, whisk, and flat beater. Spare bowl is on the top shelf.'],
            $this->texts($x, '//div[@id="settings-preview"]/div/div[@class="preview-label"]/following-sibling::div'),
        );
        self::assertSame('Save', HtmlAssertions::text($html, '//div[@id="settings-preview"]/div[@class="preview-button"]'));
        self::assertSame(0, $x->query('//section[@class="settings-preview"]//*[self::a or self::button or self::input or @tabindex]')->length, 'The preview has nothing focusable.');

        self::assertSame(['/assets/js/settings-preview.js?v=' . Fixtures::config(false)['version']], $this->texts($x, '/html/head/script/@src'));
    }

    /** @return iterable<string, array{array<string, string|int>, string, ?string}> */
    public static function savedSettings(): iterable
    {
        yield 'dark, plum, Lexend, large, very roomy, reduced motion' => [
            ['theme' => 'dark', 'accent' => 'plum', 'typeface' => 'lexend', 'text_size' => 'large', 'line_spacing' => 'very-roomy', 'reduce_motion' => 1],
            'preview-body t-dark a-plum f-lexend pv-s-large pv-l-very-roomy',
            't-dark a-plum f-lexend s-large l-very-roomy m-reduce',
        ];
        yield 'strong contrast drops the accent' => [
            ['theme' => 'hc-dark', 'accent' => 'indigo', 'typeface' => 'source-serif', 'text_size' => 'small', 'line_spacing' => 'roomy', 'reduce_motion' => 0],
            'preview-body t-hcd f-serif4 pv-s-small pv-l-roomy',
            't-hcd f-serif4 s-small l-roomy',
        ];
        yield 'strong contrast light' => [
            ['theme' => 'hc-light', 'accent' => 'graphite', 'typeface' => 'device-sans', 'text_size' => 'largest', 'line_spacing' => 'standard', 'reduce_motion' => 0],
            'preview-body t-hcl f-sans pv-s-largest pv-l-standard',
            't-hcl f-sans s-largest',
        ];
        yield 'light with an accent' => [
            ['theme' => 'light', 'accent' => 'graphite', 'typeface' => 'device-serif', 'text_size' => 'larger', 'line_spacing' => 'standard', 'reduce_motion' => 0],
            'preview-body a-graphite f-serif pv-s-larger pv-l-standard',
            'a-graphite f-serif s-larger',
        ];
        yield 'Atkinson and indigo' => [
            ['theme' => 'dark', 'accent' => 'indigo', 'typeface' => 'atkinson', 'text_size' => 'standard', 'line_spacing' => 'roomy', 'reduce_motion' => 0],
            'preview-body t-dark a-indigo f-atk pv-s-standard pv-l-roomy',
            't-dark a-indigo f-atk l-roomy',
        ];
    }

    /** @param array<string, string|int> $settings */
    #[DataProvider('savedSettings')]
    public function testSavedSettingsAreCheckedAndPreviewed(array $settings, string $preview, ?string $root): void
    {
        $this->ops->settings()->save($this->core->actor('member'), array_map(static fn (string|int $v): string => $v === 1 ? '1' : ($v === 0 ? '' : (string) $v), $settings));
        $html = $this->page(self::MEMBER, '/settings');
        $x = $this->xpath($html);

        $expected = $settings;
        $expected['reduce_motion'] = $settings['reduce_motion'] === 1 ? '1' : '';
        self::assertSame($this->checked($expected), $this->checkedIn($x));
        self::assertSame($preview, $this->previewClass($x));
        self::assertSame($root, HtmlAssertions::rootClass($html));
    }

    public function testSavingRedirectsWithANoticeAndTheNextPageChanges(): void
    {
        $post = ['theme' => 'dark', 'accent' => 'indigo', 'typeface' => 'atkinson', 'text_size' => 'larger', 'line_spacing' => 'roomy', 'reduce_motion' => '1'];
        $response = $this->request(self::MEMBER, 'POST', '/settings', $post);

        self::assertSame(303, $response->status);
        self::assertSame('/settings', $response->header('Location'));
        self::assertSame(['key' => 'notice.settings_saved', 'params' => []], $this->services->flash[self::MEMBER]);
        self::assertSame(
            ['theme' => 'dark', 'accent' => 'indigo', 'typeface' => 'atkinson', 'text_size' => 'larger', 'line_spacing' => 'roomy', 'reduce_motion' => 1],
            $this->ops->settings()->get($this->core->actor('member')),
        );
        self::assertSame(self::DEFAULTS, $this->stored('admin'), 'Only the person\'s own settings change.');

        $html = $this->page(self::MEMBER, '/settings');
        self::assertSame('Your display settings have been saved.', HtmlAssertions::text($html, '//main/div[@class="notice"][@role="status"]/p'));
        self::assertSame('t-dark a-indigo f-atk s-larger l-roomy m-reduce', HtmlAssertions::rootClass($html));
        self::assertSame('t-dark a-indigo f-atk s-larger l-roomy m-reduce', HtmlAssertions::rootClass($this->page(self::MEMBER, '/projects')), 'Every page.');
        self::assertNull(HtmlAssertions::rootClass($this->page(self::ADMIN, '/projects')));
    }

    public function testAnUntickedCheckboxTurnsReducedMovementOff(): void
    {
        $this->ops->settings()->save($this->core->actor('member'), ['reduce_motion' => '1'] + self::DEFAULTS);
        $post = self::DEFAULTS;
        unset($post['reduce_motion']);

        self::assertSame(303, $this->request(self::MEMBER, 'POST', '/settings', $post)->status);
        self::assertSame(self::DEFAULTS, $this->stored('member'));
    }

    public function testBackToStandardResetsAndSaves(): void
    {
        $this->ops->settings()->save($this->core->actor('member'), ['theme' => 'hc-light', 'accent' => 'plum', 'typeface' => 'lexend', 'text_size' => 'small', 'line_spacing' => 'very-roomy', 'reduce_motion' => '1']);
        self::assertNotNull(HtmlAssertions::rootClass($this->page(self::MEMBER, '/settings')));

        // The button posts the whole form to its formaction; the posted values are ignored.
        $response = $this->request(self::MEMBER, 'POST', '/settings/reset', ['theme' => 'dark', 'accent' => 'x"y']);

        self::assertSame(303, $response->status);
        self::assertSame('/settings', $response->header('Location'));
        self::assertSame(['key' => 'notice.settings_reset', 'params' => []], $this->services->flash[self::MEMBER]);
        self::assertSame(self::DEFAULTS, $this->stored('member'));
        $html = $this->page(self::MEMBER, '/settings');
        self::assertSame('Your display settings are back to standard.', HtmlAssertions::text($html, '//main/div[@class="notice"]/p'));
        self::assertNull(HtmlAssertions::rootClass($html));
        self::assertSame($this->checked(self::DEFAULTS), $this->checkedIn($this->xpath($html)));
    }

    public function testATamperedValueGives422WithTheProblemAtItsFieldset(): void
    {
        $this->ops->settings()->save($this->core->actor('member'), ['theme' => 'dark'] + self::DEFAULTS);
        $post = ['theme' => '"><script>purple</script>', 'accent' => 'plum', 'typeface' => 'lexend', 'text_size' => 'large', 'line_spacing' => 'roomy', 'reduce_motion' => '1'];

        $response = $this->request(self::MEMBER, 'POST', '/settings', $post);

        self::assertSame(422, $response->status);
        $html = $response->body;
        $this->assertScreen($html);
        $x = $this->xpath($html);
        self::assertSame('Error: Reading and display - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['#settings-theme'], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
        self::assertSame(['Choose one of the options shown.'], $this->texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame('settings-theme-error', $x->query('//fieldset[@id="settings-theme"]/@aria-describedby')->item(0)?->nodeValue);
        self::assertSame('Choose one of the options shown.', HtmlAssertions::text($html, '//fieldset[@id="settings-theme"]/p[@class="error-text"][@id="settings-theme-error"]'));
        self::assertSame(0, $x->query('//fieldset[@id="settings-accent"]/@aria-describedby')->length);
        self::assertSame(1, $x->query('//p[@class="error-text"]')->length);
        self::assertStringNotContainsString('purple', $html, 'A posted value is never printed.');
        // The saved theme stays checked; the other, valid, choices are kept.
        self::assertSame($this->checked(['theme' => 'dark'] + $post), $this->checkedIn($x));
        self::assertSame('preview-body t-dark a-plum f-lexend pv-s-large pv-l-roomy', $this->previewClass($x));
        self::assertSame('t-dark', HtmlAssertions::rootClass($html), 'The page keeps the saved appearance.');
        self::assertSame(['theme' => 'dark'] + self::DEFAULTS, $this->stored('member'), 'Nothing is saved.');
    }

    public function testEveryTamperedValueIsReportedInPageOrder(): void
    {
        $post = ['theme' => 'Dark', 'accent' => 'a-indigo', 'typeface' => 'f-atk', 'text_size' => 'huge', 'line_spacing' => 'very_roomy', 'reduce_motion' => 'on'];

        $response = $this->request(self::MEMBER, 'POST', '/settings', $post);

        self::assertSame(422, $response->status);
        $x = $this->xpath($response->body);
        self::assertSame(
            ['#settings-theme', '#settings-accent', '#settings-size', '#settings-spacing', '#settings-lettering', '#settings-lettering'],
            $this->texts($x, '//div[@class="error-summary"]//a/@href'),
        );
        self::assertSame('There are 6 problems', HtmlAssertions::text($response->body, '//div[@class="error-summary"]/h2'));
        self::assertSame(5, $x->query('//fieldset/p[@class="error-text"]')->length);
        self::assertSame($this->checked(self::DEFAULTS), $this->checkedIn($x), 'The saved settings are checked again.');
        self::assertSame(self::DEFAULTS, $this->stored('member'));
    }

    public function testListValuesAreReadAsEmpty(): void
    {
        $response = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', '/settings', [
            '_csrf' => FakeServices::SESSION_CSRF,
            'theme' => ['dark'],
            'accent' => 'teal',
            'typeface' => 'inter',
            'text_size' => 'standard',
            'line_spacing' => 'standard',
            'reduce_motion' => ['1'],
        ], ['tdb_session' => self::MEMBER]))[0];

        self::assertSame(422, $response->status);
        // A list is not text: the theme is refused, the checkbox reads as unticked.
        self::assertSame(['#settings-theme'], $this->texts($this->xpath($response->body), '//div[@class="error-summary"]//a/@href'));
        self::assertSame(self::DEFAULTS, $this->stored('member'));
    }

    public function testAPostWithoutTheCsrfTokenIsRefused(): void
    {
        $response = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', '/settings', ['theme' => 'dark'] + self::DEFAULTS, ['tdb_session' => self::MEMBER]))[0];
        self::assertSame(403, $response->status);
        $response = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', '/settings/reset', [], ['tdb_session' => self::MEMBER]))[0];
        self::assertSame(403, $response->status);
        self::assertSame(self::DEFAULTS, $this->stored('member'));
    }

    public function testSignedOutPeopleAreSentToSignIn(): void
    {
        $response = Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', '/settings'))[0];
        self::assertSame(303, $response->status);
        self::assertStringStartsWith('/sign-in', (string) $response->header('Location'));
    }

    public function testThePreviewScriptIsLoadedOnlyHere(): void
    {
        self::assertSame(1, $this->xpath($this->page(self::MEMBER, '/settings'))->query('//script')->length);
        foreach (['/projects', '/account/password'] as $path) {
            self::assertStringNotContainsString('settings-preview', $this->page(self::MEMBER, $path), $path);
        }
    }

    public function testTheAllowedValuesMatchTheCore(): void
    {
        $allowed = (new \ReflectionClassConstant(SettingsController::class, 'ALLOWED'))->getValue();
        $core = Users::SETTINGS;
        unset($core['reduce_motion']);
        self::assertSame($core, $allowed);
        foreach (self::RADIOS as $name => $radios) {
            self::assertEqualsCanonicalizing($core[$name], array_column($radios, 0), $name);
        }
    }

    /**
     * Every group's checked value as the page should show it.
     *
     * @param array<string, string|int> $values
     * @return array<string, string>
     */
    private function checked(array $values): array
    {
        $out = [];
        foreach (array_keys(self::RADIOS) as $name) {
            $out[$name] = (string) $values[$name];
        }
        $out['reduce_motion'] = $values['reduce_motion'] === '1' ? '1' : '';

        return $out;
    }

    /** @return array<string, string> group => checked value ('' for an unticked checkbox) */
    private function checkedIn(DOMXPath $x): array
    {
        $out = [];
        foreach (array_keys(self::RADIOS) as $name) {
            $values = $this->texts($x, '//input[@type="radio"][@name="' . $name . '"][@checked="checked"]/@value');
            self::assertCount(1, $values, $name);
            $out[$name] = $values[0];
        }
        $out['reduce_motion'] = $x->query('//input[@name="reduce_motion"][@checked="checked"]')->length === 1 ? '1' : '';

        return $out;
    }

    private function previewClass(DOMXPath $x): string
    {
        return (string) $x->query('//div[@id="settings-preview"]/@class')->item(0)?->nodeValue;
    }

    /** @return array<string, string> the stored settings in the form the page posts */
    private function stored(string $username): array
    {
        $stored = $this->ops->settings()->get($this->core->actor($username));
        $stored['reduce_motion'] = $stored['reduce_motion'] === 1 ? '1' : '';

        return array_map('strval', $stored);
    }

    private function signedIn(string $username): SignedIn
    {
        $actor = $this->core->actor($username);

        return new SignedIn(
            $actor,
            new SessionRecord(str_repeat('0', 64), $actor->userId, FakeServices::SESSION_CSRF, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'),
            false,
        );
    }

    /**
     * One request through the kernel. Before it, the scripted services
     * take each person's appearance from the core, as CoreServices reads
     * user_settings for every request.
     *
     * @param array<string, string> $post
     */
    private function request(string $token, string $method, string $uri, array $post = []): Response
    {
        foreach (['admin', 'member'] as $username) {
            $actor = $this->core->actor($username);
            $this->services->settings[$actor->userId] = $this->ops->settings()->get($actor);
        }
        if ($method === 'POST') {
            $post['_csrf'] = FakeServices::SESSION_CSRF;
        }

        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]))[0];
    }

    private function page(string $token, string $uri): string
    {
        $response = $this->request($token, 'GET', $uri);
        self::assertSame(200, $response->status, $uri);
        $this->assertScreen($response->body);

        return $response->body;
    }

    private function assertScreen(string $html): void
    {
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($html, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);
    }

    private function xpath(string $html): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
    }

    /** @return list<string> */
    private function texts(DOMXPath $x, string $query): array
    {
        $out = [];
        foreach ($x->query($query) as $node) {
            $out[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $out;
    }
}
