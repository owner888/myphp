<?php
// The examples ported from mygo, run without their windows as mygo's
// examples' main_test.go run them: each view built and clicked as a user
// would.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Node};

// counter-native: the buttons and the arrow keys count.
[$view, $s] = Headless::load('counter-native');
$t = new Headless($view, 360, 260);
$t->frame();
foreach (['Increment', 'Increment', 'Decrement'] as $l) { $t->clickLabel($l); $t->frame(); }
check($s->n === 1 && $t->byText('1') !== null, "count {$s->n} after the buttons");
$t->up(); $t->up(); $t->down(); $t->frame();
same(2, $s->n, 'the arrow keys count');
$t->clickText('Reset'); $t->frame();
same(0, $s->n, 'Reset');

// automation: what scripts/smoke-automation.php drives on a Mac, headless: the count, the field, the row whose Delete ignores input at rest.
[$view, $s] = Headless::load('automation');
$t = new Headless($view, 420, 400);
$t->frame();
$keys = $t->rt->automationTree()[1];
same(['add', 'name', 'row', 'delete'], array_values(array_intersect(['add', 'name', 'row', 'delete'], array_map('strval', $keys))), 'automation: the keys the smoke test finds');
$t->clickLabel('Add'); $t->frame();
same(1, $s->count, 'automation: Add counts');
[$dx, $dy] = $t->middle($t->byText('Delete'));
$t->click($dx, $dy); $t->frame();
same([1, 0], [$s->rowClicks, $s->deletes], "automation: a press on Delete at rest is the row's");
$t->hover($dx, $dy); $t->click($dx, $dy); $t->frame();
same([1, 1], [$s->rowClicks, $s->deletes], 'automation: shown, Delete takes it');

// timeline: the intro lasts 870 ms; its controls hold, play, turn and restart it.
[$view, $s] = Headless::load('timeline');
$t = new Headless($view, 520, 480);
$t->frame();
check(array_filter($t->texts(), fn($x) => str_ends_with($x, ' / 870 ms')) !== [], 'the intro lasts 870 ms: ' . json_encode($t->texts()));
$t->frame(30);
check($s->time > 0 && $t->byText('Plan') !== null, "the playhead moves as frames come: {$s->time}");
$t->clickText('Pause'); $t->frame(); check($s->paused, 'Pause holds the intro');
$at = $s->time; $t->frame(5); same($at, $s->time, 'paused, the playhead stays');
$t->clickText('Play'); $t->frame(); check(!$s->paused, 'Play goes on');
$t->clickText('Reverse'); $t->frame(); check($s->reverse, 'Reverse turns it around');
$t->clickText('Restart'); $t->frame(); check(!$s->reverse && $s->time < 0.1, 'Restart plays it again from the start');
$v = \MyPHP\Examples\Timeline\Intro::timeline()->value(time: 0.87);
check(abs($v['x']) < 1e-6 && abs($v['scale'] - 1) < 1e-6 && abs($v['Ship'] - 1) < 1e-6, 'at its end, the card is in place and every row shows: ' . json_encode($v));
$v = \MyPHP\Examples\Timeline\Intro::timeline()->value(time: 0.0);
check($v['x'] == -420 && $v['fade'] == 0, 'at its start, the card is off to the left');

// markdown-chat: a message sent, and without a window the reply whole.
[$view, $s] = Headless::load('markdown-chat');
$t = new Headless($view, 820, 720);
$t->frame();
$hint = $t->rect($t->byText('Ask anything: the replies are canned, streamed as a model streams them.'));
check(abs($hint[0] + $hint[2] / 2 - 410) < 1 && $hint[1] < 100, 'the hint at the top of the empty list, centered: ' . json_encode($hint));
$t->type('How do I cache?'); $t->frame();
$t->clickText('Send'); $t->frame(3);
check($t->byText('How do I cache?') !== null, 'the message shows');
check($t->byText('A few things to note:') !== null && $t->byText('Copy') !== null, 'the reply, with its code block\'s Copy: ' . json_encode(array_slice($t->texts(), 0, 20)));
$s->messages = [$s->messages[0]]; $s->messages[0]->done = true; $t->frame(2);
$mine = $t->rect($t->byText('How do I cache?'));
check($mine[1] > 500, 'rows short of the list at its end, as mygo\'s Justify(End): ' . json_encode($mine));

// clipboard: the note as data, and data received.
[$view, $s] = Headless::load('clipboard');
$t = new Headless($view, 640, 580);
$t->frame();
$d = $s->data();
same(["A portable note\nShared clipboard and drag representations.", 0], [$d->read(\MyPHP\Transfer\Format::TEXT), $s->providers], 'the note as text, its JSON not made');
$s->receive($d);
same([1, 'Received ' . \MyPHP\Examples\Clipboard\NOTE_FORMAT], [$s->providers, $s->status], 'received as JSON, made once asked for');
$s->receive(\MyPHP\Transfer\Data::text('plain'));
same('plain', $s->body, 'plain text becomes the body');

// text-input: the app's field takes typed text through the text services.
[$view, $s] = Headless::load('text-input');
$t = new Headless($view, 760, 240);
$t->frame();
$e = mb_strpos($s->text, '😀'); $u = intdiv(strlen(mb_convert_encoding(mb_substr($s->text, 0, $e), 'UTF-16LE', 'UTF-8')), 2);
[$cut] = $s->textForRange(new \MyPHP\UI\TextInputRange(0, $u + 1)); // half of the emoji's surrogate pair, as an input method may ask
check(mb_check_encoding($cut, 'UTF-8') && !str_contains($cut, '😀'), 'a range ending inside a surrogate pair: whole characters, UTF-8: ' . json_encode($cut));
$t->type('!'); $t->frame();
check(str_ends_with($s->text, 'עברית!'), 'typed at the end: ' . $s->text);
$s->replaceText(new \MyPHP\UI\TextInputRange(0, 4), 'Write');
check(str_starts_with($s->text, 'Write here'), 'a range replaced in UTF-16 units');
$t->key(Key::DELETE, 'deleteBackward:'); $t->frame();
check(str_starts_with($s->text, 'Writ here') || str_starts_with($s->text, 'Write here'), 'Delete');
$b = $s->boundsForRange(new \MyPHP\UI\TextInputRange(0, 1)); // what an input method asks for to place its window: a rectangle and the range it is of (TextInputClient)
check($b === null || (is_array($b[0]) && count($b[0]) === 4 && $b[1] instanceof \MyPHP\UI\TextInputRange), 'its bounds as the text services take them: ' . json_encode($b));

// text-buffer: the document and its counts.
[$view, $s] = Headless::load('text-buffer', 'App::setName', ['1000']);
$t = new Headless($view, 900, 650);
$t->frame();
same(1001, $s->lineCount(), 'a thousand lines and the empty last');
check($t->byText(sprintf('%d lines · %d runes · version %d', $s->lineCount(), $s->len(), $s->version())) !== null, 'the counts show');

// launcher: the ranking of names, the results of a query, Up and Down.
[$view, $s] = Headless::load('launcher');
same([0, 1, 2, 3, -1], [\MyPHP\Examples\Launcher\rank('safari', 'saf'), \MyPHP\Examples\Launcher\rank('visual studio code', 'stu'), \MyPHP\Examples\Launcher\rank('textedit', 'edit'), \MyPHP\Examples\Launcher\rank('visual studio code', 'vsc'), \MyPHP\Examples\Launcher\rank('mail', 'x')], 'names starting with it, a word starting with it, containing it, its letters in order');
$s->apps = [new \MyPHP\InstalledApp('Visual Studio Code', 'com.microsoft.VSCode', '/Applications/Visual Studio Code.app'), new \MyPHP\InstalledApp('Safari', 'com.apple.Safari', '/Applications/Safari.app'), new \MyPHP\InstalledApp('Script Editor', 'com.apple.ScriptEditor2', '/System/Applications/Utilities/Script Editor.app')];
$s->icons = array_fill_keys(array_map(fn($a) => $a->path, $s->apps), null); // no icons drawn here
$t = new Headless($view, 680, 420);
$t->frame();
$s->query = 's'; $s->filter(); $t->frame();
same(['Safari', 'Script Editor', 'Visual Studio Code'], array_map(fn($a) => $a->name, $s->results), 'what starts with s, then the rest that has it');
$t->down(); $t->frame();
same(1, $s->chosen(), 'Down chooses the next');
$s->query = ''; $s->filter(); $t->frame();
$q = $t->byLabel('Search apps');
same(22.0, $q->fontSize, 'the query in 22 points');
$t->clickOn($q); $t->type('saf'); $t->frame();
same(['Safari'], array_map(fn($a) => $a->name, $s->results), 'typing the query filters, as its field reports the change');
// The Mac's folders of apps, which Windows has not.
PHP_OS_FAMILY === 'Windows' || same(['System/Utilities', '~/Applications'], [\MyPHP\Examples\Launcher\where($s->apps[2]), \MyPHP\Examples\Launcher\where(new \MyPHP\InstalledApp('X', 'x', getenv('HOME') . '/Applications/X.app'))], 'where apps are');

// menubar-native: the note, its Clear and its status.
[$view, $s] = Headless::load('menubar-native');
$t = new Headless($view, 380, 340);
$t->frame();
check($t->byText('Kept here until you quit.') !== null, 'the status at first');
$s->text = 'milk'; $t->frame();
$t->clickText('Clear'); $t->frame(2);
check($s->text === '' && $s->editing, 'Clear empties the note and gives its editor the keyboard');

// menubar-flyout: the panel's switches.
[$view, $s] = Headless::load('menubar-flyout');
$t = new Headless($view, 280, 230);
$t->frame();
check($t->byText('Sessions of 25 minutes') !== null, 'the session length');
$switches = []; $walk = function (Node $n) use (&$walk, &$switches) { if ($n->kind === Node::SWITCH) { $switches[] = $n; } foreach ($n->kids as $k) { $walk($k); } }; $walk($t->root);
$t->clickOn($switches[0]); $t->frame(); // the switch labeled Focus mode, after the text that shows it: clickLabel() finds the first in the tree, as mygo's Tester.Click
check($s->focus, 'the switch turns focus mode on');

// flyout: the window's buttons, the list of colors chosen in its popover (in the window, as a test has no window to show one outside), the note typed.
[$view, $a] = Headless::load('flyout', 'App::whenReady');
$t = new Headless($view, 300, 240);
$t->frame();
check($t->byText('Red') !== null && $t->byText('No note') !== null, 'the color and the note at first');
$t->clickText('Red'); $t->frame();
check($a->choosing && $t->byText('Purple') !== null, 'the button shows the colors');
$t->clickText('Orange'); $t->frame();
check($a->color === 1 && !$a->choosing && $t->byText('Purple') === null, 'a color chosen, the popover closes');
$t->clickText('Edit note…'); $t->frame();
$t->clickLabel('Note'); $t->type('Milk'); $t->frame(2);
same('Milk', $a->note, 'the note typed in its popover');

// vibrancy: a material chosen in the sidebar, and the sidebar hidden and shown.
[$view, $s] = Headless::load('vibrancy');
$t = new Headless(function (Context $c) use ($view) { $c->titleBar = ['height' => 52.0, 'left' => 78.0, 'right' => 0.0]; $view($c); }, 880, 640); // its title bar hidden, the window controls inset
$t->frame();
$t->clickText('Popover'); $t->frame();
check($s->chosen === 'popover' && $t->byText("new WindowOptions(vibrancy: 'popover')  // or \$win->setVibrancy('popover')") !== null, "chose {$s->chosen}");
$t->clickLabel('Hide Sidebar'); $t->frame();
check($s->visibility === \NavigationSplitViewVisibility::detailOnly && $t->byLabel('Show Sidebar') !== null, 'Hide Sidebar hides it');
$t->clickLabel('Show Sidebar'); $t->frame();
check($s->visibility === \NavigationSplitViewVisibility::all, 'and Show Sidebar shows it');
$s->vibrant = false; $t->frame();
check($t->byText('This window shows no material here, so the sidebar draws a background of its own: materials show on macOS.') !== null, 'without a material, the pane says why');
gc_collect_cycles(); gc_disable(); $t->frame(10); $cycles = gc_collect_cycles(); gc_enable();
same(0, $cycles, 'its frames leave no cycles to collect: its sections, and their parsed icons, made once');

