<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene\Native;

use FFI;
use MyPHP\Native\ObjC;
use MyPHP\Plugins\Scene\Mesh;
use MyPHP\Plugins\Scene\Scene;
use MyPHP\UI\Image;
use RuntimeException;
use WeakMap;

/**
 * High-performance 3D Metal RenderPass:
 * - Depth testing with cached MTLDepthStencilState (MTLPixelFormatDepth32Float)
 * - Zero-allocation per-frame: meshes & textures stay resident in VRAM
 * - Viewport-cached depth textures
 * - Blinn-Phong directional lighting + hardware texture mapping (Linear & Pixelated/Nearest)
 * - Backface culling (MTLCullModeBack)
 */
final class MetalRenderPass
{
    private const PIXEL_FORMAT_RGBA8 = 70;      // MTLPixelFormatRGBA8Unorm
    private const PIXEL_FORMAT_DEPTH32F = 252;  // MTLPixelFormatDepth32Float
    private const PRIMITIVE_TRIANGLE = 3;       // MTLPrimitiveTypeTriangle
    private const INDEX_TYPE_UINT32 = 1;        // MTLIndexTypeUInt32
    private const CULL_MODE_BACK = 2;           // MTLCullModeBack
    private const WINDING_CCW = 0;              // MTLWindingCounterClockwise
    private const LOAD_CLEAR = 2;               // MTLLoadActionClear
    private const STORE_STORE = 1;              // MTLStoreActionStore
    private const STORE_DONT_CARE = 0;          // MTLStoreActionDontCare
    private const TEXTURE_SHADER_READ = 1;      // MTLTextureUsageShaderRead

    private static ?self $instance = null;

    private mixed $device;
    private mixed $pipelineState = null;
    private mixed $depthStencilState = null;
    private mixed $whiteTexture = null;
    /** @var array<string, mixed> Cached depth textures by "widthxheight" */
    private array $depthTextures = [];
    /** @var WeakMap<Image, array{0: mixed, 1: int}> Cached GPU textures by Image instance and version */
    private WeakMap $gpuTextures;

    private const SHADER_MSL = <<<'MSL'
    #include <metal_stdlib>
    using namespace metal;

    struct VertexIn {
        float3 position [[attribute(0)]];
        float3 normal   [[attribute(1)]];
        float2 uv       [[attribute(2)]];
        float4 color    [[attribute(3)]];
    };

    struct VertexOut {
        float4 position [[position]];
        float3 worldPos;
        float3 normal;
        float2 uv;
        float4 color;
    };

    struct Uniforms {
        float4x4 mvp;
        float4x4 model;
        float4   lightDir;      // xyz = direction, w = pixelated (1.0 = nearest, 0.0 = linear)
        float4   lightColor;    // rgb = color, a = intensity
        float4   ambientColor;  // rgb = color, a = intensity
        float4   materialColor; // rgba
        float4   cameraPos;     // xyz = position, w = shininess
    };

    vertex VertexOut v_scene(VertexIn in [[stage_in]], constant Uniforms& u [[buffer(1)]]) {
        VertexOut out;
        out.position = u.mvp * float4(in.position, 1.0);
        out.worldPos = (u.model * float4(in.position, 1.0)).xyz;
        out.normal   = (u.model * float4(in.normal, 0.0)).xyz;
        out.uv       = in.uv;
        out.color    = in.color * u.materialColor;
        return out;
    }

