<?php
// The terminal (mygo's plugins/terminal terminal_test.go): a shell typed
// into, the scrollback, snapshots, selecting and copying, pasting, keys,
// input methods, the mouse, the program's exit, menus, keybinds, themes,
// fonts, links, the hooks that take pastes, keys, the wheel and zooms,
// and pastes a program reads itself. libghostty-vt as Terminal::load()
// finds it: $MYGO_GHOSTTY_VT, or the one in the cache.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Terminal\{Action, CopyOnSelect, Font, Keybind, Options, PasteProgress, PasteStatus, RightClickAction, Terminal, Theme, Zoom};
use MyPHP\Plugins\Terminal\Vt\Terminal as VtTerminal;
use MyPHP\Transfer\{Data, Format, Item, Representation};
use MyPHP\UI\{InputEvent, Key};

require_once __DIR__ . '/TerminalTest.php';

try {
    Terminal::load();
} catch (\Throwable $e) {
    echo "  skipped: libghostty-vt: {$e->getMessage()}\n";
    return;
}
TermClock::install();

(function () {
    // Typing into a shell.
    $term = termShell(new Options);
    $tt = new TermTester($term, 480, 240);
    termWait($tt, 'the prompt', fn() => str_contains($term->text(), '$'));
    foreach (mb_str_split('echo hi there') as $r) { $tt->key(0, 0, $r); }
    $tt->key(0, Key::RETURN);
    termWait($tt, 'the output', fn() => str_contains($term->text(), "\nhi there\n"));
    [$cols, $rows] = $term->size();
    check($cols >= 40 && $rows >= 10, "a shell's size is {$cols}×{$rows}");
    $term->close();
})();

(function () {
    // Digits are shaped as they are: "0" is no character missing.
    $term = Terminal::new(new Options(conn: new TermPipe));
    $term->feed('15:02:41 2026');
    $tt = new TermTester($term);
    $shaped = array_map(fn($k) => explode("\0", $k)[2], array_keys($term->v->shaped));
    check(in_array('15:02:41 2026', $shaped, true), 'the row is shaped with its zeros: ' . json_encode($shaped, JSON_UNESCAPED_UNICODE));
    $term->close();
})();

(function () {
    // The scrollback.
    $term = Terminal::new(new Options(conn: new TermPipe));
    $tt = new TermTester($term);
    $term->feed(termLines(100));
    $tt->frame();
    $bottom = termOffset($term);
    $tt->scroll(100, 100, -1); // a notch of a mouse wheel up
    same($bottom - 3, termOffset($term), 'a notch up scrolls three rows');
    $tt->key(Key::SHIFT, Key::PAGE_UP);
    check(termOffset($term) < $bottom - 3, 'Shift+Page Up scrolls');
    $tt->key(0, Key::X, 'x'); // typing goes back to the bottom
    same($bottom, termOffset($term), 'typing scrolls to the bottom');
    $term->close();
})();

(function () {
    // A snapshot of a terminal no view shows restores what it shows in another.
    $new = function (): Terminal { $t = Terminal::new(new Options(conn: new TermPipe)); $t->resize(40, 6); return $t; };
    $a = $new();
    for ($i = 0; $i < 10; $i++) { $a->feed(sprintf("line %03d \x1b[31mred\x1b[0m \x1b[1;4mbold\x1b[0m\r\n", $i)); }
    $a->feed("prompt % \x1b[?2004h");
    $b = $new();
    $b->feed($a->snapshot());
    $a->feed('x'); $b->feed('x');
    same($a->text(), $b->text(), 'a snapshot restores the text and the cursor');
    check($b->term->mode(VtTerminal::MODE_BRACKETED_PASTE), 'a snapshot restores the modes');
    $a->feed("\x1b[?1049h\x1b[H\x1b[2Jfull screen\x1b[3;5Hhere");
    $a->resize(30, 5);
    $c = $new();
    $c->resize(30, 5);
    $c->feed($a->snapshot());
    $c->feed('!');
    check(str_contains($c->text(), 'full screen') && str_contains($c->text(), 'here!'), 'a snapshot restores the alternate screen: ' . json_encode($c->text()));
    foreach ([$a, $b, $c] as $t) { $t->close(); }
})();

(function () {
    // Selecting with the pointer, and copying.
    $term = Terminal::new(new Options(conn: new TermPipe));
    $term->feed("hello world\r\nsecond line");
    $tt = new TermTester($term);
    [$x0, $y0] = $tt->cell(0, 0);
    [$x1, $y1] = $tt->cell(4, 0);
    $tt->press($x0 - 2, $y0);
    $tt->move($x1, $y1);
    $tt->move($x1 + 2, $y1);
    $tt->release($x1 + 2, $y1);
    $tt->key(Key::COMMAND, Key::C);
    same('hello', $tt->clipboard(), 'dragging selects');
    [$x, $y] = $tt->cell(8, 0);
    $tt->click($x, $y);
    $tt->click($x, $y, clicks: 2);
    $tt->key(Key::COMMAND, Key::C);
    same('world', $tt->clipboard(), 'a double click selects a word');
    [$x, $y] = $tt->cell(2, 1);
    for ($i = 1; $i <= 3; $i++) { $tt->click($x, $y, clicks: $i); }
    $tt->key(Key::COMMAND, Key::C);
    same('second line', $tt->clipboard(), 'a triple click selects a line');
    $term->close();
})();