// embed-webview: the tabs in the sidebar, the page shown under the toolbar, the menu and the zoom; its web views stand-ins of no native side.
try { \MyPHP\UI\Image::symbol('globe', 16, 0); $symbols = true; } catch (\FFI\Exception) { $symbols = false; } // the system's symbols: on Linux, GTK's icon theme
if (!$symbols) { echo "  embed-webview skipped: the system's symbols need GTK\n"; } else {
[$view, $s] = Headless::load('embed-webview');
$stub = static function (): object { // a page of no native side: what each method returns empty
    $i = new \ReflectionClass(\MyPHP\Platform\Page::class);
    $body = '';
    foreach ($i->getMethods() as $m) {
        $ps = implode(', ', array_map(fn(\ReflectionParameter $p) => ($p->getType() ? $p->getType() . ' ' : '') . ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName() . ($p->isOptional() && !$p->isVariadic() ? ' = null' : ''), $m->getParameters()));
        $ps = preg_replace('/(^|, )([^?$&.][^ ]* )(\$\w+ = null)/', '$1?$2$3', $ps); // a default of null makes its type nullable
        $t = $m->getReturnType();
        $ret = $t === null ? '' : ': ' . $t;
        $v = match (true) { $t === null, (string)$t === 'void' => '', $t->allowsNull() => 'return null;', (string)$t === 'bool' => 'return false;', (string)$t === 'int' => 'return 0;', (string)$t === 'float' => 'return 0.0;', (string)$t === 'string' => "return '';", (string)$t === 'array' => 'return [];', default => 'throw new \LogicException();' };
        $body .= ($m->isStatic() ? 'public static' : 'public') . " function {$m->getName()}($ps)$ret { $v }\n";
    }
    return eval("return new class implements \\MyPHP\\Platform\\Page { $body };");
};
$web = static function () use ($stub): \MyPHP\WebView {
    $page = (new \ReflectionClass(\MyPHP\Page::class))->newInstanceWithoutConstructor();
    $native = $stub();
    (fn() => $this->web = $native)->call($page);
    $v = (new \ReflectionClass(\MyPHP\WebView::class))->newInstanceWithoutConstructor();
    (fn() => $this->page = $page)->call($v);
    return $v;
};
$s->tabs = [new \MyPHP\Examples\EmbedWebview\Tab('notes', 'Notes', $web()), new \MyPHP\Examples\EmbedWebview\Tab('web', 'example.com', $web())];
$t = new Headless(function (Context $c) use ($view) { $c->titleBar = ['height' => 52.0, 'left' => 78.0, 'right' => 0.0]; $view($c); }, 1000, 680);
$t->frame(); $t->paint();
check(count($t->rt->webPlaces) === 1 && $t->rt->webPlaces[0]['frame'][1] >= 52, 'the page shown, under the title bar: ' . json_encode($t->rt->webPlaces));
check($t->byLabel('Back') !== null && $t->byLabel('Reload') !== null && $t->byText('0 likes') !== null && $t->byText('♥ 0') !== null, 'the toolbar and the badge: ' . json_encode($t->texts()));
$t->clickText('example.com'); $t->frame();
check($s->tab === 'web' && $t->byText('example.com') !== null, "chose the tab {$s->tab}");
$s->alert = true; $t->frame(); // as the menu's Show an alert, a menu of the system's
check($t->byText('Alerts show over the page too') !== null, 'the alert shows');
$t->clickText('OK'); $t->frame();
same(false, $s->alert, 'OK closes it');
$s->dialog = true; $t->frame(2);
check($t->byText('A sheet over the page') !== null, 'the sheet shows');
$t->clickText('Done'); $t->frame(2); usleep(400_000); $t->frame(3); // it goes down first
check(!$s->dialog && $t->byText('A sheet over the page') === null, 'Done closes it');
\MyPHP\App::setFrontend(null);
}

// todo: the store, and its event in the TypeScript client.
Headless::load('todo', 'function openWindow');
$path = sys_get_temp_dir() . '/myphp-todo-' . getmypid() . '.json';
if (is_file($path)) { unlink($path); } // left by a run that stopped early, under a process id used again
\MyPHP\Examples\Todo\Todos::$changed = new \MyPHP\Event('todos:changed', \MyPHP\Examples\Todo\Stats::class);
$store = new \MyPHP\Examples\Todo\Todos($path);
$a = $store->add(' Milk '); $store->add('Bread');
$store->toggle($a->id);
same([['Bread', 'Milk'], ['Bread'], 2, 1], [array_map(fn($t) => $t->title, $store->list(\MyPHP\Examples\Todo\Filter::All)), array_map(fn($t) => $t->title, $store->list(\MyPHP\Examples\Todo\Filter::Active)), $store->stats()->total, $store->stats()->active], 'newest first, filtered, counted');
same(1, $store->clearDone(), 'the done one cleared');
same(['Bread'], array_map(fn($t) => $t->title, (new \MyPHP\Examples\Todo\Todos($path))->list(\MyPHP\Examples\Todo\Filter::All)), 'kept on disk');
try { $store->add('  '); check(false, 'an empty todo'); } catch (\InvalidArgumentException) { check(true, 'a todo needs a title'); }
unlink($path);
\MyPHP\Ipc::bind($store);
$ts = \MyPHP\TypeScript::generate();
check(str_contains($ts, '  todosChanged: event<Stats>("todos:changed"),'), 'the event\'s key is its name camel-cased, as mygo\'s');
check(str_contains($ts, 'export type Filter = "all" | "active" | "done";') && str_contains($ts, 'list(filter: Filter): Promise<Todo[]>'), 'the filter, an enum, is the union of its values');

// confetti: a demo throws its pieces, which head off in the next frame and go once they faded; the stage throws a burst of its own from the pointer.
[$view, $s] = Headless::load('confetti');
$t = new Headless($view, 980, 700);
$clock = 1_000_000_000_000;
$t->rt->clock = function () use (&$clock) { return $clock; };
$t->frame();
$t->clickText('Basic Cannon'); $t->frame(3);
check($s->inAir() === 100 && $t->byText('100 in the air') !== null, $s->inAir() . ' pieces after Basic Cannon');
check($s->bursts[0]->launched, 'the burst heads off');
$clock += (int)(200 / 60 * 1e9) + 1000;
$t->frame();
same(0, count($s->bursts), 'the bursts go once they faded');
[$x, $y] = $t->middle($t->byText('Click anywhere here'));
$t->click($x, $y + 100); $t->frame();
same(150, $s->inAir(), 'the stage throws its burst');
check(abs($s->bursts[0]->x - $x) <= 1 && abs($s->bursts[0]->y - $y - 100) <= 1, 'from the pointer');
// Pressing a demo again and again lets the oldest pieces go first: Realistic Look throws 50, 40, 70, 20 and 20 pieces.
$s = new \MyPHP\Examples\Confetti\Confetti();
$t = new Headless(Headless::view('confetti', $s), 980, 700);
$t->rt->clock = function () use (&$clock) { return $clock; }; // still: on the wall's clock, a slow run let the first bursts fade before the last were thrown
$t->frame();
for ($i = 0; $i < 8; $i++) { $t->clickText('Realistic Look'); $t->frame(); }
same([\MyPHP\Examples\Confetti\MAX_PIECES, 60, 10], [$s->inAir(), count($s->bursts[0]->pieces), $s->bursts[0]->pieces[0]->id], 'the oldest go first');

// effort-slider: the thumb stays under the pointer while it drags, choosing the nearest level, and eases to that level when let go; the arrow keys step; End goes Galaxy, whose effects come in and go when the slider leaves it.
[$view, $s] = Headless::load('effort-slider');
$t = new Headless($view, 472, 258);
$clock = 1_000_000_000_000;
$t->rt->clock = function () use (&$clock) { return $clock; };
$t->frame();
$at = fn(float $pos): array => [126 + 20 + 36 * $pos, 62 + 85.5];
$t->press(...$at(0));
$t->drag(...$at(1.3));
check(abs($s->thumb($clock)[0] - 1.3) < 0.01 && $s->level === 1, 'dragged to 1.3, the thumb is at ' . $s->thumb($clock)[0] . ", level $s->level");
$t->drag(...$at(2.7));
check(abs($s->thumb($clock)[0] - 2.7) < 0.01 && $s->level === 3, 'dragged to 2.7, level ' . $s->level);
$t->release(...$at(2.7));
[$pos, $moving] = $s->thumb($clock);
check(abs($pos - 2.7) < 0.01 && $moving, "let go at 2.7, the thumb is at $pos and moves");
$clock += 500_000_000;
same([3.0, false], $s->thumb($clock), 'after easing, the thumb is at level 3');
$t->right();
same(4, $s->level, 'the right arrow steps');
$t->home();
same(0, $s->level, 'Home goes to Low');
$t->end();
same([5, 0.0], [$s->level, $s->galaxyLevel($clock)], 'End goes Galaxy');
$list = $t->paint();
check($list->n > 0, 'the card paints, the label blurring');
$clock += 1_000_000_000;
$t->frame();
same(1.0, $s->galaxyLevel($clock), 'a second into Galaxy');
$t->left();
$clock += 250_000_000;
$t->frame();
same([4, 0.0], [$s->level, $s->galaxyLevel($clock)], 'after leaving Galaxy');

// farm (made without its sounds): the farmer tills, plants, waters, and harvests what grows; walks, and stops at the pond and the trees; a gamepad's stick walks as far as it leans; the view takes keys and walks the farmer while they are held.
Headless::load('farm');
$w = new \MyPHP\Examples\Farm\World();
$w->facing = \MyPHP\Examples\Farm\LEFT;
[$tx, $ty] = $w->target();
$tile = $w->tiles[$ty][$tx];
same([0, 0], [$tile->ground, $tile->object], 'the farmer starts facing grass');
foreach ([0, 1, 2] as $tool) { $w->tool = $tool; $w->use(); }
same([2, 0, true], [$tile->ground, $tile->crop, $tile->wet], 'after hoe, seeds and water');
$grow = function () use ($w) { for ($i = 0; $i < (int)(4.0 * 60) + 2; $i++) { $w->step(1 / 60); } };
$grow();
same([1, false], [$tile->crop, $tile->wet], 'watered, it grows a stage, then waits for water again');
while ($tile->crop < 3) { $w->use(); $grow(); }
$w->tool = 0; $w->use();
same([-1, 15], [$tile->crop, $w->coins], 'harvested');
same([1, 2, 3, 3, 3, 4], $w->events, 'till, plant, water thrice, harvest');
$w = new \MyPHP\Examples\Farm\World();
$w->held[3] = true;
for ($i = 0; $i < 5 * 60; $i++) { $w->step(1 / 60); }
check($w->facing === 3 && $w->x > 21 && $w->x <= 38, "walked right to $w->x");
$w->held = [false, true, false, false];
for ($i = 0; $i < 20 * 60; $i++) { $w->step(1 / 60); }
check($w->y >= 2 && $w->events !== [] && $w->events[0] === 0, "stopped by the trees north at $w->y, footsteps");
$w = new \MyPHP\Examples\Farm\World();
[$x0, $y0] = [$w->x, $w->y];
$w->stick = [-0.5, 0.2];
for ($i = 0; $i < 30; $i++) { $w->step(1 / 60); }
$d = $x0 - $w->x;
check($w->facing === 2 && $w->y > $y0 && $d > 0.5 * 4.5 * 0.5 * 0.9 && $d < 0.5 * 4.5 * 0.5 * 1.1, "the stick walked $d left");
$g = new \MyPHP\Examples\Farm\Farm();
$t = new Headless(Headless::view('farm', $g), 960, 640);
$t->paints = true;
$clock = 1_000_000_000_000;
$t->rt->clock = function () use (&$clock) { return $clock; };
$t->frame(1);
// Keys act in the game's next step.
$tap = function (string $ch, int $code) use ($t, &$clock) { $t->input->push(['key', $ch, $code, 0]); $t->input->push(['keyup', $ch, $code, 0]); $t->frame(1); $clock += 50_000_000; $t->frame(1); };
$tap('3', Key::N3);
same(2, $g->w->tool, 'key 3 chose the can');
$tap('=', Key::EQUAL);
same(3, $g->zoom, 'zoomed in');
$x = $g->w->x;
$t->input->push(['key', 'd', Key::D, 0]);
$clock += 200_000_000;
$t->frame(1);
check($g->w->x > $x && $g->w->facing === 3, 'D held walks right');
$list = $t->paint();
$tinted = 0;
for ($i = 0; $i < $list->n; $i++) { if (fmod($list->buf[$i * 16 + 9], 16) == 13 && $list->buf[$i * 16 + 8] == -1 - 0xffffff) { $tinted++; } }
check($list->n > 100 && $tinted > 100, "the farm paints, its sprites tinted by daylight: $tinted");

