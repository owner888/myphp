<?php
// The game plugin (mygo's plugins/game tests): the loop's fixed steps and
// input, a game's own rate, the camera, pixel-perfect fitting; the
// gamepads over fake devices.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Game\{Camera, Game, GameAudio, Input, ScreenTransition, SpriteSheet, VirtualPad};
use MyPHP\Plugins\Scene\Camera3D;
use MyPHP\UI\Image;
use MyPHP\Plugins\Game\Gamepad\{Axis, Button, Device, Gamepad, State};
use MyPHP\UI\{Context, DisplayList, Key, Painter, Theme};

/** Records the steps a view runs. */
class GameStepper extends Game
{
    public int $steps = 0, $spacePressed = 0, $wReleased = 0, $clicks = 0, $draws = 0;
    /** @var list<bool> */
    public array $wDown = [], $wPressed = [];
    public bool $focused = false;
    public float $alpha = 0;

    public function update(Input $in): void
    {
        $this->steps++;
        $this->wDown[] = $in->down(Key::W);
        $this->wPressed[] = $in->pressed(Key::W);
        if ($in->pressed(Key::SPACE)) { $this->spacePressed++; }
        if ($in->released(Key::W)) { $this->wReleased++; }
        if ($in->pointerPressed(0)) { $this->clicks++; }
        $this->focused = $in->focused();
    }

    public function draw(Painter $p, array $r, float $alpha): void
    {
        $this->alpha = $alpha;
        $this->draws++;
    }
}

final class GameFast extends GameStepper
{
    public function rate(): int { return 120; }
}

/** A view of $g whose frames are at the test's clock, $now nanoseconds. */
function gameTester(Game $g, int &$now): Headless
{
    $h = new Headless(function (Context $c) use ($g): void { Game::view($c, $g)->size(200, 100); }, 200, 100);
    $h->paints = true;
    $h->rt->clock = function () use (&$now): int { return $now; };
    $h->frame(1);
    return $h;
}

const GAME_MS = 1_000_000;

// The loop.
$g = new GameStepper;
$now = 1000 * 1000 * GAME_MS;
$h = gameTester($g, $now);
check($g->steps === 0 && $g->draws > 0, "first frame: {$g->steps} steps, {$g->draws} draws");
// 100 ms are 6 steps, and a third of the next.
$now += 105 * GAME_MS;
$h->frame(1);
check($g->steps === 6 && $g->focused, "{$g->steps} steps in 105 ms, focused " . json_encode($g->focused));
check(abs($g->alpha - 0.3) < 0.01, "alpha {$g->alpha}");
// Painting again at the same time runs no step.
$h->frame(1);
same(6, $g->steps, 'painting twice runs no step');
// W held: down in every step, pressed in the first only.
$g->wDown = $g->wPressed = [];
$h->input->push(['key', 'w', Key::W, 0]);
$now += 50 * GAME_MS;
$h->frame(1);
check(count($g->wDown) === 3 && $g->wDown[0] && $g->wDown[2] && $g->wPressed[0] && !$g->wPressed[1], 'W held: down ' . json_encode($g->wDown) . ', pressed ' . json_encode($g->wPressed));
// Space tapped between two steps: pressed in the next.
$h->input->push(['key', ' ', Key::SPACE, 0]);
$h->input->push(['keyup', ' ', Key::SPACE, 0]);
$h->frame(1);
$now += 20 * GAME_MS;
$h->frame(1);
same(1, $g->spacePressed, 'Space tapped is pressed in one step');
// Keys with Cmd are the menus'.
$h->input->push(['key', 'q', Key::Q, Key::COMMAND]);
$now += 20 * GAME_MS;
$h->frame(1);
// The window losing the keyboard lets go of W.
$h->rt->windowFocus = false;
$now += 20 * GAME_MS;
$h->frame(1);
check(!$g->focused, 'not focused without the keyboard');
check($g->wReleased === 1 && !end($g->wDown), "W after losing the keyboard: released {$g->wReleased}");
$h->rt->windowFocus = true;
// A click.
$h->click(10, 10);
$now += 20 * GAME_MS;
$h->frame(1);
same(1, $g->clicks, 'a click is pressed in a step');
// After a long pause, a quarter of a second of steps, not all.
$stepsBefore = $g->steps;
$now += 10_000 * GAME_MS;
$h->frame(1);
same(15, $g->steps - $stepsBefore, 'steps after 10 s');
// The pointer over the view, and the wheel.
$in = new Input;
check($in->pointer() === [0.0, 0.0, false] && $in->wheel() === [0.0, 0.0] && !$in->pointerDown(3), 'an input at rest');

