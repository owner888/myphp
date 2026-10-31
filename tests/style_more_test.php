<?php
// More of style_test.go (mygo's TestGrid, TestTextOptions, TestScrollBoth,
// TestScrollContentShrinks, TestEasingAndLoop, TestTextDecorations,
// TestImageFitsAndGray, TestMeasureAndDrawText): grids placing, spanning
// and aligning their cells, text that does not wrap or keeps a fixed line
// height, scrolling both ways, content that shrinks under its offset,
// easings and loops, decorations, images fitted and gray, text measured
// as drawn.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, DisplayList, Easing, Image, Node, Painter, Scrollbar, Span, Track};

/** The boxes of the nodes labeled $labels after a frame of $view. @return array<string, array{0: float, 1: float, 2: float, 3: float}> */
function smBoxes(\Closure $view, int $w, int $h, string ...$labels): array
{
    $t = new Headless($view, $w, $h);
    $t->frame(3);
    $out = [];
    foreach ($labels as $l) { $n = $t->byLabel($l) ?? $t->byText($l); $out[$l] = $n === null ? [NAN, NAN, NAN, NAN] : $t->rect($n); }
    return $out;
}

/** Whether boxes $a and $b are within a hundredth of a point. */
function smNear(array $a, array $b): bool
{
    foreach ([0, 1, 2, 3] as $i) { if (abs($a[$i] - $b[$i]) > 0.01) { return false; } }
    return true;
}

// A grid: equal columns, spans, a span to the end, a cell placed, the next after it.
$b = smBoxes(function (Context $c): void {
    $c->grid(3, 10, [], 5)->size(320, 0);
      $c->box(0, 20, 0)->label('a');
      $c->box(0, 40, 0)->label('b')->columnSpan(2);
      $c->box(0, 10, 0)->label('c')->columnSpan(-1);
      $c->box(0, 10, 0)->label('d')->columnStart(3)->rowStart(3);
      $c->box(0, 10, 0)->label('e');
    $c->end();
}, 400, 300, 'a', 'b', 'c', 'd', 'e');
$col = (310 - 20) / 3;
foreach (['a' => [5, 5, $col, 20], 'b' => [5 + $col + 10, 5, 2 * $col + 10, 40], 'c' => [5, 55, 310, 10], 'd' => [5 + 2 * $col + 20, 75, $col, 10], 'e' => [5, 75, $col, 10]] as $k => $want) {
    check(smNear($b[$k], $want), "grid cell $k at " . json_encode($b[$k]));
}
// Tracks: fixed, fit to content, fractions of what is left.
$b = smBoxes(function (Context $c): void {
    $c->grid([Track::fixed(100), Track::fit(), Track::fr(1), Track::fr(2)])->gapX(10)->size(500, 0);
      $c->box(0, 10, 0)->label('a'); $c->box(60, 10, 0)->label('b'); $c->box(0, 10, 0)->label('c'); $c->box(0, 10, 0)->label('d');
    $c->end();
}, 600, 100, 'a', 'b', 'c', 'd');
foreach (['a' => [0, 0, 100, 10], 'b' => [110, 0, 60, 10], 'c' => [180, 0, 310 / 3, 10], 'd' => [190 + 310 / 3, 0, 620 / 3, 10]] as $k => $want) {
    check(smNear($b[$k], $want), "track $k at " . json_encode($b[$k]));
}
// Alignment in cells: justifyItems, alignItems, a cell's own, automatic margins.
$b = smBoxes(function (Context $c): void {
    $c->grid(2, 0, [Track::fixed(50)])->size(200, 0)->justifyItems(Node::CENTER)->alignItems(Node::END);
      $c->box(20, 10, 0)->label('a');
      $c->box(20, 10, 0)->label('b')->justifySelf(Node::START)->alignSelf(Node::START);
      $c->box(20, 10, 0)->label('c')->margin(0, Node::AUTO);
    $c->end();
}, 300, 200, 'a', 'b', 'c');
foreach (['a' => [40, 40, 20, 10], 'b' => [100, 0, 20, 10], 'c' => [40, 50, 20, 10]] as $k => $want) {
    check(smNear($b[$k], $want), "aligned cell $k at " . json_encode($b[$k]));
}
// Tracks fitting their content; the room left stretches them.
$b = smBoxes(function (Context $c): void {
    $c->column();
      $c->row();
        $c->grid([Track::fit(), Track::fit()])->label('grid');
          $c->box(30, 10, 0)->label('a'); $c->box(50, 10, 0)->label('b');
        $c->end();
      $c->end();
      $c->grid([Track::fit(), Track::fit()])->size(200, 0);
        $c->box(0, 10, 0)->minWidth(30)->minHeight(0)->label('c'); $c->box(0, 10, 0)->minWidth(50)->minHeight(0)->label('d');
      $c->end();
    $c->end();
}, 300, 200, 'grid', 'a', 'b', 'c', 'd');
check($b['grid'][2] == 80.0 && $b['b'][0] == 30.0, 'a grid of tracks fitting their content: ' . json_encode([$b['grid'], $b['b']]));
check($b['c'][2] == 90.0 && $b['d'][2] == 110.0, 'the room left stretches them: ' . json_encode([$b['c'], $b['d']]));
// Rows grow with what wraps in them.
$b = smBoxes(function (Context $c): void {
    $c->column();
      $c->grid(2)->gapY(4)->label('grid');
        $c->text('one');
        $c->text(str_repeat('word ', 30));
        $c->box(0, 25, 0)->label('x');
      $c->end();
    $c->end();
}, 200, 400, 'grid', 'x', 'one');
check($b['x'][1] > $b['one'][1] + $b['one'][3] && $b['grid'][3] == $b['x'][1] + 25 - $b['grid'][1], 'rows take the height of the wrapped text: ' . json_encode([$b['grid'], $b['x']]));

