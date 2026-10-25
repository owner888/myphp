<?php
// More of mygo's lifetime_test.go, layout_grow_test.go and locale_test.go
// (TestListStateFocusDuringRowBuild, TestListStateInputRequiresItsActiveContext,
// TestGrowInColumnOfContentHeight, TestLang): a list's state asked while
// its rows build, for a list, a table and an outline; asked where it was
// not built; a growing column in a row of its content's height; the
// language of text, from the nodes around it and the app's.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\App;
use MyPHP\UI\{Context, Key, ListState, Node, OutlineState, Span, TableColumn, TextState};

// While its rows build, the state of a list, a table and an outline gives the focus and says so; a field in a row has it, the list its shortcut.
foreach (['list', 'table', 'outline'] as $kind) {
    $outline = new OutlineState(); $s = $kind === 'outline' ? $outline->list : new ListState();
    $field = new TextState(''); $nested = false; $unavailable = false; $ownFocus = false; $within = false; $keys = 0; $id = -1;
    $h = new Headless(function (Context $c) use ($kind, $outline, $s, $field, &$nested, &$unavailable, &$ownFocus, &$within, &$keys, &$id): void {
        $row = function () use ($c, $s, $field, &$nested, &$unavailable, &$ownFocus): void {
            if (!$s->focus($c) || !$s->focused($c) || !$s->focusWithin($c)) { $unavailable = true; }
            if ($nested) {
                $in = $c->input($field)->label('Field');
                $c->runtime->focusOn($in->id);
                if ($s->focused($c)) { $ownFocus = true; }
            } else {
                $c->text('Row');
            }
        };
        $c->column(0, 0, 1);
          $e = match ($kind) {
              'list' => $c->list($s, 1, function (int $i) use ($row): void { $row(); }),
              'table' => $c->table($s, [new TableColumn('Name')], 1, function (int $r, int $col) use ($row): void { $row(); }),
              'outline' => $c->outline($outline, ['file'], fn($p) => [], function ($p) use ($row): void { $row(); }),
          };
          $e->grow = 1;
          $id = $kind === 'table' ? $e->kids[count($e->kids) - 1]->id : $e->id; // a table's rows are its list, below its header, which has the focus
          $within = $s->focusWithin($c);
          if ($s->shortcut($c, 'k', Key::COMMAND)) { $keys++; }
        $c->end();
    }, 200, 150);
    $h->frame();
    check(!$unavailable, "$kind: its state while its rows build");
    same($id, $h->rt->focus, "$kind: the focus on the list its state shows");
    $h->shortcut('k', Key::COMMAND);
    same(1, $keys, "$kind: its shortcut");
    $nested = true; $h->frame();
    $h->shortcut('k', Key::COMMAND);
    check($h->focused()?->label === 'Field' && $within && $keys === 2 && !$ownFocus, "$kind: a field in a row has the focus, not the list, which takes its shortcut ($keys)");
}

// Asked where it was not built: another window's context, at the same frame and pass; a state no list shows.
$s = new ListState();
$owner = new Headless(function (Context $c) use ($s): void { $c->column(0, 0, 1); $c->list($s, 1, fn(int $i) => $c->text('File'))->grow = 1; $s->focus($c); $c->end(); }, 200, 100);
$owner->frame();
$used = fn(Context $c, ListState $s) => $s->focused($c) || $s->focusWithin($c) || $s->focus($c) || $s->shortcut($c, 'k', Key::COMMAND);
$other = []; $h = new Headless(function (Context $c) use ($s, $used, &$other): void { $other = [$used($c, new ListState()), $used($c, $s)]; }, 200, 100);
$h->frame();
same([false, false], $other, 'another window: a state no list shows, and one its own list shows');

// A growing column in a row of its content's height: what comes after the row comes after its content.
$h = new Headless(function (Context $c): void {
    $c->column(0, 0, 1);
      $c->row();
        $c->column(0, 0, 1);
          $c->column(10, 0, 1); $c->text('Label'); $c->end();
        $c->end();
      $c->end();
      $c->text('After');
    $c->end();
}, 400, 300);
$h->frame();
$label = $h->rect($h->byText('Label')); $after = $h->rect($h->byText('After'));
check($after[1] >= $label[1] + $label[3] + 10, 'after the row, below its content: ' . json_encode([$label, $after]));

// The language of text: what the nodes around it say, its own, "" back to theirs; a rich text's spans; the app's for the rest.
$styles = []; $spans = []; $locale = null;
$h = new Headless(function (Context $c) use (&$styles, &$spans, &$locale): void {
    $locale = $c->locale();
    $c->column();
      $styles['plain'] = $c->text('直');
      $c->column()->lang('ja');
        $styles['quote'] = $c->text('直');
        $styles['inner'] = $c->text('直')->lang('zh-Hant');
        $styles['reset'] = $c->text('直')->lang('');
      $c->end();
      $spans = $c->richText([new Span('直'), new Span('骨', lang: 'ja')]);
    $c->end();
}, 200, 200);
$h->frame();
same(['plain' => null, 'quote' => 'ja', 'inner' => 'zh-Hant', 'reset' => 'ja'], array_map(fn(Node $n) => $n->textStyle()->lang, $styles), 'the language each text is in');
same([null, 'ja'], array_map(fn(Span $sp) => $sp->lang, $spans->inlineSpans()), 'the spans');
$was = App::locale();
App::setLanguages('en', 'en-GB', 'zh-Hans', 'zh-Hant', 'fa', 'de', 'fr-CA'); // as datetime_test's
App::setLocale('zh-Hans'); $h->frame();
check($locale === 'zh-Hans' && $h->metrics->textSystem()->language() === 'zh-Hans', 'the app shows Simplified Chinese: the view sees it, the text with no language of its own is in it');
App::setLocale($was); $h->frame();
same($was, $h->metrics->textSystem()->language(), 'and back');