// mayoi (made without its sounds): notes calm what comes near and leave stones; levels offer upgrades and wait; upgrades bring the band; enemies hurt; they come from beyond the view and stay apart; the view starts, pauses, walks and waits without the keyboard.
Headless::load('mayoi');
$inPlay = function (): \MyPHP\Examples\Mayoi\World {
    $w = \MyPHP\Examples\Mayoi\World::seeded(1);
    $w->reset();
    $w->spawnTimer = $w->waveTimer = 1e9;
    return $w;
};
$steps = function (\MyPHP\Examples\Mayoi\World $w, float $sec) { for ($i = 0; $i < (int)($sec * 60); $i++) { $w->step(1 / 60); } };
$V = fn(float $x, float $y) => new \MyPHP\Examples\Mayoi\Vec($x, $y);
$w = $inPlay();
$w->add(0, $V(60, 0));
$steps($w, 2);
same([1, 0, 1], [$w->calmed, count($w->enemies), count($w->stones)], 'Tomori sings at the static, calms it, a stone left');
$w->move = $V(1, 0);
$steps($w, 1);
same([1, 1, 0], [$w->xp, $w->collected, count($w->stones)], 'she picks up the stone');
$w = $inPlay();
$w->stones[] = new \MyPHP\Examples\Mayoi\Stone($V(0, -4), 5);
$w->step(1 / 60);
same([2, 2, 3], [$w->state, $w->level, count($w->choices)], 'a level offers three upgrades');
$before = $w->time;
$steps($w, 1);
same($before, $w->time, 'the world waits while choosing');
check(array_filter($w->choices, fn($u) => \MyPHP\Examples\Mayoi\UPGRADES[$u]['member'] >= 0) !== [], 'a bandmate among the choices');
$u = $w->choices[0];
$w->choose(0);
same([1, 1], [$w->state, $w->levels[$u]], 'chosen');
$w = $inPlay();
foreach ([2, 4, 5, 8, 2] as $u) { $w->state = 2; $w->choices = [$u]; $w->choose(0); }
same([3, 1, 6, 6], [$w->notes, $w->levels[4], $w->hp, $w->maxHP], 'harmony twice, picks, band-aid');
same([1, 3, 4], $w->band, 'Anon, Rāna and Taki joined, once each');
$w->move = $V(1, 0);
$steps($w, 2);
$f = $w->follower(0);
check($f->x < $w->pos->x && $f->x >= $w->pos->x - 30, "Anon follows at $f->x, Tomori at {$w->pos->x}");
$w->move = $V(0, 0);
$w->beatTimer = 0.5; $w->damage = 0;
$w->add(0, $w->pos->add($V(30, -5)));
$w->add(1, $w->pos->add($V(-30, 20)));
$steps($w, 3);
same(2, $w->calmed, 'the picks and the beat calm what comes near');
$w = $inPlay();
$w->damage = 0;
$w->add(2, $V(3, 0));
$steps($w, 0.1);
check($w->hp === 4 && $w->hurt > 0, "hurt: hp $w->hp");
$w->hp = 1;
$steps($w, 3);
same(3, $w->state, 'without band-aids, the game is over');
$w = \MyPHP\Examples\Mayoi\World::seeded(2);
$w->reset(); $w->damage = 0; $w->hurt = 1e9;
$w->spawn(30);
check(count($w->enemies) >= 30, count($w->enemies) . ' enemies after 30 s');
check(array_filter($w->enemies, fn($e) => abs($e->pos->x - $w->pos->x) < $w->view->x && abs($e->pos->y - $w->pos->y) < $w->view->y) === [], 'none within the view');
$steps($w, 5);
$apart = true;
foreach ($w->enemies as $i => $a) { foreach (array_slice($w->enemies, $i + 1) as $b) { if ($a->pos->sub($b->pos)->len() < 2) { $apart = false; } } }
check($apart, 'the enemies stay apart');
$g = new \MyPHP\Examples\Mayoi\Mayoi();
$t = new Headless(Headless::view('mayoi', $g), 960, 640);
$t->paints = true;
$clock = 1_000_000_000_000;
$t->rt->clock = function () use (&$clock) { return $clock; };
$t->frame(1);
$tap = function (string $ch, int $code) use ($t, &$clock) { $t->input->push(['key', $ch, $code, 0]); $t->input->push(['keyup', $ch, $code, 0]); $t->frame(1); $clock += 50_000_000; $t->frame(1); };
$tap(' ', Key::SPACE);
check($g->w->state === 1 && $g->sounding, 'Space starts, the music plays');
$tap("\e", Key::ESCAPE);
check($g->paused && !$g->sounding, 'Esc pauses, the music too');
$tap("\e", Key::ESCAPE);
check(!$g->paused && $g->sounding, 'Esc again plays');
$x = $g->w->pos->x;
$t->input->push(['key', 'd', Key::D, 0]);
$clock += 200_000_000;
$t->frame(1);
check($g->w->pos->x > $x && !$g->w->facingLeft, 'D held walks right');
$t->input->push(['keyup', 'd', Key::D, 0]);
check($t->paint()->n > 100, 'the street paints');
$t->rt->windowFocus = false;
$clock += 50_000_000; $t->frame(1);
$before = $g->w->time;
$clock += 50_000_000; $t->frame(1);
check($g->w->time == $before && $g->frozen && !$g->sounding, 'without the keyboard, the world waits');

// gallery: the sidebar and the toolbar navigate the router, and the sidebar's choice follows the history; a row's star and the breadcrumb; the list's choice derived from the filter; the counter; the Languages page in English and in Chinese.
Headless::load('gallery');
$G = function (string $path = '/overview'): object { $g = new \MyPHP\Examples\Gallery\Gallery($path); $g->router->transition = \MyPHP\UI\Router::NONE; return $g; };
$g = $G();
$t = new Headless(Headless::view('gallery', $g), 1100, 800);
$t->frame();
$at = function (string $want) use ($g, $t): bool { $t->frame(); return $g->router->path() === $want; };
$ok = true;
foreach (\MyPHP\Examples\Gallery\PAGES as $p) {
    $t->clickOn($t->byLabel($p) ?? $t->byText($p)); $t->frame();
    $ok = $ok && $at(\MyPHP\Examples\Gallery\pagePath($p));
}
check($ok, 'the sidebar goes to every page, at ' . $g->router->path());
$t->clickOn($t->byLabel('Languages')); $t->frame(); // the current page: no history entry
$t->clickLabel('Back'); $t->frame();
check($at('/motion'), 'Back goes to the page before');
$t->clickLabel('Forward'); $t->frame();
check($at('/languages'), 'and Forward again');
$t->clickOn($t->byLabel('Text')); $t->frame();
$t->down(); $t->frame();
check($at('/rich-text'), 'Down in the sidebar chooses the next page');
$g->router->back(); $t->frame(2);
check($at('/text'), 'the history moves the sidebar\'s choice');
$t->down(); $t->frame();
check($at('/rich-text'), 'from which Down goes on');
$g->router->push('/list/42'); $t->frame(2);
$t->clickOn($t->byLabel('List')); $t->frame();
$t->down(); $t->frame();
check($at('/charts'), 'a row of the list is the List page\'s, in the sidebar');

$g = $G('/list/42');
$t = new Headless(Headless::view('gallery', $g), 1100, 800);
$t->frame();
foreach ([true, false] as $want) {
    $t->clickText('Starred'); $t->frame();
    same($want, $g->starred[42] ?? false, 'the row\'s star');
}
$t->clickOn($t->byText('List', $t->byLabel('Path'))); $t->frame(); // the breadcrumb's, not the sidebar's
same('/list', $g->router->path(), 'the breadcrumb goes back to the list');

$g = $G('/list');
$t = new Headless(Headless::view('gallery', $g), 1100, 900);
$t->frame(2);
$t->clickText('Row 2'); $t->frame();
same(2, $g->picked, 'a click picks a row');
$t->down(); $t->frame();
same(3, $g->picked, 'Down picks the next');
$t->clickOn($t->byLabel('Filter')); $t->type('99'); $t->frame(2);
$t->clickText('Row 99'); $t->frame();
same(99, $g->picked, 'a row of the filtered list');
$t->enter(); $t->frame();
same('/list/99', $g->router->path(), 'Enter opens it');

$g = $G();
$t = new Headless(Headless::view('gallery', $g), 1100, 800);
$t->frame();
$ok = true;
foreach ([['Increment', '1'], ['Increment', '2'], ['−', '1']] as [$click, $want]) { $t->clickText($click); $t->frame(); $ok = $ok && $t->byText($want) !== null; }
check($ok && $g->count === 1, 'the counter counts');

$g = $G('/languages');
$g->now = new \DateTimeImmutable('2026-01-05 13:07:00', new \DateTimeZone('UTC'));
$g->meeting = new \DateTimeImmutable('2026-01-05 09:30:00', new \DateTimeZone('UTC'));
$saved = \MyPHP\App::locale(); // without an app, the zero region: the formats are the language's, and Chinese's 24 hours show no AM/PM segment
\MyPHP\App::setLanguages(...\MyPHP\Examples\Gallery\GALLERY_LANGUAGES); // as the gallery declares them
$t = new Headless(Headless::view('gallery', $g), 1100, 2400);
$t->frame();
$missing = array_filter(['1,234,567.891', 'Monday, January 5, 2026', '1:07 PM', 'January 2026', '简体中文 (zh-Hans)', 'Pseudo right to left (ar-XB)', '12,34,567.891'], fn($s) => $t->byText($s) === null);
same([], array_values($missing), 'the Languages page in English');
\MyPHP\App::setLocale('zh-Hans'); $t->frame(2);
$missing = array_filter(['2026年1月5日星期一', '2026年1月', '上个月'], fn($s) => $t->byText($s) === null && $t->byLabel($s) === null);
same([], array_values($missing), 'and in Chinese');
\MyPHP\App::setLocale($saved);
\MyPHP\App::setLanguages('en');

// notes (mygo's TestNotes), laid out as an app of its own (src/, tests/): its own test passes. It types into a note,
// searches, saves, reads back and deletes.
exec(escapeshellarg(PHP_BINARY) . ' -d ffi.enable=1 ' . escapeshellarg(__DIR__ . '/../examples/notes/tests/ui_test.php') . ' 2>&1', $lines, $code);
check($code === 0, "notes: its own test passes:\n" . implode("\n", $lines));

