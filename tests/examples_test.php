<?php
// The examples ported from mygo, run without their windows as mygo's
// examples' main_test.go run them: each view built and clicked as a user
// would.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key};

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

// timeline: the intro lasts 870 ms; its controls hold, play, turn and restart it.
[$view, $s] = Headless::load('timeline');
$t = new Headless($view, 520, 480);
$t->frame();
check(array_filter($t->texts(), fn($x) => str_ends_with($x, ' / 870 ms')) !== [], 'the intro lasts 870 ms: ' . json_encode($t->texts()));
$t->clickText('Pause'); $t->frame(); check($s->paused, 'Pause holds the intro');
$t->clickText('Play'); $t->frame(); check(!$s->paused, 'Play goes on');
$t->clickText('Reverse'); $t->frame(); check($s->reverse, 'Reverse turns it around');
$t->clickText('Restart'); $t->frame(); same(1, $s->run, 'Restart plays it again');

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
same('plain', $s->body->text, 'plain text becomes the body');

// text-input: the app's field takes typed text through the text services.
[$view, $s] = Headless::load('text-input');
$t = new Headless($view, 760, 240);
$t->frame();
$t->type('!'); $t->frame();
check(str_ends_with($s->text, 'עברית!'), 'typed at the end: ' . $s->text);
$s->replaceText(new \MyPHP\UI\TextInputRange(0, 4), 'Write');
check(str_starts_with($s->text, 'Write here'), 'a range replaced in UTF-16 units');
$t->key(Key::DELETE, 'deleteBackward:'); $t->frame();
check(str_starts_with($s->text, 'Writ here') || str_starts_with($s->text, 'Write here'), 'Delete');

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
$s->query->text = 's'; $s->filter(); $t->frame();
same(['Safari', 'Script Editor', 'Visual Studio Code'], array_map(fn($a) => $a->name, $s->results), 'what starts with s, then the rest that has it');
$t->down(); $t->frame();
same(1, $s->chosen(), 'Down chooses the next');
$s->query->text = ''; $s->filter(); $t->frame();
$q = $t->byLabel('Search apps');
same(22.0, $q->fontSize, 'the query in 22 points');
$t->clickOn($q); $t->type('saf'); $t->frame();
same(['Safari'], array_map(fn($a) => $a->name, $s->results), 'typing the query filters, as its field reports the change');
same(['System/Utilities', '~/Applications'], [\MyPHP\Examples\Launcher\where($s->apps[2]), \MyPHP\Examples\Launcher\where(new \MyPHP\InstalledApp('X', 'x', getenv('HOME') . '/Applications/X.app'))], 'where apps are');

// menubar-native: the note, its Clear and its status.
[$view, $s] = Headless::load('menubar-native');
$t = new Headless($view, 380, 340);
$t->frame();
check($t->byText('Kept here until you quit.') !== null, 'the status at first');
$s->text->text = 'milk'; $t->frame();
$t->clickText('Clear'); $t->frame();
same('', $s->text->text, 'Clear empties the note');

// menubar-flyout: the panel's switches.
[$view, $s] = Headless::load('menubar-flyout');
$t = new Headless($view, 280, 230);
$t->frame();
check($t->byText('Sessions of 25 minutes') !== null, 'the session length');
$t->clickLabel('Focus mode'); $t->frame();
check($s->focus, 'the switch turns focus mode on');

// flyout: the window's buttons, and the list of colors chosen from the keys and the mouse.
Headless::load('flyout', '$a = new Flyouts');
$a = new \MyPHP\Examples\Flyout\Flyouts();
$t = new Headless($a->view(...), 300, 240);
$t->frame();
check($t->byText('Red ▾') !== null && $t->byText('No note') !== null, 'the color and the note at first');
$t = new Headless($a->listView(...), 180, 180);
$t->frame();
$t->down(); $t->down();
same(2, $a->hover, 'Down moves along the list');
$t->key(Key::UP, 'moveUp:');
same(1, $a->hover, 'Up moves back');
$t->hover(40, 6 + 4 * 28 + 14); $t->frame();
same(4, $a->hover, 'the pointer moves the choice'); // choosing closes the flyout and redraws the window, which a headless test has not