    fragment float4 f_scene(
        VertexOut in [[stage_in]],
        constant Uniforms& u [[buffer(1)]],
        texture2d<float> tex [[texture(0)]]
    ) {
        constexpr sampler linearSampler(coord::normalized, address::repeat, filter::linear);
        constexpr sampler nearestSampler(coord::normalized, address::repeat, filter::nearest);

        float4 texColor = u.lightDir.w > 0.5
            ? tex.sample(nearestSampler, in.uv)
            : tex.sample(linearSampler, in.uv);

        float4 baseColor = in.color * texColor;
        if (baseColor.a < 0.01) {
            discard_fragment();
        }

        float3 N = normalize(in.normal);
        float3 L = normalize(-u.lightDir.xyz);
        float3 V = normalize(u.cameraPos.xyz - in.worldPos);
        float3 H = normalize(L + V);

        // Ambient
        float3 ambient = u.ambientColor.rgb * baseColor.rgb;

        // Diffuse
        float diff = max(dot(N, L), 0.0);
        float3 diffuse = diff * u.lightColor.rgb * baseColor.rgb;

        // Specular (Blinn-Phong)
        float spec = pow(max(dot(N, H), 0.0), max(u.cameraPos.w, 1.0));
        float3 specular = spec * u.lightColor.rgb * 0.35;

        float3 finalColor = ambient + diffuse + specular;
        return float4(finalColor, baseColor.a);
    }
    MSL;

    public function __construct(mixed $device)
    {
        $this->device = $device;
        $this->gpuTextures = new WeakMap();
        $this->initPipeline();
        $this->initDepthStencil();
        $this->initWhiteTexture();
    }

    public static function instance(mixed $device): self
    {
        return self::$instance ??= new self($device);
    }

