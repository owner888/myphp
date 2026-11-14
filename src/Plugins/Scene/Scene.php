<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use Closure;
use MyPHP\Native\Mem;
use MyPHP\Native\ObjC;
use MyPHP\Plugins\Scene\Math\{Frustum, Mat4, Ray, Vec3};
use MyPHP\Plugins\Scene\Native\MetalRenderPass;
use MyPHP\UI\{Canvas, Context, Image, InputEvent, Node, Painter};
use RuntimeException;

/**
 * 3D Scene holding entities, camera, and lighting.
 * Can be rendered into a native Canvas for zero-copy 2D/3D compositing,
 * or embedded into the UI tree via Scene::view().
 */
final class Scene
{
    /** @var list<Entity> */
    public array $entities = [];
    /** @var list<PointLight> */
    public array $pointLights = [];
    public Camera3D $camera;
    public Vec3 $lightDirection;
    /** @var array{0: float, 1: float, 2: float, 3: float} */
    public array $lightColor = [1.0, 1.0, 1.0, 1.0];
    /** @var array{0: float, 1: float, 2: float, 3: float} */
    public array $ambientColor = [0.2, 0.2, 0.25, 1.0];
    /** @var array{0: float, 1: float, 2: float, 3: float} */
    public array $clearColor = [0.08, 0.09, 0.11, 1.0];

    /** Real-time Directional Shadow Mapping & Frustum Culling */
    public bool $shadowsEnabled = false;
    public float $shadowBounds = 9.0;
    public bool $frustumCulling = true;
    /** @var int Number of entities culled in the last render pass */
    public int $lastCulledCount = 0;

    /** Exponential Distance Fog (blends distant geometry into horizon/clearColor) */
    public bool $fogEnabled = false;
    public float $fogDensity = 0.045;
    /** @var ?array{0: float, 1: float, 2: float, 3: float} Defaults to $clearColor when null */
    public ?array $fogColor = null;

    /** @var list<ParticleEmitter3D> Active GPU-instanced 3D particle emitters */
    public array $emitters = [];

    /** @var list<Tween3D> Active 3D transform tweens */
    public array $tweens = [];

    public function __construct(?Camera3D $camera = null)
    {
        $this->camera = $camera ?? new Camera3D();
        $this->lightDirection = new Vec3(-0.5, -1.0, -0.6);
    }

    public function add(Entity $entity): Entity
    {
        $this->entities[] = $entity;
        return $entity;
    }

    public function addPointLight(PointLight $light): PointLight
    {
        $this->pointLights[] = $light;
        return $light;
    }

    public function addEmitter(ParticleEmitter3D $emitter): ParticleEmitter3D
    {
        $this->emitters[] = $emitter;
        if (!in_array($emitter->entity, $this->entities, true)) {
            $this->entities[] = $emitter->entity;
        }
        return $emitter;
    }

    public function addTween(Tween3D $tween): Tween3D
    {
        $this->tweens[] = $tween;
        return $tween;
    }

