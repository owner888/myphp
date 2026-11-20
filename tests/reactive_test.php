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

// 4. Implicit Computed: any Closure passed into a container (VStack, HStack, Group, ZStack) is automatically a fine-grained reactive scope
$impA = State(100);
$impB = State(200);
$impBuildsA = 0;
$impBuildsB = 0;
$hi = new Headless(function (Context $c) use ($impA, $impB, &$impBuildsA, &$impBuildsB) {
    Content::render(function () use ($impA, $impB, &$impBuildsA, &$impBuildsB) {
        return VStack(spacing: 8)(
            function () use ($impA, &$impBuildsA) {
                $impBuildsA++;
                return Text("Implicit A: {$impA}");
            },
            function () use ($impB, &$impBuildsB) {
                $impBuildsB++;
                return Text("Implicit B: {$impB}");
            },
        );
    }, $c);
}, 300, 200);
$hi->frame(1);
same([1, 1], [$impBuildsA, $impBuildsB], 'implicit closure children build once initially');
$impA->value = 101;
$hi->frame(1);
same([2, 1], [$impBuildsA, $impBuildsB], 'mutating $impA only rebuilds the first closure child; second closure child has 0 rebuilds');
check($hi->byText('Implicit A: 101') !== null && $hi->byText('Implicit B: 200') !== null, 'implicit closure children render updated text');

// 5. Zero-boilerplate plain PHP properties (no Property Hooks, no State(), no #[Observable])
$plain = new class {
    public int $a = 1;
    public int $b = 2;
};
$plainBuildsA = 0;
$plainBuildsB = 0;
$hp = new Headless(function (Context $c) use ($plain, &$plainBuildsA, &$plainBuildsB) {
    Content::render(function () use ($plain, &$plainBuildsA, &$plainBuildsB) {
        return VStack(spacing: 8)(
            function () use ($plain, &$plainBuildsA) {
                $plainBuildsA++;
                return Text("Plain A: {$plain->a}");
            },
            function () use ($plain, &$plainBuildsB) {
                $plainBuildsB++;
                return Text("Plain B: {$plain->b}");
            },
        );
    }, $c);
}, 300, 200);
$hp->frame(1);
same([1, 1], [$plainBuildsA, $plainBuildsB], 'zero-boilerplate plain properties: initial build');
$plain->a = 99;
$hp->frame(1);
same([2, 1], [$plainBuildsA, $plainBuildsB], 'zero-boilerplate plain properties: mutating $plain->a rebuilds only A; B has 0 rebuilds');
check($hp->byText('Plain A: 99') !== null && $hp->byText('Plain B: 2') !== null, 'zero-boilerplate plain properties: updated A text rendered');

$hp->rt->needsFrame = false;
$plain->b = 88;
Observer::sync();
check($hp->rt->needsFrame === true, 'Observer::sync() wakes runtime when a watched plain property mutates asynchronously');
$hp->frame(1);
same([2, 2], [$plainBuildsA, $plainBuildsB], 'Observer::sync() only invalidated B; A stayed at 2 builds');
check($hp->byText('Plain B: 88') !== null, 'zero-boilerplate plain properties: updated B text rendered');

// 7. What a closure reads that no `$var->name` in its source names is still tracked
$newState = fn() => new class {
    public int $a = 1;
    public int $b = 2;
    public ?object $doc = null;
    public function label(): string { $k = 'b'; return (string)$this->{$k}; }
};
$clean = function (object $s, \Closure $f, \Closure $change): bool { // whether the scope that ran $f is still clean after $change($s)
    $scope = new \MyPHP\UI\Reactive\Scope();
    Observer::run($scope, $f, $f);
    $change($s);
    return $scope->isClean();
};
$s = $newState();
check($clean($s, function () use ($s) { return $s->a; }, fn($s) => $s->b = 3), 'a change of a property the closure does not read keeps its scope clean');
$s = $newState();
check(!$clean($s, function () use ($s) { $k = 'b'; return $s->a . $s->$k; }, fn($s) => $s->b = 3), '$obj->$name: a change of the property it names dirties the scope');
$s = $newState();
check(!$clean($s, function () use ($s) { return $s->a . json_encode($s); }, fn($s) => $s->b = 3), 'an object passed to a function: a change of what it reads dirties the scope');
$s = $newState();
check(!$clean($s, function () use ($s) { return $s->label(); }, fn($s) => $s->b = 3), 'a method reading $this->{$k}: a change of any property dirties the scope');
$s = $newState();
$s->doc = new class { public string $title = 'Draft'; };
check(!$clean($s, function () use ($s) { return $s->doc->title; }, fn($s) => $s->doc->title = 'Final'), '$obj->doc->title: a change inside the nested object dirties the scope');