(function () {
    // Pasting into cat, which echoes it.
    $term = termShell(new Options(command: ['/bin/cat']));
    $tt = new TermTester($term);
    $tt->setClipboard('pasted text');
    $tt->key(Key::COMMAND, Key::V);
    $tt->key(0, Key::RETURN);
    termWait($tt, 'the echo of cat', fn() => substr_count($term->text(), 'pasted text') === 2);
    $term->close();
})();

(function () {
    // Keys.
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn));
    $tt = new TermTester($term);
    $key = function (int $mods, int $k, string $text, string $want) use ($tt, $conn) {
        $tt->key($mods, $k, $text);
        same($want, $conn->take(strlen($want)), "the key $k with $mods sends");
    };
    $key(0, Key::A, 'a', 'a');
    $key(Key::SHIFT, Key::A, 'A', 'A');
    $key(Key::CONTROL, Key::C, '', "\x03");
    $key(0, Key::RETURN, '', "\r");
    $key(0, Key::DELETE, '', "\x7f");
    $key(0, Key::UP, '', "\x1b[A");
    $key(Key::SHIFT, Key::TAB, '', "\x1b[Z");
    $key(0, Key::F5, '', "\x1b[15~");
    // Text without its key, as input methods send it.
    $tt->type('世界');
    same('世界', $conn->take(6), 'text without its key');
    // Programs that ask for the Kitty keyboard protocol get it.
    $term->feed("\x1b[>1u");
    $key(0, Key::ESCAPE, '', "\x1b[27u");
    $key(Key::CONTROL, Key::C, '', "\x1b[99;5u");
    // Programs in cursor key mode get application keys.
    $term->feed("\x1b[<u\x1b[?1h");
    $key(0, Key::UP, '', "\x1bOA");
    // Option types accented letters, or is Alt with optionAsAlt.
    $key(Key::OPTION, Key::E, '´', '´');
    $term->close();
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn, optionAsAlt: true));
    $tt = new TermTester($term);
    $tt->key(Key::OPTION, Key::B, '∫');
    same("\x1bb", $conn->take(2), 'Option as Alt');
    $term->close();
})();

(function () {
    // An input method's composition.
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn));
    $tt = new TermTester($term);
    $term->feed('abc');
    $tt->frame();
    $client = $tt->h->input->client;
    check($client === $term->v, 'the view takes the input methods');
    [$r] = $client->boundsForRange(new \MyPHP\UI\TextInputRange(0, 0));
    [$x, $y] = $tt->cell(3, 0);
    check($x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3], 'input methods compose in the cell of the cursor: ' . json_encode($r));
    $tt->h->compose('ni', 2);
    same('', $conn->take(0), 'composing sends nothing');
    // A key while composing is the input method's.
    $tt->key(0, Key::RETURN);
    same('', $conn->take(0), 'Enter while composing is the input method\'s');
    $tt->h->compose('你', 1);
    $tt->type('你');
    same('你', $conn->take(3), 'committing sends the text');
    $term->close();
})();

(function () {
    // The mouse, reported to a program that takes it.
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn));
    $tt = new TermTester($term);
    $term->feed("\x1b[?1002h\x1b[?1006h");
    $tt->frame();
    [$x, $y] = $tt->cell(2, 3);
    $tt->press($x, $y);
    same("\x1b[<0;3;4M", $conn->take(9), 'a press');
    [$x2, $y2] = $tt->cell(5, 3);
    $tt->move($x2, $y2);
    same("\x1b[<32;6;4M", $conn->take(10), 'a drag');
    $tt->release($x2, $y2);
    same("\x1b[<0;6;4m", $conn->take(9), 'a release');
    $tt->scroll($x, $y, -1);
    check(str_starts_with($conn->take(30), "\x1b[<64;3;4M"), 'the wheel');
    $term->close();
})();

(function () {
    // The program's exit.
    $codes = []; $titles = [];
    $term = termShell(new Options(command: ['/bin/sh', '-c', "printf '\\033]2;the title\\007'; echo bye; exit 3"],
        onExit: function (int $c) use (&$codes) { $codes[] = $c; },
        onTitle: function (string $s) use (&$titles) { $titles[] = $s; }));
    $tt = new TermTester($term, 300, 200, false);
    termWait($tt, 'the exit', fn() => $term->done());
    same([3], $codes, 'onExit');
    same(3, $term->exitCode(), 'the exit code');
    check(str_contains($term->text(), "bye\n[Process exited with code 3]"), 'the exit shows: ' . json_encode($term->text()));
    same(['the title'], $titles, 'onTitle');
    same('the title', $term->title(), 'the title');
    $term->close();
})();

