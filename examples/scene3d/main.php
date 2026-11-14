<?php
declare(strict_types=1);

// 3D Scene rendered via native Metal / D3D11 / OpenGL / GLES3 RenderPass:
// - 3D Texture Mapping (Wooden Crate + Tiled Floor)
// - Wavefront .OBJ Model Loader (Floating Crystal)
// - Hardware GPU Instancing (Ring of 64 orbiting Pillars in a SINGLE Draw Call)
// - 3D Raycasting / Mouse Picking (Click any 3D object to select & bounce it)
// - Declarative SceneView() / Scene::view() across all 5 platforms
// Run: myphp run -d macos|windows|linux|ios|android examples/scene3d

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Scene\{Camera3D, Entity, Material, Mesh, ParticleEmitter3D, PointLight, Scene, Tween3D};
use MyPHP\Plugins\Scene\Math\{Mat4, Vec3};
use MyPHP\UI\{Content, Context, Image, Painter};

class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

/**
 * Generates a crisp 64x64 procedural checkerboard + border texture.
 */
function makeCrateTexture(): Image
{
    $px = '';
    for ($y = 0; $y < 64; $y++) {
        for ($x = 0; $x < 64; $x++) {
            $border = $x < 4 || $x >= 60 || $y < 4 || $y >= 60;
            $cross = abs($x - $y) < 3 || abs($x - (63 - $y)) < 3;
            $check = ((($x >> 3) + ($y >> 3)) & 1) === 0;
            if ($border || $cross) {
                $px .= "\x8b\x5a\x2b\xff"; // wooden frame
            } elseif ($check) {
                $px .= "\xd2\x99\x5c\xff"; // light wood
            } else {
                $px .= "\xb8\x7d\x42\xff"; // dark wood
            }
        }
    }
    return Image::fromPixels(64, 64, $px)->pixelated();
}

/**
 * Generates a tileable checkerboard floor texture.
 */
function makeFloorTexture(): Image
{
    $px = '';
    for ($y = 0; $y < 64; $y++) {
        for ($x = 0; $x < 64; $x++) {
            $check = ((($x >> 4) + ($y >> 4)) & 1) === 0;
            $px .= $check ? "\x2c\x31\x3c\xff" : "\x1e\x22\x2a\xff";
        }
    }
    return Image::fromPixels(64, 64, $px)->pixelated();
}

/**
 * Embedded Wavefront .OBJ model of a 3D Crystal / Octahedron.
 */
const CRYSTAL_OBJ = <<<'OBJ'
# 3D Crystal Octahedron
v  0.0  1.3  0.0
v  0.9  0.0  0.9
v  0.9  0.0 -0.9
v -0.9  0.0 -0.9
v -0.9  0.0  0.9
v  0.0 -1.3  0.0

f 1 2 3
f 1 3 4
f 1 4 5
f 1 5 2
f 6 3 2
f 6 4 3
f 6 5 4
f 6 2 5
OBJ;

final class SceneApp
{
    public Scene $scene;
    public Entity $crate;
    public Entity $sphere;
    public Entity $crystal;
    public Entity $moon;
    public Entity $floor;
    public Entity $instancedRing;
    public PointLight $crystalLight;
    public ParticleEmitter3D $sparks;

    public string $selectedName = 'None (Click a 3D object!)';
    public ?Entity $selectedEntity = null;

    public int $frames = 0;
    public float $fps = 60.0;

