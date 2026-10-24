<?php
// Plural categories (mygo's plural_test.go): the operands of numbers, the
// categories of languages, CLDR's samples of each category and ICU's
// categories of numbers (tests/i18n, mygo's testdata).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{I18n, Operands, Plural};

$big = 1_000_000_000_000_000_000;
$ops = fn(Operands $o) => [$o->i, $o->v, $o->w, $o->f, $o->t, $o->c];
foreach ([
    [0, [0, 0, 0, 0, 0, 0]], [1, [1, 0, 0, 0, 0, 0]], [-5, [5, 0, 0, 0, 0, 0]], [PHP_INT_MIN, [223_372_036_854_775_808 + $big, 0, 0, 0, 0, 0]],
    [1.5, [1, 1, 1, 5, 5, 0]], [0.25, [0, 2, 2, 25, 25, 0]], [1.0, [1, 0, 0, 0, 0, 0]], ['1.0', [1, 1, 0, 0, 0, 0]], ['1.50', [1, 2, 1, 50, 5, 0]],
    ['-1.50', [1, 2, 1, 50, 5, 0]], ['1.03', [1, 2, 2, 3, 3, 0]], ['1.230', [1, 3, 2, 230, 23, 0]], ['0.001', [0, 3, 3, 1, 1, 0]], ['1200000', [1200000, 0, 0, 0, 0, 0]],
    ['1.2c6', [1200000, 0, 0, 0, 0, 6]], ['1.2e6', [1200000, 0, 0, 0, 0, 6]], ['123c6', [123000000, 0, 0, 0, 0, 6]], ['1.234c2', [123, 1, 1, 4, 4, 2]], ['1c3', [1000, 0, 0, 0, 0, 3]],
    ['.5', [0, 1, 1, 5, 5, 0]], ['1000000000000000000001', [1 + $big, 0, 0, 0, 0, 0]], ['1.0000000000000000000000', [1, 22, 0, 0, 0, 0]], [new Operands(3), [3, 0, 0, 0, 0, 0]],
] as [$in, $want]) {
    same($want, $ops(Operands::of($in)), 'the operands of ' . var_export($in instanceof Operands ? 'Operands' : $in, true));
}
$bad = 0;
foreach (['', '-', '1.', '1.2.3', 'abc', '1e', '1c-2', ' ', NAN, INF, '1,5'] as $s) {
    try { Operands::of($s); } catch (\InvalidArgumentException) { $bad++; }
}
same(11, $bad, 'what is no number has no operands');

same([Plural::Zero, null], [Plural::tryFrom('zero'), Plural::tryFrom('single')], 'keywords read');
$cats = fn(string $tag, bool $ordinal = false) => array_map(fn(Plural $p) => $p->value, $ordinal ? I18n::ordinalCategories($tag) : I18n::pluralCategories($tag));
same([['one', 'other'], ['one', 'other'], ['one', 'two', 'few', 'other'], ['other'], ['other'], ['zero', 'one', 'two', 'few', 'many', 'other'], ['one', 'few', 'many', 'other'], ['one', 'many', 'other'], ['one', 'many', 'other'], ['other'], ['other'], ['one', 'other']],
    [$cats('en'), $cats('en-GB'), $cats('en', true), $cats('ja'), $cats('zh-Hant-TW'), $cats('ar'), $cats('ru'), $cats('fr'), $cats('pt-PT'), $cats('xx'), $cats(''), $cats('de-DE-u-nu-latn')], 'the categories of languages');

