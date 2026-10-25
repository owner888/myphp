<?php
// Dialogs and popovers as scopes of the focus and the keys (mygo's
// scope_test.go; TestUnhandledEscape is in flyout_test): a dialog takes and
// keeps the focus and gives it back, Escape closes the overlay on top,
// shortcuts behind it wait, assistive technology sees only it, a popover's
// Tab order follows its anchor.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, InputEvent, Key, Node, TextState};

/** A window with buttons behind a dialog of a text input, a select and two buttons, and a window shortcut. */
function scopeDialog(object $d): \Closure
{
    return function (Context $c) use ($d): void {
        $c->row(0, 8);
          $c->button('Before');
          if ($c->clicked($c->button('Open'))) { $d->open = true; }
          $c->button('After');
        $c->end();
        if ($c->shortcut('n', Key::COMMAND)) { $d->behind++; }
        $c->modal($d->open, function () use ($c, $d): void {
            $c->input($d->name, '', 200)->label('Name');
            $c->select(['Small', 'Large'], $d->size)->label('Size');
            if ($c->shortcut('s', Key::COMMAND)) { $d->inside++; }
            $c->row(0, 8);
              if ($c->clicked($c->button('OK'))) { $d->open = false; }
              $c->button('Cancel');
            $c->end();
        });
    };
}

function scopeState(): object
{
    return (object)['open' => false, 'name' => new TextState(''), 'size' => 0, 'behind' => 0, 'inside' => 0];
}

/** What the node with the focus is: its label, or its text. */
function scopeFocused(Headless $h): string
{
    $f = $h->focused();
    return $f === null ? '' : ($f->label !== null && $f->label !== '' ? $f->label : (string)($f->text ?? implode(' ', $h->texts($f))));
}

// The dialog takes the focus as it opens, on its first element; Tab goes round it, never behind it; Shift+Tab too.
$d = scopeState();
$h = new Headless(scopeDialog($d), 500, 400);
$h->frame();
$h->clickText('Open');
same('Name', scopeFocused($h), 'the dialog opens with the focus on its first element');
$order = [];
for ($k = 0; $k < 6; $k++) { $h->tab(); $order[] = scopeFocused($h); }
same(['Size', 'OK', 'Cancel', 'Name', 'Size', 'OK'], $order, 'Tab goes round the dialog');
$h->tab(true); $h->tab(true); $h->tab(true);
same('Cancel', scopeFocused($h), 'Shift+Tab from Name goes round to Cancel');

// Escape closes the select's popup, then the dialog.
$d = scopeState();
$h = new Headless(scopeDialog($d), 500, 400);
$h->frame();
$h->clickText('Open');
$h->clickLabel('Size');
check($h->menu !== null || $h->byText('Large') !== null, 'the select opened');
if ($h->menu !== null) { $h->closeMenu(); } else { $h->escape(); } // the system's menu closes itself on Escape
check($h->byText('Large') === null && $d->open, 'Escape closes the popup, not the dialog');
$h->escape();
check(!$d->open, 'a second Escape closes the dialog');

// The overlay gives the focus back to what opened it, by Escape and by OK.
$d = scopeState();
$h = new Headless(scopeDialog($d), 500, 400);
$h->frame();
$h->clickText('Open');
$h->escape();
check(!$d->open && scopeFocused($h) === 'Open', 'closed by Escape: the focus back on Open');
$h->clickText('Open');
$h->clickText('OK');
check(!$d->open && scopeFocused($h) === 'Open', 'closed by OK: the focus back on Open');

// A popover gives the focus back to its anchor, a toast viewport built after it.
$open = false;
$h = new Headless(function (Context $c) use (&$open): void {
    $anchor = $c->button('Open');
    if ($c->clicked($anchor)) { $open = true; }
    $c->popover($anchor, $open, function () use ($c): void { $c->button('Inside'); });
    $c->toastViewportBase(function (Node $viewport, array $toasts): void {}); // after the popover, as the window builds the theme's toasts after the view
}, 400, 300);
$h->frame();
$h->clickText('Open');
$h->tab();
same('Inside', scopeFocused($h), 'Tab moves the focus into the popover');
$h->escape();
check(!$open && scopeFocused($h) === 'Open', 'closing it gives the focus back to its anchor');

// Shortcuts behind a dialog wait; those in it are taken.
$d = scopeState();
$h = new Headless(scopeDialog($d), 500, 400);
$h->frame();
$h->shortcut('n', Key::COMMAND);
same(1, $d->behind, 'Cmd+N with no dialog');
$h->clickText('Open');
$h->shortcut('n', Key::COMMAND);
$h->shortcut('s', Key::COMMAND);
check($d->behind === 1 && $d->inside === 1, "with the dialog open: Cmd+N behind it {$d->behind}, Cmd+S in it {$d->inside}");

// A popover's Tab order follows its anchor: its elements after it, then what follows the anchor.
$open = false;
$h = new Headless(function (Context $c) use (&$open): void {
    $c->row(0, 8);
      $c->button('A');
      $menu = $c->button('Menu');
      $c->button('B');
      $c->popover($menu, $open, function () use ($c): void { $c->button('P1'); $c->button('P2'); });
    $c->end();
}, 500, 300);
$h->frame();
$h->clickText('Menu');
$open = true; $h->frame();
$order = [];
for ($k = 0; $k < 4; $k++) { $h->tab(); $order[] = scopeFocused($h); }
same(['P1', 'P2', 'B', 'A'], $order, 'Tab from the anchor');

// Behind a dialog is hidden from assistive technology; its focus is on the dialog's first element.
$d = scopeState();
$h = new Headless(scopeDialog($d), 500, 400);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
$h->clickText('Open');
$labels = array_map(fn($n) => $n->label, $h->rt->accessTree->nodes);
check(!in_array('Before', $labels, true) && in_array('OK', $labels, true) && in_array('Name', $labels, true), 'with the dialog open, assistive technology sees only it: ' . json_encode($labels));
$f = null;
foreach ($h->rt->accessTree->nodes as $n) { if ($n->id === $h->rt->accessTree->focus) { $f = $n; } }
same('Name', $f?->label, 'its focus on Name');

// Escape goes to the focused element first: a terminal in a dialog takes it, as vim needs it.
$open = true; $escapes = 0;
$h = new Headless(function (Context $c) use (&$open, &$escapes): void {
    $c->modal($open, function () use ($c, &$escapes): void {
        $c->box(100, 100, 0)->focusable()->label('Terminal')->autoFocus()->handleInput(function (InputEvent $e) use (&$escapes): bool {
            if ($e->kind === InputEvent::KEY_DOWN && $e->key === Key::ESCAPE) { $escapes++; return true; }
            return false;
        });
    });
}, 500, 400);
$h->frame();
$h->escape();
check($open && $escapes === 1, "Escape in the terminal: the dialog open, the terminal took $escapes");
