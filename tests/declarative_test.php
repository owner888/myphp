<?php
// The declarative views, as SwiftUI's (docs/declarative.md): stacks, text, buttons running their actions, a toggle
// and a field bound to the state, Each keeping rows by id, modifiers in order (a padding after a background pads
// outside it), the environment flowing down, onAppear and onDisappear, keyboardShortcut.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Content, Context, Node};
use View, Font, Color, ButtonStyle, KeyEquivalent, Edge, PickerStyle, DatePickerComponents, Axis, Alignment, ToggleStyle, HorizontalAlignment, GridItem, GeometryProxy;

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
