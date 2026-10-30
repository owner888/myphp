<?php
// The code editor as a user drives it (mygo's editor_test.go): typing,
// brackets, indentation, carets and selections, lines, undo, the
// clipboard, input methods, finding, folding and words.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Node, TextInputRange};
use MyPHP\UI\Editor\{Buffer, Command, Editor, Languages, Selection, State};

require_once __DIR__ . '/EditorTest.php';

$editor = fn(string $lang, string $text) => new EditorTest($lang, $text);

// Typing: closers typed step over those the editor typed, Backspace takes an empty pair, a selection wraps in brackets.
$ct = $editor('go', '|');
$ct->typ('fmt.Println(');
$ct->want('fmt.Println(|)', 'an opening bracket typed closes');
$ct->typ('"hi');
$ct->want('fmt.Println("hi|")', 'a quote typed closes');
$ct->typ('")');
$ct->want('fmt.Println("hi")|', 'closers typed step over those typed');
$ct->key(0, Key::DELETE);
$ct->want('fmt.Println("hi"|', 'backspace');
$ct = $editor('go', 'x|');
$ct->typ('[');
$ct->want('x[|]', 'a bracket closes');
$ct->key(0, Key::DELETE);
$ct->want('x|', 'backspace takes an empty pair');
$ct = $editor('go', '[abc]');
$ct->typ('(');
$ct->want('([abc])', 'a selection wraps in brackets');

// Newline: indented after an opening bracket, the closer on its own line; line comments go on; Python indents after a colon, with spaces.
$ct = $editor('go', 'func main() {|}');
$ct->key(0, Key::RETURN, "\r");
$ct->want("func main() {\n\t|\n}", 'Return between brackets');
$ct->typ('x');
$ct->key(0, Key::RETURN, "\r");
$ct->want("func main() {\n\tx\n\t|\n}", 'Return keeps the indentation');
$ct = $editor('go', "\t// a comment|");
$ct->key(0, Key::RETURN, "\r");
$ct->want("\t// a comment\n\t// |", 'a line comment goes on');
$ct = $editor('python', 'def f():|');
$ct->key(0, Key::RETURN, "\r");
$ct->want("def f():\n    |", 'Python indents after a colon');

// Typing what closes a block outdents its line, as Zed's auto-indent on input does.
$ct = $editor('go', "func main() {\n\tif x {\n\t\ty()\n\t\t|");
$ct->typ('}');
$ct->want("func main() {\n\tif x {\n\t\ty()\n\t}|", 'a closing bracket outdents its line');
$ct->key(Key::COMMAND, Key::Z, 'z');
$ct->want("func main() {\n\tif x {\n\t\ty()\n\t\t|", 'undo takes the bracket back with its outdent');
$ct = $editor('go', "func main() {\n\t\tx|");
$ct->typ('}');
$ct->want("func main() {\n\t\tx}|", 'not after other text');
$ct = $editor('go', "f(`\n\t\t|");
$ct->typ('}');
$ct->want("f(`\n\t\t}|", 'nor inside a string');
$ct = $editor('go', "{\n\t{\n\t\t|\n\t\t|");
$ct->typ('}');
$ct->want("{\n\t{\n\t}|\n}|", 'each caret outdents its line');
$ct = $editor('shell', "if x; then\n  echo\n  |");
$ct->typ('fi');
$ct->want("if x; then\n  echo\nfi|", 'a word of the language\'s decreaseIndent');
$ct = $editor('ruby', "if x\n  y\n  |");
$ct->typ('else');
$ct->want("if x\n  y\nelse|", 'else');
$ct->typ("\n  z\n  end");
$ct->want("if x\n  y\nelse\n  z\nend|", 'end');

// The scroll bar of a text shorter than the editor, which scrolls past its end, takes the pointer: an arrow over it, and dragging it scrolls rather than selects.
$ct = $editor('go', "|package main\n\nfunc main() {}\n");
$x = 600 - Editor::SCROLLBAR_WIDTH / 2;
$ct->move(300, 5);
same(\MyPHP\UI\Cursor::Text, $ct->cursor(), 'the I-beam over the text');
$ct->move($x, 5);
same(\MyPHP\UI\Cursor::Default, $ct->cursor(), 'the arrow over the scroll bar');
$ct->h->press($x, 5);
$ct->move($x, 300);
$ct->h->release($x, 300);
$ed = (fn() => $this->ed)->call($ct->st);
check($ed->scrollY > 0, 'dragging the scroll bar scrolls');
$ct->want("|package main\n\nfunc main() {}\n", 'and selects nothing');
$ct = $editor('', '|' . str_repeat('x', 200));
$y = 400 - Editor::SCROLLBAR_WIDTH / 2;
$ct->move(300, $y);
same(\MyPHP\UI\Cursor::Default, $ct->cursor(), 'the arrow over the horizontal scroll bar, which shows for a line running under the vertical one');
$ct->h->press(300, $y);
$ct->move(500, $y);
$ct->h->release(500, $y);
$ed = (fn() => $this->ed)->call($ct->st);
check($ed->scrollX > 0, 'dragging the horizontal scroll bar scrolls');
$ct->want('|' . str_repeat('x', 200), 'and selects nothing');

