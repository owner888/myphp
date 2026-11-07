<?php
// The declarative views, as SwiftUI's (docs/declarative.md): stacks, text, buttons running their actions, a toggle
// and a field bound to the state, Each keeping rows by id, modifiers in order (a padding after a background pads
// outside it), the environment flowing down, onAppear and onDisappear, keyboardShortcut.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Content, Context, Key, Node};
use View, Font, Color, ButtonStyle, KeyEquivalent, Edge, PickerStyle, DatePickerComponents, Axis, Alignment, ToggleStyle, HorizontalAlignment, GridItem, GeometryProxy, ScrollViewProxy, UnitPoint, Binding, ListStyle;

require_once __DIR__ . '/../src/UI/Declarative/functions.php';

$s = new class {
    public int $n = 0;
    public bool $wifi = false;
    public string $name = 'Ada';
    public array $todos = [['id' => 7, 'title' => 'Milk'], ['id' => 9, 'title' => 'Bread']];
    public array $seen = [];
    public bool $showExtra = true;
};
$body = function () use ($s) {
    return VStack(spacing: 12)(
        Text("Count {$s->n}")->font(Font::title),
        HStack()(
            Button('Add', action: function () use ($s) { $s->n++; })->keyboardShortcut(KeyEquivalent::upArrow, modifiers: []),
            Button('Reset', action: function () use ($s) { $s->n = 0; })->disabled($s->n === 0),
        ),
        Toggle('Wi-Fi', isOn: $s->wifi),
        TextField('Name', text: $s->name),
        Each($s->todos, id: 'id')(fn(array $t) => Text($t['title'])),
        $s->showExtra ? Text('Extra')->onAppear(function () use ($s) { $s->seen[] = 'appear'; })->onDisappear(function () use ($s) { $s->seen[] = 'disappear'; }) : null,
        Text('Boxed')->background(Color::blue)->padding(10),
        Group()(Text('In a group'))->foregroundStyle(Color::red),
    );
};
$h = new Headless(fn(Context $c) => Content::render($body, $c), 500, 600);
$h->frame();
check($h->byText('Count 0') !== null && $h->byText('Milk') !== null && $h->byText('Bread') !== null, 'the views: ' . json_encode($h->texts()));
$h->clickText('Add'); $h->frame();
same(1, $s->n, "a Button's action runs on a click");
$h->up(); $h->frame();
same(2, $s->n, 'and on its keyboardShortcut');
check($h->byText('Count 2') !== null, 'the body built again from the state');
$h->clickLabel('Wi-Fi'); $h->frame();
check($s->wifi, 'a Toggle sets the property it is bound to');
$h->clickLabel('Name'); $h->shortcut('a', \MyPHP\UI\Key::COMMAND); $h->type('Grace'); $h->frame();
same('Grace', $s->name, 'a TextField writes the string it is bound to');
$s->name = 'Linus'; $h->frame(); $h->frame();
check($h->byLabel('Name')?->edit?->text === 'Linus', 'and shows what the app sets it to');
same(['appear'], $s->seen, 'onAppear the first frame');
$h->frame();
same(['appear'], $s->seen, 'once while it shows');
$s->showExtra = false; $h->frame(); $h->frame();
same(['appear', 'disappear'], $s->seen, 'onDisappear once it is gone');
$boxed = $h->byText('Boxed');
$outer = null;
$walk = function (Node $n) use (&$walk, $boxed, &$outer) { foreach ($n->kids as $k) { if ($k === $boxed) { $outer = $n; } $walk($k); } };
$walk($h->root);
check($boxed->color !== null && $outer !== null && $outer->color === null && $outer->sides()[0] === 10.0, 'a padding after a background pads outside it: a box around the text, the text colored');
check(in_array($h->byText('In a group')->textStyle()->color, [0xff3b30, 0xff453a], true), // UIKit's red, light or dark
    'foregroundStyle flows down to the text in a group');
$order = array_values(array_filter($h->texts(), fn($t) => in_array($t, ['Milk', 'Bread'], true)));
$s->todos = array_reverse($s->todos); $h->frame();
check(array_values(array_filter($h->texts(), fn($t) => in_array($t, ['Milk', 'Bread'], true))) === array_reverse($order), 'Each follows the items as they move');

// Flexible views, as SwiftUI's: a NavigationStack beside a sidebar makes the HStack take the window; a fixed frame
// width wins over it. A NavigationLink in a list (plain and in Each) pushes its value; navigationDestination gets the
// path parameters by name and type; Back pops.
$nav = new class {
    public \NavigationPath $path;
    public array $notes = [1 => 'Groceries', 2 => 'Ideas'];
    public function __construct() { $this->path = new \NavigationPath(); }
};
$h = new Headless(fn(Context $c) => Content::render(fn() => HStack(spacing: 0)(
    Text('Side')->frame(width: 120),
    NavigationStack(path: $nav->path)(
        ListView()(Section(header: 'Notes')(Each($nav->notes)(fn(string $title, int $id) => NavigationLink($title, value: "/notes/$id"))))->navigationTitle('Notes'),
    )->navigationDestination(for: '/notes/{id}', destination: fn(int $id) => Text("Note $id is an int: " . get_debug_type($id))),
), $c), 700, 400);
$h->frame(2);
check($h->root->kids[0]->w === 700.0 && $h->root->kids[0]->h === 400.0, 'an HStack around a NavigationStack takes the window: ' . $h->root->kids[0]->w . 'x' . $h->root->kids[0]->h);
$side = null; $up = function (Node $n) use (&$up, &$side, $h): void { foreach ($n->kids as $k) { if ($k === $h->byText('Side')) { $side = $n; } $up($k); } }; $up($h->root); $up = null;
check($side->w === 120.0 && $h->byText('Side')->w < 120.0, "a frame's width is a box around the text, as SwiftUI's, the text its own width");
$h->clickText('Ideas'); $h->frame(3);
same('/notes/2', $nav->path->path(), 'a NavigationLink of Each in a section pushes its value');
check($h->byText('Note 2 is an int: int') !== null, 'navigationDestination gets {id} as the int it asks for');
$h->clickLabel('Back'); $h->frame(3);
check($nav->path->isEmpty() && $h->byText('Groceries') !== null, 'Back pops to the list');