(function () {
    // The Edit menu's roles and the context menu.
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn));
    $term->feed('some text');
    $tt = new TermTester($term);
    $tt->command('selectAll');
    $tt->command('copy');
    same('some text', $tt->clipboard(), 'Select All and Copy');
    $tt->setClipboard('pasted');
    $tt->command('paste');
    same('pasted', $conn->take(6), 'Paste');
    [$x, $y] = $tt->cell(1, 0);
    $tt->h->rightClick($x, $y);
    $labels = implode(',', $tt->h->menuLabels() ?? []);
    check(str_contains($labels, 'Copy') && str_contains($labels, 'Clear Scrollback'), "the context menu: $labels");
    $tt->setClipboard('again');
    check($tt->h->chooseMenuItem('Paste'), "the menu's Paste");
    same('again', $conn->take(5), "the menu's Paste sends");
    $term->close();
})();

(function () {
    foreach ([CopyOnSelect::CopyOnSelectNone, CopyOnSelect::CopyOnSelectClipboard] as $on) {
        $term = Terminal::new(new Options(conn: new TermPipe, copyOnSelect: $on));
        $term->feed('hello world');
        $tt = new TermTester($term);
        $tt->setClipboard('');
        [$x0, $y0] = $tt->cell(0, 0);
        [$x1, $y1] = $tt->cell(4, 0);
        $tt->press($x0 - 2, $y0);
        $tt->move($x1 + 2, $y1);
        $tt->release($x1 + 2, $y1);
        same($on === CopyOnSelect::CopyOnSelectClipboard ? 'hello' : '', $tt->clipboard(), "copyOnSelect {$on->name}");
        $term->close();
    }
})();

(function () {
    // Windows Terminal's keys: performable Control-C and -V, Control-Insert and Shift-Insert, and a secondary click that copies or pastes.
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn, rightClick: RightClickAction::RightClickCopyOrPaste, keybinds: [
        new Keybind(Key::CONTROL, Key::C, Action::ActionCopy, true),
        new Keybind(Key::CONTROL, Key::V, Action::ActionPaste, true),
        new Keybind(Key::CONTROL, Key::INSERT, Action::ActionCopy),
        new Keybind(Key::SHIFT, Key::INSERT, Action::ActionPaste),
    ]));
    $term->feed('some text');
    $tt = new TermTester($term);
    $tt->command('selectAll');
    $tt->key(Key::CONTROL, Key::C);
    same('some text', $tt->clipboard(), 'Control-C copies a selection');
    same('', $conn->take(0), 'Control-C with a selection sends nothing');
    $tt->key(Key::CONTROL, Key::C);
    same("\x03", $conn->take(1), 'Control-C without a selection interrupts');
    $tt->setClipboard('by V');
    $tt->key(Key::CONTROL, Key::V);
    same('by V', $conn->take(4), 'Control-V pastes');
    $tt->setClipboard('');
    $tt->key(Key::CONTROL, Key::V);
    same("\x16", $conn->take(1), 'Control-V with an empty clipboard reaches the program');
    $tt->command('selectAll');
    $tt->key(Key::CONTROL, Key::INSERT);
    same('some text', $tt->clipboard(), 'Control-Insert copies');
    $tt->setClipboard('by Insert');
    $tt->key(Key::SHIFT, Key::INSERT);
    same('by Insert', $conn->take(9), 'Shift-Insert pastes');
    [$x, $y] = $tt->cell(1, 0);
    $tt->setClipboard('');
    $tt->command('selectAll');
    $tt->h->rightClick($x, $y);
    same('some text', $tt->clipboard(), 'a secondary click over a selection copies');
    $tt->h->rightClick($x, $y);
    same('some text', $conn->take(9), 'a secondary click without a selection pastes');
    same(null, $tt->h->menuLabels(), 'a secondary click shows no menu');
    $term->close();
})();

(function () {
    // Keys bound to ActionNone reach the program, and a secondary click shows the menu.
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn, keybinds: [new Keybind(Key::COMMAND, Key::C, Action::ActionNone), new Keybind(Key::COMMAND, Key::V, Action::ActionNone)]));
    $term->feed('some text');
    $tt = new TermTester($term);
    $tt->command('selectAll');
    $tt->setClipboard('clipboard');
    $tt->key(Key::CONTROL, Key::C);
    same("\x03", $conn->take(1), 'Control-C');
    $tt->key(Key::COMMAND, Key::V);
    same('', $conn->take(0), 'an unbound Command key goes to the app');
    same('clipboard', $tt->clipboard(), 'the clipboard is left');
    [$x, $y] = $tt->cell(1, 0);
    $tt->h->rightClick($x, $y);
    check($tt->h->menuLabels() !== null, 'a secondary click shows the menu');
    $term->close();
})();

