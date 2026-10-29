<?php
// Drags the system carries (mygo's data_drag_test.go and drop_test.go): a
// source handing its data to the host once the press moves, its bytes
// read only once dropped, in the formats taken, the effect negotiated; a
// value of this process's dropped as it is; Escape and the source gone
// canceling, told once; targets rejecting formats and effects not theirs,
// a disabled node and data failing; files dropped from other apps; the
// formats the view is told to take.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Transfer\{Data, DragOptions, DropOptions, Format, Item, Offer, Operation, Representation, Result};
use MyPHP\UI\{Context, DataDragEvent, Image};

/** Presses at (10, 10) and moves to (20, 20) (mygo's beginNativeDrag). */
function beginNativeDrag(Headless $t): void
{
    $t->hover(10, 10); $t->press(10, 10); $t->drag(20, 20);
}

// Negotiates and reads on drop: hovering calls no provider; the drop reads the formats taken alone; the source told once; no click.
$got = []; $ends = []; $over = false; $calls = 0; $clicks = 0;
$custom = 'application/vnd.mygo.test+json';
$data = new Data(new Item(Representation::bytes(Format::TEXT, 'fallback'), Representation::lazy($custom, function () use (&$calls) { $calls++; return '{"id":7}'; })));
$source = new Headless(function (Context $c) use ($data, &$ends, &$clicks) {
    $s = $c->dragData($c->box(100, 50, 0xff0000), $data, new DragOptions(Operation::COPY | Operation::MOVE, done: function (Result $r) use (&$ends) { $ends[] = $r; }));
    if ($c->clicked($s)) { $clicks++; }
}, 200, 200);
$target = new Headless(function (Context $c) use (&$got, &$over, $custom) {
    $zone = $c->box(100, 100, 0x00ff00);
    $o = new DropOptions([$custom], Operation::COPY | Operation::MOVE);
    $over = $c->dataDragOver($zone, $o);
    if ($c->dropData($zone, $o, $d)) { $got[] = $d; }
}, 200, 200);
$source->frame(); $target->frame();
beginNativeDrag($source);
check($calls === 0 && $source->dataDrag !== null && $source->dataDrag['options']->preview instanceof Image, 'the source reads no data, and has its default preview');
$d = new DataDragEvent(new Offer($data->formats(), Operation::COPY | Operation::MOVE, Operation::MOVE));
check($target->dataOver(10, 10, $d) && $over && $calls === 0 && $d->operation === Operation::MOVE, "hovering takes it with the modifier's move, reading nothing");
$d->data = $source->dataDrag['data'];
check($target->dataDrop(10, 10, $d), 'the drop is taken');
$source->finishDataDrag(new Result($d->operation));
$source->frame();
check(count($got) === 1 && $got[0]->operation === Operation::MOVE && $calls === 1 && !$over && $clicks === 0 && count($ends) === 1, 'dropped once, read once, told once, no click');
same([$custom], $got[0]->data->formats(), 'the fallback not asked for is not read');
$target->frame();
same(1, count($got), 'delivered once');

// A value of this process's: dropped as it is on a node taking its type.
$value = new \ArrayObject(['name' => 'same object']); $gotValue = null; $over = false;
$source = new Headless(function (Context $c) use ($value) { $c->dragData($c->drag($c->box(80, 40, 0xff0000), $value), Data::text('same object')); }, 200, 200);
$target = new Headless(function (Context $c) use (&$gotValue, &$over) {
    $e = $c->box(100, 100, 0x00ff00);
    $over = $c->dragOver($e, \ArrayObject::class);
    if ($c->drop($e, \ArrayObject::class, $v)) { $gotValue = $v; }
}, 200, 200);
$source->frame(); $target->frame();
beginNativeDrag($source);
same($value, $source->dataDrag['local'], 'the host drags the value');
$d = new DataDragEvent(new Offer([Format::TEXT], Operation::COPY));
$d->local = $source->dataDrag['local'];
check($target->dataOver(5, 5, $d) && $over, 'over a node taking its type, it sees it');
check($target->dataDrop(5, 5, $d) && $gotValue === $value, 'dropped there, the same object');
$source->finishDataDrag(new Result($d->operation));

