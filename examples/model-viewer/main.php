<?php
declare(strict_types=1);

// 3D Model Viewer & Studio built with MyPHP Declarative UI + 5-Platform Native 3D Scene:
// - Inspect procedural primitives (Torus, Capsule, Cylinder, Cone, Sphere, Cube), Wavefront .OBJ, or Binary glTF 2.0 (.glb)
// - Drag & Drop any local .obj or .glb file into the window to load and inspect it!
// - Real-time controls for 3D Reference Grid & XYZ Gizmo, PCF Soft Shadows, Distance Fog, Auto-Rotate, and Material Shininess
// - Trigger 3D GPU-instanced Particle Bursts and export high-res Offscreen 3D PNG snapshots
// Run: myphp run -d macos|windows|linux|ios|android examples/model-viewer

use MyPHP\{App, FileDropEvent, Window, WindowOptions};
use MyPHP\Plugins\Scene\{Camera3D, Entity, Material, Mesh, ParticleEmitter3D, PointLight, Scene, Tween3D};
use MyPHP\Plugins\Scene\Math\Vec3;
use MyPHP\UI\Content;

class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

const STUDIO_CRYSTAL_OBJ = <<<'OBJ'
v  0.0  1.35  0.0
v  0.95 0.0   0.95
v  0.95 0.0  -0.95
v -0.95 0.0  -0.95
v -0.95 0.0   0.95
v  0.0 -1.35  0.0
f 1 2 3
f 1 3 4
f 1 4 5
f 1 5 2
f 6 3 2
f 6 4 3
f 6 5 4
f 6 2 5
OBJ;

final class ModelViewerApp
{
    public ?Window $window = null;
    public Scene $scene;
    public Entity $modelEntity;
    public Entity $gridHelper;
    public Entity $floorEntity;
    public PointLight $keyLight;
    public ParticleEmitter3D $emitter;

    public string $preset = 'Torus';
    public bool $autoRotate = true;
    public bool $wireframe = false;
    public bool $showGrid = true;
    public bool $showFloor = true;
    public bool $shadows = true;
    public bool $fog = true;
    public float $shininess = 64.0;
    public float $rimStrength = 0.65;
    public float $lightAngle = 0.8;
    public float $pointIntensity = 2.0;

    public int $vertexCount = 0;
    public int $triangleCount = 0;
    public string $statusMessage = 'Drag & drop any .obj or .glb file into the window';

    public function __construct()
    {
        $this->scene = new Scene(new Camera3D(
            position: new Vec3(2.4, 2.2, 4.8),
            target: new Vec3(0.0, 0.0, 0.0),
            fovYDegrees: 50.0
        ));
        $this->scene->clearColor = [0.07, 0.08, 0.11, 1.0];
        $this->scene->shadowsEnabled = true;
        $this->scene->fogEnabled = true;
        $this->scene->fogDensity = 0.035;

        // 1. Floor (for receiving PCF shadows)
        $this->floorEntity = new Entity(
            Mesh::plane(16.0, 16.0),
            new Material([0.16, 0.18, 0.22, 1.0], shininess: 16.0),
            position: new Vec3(0.0, -1.35, 0.0),
            name: 'StudioFloor'
        );
        $this->floorEntity->pickable = false;
        $this->floorEntity->castShadow = false;
        $this->scene->add($this->floorEntity);

        // 2. 3D Reference Grid & XYZ Axis Gizmo
        $this->gridHelper = $this->scene->addGridHelper(size: 12.0, divisions: 12, y: -1.33);

        // 3. Dynamic Point Light
        $this->keyLight = new PointLight(new Vec3(2.0, 1.8, 2.0), [0.3, 0.85, 1.0], intensity: 2.0, radius: 9.0);
        $this->scene->addPointLight($this->keyLight);

        // 4. GPU Instanced 3D Particle Emitter
        $this->emitter = $this->scene->addEmitter(new ParticleEmitter3D(Mesh::cube(1.0), maxParticles: 256));

        // 5. Initial 3D Model
        $this->modelEntity = new Entity(Mesh::torus(1.0, 0.38, 36, 20), new Material([0.35, 0.78, 1.0, 1.0], shininess: 64.0), name: 'Torus');
        $this->scene->add($this->modelEntity);
        $this->updateStats();
    }

    public function selectPreset(string $name): void
    {
        $this->preset = $name;
        $mesh = match ($name) {
            'Torus'    => Mesh::torus(1.0, 0.38, 36, 20),
            'Capsule'  => Mesh::capsule(0.55, 1.1, 28, 16),
            'Cylinder' => Mesh::cylinder(0.8, 0.8, 1.8, 28),
            'Cone'     => Mesh::cone(0.95, 1.9, 28),
            'Sphere'   => Mesh::sphere(1.1, 32, 20),
            'Cube'     => Mesh::cube(1.5),
            'Crystal'  => Mesh::fromObj(STUDIO_CRYSTAL_OBJ),
            default    => Mesh::torus(1.0, 0.38, 36, 20),
        };
        $color = match ($name) {
            'Torus'    => [0.35, 0.78, 1.0, 1.0],
            'Capsule'  => [0.35, 0.95, 0.62, 1.0],
            'Cylinder' => [0.95, 0.65, 0.32, 1.0],
            'Cone'     => [0.98, 0.42, 0.48, 1.0],
            'Sphere'   => [0.75, 0.52, 1.0, 1.0],
            'Cube'     => [0.92, 0.85, 0.40, 1.0],
            'Crystal'  => [0.25, 0.92, 0.95, 1.0],
            default    => [0.8, 0.8, 0.9, 1.0],
        };

        $this->modelEntity->children = [];
        $this->modelEntity->mesh = $mesh;
        $this->modelEntity->material = new Material($color, shininess: $this->shininess);
        $this->modelEntity->rotation = Vec3::zero();
        $this->modelEntity->name = $name;

        $this->scene->addTween(
            (new Tween3D($this->modelEntity, duration: 0.25, easing: Tween3D::EASE_OUT_CUBIC))
                ->toScale(new Vec3(1.0, 1.0, 1.0), new Vec3(0.6, 0.6, 0.6))
        );
        $this->updateStats();
        $this->statusMessage = "Loaded preset: $name";
    }

