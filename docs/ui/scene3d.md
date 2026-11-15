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

- **Procedural primitives & Terrain**: `Mesh::cube($size)`, `Mesh::sphere($radius, $sectors, $rings)`,
  `Mesh::plane($width, $depth, $uvRepeat)`, `Mesh::cylinder($topRadius, $bottomRadius, $height)`,
  `Mesh::cone($radius, $height)`, `Mesh::torus($majorRadius, $minorRadius)`,
  `Mesh::capsule($radius, $cylinderHeight)`, `Mesh::terrain($width, $depth, $subX, $subZ, $heightFn, $colorFn)`,
  and `Mesh::skyDome($radius, $zenithColor, $horizonColor, $groundColor)`.
- **Wavefront `.obj`**: `Mesh::fromObj($src)` or `Mesh::fromObjFile($path)`
  parses positions, UVs, normals, and triangulates n-gon faces.
- **Binary glTF 2.0 (`.glb`)**: `Mesh::fromGlbFile($path)` loads a single
  mesh; `Mesh::sceneFromGlbFile($path)` parses a multi-mesh `.glb` hierarchy
  with PBR baseColorFactor materials and embedded PNG/JPEG textures into an
  `Entity` tree.
- **Cook-Torrance PBR, Emissive/Unlit, Diffuse textures, Wireframe & Fresnel Rim**:
  `Material::pbr($color, metallic: 0.5, roughness: 0.25)` enables physically
  based GGX + Smith + Fresnel-Schlick metallic/roughness shading across all 5
  platforms (and `Mesh::sceneFromGlbFile()` extracts `metallicFactor` and
  `roughnessFactor` automatically). `Material::unlit($color, $texture, emissive: 1.0)`
  (or `$material->emissive = 1.0`) creates self-illuminated surfaces (suns, magic
  orbs, selection rings, glowing eyes, sky domes) that bypass diffuse shadow darkening.
  `Material::textured($image, shininess: 32.0)` binds any `MyPHP\UI\Image` as a
  3D surface texture. Set `$material->wireframe = true` for hardware triangle-line
  rendering, or `$material->rimStrength = 1.0` for Fresnel hologram rim lighting.

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

## 3D raycast picking, colliders and reference grid

Pass `onPick:` to `SceneView()` (or call `$scene->pick($x, $y, $w, $h)` /
`$scene->raycast($ray)`) to unproject 2D pointer coordinates into a 3D world
ray and find the closest intersected entity (`['entity' => Entity, 'distance' => float, 'point' => Vec3]`).
Set `$entity->pickable = false` on ground planes, skyboxes or particle
emitters so they do not intercept clicks. To project a 3D world position onto
2D viewport coordinates for floating labels or health bars, call
`$scene->camera->worldToScreen($worldPos, $width, $height)`.

For 3D collision detection and solid push-out resolution during
`Scene::step($dt)`, attach a `Collider3D::sphere($radius)` or
`Collider3D::box($halfExtents)` to `$entity->collider`, and call
`$scene->addGridHelper($size, $divisions)` to add a hardware-instanced 3D
reference grid with color-coded XYZ axes.

## Unity-style Sky Dome, 3D Terrain, Camera Shake and Hierarchical `Animator3D`

To build rich outdoor 3D worlds and animated creatures without hand-rolling vertex math or procedural animation loops:

- **Procedural Sky Dome (`$scene->addSkyDome`)**: Adds an unlit, non-pickable atmospheric sky sphere that smoothly blends from `$groundColor` through `$horizonColor` to `$zenithColor` and automatically centers on `$scene->camera->position` during `Scene::step($dt)`.
- **3D Heightfield Terrain (`Mesh::terrain`)**: Samples `$heightFn($x, $z)` across a grid, computes smooth central-difference normals for accurate directional/PCF shadows, and colors vertices by altitude and slope (valley grass → rocky slope → snow peak, or via custom `$colorFn($y, $ny, $x, $z)`).
- **3D Camera Shake (`$scene->camera->shake`)**: `$scene->camera->shake(intensity: 0.2, duration: 0.25)` applies a damped 3D vibration to the camera's eye and target during `Scene::step($dt)`.
- **Hierarchical Creature Animator (`Animator3D`)**: Drives organic root states (`Animator3D::IDLE` chest breathing, `WALK` bobbing/sway, `LUNGE` forward strike with wind-up and return, `HURT` recoil shake, `SPIN`) plus synchronized child limb oscillations (`addLimb($wingOrHead, axis: 'z', amplitude: 0.3, frequency: 4.0)`):

```php
use MyPHP\Plugins\Scene\{Animator3D, Entity, Material, Mesh, Scene};
use MyPHP\Plugins\Scene\Math\Vec3;

$scene = new Scene();
$scene->addSkyDome(
    zenithColor:  [0.14, 0.34, 0.68, 1.0],
    horizonColor: [0.72, 0.85, 0.96, 1.0],
    groundColor:  [0.24, 0.28, 0.22, 1.0],
);

// 1. Procedural Mountain Terrain
$mountains = new Entity(
    Mesh::terrain(32.0, 32.0, 32, 32, fn(float $x, float $z) => sin($x * 0.3) * cos($z * 0.3) * 1.8),
    new Material([1.0, 1.0, 1.0, 1.0], shininess: 8.0),
    position: new Vec3(0.0, -1.5, 0.0),
);
$mountains->pickable = false;
$scene->add($mountains);

// 2. Multi-part Creature with Animator3D (body + oscillating wings + glowing eyes)
$beast = new Entity(Mesh::sphere(0.5, 20, 14, [0.3, 0.65, 0.35, 1.0]), position: new Vec3(0.0, 0.5, 0.0));
$leftWing = new Entity(Mesh::cube(0.25, [0.4, 0.8, 0.45, 1.0]), position: new Vec3(-0.55, 0.1, 0.0));
$eye = new Entity(Mesh::sphere(0.08), Material::unlit([1.0, 0.9, 0.2, 1.0], emissive: 1.5), position: new Vec3(0.18, 0.2, 0.42));
$beast->addChild($leftWing);
$beast->addChild($eye);
$scene->add($beast);

$anim = $scene->addAnimator(new Animator3D($beast));
$anim->addLimb($leftWing, axis: 'z', amplitude: 0.4, frequency: 5.0);
$anim->play(Animator3D::IDLE);

$view = fn() => SceneView($scene);
```