// Text: a fixed line height; one that does not wrap is wider and lower than one that does.
$b = smBoxes(function (Context $c): void {
    $c->column()->size(60, 0)->alignItems(Node::START);
      $c->text("one\ntwo")->fixedLineHeight(30)->label('fixed');
      $c->text('a long line of words')->noWrap()->label('nowrap');
      $c->text('a long line of words')->label('wraps');
    $c->end();
}, 300, 300, 'fixed', 'nowrap', 'wraps');
same(60.0, $b['fixed'][3], 'two lines 30 high');
check($b['nowrap'][2] > 60 && $b['nowrap'][3] < $b['wraps'][3], 'text that does not wrap: ' . json_encode([$b['nowrap'], $b['wraps']]));

// Scrolling both ways: the wheel, and the horizontal thumb dragged to its end.
$content = null; $sc = null;
$h = new Headless(function (Context $c) use (&$content, &$sc): void {
    $sc = $c->scrollBoth()->size(100, 100);
      $content = $c->box(400, 300, 0);
    $c->end();
}, 200, 200);
$h->frame();
$h->input->mouse(50, 50); $h->input->push(['scroll', 50, 50, -30, -40]); $h->frame();
same([-30.0, -40.0], [$content->x, $content->y], 'the wheel both ways');
$st = $sc->scroll;
$t = Scrollbar::thumb($h->rect($sc), $st->extentX(), $h->rt->scrollbarWidth, true);
check($t !== null && Scrollbar::thumb($h->rect($sc), $st->extent(), $h->rt->scrollbarWidth) !== null, 'both scroll bars');
[$x, $y] = [$t[0] + $t[2] / 2, $t[1] + $t[3] / 2];
$h->hover($x, $y); $h->press($x, $y); $h->drag($x + 200, $y); $h->release($x + 200, $y);
same([-300.0, -40.0], [$content->x, $content->y], 'the horizontal thumb dragged to its end');

