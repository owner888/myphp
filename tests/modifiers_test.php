<?php
// Modifiers that were accepted and did nothing, as SwiftUI's now: zIndex, scrollIndicators,
// scrollDismissesKeyboard, accessibilityHidden, listRowBackground, listRowInsets, listRowSeparator,
// scrollContentBackground, presentationCornerRadius, presentationDragIndicator; and no declarative
// modifier that only returns $this, which an app calls to no effect and no error.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Content, Context, Node};

require_once __DIR__ . '/../src/UI/Declarative/functions.php';

/** All nodes under $n, depth first. @return list<Node> */
function modifiersNodes(Node $n): array
{
    $out = [$n];
    foreach ($n->kids as $k) { array_push($out, ...modifiersNodes($k)); }
    return $out;
}

// No modifier of the declarative views is an empty body: each one does what it says, or is not there.
foreach (glob(__DIR__ . '/../src/UI/Declarative/*.php') as $file) {
    preg_match_all('/public function (\w+)\([^)]*\)\s*:\s*static\s*\{\s*return \$this;\s*\}/', (string)file_get_contents($file), $m);
    same([], $m[1], basename($file) . ': modifiers that do nothing');
}

// zIndex: over the views beside it as they paint, in their order where it is equal; the layout keeps their order.
$h = new Headless(fn(Context $c) => Content::render(fn() => ZStack()(
    Text('A')->frame(width: 100, height: 100)->zIndex(1),
    Text('B')->frame(width: 100, height: 100),
    Text('C')->frame(width: 100, height: 100),
), $c), 300, 300);
$root = $h->frame(2);
$zstack = array_values(array_filter(modifiersNodes($root), fn(Node $n) => $n->zOrdered))[0] ?? null;
same(['B', 'C', 'A'], array_map(fn(Node $k) => modifiersNodes($k)[count(modifiersNodes($k)) - 1]->text, $zstack?->paintOrder() ?? []), 'A of zIndex 1 paints over B and C, which keep their order');
same(['A', 'B', 'C'], array_map(fn(Node $k) => modifiersNodes($k)[count(modifiersNodes($k)) - 1]->text, $zstack?->kids ?? []), 'the layout\'s order unchanged');

// scrollIndicators: no bars on the axis named; scrollContentBackground: a Form's own background gone.
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack()(
    ScrollView()(Text('Down'))->scrollIndicators(\ScrollIndicatorVisibility::hidden)->accessibilityLabel('down'),
    ScrollView(\Axis::horizontal)(Text('Across'))->scrollIndicators(\ScrollIndicatorVisibility::hidden)->accessibilityLabel('across'),
    ScrollView(\Axis::horizontal)(Text('Across2'))->scrollIndicators(\ScrollIndicatorVisibility::never, axes: \Axis::horizontal)->accessibilityLabel('across2'),
    ListView([1, 2])(fn(int $i) => Text("Row $i"))->scrollIndicators(\ScrollIndicatorVisibility::hidden)->accessibilityLabel('rows'),
), $c), 300, 600);
$h->frame(2);
same([true, false, true, true], [$h->byLabel('down')?->hideScrollbars, $h->byLabel('across')?->hideScrollbars, $h->byLabel('across2')?->hideScrollbars, $h->byLabel('rows')?->hideScrollbars],
    'bars hidden on the vertical axis by default, on the horizontal one when named');
$form = fn(?\Visibility $v) => new Headless(fn(Context $c) => Content::render(fn() => ($v === null ? Form()(Section()(Text('X'))) : Form()(Section()(Text('X')))->scrollContentBackground($v))->accessibilityLabel('form'), $c), 390, 400);
$shown = $form(null); $shown->rt->touch = true; $shown->frame(2);
$hidden = $form(\Visibility::hidden); $hidden->rt->touch = true; $hidden->frame(2);
check($shown->byLabel('form')?->color !== null, 'a Form paints its grouped background');
same(null, $hidden->byLabel('form')?->color, 'scrollContentBackground(.hidden): none');

// scrollDismissesKeyboard: a finger scrolling the scroll view takes the focus from the field (the keyboard goes),
// at once, or as it reaches the keyboard's top; by default it does not.
$scrolled = function (?\ScrollDismissesKeyboardMode $mode, float $fingerY): bool {
    $v = new class { public string $text = ''; };
    $body = fn() => VStack()(
        TextField('Name', text: $v->text),
        ScrollView()(...array_map(fn(int $i) => Text("Line $i")->frame(height: 40), range(1, 40))),
    );
    $h = new Headless(function (Context $c) use ($body, $mode): void {
        $c->keyboard = 300.0;
        Content::render($mode === null ? $body : fn() => $body()->scrollDismissesKeyboard($mode), $c);
    }, 390, 800);
    $h->rt->touch = true;
    $h->frame(2);
    $h->clickLabel('Name');
    check($h->focused()?->label === 'Name', 'the field has the keyboard');
    $h->input->push(['scroll', 200.0, 300.0, 0.0, -30.0, true]); // a finger's, precise, over the scroll view
    if ($fingerY !== 300.0) { $h->input->push(['scroll', 200.0, $fingerY, 0.0, -30.0, true]); } // on, the finger down there
    $h->input->push(['scrollEnd']);
    $h->frame(2);
    return $h->focused()?->label !== 'Name';
};
same([false, true, false, true, false], [
    $scrolled(null, 300.0), $scrolled(\ScrollDismissesKeyboardMode::immediately, 300.0),
    $scrolled(\ScrollDismissesKeyboardMode::interactively, 300.0), $scrolled(\ScrollDismissesKeyboardMode::interactively, 520.0),
    $scrolled(\ScrollDismissesKeyboardMode::never, 300.0),
], 'scrolled: by default it keeps the keyboard; immediately it closes it; interactively only as the finger scrolling it reaches the keyboard (500 down), past the scroll view');