// Controls, as SwiftUI's, each writing its binding: Picker (tags of any type, a pop-up menu, a segmented control,
// radio buttons), Slider (stepped, int bound), Stepper, Toggle in a Menu, onChange (not the first frame, old and new),
// onHover, DisclosureGroup keeping its own state, ProgressView, a Form row leading a control with its title.
$ctl = new class {
    public string $flavor = 'vanilla';
    public int $size = 1;
    public string $mode = 'a';
    public int $volume = 50;
    public int $qty = 2;
    public bool $reverse = false;
    public array $changes = [];
    public array $hovers = [];
    public string $chosen = '';
};
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack(spacing: 8)(
    Picker('Flavor', selection: $ctl->flavor)(Text('Vanilla')->tag('vanilla'), Text('Chocolate')->tag('chocolate')),
    Picker('Size', selection: $ctl->size)(Each(['S', 'M', 'L'])(fn(string $s, int $i) => Text($s)->tag($i)))->pickerStyle(PickerStyle::segmented),
    Picker('Mode', selection: $ctl->mode)(Text('Mode A')->tag('a'), Text('Mode B')->tag('b'))->pickerStyle(PickerStyle::radioGroup),
    Slider(value: $ctl->volume, in: [0, 100], step: 10),
    Stepper('Quantity', value: $ctl->qty, in: [1, 3]),
    Menu('Actions')(Button('Rename', action: fn() => $ctl->chosen = 'rename'), Divider(), Toggle('Reverse', isOn: $ctl->reverse)),
    Text("Size {$ctl->size}")->onChange(of: $ctl->size, action: function ($old, $new) use ($ctl) { $ctl->changes[] = "{$old}→{$new}"; })
        ->onHover(function (bool $over) use ($ctl) { $ctl->hovers[] = $over; }),
    DisclosureGroup('Advanced')(Text('Hidden inside')),
    ProgressView('Copying', value: 3, total: 4),
    Form()(Section()(Stepper('In a form', value: $ctl->qty, in: [1, 3]))),
), $c), 700, 900);
$h->frame(2);
$h->clickText('L'); $h->frame(2);
same(2, $ctl->size, 'a segmented Picker writes the tag of the segment clicked');
same(['1→2'], $ctl->changes, 'onChange runs with the old and the new value, not on the first frame');
$h->clickText('Mode B'); $h->frame(2);
same('b', $ctl->mode, 'a radioGroup Picker writes its tag');
check($h->byLabel('Flavor') !== null, 'a menu Picker shows, labeled by its title');
check($h->byText('Hidden inside') === null, 'DisclosureGroup starts closed');
$h->clickText('Advanced'); $h->frame(2);
check($h->byText('Hidden inside') !== null, 'and opens on a click, keeping its own state');
$h->clickOn($h->byText('Size 2')); $h->frame(2);
check(in_array(true, $ctl->hovers, true), 'onHover hears the pointer come over the view');
$row = $h->byText('In a form');
check($row !== null && $h->rect($row)[0] < 120, 'in a Form, a control is led by its title at the start of the row');
$find = function (Node $n, \Closure $is) use (&$find): ?Node {
    if ($is($n)) { return $n; }
    foreach ($n->kids as $k) { if (($r = $find($k, $is)) !== null) { return $r; } }
    return null;
};
$stepper = $find($h->root, fn(Node $n) => $n->role === \MyPHP\UI\Role::STEPPER);
[$x, $y, $w, $hh] = $h->rect($stepper);
$h->click($x + $w / 2, $y + $hh / 4); $h->frame(2);
same(3, $ctl->qty, "a Stepper's upper arrow steps its value up");
$h->click($x + $w / 2, $y + $hh / 4); $h->frame(2);
same(3, $ctl->qty, 'and not past the end of its range');
$slider = $find($h->root, fn(Node $n) => $n->kind === Node::SLIDER);
$find = null;
[$x, $y, $w, $hh] = $h->rect($slider);
$h->click($x + $w - 2, $y + $hh / 2); $h->frame(2);
check($ctl->volume === 100 && is_int($ctl->volume), 'a Slider bound to an int writes an int, by its step: ' . var_export($ctl->volume, true));
$h->clickText('Actions'); $h->frame();
same(['Rename', '-', 'Reverse'], $h->menuLabels(), "a Menu's Buttons, Divider and Toggle are its items");
$h->chooseMenuItem('Reverse'); $h->frame();
check($ctl->reverse, 'choosing a Toggle in a Menu switches it');
$h->clickText('Actions'); $h->frame(); $h->chooseMenuItem('Rename'); $h->frame();
same('rename', $ctl->chosen, "choosing a Button in a Menu runs its action");
check($h->byText('Copying') !== null, 'ProgressView shows its title over its bar');