    /**
     * Steps all active 3D tweens, particle emitters, and updates billboard orientations.
     */
    public function step(float $dt): void
    {
        foreach ($this->tweens as $k => $tween) {
            if ($tween->step($dt)) {
                unset($this->tweens[$k]);
            }
        }
        if ($this->tweens !== []) {
            $this->tweens = array_values($this->tweens);
        }

        foreach ($this->emitters as $emitter) {
            $emitter->step($dt);
        }

        $camPos = $this->camera->position;
        $collidable = [];
        foreach ($this->allEntities() as $entity) {
            if ($entity->billboard) {
                $entity->faceCamera($camPos);
            }
            if ($entity->collider !== null) {
                $collidable[] = $entity;
            }
        }

        $nCol = count($collidable);
        for ($i = 0; $i < $nCol; $i++) {
            $a = $collidable[$i];
            $ca = $a->collider;
            for ($j = $i + 1; $j < $nCol; $j++) {
                $b = $collidable[$j];
                $cb = $b->collider;
                if ($ca->isStatic && $cb->isStatic) {
                    continue;
                }
                $hit = Collider3D::intersect($a, $b);
                if ($hit === null) {
                    continue;
                }
                [$normal, $pen] = $hit;

                // Resolve solid push-out if neither is a trigger
                if (!$ca->isTrigger && !$cb->isTrigger) {
                    if ($ca->isStatic) {
                        $b->position = $b->position->sub($normal->scale($pen));
                    } elseif ($cb->isStatic) {
                        $a->position = $a->position->add($normal->scale($pen));
                    } else {
                        $half = $normal->scale($pen * 0.5);
                        $a->position = $a->position->add($half);
                        $b->position = $b->position->sub($half);
                    }
                }

                if ($ca->onCollide !== null) {
                    ($ca->onCollide)($a, $b, $normal, $pen);
                }
                if ($cb->onCollide !== null) {
                    ($cb->onCollide)($b, $a, $normal->scale(-1.0), $pen);
                }
            }
        }
    }

    /**
     * Adds a hardware-instanced 3D reference grid and XYZ color-coded coordinate axes at $y.
     */
    public function addGridHelper(float $size = 10.0, int $divisions = 10, float $y = 0.0, bool $showYAxis = true): Entity
    {
        $divisions = max(2, $divisions);
        $half = $size * 0.5;
        $step = $size / $divisions;
        $lineThickness = 0.022;
        $axisThickness = 0.048;

        $gridEnt = new Entity(
            Mesh::cube(1.0),
            new Material([1.0, 1.0, 1.0, 1.0], shininess: 16.0),
            scale: new Vec3(0.0, 0.0, 0.0),
            name: 'GridHelper'
        );
        $gridEnt->pickable = false;
        $gridEnt->castShadow = false;

        $transforms = [];
        $colors = [];

        for ($i = 0; $i <= $divisions; $i++) {
            $pos = -$half + $i * $step;
            $isCenter = abs($pos) < 1e-4;

            // Line parallel to X axis (at Z = $pos)
            $transforms[] = Mat4::translation(0.0, $y, $pos)->multiply(Mat4::scaling($size, $isCenter ? $axisThickness : $lineThickness, $isCenter ? $axisThickness : $lineThickness));
            $colors[] = $isCenter ? [0.95, 0.28, 0.32, 1.0] : [0.32, 0.36, 0.44, 1.0]; // Red X-axis or gray grid line

            // Line parallel to Z axis (at X = $pos)
            $transforms[] = Mat4::translation($pos, $y, 0.0)->multiply(Mat4::scaling($isCenter ? $axisThickness : $lineThickness, $isCenter ? $axisThickness : $lineThickness, $size));
            $colors[] = $isCenter ? [0.28, 0.58, 0.98, 1.0] : [0.32, 0.36, 0.44, 1.0]; // Blue Z-axis or gray grid line
        }

        // Vertical Y-axis indicator at origin (Green)
        if ($showYAxis) {
            $axisH = $size * 0.25;
            $transforms[] = Mat4::translation(0.0, $y + $axisH * 0.5, 0.0)->multiply(Mat4::scaling($axisThickness, $axisH, $axisThickness));
            $colors[] = [0.28, 0.92, 0.45, 1.0];
        }

        $gridEnt->setInstances($transforms, $colors);
        return $this->add($gridEnt);
    }

    /**
     * Returns all entities in the scene graph (flattening parent-child hierarchies).
     *
     * @return list<Entity>
     */
    public function allEntities(): array
    {
        $out = [];
        $walk = static function (array $list) use (&$walk, &$out): void {
            foreach ($list as $e) {
                $out[] = $e;
                if ($e->children !== []) {
                    $walk($e->children);
                }
            }
        };
        $walk($this->entities);
        $walk = null;
        return $out;
    }

