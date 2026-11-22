<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\AttachmentOperations;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\FileController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\UploadedFile;
use TiffinDB\Web\UploadLimits;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-52 (M4-WP6): the attachment pages of docs/design/m4/files.md
 * through the kernel with the real templates, strings, and core
 * operations over a CoreFixture instance: the upload page (adding,
 * replacing, the limit from UploadLimits::effective), the upload (303 and
 * notice; each UPLOAD_ERR_* code and each core problem as 422 at the file
 * field), the file page, the remove confirmation and the removal, and
 * access. Uploads reach the kernel through the Request constructor (the
 * test seam: is_uploaded_file() is only consulted in fromGlobals).
 * Synthetic data only.
 */
#[CoversClass(FileController::class)]
final class FileScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const HOSTILE = '<img src=x onerror=alert(1)> "q" & \'s\'.pdf';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $receipt;
    private string $photo;
    private string $name;
    private int $mixer;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->uploadLimits = new UploadLimits(2 * 1048576, 8 * 1048576);
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);

        $admin = $this->admin();
        $schema = $this->ops->schema();
        $this->project = $this->ops->projects()->create($admin, 'Home Inventory', 'Items')->key;
        $this->items = $schema->tables($admin, $this->project)[0]->table->key;
        $this->name = $schema->design($admin, $this->project, $this->items)->fields[0]->field->key;
        $this->receipt = $schema->addField($admin, $this->project, $this->items, 'Receipt', 'attachment', [], false, '')->key;
        $this->photo = $schema->addField($admin, $this->project, $this->items, 'Photo', 'attachment', ['images_only' => true], false, '')->key;
        $this->ops->projects()->setSharing($admin, $this->project, [$this->core->person('member')->key]);
        $this->mixer = $this->ops->records()->create($admin, $this->project, $this->items, [$this->name => 'Stand mixer']);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // Upload page (files.md section 2).

    public function testUploadPageForAnyFile(): void
    {
        $html = $this->page(self::MEMBER, $this->files($this->receipt) . '/new');
        $x = $this->xpath($html);

        self::assertSame('Add a file to Stand mixer - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//main[@class="page page-record page-record-narrow"]')->length);
        self::assertSame(['Home Inventory', 'Items', 'Stand mixer', 'Add a file'], $this->crumbs($x));
        self::assertSame(['/projects/' . $this->project, $this->l(), $this->r()], $this->texts($x, '//nav[@class="breadcrumb"]/a/@href'));
        self::assertSame('Add a file to Stand mixer', $this->text($x, '//div[@class="page-head"]//h1'));
        self::assertSame('The file is added to Receipt.', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));

        $form = $this->one($x, '//main//form[@method="post"]');
        self::assertSame([$this->files($this->receipt), 'multipart/form-data'], [$form->getAttribute('action'), $form->getAttribute('enctype')]);
        self::assertSame(0, $x->query('//input[@name="replace"]')->length);
        self::assertSame('File', $this->text($x, '//label[@for="f-file"]'));
        $input = $this->one($x, '//input[@id="f-file"]');
        self::assertSame(['file', 'file', 'required', 'help-file'], [$input->getAttribute('type'), $input->getAttribute('name'), $input->getAttribute('required'), $input->getAttribute('aria-describedby')]);
        self::assertFalse($input->hasAttribute('accept'), 'Any file: no accept attribute.');
        // The limit is the smallest of the core cap (64 MiB) and the PHP limits (2 MiB here).
        self::assertSame(
            'Any kind of file, up to 2.0 MB. Pictures in PNG, JPEG, GIF, or WebP are shown on the record; other files are kept for download.',
            $this->text($x, '//p[@id="help-file"]'),
        );
        self::assertSame(['Add the file'], $this->texts($x, '//main//form//button[contains(@class, "button-primary")][contains(@class, "button-large")][@type="submit"]'));
        self::assertSame($this->r(), $this->attr($x, '//main//form//div[@class="form-actions"]/a', 'href'));
        self::assertSame('Cancel', $this->text($x, '//main//form//div[@class="form-actions"]/a'));
        self::assertSame('This field holds 0 files. At most 100 per item.', $this->text($x, '//main/p[@class="form-note"]'));

        $this->upload(self::MEMBER, $this->receipt, FileFixture::pdf(), 'receipt.pdf');
        $one = $this->xpath($this->page(self::MEMBER, $this->files($this->receipt) . '/new'));
        self::assertSame('This field holds 1 file. At most 100 per item.', $this->text($one, '//main/p[@class="form-note"]'));
    }

    public function testUploadPageForAnImagesOnlyFieldAndTheLimitWithoutPhpLimits(): void
    {
        $this->services->uploadLimits = new UploadLimits(null, null);
        $x = $this->xpath($this->page(self::MEMBER, $this->files($this->photo) . '/new'));
        self::assertSame('.png,.jpg,.jpeg,.gif,.webp', $this->attr($x, '//input[@id="f-file"]', 'accept'));
        self::assertSame('A picture in PNG, JPEG, GIF, or WebP, up to 64.0 MB.', $this->text($x, '//p[@id="help-file"]'));
        self::assertSame('The file is added to Photo.', $this->text($x, '//p[@class="lead"]'));
    }

    public function testUploadingAFile(): void
    {
        $response = $this->upload(self::MEMBER, $this->receipt, FileFixture::pdf(), 'receipt-stand-mixer.pdf');
        self::assertSame(303, $response->status);
        self::assertSame($this->r(), $response->header('Location'));
        self::assertSame(['key' => 'notice.file_added', 'params' => ['name' => 'receipt-stand-mixer.pdf']], $this->services->flash[self::MEMBER]);
        self::assertSame(['receipt-stand-mixer.pdf'], $this->names($this->receipt));

        $record = $this->xpath($this->page(self::MEMBER, $this->r()));
        self::assertSame('receipt-stand-mixer.pdf has been added.', $this->text($record, '//div[@class="notice"]'));
        self::assertSame(['receipt-stand-mixer.pdf (PDF, ' . $this->sizeText(strlen(FileFixture::pdf())) . ')'], $this->texts($record, '//div[p[@id="f-' . $this->receipt . '"]]//li/span[@class="file-name"]'));

        // A path in the client's name is reduced to its last part (UploadedFile), and hidden characters go (core).
        $this->upload(self::MEMBER, $this->photo, FileFixture::png(), "..\\..\\photo\u{202E}gnp.png");
        self::assertSame(["photognp.png"], $this->names($this->photo));
    }

    /** @return iterable<string, array{int, string}> */
    public static function uploadErrors(): iterable
    {
        yield 'no file' => [UPLOAD_ERR_NO_FILE, 'Choose a file.'];
        yield 'over upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, 'That file is larger than 2.0 MB, the largest this installation accepts.'];
        yield 'over the form limit' => [UPLOAD_ERR_FORM_SIZE, 'That file is larger than 2.0 MB, the largest this installation accepts.'];
        yield 'partial' => [UPLOAD_ERR_PARTIAL, 'The file did not arrive completely. Try again.'];
        yield 'no temporary folder' => [UPLOAD_ERR_NO_TMP_DIR, 'The file did not arrive completely. Try again.'];
        yield 'cannot write' => [UPLOAD_ERR_CANT_WRITE, 'The file did not arrive completely. Try again.'];
        yield 'extension' => [UPLOAD_ERR_EXTENSION, 'The file did not arrive completely. Try again.'];
    }

    #[DataProvider('uploadErrors')]
    public function testEachUploadErrorIsA422AtTheFileField(int $error, string $text): void
    {
        $file = new UploadedFile('file', 'receipt.pdf', 10, $error, $this->core->upload('%PDF-1.4'));
        $response = $this->send(self::MEMBER, $this->files($this->receipt), ['file' => $file], []);
        $this->assertProblem($response, $text);
        self::assertSame([], $this->names($this->receipt));
    }

    public function testAPostWithoutAFileAndAFileWithoutATemporaryPath(): void
    {
        $this->assertProblem($this->send(self::MEMBER, $this->files($this->receipt), [], []), 'Choose a file.');
        $lost = new UploadedFile('file', 'receipt.pdf', 10, UPLOAD_ERR_OK, null);
        $this->assertProblem($this->send(self::MEMBER, $this->files($this->receipt), ['file' => $lost], []), 'The file did not arrive completely. Try again.');
        // A file under another field name is not the upload.
        $other = new UploadedFile('other', 'receipt.pdf', 8, UPLOAD_ERR_OK, $this->core->upload('%PDF-1.4'));
        $this->assertProblem($this->send(self::MEMBER, $this->files($this->receipt), ['other' => $other], []), 'Choose a file.');
    }

    public function testCoreProblemsAre422AtTheFileField(): void
    {
        $this->assertProblem($this->upload(self::MEMBER, $this->receipt, '', 'empty.txt'), 'That file is empty. Choose a file with something in it.');
        $this->assertProblem($this->upload(self::MEMBER, $this->photo, FileFixture::pdf(), 'receipt.pdf'), 'This field takes images only: PNG, JPEG, GIF, or WebP.');
        self::assertSame([], $this->names($this->photo));

        // Over the core cap: a sparse file one byte larger, so the test writes no data.
        $large = $this->core->upload('');
        $handle = fopen($large, 'r+');
        self::assertIsResource($handle);
        ftruncate($handle, AttachmentOperations::MAX_BYTES + 1);
        fclose($handle);
        $file = new UploadedFile('file', 'big.bin', AttachmentOperations::MAX_BYTES + 1, UPLOAD_ERR_OK, $large);
        $this->assertProblem($this->send(self::MEMBER, $this->files($this->receipt), ['file' => $file], []), 'That file is larger than 2.0 MB, the largest this installation accepts.');

        // More than the free disk space: a sparse file as large as the free space.
        $free = disk_free_space($this->core->projectFolder($this->project));
        self::assertIsFloat($free);
        $huge = $this->core->upload('');
        $handle = fopen($huge, 'r+');
        self::assertIsResource($handle);
        if (!ftruncate($handle, (int) $free + 1)) {
            fclose($handle);
            self::markTestIncomplete('The file system cannot hold a sparse file of the free size.');
        }
        fclose($handle);
        $file = new UploadedFile('file', 'huge.bin', 1, UPLOAD_ERR_OK, $huge);
        $this->assertProblem($this->send(self::MEMBER, $this->files($this->receipt), ['file' => $file], []), 'There is not enough free disk space for that file.');
        self::assertSame([], $this->names($this->receipt));
    }

    public function testTooManyFilesInTheField(): void
    {
        $db = $this->core->projectDb($this->project);
        for ($n = 1; $n <= 100; $n++) {
            $db->run(
                'INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 1, ?, ?, ?)',
                [str_repeat('d', 64), 'application/octet-stream', 'file-' . $n . '.bin', CoreFixture::START],
            );
            $db->run(
                'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, ?)',
                [$this->receipt, $this->mixer, $db->lastInsertId(), $n],
            );
        }
        $this->assertProblem($this->upload(self::MEMBER, $this->receipt, FileFixture::pdf(), 'one-more.pdf'), 'This item already has 100 files here. Remove one first.');
        self::assertSame('This field holds 100 files. At most 100 per item.', $this->text($this->xpath($this->page(self::MEMBER, $this->files($this->receipt) . '/new')), '//p[@class="form-note"]'));
    }

    public function testReplacingAFile(): void
    {
        $this->upload(self::MEMBER, $this->receipt, FileFixture::pdf(), 'old-receipt.pdf');
        $old = $this->ids($this->receipt)[0];
        $html = $this->page(self::MEMBER, $this->files($this->receipt) . '/new?replace=' . $old);
        $x = $this->xpath($html);

        self::assertSame('Replace old-receipt.pdf - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Home Inventory', 'Items', 'Stand mixer', 'Replace a file'], $this->crumbs($x));
        self::assertSame('Replace old-receipt.pdf', $this->text($x, '//h1'));
        self::assertSame(
            'The new file takes the place of old-receipt.pdf in Receipt. old-receipt.pdf is deleted from TiffinDB when the new file is saved.',
            $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'),
        );
        self::assertSame((string) $old, $this->attr($x, '//main//form/input[@type="hidden"][@name="replace"]', 'value'));
        self::assertSame(['Replace the file'], $this->texts($x, '//main//form//button'));

        $response = $this->send(self::MEMBER, $this->files($this->receipt), ['file' => $this->file('%PDF-1.4 new receipt', 'new-receipt.pdf')], ['replace' => (string) $old]);
        self::assertSame(303, $response->status);
        self::assertSame($this->r(), $response->header('Location'));
        self::assertSame(['key' => 'notice.file_replaced', 'params' => ['name' => 'new-receipt.pdf', 'old' => 'old-receipt.pdf']], $this->services->flash[self::MEMBER]);
        self::assertSame(['new-receipt.pdf'], $this->names($this->receipt));

        // A refused replacement keeps the replace field on the 422 page.
        $again = $this->ids($this->receipt)[0];
        $refused = $this->send(self::MEMBER, $this->files($this->receipt), ['file' => $this->file('', 'empty.pdf')], ['replace' => (string) $again]);
        $this->assertProblem($refused, 'That file is empty. Choose a file with something in it.');
        self::assertSame((string) $again, $this->attr($this->xpath($refused->body), '//input[@name="replace"]', 'value'));
    }

    /** @return iterable<string, array{string}> */
    public static function badReplace(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'leading zero' => ['01'];
        yield 'zero' => ['0'];
        yield 'not linked' => ['999'];
    }

    #[DataProvider('badReplace')]
    public function testATamperedReplaceIsNotFound(string $replace): void
    {
        $this->upload(self::MEMBER, $this->receipt, FileFixture::pdf(), 'receipt.pdf');
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->files($this->receipt) . '/new?replace=' . $replace)->status);
        $response = $this->send(self::MEMBER, $this->files($this->receipt), ['file' => $this->file('%PDF-1.4', 'x.pdf')], ['replace' => $replace]);
        self::assertSame(404, $response->status);
        self::assertSame(['receipt.pdf'], $this->names($this->receipt));
    }

    public function testAFileOfAnotherFieldCannotBeReplacedHere(): void
    {
        $this->upload(self::MEMBER, $this->photo, FileFixture::png(), 'photo.png');
        $photo = $this->ids($this->photo)[0];
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->files($this->receipt) . '/new?replace=' . $photo)->status);
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->files($this->receipt) . '/' . $photo)->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->files($this->receipt) . '/' . $photo . '/remove')->status);
        self::assertSame(['photo.png'], $this->names($this->photo));
    }

    // File page (files.md section 3).

    public function testFilePageOfAnImage(): void
    {
        $this->upload(self::MEMBER, $this->photo, FileFixture::png(), 'stand-mixer.png');
        $id = $this->ids($this->photo)[0];
        $html = $this->page(self::MEMBER, $this->files($this->photo) . '/' . $id);
        $x = $this->xpath($html);

        self::assertSame('stand-mixer.png - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Home Inventory', 'Items', 'Stand mixer', 'stand-mixer.png'], $this->crumbs($x));
        self::assertSame('stand-mixer.png', $this->text($x, '//h1'));
        self::assertSame('In Photo of Stand mixer.', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));
        $img = $this->one($x, '//figure[@class="file-figure"]/img[@class="file-image-large"]');
        self::assertSame(['/projects/' . $this->project . '/files/' . $id, 'stand-mixer.png'], [$img->getAttribute('src'), $img->getAttribute('alt')]);
        self::assertSame('About this file', $this->text($x, '//section[@class="panel"]/h2'));
        self::assertSame(['Kind', 'Size', 'Added'], $this->texts($x, '//dl[@class="facts"]//dt'));
        self::assertSame(['image', $this->sizeText(strlen(FileFixture::png())), 'today by Example Member'], $this->texts($x, '//dl[@class="facts"]//dd'));
        self::assertSame(
            [
                ['Download', '/projects/' . $this->project . '/files/' . $id . '?download=1', 'button button-primary button-large'],
                ['Replace', $this->files($this->photo) . '/new?replace=' . $id, 'button button-large'],
                ['Remove', $this->files($this->photo) . '/' . $id . '/remove', 'button button-large button-danger'],
                ['Back to Stand mixer', $this->r(), 'button button-large'],
            ],
            array_map(static fn (DOMElement $a): array => [trim($a->textContent), $a->getAttribute('href'), $a->getAttribute('class')], $this->elements($x, '//main/div[@class="form-actions"]/a')),
        );
    }

    public function testFilePageOfAPdfAndOfAnUnknownPerson(): void
    {
        $this->ops->attachments()->add($this->admin(), $this->project, $this->items, (string) $this->mixer, $this->receipt, $this->core->upload(FileFixture::pdf()), 'receipt.pdf', null);
        $id = $this->ids($this->receipt)[0];
        $x = $this->xpath($this->page(self::MEMBER, $this->files($this->receipt) . '/' . $id));

        self::assertSame(0, $x->query('//img | //figure')->length, 'A PDF is never shown in a page.');
        self::assertSame('TiffinDB does not show this kind of file. Download it to open it on your device.', $this->text($x, '//main/p[@class="static-value"]'));
        self::assertSame(['PDF', $this->sizeText(strlen(FileFixture::pdf())), 'today by Example Admin'], $this->texts($x, '//dl[@class="facts"]//dd'));
        self::assertSame('/projects/' . $this->project . '/files/' . $id, $this->attr($x, '//main/div[@class="form-actions"]/a[1]', 'href'), 'The plain serving address always downloads.');

        $this->core->projectDb($this->project)->run('UPDATE "_tiffin_attachments" SET "created_by" = NULL, "media_type" = ? WHERE "id" = ?', ['application/octet-stream', $id]);
        $other = $this->xpath($this->page(self::MEMBER, $this->files($this->receipt) . '/' . $id));
        self::assertSame(['file', $this->sizeText(strlen(FileFixture::pdf())), 'today'], $this->texts($other, '//dl[@class="facts"]//dd'));
    }

    // Remove (files.md section 4).

    public function testRemoveConfirmationAndRemoving(): void
    {
        $this->upload(self::MEMBER, $this->receipt, FileFixture::pdf(), 'receipt.pdf');
        $id = $this->ids($this->receipt)[0];
        $hash = (string) $this->core->projectDb($this->project)->value('SELECT "sha256" FROM "_tiffin_attachments" WHERE "id" = ?', [$id]);
        $stored = $this->core->projectFolder($this->project) . '/attachments/' . substr($hash, 0, 2) . '/' . $hash;
        self::assertFileExists($stored);

        $html = $this->page(self::MEMBER, $this->files($this->receipt) . '/' . $id . '/remove');
        $x = $this->xpath($html);
        self::assertSame('Remove receipt.pdf? - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Home Inventory', 'Items', 'Stand mixer', 'Remove a file'], $this->crumbs($x));
        self::assertSame('Remove receipt.pdf?', $this->text($x, '//h1'));
        self::assertSame('receipt.pdf will be removed from Stand mixer and deleted from TiffinDB. This cannot be undone.', $this->text($x, '//main/p'));
        self::assertSame($this->files($this->receipt) . '/' . $id . '/remove', $this->attr($x, '//main//form[@method="post"]', 'action'));
        self::assertSame(['Remove the file'], $this->texts($x, '//main//form//button[@class="button button-large button-danger"]'));
        self::assertSame($this->r(), $this->attr($x, '//main//form//div[@class="form-actions"]/a', 'href'));

        $response = $this->request(self::MEMBER, 'POST', $this->files($this->receipt) . '/' . $id . '/remove');
        self::assertSame(303, $response->status);
        self::assertSame($this->r(), $response->header('Location'));
        self::assertSame(['key' => 'notice.file_removed', 'params' => ['name' => 'receipt.pdf']], $this->services->flash[self::MEMBER]);
        self::assertSame([], $this->names($this->receipt));
        self::assertFileDoesNotExist($stored);

        // Gone: every page of it is not found, and removing again too.
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->files($this->receipt) . '/' . $id)->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->files($this->receipt) . '/' . $id . '/remove')->status);
    }

    // Access.

    public function testUngrantedTrashedAndWrongFieldsAreNotFound(): void
    {
        $this->upload(self::MEMBER, $this->receipt, FileFixture::pdf(), 'receipt.pdf');
        $id = $this->ids($this->receipt)[0];
        $paths = [
            ['GET', $this->files($this->receipt) . '/new'],
            ['POST', $this->files($this->receipt)],
            ['GET', $this->files($this->receipt) . '/' . $id],
            ['GET', $this->files($this->receipt) . '/' . $id . '/remove'],
            ['POST', $this->files($this->receipt) . '/' . $id . '/remove'],
        ];
        $this->ops->projects()->setSharing($this->admin(), $this->project, []);
        foreach ($paths as [$method, $path]) {
            self::assertSame(404, $this->request(self::MEMBER, $method, $path)->status, "ungranted {$method} {$path}");
        }
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);

        $other = $this->ops->records()->create($this->admin(), $this->project, $this->items, [$this->name => 'Kettle']);
        $wrong = [
            'text field' => $this->r() . '/files/' . $this->name . '/new',
            'unknown field' => $this->r() . '/files/abcdefghijkl/new',
            'file of another record' => $this->l() . '/records/' . $other . '/files/' . $this->receipt . '/' . $id,
            'non-canonical id' => $this->files($this->receipt) . '/0' . $id,
            'unknown record' => $this->l() . '/records/999/files/' . $this->receipt . '/new',
        ];
        foreach ($wrong as $what => $path) {
            self::assertSame(404, $this->request(self::MEMBER, 'GET', $path)->status, $what);
        }
        self::assertSame(404, $this->send(self::MEMBER, $this->r() . '/files/' . $this->name, ['file' => $this->file('%PDF-1.4', 'x.pdf')], [])->status);

        $this->ops->records()->trash($this->admin(), $this->project, $this->items, (string) $this->mixer);
        foreach ($paths as [$method, $path]) {
            self::assertSame(404, $this->request(self::MEMBER, $method, $path)->status, "trashed {$method} {$path}");
        }
        $this->ops->records()->restore($this->admin(), $this->project, $this->items, (string) $this->mixer);
        self::assertSame(['receipt.pdf'], $this->names($this->receipt));
    }

    public function testAnUploadWithoutTheCsrfTokenIsRefused(): void
    {
        $response = Fixtures::handle($this->kernel, $this->uploadRequest(self::MEMBER, $this->files($this->receipt), ['file' => $this->file('%PDF-1.4', 'x.pdf')], [], false))[0];
        self::assertSame(403, $response->status);
        self::assertSame([], $this->names($this->receipt));
    }

    public function testHostileFileNamesAreEscapedOnce(): void
    {
        $this->upload(self::MEMBER, $this->receipt, FileFixture::pdf(), self::HOSTILE);
        $id = $this->ids($this->receipt)[0];
        foreach (['', '/remove'] as $suffix) {
            $html = $this->page(self::MEMBER, $this->files($this->receipt) . '/' . $id . $suffix);
            self::assertStringNotContainsString('<img src=x', $html);
            self::assertStringContainsString(self::HOSTILE, $this->text($this->xpath($html), '//h1'));
        }
        $replace = $this->page(self::MEMBER, $this->files($this->receipt) . '/new?replace=' . $id);
        self::assertStringNotContainsString('<img src=x', $replace);
        self::assertSame('Replace ' . self::HOSTILE, $this->text($this->xpath($replace), '//h1'));
    }

    // Helpers.

    private function assertProblem(Response $response, string $text): void
    {
        self::assertSame(422, $response->status, $response->body);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame([$text], $this->texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame('#f-file', $this->attr($x, '//div[@class="error-summary"]//a', 'href'));
        self::assertSame($text, $this->text($x, '//p[@class="error-text"][@id="err-file"]'));
        self::assertSame(['true', 'help-file err-file'], [$this->attr($x, '//input[@id="f-file"]', 'aria-invalid'), $this->attr($x, '//input[@id="f-file"]', 'aria-describedby')]);
        self::assertSame([], glob($this->core->data->path() . '/staging/uploads/*') ?: [], 'No staged upload is left.');
    }

    private function upload(string $token, string $field, string $bytes, string $clientName): Response
    {
        return $this->send($token, $this->files($field), ['file' => $this->file($bytes, $clientName)], []);
    }

    private function file(string $bytes, string $clientName): UploadedFile
    {
        return new UploadedFile('file', UploadedFile::baseName($clientName), strlen($bytes), UPLOAD_ERR_OK, $this->core->upload($bytes));
    }

    /**
     * @param array<string, UploadedFile> $files
     * @param array<string, string>       $post
     */
    private function send(string $token, string $uri, array $files, array $post): Response
    {
        return Fixtures::handle($this->kernel, $this->uploadRequest($token, $uri, $files, $post, true))[0];
    }

    /**
     * A multipart POST as fromGlobals() would build it, with $files set
     * through the constructor.
     *
     * @param array<string, UploadedFile> $files
     * @param array<string, string>       $post
     */
    private function uploadRequest(string $token, string $uri, array $files, array $post, bool $csrf): Request
    {
        if ($csrf) {
            $post['_csrf'] = FakeServices::SESSION_CSRF;
        }
        $r = Fixtures::fullRequest('POST', $uri, $post, ['tdb_session' => $token]);

        return new Request(
            $r->method, $r->path, $r->basePath, $r->query, $r->malformed, $r->post, $r->cookies, $r->secure, $r->clientAddress,
            $r->secFetchSite, $r->host, $r->serverAddress, $r->serverPort, $r->documentRoot, $r->origin, $files, $r->contentLength,
        );
    }

    /** @return list<string> the names of the files a field of the mixer holds, in order */
    private function names(string $field): array
    {
        return array_map(static fn (array $row): string => (string) $row['original_name'], $this->rows($field));
    }

    /** @return list<int> */
    private function ids(string $field): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->rows($field));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $field): array
    {
        return $this->core->projectDb($this->project)->all(
            'SELECT "a"."id", "a"."original_name" FROM "_tiffin_attachment_links" AS "l" JOIN "_tiffin_attachments" AS "a" ON "a"."id" = "l"."attachment_id" WHERE "l"."field_key" = ? AND "l"."record_id" = ? ORDER BY "l"."position"',
            [$field, $this->mixer],
        );
    }

    private function sizeText(int $bytes): string
    {
        return $bytes < 1024 ? $bytes . ' bytes' : intdiv($bytes + 1023, 1024) . ' KB';
    }

    private function files(string $field): string
    {
        return $this->r() . '/files/' . $field;
    }

    private function l(): string
    {
        return '/projects/' . $this->project . '/tables/' . $this->items;
    }

    private function r(): string
    {
        return $this->l() . '/records/' . $this->mixer;
    }

    private function admin(): Actor
    {
        return $this->core->actor('admin');
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

    /** @param array<string, string> $post */
    private function request(string $token, string $method, string $uri, array $post = []): Response
    {
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

    private function one(DOMXPath $x, string $query): DOMElement
    {
        $node = $x->query($query)->item(0);
        self::assertInstanceOf(DOMElement::class, $node, $query);

        return $node;
    }

    /** @return list<DOMElement> */
    private function elements(DOMXPath $x, string $query): array
    {
        $list = [];
        foreach ($x->query($query) as $node) {
            self::assertInstanceOf(DOMElement::class, $node);
            $list[] = $node;
        }

        return $list;
    }

    private function attr(DOMXPath $x, string $query, string $name): ?string
    {
        $node = $this->one($x, $query);

        return $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    private function text(DOMXPath $x, string $query): string
    {
        $texts = $this->texts($x, $query);
        self::assertNotSame([], $texts, $query);

        return $texts[0];
    }

    /** @return list<string> */
    private function crumbs(DOMXPath $x): array
    {
        return $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]');
    }

    /** @return list<string> */
    private function texts(DOMXPath $x, string $query): array
    {
        $texts = [];
        foreach ($x->query($query) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
        }

        return $texts;
    }
}