// inbox: mailboxes with a badge, rows chosen by a click and Cmd-click, opened by a double click, the End button scrolling.
try { \MyPHP\UI\Image::symbol('doc', 16, 0); $symbols = true; } catch (\FFI\Exception) { $symbols = false; } // the system's symbols: on Linux, GTK's icon theme
if (!$symbols) { echo "  inbox skipped: the system's symbols need GTK\n"; } else {
[, $s] = Headless::load('inbox', '// Avatars:', ['1000']); // its avatars are macOS's app icons
$s->avatars = [\MyPHP\UI\Image::fromPixels(4, 4, str_repeat("\x80\x80\x80\xff", 16))];
$t = new Headless(Headless::view('inbox', $s), 900, 640);
$t->frame(2);
check($t->byText('1,000') !== null && $t->byText('Sender 0') !== null, 'the badge and the first rows: ' . json_encode(array_slice($t->texts(), 0, 30)));
$t->clickOn($t->byText('Sender 2')); $t->frame(2);
same([[2], 'chose row 2'], [$s->chosen, $s->last], 'a click chooses a row');
$t->clickOn($t->byText('Sender 4'), Key::COMMAND); $t->frame(2);
same([2, 4], (sort($s->chosen) ? $s->chosen : []), 'Cmd-click adds one');
$t->clickOn($t->byText('Sender 4')); $t->frame(2); $t->clickOn($t->byText('Sender 4'), 0, 2); $t->frame(2);
same('opened row 4', $s->last, 'a double click is the primary action');
$s->chosen = [7]; $t->frame(2);
$t->key(Key::RETURN, 'insertNewline:'); $t->frame(2);
same('opened row 7', $s->last, 'the choice the app set is the list\'s, Return its primary action');
[$x, $y] = $t->middle($t->byText('Sender 9')); $t->rightClick($x, $y); $t->frame();
same(['Reply'], $t->menuLabels(), 'a right click on a row not chosen: its menu');
check($t->chooseMenuItem('Reply'), 'chosen'); $t->frame(2);
same([true, 'replied to 9'], [isset($s->replied[9]), $s->last], 'the menu acts on that row');
$t->clickText('End'); $t->frame(40);
check($t->byText('Sender 999') !== null, 'End scrolls to the last row');
$t->clickText('Flagged'); $t->frame(2);
same(['flagged', 'mailbox flagged'], [$s->mailbox, $s->last], 'the sidebar chooses a mailbox');
}

// transfer: the wells take text and files dropped from other apps, lit while one is over them.
[$view, $s] = Headless::load('transfer');
$t = new Headless($view, 600, 560);
$t->frame(2);
check($t->byText('Drop here') !== null && $t->byText('Write the spec') !== null && $t->byText('Ship v0.78') !== null, 'the note, the wells and the cards: ' . json_encode($t->texts()));
[$x, $y] = $t->middle($t->byText('Drop here'));
$text = \MyPHP\Transfer\Data::text('dropped words');
$d = new \MyPHP\UI\DataDragEvent(new \MyPHP\Transfer\Offer($text->formats(), \MyPHP\Transfer\Operation::COPY));
check($t->dataOver($x, $y, $d), 'the text well takes text'); $t->frame(2);
check($s->over === 'text' && $t->byText('Drop to take it') !== null, 'and is lit');
$d->data = $text;
check($t->dataDrop($x, $y, $d), 'its drop'); $t->frame(2);
same(['dropped words', ''], [$s->dropped, $s->over], 'what was dropped shows, the well no longer lit');
[$x, $y] = $t->middle($t->byText('Drop files from the Finder'));
$files = \MyPHP\Transfer\Data::files(__FILE__);
$d = new \MyPHP\UI\DataDragEvent(new \MyPHP\Transfer\Offer($files->formats(), \MyPHP\Transfer\Operation::COPY));
check($t->dataOver($x, $y, $d), 'the files well takes files'); $d->data = $files;
check($t->dataDrop($x, $y, $d), 'their drop'); $t->frame(2);
check($t->byText(basename(__FILE__)) !== null, 'the file dropped shows');

// richtext: the toolbar formats the paragraph the caret is in, the editor keeping the focus.
[$view, $s] = Headless::load('richtext');
$t = new Headless($view, 820, 640);
$t->frame(2);
check($t->byText('Body') !== null && $t->byText('Undo') !== null, 'the toolbar: ' . json_encode(array_slice($t->texts(), 0, 30)));
$was = $s->formatting()->paragraph->heading;
$t->clickText($was === 3 ? 'H2' : 'H3'); $t->frame(2);
same($was === 3 ? 2 : 3, $s->formatting()->paragraph->heading, 'a heading button sets the paragraph\'s');
$t->clickText('Undo'); $t->frame(2);
same($was, $s->formatting()->paragraph->heading, 'Undo takes it back');

// logview: a log's lines in a table from its end, the filter, the find bar choosing each line holding the query.
[$view, $s] = Headless::load('logview');
$log = tempnam(sys_get_temp_dir(), 'log');
file_put_contents($log, implode("\n", array_map(fn(int $i) => $i % 50 === 7 ? "line $i ERROR disk full" : ($i % 30 === 3 ? "line $i WARN slow" : "line $i ok"), range(0, 499))) . "\n");
$s->open($log);
$t = new Headless($view, 960, 600);
$t->frame(3);
check($t->byText('line 499 ok') !== null && $t->byText('line 0 ok') === null, 'from its end: ' . json_encode(array_slice($t->texts(), -6)));
same([500, 10, 17], [count($s->lines), $s->counts['ERROR'], $s->counts['WARN']], 'the lines and the levels counted');
$t->clickOn($t->byLabel('Filter lines (type to search)')); $t->type('ERROR'); $t->frame(3);
same(10, count($s->shown), 'the filter leaves the errors');
check($t->byText('line 457 ') !== null || in_array('line 457 ', $t->texts(), true) || str_contains(json_encode($t->texts()), 'line 457'), 'and shows them: ' . json_encode(array_slice($t->texts(), -8)));
$t->key(ord('f'), 'f', Key::COMMAND, 'f'); $t->frame(2);
check($s->finding, 'Cmd+F opens the find bar');
$t->type('disk'); $t->frame(3);
same([0, 7], [$s->found, $s->chosen], 'the first line holding it chosen');
$t->key(Key::RETURN, 'insertNewline:'); $t->frame(3);
same([1, 57], [$s->found, $s->chosen], 'Return goes to the next');
@unlink($log);

// http: the history a sortable table, the request chosen showing its response, JSON colored line by line.
[$view, $s] = Headless::load('http');
$mk = function (int $id, string $url, ?int $status, float $time) {
    $r = ['id' => $id, 'method' => 'GET', 'url' => $url, 'status' => $status, 'time' => $time, 'headers' => "HTTP/1.1 $status OK\r\ncontent-type: application/json", 'body' => '{"a":1,"b":"x"}', 'error' => '', 'at' => 0, 'lines' => []];
    $r['lines'] = \MyPHP\Examples\Http\lines($r);
    return $r;
};
$s->history = [$mk(1, 'https://b.example/one', 200, 0.3), $mk(2, 'https://a.example/two', 404, 0.1)];
$t = new Headless($view, 1000, 680);
$t->frame(2);
check($t->byText('Send a request (Cmd+Enter); responses show here.') !== null && $t->byText('b.example/one') !== null, 'the history, nothing chosen: ' . json_encode($t->texts()));
$t->clickText('a.example/two'); $t->frame(2);
same(2, $s->chosen, 'a click chooses a request by its id');
check($t->byText('404') !== null && $t->byText('    "b": "x"') !== null, 'its response, the JSON pretty-printed: ' . json_encode(array_slice($t->texts(), -16)));
$t->clickText('URL'); $t->frame(2);
same(['url', ['a.example/two', 'b.example/one'], 2], [$s->sortOrder?->column, array_map(fn($r) => preg_replace('#^https?://#', '', $r['url']), $s->history), $s->chosen], 'the URL header sorts by it, the choice kept');

// files: a folder's entries as icons and as a sortable table, chosen, opened, found.
$dir = sys_get_temp_dir() . '/myphp-files-' . getmypid();
@mkdir("$dir/sub/inner", 0700, true);
file_put_contents("$dir/b.txt", str_repeat('x', 3000)); file_put_contents("$dir/a.txt", 'a');
[, $s] = Headless::load('files', '$files = new Files');
$s = new \MyPHP\Examples\Files\Files($dir);
$t = new Headless(Headless::view('files', $s), 960, 640);
$t->frame(2);
check($t->byText('a.txt') !== null && $t->byText('sub') !== null && $t->byText('3 items') !== null, 'the icons and the count: ' . json_encode($t->texts()));
$t->clickText('b.txt'); $t->frame(2);
same([realpath($dir) . '/b.txt'], $s->chosen, 'a click chooses');
check($t->byText('1 of 3 chosen') !== null, 'the status says so');
$t->clickText('List'); $t->frame(2);
$t->clickText('Size'); $t->frame(2);
same(['sub', 'a.txt', 'b.txt'], array_column($s->entries, 'name'), 'the Size header sorts, folders first');
$t->clickOn($t->byText('sub')); $t->frame(2); $t->clickOn($t->byText('sub'), 0, 2); $t->frame(2);
same(realpath($dir) . DIRECTORY_SEPARATOR . 'sub', $s->path, 'a double click opens a folder');
$t->key(Key::DELETE, 'deleteBackward:'); $t->frame(2);
same(realpath($dir), $s->path, 'Backspace goes up');
$t->key(ord('f'), 'f', Key::COMMAND, 'f'); $t->type('a.'); $t->frame(3);
same(['a.txt'], array_column($s->entries, 'name'), 'the find bar filters the entries');
@unlink("$dir/a.txt"); @unlink("$dir/b.txt"); @rmdir("$dir/sub/inner"); @rmdir("$dir/sub"); @rmdir($dir);

// markdown-notes: notes in a list the search filters, one opened as it is chosen, the preview following the editor's scroll.
$dir = sys_get_temp_dir() . '/myphp-mdn-' . getmypid(); @mkdir($dir);
file_put_contents("$dir/a.md", "# Alpha\n\n" . str_repeat("line\n\n", 80)); touch("$dir/a.md", time() - 100);
file_put_contents("$dir/b.md", "# Beta\n\nhello **world**\n");
Headless::load('markdown-notes', '$confirmDelete');
$s = new \MyPHP\Examples\MarkdownNotes\Notes($dir);
$s->choose($s->items[0]['path']);
$t = new Headless(Headless::view('markdown-notes', $s), 1100, 680);
$t->paints = true; // the editor's scroll is the painter's
$t->frame(3);
check($t->byText('hello world') !== null && $t->byText('saved') !== null, 'the newest note, previewed: ' . json_encode($t->texts()));
$t->clickText('Alpha'); $t->frame(3);
same(['a.md', '# Alpha'], [basename((string)$s->open), substr($s->text, 0, 7)], 'a note chosen opens');
$editor = null; $preview = null;
$walk = function (Node $n) use (&$walk, &$editor, &$preview) { if ($n->kind === Node::TEXTAREA) { $editor = $n; } if ($n->scroll instanceof \MyPHP\UI\ScrollState && !$n->scroll instanceof \MyPHP\UI\ListState) { $preview = $n; } foreach ($n->kids as $k) { $walk($k); } };
$walk($t->root); $walk = null;
[$x, $y] = $t->middle($editor);
for ($i = 0; $i < 10; $i++) { $t->wheel($x, $y, 0, -300); }
$t->frame(5);
check($editor->edit->scrollFraction > 0.9 && $preview->scroll->y > 0.9 * $s->previewRange && $s->previewRange > 0, 'the preview follows the editor to its end: ' . json_encode([$editor->edit->scrollFraction, $preview->scroll->y, $s->previewRange]));
$t->clickOn($t->byLabel('Search')); $t->type('bet'); $t->frame(3);
same(['Beta'], array_column($s->shown(), 'title'), 'the search filters the notes');
array_map('unlink', glob("$dir/*.md")); @rmdir($dir);

// code-editor: the files in a sidebar, the editor's status, TODOs as warnings as they are typed, the changes page.
[$view, $s] = Headless::load('code-editor', 'App::setMenu');
$t = new Headless($view, 1200, 800);
$t->frame(3);
check($t->byText('server.go') !== null && $t->byText('Ln 1, Col 1') !== null && $t->byText('+0 −0') !== null, 'the files and the status: ' . json_encode($t->texts()));
$before = count($s->current()->editor->diagnostics());
$t->type('// TODO x'); $t->frame(3);
same([$before + 1, 'Ln 1, Col 10'], [count($s->current()->editor->diagnostics()), $t->texts()[array_search('+1 −1', $t->texts(), true) - 1] ?? null], 'a TODO typed is a warning; the caret moved, a line changed');
check($t->byText('Greeter.php •') !== null, 'the file marked modified');
$t->clickText('Changes'); $t->frame(3);
check($t->byText('Unified') !== null && $t->byText('No changes') === null, 'its changes, as a diff');
$t->clickText('server.go'); $t->frame(3);
check($t->byText('No changes') !== null, 'another file, unchanged');