    /**
     * Returns only the entities visible inside the camera's view frustum (or instanced entities).
     * Updates $this->lastCulledCount.
     *
     * @return list<Entity>
     */
    public function visibleEntities(Mat4 $viewProj): array
    {
        $all = $this->allEntities();
        $frustum = $this->frustumCulling ? Frustum::fromViewProjection($viewProj) : null;
        $visible = [];
        $culled = 0;

        foreach ($all as $entity) {
            if ($entity->scale->x === 0.0 && $entity->scale->y === 0.0 && $entity->scale->z === 0.0 && $entity->instanceCount === 0) {
                continue;
            }
            if (!$this->frustumCulling) {
                $visible[] = $entity;
                continue;
            }
            // Instanced entities span wide areas; keep them always visible
            if ($entity->instanceCount > 0 || $frustum->intersectsSphere($entity->worldPosition(), $entity->worldBoundingRadius())) {
                $visible[] = $entity;
            } else {
                $culled++;
            }
        }

        $this->lastCulledCount = $culled;
        return $visible;
    }

    /**
     * Computes the orthographic View-Projection matrix from the directional light's perspective.
     */
    public function lightViewProjectionMatrix(): Mat4
    {
        $dir = $this->lightDirection->normalize();
        $center = $this->camera->target;
        $lightPos = $center->sub($dir->scale(15.0));
        $up = abs($dir->y) > 0.99 ? new Vec3(0.0, 0.0, 1.0) : Vec3::up();

        $lightView = Mat4::lookAt($lightPos, $center, $up);
        $b = $this->shadowBounds;
        $lightProj = Mat4::ortho(-$b, $b, -$b, $b, 1.0, 35.0);
        return $lightProj->multiply($lightView);
    }

    /**
     * Packs point light & shadow parameters into a 144-byte binary block (9 x float4):
     * - Mat4 lightVP (64 bytes)
     * - 2 x PointLight (pos+radius, color+intensity = 64 bytes)
     * - float4 shadowParams (x = shadowsEnabled, y = pointLightCount, z = shadowBias, w = pad = 16 bytes)
     */
    public function packLightingExtension(?Mat4 $lightVP = null): string
    {
        $lvp = ($lightVP ?? $this->lightViewProjectionMatrix())->toBinary();

        $pl0 = $this->pointLights[0] ?? null;
        $pl1 = $this->pointLights[1] ?? null;

        $p0Pos = $pl0 !== null ? pack('f4', $pl0->position->x, $pl0->position->y, $pl0->position->z, $pl0->radius) : pack('f4', 0.0, 0.0, 0.0, 1.0);
        $p0Col = $pl0 !== null ? pack('f4', $pl0->color[0], $pl0->color[1], $pl0->color[2], $pl0->intensity) : pack('f4', 0.0, 0.0, 0.0, 0.0);

        $p1Pos = $pl1 !== null ? pack('f4', $pl1->position->x, $pl1->position->y, $pl1->position->z, $pl1->radius) : pack('f4', 0.0, 0.0, 0.0, 1.0);
        $p1Col = $pl1 !== null ? pack('f4', $pl1->color[0], $pl1->color[1], $pl1->color[2], $pl1->intensity) : pack('f4', 0.0, 0.0, 0.0, 0.0);

        $plCount = min(2, count($this->pointLights));
        $fogDensity = $this->fogEnabled ? max(0.0, $this->fogDensity) : 0.0;
        $params = pack('f4', $this->shadowsEnabled ? 1.0 : 0.0, (float)$plCount, 0.004, $fogDensity);

        return $lvp . $p0Pos . $p0Col . $p1Pos . $p1Col . $params;
    }

    public function remove(Entity $entity): void
    {
        $idx = array_search($entity, $this->entities, true);
        if ($idx !== false) {
            array_splice($this->entities, $idx, 1);
        }
    }