// NavigationSplitView: a sidebar of tagged Labels in Sections, bound to the page; a click chooses, the detail follows;
// a section folds; the split takes the window.
$app = new class { public ?string $page = 'inbox'; };
$h = new Headless(fn(Context $c) => Content::render(fn() => NavigationSplitView(
    sidebar: ListView(selection: $app->page)(
        Label('Inbox', systemImage: 'tray')->tag('inbox'),
        Section('Folders')(Text('Work')->tag('work'), Text('Travel')->tag('travel')),
    ),
    detail: Text("Showing {$app->page}"),
), $c), 800, 500);
$h->frame(2);
check($h->byText('Showing inbox') !== null, 'the detail shows the page the sidebar binds');
$h->clickText('Travel'); $h->frame(2);
same('travel', $app->page, 'a click on a sidebar item writes its tag');
check($h->byText('Showing travel') !== null, 'and the detail follows');
same([0.0, 0.0, 800.0, 500.0], $h->rect($h->root->kids[0]), 'the split view takes the window');
$h->clickText('Folders'); $h->frame(3);
check($h->byText('Work') === null, 'a section folds on a click on its title');

// A field of lines (axis: vertical) and a TextEditor keep their caret and what is typed from frame to frame: their state
// is keyed by the text area they build, not a one-line field's id, which the runtime let go every frame.
$lines = new class { public string $a = '', $b = ''; };
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack()(
    TextField('Lines', text: $lines->a, axis: Axis::vertical),
    TextEditor(text: $lines->b)->frame(width: 200, height: 80),
), $c), 500, 400);
$h->frame(2);
$areas = [];
$walk = function (Node $n) use (&$walk, &$areas) { if ($n->kind === Node::TEXTAREA) { $areas[] = $n; } foreach ($n->kids as $k) { $walk($k); } };
$walk($h->root); $walk = null;
$h->clickOn($areas[0]); $h->type('one'); $h->frame(2);
$h->clickOn($areas[1]); $h->type('two'); $h->frame(2);
same(['one', 'two'], [$lines->a, $lines->b], 'a field of lines and a TextEditor write what is typed');

// ScrollView(.horizontal) is as high as what it shows, inside a vertical ScrollView whose height has no end, and a
// Button with a label view has a button's face (bordered).
$h = new Headless(fn(Context $c) => Content::render(fn() => ScrollView()(VStack()(
    ScrollView(Axis::horizontal)(HStack()(Each(range(0, 40))(fn(int $i) => Text("Tag $i")))),
    Button(action: fn() => null, label: Text('A card'))->buttonStyle(ButtonStyle::bordered),
)), $c), 400, 300);
$h->frame(2);
check($h->rect($h->byText('Tag 0'))[3] > 0 && $h->rect($h->byText('Tag 0'))[1] < 50, 'a horizontal ScrollView shows its content inside a vertical one: ' . json_encode($h->rect($h->byText('Tag 0'))));
$card = $h->byText('A card');
$face = null;
$up = function (Node $n) use (&$up, $card, &$face): bool { foreach ($n->kids as $k) { if ($k === $card || $up($k)) { if ($face === null && $n->role === \MyPHP\UI\Role::BUTTON) { $face = $n; } return true; } } return false; };
$up($h->root); $up = null;
check($face !== null && $face->bordered && $face->sides()[0] > 0, "a Button with a label view has a bordered button's face");

// Text without a catalog having its key: shown as it is, its ICU placeholders filled in the environment's language;
// Text(verbatim:) neither filled nor read as Markdown.
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack()(
    Text('Hello, {name}!', ['name' => 'Ada']),
    Text('{n, plural, one {# file} other {# files}}', ['n' => 3]),
    Text(verbatim: '{n} **stays**'),
)->environment(locale: 'en'), $c), 400, 200);
$h->frame(2);
check($h->byText('Hello, Ada!') !== null && $h->byText('3 files') !== null && $h->byText('{n} **stays**') !== null, 'Text fills its placeholders, a plural too; verbatim shows as it is: ' . json_encode($h->texts()));

// focused($isFocused), as SwiftUI's FocusState: true moves the focus to the view, a click elsewhere makes it false;
// defaultFocus() focuses a view as it first shows.
$f = new class { public bool $a = false; public string $x = '', $y = ''; };
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack()(
    TextField('First', text: $f->x)->defaultFocus(),
    TextField('Second', text: $f->y)->focused($f->a),
), $c), 400, 200);
$h->frame(2);
$fields = [];
$walk = function (Node $n) use (&$walk, &$fields) { if ($n->kind === Node::INPUT) { $fields[] = $n; } foreach ($n->kids as $k) { $walk($k); } };
$walk($h->root); $walk = null;
check($h->rt->focus === $fields[0]->id && !$f->a, 'defaultFocus focuses the first field as it shows');
$f->a = true; $h->frame(2);
same($fields[1]->id, $h->rt->focus, 'focused(true) moves the focus to the second');
$h->clickOn($fields[0]); $h->frame(2);
check(!$f->a, 'a click on the first makes the binding false');

