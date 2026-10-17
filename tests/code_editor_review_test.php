<?php
// Regressions found by reviewing the editor (mygo's review_test.go). Its
// tests of edits from other goroutines have nothing to check in PHP, which
// edits a buffer from one thread.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, TextInputRange};
use MyPHP\UI\Editor\{Buffer, Change, Command, Completion, CompletionRequest, Diagnostic, Edit, Editor, GoToLineBar, Highlighter, HoverRequest, Language, Languages, Snapshot, State, Token};

require_once __DIR__ . '/EditorTest.php';

$edOf = fn(State $st): Editor => (fn() => $this->ed)->call($st);
/** Editors of the states in a row, each growing, the first focused. */
$row = function (array $states, float $w, float $h) use (&$shown): Headless {
    $nodes = [];
    $t = new Headless(function (Context $c) use ($states, &$nodes, &$shown) {
        $c->row(grow: 1);
          foreach ($states as $i => $st) {
              if ($i === 1 && isset($shown) && !$shown) { continue; }
              $nodes[$i] = $st->view($c); $nodes[$i]->grow = 1;
          }
        $c->end();
    }, $w, $h);
    $t->paints = true;
    $t->frame(1);
    $t->rt->focusOn($nodes[0]->id);
    $t->frame();
    return $t;
};

// Clicking a completion other than the last chose it while the rows after it were still being built from the list it closed.
$ct = new EditorTest('go', '|');
$ct->st->onCompletion = function (CompletionRequest $r) use ($ct) {
    $ct->st->showCompletions($r->offset - strlen($r->prefix), [new Completion('alpha'), new Completion('alpine'), new Completion('alps')]);
};
$ct->typ('al');
$ct->h->clickText('alpha');
$ct->want('alpha|', 'clicking the first completion chooses it');

// The lines a highlighter reports final hold the tokens a fresh tokenization gives, whatever edits and passes came before.
$lang = Languages::named('go');
$pieces = ['/*', '*/', "\n", 'x := 1', '`', '"', 'y', "\n\n"];
$bad = '';
for ($seed = 0; $seed < 300 && $bad === ''; $seed++) {
    mt_srand($seed);
    $buf = new Buffer(implode("\n", array_fill(0, 80, 'x := 1')));
    $buf->setLanguage($lang);
    for ($step = 0; $step < 12 && $bad === ''; $step++) {
        $buf->highlighter()->highlight(mt_rand(0, $buf->lineCount() - 1), 1.0);
        $off = mt_rand(0, $buf->len());
        $end = min($buf->len(), $off + mt_rand(0, 3));
        $buf->applyEdits(new Edit($off, $end, $pieces[mt_rand(0, count($pieces) - 1)]));
        $h = $buf->highlighter();
        $h->highlight(mt_rand(0, $buf->lineCount() - 1), 1.0);
        $state = 0;
        for ($i = 0; $i < $buf->lineCount(); $i++) {
            [$want, $state] = $lang->tokenizer->tokenize($buf->line($i), $state, []);
            [$got, $ok] = $h->tokens($i, $buf->line($i), []);
            if ($ok && $got !== $want) { $bad = "seed $seed step $step: line $i final with other tokens"; break; }
        }
    }
}
same('', $bad, 'the lines a highlighter reports final hold a fresh tokenization\'s tokens');

// Swapping a State's buffer kept the old buffer's carets, past the new text's end, and its find matches.
$long = str_repeat("line\n", 100);
$st = new State(new Buffer($long));
$st->buffer->setLanguage(Languages::named('go'));
$t = $row([$st], 500, 300);
$st->select(strlen($long), strlen($long));
$st->find('line');
$t->frame();
$nb = new Buffer('short line');
$nb->setLanguage(Languages::named('go'));
$st->buffer = $nb;
$t->frame();
same(1, $st->findMatches()[0], 'the find bar searches the new buffer');
$st->select(0, 0);
$t->type('x');
same('xshort line', (string)$nb, 'typing goes into the new buffer');