// Several carets: select next occurrence, add carets below, select all occurrences.
$ct = $editor('go', "foo := 1\nbar := foo\nfoo++");
$ct->st->select(0, 0);
$ct->key(Key::COMMAND, Key::D, 'd');
$ct->want("[foo] := 1\nbar := foo\nfoo++", 'select next occurrence takes the word first');
$ct->key(Key::COMMAND, Key::D, 'd');
$ct->key(Key::COMMAND, Key::D, 'd');
$ct->want("[foo] := 1\nbar := [foo]\n[foo]++", 'then each next');
$ct->typ('x');
$ct->want("x| := 1\nbar := x|\nx|++", 'typing at each');
$ct->key(0, Key::ESCAPE, "\e");
$ct->want("x := 1\nbar := x\nx|++", 'Escape keeps the newest');
$ct = $editor('', "|ab\ncd\nef");
$ct->key(Key::COMMAND | Key::OPTION, Key::DOWN);
$ct->key(Key::COMMAND | Key::OPTION, Key::DOWN);
$ct->typ('-');
$ct->want("-|ab\n-|cd\n-|ef", 'carets added below');
$ct->key(Key::COMMAND | Key::OPTION, Key::UP);
$ct->want("-|ab\n-|cd\n-ef", 'going back up takes the last added away');
$ct = $editor('', 'a b a c |a');
$ct->key(Key::COMMAND | Key::SHIFT, Key::L, 'l');
$ct->want('[a] b [a] c [a]', 'select all occurrences');

// Lines: moved, duplicated, deleted keeping the caret's column, joined; comments toggled; indented.
$ct = $editor('go', "one\ntw|o\nthree");
$ct->key(Key::OPTION, Key::UP);
$ct->want("tw|o\none\nthree", 'a line moved up');
$ct->key(Key::OPTION, Key::DOWN);
$ct->key(Key::OPTION, Key::DOWN);
$ct->want("one\nthree\ntw|o", 'and down');
$ct->key(Key::OPTION | Key::SHIFT, Key::UP);
$ct->want("one\nthree\ntw|o\ntwo", 'duplicated up');
$ct->key(Key::COMMAND | Key::SHIFT, Key::K, 'k');
$ct->want("one\nthree\ntw|o", 'deleting a line keeps the caret\'s column, as Zed\'s');
$ct->key(Key::CONTROL, Key::J, 'j');
$ct->want("one\nthree\ntw|o", 'the last line joins nothing');
$ct = $editor('go', "a|\n  b\nc");
$ct->key(Key::CONTROL, Key::J, 'j');
$ct->want("a |b\nc", 'joining lines drops the indentation');
$ct = $editor('go', "\tif x {\n\t\t[y()\n\t}]");
$ct->key(Key::COMMAND, Key::SLASH, '/');
same("\tif x {\n\t// \ty()\n\t// }", (string)$ct->st->buffer, 'comments at the least indentation');
$ct->key(Key::COMMAND, Key::SLASH, '/');
same("\tif x {\n\t\ty()\n\t}", (string)$ct->st->buffer, 'and back');
$ct = $editor('python', "[a\nb]");
$ct->key(Key::COMMAND, Key::BRACKET_RIGHT, ']');
$ct->want("[    a\n    b]", 'indent');
$ct->key(Key::COMMAND, Key::BRACKET_LEFT, '[');
$ct->want("[a\nb]", 'outdent');

// Undo: typing in a row is one step, a pause makes another, and undo puts the selections back.
$ct = $editor('', '|');
$ct->typ('abc');
$ct->key(Key::COMMAND, Key::Z, 'z');
$ct->want('|', 'typing in a row is one step');
$ct->key(Key::COMMAND | Key::SHIFT, Key::Z, 'z');
$ct->want('abc|', 'redo');
usleep(350000);
$ct->typ('d');
$ct->key(Key::COMMAND, Key::Z, 'z');
$ct->want('abc|', 'a pause makes another step');
$ct = $editor('', 'x [hello] y');
$ct->typ('b');
$ct->want('x b| y', 'typing over a selection');
$ct->key(Key::COMMAND, Key::Z, 'z');
$ct->want('x [hello] y', 'undo puts the selections back');