// Escape and the source gone cancel it, told once; a release the system let through is no click.
foreach (['escape' => false, 'source removal' => true] as $how => $remove) {
    $show = true; $ends = [];
    $t = new Headless(function (Context $c) use (&$show, &$ends) {
        if ($show) { $c->dragData($c->box(80, 40, 0xff0000), Data::text('a'), new DragOptions(done: function (Result $r) use (&$ends) { $ends[] = $r; })); }
    }, 200, 200);
    $t->frame();
    beginNativeDrag($t);
    if ($remove) { $show = false; $t->frame(); } else { $t->escape(); }
    $t->release(80, 80);
    $t->finishDataDrag(new Result(Operation::COPY));
    check(count($ends) === 1 && $ends[0]->canceled && $t->rt->valueDrag === null && $t->dataDrag === null, "$how cancels it, told once");
    check($ends[0]->escaped && $ends[0]->point == new \MyPHP\Point(10, 20), "$how: given up, where it ended");
}

// A source tells a drag let go over no target from one given up, and learns where it ended (mygo's TestDataDragEndReasonAndPoint).
$P = fn(int $x, int $y) => new \MyPHP\Point($x, $y);
foreach ([
    'no target' => [new Result(canceled: true, point: $P(-40, 900)), new Result(canceled: true, point: $P(-40, 900))],
    'escape' => [new Result(canceled: true, escaped: true, point: $P(5, 6)), new Result(canceled: true, escaped: true, point: $P(5, 6))],
    'no effect' => [new Result(point: $P(1, 2)), new Result(canceled: true, point: $P(1, 2))],
    'drop' => [new Result(Operation::COPY, escaped: true, point: $P(7, 8)), new Result(Operation::COPY, point: $P(7, 8))],
] as $how => [$native, $want]) {
    $got = [];
    $src = (new \ReflectionClass(\MyPHP\DataDrag::class))->newInstanceWithoutConstructor();
    (function () use (&$got) { $this->operations = Operation::COPY; $this->done = function (Result $r) use (&$got) { $got[] = $r; }; })->call($src);
    $src->finish($native);
    check(count($got) === 1 && $got[0] == $want, "$how: " . json_encode([$got[0]->operation ?? null, $got[0]->canceled ?? null, $got[0]->escaped ?? null, $got[0]->point ?? null]));
}

// The press: a click moving two points; past a few, the drag the system's, nothing painted following the pointer, its release no click.
$clicks = 0; $dragging = false; $nodes = [];
$t = new Headless(function (Context $c) use (&$clicks, &$dragging, &$nodes) {
    $c->column(20, 20);
      $nodes['src'] = $src = $c->dragData($c->box(100, 40, 0xff0000), Data::text('hello'));
      if ($c->clicked($src)) { $clicks++; }
      $dragging = $c->dragging($src);
    $c->end();
}, 400, 400);
$t->frame();
[$sx, $sy] = $t->rect($nodes['src']);
$t->hover($sx + 10, $sy + 10); $t->press($sx + 10, $sy + 10); $t->drag($sx + 12, $sy + 11); $t->release($sx + 12, $sy + 11);
check($clicks === 1 && $t->dataDrag === null, 'a press moving 2 points is a click');
$t->hover($sx + 10, $sy + 10); $t->press($sx + 10, $sy + 10); $t->drag($sx + 30, $sy + 30);
check($t->dataDrag !== null && $dragging && $t->rt->valueDrag['native'], 'past a few points, the system drags it');
same(Operation::NONE, $t->dataDrag['options']->operations, 'its effects as given: copy, the default, the core\'s');
same([10, 10], $t->dataDrag['options']->hotspot, 'its image held where the press took it');
check($t->dataDrag['options']->preview->w === 100 && $t->dataDrag['options']->preview->h === 40, 'its image the node as it shows');
same(1, $t->paint()->transforms, 'no copy follows the pointer in the window');
$t->release($sx + 20, $sy + 20);
check($clicks === 1 && $t->dataDrag !== null, 'a release the system let through is no click, and does not end it');
$t->finishDataDrag(new Result(Operation::COPY));
$t->frame();
check(!$dragging && $t->rt->valueDrag === null, 'its end ends the dragging');
$t->clickOn($nodes['src']);
same(2, $clicks, 'a click after it is one');

