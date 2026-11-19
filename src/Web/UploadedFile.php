<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use Closure;
use InvalidArgumentException;

/**
 * One uploaded file of a request (M3 spec part 2 section 2.1; P126).
 *
 * Nothing about it is trusted: the client's name is kept as text only (the
 * part after the last `/` or `\`, never used as a path; the core cleans it
 * again), and the temporary path is set only when PHP reports success and,
 * for a request built from PHP's arrays, is_uploaded_file() confirms that
 * PHP itself received the file. Controllers pass tmpPath and clientName to
 * the core, which moves the file into its staging folder and names it
 * itself; the web layer never moves, copies, or deletes an upload.
 *
 * A test seam: the constructor takes any temporary path, so tests can
 * build a Request with a file they made; is_uploaded_file() is consulted
 * only by fromEntry(), which Request::fromGlobals() uses.
 */
final class UploadedFile
{
    /**
     * Field names an upload may have: a lower-case letter, then up to 31
     * lower-case letters, digits, or underscores; anything else is dropped.
     */
    public const FIELD = '/^[a-z][a-z0-9_]{0,31}\z/';

    /** One accepted extension, with or without its leading dot. */
    private const ACCEPT_ITEM = '/^\.?([a-z0-9]{1,10})\z/';

    /** The largest PHP upload error code (UPLOAD_ERR_EXTENSION). */
    private const MAX_ERROR = 8;

    /** The temporary path, or null when there is no usable file. */
    public readonly ?string $tmpPath;

    /**
     * @param string      $field      The form field name (FIELD).
     * @param string      $clientName The name the browser sent, as plain text.
     * @param int         $size       The size PHP reports, in bytes.
     * @param int         $error      PHP's UPLOAD_ERR_* code.
     * @param string|null $tmpPath    Kept only when $error is UPLOAD_ERR_OK.
     */
    public function __construct(
        public readonly string $field,
        public readonly string $clientName,
        public readonly int $size,
        public readonly int $error,
        ?string $tmpPath,
    ) {
        if (preg_match(self::FIELD, $field) !== 1) {
            throw new InvalidArgumentException('Invalid upload field name.');
        }
        if ($size < 0) {
            throw new InvalidArgumentException('Invalid upload size.');
        }
        if ($error < 0 || $error > self::MAX_ERROR) {
            throw new InvalidArgumentException('Invalid upload error code.');
        }
        if ($tmpPath !== null && ($tmpPath === '' || str_contains($tmpPath, "\0"))) {
            throw new InvalidArgumentException('Invalid upload path.');
        }
        $this->tmpPath = $error === UPLOAD_ERR_OK ? $tmpPath : null;
    }

    /**
     * The file for one entry of PHP's upload array, or null when the entry
     * is dropped: a field name outside FIELD, an entry that is not an array,
     * or one whose `name`, `tmp_name`, `size`, or `error` is not a scalar
     * (array-shaped entries from `name="x[]"`: single files only), or whose
     * size or error is not a whole number. When PHP reports success but
     * $isUploaded refuses the temporary path, the file is kept with a null
     * tmpPath, so problem() reports it as failed.
     *
     * @param Closure(string): bool $isUploaded is_uploaded_file() in production.
     */
    public static function fromEntry(mixed $field, mixed $entry, Closure $isUploaded): ?self
    {
        if (!is_string($field) || preg_match(self::FIELD, $field) !== 1 || !is_array($entry)) {
            return null;
        }
        $name = $entry['name'] ?? null;
        $tmp = $entry['tmp_name'] ?? null;
        $size = self::whole($entry['size'] ?? null);
        $error = self::whole($entry['error'] ?? null);
        if (!is_string($name) || !is_string($tmp) || $size === null || $error === null || $error > self::MAX_ERROR) {
            return null;
        }

        $tmpPath = null;
        if ($error === UPLOAD_ERR_OK && $tmp !== '' && !str_contains($tmp, "\0") && $isUploaded($tmp) === true) {
            $tmpPath = $tmp;
        }

        return new self($field, self::baseName($name), $size, $error, $tmpPath);
    }

    /**
     * The client's file name after the last `/` or `\`: text to show and to
     * hand to the core, never a path.
     */
    public static function baseName(string $name): string
    {
        $parts = preg_split('#[/\\\\]#', $name);

        return is_array($parts) ? (string) end($parts) : '';
    }

    /**
     * The message key for what went wrong, or null when the file arrived:
     * `problem.upload.missing` (no file chosen), `problem.upload.too_large`
     * (over upload_max_filesize or the form's limit), or
     * `problem.upload.failed` (anything else, including a file without a
     * usable temporary path).
     */
    public function problem(): ?string
    {
        return match (true) {
            $this->error === UPLOAD_ERR_NO_FILE => 'problem.upload.missing',
            $this->error === UPLOAD_ERR_INI_SIZE, $this->error === UPLOAD_ERR_FORM_SIZE => 'problem.upload.too_large',
            $this->error !== UPLOAD_ERR_OK, $this->tmpPath === null => 'problem.upload.failed',
            default => null,
        };
    }

    /**
     * The `accept` list of the file-field partial: each extension with a
     * leading dot (`csv` and `.csv` both give `.csv`), duplicates removed,
     * in order. The browser uses it only to filter its chooser; the core
     * decides how to read a file from its content and the chosen plugin.
     *
     * @param list<string> $extensions
     * @return list<string>
     * @throws InvalidArgumentException for an item other than 1 to 10 lower-case letters or digits
     */
    public static function acceptList(array $extensions): array
    {
        $list = [];
        foreach ($extensions as $extension) {
            if (!is_string($extension) || preg_match(self::ACCEPT_ITEM, $extension, $m) !== 1) {
                throw new InvalidArgumentException('An accepted extension is 1 to 10 lower-case letters or digits.');
            }
            $item = '.' . $m[1];
            if (!in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /** A non-negative int, or a string of 1 to 18 digits, as an int; else null. */
    private static function whole(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[0-9]{1,18}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
