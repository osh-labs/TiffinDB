<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-WEB-11: the escaping rule and the other template source rules
 * (ADR 0008 decisions 4 and 5; M0 spec sections 6.8 and 15.2).
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
 *   variable variable, superglobal, or backtick.
 * - The static markup, with every PHP block removed (so markup split by an
 *   empty PHP block is seen whole), has no inline style or script, no
 *   event-handler attribute, no `javascript:`, external, or
 *   protocol-relative URL, and no character reference or scheme at the
 *   start of a URL attribute.
 * - Each `<?=` sits in element text, or is the whole value of a
 *   double-quoted attribute (not `class`, `id`, `style`, or `on*`; in a URL
 *   attribute only through url() or asset(), or e() for the footer's
 *   configured source link). The layout accessor may sit in the `<html>`
 *   start tag. Never in a comment or elsewhere in a tag.
 * - Every literal string key passed to t() exists, and every key in the
 *   strings file is used, except the keys code builds at run time.
 */
#[CoversNothing]
final class TemplateRulesTest extends TestCase
{
    /** Helpers any template may call (ADR 0008 decision 4). */
    public const HELPERS = ['e', 't', 'url', 'asset', 'partial', 'slot'];

    /** Accessors only the layout may call, each returning markup built from server-side constants. */
    public const LAYOUT_ACCESSORS = ['rootClassAttribute'];

    public const LAYOUT = 'templates/layout.php';

    /** The one template that prints a configured (not generated) URL, with e(). */
    public const FOOTER = 'templates/partials/footer.php';

    /** Plain functions a template may call: pure checks that print nothing. */
    public const ALLOWED_FUNCTIONS = ['is_array', 'is_string', 'is_int', 'is_bool', 'count', 'array_key_exists', 'in_array'];

    /**
     * String keys built at run time, not written literally in a template:
     * the error page keys (Kernel: error.<status>.title and .body) and the
     * theme preview link labels (HomeController: home.preview.<theme>).
     */
    public const RUNTIME_KEYS = [
        'error.400.title', 'error.400.body',
        'error.404.title', 'error.404.body',
        'error.405.title', 'error.405.body',
        'error.500.title', 'error.500.body',
        'home.preview.light', 'home.preview.dark', 'home.preview.hc-light', 'home.preview.hc-dark',
    ];

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

        array_push($problems, ...self::markupProblems($html));
        array_push($problems, ...self::outputContextProblems($html, $echoes, $file));

        return $problems;
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
            '#["\'=]\s*\\\\#' => 'a backslash-relative URL',
            '/<\?/' => 'a short open tag or a stray <?',
        ];
        $problems = [];
        foreach ($rules as $pattern => $what) {
            if (preg_match($pattern, $html) === 1) {
                $problems[] = "Markup contains {$what}.";
            }
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
                    'text' => in_array($helper, self::LAYOUT_ACCESSORS, true)
                        ? 'a layout accessor outside the <html> start tag' : null,
                    'comment' => 'output inside a comment',
                    'tag' => $file === self::LAYOUT && $tagName === 'html' && $n === 0
                        && in_array($helper, self::LAYOUT_ACCESSORS, true)
                        ? null : 'output inside a tag, outside an attribute value',
                    'dq' => self::attributeOutputProblem($html, $pos, $valueStart, $n, $attribute, $helper, $file),
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

    /** Why output at this point of a double-quoted value is not allowed, or null. */
    private static function attributeOutputProblem(
        string $html,
        int $pos,
        int $valueStart,
        int $index,
        string $attribute,
        string $helper,
        string $file,
    ): ?string {
        if ($pos !== $valueStart || $index !== 0 || ($html[$pos] ?? '') !== '"') {
            return "output that is not the whole value of the {$attribute} attribute";
        }
        if (in_array($attribute, ['class', 'id', 'style'], true) || str_starts_with($attribute, 'on')) {
            return "output in the {$attribute} attribute";
        }
        if (in_array($attribute, self::URL_ATTRIBUTES, true)) {
            $allowed = ['url', 'asset'];
            if ($file === self::FOOTER && $attribute === 'href') {
                $allowed[] = 'e';
            }

            return in_array($helper, $allowed, true) ? null : "{$helper}() output in the URL attribute {$attribute}";
        }

        return in_array($helper, ['e', 't'], true) ? null : "{$helper}() output in the attribute {$attribute}";
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

    // The real templates.

    public function testTheShellTemplatesExist(): void
    {
        foreach (['layout', 'partials/top-bar', 'partials/mark', 'partials/footer', 'pages/home', 'pages/error'] as $name) {
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

    public function testEveryStringIsUsed(): void
    {
        $used = [];
        foreach (self::templates() as $file) {
            foreach (self::literalKeys(RepoFiles::read($file)) as $key) {
                $used[$key] = true;
            }
        }
        foreach (self::RUNTIME_KEYS as $key) {
            $used[$key] = true;
        }

        $unused = array_values(array_diff(array_keys(self::strings()), array_keys($used)));
        self::assertSame([], $unused, 'Keys in strings/en.php that nothing uses.');
    }

    public function testRuntimeKeysExist(): void
    {
        $strings = self::strings();
        foreach (self::RUNTIME_KEYS as $key) {
            self::assertArrayHasKey($key, $strings);
        }
    }

    public function testStringsArePlainText(): void
    {
        foreach (self::strings() as $key => $text) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $key);
            self::assertNotSame('', trim($text), $key);
            self::assertDoesNotMatchRegularExpression('/[<>]/', $text, "{$key}: strings hold text, not markup.");
            self::assertDoesNotMatchRegularExpression('#https?://#i', $text, "{$key}: no addresses in strings.");
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
            if ($file === 'templates/partials/top-bar.php') {
                $text = (string) preg_replace('/\bTiffin\b|\bDB\b/', ' ', $text);
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

    public function testLiteralKeysAreFound(): void
    {
        self::assertSame(
            ['a.b', 'c.d'],
            self::literalKeys(self::HEADER . "<?= \$this->t('a.b') ?><?= \$this->t( 'c.d', ['x' => 1]) ?><?= \$this->t(\$data['k']) ?>"),
        );
    }
}
