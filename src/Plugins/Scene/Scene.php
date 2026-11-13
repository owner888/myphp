<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Native\Mem;
use MyPHP\Native\ObjC;
use MyPHP\Plugins\Scene\Math\{Ray, Vec3};
use MyPHP\Plugins\Scene\Native\MetalRenderPass;
use MyPHP\UI\{Canvas, Context, Image, Node, Painter};
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
    public Camera3D $camera;
    public Vec3 $lightDirection;
    /** @var array{0: float, 1: float, 2: float, 3: float} */
    public array $lightColor = [1.0, 1.0, 1.0, 1.0];
    /** @var array{0: float, 1: float, 2: float, 3: float} */
    public array $ambientColor = [0.2, 0.2, 0.25, 1.0];
    /** @var array{0: float, 1: float, 2: float, 3: float} */
    public array $clearColor = [0.08, 0.09, 0.11, 1.0];

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

        foreach ($this->entities as $entity) {
            if (!$entity->pickable || $entity->instanceCount > 0) {
                continue;
            }
            $radius = $entity->worldBoundingRadius();
            $t = $ray->intersectSphere($entity->position, $radius);
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