(function () {
    // Options::$keybinds replace the defaults of their keys, in order, ActionNone unbinds, and noDefaultKeybinds starts from none.
    $copyKey = new Keybind(Key::COMMAND, Key::C, Action::ActionCopy);
    check(in_array($copyKey, Terminal::defaultKeybinds()), 'the defaults bind Command-C');
    $opts = new Options(keybinds: [
        new Keybind(Key::COMMAND, Key::C, Action::ActionPaste),
        new Keybind(Key::OPTION, Key::C, Action::ActionCopy),
        new Keybind(Key::OPTION, Key::C, Action::ActionSelectAll),
        new Keybind(Key::COMMAND, Key::A, Action::ActionNone),
    ]);
    $b = Keybind::of($opts);
    $find = function (int $mods, int $key) use ($b): ?Keybind { foreach ($b as $k) { if ($k->mods === $mods && $k->key === $key) { return $k; } } return null; };
    same(Action::ActionPaste, $find(Key::COMMAND, Key::C)?->action, 'a binding replaces the default');
    same(Action::ActionSelectAll, $find(Key::OPTION, Key::C)?->action, 'the last of a key bound twice');
    same(null, $find(Key::COMMAND, Key::A), 'ActionNone unbinds');
    same(count(Terminal::defaultKeybinds()), count($b), 'as many bindings');
    $opts->noDefaultKeybinds = true;
    same(2, count(Keybind::of($opts)), 'without the defaults');
})();

(function () {
    // The defaults select all, clear the screen above the prompt, scroll the scrollback but for a full-screen program, and show in the menu.
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn, keybinds: [
        new Keybind(Key::OPTION, Key::K, Action::ActionClearScreen, true),
        new Keybind(Key::OPTION, Key::A, Action::ActionSelectAll),
        new Keybind(Key::OPTION, Key::X, Action::ActionIgnore),
    ]));
    $tt = new TermTester($term);
    $term->feed(termLines(100) . '$ ls');
    $tt->frame();
    $bottom = termOffset($term);
    $tt->key(Key::SHIFT, Key::PAGE_UP);
    check(termOffset($term) < $bottom, 'Shift-Page Up scrolls');
    $tt->key(Key::SHIFT, Key::PAGE_DOWN);
    same($bottom, termOffset($term), 'Shift-Page Down scrolls back');
    $tt->key(Key::OPTION, Key::X, '≈');
    same('', $conn->take(0), 'an ignored key sends nothing');
    $tt->key(Key::OPTION, Key::A, 'å');
    $tt->command('copy');
    check(str_starts_with($tt->clipboard(), "line 000\n"), 'a key bound to Select All');
    $tt->key(Key::OPTION, Key::K, '˚');
    $tt->frame();
    same('$ ls', trim($term->text()), 'clearing the screen leaves the prompt');
    same([4, 0], $term->term->cursor(), 'the cursor after clearing');
    $term->feed("\x1b[?1049h");
    $tt->frame();
    $tt->key(Key::SHIFT, Key::PAGE_UP);
    same("\x1b[5;2~", $conn->take(1), 'Shift-Page Up on the alternate screen goes to the program');
    $tt->key(Key::OPTION, Key::K, '˚');
    check($conn->take(1) !== '', 'a performable clear leaves the key on the alternate screen');
    $term->close();
})();

(function () {
    // The theme follows the window's appearance; a closed terminal shows nothing.
    $term = Terminal::new(new Options(conn: new TermPipe));
    $tt = new TermTester($term, 400, 200, false);
    $light = $term->v->colors->background;
    $tt->h->theme = \MyPHP\UI\Theme::dark();
    $tt->frame();
    $dark = $term->v->colors->background;
    check($dark !== $light && (($dark >> 16) & 0xff) <= 64, sprintf('the background follows the dark appearance: %06x, then %06x', $light, $dark));
    [$cols] = $term->size();
    $term->setFont(new Font(size: 26));
    $tt->frame();
    check($term->size()[0] < $cols, 'a bigger font has fewer columns');
    $term->close();
    $tt->frame();
    $tt->type('x');
    same('', $term->text(), 'a closed terminal has no text');
})();

(function () {
    // Themes: light and dark, and the colors of a theme where they show.
    $old = getenv('XDG_CONFIG_HOME');
    putenv('XDG_CONFIG_HOME=' . sys_get_temp_dir() . '/myphp-no-ghostty');
    $latte = Terminal::ghosttyTheme('Catppuccin Latte');
    $mocha = Terminal::ghosttyTheme('Catppuccin Mocha');
    putenv($old === false ? 'XDG_CONFIG_HOME' : "XDG_CONFIG_HOME=$old");
    $term = Terminal::new(new Options(conn: new TermPipe, theme: $latte, darkTheme: $mocha, noBlink: true));
    $term->feed("\x1b[2;1H██ x\x1b[1;1H█\x1b[D");
    $tt = new TermTester($term);
    same($latte->background, $term->v->colors->background, 'the light theme');
    $tt->h->theme = \MyPHP\UI\Theme::dark();
    $tt->frame();
    same($mocha->background, $term->v->colors->background, 'the dark theme');
    $th = new Theme(0xc0c0c0, 0x101010, 0xff0000, 0x00ff00, 0x0000ff, 0xffff00);
    $term->setTheme($th, null);
    $tt->frame();
    $list = $tt->h->paint();
    $has = function (int $rgb) use ($list): bool {
        for ($i = 0; $i < $list->n; $i++) {
            $k = $i * \MyPHP\UI\DisplayList::FLOATS_PER_RECT;
            if (sprintf('%.4f,%.4f,%.4f', $list->buf[$k + 4], $list->buf[$k + 5], $list->buf[$k + 6]) === sprintf('%.4f,%.4f,%.4f', ($rgb >> 16) / 255, (($rgb >> 8) & 0xff) / 255, ($rgb & 0xff) / 255)) { return true; }
        }
        return false;
    };
    same(0x101010, $term->v->colors->background, 'the theme set');
    check($has(0x101010) && $has(0xc0c0c0) && $has(0xff0000) && $has(0x00ff00), 'the background, the text, the cursor and the text under it draw');
    $tt->command('selectAll');
    $list = $tt->h->paint();
    check($has(0x0000ff) && $has(0xffff00), 'the selection and the selected text draw');
    $term->close();
})();