    /**
     * Casts a 3D ray and returns the closest hit pickable Entity and distance, or null if none hit.
     *
     * @return ?array{entity: Entity, distance: float, point: Vec3}
     */
    public function raycast(Ray $ray): ?array
    {
        $closestEntity = null;
        $closestDist = INF;

        foreach ($this->allEntities() as $entity) {
            if (!$entity->pickable || $entity->instanceCount > 0) {
                continue;
            }
            $radius = $entity->worldBoundingRadius();
            $t = $ray->intersectSphere($entity->worldPosition(), $radius);
            if ($t !== null && $t < $closestDist) {
                $closestDist = $t;
                $closestEntity = $entity;
            }
        }

        if ($closestEntity === null) {
            return null;
        }

        return [
            'entity'   => $closestEntity,
            'distance' => $closestDist,
            'point'    => $ray->at($closestDist),
        ];
    }

    /**
     * Picks the closest 3D Entity under 2D viewport coordinates ($x, $y) within ($width, $height).
     *
     * @return ?array{entity: Entity, distance: float, point: Vec3}
     */
    public function pick(float $x, float $y, float $width, float $height): ?array
    {
        $ray = $this->camera->screenToRay($x, $y, $width, $height);
        return $this->raycast($ray);
    }

    /**
     * Binds this 3D scene to $canvas so that the engine's GPU pipeline
     * executes the 3D RenderPass directly into the canvas's VRAM target.
     */
    public function renderToCanvas(Canvas $canvas, mixed $device = null, mixed $commandBuffer = null): void
    {
        $canvas->scene = $this;
        $canvas->rendered();
    }

