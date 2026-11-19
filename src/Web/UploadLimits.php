<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * PHP's upload limits as the web layer needs them (M3 spec part 2 sections
 * 2.1 and 2.3): `upload_max_filesize` and `post_max_size`, each in bytes or
 * null when PHP sets no limit (parse()).
 *
 * Upload screens state effective(), the smallest of the core's own cap
 * (IoLimits::MAX_UPLOAD_BYTES, which the core screen objects report as
 * `maxBytes`) and the two PHP limits, so the size limit is shown before
 * anything is uploaded. The kernel refuses a POST larger than
 * postMaxBytes() with the 413 page (Kernel step 9).
 *
 * Built from ini values by fromIni() (CoreServices) or with explicit
 * figures (tests).
 */
final class UploadLimits
{
    public function __construct(
        public readonly ?int $uploadMaxFilesize,
        public readonly ?int $postMaxSize,
    ) {
        if (($uploadMaxFilesize !== null && $uploadMaxFilesize < 1) || ($postMaxSize !== null && $postMaxSize < 1)) {
            throw new InvalidArgumentException('An upload limit is a positive number of bytes or null.');
        }
    }

    /** The limits of the running PHP. */
    public static function fromIni(): self
    {
        return new self(self::parse(ini_get('upload_max_filesize')), self::parse(ini_get('post_max_size')));
    }

    /**
     * An ini quantity in bytes, read by PHP's own rules (ini_parse_quantity,
     * the parser PHP uses for upload_max_filesize and post_max_size): `2M`
     * is 2,097,152, `512K` 524,288, `1G` 1,073,741,824, plain digits are
     * bytes, `0x10` is 16 and `010` is 8, and a malformed value is read as
     * PHP reads it (`1.5M` is 1 MB, `1MB` is 1 byte). Null when PHP sets no
     * limit: `0`, a negative value such as `-1`, an empty or absent setting,
     * or a value with no leading digits (PHP reads those as 0). A value out
     * of range becomes PHP_INT_MAX.
     */
    public static function parse(string|false $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $bytes = ini_parse_quantity($value);
        } catch (\ValueError) {
            return null;
        } finally {
            restore_error_handler();
        }
        if (str_contains($warning, 'out of range')) {
            return PHP_INT_MAX;
        }

        return $bytes > 0 ? $bytes : null;
    }

    /** The request body size above which the kernel answers 413, or null for no check. */
    public function postMaxBytes(): ?int
    {
        return $this->postMaxSize;
    }

    /**
     * The largest file an upload form accepts, in bytes: the smallest of
     * $coreMaxBytes (the core screen object's `maxBytes`; null to leave it
     * out, as the 413 page does) and the two PHP limits. PHP_INT_MAX when
     * none of them sets a limit.
     */
    public function effective(?int $coreMaxBytes = null): int
    {
        if ($coreMaxBytes !== null && $coreMaxBytes < 1) {
            throw new InvalidArgumentException('The core upload limit is a positive number of bytes.');
        }
        $limits = array_filter([$coreMaxBytes, $this->uploadMaxFilesize, $this->postMaxSize], static fn (?int $n): bool => $n !== null);

        return $limits === [] ? PHP_INT_MAX : min($limits);
    }
}