    public function __construct()
    {
        $this->scene = new Scene(new Camera3D(position: new Vec3(2.1, 2.7, 6.6)));
        $this->scene->clearColor = [0.06, 0.07, 0.09, 1.0];
        $this->scene->shadowsEnabled = true;
        $this->scene->fogEnabled = true;
        $this->scene->fogDensity = 0.032;

        // GPU Instanced 3D Particle Emitter for click bursts
        $this->sparks = $this->scene->addEmitter(new ParticleEmitter3D(Mesh::cube(1.0), maxParticles: 256));

        // Pulsing cyan Point Light inside the crystal
        $this->crystalLight = new PointLight(new Vec3(0.0, 0.2, 0.0), [0.2, 0.9, 1.0], intensity: 2.2, radius: 7.0);
        $this->scene->addPointLight($this->crystalLight);

        // 0. Textured Floor Plane (receives real-time PCF shadows)
        $floorMesh = Mesh::plane(18.0, 18.0, uvRepeat: 8.0);
        $floorMat = Material::textured(makeFloorTexture(), shininess: 16.0);
        $this->floor = new Entity($floorMesh, $floorMat, position: new Vec3(0.0, -1.6, 0.0), name: 'Floor');
        $this->floor->pickable = false;
        $this->floor->castShadow = false;
        $this->scene->add($this->floor);

        // 1. Textured 3D Wooden Crate (left)
        $cubeMesh = Mesh::cube(1.7);
        $cubeMat = Material::textured(makeCrateTexture(), shininess: 32.0);
        $this->crate = new Entity($cubeMesh, $cubeMat, position: new Vec3(-2.7, -0.2, 0.0), name: 'Wooden Crate');
        $this->scene->add($this->crate);

        // 2. Loaded Wavefront .OBJ Crystal (center) + Child Orbiting Moon (Scene Graph hierarchy!)
        $crystalMesh = Mesh::fromObj(CRYSTAL_OBJ, [0.25, 0.88, 0.95, 1.0]);
        $crystalMat = new Material([0.25, 0.88, 0.95, 1.0], shininess: 96.0);
        $this->crystal = new Entity($crystalMesh, $crystalMat, position: new Vec3(0.0, 0.0, 0.0), name: 'OBJ Crystal');
        $this->scene->add($this->crystal);

        $moonMesh = Mesh::sphere(0.24, 16, 12, [1.0, 0.9, 0.3, 1.0]);
        $moonMat = new Material([1.0, 0.9, 0.3, 1.0], shininess: 64.0);
        $this->moon = new Entity($moonMesh, $moonMat, position: new Vec3(1.15, 0.35, 0.0), name: 'Crystal Moon (Child)');
        $this->crystal->addChild($this->moon);

        // 3. Smooth UV Sphere (right)
        $sphereMesh = Mesh::sphere(1.0, 28, 18, [1.0, 0.42, 0.28, 1.0]);
        $sphereMat = new Material([1.0, 0.42, 0.28, 1.0], shininess: 48.0);
        $this->sphere = new Entity($sphereMesh, $sphereMat, position: new Vec3(2.7, -0.2, 0.0), name: 'Coral Sphere');
        $this->scene->add($this->sphere);

        // 4. Hardware GPU Instanced Ring (64 cubes rendered in 1 single GPU draw call)
        $pillarMesh = Mesh::cube(0.35);
        $pillarMat = new Material([1.0, 1.0, 1.0, 1.0], shininess: 64.0);
        $this->instancedRing = new Entity($pillarMesh, $pillarMat, name: 'Instanced Ring');
        $transforms = [];
        $colors = [];
        $count = 64;
        for ($i = 0; $i < $count; $i++) {
            $angle = ($i / $count) * 2.0 * M_PI;
            $r = 5.2;
            $px = cos($angle) * $r;
            $pz = sin($angle) * $r;
            $py = -1.1 + sin($angle * 4.0) * 0.35;
            $t = Mat4::translation($px, $py, $pz)->multiply(Mat4::rotationY(-$angle));
            $transforms[] = $t;
            // Rainbow gradient around the ring
            $colors[] = [
                0.5 + 0.5 * cos($angle),
                0.5 + 0.5 * cos($angle + 2.094),
                0.5 + 0.5 * cos($angle + 4.188),
                1.0,
            ];
        }
        $this->instancedRing->setInstances($transforms, $colors);
        $this->scene->add($this->instancedRing);
    }

    /**
     * @param ?array{entity: Entity, distance: float, point: Vec3} $hit
     */
    public function onPick(?array $hit): void
    {
        foreach ([$this->crate, $this->crystal, $this->sphere] as $ent) {
            $ent->scale = new Vec3(1.0, 1.0, 1.0);
        }

        if ($hit !== null) {
            $ent = $hit['entity'];
            $this->selectedEntity = $ent;
            $this->selectedName = sprintf('%s (dist: %.2fm)', $ent->name, $hit['distance']);

            // Smooth Tween3D yoyo scale bounce + 3D Particle burst at hit point!
            $this->scene->addTween(
                (new Tween3D($ent, duration: 0.18, easing: Tween3D::EASE_OUT_CUBIC))
                    ->toScale(new Vec3(1.32, 1.32, 1.32), new Vec3(1.0, 1.0, 1.0))
                    ->yoyo(true)
            );
            $this->sparks->burst($hit['point'], count: 42, color: [1.0, 0.85, 0.3, 1.0]);
        } else {
            $this->selectedEntity = null;
            $this->selectedName = 'None (Click a 3D object!)';
        }
    }

    public function update(float $dt): void
    {
        $this->frames++;
        if ($this->frames % 20 === 0) {
            $this->fps = 1.0 / max(0.001, $dt);
        }

        // Idle animations
        $t = hrtime(true) / 1e9;
        $this->crate->rotation->y += 0.012;
        $this->crate->rotation->x += 0.006;

        $this->crystal->rotation->y -= 0.022;
        $this->crystal->position->y = sin($t * 2.5) * 0.25;
        $this->crystalLight->position->y = $this->crystal->position->y;
        $this->crystalLight->intensity = 1.8 + 0.8 * sin($t * 4.0);

        $this->sphere->rotation->y += 0.015;
        $this->sphere->rotation->z += 0.01;
    }
}

$app = new SceneApp();

App::whenReady(function () use ($app) {
    new Window(new WindowOptions(
        title: 'MyPHP 3D Scene (5-Platform Native RenderPass)',
        width: 800,
        height: 600,
        content: Content::file(__DIR__ . '/view.php', $app)
    ));
});

App::run();
