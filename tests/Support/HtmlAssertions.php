<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Assert;

/**
 * Shared assertions for rendered pages (M0 spec section 15.2, T-WEB-8 to
 * T-WEB-10), so that later milestones apply them to every new page.
 *
 * Pages are HTML5 in XML-well-formed syntax (ADR 0008 decision 4), so they
 * are parsed with PHP's XML parser, offline (LIBXML_NONET).
 */
final class HtmlAssertions
{
    /** Stylesheets every page links, in this order (M0 spec section 8). */
    public const STYLESHEETS = ['css/fonts.css', 'css/tokens.css', 'css/app.css'];

    private function __construct()
    {
    }

    /**
     * T-WEB-10: starts with `<!DOCTYPE html>` and parses as XML with zero
     * libxml errors. Returns the parsed document.
     */
    public static function assertWellFormed(string $html): DOMDocument
    {
        Assert::assertStringStartsWith('<!DOCTYPE html>', $html, 'The page must start with <!DOCTYPE html>.');

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($html, LIBXML_NONET);
            $errors = array_map(
                static fn ($error): string => sprintf('line %d: %s', $error->line, trim($error->message)),
                libxml_get_errors(),
            );
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        Assert::assertSame([], $errors, 'The page must be XML-well-formed.');
        Assert::assertTrue($loaded, 'The page must parse.');

        return $document;
    }

    /**
     * T-WEB-9: no inline style or script, no event-handler attribute, no
     * `javascript:` address, and no http(s) URL other than the configured
     * source link.
     */
    public static function assertNoInlineCode(string $html, string $sourceUrl): void
    {
        $xpath = new DOMXPath(self::assertWellFormed($html));

        Assert::assertSame(0, $xpath->query('//*[local-name()="style"]')->length, 'No <style> element.');
        Assert::assertSame(0, $xpath->query('//*[local-name()="script"]')->length, 'No <script> element.');
        Assert::assertSame(0, $xpath->query('//@style')->length, 'No style attribute.');

        foreach ($xpath->query('//@*') as $attribute) {
            /** @var \DOMAttr $attribute */
            Assert::assertDoesNotMatchRegularExpression(
                '/^on/i',
                $attribute->nodeName,
                "No event-handler attribute ({$attribute->nodeName}).",
            );
            Assert::assertDoesNotMatchRegularExpression(
                '/^\s*javascript:/i',
                $attribute->nodeValue ?? '',
                'No javascript: address.',
            );
        }
        Assert::assertDoesNotMatchRegularExpression('/javascript:/i', $html, 'No javascript: anywhere.');

        preg_match_all('#https?://[^\s"\'<>]*#i', $html, $matches);
        foreach ($matches[0] as $url) {
            Assert::assertSame($sourceUrl, $url, 'The only external URL is the configured source link.');
        }
    }

    /**
     * T-WEB-8: landmarks, language, title, skip link, stylesheets, brand
     * link, and the footer with the licence line and the source link.
     *
     * @param string $licenceText The footer licence line, as plain text.
     * @param string $sourceText  The source link text, as plain text.
     */
    public static function assertShell(
        string $html,
        string $sourceUrl,
        string $licenceText,
        string $sourceText,
        string $basePath = '',
    ): void {
        $document = self::assertWellFormed($html);
        $xpath = new DOMXPath($document);

        $root = $document->documentElement;
        Assert::assertInstanceOf(DOMElement::class, $root);
        Assert::assertSame('html', $root->nodeName);
        Assert::assertSame('en', $root->getAttribute('lang'), 'html must carry lang="en".');

        foreach (['header', 'main', 'footer', 'h1'] as $element) {
            Assert::assertSame(1, $xpath->query("//{$element}")->length, "Exactly one <{$element}>.");
        }
        Assert::assertSame(1, $xpath->query('/html/head/meta[@charset="utf-8"]')->length, 'UTF-8 charset.');
        Assert::assertSame(1, $xpath->query('/html/head/meta[@name="viewport"]')->length, 'Viewport meta.');

        $titles = $xpath->query('/html/head/title');
        Assert::assertSame(1, $titles->length, 'Exactly one <title>.');
        Assert::assertNotSame('', trim((string) $titles->item(0)?->textContent), 'The title must not be empty.');

        // Skip link: the first element in body, pointing at an existing id.
        $first = $xpath->query('/html/body/*[1]')->item(0);
        Assert::assertInstanceOf(DOMElement::class, $first);
        Assert::assertSame('a', $first->nodeName, 'The skip link is the first element in body.');
        Assert::assertSame('skip-link', $first->getAttribute('class'));
        $href = $first->getAttribute('href');
        Assert::assertMatchesRegularExpression('/^#[A-Za-z][A-Za-z0-9_-]*$/', $href, 'The skip link targets an id.');
        Assert::assertNotSame('', trim($first->textContent), 'The skip link has text.');
        $target = $xpath->query('//*[@id="' . substr($href, 1) . '"]');
        Assert::assertSame(1, $target->length, 'The skip link target exists exactly once.');
        Assert::assertSame('main', $target->item(0)?->nodeName, 'The skip link targets <main>.');

        // Stylesheets: exactly three, in order, each an existing file under public/.
        $links = [];
        foreach ($xpath->query('/html/head/link[@rel="stylesheet"]') as $link) {
            /** @var DOMElement $link */
            $links[] = $link->getAttribute('href');
        }
        Assert::assertCount(count(self::STYLESHEETS), $links, 'Exactly three stylesheet links.');
        foreach (self::STYLESHEETS as $i => $file) {
            $prefix = $basePath . '/assets/' . $file . '?v=';
            Assert::assertStringStartsWith($prefix, $links[$i], "Stylesheet {$i} must be {$file}.");
            Assert::assertFileExists(RepoFiles::path('public/assets/' . $file));
        }

        // Brand link in the header, with an accessible name, to the start page.
        $brand = $xpath->query('//header//a[contains(concat(" ", @class, " "), " brand ")]');
        Assert::assertSame(1, $brand->length, 'One brand link in the header.');
        $brandLink = $brand->item(0);
        Assert::assertInstanceOf(DOMElement::class, $brandLink);
        Assert::assertNotSame('', trim($brandLink->getAttribute('aria-label')), 'The brand link has an accessible name.');
        Assert::assertSame($basePath . '/', $brandLink->getAttribute('href'), 'The brand link goes to the start page.');

        // Footer: licence line and the source link.
        $footer = $xpath->query('//footer')->item(0);
        Assert::assertInstanceOf(DOMElement::class, $footer);
        Assert::assertStringContainsString($licenceText, self::normalise($footer->textContent), 'Footer licence line.');
        $sourceLinks = [];
        foreach ($xpath->query('.//a', $footer) as $a) {
            /** @var DOMElement $a */
            if (self::normalise($a->textContent) === $sourceText) {
                $sourceLinks[] = $a->getAttribute('href');
            }
        }
        Assert::assertSame([$sourceUrl], $sourceLinks, 'The footer has one source link to the configured URL.');
    }

    /** The `class` attribute of the root element, or null when absent. */
    public static function rootClass(string $html): ?string
    {
        $root = self::assertWellFormed($html)->documentElement;
        Assert::assertInstanceOf(DOMElement::class, $root);

        return $root->hasAttribute('class') ? $root->getAttribute('class') : null;
    }

    /** Text content of the first element matching an XPath query, normalised. */
    public static function text(string $html, string $query): string
    {
        $node = (new DOMXPath(self::assertWellFormed($html)))->query($query)->item(0);
        Assert::assertNotNull($node, "Nothing matches {$query}.");

        return self::normalise($node->textContent);
    }

    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
