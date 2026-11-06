<?php
// The declarative views, as SwiftUI's (docs/declarative.md): stacks, text, buttons running their actions, a toggle
// and a field bound to the state, Each keeping rows by id, modifiers in order (a padding after a background pads
// outside it), the environment flowing down, onAppear and onDisappear, keyboardShortcut.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Content, Context, Node};
use View, Font, Color, ButtonStyle, KeyEquivalent, Edge;

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
same(120.0, $h->byText('Side')->w, 'a fixed frame width keeps its width');
$h->clickText('Ideas'); $h->frame(3);
same('/notes/2', $nav->path->path(), 'a NavigationLink of Each in a section pushes its value');
check($h->byText('Note 2 is an int: int') !== null, 'navigationDestination gets {id} as the int it asks for');
$h->clickLabel('Back'); $h->frame(3);
check($nav->path->isEmpty() && $h->byText('Groceries') !== null, 'Back pops to the list');
