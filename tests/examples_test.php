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

// video: a player's window; a file the system cannot read says why (playing is tests/player_test.php's, on macOS).
Headless::load('video');
$s = new \MyPHP\UI\MoviePlayerState();
$t = new Headless(function (Context $c) use ($s) { \MyPHP\Examples\Video\view($c, $s); }, 900, 600);
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

// chart-streaming: three waveforms, a few points per pixel column, a capsule each segment; path and raw as strokePath strokes them.
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
check($t->byLabel('Three live waveforms') !== null, 'chart-streaming: the plot named');
foreach (['path', 'raw'] as $mode) {
    $w = new \MyPHP\Examples\ChartStreaming\Waveform($mode);
    [$kinds] = $csKinds(fn(Context $c) => \MyPHP\Examples\ChartStreaming\view($c, $w));
    check(count($kinds) === 3 && array_filter($kinds, fn(array $k) => isset($k[9])) === [], "chart-streaming $mode: stroked paths, no capsules");
}

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
        $rt = $c->runtime;
        $c->column()->fill()->draw(function (\MyPHP\UI\DisplayList $list, \MyPHP\UI\Node $n, \MyPHP\UI\Theme $th, \MyPHP\UI\TextPainter $text) use ($tex, $rt): void {
            $tex->draw(new \MyPHP\UI\Painter($list, $text, $th, $rt), [$n->x, $n->y, $n->w, $n->h], false);
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
$t = new Headless(fn(Context $c) => \MyPHP\Examples\Blackhole\view($c, $q), 1200, 760);
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
$t = new Headless(fn(Context $c) => \MyPHP\Examples\ChatGlow\view($c, $a), 860, 700);
$t->frame();
$t->type('Hello there'); $t->enter();
check(count($a->messages) === 2 && $a->messages[0]->text === 'Hello there' && $a->input->text === '', 'chat-glow: Return sends the question');
$t->escape();
check(!$a->busy && $a->messages[1]->shown === 1 && $a->messages[1]->words[0] === 'Stopped.', 'chat-glow: Escape stops the reply');
// TestSplitWords: splitWords keeps the spaces and line breaks, which joined give the text back.
foreach (['one', 'one two', "a  b\n\nc ", "Day 1: x.\n\nDay 2"] as $text) {
    same($text, implode('', \MyPHP\Examples\ChatGlow\splitWords($text)), 'chat-glow: splitWords and back');
}
same(4, count(\MyPHP\Examples\ChatGlow\splitWords("a b  c\n\nd")), 'chat-glow: four words');
// TestScrollsToEnd: once the conversation is longer than the window, it shows its end, the whole of the last reply above the composer, not under it.
$a = new \MyPHP\Examples\ChatGlow\ChatGlow($cgSh);
$t = poseTester(fn(Context $c) => \MyPHP\Examples\ChatGlow\view($c, $a), $now, 860, 500);
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
check($a->list->atEnd && $r[3] > 0 && $r[1] + $r[3] <= $limit, 'chat-glow: the end of the conversation in view: ' . json_encode([$r, $limit]));
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
$t = poseTester(fn(Context $c) => \MyPHP\Examples\ChatGlow\view($c, $a), $now, 860, 500);
$t->clickText('Summarize my week');
$a->step(60);
$t->frame();
[$r, $limit] = $cgEnd();
check($r[1] + $r[3] <= $limit, 'chat-glow: the reply that came at once in view at once: ' . json_encode([$r, $limit]));
