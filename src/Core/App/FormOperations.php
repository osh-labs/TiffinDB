<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Forms\EditorAction;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormEditor;
use TiffinDB\Core\Forms\FormFormat;
use TiffinDB\Core\Forms\FormGenerator;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormRejected;
use TiffinDB\Core\Forms\FormReport;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Forms\FormStore;
use TiffinDB\Core\Forms\FormWrite;
use TiffinDB\Core\Forms\StoredForm;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\MediaType;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Editing a table's default form (M5 spec section 9, ADR 0020): the simple
 * editor, the source view, download, upload, and reset.
 *
 * Authorization: every method starts with open(), which calls
 * Context::project for forms.edit (Admin only) before anything else: an
 * ungranted person, an unknown project, and an inactive project are
 * NotFound, a granted Member is AccessDenied, and nothing of the project
 * or its forms is read before that. A table key that is not a table of the
 * project is NotFound.
 *
 * Untrusted input: the editor's hidden draft and the source text are
 * parsed with FormParser on every request before anything else uses them;
 * a refused text is never stored. Every save stores the canonical text and
 * refuses one longer than FormFormat::MAX_BYTES (section 5).
 *
 * Audit: `form.saved` (table, source, generated) and `form.reset` (table)
 * after the commit, and only when something was written. No entry, log
 * line, or problem holds form text, a label, a legend, or a file name.
 */
final class FormOperations
{
    public const STATE_STORED = 'stored';
    public const STATE_NONE = 'none';
    public const STATE_REFUSED = 'refused';

    public const SOURCE_EDITOR = 'editor';
    public const SOURCE_SOURCE = 'source';
    public const SOURCE_UPLOAD = 'upload';

    public const FILE_SUFFIX = ' form.html';

    private readonly FormParser $parser;
    private readonly FormSerializer $serializer;
    private readonly FormGenerator $generator;
    private readonly FormEditor $editor;

    public function __construct(private readonly Context $c)
    {
        $this->parser = new FormParser($c->types);
        $this->serializer = new FormSerializer();
        $this->generator = new FormGenerator();
        $this->editor = new FormEditor();
    }

    /**
     * The simple editor (section 9.2). With $input null: the stored default
     * form, or the generated form when there is none or it cannot be used.
     * With $input (`draft`, `base`, `do`, and the arrays `label`, `legend`,
     * `width`): the posted draft, parsed first, with the inputs applied and
     * the action performed; `do` `save` saves.
     *
     * @param array<mixed>|null $input
     */
    public function editor(Actor $actor, string $projectKey, string $tableKey, ?array $input = null): FormEditorScreen
    {
        [$project, $db, $schema, $table] = $this->open($actor, $projectKey, Permission::EditForms, $tableKey);
        $store = $this->c->forms($db);
        $fields = $schema->fields($table->key);
        [$stored, $storedDocument, $state, $rejected] = $this->load($store, $schema, $table->key);
        $storedProblem = $rejected === null ? null : self::problemOf(null, $rejected);
        $base = FormStore::baseOf($stored);
        $screen = fn (FormDocument $document, string $base, array $problems, array $typed, bool $draftRefused, ?array $focus, ?FormSaved $saved, string $state, ?Problem $storedProblem): FormEditorScreen => new FormEditorScreen(
            $project,
            $table,
            $this->editor->rows($document, $fields),
            $this->report($project, $db, $schema, $document, $fields),
            $this->serializer->serialize($document),
            $base,
            $state,
            $storedProblem,
            $this->isGenerated($schema, $table->key, $document),
            $problems,
            $typed,
            $draftRefused,
            $focus,
            $saved,
        );
        if ($input === null) {
            return $screen($storedDocument, $base, [], [], false, null, null, $state, $storedProblem);
        }

        // Step 1: the draft is never trusted; a text the parser refuses shows the stored form again.
        try {
            $draft = $this->parser->parse(self::string($input, 'draft'));
        } catch (FormRejected) {
            return $screen($storedDocument, $base, [new Problem(null, 'problem.form.draft')], [], true, null, null, $state, $storedProblem);
        }
        $postedBase = self::string($input, 'base');

        // Step 2: labels, legends, and widths, keyed by paths of the draft.
        $applied = $this->editor->apply($draft, $fields, self::array($input, 'label'), self::array($input, 'legend'), self::array($input, 'width'));
        $document = $applied->document;
        $problems = $applied->problems;
        $typed = $applied->typed;
        $focus = null;
        $action = null;

        // Steps 3 and 4: the action, only when every input was accepted.
        if ($problems === []) {
            $action = EditorAction::parse(self::string($input, 'do')) ?? EditorAction::apply();
            try {
                $after = $this->editor->act($document, $action, $fields);
                $focus = $this->editor->focusAfter($document, $action, $after);
                $document = $after;
            } catch (\DomainException) {
                $problems = [new Problem(null, 'problem.form.action')];
                $action = null;
            }
        }

        // Step 5: a working copy whose canonical text is too long is not kept.
        if (strlen($this->serializer->serialize($document)) > FormFormat::MAX_BYTES) {
            return $screen($draft, $postedBase, [self::tooLarge(null)], [], false, null, null, $state, $storedProblem);
        }

        // Step 6: save.
        $saved = null;
        if ($problems === [] && $action !== null && $action->verb === EditorAction::SAVE) {
            [$write, $current] = $this->save($actor, $project, $db, $schema, $store, $table, $document, $postedBase, self::SOURCE_EDITOR);
            if ($write === null) {
                $problems = [new Problem(null, 'problem.form.changed')];
                $postedBase = $current;
            } else {
                $saved = new FormSaved($table->key, $write->generated, $write->changed, false);
                $postedBase = $current;
                $state = self::STATE_STORED;
                $storedProblem = null;
            }
        }

        return $screen($document, $postedBase, $problems, $typed, false, $focus, $saved, $state, $storedProblem);
    }