// vibrancy: a material chosen in the sidebar, and the sidebar hidden and shown.
[$view, $s] = Headless::load('vibrancy');
$t = new Headless($view, 880, 640);
$t->frame();
$t->clickText('Popover'); $t->frame();
check($s->chosen === 'popover' && $t->byText("new WindowOptions(vibrancy: 'popover')  // or \$win->setVibrancy('popover')") !== null, "chose {$s->chosen}");
$t->clickLabel('Hide Sidebar'); $t->frame();
check($s->collapsed && $t->byLabel('Show Sidebar') !== null, 'Hide Sidebar hides it');
$t->clickLabel('Show Sidebar'); $t->frame();
check(!$s->collapsed, 'and Show Sidebar shows it');
check($t->byText('This window shows no material here, so the sidebar draws a background of its own: materials show on macOS.') !== null, 'without a material, the pane says why');

// todo: the store, and its event in the TypeScript client.
Headless::load('todo', 'function openWindow');
$path = sys_get_temp_dir() . '/myphp-todo-' . getmypid() . '.json';
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
check($s->bursts[0]->launched !== 0, 'the burst heads off');
$clock += (int)(200 / 60 * 1e9) + 1000;
$t->frame();
same(0, count($s->bursts), 'the bursts go once they faded');
[$x, $y] = $t->middle($t->byText('Click anywhere here'));
$t->click($x, $y + 100); $t->frame();
same(150, $s->inAir(), 'the stage throws its burst');
check(abs($s->bursts[0]->x - $x) <= 1 && abs($s->bursts[0]->y - $y - 100) <= 1, 'from the pointer');
// Pressing a demo again and again lets the oldest pieces go first: Realistic Look throws 50, 40, 70, 20 and 20 pieces.
$s = new \MyPHP\Examples\Confetti\Confetti();
$t = new Headless(fn(\MyPHP\UI\Context $c) => \MyPHP\Examples\Confetti\view($c, $s), 980, 700);
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
$t = new Headless(fn(\MyPHP\UI\Context $c) => \MyPHP\Examples\Farm\view($c, $g), 960, 640);
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
$t = new Headless(fn(\MyPHP\UI\Context $c) => \MyPHP\Examples\Mayoi\view($c, $g), 960, 640);
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
$G = fn(string $path = '/overview') => new \MyPHP\Examples\Gallery\Gallery($path);
$g = $G();
$g->router->transition = \MyPHP\UI\Router::NONE;
$t = new Headless(function (\MyPHP\UI\Context $c) use ($g) {
    $c->row(0, 0, 1)->fill()->align(\MyPHP\UI\Node::STRETCH);
      $g->sidebar($c);
      $c->column(0, 0, 1);
        $g->toolbar($c);
        $g->router->view($c, function (\MyPHP\UI\Route $r) use ($c) {
            $r->title(\MyPHP\Examples\Gallery\pageOf($r->path()));
            $c->text('Page: ' . $r->path());
        });
      $c->end();
    $c->end();
}, 980, 720);
$t->frame();
$at = function (string $want) use ($g, $t): bool { $t->frame(); return $g->router->path() === $want && $t->byText("Page: $want") !== null; };
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
$t->down(); $t->frame();
check($at('/charts'), 'a row of the list is the List page\'s, in the sidebar');

$g = $G('/list/42');
$g->router->transition = \MyPHP\UI\Router::NONE;
$t = new Headless(function (\MyPHP\UI\Context $c) use ($g) {
    $c->column(0, 0, 1)->fill();
      $g->toolbar($c);
      $g->router->view($c, function (\MyPHP\UI\Route $r) use ($c, $g) { if ($r->match('/list/{row}')) { $g->listRow($c, $r); } });
    $c->end();
}, 600, 400);
$t->frame();
foreach ([true, false] as $want) {
    $t->clickText('Starred'); $t->frame();
    same($want, $g->starred[42] ?? false, 'the row\'s star');
}
$t->clickText('List'); $t->frame();
same('/list', $g->router->path(), 'the breadcrumb goes back to the list');

$g = $G('/list');
$t = new Headless(function (\MyPHP\UI\Context $c) use ($g) { $c->scroll(0, 0, 1)->fill(); $g->list($c); $c->end(); }, 980, 720);
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
$t = new Headless(fn(\MyPHP\UI\Context $c) => $g->overview($c), 980, 720);
$t->frame();
$ok = true;
foreach ([['Increment', '1'], ['Increment', '2'], ['−', '1']] as [$click, $want]) { $t->clickText($click); $t->frame(); $ok = $ok && $t->byText($want) !== null; }
check($ok && $g->count === 1, 'the counter counts');

