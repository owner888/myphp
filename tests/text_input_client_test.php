<?php
// A text input client of the app's own (mygo's text_input_client_test.go
// TestTextInputClientCompositionAndReplacement,
// TestTextInputClientQueriesGeometryAndLifetime,
// TestTextInputClientSwappingAndTypedNil): typing and composing in UTF-16
// units, the geometry the window gets, a composition the focus leaves
// unmarked, the client swapped, none.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, TextInputClient, TextInputRange, TextInputSelection, TextState};

/** A document of the test's, as mygo's primitiveClient: UTF-16 ranges over $text. */
final class PrimitiveClient implements TextInputClient
{
    public ?TextInputRange $mark = null;
    public int $changes = 0, $unmarks = 0;

    public function __construct(public string $text, public TextInputSelection $sel) {}

    private function byte(int $u16): int { return TextState::byteIndex($this->text, $u16); }
    private function u16(string $s): int { return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2); }
    public function textForRange(TextInputRange $r): array
    {
        [$a, $z] = [$this->byte($r->start), $this->byte($r->end)];
        return [substr($this->text, $a, $z - $a), new TextInputRange($this->u16(substr($this->text, 0, $a)), $this->u16(substr($this->text, 0, $z)))];
    }
    public function selection(): TextInputSelection { return $this->sel; }
    public function markedRange(): ?TextInputRange { return $this->mark; }
    private function rangeFor(?TextInputRange $r): TextInputRange { return $r ?? $this->mark ?? $this->sel->range; }
    private function replace(TextInputRange $r, string $s): void
    {
        [$a, $z] = [$this->byte($r->start), $this->byte($r->end)];
        $this->text = substr($this->text, 0, $a) . $s . substr($this->text, $z);
        $end = $r->start + $this->u16($s);
        $this->sel = new TextInputSelection(new TextInputRange($end, $end));
        $this->changes++;
    }
    public function replaceText(?TextInputRange $r, string $s): void { $this->replace($this->rangeFor($r), $s); $this->mark = null; }
    public function setMarkedText(?TextInputRange $r, string $s, TextInputRange $selected): void
    {
        $wanted = $this->rangeFor($r);
        $this->replace($wanted, $s);
        $this->mark = new TextInputRange($wanted->start, $wanted->start + $this->u16($s));
        $this->sel = new TextInputSelection(new TextInputRange($this->mark->start + $selected->start, $this->mark->start + $selected->end));
    }
    public function unmarkText(): void { $this->mark = null; $this->unmarks++; }
    public function boundsForRange(TextInputRange $r): ?array { return [[$r->start * 4.0, 5.0, max(1.0, ($r->end - $r->start) * 4.0), 20.0], $r]; }
    public function indexForPoint(float $x, float $y): ?int { return max(0, min((int)($x / 4), $this->u16($this->text))); }
}

// Typing and composing, counted in UTF-16 units past an emoji.
$c = new PrimitiveClient('A😀cafe', new TextInputSelection(new TextInputRange(7, 7)));
$h = new Headless(function (Context $ctx) use ($c): void { $ctx->box(250, 80, 0)->focusable()->autoFocus()->handleTextInput($c); }, 300, 100);
$h->frame();
check($h->input->client === $c, 'the window edits the client');
$h->type('!');
same('A😀cafe!', $c->text, 'typed at its selection');
$c->sel = new TextInputSelection(new TextInputRange(6, 7)); $h->frame();
$h->compose('é', 2);
check($c->text === 'A😀café!' && $c->mark == new TextInputRange(6, 7), 'composed over the selection, marked');
$h->type('é');
check($c->text === 'A😀café!' && $c->mark === null, 'committed');

// The geometry the window gets is the node's, and a composition the focus leaves is unmarked; the client gone, nothing edits it.
$c = new PrimitiveClient(str_repeat('a', 100), new TextInputSelection(new TextInputRange(80, 80)));
$show = true;
$h = new Headless(function (Context $ctx) use ($c, &$show): void {
    $ctx->column(10);
      if ($show) { $ctx->box(200, 40, 0)->focusable()->autoFocus()->handleTextInput($c); }
      $ctx->button('Other');
    $ctx->end();
}, 300, 100);
$h->frame();
same([10.0, 10.0], $h->input->clientOrigin, 'the client\'s rectangles are from the node');
$c->mark = new TextInputRange(3, 4);
$h->clickText('Other');
check($c->mark === null && $c->unmarks === 1 && $h->input->client === null, 'the focus left: unmarked, the window edits it no more');

// Clients swapped: the window edits the new one; none, nothing.
$first = new PrimitiveClient('one', new TextInputSelection(new TextInputRange(0, 0))); $second = new PrimitiveClient('two', new TextInputSelection(new TextInputRange(0, 0)));
$current = $first;
$h = new Headless(function (Context $ctx) use (&$current): void {
    $b = $ctx->box(200, 50, 0)->focusable()->autoFocus();
    if ($current !== null) { $b->handleTextInput($current); }
}, 250, 60);
$h->frame();
$current = $second; $h->frame();
$h->type('new');
check($first->changes === 0 && $second->text === 'newtwo', 'typing goes to the client of this frame');
$current = null; $h->frame();
same(null, $h->input->client, 'no client: the window edits none');
