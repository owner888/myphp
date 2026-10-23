<?php
// A little game on the game plugin: walk a meadow with WASD, the arrows or
// a gamepad's left stick, the camera following; Space or the gamepad's
// South button jumps. The world is tiles of a pixel-art sheet made here,
// drawn pixelated through the camera, only those in view; the wheel zooms.
// Run: ./run.sh game
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Game\{Camera, Game, Input};
use MyPHP\Plugins\Game\Gamepad\{Button, Gamepad, State};
use MyPHP\UI\{Content, Context, Image, Key, Node, Painter};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** A sheet of 16×16 tiles: grass, flowers, water, and the walker. */
function sheet(): Image
{
    $tile = function (int $i, int $x, int $y): ?array {
        $n = crc32("$i:$x:$y") % 100;
        return match ($i) {
            0 => $n < 12 ? [0x4c, 0x9a, 0x3a] : [0x5c, 0xb0, 0x45],
            1 => ($x - 8) ** 2 + ($y - 8) ** 2 < 6 ? [0xf2, 0xd1, 0x4b] : (($x - 8) ** 2 + ($y - 8) ** 2 < 14 ? [0xe8, 0x6f, 0x9a] : [0x5c, 0xb0, 0x45]),
            2 => $n < 10 ? [0x8f, 0xc7, 0xf0] : [0x3a, 0x86, 0xc8],
            default => ($x > 3 && $x < 12 && $y > 1 && $y < 15) ? ($y < 6 ? [0xf5, 0xc8, 0x9a] : [0xc0, 0x39, 0x2b]) : null,
        };
    };
    $px = '';
    for ($y = 0; $y < 16; $y++) {
        for ($i = 0; $i < 4; $i++) {
            for ($x = 0; $x < 16; $x++) {
                $c = $tile($i, $x, $y);
                $px .= $c === null ? "\0\0\0\0" : chr($c[0]) . chr($c[1]) . chr($c[2]) . "\xff";
            }
        }
    }
    return Image::fromPixels(64, 16, $px)->pixelated();
}

final class Meadow extends Game
{
    public float $x = 0, $y = 0, $px = 0, $py = 0, $z = 0, $vz = 0, $zoom = 3;
    private State $last;

    public function __construct(private readonly Image $sheet)
    {
        $this->last = new State;
    }

    private static function tile(int $tx, int $ty): int
    {
        $n = crc32("$tx,$ty") % 100;
        return $n < 6 ? 2 : ($n < 16 ? 1 : 0);
    }

    public function update(Input $in): void
    {
        $this->px = $this->x;
        $this->py = $this->y;
        if (!$in->focused()) {
            return; // the player is elsewhere
        }
        $dx = ($in->down(Key::D) || $in->down(Key::RIGHT) ? 1 : 0) - ($in->down(Key::A) || $in->down(Key::LEFT) ? 1 : 0);
        $dy = ($in->down(Key::S) || $in->down(Key::DOWN) ? 1 : 0) - ($in->down(Key::W) || $in->down(Key::UP) ? 1 : 0);
        $jump = $in->pressed(Key::SPACE);
        foreach (array_slice(Gamepad::pads(), 0, 1) as $pad) {
            $s = $pad->state();
            [$sx, $sy] = $s->leftStick();
            $dx += $sx;
            $dy += $sy;
            $jump = $jump || $s->justPressed($this->last, Button::South);
            $this->last = $s;
        }
        $this->x += 1.5 * max(-1, min(1, $dx));
        $this->y += 1.5 * max(-1, min(1, $dy));
        if ($jump && $this->z === 0.0) {
            $this->vz = 3;
        }
        $this->z = max(0.0, $this->z + $this->vz);
        $this->vz = $this->z > 0 ? $this->vz - 0.25 : 0;
        [, $wy] = $in->wheel();
        $this->zoom = max(1, min(8, $this->zoom - $wy / 40));
    }

    public function draw(Painter $p, array $r, float $alpha): void
    {
        // Between the last two steps, as far as the frame is into the next.
        $x = $this->px + ($this->x - $this->px) * $alpha;
        $y = $this->py + ($this->y - $this->py) * $alpha;
        $cam = new Camera($x, $y, zoom: round($this->zoom));
        $p->fill($r, 0x2d6a8f);
        $cam->draw($p, $r, function () use ($p, $cam, $r, $x, $y): void {
            [$vx, $vy, $vw, $vh] = $cam->visible($r);
            for ($ty = (int)floor($vy / 16); $ty * 16 < $vy + $vh; $ty++) {
                for ($tx = (int)floor($vx / 16); $tx * 16 < $vx + $vw; $tx++) {
                    $p->imageRegion($this->sheet, [self::tile($tx, $ty) * 16, 0, 16, 16], [$tx * 16.0, $ty * 16.0, 16.0, 16.0]);
                }
            }
            $p->fill([$x - 5, $y + 5, 10, 3], 0x000000, 1.5, 0.25);
            $p->imageRegion($this->sheet, [48, 0, 16, 16], [$x - 8, $y - 8 - $this->z, 16, 16]);
        });
        $p->text($r[0] + 12, $r[1] + 10, sprintf('%.0f, %.0f   zoom %d   %d gamepads', $x, $y, round($this->zoom), count(Gamepad::pads())), 12, 0xffffff);
    }
}

App::whenReady(function () {
    $meadow = new Meadow(sheet());
    new Window(new WindowOptions(title: 'Meadow', width: 720, height: 480, content: Content::view(function (Context $c) use ($meadow): void {
        Game::view($c, $meadow)->grow = 1;
    })));
});
App::run();
