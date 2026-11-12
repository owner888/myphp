<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Native\Mem;
use MyPHP\Native\ObjC;
use MyPHP\Plugins\Scene\Math\Vec3;
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
     * Binds this 3D scene to $canvas so that the engine's GPU pipeline
     * executes the 3D RenderPass directly into the canvas's VRAM target.
     */
    public function renderToCanvas(Canvas $canvas, mixed $device = null, mixed $commandBuffer = null): void
    {
        $canvas->scene = $this;
        $canvas->rendered();
    }

    /**
     * Renders this scene off-screen into an RGBA Image on macOS Metal.
     * Ideal for testing, snapshots, or headless environments.
     */
    public function renderOffscreen(int $width, int $height, mixed $device = null): Image
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('Scene::renderOffscreen currently requires macOS Metal');
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
}