    /**
     * The source view (section 9.5). With $input null: the stored text
     * exactly as stored (also when it is refused), or the generated text
     * when there is no row. With $input (`text`, `base`, `do` = `check` or
     * `save`; anything else is `check`): the text parsed, reported, and on
     * `save` stored as its canonical text.
     *
     * @param array<mixed>|null $input
     */
    public function source(Actor $actor, string $projectKey, string $tableKey, ?array $input = null): FormSourceScreen
    {
        [$project, $db, $schema, $table] = $this->open($actor, $projectKey, Permission::EditForms, $tableKey);
        $store = $this->c->forms($db);
        [$stored, , $state, $rejected] = $this->load($store, $schema, $table->key);
        $storedProblem = $rejected === null ? null : self::problemOf('source', $rejected);
        $base = FormStore::baseOf($stored);
        if ($input === null) {
            $text = $stored === null ? $this->serializer->serialize($this->generator->generate($schema, $table->key)) : $stored->html;

            return new FormSourceScreen($project, $table, $text, $base, $state, $storedProblem, [], null, null);
        }

        $text = self::string($input, 'text');
        $postedBase = self::string($input, 'base');
        $save = self::string($input, 'do') === 'save';
        try {
            $document = $this->parser->parse($text);
        } catch (FormRejected $refused) {
            return new FormSourceScreen($project, $table, $text, $postedBase, $state, $storedProblem, [self::problemOf('source', $refused)], null, null);
        }
        $canonical = $this->serializer->serialize($document);
        if (strlen($canonical) > FormFormat::MAX_BYTES) {
            return new FormSourceScreen($project, $table, $text, $postedBase, $state, $storedProblem, [self::tooLarge('source')], null, null);
        }
        $report = $this->report($project, $db, $schema, $document, $schema->fields($table->key));
        if (!$save) {
            return new FormSourceScreen($project, $table, $text, $postedBase, $state, $storedProblem, [], $report, null);
        }

        [$write, $current] = $this->save($actor, $project, $db, $schema, $store, $table, $document, $postedBase, self::SOURCE_SOURCE);
        if ($write === null) {
            return new FormSourceScreen($project, $table, $text, $current, $state, $storedProblem, [new Problem(null, 'problem.form.changed')], $report, null);
        }
        $saved = new FormSaved($table->key, $write->generated, $write->changed, $canonical !== self::lineFeeds($text));

        return new FormSourceScreen($project, $table, $write->html, $current, self::STATE_STORED, null, [], $report, $saved);
    }

    /** The upload page: the table and the size cap of a form file. */
    public function uploadScreen(Actor $actor, string $projectKey, string $tableKey): FormUploadScreen
    {
        [$project, , , $table] = $this->open($actor, $projectKey, Permission::EditForms, $tableKey);

        return new FormUploadScreen($project, $table, FormFormat::MAX_BYTES);
    }