    private function initPipeline(): void
    {
        $err = ObjC::$c->new('void*');
        $lib = ObjC::msg('p:ppp', $this->device, 'newLibraryWithSource:options:error:',
            ObjC::str(self::SHADER_MSL), null, FFI::addr($err))
            ?? throw new RuntimeException('Metal 3D shader compile error');

        $vfn = ObjC::msg('p:p', $lib, 'newFunctionWithName:', ObjC::str('v_scene'));
        $ffn = ObjC::msg('p:p', $lib, 'newFunctionWithName:', ObjC::str('f_scene'));

        $desc = ObjC::msg('p:', ObjC::alloc('MTLRenderPipelineDescriptor'), 'init');
        ObjC::msg('v:p', $desc, 'setVertexFunction:', $vfn);
        ObjC::msg('v:p', $desc, 'setFragmentFunction:', $ffn);

        $ca = ObjC::msg('p:L', ObjC::msg('p:', $desc, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:L', $ca, 'setPixelFormat:', self::PIXEL_FORMAT_RGBA8);
        ObjC::msg('v:L', $desc, 'setDepthAttachmentPixelFormat:', self::PIXEL_FORMAT_DEPTH32F);

        // Vertex Descriptor (48-byte stride)
        $vdesc = ObjC::msg('p:', ObjC::cls('MTLVertexDescriptor'), 'vertexDescriptor');
        $attrs = ObjC::msg('p:', $vdesc, 'attributes');
        $layouts = ObjC::msg('p:', $vdesc, 'layouts');

        // 0: position (float3)
        $a0 = ObjC::msg('p:L', $attrs, 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:L', $a0, 'setFormat:', 30); ObjC::msg('v:L', $a0, 'setOffset:', 0); ObjC::msg('v:L', $a0, 'setBufferIndex:', 0);
        // 1: normal (float3)
        $a1 = ObjC::msg('p:L', $attrs, 'objectAtIndexedSubscript:', 1);
        ObjC::msg('v:L', $a1, 'setFormat:', 30); ObjC::msg('v:L', $a1, 'setOffset:', 12); ObjC::msg('v:L', $a1, 'setBufferIndex:', 0);
        // 2: uv (float2)
        $a2 = ObjC::msg('p:L', $attrs, 'objectAtIndexedSubscript:', 2);
        ObjC::msg('v:L', $a2, 'setFormat:', 29); ObjC::msg('v:L', $a2, 'setOffset:', 24); ObjC::msg('v:L', $a2, 'setBufferIndex:', 0);
        // 3: color (float4)
        $a3 = ObjC::msg('p:L', $attrs, 'objectAtIndexedSubscript:', 3);
        ObjC::msg('v:L', $a3, 'setFormat:', 31); ObjC::msg('v:L', $a3, 'setOffset:', 32); ObjC::msg('v:L', $a3, 'setBufferIndex:', 0);

        $l0 = ObjC::msg('p:L', $layouts, 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:L', $l0, 'setStride:', Mesh::VERTEX_STRIDE);
        ObjC::msg('v:L', $l0, 'setStepFunction:', 1); // per-vertex

        ObjC::msg('v:p', $desc, 'setVertexDescriptor:', $vdesc);

        $this->pipelineState = ObjC::msg('p:pp', $this->device, 'newRenderPipelineStateWithDescriptor:error:', $desc, FFI::addr($err))
            ?? throw new RuntimeException('Metal 3D pipeline state creation failed');
    }

    private function initDepthStencil(): void
    {
        $desc = ObjC::msg('p:', ObjC::alloc('MTLDepthStencilDescriptor'), 'init');
        ObjC::msg('v:L', $desc, 'setDepthCompareFunction:', 1); // MTLCompareFunctionLess
        ObjC::msg('v:b', $desc, 'setDepthWriteEnabled:', true);

        $this->depthStencilState = ObjC::msg('p:p', $this->device, 'newDepthStencilStateWithDescriptor:', $desc)
            ?? throw new RuntimeException('Metal depth stencil state creation failed');
    }

    private function initWhiteTexture(): void
    {
        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:',
            self::PIXEL_FORMAT_RGBA8, 1, 1, false);
        ObjC::msg('v:L', $desc, 'setUsage:', self::TEXTURE_SHADER_READ);
        $this->whiteTexture = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc);

        $region = ObjC::$c->new('MTLRegion');
        $region->w = 1; $region->h = 1; $region->d = 1;
        $white = "\xff\xff\xff\xff";
        ObjC::msg('v:RLpL', $this->whiteTexture, 'replaceRegion:mipmapLevel:withBytes:bytesPerRow:', $region, 0, $white, 4);
    }

    /**
     * Obtains or uploads a GPU resident texture for $img.
     */
    private function textureFor(?Image $img): mixed
    {
        if ($img === null) {
            return $this->whiteTexture;
        }

        [$tex, $ver] = $this->gpuTextures[$img] ?? [null, -1];
        if ($tex !== null && $ver === $img->version) {
            return $tex;
        }

        if ($tex !== null) {
            ObjC::msg('v:', $tex, 'release');
        }

        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:',
            self::PIXEL_FORMAT_RGBA8, $img->w, $img->h, false);
        ObjC::msg('v:L', $desc, 'setUsage:', self::TEXTURE_SHADER_READ);
        $newTex = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc)
            ?? throw new RuntimeException('Scene: failed to allocate 3D texture');

        $region = ObjC::$c->new('MTLRegion');
        $region->w = $img->w; $region->h = $img->h; $region->d = 1;
        ObjC::msg('v:RLpL', $newTex, 'replaceRegion:mipmapLevel:withBytes:bytesPerRow:',
            $region, 0, ObjC::$c->cast('void*', $img->address()), $img->w * 4);
        $img->uploaded();

        $this->gpuTextures[$img] = [$newTex, $img->version];
        return $newTex;
    }

    /**
     * Obtains or creates a cached depth texture for viewport $w x $h.
     */
    private function depthTexture(int $w, int $h): mixed
    {
        $key = "{$w}x{$h}";
        if (isset($this->depthTextures[$key])) {
            return $this->depthTextures[$key];
        }

        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:',
            self::PIXEL_FORMAT_DEPTH32F, $w, $h, false);
        ObjC::msg('v:L', $desc, 'setUsage:', 4); // MTLTextureUsageRenderTarget
        ObjC::msg('v:L', $desc, 'setStorageMode:', 2); // MTLStorageModePrivate

