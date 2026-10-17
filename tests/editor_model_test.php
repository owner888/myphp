<?php
// The editor's model below the view: selections merging, the characters
// of words and graphemes, and the key bindings.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Editor\{Binding, Chars, Command, Keymap, RopeText, Sel};
use MyPHP\UI\Key;

// Selections merge as Zed's: overlapping ones, and a caret touching a selection; two selections that only touch stay apart.
$m = fn(array $sels) => array_map(fn(Sel $s) => [$s->anchor, $s->head, $s->id], Sel::merge($sels));
same([[0, 5, 2]], $m([new Sel(0, 3, -1, 1), new Sel(2, 5, -1, 2)]), 'overlapping selections merge, the newer\'s identity kept');
same([[5, 0, 2]], $m([new Sel(0, 3, -1, 1), new Sel(5, 2, -1, 2)]), 'and its direction');
same([[0, 3, 1], [3, 6, 2]], $m([new Sel(0, 3, -1, 1), new Sel(3, 6, -1, 2)]), 'two selections that only touch stay apart');
same([[0, 3, 2]], $m([new Sel(0, 3, -1, 1), new Sel(3, 3, -1, 2)]), 'a caret touching a selection merges into it');
same([[1, 1, 1], [4, 4, 3]], $m([new Sel(4, 4, -1, 3), new Sel(1, 1, -1, 1)]), 'carets sort');
same(1, Sel::newest([new Sel(0, 0, -1, 2), new Sel(1, 1, -1, 7), new Sel(2, 2, -1, 3)]), 'the newest selection');

// Words and subwords.
$t = RopeText::new("foo_bar HTTPServer  x\nnext");
same(7, Chars::wordRight($t, 0, '', false), 'a word to its end');
same(3, Chars::wordRight($t, 0, '', true), 'a subword stops at the underscore');
same(4, Chars::wordRight($t, 3, '', true), 'then takes the underscore');
same(12, Chars::wordRight($t, 8, '', true), 'HTTPServer: HTTP ends where a capital precedes lower case');
same(18, Chars::wordRight($t, 12, '', true), 'then Server');
same(21, Chars::wordRight($t, 18, '', false), 'spaces are skipped to the word after');
same(22, Chars::wordRight($t, 21, '', false), 'a newline is a step of its own');
same(0, Chars::wordLeft($t, 7, '', false), 'a word back to its start');
same(4, Chars::wordLeft($t, 7, '', true), 'a subword back to the underscore');
same(8, Chars::wordLeft($t, 18, '', false), 'back over a word after spaces');
same([8, 18], Chars::wordAt($t, 12, ''), 'the word around an offset');
same([0, 7], Chars::wordAt($t, 3, ''), '_ is a word character: the whole identifier');
same([1, 3], Chars::wordAt(RopeText::new('a.;b'), 2, ''), 'the run of punctuation at an offset');
same([0, 1], Chars::wordAt(RopeText::new('a.;b'), 1, ''), 'at the end of a word before punctuation, the word');
same([0, 7], Chars::wordAt($t, 7, ''), 'at the end of a word, the word');
same(Chars::WORD, Chars::classOf('é', ''), 'a letter outside ASCII is a word character');
same(Chars::SPACE, Chars::classOf("\u{3000}", ''), 'an ideographic space is space');
same(Chars::PUNCT, Chars::classOf('。', ''), 'ideographic punctuation is punctuation');

// Graphemes: combining marks, joiners, flags and CRLF step as one.
$g = RopeText::new("e\u{301}x\u{1F469}\u{200D}\u{1F4BB}y\u{1F1EB}\u{1F1F7}\u{1F1EF}\u{1F1F5}z\r\nq");
$steps = []; for ($off = 0; $off < $g->len();) { $off = Chars::graphemeRight($g, $off); $steps[] = $off; }
same([3, 4, 15, 16, 24, 32, 33, 35, 36], $steps, 'graphemeRight steps over whole characters');
$back = []; for ($off = $g->len(); $off > 0;) { $off = Chars::graphemeLeft($g, $off); $back[] = $off; }
same([35, 33, 32, 24, 16, 15, 4, 3, 0], $back, 'and graphemeLeft back');

// Indentation.
same("\t  ", Chars::indentOf("\t  x"), 'indentOf');
same(10, Chars::indentWidth("\t  x", 8), 'indentWidth with tab stops');

// Key bindings: the defaults, the app's over them, and chords.
$km = new Keymap([new Binding(Key::COMMAND, Key::D, 0, null, Command::NO_COMMAND)]);
[$b, $prefix] = $km->lookup(null, Key::COMMAND, Key::D);
same([Command::NO_COMMAND, false], [$b->command, $prefix], 'the app\'s binding takes a key away from the editor');
[$b] = $km->lookup(null, Key::COMMAND | Key::SHIFT, Key::Z);
same(Command::REDO, $b->command, 'Cmd+Shift+Z redoes');
[$b, $prefix] = $km->lookup(null, Key::COMMAND, Key::K);
same([null, true], [$b, $prefix], 'Cmd+K starts a chord');
$chord = new Binding(Key::COMMAND, Key::K, 0, null, Command::NO_COMMAND);
[$b] = $km->lookup($chord, Key::COMMAND, Key::N0);
same(Command::FOLD_ALL, $b->command, 'Cmd+K Cmd+0 folds all');
[$b] = $km->lookup($chord, 0, Key::Z);
same(Command::TOGGLE_SOFT_WRAP, $b->command, 'Cmd+K Z toggles soft wrap');
[$b] = $km->lookup($chord, Key::COMMAND, Key::N3);
same(Command::FOLD_LEVEL3, $b->command, 'Cmd+K Cmd+3 folds level 3');
same('toggleComment', Command::name(Command::TOGGLE_COMMENT), 'a command\'s name');
same(Command::TOGGLE_COMMENT, Command::named('toggleComment'), 'and back');
same(null, Command::named('nope'), 'no command of an unknown name');
same(Command::COUNT, count(array_filter((new \ReflectionClass(Command::class))->getConstants(), 'is_int')) - 1, 'every command has a name');
