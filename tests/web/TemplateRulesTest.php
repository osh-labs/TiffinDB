<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Web\Ids;

/**
 * T-WEB-11 and T-WEB-26: the escaping rule and the other template source
 * rules (ADR 0008 decisions 4 and 5; M0 spec sections 6.8 and 15.2; M1
 * spec part 2 section 4.3; M2 spec part 2 section 4.3).
 *
 * The scanner works on PHP tokens and on the static markup as a whole:
 *
 * - Every `<?=` is exactly one call to a documented scope helper,
 *   `$this->name(...)`, followed only by an optional `;` and `?>`. The
 *   layout-only accessors are allowed in `templates/layout.php` only.
 * - `$this` may be used only to call those helpers: no other method (such
 *   as the internal setSlot() or run()), no property.
 * - Default deny for `(`: it may follow only an allowlisted pure function
 *   name, a `$this->helper` name, a control keyword, or an operator. So no
 *   call of a variable, string, array element, or result. Also no echo,
 *   print, include, eval, exit, closure, class, new, static access,
 *   variable variable, superglobal, backtick, or pipe operator (`|>`).
 * - The static markup, with every PHP block removed (so markup split by an
 *   empty PHP block is seen whole), has no inline style or script, no
 *   event-handler attribute, no `javascript:`, external, protocol-relative
 *   (`//`), or slash-backslash URL, and no character reference or scheme
 *   at the start of a URL attribute; no `<meta>` and no element of
 *   FORBIDDEN_ELEMENTS (iframe, object, embed, base, SVG animation, and
 *   the like). The exceptions are in the layout's `<head>` only: its two
 *   fixed meta elements (LAYOUT_METAS) and the three script elements,
 *   each at most once and exactly as in SCRIPT_ELEMENTS. The only
 *   `enctype` is the literal `enctype="multipart/form-data"` on a `form`
 *   start tag (M3 spec part 2 section 5.2); no `formenctype`.
 * - Each `<?=` sits in element text, or is the whole value of a
 *   double-quoted attribute (not `class`, `id`, `style`, `on*`, or
 *   NO_OUTPUT_ATTRIBUTES such as `srcdoc` and SVG `to`, `from`, `values`,
 *   `attributeName`; in a URL attribute only through url() or asset(), or
 *   e() for the footer's configured source link). csrf() only in the csrf
 *   partial, as the whole value of a `value` attribute; count() only in element text or `aria-label`.
 *   value() and inputValue() only in element text or as the whole value of
 *   `value`. id() only as the whole value of `id`, `for`, `form`, or
 *   `aria-labelledby`, or in `aria-describedby` as one or more id()
 *   separated by single literal spaces; those attributes take no other
 *   output. fieldFacts() returns data and is never printed. The layout
 *   accessor may sit in the `<html>` start tag. Never in a comment or
 *   elsewhere in a tag.
 * - Every literal string key passed to t(), or as a key parameter to a
 *   partial, exists. Every key in the strings file is used: literally in
 *   t() or count(), as a literal key parameter of a partial (the
 *   parameters each partial passes to t(), partialKeyParameters()), or as
 *   a whole string literal in a controller (breadcrumb labels); keys code
 *   builds at run time need a declared prefix (RUNTIME_PREFIXES).
 */
#[CoversNothing]
final class TemplateRulesTest extends TestCase
{
    /** Helpers any template may call (ADR 0008 decision 4; M2 spec part 2 sections 4.2 and 5.2). */
    public const HELPERS = ['e', 't', 'url', 'asset', 'partial', 'slot', 'csrf', 'count', 'number', 'value', 'inputValue', 'id', 'fieldFacts'];

    /** Helpers that return data for decisions, never markup: not allowed after `<?=`. */
    public const DATA_HELPERS = ['fieldFacts'];

    /** Attributes whose whole value may be one id() (P96). */
    public const ID_ATTRIBUTES = ['id', 'for', 'form', 'aria-labelledby'];

    /** The attribute whose value may be several id() separated by single spaces. */
    public const ID_LIST_ATTRIBUTE = 'aria-describedby';

    /** Accessors only the layout may call, each returning markup built from server-side constants. */
    public const LAYOUT_ACCESSORS = ['rootClassAttribute'];

    public const LAYOUT = 'templates/layout.php';

    /** The one template that prints a configured (not generated) URL, with e(). */
    public const FOOTER = 'templates/partials/footer.php';

    /** The one template that may call csrf(). */
    public const CSRF_PARTIAL = 'templates/partials/csrf.php';

    /** The layout's fixed meta elements: the only meta elements any template may hold. */
    public const LAYOUT_METAS = [
        '<meta charset="utf-8" />',
        '<meta name="viewport" content="width=device-width, initial-scale=1" />',
    ];

    /** Elements no template may contain (they load, embed, redirect, or rewrite attributes). */
    private const FORBIDDEN_ELEMENTS = [
        'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'portal',
        'animate', 'animatemotion', 'animatetransform', 'set', 'foreignobject', 'handler', 'listener',
    ];

    /** Attributes that never take output: they hold markup or name or set other attributes. */
    private const NO_OUTPUT_ATTRIBUTES = ['srcdoc', 'to', 'from', 'values', 'by', 'attributename', 'http-equiv'];

    /** Plain functions a template may call: pure checks that print nothing. */
    public const ALLOWED_FUNCTIONS = ['is_array', 'is_string', 'is_int', 'is_bool', 'count', 'array_key_exists', 'in_array'];

    /**
     * Prefixes of string keys built at run time, which need not appear
     * literally in a template (M1 spec part 2 section 4.3).
     */
    public const RUNTIME_PREFIXES = [
        'problem.', 'error.', 'type.', 'setup.check.', 'notice.', 'wait.', 'date.', 'count.', 'role.',
        'currency.', 'file.type.', 'size.', 'sort.', 'lists.cond.', 'settings.theme.', 'settings.accent.',
        'settings.size.', 'settings.spacing.', 'settings.face.',
        // M3 spec part 2 section 6 (P141).
        'import.warning.', 'import.issue.', 'import.type.', 'plugins.status.', 'problem.archive.', 'steps.import.',
    ];

    /**
     * Shared keys (strings/en/io.php and the other M3-WP3 strings) that only
     * the import screens of M3-WP9 print (M3 spec part 2 section 6, P141).
     * WP9 empties this list; nothing else changes these lines.
     */
    public const PENDING_IMPORT = [
    ];

    /**
     * Shared keys that only the export, archive, and plugins screens of
     * M3-WP10 print (P141). WP10 empties this list; nothing else changes
     * these lines.
     */
    public const PENDING_EXPORT = [
    ];

    /**
     * Keys that count as used until the templates that print them land;
     * testPendingKeysAreStillPending fails once a template uses one, so the
     * entry is then removed.
     */
    public const PENDING_KEYS = [...self::PENDING_IMPORT, ...self::PENDING_EXPORT];

    /** The show-password script element (M1 spec part 2 section 4.1). */
    public const SCRIPT_ELEMENT = '<script src="<?= $this->asset(\'js/show-password.js\') ?>" defer="defer"></script>';

    /** The script elements allowed in the layout's head, each at most once (M2 spec part 2 section 3.3; M3 spec part 2 section 7). */
    public const SCRIPT_ELEMENTS = [
        self::SCRIPT_ELEMENT,
        '<script src="<?= $this->asset(\'js/settings-preview.js\') ?>" defer="defer"></script>',
        '<script src="<?= $this->asset(\'js/import-continue.js\') ?>" defer="defer"></script>',
    ];

    /** The one enctype a template may write, on a form start tag (M3 spec part 2 section 5.2). */
    public const UPLOAD_ENCTYPE = 'enctype="multipart/form-data"';

    /** Templates that write the wordmark as live text (ADR 0008 decision 5). */
    public const WORDMARK_TEMPLATES = ['templates/partials/top-bar.php', 'templates/partials/lockup.php'];

    /** The template whose separators are a literal "/" (hidden from assistive technology). */
    public const BREADCRUMB = 'templates/partials/breadcrumb.php';

    /** Token kinds that are never allowed in a template. */
    private const FORBIDDEN_TOKENS = [
        T_ECHO => 'echo',
        T_PRINT => 'print',
        T_EVAL => 'eval',
        T_EXIT => 'exit or die',
        T_INCLUDE => 'include',
        T_INCLUDE_ONCE => 'include_once',
        T_REQUIRE => 'require',
        T_REQUIRE_ONCE => 'require_once',
        T_FUNCTION => 'a function or closure',
        T_FN => 'an arrow function',
        T_CLASS => 'a class',
        T_NEW => 'new',
        T_GLOBAL => 'global',
        T_STATIC => 'static',
        T_DOUBLE_COLON => 'a static access (::)',
        T_GOTO => 'goto',
        T_UNSET => 'unset',
        T_HALT_COMPILER => '__halt_compiler',
        T_NULLSAFE_OBJECT_OPERATOR => 'the ?-> operator',
        T_DOLLAR_OPEN_CURLY_BRACES => 'a ${ } expression',
        T_CURLY_OPEN => 'a {$ } expression',
        T_START_HEREDOC => 'a heredoc or nowdoc',
    ];

    private const SUPERGLOBALS = ['$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_COOKIE', '$_FILES', '$_ENV', '$_REQUEST', '$_SESSION'];

    /** Attributes whose value is a URL. */
    private const URL_ATTRIBUTES = [
        'href', 'src', 'srcset', 'action', 'formaction', 'xlink:href', 'poster', 'data', 'cite', 'background', 'ping', 'manifest',
    ];

    /** Keywords a `(` may follow. */
    private const PAREN_KEYWORDS = [T_DECLARE, T_IF,T_ELSEIF, T_FOREACH, T_FOR, T_WHILE, T_SWITCH, T_ISSET, T_EMPTY, T_ARRAY];