// photos: a folder's photos in a grid, chosen through a Binding of the index, one shown with Space, the arrows, Escape back.
$dir = sys_get_temp_dir() . '/myphp-photos-' . getmypid(); @mkdir($dir);
foreach (['a', 'b', 'c'] as $n) { touch("$dir/$n.png"); }
Headless::load('photos', '$p = new Photos');
$p = new \MyPHP\Examples\Photos\Photos(); $p->open($dir);
foreach ($p->files as $f) { $p->thumbs[$f] = $p->full[$f] = \MyPHP\UI\Image::fromPixels(4, 3, str_repeat("\x80\x80\x80\xff", 12)); }
$p->loading = array_fill_keys($p->files, true); // none decoded here: CoreImage's
$t = new Headless(Headless::view('photos', $p), 900, 640);
$t->frame(3);
$cell = null; $walk = function (Node $n) use (&$walk, &$cell) { if ($n->tooltip === 'b.png') { $cell = $n; } foreach ($n->kids as $k) { $walk($k); } }; $walk($t->root); $walk = null;
$t->clickOn($cell); $t->frame(2);
same(1, $p->selected, 'a click chooses, through the binding of the index');
$t->key(Key::SPACE, ' ', 0, ' '); $t->frame(2);
check($p->viewing && $t->byText('2 / 3 · 0 KB') !== null, 'Space shows it: ' . json_encode($t->texts()));
$t->key(Key::RIGHT, 'moveRight:'); $t->frame(2);
check($t->byText('c.png') !== null, 'Right goes to the next');
$t->key(Key::ESCAPE, 'cancelOperation:'); $t->frame(2);
same([false, 2], [$p->viewing, $p->selected], 'Escape back to the grid, on it');
array_map('unlink', glob("$dir/*.png")); @rmdir($dir);

// shaders: every frame, the waves, the rainbow, the frost drawn by their shaders in Canvases, and the wavy text through its DistortionEffect.
[$view, $s] = Headless::load('shaders', 'App::setName');
$t = new Headless($view, 720, 900);
$t->frame(2);
$list = $t->paint();
$codes = array_map(fn(array $d) => $d[0], $list->shaders);
check(in_array($s->waves->code, $codes, true) && in_array($s->rainbow->code, $codes, true) && in_array($s->frost->code, $codes, true), 'shaders: the waves, the rainbow and the frost drawn: ' . count($codes));
check($t->byText('Wavy text')?->effect !== null, 'shaders: the wavy text through its effect');

// drag (DragGesture, withAnimation, matchedGeometryEffect): the card dragged stays where it is let go; the puck thrown rests at a stop; a word dropped in the basket.
[$view, $s] = Headless::load('drag', 'App::setName');
$t = new Headless($view, 640, 720);
$t->frame(2);
[$x, $y] = $t->middle($t->byText('Drag me'));
$t->press($x, $y); $t->drag($x + 20, $y + 10); $t->drag($x + 40, $y + 30); $t->frame();
check($s->holding && $s->card->width == 40 && $s->card->height == 30 && $t->byText('Dragging…') !== null, 'the card follows the pointer: ' . json_encode([$s->card, $s->holding]));
$t->release($x + 40, $y + 30); $t->frame();
check(!$s->holding && $s->cardX == 40 && $s->cardY == 30, 'let go, it stays there: ' . json_encode([$s->cardX, $s->cardY]));
$find = function (\MyPHP\UI\Node $n) use (&$find, $t): ?\MyPHP\UI\Node { if (abs($n->w - 48) < 0.5 && abs($n->h - 48) < 0.5) { return $n; } foreach ($n->kids as $k) { if (($f = $find($k)) !== null) { return $f; } } return null; };
$puck = $find($t->root);
check($puck !== null, 'the puck');
[$px, $py] = $t->middle($puck);
$t->press($px, $py); $t->drag($px + 60, $py); $t->frame();
same(60.0, $s->puckDrag, 'the puck follows the pointer along its track');
$t->release($px + 60, $py); $t->frame(30);
check(in_array($s->puck, [0.0, 206.0, 412.0], true) && $s->puckDrag == 0, "let go, it rests at a stop: {$s->puck}");
[$wx, $wy] = $t->middle($t->byText('pear'));
[$bx, $by] = $t->middle($t->byText('Drop words here'));
$t->hover($wx, $wy); $t->press($wx, $wy); $t->drag($wx + 20, $wy); // a native drag, as the system carries it: the word to this app's targets
same('pear', $t->dataDrag['local'] ?? null, 'the chip drags its word');
$d = new \MyPHP\UI\DataDragEvent(new \MyPHP\Transfer\Offer([\MyPHP\Transfer\Format::TEXT], \MyPHP\Transfer\Operation::COPY));
$d->local = 'pear';
check($t->dataOver($bx, $by, $d) && $s->targeted, 'over the basket, it lights up');
$t->dataDrop($bx, $by, $d); $t->finishDataDrag(new \MyPHP\Transfer\Result($d->operation)); $t->release($bx, $by); $t->frame(3);
check(in_array('pear', $s->basket, true) && !in_array('pear', $s->words, true), 'a word dropped in the basket: ' . json_encode([$s->words, $s->basket]));
$t->clickText('pear'); $t->frame(3);
check(in_array('pear', $s->words, true), 'a click takes it back');

// drag-drop: a note dropped on the other window's zone joins its notes; a URL is reported.
[, $s] = Headless::load('drag-drop');
$right = $s->panes[1];
$t = new Headless(Headless::view('drag-drop', $right), 440, 580);
$t->frame();
check($t->byText('A note from Right') !== null && $t->byText('Drop here') !== null, 'the pane, its note and its zone');
[$x, $y] = $t->middle($t->byText('Drop here'));
$note = new \MyPHP\Transfer\Data(new \MyPHP\Transfer\Item(
    \MyPHP\Transfer\Representation::bytes(\MyPHP\Transfer\Format::TEXT, 'A note from Left'),
    \MyPHP\Transfer\Representation::bytes('application/vnd.myphp.example-note+json', '{"ID":1,"Title":"A note from Left"}')));
$d = new \MyPHP\UI\DataDragEvent(new \MyPHP\Transfer\Offer($note->formats(), \MyPHP\Transfer\Operation::COPY | \MyPHP\Transfer\Operation::MOVE, \MyPHP\Transfer\Operation::MOVE));
check($t->dataOver($x, $y, $d), 'the zone takes a note');
$t->frame();
check($right->targeted, 'isTargeted as a note is over the zone');
$d->data = $note;
check($t->dataDrop($x, $y, $d), 'and its drop');
$t->frame();
same(['A note from Right', 'A note from Left'], array_map(fn($n) => $n->title, $right->notes), 'the note joins the pane, decoded from its JSON');
same([3, 'Received note: A note from Left'], [$right->notes[1]->id, $right->status], 'with a new id');
$url = \MyPHP\Transfer\Data::url('https://example.com/');
[$x, $y] = $t->middle($t->byText('Drop here')); // below the note now
$d = new \MyPHP\UI\DataDragEvent(new \MyPHP\Transfer\Offer($url->formats(), \MyPHP\Transfer\Operation::COPY));
check($t->dataOver($x, $y, $d), 'the zone takes a URL'); $d->data = $url;
check($t->dataDrop($x, $y, $d), 'and its drop'); $t->frame();
same('Received URLs: https://example.com/', $right->status, 'a URL dropped is reported');

// video: a player's window; a file the system cannot read says why (playing is tests/player_test.php's, on macOS).
Headless::load('video');
$s = new \MyPHP\UI\MediaPlayerState();
$t = new Headless(Headless::view('video', $s), 900, 600);
$t->frame();
$s->open(__DIR__ . '/testdata/upright.jpg');
$t->frame();
check($s->player === null && $s->error !== '' && $t->byText($s->error) !== null, "a file it cannot play shows why: {$s->error}");

// The video example's controls over a picture that pauses when clicked: the controls take their clicks (the picture asks after them, and the bar for the end of a drag of its slider), and a press on them leaves the keyboard with the window's shortcuts.
$pc = ['plays' => 0, 'pictures' => 0, 'spaces' => 0, 'lefts' => 0, 'track' => 0, 'at' => 0.5, 'slider' => null];
$t = new Headless(function (Context $c) use (&$pc) {
    if ($c->shortcut(Key::SPACE)) { $pc['spaces']++; }
    if ($c->shortcut(Key::LEFT)) { $pc['lefts']++; }
    $c->column(0, 0, 1, 0x000000);
      $picture = $c->column()->grow(1);
      $c->end();
      $bar = $c->row(10, 10)->absolute()->left(16)->bottom(16)->keepFocus();
        if ($c->clicked($c->button('Play'))) { $pc['plays']++; }
        $pc['slider'] = $c->slider($pc['at'], 0, 1, 200);
        $c->select(['国语', '潮汕话'], $pc['track'], 150);
      $c->end();
      $c->clicked($bar);
      if ($c->clicked($picture)) { $pc['pictures']++; }
    $c->end();
}, 900, 600);
$t->frame();
$t->clickText('Play');
$r = $t->rt->rectOf($pc['slider']->id);
$t->press($r[0] + 10, $r[1] + $r[3] / 2); $t->drag($r[0] + 150, $r[1] + $r[3] / 2); $t->release($r[0] + 150, $r[1] + $r[3] / 2); $t->frame();
$t->clickText('国语'); $t->clickText('潮汕话');
$t->key(Key::SPACE, 'insertText:', 0, ' '); $t->left();
same([1, 0, 1, 1, 1], [$pc['plays'], $pc['pictures'], $pc['track'], $pc['spaces'], $pc['lefts']], 'the button, the slider and the select take their clicks, not the picture under them; Space and the arrows stay the window\'s');

// The video example's sidebar closes on a press outside it, not on the control bar or the title bar, and not when a slider in it is let go over the picture (whose release clicks what is under the pointer).
$sideOpen = true; $sideAt = 0.5;
$t = new Headless(function (Context $c) use (&$sideOpen, &$sideAt) {
    $box = $c->column(0, 0, 1, 0x000000);
      $picture = $c->column()->grow(1); $c->end();
      $osc = $c->column()->absolute()->left(100)->bottom(50)->size(460, 80); $c->end();
      $title = $c->row()->absolute()->top(0)->left(0)->right(360)->height(32); $c->end();
      if ($sideOpen) {
          $side = $c->column()->absolute()->top(0)->right(0)->bottom(0)->width(360)->translate(0, 0)->keepFocus();
            $c->sliderBase($sideAt, 0, 1)->absolute()->left(20)->top(100)->size(300, 20);
          $c->end();
          $c->clicked($side);
          $bars = $c->pressedOutside($osc) && $c->pressedOutside($title);
          if ($c->pressedOutside($side) && $bars) { $sideOpen = false; }
      }
    $c->end();
}, 900, 600);
$t->frame(); $t->frame();
$t->click(700, 300); $t->frame(); check($sideOpen, 'press in the sidebar keeps it');
$t->click(300, 520); $t->frame(); check($sideOpen, 'press on the bar keeps it');
$t->click(300, 10); $t->frame(); check($sideOpen, 'press on the title bar keeps it');
$t->press(700, 110); $t->drag(400, 110); $t->release(400, 110); $t->frame(); check($sideOpen, 'a slider let go over the picture keeps it');
$t->click(300, 200); $t->frame(); check(!$sideOpen, 'press on the picture closes it');