(function () {
    // The font: rows taller, bold and italic variants, features.
    $cell = function (Font $f): array {
        $term = Terminal::new(new Options(conn: new TermPipe, font: $f));
        new TermTester($term, 400, 120, false);
        $v = $term->v;
        $out = [$v->cellW, $v->cellH, $v->fonts];
        $term->close();
        return $out;
    };
    [$w, $h, $fonts] = $cell(new Font(size: 14));
    [$w2, $h2] = $cell(new Font(size: 14, lineHeight: 1.5));
    check($w2 === $w && $h2 >= intdiv($h * 3, 2) - 1 && $h2 <= intdiv($h * 3, 2) + 1, "rows 1.5 times as high are {$w2}×{$h2}, from {$w}×{$h}");
    same([400, 700, 400, 700], array_map(fn($s) => $s->weight, $fonts), 'the weights of the variants');
    same([false, false, true, true], array_map(fn($s) => $s->italic, $fonts), 'the italic variants');
    same('calt=0,ss01,cv05=2,liga=0', Font::featureList(['-calt', '+ss01', ' cv05=2 ', 'liga=0', '']), 'features');
    [, , $f300] = $cell(new Font(weight: 300, features: ['-calt']));
    same([700, 'calt=0'], [$f300[1]->weight, $f300[1]->features], 'the bold of weight 300');
    [, , $f800] = $cell(new Font(weight: 800));
    same([900, true], [$f800[3]->weight, $f800[3]->italic], 'the bold italic of weight 800');
})();

(function () {
    // The program starts at the view's size.
    $term = termShell(new Options(command: ['/bin/sh', '-c', 'stty size; sleep 5']));
    $tt = new TermTester($term, 300, 200, false);
    [$cols, $rows] = $term->size();
    termWait($tt, 'stty', fn() => str_contains($term->text(), ' '));
    same("$rows $cols", trim($term->text()), 'the program sees the view\'s size');
    $term->close();
})();

(function () {
    // A key that types text, whose text never comes, goes alone when released.
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn));
    $tt = new TermTester($term);
    $tt->key(0, Key::B);
    same('b', $conn->take(1), 'a key without its text');
    $term->close();
})();

(function () {
    // Close kills what ignores the hangup.
    $term = termShell(new Options(command: ['/bin/sh', '-c', "trap '' HUP; echo ready; sleep 30"]));
    $tt = new TermTester($term, 300, 200, false);
    termWait($tt, 'the program', fn() => str_contains($term->text(), 'ready'));
    $term->close();
    $deadline = microtime(true) + 5;
    while (!$term->done() && microtime(true) < $deadline) { TermClock::pump(); usleep(10000); }
    check($term->done(), 'the program outlived close()');
})();

(function () {
    // Command+click hands URLs and printed paths to openLink, and URLs it leaves to the system.
    $opened = [];
    $term = Terminal::new(new Options(conn: new TermPipe, openLink: function (string $link) use (&$opened): bool { $opened[] = $link; return $link !== 'https://x.org'; }));
    $term->feed("see src/app.go:12 or https://x.org\r\nplain words");
    $tt = new TermTester($term);
    foreach ([6, 24] as $col) { [$x, $y] = $tt->cell($col, 0); $tt->click($x, $y, Key::COMMAND); }
    [$x, $y] = $tt->cell(2, 1);
    $tt->click($x, $y, Key::COMMAND);
    same(['src/app.go:12', 'https://x.org'], $opened, 'openLink');
    same(['https://x.org'], $tt->opened, 'what the system opened');
    $term->close();
})();