$scope = new \MyPHP\UI\Reactive\Scope();
$f = function () use ($s) { return $s->doc->title; };
Observer::run($scope, $f, $f);
$scope->begin();
$s->doc->title = 'Sent';
Observer::sync();
check($scope->dirty, 'Observer::sync() sees a change inside a nested object');

$f = function () use ($s) { return $s->doc->title; };
for ($i = 0; $i < 3; $i++) { $gone = new \MyPHP\UI\Reactive\Scope(); Observer::run($gone, $f, $f); unset($gone); } // freed: their object ids come again
$scope = new \MyPHP\UI\Reactive\Scope();
Observer::run($scope, $f, $f);
$scope->begin();
$s->doc->title = 'Archived';
Observer::sync();
check($scope->dirty, 'a scope that took a freed scope\'s object id is subscribed all the same');

$mk = fn(object $o) => function () use ($o) { return $o->a; };
[$o1, $o2] = [$newState(), $newState()];
$scope = new \MyPHP\UI\Reactive\Scope();
$c = $mk($o1); Observer::run($scope, $c, $c);
$c = $mk($o2); Observer::run($scope, $c, $c);
$o2->a = 7;
check(!$scope->isClean(), 'the same closure made again over another object tracks that object, not the first');

// A view file loaded again (hot reload) is parsed from its new source
$file = sys_get_temp_dir() . '/myphp_reactive_view_' . getmypid() . '.php';
$s = $newState();
$load = function () use ($file, $s): \Closure { return require $file; };
file_put_contents($file, "<?php\nreturn function () use (\$s) { return \$s->a; };\n");
$scope = new \MyPHP\UI\Reactive\Scope();
$f = $load(); Observer::run($scope, $f, $f);
file_put_contents($file, "<?php\nreturn function () use (\$s) { return \$s->b; };\n");
Observer::forgetFile((string)realpath($file));
$f = $load(); Observer::run($scope, $f, $f);
$s->b = 8;
check(!$scope->isClean(), 'Observer::forgetFile: the closures of a file loaded again track what the new source reads');
unlink($file);

// 8. A plain property an app event's listener changed redraws, as one a timer changed
$evPlain = new class { public string $last = ''; };
$hev = new Headless(function (Context $c) use ($evPlain) {
    Content::render(fn() => VStack()(function () use ($evPlain) { return Text("Last: {$evPlain->last}"); }), $c);
}, 300, 200);
$hev->frame(1);
\MyPHP\App::onNotificationClick(function (string $id) use ($evPlain): void { $evPlain->last = $id; });
$hev->rt->needsFrame = false;
(new \MyPHP\AppHandler())->notificationClicked('reactive-test');
check($hev->rt->needsFrame === true, 'AppHandler: a listener changing a plain property wakes the window');
$hev->frame(1);
check($hev->byText('Last: reactive-test') !== null, 'AppHandler: the view shows what the listener changed');

// 9. The press (and the focus) moving rebuilds the Computed subtrees it left or went to, not the other interactive ones
$pressBuilds = [0, 0, 0];
$hpress = new Headless(function (Context $c) use (&$pressBuilds) {
    Content::render(function () use (&$pressBuilds) {
        return VStack(spacing: 8)(
            function () use (&$pressBuilds) { $pressBuilds[0]++; return Button('Press A', action: function (): void {}); },
            function () use (&$pressBuilds) { $pressBuilds[1]++; return Button('Press B', action: function (): void {}); },
            function () use (&$pressBuilds) { $pressBuilds[2]++; return Button('Press C', action: function (): void {}); },
        );
    }, $c);
}, 300, 300);
$hpress->frame(2);
same([1, 1, 1], $pressBuilds, 'three interactive Computed subtrees built once');
[$px, $py] = $hpress->middle($hpress->byText('Press A'));
$hpress->input->mouse($px, $py);
$hpress->input->push(['down', $px, $py, 0, 1, 0]);
$hpress->input->push(['up', $px, $py, 0, 1, 0]);
$hpress->frame(1); // the click: its second pass builds every interactive subtree again
$afterClick = $pressBuilds;
$hpress->frame(2); // the press let go: only the subtree it was in shows it
check($pressBuilds[0] > $afterClick[0], 'the subtree the press left is built again');
same([$afterClick[1], $afterClick[2]], [$pressBuilds[1], $pressBuilds[2]], 'the other interactive subtrees stay as they were');