    /**
     * A native UI node that displays this 3D $scene across all 5 platforms
     * (macOS, Windows, Linux, iOS, Android), with optional interactive camera
     * orbit controls (mouse drag / touch drag / wheel zoom / pinch zoom) and
     * 3D raycast click picking.
     *
     * @param ?Closure(float $dt): void $onUpdate Called each frame before rendering
     * @param ?Closure(?array{entity: Entity, distance: float, point: Vec3}): void $onPick Called when the user clicks/taps
     * @param ?Closure(Painter $p, array{0: float, 1: float, 2: float, 3: float} $r): void $overlay Optional 2D HUD overlay callback
     */
    public static function view(
        Context $c,
        self $scene,
        bool $orbitControls = true,
        ?Closure $onUpdate = null,
        ?Closure $onPick = null,
        ?Closure $overlay = null,
        int $canvasWidth = 800,
        int $canvasHeight = 600
    ): Node {
        $e = $c->column();
        $c->end();
        $e->focusable()->autoFocus();

        $rt = $c->runtime;
        $state = $rt?->local($e->id, static fn() => (object)[
            'canvas'   => new Canvas($canvasWidth, $canvasHeight),
            'yaw'      => atan2($scene->camera->position->x - $scene->camera->target->x, $scene->camera->position->z - $scene->camera->target->z),
            'pitch'    => asin(max(-1.0, min(1.0, ($scene->camera->position->y - $scene->camera->target->y) / max(0.001, $scene->camera->position->sub($scene->camera->target)->length())))),
            'dist'     => max(0.5, $scene->camera->position->sub($scene->camera->target)->length()),
            'dragging' => false,
            'downX'    => 0.0,
            'downY'    => 0.0,
            'lastX'    => 0.0,
            'lastY'    => 0.0,
            'lastTime' => hrtime(true) / 1e9,
            'rect'     => [0.0, 0.0, (float)$canvasWidth, (float)$canvasHeight],
        ]) ?? (object)[
            'canvas'   => new Canvas($canvasWidth, $canvasHeight),
            'yaw'      => 0.0,
            'pitch'    => 0.2,
            'dist'     => 6.0,
            'dragging' => false,
            'downX'    => 0.0,
            'downY'    => 0.0,
            'lastX'    => 0.0,
            'lastY'    => 0.0,
            'lastTime' => hrtime(true) / 1e9,
            'rect'     => [0.0, 0.0, (float)$canvasWidth, (float)$canvasHeight],
        ];

        $e->handleInput(static function (InputEvent $ev) use ($scene, $state, $orbitControls, $onPick): bool {
            if ($ev->kind === InputEvent::POINTER_DOWN) {
                $state->dragging = true;
                [$state->downX, $state->downY] = [$ev->x, $ev->y];
                [$state->lastX, $state->lastY] = [$ev->x, $ev->y];
                return true;
            }
            if ($ev->kind === InputEvent::POINTER_UP) {
                $state->dragging = false;
                if ($onPick !== null && hypot($ev->x - $state->downX, $ev->y - $state->downY) < 6.0) {
                    $hit = $scene->pick($ev->x, $ev->y, $state->rect[2], $state->rect[3]);
                    $onPick($hit);
                }
                return true;
            }
            if ($ev->kind === InputEvent::POINTER_MOVE && $state->dragging && $orbitControls) {
                $dx = $ev->x - $state->lastX;
                $dy = $ev->y - $state->lastY;
                $state->yaw -= $dx * 0.008;
                $state->pitch = max(-1.45, min(1.45, $state->pitch + $dy * 0.008));
                [$state->lastX, $state->lastY] = [$ev->x, $ev->y];
                return true;
            }
            if ($ev->kind === InputEvent::SCROLL && $orbitControls) {
                $state->dist = max(1.5, min(50.0, $state->dist - $ev->dy * 0.05));
                return true;
            }
            if ($ev->kind === InputEvent::ZOOM && $orbitControls && $ev->scale > 0.0) {
                $state->dist = max(1.5, min(50.0, $state->dist / $ev->scale));
                return true;
            }
            return false;
        });

        $e->draw(static function (Painter $p, array $r) use ($scene, $state, $orbitControls, $onUpdate, $overlay): void {
            $state->rect = $r;
            $p->animationFrame();

            $now = hrtime(true) / 1e9;
            $dt = max(0.001, $now - $state->lastTime);
            $state->lastTime = $now;

            if ($orbitControls) {
                $target = $scene->camera->target;
                $cx = $target->x + $state->dist * cos($state->pitch) * sin($state->yaw);
                $cy = $target->y + $state->dist * sin($state->pitch);
                $cz = $target->z + $state->dist * cos($state->pitch) * cos($state->yaw);
                $scene->camera->position = new Vec3($cx, $cy, $cz);
            }

            $scene->camera->aspect = $r[2] / max(1.0, $r[3]);
            $scene->step(min(0.1, $dt));
            if ($onUpdate !== null) {
                $onUpdate($dt);
            }

            $state->canvas->renderScene($scene);
            $p->image($state->canvas->bitmap(), $r, Node::FILL_BOX);

            if ($overlay !== null) {
                $overlay($p, $r);
            }
        });

        return $e;
    }