// ZStack: a view that takes the room (a Representable that fills, a Color) takes the stack's, and the stack the
// window's; the others are placed by its alignment.
$h = new Headless(fn(Context $c) => Content::render(fn() => ZStack(alignment: Alignment::bottomLeading)(
    Representable(fn(Context $c) => $c->box(0, 0, 0x000000)->label('canvas'), fills: true),
    Text('Caption'),
), $c), 500, 300);
$h->frame(2);
same([0.0, 0.0, 500.0, 300.0], $h->rect($h->byLabel('canvas')), 'a filling view in a ZStack takes the window');
$cap = $h->rect($h->byText('Caption'));
check($cap[0] === 0.0 && $cap[1] + $cap[3] === 300.0, 'and the other sits at the bottom leading corner: ' . json_encode($cap));

// Toggle's styles, as SwiftUI's: automatic a checkbox on a Mac and a switch on a phone; switch, checkbox, button.
$tg = new class { public bool $a = false, $b = false, $c = false; };
$kinds = function (bool $touch) use ($tg): array {
    $h = new Headless(fn(Context $c) => Content::render(fn() => VStack()(
        Toggle('Auto', isOn: $tg->a),
        Toggle('Switch', isOn: $tg->b)->toggleStyle(ToggleStyle::switch),
        Toggle('Button', isOn: $tg->c)->toggleStyle(ToggleStyle::button),
    ), $c), 300, 200);
    $h->rt->touch = $touch;
    $h->frame(2);
    $out = [];
    $walk = function (Node $n) use (&$walk, &$out) { if (in_array($n->kind, [Node::CHECKBOX, Node::SWITCH, Node::BUTTON], true)) { $out[] = $n->kind; } foreach ($n->kids as $k) { $walk($k); } };
    $walk($h->root);
    return $out;
};
same([Node::CHECKBOX, Node::SWITCH, Node::BUTTON], $kinds(false), 'on a Mac: automatic a checkbox, then a switch and a button');
same(Node::SWITCH, $kinds(true)[0], 'on a phone: automatic a switch');

// A TextField takes the width it is given, as SwiftUI's, unless a frame fixes it; a field of lines too.
$tf = new class { public string $a = '', $b = '', $c = ''; };
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack(alignment: HorizontalAlignment::leading)(
    TextField('Wide', text: $tf->a),
    TextField('Fixed', text: $tf->b)->frame(width: 120),
    TextField('Lines', text: $tf->c, axis: Axis::vertical),
)->padding(10), $c), 400, 300);
$h->frame(2);
same([380.0, 120.0, 380.0], [$h->rect($h->byLabel('Wide'))[2], $h->rect($h->byLabel('Fixed'))[2], $h->rect($h->byLabel('Lines'))[2]], 'fields take the width, but a fixed one');

// LazyVGrid with an adaptive column: as many columns as fit cells 100 wide in 430 (4, 10 apart), sharing what is left.
$h = new Headless(fn(Context $c) => Content::render(fn() => LazyVGrid(columns: [GridItem::adaptive(minimum: 100)], spacing: 10)(
    Each(range(1, 6))(fn(int $i) => Text("Cell $i")->frame(maxWidth: INF)->border(Color::separator)),
)->padding(0), $c), 430, 300);
$h->frame(3);
$xs = array_map(fn(int $i) => $h->rect($h->byText("Cell $i"))[1], [1, 4, 5]);
check($xs[0] === $xs[1] && $xs[2] > $xs[0], 'four cells to a row, the fifth on the next: ' . json_encode($xs));

// GeometryReader follows the window: built with the size of the frame before, it builds again once laid out to another.
$gw = [];
$h = new Headless(fn(Context $c) => Content::render(fn() => GeometryReader(function (GeometryProxy $g) use (&$gw) { $gw[] = $g->size[0]; return Text(verbatim: 'w ' . (int)$g->size[0]); }), $c), 500, 300);
$h->paints = true;
$h->frame(2);
$h->w = 700; $h->frame(3);
check($h->byText('w 700') !== null, 'GeometryReader builds again with the new width: ' . json_encode($h->texts()));

// Image->resizable()->scaledToFit() in a frame: as wide as the frame, as high as its aspect ratio makes it.
$pic = \MyPHP\UI\Image::fromPixels(40, 30, str_repeat("\x80\x80\x80\xff", 1200));
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack()(Image($pic)->resizable()->scaledToFit()->frame(width: 400)), $c), 600, 600);
$h->frame(2);
$img = null; $walk = function (Node $n) use (&$walk, &$img) { if ($n->kind === Node::IMAGE) { $img = $n; } foreach ($n->kids as $k) { $walk($k); } }; $walk($h->root); $walk = null;
same([400.0, 300.0], array_slice($h->rect($img), 2), 'a resizable picture scaled to fit: the frame\'s width, its aspect\'s height');

// A frame's fixed size is neither shrunk nor grown, as SwiftUI's: 48-point squares in a row narrower than they are.
$h = new Headless(fn(Context $c) => Content::render(fn() => HStack(spacing: 4)(Each(range(1, 10))(fn(int $i) => Rectangle()->fill(Color::red)->frame(width: 48, height: 48)->accessibilityLabel("sq $i"))), $c), 200, 100);
$h->frame(2);
same(48.0, $h->rect($h->byLabel('sq 1'))[2], 'a 48-point frame stays 48 in a row too narrow for ten');