// 10. A stack whose value is the frame before's keeps its nodes, though the view's closure made it again (ViewKey)
$vk = new class { public int $tick = 0; public int $b = 0; public bool $red = false; };
$lines = fn(string $p, int $n) => array_map(fn(int $i) => Text("$p$i"), range(1, $n));
$hvk = new Headless(function (Context $c) use ($vk, $lines) {
    Content::render(fn() => VStack()(
        Text("Tick {$vk->tick}"),
        VStack()(...$lines('A', 10)),
        VStack()(Text("B is {$vk->b}"), ...$lines('B', 10)),
        VStack()(VStack()(...$lines('C', 10)))->foregroundStyle($vk->red ? \Color::red : \Color::blue),
        VStack()(Button('Go', action: function () use ($vk): void { $vk->b++; }), ...$lines('D', 10)),
    ), $c);
}, 400, 1200);
$hvk->frame(2);
[$a1, $b1, $c1, $d1] = [$hvk->byText('A1'), $hvk->byText('B1'), $hvk->byText('C1'), $hvk->byText('D1')];
$vk->tick++;
$hvk->frame(1);
check($hvk->byText('Tick 1') !== null, 'the value that changed shows');
check($hvk->byText('A1') === $a1 && $hvk->byText('B1') === $b1 && $hvk->byText('C1') === $c1, 'the stacks whose values are the same keep their nodes');
check($hvk->byText('D1') !== $d1, 'a stack holding a closure (a button\'s action) is built again');
$vk->b++;
$hvk->frame(1);
check($hvk->byText('B is 1') !== null && $hvk->byText('B1') !== $b1, 'a stack whose value changed is built again');
check($hvk->byText('A1') === $a1, 'the others stay kept');
$vk->red = true;
$hvk->frame(1);
check($hvk->byText('C1') !== $c1, 'a kept stack is built again when its environment changes (the color around it)');
$keyed = array_merge(array_keys((new \MyPHP\UI\Declarative\Env(\MyPHP\UI\Theme::light()))->memoKey()), \MyPHP\UI\Declarative\Env::NOT_IN_MEMO_KEY);
same(array_keys(get_class_vars(\MyPHP\UI\Declarative\Env::class)), array_values(array_intersect(array_keys(get_class_vars(\MyPHP\UI\Declarative\Env::class)), $keyed)), 'each property of Env is in its memo key or named out of it');
check(array_diff(\MyPHP\UI\Declarative\Env::NOT_IN_MEMO_KEY, array_keys(get_class_vars(\MyPHP\UI\Declarative\Env::class))) === [], 'the properties named out of the memo key are Env\'s');

// A view that builds a thousand nodes anew frame after frame is told how to keep its parts
$built = new class { public int $tick = 0; };
$many = array_map(fn(int $i) => "Row $i", range(1, \MyPHP\UI\Runtime::BUILT_MANY + 50));
$hbuilt = new Headless(function (Context $c) use ($built, $many) {
    Content::render(fn() => VStack()(Text("Tick {$built->tick}"), ...array_map(fn(string $s) => Text($s), $many)), $c); // the texts in the stack whose tick changes: none kept
}, 400, 300);
for ($i = 0; $i <= \MyPHP\UI\Runtime::BUILT_FRAMES; $i++) { $built->tick++; $hbuilt->frame(1); }
check(array_filter($hbuilt->rt->warnings, fn(string $w) => str_contains($w, 'closure children')) !== [], 'a view that builds all of itself each frame is told to split into closure children');
$kept = new class { public int $tick = 0; };
$hkept = new Headless(function (Context $c) use ($kept, $many) {
    Content::render(fn() => VStack()(Text("Tick {$kept->tick}"), VStack()(...array_map(fn(string $s) => Text($s), $many))), $c); // the texts in a stack of their own: kept
}, 400, 300);
for ($i = 0; $i <= \MyPHP\UI\Runtime::BUILT_FRAMES; $i++) { $kept->tick++; $hkept->frame(1); }
check(array_filter($hkept->rt->warnings, fn(string $w) => str_contains($w, 'closure children')) === [], 'a view whose parts are kept is not told');

// 6. Zero reference cycles across 50 frames with State, Observer and Computed
gc_collect_cycles();
gc_disable();
for ($i = 0; $i < 25; $i++) {
    $store->a++;
    $h->frame(2);
}
gc_enable();
same(0, gc_collect_cycles(), 'Reactive State, Observer and Computed leave 0 reference cycles');
