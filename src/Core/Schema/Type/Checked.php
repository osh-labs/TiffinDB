<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

/**
 * The result of checking or parsing one value (M1 spec section 8.1): either
 * the canonical value (null for empty) or one problem key with parameters.
 * The caller adds the input name when it turns the problem into a Problem.
 */
final class Checked
{
    /** @param array<string, string|int> $params */
    private function __construct(
        private readonly bool $ok,
        private readonly mixed $value,
        private readonly ?string $key,
        private readonly array $params,
    ) {
    }

    /** A canonical value, or null for empty. */
    public static function ok(mixed $value): self
    {
        return new self(true, $value, null, []);
    }

    /** @param array<string, string|int> $params */
    public static function problem(string $key, array $params = []): self
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A problem needs a message key.');
        }

        return new self(false, null, $key, $params);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    /** @throws \LogicException when this is a problem */
    public function value(): mixed
    {
        if (!$this->ok) {
            throw new \LogicException('A refused value has no canonical value.');
        }

        return $this->value;
    }

    public function problemKey(): ?string
    {
        return $this->key;
    }

    /** @return array<string, string|int> */
    public function problemParams(): array
    {
        return $this->params;
    }
}