// A game's own rate.
$g = new GameFast;
$now = 1000 * 1000 * GAME_MS;
$h = gameTester($g, $now);
$now += 100 * GAME_MS;
$h->frame(1);
same(12, $g->steps, 'steps in 100 ms at 120 a second');

// The camera.
$r = [50.0, 20.0, 400.0, 300.0];
$cam = new Camera(100, 80, zoom: 2);
same([100.0, 80.0], $cam->toWorld($r, 200, 150), 'the middle of the view is the camera\'s point');
same([0.0, 5.0], $cam->toWorld($r, 0, 0), 'the corner');
same([0.0, 5.0, 200.0, 150.0], $cam->visible($r), 'what the camera shows');
// Transform maps the world back to where toWorld found it.
[$wx, $wy] = $cam->toWorld($r, 0, 0);
$pt = $cam->transform($r)->flat()->point($wx, $wy);
check(abs($pt[0] - 50) < 1e-9 && abs($pt[1] - 20) < 1e-9, 'the world\'s corner shows at the view\'s: ' . json_encode($pt));
$turned = new Camera(rotation: 90);
[$wx, $wy] = $turned->toWorld($r, 300, 150);
$pt = $turned->transform($r)->flat()->point($wx, $wy);
check(abs($pt[0] - 350) < 1e-6 && abs($pt[1] - 170) < 1e-6, 'turned: the point shows where toWorld found it: ' . json_encode($pt));

/** A painter over a display list at $scale. */
function gamePainter(float $scale): array
{
    $m = new Metrics;
    $m->scale = $scale;
    $ffi = \FFI::cdef();
    $list = new DisplayList($ffi->new('float[' . (1024 * DisplayList::FLOATS_PER_RECT) . ']'), 1024, $ffi->new('float[' . (64 * DisplayList::FLOATS_PER_TRANSFORM) . ']'), 64);
    $list->pushClip(0, 0, 800, 600);
    return [new Painter($list, $m, Theme::light()), $list];
}

// Pixel-perfect fitting.
[$p] = gamePainter(2);
// 1000×600 points at 2×: 2000×1200 pixels, for 480×270: 4 times (1920×1080).
same([[30.0, 50.0, 960.0, 540.0], 4], Game::integerFit($p, 480, 270, [10.0, 20.0, 1000.0, 600.0]), 'fit at 2×');
// Smaller than the canvas: one pixel each, overflowing.
[$small, $k] = Game::integerFit($p, 480, 270, [0.0, 0.0, 100.0, 100.0]);
check($k === 1 && $small[2] == 240, 'fit in a small view: ' . json_encode([$small, $k]));
same([[0.0, 0.0, 0.0, 0.0], 0], Game::integerFit($p, 0, 270, [0.0, 0.0, 100.0, 100.0]), 'nothing to fit');

// Through an unturned camera, the world moves by whole pixels, and is clipped to the view.
[$p, $list] = gamePainter(2);
$seen = null;
(new Camera(10.3, 7.77, zoom: 3))->draw($p, [0.0, 0.0, 400.0, 300.0], function () use ($list, &$seen): void { $seen = [$list->world(), $list->visible()]; });
[$m, $vis] = $seen;
check(abs($m->a - 3) < 1e-9 && abs($m->d - 3) < 1e-9, 'zoomed 3 times');
check(abs($m->x * 2 - round($m->x * 2)) < 1e-9 && abs($m->y * 2 - round($m->y * 2)) < 1e-9, "moved by whole pixels: {$m->x}, {$m->y}");
check(abs($m->x - (200 - 10.3 * 3)) <= 0.25 && abs($m->y - (150 - 7.77 * 3)) <= 0.25, 'about where the camera looks');
check(abs($vis[2] - 400 / 3) < 1e-6, 'clipped to the view, in the world: ' . json_encode($vis));
same(false, $list->world()->set, 'the transform ends with the drawing');