// Diagnostics given for a version are moved through the edits since, a new buffer's version 0 too, and keep End at or after Start.
$ct = new EditorTest('go', '|abc def');
$v0 = $ct->st->buffer->version();
$ct->typ('xx');
$ct->st->setDiagnostics($v0, [new Diagnostic(4, 7)]);
$d = $ct->st->diagnostics()[0];
same('def', $ct->st->buffer->slice($d->start, $d->end), 'a diagnostic of version 0 moves with the edits');

// Several insertions at one offset: a left-biased offset stays before them all.
$b = new Buffer('ab');
$b->applyEdits(new Edit(1, 1, 'X'), new Edit(1, 1, 'Y'));
$cs = $b->changesTo(0)[0][0];
same([1, 3], [Change::mapOffset(1, $cs, Change::LEFT), Change::mapOffset(1, $cs, Change::RIGHT)], 'an offset at two insertions, biased left and right');
$ct = new EditorTest('go', '[abc][de]');
$ct->typ('(');
$ct->want('([abc])([de])', 'brackets typed wrap each selection');

// A selection keeps what it holds as text is inserted at its edges, by another editor or a command that leaves the selections to the buffer.
$buf = new Buffer('hello world');
$a = new State($buf); $b = new State($buf);
$t = $row([$a, $b], 800, 300);
$b->select(0, 5);
$a->select(5, 5);
$t->frame();
$a->insert('X');
$t->frame();
$s = $b->selection();
same([0, 5], [$s->start(), $s->end()], 'a selection does not grow over what another editor typed at its edge');
$ct = new EditorTest('go', 'x := [foo]');
$ct->run(Command::TOGGLE_BLOCK_COMMENT);
$ct->want('x := /* [foo] */', 'a block comment leaves the selection on what it held');

// Undo Selection takes back selections moved through the edits since.
$ct = new EditorTest('', 'abc def|');
$ct->st->select(0, 0);
$ct->h->frame();
$ct->typ('XX');
$ct->key(Key::COMMAND, Key::U, 'u');
$ct->want('XXabc def|', 'Undo Selection takes back a selection moved by the edits');

// Find in the selection: the selection found in moves with the edits, so that replacing stays within it.
$ct = new EditorTest('', '[aaa aaa aaa] aaa');
$ed = $edOf($ct->st);
$f = $ed->find;
$ct->run(Command::FIND_IN_SELECTION);
$f->query = 'aaa'; $f->replace = 'b'; $f->stale = true;
$ct->h->frame();
for ($i = 0; $i < 4; $i++) { $f->replaceOne($ed); $ct->h->frame(); }
same('b b b aaa', (string)$ct->st->buffer, 'replacing stays within the selection found in');

// An editor scrolled down stays on the lines it shows as another editor of the buffer adds lines above them.
$lines = [];
for ($i = 0; $i < 200; $i++) { $lines[] = 'line ' . str_repeat('x', $i % 7); }
$buf = new Buffer(implode("\n", $lines));
$a = new State($buf); $b = new State($buf);
$t = $row([$a, $b], 800, 300);
$b->scrollTo(100);
$t->frame();
$edB = $edOf($b);
$top = fn() => $buf->line($edB->view->heights->lineAt($edB->scrollY));
$was = $top();
$a->select(0, 0);
$t->frame();
for ($i = 0; $i < 5; $i++) { $t->key(Key::RETURN, 'insertNewline:', 0, "\r"); }
$t->frame();
same($was, $top(), 'an editor stays on its lines as another adds lines above them');

// A language set after the first frame highlights the lines shown.
$ct = new EditorTest('', '|func main() {}');
$ct->st->buffer->setLanguage(Languages::named('go'));
$ct->h->frame(); $ct->h->frame();
$toks = $edOf($ct->st)->view->line(0)->toks;
check($toks !== [] && $toks[0][1] === Token::KEYWORD, 'a language set late highlights the lines');