// charts (Swift Charts' marks): the axes the scales pick, the bars the pointer chooses a month of, the stacking switched, the donut's sector chosen.
[$view, $s] = Headless::load('charts', 'App::whenReady');
$t = new Headless($view, 1080, 1100);
$t->frame(2);
check($t->byText('$120k') !== null && $t->byText('Jan') !== null && $t->byText('W2') !== null && $t->byText('0.5 t') !== null && $t->byText('Search') !== null, 'charts: the axes and the legend: ' . json_encode(array_slice($t->texts(), 0, 40)));
$bar = $t->byLabel('Month: Mar, Sales: ' . rtrim(rtrim(sprintf('%.4f', $s->sales[2]), '0'), '.'));
check($bar !== null, 'charts: a bar named by its values');
[$bx, $by] = $t->middle($bar); $t->hover($bx, $by); $t->frame(2);
same('Mar', $s->month, 'charts: the pointer over a bar chooses its month');
check($t->byText(sprintf('Mar: $%.1fk', $s->sales[2])) !== null && $t->byText(sprintf('%.0f', $s->sales[2])) !== null, 'charts: its value over it, and above the chart');
$t->clickText('Shares'); $t->frame(2);
check($s->stacking === 1 && $t->byText('100%') !== null, 'charts: the areas stacked as shares');
[$cx, $cy] = $t->middle($t->byText('4.2M')); // the donut's middle
$t->hover($cx + 60, $cy - 20); $t->frame(2); // right of twelve o'clock: the first sector
same(0, $s->platformAt($s->angle), 'charts: the pointer chooses a sector');
check($t->byText('46%') !== null && $t->byText('macOS') !== null, 'charts: its share in the middle');

// chart-streaming: three LinePlots of 15,000 samples, a few points per pixel column, a capsule each segment.
[$view, $s] = Headless::load('chart-streaming');
$csKinds = function (\Closure $view): array {
    $t = new Headless($view, 1000, 600);
    $t->frame();
    $list = $t->paint(); $out = [];
    $colors = array_map(fn(int $c) => [(($c >> 16) & 255) / 255, ($c & 255) / 255], \MyPHP\Examples\ChartStreaming\COLORS);
    for ($i = 0; $i < $list->n; $i++) {
        $b = $list->buf; $k = $i * \MyPHP\UI\DisplayList::FLOATS_PER_RECT;
        foreach ($colors as $c => [$r, $bl]) {
            if (abs($b[$k + 4] - $r) < 1e-3 && abs($b[$k + 6] - $bl) < 1e-3) {
                $kind = (int)round($b[$k + 9] - \MyPHP\UI\DisplayList::KIND_STRIDE * floor($b[$k + 9] / \MyPHP\UI\DisplayList::KIND_STRIDE));
                $out[$c][$kind] = ($out[$c][$kind] ?? 0) + 1;
            }
        }
    }
    return [$out, $t];
};
[$kinds, $t] = $csKinds($view);
$lines = array_map(fn(array $k) => $k[9] ?? 0, $kinds);
check(count($lines) === 3 && min($lines) > 100 && max($lines) < 2 * 1000 + 3, 'chart-streaming: three lines of capsules, a few a column: ' . json_encode($lines));
check($t->byLabel('Three live waveforms') !== null && $t->byText('-10s') !== null && $t->byText('0s') !== null, 'chart-streaming: the chart named, its axis in seconds: ' . json_encode($t->texts()));

// wind (mygo's TestGrid): the grid's cells are 6.5 DIPs wide, in whole pixels, twice as high, centered on the pane and overhanging it; a pane too small has none.
[$view, $s] = Headless::load('wind');
$g = \MyPHP\Examples\Wind\Grid::of(2200, 1440, 2);
same([13, 26, 170, 56], [$g->cellW, $g->cellH, $g->cols, $g->rows], 'wind: the grid of 2200×1440 at 2');
same([-5, -8], [$g->left, $g->top], 'wind: the grid centered');
same(7, \MyPHP\Examples\Wind\Grid::of(1100, 720, 1)->cellW, 'wind: cells 7 pixels wide at 1 (6.5 rounded)');
same(null, \MyPHP\Examples\Wind\Grid::of(100, 100, 1), 'wind: no grid in a pane of 100×100');
// TestWind: a seed picks the same wind every time, heading down the pane, and a look that the last visits did not show.
check(\MyPHP\Examples\Wind\Wind::of(42, 'mixed') == \MyPHP\Examples\Wind\Wind::of(42, 'mixed'), 'wind: one seed, one wind');
$windOk = true;
for ($seed = 0; $seed < 200; $seed++) {
    $w = \MyPHP\Examples\Wind\Wind::of($seed, 'mixed');
    $windOk = $windOk && $w->heading > 0 && $w->heading < 3.1416 && $w->speed >= 42 && $w->speed <= 64 && $w->gustWidth >= 120 && $w->gustWidth <= 190
        && \MyPHP\Examples\Wind\Texture::pickLook($seed, ['mixed', 'figured']) === 'lettered'
        && \MyPHP\Examples\Wind\Texture::pickLook($seed, ['mixed', 'figured', 'lettered']) !== 'mixed';
}
check($windOk, 'wind: the winds of 200 seeds in range, their looks not the last ones');
// TestClicks: clicks on the pane blow ripples from the pointer, every third a heart; a click on the composer blows none.
$t = new Headless($view, 1100, 720);
$t->frame();
for ($i = 0; $i < 3; $i++) { $t->click(100 + $i * 50, 120); }
$taps = $s->tex->hand->taps;
check(count($taps) === 3 && $taps[0]->x == 100 && $taps[0]->y == 120, 'wind: three taps from (100, 120) on');
check($taps[0]->force > 0 && $taps[1]->force > 0 && $taps[2]->force < 0, 'wind: the third a heart (negative)');
$t->clickLabel('Task'); $t->frame();
same(3, count($s->tex->hand->taps), 'wind: a click on the composer blows no ripple');
// TestSettles: the wind blows for its budget, settles and fades out; whatever the user does next brings it back in.
$tex = new \MyPHP\Examples\Wind\Texture(7, $s->tex->sh);
$now = 1000 * 1_000_000_000;
for ($i = 0; $i < (int)((\MyPHP\Examples\Wind\BUDGET + \MyPHP\Examples\Wind\SETTLE_TIME + 1) / 0.04); $i++) { $now += 40_000_000; $tex->step($now); }
same(0.0, $tex->energy, 'wind: no energy after the budget');
$frozen = $tex->windTime;
$tex->step($now + 1_000_000_000);
same($frozen, $tex->windTime, 'wind: the wind stands once settled');
$tex->calm = true;
$tex->move(10, 10, $now);
check(!$tex->calm && $tex->revealFrom === $tex->active && $tex->until === $tex->active + \MyPHP\Examples\Wind\BUDGET, 'wind: woken, it comes in anew');
// stillTexture: a texture whose wind stands still at a time, in a pane of 300×200 DIPs at 1, shown long ago, with a click a second old when $click; its atlas made by a view, as the window's.
$windAtlas = null;
(new Headless(function (Context $c) use (&$windAtlas): void { $windAtlas ??= new \MyPHP\Examples\Wind\Atlas($c, 7, 1); }))->frame(1);
$stillTexture = function (bool $click) use ($s, $windAtlas, &$now): \MyPHP\Examples\Wind\Texture {
    $tex = new \MyPHP\Examples\Wind\Texture(0x3fa1c2d9, $s->tex->sh);
    $tex->windTime = 21.5;
    [$tex->calm, $tex->active, $tex->revealFrom, $tex->energy] = [true, 10.0, 0.0, 1.0];
    $tex->atlases['7@1'] = $windAtlas;
    $tex->shown = $now - 3600 * 1_000_000_000;
    if ($click) {
        $tex->hand->tap(150, 100, 0);
        $tex->start = $now - 1_000_000_000;
    }
    return $tex;
};
// render: paints the texture in a pane of 300×200 DIPs, and returns the display list the glyphs draw in.
$render = function (\MyPHP\Examples\Wind\Texture $tex) use (&$now): \MyPHP\UI\DisplayList {
    $t = new Headless(function (Context $c) use ($tex): void {
        $c->column()->fill()->draw(function (\MyPHP\UI\Painter $p, array $r) use ($tex): void {
            $tex->draw($p, $r, false);
        });
        $c->end();
    }, 300, 200);
    $t->rt->clock = function () use (&$now): int { return $now; };
    $t->frame(1);
    return $t->paint();
};
// TestRender (what the GPU is given: myphp has no CPU renderer to count pixels with): the wind's field drawn by wind.kage into its canvas, a pixel a sample, and the glyphs by glyphs.kage over the grid, reading it and the atlas; a click's blow goes to both.
$tex = $stillTexture(false);
$list = $render($tex);
$sh = $s->tex->sh;
check(count($list->shaders) === 1 && $list->shaders[0][0] === $sh->glyphs->code && $list->shaders[0][3][0] === $tex->field->bitmap() && $list->shaders[0][3][1] === $windAtlas->bitmap, 'wind: the glyphs drawn from the field and the atlas');
check(count($tex->field->list->shaders) === 1 && $tex->field->list->shaders[0][0] === $sh->wind->code && $tex->field->size() === [43, 30], 'wind: the field drawn, 43×30 samples, two a cell down: ' . json_encode($tex->field->size()));
same([0, 0, 1.0], [$tex->windValues['TapCount'], $tex->glyphValues['TapCount'], $tex->windValues['Reveal']], 'wind: no click, the wind whole');
closeFloat(0.78, $tex->glyphValues['Opacity'], 'wind: the canvas faded in, at its strength');
$tex = $stillTexture(true);
$render($tex);
check($tex->glyphValues['TapCount'] === 1 && $tex->glyphValues['Taps'] == [150, 100, 0, 1] && abs($tex->glyphValues['Now'] - 1) < 1e-9, 'wind: a click a second old blows from (150, 100): ' . json_encode($tex->glyphValues['Taps']));
// TestStillWhileCalm: settled, the texture has faded out; asking for less motion then shows the wind as it stands, at once.
$tex = $stillTexture(false);
$tex->energy = 0;
$render($tex);
same(0.0, $tex->windValues['Reveal'], 'wind: nothing lit once settled');
$tex->still = true;
$render($tex);
same(1.0, $tex->windValues['Reveal'], 'wind: the wind as it stands once still');
$tex->still = false;
$render($tex);
same(0.0, $tex->windValues['Reveal'], 'wind: nothing lit moving again, settled');

// blackhole (mygo's TestInput): a drag orbits the camera and keeps it turning after a throw, slowing to its own orbit; a scroll comes closer, no nearer than NEAR; the hint fades once the user moved the camera.
[$view, $a] = Headless::load('blackhole');
$t = new Headless($view, 1200, 760);
$t->frame();
check($t->byText('Drag to orbit · Scroll to zoom') !== null, 'blackhole: the hint');
[$yaw, $pitch] = [$a->cam->yaw, $a->cam->pitch];
$t->press(500, 300); $t->drag(560, 330); $t->drag(620, 360); $t->release(620, 360);
check(abs($a->cam->yaw - ($yaw - 120 * 0.005)) <= 0.05, "blackhole: yaw {$a->cam->yaw} after dragging 120 DIPs right");
check($a->cam->pitch > $pitch, "blackhole: pitch {$a->cam->pitch} after dragging down from $pitch");
for ($i = 0; $i < 10; $i++) { $a->step(0.1, false); }
same(0.0, $a->hint, 'blackhole: the hint faded after moving the camera');
// A scroll of InputEvent dy -200 each, as mygo's Scroll(0, -200): the wheel's raw delta, which the event turns around.
for ($i = 0; $i < 40; $i++) { $t->wheel(600, 380, 0, 200); }
same((float)\MyPHP\Examples\Blackhole\NEAR, $a->cam->dist, 'blackhole: scrolled in a long way, at the nearest');
// TestCamera: the camera looks at the hole, its axes a right-handed frame.
$cam = new \MyPHP\Examples\Blackhole\Camera(yaw: 0.7, pitch: 0.3, dist: 20);
[$eye, $right, $up, $forward] = $cam->axes();
check(abs(sqrt($eye->dot($eye)) - 20) <= 1e-9, 'blackhole: the eye 20 from the hole');
check(abs($forward->dot($eye->norm()) + 1) <= 1e-9, 'blackhole: the camera looks at the hole');
check(abs($right->dot($up)) <= 1e-9 && abs($right->dot($forward)) <= 1e-9 && $up->y > 0, 'blackhole: axes orthogonal, up upward');
// TestQuality: the resolution drops while frames come late, no lower than MIN_QUALITY, and rises again once they keep up.
$q = new \MyPHP\Examples\Blackhole\BlackHole($a->sh);
for ($i = 0; $i < 600; $i++) { $q->step(1.0 / 20, false); }
same(\MyPHP\Examples\Blackhole\MIN_QUALITY, $q->quality, 'blackhole: the least quality after slow frames');
for ($i = 0; $i < 2000; $i++) { $q->step(1.0 / 120, false); }
same(1.0, $q->quality, 'blackhole: the whole quality after fast frames');
// The three passes: the scene into its canvas at the quality's share of the window's pixels, its glow into a quarter of it, and both over the window.
$q = new \MyPHP\Examples\Blackhole\BlackHole($a->sh);
$t = new Headless(Headless::view('blackhole', $q), 1200, 760);
$t->frame();
$list = $t->paint();
check(count($list->shaders) === 1 && $list->shaders[0][0] === $q->sh->post->code && $list->shaders[0][3][0] === $q->scene->bitmap() && $list->shaders[0][3][1] === $q->glow->bitmap(), 'blackhole: the post pass over the window');
check($q->scene->list->shaders[0][0] === $q->sh->hole->code && $q->glow->list->shaders[0][0] === $q->sh->bloom->code, 'blackhole: the scene and its glow, in their canvases');
same([[1200, 760], [300, 190]], [$q->scene->size(), $q->glow->size()], 'blackhole: the canvases at the window\'s pixels and a quarter');