// Gamepads, over devices a test sets.
final class GameFakeDevice implements Device
{
    public State $state;
    public bool $gone = false, $closed = false;
    public function __construct(public string $id) { $this->state = new State; }
    public function key(): string { return $this->id; }
    public function name(): string { return "Fake {$this->id}"; }
    public function read(): ?State { return $this->gone ? null : $this->state; }
    public function close(): void { $this->closed = true; }
}
$a = new GameFakeDevice('a');
$b = new GameFakeDevice('b');
$devs = [$a];
Gamepad::reset();
Gamepad::$scanEach = 0;
Gamepad::$scan = function (array $known) use (&$devs): array { return $devs; };
$ps = Gamepad::pads();
check(count($ps) === 1 && $ps[0]->name() === 'Fake a' && $ps[0]->connected(), 'one pad');
$first = $ps[0];
$devs = [$b, $a];
$ps = Gamepad::pads();
check(count($ps) === 2 && $ps[0] === $first && $ps[1]->name() === 'Fake b', 'pads in connecting order');
// Unplugged between scans: reading finds it gone.
$a->gone = true;
$a->state->buttons[Button::South->value] = 1;
check(!$first->state()->pressed(Button::South) && !$first->connected() && $a->closed, 'a pad gone reads nothing and is closed');
// Gone from a scan.
$devs = [];
same([], Gamepad::pads(), 'no pads after unplugging');
check($b->closed, 'the pad gone from the scan is closed');
Gamepad::$scan = null;
Gamepad::$scanEach = 1.0;
Gamepad::reset();

// States.
$last = new State;
$s = new State;
$s->buttons[Button::South->value] = 1;
$s->buttons[Button::LeftTrigger->value] = 0.2;
$s->buttons[Button::RightTrigger->value] = 0.3;
check($s->pressed(Button::South) && !$s->pressed(Button::LeftTrigger) && $s->pressed(Button::RightTrigger), 'pressed, a trigger a quarter of the way');
check($s->justPressed($last, Button::South) && !$s->justPressed($s, Button::South) && $last->justReleased($s, Button::South), 'just pressed, just released');
// Within the dead zone, nothing; at the rim, all the way, in every direction alike.
[$s->axes[Axis::LeftX->value], $s->axes[Axis::LeftY->value]] = [0.1, 0.05];
same([0.0, 0.0], $s->leftStick(), 'a stick at rest');
[$s->axes[Axis::LeftX->value], $s->axes[Axis::LeftY->value]] = [0.7071, 0.7071];
[$x, $y] = $s->leftStick();
check(abs(hypot($x, $y) - 1) < 1e-3, 'the diagonal rim is all the way');
$s->axes[Axis::RightX->value] = -(Gamepad::DEADZONE + (1 - Gamepad::DEADZONE) / 2);
check(abs($s->rightStick()[0] + 0.5) < 1e-4, 'half way past the dead zone');
same(0.0, $s->axis(Axis::RightY), 'an axis at rest');

// 1. Hit-stop (freeze-frame) & 2D/3D Camera Shake.
$g = new GameStepper;
$now = 1000 * 1000 * GAME_MS;
$h = gameTester($g, $now);
$g->hitStop(3);
$now += 100 * GAME_MS; // 6 steps of time, 3 frozen by hitStop -> 3 steps executed
$h->frame(1);
same(3, $g->steps, 'hitStop(3) freezes 3 logic ticks out of 6');
same(0, $g->hitStopSteps, 'hitStopSteps drained to 0');

$cam2d = new Camera(100, 100);
$cam2d->shake(6.0, 0.25);
$cam2d->step(0.05);
check($cam2d->shakeOffsetX != 0.0 || $cam2d->shakeOffsetY != 0.0, '2D camera shake offsets active');
$cam2d->step(0.25);
same([0.0, 0.0], [$cam2d->shakeOffsetX, $cam2d->shakeOffsetY], '2D camera shake decays back to zero');