// Content that shrinks under the offset shows from the top, not from where the long content was.
$long = true; $top = null; $sw = null;
$h = new Headless(function (Context $c) use (&$long, &$top, &$sw): void {
    $c->column(0, 0, 1);
      $sw = $c->button('Switch');
      if ($c->clicked($sw)) { $long = !$long; }
      $c->scroll(0, 0, 1);
        $top = $c->box(0, 20, 0)->shrink(0);
        if ($long) { $c->box(0, 1000, 0)->shrink(0); }
      $c->end();
    $c->end();
}, 200, 200);
$h->frame();
$start = $top->y;
$h->input->mouse(100, 150); $h->input->push(['scroll', 100, 150, 0, -2000]); $h->frame();
check($top->y < 0, 'scrolled to the end');
$h->clickOn($sw);
same($start, $top->y, 'the short content from its top');

// Easings start at 0, end at 1, bend as named; bounce goes there and back; a loop moves within [0, 1).
foreach (['linear' => Easing::linear(...), 'in' => Easing::easeIn(...), 'out' => Easing::easeOut(...), 'in out' => Easing::easeInOut(...)] as $name => $e) {
    check($e(0.0) == 0.0 && $e(1.0) == 1.0 && $e(0.5) > 0 && $e(0.5) < 1, "$name from 0 to 1");
}
check(Easing::easeIn(0.5) < 0.5 && Easing::easeOut(0.5) > 0.5 && Easing::easeInOut(0.5) == 0.5, 'they bend as named');
$bounce = Easing::bounce(Easing::linear(...));
same([0.5, 1.0, 0.5], [$bounce(0.25), $bounce(0.5), $bounce(0.75)], 'bounce, there and back');
$now = 0; $values = [];
$h = poseTester(function (Context $c) use (&$values): void { $values[] = $c->loop($c->box(1, 1, 0), 'spin', 0.05, Easing::linear(...)); }, $now, 50, 50);
$now += 20_000_000; $h->frame();
check(array_filter($values, fn(float $v) => $v < 0 || $v >= 1) === [] && count(array_unique(array_map('strval', $values))) > 1, 'a loop moves within [0, 1)');

/** The quads of $list in $rgb, as [x, y, w, h]. @return list<array{0: float, 1: float, 2: float, 3: float}> */
function smQuads(DisplayList $list, int $rgb): array
{
    $out = [];
    for ($i = 0; $i < $list->n; $i++) {
        $b = $list->buf; $k = $i * DisplayList::FLOATS_PER_RECT;
        if ($b[$k + 7] > 0 && ((int)round($b[$k + 4] * 255) << 16 | (int)round($b[$k + 5] * 255) << 8 | (int)round($b[$k + 6] * 255)) === $rgb) { $out[] = [$b[$k], $b[$k + 1], $b[$k + 2], $b[$k + 3]]; }
    }
    return $out;
}

// Decorations in their color: an underline, a thick strikethrough, a background, spans of them.
foreach ([
    'underline' => [fn(Context $c) => $c->text('Hello')->underline()->decorationColor(0xff0000), fn(array $q) => count($q) >= 1 && $q[0][3] <= 2],
    'thick strikethrough' => [fn(Context $c) => $c->text('Hello')->strikethrough()->decorationColor(0xff0000)->decorationThickness(3), fn(array $q) => count($q) >= 1 && abs($q[0][3] - 3) < 0.01],
    'wavy underline' => [fn(Context $c) => $c->text('Hello world')->wavyUnderline()->decorationColor(0xff0000), fn(array $q) => count($q) >= 1],
    'background' => [fn(Context $c) => $c->text('Hello')->textBackground(0xff0000), fn(array $q) => count($q) >= 1 && $q[0][2] >= 30],
    'spans' => [fn(Context $c) => $c->richText([new Span('plain '), new Span('found', background: 0xff0000), new Span(' typo', wavyUnderline: true, decorationColor: 0xff0000)]), fn(array $q) => count($q) >= 2],
] as $name => [$view, $ok]) {
    $h = new Headless(function (Context $c) use ($view): void { $view($c); }, 300, 60);
    $h->frame();
    $q = smQuads($h->paint(), 0xff0000);
    check($ok($q), "$name painted in red: " . json_encode($q));
}