(function () {
    // Without openLink, the system opens hyperlinks, paths are no links, a hand shows over a link while Command is held, and a click on a link goes before a program that takes the mouse.
    $conn = new TermPipe;
    $term = Terminal::new(new Options(conn: $conn));
    $term->feed("\x1b]8;;vscode://file/a.go\x1b\\edit\x1b]8;;\x1b\\ src/app.go:12");
    $tt = new TermTester($term);
    [$x, $y] = $tt->cell(1, 0);
    $tt->hover($x, $y);
    same(\MyPHP\UI\Cursor::Text, $tt->node->cursor, 'the cursor over a link without Command');
    $tt->h->input->mods = Key::COMMAND;
    $tt->frame();
    same(\MyPHP\UI\Cursor::Pointer, $tt->node->cursor, 'the cursor over a link with Command');
    $tt->h->input->mods = 0;
    $tt->frame();
    same(\MyPHP\UI\Cursor::Text, $tt->node->cursor, 'the cursor once Command is let go of');
    $term->feed("\x1b[?1002h\x1b[?1006h");
    $tt->frame();
    $tt->click($x, $y, Key::COMMAND);
    [$x2, $y2] = $tt->cell(8, 0);
    $tt->click($x2, $y2, Key::COMMAND);
    same(['vscode://file/a.go'], $tt->opened, 'the system opened the hyperlink');
    $got = $conn->take(18);
    check(substr_count($got, "\x1b[<") === 2 && substr_count($got, ';9;1M') === 1 && substr_count($got, ';9;1m') === 1, 'the click on the path went to the program: ' . json_encode($got));
    $term->close();
})();

(function () {
    // onPaste sees a paste and the clipboard's formats; true pastes nothing.
    $conn = new TermPipe;
    $seen = []; $take = true;
    $term = Terminal::new(new Options(conn: $conn, onPaste: function (Data $d) use (&$seen, &$take): bool {
        $text = in_array(Format::TEXT, $d->formats(), true) ? $d->read(Format::TEXT) : '';
        $png = in_array(Format::PNG, $d->formats(), true) ? $d->read(Format::PNG) : '';
        $seen[] = $text . $png;
        return $take;
    }));
    $tt = new TermTester($term);
    $tt->setClipboard('hello');
    $tt->key(Key::COMMAND, Key::V);
    $tt->command('paste');
    same(['hello', 'hello'], $seen, 'onPaste saw the pastes');
    same('', $conn->take(0), 'a taken paste sends nothing');
    $take = false;
    $tt->key(Key::COMMAND, Key::V);
    same('hello', $conn->take(5), 'a paste onPaste leaves');
    $take = true;
    $tt->setClipboardData(new Data(new Item(Representation::bytes(Format::PNG, 'PNG'))));
    $tt->key(Key::COMMAND, Key::V);
    same('PNG', $seen[count($seen) - 1], 'onPaste sees an image');
    $term->close();
})();

(function () {
    // onKey sees a key before the terminal encodes it, in order with the keys after it; true sends nothing, not even its text.
    $conn = new TermPipe;
    $seen = [];
    $term = null;
    $term = Terminal::new(new Options(conn: $conn, onKey: function (int $mods, int $key) use (&$seen, &$term): bool {
        $seen[] = $key;
        if ($mods === Key::CONTROL && $key === Key::V || $key === Key::X) {
            $term->send('<taken>');
            return true;
        }
        return false;
    }));
    $tt = new TermTester($term);
    $tt->key(Key::CONTROL, Key::V);
    $tt->key(0, Key::TAB);
    $tt->key(0, Key::X, 'x');
    $tt->key(0, Key::Y, 'y');
    same("<taken>\t<taken>y", $conn->take(16), 'the taken keys in order');
    same([Key::V, Key::TAB, Key::X, Key::Y], $seen, 'onKey saw the keys');
    $term->close();
})();

(function () {
    // onScroll sees the wheel's rows unless a program takes the wheel.
    $conn = new TermPipe;
    $seen = [];
    $term = Terminal::new(new Options(conn: $conn, onScroll: function (int $rows) use (&$seen): bool { $seen[] = $rows; return true; }));
    $tt = new TermTester($term);
    $term->feed(termLines(100));
    $tt->frame();
    $bottom = termOffset($term);
    $tt->scroll(100, 100, -1);
    $tt->scroll(100, 100, 1);
    same([-3, 3], $seen, 'onScroll saw a notch up and down');
    same($bottom, termOffset($term), 'a taken scroll moves nothing');
    $term->feed("\x1b[?1002h\x1b[?1006h");
    $tt->frame();
    $tt->scroll(100, 100, -1);
    same(2, count($seen), 'onScroll is not asked while the program takes the mouse');
    check(str_starts_with($conn->take(10), "\x1b[<64;"), 'the wheel as the mouse');
    $term->feed("\x1b[?1002l\x1b[?1006l\x1b[?1049h");
    $tt->frame();
    $tt->scroll(100, 100, -1);
    same(2, count($seen), 'onScroll is not asked on a full-screen program\'s screen');
    same(3, substr_count($conn->take(9), 'A'), 'the wheel as arrows');
    $term->feed("\x1b[?1007l");
    $tt->frame();
    $tt->scroll(100, 100, -1);
    same([-3, 3, -3], $seen, 'without alternate scroll');
    $term->close();
})();

