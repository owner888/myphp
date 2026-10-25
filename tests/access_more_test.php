<?php
// More of mygo's access_test.go and router_test.go
// (TestAccessibilityScrollsToARowNoLongerBuilt, TestAnnounce): assistive
// technology asking to see a row the frame before built but the next does
// not; a toast and an announcement read out with the tree, once.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, ListState, Node, Role};

// A frame that scrolled to a row built more rows around it than the next builds: assistive technology asks to see the last of them.
$s = new ListState();
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1);
      $c->list($s, 1000, fn(int $i) => $c->text("Row $i")->height(30))->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
$s->scrollTo(20, false, Node::CENTER); $h->frame(1);
$last = null;
foreach ($h->rt->accessTree->nodes as $a) { if ($a->role === Role::LIST_ITEM && ($last === null || $a->posInSet > $last->posInSet)) { $last = $a; } }
check($last !== null, 'the rows read');
$h->input->push(['access', $last->id, AccessNode::SCROLL_INTO_VIEW]); $h->frame();
$row = $h->byText('Row ' . ($last->posInSet - 1));
$r = $row === null ? null : $h->rect($row);
check($r !== null && $r[1] >= 0 && $r[1] + $r[3] <= 400.01, 'row ' . ($last->posInSet - 1) . ' in view once asked: ' . json_encode($r));

// A toast and an announcement, read out with the tree, once.
$h = new Headless(function (Context $c): void {
    if ($c->clicked($c->button('Save'))) { $c->toast('Saved'); $c->announce('3 files saved'); }
}, 300, 200);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
$h->clickText('Save');
same(['Saved', '3 files saved'], $h->told, 'read out, in order');
$h->frame();
same(['Saved', '3 files saved'], $h->told, 'once');