// ListView(selection:) of a single id: a click chooses, the app's choice is the list's, Return its primary action; a ScrollViewReader's proxy scrolls it.
$app = new class { public ?string $chosen = null; public string $opened = ''; };
$view = fn() => ScrollViewReader(fn(ScrollViewProxy $proxy) => VStack()(
    Button('Last', action: fn() => $proxy->scrollTo('row 499', anchor: UnitPoint::bottom)),
    ListView(array_map(fn(int $i) => "row $i", range(0, 499)), id: fn(string $s) => $s, selection: $app->chosen)(fn(string $s) => Text(verbatim: $s))
        ->contextMenu(primaryAction: function (array $ids) use ($app) { $app->opened = implode(',', $ids); }),
));
$h = new Headless(fn(Context $c) => Content::render($view, $c), 400, 400);
$h->frame(2);
$h->clickText('row 3'); $h->frame(2);
same('row 3', $app->chosen, 'a click chooses the row of its id');
$h->down(); $h->frame(2);
same('row 4', $app->chosen, 'Down chooses the next, not put back to the app\'s choice before it');
$app->chosen = 'row 5'; $h->frame(2);
$h->key(Key::RETURN, 'insertNewline:'); $h->frame(2);
same('row 5', $app->opened, 'the app\'s choice is the list\'s; Return opens it');
check($h->byText('row 499') === null, 'the last row not built yet');
$h->clickText('Last'); $h->frame(40);
check($h->byText('row 499') !== null, 'scrollTo() glides to it');

// Frames in frames, as SwiftUI lays them: a panel frame(width: 280)->frame(maxHeight: INF) beside what takes the row is 280 wide and full height, its Spacer pushing
// its last view down; a field's bar frame(maxWidth: 400) under what takes the column is at the bottom, 400 wide and centered, a background view under it taking no room.
$field = '';
$h = new Headless(fn(Context $c) => Content::render(fn() => HStack(alignment: \VerticalAlignment::top, spacing: 16)(
    Color(\Color::red)->frame(maxWidth: INF, maxHeight: INF),
    VStack(alignment: \HorizontalAlignment::leading)(Text('Top'), Spacer(), Text('Bottom'))->padding(16)->frame(width: 280)->frame(maxHeight: INF)
        ->background(\Color::gray)->cornerRadius(12)->overlay(RoundedRectangle(cornerRadius: 12)->stroke(\Color::separator)),
), $c), 1000, 600);
$h->frame(3);
same([568.0, 839.0], [$h->rect($h->byText('Bottom'))[1], $h->rect($h->byText('Top'))[0]], 'the panel 280 wide at the right, the stack centered in it, its Spacer taking its height');
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack(spacing: 0)(
    Color(\Color::red)->frame(maxWidth: INF, maxHeight: INF),
    HStack()(TextField('M', text: $field), Text('Send'))->padding(6)->background(\Color::gray)->cornerRadius(10)
        ->padding(4)->background(Canvas(fn($g) => null))->frame(maxWidth: 400)->padding(\Edge::horizontal, 20)->accessibilityLabel('bar'),
), $c), 1000, 600);
$h->frame(3);
same([304.0, 554.0, 392.0, 42.0], $h->rect($h->byLabel('bar')), 'the bar at the bottom, 400 wide (less its ring) and centered');

// A ZStack over an Each: its views in the one cell, over the view that takes the room, which keeps all of it (confetti's bursts made the page shorter).
$h = new Headless(fn(Context $c) => Content::render(fn() => ZStack(alignment: \Alignment::topLeading)(
    \Color(\Color::red)->frame(maxWidth: INF, maxHeight: INF)->accessibilityLabel('page'),
    Each([1, 2])(fn(int $i) => \Color(\Color::blue)->frame(width: 1, height: 1)->offset(x: 10 * $i)),
), $c), 400, 300);
$h->frame(2);
same([0.0, 0.0, 400.0, 300.0], $h->rect($h->byLabel('page')), 'the page all the stack, the Each\'s views over it');

// overlay: a shape over the view, as large as it, the view keeping its size; GroupBox as wide as it is given.
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack(alignment: HorizontalAlignment::leading)(
    Text('Framed')->padding(10)->overlay(RoundedRectangle(cornerRadius: 6)->stroke(Color::red, lineWidth: 2)->accessibilityLabel('ring')),
    GroupBox('Box')(Text('Inside')),
), $c), 400, 300);
$h->frame(2);
$ring = $h->rect($h->byLabel('ring')); $framed = $h->rect($h->byText('Framed'));
check($ring === $framed && $framed[2] > 40 && $framed[2] < 100, 'the overlay as large as the padded text, which keeps its size: ' . json_encode([$ring, $framed]));
check($h->rect($h->byText('Inside'))[0] < 40 && $h->byText('Box') !== null, 'a GroupBox\'s title above its views, leading');

// A styled Text wraps at the width it is given as a plain one does; a Divider framed in an HStack stays a vertical hairline.
$long = str_repeat('Drag a card to the other window or a text editor. ', 4);
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack(alignment: HorizontalAlignment::leading)(
    Text(verbatim: $long)->foregroundStyle(Color::secondary),
    HStack()(Text('L'), Divider()->frame(height: 20)->accessibilityLabel('rule'), Text('R')),
)->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading), $c), 400, 400);
$h->frame(2);
same(360.0, $h->rect($h->byText($long))[2], 'a secondary Text wraps at the 360 points it has');
$walk = function (Node $n) use (&$walk) { if ($n->kind === Node::BOX && $n->fw == 1.0) { return $n; } foreach ($n->kids as $k) { if ($r = $walk($k)) { return $r; } } return null; };
same([1.0, 20.0], array_slice($h->rect($walk($h->root)), 2), 'the framed Divider down the HStack, 20 high'); $walk = null;

