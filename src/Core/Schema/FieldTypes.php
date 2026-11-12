<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

use TiffinDB\Core\Schema\Type\AttachmentType;
use TiffinDB\Core\Schema\Type\AutoNumberType;
use TiffinDB\Core\Schema\Type\CheckboxType;
use TiffinDB\Core\Schema\Type\ChoiceType;
use TiffinDB\Core\Schema\Type\CurrencyType;
use TiffinDB\Core\Schema\Type\DateTimeType;
use TiffinDB\Core\Schema\Type\DateType;
use TiffinDB\Core\Schema\Type\DurationType;
use TiffinDB\Core\Schema\Type\EmailType;
use TiffinDB\Core\Schema\Type\FieldType;
use TiffinDB\Core\Schema\Type\LongTextType;
use TiffinDB\Core\Schema\Type\NumberType;
use TiffinDB\Core\Schema\Type\PhoneType;
use TiffinDB\Core\Schema\Type\RatingType;
use TiffinDB\Core\Schema\Type\RelationType;
use TiffinDB\Core\Schema\Type\TextType;
use TiffinDB\Core\Schema\Type\TimeType;
use TiffinDB\Core\Schema\Type\UrlType;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SecureRandom;

/** The field types known to this version (M1 spec sections 7.5 and 8). */
final class FieldTypes
{
    /** Types that convert to one another (section 8.4). */
    private const CONVERTIBLE = [
        'text', 'long_text', 'number', 'currency', 'date', 'time', 'date_time', 'duration',
        'checkbox', 'choice', 'rating', 'url', 'email', 'phone',
    ];

    /** @var array<string, FieldType> */
    private readonly array $types;

    /** @param list<FieldType> $types */
    private function __construct(array $types)
    {
        $byId = [];
        foreach ($types as $type) {
            $byId[$type->id()] = $type;
        }
        $this->types = $byId;
    }

    /** The 17 v1 types, in the order of section 8.2. Choice keys come from $keys (secure random by default). */
    public static function standard(?Keys $keys = null): self
    {
        return new self([
            new TextType(),
            new LongTextType(),
            new NumberType(),
            new CurrencyType(),
            new DateType(),
            new TimeType(),
            new DateTimeType(),
            new DurationType(),
            new CheckboxType(),
            new ChoiceType($keys ?? new Keys(new SecureRandom())),
            new RatingType(),
            new UrlType(),
            new EmailType(),
            new PhoneType(),
            new AttachmentType(),
            new RelationType(),
            new AutoNumberType(),
        ]);
    }

    /** @throws \InvalidArgumentException for an unknown id */
    public function get(string $id): FieldType
    {
        if (!isset($this->types[$id])) {
            throw new \InvalidArgumentException('Unknown field type.');
        }

        return $this->types[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->types[$id]);
    }

    /** @return list<string> type ids in the order of section 8.2 */
    public function ids(): array
    {
        return array_keys($this->types);
    }

    /** Section 8.4: whether a field of type $from may change to type $to. */
    public function canConvert(string $from, string $to): bool
    {
        if ($from === $to || !$this->has($from) || !$this->has($to)) {
            return false;
        }
        if (in_array($from, self::CONVERTIBLE, true) && in_array($to, self::CONVERTIBLE, true)) {
            return true;
        }

        return $from === 'auto_number' && ($to === 'number' || $to === 'text');
    }
}
