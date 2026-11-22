<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\Link;
use TiffinDB\Core\Forms\Paragraph;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Web\ViewScope;

/**
 * Review M5-R-WP1: every link address FormParser accepts must be one that
 * ViewScope::formLink() prints. The url rule allows `'`; formLink refuses
 * it, so an accepted, canonical form would make the record screen fail
 * for its table. Synthetic data only.
 */
#[CoversClass(FormParser::class)]
#[CoversClass(ViewScope::class)]
final class FormLinkContractTest extends TestCase
{
    public function testEveryAcceptedAddressIsPrintable(): void
    {
        $parser = new FormParser(FieldTypes::standard());
        $scope = (new \ReflectionClass(ViewScope::class))->newInstanceWithoutConstructor();
        foreach (["https://example.com/a'b", 'https://example.com/a&apos;b', 'https://example.com/?q=it&apos;s'] as $href) {
            $text = '<div data-tiffin-form="1"><p><a href="' . $href . '">x</a></p></div>';
            try {
                $document = $parser->parse($text);
            } catch (\TiffinDB\Core\Forms\FormRejected) {
                self::addToAssertionCount(1);

                continue;
            }
            $paragraph = $document->blocks[0];
            self::assertInstanceOf(Paragraph::class, $paragraph);
            $link = $paragraph->children[0];
            self::assertInstanceOf(Link::class, $link);
            try {
                $scope->formLink($link->href);
                self::addToAssertionCount(1);
            } catch (\InvalidArgumentException) {
                self::fail('The parser accepted a link address that formLink refuses: ' . $href);
            }
        }
    }
}