// An editor not built while more edits than the log keeps were made follows them by the lines that changed, rather than keeping offsets of the old text.
$buf = new Buffer("hello world\nsecond\n");
$a = new State($buf); $b = new State($buf);
$shown = true;
$t = $row([$a, $b], 800, 300);
$b->select(strlen("hello world\n"), strlen("hello world\nsecond"));
$t->frame();
$shown = false;
$a->select(0, 0);
$t->frame();
for ($i = 0; $i < 300; $i++) { $t->type('x'); }
$shown = true;
$t->frame();
same('second', $b->selectedText(), 'an editor far behind the log keeps its selection on its lines');
unset($shown);

// Line commands with whole-line selections, as Cmd+L makes, and with adjacent carets.
foreach ([
    ["one\n[two\n]three", Command::MOVE_LINES_UP, "[two\n]one\nthree"],
    ["[one\n]two\nthree", Command::MOVE_LINES_DOWN, "two\n[one\n]three"],
    ["one\n[two\n]three", Command::DUPLICATE_LINES_DOWN, "one\ntwo\n[two\n]three"],
    ["one\n[two\n]three", Command::DUPLICATE_LINES_UP, "one\n[two\n]two\nthree"],
] as [$from, $cmd, $want]) {
    $ct = new EditorTest('go', $from);
    $ct->run($cmd);
    $ct->want($want, "a line command on whole lines from " . json_encode($from));
}
// Transposing at carets next to each other.
$ct = new EditorTest('', 'ab|c|d');
$ct->run(Command::TRANSPOSE);
same(4, strlen((string)$ct->st->buffer), 'transposing at adjacent carets keeps the text\'s length');
// Sorting keeps the lines selected.
$ct = new EditorTest('', "[c\nb\na]");
$ct->run(Command::SORT_LINES);
$ct->want("[a\nb\nc]", 'sorting keeps the lines selected');
// Select Line again extends to the next line.
$ct = new EditorTest('', "o|ne\ntwo\nthree");
$ct->run(Command::SELECT_LINE);
$ct->run(Command::SELECT_LINE);
$ct->want("[one\ntwo\n]three", 'Select Line again extends to the next line');
// Delete Line keeps the column.
$ct = new EditorTest('', "one\ntw|o\nthree");
$ct->run(Command::DELETE_LINE);
$ct->want("one\nth|ree", 'Delete Line keeps the column');

// Tab goes to the next tab stop past hard tabs, and indents the line of a selection, as Zed's.
$ct = new EditorTest('python', "\tx|");
$ct->key(0, Key::TAB, "\t");
$ct->want("\tx   |", 'Tab goes to the next tab stop past a hard tab');
$ct = new EditorTest('python', 'x = [foo]');
$ct->key(0, Key::TAB, "\t");
$ct->want('    x = [foo]', 'Tab indents the line of a selection');

// Turning soft wrap on scrolls back to the lines' start.
$ct = new EditorTest('', '|' . str_repeat('word ', 100));
$ct->run(Command::MOVE_LINE_END);
$ct->h->frame();
$ct->run(Command::TOGGLE_SOFT_WRAP);
$ct->h->frame(); $ct->h->frame();
same(0.0, $edOf($ct->st)->scrollX, 'soft wrap scrolls back to the lines\' start');

// Folding keeps carets on the line a fold starts at.
$ct = new EditorTest('go', "func a() {\n\tx()\n}\nfunc b() {\n\ty|()\n}\n");
$ct->run(Command::FOLD_ALL);
same(3, $ct->st->cursorPos()->line, 'Fold All puts the caret on the line its fold starts at');
$ct = new EditorTest('', "[a\nb\nc\nd]\ne");
$ct->run(Command::FOLD_SELECTION);
$ct->h->frame();
check($edOf($ct->st)->caretRect($ct->st->cursor()) !== null, 'Fold Selection leaves the caret where it shows');

