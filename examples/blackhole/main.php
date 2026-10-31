<?php
// Black hole is a black hole and its accretion disk (mygo's
// examples/blackhole), drawn by shaders written in PHP (UI\Shader)
// on the GPU: every pixel's ray of light is traced back through the hole's
// gravity, so the disk's far side shows above and below the shadow, the
// side turning toward the camera blazes brighter and bluer, and the stars
// behind smear into arcs around an Einstein ring.
//
// Drag to orbit, scroll to come closer. Three passes draw a frame: the
// scene into a canvas, its brightest light blurred into a smaller one, and
// both over the window with a lens's color fringes and a film's grain.
// The scene renders at a resolution of its own, which drops while frames
// come late and rises again once they keep up, as games do.
// Run: ./run.sh blackhole
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Canvas, Content, Context, Cursor, InputEvent, Node, Painter};
use MyPHP\UI\Shader\{Options, Shader, Uniform, Values};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** A point or a direction of the scene, y up, in radii of the hole. */
final class Vec3
{
    public function __construct(public readonly float $x, public readonly float $y, public readonly float $z) {}

    public function mul(float $k): self { return new self($this->x * $k, $this->y * $k, $this->z * $k); }
    public function dot(self $b): float { return $this->x * $b->x + $this->y * $b->y + $this->z * $b->z; }
    public function norm(): self { return $this->mul(1 / sqrt($this->dot($this))); }

    public function cross(self $b): self
    {
        return new self($this->y * $b->z - $this->z * $b->y, $this->z * $b->x - $this->x * $b->z, $this->x * $b->y - $this->y * $b->x);
    }
}

// FOCAL is the camera's focal length, in heights of the view.
const FOCAL = 1.5;

/**
 * Orbits the hole: its angles about the disk's axis (yaw) and above the
 * disk (pitch), in radians, its distance, and how fast the angles turn on
 * their own, after a drag's throw.
 */
final class Camera
{
    public function __construct(
        public float $yaw = 0,
        public float $pitch = 0,
        public float $dist = 0,
        public float $spinYaw = 0,
        public float $spinPitch = 0,
    ) {}

    /** Where the camera is and its right, up and forward axes. @return array{0: Vec3, 1: Vec3, 2: Vec3, 3: Vec3} */
    public function axes(): array
    {
        $cp = cos($this->pitch);
        $eye = (new Vec3($cp * sin($this->yaw), sin($this->pitch), $cp * cos($this->yaw)))->mul($this->dist);
        $forward = $eye->mul(-1)->norm();
        $right = $forward->cross(new Vec3(0, 1, 0))->norm();
        $up = $right->cross($forward);
        return [$eye, $right, $up, $forward];
    }

    /** Whether the camera still turns on its own. */
    public function spinning(): bool
    {
        return abs($this->spinYaw) > 1e-3 || abs($this->spinPitch) > 1e-3;
    }
}

// The camera's distances from the hole, in its radii: nearest, at rest and
// farthest (within the shader's escape, 42); and its pitch's bounds, short
// of the poles.
const NEAR = 8;
const REST = 22;
const FAR = 36;
const MAX_PITCH = 1.4;
// ORBIT_SPEED is how fast the camera circles on its own, in radians a
// second, and SETTLE how quickly a throw slows to it, a second.
const ORBIT_SPEED = 0.025;
const SETTLE = 1.2;
// The scene's resolution, a fraction of the window's pixels across, from
// MIN_QUALITY up: it drops by a step while frames come later than
// SLOW_FRAME, and rises once they come sooner than FAST_FRAME for a while.
// The glow renders at 1/GLOW_SCALE of it.
const MIN_QUALITY = 0.5;
const QUALITY_STEP = 0.125;
const SLOW_FRAME = 1.0 / 50;
const FAST_FRAME = 1.0 / 90;
const GLOW_SCALE = 4;

// The text: white, and muted, in their opacities.
const WHITE_ALPHA = 0.92;
const MUTED_ALPHA = 0.5;

/** The shaders: the scene, its glow, and the pass that shows both. */
final class Shaders
{
    public readonly Shader $hole, $bloom, $post;

    public function __construct()
    {
        $this->hole = Shader::compile(file_get_contents(__DIR__ . '/blackhole.kage'));
        $this->bloom = Shader::compile(file_get_contents(__DIR__ . '/bloom.kage'));
        $this->post = Shader::compile(file_get_contents(__DIR__ . '/post.kage'));
    }
}