    /**
     * Renders this scene off-screen into an RGBA Image on macOS (Metal) or Windows (Direct3D 11).
     * Ideal for testing, snapshots, or headless environments.
     */
    public function renderOffscreen(int $width, int $height, mixed $device = null): Image
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->renderOffscreenD3d($width, $height);
        }
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('Scene::renderOffscreen requires macOS Metal or Windows Direct3D 11');
        }

        ObjC::boot(['Metal']);
        $mtl = \FFI::cdef('void* MTLCreateSystemDefaultDevice(void);', '/System/Library/Frameworks/Metal.framework/Metal');
        $device ??= ObjC::import($mtl->MTLCreateSystemDefaultDevice());
        $queue = ObjC::msg('p:', $device, 'newCommandQueue');

        // Color texture
        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:', 70, $width, $height, false);
        ObjC::msg('v:L', $desc, 'setUsage:', 1 | 4); // ShaderRead | RenderTarget
        $colorTex = ObjC::msg('p:p', $device, 'newTextureWithDescriptor:', $desc);

        $cb = ObjC::msg('p:', $queue, 'commandBuffer');
        $pass = MetalRenderPass::instance($device);
        $pass->render($this, $width, $height, $colorTex, $cb);

        ObjC::msg('v:', $cb, 'commit');
        ObjC::msg('v:', $cb, 'waitUntilCompleted');

        // Read pixels into an Image
        $bytesPerRow = $width * 4;
        $totalBytes = $bytesPerRow * $height;
        $buf = Mem::alloc($totalBytes);

        $region = ObjC::$c->new('MTLRegion');
        $region->w = $width; $region->h = $height; $region->d = 1;

        ObjC::msg('v:pLRL', $colorTex, 'getBytes:bytesPerRow:fromRegion:mipmapLevel:', $buf, $bytesPerRow, $region, 0);

        $rawRgba = \FFI::string($buf, $totalBytes);
        ObjC::msg('v:', $colorTex, 'release');
        ObjC::msg('v:', $queue, 'release');

        return Image::fromPixels($width, $height, $rawRgba);
    }

    private function renderOffscreenD3d(int $width, int $height): Image
    {
        $f = \MyPHP\Native\D3d::ffi();
        $dev = \MyPHP\Native\D3d::device();
        $ctx = \MyPHP\Native\D3d::context();

        // Render Target Texture (R8G8B8A8_UNORM = 28, BIND_RENDER_TARGET = 0x20)
        $desc = $f->new('D3D11_TEXTURE2D_DESC');
        $desc->Width = $width;
        $desc->Height = $height;
        $desc->MipLevels = 1;
        $desc->ArraySize = 1;
        $desc->Format = 28; // R8G8B8A8_UNORM
        $desc->SampleDesc->Count = 1;
        $desc->Usage = 0;   // DEFAULT
        $desc->BindFlags = 0x20; // BIND_RENDER_TARGET

        $texOut = $f->new('void *');
        \MyPHP\Native\Com::check(($dev->v->CreateTexture2D)($dev, \FFI::addr($desc), null, \FFI::addr($texOut)), 'D3D11 offscreen texture');
        $colorTex = \MyPHP\Native\Com::typed($f, $texOut, 'void *');

        $rtvOut = $f->new('void *');
        \MyPHP\Native\Com::check(($dev->v->CreateRenderTargetView)($dev, $colorTex, null, \FFI::addr($rtvOut)), 'D3D11 offscreen RTV');
        $rtv = \MyPHP\Native\Com::typed($f, $rtvOut, 'void *');

        \MyPHP\Plugins\Scene\Native\D3dRenderPass::instance()->render($this, $width, $height, $rtv);

        // Staging texture for CPU readback
        $desc->Usage = 3; // STAGING
        $desc->BindFlags = 0;
        $desc->CPUAccessFlags = 0x20000; // CPU_READ
        $stgOut = $f->new('void *');
        \MyPHP\Native\Com::check(($dev->v->CreateTexture2D)($dev, \FFI::addr($desc), null, \FFI::addr($stgOut)), 'D3D11 staging texture');
        $staging = \MyPHP\Native\Com::typed($f, $stgOut, 'void *');

        ($ctx->v->CopyResource)($ctx, $staging, $colorTex);

        $map = $f->new('D3D11_MAPPED_SUBRESOURCE');
        \MyPHP\Native\Com::check(($ctx->v->Map)($ctx, $staging, 0, 1 /* MAP_READ */, 0, \FFI::addr($map)), 'D3D11 Map readback');
        $rawRgba = '';
        $baseAddr = Mem::addr($map->pData);
        for ($y = 0; $y < $height; $y++) {
            $rawRgba .= \FFI::string($f->cast('char *', $baseAddr + $y * $map->RowPitch), $width * 4);
        }
        ($ctx->v->Unmap)($ctx, $staging, 0);

        \MyPHP\Native\Com::release($staging);
        \MyPHP\Native\Com::release($rtv);
        \MyPHP\Native\Com::release($colorTex);

        return Image::fromPixels($width, $height, $rawRgba);
    }
}
