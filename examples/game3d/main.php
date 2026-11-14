<?php
declare(strict_types=1);

// 3D Crystal Collector Game built on MyPHP's Game Loop + 5-Platform Native 3D Scene:
// - Move the 3D Hero with WASD / Arrow Keys / Gamepad Left Stick
// - Space / Gamepad South Button to Jump
// - Collect glowing 3D Crystals (each with a dynamic Point Light & orbiting energy ring!)
// - Avoid patrolling 3D Sentinels
// - Real-time 1024x1024 PCF Soft Shadows on the tiled arena floor
// Run: myphp run -d macos|windows|linux|ios|android examples/game3d

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Game\{Game, Input};
use MyPHP\Plugins\Game\Gamepad\{Button, Gamepad, State};
use MyPHP\Plugins\Scene\{Camera3D, Entity, Material, Mesh, ParticleEmitter3D, PointLight, Scene, Tween3D};
use MyPHP\Plugins\Scene\Math\{Mat4, Vec3};
use MyPHP\UI\{Canvas, Content, Context, Image, Key, Node, Painter};

class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

function makeArenaTexture(): Image
{
    $px = '';
    for ($y = 0; $y < 64; $y++) {
        for ($x = 0; $x < 64; $x++) {
            $grid = ($x % 16 === 0) || ($y % 16 === 0);
            $check = ((($x >> 4) + ($y >> 4)) & 1) === 0;
            if ($grid) {
                $px .= "\x18\x20\x2c\xff";
            } elseif ($check) {
                $px .= "\x28\x32\x42\xff";
            } else {
                $px .= "\x20\x28\x36\xff";
            }
        }
    }
    return Image::fromPixels(64, 64, $px)->pixelated();
}

const GEM_OBJ = <<<'OBJ'
v  0.0  0.75  0.0
v  0.45 0.0   0.45
v  0.45 0.0  -0.45
v -0.45 0.0  -0.45
v -0.45 0.0   0.45
v  0.0 -0.75  0.0
f 1 2 3
f 1 3 4
f 1 4 5
f 1 5 2
f 6 3 2
f 6 4 3
f 6 5 4
f 6 2 5
OBJ;

final class CrystalRunner3D extends Game
{
    public Scene $scene;
    public Canvas $viewport;

    public Entity $player;
    public Entity $playerHalo;
    public Entity $gem;
    public Entity $gemOrbiter;
    /** @var list<Entity> */
    public array $sentinels = [];
    /** @var list<float> */
    public array $sentinelAngles = [];

    public PointLight $gemLight;
    public PointLight $playerLight;
    public ParticleEmitter3D $particles;

    public float $px = 0.0, $pz = 2.5;
    public float $py = 0.0, $vy = 0.0;
    public int $score = 0;
    public int $highScore = 0;
    public float $time = 0.0;
    public float $flashTimer = 0.0;

    private State $lastPad;

