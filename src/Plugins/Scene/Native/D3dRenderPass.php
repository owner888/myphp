<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene\Native;

use FFI;
use FFI\CData;
use MyPHP\Native\{Com, D3d, Mem};
use MyPHP\Plugins\Scene\{Mesh, Scene};
use MyPHP\UI\Image;
use WeakMap;

/**
 * High-performance Direct3D 11 3D RenderPass for Windows:
 * - 32-bit float depth testing (DXGI_FORMAT_D32_FLOAT + ID3D11DepthStencilView)
 * - Zero-allocation per-frame: meshes stay resident in VRAM (IMMUTABLE ID3D11Buffer)
 * - Viewport-cached depth textures and views
 * - Blinn-Phong directional lighting + hardware texture mapping (Linear & Point/Pixelated)
 * - Backface culling (CCW front face)
 */
final class D3dRenderPass
{
    private const FORMAT_R32G32B32A32_FLOAT = 2;
    private const FORMAT_R32G32B32_FLOAT    = 6;
    private const FORMAT_R32G32_FLOAT       = 16;
    private const FORMAT_R8G8B8A8_UNORM     = 28;
    private const FORMAT_D32_FLOAT          = 40;
    private const FORMAT_R32_UINT           = 42;

    private const USAGE_DEFAULT   = 0;
    private const USAGE_DYNAMIC   = 2;
    private const BIND_CONSTANT_BUFFER = 0x4;
    private const BIND_SHADER_RESOURCE = 0x8;
    private const BIND_DEPTH_STENCIL   = 0x40;
    private const CPU_WRITE       = 0x10000;
    private const MAP_WRITE_DISCARD = 4;
    private const TRIANGLELIST    = 4;
    private const CLEAR_DEPTH     = 0x1;

    private static ?self $instance = null;

    private FFI $f;
    private CData $device;
    private CData $context;
    private CData $vs;
    private CData $ps;
    private CData $inputLayout;
    private CData $depthStencilState;
    private CData $rasterizerState;
    private CData $linearSampler;
    private CData $pointSampler;
    private CData $constantBuffer;
    private CData $whiteTexture;
    private CData $whiteSrv;

    /** @var array<string, array{0: CData, 1: CData}> Cached [texture, dsv] by "widthxheight" */
    private array $depthTargets = [];
    /** @var WeakMap<Image, array{0: CData, 1: CData, 2: int}> Cached [texture, srv, version] */
    private WeakMap $gpuTextures;

    public const SHADER_HLSL = <<<'HLSL'
    cbuffer SceneUniforms : register(b0) {
        column_major float4x4 mvp;
        column_major float4x4 model;
        float4 lightDir;      // xyz = direction, w = pixelated (1.0 = point, 0.0 = linear)
        float4 lightColor;    // rgb = color, a = instanced flag (1.0 = instanced, 0.0 = single)
        float4 ambientColor;  // rgb = color, a = intensity
        float4 materialColor; // rgba
        float4 cameraPos;     // xyz = position, w = shininess
    };

    Buffer<float4> instances : register(t0);
    Texture2D<float4> tex : register(t0);
    SamplerState linearSampler : register(s0);
    SamplerState pointSampler  : register(s1);

    struct VSIn {
        float3 position : POSITION;
        float3 normal   : NORMAL;
        float2 uv       : TEXCOORD0;
        float4 color    : COLOR0;
    };

    struct PSIn {
        float4 position : SV_Position;
        float3 worldPos : TEXCOORD0;
        float3 normal   : TEXCOORD1;
        float2 uv       : TEXCOORD2;
        float4 color    : COLOR0;
    };

    PSIn vs_scene(VSIn input, uint iid : SV_InstanceID) {
        PSIn output;
        if (lightColor.w > 0.5) {
            uint base = iid * 5;
            float4 c0 = instances[base + 0];
            float4 c1 = instances[base + 1];
            float4 c2 = instances[base + 2];
            float4 c3 = instances[base + 3];
            float4 ic = instances[base + 4];
            float4 world = c0 * input.position.x + c1 * input.position.y + c2 * input.position.z + c3;
            float3 norm  = (c0 * input.normal.x   + c1 * input.normal.y   + c2 * input.normal.z).xyz;
            output.position = mul(mvp, world);
            output.worldPos = world.xyz;
            output.normal   = norm;
            output.uv       = input.uv;
            output.color    = input.color * materialColor * ic;
        } else {
            output.position = mul(mvp, float4(input.position, 1.0));
            output.worldPos = mul(model, float4(input.position, 1.0)).xyz;
            output.normal   = mul(model, float4(input.normal, 0.0)).xyz;
            output.uv       = input.uv;
            output.color    = input.color * materialColor;
        }
        return output;
    }

