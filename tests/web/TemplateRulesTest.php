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
 * The scanner works on PHP tokens, not on text patterns:
 *
 * - Every `<?=` is exactly one call to a documented scope helper,
 *   `$this->name(...)`, followed only by an optional `;` and `?>`. The
 *   layout-only accessors are allowed in `templates/layout.php` only.
 * - `$this` may be used only to call those helpers: no other method (such
 *   as the internal setSlot() or run()), no property.
 * - No echo, print, or other output, include, eval, exit, closure, class,
 *   static call, variable variable, superglobal, or backtick. Function
 *   calls are limited to a short list of pure type checks.
 * - The markup has no inline style or script, no event-handler attribute,
 *   no `javascript:` or external URL, and no output in an unquoted
 *   attribute value.
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
    ];

    private const SUPERGLOBALS = ['$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_COOKIE', '$_FILES', '$_ENV', '$_REQUEST', '$_SESSION'];

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
     * @return list<string>
     */
    public static function violations(string $source, bool $isLayout): array
    {
        $problems = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        $allowedMethods = $isLayout ? [...self::HELPERS, ...self::LAYOUT_ACCESSORS] : self::HELPERS;

        // Header: SPDX block first, then strict types, then markup.
        if (preg_match('#\A<\?php\n/\*\n \* SPDX-License-Identifier: AGPL-3\.0-or-later\n \* Copyright \(C\) TiffinDB contributors\n#', $source) !== 1) {
            $problems[] = 'The file must start with <?php and the SPDX block.';
        }
        if (preg_match('#\*/\s*declare\(strict_types=1\);\s*\?>#', $source) !== 1) {
            $problems[] = 'The SPDX block must be followed by declare(strict_types=1); and ?>.';
        }

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
            if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true)) {
                $prev = self::previousCode($tokens, $i);
                $next = self::nextCode($tokens, $i);
                if ($next === '(' && $prev !== '->' && $prev !== 'function') {
                    $name = strtolower(ltrim($text, '\\'));
                    if (!in_array($name, self::ALLOWED_FUNCTIONS, true)) {
                        $problems[] = "Forbidden function call: {$text}(){$line}.";
                    }
                }
            }
            if ($id === T_OPEN_TAG_WITH_ECHO) {
                $problem = self::echoProblem($tokens, $i, $allowedMethods);
                if ($problem !== null) {
                    $problems[] = $problem . $line . '.';
                }
                $before = $i > 0 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_INLINE_HTML ? $tokens[$i - 1][1] : '';
                if (preg_match('/=\s*$/', $before) === 1) {
                    $problems[] = "Output in an unquoted attribute value{$line}.";
                }
                if (preg_match("/=\s*'\$/", $before) === 1) {
                    $problems[] = "Output in a single-quoted attribute value{$line}.";
                }
            }
            if ($id === T_INLINE_HTML) {
                array_push($problems, ...self::markupProblems($text));
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function markupProblems(string $html): array
    {
        $rules = [
            '/<\s*script\b/i' => 'a <script> element',
            '/<\s*style\b/i' => 'a <style> element',
            '/\sstyle\s*=/i' => 'a style attribute',
            '/\son[a-z]+\s*=/i' => 'an event-handler attribute',
            '/javascript:/i' => 'a javascript: address',
            '#https?://#i' => 'an external URL',
            '/<\?/' => 'a short open tag or a stray <?',
        ];
        $problems = [];
        foreach ($rules as $pattern => $what) {
            if (preg_match($pattern, $html) === 1) {
                $problems[] = "Markup contains {$what}.";
            }
        }

        return $problems;
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

    /** @param list<mixed> $tokens */
    private static function nextCodeIndex(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($n = $i + 1; $n < $count; $n++) {
            if (!is_array($tokens[$n]) || !in_array($tokens[$n][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $n;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function nextCode(array $tokens, int $i): ?string
    {
        $n = self::nextCodeIndex($tokens, $i);
        if ($n === null) {
            return null;
        }

        return is_array($tokens[$n]) ? $tokens[$n][1] : $tokens[$n];
    }

    /** @param list<mixed> $tokens */
    private static function previousCode(array $tokens, int $i): ?string
    {
        for ($n = $i - 1; $n >= 0; $n--) {
            if (!is_array($tokens[$n]) || !in_array($tokens[$n][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return is_array($tokens[$n]) ? $tokens[$n][1] : $tokens[$n];
            }
        }

        return null;
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
        self::assertSame([], self::violations(RepoFiles::read($file), $file === self::LAYOUT), $file);
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

    /** @return iterable<string, array{string, bool}> */
    public static function badTemplates(): iterable
    {
        yield 'raw variable' => ['<p><?= $data[\'x\'] ?></p>', false];
        yield 'echo' => ['<?php echo $data[\'x\']; ?>', false];
        yield 'print' => ['<?php print $data[\'x\']; ?>', false];
        yield 'printf' => ['<?php printf(\'%s\', $data[\'x\']); ?>', false];
        yield 'qualified printf' => ['<?php \printf(\'%s\', $data[\'x\']); ?>', false];
        yield 'helper then concatenation' => ['<p><?= $this->e($data[\'a\']) . $data[\'b\'] ?></p>', false];
        yield 'helper then second statement' => ['<p><?= $this->e($data[\'a\']); $data[\'b\'] ?></p>', false];
        yield 'ternary around helper' => ['<p><?= $this->e(1) ? $data[\'b\'] : \'\' ?></p>', false];
        yield 'unknown helper' => ['<p><?= $this->raw($data[\'x\']) ?></p>', false];
        yield 'setSlot' => ['<?php $this->setSlot(\'content\', \'x\'); ?>', false];
        yield 'run' => ['<?php $this->run(\'/etc/passwd\', []); ?>', false];
        yield 'setSlot inside a helper argument' => ['<?= $this->e($this->setSlot(\'a\', \'b\')) ?>', false];
        yield 'property of $this' => ['<?php $v = $this->view; ?>', false];
        yield 'dynamic method' => ['<?= $this->{$data[\'m\']}() ?>', false];
        yield 'layout accessor outside the layout' => ['<html<?= $this->rootClassAttribute() ?>>', false];
        yield 'method on data' => ['<?= $this->e($data[\'o\']->name()) ?>', false];
        yield 'include' => ['<?php include \'x.php\'; ?>', false];
        yield 'eval' => ['<?php eval(\'1;\'); ?>', false];
        yield 'superglobal' => ['<?= $this->e($_GET[\'x\']) ?>', false];
        yield 'variable variable' => ['<?php $$name = 1; ?>', false];
        yield 'closure' => ['<?php $f = function () {}; ?>', false];
        yield 'static call' => ['<?= $this->e(Foo::bar()) ?>', false];
        yield 'new object' => ['<?= $this->e(new Foo()) ?>', false];
        yield 'backtick' => ['<?= $this->e(`ls`) ?>', false];
        yield 'exit' => ['<?php exit; ?>', false];
        yield 'style attribute' => ['<p style="color: red">x</p>', false];
        yield 'style element' => ['<style>p {}</style>', false];
        yield 'script element' => ['<script src="/a.js"></script>', false];
        yield 'event handler' => ['<a onclick="x()">x</a>', false];
        yield 'javascript address' => ['<a href="javascript:x()">x</a>', false];
        yield 'external address' => ['<a href="https://example.org/">x</a>', false];
        yield 'unquoted attribute output' => ['<a href=<?= $this->url(\'/\') ?>>x</a>', false];
        yield 'single-quoted attribute output' => ["<a href='<?= \$this->url('/') ?>'>x</a>", false];
    }

    #[DataProvider('badTemplates')]
    public function testScannerRejects(string $body, bool $isLayout): void
    {
        self::assertNotSame([], self::violations(self::HEADER . $body, $isLayout));
    }

    public function testScannerRejectsAMissingHeader(): void
    {
        self::assertNotSame([], self::violations("<?php\ndeclare(strict_types=1);\n?>\n<p></p>", false));
    }

    public function testScannerAcceptsTheDocumentedForms(): void
    {
        $body = <<<'HTML'
            <html lang="en"<?= $this->rootClassAttribute() ?>>
            <title><?= $this->t('shell.title', ['title' => $data['title']]) ?></title>
            <link href="<?= $this->asset('css/app.css') ?>" />
            <?php if (is_array($data['items'] ?? null)) : ?>
            <?php foreach ($data['items'] as $item) : ?>
            <a href="<?= $this->url($item['path'], $item['query']) ?>"<?php if ($item['current'] === true) : ?> aria-current="true"<?php endif; ?>><?= $this->e($item['label']); ?></a>
            <?php endforeach; ?>
            <?php endif; ?>
            <?= $this->partial('footer', ['sourceUrl' => $data['sourceUrl']]) ?>
            <?= $this->slot('content') ?>
            HTML;

        self::assertSame([], self::violations(self::HEADER . $body, true));
    }

    public function testLiteralKeysAreFound(): void
    {
        self::assertSame(
            ['a.b', 'c.d'],
            self::literalKeys(self::HEADER . "<?= \$this->t('a.b') ?><?= \$this->t( 'c.d', ['x' => 1]) ?><?= \$this->t(\$data['k']) ?>"),
        );
    }
}