// The clipboard: a caret alone copies its line, which pastes above a line; a cut of several selections pastes back piece by piece.
$ct = $editor('', "one\ntw|o\nthree");
$ct->key(Key::COMMAND, Key::C, 'c');
same("two\n", ($ct->h->rt->readClipboard)(), 'copying a caret alone copies its line');
$ct->st->select(1, 1);
$ct->key(Key::COMMAND, Key::V, 'v');
$ct->want("two\no|ne\ntwo\nthree", 'which pastes above a line');
$ct = $editor('', '[a] [b] [c]');
$ct->key(Key::COMMAND, Key::X, 'x');
$ct->want('| | |', 'cut of several selections');
$ct->key(Key::COMMAND, Key::V, 'v');
$ct->want('a| b| c|', 'pastes back piece by piece');
($ct->h->rt->writeClipboard)('zz');
$ct->key(Key::COMMAND, Key::V, 'v');
$ct->want('azz| bzz| czz|', 'pasting text from elsewhere');
$ct = $editor('', 'say [hello] there');
$ct->key(Key::COMMAND, Key::C, 'c');
$ct->key(Key::COMMAND, Key::V, 'v');
$ct->want('say hello| there', 'pasting what was just copied over the selection still copied puts the caret after it');
$ct->key(Key::COMMAND, Key::V, 'v');
$ct->want('say hellohello| there', 'and pasting again pastes it again');
$ct = $editor('', 'a[b]c');
$ct->typ('b');
$ct->want('ab|c', 'as does typing over a selection the character typed');

// Input methods compose at every caret, and the composition and its commit are one step of undo.
$ct = $editor('', "a|b\nc|d");
$ct->h->input->client->setMarkedText(null, 'に', new TextInputRange(1, 1)); $ct->h->frame();
$ct->want("aに|b\ncに|d", 'a composition at every caret');
$ct->h->input->client->setMarkedText(null, 'にほ', new TextInputRange(2, 2)); $ct->h->frame();
$ct->want("aにほ|b\ncにほ|d", 'the composition grows');
$ct->h->type('日本');
$ct->want("a日本|b\nc日本|d", 'committed');
$ct->key(Key::COMMAND, Key::Z, 'z');
$ct->want("a|b\nc|d", 'one step of undo');

// Finding and replacing.
$ct = $editor('', '|cat Cat cat dog');
$ct->st->find('cat');
$ct->h->frame();
same([3, 0], $ct->st->findMatches(), 'matches without case');
$ed = (fn() => $this->ed)->call($ct->st);
$ed->find->caseSens = true;
$ed->find->stale = true;
same(2, $ct->st->findMatches()[0], 'case-sensitive matches');
$ed->find->replace = 'cow';
$ed->find->replaceAll($ed);
same('cow Cat cow dog', (string)$ct->st->buffer, 'replaced');
$ed->find->regex = true; $ed->find->query = 'c(\w+)'; $ed->find->replace = '<$1>'; $ed->find->stale = true;
$ed->find->replaceAll($ed);
same('<ow> Cat <ow> dog', (string)$ct->st->buffer, 'replaced with a regular expression');

// Folding hides lines, which moving down skips.
$ct = $editor('go', "func a() {\n\tx()\n\ty()\n}\nfunc b() {}");
$ed = (fn() => $this->ed)->call($ct->st);
$hs = $ed->view->heights;
$was = $hs->total();
$ct->st->fold(0);
$ct->h->frame();
same([0], $ct->st->folded(), 'folded');
same($was - 2 * $ed->view->lineHeight, $hs->total(), 'the fold hides its lines');
$ct->st->select(0, 0);
$ct->key(0, Key::DOWN);
same(3, $ct->st->cursorPos()->line, 'moving down skips what the fold hides');
$ct->run(Command::UNFOLD_ALL);
same($was, $hs->total(), 'unfolded');

