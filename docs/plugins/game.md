# Game

In a view, `SpriteView(scene: $this)` shows a `Game` taking the room it is
given, as SwiftUI's `SpriteView` (`examples/game/view.php`, `examples/game3d/view.php`):

<!-- no-test -->
```php
return fn() => SpriteView(scene: $this);
```

The game plugin (`MyPHP\Plugins\Game`) has what games need beyond what
apps do: MyPHP is an app framework, and these are opt-in, in a namespace of
their own. It is all PHP. Native UI draws a game as it draws the rest:
`Game` runs its loop in a node and `Camera` looks at its world,
`Gamepad\Gamepad` reads its controllers, and the core draws its pixel art
([pixelated bitmaps](../ui/image.md) and [parts of
them](../ui/drawing.md)) and plays its sound ([audio](../audio.md)).

[examples/mayoi](../../examples/mayoi), a little survivor game, is made of
all of them: click it, then walk with WASD or the arrows (`myphp run -d macos
examples/mayoi`). [examples/farm](../../examples/farm) is a calmer one, a farm to
till and water.

## The game loop

A `Game` has two methods: `update()` advances its world one step with the
player's input, and `draw()` draws it. `Game::view()` runs it in a node:

```php
use MyPHP\Plugins\Game\{Game, Input};

final class Farm extends Game
{
    public float $x = 0, $y = 0;   // the player, in the world
    public float $px = 0, $py = 0; // where they were a step before

    public function update(Input $in): void
    {
        [$this->px, $this->py] = [$this->x, $this->y];
        if ($in->down(Key::D)) {
            $this->x += 2;
        }
        if ($in->pressed(Key::SPACE)) {
            $this->y -= 16; // jump
        }
    }

    public function draw(Painter $p, array $r, float $alpha): void
    {
        // Between the last two steps, as far as the frame is into the next.
        $x = $this->px + ($this->x - $this->px) * $alpha;
        $y = $this->py + ($this->y - $this->py) * $alpha;
        $p->fill([$r[0] + $x, $r[1] + 100 + $y, 48, 48], 0x3a7d44, 6);
    }
}

$farm = new Farm();
$view = fn() => SpriteView(scene: $farm);
```

In an app, the view is a window's content (`examples/game/view.php`):

<!-- no-test -->
```php
new Window(new WindowOptions(title: 'Farm', content: Content::file(__DIR__ . '/view.php', $farm)));
```

Steps are fixed, `Game::RATE` (60) a second, or what the game's `rate()`
returns, whatever the display's rate, as Ebitengine and MonoGame run
games: a game plays alike on a display of 60 Hz and one of 120, and its
physics stay stable. `draw()` runs every frame; its `$alpha`, from 0 to 1,
is how far the frame is into the next step, for drawing what moves between
its last two places. After a pause, as a window out of sight, steps catch
up on a quarter of a second at most (`Game::MAX_CATCH_UP`).

### Input

`Input` is the player's input as a step sees it:

| method | reports |
|---|---|
| `down($k)` | key `$k` held |
| `pressed($k)`, `released($k)` | `$k` pressed or released since the step before, even between two steps |
| `pointer()` | `[x, y, inside]`: where the pointer is, from the view's corner, and whether it is over it |
| `pointerDown($b)`, `pointerPressed($b)`, `pointerReleased($b)` | pointer button `$b` (0 the primary) |
| `wheel()` | `[x, y]`: how far the wheel scrolled since the step before |
| `size()` | the view's size |
| `focused()` | the view has the keyboard: a game pauses without it |

Keys are by position: `Key::W` is the key in W's place on any layout, as
WASD wants. The view takes the keyboard focus as it appears and when
clicked, and lets go of everything held when it or the window loses the
keyboard, whose releases never come.

### Testing

A game's `update()` is plain PHP: test its world without a window. The
view runs in the headless harness (`tests/Headless.php`), as any node:
`keyDown()` holds a key between frames, and a step runs once a frame comes
a step's time after the last.

## The camera

A `Camera` looks at the world's point `x, y`, which shows at the middle of
the view, `zoom` times as large, turned `rotation` degrees, as raylib's
`Camera2D`. `draw()` draws a world in its own coordinates through it,
clipped to the view:

