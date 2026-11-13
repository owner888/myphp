<?php
declare(strict_types=1);

// 3D Scene rendered via native Metal RenderPass into a Canvas,
// featuring depth testing, Blinn-Phong lighting, 3D Texture Mapping,
// OBJ model loading, and interactive orbit controls.
// Run: myphp run -d macos examples/scene3d

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Scene\{Camera3D, Entity, Material, Mesh, Scene};
use MyPHP\Plugins\Scene\Math\Vec3;
use MyPHP\UI\{Canvas, Content, Context, Image, InputEvent, Key, Node, Painter};

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
    public Entity $floor;
    public Canvas $canvas;

    public float $yaw = 0.3;
    public float $pitch = 0.35;
    public float $dist = 6.8;

    public float $lastMouseX = 0.0;
    public float $lastMouseY = 0.0;
    public bool $dragging = false;

    public int $frames = 0;
    public float $fps = 60.0;
    public float $lastTime = 0.0;

    public function __construct()
    {
        $this->canvas = new Canvas(800, 600);
        $this->scene = new Scene();
        $this->scene->clearColor = [0.06, 0.07, 0.09, 1.0];

        // 0. Textured Floor Plane
        $floorMesh = Mesh::plane(14.0, 14.0, uvRepeat: 6.0);
        $floorMat = Material::textured(makeFloorTexture(), shininess: 16.0);
        $this->floor = new Entity($floorMesh, $floorMat, position: new Vec3(0.0, -1.6, 0.0));
        $this->scene->add($this->floor);

        // 1. Textured 3D Wooden Crate (left)
        $cubeMesh = Mesh::cube(1.7);
        $cubeMat = Material::textured(makeCrateTexture(), shininess: 32.0);
        $this->crate = new Entity($cubeMesh, $cubeMat, position: new Vec3(-2.1, -0.2, 0.0));
        $this->scene->add($this->crate);

        // 2. Loaded Wavefront .OBJ Crystal (center)
        $crystalMesh = Mesh::fromObj(CRYSTAL_OBJ, [0.25, 0.88, 0.95, 1.0]);
        $crystalMat = new Material([0.25, 0.88, 0.95, 1.0], shininess: 96.0);
        $this->crystal = new Entity($crystalMesh, $crystalMat, position: new Vec3(0.0, 0.0, 0.0));
        $this->scene->add($this->crystal);

        // 3. Smooth UV Sphere (right)
        $sphereMesh = Mesh::sphere(1.0, 28, 18, [1.0, 0.42, 0.28, 1.0]);
        $sphereMat = new Material([1.0, 0.42, 0.28, 1.0], shininess: 48.0);
        $this->sphere = new Entity($sphereMesh, $sphereMat, position: new Vec3(2.1, -0.2, 0.0));
        $this->scene->add($this->sphere);

        $this->lastTime = hrtime(true) / 1e9;
    }

    public function update(): void
    {
        $now = hrtime(true) / 1e9;
        $dt = max(0.001, $now - $this->lastTime);
        $this->lastTime = $now;

        $this->frames++;
        if ($this->frames % 20 === 0) {
            $this->fps = 1.0 / $dt;
        }

        // Idle animations
        $this->crate->rotation->y += 0.012;
        $this->crate->rotation->x += 0.006;

        $this->crystal->rotation->y -= 0.022;
        $this->crystal->position->y = sin($now * 2.5) * 0.2;

        $this->sphere->rotation->y += 0.015;
        $this->sphere->rotation->z += 0.01;

        // Update camera position from orbit parameters (yaw, pitch, dist)
        $cx = $this->dist * cos($this->pitch) * sin($this->yaw);
        $cy = $this->dist * sin($this->pitch);
        $cz = $this->dist * cos($this->pitch) * cos($this->yaw);
        $this->scene->camera->position = new Vec3($cx, $cy, $cz);

        // Execute GPU 3D RenderPass into canvas target
        $this->canvas->renderScene($this->scene);
    }
}

App::whenReady(function () {
    $app = new SceneApp();

    $content = Content::view(function (Context $c) use ($app) {
        $e = $c->column();
        $c->end();
        $e->grow = 1;

        $e->focusable()->autoFocus()->handleInput(function (InputEvent $ev) use ($app): bool {
            if ($ev->kind === InputEvent::POINTER_DOWN) {
                $app->dragging = true;
                [$app->lastMouseX, $app->lastMouseY] = [$ev->x, $ev->y];
                return true;
            } elseif ($ev->kind === InputEvent::POINTER_UP) {
                $app->dragging = false;
                return true;
            } elseif ($ev->kind === InputEvent::POINTER_MOVE && $app->dragging) {
                $dx = $ev->x - $app->lastMouseX;
                $dy = $ev->y - $app->lastMouseY;
                $app->yaw -= $dx * 0.008;
                $app->pitch = max(-0.1, min(1.4, $app->pitch + $dy * 0.008));
                [$app->lastMouseX, $app->lastMouseY] = [$ev->x, $ev->y];
                return true;
            } elseif ($ev->kind === InputEvent::SCROLL) {
                $app->dist = max(3.0, min(16.0, $app->dist - $ev->dy * 0.05));
                return true;
            }
            return false;
        });

        $e->draw(function (Painter $p, array $r) use ($app) {
            $p->animationFrame();
            $app->update();

            // 1. Draw 3D scene canvas
            $p->image($app->canvas->bitmap(), $r, Node::FILL_BOX);

            // 2. Draw 2D HUD overlay on top
            $p->fill([$r[0] + 16, $r[1] + 16, 330, 82], 0x000000, 8.0, 0.65);
            $p->text($r[0] + 28, $r[1] + 28, 'MyPHP Native 3D Scene (Metal GPU)', 15, 0xffffff);
            $p->text($r[0] + 28, $r[1] + 50, sprintf('FPS: %.1f | Textured Crate + OBJ Crystal + Sphere', $app->fps), 12, 0x00e676);
            $p->text($r[0] + 28, $r[1] + 68, 'Drag mouse to orbit camera | Scroll wheel to zoom', 11, 0xaaaaaa);
        });
    });

    new Window(new WindowOptions(
        title: 'MyPHP 3D Scene (Textures & OBJ Loader)',
        width: 800,
        height: 600,
        content: $content
    ));
});

App::run();