// A Picker of a Binding(get:set:): it shows what get() computes, and a choice goes through set().
$heading = 3; $sets = [];
$h = new Headless(function (Context $c) use (&$heading, &$sets) {
    $style = new Binding(get: fn() => $heading, set: function (int $v) use (&$heading, &$sets) { $sets[] = $v; $heading = $v; });
    Content::render(fn() => Picker('Style', selection: $style)(Text('Title')->tag(0), Text('Body')->tag(3))->pickerStyle(PickerStyle::segmented), $c);
}, 400, 200);
$h->frame(2);
$h->clickText('Title'); $h->frame(2);
same([[0], 0], [$sets, $heading], 'the choice set through the binding, once');

// A chosen row of a plain list: its secondary text in the accent's text color, fainter, as SwiftUI's in a selected row.
$pick = 'b';
$h = new Headless(fn(Context $c) => Content::render(fn() => ListView(['a', 'b'], selection: $pick)(fn(string $s) => VStack()(Text(verbatim: "name $s"), Text(verbatim: "where $s")->foregroundStyle(Color::secondary)))->listStyle(ListStyle::plain), $c), 300, 200);
$h->frame(2);
$fg = fn(string $s) => [$h->byText($s)->ts?->color ?? $h->byText($s)->fg, $h->byText($s)->ts?->alpha ?? 1.0];
same([[$h->theme->accentText, 0.75], [$h->theme->textMuted, 1.0]], [$fg('where b'), $fg('where a')], 'secondary on the accent of the chosen row, muted elsewhere');

// Modifiers in their order, as SwiftUI's: a background after searchable() is behind the search field too; before it, the view's alone.
$q = '';
foreach ([true, false] as $after) {
    $h = new Headless(function (Context $c) use (&$q, $after) {
        Content::render(function () use (&$q, $after) {
            $v = VStack()(Text('Rows'))->frame(maxWidth: INF, maxHeight: INF);
            return $after ? $v->searchable(text: $q)->background(Color::red) : $v->background(Color::red)->searchable(text: $q);
        }, $c);
    }, 300, 200);
    $h->frame(2);
    $bg = null; $walk = function (Node $n) use (&$walk, &$bg) { if ($n->color === 0xff3b30 || $n->color === 0xff453a) { $bg ??= $n; } foreach ($n->kids as $k) { $walk($k); } }; $walk($h->root); $walk = null;
    $field = $h->rect($h->byLabel('Search'));
    same($after, $h->rect($bg)[1] <= $field[1], $after ? 'a background after searchable() is behind its field' : 'one before it is the view\'s alone');
}
$h = new Headless(function (Context $c) use (&$q) { Content::render(function () use (&$q) { return VStack()(Text('Rows'))->searchable(text: $q); }, $c); }, 300, 200);
$h->frame(); $h->clickOn($h->byLabel('Search')); $h->type('ab'); $h->frame();
same('ab', $q, 'the field binds its text');

// A picture scaled to fit in a frame of a height: no higher than it, drawn within it, as SwiftUI's fits both sides.
$tall = \MyPHP\UI\Image::fromPixels(30, 60, str_repeat("\x80\x80\x80\xff", 1800));
$h = new Headless(fn(Context $c) => Content::render(fn() => VStack()(Image($tall)->resizable()->scaledToFit()->frame(maxWidth: INF, height: 160)), $c), 300, 400);
$h->frame(2);
$img = null; $walk = function (Node $n) use (&$walk, &$img) { if ($n->kind === Node::IMAGE) { $img = $n; } foreach ($n->kids as $k) { $walk($k); } }; $walk($h->root); $walk = null;
same(160.0, $h->rect($img)[3], 'a tall picture no higher than its frame');

// help(): the tip of the view under the pointer, what a window whose system shows tips (macOS's) gives it; a press hides it.
$h = new Headless(fn(Context $c) => Content::render(fn() => HStack()(Text('One')->help('First tip'), Text('Two')), $c), 300, 100);
$h->frame(2);
[$x, $y] = $h->middle($h->byText('One')); $h->hover($x, $y); $h->frame(2);
same('First tip', $h->rt->hoverTip(), 'the tip of the view under the pointer');
[$x2, $y2] = $h->middle($h->byText('Two')); $h->hover($x2, $y2); $h->frame(2);
same('', $h->rt->hoverTip(), 'none over a view without one');

// iOS's semantic colors follow the theme: a surface over the background, a grouped list's the other way round, the grays as Apple gives them.
$ok = true;
foreach ([\MyPHP\UI\Theme::light(), \MyPHP\UI\Theme::dark()] as $th) {
    $ok = $ok && \Color::secondarySystemBackground->resolve($th) === $th->surface && \Color::systemGroupedBackground->resolve($th) === $th->surface
        && \Color::secondarySystemGroupedBackground->resolve($th) === $th->background && \Color::secondaryLabel->resolve($th) === $th->textMuted;
}
same([true, 0xf2f2f7, 0x1c1c1e], [$ok, \Color::systemGray6->resolve(\MyPHP\UI\Theme::light()), \Color::systemGray6->resolve(\MyPHP\UI\Theme::dark())], 'iOS\'s semantic colors in the light theme and the dark');

// A plain TextField's text starts at the field, and a padding put around it moves it in (it was drawn 11 points left of it).
$typed = '';
$h = new Headless(fn(Context $c) => Content::render(fn() => HStack()(TextField('M', text: $typed)->textFieldStyle(\TextFieldStyle::plain)->padding(\Edge::vertical, 8)->accessibilityLabel('plain'))
    ->padding(\Edge::leading, 16)->frame(maxWidth: INF, alignment: \Alignment::leading), $c), 300, 100);