    public function __construct()
    {
        $this->lastPad = new State();
        $this->viewport = new Canvas(800, 600);

        $this->scene = new Scene(new Camera3D(
            position: new Vec3(0.0, 7.2, 9.625),
            target: new Vec3(0.0, 0.0, 0.875),
            fovYDegrees: 52.0
        ));
        $this->scene->clearColor = [0.04, 0.05, 0.08, 1.0];
        $this->scene->shadowsEnabled = true;
        $this->scene->shadowBounds = 10.0;
        $this->scene->fogEnabled = true;
        $this->scene->fogDensity = 0.035;

        $this->particles = $this->scene->addEmitter(new ParticleEmitter3D(Mesh::cube(1.0), maxParticles: 320));

        // 1. Arena Floor
        $floor = new Entity(
            Mesh::plane(16.0, 16.0, uvRepeat: 8.0),
            Material::textured(makeArenaTexture(), shininess: 24.0),
            position: new Vec3(0.0, -0.5, 0.0),
            name: 'ArenaFloor'
        );
        $floor->castShadow = false;
        $this->scene->add($floor);

        // 2. Arena Border Pillars (Instanced: 32 pillars in 1 Draw Call)
        $pillarEntity = new Entity(Mesh::cube(0.55), new Material([0.4, 0.5, 0.65, 1.0], shininess: 48.0));
        $transforms = [];
        $colors = [];
        for ($i = 0; $i < 32; $i++) {
            $a = ($i / 32) * 2.0 * M_PI;
            $rx = cos($a) * 7.6;
            $rz = sin($a) * 7.6;
            $transforms[] = Mat4::translation($rx, 0.1, $rz)->multiply(Mat4::scaling(1.0, 2.2, 1.0));
            $colors[] = [0.35, 0.45, 0.6, 1.0];
        }
        $pillarEntity->setInstances($transforms, $colors);
        $this->scene->add($pillarEntity);

        // 3. Player Hero (with child Halo orbiting above head!)
        $this->player = new Entity(
            Mesh::sphere(0.55, 24, 16, [0.2, 0.85, 1.0, 1.0]),
            new Material([0.2, 0.85, 1.0, 1.0], shininess: 80.0),
            position: new Vec3(0.0, 0.05, 2.5),
            name: 'Hero'
        );
        $this->playerHalo = new Entity(
            Mesh::cube(0.22, [1.0, 0.95, 0.4, 1.0]),
            new Material([1.0, 0.95, 0.4, 1.0], shininess: 96.0),
            position: new Vec3(0.0, 0.85, 0.0)
        );
        $this->player->addChild($this->playerHalo);
        $this->scene->add($this->player);

        // 4. Collectible 3D Gem (OBJ mesh + child orbiter + PointLight)
        $this->gem = new Entity(
            Mesh::fromObj(GEM_OBJ, [0.2, 1.0, 0.55, 1.0]),
            new Material([0.2, 1.0, 0.55, 1.0], shininess: 110.0),
            position: new Vec3(0.0, 0.3, -2.5),
            name: 'CrystalGem'
        );
        $this->gemOrbiter = new Entity(
            Mesh::sphere(0.16, 12, 8, [0.8, 1.0, 0.9, 1.0]),
            new Material([0.8, 1.0, 0.9, 1.0]),
            position: new Vec3(0.75, 0.0, 0.0)
        );
        $this->gem->addChild($this->gemOrbiter);
        $this->scene->add($this->gem);

        // 5. Patrolling Red Sentinels
        for ($i = 0; $i < 3; $i++) {
            $ang = $i * (2.0 * M_PI / 3.0);
            $orbitR = 2.4 + $i * 1.6;
            $s = new Entity(
                Mesh::cube(0.85, [1.0, 0.25, 0.3, 1.0]),
                new Material([1.0, 0.25, 0.3, 1.0], shininess: 64.0),
                position: new Vec3(cos($ang) * $orbitR, 0.0, sin($ang) * $orbitR),
                rotation: new Vec3(0.0, -$ang, 0.0),
                name: "Sentinel-$i"
            );
            $this->sentinels[] = $s;
            $this->sentinelAngles[] = $ang;
            $this->scene->add($s);
        }

        // Dynamic Point Lights
        $this->gemLight = new PointLight($this->gem->position, [0.2, 1.0, 0.5], intensity: 2.5, radius: 6.0);
        $this->playerLight = new PointLight($this->player->position, [0.2, 0.7, 1.0], intensity: 1.6, radius: 5.0);
        $this->scene->addPointLight($this->gemLight);
        $this->scene->addPointLight($this->playerLight);
    }

    private function respawnGem(): void
    {
        $angle = (mt_rand(0, 360) / 180.0) * M_PI;
        $dist = mt_rand(15, 52) / 10.0;
        $this->gem->position = new Vec3(cos($angle) * $dist, 0.3, sin($angle) * $dist);
    }

