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
 * - Real-time 1024x1024 Directional Shadow Mapping with 3x3 PCF soft shadows
 * - Dynamic Point Lights with smooth radial attenuation
 * - CPU Frustum Culling + Scene Graph parent-child hierarchy support
 * - Depth testing with cached MTLDepthStencilState (MTLPixelFormatDepth32Float)
 * - Zero-allocation per-frame: meshes & textures stay resident in VRAM
 */
final class MetalRenderPass
{
    private const PIXEL_FORMAT_RGBA8 = 70;      // MTLPixelFormatRGBA8Unorm
    private const PIXEL_FORMAT_DEPTH32F = 252;  // MTLPixelFormatDepth32Float
    private const PRIMITIVE_TRIANGLE = 3;       // MTLPrimitiveTypeTriangle
    private const INDEX_TYPE_UINT32 = 1;        // MTLIndexTypeUInt32
    private const CULL_MODE_NONE = 0;           // MTLCullModeNone
    private const CULL_MODE_BACK = 2;           // MTLCullModeBack
    private const WINDING_CCW = 1;              // MTLWindingCounterClockwise (0 is Clockwise)
    private const FILL_MODE_FILL = 0;           // MTLTriangleFillModeFill
    private const FILL_MODE_LINES = 1;          // MTLTriangleFillModeLines
    private const LOAD_CLEAR = 2;               // MTLLoadActionClear
    private const STORE_STORE = 1;              // MTLStoreActionStore
    private const STORE_DONT_CARE = 0;          // MTLStoreActionDontCare
    private const TEXTURE_SHADER_READ = 1;      // MTLTextureUsageShaderRead
    private const TEXTURE_RENDER_TARGET = 4;    // MTLTextureUsageRenderTarget
    private const SHADOW_MAP_SIZE = 1024;

    private static ?self $instance = null;