// Toggle Line Numbers gives the numbers room in the gutter.
$st = new State(new Buffer("a\nb"));
$st->lineNumbers = State::LINE_NUMBERS_OFF; $st->noFolding = true;
$t = $row([$st], 400, 200);
$st->run(Command::TOGGLE_LINE_NUMBERS);
$t->frame();
check($edOf($st)->gutterW > 0, 'line numbers toggled on have a gutter');

// No hover rests where no text is: over the gutter, or past a line's end.
$st = new State(new Buffer("first line\nfoo := bar\n"));
$asked = [];
$st->onHover = function (HoverRequest $r) use (&$asked) { $asked[] = $r->offset; };
$t = $row([$st], 600, 300);
$ed = $edOf($st);
$y = $ed->view->heights->geometry(1)->rows - $ed->scrollY + $ed->view->lineHeight / 2;
$move = function (float $x, float $y) use ($t) { $t->input->mouse($x, $y); $t->input->push(['move', $x, $y]); $t->frame(); };
$rest = function () use ($ed, $t) { $ed->hover->restAt = hrtime(true) / 1e9 - 3600; $t->frame(); };
$move($ed->textX + 40, $y);
$move(5, $y); // to the gutter
$rest();
$move(500, $y); // past the line's end
$rest();
same([], $asked, 'no hover is asked for off the text');

// Go to line's column counts characters.
$ct = new EditorTest('', '|héllo');
$ed = $edOf($ct->st);
$ed->goTo->show($ed);
$ed->goTo->field->text = '1:3';
(new GoToLineBar(new Context(Editor::$text), $ed))->go();
$ct->h->frame();
$ct->typ('x');
same('héxllo', (string)$ct->st->buffer, 'going to column 3 counts characters');

// Finding scrolls sideways to the match it selects.
$ct = new EditorTest('', '|' . str_repeat('x', 300) . 'needle');
$ct->st->find('needle');
$ct->h->frame(); $ct->h->frame();
$ed = $edOf($ct->st);
$cr = $ed->caretRect($ct->st->cursor());
check($cr !== null && $cr[0] >= $ed->scrollX && $cr[0] <= $ed->scrollX + $ed->r[2], 'finding scrolls sideways to the match');

// Lua's --[[ comments are block comments.
$tk = Languages::named('lua')->tokenizer;
$state = 0; $toks = [];
foreach (['--[[ a', 'b ]]'] as $line) { [$toks, $state] = $tk->tokenize($line, $state, []); }
check(count($toks) === 1 && $toks[0][1] === Token::COMMENT, 'the second line of a Lua block comment is a comment');

// With soft wrap, a press past a row's end and Down put the caret on that row, not at the start of the next.
$st = new State(new Buffer(str_repeat('x', 38) . "\nabcdefghij " . str_repeat('z', 45)));
$st->softWrap = true; $st->wrapColumn = 40;
$t = $row([$st], 800, 400);
$st->select(35, 35);
$t->frame();
$t->down();
$t->frame();
$ed = $edOf($st);
same($ed->rowTop(1), $ed->caretRect($st->cursor())[1], 'Down goes to the first row of the wrapped line');
$st2 = new State(new Buffer('abcdefghij ' . str_repeat('z', 45)));
$st2->softWrap = true; $st2->wrapColumn = 40;
$t = $row([$st2], 800, 400);
$ed = $edOf($st2);
$t->click($ed->textX + $ed->view->charWidth * 30, $ed->rowTop(0) + $ed->view->lineHeight / 2);
$t->frame();
same($ed->rowTop(0), $ed->caretRect($st2->cursor())[1], 'a press past a row\'s end puts the caret on that row');

