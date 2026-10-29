<?php
// Words in Chinese and Japanese (mygo's 5a134b4): each Han ideograph, Hiragana
// and Katakana is a word of its own, as platforms segment without a
// dictionary; Latin, digits, _ and Hangul keep their runs; script changes are
// boundaries. Text fields, the code editor, rich text and Markdown's reveal
// by words all go so.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Text\Boundaries;
use MyPHP\UI\Markdown\{RevealState, RevealUnit};
use MyPHP\UI\TextState;

$cps = fn(string $s): array => array_map('mb_ord', mb_str_split($s, 1, 'UTF-8'));
$alone = true;
foreach ($cps('我们公园今日直骨々あをはカタナーｶｰ') as $r) { $alone = $alone && Boundaries::standsAlone($r) && Boundaries::isWordRune($r); }
check($alone, 'ideographs and kana stand alone');
$none = true;
foreach ($cps("aZé_9жω한글ไทย，。、「」 \n　！１") as $r) { $none = $none && !Boundaries::standsAlone($r); }
check($none, 'spaced scripts, Hangul, digits, punctuation and spaces do not');
foreach ([['a', 'b', false], ['a', '_', false], ['한', '글', false], ['a', ' ', true], [',', ' ', false], ['a', '中', true], ['今', '日', true], ['カ', 'タ', true], ['中', '，', true]] as [$a, $b, $want]) {
    same($want, Boundaries::wordBoundary(mb_ord($a), mb_ord($b)), "a boundary between $a and $b");
}
$walk = function (string $s, bool $forward) use ($cps): array {
    $r = $cps($s); $stops = []; $i = $forward ? 0 : count($r);
    while (true) { $j = $forward ? Boundaries::wordEnd($r, $i) : Boundaries::wordStart($r, $i); if ($j === $i) { return $stops; } $stops[] = $i = $j; }
};
foreach ([
    ['hello brave new_world', [5, 11, 21], [12, 6, 0]], ['한국어 문장', [3, 6], [4, 0]], ['我们去公园', [1, 2, 3, 4, 5], [4, 3, 2, 1, 0]],
    ['今天，好', [1, 2, 4], [3, 1, 0]], ['Go语言 is fun', [2, 3, 4, 7, 11], [8, 5, 3, 2, 0]], ['カタカナとabc', [1, 2, 3, 4, 5, 8], [5, 4, 3, 2, 1, 0]],
] as [$s, $right, $left]) {
    same([$right, $left], [$walk($s, true), $walk($s, false)], "the words of $s");
}
$r = $cps("say 我们去，ok_1\nx");
foreach ([[0, 0, 3], [3, 3, 4], [4, 4, 5], [5, 5, 6], [7, 7, 8], [8, 8, 12], [11, 8, 12], [12, 12, 12], [13, 13, 14]] as [$i, $a, $z]) {
    same([$a, $z], Boundaries::wordAround($r, $i), "the word around $i");
}

// A text field: Option and the arrows, and a double click.
$t = new TextState('Go语言 今日は');
$t->cursor = $t->anchor = 0;
$stops = [];
for ($k = 0; $k < 6; $k++) { $t->command('moveWordRight:'); $stops[] = mb_strlen(substr($t->text, 0, $t->cursor)); }
same([2, 3, 4, 6, 7, 8], $stops, 'a field moves by a character in Chinese and Japanese');
$t->cursor = $t->anchor = strlen('Go语');
$t->selectWord();
same('言', $t->selectedText(), 'a double click on an ideograph takes it alone');

// The code editor's words.
$rope = \MyPHP\UI\Editor\RopeText::new('say 我们，ok');
foreach ([[0, 0, 3], [4, 4, 7], [5, 4, 7], [7, 7, 10], [10, 7, 10], [13, 13, 15]] as [$off, $a, $z]) {
    same([$a, $z], \MyPHP\UI\Editor\Chars::wordAt($rope, $off, ''), "the editor's word at $off");
}
$rope = \MyPHP\UI\Editor\RopeText::new('Go语言 今日は');
$at = 0; $stops = [];
for ($k = 0; $k < 6; $k++) { $stops[] = $at = \MyPHP\UI\Editor\Chars::wordRight($rope, $at, '', false); }
same([2, 5, 8, 12, 15, 18], $stops, 'the editor moves a character at a time in Chinese and Japanese');

// Markdown's reveal by words: up to an ideograph, past the one it is at.
$src = 'Go语言 is 今日は。';
foreach ([[0, 2], [1, 2], [2, 5], [3, 5], [5, 9], [9, 12], [12, 15], [15, 18], [18, 21], [21, 24]] as [$i, $want]) {
    same($want, RevealState::snap($src, $i, strlen($src), RevealUnit::ByWord), "the reveal from $i");
}