(function () {
    // A program that reads the clipboard itself (mode 5522) reads the image a paste offers; another terminal answers nothing; without the mode the text is pasted.
    $image = str_repeat("\x89PNG\r\n\x1a\nxyz", 1000);
    $conn = new TermPipe;
    $statuses = []; $last = null;
    $term = null;
    $term = Terminal::new(new Options(conn: $conn, onPasteStatus: function (PasteProgress $p) use (&$statuses, &$last, &$term): void {
        $term->title();
        $statuses[] = $p->status->name . ($p->sent === $p->total && $p->total > 0 ? '+' : '');
        if ($p->status === PasteStatus::PasteSent) { $last = $p; }
    }));
    $tt = new TermTester($term);
    $tt->setClipboardData(new Data(new Item(Representation::bytes(Format::TEXT, 'some text'), Representation::bytes(Format::PNG, $image))));
    $term->feed("\x1b[?5522h");
    $tt->key(Key::COMMAND, Key::V);
    $event = $conn->take(1);
    $at = strpos($event, ':pw=');
    check($at !== false && str_ends_with($event, "\x1b\\"), 'a paste event: ' . json_encode($event));
    if ($at === false) { $term->close(); return; }
    $after = substr($event, $at + 4);
    $pw = substr($after, 0, strpos($after, "\x1b"));
    $start = strpos($event, "Lg==:pw=$pw;") + strlen("Lg==:pw=$pw;");
    same("text/plain image/png\n", base64_decode(substr($event, $start, strpos($event, "\x1b\\\x1b]5522;type=read:status=DONE") - $start)), 'the formats offered');
    $name = base64_encode('Paste event'); $mime = base64_encode('image/png');
    $read = "\x1b]5522;type=read:pw=$pw:name=$name;$mime\x1b\\";
    $term->feed($read);
    $reply = $conn->take(intdiv(strlen($image) * 4, 3));
    $got = '';
    foreach (explode("\x1b\\", $reply) as $pkt) {
        if (($i = strpos($pkt, "status=DATA:mime=$mime;")) !== false) { $got .= base64_decode(substr($pkt, $i + strlen("status=DATA:mime=$mime;"))); }
    }
    check($got === $image && str_ends_with($reply, "status=DONE\x1b\\"), 'the program read ' . strlen($got) . ' bytes of ' . strlen($image));
    same(strlen($reply), $last?->total, 'the reply sent whole');
    $conn2 = new TermPipe;
    $other = Terminal::new(new Options(conn: $conn2));
    $other->feed("\x1b[?5522h$read");
    same('', $conn2->take(0), 'a terminal that did not paste answers nothing');
    $other->close();
    $term->feed("\x1b[?5522l\x1b[?2004h");
    $tt->key(Key::COMMAND, Key::V);
    same("\x1b[200~some text\x1b[201~", $conn->take(1), 'without the mode, the text, bracketed');
    $tt->setClipboardData(new Data(new Item(Representation::bytes(Format::PNG, $image))));
    $tt->key(Key::COMMAND, Key::V);
    same('', $conn->take(0), 'only an image and no mode pastes nothing');
    $seen = implode(' ', $statuses);
    check(str_starts_with($seen, 'PasteOffered PasteSending') && str_contains($seen, 'PasteSent+') && str_ends_with($seen, 'PasteNoText'), "statuses $seen");
    $term->close();
})();

(function () {
    // onZoom takes the wheel with Command in notches, rolling up positive, before onScroll and a program that takes the mouse, and the font size keys.
    $conn = new TermPipe;
    $zooms = []; $scrolls = [];
    $term = Terminal::new(new Options(conn: $conn, onZoom: function (Zoom $z) use (&$zooms): bool { $zooms[] = $z; return true; }, onScroll: function (int $r) use (&$scrolls): bool { $scrolls[] = $r; return true; }));
    $tt = new TermTester($term);
    $wheel = function (int $mods, float $dy, bool $precise) use ($term) { $term->v->input(new InputEvent(InputEvent::SCROLL, mods: $mods, dy: $dy, precise: $precise)); };
    $steps = function () use (&$zooms): array { return array_map(fn(Zoom $z) => $z->steps, $zooms); };
    $wheel(Key::COMMAND, -10, false);
    $wheel(Key::COMMAND, 10, false);
    $wheel(Key::COMMAND, -30, false);
    same([1, -1, 1], $steps(), 'notches zoom a step an event');
    $zooms = [];
    for ($i = 0; $i < 6; $i++) { $wheel(Key::COMMAND, -15, true); }
    same([1, 1], $steps(), 'a trackpad zooms by 40 points');
    same([], $scrolls, 'onScroll is not asked while zooming');
    $zooms = [];
    $wheel(0, -10, false);
    same([[], [-3]], [$zooms, $scrolls], 'without Command the wheel scrolls');
    $term->feed("\x1b[?1002h\x1b[?1006h");
    $tt->frame();
    $wheel(Key::COMMAND, -10, false);
    same([1], $steps(), 'a zoom while the program takes the mouse');
    same('', $conn->take(0), 'a zoom sends nothing');
    $zooms = [];
    $tt->key(Key::COMMAND, Key::EQUAL);
    $tt->key(Key::COMMAND, Key::MINUS);
    $tt->key(Key::COMMAND, Key::N0);
    same([[1, false], [-1, false], [0, true]], array_map(fn(Zoom $z) => [$z->steps, $z->reset], $zooms), 'the font size keys');
    same('', $conn->take(0), 'the zoom keys send nothing');
    same(13.0, $term->v->font['size'], 'a zoom onZoom took leaves the font');
    $term->close();
})();