// Words and subwords, selecting larger, brackets, cases.
$ct = $editor('', '|fooBar_baz qux');
$ct->run(Command::MOVE_SUBWORD_RIGHT);
$ct->want('foo|Bar_baz qux', 'a subword');
$ct->run(Command::MOVE_SUBWORD_RIGHT);
$ct->want('fooBar|_baz qux', 'another');
$ct->run(Command::MOVE_WORD_RIGHT);
$ct->want('fooBar_baz| qux', 'a word');
$ct->run(Command::MOVE_WORD_LEFT);
$ct->want('|fooBar_baz qux', 'a word back');
$ct = $editor('go', 'f(x, "a b|c")');
$ct->run(Command::SELECT_LARGER);
$ct->want('f(x, "a [bc]")', 'selecting larger: the word');
$ct->run(Command::SELECT_LARGER);
$ct->want('f(x, "[a bc]")', 'the string');
$ct->run(Command::SELECT_LARGER);
$ct->want('f(x, ["a bc"])', 'its quotes');
$ct->run(Command::SELECT_LARGER);
$ct->want('f([x, "a bc"])', 'the brackets');
$ct->run(Command::SELECT_SMALLER);
$ct->want('f(x, ["a bc"])', 'select smaller takes it back');
$ct = $editor('go', 'g(f[(x)])');
$ct->run(Command::SELECT_LARGER);
$ct->want('g([f(x)])', 'a selection of a pair grows to the pair around it');
$ct->run(Command::SELECT_LARGER);
$ct->want('g[(f(x))]', 'and on');
$ct = $editor('go', 'g(f(x)|)');
$ct->run(Command::SELECT_LARGER);
$ct->want('g(f[(x)])', 'a caret after a pair selects that pair, as Zed does');
$ct = $editor('go', 'g(f(x)|)');
$ct->run(Command::MOVE_TO_BRACKET);
$ct->want('g(f|(x))', 'moving to the bracket goes to the same side of the other one');
$ct->run(Command::MOVE_TO_BRACKET);
$ct->want('g(f(x)|)', 'and back');
$ct = $editor('go', 'g(f(|x))');
$ct->run(Command::MOVE_TO_BRACKET);
$ct->want('g(f(x|))', 'from inside');
$ct->run(Command::MOVE_TO_BRACKET);
$ct->want('g(f(|x))', 'and back');
$ct = $editor('', '[fooBarBaz]');
$ct->run(Command::SNAKE_CASE);
$ct->want('[foo_bar_baz]', 'snake case');
$ct->run(Command::KEBAB_CASE);
$ct->want('[foo-bar-baz]', 'kebab case');
$ct->run(Command::PASCAL_CASE);
$ct->want('[FooBarBaz]', 'Pascal case');

// Two editors of one buffer follow each other's edits.
$buf = new Buffer('hello world');
$a = new State($buf); $b = new State($buf);
$na = null;
$h = new Headless(function (Context $c) use ($a, $b, &$na) {
    $c->row(0, 0, 1);
      $na = $a->view($c); $na->grow = 1;
      $b->view($c)->grow = 1;
    $c->end();
}, 800, 300);
$h->paints = true;
$b->select(6, 11);
$a->select(0, 0);
$h->frame(1);
$h->rt->focusOn($na->id);
$h->frame();
$h->type('¡');
same('¡hello world', (string)$buf, 'typed in one editor');
same([8, 13], [$b->selection()->start(), $b->selection()->end()], 'the other editor\'s selection moves with the text');

// A run of imports folds as one, as the language writes them; one alone by its brackets; not in strings or comments (mygo's TestCodeEditorFoldImports).
foreach ([
    ['go', "package main\n\nimport (\n\t\"fmt\"\n\t\"os\"\n)\n\nfunc main() {}", [[2, 4]]],
    ['go', "package main\n\nimport \"fmt\"\nimport (\n\t\"os\"\n)\n\nfunc main() {}", [[2, 5]]],
    ['go', "package main\n\nimport \"fmt\"\n\nfunc main() {}", []],
    ['typescript', "// x\nimport a from './a'\nimport {\n  b,\n  c,\n} from './b'\n\nimport './d'\n\nconst e = a", [[1, 7]]],
    ['python', "\"\"\"Doc.\"\"\"\nimport os\nfrom x import (\n    a,\n)\nimport sys\n\nprint(os)", [[1, 5]]],
    ['rust', "use std::io;\npub use crate::x;\n\nfn main() {}", [[0, 1]]],
    ['c', "#include <stdio.h>\n#include \"x.h\"\n\nint main() {}", [[0, 1]]],
    ['java', "package a;\n\nimport a.B;\nimport a.C;\n\nclass D {}", [[2, 3]]],
    ['python', "\"\"\"\nfrom here on\nimport this\n\"\"\"\nimport os\nimport sys\n", [[4, 5]]],
    ['go', "// import a\n// import b\npackage main\n", []],
    ['markdown', "import a\nimport b\n", []],
] as [$lang, $text, $want]) {
    $ct = new EditorTest($lang, $text);
    $ct->st->foldImports();
    same($want, (fn() => $this->ed->folds)->call($ct->st), "foldImports in $lang: " . json_encode($text));
}
$ct = new EditorTest('javascript', "import a from 'a'\nimport b from 'b'\nimport c from 'c'\n\nf(a, b, c)");
same([true, false], (fn() => [$this->ed->foldable(0), $this->ed->foldable(1)])->call($ct->st), "the gutter marks a run of imports' first line alone");

