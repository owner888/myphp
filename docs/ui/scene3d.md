# 3D Scenes

`MyPHP\Plugins\Scene` renders hardware-accelerated 3D scenes across all five
platforms — Metal on macOS and iOS, Direct3D 11 on Windows, OpenGL 3.3 Core
on Linux, and OpenGL ES 3.0 on Android — directly into a GPU `Canvas` texture
with zero CPU readback. A 3D viewport sits anywhere in a declarative view tree
via `SceneView()`, with 2D UI controls or HUD overlays composited on top.

```php
use MyPHP\Plugins\Scene\{Camera3D, Entity, Material, Mesh, Scene};
use MyPHP\Plugins\Scene\Math\Vec3;

$scene = new Scene(new Camera3D(position: new Vec3(0.0, 2.0, 5.0)));
$cube = new Entity(
    Mesh::cube(1.5),
    new Material(color: [0.25, 0.75, 1.0, 1.0], shininess: 64.0),
    name: 'HeroCube',
);
$scene->add($cube);

$view = fn() => VStack(spacing: 12)(
    Text('3D Viewport')->font(Font::headline),
    SceneView(
        $scene,
        orbitControls: true,
        onUpdate: function (float $dt) use ($cube): void {
            $cube->rotation->y += $dt;
        },
    ),
)->padding(16);
```

`SceneView()` provides built-in mouse drag and touch orbit camera controls,
scroll/pinch zoom, automatic `Scene::step($dt)` advancement for 3D tweens,
particles and billboards, and 3D raycast picking via `onPick:`.

## Meshes, textures, OBJ and GLB models

`Mesh` stores 48-byte GPU-aligned vertices (`position`, `normal`, `uv`,
`color`) and uploads them to VRAM once on first draw:

- **Procedural primitives**: `Mesh::cube($size)`, `Mesh::sphere($radius, $sectors, $rings)`,
  and `Mesh::plane($width, $depth, $uvRepeat)`.
- **Wavefront `.obj`**: `Mesh::fromObj($src)` or `Mesh::fromObjFile($path)`
  parses positions, UVs, normals, and triangulates n-gon faces.
- **Binary glTF 2.0 (`.glb`)**: `Mesh::fromGlbFile($path)` loads a single
  mesh; `Mesh::sceneFromGlbFile($path)` parses a multi-mesh `.glb` hierarchy
  with PBR baseColorFactor materials and embedded PNG/JPEG textures into an
  `Entity` tree.
- **Diffuse textures**: `Material::textured($image, shininess: 32.0)` binds any
  `MyPHP\UI\Image` as a 3D surface texture (respecting `$image->pixelated()`
  for crisp nearest-neighbor voxel/retro art).

```php
use MyPHP\Plugins\Scene\{Entity, Material, Mesh, Scene};

$octahedron = Mesh::fromObj(<<<'OBJ'
v  0.0  1.0  0.0
v  0.8  0.0  0.8
v  0.8  0.0 -0.8
v -0.8  0.0 -0.8
v -0.8  0.0  0.8
v  0.0 -1.0  0.0
f 1 2 3
f 1 3 4
f 1 4 5
f 1 5 2
f 6 3 2
f 6 4 3
f 6 5 4
f 6 2 5
OBJ);

$scene = new Scene();
$scene->add(new Entity($octahedron, new Material([0.2, 0.9, 0.8, 1.0])));
$view = fn() => SceneView($scene);
```

## Scene Graph hierarchy, billboards and frustum culling

Entities form a parent-child hierarchy via `$parent->addChild($child)`. Child
entities inherit their parent's world transform (`$child->modelMatrix()` and
`$child->worldPosition()`), making orbiting moons, character attachments or
multi-part vehicles straightforward.

Setting `$entity->billboard = true` causes `Scene::step()` to rotate the
entity toward the active camera each frame — useful for 3D floating health
bars, markers or sprites.

By default, `$scene->frustumCulling = true` extracts the 6 Gribb-Hartmann
frustum planes from the camera's View-Projection matrix before each pass and
skips any non-instanced entity whose world bounding sphere lies outside the
view frustum (`$scene->lastCulledCount` records how many were skipped).

## Lighting, PCF soft shadows and exponential fog

Every `Scene` includes a directional sun light (`$scene->lightDirection`,
`$scene->lightColor`), ambient hemisphere light (`$scene->ambientColor`), up to
two dynamic distance-attenuated `PointLight` sources, real-time 1024×1024
3×3 PCF soft shadows, and exponential distance fog:

```php
use MyPHP\Plugins\Scene\{Entity, Mesh, PointLight, Scene};
use MyPHP\Plugins\Scene\Math\Vec3;

$scene = new Scene();
$scene->shadowsEnabled = true;
$scene->fogEnabled = true;
$scene->fogDensity = 0.035;

$scene->addPointLight(new PointLight(
    position: new Vec3(0.0, 1.5, 0.0),
    color: [0.2, 0.9, 1.0],
    intensity: 2.5,
    radius: 8.0,
));

$floor = new Entity(Mesh::plane(16.0, 16.0), position: new Vec3(0.0, -1.0, 0.0));
$floor->castShadow = false;
$scene->add($floor);
$scene->add(new Entity(Mesh::cube(1.2)));

$view = fn() => SceneView($scene);
```

## Hardware GPU instancing, 3D particles and tweens

- **Hardware Instancing (`Entity::setInstances`)**: Renders thousands of
  transformed, individually tinted copies of a mesh in a single GPU draw call
  (`80` bytes per instance: `Mat4` model matrix + `float4` RGBA color).
- **3D Particle Emitter (`ParticleEmitter3D`)**: Built on top of hardware
  instancing; `$emitter->burst($origin, count: 48, color: [...])` simulates
  3D velocity, gravity, floor bounce, and size/alpha decay in one instanced
  draw call.
- **3D Transform Tweens (`Tween3D`)**: Smoothly animates an entity's
  `position`, `rotation` or `scale` with easing (`EASE_OUT_CUBIC`,
  `EASE_IN_OUT_QUAD`, `BOUNCE_OUT`) and optional `->yoyo(true)`.

```php
use MyPHP\Plugins\Scene\{Entity, Mesh, ParticleEmitter3D, Scene, Tween3D};
use MyPHP\Plugins\Scene\Math\{Mat4, Vec3};

$scene = new Scene();
$pillars = new Entity(Mesh::cube(0.4));
$pillars->setInstances([
    Mat4::translation(-2.0, 0.0, 0.0),
    Mat4::translation( 2.0, 0.0, 0.0),
], [
    [1.0, 0.4, 0.4, 1.0],
    [0.4, 0.8, 1.0, 1.0],
]);
$scene->add($pillars);

$emitter = $scene->addEmitter(new ParticleEmitter3D(Mesh::cube(1.0), maxParticles: 128));
$emitter->burst(new Vec3(0.0, 0.5, 0.0), count: 24);

$scene->addTween(
    (new Tween3D($pillars, duration: 0.4, easing: Tween3D::EASE_OUT_CUBIC))
        ->toScale(new Vec3(1.2, 1.2, 1.2))
        ->yoyo(true)
);

$view = fn() => SceneView($scene);
```

## 3D raycast picking

Pass `onPick:` to `SceneView()` (or call `$scene->pick($x, $y, $w, $h)` /
`$scene->raycast($ray)`) to unproject 2D pointer coordinates into a 3D world
ray and find the closest intersected entity (`['entity' => Entity, 'distance' => float, 'point' => Vec3]`).
Set `$entity->pickable = false` on ground planes, skyboxes or particle
emitters so they do not intercept clicks.