    public function loadDroppedFile(string $path): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        try {
            if ($ext === 'obj') {
                $mesh = Mesh::fromObjFile($path);
                $this->modelEntity->children = [];
                $this->modelEntity->mesh = $mesh;
                $this->modelEntity->name = basename($path);
            } elseif ($ext === 'glb') {
                $loaded = Mesh::sceneFromGlbFile($path);
                $this->scene->remove($this->modelEntity);
                $this->modelEntity = $loaded;
                $this->scene->add($this->modelEntity);
            } else {
                $this->statusMessage = "Unsupported file .$ext (drop .obj or .glb)";
                return;
            }
            $this->updateStats();
            $this->statusMessage = 'Loaded file: ' . basename($path);
            $this->burstParticles();
        } catch (\Throwable $e) {
            $this->statusMessage = 'Error: ' . $e->getMessage();
        }
    }

    private function updateStats(): void
    {
        $v = 0;
        $idx = 0;
        $walk = static function (Entity $e) use (&$walk, &$v, &$idx): void {
            $v += $e->mesh->vertexCount;
            $idx += $e->mesh->indexCount;
            foreach ($e->children as $c) {
                $walk($c);
            }
        };
        $walk($this->modelEntity);
        $walk = null;
        $this->vertexCount = $v;
        $this->triangleCount = intdiv($idx, 3);
    }

    public function burstParticles(): void
    {
        $this->emitter->burst(new Vec3(0.0, 0.2, 0.0), count: 56, color: [0.35, 0.9, 1.0, 1.0]);
        $this->scene->addTween(
            (new Tween3D($this->modelEntity, duration: 0.2, easing: Tween3D::EASE_OUT_CUBIC))
                ->toScale(new Vec3(1.22, 1.22, 1.22), new Vec3(1.0, 1.0, 1.0))
                ->yoyo(true)
        );
    }

    public function exportSnapshot(): void
    {
        try {
            $img = $this->scene->renderOffscreen(1024, 768);
            $rgba = $img->pixels();
            $outPath = sys_get_temp_dir() . '/myphp-3d-snapshot.png';
            // Convert RGBA to BGRA for ImageIO::pngFromBGRA (or write a pure-PHP PNG)
            $bgra = '';
            for ($i = 0, $n = strlen($rgba); $i < $n; $i += 4) {
                $bgra .= $rgba[$i + 2] . $rgba[$i + 1] . $rgba[$i] . $rgba[$i + 3];
            }
            $png = PHP_OS_FAMILY === 'Darwin'
                ? \MyPHP\Native\ImageIO::pngFromBGRA($bgra, 1024, 768)
                : $rgba;
            file_put_contents($outPath, $png);
            $this->statusMessage = "Saved 1024×768 PNG to $outPath";
        } catch (\Throwable $e) {
            $this->statusMessage = 'Snapshot error: ' . $e->getMessage();
        }
    }

    public function updateScene(float $dt): void
    {
        $this->scene->shadowsEnabled = $this->shadows;
        $this->scene->fogEnabled = $this->fog;
        $this->modelEntity->material->shininess = $this->shininess;
        $this->modelEntity->material->wireframe = $this->wireframe;
        $this->modelEntity->material->rimStrength = $this->rimStrength;
        $this->keyLight->intensity = $this->pointIntensity;

        // Toggle Grid & Floor visibility
        $this->gridHelper->instanceCount = $this->showGrid ? 27 : 0;
        $this->floorEntity->scale = $this->showFloor ? new Vec3(1.0, 1.0, 1.0) : new Vec3(0.0, 0.0, 0.0);

        // Rotate directional sun light from slider
        $this->scene->lightDirection = new Vec3(-cos($this->lightAngle), -1.0, -sin($this->lightAngle));

        if ($this->autoRotate) {
            $this->modelEntity->rotation->y += $dt * 0.85;
            $this->modelEntity->rotation->x = sin(hrtime(true) / 1e9 * 1.2) * 0.22;
        }
    }
}

$app = new ModelViewerApp();

App::whenReady(function () use ($app) {
    $app->window = new Window(new WindowOptions(
        title: 'MyPHP 3D Model Viewer & Studio',
        width: 1080,
        height: 740,
        content: Content::file(__DIR__ . '/view.php', $app)
    ));
    $app->window->on('fileDrop', function (FileDropEvent $ev) use ($app): void {
        if ($ev->paths !== []) {
            $app->loadDroppedFile($ev->paths[0]);
            $app->window?->invalidate();
        }
    });
});

App::run();