        $tex = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc)
            ?? throw new RuntimeException('Metal depth texture allocation failed');

        if (count($this->depthTextures) > 8) {
            foreach ($this->depthTextures as $old) {
                ObjC::msg('v:', $old, 'release');
            }
            $this->depthTextures = [];
        }

        return $this->depthTextures[$key] = $tex;
    }

    /**
     * Executes the 3D RenderPass into $targetColorTexture.
     */
    public function render(Scene $scene, int $width, int $height, mixed $targetColorTexture, mixed $commandBuffer): void
    {
        $depthTex = $this->depthTexture($width, $height);

        $rpDesc = ObjC::msg('p:', ObjC::cls('MTLRenderPassDescriptor'), 'renderPassDescriptor');

        // Color attachment
        $ca = ObjC::msg('p:L', ObjC::msg('p:', $rpDesc, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:p', $ca, 'setTexture:', $targetColorTexture);
        ObjC::msg('v:L', $ca, 'setLoadAction:', self::LOAD_CLEAR);
        ObjC::msg('v:L', $ca, 'setStoreAction:', self::STORE_STORE);

        $clr = ObjC::$c->new('MTLClearColor');
        [$clr->r, $clr->g, $clr->b, $clr->a] = $scene->clearColor;
        ObjC::msg('v:k', $ca, 'setClearColor:', $clr);

        // Depth attachment
        $da = ObjC::msg('p:', $rpDesc, 'depthAttachment');
        ObjC::msg('v:p', $da, 'setTexture:', $depthTex);
        ObjC::msg('v:L', $da, 'setLoadAction:', self::LOAD_CLEAR);
        ObjC::msg('v:L', $da, 'setStoreAction:', self::STORE_DONT_CARE);
        ObjC::msg('v:d', $da, 'setClearDepth:', 1.0);

        // Begin encoding 3D Pass
        $enc = ObjC::msg('p:p', $commandBuffer, 'renderCommandEncoderWithDescriptor:', $rpDesc);
        ObjC::msg('v:p', $enc, 'setRenderPipelineState:', $this->pipelineState);
        ObjC::msg('v:p', $enc, 'setDepthStencilState:', $this->depthStencilState);
        ObjC::msg('v:L', $enc, 'setCullMode:', self::CULL_MODE_BACK);
        ObjC::msg('v:L', $enc, 'setFrontFacingWinding:', self::WINDING_CCW);

        $aspect = (float)$width / max(1.0, (float)$height);
        $view = $scene->camera->viewMatrix();
        $proj = $scene->camera->projectionMatrix($aspect);
        $viewProj = $proj->multiply($view);

        $camPos = $scene->camera->position;
        $lDir = $scene->lightDirection->normalize();

        foreach ($scene->entities as $entity) {
            $mesh = $entity->mesh;
            $mesh->uploadMetal($this->device);

            $mat = $entity->material;
            $tex = $this->textureFor($mat->texture);
            $pixelated = ($mat->texture?->pixelated ?? false) ? 1.0 : 0.0;

            $model = $entity->modelMatrix();
            $mvp = $viewProj->multiply($model);

            // Pack Uniforms (208 bytes)
            $uniformBinary = $mvp->toBinary()
                . $model->toBinary()
                . pack('f4', $lDir->x, $lDir->y, $lDir->z, $pixelated)
                . pack('f4', ...$scene->lightColor)
                . pack('f4', ...$scene->ambientColor)
                . pack('f4', ...$mat->color)
                . pack('f4', $camPos->x, $camPos->y, $camPos->z, $mat->shininess);

            ObjC::msg('v:pLL', $enc, 'setVertexBytes:length:atIndex:', $uniformBinary, strlen($uniformBinary), 1);
            ObjC::msg('v:pLL', $enc, 'setFragmentBytes:length:atIndex:', $uniformBinary, strlen($uniformBinary), 1);
            ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $tex, 0);
            ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $mesh->nativeVertexBuffer, 0, 0);

            ObjC::msg('v:LLLpL', $enc, 'drawIndexedPrimitives:indexCount:indexType:indexBuffer:indexBufferOffset:',
                self::PRIMITIVE_TRIANGLE,
                $mesh->indexCount,
                self::INDEX_TYPE_UINT32,
                $mesh->nativeIndexBuffer,
                0
            );
        }

        ObjC::msg('v:', $enc, 'endEncoding');
    }
}