    float4 ps_scene(PSIn input) : SV_Target {
        float4 texColor = lightDir.w > 0.5
            ? tex.Sample(pointSampler, input.uv)
            : tex.Sample(linearSampler, input.uv);

        float4 baseColor = input.color * texColor;
        if (baseColor.a < 0.01) {
            discard;
        }

        float3 N = normalize(input.normal);
        float3 L = normalize(-lightDir.xyz);
        float3 V = normalize(cameraPos.xyz - input.worldPos);
        float3 H = normalize(L + V);

        float3 ambient  = ambientColor.rgb * baseColor.rgb;
        float  diff     = max(dot(N, L), 0.0);
        float3 diffuse  = diff * lightColor.rgb * baseColor.rgb;
        float  spec     = pow(max(dot(N, H), 0.0), max(cameraPos.w, 1.0));
        float3 specular = spec * lightColor.rgb * 0.35;

        float3 finalColor = ambient + diffuse + specular;
        return float4(finalColor, baseColor.a);
    }
    HLSL;

    public function __construct()
    {
        $this->f = D3d::ffi();
        $this->device = D3d::device();
        $this->context = D3d::context();
        $this->gpuTextures = new WeakMap();

        $this->initShadersAndLayout();
        $this->initStates();
        $this->initWhiteTexture();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function make(\Closure $call, string $what): CData
    {
        $out = $this->f->new('void *');
        Com::check($call(FFI::addr($out)), "D3D11 3D: $what");
        return Com::typed($this->f, $out, 'void *');
    }

    private function initShadersAndLayout(): void
    {
        $f = $this->f;
        $d = $this->device;

        $vsCode = D3d::compile(self::SHADER_HLSL, 'scene3d', 'vs_scene', 'vs_5_0');
        $psCode = D3d::compile(self::SHADER_HLSL, 'scene3d', 'ps_scene', 'ps_5_0');

        $this->vs = $this->make(fn($out) => ($d->v->CreateVertexShader)($d, $vsCode, strlen($vsCode), null, $out), 'CreateVertexShader');
        $this->ps = $this->make(fn($out) => ($d->v->CreatePixelShader)($d, $psCode, strlen($psCode), null, $out), 'CreatePixelShader');

        // Input Layout (48-byte stride: POSITION(12), NORMAL(12), TEXCOORD(8), COLOR(16))
        $elems = $f->new('D3D11_INPUT_ELEMENT_DESC[4]');
        $semPos  = $f->new('char[9]'); FFI::memcpy($semPos,  "POSITION\0", 9);
        $semNorm = $f->new('char[7]'); FFI::memcpy($semNorm, "NORMAL\0",   7);
        $semUv   = $f->new('char[9]'); FFI::memcpy($semUv,   "TEXCOORD\0", 9);
        $semCol  = $f->new('char[6]'); FFI::memcpy($semCol,  "COLOR\0",    6);

        $elems[0]->SemanticName = $semPos;  $elems[0]->Format = self::FORMAT_R32G32B32_FLOAT;    $elems[0]->AlignedByteOffset = 0;
        $elems[1]->SemanticName = $semNorm; $elems[1]->Format = self::FORMAT_R32G32B32_FLOAT;    $elems[1]->AlignedByteOffset = 12;
        $elems[2]->SemanticName = $semUv;   $elems[2]->Format = self::FORMAT_R32G32_FLOAT;       $elems[2]->AlignedByteOffset = 24;
        $elems[3]->SemanticName = $semCol;  $elems[3]->Format = self::FORMAT_R32G32B32A32_FLOAT; $elems[3]->AlignedByteOffset = 32;

        $this->inputLayout = $this->make(
            fn($out) => ($d->v->CreateInputLayout)($d, FFI::addr($elems[0]), 4, $vsCode, strlen($vsCode), $out),
            'CreateInputLayout'
        );
    }

    private function initStates(): void
    {
        $f = $this->f;
        $d = $this->device;

        // Depth Stencil State (DepthEnable = 1, WriteMask = ALL(1), Func = LESS(2))
        $dsDesc = $f->new('D3D11_DEPTH_STENCIL_DESC');
        $dsDesc->DepthEnable = 1;
        $dsDesc->DepthWriteMask = 1; // D3D11_DEPTH_WRITE_MASK_ALL
        $dsDesc->DepthFunc = 2;      // D3D11_COMPARISON_LESS
        $dsDesc->StencilEnable = 0;
        $this->depthStencilState = $this->make(
            fn($out) => ($d->v->CreateDepthStencilState)($d, FFI::addr($dsDesc), $out),
            'CreateDepthStencilState'
        );

        // Rasterizer State (Solid, Cull Back, FrontCounterClockwise = 1)
        $rDesc = $f->new('D3D11_RASTERIZER_DESC');
        $rDesc->FillMode = 3; // SOLID
        $rDesc->CullMode = 3; // CULL_BACK
        $rDesc->FrontCounterClockwise = 1;
        $rDesc->DepthClipEnable = 1;
        $this->rasterizerState = $this->make(
            fn($out) => ($d->v->CreateRasterizerState)($d, FFI::addr($rDesc), $out),
            'CreateRasterizerState'
        );

        // Linear Wrap Sampler
        $sDesc = $f->new('D3D11_SAMPLER_DESC');
        $sDesc->Filter = 0x15; // MIN_MAG_MIP_LINEAR
        $sDesc->AddressU = $sDesc->AddressV = $sDesc->AddressW = 1; // WRAP
        $sDesc->MaxAnisotropy = 1;
        $sDesc->ComparisonFunc = 1;
        $sDesc->MaxLOD = 3.402823466e+38;
        $this->linearSampler = $this->make(
            fn($out) => ($d->v->CreateSamplerState)($d, FFI::addr($sDesc), $out),
            'CreateSamplerState (linear)'
        );

        // Point Wrap Sampler (Pixelated)
        $sDesc->Filter = 0x00; // MIN_MAG_MIP_POINT
        $this->pointSampler = $this->make(
            fn($out) => ($d->v->CreateSamplerState)($d, FFI::addr($sDesc), $out),
            'CreateSamplerState (point)'
        );

        // Constant Buffer (208 bytes rounded up to 16-byte multiple = 208)
        $cbDesc = $f->new('D3D11_BUFFER_DESC');
        $cbDesc->ByteWidth = 208;
        $cbDesc->Usage = self::USAGE_DYNAMIC;
        $cbDesc->BindFlags = self::BIND_CONSTANT_BUFFER;
        $cbDesc->CPUAccessFlags = self::CPU_WRITE;
        $this->constantBuffer = $this->make(
            fn($out) => ($d->v->CreateBuffer)($d, FFI::addr($cbDesc), null, $out),
            'CreateBuffer (3D constants)'
        );
    }

    private function initWhiteTexture(): void
    {
        $f = $this->f;
        $d = $this->device;

        $desc = $f->new('D3D11_TEXTURE2D_DESC');
        $desc->Width = 1;
        $desc->Height = 1;
        $desc->MipLevels = 1;
        $desc->ArraySize = 1;
        $desc->Format = self::FORMAT_R8G8B8A8_UNORM;
        $desc->SampleDesc->Count = 1;
        $desc->Usage = self::USAGE_DEFAULT;
        $desc->BindFlags = self::BIND_SHADER_RESOURCE;

        $px = $f->new('uint8_t[4]');
        $px[0] = 255; $px[1] = 255; $px[2] = 255; $px[3] = 255;

        $init = $f->new('D3D11_SUBRESOURCE_DATA');
        $init->pSysMem = $f->cast('void *', FFI::addr($px));
        $init->SysMemPitch = 4;

        $this->whiteTexture = $this->make(fn($out) => ($d->v->CreateTexture2D)($d, FFI::addr($desc), FFI::addr($init), $out), 'CreateTexture2D (white)');
        $this->whiteSrv = $this->make(fn($out) => ($d->v->CreateShaderResourceView)($d, $this->whiteTexture, null, $out), 'CreateShaderResourceView (white)');
    }

    private function srvFor(?Image $img): CData
    {
        if ($img === null) {
            return $this->whiteSrv;
        }

        [$tex, $srv, $ver] = $this->gpuTextures[$img] ?? [null, null, -1];
        if ($srv !== null && $ver === $img->version) {
            return $srv;
        }

        if ($tex !== null) {
            Com::release($srv);
            Com::release($tex);
        }

        $f = $this->f;
        $d = $this->device;

        $desc = $f->new('D3D11_TEXTURE2D_DESC');
        $desc->Width = $img->w;
        $desc->Height = $img->h;
        $desc->MipLevels = 1;
        $desc->ArraySize = 1;
        $desc->Format = self::FORMAT_R8G8B8A8_UNORM;
        $desc->SampleDesc->Count = 1;
        $desc->Usage = self::USAGE_DEFAULT;
        $desc->BindFlags = self::BIND_SHADER_RESOURCE;

        $init = $f->new('D3D11_SUBRESOURCE_DATA');
        $init->pSysMem = $f->cast('void *', $img->address());
        $init->SysMemPitch = $img->w * 4;

        $newTex = $this->make(fn($out) => ($d->v->CreateTexture2D)($d, FFI::addr($desc), FFI::addr($init), $out), 'CreateTexture2D (3D)');
        $newSrv = $this->make(fn($out) => ($d->v->CreateShaderResourceView)($d, $newTex, null, $out), 'CreateShaderResourceView (3D)');
        $img->uploaded();

        $this->gpuTextures[$img] = [$newTex, $newSrv, $img->version];
        return $newSrv;
    }

    private function depthTarget(int $w, int $h): CData
    {
        $key = "{$w}x{$h}";
        if (isset($this->depthTargets[$key])) {
            return $this->depthTargets[$key][1];
        }

        if (count($this->depthTargets) > 8) {
            foreach ($this->depthTargets as [$t, $dsv]) {
                Com::release($dsv);
                Com::release($t);
            }
            $this->depthTargets = [];
        }

        $f = $this->f;
        $d = $this->device;

        $desc = $f->new('D3D11_TEXTURE2D_DESC');
        $desc->Width = $w;
        $desc->Height = $h;
        $desc->MipLevels = 1;
        $desc->ArraySize = 1;
        $desc->Format = self::FORMAT_D32_FLOAT;
        $desc->SampleDesc->Count = 1;
        $desc->Usage = self::USAGE_DEFAULT;
        $desc->BindFlags = self::BIND_DEPTH_STENCIL;

        $tex = $this->make(fn($out) => ($d->v->CreateTexture2D)($d, FFI::addr($desc), null, $out), 'CreateTexture2D (depth)');
        $dsv = $this->make(fn($out) => ($d->v->CreateDepthStencilView)($d, $tex, null, $out), 'CreateDepthStencilView');

        $this->depthTargets[$key] = [$tex, $dsv];
        return $dsv;
    }

    /**
     * Executes the 3D RenderPass into $targetRtv.
     */
    public function render(Scene $scene, int $width, int $height, CData $targetRtv): void
    {
        $f = $this->f;
        $c = $this->context;

        $dsv = $this->depthTarget($width, $height);

        // Bind RenderTarget + DepthStencil
        $rtvs = $f->new('void *[1]');
        $rtvs[0] = $targetRtv;
        ($c->v->OMSetRenderTargets)($c, 1, $rtvs, $dsv);

        // Viewport
        $vp = $f->new('D3D11_VIEWPORT');
        $vp->Width = (float)$width;
        $vp->Height = (float)$height;
        $vp->MinDepth = 0.0;
        $vp->MaxDepth = 1.0;
        ($c->v->RSSetViewports)($c, 1, FFI::addr($vp));

        // Clear Color & Depth
        $clearRgba = $f->new('float[4]');
        [$clearRgba[0], $clearRgba[1], $clearRgba[2], $clearRgba[3]] = $scene->clearColor;
        ($c->v->ClearRenderTargetView)($c, $targetRtv, $clearRgba);
        ($c->v->ClearDepthStencilView)($c, $dsv, self::CLEAR_DEPTH, 1.0, 0);

        // Pipeline State
        ($c->v->IASetInputLayout)($c, $this->inputLayout);
        ($c->v->IASetPrimitiveTopology)($c, self::TRIANGLELIST);
        ($c->v->VSSetShader)($c, $this->vs, null, 0);
        ($c->v->PSSetShader)($c, $this->ps, null, 0);
        ($c->v->RSSetState)($c, $this->rasterizerState);
        ($c->v->OMSetDepthStencilState)($c, $this->depthStencilState, 0);
        ($c->v->OMSetBlendState)($c, null, null, 0xFFFFFFFF);

        // Samplers (s0 = linear, s1 = point)
        $samplers = $f->new('void *[2]');
        $samplers[0] = $this->linearSampler;
        $samplers[1] = $this->pointSampler;
        ($c->v->PSSetSamplers)($c, 0, 2, $samplers);

        // Constant Buffer (b0)
        $cbs = $f->new('void *[1]');
        $cbs[0] = $this->constantBuffer;
        ($c->v->VSSetConstantBuffers)($c, 0, 1, $cbs);
        ($c->v->PSSetConstantBuffers)($c, 0, 1, $cbs);

        $aspect = (float)$width / max(1.0, (float)$height);
        $view = $scene->camera->viewMatrix();
        $proj = $scene->camera->projectionMatrix($aspect);
        $viewProj = $proj->multiply($view);

        $camPos = $scene->camera->position;
        $lDir = $scene->lightDirection->normalize();

        $vbs = $f->new('void *[1]');
        $strides = $f->new('uint32_t[1]');
        $strides[0] = Mesh::VERTEX_STRIDE;
        $offsets = $f->new('uint32_t[1]');
        $offsets[0] = 0;
        $srvs = $f->new('void *[1]');
        $mapped = $f->new('D3D11_MAPPED_SUBRESOURCE');

        foreach ($scene->entities as $entity) {
            $mesh = $entity->mesh;
            $mesh->uploadD3d($f, $this->device);

            $mat = $entity->material;
            $srvs[0] = $this->srvFor($mat->texture);
            ($c->v->PSSetShaderResources)($c, 0, 1, $srvs);

            $isInstanced = $entity->instanceCount > 0;
            $pixelated = ($mat->texture?->pixelated ?? false) ? 1.0 : 0.0;
            $model = $entity->modelMatrix();
            $mvp = $isInstanced ? $viewProj : $viewProj->multiply($model);

            // Pack 208-byte Uniforms
            $lc = $scene->lightColor;
            $uniformBinary = $mvp->toBinary()
                . $model->toBinary()
                . pack('f4', $lDir->x, $lDir->y, $lDir->z, $pixelated)
                . pack('f4', $lc[0], $lc[1], $lc[2], $isInstanced ? 1.0 : 0.0)
                . pack('f4', ...$scene->ambientColor)
                . pack('f4', ...$mat->color)
                . pack('f4', $camPos->x, $camPos->y, $camPos->z, $mat->shininess);

            Com::check(($c->v->Map)($c, $this->constantBuffer, 0, self::MAP_WRITE_DISCARD, 0, FFI::addr($mapped)), 'Map (3D constants)');
            FFI::memcpy($mapped->pData, $uniformBinary, 208);
            ($c->v->Unmap)($c, $this->constantBuffer, 0);

            $vbs[0] = $mesh->nativeVertexBuffer;
            ($c->v->IASetVertexBuffers)($c, 0, 1, $vbs, $strides, $offsets);
            ($c->v->IASetIndexBuffer)($c, $mesh->nativeIndexBuffer, self::FORMAT_R32_UINT, 0);

            if ($isInstanced) {
                $entity->uploadInstancesD3d($f, $this->device);
                $srvs[0] = $entity->nativeInstanceSrv;
                ($c->v->VSSetShaderResources)($c, 0, 1, $srvs);
                ($c->v->DrawIndexedInstanced)($c, $mesh->indexCount, $entity->instanceCount, 0, 0, 0);
            } else {
                ($c->v->DrawIndexed)($c, $mesh->indexCount, 0, 0);
            }
        }

        // Reset depth state to null so subsequent 2D passes are unaffected
        ($c->v->OMSetDepthStencilState)($c, null, 0);
        ($c->v->IASetInputLayout)($c, null);
    }
}