    private mixed $device;
    private mixed $pipelineState = null;
    private mixed $shadowPipelineState = null;
    private mixed $depthStencilState = null;
    private mixed $whiteTexture = null;
    private mixed $shadowMapTexture = null;
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
        float4 shadowPos;
    };

    struct InstanceData {
        float4x4 model;
        float4   color;
    };

    // 352 bytes total
    struct Uniforms {
        float4x4 mvp;           // for single entity: MVP; for instanced: ViewProj
        float4x4 model;
        float4   lightDir;      // xyz = direction, w = pixelated (1.0 = nearest, 0.0 = linear)
        float4   lightColor;    // rgb = color, a = instanced flag (1.0 = instanced, 0.0 = single)
        float4   ambientColor;  // rgb = color, a = intensity
        float4   materialColor; // rgba
        float4   cameraPos;     // xyz = position, w = shininess
        float4x4 lightVP;       // directional light ViewProj matrix for shadow mapping
        float4   pointPos0;     // xyz = pos, w = radius
        float4   pointCol0;     // rgb = color, w = intensity
        float4   pointPos1;     // xyz = pos, w = radius
        float4   pointCol1;     // rgb = color, w = intensity
        float4   shadowParams;  // x = shadowsEnabled, y = pointLightCount, z = bias, w = pad
    };

    // Shadow depth-only vertex shader
    vertex float4 v_shadow(
        VertexIn in [[stage_in]],
        uint iid [[instance_id]],
        constant Uniforms& u [[buffer(1)]],
        const device InstanceData* instances [[buffer(2)]]
    ) {
        if (u.lightColor.w > 0.5) {
            InstanceData inst = instances[iid];
            float4 world = inst.model * float4(in.position, 1.0);
            return u.lightVP * world;
        }
        return u.lightVP * (u.model * float4(in.position, 1.0));
    }

    vertex VertexOut v_scene(
        VertexIn in [[stage_in]],
        uint iid [[instance_id]],
        constant Uniforms& u [[buffer(1)]],
        const device InstanceData* instances [[buffer(2)]]
    ) {
        VertexOut out;
        float4 world;
        if (u.lightColor.w > 0.5) {
            InstanceData inst = instances[iid];
            world        = inst.model * float4(in.position, 1.0);
            out.position = u.mvp * world;
            out.worldPos = world.xyz;
            out.normal   = (inst.model * float4(in.normal, 0.0)).xyz;
            out.uv       = in.uv;
            out.color    = in.color * u.materialColor * inst.color;
        } else {
            world        = u.model * float4(in.position, 1.0);
            out.position = u.mvp * float4(in.position, 1.0);
            out.worldPos = world.xyz;
            out.normal   = (u.model * float4(in.normal, 0.0)).xyz;
            out.uv       = in.uv;
            out.color    = in.color * u.materialColor;
        }
        out.shadowPos = u.lightVP * world;
        return out;
    }

    float calcPointLight(float3 worldPos, float3 N, float3 V, float shininess, float4 pPos, float4 pCol, float3 baseRgb, thread float3& outLight) {
        float3 toLight = pPos.xyz - worldPos;
        float dist = length(toLight);
        float radius = max(pPos.w, 0.1);
        if (dist >= radius) { return 0.0; }
        float3 L = toLight / max(dist, 1e-4);
        float atten = 1.0 - (dist / radius);
        atten = atten * atten * pCol.w;

        float diff = max(dot(N, L), 0.0);
        float3 H = normalize(L + V);
        float spec = pow(max(dot(N, H), 0.0), max(shininess, 1.0));

        outLight += atten * pCol.rgb * (diff * baseRgb + spec * 0.4);
        return 1.0;
    }

    fragment float4 f_scene(
        VertexOut in [[stage_in]],
        constant Uniforms& u [[buffer(1)]],
        texture2d<float> tex [[texture(0)]],
        depth2d<float> shadowMap [[texture(1)]]
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
        float diff = max(dot(N, L), 0.0);
        float3 diffuse = diff * u.lightColor.rgb * baseColor.rgb;
        float spec = pow(max(dot(N, H), 0.0), max(u.cameraPos.w, 1.0));
        float3 specular = spec * u.lightColor.rgb * 0.35;

        float shadow = 1.0;
        if (u.shadowParams.x > 0.5) {
            float3 proj = in.shadowPos.xyz / max(in.shadowPos.w, 1e-4);
            float2 suv = float2(proj.x * 0.5 + 0.5, 0.5 - proj.y * 0.5);
            float bias = max(u.shadowParams.z * (1.0 - dot(N, L)), 0.0015);
            float currentDepth = proj.z - bias;
            float sum = 0.0;
            float texel = 1.0 / 1024.0;
            for (int dy = -1; dy <= 1; dy++) {
                for (int dx = -1; dx <= 1; dx++) {
                    float closest = shadowMap.read(uint2(clamp(suv + float2(dx, dy) * texel, 0.0, 1.0) * 1023.0));
                    sum += (currentDepth > closest) ? 0.25 : 1.0;
                }
            }
            if (suv.x >= 0.0 && suv.x <= 1.0 && suv.y >= 0.0 && suv.y <= 1.0 && proj.z >= 0.0 && proj.z <= 1.0) {
                shadow = sum / 9.0;
            }
        }

        float3 finalColor = ambient + shadow * (diffuse + specular);

        // Point lights
        if (u.shadowParams.y > 0.5) {
            float3 toL = u.pointPos0.xyz - in.worldPos;
            float d = length(toL);
            float r = max(u.pointPos0.w, 0.1);
            if (d < r) {
                float3 pL = toL / max(d, 1e-4);
                float att = (1.0 - d / r);
                att = att * att * u.pointCol0.w;
                float pDiff = max(dot(N, pL), 0.0);
                float3 pH = normalize(pL + V);
                float pSpec = pow(max(dot(N, pH), 0.0), max(u.cameraPos.w, 1.0));
                finalColor += att * u.pointCol0.rgb * (pDiff * baseColor.rgb + pSpec * 0.4);
            }
        }
        if (u.shadowParams.y > 1.5) {
            float3 toL = u.pointPos1.xyz - in.worldPos;
            float d = length(toL);
            float r = max(u.pointPos1.w, 0.1);
            if (d < r) {
                float3 pL = toL / max(d, 1e-4);
                float att = (1.0 - d / r);
                att = att * att * u.pointCol1.w;
                float pDiff = max(dot(N, pL), 0.0);
                float3 pH = normalize(pL + V);
                float pSpec = pow(max(dot(N, pH), 0.0), max(u.cameraPos.w, 1.0));
                finalColor += att * u.pointCol1.rgb * (pDiff * baseColor.rgb + pSpec * 0.4);
            }
        }

        // Fresnel Rim Lighting (u.ambientColor.w = rimStrength)
        if (u.ambientColor.w > 0.01) {
            float ndotv = max(dot(N, V), 0.0);
            float rim = pow(1.0 - ndotv, 3.0) * u.ambientColor.w;
            finalColor += rim * float3(0.35, 0.9, 1.0);
        }

        // Exponential distance fog (u.shadowParams.w = fogDensity)
        if (u.shadowParams.w > 0.0001) {
            float camDist = length(u.cameraPos.xyz - in.worldPos);
            float fogFactor = exp(-pow(camDist * u.shadowParams.w, 2.0));
            fogFactor = clamp(fogFactor, 0.0, 1.0);
            float3 horizon = u.ambientColor.rgb * 0.6;
            finalColor = mix(horizon, finalColor, fogFactor);
        }

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
        $this->initShadowMap();
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
        $vShadow = ObjC::msg('p:p', $lib, 'newFunctionWithName:', ObjC::str('v_shadow'));

        // Vertex Descriptor (48-byte stride)
        $vdesc = ObjC::msg('p:', ObjC::cls('MTLVertexDescriptor'), 'vertexDescriptor');
        $attrs = ObjC::msg('p:', $vdesc, 'attributes');
        $layouts = ObjC::msg('p:', $vdesc, 'layouts');

        $a0 = ObjC::msg('p:L', $attrs, 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:L', $a0, 'setFormat:', 30); ObjC::msg('v:L', $a0, 'setOffset:', 0); ObjC::msg('v:L', $a0, 'setBufferIndex:', 0);
        $a1 = ObjC::msg('p:L', $attrs, 'objectAtIndexedSubscript:', 1);
        ObjC::msg('v:L', $a1, 'setFormat:', 30); ObjC::msg('v:L', $a1, 'setOffset:', 12); ObjC::msg('v:L', $a1, 'setBufferIndex:', 0);
        $a2 = ObjC::msg('p:L', $attrs, 'objectAtIndexedSubscript:', 2);
        ObjC::msg('v:L', $a2, 'setFormat:', 29); ObjC::msg('v:L', $a2, 'setOffset:', 24); ObjC::msg('v:L', $a2, 'setBufferIndex:', 0);
        $a3 = ObjC::msg('p:L', $attrs, 'objectAtIndexedSubscript:', 3);
        ObjC::msg('v:L', $a3, 'setFormat:', 31); ObjC::msg('v:L', $a3, 'setOffset:', 32); ObjC::msg('v:L', $a3, 'setBufferIndex:', 0);

        $l0 = ObjC::msg('p:L', $layouts, 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:L', $l0, 'setStride:', Mesh::VERTEX_STRIDE);
        ObjC::msg('v:L', $l0, 'setStepFunction:', 1);

        // 1. Main Scene Pipeline
        $desc = ObjC::msg('p:', ObjC::alloc('MTLRenderPipelineDescriptor'), 'init');
        ObjC::msg('v:p', $desc, 'setVertexFunction:', $vfn);
        ObjC::msg('v:p', $desc, 'setFragmentFunction:', $ffn);
        $ca = ObjC::msg('p:L', ObjC::msg('p:', $desc, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:L', $ca, 'setPixelFormat:', self::PIXEL_FORMAT_RGBA8);
        ObjC::msg('v:L', $desc, 'setDepthAttachmentPixelFormat:', self::PIXEL_FORMAT_DEPTH32F);
        ObjC::msg('v:p', $desc, 'setVertexDescriptor:', $vdesc);

        $this->pipelineState = ObjC::msg('p:pp', $this->device, 'newRenderPipelineStateWithDescriptor:error:', $desc, FFI::addr($err))
            ?? throw new RuntimeException('Metal 3D pipeline state creation failed');

        // 2. Shadow Depth-Only Pipeline
        $sDesc = ObjC::msg('p:', ObjC::alloc('MTLRenderPipelineDescriptor'), 'init');
        ObjC::msg('v:p', $sDesc, 'setVertexFunction:', $vShadow);
        ObjC::msg('v:p', $sDesc, 'setFragmentFunction:', null);
        ObjC::msg('v:L', $sDesc, 'setDepthAttachmentPixelFormat:', self::PIXEL_FORMAT_DEPTH32F);
        ObjC::msg('v:p', $sDesc, 'setVertexDescriptor:', $vdesc);

        $this->shadowPipelineState = ObjC::msg('p:pp', $this->device, 'newRenderPipelineStateWithDescriptor:error:', $sDesc, FFI::addr($err))
            ?? throw new RuntimeException('Metal shadow pipeline state creation failed');
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

    private function initShadowMap(): void
    {
        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:',
            self::PIXEL_FORMAT_DEPTH32F, self::SHADOW_MAP_SIZE, self::SHADOW_MAP_SIZE, false);
        ObjC::msg('v:L', $desc, 'setUsage:', self::TEXTURE_SHADER_READ | self::TEXTURE_RENDER_TARGET);
        ObjC::msg('v:L', $desc, 'setStorageMode:', 2); // private
        $this->shadowMapTexture = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc)
            ?? throw new RuntimeException('Metal shadow map allocation failed');
    }

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

    private function depthTexture(int $w, int $h): mixed
    {
        $key = "{$w}x{$h}";
        if (isset($this->depthTextures[$key])) {
            return $this->depthTextures[$key];
        }

        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:',
            self::PIXEL_FORMAT_DEPTH32F, $w, $h, false);
        ObjC::msg('v:L', $desc, 'setUsage:', self::TEXTURE_RENDER_TARGET);
        ObjC::msg('v:L', $desc, 'setStorageMode:', 2);

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
     * Executes the 3D RenderPass (Shadow Pass + Main Pass) into $targetColorTexture.
     */
    public function render(Scene $scene, int $width, int $height, mixed $targetColorTexture, mixed $commandBuffer): void
    {
        $aspect = (float)$width / max(1.0, (float)$height);
        $view = $scene->camera->viewMatrix();
        $proj = $scene->camera->projectionMatrix($aspect);
        $viewProj = $proj->multiply($view);

        $lightVP = $scene->lightViewProjectionMatrix();
        $lightingExt = $scene->packLightingExtension($lightVP);

        $camPos = $scene->camera->position;
        $lDir = $scene->lightDirection->normalize();
        $lc = $scene->lightColor;

        // 1. Optional Shadow Depth Pass
        if ($scene->shadowsEnabled) {
            $sPass = ObjC::msg('p:', ObjC::cls('MTLRenderPassDescriptor'), 'renderPassDescriptor');
            $sDa = ObjC::msg('p:', $sPass, 'depthAttachment');
            ObjC::msg('v:p', $sDa, 'setTexture:', $this->shadowMapTexture);
            ObjC::msg('v:L', $sDa, 'setLoadAction:', self::LOAD_CLEAR);
            ObjC::msg('v:L', $sDa, 'setStoreAction:', self::STORE_STORE);
            ObjC::msg('v:d', $sDa, 'setClearDepth:', 1.0);

            $sEnc = ObjC::msg('p:p', $commandBuffer, 'renderCommandEncoderWithDescriptor:', $sPass);
            ObjC::msg('v:p', $sEnc, 'setRenderPipelineState:', $this->shadowPipelineState);
            ObjC::msg('v:p', $sEnc, 'setDepthStencilState:', $this->depthStencilState);
            ObjC::msg('v:L', $sEnc, 'setCullMode:', self::CULL_MODE_BACK);
            ObjC::msg('v:L', $sEnc, 'setFrontFacingWinding:', self::WINDING_CCW);

            foreach ($scene->allEntities() as $entity) {
                if (!$entity->castShadow) {
                    continue;
                }
                $mesh = $entity->mesh;
                $mesh->uploadMetal($this->device);

                $isInstanced = $entity->instanceCount > 0;
                $model = $entity->modelMatrix();
                $mvp = $isInstanced ? $viewProj : $viewProj->multiply($model);

                $uniformBinary = $mvp->toBinary()
                    . $model->toBinary()
                    . pack('f4', $lDir->x, $lDir->y, $lDir->z, 0.0)
                    . pack('f4', $lc[0], $lc[1], $lc[2], $isInstanced ? 1.0 : 0.0)
                    . pack('f4', ...$scene->ambientColor)
                    . pack('f4', ...$entity->material->color)
                    . pack('f4', $camPos->x, $camPos->y, $camPos->z, $entity->material->shininess)
                    . $lightingExt;

                ObjC::msg('v:pLL', $sEnc, 'setVertexBytes:length:atIndex:', $uniformBinary, 352, 1);
                ObjC::msg('v:pLL', $sEnc, 'setVertexBuffer:offset:atIndex:', $mesh->nativeVertexBuffer, 0, 0);

                if ($isInstanced) {
                    $entity->uploadInstancesMetal($this->device);
                    ObjC::msg('v:pLL', $sEnc, 'setVertexBuffer:offset:atIndex:', $entity->nativeInstanceBuffer, 0, 2);
                    ObjC::msg('v:LLLpLL', $sEnc, 'drawIndexedPrimitives:indexCount:indexType:indexBuffer:indexBufferOffset:instanceCount:',
                        self::PRIMITIVE_TRIANGLE, $mesh->indexCount, self::INDEX_TYPE_UINT32, $mesh->nativeIndexBuffer, 0, $entity->instanceCount);
                } else {
                    ObjC::msg('v:pLL', $sEnc, 'setVertexBuffer:offset:atIndex:', $mesh->nativeVertexBuffer, 0, 2);
                    ObjC::msg('v:LLLpL', $sEnc, 'drawIndexedPrimitives:indexCount:indexType:indexBuffer:indexBufferOffset:',
                        self::PRIMITIVE_TRIANGLE, $mesh->indexCount, self::INDEX_TYPE_UINT32, $mesh->nativeIndexBuffer, 0);
                }
            }
            ObjC::msg('v:', $sEnc, 'endEncoding');
        }

        // 2. Main Color + Depth Pass (with Frustum Culling)
        $visibleEntities = $scene->visibleEntities($viewProj);
        $depthTex = $this->depthTexture($width, $height);

        $rpDesc = ObjC::msg('p:', ObjC::cls('MTLRenderPassDescriptor'), 'renderPassDescriptor');
        $ca = ObjC::msg('p:L', ObjC::msg('p:', $rpDesc, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:p', $ca, 'setTexture:', $targetColorTexture);
        ObjC::msg('v:L', $ca, 'setLoadAction:', self::LOAD_CLEAR);
        ObjC::msg('v:L', $ca, 'setStoreAction:', self::STORE_STORE);

        $clr = ObjC::$c->new('MTLClearColor');
        [$clr->r, $clr->g, $clr->b, $clr->a] = $scene->clearColor;
        ObjC::msg('v:k', $ca, 'setClearColor:', $clr);

        $da = ObjC::msg('p:', $rpDesc, 'depthAttachment');
        ObjC::msg('v:p', $da, 'setTexture:', $depthTex);
        ObjC::msg('v:L', $da, 'setLoadAction:', self::LOAD_CLEAR);
        ObjC::msg('v:L', $da, 'setStoreAction:', self::STORE_DONT_CARE);
        ObjC::msg('v:d', $da, 'setClearDepth:', 1.0);

        $enc = ObjC::msg('p:p', $commandBuffer, 'renderCommandEncoderWithDescriptor:', $rpDesc);
        ObjC::msg('v:p', $enc, 'setRenderPipelineState:', $this->pipelineState);
        ObjC::msg('v:p', $enc, 'setDepthStencilState:', $this->depthStencilState);
        ObjC::msg('v:L', $enc, 'setCullMode:', self::CULL_MODE_BACK);
        ObjC::msg('v:L', $enc, 'setFrontFacingWinding:', self::WINDING_CCW);
        ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $this->shadowMapTexture, 1);

        foreach ($visibleEntities as $entity) {
            $mesh = $entity->mesh;
            $mesh->uploadMetal($this->device);

            $mat = $entity->material;
            $tex = $this->textureFor($mat->texture);
            $pixelated = ($mat->texture?->pixelated ?? false) ? 1.0 : 0.0;

            $isInstanced = $entity->instanceCount > 0;
            $model = $entity->modelMatrix();
            $mvp = $isInstanced ? $viewProj : $viewProj->multiply($model);

            // Pack 352-byte Uniforms (ambientColor.w carries material->rimStrength)
            $ac = $scene->ambientColor;
            $uniformBinary = $mvp->toBinary()
                . $model->toBinary()
                . pack('f4', $lDir->x, $lDir->y, $lDir->z, $pixelated)
                . pack('f4', $lc[0], $lc[1], $lc[2], $isInstanced ? 1.0 : 0.0)
                . pack('f4', $ac[0], $ac[1], $ac[2], $mat->rimStrength)
                . pack('f4', ...$mat->color)
                . pack('f4', $camPos->x, $camPos->y, $camPos->z, $mat->shininess)
                . $lightingExt;

            ObjC::msg('v:L', $enc, 'setTriangleFillMode:', $mat->wireframe ? self::FILL_MODE_LINES : self::FILL_MODE_FILL);
            ObjC::msg('v:L', $enc, 'setCullMode:', $mat->wireframe ? self::CULL_MODE_NONE : self::CULL_MODE_BACK);

            ObjC::msg('v:pLL', $enc, 'setVertexBytes:length:atIndex:', $uniformBinary, 352, 1);
            ObjC::msg('v:pLL', $enc, 'setFragmentBytes:length:atIndex:', $uniformBinary, 352, 1);
            ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $tex, 0);
            ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $mesh->nativeVertexBuffer, 0, 0);

            if ($isInstanced) {
                $entity->uploadInstancesMetal($this->device);
                ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $entity->nativeInstanceBuffer, 0, 2);
                ObjC::msg('v:LLLpLL', $enc, 'drawIndexedPrimitives:indexCount:indexType:indexBuffer:indexBufferOffset:instanceCount:',
                    self::PRIMITIVE_TRIANGLE, $mesh->indexCount, self::INDEX_TYPE_UINT32, $mesh->nativeIndexBuffer, 0, $entity->instanceCount);
            } else {
                ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $mesh->nativeVertexBuffer, 0, 2);
                ObjC::msg('v:LLLpL', $enc, 'drawIndexedPrimitives:indexCount:indexType:indexBuffer:indexBufferOffset:',
                    self::PRIMITIVE_TRIANGLE, $mesh->indexCount, self::INDEX_TYPE_UINT32, $mesh->nativeIndexBuffer, 0);
            }
        }

        ObjC::msg('v:', $enc, 'endEncoding');
    }
}
