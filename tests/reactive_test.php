<?php
// Native PHP 8.4+ fine-grained reactivity (State<T>, Observer Property Hooks, Binding::of, Computed retained subtrees).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Content, Context};
use MyPHP\UI\Reactive\{Observer, State};
use Binding, ButtonStyle, Font, PickerStyle;

require_once __DIR__ . '/../src/UI/Declarative/functions.php';

// 1. PHP 8.4 Property Hooks with Observer::get / Observer::set + fine-grained Computed subtrees
$store = new class {
    public int $a = 10 {
        get => Observer::get($this, 'a', $this->a);
        set => Observer::set($this, 'a', $this->a, $value);
    }
    public int $b = 20 {
        get => Observer::get($this, 'b', $this->b);
        set => Observer::set($this, 'b', $this->b, $value);
    }
    public bool $flag = false {
        get => Observer::get($this, 'flag', $this->flag);
        set => Observer::set($this, 'flag', $this->flag, $value);
    }
};

$buildsA = 0;
$buildsB = 0;
$buildsNestedA = 0;
$buildsNestedB = 0;

$view = function () use ($store, &$buildsA, &$buildsB, &$buildsNestedA, &$buildsNestedB) {
    return VStack(spacing: 8)(
        Computed(function () use ($store, &$buildsA) {
            $buildsA++;
            return Text("Branch A: {$store->a}")->font(Font::title);
        }, key: 'branch-a'),
        Computed(function () use ($store, &$buildsB) {
            $buildsB++;
            return Text("Branch B: {$store->b}");
        }, key: 'branch-b'),
        Computed(function () use ($store, &$buildsNestedA, &$buildsNestedB) {
            return HStack(spacing: 8)(
                Computed(function () use ($store, &$buildsNestedA) {
                    $buildsNestedA++;
                    return Text("Nested A: {$store->a}");
                }, key: 'nested-a'),
                Computed(function () use ($store, &$buildsNestedB) {
                    $buildsNestedB++;
                    return Text("Nested B: {$store->b}");
                }, key: 'nested-b'),
            );
        }, key: 'nested-outer'),
        Button('Inc A', action: function () use ($store) { $store->a++; }),
        Button('Inc B', action: function () use ($store) { $store->b++; })->buttonStyle(ButtonStyle::borderedProminent),
    );
};

$h = new Headless(fn(Context $c) => Content::render($view, $c), 500, 400);
$h->frame(1);
same([1, 1, 1, 1], [$buildsA, $buildsB, $buildsNestedA, $buildsNestedB], 'initial frame builds each Computed subtree once');
$nodeB = $h->byText('Branch B: 20');
check($nodeB !== null && $h->byText('Branch A: 10') !== null, 'both branches rendered');

// Idle frames with no state change: 0 rebuilds of any Computed subtree
$h->frame(3);
same([1, 1, 1, 1], [$buildsA, $buildsB, $buildsNestedA, $buildsNestedB], 'idle frames skip all clean Computed subtrees');
check($h->byText('Branch B: 20') === $nodeB, 'retained Node instance is identical across frames');

// Mutating $store->a directly (as from an async Fiber or timer) immediately marks runtime needsFrame and only invalidates A
$h->rt->needsFrame = false;
$store->a = 11;
check($h->rt->needsFrame === true, 'PHP 8.4 Property Hook set immediately wakes the runtime without polling');
$h->frame(1);
same([2, 1, 2, 1], [$buildsA, $buildsB, $buildsNestedA, $buildsNestedB], 'mutating $store->a rebuilds only Branch A and Nested A; Branch B and Nested B have 0 rebuilds');
check($h->byText('Branch A: 11') !== null && $h->byText('Nested A: 11') !== null, 'updated A text rendered');
check($h->byText('Branch B: 20') === $nodeB, 'Branch B kept its exact retained Node instance');