(function () {
    // Without onZoom the terminal zooms its font a point a step, and back to its font's size, which setFont changes.
    $term = Terminal::new(new Options(conn: new TermPipe, font: new Font(size: 14)));
    $tt = new TermTester($term);
    [$cols] = $term->size();
    $size = function () use ($tt, $term): float { $tt->frame(); return $term->v->font['size']; };
    $term->v->input(new InputEvent(InputEvent::SCROLL, mods: Key::COMMAND, dy: -10));
    $tt->key(Key::COMMAND, Key::EQUAL);
    same(16.0, $size(), 'zoomed in twice');
    check($term->size()[0] < $cols, 'fewer columns at a larger size');
    $tt->key(Key::COMMAND, Key::MINUS);
    same(15.0, $size(), 'zoomed out');
    $tt->key(Key::COMMAND, Key::N0);
    same(14.0, $size(), 'reset');
    $tt->key(Key::COMMAND, Key::EQUAL);
    $term->setFont(new Font(size: 20));
    same(20.0, $size(), 'setFont after a zoom');
    for ($i = 0; $i < 30; $i++) { $tt->key(Key::COMMAND, Key::MINUS); }
    same(1.0, $size(), 'zoomed out to 1 at the least');
    $term->close();
})();

(function () {
    // A touchpad's gesture keeps what it began as: its momentum does not zoom when Command comes down meanwhile, nor stop zooming when it is let go (mygo's 9c69cf8).
    $term = Terminal::new(new Options(conn: new TermPipe, font: new Font(size: 14)));
    $tt = new TermTester($term);
    $size = function () use ($tt, $term): float { $tt->frame(); return $term->v->font['size']; };
    $term->v->input(new InputEvent(InputEvent::SCROLL, dy: -10, precise: true));
    $term->v->input(new InputEvent(InputEvent::SCROLL, mods: Key::COMMAND, dy: -80, precise: true, momentum: true));
    same(14.0, $size(), 'momentum with Command down: no zoom');
    $term->v->input(new InputEvent(InputEvent::SCROLL, mods: Key::COMMAND, dy: -40, precise: true));
    $term->v->input(new InputEvent(InputEvent::SCROLL, dy: -40, precise: true, momentum: true));
    same(16.0, $size(), 'a gesture that began zooming zooms through its momentum');
    $term->close();
})();

(function () {
    // A program that reads the clipboard is offered every format, a file list as text/uri-list, and reads a lazy one only when it asks; a paste it does not read is told after a moment, and still read.
    Terminal::$notTakenAfter = 0.05;
    $conn = new TermPipe;
    $statuses = [];
    $term = Terminal::new(new Options(conn: $conn, onPasteStatus: function (PasteProgress $p) use (&$statuses): void { $statuses[] = $p->status; }));
    $read = 0;
    $files = Data::files('/tmp/a.png');
    $uri = $files->items[0]->read(Format::FILE_LIST);
    $d = new Data(...[...$files->items, new Item(Representation::lazy('image/jpeg', function () use (&$read): string { $read++; return 'JPEG'; }), Representation::bytes(Format::TEXT, 'a.png'))]);
    $term->feed("\x1b[?5522h");
    $term->pasteData($d);
    $event = $conn->take(1);
    $at = strpos($event, ':pw=');
    check($at !== false, 'a paste event: ' . json_encode($event));
    if ($at === false) { $term->close(); return; }
    $after = substr($event, $at + 4);
    $pw = substr($after, 0, strpos($after, "\x1b"));
    $start = strpos($event, "Lg==:pw=$pw;") + strlen("Lg==:pw=$pw;");
    same("text/uri-list image/jpeg text/plain\n", base64_decode(substr($event, $start, strpos($event, "\x1b", $start) - $start)), 'the formats offered');
    $deadline = microtime(true) + 0.15;
    while (microtime(true) < $deadline) { TermClock::pump(); usleep(5000); }
    same(0, $read, 'the lazy format is read only when asked');
    same([PasteStatus::PasteOffered, PasteStatus::PasteNotTaken], $statuses, 'the statuses before the read');
    $term->feed("\x1b]5522;type=read:pw=$pw:name=" . base64_encode('Paste event') . ';' . base64_encode('image/jpeg text/uri-list') . "\x1b\\");
    $reply = $conn->take(1);
    check($read === 1 && str_contains($reply, base64_encode('JPEG')) && str_contains($reply, base64_encode($uri)), "read $read times, replied " . json_encode($reply));
    Terminal::$notTakenAfter = 5.0;
    $term->close();
})();

(function () {
    // Programs write to the clipboard (OSC 52) unless noClipboardWrite.
    foreach ([false, true] as $deny) {
        $term = Terminal::new(new Options(conn: new TermPipe, noClipboardWrite: $deny));
        $tt = new TermTester($term);
        $tt->setClipboard('');
        $term->feed("\x1b]52;c;" . base64_encode('copied') . "\x1b\\");
        $tt->frame();
        same($deny ? '' : 'copied', $tt->clipboard(), 'noClipboardWrite ' . var_export($deny, true));
        $term->close();
    }
})();