    /**
     * Stores the uploaded form file at $uploadPath as the table's default
     * form (section 9.1), without a save-token check (P211). The size is
     * checked before anything is read; a refused or too long text stores
     * nothing. The file itself is left where it is (the web layer's).
     *
     * @throws ValidationFailed at `file`: problem.upload.missing, problem.upload.failed,
     *         problem.form.empty_file, problem.form.too_large, or the parser's refusal
     */
    public function upload(Actor $actor, string $projectKey, string $tableKey, string $uploadPath): FormSaved
    {
        [$project, $db, $schema, $table] = $this->open($actor, $projectKey, Permission::EditForms, $tableKey);
        if ($uploadPath === '' || str_contains($uploadPath, "\0") || !Fs::isFile($uploadPath)) {
            throw new ValidationFailed([new Problem('file', 'problem.upload.missing')]);
        }
        try {
            $size = Fs::size($uploadPath);
        } catch (\RuntimeException) {
            throw new ValidationFailed([new Problem('file', 'problem.upload.failed')]);
        }
        if ($size === 0) {
            throw new ValidationFailed([new Problem('file', 'problem.form.empty_file')]);
        }
        if ($size > FormFormat::MAX_BYTES) {
            throw new ValidationFailed([self::tooLarge('file')]);
        }
        try {
            $text = ErrorTrap::run(static fn (): string|false => file_get_contents($uploadPath, false, null, 0, FormFormat::MAX_BYTES + 1), 'Cannot read the form file.');
        } catch (\RuntimeException $failed) {
            error_log('TiffinDB: a form file could not be read (' . $failed::class . ').');

            throw new ValidationFailed([new Problem('file', 'problem.upload.failed')]);
        }
        if (!is_string($text)) {
            throw new ValidationFailed([new Problem('file', 'problem.upload.failed')]);
        }
        if ($text === '') {
            throw new ValidationFailed([new Problem('file', 'problem.form.empty_file')]);
        }
        if (strlen($text) > FormFormat::MAX_BYTES) {
            throw new ValidationFailed([self::tooLarge('file')]);
        }
        try {
            $document = $this->parser->parse($text);
        } catch (FormRejected $refused) {
            throw new ValidationFailed([self::problemOf('file', $refused)]);
        }
        $canonical = $this->serializer->serialize($document);
        if (strlen($canonical) > FormFormat::MAX_BYTES) {
            throw new ValidationFailed([self::tooLarge('file')]);
        }
        [$write] = $this->save($actor, $project, $db, $schema, $this->c->forms($db), $table, $document, null, self::SOURCE_UPLOAD);
        assert($write !== null);

        return new FormSaved($table->key, $write->generated, $write->changed, $canonical !== self::lineFeeds($text));
    }

    /** The default form as a file: the stored text exactly, or the generated text when there is no row. Never writes. */
    public function download(Actor $actor, string $projectKey, string $tableKey): FormFile
    {
        [, $db, $schema, $table] = $this->open($actor, $projectKey, Permission::EditForms, $tableKey);
        $stored = $this->c->forms($db)->defaultFor($table->key);
        $text = $stored === null ? $this->serializer->serialize($this->generator->generate($schema, $table->key)) : $stored->html;

        return new FormFile($table->name . self::FILE_SUFFIX, $text);
    }

    /** The reset confirmation page. */
    public function resetScreen(Actor $actor, string $projectKey, string $tableKey): FormResetScreen
    {
        [$project, $db, $schema, $table] = $this->open($actor, $projectKey, Permission::EditForms, $tableKey);
        $stored = $this->c->forms($db)->defaultFor($table->key);
        $isStandard = $stored !== null
            && $stored->generated
            && $stored->format === FormFormat::VERSION
            && $stored->html === $this->serializer->serialize($this->generator->generate($schema, $table->key));

        return new FormResetScreen($project, $table, $isStandard);
    }

    /** Makes the generated layout the default form again; true when anything changed (then audited). */
    public function reset(Actor $actor, string $projectKey, string $tableKey): bool
    {
        [$project, $db, $schema, $table] = $this->open($actor, $projectKey, Permission::EditForms, $tableKey);
        $store = $this->c->forms($db);
        $write = $db->write(function (Database $db) use ($actor, $store, $schema, $table): FormWrite {
            $write = $store->resetDefault($schema, $table->key);
            if ($write->changed) {
                ProjectFile::touch($db, $actor->userKey, $this->c->clock);
            }

            return $write;
        });
        if ($write->changed) {
            $this->c->audit->record(AuditAction::FORM_RESET, $actor->userId, $project->id, 'form', $write->key, ['table' => $table->key]);
        }

        return $write->changed;
    }

    // ------------------------------------------------------------------ internals

    /**
     * The permission check first, then the project, its schema, and the table.
     *
     * @return array{0: ProjectRecord, 1: Database, 2: Schema, 3: TableDef}
     */
    private function open(Actor $actor, string $projectKey, Permission $permission, string $tableKey): array
    {
        $project = $this->c->project($actor, $permission, $projectKey);
        $db = $this->c->opener->open($project);
        $schema = new Schema($db, $this->c->types);

        return [$project, $db, $schema, $schema->table($tableKey)];
    }