// Text backgrounds fill their bytes where the editor lays them out, across wrapped rows, and nothing past the text (mygo's TestTextBackgrounds).
$blueQuads = function (\MyPHP\UI\DisplayList $l): array {
    $out = [];
    for ($i = 0; $i < $l->n; $i++) {
        $k = $i * 16;
        if (fmod($l->buf[$k + 9], \MyPHP\UI\DisplayList::KIND_STRIDE) == 0.0 && $l->buf[$k + 4] == 0.0 && $l->buf[$k + 5] == 0.0 && $l->buf[$k + 6] == 1.0 && $l->buf[$k + 7] == 1.0) {
            $out[] = [$l->buf[$k], $l->buf[$k + 1], $l->buf[$k + 2]];
        }
    }
    return $out;
};
$edOf = fn(\MyPHP\UI\Editor\State $st) => (fn() => $this->ed)->call($st);
$ct = new EditorTest('', "abc def ghi\nz|");
$ct->st->noActiveLine = true;
$ct->st->textBackgrounds = [new \MyPHP\UI\Editor\TextBackground(4, 7, [0x0000ff, 1.0])];
$ct->h->frame();
$q = $blueQuads($ct->h->paint());
$ed = $edOf($ct->st);
check(count($q) === 1 && abs($q[0][2] - ($ed->caretRect(7)[0] - $ed->caretRect(4)[0])) < 0.01, 'textBackgrounds: the bytes of def: ' . json_encode($q));
$ct = new EditorTest('', str_repeat('x', 150) . "\nz|");
$ct->st->noActiveLine = true; $ct->st->softWrap = true;
$ct->st->textBackgrounds = [new \MyPHP\UI\Editor\TextBackground(40, 130, [0x0000ff, 1.0])];
$ct->h->frame(); $ct->h->frame();
$ed = $edOf($ct->st);
$rows = count(array_unique(array_map(fn(int $o) => $ed->caretRect($o)[1], range(40, 129))));
check($rows > 1 && count($blueQuads($ct->h->paint())) === $rows, "textBackgrounds: across the $rows rows of a wrapped line");
$ct->st->textBackgrounds = [new \MyPHP\UI\Editor\TextBackground(140, 400, [0x0000ff, 1.0]), new \MyPHP\UI\Editor\TextBackground(500, 600, [0x0000ff, 1.0])];
$ct->h->frame();
check(count($blueQuads($ct->h->paint())) === 2, 'textBackgrounds: a range past the text cut to it (the end of the long line, z), one beyond it nothing');

// The scroll in points, and where lines start (mygo's ScrollY, SetScrollY, LineTop).
$ct = new EditorTest('', str_repeat("line\n", 300));
$lh = $edOf($ct->st)->view->lineHeight;
same([0.0, 10 * $lh, 300 * $lh], [$ct->st->lineTop(0), $ct->st->lineTop(10), $ct->st->lineTop(300)], 'lineTop: the lines one under the other');
$ct->st->setScrollY(20 * $lh);
$ct->h->frame();
same(20 * $lh, $ct->st->scrollY(), 'setScrollY scrolls the lines');

// An editor as high as its lines, in a scroll view, shows its caret as it takes the focus, not its first line (mygo's TestFitContentFocusRevealsCaret).
$st = new \MyPHP\UI\Editor\State(new \MyPHP\UI\Editor\Buffer(str_repeat("line\n", 300)));
$st->fitContent = true;
$sv = null; $edNode = null;
$h = new Headless(function (\MyPHP\UI\Context $c) use ($st, &$sv, &$edNode): void {
    $sv = $c->scroll()->fill();
      $c->box(0, 400, 0xffffff)->shrink(0);
      $edNode = $st->view($c)->shrink(0);
    $c->end();
}, 600, 400);
$h->paints = true; // as a window, which paints each frame: the editor knows its height as it paints
$h->frame();
$st->select(200 * 5, 200 * 5);
$h->frame();
$h->tab();
$h->frame();
$top = 400 + $st->lineTop(200);
$y = $sv->scroll->y;
check($st->focused() && $y <= $top && $y + 400 >= $top + $edOf($st)->view->lineHeight, "focused, the view scrolled to $y, showing the caret at $top");