/** The window's state. */
final class BlackHole
{
    public Camera $cam;
    /** The disk's clock, which stands still while the desktop asks for less motion; $last is the frame's time, in nanoseconds. */
    public float $time = 0;
    public ?int $last = null;
    /**
     * The pointer's drag: whether the primary button is down, where it
     * last was and when; $touched tells that the user moved the camera,
     * and the hint fades.
     */
    public bool $down = false;
    public float $lastX = 0, $lastY = 0;
    public int $lastMove = 0;
    public bool $touched = false;
    public float $hint = 1;
    /**
     * The passes' canvases: the scene, a fraction ($quality) of the
     * window's pixels across, and its glow; $frame is how long frames
     * take, smoothed, and $slow and $fast how long they took longer or
     * shorter than they should.
     */
    public ?Canvas $scene = null, $glow = null;
    public float $quality = 1, $frame = 1.0 / 60, $slow = 0, $fast = 0;

    public Values $values, $postValues;
    public Uniform $timeVar, $eyeVar, $rightVar, $upVar, $fwdVar, $focVar, $postTime;

    public function __construct(public readonly Shaders $sh)
    {
        $this->cam = new Camera(yaw: 0.6, pitch: 0.1, dist: REST, spinYaw: ORBIT_SPEED);
        $this->values = $sh->hole->newValues();
        [$this->timeVar, $this->eyeVar, $this->rightVar] = [$sh->hole->uniform('Time'), $sh->hole->uniform('Eye'), $sh->hole->uniform('Right')];
        [$this->upVar, $this->fwdVar, $this->focVar] = [$sh->hole->uniform('Up'), $sh->hole->uniform('Forward'), $sh->hole->uniform('Focal')];
        $this->postValues = $sh->post->newValues();
        $this->postTime = $sh->post->uniform('Time');
    }

    /**
     * Moves the scene on to the frame's time and draws it in its three
     * passes.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    public function draw(Painter $p, array $r, bool $reduce): void
    {
        $now = $p->now();
        $dt = $this->last === null ? 0.0 : min(($now - $this->last) / 1e9, 0.1);
        $this->last = $now;
        if (!$reduce) {
            $this->time += $dt;
        }
        $this->step($dt, $reduce);

        $scale = $p->scale();
        // A canvas is at most Canvas::MAX pixels on a side, what every GPU's
        // textures allow: a window larger renders the scene at that, as at
        // a lower quality.
        $fit = min(1.0, Canvas::MAX / ($r[2] * $scale * $this->quality), Canvas::MAX / ($r[3] * $scale * $this->quality));
        $w = max(1, (int)round($r[2] * $scale * $this->quality * $fit));
        $h = max(1, (int)round($r[3] * $scale * $this->quality * $fit));
        $this->scene = self::sized($this->scene, $w, $h);
        $this->glow = self::sized($this->glow, max(1, intdiv($w, GLOW_SCALE)), max(1, intdiv($h, GLOW_SCALE)));

        [$eye, $right, $up, $forward] = $this->cam->axes();
        $this->values->float($this->timeVar, $this->time)->float($this->focVar, FOCAL);
        foreach ([[$this->eyeVar, $eye], [$this->rightVar, $right], [$this->upVar, $up], [$this->fwdVar, $forward]] as [$u, $d]) {
            $this->values->vec3($u, $d->x, $d->y, $d->z);
        }
        $hole = new Options(values: $this->values);
        $this->scene->draw($p, fn(Painter $cp) => $this->sh->hole->draw($cp, [0, 0, $w, $h], $hole));
        [$gw, $gh] = $this->glow->size();
        $bloom = new Options(images: [$this->scene->bitmap()]);
        $this->glow->draw($p, fn(Painter $cp) => $this->sh->bloom->draw($cp, [0, 0, $gw, $gh], $bloom));
        $this->sh->post->draw($p, $r, new Options(
            values: $this->postValues->float($this->postTime, $this->time),
            images: [$this->scene->bitmap(), $this->glow->bitmap()],
        ));
        if (!$reduce || $this->down || $this->cam->spinning() || ($this->touched && $this->hint > 0)) {
            $p->animationFrame();
        }
    }

    /** $cv, or a canvas of $w×$h pixels where $cv is not one. */
    private static function sized(?Canvas $cv, int $w, int $h): Canvas
    {
        return $cv !== null && $cv->w === $w && $cv->h === $h ? $cv : new Canvas($w, $h);
    }