    /**
     * The stored default form and the document the editor starts from: the
     * stored form parsed (`stored`), or the generated form when there is no
     * row (`none`), its format is not 1, or the parser refuses it
     * (`refused`, with the refusal).
     *
     * @return array{0: ?StoredForm, 1: FormDocument, 2: string, 3: ?FormRejected}
     */
    private function load(FormStore $store, Schema $schema, string $tableKey): array
    {
        $stored = $store->defaultFor($tableKey);
        if ($stored === null) {
            return [null, $this->generator->generate($schema, $tableKey), self::STATE_NONE, null];
        }
        if ($stored->format !== FormFormat::VERSION) {
            return [$stored, $this->generator->generate($schema, $tableKey), self::STATE_REFUSED, new FormRejected(FormRejected::FORMAT_VERSION)];
        }
        try {
            return [$stored, $this->parser->parse($stored->html), self::STATE_STORED, null];
        } catch (FormRejected $refused) {
            return [$stored, $this->generator->generate($schema, $tableKey), self::STATE_REFUSED, $refused];
        }
    }

    /**
     * One write (section 9.4): the save-token check (skipped when $base is
     * null, for uploads), the canonical text stored, the project touched
     * when it changed. Audited after the commit when it changed. Returns
     * the write (null on a token mismatch, nothing written) and the save
     * token of the text now stored.
     *
     * @return array{0: ?FormWrite, 1: string}
     */
    private function save(
        Actor $actor,
        ProjectRecord $project,
        Database $db,
        Schema $schema,
        FormStore $store,
        TableDef $table,
        FormDocument $document,
        ?string $base,
        string $source,
    ): array {
        /** @var array{0: ?FormWrite, 1: string} $result */
        $result = $db->write(function (Database $db) use ($actor, $schema, $store, $table, $document, $base): array {
            $current = FormStore::baseOf($store->defaultFor($table->key));
            if ($base !== null && !hash_equals($current, $base)) {
                return [null, $current];
            }
            $write = $store->saveDefault($schema, $table->key, $document);
            if ($write->changed) {
                ProjectFile::touch($db, $actor->userKey, $this->c->clock);
            }

            return [$write, hash('sha256', $write->html)];
        });
        $write = $result[0];
        if ($write !== null && $write->changed) {
            $this->c->audit->record(AuditAction::FORM_SAVED, $actor->userId, $project->id, 'form', $write->key, [
                'table' => $table->key,
                'source' => $source,
                'generated' => $write->generated ? 1 : 0,
            ]);
        }

        return $result;
    }

    /**
     * The report of a document (section 9.3): the pictures that cannot be
     * shown are those whose attachment is missing, not one of the four
     * inline image types, or not linked from a live record (P173).
     *
     * @param list<FieldDef> $fields
     */
    private function report(ProjectRecord $project, Database $db, Schema $schema, FormDocument $document, array $fields): FormReport
    {
        $report = FormReport::of($document, $fields);
        if ($report->images === []) {
            return $report;
        }
        $attachments = $this->c->attachments($project, $db, $schema);
        $hidden = [];
        foreach (array_unique($report->images) as $id) {
            $row = $id >= 1 ? $attachments->get($id) : null;
            if ($row === null || !MediaType::isInline($row->mediaType) || !$attachments->isServable($id)) {
                $hidden[] = $id;
            }
        }

        return $report->withImagesNotShown($hidden);
    }

    private function isGenerated(Schema $schema, string $tableKey, FormDocument $document): bool
    {
        return $this->serializer->serialize($document) === $this->serializer->serialize($this->generator->generate($schema, $tableKey));
    }

    /**
     * A refusal as a problem: its message key and parameters (the line and
     * the shown names only), with `max` for the limit reasons.
     */
    private static function problemOf(?string $field, FormRejected $refused): Problem
    {
        $params = $refused->params();
        $max = match ($refused->reason) {
            FormRejected::TOO_LARGE => FormFormat::MAX_BYTES,
            FormRejected::TOO_MANY_ELEMENTS => FormFormat::MAX_ELEMENTS,
            FormRejected::TOO_DEEP => FormFormat::MAX_DEPTH,
            default => null,
        };
        if ($max !== null) {
            $params['max'] = $max;
        }

        return new Problem($field, $refused->messageKey(), $params);
    }

    private static function tooLarge(?string $field): Problem
    {
        return new Problem($field, 'problem.form.too_large', ['max' => FormFormat::MAX_BYTES]);
    }

    /** The text with CR LF and lone CR as LF, as a browser's text area sends line breaks. */
    private static function lineFeeds(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** @param array<mixed> $input */
    private static function string(array $input, string $name): string
    {
        $value = $input[$name] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<mixed> $input
     * @return array<mixed>
     */
    private static function array(array $input, string $name): array
    {
        $value = $input[$name] ?? null;

        return is_array($value) ? $value : [];
    }
}