$h->frame(2);
$h->metrics->painted = [];
$h->paint();
$at = array_values(array_filter($h->metrics->painted, fn(array $p) => $p[0] === 'M'))[0][2] ?? null;
$h->metrics->painted = null;
same([16.0, 16.0], [$h->rect($h->byLabel('plain'))[0], $at], 'the field 16 in, and its prompt drawn where it starts');

// What a Circle clips is masked by a circle, its radius half its side: a radius of 100,000 cut all of it out on the Mac (chat-glow's send arrow).
$seen = new \ArrayObject();
$h = new Headless(fn(Context $c) => Content::render(fn() => Canvas(function (\GraphicsContext $g) use ($seen) { $g->withPainter(function (\MyPHP\UI\Painter $p) use ($seen) { $seen['mask'] = $p->displayList()->mask(); }); })
    ->frame(width: 34, height: 34)->background(\Color::gray)->clipShape(Circle()), $c), 100, 100);
$h->frame(2); $h->paint();
$m = $seen['mask'] ?? [];
same([17.0, 17.0, 17.0], [$m[4] ?? null, $m[5] ?? null, -1.0 - ($m[6] ?? 0)], 'the circle\'s mask rounds 17, half its 34');

// The caret blinks for as long as frames come, after it has rested 30 s too (chat-glow's orb animating under it), and
// stops on: rested and off, it asks for one frame more; rested and on, for none (mygo's, and the Mac's).
$blinkStart = new \ReflectionProperty(\MyPHP\UI\Runtime::class, 'blinkStart');
$blinkSeen = new \ReflectionProperty(\MyPHP\UI\Runtime::class, 'blinkSeen');
$h = new Headless(fn(Context $c) => null, 100, 100);
$blinkSeen->setValue($h->rt, ['a field']);
$restedAt = hrtime(true) / 1e9; // not $now: the tests share a scope, and draggable's $now is an int
$blinkStart->setValue($h->rt, $restedAt - 31.75); // rested, in the off half of its blink
[$off, $oneMore] = [$h->rt->caretVisible(), $h->rt->nextBlink()];
$blinkStart->setValue($h->rt, $restedAt - 31.25); // rested, in the on half
[$on, $none] = [$h->rt->caretVisible(), $h->rt->nextBlink()];
same([false, true, true, null], [$off, $oneMore !== null && $oneMore <= 0.26, $on, $none], 'rested, the caret still blinks in the frames that come, and stops asking once on');

// A LabeledContent in a Form on the desktop: its text the size of the form's other rows', not a phone's 17, and 44 high as they are.
$h = new Headless(fn(Context $c) => Content::render(fn() => Form()(Section(header: 'S')(LabeledContent('Address', 'AA:BB'), Text('Plain row'))), $c), 400, 300);
$h->frame(2);
[$label, $value, $plain] = [$h->byText('Address'), $h->byText('AA:BB'), $h->byText('Plain row')];
same([$plain->fontSize, $plain->fontSize, 44.0], [$label->fontSize, $value->fontSize, $h->rect($h->byLabel('Address, AA:BB'))[3]], 'a LabeledContent\'s text as the form\'s other rows\'');

// A Form's section on the desktop, as the Mac's grouped forms: its header the headline style, as written, not a phone's
// capitals; its footer the subheadline style.
$h = new Headless(fn(Context $c) => Content::render(fn() => Form()(Section(header: 'Noise Control', footer: 'Sent as it changes.')(Text('Row'))), $c), 400, 300);
$h->frame(2);
[$head, $foot] = [$h->byText('Noise Control'), $h->byText('Sent as it changes.')];
same([true, 13.0, 700, 11.0], [$head !== null && $h->byText('NOISE CONTROL') === null, $head?->fontSize, $head?->textStyle()->weight, $foot?->fontSize], 'a section\'s header and footer on the desktop');

// A Form's first section without a header: as much room above its group as at its sides.
$h = new Headless(fn(Context $c) => Content::render(fn() => Form()(Section()(Text('First row')->accessibilityLabel('first'))), $c), 400, 300);
$h->frame(2);
$row = $h->rect($h->byLabel('first'));
same(16.0 + 16.0, $row[0], 'the group 16 in from the side, its row 16 in from the group');
check($row[1] >= 16.0, 'and 16 below the top at least: ' . json_encode($row));

// TabView(.sidebarAdaptable), one view for every screen: a sidebar on the desktop and a tablet on its side (a
// NavigationSplitView of the tabs), tabs across the top of a tablet held upright, the tab bar across the bottom of a
// phone; each chooses the tab, its badge in the sidebar.
$tabsApp = new class { public string $page = 'a'; };
$tabs = fn() => TabView(selection: $tabsApp->page)(
    Tab('Alpha', systemImage: 'gearshape', value: 'a')(Text('Page A')),
    Tab('Beta', systemImage: 'list.bullet', value: 'b')(Text('Page B'))->badge(7),
)->tabViewStyle(\TabViewStyle::sidebarAdaptable);
$where = [];
foreach (['desktop' => [false, 900, 600], 'phone' => [true, 390, 844], 'tablet upright' => [true, 820, 1180], 'tablet on its side' => [true, 1180, 820]] as $name => [$touch, $w, $hgt]) {
    $tabsApp->page = 'a';
    $h = new Headless(fn(Context $c) => Content::render($tabs, $c), $w, $hgt);
    $h->rt->touch = $touch;
    $h->frame(3);
    $beta = $h->byText('Beta') ?? $h->byLabel('Beta');
    $r = $h->rect($beta);
    $where[$name] = $r[1] > $hgt / 2 ? 'bottom' : ($r[0] < 100 ? 'side' : 'top');
    $h->clickOn($beta); $h->frame(3);
    $where[$name] .= $tabsApp->page === 'b' && $h->byText('Page B') !== null ? ', chosen' : ', not chosen';
    if ($name === 'desktop') { $where['badge'] = $h->byText('7') !== null; }
}
same(['desktop' => 'side, chosen', 'badge' => true, 'phone' => 'bottom, chosen', 'tablet upright' => 'top, chosen', 'tablet on its side' => 'side, chosen'], $where, 'sidebarAdaptable on each screen');