    public function update(Input $in): void
    {
        $this->time += 1.0 / 60.0;
        if ($this->flashTimer > 0.0) {
            $this->flashTimer = max(0.0, $this->flashTimer - 1.0 / 60.0);
        }

        $dx = ($in->down(Key::D) || $in->down(Key::RIGHT) ? 1.0 : 0.0) - ($in->down(Key::A) || $in->down(Key::LEFT) ? 1.0 : 0.0);
        $dz = ($in->down(Key::S) || $in->down(Key::DOWN) ? 1.0 : 0.0) - ($in->down(Key::W) || $in->down(Key::UP) ? 1.0 : 0.0);
        $jump = $in->pressed(Key::SPACE);

        foreach (array_slice(Gamepad::pads(), 0, 1) as $pad) {
            $s = $pad->state();
            [$sx, $sy] = $s->leftStick();
            $dx += $sx;
            $dz += $sy;
            $jump = $jump || $s->justPressed($this->lastPad, Button::South);
            $this->lastPad = $s;
        }

        $len = hypot($dx, $dz);
        if ($len > 1.0) {
            $dx /= $len;
            $dz /= $len;
        }

        $speed = 0.11;
        $this->px += $dx * $speed;
        $this->pz += $dz * $speed;

        // Clamp inside circular arena (radius 6.6)
        $r = hypot($this->px, $this->pz);
        if ($r > 6.6) {
            $this->px = ($this->px / $r) * 6.6;
            $this->pz = ($this->pz / $r) * 6.6;
        }

        // Jump physics
        if ($jump && $this->py <= 0.001) {
            $this->vy = 0.18;
        }
        $this->py = max(0.0, $this->py + $this->vy);
        $this->vy = $this->py > 0.0 ? $this->vy - 0.01 : 0.0;

        $this->player->position = new Vec3($this->px, 0.05 + $this->py, $this->pz);
        $this->player->rotation->y += 0.04;
        $this->playerHalo->rotation->y -= 0.08;
        $this->playerLight->position = new Vec3($this->px, 0.6 + $this->py, $this->pz);

        // Animate gem & point light
        $this->gem->rotation->y += 0.045;
        $this->gem->position->y = 0.35 + sin($this->time * 4.0) * 0.15;
        $this->gemLight->position = $this->gem->position;
        $this->gemLight->intensity = 2.2 + 0.8 * sin($this->time * 6.0);

        // Check Gem collection
        if ($this->player->position->sub($this->gem->position)->length() < 0.95) {
            $this->score++;
            if ($this->score > $this->highScore) {
                $this->highScore = $this->score;
            }
            $this->flashTimer = 0.35;
            // Burst 48 emerald 3D sparks at gem location!
            $this->particles->burst($this->gem->position, count: 48, color: [0.2, 1.0, 0.6, 1.0]);
            $this->respawnGem();
            $this->scene->addTween(
                (new Tween3D($this->gem, duration: 0.25, easing: Tween3D::EASE_OUT_CUBIC))
                    ->toScale(new Vec3(1.0, 1.0, 1.0), new Vec3(0.05, 0.05, 0.05))
            );
        }

        $this->scene->step(1.0 / 60.0);

        // Update patrolling sentinels
        foreach ($this->sentinels as $i => $s) {
            $this->sentinelAngles[$i] += 0.018 + $i * 0.004;
            $orbitR = 2.4 + $i * 1.6;
            $sx = cos($this->sentinelAngles[$i]) * $orbitR;
            $sz = sin($this->sentinelAngles[$i]) * $orbitR;
            $s->position = new Vec3($sx, 0.0, $sz);
            $s->rotation->y = -$this->sentinelAngles[$i];
            $s->rotation->x += 0.03;

            // Check collision with player (can jump over sentinels!)
            if (hypot($this->px - $sx, $this->pz - $sz) < 0.82 && $this->py < 0.65) {
                $this->score = 0;
                $this->px = 0.0;
                $this->pz = 3.5;
            }
        }

        // Smooth camera follow
        $camTarget = new Vec3($this->px * 0.35, 0.0, $this->pz * 0.35);
        $this->scene->camera->target = $camTarget;
        $this->scene->camera->position = new Vec3($this->px * 0.45, 7.2, $this->pz * 0.45 + 8.5);
    }

    public function draw(Painter $p, array $r, float $alpha): void
    {
        $this->scene->camera->aspect = $r[2] / max(1.0, $r[3]);
        $this->viewport->renderScene($this->scene);

        $p->image($this->viewport->bitmap(), $r, Node::FILL_BOX);

        // HUD Overlay
        $p->fill([$r[0] + 16, $r[1] + 16, 355, 86], 0x000000, 8.0, 0.68);
        $p->text($r[0] + 28, $r[1] + 28, 'Crystal Runner 3D (MyPHP Native 3D Game)', 15, 0xffffff);
        $p->text($r[0] + 28, $r[1] + 50, sprintf('Score: %d   |   Best: %d', $this->score, $this->highScore), 14, $this->flashTimer > 0 ? 0x00e676 : 0xffd54f);
        $p->text($r[0] + 28, $r[1] + 72, 'WASD / Arrows: Move | Space: Jump over Sentinels', 11, 0xbbbbbb);
    }
}

$game = new CrystalRunner3D();

App::whenReady(function () use ($game) {
    new Window(new WindowOptions(
        title: 'Crystal Runner 3D — MyPHP Native 3D Game',
        width: 860,
        height: 620,
        content: Content::file(__DIR__ . '/view.php', $game)
    ));
});

App::run();
