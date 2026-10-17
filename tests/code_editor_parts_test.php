<?php
// The parts of a code editor around its text (mygo's parts_test.go): the
// find bar, the go to line bar, the completions and the hover built by
// the app's render functions, and the hover following the pointer.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Node};
use MyPHP\UI\Editor\{Buffer, Command, Completion, CompletionMenu, CompletionRequest, FindBar, GoToLineBar, HoverCard, HoverRequest, State};

require_once __DIR__ . '/EditorTest.php';

$edOf = fn(State $st) => (fn() => $this->ed)->call($st);

// The find bar of the app's, built in place of the default with its handle.
$ct = new EditorTest('', "|cat dog cat\ncat");
$st = $ct->st;
$st->renderFindBar = function (Context $c, Node $bar, FindBar $f) {
    $f->queryInput($c->input($f->query(), 'Search', 100));
    [$n] = $f->matches();
    $c->text("found $n");
    if ($c->clicked($c->button('Onward'))) { $f->next(); }
    if ($c->clicked($c->button('Every'))) { $f->selectAll(); }
};
$st->find('cat');
$ct->h->frame();
check(in_array('found 3', $ct->h->texts(), true) && !in_array('Aa', $ct->h->texts(), true), 'the app\'s find bar is built in place of the default');
$ct->h->clickText('Onward');
same(1, $st->findMatches()[1], 'its button goes to the next match');
$ct->h->clickText('Every');
$ct->want("[cat] dog [cat]\n[cat]", 'and its other selects every match');

// Going to a line with a bar of the app's.
$ct = new EditorTest('', "|a\nb\nc\nd");
$ct->st->renderGoToLine = function (Context $c, Node $bar, GoToLineBar $g) {
    $g->input($c->input($g->text(), 'Line', 80));
    $c->text('of ' . $g->lineCount());
};
$ct->run(Command::GO_TO_LINE);
check(in_array('of 4', $ct->h->texts(), true), 'the app\'s go to line bar is built');
$ct->h->type('3');
$ct->h->enter();
$ct->want("a\nb\n|c\nd", 'Enter in its field goes to the line');

// Completions in a list of the app's, chosen by a click.
$ct = new EditorTest('go', '|');
$cst = $ct->st;
$ct->st->onCompletion = function (CompletionRequest $r) use ($cst) {
    $cst->showCompletions($r->offset - strlen($r->prefix), [new Completion('alpha'), new Completion('alpine'), new Completion('beta')]);
};
$ct->st->renderCompletions = function (Context $c, Node $panel, CompletionMenu $m) {
    $scroll = new \MyPHP\UI\ScrollState;
    foreach ($m->items() as $i => $it) {
        $m->item($i, $scroll, 20);
          $c->text('→ ' . $it->label);
        $c->end();
    }
};
$ct->typ('al');
check(in_array('→ alpine', $ct->h->texts(), true) && !in_array('→ beta', $ct->h->texts(), true), 'the app\'s completions, filtered by the word typed');
$ct->h->clickText('→ alpine');
$ct->want('alpine|', 'a click inserts the completion');

// A hover of the app's, wrapping the default.
$ct = new EditorTest('go', '|x := 1');
$ct->st->renderHover = function (Context $c, Node $panel, HoverCard $h) {
    HoverCard::default($c, $panel, $h);
    $panel->color = 0xffffc8;
};
$ct->st->showHover(0, 1, 'x is an int');
$ct->h->frame();
check(in_array('x is an int', $ct->h->texts(), true), 'the app\'s hover is built');

// The pointer passing over words on its way out of the editor asks for no hover, and leaving closes the hover it asked for, after a grace.
$st = new State(new Buffer("// Package server serves greetings.\nfunc main() {}\n"));
$asked = [];
$st->onHover = function (HoverRequest $r) use ($st, &$asked) {
    $asked[] = $r->offset;
    $st->showHover($r->offset, $r->offset + 1, 'word');
};
$h = new Headless(function (Context $c) use ($st) {
    $c->row(0, 0, 1)->align(Node::STRETCH);
      $c->column()->size(200, 0); $c->end();
      $st->view($c)->grow = 1;
    $c->end();
}, 800, 400);
$h->paints = true;
$h->frame();
$ed = $edOf($st);
$move = function (float $x, float $y) use ($h) { $h->input->mouse($x, $y); $h->input->push(['move', $x, $y]); $h->frame(); };
$rest = function () use ($ed, $h) { $ed->hover->restAt = hrtime(true) / 1e9 - 3600; $h->frame(); };
$move(400, 10);
$move(100, 10);
$rest();
check($asked === [] && !$ed->hover->shown, 'no hover after the pointer left before resting');
$move(400, 10);
$rest();
check(count($asked) === 1 && $ed->hover->shown, 'resting on a word asks for a hover');
$move(100, 10);
check($ed->hover->shown, 'the hover stays a grace as the pointer leaves the editor');
$ed->hover->hideAt = hrtime(true) / 1e9 - 0.001;
$h->frame();
check(!$ed->hover->shown, 'and goes after it');
$st->run(Command::SHOW_HOVER);
$h->frame();
check($ed->hover->shown, 'a hover asked for from the keyboard stays with the pointer outside');

// The pointer can go from the text into its hover, which stays; Escape closes it, the editor keeping the keyboard.
$ct = new EditorTest('', "first line\nsecond line\nfoo := bar()\n");
$ct->st->onHover = function (HoverRequest $r) use ($ct) { $ct->st->showHover($r->offset, $r->offset + 3, 'func bar() int'); };
$ct->h->frame(); // the editor takes its options as it is built
$ed = $edOf($ct->st);
$y = $ed->view->heights->geometry(2)->rows - $ed->scrollY + $ed->view->lineHeight / 2;
$ct->move($ed->textX + 80, $y);
$ed->hover->restAt = hrtime(true) / 1e9 - 3600;
$ct->h->frame();
$card = $ct->h->byText('func bar() int');
check($card !== null, 'the hover shows');
$r = $ct->h->rect($card);
$ct->move($ed->textX + 80, $y - $ed->view->lineHeight);
$ct->move($r[0] + 4, $r[1] + $r[3] / 2);
$ed->hover->hideAt = hrtime(true) / 1e9 - 0.001;
$ct->h->frame();
check($ed->hover->shown, 'the hover stays as the pointer goes into it');
$ct->h->escape();
check(!$ed->hover->shown && $ct->st->focused(), 'Escape closes it, the editor keeping the keyboard');
