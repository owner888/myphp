<?php
// A custom text field built from MyPHP's text-input and layout primitives
// (mygo's examples/text-input): the app owns the text, the selection, the
// marked text of input methods and the drawing; handleTextInput gives the
// field the system's text services.
// Run: ./run.sh text-input
declare(strict_types=1);

use MyPHP\{App, Clipboard, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, InputEvent, Key, Painter, Role, TextInputClient, TextInputRange, TextInputSelection, TextLayout, TextStyle};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** The UTF-16 length of $s, in which the system's text services count. */
function utf16Len(string $s): int
{
    return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
}

/** The byte offset in $s of its UTF-16 offset $u, at the start of the character it falls in: an input method may ask for half of a surrogate pair (an emoji's). */
function utf16ByteOffset(string $s, int $u): int
{
    $bytes = 0;
    foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
        $u -= strlen($ch) === 4 ? 2 : 1;
        if ($u < 0) { break; }
        $bytes += strlen($ch);
    }
    return $bytes;
}

/** The field's state: the app's own, which a larger editor could replace with its buffer, selections and history without changing the API. */
final class Field implements TextInputClient
{
    public TextInputSelection $selection;
    public ?TextInputRange $marked = null;
    public ?TextLayout $layout = null;
    /** Shapes the text, given a context (the window's text system). */
    public ?Closure $shaper = null;

    public function __construct(public string $text)
    {
        $end = utf16Len($text);
        $this->selection = new TextInputSelection(new TextInputRange($end, $end));
    }

    public function shape(): void { $this->layout = $this->shaper === null ? null : ($this->shaper)($this->text); }

    public function textForRange(TextInputRange $r): array
    {
        [$a, $z] = [utf16ByteOffset($this->text, $r->start), utf16ByteOffset($this->text, $r->end)];
        return [substr($this->text, $a, $z - $a), new TextInputRange(utf16Len(substr($this->text, 0, $a)), utf16Len(substr($this->text, 0, $z)))];
    }

    public function selection(): TextInputSelection { return $this->selection; }
    public function markedRange(): ?TextInputRange { return $this->marked; }

    private function target(?TextInputRange $r): TextInputRange { return $r ?? $this->marked ?? $this->selection->range; }

    public function replaceText(?TextInputRange $r, string $s): void
    {
        $want = $this->target($r);
        [$a, $z] = [utf16ByteOffset($this->text, $want->start), utf16ByteOffset($this->text, $want->end)];
        $this->text = substr($this->text, 0, $a) . $s . substr($this->text, $z);
        $end = $want->start + utf16Len($s);
        $this->selection = new TextInputSelection(new TextInputRange($end, $end));
        $this->marked = null;
        $this->shape();
    }

    public function setMarkedText(?TextInputRange $r, string $s, TextInputRange $selected): void
    {
        $want = $this->target($r);
        $this->replaceText($want, $s);
        $this->marked = new TextInputRange($want->start, $want->start + utf16Len($s));
        $this->selection = new TextInputSelection(new TextInputRange($this->marked->start + $selected->start, $this->marked->start + $selected->end));
    }

    public function unmarkText(): void { $this->marked = null; }

    public function boundsForRange(TextInputRange $r): ?array
    {
        if ($this->layout === null) { return null; }
        $b = $this->layout->caret($r->start);
        if ($r->start !== $r->end && ($rects = $this->layout->selectionRects($r)) !== []) { $b = $rects[0]; }
        return [[$b[0] + 12, $b[1] + 12, $b[2], $b[3]], $r]; // the rectangle and the range it is of (TextInputClient)
    }

    public function indexForPoint(float $x, float $y): ?int
    {
        return $this->layout?->indexAt($x - 12, $y - 12);
    }

    private function move(int $to, bool $extend): void
    {
        if (!$extend) { $this->selection = new TextInputSelection(new TextInputRange($to, $to)); return; }
        $anchor = $this->selection->reversed ? $this->selection->range->end : $this->selection->range->start;
        $this->selection = new TextInputSelection(new TextInputRange(min($anchor, $to), max($anchor, $to)), $to < $anchor);
    }

