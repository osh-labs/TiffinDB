<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;

// Group X: declarations, entities, instructions (M5 spec section 12.2). None reaches a parser.
$m = C::MARKER;
$refuse = static fn (string $id, string $text, string $reason, ?int $line): array => [
    'id' => $id, 'text' => $text, 'verdict' => 'refuse', 'reason' => $reason, 'line' => $line,
];
$laughs = '<!DOCTYPE div [' . "\n" . '<!ENTITY a0 "' . $m . '">' . "\n";
for ($i = 1; $i <= 9; $i++) {
    $laughs .= '<!ENTITY a' . $i . ' "' . str_repeat('&a' . ($i - 1) . ';', 10) . '">' . "\n";
}
$laughs .= ']>' . "\n";

return [
    $refuse('X1', '<!DOCTYPE div [<!ENTITY a "' . $m . '">]>' . "\n" . C::f('<p>&a;</p>'), 'declaration', 1),
    $refuse('X2', $laughs . C::f('<p>&a9;</p>'), 'declaration', 1),
    $refuse('X3', '<!DOCTYPE div [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . C::f('<p>' . $m . '&x;</p>'), 'declaration', 1),
    $refuse('X4', '<!DOCTYPE div [<!ENTITY % p SYSTEM "https://example.com/x.dtd"> %p;]>' . C::f('<p>' . $m . '</p>'), 'declaration', 1),
    $refuse('X5', '<!DOCTYPE html>' . "\n" . C::f('<p>' . $m . '</p>'), 'declaration', 1),
    $refuse('X6', C::f('<p><![CDATA[<script>' . $m . '</script>]]></p>'), 'declaration', 2),
    $refuse('X7', '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . C::f('<p>' . $m . '</p>'), 'processing-instruction', 1),
    $refuse('X8', C::f('<?php echo "' . $m . '"; ?>'), 'processing-instruction', 2),
    $refuse('X9', '<?xml-stylesheet href="' . $m . '"?>' . C::f(''), 'processing-instruction', 1),
    $refuse('X10', '<!ELEMENT p ANY>' . C::f('<p>' . $m . '</p>'), 'declaration', 1),
    $refuse('X11', '<!ATTLIST p a CDATA "' . $m . '">' . C::f(''), 'declaration', 1),
    $refuse('X12', C::f('<![INCLUDE[ ' . $m . ' ]]>'), 'declaration', 2),
    $refuse('X13', C::f('<p>' . $m . '&a;</p>'), 'malformed', 2),
    // X14: the pre-scan is lexical and does not know comments; refusing is the safe side.
    $refuse('X14', C::f('<!-- <!DOCTYPE x> ' . $m . ' -->'), 'declaration', 2),
    // Extra: lower case, a declaration late in the text, an instruction after an escaped one, a bare `<!`.
    $refuse('X15', '<!doctype html>' . C::f('<p>' . $m . '</p>'), 'declaration', 1),
    $refuse('X16', C::f("<p>a</p>\n\n<!ENTITY e \"" . $m . '">'), 'declaration', 4),
    $refuse('X17', C::f(C::slot(' data-tiffin-label="&lt;?' . $m . '"') . '<p><?x ?></p>'), 'processing-instruction', 2),
    $refuse('X18', C::f('<p>' . $m . '<!x></p>'), 'declaration', 2),
    // X19: a reference to an ampersand is text, never a second round of references.
    [
        'id' => 'X19', 'text' => C::f('<p>&#x26;nbsp;&amp;lt;</p>'), 'verdict' => 'accept',
        'canonical' => C::canonical('<p>&amp;nbsp;&amp;lt;</p>'),
    ],
    // X20, X21: NODE_TYPE cannot be reached through parse(), whose pre-scan refuses every declaration first
    // ('declaration'); these are fed to the reader stage alone ('stage' => 'reader'), which must still refuse.
    [
        'id' => 'X20', 'text' => '<!DOCTYPE div [<!ENTITY y "' . $m . '">]>' . C::f('<p>&y;</p>'), 'verdict' => 'refuse',
        'reason' => 'node-type', 'line' => null, 'stage' => 'reader', 'parse' => 'declaration',
    ],
    [
        'id' => 'X21', 'text' => C::f('<p>x</p>') . '<?pi ' . $m . '?>', 'verdict' => 'refuse',
        'reason' => 'node-type', 'line' => 2, 'stage' => 'reader', 'parse' => 'processing-instruction',
    ],
];