// chat-glow (mygo's TestReply): a suggestion asks the assistant, which thinks, the window glowing, then writes its reply word by word, and the glow fades once it is done.
[$view, $a] = Headless::load('chat-glow');
$cgSh = $a->sh;
$t = poseTester($view, $now, 860, 700);
$t->clickText('Summarize my week');
check(count($a->messages) === 2 && $a->busy, 'chat-glow: a suggestion asks');
$t->frame();
check($t->byText('Summarize my week') !== null, 'chat-glow: the question shows');
$reply = $a->messages[1];
$a->step(\MyPHP\Examples\ChatGlow\THINK_TIME / 2);
check($reply->shown === 0 && $a->glow > 0, "chat-glow: thinking, $reply->shown words shown, glowing $a->glow");
// Half a second on, the rows in their place, still thinking.
for ($i = 0; $i < 30; $i++) { $now += (int)(1e9 / 60); $t->frame(1); }
$list = $t->paint();
$codes = array_map(fn(array $d) => $d[0], $list->shaders);
check(in_array($cgSh->orb->code, $codes, true) && in_array($cgSh->ring->code, $codes, true) && in_array($cgSh->shimmer->code, $codes, true), 'chat-glow: the orb, the ring and "Thinking…" drawn by their shaders');
$a->step(\MyPHP\Examples\ChatGlow\THINK_TIME / 2);
$a->step(0.1);
check($reply->shown > 0 && $reply->shown < count($reply->words), "chat-glow: $reply->shown words a moment into writing");
for ($i = 0; $i < 200; $i++) { $a->step(0.05); }
check(!$a->busy && $reply->shown === count($reply->words) && $a->glow == 0, 'chat-glow: done long after, the whole reply, no glow');
same($reply->text, implode('', $reply->words), 'chat-glow: the words join into the reply');
$t->frame();
check($t->byText($reply->text) !== null, 'chat-glow: the reply shows');
// TestTypeAndStop: Return sends what was typed; Escape stops the assistant while it thinks.
$a = new \MyPHP\Examples\ChatGlow\ChatGlow($cgSh);
$t = new Headless(Headless::view('chat-glow', $a), 860, 700);
$t->frame();
$t->type('Hello there'); $t->enter();
check(count($a->messages) === 2 && $a->messages[0]->text === 'Hello there' && $a->input === '', 'chat-glow: Return sends the question');
$t->escape();
check(!$a->busy && $a->messages[1]->shown === 1 && $a->messages[1]->words[0] === 'Stopped.', 'chat-glow: Escape stops the reply');
// TestSplitWords: splitWords keeps the spaces and line breaks, which joined give the text back.
foreach (['one', 'one two', "a  b\n\nc ", "Day 1: x.\n\nDay 2"] as $text) {
    same($text, implode('', \MyPHP\Examples\ChatGlow\splitWords($text)), 'chat-glow: splitWords and back');
}
same(4, count(\MyPHP\Examples\ChatGlow\splitWords("a b  c\n\nd")), 'chat-glow: four words');
// TestScrollsToEnd: once the conversation is longer than the window, it shows its end, the whole of the last reply above the composer, not under it.
$a = new \MyPHP\Examples\ChatGlow\ChatGlow($cgSh);
$t = poseTester(Headless::view('chat-glow', $a), $now, 860, 500);
for ($i = 0; $i < 3; $i++) {
    if ($i === 0) {
        $t->clickText('Summarize my week');
    } else {
        $t->clickLabel('Message');
        $t->type('And then?'); $t->enter();
    }
    // The reply comes in word by word, as it shows.
    while ($a->busy) { $a->step(0.1); $t->frame(); }
}
same(6, count($a->messages), 'chat-glow: three questions and their replies');
// The rows glide up to their place (followAnimation), on the clock.
for ($i = 0; $i < 120; $i++) { $now += (int)(1e9 / 60); $t->frame(1); }
// The list ends RING_PADDING and the field's own 6 above the field's box, and its rows 16 above that once they settled.
$cgEnd = function () use (&$t, &$a): array {
    $r = $t->rect($t->byText($a->messages[count($a->messages) - 1]->text));
    return [$r, $t->rect($t->byLabel('Message'))[1] - \MyPHP\Examples\ChatGlow\RING_PADDING - 6 - 12];
};
[$r, $limit] = $cgEnd();
check($r[3] > 0 && $r[1] + $r[3] <= $limit, 'chat-glow: the end of the conversation in view: ' . json_encode([$r, $limit]));
check($r[3] >= 3 * \MyPHP\Examples\ChatGlow\REPLY_SIZE, "chat-glow: the last reply shows all of its lines, $r[3] DIPs");
// TestStops: the edge glow's stops lie in order around the window, and move to new places every SHUFFLE_EVERY seconds, easing there over SHUFFLE_TIME.
$n = 0;
$st = new \MyPHP\Examples\ChatGlow\Stops(function () use (&$n): float { $n++; return ($n % 7) / 7; });
$sorted = function (array $stops): bool { for ($i = 1; $i < count($stops); $i++) { if ($stops[$i]->at < $stops[$i - 1]->at) { return false; } } return true; };
check($sorted($st->show(0)), 'chat-glow: the stops in order');
$st->step(0);
[$from, $to] = [$st->from, $st->to];
$halfway = true;
foreach ($st->show(\MyPHP\Examples\ChatGlow\SHUFFLE_TIME / 2) as $i => $x) { $halfway = $halfway && abs($x->at - ($from[$i]->at + $to[$i]->at) / 2) <= 1e-9; }
check($halfway, 'chat-glow: the stops halfway at half the time');
$got = $st->show(\MyPHP\Examples\ChatGlow\SHUFFLE_TIME);
check($sorted($got) && $got[0] == $to[0], 'chat-glow: the stops arrive in order');
$st->step(\MyPHP\Examples\ChatGlow\SHUFFLE_EVERY / 2);
same(0.0, $st->since, 'chat-glow: the stops wait for their time');
$st->step(\MyPHP\Examples\ChatGlow\SHUFFLE_EVERY);
same(\MyPHP\Examples\ChatGlow\SHUFFLE_EVERY, $st->since, 'chat-glow: the stops move again on time');
$grad = $st->gradient(1);
check(count($grad) === count(\MyPHP\Examples\ChatGlow\GLOW_COLORS) + 2 && $grad[0]->at == 0 && $grad[count($grad) - 1]->at == 1 && $grad[0]->color === $grad[count($grad) - 1]->color, 'chat-glow: the colors between two stops of one color at 0 and 1');
same(\MyPHP\Examples\ChatGlow\GLOW_COLORS[3], \MyPHP\Examples\ChatGlow\srgb(...\MyPHP\Examples\ChatGlow\oklab(\MyPHP\Examples\ChatGlow\GLOW_COLORS[3])), 'chat-glow: a color through Oklab and back');
// TestCatchesUp: a reply asked for before the window went out of sight, its frames paused, has come as far as the time tells once it shows again, done if long enough.
$a = new \MyPHP\Examples\ChatGlow\ChatGlow($cgSh);
$a->send('Summarize my week');
$a->step(\MyPHP\Examples\ChatGlow\THINK_TIME + 0.5);
check($a->messages[1]->shown > 0 && $a->busy, 'chat-glow: half a second into writing, some words');
$a = new \MyPHP\Examples\ChatGlow\ChatGlow($cgSh);
$a->send('Summarize my week');
$a->step(60);
check(!$a->busy && $a->messages[1]->shown === count($a->messages[1]->words), 'chat-glow: a minute later, the whole reply');
// TestJumpsOnCatchUp: a reply that comes all at once shows its end at once rather than gliding up from below.
$a = new \MyPHP\Examples\ChatGlow\ChatGlow($cgSh);
$t = poseTester(Headless::view('chat-glow', $a), $now, 860, 500);
$t->clickText('Summarize my week');
$a->step(60);
$t->frame();
[$r, $limit] = $cgEnd();
check($r[1] + $r[3] <= $limit, 'chat-glow: the reply that came at once in view at once: ' . json_encode([$r, $limit]));

// wavelength (mygo's examples/video): the background plays behind the content, and the button pauses it and plays it again; on macOS, which decodes the GIF.
if (PHP_OS_FAMILY === 'Darwin') {
    [$view, $s] = Headless::load('wavelength');
    same([192, 108], $s->waves->size(), 'the clip is 192 by 108');
    $t = new Headless($view, 640, 360);
    $t->frame();
    check($t->byText('Wavelength') !== null && $s->waves->playing() && $s->waves->loop(), 'the title over the clip, playing, looping');
    $t->clickText('Pause'); $t->frame();
    check(!$s->waves->playing(), 'Pause holds the clip');
    $t->clickText('Play'); $t->frame();
    check($s->waves->playing(), 'Play goes on');
    $s->waves->close();
}

// loading: every indicator, named, 24 high; 48 makes them 48; Paused stops their frames.
[$view, $s] = Headless::load('loading');
$t = new Headless($view, 780, 2000);
$t->frame();
foreach (\MyPHP\Plugins\Loading\Loading::all() as $sp) {
    $n = $t->byLabel($sp->name);
    check($n !== null && $t->rect($n)[3] === 24.0 && $t->byText($sp->name) !== null, "loading: {$sp->name} shown, 24 high");
}
$t->clickText('48'); $t->frame();
same(48.0, $t->rect($t->byLabel('Arc'))[3], 'loading: Arc 48 high after 48');
$t->clickText('Paused'); $t->frame();
$asks = $t->rt->paintAsks;
$t->frame(); $t->paint();
check($s->paused && $t->rt->paintAsks === $asks, 'loading: Paused, asking for no frames');
// Painted in parts frame after frame, as the window paints them: the transforms of the indicators' parts written anew each frame, not piling up (the list filled in seconds).
$s->paused = false; $now = 0;
$t = poseTester($view, $now, 780, 640);
$t->paint(); $t->rt->pace(60.0);
$first = $t->painted()->transforms;
for ($i = 0; $i < 200; $i++) { $now += 16_000_000; $t->windowFrame(); }
check($t->rt->partPaints === 200 && $t->painted()->transforms <= 2 * $first, "loading: 200 frames in parts, {$t->painted()->transforms} transforms (the first {$first})");