    /**
     * Moves the camera on by $dt seconds, fades the hint once the user
     * moved it, and fits the resolution to how fast frames come.
     */
    public function step(float $dt, bool $reduce): void
    {
        $cam = $this->cam;
        if (!$this->down) {
            $goal = $reduce ? 0 : ORBIT_SPEED;
            $k = 1 - exp(-$dt * SETTLE);
            $cam->spinYaw += ($goal - $cam->spinYaw) * $k;
            $cam->spinPitch -= $cam->spinPitch * min(1, 2 * $k);
            $cam->yaw += $cam->spinYaw * $dt;
            $cam->pitch = self::clamp($cam->pitch + $cam->spinPitch * $dt, -MAX_PITCH, MAX_PITCH);
        }
        if ($this->touched) {
            $this->hint = max(0, $this->hint - $dt / 0.6);
        }

        if ($dt <= 0) {
            return;
        }
        $this->frame += ($dt - $this->frame) * 0.1;
        $this->slow = $this->frame > SLOW_FRAME ? $this->slow + $dt : 0;
        $this->fast = $this->frame < FAST_FRAME ? $this->fast + $dt : 0;
        if ($this->slow > 0.5 && $this->quality > MIN_QUALITY) {
            $this->quality = max(MIN_QUALITY, $this->quality - QUALITY_STEP);
            [$this->slow, $this->frame] = [0, FAST_FRAME];
        } elseif ($this->fast > 3 && $this->quality < 1) {
            $this->quality = min(1, $this->quality + QUALITY_STEP);
            $this->fast = 0;
        }
    }

    /**
     * Takes the pointer's drags and scrolls: a drag turns the camera
     * around the hole, and a throw keeps it turning, slowing down to its
     * own orbit.
     */
    public function input(InputEvent $ev): bool
    {
        $cam = $this->cam;
        $now = hrtime(true);
        switch ($ev->kind) {
            case InputEvent::POINTER_DOWN:
                if ($ev->button !== 0) {
                    return false;
                }
                [$this->down, $this->touched] = [true, true];
                [$this->lastX, $this->lastY, $this->lastMove] = [$ev->x, $ev->y, $now];
                [$cam->spinYaw, $cam->spinPitch] = [0, 0];
                return true;
            case InputEvent::POINTER_MOVE:
                if (!$this->down) {
                    return false;
                }
                $dt = max(($now - $this->lastMove) / 1e9, 1.0 / 240);
                [$dyaw, $dpitch] = [-($ev->x - $this->lastX) * 0.005, ($ev->y - $this->lastY) * 0.004];
                $cam->yaw += $dyaw;
                $cam->pitch = self::clamp($cam->pitch + $dpitch, -MAX_PITCH, MAX_PITCH);
                // The throw: the drag's speed, smoothed over its last moves.
                $k = min(1, $dt / 0.06);
                $cam->spinYaw += ($dyaw / $dt - $cam->spinYaw) * $k;
                $cam->spinPitch += ($dpitch / $dt - $cam->spinPitch) * $k;
                [$this->lastX, $this->lastY, $this->lastMove] = [$ev->x, $ev->y, $now];
                return true;
            case InputEvent::POINTER_UP:
                if (!$this->down) {
                    return false;
                }
                $this->down = false;
                if ($now - $this->lastMove > 80_000_000) {
                    // Held still before letting go: no throw.
                    [$cam->spinYaw, $cam->spinPitch] = [0, 0];
                }
                return true;
            case InputEvent::SCROLL:
                $this->touched = true;
                $cam->dist = self::clamp($cam->dist * exp($ev->dy * 0.003), NEAR, FAR);
                return true;
        }
        return false;
    }

    private static function clamp(float $x, float $lo, float $hi): float
    {
        return max($lo, min($hi, $x));
    }
}

function view(Context $c, BlackHole $a): void
{
    $reduce = $c->preferences()->reduceMotion;
    $root = $c->row(0, 0, 0, 0x000000)->fill()->alignItems(Node::END)->padding(0, 28, 26, 30);
    $root->cursor($a->down ? Cursor::Grabbing : Cursor::Grab);
    $root->draw(fn(Painter $p, array $r) => $a->draw($p, $r, $reduce));
    $root->handleInput($a->input(...));
      $c->column(0, 4);
        $c->text('Gargantua', 22)->fontWeight(600)->textColor(0xffffff, WHITE_ALPHA);
        $c->text('A black hole, its light traced through gravity by shaders written in PHP', 12)->textColor(0xffffff, MUTED_ALPHA);
      $c->end();
      $c->spacer();
      $c->text('Drag to orbit · Scroll to zoom', 12)->textColor(0xffffff, MUTED_ALPHA)->opacity($a->hint);
    $c->end();
}

$s = new BlackHole(new Shaders());
App::setName('Black Hole');
App::whenReady(function () use ($s): void {
    new Window(new WindowOptions(title: 'Black Hole', width: 1200, height: 760, titleBarStyle: 'hiddenInset', backgroundColor: '#000000',
        content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
