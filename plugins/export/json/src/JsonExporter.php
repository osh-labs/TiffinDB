<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Export\Json;

use TiffinDB\Core\Io\Contract\ExportAttachment;
use TiffinDB\Core\Io\Contract\ExportCollection;
use TiffinDB\Core\Io\Contract\ExportField;
use TiffinDB\Core\Io\Contract\ExportRecord;
use TiffinDB\Core\Io\Contract\ExportRelation;
use TiffinDB\Core\Io\Contract\ExportRule;
use TiffinDB\Core\Io\Contract\ExportSource;
use TiffinDB\Core\Io\Contract\ExportTable;
use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Settings;

/**
 * Writes a whole project as one JSON document in the format of
 * docs/formats/json-export.md, version 1 (M3 spec section 10.3). No
 * settings. The document is streamed: each top-level member, relation,
 * attachment, and record is written with one fwrite and followed by a line
 * break, so memory does not grow with the number of records. Members are
 * written in the order of the format document, `collections`
 * last (M4 spec section 8.3); record values follow the
 * table's field order, not the order of the record's value array.
 *
 * Expected failures are PluginFailure: the target cannot be written
 * (`failed`), or a field type the format has no form for (`unsupported`).
 * A partly written file is removed.
 */
final class JsonExporter implements Exporter
{
    public const FORMAT = 'tiffindb-json';
    public const FORMAT_VERSION = 1;

    /** @var resource|null */
    private $handle = null;

    public function settings(string $scope): Settings
    {
        return new Settings([]);
    }