// mobile (the declarative views, as SwiftUI's): a row of the list goes to its page and further, Back comes back, the
// tabs keep their stacks, the alert's buttons run their actions, the field writes the name.
[$view, $s] = Headless::load('mobile');
$s->homePath->transition = \MyPHP\UI\Router::NONE;
$t = new Headless($view, 390, 844);
$t->rt->touch = true;
$t->frame(2);
check($t->byText('200,000 行') !== null && $t->byText('第 3 行') !== null && $t->byText(md5('3')) !== null, 'mobile: the list and its title: ' . json_encode(array_slice($t->texts(), 0, 6)));
$t->clickText('第 3 行'); $t->frame(3);
check($t->byText('这是第 3 行的详情页。') !== null && $t->byText('第 3 行') !== null, "mobile: a row's page, {i} given as an int, its navigationTitle");
$t->clickText('再推一页'); $t->frame(3);
same(2, $s->homePath->count(), 'mobile: append() goes deeper');
$t->clickLabel('Back'); $t->frame(3);
check($t->byText('这是第 3 行的详情页。') !== null, 'mobile: Back comes back a page');
$s->tab = 2; $t->frame(3);
check($t->byText('下拉刷新过 0 次') !== null, 'mobile: the third tab');
$s->tab = 0; $t->frame(3);
check($t->byText('这是第 3 行的详情页。') !== null, 'mobile: the first tab kept its stack');
$s->alert = true; $t->frame(2);
$t->clickText('删除'); $t->frame(2);
same('alert: 删除', $s->lastAction, "mobile: an alert's button runs its action");
$s->tab = 1; $t->frame(3);
$t->clickLabel('姓名'); $t->type('Ada'); $t->frame();
same('Ada', $s->name, 'mobile: the TextField writes the name');

// router (the declarative views): a NavigationLink of the list beside the sidebar goes to the note, the sidebar to the
// settings, a link of the settings deeper, the field writes the name, Back comes back.
[$view, $s] = Headless::load('router');
$t = new Headless($view, 760, 460);
$t->frame(2);
$t->clickText('Ideas'); $t->frame(3);
same('/notes/2', $s->path->path(), 'router: a NavigationLink beside the sidebar goes to its note');
$t->clickText('Settings'); $t->frame(3);
$t->clickText('Account'); $t->frame(3);
check($t->byLabel('Your name') !== null && $t->byText('Version') === null, 'router: a link of the settings goes deeper'); // the Account section, its name's field
$t->clickLabel('Your name'); $t->type('Ada'); $t->frame();
same('Ada', $s->name, 'router: the TextField writes the name');
$t->clickLabel('Back'); $t->frame(3);
check($t->byText('Version') !== null && $t->byLabel('Your name') === null, 'router: Back comes back to General');

// form (the declarative views): the fields write the Profile, Return in a field submits, Return in the message field
// sends it and Shift+Return breaks its line, the controls write their values, the card and Clear act.
[$view, $s] = Headless::load('form');
$t = new Headless($view, 600, 900);
$t->frame(2);
$fields = []; // the fields in order: Name, City, Note, Message
$walk = function (\MyPHP\UI\Node $n) use (&$walk, &$fields) { if (in_array($n->kind, [\MyPHP\UI\Node::INPUT, \MyPHP\UI\Node::TEXTAREA], true)) { $fields[] = $n; } foreach ($n->kids as $k) { $walk($k); } };
$walk($t->root); $walk = null;
same(4, count($fields), 'form: four fields');
$t->clickOn($fields[0]); $t->key(\MyPHP\UI\Key::END, 'moveToEndOfLine:'); $t->type(' L'); $t->frame();
same('Ada L', $s->name, 'form: the Name field writes the Profile');
$t->key(\MyPHP\UI\Key::RETURN, 'insertNewline:'); $t->frame(2);
check($s->submits === 1 && $t->byText('Hello, Ada L from  (#1)') !== null, 'form: Return in a field submits, the greeting its placeholders filled: ' . json_encode(array_slice($t->texts(), -3)));
$t->clickOn($fields[3]); $t->type('hi'); $t->frame();
$t->key(\MyPHP\UI\Key::RETURN, 'insertNewline:'); $t->frame(2);
check($s->log === 'hi' && $s->draft === '', "form: Return sends the message to the log: log \"{$s->log}\", draft \"{$s->draft}\"");
$t->type('a'); $t->key(\MyPHP\UI\Key::RETURN, 'insertNewline:', \MyPHP\UI\Key::SHIFT); $t->type('b'); $t->frame();
same("a\nb", $s->draft, 'form: Shift+Return breaks the line instead');
$t->clickText('Subscribe'); $t->frame();
check($s->subscribe, 'form: the checkbox writes subscribe');
$t->clickText('Dark'); $t->frame(2);
same('dark', $s->mode, 'form: a radio button writes the theme');
$t->clickText('Submit'); $t->frame();
same(2, $s->submits, 'form: Submit submits');
$t->clickOn($t->byText('A card. Tab to it and press Space, or click it. This sentence wraps at the width the window gives the card, so make the window narrower and watch it reflow.')); $t->frame();
check($s->greeting === 'card' && $t->byText('Card activated (#3)') !== null, 'form: the card, a button, activates');
$t->clickText('Clear'); $t->frame(2);
check($s->name === '' && $s->city === '' && $s->greeting === '', 'form: Clear empties the fields');
// Its text in the language chosen, as SwiftUI's String Catalog: each text the key of its translation in locales/, a
// placeholder's value, a plural, a field's prompt, a button's title; the language names in their own.
$s->lang = 'zh-Hans'; $t->frame(2);
check($t->byText('个人资料') !== null && $t->byText('提交') !== null && $t->byText('标签 3') !== null && $t->byText('姓名：0 字节') !== null, 'form: in Chinese: ' . json_encode(array_slice($t->texts(), 0, 8), JSON_UNESCAPED_UNICODE));
check($t->byLabel('城市（试试中文输入法）') !== null && $t->byText('中文') !== null, "form: a field's prompt translated, the language names in their own");
$s->lang = 'es'; $s->name = 'Ada'; $t->frame(2);
check($t->byText('Perfil') !== null && $t->byText('nombre: 3 bytes') !== null, 'form: in Spanish, its plural');
$s->lang = 'en'; $t->frame(2);
check($t->byText('Profile') !== null, 'form: in English again');
\MyPHP\Catalog::resetRegistry(); // its catalogs, which requiring locales/Locales.php registered, are not the next tests'

// desktop (the declarative views): the log's context menu, as SwiftUI's contextMenu, clears it.
[$view, $s] = Headless::load('desktop');
$t = new Headless($view, 700, 500);
$t->frame(2);
[$x, $y] = $t->rect($t->byText('Use the menu, the buttons, or right-click.'));
$t->rightClick($x + 5, $y + 5); $t->frame();
same(['Open…', 'Save As…', '-', 'Clear log'], $t->menuLabels(), "desktop: the log's context menu");
$t->closeMenu();
$s->log[] = 'move (980×720)'; $s->log[] = 'move (980×720)'; $t->frame(2); // lines that repeat, told apart by their place: no key twice
check(count(array_filter($t->texts(), fn(string $x) => $x === 'move (980×720)')) === 2, 'desktop: a line that repeats shows twice');
[$x, $y] = $t->rect($t->byText('Use the menu, the buttons, or right-click.'));
$t->rightClick($x + 5, $y + 5); $t->frame();
$t->chooseMenuItem('Clear log'); $t->frame(2);
same([], $s->log, 'desktop: Clear log empties it');

// titlebar (the declarative views): the bar clear of the window's controls, a handle moving the window; its field and
// its button keep their presses.
[$view, $s] = Headless::load('titlebar', 'App::whenReady');
$t = new Headless($view, 700, 400);
$t->frame(2);
$t->clickText('Count'); $t->frame(2);
same(1, $s->count, 'titlebar: Count counts');
check($t->byText('Counted 1; searching “”.') !== null, 'titlebar: the text follows');

// glass (the declarative views): the toolbar offset in the layout (a system material placed by it moves with it), its button counting a press, and the blur
// is as wide as the window, its frame a box around its padded text, as SwiftUI's.
$s = (object)['photo' => \MyPHP\UI\Image::fromPixels(4, 3, str_repeat("\x80\x80\x80\xff", 12)), 'x' => 40.0, 'clear' => false, 'tinted' => true, 'presses' => 0, 'hard' => true];
$t = new Headless(Headless::view('glass', $s), 820, 620);
$t->frame(3);
$bar = null; $find = function (Node $n) use (&$find, &$bar, $t) { foreach ($n->kids as $k) { if ($k === $t->byText('Liquid Glass')) { $bar = $n; } $find($k); } }; $find($t->root); $find = null;
same([16.0, 16.0], array_slice($t->rect($bar), 0, 2), 'glass: the toolbar offset 16, 16 in the layout, its glass with it');
$t->clickText('Press me'); $t->frame(2);
same(1, $s->presses, 'glass: the glass button counts a press');
$box = null; $find = function (Node $n) use (&$find, &$box, $t) { foreach ($n->kids as $k) { if ($k === $t->byText('Blur, fading downward')) { $box = $n; } $find($k); } }; $find($t->root); $find = null;
same([820.0, 90.0], [$box->w, $box->h], "glass: the blur's frame, the window's width");

// scene3d: 3D scene view builds, steps animations, and picks 3D objects with particle bursts & tweens.
[$view, $s] = Headless::load('scene3d', 'App::whenReady');
$t = new Headless($view, 800, 600);
$t->frame(2);
$t->paint();
check($s->frames > 0 && $s->instancedRing->instanceCount === 64, 'scene3d: 64 instanced pillars and update loop running');
$hit = $s->scene->pick(400.0, 300.0, 800.0, 600.0);
$s->onPick($hit);
check($s->sparks->activeCount() === 42 && count($s->scene->tweens) > 0, 'scene3d: picking object bursts 42 3D particles and starts Tween3D');

// game3d: 3D game loop builds, steps player movement, gem collection, and 3D particle bursts.
[$view, $g] = Headless::load('game3d', 'App::whenReady');
$t = new Headless($view, 860, 620);
$t->frame(2);
$t->paint();
$g->px = $g->gem->position->x;
$g->pz = $g->gem->position->z;
$g->update(new \MyPHP\Plugins\Game\Input());
same(1, $g->score, 'game3d: collecting crystal gem increments score');
check($g->particles->activeCount() > 0, 'game3d: collecting crystal gem bursts emerald 3D particles');

// model-viewer: preset switching, wireframe toggle, Fresnel rim slider, and particle burst button.
[$view, $m] = Headless::load('model-viewer', 'App::whenReady');
$t = new Headless($view, 1080, 740);
$t->frame(2);
$t->clickText('Capsule'); $t->frame(2);
same('Capsule', $m->preset, 'model-viewer: Capsule preset selected');
$t->clickText('Hardware Wireframe Mode'); $t->frame(2);
check($m->wireframe, 'model-viewer: Hardware Wireframe Mode toggled on');
$t->clickText('Spark Burst'); $t->frame(2);
check($m->emitter->activeCount() === 56, 'model-viewer: Spark Burst button spawns 56 3D particles');
\MyPHP\UI\CanvasAtlas::take();

// rubiks-cube: 27 3D cubies, 64 audio spectrum bars, Scramble and Auto-Solve queue.
[$view, $r] = Headless::load('rubiks-cube', 'App::whenReady');
$r->soundEnabled = false;
$t = new Headless($view, 1080, 720);
$t->frame(2);
same(27, count($r->cubies), 'rubiks-cube: 27 3D cubies initialized');
$t->clickText('Scramble'); $t->frame(2);
same(12, count($r->moveQueue) + ($r->activeMove !== null ? 1 : 0), 'rubiks-cube: Scramble queues 12 layer rotations');
for ($i = 0; $i < 80; $i++) { $r->update(0.05); }
same(12, count($r->moveHistory), 'rubiks-cube: 12 scramble twists completed');
$t->clickText('Auto-Solve'); $t->frame(2);
for ($i = 0; $i < 80; $i++) { $r->update(0.05); }
same(0, count($r->moveHistory) + count($r->moveQueue), 'rubiks-cube: Auto-Solve unwinds all moves back to solved state');
\MyPHP\UI\CanvasAtlas::take();