$cam3d = new Camera3D();
$cam3d->shake(0.3, 0.2);
$cam3d->step(0.04);
check($cam3d->shakeOffset->length() > 0.0, '3D camera shake offset active');
$cam3d->step(0.20);
same(0.0, $cam3d->shakeOffset->length(), '3D camera shake decays back to zero');

// 2. VirtualPad touch controller & synthetic key injection.
$vpad = new VirtualPad();
$vin = new Input();
$vin->w = 800.0;
$vin->h = 600.0;
// Press right side of D-Pad (center is at x = 28 + 56 = 84, y = 600 - 32 - 56 = 512)
$vin->buttons[0] = true;
$vin->x = 124.0;
$vin->y = 512.0;
$vpad->step($vin);
check($vpad->right && $vpad->axisX > 0.5 && $vin->down(Key::RIGHT) && $vin->pressed(Key::RIGHT), 'VirtualPad D-pad right injects Key::RIGHT');
[$p] = gamePainter(1);
$vpad->draw($p, [0.0, 0.0, 800.0, 600.0]);
// Release touch
$vin->step();
$vin->buttons[0] = false;
$vpad->step($vin);
check(!$vpad->right && !$vin->down(Key::RIGHT) && $vin->released(Key::RIGHT), 'VirtualPad release emits Key::RIGHT released');

// 3. SpriteSheet & SpriteAnimation.
$fakeImg = Image::fromPixels(64, 32, str_repeat("\xFF\x00\x00\xFF", 64 * 32));
$sheet = new SpriteSheet($fakeImg, tileWidth: 16, tileHeight: 16);
same(4, $sheet->columns, 'SpriteSheet columns');
same(2, $sheet->rows, 'SpriteSheet rows');
same([32.0, 16.0, 16.0, 16.0], $sheet->rect(2, 1), 'SpriteSheet tile rect(2, 1)');
$anim = $sheet->animation(row: 0, fromCol: 0, count: 4, stepTime: 0.1, loop: false);
same(0, $anim->currentIndex, 'SpriteAnimation initial frame');
$anim->step(0.25);
same(2, $anim->currentIndex, 'SpriteAnimation after 0.25s');
$anim->step(0.20);
check($anim->currentIndex === 3 && $anim->done(), 'SpriteAnimation non-looping finishes at last frame');

// 4. GameAudio procedural synth & SFX pool.
$audio = new GameAudio();
$sound = $audio->registerTone('hit', startFreq: 520.0, endFreq: 180.0, duration: 0.08, square: true);
check($sound->frames() > 3000, 'GameAudio synthesized retro tone frames');
$audio->muted = true;
same(null, $audio->playSfx('hit'), 'GameAudio muted returns null player');
check(\MyPHP\Audio\Output::holding(), 'GameAudio holds the audio output open while it lives');
unset($audio);
check(!\MyPHP\Audio\Output::holding(), 'and lets go of it when it goes');

// 5. ScreenTransition (Fade, Battle Blinds, Shutter).
$trans = new ScreenTransition();
$midCalled = $doneCalled = false;
$trans->start(
    ScreenTransition::BATTLE_BLINDS,
    duration: 0.4,
    onMidpoint: function () use (&$midCalled): void { $midCalled = true; },
    onComplete: function () use (&$doneCalled): void { $doneCalled = true; }
);
$trans->step(0.10);
$trans->draw($p, [0.0, 0.0, 800.0, 600.0]);
check($trans->active && !$midCalled, 'ScreenTransition before midpoint');
$trans->step(0.15); // total 0.25 >= 0.20 midpoint
$trans->draw($p, [0.0, 0.0, 800.0, 600.0]);
check($midCalled && !$doneCalled, 'ScreenTransition fired onMidpoint at 50%');
$trans->step(0.20); // total 0.45 >= 0.40 duration
check(!$trans->active && $doneCalled, 'ScreenTransition completed and fired onComplete');
