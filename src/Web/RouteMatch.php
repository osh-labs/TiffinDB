<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

/**
 * The result of Router::match(): found (with handler and parameters), not
 * found, or method not allowed (with the allowed methods).
 */
final class RouteMatch
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not-found';
    public const METHOD_NOT_ALLOWED = 'method-not-allowed';

    /**
     * @param array{0: class-string, 1: string}|null $handler
     * @param array<string, string>                  $params
     * @param list<string>                           $allowedMethods
     */
    private function __construct(
        public readonly string $result,
        public readonly ?array $handler = null,
        public readonly array $params = [],
        public readonly array $allowedMethods = [],
    ) {
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, string>             $params
     */
    public static function found(array $handler, array $params): self
    {
        return new self(self::FOUND, $handler, $params);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /** @param list<string> $allowedMethods */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(self::METHOD_NOT_ALLOWED, null, [], $allowedMethods);
    }

    public function isFound(): bool
    {
        return $this->result === self::FOUND;
    }

    public function isNotFound(): bool
    {
        return $this->result === self::NOT_FOUND;
    }

    public function isMethodNotAllowed(): bool
    {
        return $this->result === self::METHOD_NOT_ALLOWED;
    }
}
