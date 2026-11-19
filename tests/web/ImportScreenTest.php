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
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Context;
use TiffinDB\Web\Controller\ImportController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\UploadedFile;
use TiffinDB\Web\UploadLimits;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-43: the import screens (docs/design/m3/import.md) through the
 * kernel with the real templates, strings, core operations, and the
 * shipped CSV plugin, plus the keyboard structure of the import flow (M3
 * spec part 2 section 9.1 items 1 to 4) and hostile input: markup and huge
 * values in file names, headings, and cells; forged and foreign job keys;
 * another Admin; Members; an expired job; a request at the wrong step;
 * double submits. Synthetic data only.
 */
#[CoversClass(ImportController::class)]
final class ImportScreenTest extends TestCase
{
    private const MARKUP = '<b x="1">&amp;\'"';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private string $admin;
    private string $project;
    private string $rooms;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->admin = $this->signInAs('admin');
        $admin = $this->core->actor('admin');
        $this->project = $this->ops->projects()->create($admin, 'Home Inventory', null)->key;
        $this->rooms = $this->ops->schema()->createTable($admin, $this->project, 'Rooms', 'room', true)->key;
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // ------------------------------------------------------------ helpers

    private function signInAs(string $username): string
    {
        $token = $this->core->signIn($this->ops, $username)->token();
        $signedIn = $this->ops->auth()->resume($token);
        self::assertNotNull($signedIn);
        $this->services->withSession($signedIn, $token);

        return $token;
    }

    /**
     * @param array<string, mixed>        $post
     * @param array<string, UploadedFile> $files
     */
    private function send(string $method, string $uri, array $post = [], ?string $token = null, array $files = []): Response
    {
        $token ??= $this->admin;
        if ($method === 'POST' && !array_key_exists('_csrf', $post)) {
            $post['_csrf'] = $this->services->sessions[$token]->session->csrfToken;
        }
        $base = Fixtures::fullRequest($method, $uri, $post, [Context::SESSION_COOKIE => $token]);
        $request = new Request(
            $base->method,
            $base->path,
            $base->basePath,
            $base->query,
            $base->malformed,
            $base->post,
            $base->cookies,
            files: $files,
        );
        $kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
        [$response] = Fixtures::handle($kernel, $request);

        return $response;
    }

    /** A file as PHP leaves an accepted upload. */
    private function file(string $contents, string $name, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = $error === UPLOAD_ERR_OK ? $this->core->upload($contents) : null;

        return new UploadedFile('file', $name, strlen($contents), $error, $path);
    }

    private function base(): string
    {
        return "/projects/{$this->project}/import";
    }

    /** Uploads and returns the job key from the 303. */
    private function upload(string $csv, string $name = 'inventory.csv', ?string $token = null): string
    {
        $response = $this->send('POST', $this->base(), ['format' => 'auto'], $token, ['file' => $this->file($csv, $name)]);
        self::assertSame(303, $response->status, $response->body);
        $location = (string) $response->header('Location');
        self::assertMatchesRegularExpression('#^/projects/' . $this->project . '/import/[A-Za-z0-9_-]+\z#', $location, 'No file content in the address.');

        return substr($location, strlen($this->base()) + 1);
    }

    /** @param list<list<string>>|null $rows */
    private static function csv(?array $rows = null, ?array $headings = null): string
    {
        return IoFixture::csv($headings ?? IoFixture::HEADINGS, $rows ?? (new IoFixture())->itemRows(12));
    }

    private static function page(Response $response, int $status = 200): DOMXPath
    {
        self::assertSame($status, $response->status, $response->body);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($response->body));
        self::assertSame(1, $x->query('//main[@id="main"][@class="page page-io"]')->length);
        self::assertSame(0, $x->query('//meta[@http-equiv]')->length, 'No meta refresh.');

