<?php
// A collapsible and an accordion with their look (mygo's collapsible_test.go
// TestCollapsible, TestAccordion, and TestCollapsibleAnimates' less
// motion; its frames on the way are base_test's): a disclosure to
// assistive technology, opening and closing by the pointer and the keys,
// Tab into what is open, the arrows between an accordion's headers.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Role};

/** The node of the access tree with $role named $label. */
function colNode(Headless $h, int $role, string $label): ?AccessNode
{
    foreach ($h->rt->accessTree?->nodes ?? [] as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    return null;
}

/** Whether the node with the focus shows $text. */
function colFocused(Headless $h, string $text): bool
{
    $f = $h->focused();
    return $f !== null && in_array($text, $h->texts($f), true);
}

// A collapsible: closed, its content hidden, a disclosure that presses; a click opens it; Tab into it; Space and Enter.
$open = false; $changes = 0;
$h = new Headless(function (Context $c) use (&$open, &$changes): void {
    $c->column(0, 8);
      $n = $c->collapsible('Advanced', $open, function () use ($c): void { $c->text('Inside'); $c->button('Inner'); });
      if ($c->changed($n)) { $changes++; }
      $c->button('After');
    $c->end();
}, 400, 400);
$h->rt->reduceMotion = true;
$h->frame();
$h->input->push(['accessOn']); $h->frame();
check($h->byText('Inside') === null, 'closed, its content is hidden');
$d = colNode($h, Role::DISCLOSURE, 'Advanced');
check($d !== null && ($d->states & AccessNode::EXPANDED) === 0 && ($d->actions & AccessNode::ACTION_PRESS) !== 0, 'a disclosure, closed, which presses');
$h->clickText('Advanced');
check($open && $changes === 1 && $h->byText('Inside') !== null, "a click opens it ($changes changes)");
check((colNode($h, Role::DISCLOSURE, 'Advanced')?->states & AccessNode::EXPANDED) !== 0, 'open, it says so');
$h->tab();
check(colFocused($h, 'Inner'), 'Tab goes into its content');
$h->tab(true); $h->space();
check(!$open && $h->byText('Inside') === null, 'Space closes it');
$h->enter();
check($open && $changes === 3, "Enter opens it ($changes changes)");

// With less motion, it opens at once.
$open = false; $progress = -1.0;
$h = new Headless(function (Context $c) use (&$open, &$progress): void {
    $p = $c->collapsibleBase($open);
    $p->trigger; $c->text('More'); $c->end();
    $progress = $p->progress();
    $p->panel(function () use ($c): void { for ($i = 0; $i < 5; $i++) { $c->text('Line'); } });
}, 400, 400);
$h->rt->reduceMotion = true;
$h->frame();
$h->clickText('More');
same(1.0, $progress, 'with less motion, at once');

// An accordion: a click closes the section open, keeping the focus; the arrows, Home and End between the headers; Enter and Space open; Tab through what is open.
$general = true; $privacy = false; $advanced = false;
$h = new Headless(function (Context $c) use (&$general, &$privacy, &$advanced): void {
    $c->accordion(function () use ($c, &$general, &$privacy, &$advanced): void {
        $c->accordionItem('General', $general, function () use ($c): void { $c->button('Save'); });
        $c->accordionItem('Privacy', $privacy, function () use ($c): void { $c->text('Tracking'); });
        $c->accordionItem('Advanced', $advanced, function () use ($c): void { $c->text('Logs'); });
    });
}, 400, 400);
$h->rt->reduceMotion = true;
$h->frame();
check($h->byText('Save') !== null && $h->byText('Tracking') === null, 'what is open shows');
$h->clickText('General');
check(!$general && $h->byText('Save') === null && colFocused($h, 'General'), 'a click closes it, focused');
foreach ([['down', 'Privacy'], ['down', 'Advanced'], ['down', 'Advanced'], ['home', 'General'], ['up', 'General'], ['end', 'Advanced'], ['up', 'Privacy']] as [$key, $want]) {
    $h->$key();
    check(colFocused($h, $want), "$key: $want");
}
$h->enter();
check($privacy && $h->byText('Tracking') !== null, 'Enter opens Privacy');
$h->tab(true); $h->space();
check($general && $h->byText('Save') !== null, 'Space opens General');
$h->tab();
check(colFocused($h, 'Save'), 'Tab goes into its content');
$h->input->push(['accessOn']); $h->frame();
foreach (['General' => true, 'Privacy' => true, 'Advanced' => false] as $name => $isOpen) {
    same($isOpen, (colNode($h, Role::DISCLOSURE, $name)?->states & AccessNode::EXPANDED) !== 0, "$name expanded to assistive technology");
}