$cat = fn(string $tag, $n, bool $ordinal = false) => ($ordinal ? I18n::ordinalCategory($tag, $n) : I18n::pluralCategory($tag, $n))->value;
$got = $want = [];
foreach ([['en', 1, false, 'one'], ['en', '1.0', false, 'other'], ['en', 2, false, 'other'], ['en', 0, false, 'other'], ['en', 1, true, 'one'], ['en', 22, true, 'two'], ['en', 113, true, 'other'], ['en', 103, true, 'few'],
    ['fr', 0, false, 'one'], ['fr', 1.5, false, 'one'], ['fr', 1000000, false, 'many'], ['fr', '1c6', false, 'many'], ['fr', '1.2c6', false, 'many'], ['fr', '1c3', false, 'other'],
    ['ru', 1, false, 'one'], ['ru', 3, false, 'few'], ['ru', 5, false, 'many'], ['ru', 11, false, 'many'], ['ru', 21, false, 'one'], ['ru', 1.5, false, 'other'],
    ['ar', 0, false, 'zero'], ['ar', 102, false, 'other'], ['ar', 103, false, 'few'], ['ar', 111, false, 'many'], ['ar', '1.0', false, 'one'],
    ['pl', '1000000000000000000021', false, 'many'], ['lv', '0.1', false, 'one'], ['lv', '0.10', false, 'other'], ['lv', 10, false, 'zero'], ['ja', 1, false, 'other']] as [$tag, $n, $ord, $w]) {
    $got[] = "$tag $n " . $cat($tag, $n, $ord); $want[] = "$tag $n $w";
}
same($want, $got, 'the categories of numbers');

// CLDR's samples of each category, every range written out.
$expand = function (string $s): array {
    $out = [];
    foreach (explode('@', $s) as $group) {
        $parts = explode(' ', trim($group), 2);
        if (count($parts) < 2) { continue; }
        foreach (explode(',', $parts[1]) as $item) {
            $item = trim($item);
            if ($item === '' || $item === '…') { continue; }
            if (!str_contains($item, '~')) { $out[] = $item; continue; }
            [$lo, $hi] = explode('~', $item);
            $frac = strlen(explode('.', $lo . '.')[1]);
            for ($n = (int)str_replace('.', '', $lo); $n <= (int)str_replace('.', '', $hi); $n++) {
                $s = (string)$n;
                if ($frac > 0) { $s = str_pad($s, $frac + 1, '0', STR_PAD_LEFT); $s = substr($s, 0, -$frac) . '.' . substr($s, -$frac); }
                $out[] = $s;
            }
        }
    }
    return $out;
};
$checked = 0; $wrong = [];
foreach (file(__DIR__ . '/i18n/plural_samples.txt', FILE_IGNORE_NEW_LINES) as $line) {
    if ($line === '' || $line[0] === '#') { continue; }
    [$kind, $langs, $w, $samples] = explode(' ', $line, 4);
    foreach ($expand($samples) as $s) {
        foreach (explode(',', $langs) as $lang) {
            if (($g = $cat($lang, $s, $kind === 'ordinal')) !== $w) { $wrong[] = "$lang $kind $s = $g, want $w"; }
            $checked++;
        }
    }
}
same([], array_slice($wrong, 0, 5), 'CLDR\'s samples');
check($checked >= 10000, "$checked samples checked");

// ICU's categories of numbers (Intl.PluralRules), by their initials.
$golden = json_decode(file_get_contents(__DIR__ . '/i18n/plurals_icu.json'), true, flags: JSON_THROW_ON_ERROR);
$initials = ['z' => 'zero', 'o' => 'one', 't' => 'two', 'f' => 'few', 'm' => 'many', 'x' => 'other'];
$langs = 0; $wrong = [];
foreach ([[false, $golden['cardinal'] ?? $golden['Cardinal']], [true, $golden['ordinal'] ?? $golden['Ordinal']]] as [$ord, $byLang]) {
    foreach ($byLang as $lang => $w) {
        $langs++;
        foreach ($golden['numbers'] ?? $golden['Numbers'] as $i => $n) {
            if (($g = $cat((string)$lang, $n, $ord)) !== $initials[$w[$i]]) { $wrong[] = "$lang $n = $g, ICU says {$initials[$w[$i]]}"; }
        }
    }
}
same([], array_slice($wrong, 0, 5), 'ICU\'s categories');
check($langs >= 250, "$langs languages compared");