    /** Punctuation a grouping `(` may follow. */
    private const PAREN_PUNCTUATION = ['(', ',', '[', '=', '!', '.', '?', ':', ';', '<', '>', '+', '-', '*', '/', '%', '|', '&', '^', '~'];

    /** Operator tokens a grouping `(` may follow. */
    private const PAREN_OPERATORS = [
        T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
        T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL,
        T_COALESCE, T_DOUBLE_ARROW, T_AS, T_OPEN_TAG,
    ];

    /** @return list<string> */
    private static function templates(): array
    {
        return RepoFiles::withExtension(['php'], 'templates');
    }

    /** @return array<string, string> */
    private static function strings(): array
    {
        $strings = require RepoFiles::path('strings/en.php');
        self::assertIsArray($strings);

        return $strings;
    }

    /**
     * Rule violations in one template's source.
     *
     * @param string $file Repository-relative path; decides where the layout
     *                     accessors and the footer's e() link are allowed.
     * @return list<string>
     */
    public static function violations(string $source, string $file): array
    {
        $problems = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        $allowedMethods = $file === self::LAYOUT ? [...self::HELPERS, ...self::LAYOUT_ACCESSORS] : self::HELPERS;
        if ($file !== self::CSRF_PARTIAL) {
            $allowedMethods = array_values(array_diff($allowedMethods, ['csrf']));
        }

        // Header: SPDX block first, then strict types, then markup.
        if (preg_match('#\A<\?php\n/\*\n \* SPDX-License-Identifier: AGPL-3\.0-or-later\n \* Copyright \(C\) TiffinDB contributors\n#', $source) !== 1) {
            $problems[] = 'The file must start with <?php and the SPDX block.';
        }
        if (preg_match('#\*/\s*declare\(strict_types=1\);\s*\?>#', $source) !== 1) {
            $problems[] = 'The SPDX block must be followed by declare(strict_types=1); and ?>.';
        }

        // The static markup with every PHP block removed, and where each
        // <?= sits in it: [offset, helper name, line note].
        $html = '';
        $echoes = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            $line = is_array($token) ? " (line {$token[2]})" : '';

            if ($id !== null && isset(self::FORBIDDEN_TOKENS[$id])) {
                $problems[] = 'Forbidden: ' . self::FORBIDDEN_TOKENS[$id] . $line . '.';
            }
            if ($text === '`') {
                $problems[] = 'Forbidden: backtick execution.';
            }
            if (self::isPipe($tokens, $i)) {
                $problems[] = 'Forbidden: the pipe operator |>.';
            }
            if ($text === '$') {
                $problems[] = 'Forbidden: a variable variable.';
            }
            if ($id === T_VARIABLE && in_array($text, self::SUPERGLOBALS, true)) {
                $problems[] = "Forbidden: the superglobal {$text}{$line}.";
            }
            if ($id === T_VARIABLE && $text === '$this') {
                $problem = self::thisUseProblem($tokens, $i, $allowedMethods);
                if ($problem !== null) {
                    $problems[] = $problem . $line . '.';
                }
            }
            if ($id === T_OBJECT_OPERATOR && self::previousCode($tokens, $i) !== '$this') {
                $problems[] = "Forbidden: -> on anything but \$this{$line}.";
            }
            if ($text === '(') {
                $problem = self::parenthesisProblem($tokens, $i);
                if ($problem !== null) {
                    $problems[] = $problem . '.';
                }
            }
            if ($id === T_OPEN_TAG_WITH_ECHO) {
                $problem = self::echoProblem($tokens, $i, $allowedMethods);
                if ($problem !== null) {
                    $problems[] = $problem . $line . '.';
                }
                $echoes[] = [strlen($html), self::echoHelper($tokens, $i), $line];
            }
            if ($id === T_INLINE_HTML) {
                $html .= $text;
            }
        }

        array_push($problems, ...self::markupProblems(self::withoutLayoutHead($source, $html, $file)));
        array_push($problems, ...self::outputContextProblems($html, $echoes, $file));

