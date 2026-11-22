<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/**
 * A staged upload after AttachmentStore::inspect (M4 spec section 3.2):
 * where it is, its SHA-256 (64 lower-case hex characters), its size in
 * bytes, and the type MediaType decided from its leading bytes. Made by
 * inspect; AttachmentStore::place checks every value again against the
 * staged file, so a hand-built or outdated upload is refused there.
 */
final class StoredUpload
{
    public function __construct(
        public readonly string $path,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $mediaType,
    ) {
        if (preg_match(AttachmentStore::HASH, $sha256) !== 1) {
            throw new \InvalidArgumentException('The hash must be 64 lower-case hex characters.');
        }
        if ($size < 0) {
            throw new \InvalidArgumentException('The size must not be negative.');
        }
    }
}
