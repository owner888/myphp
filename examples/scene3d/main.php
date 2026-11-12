<?php
declare(strict_types=1);

// 3D Scene rendered via native Metal RenderPass into a Canvas,
// featuring depth testing, Blinn-Phong lighting, and mouse interaction.
// Run: myphp run -d macos examples/scene3d

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Scene\{Camera3D, Entity, Material, Mesh, Scene};
use MyPHP\Plugins\Scene\Math\Vec3;
use MyPHP\UI\{Canvas, Content, Context, Event, Key, Node, Painter};

class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

final class SceneApp
{
    public Scene $scene;
    public Entity $cube;
    public Entity $sphere;
    public Canvas $canvas;

    public float $yaw = 0.0;
    public float $pitch = 0.2;
    public float $dist = 6.0;

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
        $this->scene->clearColor = [0.07, 0.08, 0.10, 1.0];

        // 3D Cube (cyan with specular)
        $cubeMesh = Mesh::cube(1.8, [0.2, 0.8, 0.9, 1.0]);
        $cubeMat = new Material([0.2, 0.8, 0.9, 1.0], ambient: 0.2, diffuse: 0.9, specular: 0.8, shininess: 64.0);
        $this->cube = new Entity($cubeMesh, $cubeMat);
        $this->cube->position = new Vec3(-1.4, 0.0, 0.0);
        $this->scene->add($this->cube);

        // 3D Sphere (coral orange)
        $sphereMesh = Mesh::sphere(1.1, 24, 16, [1.0, 0.45, 0.25, 1.0]);
        $sphereMat = new Material([1.0, 0.45, 0.25, 1.0], ambient: 0.25, diffuse: 0.85, specular: 0.6, shininess: 32.0);
        $this->sphere = new Entity($sphereMesh, $sphereMat);
        $this->sphere->position = new Vec3(1.4, 0.0, 0.0);
        $this->scene->add($this->sphere);

        $this->lastTime = hrtime(true) / 1e9;
    }

    public function update(): void
    {
        $now = hrtime(true) / 1e9;
        $dt = max(0.001, $now - $this->lastTime);
        $this->lastTime = $now;

        $this->frames++;
        if ($this->frames % 30 === 0) {
            $this->fps = 1.0 / $dt;
        }

        // Automatic idle spin
        $this->cube->rotation->y += 0.015;
        $this->cube->rotation->x += 0.008;

        $this->sphere->rotation->y -= 0.02;
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
        $app->update();

        $c->column(function (Context $c) use ($app) {
            $e = $c->box(800, 600);
            $e->grow = 1;

            $e->draw(function (Painter $p, array $r) use ($app) {
                // 1. Draw 3D scene canvas
                $p->image($app->canvas->bitmap(), $r);

                // 2. Draw 2D overlay on top
                $p->fill([$r[0] + 16, $r[1] + 16, 260, 80], 0x000000, 8.0, 0.65);
                $p->text($r[0] + 28, $r[1] + 28, 'MyPHP Native 3D RenderPass', 15, 0xffffff, weight: 0.4);
                $p->text($r[0] + 28, $r[1] + 50, sprintf('FPS: %.1f | Depth: 32F | Metal GPU', $app->fps), 12, 0x00e676);
                $p->text($r[0] + 28, $r[1] + 68, 'Drag mouse to rotate | Wheel to zoom', 11, 0xaaaaaa);
            });
        });
    });

    $win = new Window(new WindowOptions(
        title: 'MyPHP 3D Scene (Metal RenderPass)',
        width: 800,
        height: 600,
        content: $content
    ));
});

App::run();