    public function input(InputEvent $ev): bool
    {
        $shift = ($ev->mods & Key::SHIFT) !== 0;
        switch ($ev->kind) {
            case InputEvent::POINTER_DOWN:
                $this->move($this->indexForPoint($ev->x, $ev->y) ?? 0, $shift);
                return true;
            case InputEvent::POINTER_MOVE:
                if ($ev->button === 0) { $this->move($this->indexForPoint($ev->x, $ev->y) ?? 0, true); return true; }
                return false;
            case InputEvent::POINTER_UP:
                return true;
            case InputEvent::KEY_DOWN:
                if (($ev->mods & Key::MODIFIERS) === Key::COMMAND && $ev->key === Key::A) { $this->selection = new TextInputSelection(new TextInputRange(0, utf16Len($this->text))); return true; }
                switch ($ev->key) {
                    case Key::HOME: $this->move(0, $shift); return true;
                    case Key::END: $this->move(utf16Len($this->text), $shift); return true;
                    case Key::DELETE:
                    case Key::FORWARD_DELETE:
                        $r = $this->selection->range;
                        if ($r->start === $r->end && $this->layout !== null) {
                            $next = $ev->key === Key::FORWARD_DELETE ? $this->layout->nextBoundary($r->end) : $this->layout->previousBoundary($r->start);
                            $r = new TextInputRange(min($next, $r->start), max($next, $r->end));
                        }
                        $this->replaceText($r, '');
                        return true;
                }
                return false;
            case InputEvent::COMMAND:
                switch ($ev->text) {
                    case 'selectAll': $this->selection = new TextInputSelection(new TextInputRange(0, utf16Len($this->text))); return true;
                    case 'copy':
                    case 'cut':
                        Clipboard::writeText($this->textForRange($this->selection->range)[0]);
                        if ($ev->text === 'cut') { $this->replaceText(null, ''); }
                        return true;
                    case 'paste': $this->replaceText(null, Clipboard::readText()); return true;
                    case 'delete': $this->replaceText(null, ''); return true;
                }
        }
        return false;
    }

    public function draw(Painter $p, array $r): void
    {
        if ($this->layout === null) { return; }
        $t = $p->theme();
        [$x, $y] = [$r[0] + 12, $r[1] + 12];
        foreach ($this->layout->selectionRects($this->selection->range) as $b) { $p->fill([$b[0] + $x, $b[1] + $y, $b[2], $b[3]], $t->selection); }
        $p->textLayout($this->layout, $x, $y, $t->text);
        $b = $this->layout->caret($this->selection->caret());
        $p->fill([$b[0] + $x, $b[1] + $y, $b[2], $b[3]], $t->accent);
    }
}

function view(Context $c, Field $f): void
{
    $t = $c->theme;
    if ($f->shaper === null) {
        $font = new TextStyle();
        $font->size = 18;
        $f->shaper = fn(string $s) => $c->shapeText($s, 0, $font);
        $f->shape();
    }
    $c->column(24, 12, 1);
      $c->text('Application-owned text input', 22, style: Font::BOLD);
      $c->muted('Click, type, compose with your input method, select with Shift, and use the Edit menu.');
      $c->box(0, 60, $t->surface)->fillWidth()->border(1, $t->border)->radius($t->radius)->label('Custom text field')->role(Role::TEXT_FIELD)->value($f->text)
          ->focusable()->handleTextInput($f)->autoFocus()->handleInput($f->input(...))->draw($f->draw(...));
      $c->muted('Storage, navigation, clipboard policy and rendering belong to this example; the primitive supplies native text services.');
    $c->end();
}

$s = new Field('Type here: é, 😀, العربية, עברית');
App::setName('Text input');
App::whenReady(function () use ($s) {
    App::setMenu(new Menu([new MenuItem(role: 'appMenu'), new MenuItem(role: 'editMenu')]));
    new Window(new WindowOptions(title: 'Text input primitive', width: 760, height: 240, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