// Moving sideways goes over a fold, rather than into it.
$ct = new EditorTest('go', "func a() {\n\tx()\n\ty()\n}\n");
$ct->st->fold(0);
$ct->h->frame();
$ct->st->select(10, 10);
$ct->h->frame();
$ct->key(0, Key::RIGHT);
$ct->h->frame();
same([1, 3], [count($ct->st->folded()), $ct->st->cursorPos()->line], 'Right goes over a fold');
$ct->key(0, Key::LEFT);
$ct->h->frame();
same([1, 10], [count($ct->st->folded()), $ct->st->cursor()], 'and Left back');

// A suggestion goes as the caret moves, and the caret shows before it.
$ct = new EditorTest('', 'abc|');
$ct->st->setSuggestion(3, 'defgh');
$ct->h->frame();
$ed = $edOf($ct->st);
check($ed->caretRect(3)[0] <= 3 * $ed->view->charWidth + 1, 'the caret shows before the suggestion');
$ct->key(0, Key::LEFT);
check($ed->suggestion === '' && $ed->view->inlays === [], 'the suggestion goes as the caret moves');

// Completing at several carets replaces the word typed only where it is.
$ct = new EditorTest('', "ab x|\nfoo|");
$ct->st->onCompletion = function (CompletionRequest $r) use ($ct) { $ct->st->showCompletions($r->offset - strlen($r->prefix), [new Completion('fooBar')]); };
$ct->h->frame();
$ct->st->run(Command::SHOW_COMPLETIONS);
$ct->h->frame();
$ct->key(0, Key::RETURN, "\r");
same("ab xfooBar\nfooBar", (string)$ct->st->buffer, 'completing at several carets');

// A regular expression's replacement expands its groups as found in the text, \B and all.
$ct = new EditorTest('', '|fooBar bazQux');
$ed = $edOf($ct->st);
$f = $ed->find;
$f->open = true; $f->regex = true; $f->caseSens = true; $f->query = '\B([A-Z])'; $f->replace = '_$1'; $f->stale = true;
$f->replaceAll($ed);
same('foo_Bar baz_Qux', (string)$ct->st->buffer, 'a regular expression\'s replacement expands its groups');

// After edits, the matches found by searching only the lines changed are those of a search of the whole text.
$pieces = ['cat', 'Cat', ' ', "\n", 'dog', 'ca', 't', 'é', 'catalog'];
$bad = '';
for ($seed = 0; $seed < 150 && $bad === ''; $seed++) {
    mt_srand($seed);
    $ct = new EditorTest('', "|cat dog\ncat cat\nnothing\ncatalog Cat\n");
    $ed = $edOf($ct->st);
    $f = $ed->find;
    $ct->st->find('cat');
    $f->caseSens = mt_rand(0, 1) === 0; $f->wholeWord = mt_rand(0, 1) === 0;
    if (mt_rand(0, 2) === 0) { $f->query = 'é'; }
    $ct->h->frame();
    for ($step = 0; $step < 15; $step++) {
        $b = $ct->st->buffer;
        $off = mt_rand(0, $b->len());
        $end = min($b->len(), $off + mt_rand(0, 4));
        $b->applyEdits(new Edit($off, $end, $pieces[mt_rand(0, count($pieces) - 1)]));
        $ct->h->frame();
        $got = $f->matches;
        $f->stale = true;
        $f->search($ed);
        if ($got != $f->matches) { $bad = "seed $seed step $step: " . json_encode((string)$b); break; }
    }
}
same('', $bad, 'searching the lines changed finds what a full search does');

// Lines copied whole paste as lines, from several carets at one, and in another editor.
$ct = new EditorTest('', "a|\nb|\nc");
$ct->key(Key::COMMAND, Key::C, 'c');
$ct->st->select(5, 5);
$ct->key(Key::COMMAND, Key::V, 'v');
$ct->want("a\nb\na\nb\nc|", 'lines copied from two carets paste as lines at one');
$a = new EditorTest('', "o|ne\ntwo");
$a->key(Key::COMMAND, Key::C, 'c');
$b = new EditorTest('', "abc\nd|ef");
($b->h->rt->writeClipboard)(($a->h->rt->readClipboard)());
$b->key(Key::COMMAND, Key::V, 'v');
$b->want("abc\none\nd|ef", 'a line copied in one editor pastes as a line in another');