// Clicking 'Inc B' button only rebuilds Branch B and Nested B; Branch A and Nested A stay at 2 builds!
$h->clickText('Inc B');
$h->frame(1);
same(21, $store->b, 'Button click incremented $store->b');
same([2, 2, 2, 2], [$buildsA, $buildsB, $buildsNestedA, $buildsNestedB], 'clicking Inc B rebuilt only Branch B and Nested B; Branch A and Nested A skipped');

// Setting the same value is a no-op (does not trigger or rebuild)
$h->rt->needsFrame = false;
$store->a = 11;
check($h->rt->needsFrame === false, 'setting identical value does not trigger invalidation');
$h->frame(1);
same([2, 2, 2, 2], [$buildsA, $buildsB, $buildsNestedA, $buildsNestedB], 'no rebuild on identical assignment');

// 2. State<T> and Binding::of() with declarative controls (Toggle, TextField, Slider, Stepper, Picker)
$count = State(5);
$wifi = State(false);
$name = State('Ada');
$flavor = State('vanilla');
$flagBinding = Binding::of($store, 'flag');

$ctlView = function () use ($count, $wifi, $name, $flavor, $flagBinding) {
    return VStack(spacing: 8)(
        Computed(fn() => Text("State count: {$count}"), key: 'state-count'),
        Toggle('Wi-Fi', isOn: $wifi),
        Toggle('Hook Flag', isOn: $flagBinding),
        TextField('Name', text: $name),
        Stepper('Count', value: $count, in: [0, 20]),
        Slider(value: $count, in: [0, 20], step: 1),
        Picker('Flavor', selection: $flavor)(
            Text('Vanilla')->tag('vanilla'),
            Text('Matcha')->tag('matcha'),
        )->pickerStyle(PickerStyle::segmented),
    );
};

$hc = new Headless(fn(Context $c) => Content::render($ctlView, $c), 500, 500);
$hc->frame(2);
check($hc->byText('State count: 5') !== null, 'State<T> string interpolation in Text');

$hc->clickLabel('Wi-Fi');
$hc->frame(2);
same(true, $wifi->value, 'Toggle updates State<bool>');

$hc->clickLabel('Hook Flag');
$hc->frame(2);
same(true, $store->flag, 'Toggle updates PHP 8.4 hooked property via Binding::of');

$hc->clickLabel('Name');
$hc->shortcut('a', \MyPHP\UI\Key::COMMAND);
$hc->type('Grace');
$hc->frame(2);
same('Grace', $name->value, 'TextField updates State<string>');

$hc->clickText('Matcha');
$hc->frame(2);
same('matcha', $flavor->value, 'Segmented Picker updates State<string>');

$count->update(fn(int $n): int => $n + 10);
$hc->frame(1);
same(15, $count->peek(), 'State::update and State::peek');
check($hc->byText('State count: 15') !== null, 'Computed Text updated after State::update');

// 3. Observer::batch coalesces multiple mutations
$batchRuns = 0;
$x = State(1);
$y = State(2);
$hb = new Headless(function (Context $c) use ($x, $y, &$batchRuns) {
    Content::render(function () use ($x, $y, &$batchRuns) {
        return Computed(function () use ($x, $y, &$batchRuns) {
            $batchRuns++;
            return Text("Sum: " . ($x->value + $y->value));
        }, key: 'sum');
    }, $c);
}, 300, 200);
$hb->frame(1);
same(1, $batchRuns, 'initial batch scope build');
Observer::batch(function () use ($x, $y) {
    $x->value = 10;
    $y->value = 20;
});
$hb->frame(1);
same(2, $batchRuns, 'Observer::batch coalesced two State writes into a single rebuild');
check($hb->byText('Sum: 30') !== null, 'Sum updated to 30');

// 4. Zero reference cycles across 50 frames with State, Observer and Computed
gc_collect_cycles();
gc_disable();
for ($i = 0; $i < 25; $i++) {
    $store->a++;
    $h->frame(2);
}
gc_enable();
same(0, gc_collect_cycles(), 'Reactive State, Observer and Computed leave 0 reference cycles');