    public function write(ExportSource $source, Settings $settings, string $targetPath): void
    {
        $handle = self::quietly(static fn (): mixed => fopen($targetPath, 'wb'));
        if (!is_resource($handle)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The export file cannot be created.');
        }
        $this->handle = $handle;
        try {
            $this->document($source);
        } catch (\JsonException $error) {
            $this->abandon($targetPath);
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'A value could not be written as JSON.', $error);
        } catch (\Throwable $error) {
            $this->abandon($targetPath);
            throw $error;
        }
        $this->handle = null;
        if (!fclose($handle)) {
            self::remove($targetPath);
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The export file could not be completed.');
        }
    }

    private function document(ExportSource $source): void
    {
        $project = $source->project();
        $this->put('{' . JsonText::string('format') . ':' . JsonText::string(self::FORMAT) . ",\n");
        $this->put(JsonText::string('format_version') . ':' . self::FORMAT_VERSION . ",\n");
        $this->put(JsonText::string('exported_at') . ':' . JsonText::string($project->exportedAt) . ",\n");
        $this->put(JsonText::string('exported_with') . ':' . JsonText::string($project->exportedWith) . ",\n");
        $this->put(JsonText::string('project') . ':{'
            . JsonText::string('key') . ':' . JsonText::string($project->key) . ','
            . JsonText::string('name') . ':' . JsonText::string($project->name) . ','
            . JsonText::string('created_at') . ':' . JsonText::string($project->createdAt) . ','
            . JsonText::string('updated_at') . ':' . JsonText::string($project->updatedAt) . ','
            . JsonText::string('file_format_version') . ':' . $project->fileFormatVersion . "},\n");

        $this->put(JsonText::string('relations') . ':[');
        $first = true;
        foreach ($source->relations() as $relation) {
            $this->put(($first ? "\n" : ",\n") . self::relation($relation));
            $first = false;
        }
        $this->put(($first ? '' : "\n") . "],\n");

        $this->put(JsonText::string('attachments') . ':[');
        $first = true;
        foreach ($source->attachments() as $attachment) {
            $this->put(($first ? "\n" : ",\n") . self::attachment($attachment));
            $first = false;
        }
        $this->put(($first ? '' : "\n") . "],\n");

        $this->put(JsonText::string('tables') . ':[');
        $firstTable = true;
        foreach ($source->tables() as $table) {
            $this->put(($firstTable ? "\n" : ",\n") . self::tableHead($table) . ',' . JsonText::string('records') . ':[');
            $firstTable = false;
            $first = true;
            foreach ($source->records($table->key) as $record) {
                $this->put(($first ? "\n" : ",\n") . self::record($table, $record));
                $first = false;
            }
            $this->put(($first ? '' : "\n") . ']}');
        }
        $this->put(($firstTable ? '' : "\n") . "],\n");

        $this->put(JsonText::string('collections') . ':[');
        $first = true;
        foreach ($source->collections() as $collection) {
            $this->put(($first ? "\n" : ",\n") . self::collection($collection));
            $first = false;
        }
        $this->put(($first ? '' : "\n") . "]}\n");
    }

    /** One collection (format section 7): rules exactly as stored, `match` from the stored match mode. */
    private static function collection(ExportCollection $collection): string
    {
        $rules = [];
        foreach ($collection->rules as $rule) {
            $rules[] = self::rule($rule);
        }

        return '{' . JsonText::string('key') . ':' . JsonText::string($collection->key) . ','
            . JsonText::string('table') . ':' . JsonText::string($collection->tableKey) . ','
            . JsonText::string('name') . ':' . JsonText::string($collection->name) . ','
            . JsonText::string('match') . ':' . JsonText::string($collection->match) . ','
            . JsonText::string('rules') . ':[' . implode(',', $rules) . '],'
            . JsonText::string('sort') . ':' . JsonText::nullableString($collection->sort) . ','
            . JsonText::string('created_at') . ':' . JsonText::string($collection->createdAt) . ','
            . JsonText::string('updated_at') . ':' . JsonText::string($collection->updatedAt) . '}';
    }

    private static function rule(ExportRule $rule): string
    {
        $values = [];
        foreach ($rule->values as $value) {
            $values[] = JsonText::string($value);
        }

        return '{' . JsonText::string('field') . ':' . JsonText::string($rule->field) . ','
            . JsonText::string('op') . ':' . JsonText::string($rule->op) . ','
            . JsonText::string('values') . ':[' . implode(',', $values) . ']}';
    }

    private static function relation(ExportRelation $relation): string
    {
        return '{' . JsonText::string('key') . ':' . JsonText::string($relation->key) . ','
            . JsonText::string('left') . ':' . self::side($relation->leftTable, $relation->leftField, $relation->leftMax) . ','
            . JsonText::string('right') . ':' . self::side($relation->rightTable, $relation->rightField, $relation->rightMax) . '}';
    }

    private static function side(string $table, string $field, string $max): string
    {
        return '{' . JsonText::string('table') . ':' . JsonText::string($table) . ','
            . JsonText::string('field') . ':' . JsonText::string($field) . ','
            . JsonText::string('max') . ':' . JsonText::string($max) . '}';
    }

    private static function attachment(ExportAttachment $attachment): string
    {
        return '{' . JsonText::string('id') . ':' . $attachment->id . ','
            . JsonText::string('sha256') . ':' . JsonText::string($attachment->sha256) . ','
            . JsonText::string('size') . ':' . $attachment->size . ','
            . JsonText::string('media_type') . ':' . JsonText::string($attachment->mediaType) . ','
            . JsonText::string('original_name') . ':' . JsonText::string($attachment->originalName) . ','
            . JsonText::string('created_at') . ':' . JsonText::string($attachment->createdAt) . '}';
    }

    /** The table object up to, not including, its `records` member. */
    private static function tableHead(ExportTable $table): string
    {
        $fields = [];
        foreach ($table->fields as $field) {
            $fields[] = self::field($field);
        }

        return '{' . JsonText::string('key') . ':' . JsonText::string($table->key) . ','
            . JsonText::string('name') . ':' . JsonText::string($table->name) . ','
            . JsonText::string('item_name') . ':' . JsonText::string($table->itemName) . ','
            . JsonText::string('title_field') . ':' . JsonText::nullableString($table->titleField) . ','
            . JsonText::string('fields') . ':[' . implode(',', $fields) . ']';
    }

    private static function field(ExportField $field): string
    {
        return '{' . JsonText::string('key') . ':' . JsonText::string($field->key) . ','
            . JsonText::string('name') . ':' . JsonText::string($field->name) . ','
            . JsonText::string('type') . ':' . JsonText::string($field->type) . ','
            . JsonText::string('options') . ':' . JsonText::options($field->options) . ','
            . JsonText::string('required') . ':' . ($field->required ? 'true' : 'false') . ','
            . JsonText::string('help') . ':' . JsonText::string($field->help) . '}';
    }

    private static function record(ExportTable $table, ExportRecord $record): string
    {
        $values = [];
        foreach ($table->fields as $field) {
            $values[] = JsonText::string($field->key) . ':' . JsonText::value($field, $record->values[$field->key] ?? null);
        }

        return '{' . JsonText::string('id') . ':' . $record->id . ','
            . JsonText::string('created_at') . ':' . JsonText::string($record->createdAt) . ','
            . JsonText::string('created_by') . ':' . JsonText::nullableString($record->createdBy) . ','
            . JsonText::string('updated_at') . ':' . JsonText::string($record->updatedAt) . ','
            . JsonText::string('updated_by') . ':' . JsonText::nullableString($record->updatedBy) . ','
            . JsonText::string('values') . ':{' . implode(',', $values) . '}}';
    }

    private function put(string $bytes): void
    {
        $handle = $this->handle;
        if ($handle === null || self::quietly(static fn (): mixed => fwrite($handle, $bytes)) !== strlen($bytes)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The export file could not be written.');
        }
    }

    private function abandon(string $targetPath): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        self::remove($targetPath);
    }

    private static function remove(string $path): void
    {
        if (is_file($path)) {
            self::quietly(static fn (): mixed => unlink($path));
        }
    }

    /**
     * Runs $work with PHP warnings turned off for its duration (a failed
     * fopen, fwrite, or unlink warns; the caller checks the result instead).
     * The repository has no `@` operator (ADR 0008 decision 6).
     *
     * @param \Closure(): mixed $work
     */
    private static function quietly(\Closure $work): mixed
    {
        set_error_handler(static fn (int $level, string $message): bool => true);
        try {
            return $work->__invoke();
        } finally {
            restore_error_handler();
        }
    }
}
