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
     * The only script a page may load (M1 spec part 2 sections 4.1 and 6):
     * a same-origin file under the assets directory, after an optional base
     * path, with the version parameter.
     */
    private const SCRIPT_SRC = '#^(?:/[^/\\\\\s"\'<>?\#]+)*/assets/js/[a-z0-9][a-z0-9-]*\.js\?v=[A-Za-z0-9._~%-]+$#';

    /**
     * T-WEB-9 and T-WEB-22: no inline style or script, no event-handler
     * attribute, no `javascript:` address, and no http(s) URL other than the
     * configured source link. At most one `script` element is allowed: in
     * `head`, with a same-origin `src` under `/assets/js/`, `defer`, no other
     * attribute, and no content.
     */
    public static function assertNoInlineCode(string $html, string $sourceUrl): void
    {
        $xpath = new DOMXPath(self::assertWellFormed($html));

        Assert::assertSame(0, $xpath->query('//*[local-name()="style"]')->length, 'No <style> element.');
        $scripts = $xpath->query('//*[local-name()="script"]');
        Assert::assertLessThanOrEqual(1, $scripts->length, 'At most one <script> element.');
        foreach ($scripts as $script) {
            /** @var DOMElement $script */
            Assert::assertSame('head', $script->parentNode?->nodeName, 'The script is in <head>.');
            Assert::assertMatchesRegularExpression(self::SCRIPT_SRC, $script->getAttribute('src'), 'The script is a same-origin file under /assets/js/.');
            Assert::assertSame('defer', $script->getAttribute('defer'), 'The script is deferred.');
            Assert::assertSame(2, $script->attributes->length, 'The script has only src and defer.');
            Assert::assertSame(0, $script->childNodes->length, 'The script element has no content.');
        }
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
            Assert::assertDoesNotMatchRegularExpression(
                '#^\s*[/\\\\]{2}#',
                $attribute->nodeValue ?? '',
                "No protocol-relative address ({$attribute->nodeName}).",
            );
        }
        Assert::assertDoesNotMatchRegularExpression('/javascript:/i', $html, 'No javascript: anywhere.');
        Assert::assertDoesNotMatchRegularExpression('#["\'=]\s*//#', $html, 'No protocol-relative URL.');

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

    /**
     * T-WEB-22: M1 spec part 2 section 4.5 items 1, 2, and 6 on every form
     * in a page.
     *
     * 1. `method` is `post` (or `get` for a form that changes nothing, which
     *    then carries no CSRF field); `action` is a same-origin path; no
     *    `novalidate`; a POST form's first child is the CSRF field
     *    (`input type="hidden" name="_csrf"` with a non-empty value).
     * 2. Every text-like control (input other than hidden, checkbox, radio,
     *    and buttons; select; textarea) has an id, sits in a `div.field`
     *    with a `label.label` whose `for` names it; every id in its
     *    `aria-describedby` exists; when one of them is a `.error-text`, the
     *    control has `aria-invalid="true"`, and `aria-invalid="true"` is
     *    never set without a described error.
     * 6. Every checkbox and radio has a name from a wrapping `label`, a
     *    `label for`, or `aria-label`; ids in the page are unique.
     *
     * Also: every button has a type and a name (text or `aria-label`).
     */
    public static function assertForm(string $html): void
    {
        $document = self::assertWellFormed($html);
        $xpath = new DOMXPath($document);

        $ids = [];
        foreach ($xpath->query('//@id') as $id) {
            $ids[] = (string) $id->nodeValue;
        }
        Assert::assertSame(array_values(array_unique($ids)), $ids, 'Element ids are unique.');

        foreach ($xpath->query('//form') as $form) {
            /** @var DOMElement $form */
            $method = $form->getAttribute('method');
            Assert::assertContains($method, ['post', 'get'], 'A form has method="post" (or "get" when it changes nothing).');
            Assert::assertMatchesRegularExpression('#^/(?![/\\\\])[^\s"\'<>\\\\]*$#', $form->getAttribute('action'), 'A form posts to a same-origin path.');
            Assert::assertFalse($form->hasAttribute('novalidate'), 'Forms do not set novalidate.');

            $csrf = $xpath->query('.//input[@name="_csrf"]', $form);
            if ($method === 'post') {
                $first = $xpath->query('./*[1]', $form)->item(0);
                Assert::assertInstanceOf(DOMElement::class, $first, 'A POST form has content.');
                Assert::assertSame('input', $first->nodeName, 'The CSRF field is the first child of a POST form.');
                Assert::assertSame('hidden', $first->getAttribute('type'), 'The CSRF field is hidden.');
                Assert::assertSame('_csrf', $first->getAttribute('name'), 'The first child of a POST form is the CSRF field.');
                Assert::assertNotSame('', $first->getAttribute('value'), 'The CSRF field has a value.');
                Assert::assertSame(1, $csrf->length, 'A POST form has one CSRF field.');
            } else {
                Assert::assertSame(0, $csrf->length, 'A GET form never carries the CSRF token.');
            }

            foreach ($xpath->query('.//input | .//select | .//textarea', $form) as $control) {
                /** @var DOMElement $control */
                self::assertControl($xpath, $control);
            }
            foreach ($xpath->query('.//button', $form) as $button) {
                /** @var DOMElement $button */
                Assert::assertContains($button->getAttribute('type'), ['submit', 'button'], 'A button has a type.');
                Assert::assertTrue(
                    self::normalise($button->textContent) !== '' || trim($button->getAttribute('aria-label')) !== '',
                    'A button has a name.',
                );
            }
        }
    }

    private static function assertControl(DOMXPath $xpath, DOMElement $control): void
    {
        $type = $control->nodeName === 'input' ? ($control->getAttribute('type') ?: 'text') : $control->nodeName;
        if (in_array($type, ['hidden', 'submit', 'button', 'reset', 'image'], true)) {
            return;
        }
        $id = $control->getAttribute('id');
        $what = "The {$type} control" . ($id === '' ? '' : " #{$id}");

        if (in_array($type, ['checkbox', 'radio'], true)) {
            $named = $xpath->query('ancestor::label', $control)->length > 0
                || trim($control->getAttribute('aria-label')) !== ''
                || ($id !== '' && $xpath->query('//label[@for="' . $id . '"]')->length === 1);
            Assert::assertTrue($named, "{$what} has a wrapping label, a label for it, or an aria-label.");
        } else {
            Assert::assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9_-]*$/', $id, "{$what} has an id.");
            $field = $control->parentNode;
            Assert::assertInstanceOf(DOMElement::class, $field);
            Assert::assertSame('div', $field->nodeName, "{$what} sits in a div.field.");
            Assert::assertContains('field', explode(' ', $field->getAttribute('class')), "{$what} sits in a div.field.");
            $labels = $xpath->query('./label[@for="' . $id . '"]', $field);
            Assert::assertSame(1, $labels->length, "{$what} has a label in its div.field.");
            $label = $labels->item(0);
            Assert::assertInstanceOf(DOMElement::class, $label);
            Assert::assertContains('label', explode(' ', $label->getAttribute('class')), "{$what} has a label.label.");
            Assert::assertNotSame('', self::normalise($label->textContent), "{$what} has a label with text.");
        }

        $describedBy = trim($control->getAttribute('aria-describedby'));
        $describedError = false;
        foreach ($describedBy === '' ? [] : preg_split('/\s+/', $describedBy) as $ref) {
            $target = $xpath->query('//*[@id="' . $ref . '"]')->item(0);
            Assert::assertInstanceOf(DOMElement::class, $target, "{$what}: aria-describedby names an existing id ({$ref}).");
            if (in_array('error-text', explode(' ', $target->getAttribute('class')), true)) {
                $describedError = true;
            }
        }
        if ($describedError) {
            Assert::assertSame('true', $control->getAttribute('aria-invalid'), "{$what} has an error, so aria-invalid=\"true\".");
        }
        if ($control->getAttribute('aria-invalid') === 'true') {
            Assert::assertTrue($describedError, "{$what} is marked invalid, so it is described by its .error-text.");
        }
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