$g = $G('/languages');
$g->now = new \DateTimeImmutable('2026-01-05 13:07:00', new \DateTimeZone('UTC'));
$g->meeting = new \DateTimeImmutable('2026-01-05 09:30:00', new \DateTimeZone('UTC'));
$saved = \MyPHP\App::locale(); // without an app, the zero region: the formats are the language's, and Chinese's 24 hours show no AM/PM segment
\MyPHP\App::setLanguages(...\MyPHP\Examples\Gallery\GALLERY_LANGUAGES); // as the gallery declares them
$t = new Headless(fn(\MyPHP\UI\Context $c) => $g->languages($c), 980, 1400);
$t->frame();
$missing = array_filter(['1,234,567.891', 'Monday, January 5, 2026', '1:07 PM', 'January 2026', '简体中文 (zh-Hans)', 'Pseudo right to left (ar-XB)', '12,34,567.891'], fn($s) => $t->byText($s) === null);
same([], array_values($missing), 'the Languages page in English');
\MyPHP\App::setLocale('zh-Hans'); $t->frame(2);
$missing = array_filter(['2026年1月5日星期一', '2026年1月', '上个月'], fn($s) => $t->byText($s) === null && $t->byLabel($s) === null);
same([], array_values($missing), 'and in Chinese');
\MyPHP\App::setLocale($saved);
\MyPHP\App::setLanguages('en');

// notes (mygo's TestNotes): a new note takes a title and a list typed with Markdown, and its title shows in the sidebar.
[$view, $s] = Headless::load('notes');
$s->path = ''; $s->notes = []; $s->next = 0; $s->welcome = true; // nothing read or written: the welcome note
$t = new Headless($view, 900, 640);
$t->paints = true;
$t->frame();
same('Welcome to Notes', $s->current()->title(), 'the welcome note, its chip measured');
$t->clickLabel('New note'); $t->frame();
$t->press(600, 300); $t->release(600, 300); $t->frame();
$t->type('Shopping'); $t->frame();
$t->key(\MyPHP\UI\Key::RETURN, 'insertNewline:'); $t->frame();
$t->type('- milk'); $t->frame();
$n = $s->current();
same('Shopping', $n->title(), 'the new note is titled by its first line');
$md = $n->document()->text()->markdown();
check(str_contains($md, '# Shopping') && str_contains($md, '- milk'), "its Markdown: $md");
check($t->byText('Shopping') !== null, 'the sidebar shows it: ' . json_encode($t->texts()));

// drag-drop: a note dropped on the other window's zone joins its notes; a URL is reported.
[, $s] = Headless::load('drag-drop');
$right = $s->panes[1];
$t = new Headless(fn(\MyPHP\UI\Context $c) => \MyPHP\Examples\DragDrop\view($c, $right), 440, 580);
$t->frame();
check($t->byText('A note from Right') !== null && $t->byText('Drop here') !== null, 'the pane, its note and its zone');
[$x, $y] = $t->middle($t->byText('Drop here'));
$note = new \MyPHP\Transfer\Data(new \MyPHP\Transfer\Item(
    \MyPHP\Transfer\Representation::bytes(\MyPHP\Transfer\Format::TEXT, 'A note from Left'),
    \MyPHP\Transfer\Representation::bytes('application/vnd.myphp.example-note+json', '{"ID":1,"Title":"A note from Left"}')));
$d = new \MyPHP\UI\DataDragEvent(new \MyPHP\Transfer\Offer($note->formats(), \MyPHP\Transfer\Operation::COPY | \MyPHP\Transfer\Operation::MOVE, \MyPHP\Transfer\Operation::MOVE));
check($t->dataOver($x, $y, $d), 'the zone takes a note');
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

// video: without a file, it asks for one; a file the system cannot read says why (playing is tests/player_test.php's, on macOS).
[$view, $s] = Headless::load('video', '$openDialog');
$t = new Headless($view, 900, 600);
$t->frame();
check($t->byText('Open a video (Cmd+O) or drop one on the window') !== null, 'the video player asks for a file');
$s->open(__DIR__ . '/testdata/upright.jpg');
$t->frame();
check($s->player === null && $s->error !== '' && $t->byText($s->error) !== null, "a file it cannot play shows why: {$s->error}");

// The video example's subtitles: the text in white over eight copies of it in black around it, which take no pointer.
$t = new Headless(function (Context $c) { $c->column(0, 0, 1); \MyPHP\Examples\Video\subtitle($c, "two\nlines", 20, 40); $c->end(); }, 900, 600);
$t->frame();
same(9, count(array_filter($t->texts(), fn($s) => $s === "two\nlines")), 'the video example outlines its subtitles');

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