<!-- no-test -->
```php
$cam = new Camera($player->x, $player->y, zoom: 3);
$cam->draw($p, $r, function () use ($p, $tiles, $sheet) {
    foreach ($tiles as $t) {
        $p->imageRegion($sheet, $t->src, $t->box); // culled where the camera does not look
    }
});
[$wx, $wy] = $cam->toWorld($r, $px, $py); // the pointer, in the world
```

Unturned, the world moves by whole pixels of the screen, so that tiles
drawn at whole units keep their edges on them, while parts of bitmaps,
placed where they are under a transform, glide. `visible()` returns the
world's part in view, for a game that iterates its tiles itself, and
`transform()` the camera's transform, for `Painter::transform()`.

Zooms that are whole numbers give each of a pixelated bitmap's pixels the
same whole number of the screen's; others keep them square, with edges
blended across one screen pixel. For a whole number at any window size,
draw into a canvas (below).

## Pixel-perfect scaling

For pixel art that is pixel-perfect at any window size, as Godot's
viewport stretch and Stardew Valley draw it, a game draws its world into a
[`Canvas`](../ui/drawing.md) of a logical size and shows it at the largest
whole multiple of that size that fits, letterboxed (`Game::integerFit()`):

<!-- no-test -->
```php
$world = new Canvas(480, 270);
$world->bitmap()->pixelated();

public function draw(Painter $p, array $r, float $alpha): void
{
    $this->world->draw($p, function (Painter $cp) {
        (new Camera($this->x, $this->y))->draw($cp, [0, 0, 480, 270], fn() => $this->drawWorld($cp));
    });
    [$dst] = Game::integerFit($p, 480, 270, $r);
    $p->image($this->world->bitmap(), $dst, Node::FILL_BOX);
}
```

## Gamepads

`Gamepad\Gamepad` reads game controllers, in apps of native UI or web
pages. Every pad reads in one layout, the standard one of the W3C's
Gamepad API and SDL's game controller database, whatever pad it is:
buttons by place, two sticks, analog triggers and a d-pad.

<!-- no-test -->
```php
use MyPHP\Plugins\Game\Gamepad\{Button, Gamepad};

$pads = Gamepad::pads();
if ($pads !== []) {
    $s = $pads[0]->state();
    [$x, $y] = $s->leftStick(); // -1 to 1, right and down, with a dead zone
    $player->move($x, $y);
    if ($this->last !== null && $s->justPressed($this->last, Button::South)) {
        $player->jump();
    }
    $this->last = $s;
}
```

### Pads

`Gamepad::pads()` returns the pads connected, in the order they connected,
and looks for new ones at most once a second; a game calls it once a
frame. A `Pad` lasts from when it connects until it disconnects
(`connected()` turns false, and it leaves `pads()`); plugged in again, it
is a new one. `name()` is what its maker calls it.

`state()` reads a pad's controls now, as a value: keep the last one to
tell a button just pressed (`justPressed()`) or released
(`justReleased()`) from one held.

### The layout

| button | Xbox | PlayStation | Nintendo |
|---|---|---|---|
| `Button::South` | A | Cross | B |
| `East` | B | Circle | A |
| `West` | X | Square | Y |
| `North` | Y | Triangle | X |
| `LeftShoulder`, `RightShoulder` | LB, RB | L1, R1 | L, R |
| `LeftTrigger`, `RightTrigger` | LT, RT | L2, R2 | ZL, ZR |
| `Back`, `Start` | View, Menu | Create, Options | −, + |
| `LeftStickButton`, `RightStickButton` | LS, RS | L3, R3 | |
| `Up`, `Down`, `Left`, `Right` | d-pad | d-pad | d-pad |
| `Guide` | Xbox | PS | Home |

Buttons read from 0 to 1 (`value()`), the triggers by how far they are
pulled; `pressed()` is a quarter of the way. Axes (`axis(Axis::LeftX)`)
read from -1 to 1, right and down positive, as the pad reports them;
`leftStick()` and `rightStick()` apply a radial dead zone
(`Gamepad::DEADZONE`, 0.15), so that a stick at rest reads nothing and
leans as far in every direction.

### Platforms

Pads come from GameController.framework on macOS: Xbox, PlayStation,
Switch Pro and MFi controllers. A pad's input reaches the app in front
only: in the background it reads nothing pressed, as games expect. Other
platforms report no pads yet.

[examples/farm](../../examples/farm) plays with a gamepad as with the
keyboard.