// accessibilityHidden: shown, not read, nor taken into a container's label.
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack()(
    Text('Read'),
    Text('Decoration')->accessibilityHidden(),
    HStack()(Text('Kept'), Text('Dropped')->accessibilityHidden())->accessibilityLabel('')->onTapGesture(fn() => null),
    VStack()(Text('Inner'))->accessibilityHidden(),
), $c), 300, 300);
$h->frame(2);
$read = array_map(fn($n) => $n->label, $h->rt->automationTree()[0]->nodes);
check(in_array('Read', $read, true) && !in_array('Decoration', $read, true) && !in_array('Inner', $read, true), 'hidden texts are not read: ' . json_encode($read));
check($h->byText('Decoration') !== null && $h->byText('Inner') !== null, 'but they show');

// listRowBackground, listRowInsets, listRowSeparator: of a row of a Form's section and of a ListView's.
$h = new Headless(fn(Context $c) => Content::render(fn() => Form()(Section()(
    Text('Plain')->accessibilityLabel('plain'),
    Text('Red')->listRowBackground(Color(\Color::rgb(0xff0000)))->accessibilityLabel('red'),
    Text('Tight')->listRowInsets(new \EdgeInsets(top: 2, leading: 40, bottom: 2, trailing: 0))->accessibilityLabel('tight'),
    Text('Behind')->listRowBackground(Text('Under'))->listRowSeparator(\Visibility::hidden)->accessibilityLabel('behind'),
    Text('Last'),
)), $c), 390, 600);
$h->rt->touch = true; $h->theme = \MyPHP\UI\Theme::phone(false);
$h->frame(2);
$cell = fn(string $label) => array_values(array_filter(modifiersNodes($h->frame(1)), fn(Node $n) => in_array($h->byLabel($label), $n->kids, true)))[0] ?? null;
same(0xff0000, $cell('red')?->color, 'a color row background is the cell\'s');
same([[2.0, 0.0, 2.0, 40.0], 40.0], [$cell('tight')?->sides(), $h->rect($h->byLabel('tight'))[0] - $h->rect($cell('tight'))[0]], 'the row\'s insets in place of the cell\'s padding (11 by 16), its height the cell\'s least');
$behind = $cell('behind');
same(['Under', true], [$behind?->kids[0]?->text, $behind?->kids[0]?->absolute], 'a view row background under the content, as large as the cell');
same(Node::SEPARATOR_ABOVE | Node::SEPARATOR_BELOW, $behind?->hideSeparators, 'its separators hidden');
$hairlines = function (Headless $h): int {
    $list = $h->paint(); $n = 0;
    for ($i = 0; $i < $list->n; $i++) { if ($list->buf[$i * 16 + 3] > 0 && $list->buf[$i * 16 + 3] <= 1.0 && $list->buf[$i * 16 + 2] > 200) { $n++; } }
    return $n;
};
same(2, $hairlines($h), 'five rows, four lines between them, the two by the row that hides its own gone');
$h = new Headless(fn(Context $c) => Content::render(fn() => ListView(range(1, 4))(fn(int $i) => $i === 3 ? Text("Row $i")->listRowSeparator(\Visibility::hidden, edges: \Edge::top) : Text("Row $i")), $c), 390, 600);
$h->rt->touch = true; $h->theme = \MyPHP\UI\Theme::phone(false);
$h->frame(2);
same(3, $hairlines($h), 'a list\'s rows: a line under each, but the one above the row that hides it');

// presentationCornerRadius, presentationDragIndicator: of the view a sheet presents.
$sheet = function (?float $radius, ?\Visibility $grabber): array {
    $s = new class { public bool $open = true; };
    $h = new Headless(fn(Context $c) => Content::render(function () use ($s, $radius, $grabber) {
        $content = Text('In the sheet')->frame(height: 300); // tall enough that the corners are not fitted to it
        if ($radius !== null) { $content = $content->presentationCornerRadius($radius); }
        if ($grabber !== null) { $content = $content->presentationDragIndicator($grabber); }
        return Text('Page')->sheet(isPresented: $s->open, content: fn() => $content);
    }, $c), 390, 800);
    $h->rt->touch = true;
    $h->frame(10);
    $grabbers = count(array_filter(modifiersNodes($h->frame(1)), fn(Node $n) => $n->fw == 36.0 && $n->fh == 5.0));
    $list = $h->paint(); $radii = [];
    for ($i = 0; $i < $list->n - 1; $i++) {
        if (fmod($list->buf[$i * 16 + 9], \MyPHP\UI\DisplayList::KIND_STRIDE) == 14.0 && $list->buf[$i * 16 + 2] >= 370) { $radii[] = round($list->buf[($i + 1) * 16], 1); }
    }
    return [$grabbers, $radii];
};
[$g0, $r0] = $sheet(null, null);
[$g1, $r1] = $sheet(12.0, \Visibility::hidden);
same([1, 0], [$g0, $g1], 'a phone\'s sheet has a grabber; presentationDragIndicator(.hidden) none');
check(in_array(32.0, $r0, true) && in_array(12.0, $r1, true) && !in_array(32.0, $r1, true), 'its corners the system\'s 32, or presentationCornerRadius\'s: ' . json_encode([$r0, $r1]));