// An image scaled down and one contained: the small one at its own size, centered; grayscale asked.
$img = Image::fromPixels(10, 10, str_repeat("\xff\x00\x00\xff", 100));
$h = new Headless(function (Context $c) use ($img): void {
    $c->column();
      $c->image($img, 50, 50)->fit(Node::SCALE_DOWN)->label('down');
      $c->image($img, 50, 50)->fit(Node::CONTAIN)->grayscale()->label('gray');
    $c->end();
}, 50, 100);
$h->frame();
$quads = [];
$list = $h->paint();
for ($i = 0; $i < $list->n; $i++) { $b = $list->buf; $k = $i * DisplayList::FLOATS_PER_RECT; $quads[] = [$b[$k], $b[$k + 1], $b[$k + 2], $b[$k + 3]]; }
check(in_array([20.0, 20.0, 10.0, 10.0], $quads, true), 'scaled down: a small picture at its own size, centered');
check(in_array([0.0, 50.0, 50.0, 50.0], $quads, true) && $h->byLabel('gray')->gray, 'contained: enlarged to its box, gray');

// Text measured as the painter draws it.
$h = new Headless(function (Context $c): void {}, 200, 60);
$h->frame();
$c = new Context($h->metrics, $h->rt, 200, 60, $h->theme);
$spans = [new Span('Total', weight: 700, size: 20)];
$ffi = \FFI::cdef();
$list = new DisplayList($ffi->new('float[' . (256 * DisplayList::FLOATS_PER_RECT) . ']'), 256, $ffi->new('float[' . (8 * DisplayList::FLOATS_PER_TRANSFORM) . ']'), 8);
$p = new Painter($list, $h->metrics, $h->theme);
[$mw, $mh] = $c->measureText(0, ...$spans);
check($mw > 0 && $mh >= 20 && [$mw, $mh] == $p->measureText(0, ...$spans), "measured $mw by $mh, as drawn");
$p->richText(10, 10, 0, ...$spans);
check($list->n >= 5, 'drawn, a glyph a letter');

// An outline and a shadow of text (Node::textOutline, textShadow): laid out by the text system; painted under the glyphs, the shadow first, at the node's opacity.
$outlined = null;
$h = new Headless(function (\MyPHP\UI\Context $c) use (&$outlined) {
    $c->column(0, 0, 1)->opacity(0.5);
      $outlined = $c->text('ab', 20, 0xffffff)->textOutline(2, 0x000000)->textShadow(1, 1, 0x111111, 0.5);
    $c->end();
}, 300, 200);
$h->frame();
$l = $h->paint();
same([\MyPHP\UI\Node::RICH, true], [$outlined->kind, $outlined->textStyle()->needsLayout()], 'outlined text: laid out as rich text');
$bare = new Headless(function (\MyPHP\UI\Context $c) { $c->column(0, 0, 1)->opacity(0.5); $c->text('ab', 20, 0xffffff)->letterSpacing(0.001); $c->end(); }, 300, 200); // laid out as it is, neither outlined nor shadowed
$bare->frame();
same(4, $l->n - $bare->paint()->n, 'its two glyphs twice more, under them: the shadow, then the outline');
$err = null;
try { $outlined->textOutline(-1, 0); } catch (\InvalidArgumentException $e) { $err = $e->getMessage(); }
same('an outline of a width of 0 or more', $err, 'an outline of a negative width throws');
same(null, $outlined->textOutline(0, 0)->textStyle()->outline, 'one of no width: none');
