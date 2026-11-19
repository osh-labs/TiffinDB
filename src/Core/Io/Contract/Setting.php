<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/**
 * One reading or export option (M3 spec section 3.1). Kind `choice` holds
 * one of its choices' values; kind `boolean` holds '1' or '0'. Labels and
 * help are plain text supplied by the plugin (P133).
 */
final class Setting
{
    public const NAME = '/^[a-z][a-z0-9_]{0,31}\z/';
    public const KINDS = ['choice', 'boolean'];

    /** @param list<SettingChoice> $choices kind 'choice' only */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $kind,
        public readonly string $value,
        public readonly array $choices = [],
        public readonly string $help = '',
    ) {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new \InvalidArgumentException('A setting name is a lower-case word of 1 to 32 characters.');
        }
        if (!Values::isText($label, 1, 80) || !Values::isText($help, 0, 200)) {
            throw new \InvalidArgumentException('A setting label is 1 to 80 characters and its help at most 200.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('A setting is a choice or a boolean.');
        }
        if (!array_is_list($choices)) {
            throw new \InvalidArgumentException('Choices are a list.');
        }
        $values = [];
        foreach ($choices as $choice) {
            if (!$choice instanceof SettingChoice || in_array($choice->value, $values, true)) {
                throw new \InvalidArgumentException('Choices are distinct SettingChoice values.');
            }
            $values[] = $choice->value;
        }
        if ($kind === 'boolean' && $choices !== []) {
            throw new \InvalidArgumentException('A boolean setting has no choices.');
        }
        if ($kind === 'choice' && $choices === []) {
            throw new \InvalidArgumentException('A choice setting has at least one choice.');
        }
        if (!$this->allows($value)) {
            throw new \InvalidArgumentException('The value is not allowed by the setting.');
        }
    }

    /** Whether $value is a value this setting may hold. */
    public function allows(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        if ($this->kind === 'boolean') {
            return $value === '1' || $value === '0';
        }
        foreach ($this->choices as $choice) {
            if ($choice->value === $value) {
                return true;
            }
        }

        return false;
    }

    /** A copy holding $value (which allows() must accept). */
    public function withValue(string $value): self
    {
        return new self($this->name, $this->label, $this->kind, $value, $this->choices, $this->help);
    }
}