// Backspace takes a pair together only when the editor closed it.
$ct = new EditorTest('go', 'f(|)');
$ct->key(0, Key::DELETE);
$ct->want('f|)', 'Backspace leaves a closer the editor did not type');

// Select Next skips matches overlapping a selection.
$ct = new EditorTest('', '[aa]aa');
$ct->run(Command::SELECT_NEXT);
$ct->run(Command::SELECT_NEXT);
$ct->want('[aa][aa]', 'Select Next skips overlapping matches');

// A flag is one character to the caret.
$ct = new EditorTest('', '|🇺🇸🇫🇷x');
$ct->run(Command::MOVE_RIGHT);
$ct->want('🇺🇸|🇫🇷x', 'Right moves over a flag');
$ct->run(Command::MOVE_RIGHT);
$ct->want('🇺🇸🇫🇷|x', 'and over the next');
$ct->key(0, Key::DELETE);
$ct->want('🇺🇸|x', 'Backspace deletes a flag');

// Text with \r\n line endings edits as lines ending in \n, and is written back with \r\n.
$ct = new EditorTest('', "a|b\r\ncd");
$ct->run(Command::MOVE_LINE_END);
$ct->typ('x');
$b = $ct->st->buffer;
same("abx\ncd", (string)$b, 'a text with \\r\\n edits as \\n lines');
same(["abx\r\ncd", "\r\n"], [$b->snapshot()->text(), $b->lineEnding()], 'and is written with \\r\\n');
$ct = new EditorTest('', "one\r\ntw|o\r\nthree");
$ct->key(Key::COMMAND, Key::C, 'c');
$ct->st->select(1, 1);
$ct->key(Key::COMMAND, Key::V, 'v');
$ct->want("two\no|ne\ntwo\nthree", 'a line of a \\r\\n text copies and pastes as a line');

// An input method composing in a range it names, with several carets, composes there alone and commits there.
$ct = new EditorTest('', "e|\ne|");
$ed = $edOf($ct->st);
$ed->setMarkedText(new TextInputRange(2, 3), 'ab', new TextInputRange(2, 2));
same("e\nab", (string)$ct->st->buffer, 'composing in a range named composes there alone');
$ed->replaceText(null, 'X');
same("e\nX", (string)$ct->st->buffer, 'and commits there');

// Undo and redo of a step of several edits give a highlighter each edit's own texts, as an incremental parser needs.
final class CheckingHighlighter implements Highlighter
{
    /** @param list<string> $bad */
    public function __construct(public array &$bad) {}

    public function edit(Snapshot $old, Snapshot $new, array $changes): void
    {
        $text = (string)$old;
        for ($i = count($changes) - 1; $i >= 0; $i--) {
            $c = $changes[$i];
            $text = substr($text, 0, $c->oldStart) . $new->slice($c->newStart, $c->newEnd) . substr($text, $c->oldEnd);
        }
        if ($text !== (string)$new) { $this->bad[] = "$old -> $text, not $new"; }
    }

    public function highlight(int $last, float $budget): bool { return true; }

    public function tokens(int $i, string $text, array $dst): array { return [$dst, true]; }
}
$bad = [];
$lang = new Language('Check', highlighter: function (Snapshot $text) use (&$bad) { return new CheckingHighlighter($bad); });
$ct = new EditorTest('', '|');
$ct->st->buffer->setLanguage($lang);
$ct->h->frame();
$ct->typ('abc');
$ct->h->frame();
$ct->key(Key::COMMAND, Key::Z, 'z');
$ct->h->frame();
$ct->key(Key::COMMAND | Key::SHIFT, Key::Z, 'z');
$ct->h->frame();
same([], $bad, 'undo and redo give the highlighter each edit\'s texts');