        return $problems;
    }

    /**
     * The pipe operator: one T_PIPE token where the tokenizer has it (PHP
     * 8.5), or `|` immediately followed by `>` before that.
     *
     * @param list<mixed> $tokens
     */
    private static function isPipe(array $tokens, int $i): bool
    {
        if (defined('T_PIPE') && self::is($tokens[$i], (int) constant('T_PIPE'))) {
            return true;
        }

        return $tokens[$i] === '|' && ($tokens[$i + 1] ?? null) === '>';
    }

    /**
     * The static markup with what only the layout's head may hold removed:
     *
     * - each fixed meta element (LAYOUT_METAS) found inside the one
     *   `<head>`...`</head>`, exactly as written (so it holds no output);
     * - the script elements, when the source holds each of SCRIPT_ELEMENTS
     *   at most once, exactly as written, with no other script, and they
     *   sit inside that head.
     *
     * Anything else is left as it is, so markupProblems() reports it.
     */
    private static function withoutLayoutHead(string $source, string $html, string $file): string
    {
        if ($file !== self::LAYOUT || substr_count($html, '<head>') !== 1 || substr_count($html, '</head>') !== 1) {
            return $html;
        }
        $start = (int) strpos($html, '<head>');
        $end = (int) strpos($html, '</head>');
        if ($end < $start) {
            return $html;
        }
        $head = substr($html, $start, $end - $start);

        foreach (self::LAYOUT_METAS as $meta) {
            if (substr_count($head, $meta) === 1) {
                $head = str_replace($meta, '', $head);
            }
        }

        $stripped = '<script src="" defer="defer"></script>';
        $present = 0;
        $once = true;
        foreach (self::SCRIPT_ELEMENTS as $element) {
            $n = substr_count($source, $element);
            $present += $n;
            $once = $once && $n <= 1;
        }
        if (
            $present > 0
            && $once
            && preg_match_all('/<\s*\/?\s*script\b/i', $source) === 2 * $present
            && substr_count($html, $stripped) === $present
            && substr_count($head, $stripped) === $present
        ) {
            $head = str_replace($stripped, '', $head);
        }

        return substr($html, 0, $start) . $head . substr($html, $end);
    }

    /**
     * Default deny for `(`. It may follow an allowlisted function name, a
     * `$this->helper` name (checked by thisUseProblem; `->` on anything
     * else is rejected separately), a control keyword, or an operator
     * (grouping). After anything else it is a dynamic call.
     *
     * @param list<mixed> $tokens
     */
    private static function parenthesisProblem(array $tokens, int $i): ?string
    {
        $p = self::previousCodeIndex($tokens, $i);
        if ($p === null) {
            return 'Forbidden: ( with nothing before it';
        }
        $prev = $tokens[$p];

        if (is_array($prev) && in_array($prev[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true)) {
            $before = self::previousCode($tokens, $p);
            if ($before === '->' && $prev[0] === T_STRING) {
                return null;
            }
            if ($before === '::') {
                return 'Forbidden: a static call';
            }
            $name = strtolower(ltrim($prev[1], '\\'));

            return in_array($name, self::ALLOWED_FUNCTIONS, true) ? null : "Forbidden function call: {$prev[1]}()";
        }
        if (is_array($prev) && (in_array($prev[0], self::PAREN_KEYWORDS, true) || in_array($prev[0], self::PAREN_OPERATORS, true))) {
            return null;
        }
        if (!is_array($prev) && in_array($prev, self::PAREN_PUNCTUATION, true)) {
            return null;
        }

        $what = is_array($prev) ? token_name($prev[0]) : $prev;

        return "Forbidden: ( after {$what} (a dynamic call)";
    }

    /**
     * Rules over the whole static markup of a template.
     *
     * @return list<string>
     */
    public static function markupProblems(string $html): array
    {
        $rules = [
            '/<\s*\/?\s*script\b/i' => 'a <script> element',
            '/<\s*\/?\s*style\b/i' => 'a <style> element',
            '#[\s"\'/]style\s*=#i' => 'a style attribute',
            '#[\s"\'/]on[a-z]+\s*=#i' => 'an event-handler attribute',
            '/javascript\s*:/i' => 'a javascript: address',
            '#https?:#i' => 'an external URL',
            '#["\'=]\s*//#' => 'a protocol-relative URL',
            '#["\'=]\s*/[\t\n\r]*[/\\\\]#' => 'a slash-backslash or protocol-relative URL',
            '#["\'=]\s*\\\\#' => 'a backslash-relative URL',
            '/<\?/' => 'a short open tag or a stray <?',
            '/<\s*\/?\s*meta\b/i' => 'a <meta> element other than the layout\'s fixed ones',
            '/<\s*\/?\s*(?:' . implode('|', self::FORBIDDEN_ELEMENTS) . ')\b/i' => 'a forbidden element (' . implode(', ', self::FORBIDDEN_ELEMENTS) . ')',
        ];
        $problems = [];
        foreach ($rules as $pattern => $what) {
            if (preg_match($pattern, $html) === 1) {
                $problems[] = "Markup contains {$what}.";
            }
        }

        // enctype: the literal UPLOAD_ENCTYPE once in a form start tag, and
        // nowhere else (no other value, element, or formenctype).
        $withoutUploadForms = (string) preg_replace_callback(
            '/<form\b[^>]*>/',
            static fn (array $tag): string => (string) preg_replace('/(?<=\s)' . preg_quote(self::UPLOAD_ENCTYPE, '/') . '(?=[\s\/>])/', '', $tag[0], 1),
            $html,
        );
        if (preg_match('/enctype/i', $withoutUploadForms) === 1) {
            $problems[] = 'Markup contains an enctype other than ' . self::UPLOAD_ENCTYPE . ' on a form.';
        }

        // URL attributes: no character reference and no scheme before the
        // first / ? or #, so "&#106;avascript:" cannot hide a scheme.
        $names = implode('|', array_map(static fn (string $n): string => preg_quote($n, '#'), self::URL_ATTRIBUTES));
        if (preg_match_all('#[\s"\'/](?:' . $names . ')\s*=\s*["\']?([^"\'\s>]*)#i', $html, $m) > 0) {
            foreach ($m[1] as $value) {
                $head = preg_split('#[/?\#]#', $value, 2)[0] ?? '';
                if (str_contains((string) $head, '&') || str_contains((string) $head, ':')) {
                    $problems[] = "Markup has a URL attribute starting with a character reference or scheme ({$value}).";
                }
            }
        }

        return $problems;
    }

    /**
     * Checks where each <?= sits in the static markup (see the class
     * comment). A small state machine: text, comment, tag, and attribute
     * values (double-quoted, single-quoted, unquoted).
     *
     * @param list<array{int, string, string}> $echoes [offset, helper, line note]
     * @return list<string>
     */
    public static function outputContextProblems(string $html, array $echoes, string $file): array
    {
        $byOffset = [];
        foreach ($echoes as $echo) {
            $byOffset[$echo[0]][] = $echo;
        }

        $problems = [];
        $length = strlen($html);
        $state = 'text';
        $tagName = '';
        $attribute = '';
        $valueStart = -1;

        for ($pos = 0; $pos <= $length; $pos++) {
            foreach ($byOffset[$pos] ?? [] as $n => [, $helper, $line]) {
                $where = match ($state) {
                    'text' => match (true) {
                        in_array($helper, self::LAYOUT_ACCESSORS, true) => 'a layout accessor outside the <html> start tag',
                        $helper === 'csrf' => 'csrf() output in element text',
                        $helper === 'id' => 'id() output in element text',
                        default => null,
                    },
                    'comment' => 'output inside a comment',
                    'tag' => $file === self::LAYOUT && $tagName === 'html' && $n === 0
                        && in_array($helper, self::LAYOUT_ACCESSORS, true)
                        ? null : 'output inside a tag, outside an attribute value',
                    'dq' => self::attributeOutputProblem($html, $pos, $valueStart, $n, $attribute, $helper, $file, $byOffset),
                    default => 'output in a single-quoted or unquoted attribute value',
                };
                if ($where !== null) {
                    $problems[] = "Forbidden: {$where}{$line}.";
                }
            }
            if ($pos === $length) {
                break;
            }

            $char = $html[$pos];
            if ($state === 'text') {
                if (substr($html, $pos, 4) === '<!--') {
                    $state = 'comment';
                    $pos += 3;
                } elseif (preg_match('#\G<(/?)([A-Za-z!][A-Za-z0-9-]*)#', $html, $tm, 0, $pos) === 1) {
                    $state = 'tag';
                    $tagName = strtolower($tm[2]);
                    $attribute = '';
                    $pos += strlen($tm[0]) - 1;
                }
            } elseif ($state === 'comment') {
                if (substr($html, $pos, 3) === '-->') {
                    $state = 'text';
                    $pos += 2;
                }
            } elseif ($state === 'tag') {
                if ($char === '>') {
                    $state = 'text';
                } elseif ($char === '"') {
                    $state = 'dq';
                    $valueStart = $pos + 1;
                } elseif ($char === "'") {
                    $state = 'sq';
                } elseif ($char === '=' && preg_match('#\G=\s*(?=[^\s"\'>])#', $html, $um, 0, $pos) === 1) {
                    $state = 'unquoted';
                    $pos += strlen($um[0]) - 1;
                } elseif (preg_match('#\G[A-Za-z_:][-A-Za-z0-9_:.]*#', $html, $am, 0, $pos) === 1) {
                    $attribute = strtolower($am[0]);
                    $pos += strlen($am[0]) - 1;
                }
            } elseif ($state === 'dq') {
                if ($char === '"') {
                    $state = 'tag';
                }
            } elseif ($state === 'sq') {
                if ($char === "'") {
                    $state = 'tag';
                }
            } elseif ($char === '>') {
                $state = 'text';
            } elseif (ctype_space($char)) {
                $state = 'tag';
            }
        }
        if ($state !== 'text') {
            $problems[] = "The markup ends inside a {$state}.";
        }

        return $problems;
    }

    /**
     * Why output at this point of a double-quoted value is not allowed, or
     * null.
     *
     * @param array<int, list<array{int, string, string}>> $byOffset echoes by offset in the static markup
     */
    private static function attributeOutputProblem(
        string $html,
        int $pos,
        int $valueStart,
        int $index,
        string $attribute,
        string $helper,
        string $file,
        array $byOffset = [],
    ): ?string {
        if ($attribute === self::ID_LIST_ATTRIBUTE) {
            // One or more id() outputs separated by single literal spaces.
            $startsWell = $pos === $valueStart || ($pos > $valueStart && $html[$pos - 1] === ' ' && isset($byOffset[$pos - 1]));
            $endsWell = ($html[$pos] ?? '') === '"' || (($html[$pos] ?? '') === ' ' && isset($byOffset[$pos + 1]));
            if ($helper !== 'id' || $index !== 0 || !$startsWell || !$endsWell) {
                return "output in {$attribute} other than id() outputs separated by single spaces";
            }

            return null;
        }
        if ($pos !== $valueStart || $index !== 0 || ($html[$pos] ?? '') !== '"') {
            return "output that is not the whole value of the {$attribute} attribute";
        }
        if (in_array($attribute, self::ID_ATTRIBUTES, true)) {
            return $helper === 'id' ? null : "{$helper}() output in the attribute {$attribute} (only id())";
        }
        if ($helper === 'id') {
            return "id() output in the attribute {$attribute}";
        }
        if (
            in_array($attribute, ['class', 'style'], true)
            || in_array($attribute, self::NO_OUTPUT_ATTRIBUTES, true)
            || str_starts_with($attribute, 'on')
        ) {
            return "output in the {$attribute} attribute";
        }
        if ($helper === 'csrf') {
            return $attribute === 'value' ? null : "csrf() output in the attribute {$attribute}";
        }
        if ($helper === 'count') {
            return $attribute === 'aria-label' ? null : "count() output in the attribute {$attribute}";
        }
        if (in_array($helper, ['value', 'inputValue'], true)) {
            return $attribute === 'value' ? null : "{$helper}() output in the attribute {$attribute}";
        }
        if (in_array($attribute, self::URL_ATTRIBUTES, true)) {
            $allowed = ['url', 'asset'];
            if ($file === self::FOOTER && $attribute === 'href') {
                $allowed[] = 'e';
            }

            return in_array($helper, $allowed, true) ? null : "{$helper}() output in the URL attribute {$attribute}";
        }

        return in_array($helper, ['e', 't', 'number'], true) ? null : "{$helper}() output in the attribute {$attribute}";
    }

    /**
     * Checks one `$this` use: it must be `$this->helper(`.
     *
     * @param list<mixed>   $tokens
     * @param list<string>  $allowed
     */
    private static function thisUseProblem(array $tokens, int $i, array $allowed): ?string
    {
        $j = self::nextCodeIndex($tokens, $i);
        if ($j === null || !self::is($tokens[$j], T_OBJECT_OPERATOR)) {
            return 'Forbidden: $this used other than to call a helper';
        }
        $k = self::nextCodeIndex($tokens, $j);
        if ($k === null || !self::is($tokens[$k], T_STRING)) {
            return 'Forbidden: dynamic member access on $this';
        }
        $name = $tokens[$k][1];
        if (!in_array($name, $allowed, true)) {
            return "Forbidden: \$this->{$name} is not a documented helper here";
        }
        $l = self::nextCodeIndex($tokens, $k);
        if ($l === null || $tokens[$l] !== '(') {
            return "Forbidden: \$this->{$name} must be called";
        }

        return null;
    }

    /**
     * The helper named by a `<?= $this->name(`, or '' if it is not that form.
     *
     * @param list<mixed> $tokens
     */
    private static function echoHelper(array $tokens, int $i): string
    {
        $a = self::nextCodeIndex($tokens, $i);
        $b = $a === null ? null : self::nextCodeIndex($tokens, $a);
        $c = $b === null ? null : self::nextCodeIndex($tokens, $b);

        return $c !== null && self::is($tokens[$c], T_STRING) ? $tokens[$c][1] : '';
    }

    /**
     * Checks one `<?=`: exactly `$this->helper( ... )`, then an optional `;`
     * and the closing tag.
     *
     * @param list<mixed>   $tokens
     * @param list<string>  $allowed
     */
    private static function echoProblem(array $tokens, int $i, array $allowed): ?string
    {
        $a = self::nextCodeIndex($tokens, $i);
        if ($a === null || !self::is($tokens[$a], T_VARIABLE) || $tokens[$a][1] !== '$this') {
            return '<?= must begin with $this->helper(';
        }
        $b = self::nextCodeIndex($tokens, $a);
        $c = $b === null ? null : self::nextCodeIndex($tokens, $b);
        $d = $c === null ? null : self::nextCodeIndex($tokens, $c);
        if (
            $b === null || $c === null || $d === null
            || !self::is($tokens[$b], T_OBJECT_OPERATOR)
            || !self::is($tokens[$c], T_STRING)
            || !in_array($tokens[$c][1], $allowed, true)
            || $tokens[$d] !== '('
        ) {
            return '<?= must begin with a documented helper call';
        }
        if (in_array($tokens[$c][1], self::DATA_HELPERS, true)) {
            return "<?= cannot print \$this->{$tokens[$c][1]}(), which returns data";
        }

        // Find the matching closing parenthesis.
        $depth = 0;
        $end = null;
        $count = count($tokens);
        for ($n = $d; $n < $count; $n++) {
            $t = $tokens[$n];
            if ($t === '(' || $t === '[') {
                $depth++;
            } elseif ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    $end = $n;
                    break;
                }
            } elseif (self::is($t, T_CLOSE_TAG)) {
                break;
            }
        }
        if ($end === null) {
            return '<?= helper call is not closed';
        }

        $after = self::nextCodeIndex($tokens, $end);
        if ($after !== null && $tokens[$after] === ';') {
            $after = self::nextCodeIndex($tokens, $after);
        }
        if ($after !== null && !self::is($tokens[$after], T_CLOSE_TAG)) {
            return '<?= must contain nothing after the helper call';
        }

        return null;
    }

    private static function is(mixed $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    private static function isCode(mixed $token): bool
    {
        return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /** @param list<mixed> $tokens */
    private static function nextCodeIndex(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($n = $i + 1; $n < $count; $n++) {
            if (self::isCode($tokens[$n])) {
                return $n;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function previousCodeIndex(array $tokens, int $i): ?int
    {
        for ($n = $i - 1; $n >= 0; $n--) {
            if (self::isCode($tokens[$n])) {
                return $n;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function previousCode(array $tokens, int $i): ?string
    {
        $n = self::previousCodeIndex($tokens, $i);
        if ($n === null) {
            return null;
        }

        return is_array($tokens[$n]) ? $tokens[$n][1] : $tokens[$n];
    }

    /**
     * Literal string keys passed to t() in a template.
     *
     * @return list<string>
     */
    public static function literalKeys(string $source): array
    {
        $tokens = token_get_all($source);
        $keys = [];
        foreach ($tokens as $i => $token) {
            if (!self::is($token, T_STRING) || $token[1] !== 't' || self::previousCode($tokens, $i) !== '->') {
                continue;
            }
            $open = self::nextCodeIndex($tokens, $i);
            $arg = $open === null ? null : self::nextCodeIndex($tokens, $open);
            if ($arg !== null && self::is($tokens[$arg], T_CONSTANT_ENCAPSED_STRING)) {
                $keys[] = stripslashes(substr($tokens[$arg][1], 1, -1));
            }
        }

        return $keys;
    }

    /**
     * Literal partial names used in a template.
     *
     * @return list<string>
     */
    private static function literalPartials(string $source): array
    {
        preg_match_all("/\\\$this->partial\(\s*'([^']+)'/", $source, $m);

        return $m[1];
    }

    /**
     * The data parameters each partial treats as string keys, derived from
     * the partials themselves: a partial whose source passes
     * `$data['submit']` (or a loop variable's `$action['label']`) as the
     * first argument of t() or count() treats `submit` (or `label`) as a
     * key. Keyed by partial name (`notice`, `field-options/text`).
     *
     * @param array<string, string> $partials partial name => source
     * @return array<string, list<string>>
     */
    public static function partialKeyParameters(array $partials): array
    {
        $parameters = [];
        foreach ($partials as $name => $source) {
            $tokens = array_values(array_filter(token_get_all($source), self::isCode(...)));
            $found = [];
            foreach ($tokens as $i => $token) {
                if (!self::is($token, T_STRING) || !in_array($token[1], ['t', 'count'], true) || ($tokens[$i - 1] ?? null) === null) {
                    continue;
                }
                $arrow = $tokens[$i - 1];
                if (!self::is($arrow, T_OBJECT_OPERATOR) && !self::is($arrow, T_NULLSAFE_OBJECT_OPERATOR)) {
                    continue;
                }
                $next = array_slice($tokens, $i + 1, 6);
                if (
                    count($next) === 6
                    && $next[0] === '('
                    && self::is($next[1], T_VARIABLE)
                    && $next[2] === '['
                    && self::is($next[3], T_CONSTANT_ENCAPSED_STRING)
                    && $next[4] === ']'
                    && in_array($next[5], [',', ')'], true)
                ) {
                    $found[stripslashes(substr($next[3][1], 1, -1))] = true;
                }
            }
            $names = array_keys($found);
            sort($names);
            $parameters[$name] = $names;
        }

        return $parameters;
    }

    /** @return array<string, string> partial name => source, for the real partials */
    private static function partialSources(): array
    {
        $sources = [];
        foreach (RepoFiles::withExtension(['php'], 'templates/partials') as $file) {
            $sources[substr($file, strlen('templates/partials/'), -4)] = RepoFiles::read($file);
        }

        return $sources;
    }

    /**
     * Literal string keys a template passes to a partial: in
     * `$this->partial('name', [...])`, every `'param' => 'literal'` entry,
     * at any depth of the array literal (so `actions` lists count too),
     * whose `param` is one of that partial's key parameters
     * (partialKeyParameters()). The literal must be the whole value: a key
     * built by concatenation (`'x.' . $y`) is not a literal key and still
     * needs a runtime prefix. Values under other names (such as `params`,
     * whose values are substituted text, or `cancel`, a path) never count.
     *
     * @param array<string, list<string>> $parameters partial name => key parameters
     * @return list<string>
     */
    public static function partialKeys(string $source, array $parameters): array
    {
        $tokens = array_values(array_filter(token_get_all($source), self::isCode(...)));
        $count = count($tokens);
        $keys = [];
        foreach ($tokens as $i => $token) {
            if (!self::is($token, T_STRING) || $token[1] !== 'partial' || !self::is($tokens[$i - 1] ?? null, T_OBJECT_OPERATOR)) {
                continue;
            }
            if (
                ($tokens[$i + 1] ?? null) !== '('
                || !self::is($tokens[$i + 2] ?? null, T_CONSTANT_ENCAPSED_STRING)
                || ($tokens[$i + 3] ?? null) !== ','
                || ($tokens[$i + 4] ?? null) !== '['
            ) {
                continue;
            }
            $names = $parameters[stripslashes(substr($tokens[$i + 2][1], 1, -1))] ?? [];
            $depth = 0;
            for ($n = $i + 4; $n < $count; $n++) {
                $t = $tokens[$n];
                if ($t === '[' || $t === '(') {
                    $depth++;
                } elseif ($t === ']' || $t === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif (
                    self::is($t, T_CONSTANT_ENCAPSED_STRING)
                    && self::is($tokens[$n + 1] ?? null, T_DOUBLE_ARROW)
                    && self::is($tokens[$n + 2] ?? null, T_CONSTANT_ENCAPSED_STRING)
                    && in_array($tokens[$n + 3] ?? null, [',', ']'], true)
                    && in_array(stripslashes(substr($t[1], 1, -1)), $names, true)
                ) {
                    $keys[] = stripslashes(substr($tokens[$n + 2][1], 1, -1));
                }
            }
        }

        return $keys;
    }

    /**
     * String literals in PHP code (controllers) that exactly equal a
     * defined key: the breadcrumb labels and other keys a controller hands
     * to the layout, which no template names. A literal that is only part
     * of a key (`'people.' . $x`) is not a defined key and does not count.
     *
     * @param array<string, string> $strings
     * @return list<string>
     */
    public static function codeKeys(string $source, array $strings): array
    {
        $keys = [];
        foreach (token_get_all($source) as $token) {
            if (!self::is($token, T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }
            $value = stripslashes(substr($token[1], 1, -1));
            if (array_key_exists($value, $strings)) {
                $keys[] = $value;
            }
        }

        return $keys;
    }

    /**
     * The PHP files whose whole string literals count as key uses: the
     * controllers (breadcrumb labels) and the web layer's own classes
     * directly under src/Web (Format and ValueText name the keys they
     * format with).
     *
     * @return list<string>
     */
    private static function controllers(): array
    {
        return array_values(array_filter(
            RepoFiles::withExtension(['php'], 'src/Web'),
            static fn (string $file): bool => in_array(dirname($file), ['src/Web', 'src/Web/Controller'], true),
        ));
    }

    /**
     * Keys in $strings that nothing uses: not literal in a t() or count()
     * call, not a literal key passed to a partial, not a literal in a
     * controller, and not under a runtime prefix.
     *
     * @param list<string>                $templates  template sources
     * @param list<string>                $code       controller sources
     * @param array<string, string>       $strings
     * @param array<string, list<string>> $parameters partial name => key parameters
     * @return list<string>
     */
    public static function unusedKeys(array $templates, array $code, array $strings, array $parameters): array
    {
        $used = [];
        foreach ($templates as $source) {
            foreach ([...self::literalKeys($source), ...self::partialKeys($source, $parameters)] as $key) {
                $used[$key] = true;
            }
        }
        foreach ($code as $source) {
            foreach (self::codeKeys($source, $strings) as $key) {
                $used[$key] = true;
            }
        }
        $unused = [];
        foreach (array_keys($strings) as $key) {
            if (!isset($used[$key]) && !self::isRuntimeKey($key) && !in_array($key, self::PENDING_KEYS, true)) {
                $unused[] = $key;
            }
        }

        return $unused;
    }

    // The real templates.

    public function testTheShellTemplatesExist(): void
    {
        $names = [
            'layout', 'pages/error',
            'partials/top-bar', 'partials/mark', 'partials/footer', 'partials/lockup', 'partials/breadcrumb',
            'partials/notice', 'partials/error-summary', 'partials/field-error', 'partials/password-toggle',
            'partials/page-head', 'partials/csrf', 'partials/form-actions', 'partials/field-label',
        ];
        foreach ($names as $name) {
            self::assertFileExists(RepoFiles::path("templates/{$name}.php"));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function templateFiles(): iterable
    {
        foreach (self::templates() as $file) {
            yield $file => [$file];
        }
    }

    #[DataProvider('templateFiles')]
    public function testTemplateFollowsTheRules(string $file): void
    {
        self::assertSame([], self::violations(RepoFiles::read($file), $file), $file);
    }

    public function testEveryLiteralKeyExists(): void
    {
        $strings = self::strings();
        $missing = [];
        foreach (self::templates() as $file) {
            foreach (self::literalKeys(RepoFiles::read($file)) as $key) {
                if (!array_key_exists($key, $strings)) {
                    $missing[] = "{$file}: {$key}";
                }
            }
        }

        self::assertSame([], $missing, 'String keys used in templates but missing from strings/en.php.');
    }

    public function testEveryPartialExists(): void
    {
        $missing = [];
        foreach (self::templates() as $file) {
            foreach (self::literalPartials(RepoFiles::read($file)) as $name) {
                if (!is_file(RepoFiles::path("templates/partials/{$name}.php"))) {
                    $missing[] = "{$file}: {$name}";
                }
            }
        }

        self::assertSame([], $missing, 'Partials used in templates but missing.');
    }

    public function testEveryKeyPassedToAPartialExists(): void
    {
        $strings = self::strings();
        $parameters = self::partialKeyParameters(self::partialSources());
        $missing = [];
        foreach (self::templates() as $file) {
            foreach (self::partialKeys(RepoFiles::read($file), $parameters) as $key) {
                if (!array_key_exists($key, $strings)) {
                    $missing[] = "{$file}: {$key}";
                }
            }
        }

        self::assertSame([], $missing, 'String keys passed to partials but missing from strings/en.php.');
    }

    public function testEveryStringIsUsed(): void
    {
        $unused = self::unusedKeys(
            array_map(RepoFiles::read(...), self::templates()),
            array_map(RepoFiles::read(...), self::controllers()),
            self::strings(),
            self::partialKeyParameters(self::partialSources()),
        );
        self::assertSame([], $unused, 'Keys in strings/en.php that nothing uses.');
    }

    public function testPartialKeyParametersAreDerivedFromThePartials(): void
    {
        $parameters = self::partialKeyParameters(self::partialSources());
        // The shared partials of section 4.4 that take keys (others may be added).
        self::assertSame(['cancelLabel', 'submit'], $parameters['form-actions']);
        self::assertSame(['key', 'title'], $parameters['notice']);
        self::assertSame(['key'], $parameters['field-label']);
        self::assertSame(['label'], $parameters['page-head']);
        // Partials that print only fixed keys, or plain text, take none.
        self::assertSame([], $parameters['breadcrumb']);
        self::assertSame([], $parameters['footer']);
        self::assertSame([], $parameters['field-error']);
        // The M3 shared partials (M3 spec part 2 section 5.1).
        self::assertSame(['label'], $parameters['steps']);
        self::assertSame(['help', 'label'], $parameters['file-field']);
        self::assertSame(['heading', 'term'], $parameters['facts']);
        self::assertSame(['heading', 'key'], $parameters['warning-panel']);
        self::assertSame(['label', 'status'], $parameters['progress']);
        self::assertSame(['label', 'working'], $parameters['continue-form']);
    }

    /** P141: the pending lists are the two package lists, and hold only existing keys. */
    public function testPendingKeysAreThePackageLists(): void
    {
        self::assertSame([...self::PENDING_IMPORT, ...self::PENDING_EXPORT], self::PENDING_KEYS);
        self::assertSame([], array_intersect(self::PENDING_IMPORT, self::PENDING_EXPORT));
        $source = RepoFiles::read('tests/web/TemplateRulesTest.php');
        self::assertMatchesRegularExpression('/^    public const PENDING_IMPORT = \[\n(?:        \'[a-z0-9._-]+\',\n)*    \];$/m', $source);
        self::assertMatchesRegularExpression('/^    public const PENDING_EXPORT = \[\n(?:        \'[a-z0-9._-]+\',\n)*    \];$/m', $source);
    }

    public function testKeysPassedToPartialsCountAsUsed(): void
    {
        $fixture = RepoFiles::read('tests/web/fixtures/templates/pages/partial-keys.php');
        self::assertSame([], self::violations($fixture, self::PAGE));
        $parameters = self::partialKeyParameters(self::partialSources());
        $strings = [
            'fixture.submit' => 'Save',
            'fixture.cancel_label' => 'Go back',
            'fixture.notice' => 'Saved.',
            'fixture.notice_title' => 'Done',
            'fixture.action' => 'Add',
            'fixture.label' => 'Name',
            'fixture.literal' => 'Literal',
            'fixture.in_params' => 'Only a parameter value',
            'fixture.as_path' => 'Only a cancel path',
            'fixture.unknown_param' => 'Only under an unknown parameter',
            'fixture.wrong_partial' => 'Only under another partial\'s parameter',
            'fixture.built.one' => 'Only built by concatenation',
            'fixture.nowhere' => 'Never passed anywhere',
        ];

        self::assertSame(
            [
                'fixture.in_params', 'fixture.as_path', 'fixture.unknown_param', 'fixture.wrong_partial',
                'fixture.built.one', 'fixture.nowhere',
            ],
            self::unusedKeys([$fixture], [], $strings, $parameters),
        );
        self::assertSame(
            ['fixture.submit', 'fixture.cancel_label', 'fixture.notice_title', 'fixture.notice', 'fixture.action', 'fixture.label'],
            self::partialKeys($fixture, $parameters),
        );
    }

    public function testControllerLiteralsThatAreKeysCountAsUsed(): void
    {
        $code = <<<'PHP'
            <?php
            $crumbs = [['label' => 'fixture.crumb', 'path' => '/x'], ['label' => 'fixture.crumb.' . $name, 'path' => null]];
            $title = "fixture.title";
            // 'fixture.comment' in a comment is not code.
            $partial = 'fixture.part';
            PHP;
        $strings = [
            'fixture.crumb' => 'Crumb',
            'fixture.title' => 'Title',
            'fixture.crumb.one' => 'Built at run time only',
            'fixture.comment' => 'Only in a comment',
            'fixture.part.whole' => 'Longer than the literal',
        ];

        self::assertSame(['fixture.crumb', 'fixture.title'], self::codeKeys($code, $strings));
        self::assertSame(
            ['fixture.crumb.one', 'fixture.comment', 'fixture.part.whole'],
            self::unusedKeys([], [$code], $strings, []),
        );
    }

    public function testTheControllerScanReadsTheControllers(): void
    {
        $files = self::controllers();
        self::assertContains('src/Web/Controller/HomeController.php', $files);
        self::assertContains('src/Web/ValueText.php', $files);
        foreach ($files as $file) {
            self::assertContains(dirname($file), ['src/Web', 'src/Web/Controller']);
        }
    }

    public function testPendingKeysAreStillPending(): void
    {
        $strings = self::strings();
        $used = [];
        foreach (self::templates() as $file) {
            foreach (self::literalKeys(RepoFiles::read($file)) as $key) {
                $used[$key] = true;
            }
        }
        foreach (self::PENDING_KEYS as $key) {
            self::assertArrayHasKey($key, $strings, $key);
            self::assertArrayNotHasKey($key, $used, "{$key} is used by a template now; remove it from PENDING_KEYS.");
        }
    }

    private static function isRuntimeKey(string $key): bool
    {
        foreach (self::RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** P50: the shared form strings are printed by their shared partial only. */
    public function testSharedFormStringsAreOnlyInTheirPartials(): void
    {
        $owners = [
            'form.cancel' => 'templates/partials/form-actions.php',
            'form.optional' => 'templates/partials/field-label.php',
            'form.show_password' => 'templates/partials/password-toggle.php',
            'form.show_passwords' => 'templates/partials/password-toggle.php',
            'form.errors.page_title' => self::LAYOUT,
            'shell.breadcrumb' => self::BREADCRUMB,
        ];
        $found = [];
        foreach (self::templates() as $file) {
            foreach (self::literalKeys(RepoFiles::read($file)) as $key) {
                if (isset($owners[$key])) {
                    $found[$key][$file] = true;
                }
            }
        }
        foreach ($owners as $key => $file) {
            self::assertSame([$file], array_keys($found[$key] ?? []), $key);
        }
    }

    public function testRuntimePrefixesMatchOnlyTheirKeys(): void
    {
        // The prefixes of section 4.3 whose keys this package owns have
        // keys; the others (type., setup.check., role.) arrive with the
        // screen packages. Shared keys outside the prefixes must be used
        // literally.
        $keys = array_keys(self::strings());
        foreach (['problem.', 'error.', 'notice.', 'wait.', 'date.', 'count.'] as $prefix) {
            self::assertNotSame([], array_filter($keys, static fn (string $k): bool => str_starts_with($k, $prefix)), $prefix);
        }
        foreach (['shell.sign_out', 'form.cancel', 'form.optional', 'form.errors.page_title', 'app.name'] as $key) {
            self::assertFalse(self::isRuntimeKey($key), $key);
        }
    }

    public function testStringsArePlainText(): void
    {
        foreach (self::strings() as $key => $text) {
            // Lower case, except the ISO 4217 code of a currency name (M2 spec part 2 section 6.3).
            self::assertMatchesRegularExpression('/^(?:[a-z0-9]+(?:[._-][a-z0-9]+)*|currency\.[A-Z]{3})$/', $key);
            self::assertNotSame('', trim($text), $key);
            self::assertDoesNotMatchRegularExpression('/[<>]/', $text, "{$key}: strings hold text, not markup.");
            // A scheme may be named ("starts with https://"), but no address.
            self::assertDoesNotMatchRegularExpression('#https?://[^\s.,;]#i', $text, "{$key}: no addresses in strings.");
        }
    }

    public function testTemplatesWriteNoProseExceptTheWordmark(): void
    {
        foreach (self::templates() as $file) {
            $html = '';
            foreach (token_get_all(RepoFiles::read($file)) as $token) {
                if (self::is($token, T_INLINE_HTML)) {
                    $html .= ' ' . $token[1];
                }
            }
            // Remove tags (and the wordmark spans' text in the top bar), then
            // what is left between tags must be empty.
            $text = (string) preg_replace('/<[^>]*>/', ' ', $html);
            if (in_array($file, self::WORDMARK_TEMPLATES, true)) {
                $text = (string) preg_replace('/\bTiffin\b|\bDB\b/', ' ', $text);
            }
            if ($file === self::BREADCRUMB) {
                $text = str_replace('/', ' ', $text);
            }
            self::assertSame('', trim($text), "{$file} must take its text from the strings file.");
        }
    }

    // Self-checks: the scanner rejects what it must.

    private const HEADER = "<?php\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n * Copyright (C) TiffinDB contributors\n */\n\ndeclare(strict_types=1);\n\n?>\n";

    private const PAGE = 'templates/pages/example.php';

    /** @return iterable<string, array{string, string}> */
    public static function badTemplates(): iterable
    {
        $page = self::PAGE;
        $layout = self::LAYOUT;
        $footer = self::FOOTER;
        $csrf = self::CSRF_PARTIAL;

        // Output and code.
        yield 'raw variable' => ['<p><?= $data[\'x\'] ?></p>', $page];
        yield 'echo' => ['<?php echo $data[\'x\']; ?>', $page];
        yield 'print' => ['<?php print $data[\'x\']; ?>', $page];
        yield 'printf' => ['<?php printf(\'%s\', $data[\'x\']); ?>', $page];
        yield 'qualified printf' => ['<?php \printf(\'%s\', $data[\'x\']); ?>', $page];
        yield 'helper then concatenation' => ['<p><?= $this->e($data[\'a\']) . $data[\'b\'] ?></p>', $page];
        yield 'helper then second statement' => ['<p><?= $this->e($data[\'a\']); $data[\'b\'] ?></p>', $page];
        yield 'ternary around helper' => ['<p><?= $this->e(1) ? $data[\'b\'] : \'\' ?></p>', $page];
        yield 'unknown helper' => ['<p><?= $this->raw($data[\'x\']) ?></p>', $page];
        yield 'setSlot' => ['<?php $this->setSlot(\'content\', \'x\'); ?>', $page];
        yield 'run' => ['<?php $this->run(\'/etc/passwd\', []); ?>', $page];
        yield 'setSlot inside a helper argument' => ['<?= $this->e($this->setSlot(\'a\', \'b\')) ?>', $page];
        yield 'property of $this' => ['<?php $v = $this->view; ?>', $page];
        yield 'dynamic method' => ['<?= $this->{$data[\'m\']}() ?>', $page];
        yield 'layout accessor outside the layout' => ['<html<?= $this->rootClassAttribute() ?>>', $page];
        yield 'method on data' => ['<?= $this->e($data[\'o\']->name()) ?>', $page];
        yield 'include' => ['<?php include \'x.php\'; ?>', $page];
        yield 'eval' => ['<?php eval(\'1;\'); ?>', $page];
        yield 'superglobal' => ['<?= $this->e($_GET[\'x\']) ?>', $page];
        yield 'variable variable' => ['<?php $$name = 1; ?>', $page];
        yield 'closure' => ['<?php $f = function () {}; ?>', $page];
        yield 'arrow function' => ['<?php $f = fn () => 1; ?>', $page];
        yield 'static call' => ['<?= $this->e(Foo::bar()) ?>', $page];
        yield 'new object' => ['<?= $this->e(new Foo()) ?>', $page];
        yield 'backtick' => ['<?= $this->e(`ls`) ?>', $page];
        yield 'exit' => ['<?php exit; ?>', $page];

        // Dynamic calls (default deny for "(").
        yield 'call of a string' => ['<?php \'printf\'(\'%s\', $data[\'x\']); ?>', $page];
        yield 'call of a variable' => ['<?php $f = \'printf\'; $f(\'%s\', $data[\'x\']); ?>', $page];
        yield 'call of an array element' => ['<?php $data[\'f\']($data[\'x\']); ?>', $page];
        yield 'call of a string inside a helper' => ['<?= $this->e(\'readfile\'(\'/etc/hostname\')) ?>', $page];
        yield 'callable array calling setSlot' => [
            '<?php [\'debug_backtrace\'()[1][\'object\'], \'setSlot\'](\'content\', $data[\'x\']); ?>',
            $page,
        ];
        yield 'call of a call result' => ['<?php is_array(1)(2); ?>', $page];
        yield 'call after a closing brace' => ['<?php if (true) { } (1); ?>', $page];

        // Markup, checked as a whole.
        yield 'style attribute' => ['<p style="color: red">x</p>', $page];
        yield 'style element' => ['<style>p {}</style>', $page];
        yield 'script element' => ['<script src="/a.js"></script>', $page];
        yield 'event handler' => ['<a onclick="x()">x</a>', $page];
        yield 'javascript address' => ['<a href="javascript:x()">x</a>', $page];
        yield 'external address' => ['<a href="https://example.org/">x</a>', $page];
        yield 'style split by an empty PHP block' => ['<p st<?php ?>yle="color:red">x</p>', $page];
        yield 'script split by an empty PHP block' => ['<scr<?php ?>ipt src="/x.js"></script>', $page];
        yield 'event handler after a quote' => ['<img src="x"onerror="a()" />', $page];
        yield 'event handler after a slash' => ['<svg/onload="a()"></svg>', $page];
        yield 'character reference hiding a scheme' => ['<a href="&#106;avascript:a()">x</a>', $page];
        yield 'protocol-relative stylesheet' => ['<link rel="stylesheet" href="//example.org/x.css" />', $page];
        yield 'protocol-relative link with spaces' => ['<a href= "//example.org/">x</a>', $page];

        // Output context.
        yield 'unquoted attribute output' => ['<a href=<?= $this->url(\'/\') ?>>x</a>', $page];
        yield 'single-quoted attribute output' => ["<a href='<?= \$this->url('/') ?>'>x</a>", $page];
        yield 'output inside a tag' => ['<p <?= $this->e($data[\'a\']) ?>>x</p>', $page];
        yield 'output as an attribute name' => ['<p <?= $this->t(\'k\') ?>="1">x</p>', $page];
        yield 'output in a comment' => ['<!-- <?= $this->e($data[\'a\']) ?> -->', $page];
        yield 'output in class with static text' => ['<p class="x <?= $this->e($data[\'c\']) ?>">x</p>', $page];
        yield 'output as the whole class' => ['<p class="<?= $this->e($data[\'c\']) ?>">x</p>', $page];
        yield 'output after static value text' => ['<p title="a <?= $this->e($data[\'c\']) ?>">x</p>', $page];
        yield 'output before static value text' => ['<p title="<?= $this->e($data[\'c\']) ?> a">x</p>', $page];
        yield 'two outputs in one value' => ['<p title="<?= $this->e(1) ?><?= $this->e(2) ?>">x</p>', $page];
        yield 'e() in href outside the footer' => ['<a href="<?= $this->e($data[\'u\']) ?>">x</a>', $page];
        yield 't() in href in the footer' => ['<a href="<?= $this->t(\'k\') ?>">x</a>', $footer];
        yield 'e() in src in the footer' => ['<img src="<?= $this->e($data[\'u\']) ?>" />', $footer];
        yield 'url() in a non-URL attribute' => ['<p title="<?= $this->url(\'/\') ?>">x</p>', $page];
        yield 'partial in an attribute' => ['<p title="<?= $this->partial(\'x\') ?>">x</p>', $page];
        yield 'layout accessor in text' => ['<p><?= $this->rootClassAttribute() ?></p>', $layout];
        yield 'layout accessor in another tag' => ['<body<?= $this->rootClassAttribute() ?>>', $layout];
        yield 'markup ending inside a tag' => ['<p title="x"', $page];

        // M1 rules (M1 spec part 2 section 4.3).
        yield 'pipe operator' => ['<?= $this->e($data[\'x\'] |> trim(...)) ?>', $page];
        yield 'pipe operator in a statement' => ['<?php $v = $data[\'x\'] |> $data[\'f\']; ?>', $page];
        yield 'slash-backslash link' => ['<a href="/\\example.org/">x</a>', $page];
        yield 'slash-double-backslash link' => ['<a href="/\\\\example.org/">x</a>', $page];
        yield 'slash-backslash image with space' => ['<img src=" /\\example.org/x.png" />', $page];
        yield 'slash-backslash form action' => ['<form action="/\\example.org/" method="post"></form>', $page];
        yield 'slash-tab-slash link' => ["<a href=\"/\t/example.org/\">x</a>", $page];
        yield 'protocol-relative form action' => ['<form action="//example.org/" method="post"></form>', $page];
        yield 'csrf() in element text' => ['<p><?= $this->csrf() ?></p>', $csrf];
        yield 'csrf() in a title' => ['<p title="<?= $this->csrf() ?>">x</p>', $csrf];
        yield 'csrf() in an href' => ['<a href="<?= $this->csrf() ?>">x</a>', $csrf];
        yield 'csrf() as part of a value' => ['<input type="hidden" name="_csrf" value="x<?= $this->csrf() ?>" />', $csrf];
        yield 'count() in a title' => ['<p title="<?= $this->count(\'count.problems\', 2) ?>">x</p>', $page];
        yield 'count() in a value' => ['<input value="<?= $this->count(\'count.problems\', 2) ?>" />', $page];
        yield 'count() in an href' => ['<a href="<?= $this->count(\'count.problems\', 2) ?>">x</a>', $page];
        yield 'count() then more' => ['<p><?= $this->count(\'count.problems\', 2) . $data[\'x\'] ?></p>', $page];
        yield 'script outside the layout' => [self::SCRIPT_ELEMENT, $page];
        yield 'script in a partial' => [self::SCRIPT_ELEMENT, 'templates/partials/top-bar.php'];
        yield 'other script in the layout' => ['<script src="<?= $this->asset(\'js/other.js\') ?>" defer="defer"></script>', $layout];
        yield 'script without defer in the layout' => ['<script src="<?= $this->asset(\'js/show-password.js\') ?>"></script>', $layout];
        yield 'inline script in the layout' => ['<script>alert(1)</script>', $layout];
        yield 'two scripts in the layout' => [self::SCRIPT_ELEMENT . "\n" . self::SCRIPT_ELEMENT, $layout];
        yield 'the script and an inline one in the layout' => [self::SCRIPT_ELEMENT . "\n<script>alert(1)</script>", $layout];
        yield 'the script with content in the layout' => [str_replace('></script>', '>x</script>', self::SCRIPT_ELEMENT), $layout];

        // Security review follow-ups.
        $head = static fn (string $inner): string => "<head>\n{$inner}\n</head>\n<body>\n</body>";
        yield 'csrf() outside the csrf partial' => ['<input type="hidden" name="_csrf" value="<?= $this->csrf() ?>" />', $page];
        yield 'csrf() in the layout' => [$head('') . '<input type="hidden" name="_csrf" value="<?= $this->csrf() ?>" />', $layout];
        yield 'csrf() called without output outside the partial' => ['<?php if ($this->csrf() !== \'\') : ?><p></p><?php endif; ?>', $page];
        yield 'the script in the layout body' => ["<head>\n</head>\n<body>\n" . self::SCRIPT_ELEMENT . "\n</body>", $layout];
        yield 'the script before the layout head' => [self::SCRIPT_ELEMENT . "\n" . $head(''), $layout];
        yield 'the script in the layout without a head' => ['<div>' . self::SCRIPT_ELEMENT . '</div>', $layout];
        yield 'iframe srcdoc with output' => ['<iframe srcdoc="<?= $this->e($data[\'x\']) ?>"></iframe>', $page];
        yield 'iframe without output' => ['<iframe src="/x"></iframe>', $page];
        yield 'srcdoc on another element' => ['<p srcdoc="<?= $this->e($data[\'x\']) ?>">x</p>', $page];
        yield 'meta refresh with output' => ['<meta http-equiv="refresh" content="<?= $this->e($data[\'x\']) ?>" />', $page];
        yield 'meta refresh, static' => ['<meta http-equiv="refresh" content="0; url=/x" />', $page];
        yield 'meta in a page' => ['<meta charset="utf-8" />', $page];
        yield 'meta in the layout body' => [$head('') . "\n" . self::LAYOUT_METAS[0], $layout];
        yield 'other meta in the layout head' => [$head('<meta name="referrer" content="unsafe-url" />'), $layout];
        yield 'layout meta with output' => [$head('<meta name="viewport" content="<?= $this->e($data[\'v\']) ?>" />'), $layout];
        yield 'layout meta twice' => [$head(self::LAYOUT_METAS[0] . "\n" . self::LAYOUT_METAS[0]), $layout];
        yield 'svg animate href' => ['<svg><animate attributeName="href" to="<?= $this->e($data[\'x\']) ?>" /></svg>', $page];
        yield 'svg animate, static' => ['<svg><a href="/x"><animate attributeName="href" to="/y" /></a></svg>', $page];
        yield 'svg set' => ['<svg><set attributeName="href" to="/y" /></svg>', $page];
        yield 'svg animateTransform' => ['<svg><animateTransform attributeName="transform" from="0" to="1" /></svg>', $page];
        yield 'to with output' => ['<p to="<?= $this->e($data[\'x\']) ?>">x</p>', $page];
        yield 'from with output' => ['<p from="<?= $this->e($data[\'x\']) ?>">x</p>', $page];
        yield 'values with output' => ['<p values="<?= $this->e($data[\'x\']) ?>">x</p>', $page];
        yield 'attributeName with output' => ['<p attributeName="<?= $this->t(\'k\') ?>">x</p>', $page];
        yield 'object' => ['<object data="/x"></object>', $page];
        yield 'embed' => ['<embed src="/x" />', $page];
        yield 'base' => ['<base href="/x/" />', $page];
        yield 'base in the layout head' => [$head('<base href="/x/" />'), $layout];
        yield 'upper-case iframe' => ['<IFRAME src="/x"></IFRAME>', $page];

        // M2 rules (M2 spec part 2 section 4.3).
        $preview = self::SCRIPT_ELEMENTS[1];
        yield 'id() in element text' => ['<p><?= $this->id(\'f\', $data[\'k\']) ?></p>', $page];
        yield 'id() in class' => ['<p class="<?= $this->id(\'f\', $data[\'k\']) ?>">x</p>', $page];
        yield 'id() in title' => ['<p title="<?= $this->id(\'f\', $data[\'k\']) ?>">x</p>', $page];
        yield 'id() in href' => ['<a href="<?= $this->id(\'f\', $data[\'k\']) ?>">x</a>', $page];
        yield 'id() in name' => ['<input name="<?= $this->id(\'f\', $data[\'k\']) ?>" />', $page];
        yield 'e() in id' => ['<p id="<?= $this->e($data[\'k\']) ?>">x</p>', $page];
        yield 't() in for' => ['<label for="<?= $this->t(\'k\') ?>">x</label>', $page];
        yield 'e() in form' => ['<button form="<?= $this->e($data[\'k\']) ?>">x</button>', $page];
        yield 'e() in aria-labelledby' => ['<section aria-labelledby="<?= $this->e($data[\'k\']) ?>"></section>', $page];
        yield 'id() with static text in id' => ['<p id="x-<?= $this->id(\'f\', $data[\'k\']) ?>">x</p>', $page];
        yield 'two id() in id' => ['<p id="<?= $this->id(\'f\', 1) ?> <?= $this->id(\'f\', 2) ?>">x</p>', $page];
        yield 'e() in aria-describedby' => ['<input aria-describedby="<?= $this->e($data[\'k\']) ?>" />', $page];
        yield 'static text in aria-describedby' => ['<input aria-describedby="help <?= $this->id(\'f\', 1) ?>" />', $page];
        yield 'static id after id() in aria-describedby' => ['<input aria-describedby="<?= $this->id(\'f\', 1) ?> help" />', $page];
        yield 'two spaces in aria-describedby' => ['<input aria-describedby="<?= $this->id(\'f\', 1) ?>  <?= $this->id(\'f\', 2) ?>" />', $page];
        yield 'adjacent ids in aria-describedby' => ['<input aria-describedby="<?= $this->id(\'f\', 1) ?><?= $this->id(\'f\', 2) ?>" />', $page];
        yield 'value() in title' => ['<p title="<?= $this->value($data[\'f\'], $data[\'v\']) ?>">x</p>', $page];
        yield 'value() in href' => ['<a href="<?= $this->value($data[\'f\'], $data[\'v\']) ?>">x</a>', $page];
        yield 'inputValue() in aria-label' => ['<input aria-label="<?= $this->inputValue($data[\'f\'], \'\', false) ?>" />', $page];
        yield 'value() as part of a value' => ['<input value="x<?= $this->value($data[\'f\'], $data[\'v\']) ?>" />', $page];
        yield 'fieldFacts() printed' => ['<p><?= $this->fieldFacts($data[\'f\']) ?></p>', $page];
        yield 'number() in href' => ['<a href="<?= $this->number(1) ?>">x</a>', $page];
        yield 'protocol-relative formaction' => ['<button type="submit" formaction="//example.org/">x</button>', $page];
        yield 'e() in formaction' => ['<button type="submit" formaction="<?= $this->e($data[\'u\']) ?>">x</button>', $page];
        yield 'slash-backslash formaction' => ['<button type="submit" formaction="/\\example.org/">x</button>', $page];
        yield 'preview script outside the layout' => [$preview, $page];
        yield 'preview script twice in the layout' => [$head($preview . "\n" . $preview), $layout];
        yield 'both scripts and an inline one' => [$head(self::SCRIPT_ELEMENT . "\n" . $preview . "\n<script>alert(1)</script>"), $layout];
        yield 'preview script in the layout body' => ["<head>\n</head>\n<body>\n" . $preview . "\n</body>", $layout];

        // M3 rules (M3 spec part 2 sections 5.2 and 7).
        $continue = self::SCRIPT_ELEMENTS[2];
        yield 'continue script outside the layout' => [$continue, $page];
        yield 'continue script in a partial' => [$continue, 'templates/partials/continue-form.php'];
        yield 'continue script twice in the layout' => [$head($continue . "\n" . $continue), $layout];
        yield 'continue script in the layout body' => ["<head>\n</head>\n<body>\n" . $continue . "\n</body>", $layout];
        yield 'continue script without defer' => [$head('<script src="<?= $this->asset(\'js/import-continue.js\') ?>"></script>'), $layout];
        yield 'all three scripts and an inline one' => [$head(implode("\n", self::SCRIPT_ELEMENTS) . "\n<script>alert(1)</script>"), $layout];
        yield 'enctype text/plain' => ['<form method="post" action="/x" enctype="text/plain"></form>', $page];
        yield 'enctype url-encoded' => ['<form method="post" action="/x" enctype="application/x-www-form-urlencoded"></form>', $page];
        yield 'enctype in upper case' => ['<form method="post" action="/x" ENCTYPE="multipart/form-data"></form>', $page];
        yield 'enctype value in upper case' => ['<form method="post" action="/x" enctype="MULTIPART/FORM-DATA"></form>', $page];
        yield 'enctype single-quoted' => ["<form method=\"post\" action=\"/x\" enctype='multipart/form-data'></form>", $page];
        yield 'enctype unquoted' => ['<form method="post" action="/x" enctype=multipart/form-data></form>', $page];
        yield 'enctype with spaces around =' => ['<form method="post" action="/x" enctype = "multipart/form-data"></form>', $page];
        yield 'enctype on a div' => ['<div enctype="multipart/form-data"></div>', $page];
        yield 'enctype on an input' => ['<form method="post" action="/x"><input enctype="multipart/form-data" /></form>', $page];
        yield 'formenctype on a button' => ['<form method="post" action="/x"><button type="submit" formenctype="text/plain">x</button></form>', $page];
        yield 'formenctype multipart on a button' => ['<form method="post" action="/x"><button type="submit" formenctype="multipart/form-data">x</button></form>', $page];
        yield 'enctype from output' => ['<form method="post" action="/x" enctype="<?= $this->e($data[\'t\']) ?>"></form>', $page];
        yield 'enctype with output after it' => ['<form method="post" action="/x" enctype="multipart/form-data<?= $this->e($data[\'t\']) ?>"></form>', $page];
        yield 'enctype with output before it' => ['<form method="post" action="/x" enctype="<?= $this->e($data[\'t\']) ?>multipart/form-data"></form>', $page];
        yield 'enctype twice on a form' => ['<form method="post" action="/x" enctype="multipart/form-data" enctype="text/plain"></form>', $page];
        yield 'enctype split by an empty PHP block' => ['<form method="post" action="/x" enc<?php ?>type="text/plain"></form>', $page];
    }

    public function testScannerAcceptsTheM3Forms(): void
    {
        $page = self::HEADER . <<<'HTML'
            <form method="post" action="<?= $this->url('/projects') ?>" enctype="multipart/form-data">
            <?= $this->partial('csrf') ?>
            </form>
            <form class="continue-form" method="post" action="<?= $this->url('/projects') ?>" data-auto-continue="1">
            <button class="button" type="submit" data-working="<?= $this->t('k') ?>"><?= $this->t('k') ?></button>
            </form>
            <progress aria-labelledby="progress-label" max="<?= $this->e($data['total']) ?>" value="<?= $this->e($data['done']) ?>"></progress>
            <input type="file" accept="<?= $this->e($data['accept']) ?>" required="required" />
            HTML;
        self::assertSame([], self::violations($page, self::PAGE));

        $all = "<head>\n" . implode("\n", self::SCRIPT_ELEMENTS) . "\n</head>";
        self::assertSame([], self::violations(self::HEADER . $all, self::LAYOUT));
        self::assertSame([], self::violations(self::HEADER . "<head>\n" . self::SCRIPT_ELEMENTS[2] . "\n</head>", self::LAYOUT));
    }

    public function testScannerAcceptsTheM2Forms(): void
    {
        $page = self::HEADER . <<<'HTML'
            <?php $facts = $this->fieldFacts($data['field']); ?>
            <label class="label" for="<?= $this->id('f', $data['key']) ?>"><?= $this->e($data['label']) ?></label>
            <p class="help" id="<?= $this->id('help', $data['key']) ?>"><?= $this->value($data['field'], $data['value']) ?></p>
            <?php if ($facts['type'] === 'text') : ?>
            <input class="input" id="<?= $this->id('f', $data['key']) ?>" name="<?= $this->e('f_' . $data['key']) ?>" value="<?= $this->inputValue($data['field'], $data['text'], false) ?>" aria-describedby="<?= $this->id('help', $data['key']) ?> <?= $this->id('err', $data['key']) ?>" />
            <?php endif; ?>
            <input aria-describedby="<?= $this->id('help', $data['key']) ?>" value="<?= $this->value($data['field'], $data['value'], 'cell') ?>" />
            <button type="submit" form="<?= $this->id('form', 1) ?>" formaction="<?= $this->url('/settings/reset') ?>"><?= $this->number(1234) ?></button>
            <section aria-labelledby="<?= $this->id('group', $data['key']) ?>"><span title="<?= $this->number(2) ?>"><?= $this->number(3) ?></span></section>
            HTML;
        self::assertSame([], self::violations($page, self::PAGE));

        $both = "<head>\n" . implode("\n", self::SCRIPT_ELEMENTS) . "\n</head>";
        self::assertSame([], self::violations(self::HEADER . $both, self::LAYOUT));
        self::assertSame([], self::violations(self::HEADER . "<head>\n" . self::SCRIPT_ELEMENTS[1] . "\n</head>", self::LAYOUT));
    }

    public function testScannerAcceptsTheLayoutHead(): void
    {
        $body = "<head>\n" . implode("\n", self::LAYOUT_METAS) . "\n" . self::SCRIPT_ELEMENT . "\n</head>\n<body>\n<section><select name=\"s\"><option>x</option></select></section>\n</body>";
        self::assertSame([], self::violations(self::HEADER . $body, self::LAYOUT));
        self::assertSame([], self::violations(self::HEADER . '<input type="hidden" name="_csrf" value="<?= $this->csrf() ?>" />', self::CSRF_PARTIAL));
    }

    public function testScannerAcceptsTheM1Forms(): void
    {
        $page = self::HEADER . <<<'HTML'
            <form method="post" action="<?= $this->url('/sign-out') ?>">
            <?= $this->partial('csrf') ?>
            <h2><?= $this->count('count.problems', count($data['problems'])) ?></h2>
            <a href="<?= $this->url('#' . $data['field']) ?>" aria-label="<?= $this->count('count.problems', 2) ?>">x</a>
            <span aria-hidden="true">/</span>
            <a href="/">x</a>
            </form>
            HTML;
        self::assertSame([], self::violations($page, self::PAGE));
        self::assertSame([], self::violations(self::HEADER . "<head>\n" . self::SCRIPT_ELEMENT . "\n</head>", self::LAYOUT));
    }

    public function testThePipeCheckDoesNotFlagOtherOperators(): void
    {
        $body = '<?php if (($data[\'a\'] | 2) > 1 || $data[\'b\'] >= 1) : ?><p></p><?php endif; ?>';
        self::assertSame([], self::violations(self::HEADER . $body, self::PAGE));
    }

    public function testTheComponentFixturesFollowTheRules(): void
    {
        $strings = self::strings();
        foreach (['components', 'notices', 'form-actions', 'm2-components', 'm3-components'] as $name) {
            $file = "tests/web/fixtures/templates/pages/{$name}.php";
            self::assertSame([], self::violations(RepoFiles::read($file), self::PAGE), $file);
            foreach (self::literalKeys(RepoFiles::read($file)) as $key) {
                self::assertArrayHasKey($key, $strings, $file);
            }
        }
    }

    #[DataProvider('badTemplates')]
    public function testScannerRejects(string $body, string $file): void
    {
        self::assertNotSame([], self::violations(self::HEADER . $body, $file));
    }

    public function testScannerRejectsAMissingHeader(): void
    {
        self::assertNotSame([], self::violations("<?php\ndeclare(strict_types=1);\n?>\n<p></p>", self::PAGE));
    }

    public function testScannerAcceptsTheDocumentedForms(): void
    {
        $body = <<<'HTML'
            <!DOCTYPE html>
            <html lang="en"<?= $this->rootClassAttribute() ?>>
            <title><?= $this->t('shell.title', ['title' => $data['title']]) ?></title>
            <link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>" />
            <?php if (is_array($data['items'] ?? null) && (count($data['items']) > 0)) : ?>
            <?php foreach ($data['items'] as $item) : ?>
            <a href="<?= $this->url($item['path'], $item['query']) ?>" aria-label="<?= $this->t('k') ?>"<?php if ($item['current'] === true) : ?> aria-current="true"<?php endif; ?>><?= $this->e($item['label']); ?></a>
            <?php endforeach; ?>
            <?php endif; ?>
            <!-- a comment -->
            <?= $this->partial('footer', ['sourceUrl' => $data['sourceUrl']]) ?>
            <?= $this->slot('content') ?>
            HTML;

        self::assertSame([], self::violations(self::HEADER . $body, self::LAYOUT));
        self::assertSame(
            [],
            self::violations(self::HEADER . '<a href="<?= $this->e($data[\'sourceUrl\']) ?>" rel="noopener noreferrer">x</a>', self::FOOTER),
        );
    }

    // T-WEB-26: Ids::make() (M2 spec part 2 section 4.2).

    public function testIdsAreBuiltFromValidatedParts(): void
    {
        self::assertSame('f-abcdefghijkl', Ids::make('f', 'abcdefghijkl'));
        self::assertSame('group-abcdefghijkl', Ids::make('group', 'abcdefghijkl'));
        self::assertSame('err-a2-12', Ids::make('err', 'a2', 12));
        self::assertSame('filter-field-x', Ids::make('filter-field', 'x'));
    }

    /** @return iterable<string, array{string, list<string|int>}> */
    public static function badIds(): iterable
    {
        yield 'empty prefix' => ['', ['a']];
        yield 'prefix starting with a digit' => ['1f', ['a']];
        yield 'prefix starting with a dash' => ['-f', ['a']];
        yield 'upper-case prefix' => ['F', ['a']];
        yield 'prefix with a quote' => ['f"', ['a']];
        yield 'prefix too long' => [str_repeat('a', 17), ['a']];
        yield 'no parts' => ['f', []];
        yield 'empty part' => ['f', ['']];
        yield 'part with a dash' => ['f', ['a-b']];
        yield 'part with a space' => ['f', ['a b']];
        yield 'part with a quote' => ['f', ['a"']];
        yield 'part with markup' => ['f', ['<b>']];
        yield 'upper-case part' => ['f', ['ABC']];
        yield 'part too long' => ['f', [str_repeat('a', 25)]];
        yield 'negative number' => ['f', [-1]];
        yield 'part with a newline' => ['f', ["a\n"]];
    }

    /** @param list<string|int> $parts */
    #[DataProvider('badIds')]
    public function testIdsRefuseBadPrefixesAndParts(string $prefix, array $parts): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ids::make($prefix, ...$parts);
    }

    public function testLiteralKeysAreFound(): void
    {
        self::assertSame(
            ['a.b', 'c.d'],
            self::literalKeys(self::HEADER . "<?= \$this->t('a.b') ?><?= \$this->t( 'c.d', ['x' => 1]) ?><?= \$this->t(\$data['k']) ?>"),
        );
    }
}