        return $x;
    }

    private static function texts(DOMXPath $x, string $query): array
    {
        $texts = [];
        foreach ($x->query($query) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }

    private function stagedJobs(): array
    {
        $dir = $this->core->data->path() . '/staging/imports';

        return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
    }

    private function recordCount(string $tableKey): int
    {
        return $this->ops->records()->listing($this->core->actor('admin'), $this->project, $tableKey, ListRequest::none())->tableTotal;
    }

    /** Runs Continue until the job is ready; returns the ready page. */
    private function continueUntilReady(string $job): DOMXPath
    {
        for ($i = 0; $i < 20; $i++) {
            $page = $this->send('GET', "{$this->base()}/{$job}");
            $x = self::page($page);
            if ($x->query('//input[@name="step"][@value="save"]')->length === 1) {
                return $x;
            }
            $response = $this->send('POST', "{$this->base()}/{$job}/continue");
            self::assertSame(303, $response->status, $response->body);
        }
        self::fail('The job never became ready.');
    }

    /** @return array<string, mixed> the review form's default values, as a browser would send them */
    private static function formValues(DOMXPath $x): array
    {
        $post = [];
        foreach ($x->query('//form[@id="import-review"]//input | //form[@id="import-review"]//select') as $control) {
            $name = $control->getAttribute('name');
            $type = $control->getAttribute('type');
            if ($name === '_csrf') {
                continue;
            }
            if (in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) {
                continue;
            }
            $value = $control->getAttribute('value');
            if ($control->nodeName === 'select') {
                $selected = $x->query('./option[@selected]', $control)->item(0) ?? $x->query('./option', $control)->item(0);
                $value = $selected?->getAttribute('value') ?? '';
            }
            if (preg_match('/^([a-z_]+)\[([0-9]+)\]\z/', $name, $m) === 1) {
                $post[$m[1]][(int) $m[2]] = $value;
            } else {
                $post[$name] = $value;
            }
        }

        return $post;
    }

    private static function removeTree(string $path): void
    {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            $child = $path . '/' . $name;
            is_dir($child) && !is_link($child) ? self::removeTree($child) : unlink($child);
        }
        rmdir($path);
    }

    // -------------------------------------------------------------- step 1

    public function testStepOne(): void
    {
        $this->services->uploadLimits = new UploadLimits(2097152, 8388608);
        $response = $this->send('GET', $this->base());
        $x = self::page($response);
        self::assertSame('Import a file - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame('Import a file', HtmlAssertions::text($response->body, '//h1'));
        self::assertSame(['Home Inventory', 'Import'], self::texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]'));
        self::assertSame("/projects/{$this->project}", $x->query('//nav[@class="breadcrumb"]/a')->item(0)?->getAttribute('href'));
        self::assertSame(
            ['Step 1, now Choose a file', 'Step 2 Check the fields', 'Step 3 Import', 'Step 4 Read the report'],
            self::texts($x, '//ol[@class="steps"][@aria-label="Import steps"]/li'),
        );
        self::assertSame(1, $x->query('//li[@aria-current="step"][contains(@class, "step-now")]')->length);
        $form = $x->query('//form[@enctype="multipart/form-data"][@method="post"]')->item(0);
        self::assertNotNull($form);
        self::assertSame($this->base(), $form->getAttribute('action'));
        $input = $x->query('.//input[@type="file"][@name="file"][@id="f-file"][@required]', $form)->item(0);
        self::assertNotNull($input, 'The file input.');
        self::assertStringContainsString('.csv', $input->getAttribute('accept'));
        self::assertSame('help-file', $input->getAttribute('aria-describedby'));
        self::assertSame('File', HtmlAssertions::text($response->body, '//label[@for="f-file"]'));
        self::assertSame('CSV or Excel workbook (.xlsx), up to 2 MB.', str_replace('2.0 MB', '2 MB', HtmlAssertions::text($response->body, '//p[@id="help-file"]')), 'Size cap shown before upload.');
        self::assertSame(0, $x->query('//input[@name="MAX_FILE_SIZE"]')->length);
        self::assertSame(0, $x->query('//script')->length, 'No script on step 1.');
        // csv and xlsx are both enabled: the format choice is offered.
        $formats = $x->query('//fieldset[@class="panel"]/legend')->item(0);
        self::assertSame('Read the file as', trim((string) $formats?->textContent));
        self::assertSame('auto', $x->query('//input[@name="format"][@checked]')->item(0)?->getAttribute('value'));
        self::assertSame("/projects/{$this->project}", $x->query('//div[@class="io-actions"]/a')->item(0)?->getAttribute('href'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function uploadErrors(): iterable
    {
        yield 'no file' => [UPLOAD_ERR_NO_FILE, 'Choose a file.'];
        yield 'ini size' => [UPLOAD_ERR_INI_SIZE, 'That file is larger than 1 KB, the largest this installation accepts.'];
        yield 'form size' => [UPLOAD_ERR_FORM_SIZE, 'That file is larger than 1 KB, the largest this installation accepts.'];
        yield 'partial' => [UPLOAD_ERR_PARTIAL, 'The file did not arrive completely. Try again.'];
        yield 'no tmp dir' => [UPLOAD_ERR_NO_TMP_DIR, 'The file did not arrive completely. Try again.'];
        yield 'cannot write' => [UPLOAD_ERR_CANT_WRITE, 'The file did not arrive completely. Try again.'];
        yield 'extension' => [UPLOAD_ERR_EXTENSION, 'The file did not arrive completely. Try again.'];
    }

    #[DataProvider('uploadErrors')]
    public function testUploadProblems(int $error, string $text): void
    {
        $this->services->uploadLimits = new UploadLimits(1024, null);
        $response = $this->send('POST', $this->base(), [], null, ['file' => $this->file('x', 'a.csv', $error)]);
        $x = self::page($response, 422);
        self::assertSame('Error: Import a file - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame([$text], self::texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame('#f-file', $x->query('//div[@class="error-summary"]//a')->item(0)?->getAttribute('href'));
        self::assertSame($text, HtmlAssertions::text($response->body, '//p[@id="err-file"]'));
        self::assertSame('true', $x->query('//input[@id="f-file"]')->item(0)?->getAttribute('aria-invalid'));
        self::assertSame([], $this->stagedJobs());
    }

    public function testNoFileFieldAndAFileWithoutATemporaryPath(): void
    {
        $x = self::page($this->send('POST', $this->base()), 422);
        self::assertSame(['Choose a file.'], self::texts($x, '//div[@class="error-summary"]//li'));
        // PHP said OK but is_uploaded_file refused the path.
        $forged = new UploadedFile('file', 'a.csv', 10, UPLOAD_ERR_OK, null);
        $x = self::page($this->send('POST', $this->base(), [], null, ['file' => $forged]), 422);
        self::assertSame(['The file did not arrive completely. Try again.'], self::texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame([], $this->stagedJobs());
    }

    public function testUnknownFormatShowsTheFormatChoice(): void
    {
        $response = $this->send('POST', $this->base(), ['format' => 'auto'], null, ['file' => $this->file("a,b\n1,2\n", 'notes.zzz')]);
        $x = self::page($response, 422);
        self::assertSame(['TiffinDB cannot tell how to read this file. Choose a format.'], self::texts($x, '//div[@class="error-summary"]//li'));
        self::assertGreaterThan(1, $x->query('//input[@name="format"]')->length);
        // Choosing CSV reads it.
        $response = $this->send('POST', $this->base(), ['format' => 'import/csv'], null, ['file' => $this->file("a,b\n1,2\n", 'notes.zzz')]);
        self::assertSame(303, $response->status);
        // A tampered format is the core's format_unknown, with the posted value not echoed as checked.
        $response = $this->send('POST', $this->base(), ['format' => '"><b>'], null, ['file' => $this->file("a,b\n1,2\n", 'a.csv')]);
        $x = self::page($response, 422);
        self::assertSame('auto', $x->query('//input[@name="format"][@checked]')->item(0)?->getAttribute('value'));
        self::assertStringNotContainsString('"><b>', $response->body);
    }

    public function testUnreadableFile(): void
    {
        $response = $this->send('POST', $this->base(), [], null, ['file' => $this->file('', 'empty.csv')]);
        $x = self::page($response, 422);
        self::assertCount(1, self::texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame(1, $x->query('//p[@id="err-file"]')->length);
        self::assertSame([], $this->stagedJobs());
    }

    public function testMultipartPostNeedsTheCsrfToken(): void
    {
        $response = $this->send('POST', $this->base(), ['_csrf' => 'wrong'], null, ['file' => $this->file(self::csv(), 'a.csv')]);
        self::assertSame(403, $response->status);
        $response = $this->send('POST', $this->base(), ['_csrf' => ''], null, ['file' => $this->file(self::csv(), 'a.csv')]);
        self::assertSame(403, $response->status);
        self::assertSame([], $this->stagedJobs());
    }

    // ------------------------------------------------------------ review

    public function testReviewDefaultsAndKeyboardStructure(): void
    {
        $job = $this->upload(self::csv());
        $response = $this->send('GET', "{$this->base()}/{$job}");
        $x = self::page($response);
        self::assertSame('Check the fields - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame(
            ['Step 1, done Choose a file', 'Step 2, now Check the fields', 'Step 3 Import', 'Step 4 Read the report'],
            self::texts($x, '//ol[@class="steps"]/li'),
        );
        self::assertSame(
            ['File inventory.csv', 'Read as ' . self::texts($x, '//dl[@class="facts"]/div[2]/dd')[0], 'Rows 12 rows, plus a heading row', 'Columns 7'],
            self::texts($x, '//section[@class="panel"]/dl[@class="facts"]/div'),
        );
        self::assertSame("{$this->base()}/{$job}/reading", $x->query('//section[@class="panel"][@aria-labelledby="i-file"]/div[@class="link-row"]/a[normalize-space()="Change how the file is read"]')->item(0)?->getAttribute('href'), 'The reading link is a plain link inside the panel.');
        self::assertSame(1, $x->query('//form[@id="import-review"][@class="form"]/section[@aria-labelledby="i-fields"]')->length, 'The Fields section sits in the form\'s gap.');

        // Item 2: the target radios are a fieldset with a legend.
        $fieldset = $x->query('//form[@id="import-review"]/fieldset[@class="panel"]')->item(0);
        self::assertSame('Where should the rows go?', trim((string) $x->query('./legend', $fieldset)->item(0)?->textContent));
        self::assertSame(2, $x->query('.//input[@type="radio"][@name="target"]', $fieldset)->length);
        self::assertSame('new', $x->query('.//input[@name="target"][@checked]', $fieldset)->item(0)?->getAttribute('value'));
        $name = $x->query('//input[@id="f-tablename"]')->item(0);
        self::assertSame(['inventory', 'Name of the new table', '60'], [$name?->getAttribute('value'), $name?->getAttribute('aria-label'), $name?->getAttribute('maxlength')]);
        self::assertSame(['Rooms'], self::texts($x, '//select[@id="f-table"]/option'));
        self::assertSame('recheck', $x->query('//button[normalize-space()="Check again"]')->item(0)?->getAttribute('value'));

        // Item 1: every control of the mapping table names its column.
        self::assertSame('Columns in the file and the fields they become', HtmlAssertions::text($response->body, '//table[@class="mapping-table"]/caption'));
        self::assertSame(['Bring in', 'Column in file', 'Field name', 'Type', 'First values'], self::texts($x, '//table[@class="mapping-table"]/thead//th'));
        self::assertSame(IoFixture::HEADINGS, self::texts($x, '//table[@class="mapping-table"]/tbody/tr/th[@scope="row"]'));
        foreach (IoFixture::HEADINGS as $n => $heading) {
            $row = "//table[@class=\"mapping-table\"]/tbody/tr[" . ($n + 1) . ']';
            self::assertSame("Bring in {$heading}", $x->query($row . '//input[@type="checkbox"]')->item(0)?->getAttribute('aria-label'));
            self::assertSame("include[{$n}]", $x->query($row . '//input[@type="checkbox"]')->item(0)?->getAttribute('name'));
            self::assertSame("Field name for {$heading}", $x->query($row . "//input[@id=\"f-name-{$n}\"]")->item(0)?->getAttribute('aria-label'));
            self::assertSame("Type for {$heading}", $x->query($row . "//select[@id=\"f-type-{$n}\"]")->item(0)?->getAttribute('aria-label'));
        }
        self::assertSame('Link to Rooms', HtmlAssertions::text($response->body, '//select[@id="f-type-1"]/option[@selected]'));
        self::assertSame('Currency', HtmlAssertions::text($response->body, '//select[@id="f-type-2"]/option[@selected]'));
        self::assertSame('Date', HtmlAssertions::text($response->body, '//select[@id="f-type-3"]/option[@selected]'));

        // The warning panel is a region with a heading.
        self::assertSame(1, $x->query('//section[@class="warning-panel"][@aria-labelledby]/h2')->length);
        self::assertStringContainsString('are not in the Rooms table yet. They will be added as new rooms.', HtmlAssertions::text($response->body, '//section[@class="warning-panel"]//li'));

        $import = $x->query('//div[@class="io-actions"]/button[@form="import-review"]')->item(0);
        self::assertSame(['submit', 'action', 'import', 'Import 12 rows'], [$import?->getAttribute('type'), $import?->getAttribute('name'), $import?->getAttribute('value'), trim((string) $import?->textContent)]);
        self::assertSame("{$this->base()}/{$job}/cancel", $x->query('//div[@class="io-actions"]/form[@class="inline-form"]')->item(0)?->getAttribute('action'));
        self::assertSame(0, $x->query('//script')->length);
        self::assertSame(0, $x->query('//*[@data-auto-continue]')->length);
    }

    public function testHostileFileText(): void
    {
        $long = str_repeat('W', 5000);
        $csv = IoFixture::csv(
            [self::MARKUP . 'Heading', $long, 'Room'],
            [['<script>alert(1)</script>', 'x', 'Kitchen'], ['"><img src=x>', 'y', '<i>Hall</i>']],
        );
        $job = $this->upload($csv, '<img src=x onerror=alert(1)>"\'.csv');
        $response = $this->send('GET', "{$this->base()}/{$job}");
        $x = self::page($response);
        self::assertStringNotContainsString('<script>alert', $response->body);
        self::assertStringNotContainsString('<img', $response->body);
        self::assertStringNotContainsString('<b x=', $response->body);
        self::assertStringNotContainsString('<i>Hall', $response->body);
        self::assertSame('<img src=x onerror=alert(1)>"\'.csv', HtmlAssertions::text($response->body, '//dl[@class="facts"]/div[1]/dd'));
        self::assertSame(self::MARKUP . 'Heading', HtmlAssertions::text($response->body, '//tbody/tr[1]/th'));
        self::assertSame('Bring in ' . self::MARKUP . 'Heading', $x->query('//tbody/tr[1]//input[@type="checkbox"]')->item(0)?->getAttribute('aria-label'));
        self::assertStringStartsWith('<script>alert(1)</script>', HtmlAssertions::text($response->body, '//tbody/tr[1]/td[@class="mapping-first"]'));
        // A huge heading is cut on screen.
        self::assertSame(str_repeat('W', 200) . '…', HtmlAssertions::text($response->body, '//tbody/tr[2]/th'));
        self::assertLessThan(20000, strlen($response->body) - 2 * strlen($csv), 'The page does not repeat the 5,000-character heading.');
    }

    public function testReviewProblemsAreLinkedFromTheSummary(): void
    {
        $job = $this->upload(self::csv());
        $x = self::page($this->send('GET', "{$this->base()}/{$job}"));
        $post = self::formValues($x);
        $post['action'] = 'import';
        $post['table_name'] = 'Rooms';
        $post['name'][0] = '';
        $post['name'][2] = 'Room';
        $post['type'][3] = 'auto_number';
        $response = $this->send('POST', "{$this->base()}/{$job}", $post);
        $x = self::page($response, 422);
        $links = [];
        foreach ($x->query('//div[@class="error-summary"]//a') as $a) {
            $links[] = [trim($a->textContent), $a->getAttribute('href')];
        }
        self::assertContains(['Another table in this project already has this name.', '#f-tablename'], $links);
        self::assertContains(['Enter a name.', '#f-name-0'], $links);
        self::assertContains(['Another field in this table already has this name.', '#f-name-2'], $links);
        self::assertContains(['Choose a type for this column.', '#f-type-3'], $links);
        foreach ($links as [, $href]) {
            $id = substr($href, 1);
            $control = $x->query("//*[@id=\"{$id}\"]")->item(0);
            self::assertNotNull($control, $href);
            self::assertSame('true', $control->getAttribute('aria-invalid'), $href);
        }
        // Posted values are shown again.
        self::assertSame('Rooms', $x->query('//input[@id="f-tablename"]')->item(0)?->getAttribute('value'));
        self::assertSame('', $x->query('//input[@id="f-name-0"]')->item(0)?->getAttribute('value'));

        // Nothing included.
        $post = self::formValues(self::page($this->send('GET', "{$this->base()}/{$job}")));
        unset($post['include']);
        $post['action'] = 'import';
        $x = self::page($this->send('POST', "{$this->base()}/{$job}", $post), 422);
        self::assertContains('Choose at least one column to bring in.', self::texts($x, '//div[@class="error-summary"]//li'));
        // Still in review.
        self::assertSame(1, self::page($this->send('GET', "{$this->base()}/{$job}"))->query('//form[@id="import-review"]')->length);
    }

    public function testCheckAgainForAnExistingTable(): void
    {
        $job = $this->upload(self::csv());
        $x = self::page($this->send('GET', "{$this->base()}/{$job}"));
        $post = self::formValues($x);
        $post['target'] = 'existing';
        $post['table'] = $this->rooms;
        $post['action'] = 'recheck';
        $response = $this->send('POST', "{$this->base()}/{$job}", $post);
        self::assertSame(303, $response->status);
        self::assertSame("{$this->base()}/{$job}", $response->header('Location'));
        $response = $this->send('GET', "{$this->base()}/{$job}");
        $x = self::page($response);
        self::assertSame(0, $x->query('//div[@class="error-summary"]')->length, 'No problems after Check again.');
        self::assertSame('existing', $x->query('//input[@name="target"][@checked]')->item(0)?->getAttribute('value'));
        self::assertSame(['Bring in', 'Column in file', 'Goes into', 'First values'], self::texts($x, '//table[@class="mapping-table"]/thead//th'));
        self::assertSame('Field for Name', $x->query('//select[@id="f-field-0"]')->item(0)?->getAttribute('aria-label'));
        self::assertSame('Leave out', HtmlAssertions::text($response->body, '//select[@id="f-field-0"]/option[1]'));
        self::assertSame('Name (Text)', HtmlAssertions::text($response->body, '//select[@id="f-field-0"]/option[@selected]'));

        // Back to a new table: the existing-table page sends an empty name, and the proposed name stays (X-12).
        $back = self::formValues($x);
        $back['target'] = 'new';
        $back['action'] = 'recheck';
        self::assertSame('', $back['table_name']);
        self::assertSame(303, $this->send('POST', "{$this->base()}/{$job}", $back)->status);
        $again = self::page($this->send('GET', "{$this->base()}/{$job}"));
        self::assertSame('inventory', $again->query('//input[@id="f-tablename"]')->item(0)?->getAttribute('value'));
        self::assertSame(0, $again->query('//div[@class="error-summary"]')->length);
        // A name typed for the new table is kept by Check again.
        $typed = self::formValues($again);
        $typed['table_name'] = 'Kitchen things';
        $typed['action'] = 'recheck';
        $this->send('POST', "{$this->base()}/{$job}", $typed);
        self::assertSame('Kitchen things', self::page($this->send('GET', "{$this->base()}/{$job}"))->query('//input[@id="f-tablename"]')->item(0)?->getAttribute('value'));
        $this->send('POST', "{$this->base()}/{$job}", $post);
        $x = self::page($this->send('GET', "{$this->base()}/{$job}"));

        // The same field twice.
        $post = self::formValues($x);
        $post['action'] = 'import';
        $post['field'][1] = $post['field'][0];
        $post['include'][1] = '1';
        $x = self::page($this->send('POST', "{$this->base()}/{$job}", $post), 422);
        self::assertSame('#f-field-1', $x->query('//div[@class="error-summary"]//a[normalize-space()="Each field can take only one column."]')->item(0)?->getAttribute('href'));

        // A table key that is not a table of the project.
        $post['table'] = 'zzzzzzzzzzzzzzzz';
        $post['action'] = 'recheck';
        self::assertSame(404, $this->send('POST', "{$this->base()}/{$job}", $post)->status);
    }

    // -------------------------------------------------- import and report

    public function testImportCommitAndReport(): void
    {
        $job = $this->upload(self::csv());
        $post = self::formValues(self::page($this->send('GET', "{$this->base()}/{$job}")));
        $post['action'] = 'import';
        $response = $this->send('POST', "{$this->base()}/{$job}", $post);
        self::assertSame([303, "{$this->base()}/{$job}"], [$response->status, $response->header('Location')]);
        // A second press of Import (back button) only redirects.
        self::assertSame(303, $this->send('POST', "{$this->base()}/{$job}", $post)->status);

        // Preparing: the progress page with the auto-continue form and its script.
        $response = $this->send('GET', "{$this->base()}/{$job}");
        $x = self::page($response);
        self::assertSame(
            ['Step 1, done Choose a file', 'Step 2, done Check the fields', 'Step 3, now Import', 'Step 4 Read the report'],
            self::texts($x, '//ol[@class="steps"]/li'),
        );
        self::assertSame('Checking the rows', HtmlAssertions::text($response->body, '//div[@class="progress"]/p[1]'));
        self::assertSame('0 of 12 rows checked.', HtmlAssertions::text($response->body, '//p[@class="progress-status"][@role="status"]'));
        $auto = $x->query('//form[@data-auto-continue="1"]')->item(0);
        self::assertSame("{$this->base()}/{$job}/continue", $auto?->getAttribute('action'));
        self::assertSame('Working…', $x->query('.//button', $auto)->item(0)?->getAttribute('data-working'));
        self::assertSame(1, $x->query('//script[contains(@src, "import-continue.js")]')->length);
        // Item 3: the Continue button follows the live region in reading order.
        self::assertSame(1, $x->query('//p[@role="status"]/ancestor::div[@class="progress"]/following::form[@data-auto-continue]')->length);
        self::assertSame(1, $x->query('//form[@class="inline-form"][@action="' . "{$this->base()}/{$job}/cancel" . '"]')->length);

        $x = $this->continueUntilReady($job);
        $response = $this->send('GET', "{$this->base()}/{$job}");
        self::assertSame('Saving the rows', HtmlAssertions::text($response->body, '//div[@class="progress"]/p[1]'));
        self::assertSame('Everything is checked. Choose Save the rows to save them all together.', HtmlAssertions::text($response->body, '//p[@class="progress-status"]'));
        self::assertSame(0, $x->query('//*[@data-auto-continue]')->length, 'Saving never submits itself.');
        self::assertSame(0, $x->query('//script')->length, 'The save page loads no script.');
        self::assertSame('Save the rows', HtmlAssertions::text($response->body, '//form[@class="continue-form"]/button'));
        self::assertSame(0, $x->query('//button[normalize-space()="Start again"]')->length);

        // Continue without the save marker (a stale auto-continue form) saves nothing.
        $response = $this->send('POST', "{$this->base()}/{$job}/continue");
        self::assertSame([303, "{$this->base()}/{$job}"], [$response->status, $response->header('Location')]);
        $tables = $this->ops->schema()->tables($this->core->actor('admin'), $this->project);
        self::assertCount(1, $tables, 'Nothing saved yet.');

        $response = $this->send('POST', "{$this->base()}/{$job}/continue", ['step' => 'save']);
        self::assertSame([303, "{$this->base()}/{$job}/report"], [$response->status, $response->header('Location')]);
        $tables = $this->ops->schema()->tables($this->core->actor('admin'), $this->project);
        self::assertCount(2, $tables);
        $table = $tables[0]->table->name === 'inventory' ? $tables[0]->table : $tables[1]->table;
        self::assertSame(12, $this->recordCount($table->key));

        // Double submit: the second save goes to the report and imports nothing.
        $response = $this->send('POST', "{$this->base()}/{$job}/continue", ['step' => 'save']);
        self::assertSame([303, "{$this->base()}/{$job}/report"], [$response->status, $response->header('Location')]);
        self::assertSame(12, $this->recordCount($table->key));
        self::assertSame(303, $this->send('GET', "{$this->base()}/{$job}")->status, 'The finished job address goes to the report.');

        $response = $this->send('GET', "{$this->base()}/{$job}/report");
        $x = self::page($response);
        self::assertSame('Import report - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame('12 rows were imported into inventory.', HtmlAssertions::text($response->body, '//div[@class="notice"][@role="status"]/p'));
        self::assertSame(
            ['Step 1, done Choose a file', 'Step 2, done Check the fields', 'Step 3, done Import', 'Step 4, now Read the report'],
            self::texts($x, '//ol[@class="steps"]/li'),
        );
        $facts = self::texts($x, '//dl[@class="facts"]/div');
        self::assertContains('File inventory.csv', $facts);
        self::assertContains('Table inventory (new table)', $facts);
        self::assertContains('Records added 12', $facts);
        self::assertContains('Linked records added 3', $facts);
        $tablePath = "/projects/{$this->project}/tables/{$table->key}";
        self::assertSame($tablePath, $x->query('//dl[@class="facts"]//a')->item(0)?->getAttribute('href'));
        self::assertSame($tablePath, $x->query('//a[normalize-space()="Open the table"]')->item(0)?->getAttribute('href'));
        self::assertSame($this->base(), $x->query('//a[normalize-space()="Import another file"]')->item(0)?->getAttribute('href'));
        self::assertContains('3 records were added to Rooms.', self::texts($x, '//main/p'));

        // The notice is shown once; the report stays at its address, also after the table is deleted.
        self::assertSame(0, self::page($this->send('GET', "{$this->base()}/{$job}/report"))->query('//div[@class="notice"]')->length);
        $this->ops->schema()->deleteTable($this->core->actor('admin'), $this->project, $table->key);
        $response = $this->send('GET', "{$this->base()}/{$job}/report");
        $x = self::page($response);
        self::assertContains('Table a table that has since been deleted', self::texts($x, '//dl[@class="facts"]/div'));
        self::assertSame(0, $x->query('//a[normalize-space()="Open the table"]')->length);
    }

    public function testReportListsValuesLeftEmpty(): void
    {
        $rows = (new IoFixture())->itemRows(25);
        foreach ([2, 5, 7] as $i) {
            $rows[$i][3] = 'not a date';
        }
        $job = $this->upload(self::csv($rows));
        $response = $this->send('GET', "{$this->base()}/{$job}");
        $x = self::page($response);
        self::assertSame(1, $x->query('//select[@id="f-type-3"]/option[@selected][@value="text"]')->length, 'Mixed column proposed as text.');
        $post = self::formValues($x);
        $post['type'][3] = 'date';
        $post['action'] = 'recheck';
        $this->send('POST', "{$this->base()}/{$job}", $post);
        $response = $this->send('GET', "{$this->base()}/{$job}");
        self::assertContains(
            '3 values in "Purchased" could not be read as date. They will be left empty and listed in the report.',
            self::texts(self::page($response), '//section[@class="warning-panel"]//li'),
        );
        $post['action'] = 'import';
        self::assertSame(303, $this->send('POST', "{$this->base()}/{$job}", $post)->status);
        $this->continueUntilReady($job);
        $this->send('POST', "{$this->base()}/{$job}/continue", ['step' => 'save']);
        $x = self::page($this->send('GET', "{$this->base()}/{$job}/report"));
        self::assertSame(['Field', 'Values', 'Rows'], self::texts($x, '//div[@class="table-scroll"]/table[@class="report-table"]/thead/tr/th'), 'The report table scrolls inside its box.');
        self::assertSame(['Purchased', '3', '3, 6, 8'], self::texts($x, '//table[@class="report-table"]/tbody/tr/*'));
        self::assertSame('Row 1 is the first row after the heading row.', self::texts($x, '//div[@class="table-scroll"]/following-sibling::p')[0]);
    }

    public function testTargetChangedShowsTheReviewAgain(): void
    {
        $job = $this->upload(self::csv());
        $post = self::formValues(self::page($this->send('GET', "{$this->base()}/{$job}")));
        $post['action'] = 'import';
        $this->send('POST', "{$this->base()}/{$job}", $post);
        $this->ops->schema()->createTable($this->core->actor('admin'), $this->project, 'Warranties', 'warranty', true);
        $response = $this->send('POST', "{$this->base()}/{$job}/continue");
        $x = self::page($response, 422);
        self::assertSame(['The table changed while you were importing. Check the fields again.'], self::texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame(1, $x->query('//form[@id="import-review"]')->length);
        // Reloading the POST only redirects; the job is in review.
        self::assertSame(303, $this->send('POST', "{$this->base()}/{$job}/continue")->status);
        self::assertSame(1, self::page($this->send('GET', "{$this->base()}/{$job}"))->query('//form[@id="import-review"]')->length);
    }

    // ---------------------------------------- reading, options, cancel, failure

    public function testLargeFileIsReadInParts(): void
    {
        $job = $this->upload(self::csv((new IoFixture())->itemRows(2300)), 'big & <large>.csv');
        $response = $this->send('GET', "{$this->base()}/{$job}");
        $x = self::page($response);
        self::assertSame('Reading big & <large>.csv', HtmlAssertions::text($response->body, '//div[@class="progress"]/p[1]'));
        self::assertSame('2,000 rows read so far.', HtmlAssertions::text($response->body, '//p[@class="progress-status"]'));
        self::assertSame('Step 1, now Choose a file', self::texts($x, '//ol[@class="steps"]/li')[0]);
        self::assertSame(1, $x->query('//form[@data-auto-continue="1"]')->length);
        self::assertSame(1, $x->query('//script[contains(@src, "import-continue.js")]')->length);
        self::assertSame(1, $x->query('//button[normalize-space()="Start again"]')->length);
        $response = $this->send('POST', "{$this->base()}/{$job}/continue");
        self::assertSame([303, "{$this->base()}/{$job}"], [$response->status, $response->header('Location')]);
        $x = self::page($this->send('GET', "{$this->base()}/{$job}"));
        self::assertSame(1, $x->query('//form[@id="import-review"]')->length);
        self::assertSame('Import 2,300 rows', trim((string) $x->query('//button[@value="import"]')->item(0)?->textContent));
    }

    public function testReadingOptions(): void
    {
        $job = $this->upload(self::csv());
        $response = $this->send('GET', "{$this->base()}/{$job}/reading");
        $x = self::page($response);
        self::assertSame('How the file is read - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame('How the file is read', HtmlAssertions::text($response->body, '//h1'));
        self::assertSame('Step 1, now Choose a file', self::texts($x, '//ol[@class="steps"]/li')[0]);
        self::assertGreaterThan(0, $x->query('//form//input[starts-with(@name, "setting[")]')->length);
        self::assertSame("{$this->base()}/{$job}", $x->query('//a[normalize-space()="Back"]')->item(0)?->getAttribute('href'));
        // Every radio group is a fieldset with a legend.
        foreach ($x->query('//input[@type="radio"]') as $radio) {
            self::assertSame(1, $x->query('ancestor::fieldset/legend', $radio)->length);
        }

        // Untick every box (the heading row among them) and read again.
        $post = [];
        foreach ($x->query('//form//input[@type="radio"][@checked]') as $radio) {
            $post[$radio->getAttribute('name')] = $radio->getAttribute('value');
        }
        $settings = [];
        foreach ($post as $name => $value) {
            if (preg_match('/^setting\[([a-z0-9_]+)\]\z/', $name, $m) === 1) {
                $settings[$m[1]] = $value;
            }
        }
        $response = $this->send('POST', "{$this->base()}/{$job}/reading", ['setting' => $settings]);
        self::assertSame([303, "{$this->base()}/{$job}"], [$response->status, $response->header('Location')]);
        // The job reads the file again from the start: a reading page, then the review.
        $response = $this->send('GET', "{$this->base()}/{$job}");
        self::assertSame('0 rows read so far.', HtmlAssertions::text($response->body, '//p[@class="progress-status"]'));
        self::assertSame(303, $this->send('POST', "{$this->base()}/{$job}/continue")->status);
        $x = self::page($this->send('GET', "{$this->base()}/{$job}"));
        self::assertSame('Rows 13 rows', self::texts($x, '//dl[@class="facts"]/div')[2], 'The unticked heading row is read as data.');
        // Tampered setting values are ignored by the core.
        $response = $this->send('POST', "{$this->base()}/{$job}/reading", ['setting' => ['heading' => ['x'], 'nope' => '<b>'], 'table' => '99']);
        self::assertSame(303, $response->status);
    }

    public function testStartAgain(): void
    {
        $job = $this->upload(self::csv());
        self::assertCount(1, $this->stagedJobs());
        $response = $this->send('POST', "{$this->base()}/{$job}/cancel");
        self::assertSame([303, $this->base()], [$response->status, $response->header('Location')]);
        self::assertSame([], $this->stagedJobs());
        self::assertSame(404, $this->send('GET', "{$this->base()}/{$job}")->status);
        self::assertSame(404, $this->send('POST', "{$this->base()}/{$job}/cancel")->status);
    }

    public function testAFailedSaveShowsTheFailurePage(): void
    {
        $fail = false;
        $ops = $this->core->operations(null, null, static function (string $point) use (&$fail): void {
            if ($fail && $point === 'import.commit.rows') {
                throw new \RuntimeException('Injected commit failure.');
            }
        });
        $this->services->operations = $ops;
        $job = $this->upload(self::csv());
        $post = self::formValues(self::page($this->send('GET', "{$this->base()}/{$job}")));
        $post['action'] = 'import';
        $this->send('POST', "{$this->base()}/{$job}", $post);
        $this->continueUntilReady($job);
        $file = $this->core->projectFolder($this->project) . '/project.tiffin';
        $before = hash_file('sha256', $file);

        $fail = true;
        $response = $this->send('POST', "{$this->base()}/{$job}/continue", ['step' => 'save']);
        self::assertSame([303, "{$this->base()}/{$job}"], [$response->status, $response->header('Location')], 'A failed save redirects; no 500 page.');
        self::assertSame($before, hash_file('sha256', $file), 'The project is unchanged.');
        $fail = false;

        $response = $this->send('GET', "{$this->base()}/{$job}");
        $x = self::page($response);
        self::assertSame('The import did not finish - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame(
            ['Nothing was saved. The project is exactly as it was before.', 'Saving the rows went wrong.'],
            self::texts($x, '//main/p'),
        );
        self::assertSame(1, $x->query('//form[@class="inline-form"][@action="' . "{$this->base()}/{$job}/cancel" . '"]')->length);

        // A reload or a second press of Save only redirects and saves nothing.
        foreach ([1, 2] as $press) {
            $response = $this->send('POST', "{$this->base()}/{$job}/continue", ['step' => 'save']);
            self::assertSame([303, "{$this->base()}/{$job}"], [$response->status, $response->header('Location')], "Press {$press}.");
        }
        self::assertSame($before, hash_file('sha256', $file));
        self::assertCount(1, $this->ops->schema()->tables($this->core->actor('admin'), $this->project), 'No table was added.');
        self::assertSame(1, self::page($this->send('GET', "{$this->base()}/{$job}"))->query('//h1[normalize-space()="The import did not finish"]')->length);

        // Members and another Admin are still refused on the failed job.
        $member = $this->signInAs('member');
        $member2 = $this->signInAs('member2');
        $this->ops->projects()->setSharing($this->core->actor('admin'), $this->project, [$this->core->person('member')->key]);
        $this->core->addPerson('admin2', 'Second Admin', Role::Admin);
        $other = $this->signInAs('admin2');
        foreach ([['GET', ''], ['POST', '/continue'], ['POST', '/cancel']] as [$method, $suffix]) {
            $uri = "{$this->base()}/{$job}{$suffix}";
            self::assertSame(403, $this->send($method, $uri, ['step' => 'save'], $member)->status, "{$method} {$suffix} granted Member");
            self::assertSame(404, $this->send($method, $uri, ['step' => 'save'], $member2)->status, "{$method} {$suffix} ungranted Member");
            self::assertSame(404, $this->send($method, $uri, ['step' => 'save'], $other)->status, "{$method} {$suffix} another Admin");
        }
        self::assertSame($before, hash_file('sha256', $file));
    }

    public function testFailurePage(): void
    {
        $job = $this->upload(self::csv((new IoFixture())->itemRows(2100)));
        $this->ops->plugins()->disable($this->core->actor('admin'), 'import', 'csv');
        self::assertSame(303, $this->send('POST', "{$this->base()}/{$job}/continue")->status);
        $response = $this->send('GET', "{$this->base()}/{$job}");
        $x = self::page($response);
        self::assertSame('The import did not finish - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame(
            ['Nothing was saved. The project is exactly as it was before.', 'The format this file was read with is no longer turned on.'],
            self::texts($x, '//main/p'),
        );
        $form = $x->query('//form[@class="inline-form"][@action="' . "{$this->base()}/{$job}/cancel" . '"]')->item(0);
        self::assertSame('Start again', trim((string) $x->query('.//button[@class="button button-primary button-large"]', $form)->item(0)?->textContent));
        // Steps that need another state are not found; Continue only redirects.
        self::assertSame(404, $this->send('GET', "{$this->base()}/{$job}/reading")->status);
        self::assertSame(303, $this->send('POST', "{$this->base()}/{$job}/continue", ['step' => 'save'])->status);
        self::assertSame(303, $this->send('POST', "{$this->base()}/{$job}", ['action' => 'import'])->status);
    }

    // ---------------------------------------------------------- access

    /** @return list<array{string, string}> method and path suffix of every import route ('' = start) */
    private function routes(string $job): array
    {
        return [
            ['GET', ''], ['POST', ''], ['GET', "/{$job}"], ['POST', "/{$job}"], ['GET', "/{$job}/reading"],
            ['POST', "/{$job}/reading"], ['POST', "/{$job}/continue"], ['POST', "/{$job}/cancel"], ['GET', "/{$job}/report"],
        ];
    }

    public function testMembersAreRefusedOnEveryRoute(): void
    {
        $job = $this->upload(self::csv());
        $member = $this->signInAs('member');
        $member2 = $this->signInAs('member2');
        $this->ops->projects()->setSharing($this->core->actor('admin'), $this->project, [$this->core->person('member')->key]);
        foreach ($this->routes($job) as [$method, $suffix]) {
            $files = $suffix === '' && $method === 'POST' ? ['file' => $this->file(self::csv(), 'a.csv')] : [];
            self::assertSame(403, $this->send($method, $this->base() . $suffix, ['step' => 'save'], $member, $files)->status, "{$method} {$suffix} granted Member");
            self::assertSame(404, $this->send($method, $this->base() . $suffix, ['step' => 'save'], $member2, $files)->status, "{$method} {$suffix} ungranted Member");
        }
        self::assertCount(1, $this->stagedJobs(), 'No Member upload was kept.');
        // The sidebar shows no Import link to a Member, and does to the Admin.
        $list = "/projects/{$this->project}/tables/{$this->rooms}";
        self::assertStringNotContainsString($this->base(), $this->send('GET', $list, [], $member)->body);
        self::assertStringContainsString('href="' . $this->base() . '"', $this->send('GET', $list)->body);
        self::assertStringContainsString('href="' . $this->base() . '"', $this->send('GET', "/projects/{$this->project}/search")->body);
    }

    public function testAnotherAdminCannotSeeTheJob(): void
    {
        $job = $this->upload(self::csv());
        $this->core->addPerson('admin2', 'Second Admin', Role::Admin);
        $other = $this->signInAs('admin2');
        foreach ($this->routes($job) as [$method, $suffix]) {
            if ($suffix === '') {
                continue;
            }
            self::assertSame(404, $this->send($method, $this->base() . $suffix, ['step' => 'save', 'action' => 'recheck'], $other)->status, "{$method} {$suffix}");
        }
        // The job is untouched for its owner.
        self::assertSame(1, self::page($this->send('GET', "{$this->base()}/{$job}"))->query('//form[@id="import-review"]')->length);
    }

    public function testForgedExpiredAndWrongProjectJobKeys(): void
    {
        $job = $this->upload(self::csv());
        foreach (['AAAAAAAAAAAAAAAAAAAAAA', 'zz', str_repeat('a', 300), '..', '%2e%2e', 'a.b'] as $forged) {
            foreach ($this->routes($forged) as [$method, $suffix]) {
                if ($suffix === '') {
                    continue;
                }
                self::assertSame(404, $this->send($method, $this->base() . $suffix, ['step' => 'save'])->status, "{$method} {$suffix}");
            }
        }
        $other = $this->ops->projects()->create($this->core->actor('admin'), 'Garden Log', null)->key;
        self::assertSame(404, $this->send('GET', "/projects/{$other}/import/{$job}")->status, 'A job of another project.');
        self::assertSame(404, $this->send('POST', "/projects/{$other}/import/{$job}/continue")->status);

        // The clean-up removed the job: not found, never an error page.
        $folder = $this->core->data->path() . '/staging/imports/' . $job;
        self::assertDirectoryExists($folder);
        self::removeTree($folder);
        self::assertSame(404, $this->send('GET', "{$this->base()}/{$job}")->status);
        self::assertSame(404, $this->send('POST', "{$this->base()}/{$job}/continue", ['step' => 'save'])->status);
        self::assertSame(404, $this->send('GET', "{$this->base()}/{$job}/report")->status);
    }
}