// A phone's form, as UIKit's cells: a Picker's choice muted at the row's end with no face; a segmented Picker across the
// row, its segments sharing it; and a tab's page in a NavigationStack, its navigationTitle in the bar.
$phoneApp = new class { public int $speed = 0; public int $mode = 0; public string $tab = 'a'; };
$h = new Headless(fn(Context $c) => Content::render(fn() => TabView(selection: $phoneApp->tab)(
    Tab('A', systemImage: 'gearshape', value: 'a')(Form()(Section(header: 'S')(
        Picker('Speed', selection: $phoneApp->speed)(Text('Default')->tag(0), Text('Slower')->tag(1)),
        Picker('Mode', selection: $phoneApp->mode)(Text('Off')->tag(0), Text('On')->tag(1))->pickerStyle(\PickerStyle::segmented)->labelsHidden(),
    ))->navigationTitle('Settings page')),
)->tabViewStyle(\TabViewStyle::sidebarAdaptable), $c), 393, 852);
$h->rt->touch = true;
$h->frame(3);
$choice = $h->byText('Default');
same([$h->theme->textMuted, true, true, true], [$choice->fg, $h->rect($choice)[0] > 393 / 2, $h->rect($h->byLabel('Mode'))[2] > 393 - 4 * 16 - 4, $h->byText('Settings page') !== null],
    'a phone\'s form: the choice muted at the end, the segments across the row, the page\'s title in its bar');

// A phone's form as iOS 26 draws it: every row 52 high (a switch's and segments' too); its header as written; a Toggle its title leading and the
// switch at the row's end; segments on a see-through gray track, the chosen one white; the page's title large; and
// the grouped gray behind the bar and the tab bar, which are clear over the page.
$iosApp = new class { public int $speed = 0; public int $mode = 1; public bool $on = true; public string $tab = 'a'; };
$h = new Headless(fn(Context $c) => Content::render(fn() => TabView(selection: $iosApp->tab)(
    Tab('A', systemImage: 'gearshape', value: 'a')(Form()(Section(header: 'Noise')(
        Picker('Mode', selection: $iosApp->mode)(Text('Off')->tag(0), Text('On')->tag(1))->pickerStyle(\PickerStyle::segmented)->labelsHidden(),
        Toggle('Awareness', isOn: $iosApp->on),
        Picker('Speed', selection: $iosApp->speed)(Text('Default')->tag(0), Text('Slower')->tag(1)),
        Text('Plain'),
    ))->navigationTitle('Settings page')),
)->tabViewStyle(\TabViewStyle::sidebarAdaptable), $c), 393, 852);
$h->rt->touch = true; $h->theme = \MyPHP\UI\Theme::phone(false);
$h->frame(3);
$parentOf = function (Node $of, ?Node $in = null) use (&$parentOf, $h): ?Node {
    foreach (($in ?? $h->root)->kids as $k) { if ($k === $of) { return $in; } if (($p = $parentOf($of, $k)) !== null) { return $p; } }
    return null;
};
$rowOf = fn(Node $n) => $h->rect($parentOf($n))[3];
$switch = $h->byLabel('Awareness');
$track = $h->byLabel('Mode');
$chosen = array_values(array_filter($track->kids, fn(Node $k) => $k->on))[0];
$bar = $h->root->kids[0]->kids[0]->kids[0]->kids[0];
same([[52.0, 52.0, 52.0, 52.0], [true, false], true, $h->rect($parentOf($switch))[2] - 32, [0x767680, 0.12, 0xffffff], true, 0xf2f2f7, 0xf2f2f7],
    [array_map($rowOf, [$track, $switch, $parentOf($parentOf($h->byText('Default'))), $h->byText('Plain')]), [$h->byText('Noise') !== null, $h->byText('NOISE') !== null], $switch->reverse, $h->rect($switch)[2], [$track->color, $track->bgAlpha, $chosen->color],
     count(array_filter($h->texts(), fn($t) => $t === 'Settings page')) === 2, $bar->color, $h->root->kids[0]->color],
    'a phone\'s form as iOS 26\'s: rows 52 high, the header as written, the switch at the end, the segments\' track, a large title, the page\'s gray behind the bars');

// A LabeledContent too long for its row keeps its title whole and shortens its value, as UIKit's value1 cell.
$h = new Headless(fn(Context $c) => Content::render(fn() => Form()(Section()(LabeledContent('JNI constructor', str_repeat('Legacy(type,auth,enc,Device,psm,uuid)', 2)))), $c), 393, 300);
$h->frame(2);
$title = $h->byText('JNI constructor');
same(mb_strlen('JNI constructor') * 7.0, $h->rect($title)[2], 'the title whole, the value shortened');