// Rejects formats and effects not its own, a disabled node, and data failing to be read.
$disabled = false; $drops = 0;
$t = new Headless(function (Context $c) use (&$disabled, &$drops) {
    $e = $c->box(100, 100, 0x00ff00);
    $e->disabled = $disabled;
    if ($c->dropData($e, new DropOptions([Format::TEXT], Operation::COPY))) { $drops++; }
}, 200, 200);
$t->frame();
foreach ([new Offer([Format::PNG], Operation::COPY), new Offer([Format::TEXT], Operation::MOVE)] as $offer) {
    $d = new DataDragEvent($offer);
    check(!$t->dataOver(10, 10, $d) && $d->operation === Operation::NONE, 'an incompatible transfer is not taken');
}
$disabled = true; $t->frame();
check(!$t->dataOver(10, 10, new DataDragEvent(new Offer([Format::TEXT], Operation::COPY))), 'a disabled node takes none');
$disabled = false; $t->frame();
$d = new DataDragEvent(new Offer([Format::TEXT], Operation::COPY));
$d->data = new Data(new Item(Representation::lazy(Format::TEXT, fn() => throw new \RuntimeException('failed'))));
check(!$t->dataDrop(10, 10, $d) && $d->operation === Operation::NONE && $drops === 0, 'a failed provider drops nothing');
same([Format::DRAG_SESSION, Format::FILE_LIST, Format::TEXT, Format::URI_LIST], $t->dropFormats, 'the view takes files, URLs, the drags of this process and the targets\' formats');
$told = 0;
$t->rt->setDropFormats = function (array $f) use (&$told) { $told++; };
$t->frame();
same(0, $told, 'told when they change alone');

// The innermost target taking it.
$where = [];
$t = new Headless(function (Context $c) use (&$where) {
    $outer = $c->column()->size(200, 200);
      $inner = $c->box(100, 50, 0x00ff00);
      if ($c->dropData($inner, new DropOptions([Format::PNG]))) { $where[] = 'inner'; }
    $c->end();
    if ($c->dropData($outer, new DropOptions([Format::TEXT]))) { $where[] = 'outer'; }
}, 300, 300);
$t->frame();
$text = fn() => (function () { $d = new DataDragEvent(new Offer([Format::TEXT], Operation::COPY)); $d->data = Data::text('a'); return $d; })();
check($t->dataDrop(10, 10, $text()) && $where === ['outer'], 'a node taking images takes no text: the one around it does');

// Files from other apps (mygo's TestFileDrops).
$dropped = []; $over = false;
$t = new Headless(function (Context $c) use (&$dropped, &$over) {
    $c->column();
      $zone = $c->box(200, 100, 0x00ff00);
      if (($files = $c->droppedFiles($zone)) !== null) { array_push($dropped, ...$files); }
      $over = $c->fileDragOver($zone);
      $c->box(200, 100, 0x0000ff);
    $c->end();
}, 300, 300);
$t->frame();
check(!$t->rt->fileDrag(50, 150) && ($t->frame() || true) && !$over, 'files are not taken outside of the zone');
check($t->rt->fileDrag(50, 50) && ($t->frame() || true) && $over, 'files are taken over the zone');
$t->rt->fileDrag(-1, -1); $t->frame();
check(!$over, 'the zone shows no files once they left');
check(!$t->rt->fileDrop(50, 150, ['/tmp/a']) && ($t->frame() || true) && $dropped === [], 'files dropped outside of the zone do not reach it');
check($t->rt->fileDrop(10, 10, ['/tmp/a', '/tmp/b']), 'the zone takes the files dropped');
$t->frame();
check($dropped === ['/tmp/a', '/tmp/b'] && !$over, 'dropped on it');
$t->frame();
same(2, count($dropped), 'once');
