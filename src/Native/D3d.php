<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Logger;
use MyPHP\UI\{Atlas, Canvas, CanvasAtlas, DisplayList, Image, ImageAtlas};
use MyPHP\UI\Shader\{Code, Shader};
use RuntimeException;

/**
 * The Direct3D 11 renderer: Gl.php's, myphp's OpenGL renderer (mygo's
 * gpu/gl), on Direct3D 11, not a port of mygo's gpu/d3d11, which draws
 * mygo's scene ops (upstream-deviations.md): a frame's DisplayList drawn
 * with instanced draws of the quads (D3dShader's HLSL, GlShader's line
 * for line), into a window's flip-model swap chain (attach, draw, present)
 * or off screen (readBack). Its shaders' HLSL is mygo's hlsl backend's
 * (UI\Shader\Hlsl).
 *
 * One device serves every window, as Metal's does: the shaders, states and
 * the atlases' textures are made once and shared; a renderer has its
 * quads' and transforms' buffers, its lists and its swap chain. Where the
 * machine has no GPU Direct3D's own, WARP, draws (CI's runners).
 *
 * A list is drawn in one pass, split at each quad that reads the backdrop
 * (glass, blurs): what was drawn so far is copied and blurred at half
 * size, which the quads after read, as Gl's. Canvases are rendered first,
 * each into a texture of its size, and copied to its place among the
 * canvases' layers of the image texture. A shader's runs draw with its
 * pixel stage (D3dShader's SHADER tail around Hlsl's library of it), its
 * uniforms a cbuffer, its images textures of their own.
 */
final class D3d
{
    private const TRANSFORMS = 16384;
    private const R32G32B32A32_FLOAT = 2, R8G8B8A8_UNORM = 28, B8G8R8A8_UNORM = 87, R8_UNORM = 61;
    private const USAGE_DEFAULT = 0, USAGE_DYNAMIC = 2, USAGE_STAGING = 3;
    private const BIND_CONSTANT_BUFFER = 0x4, BIND_SHADER_RESOURCE = 0x8, BIND_RENDER_TARGET = 0x20;
    private const CPU_WRITE = 0x10000, CPU_READ = 0x20000;
    private const MAP_READ = 1, MAP_WRITE_DISCARD = 4;
    private const SRV_BUFFER = 1;
    private const BLEND_ZERO = 1, BLEND_ONE = 2, BLEND_SRC_ALPHA = 5, BLEND_INV_SRC_ALPHA = 6, BLEND_OP_ADD = 1;
    private const TRIANGLESTRIP = 5, TRIANGLELIST = 4;
    private const IID_TEXTURE2D = '6f15aaf2-d208-4e89-9ab4-489535d34f9c';
    private const IID_DXGIDEVICE = '54ec77fa-1377-44e6-8c32-88fd5f44c84c';
    private const IID_DXGIFACTORY2 = '50c83a1c-e072-4c48-87b0-3630fa36a6d0';

    private static ?FFI $f = null;
    /** The device and its immediate context, shared by the renderers; the shaders and states they draw with. */
    private static ?CData $device = null, $context = null;

    /** @internal exposes shared D3D11 device for 3D RenderPass */
    public static function device(): CData
    {
        self::start();
        return self::$device;
    }

    /** @internal exposes shared D3D11 immediate context for 3D RenderPass */
    public static function context(): CData
    {
        self::start();
        return self::$context;
    }
    private static CData $quadVS, $quadPS, $blurVS, $blurPS, $shaderVS, $sampler, $rasterizer, $frameBuffer, $blurBuffer, $noBackdrop;
    /** @var ?\WeakMap<Code, CData> each shader's pixel stage, compiled the first time it draws */
    private static ?\WeakMap $pixelShaders = null;
    /** The shaders' uniforms' cbuffer (b2), of $uniformSize bytes, made larger as a shader asks. */
    private static ?CData $uniformBuffer = null;
    private static int $uniformSize = 0;
    /** @var array<string, CData> the blend states, by name: blend, canvas (its alpha kept premultiplied), hole */
    private static array $blends = [];
    /** Whether WARP draws: no GPU, or none of feature level 11. */
    public static bool $warp = false;
    /** The atlases' textures and their views, shared: the glyph atlas's (R8) and the image atlas's layers (RGBA8). */
    private static ?CData $atlasTexture = null, $atlasView = null, $imagesTexture = null, $imagesView = null;
    private static ?Atlas $atlas = null;
    private static ?ImageAtlas $images = null;

    private CData $quads, $quadsView, $xforms, $xformsView;
    /** @var list<DisplayList> the frames' lists, two: the one drawing, and the last (lastFrame) */
    private array $lists = [];
    private int $frame = 0;
    private float $atlasScale = 1.0, $dark = 0.0;
    /** The window's swap chain and its back buffer's view, of $size pixels. */
    private ?CData $swapChain = null, $backBuffer = null, $backTexture = null;
    /**
     * The glass's textures, of the target's size $backdropSize: a copy of
     * what was drawn (the snapshot), the half-size blur in progress and the
     * blurred backdrop the quads read, each texture, render target's view
     * and shaders' view (the snapshot's no target's).
     * @var array<string, array{0: CData, 1: ?CData, 2: CData}>
     */
    private array $glass = [];
    private array $backdropSize = [0, 0];
    /** @var array<string, array{0: CData, 1: CData, 2: CData}> the textures canvases render into, by size, and those their blurs go through (targetTexture's) */
    private array $canvasTargets = [], $canvasBlurTemps = [];
    /** @var ?\WeakMap<Image, array{0: CData, 1: CData, 2: int}> the textures shaders read images from, their views, the version of the image each holds */
    private ?\WeakMap $imageTextures = null;
    /** @var ?\WeakMap<Canvas, array{0: CData, 1: CData}> the textures shaders read canvases' bitmaps from, copied from their place each frame they are read */
    private ?\WeakMap $canvasTextures = null;
    /** @var array<int, list<CData>> the views each shader draw of the list drawing reads, by its draw: four each */
    private array $shaderTextures = [];
    private array $size = [0, 0];
    /** The values of the target being drawn: viewport in points, pixels per point, height in pixels. */
    private array $target = [1.0, 1.0, 1.0, 1.0];
    /** @var array<string, bool> what this renderer does not draw yet and has said so */
    private array $warned = [];

    public function __construct(public int $capacity = 65536)
    {
        self::start();
        $f = self::$f;
        [$this->quads, $this->quadsView] = $this->floatBuffer($capacity * DisplayList::FLOATS_PER_RECT);
        [$this->xforms, $this->xformsView] = $this->floatBuffer(self::TRANSFORMS * DisplayList::FLOATS_PER_TRANSFORM);
        for ($i = 0; $i < 2; $i++) {
            $this->lists[] = new DisplayList($f->new('float[' . ($capacity * DisplayList::FLOATS_PER_RECT) . ']', false), $capacity,
                $f->new('float[' . (self::TRANSFORMS * DisplayList::FLOATS_PER_TRANSFORM) . ']', false), self::TRANSFORMS);
        }
    }

    /** The FFI of Direct3D 11 and DXGI: their structures, and the methods of their objects' vtables this renderer calls. */
    public static function ffi(): FFI
    {
        if (self::$f !== null) {
            return self::$f;
        }
        $f = FFI::cdef(<<<'C'
            typedef struct { void *r0[3]; void *(*GetBufferPointer)(void *self); size_t (*GetBufferSize)(void *self); } ID3DBlobVtbl;
            typedef struct { ID3DBlobVtbl *v; } ID3DBlob;

            typedef struct { uint32_t Count, Quality; } DXGI_SAMPLE_DESC;
            typedef struct { uint32_t ByteWidth, Usage, BindFlags, CPUAccessFlags, MiscFlags, StructureByteStride; } D3D11_BUFFER_DESC;
            typedef struct { uint32_t Width, Height, MipLevels, ArraySize, Format; DXGI_SAMPLE_DESC SampleDesc; uint32_t Usage, BindFlags, CPUAccessFlags, MiscFlags; } D3D11_TEXTURE2D_DESC;
            typedef struct { uint32_t Format, ViewDimension; uint32_t u[4]; } D3D11_SHADER_RESOURCE_VIEW_DESC;
            typedef struct { const void *pSysMem; uint32_t SysMemPitch, SysMemSlicePitch; } D3D11_SUBRESOURCE_DATA;
            typedef struct { void *pData; uint32_t RowPitch, DepthPitch; } D3D11_MAPPED_SUBRESOURCE;
            typedef struct { const char *SemanticName; uint32_t SemanticIndex, Format, InputSlot, AlignedByteOffset, InputSlotClass, InstanceDataStepRate; } D3D11_INPUT_ELEMENT_DESC;
            typedef struct { int32_t DepthWritingOp0, DepthWritingOp1, DepthWritingOp2, DepthCompareFunc; } D3D11_DEPTH_STENCILOP_DESC;
            typedef struct { int32_t DepthEnable; uint32_t DepthWriteMask, DepthFunc; int32_t StencilEnable; uint8_t StencilReadMask, StencilWriteMask; D3D11_DEPTH_STENCILOP_DESC FrontFace, BackFace; } D3D11_DEPTH_STENCIL_DESC;
            typedef struct { int32_t BlendEnable; uint32_t SrcBlend, DestBlend, BlendOp, SrcBlendAlpha, DestBlendAlpha, BlendOpAlpha; uint8_t RenderTargetWriteMask; } D3D11_RENDER_TARGET_BLEND_DESC;
            typedef struct { int32_t AlphaToCoverageEnable, IndependentBlendEnable; D3D11_RENDER_TARGET_BLEND_DESC RenderTarget[8]; } D3D11_BLEND_DESC;
            typedef struct { uint32_t Filter, AddressU, AddressV, AddressW; float MipLODBias; uint32_t MaxAnisotropy, ComparisonFunc; float BorderColor[4]; float MinLOD, MaxLOD; } D3D11_SAMPLER_DESC;
            typedef struct { uint32_t FillMode, CullMode; int32_t FrontCounterClockwise, DepthBias; float DepthBiasClamp, SlopeScaledDepthBias; int32_t DepthClipEnable, ScissorEnable, MultisampleEnable, AntialiasedLineEnable; } D3D11_RASTERIZER_DESC;
            typedef struct { float TopLeftX, TopLeftY, Width, Height, MinDepth, MaxDepth; } D3D11_VIEWPORT;
            typedef struct { uint32_t left, top, front, right, bottom, back; } D3D11_BOX;
            typedef struct { uint32_t Width, Height, Format; int32_t Stereo; DXGI_SAMPLE_DESC SampleDesc; uint32_t BufferUsage, BufferCount, Scaling, SwapEffect, AlphaMode, Flags; } DXGI_SWAP_CHAIN_DESC1;
            typedef struct { float viewport[2], atlas[2]; float scale, atlasScale, dark, flip, targetH; int32_t from; float pad[2]; } FRAME;
            typedef struct { float dir[2]; float sigma, pad; } BLUR;

            typedef struct { void *r0[3];
                int32_t (*CreateBuffer)(void *self, const D3D11_BUFFER_DESC *desc, const D3D11_SUBRESOURCE_DATA *init, void **out);
                void *r4;
                int32_t (*CreateTexture2D)(void *self, const D3D11_TEXTURE2D_DESC *desc, const D3D11_SUBRESOURCE_DATA *init, void **out);
                void *r6;
                int32_t (*CreateShaderResourceView)(void *self, void *resource, const D3D11_SHADER_RESOURCE_VIEW_DESC *desc, void **out);
                void *r8;
                int32_t (*CreateRenderTargetView)(void *self, void *resource, const void *desc, void **out);
                int32_t (*CreateDepthStencilView)(void *self, void *resource, const void *desc, void **out);
                int32_t (*CreateInputLayout)(void *self, const D3D11_INPUT_ELEMENT_DESC *elements, uint32_t n, const void *code, size_t codeLen, void **out);
                int32_t (*CreateVertexShader)(void *self, const void *code, size_t n, void *linkage, void **out);
                void *r13[2];
                int32_t (*CreatePixelShader)(void *self, const void *code, size_t n, void *linkage, void **out);
                void *r16[4];
                int32_t (*CreateBlendState)(void *self, const D3D11_BLEND_DESC *desc, void **out);
                int32_t (*CreateDepthStencilState)(void *self, const D3D11_DEPTH_STENCIL_DESC *desc, void **out);
                int32_t (*CreateRasterizerState)(void *self, const D3D11_RASTERIZER_DESC *desc, void **out);
                int32_t (*CreateSamplerState)(void *self, const D3D11_SAMPLER_DESC *desc, void **out);
            } ID3D11DeviceVtbl;
            typedef struct { ID3D11DeviceVtbl *v; } ID3D11Device;
            typedef struct { void *r0[7];
                void (*VSSetConstantBuffers)(void *self, uint32_t start, uint32_t n, void **buffers);
                void (*PSSetShaderResources)(void *self, uint32_t start, uint32_t n, void **views);
                void (*PSSetShader)(void *self, void *shader, void **instances, uint32_t n);
                void (*PSSetSamplers)(void *self, uint32_t start, uint32_t n, void **samplers);
                void (*VSSetShader)(void *self, void *shader, void **instances, uint32_t n);
                void (*DrawIndexed)(void *self, uint32_t indices, uint32_t startIndex, int32_t baseVertex);
                void (*Draw)(void *self, uint32_t vertices, uint32_t start);
                int32_t (*Map)(void *self, void *resource, uint32_t sub, uint32_t type, uint32_t flags, D3D11_MAPPED_SUBRESOURCE *out);
                void (*Unmap)(void *self, void *resource, uint32_t sub);
                void (*PSSetConstantBuffers)(void *self, uint32_t start, uint32_t n, void **buffers);
                void (*IASetInputLayout)(void *self, void *layout);
                void (*IASetVertexBuffers)(void *self, uint32_t start, uint32_t n, void **buffers, const uint32_t *strides, const uint32_t *offsets);
                void (*IASetIndexBuffer)(void *self, void *buffer, uint32_t format, uint32_t offset);
                void (*DrawIndexedInstanced)(void *self, uint32_t indexCountPerInstance, uint32_t instanceCount, uint32_t startIndexLocation, int32_t baseVertexLocation, uint32_t startInstanceLocation);
                void (*DrawInstanced)(void *self, uint32_t vertices, uint32_t instances, uint32_t startVertex, uint32_t startInstance);
                void *r22[2];
                void (*IASetPrimitiveTopology)(void *self, uint32_t topology);
                void (*VSSetShaderResources)(void *self, uint32_t start, uint32_t n, void **views);
                void *r26[7];
                void (*OMSetRenderTargets)(void *self, uint32_t n, void **views, void *depth);
                void *r34;
                void (*OMSetBlendState)(void *self, void *state, const float *factor, uint32_t mask);
                void (*OMSetDepthStencilState)(void *self, void *state, uint32_t stencilRef);
                void *r37[6];
                void (*RSSetState)(void *self, void *state);
                void (*RSSetViewports)(void *self, uint32_t n, const D3D11_VIEWPORT *viewports);
                void *r45;
                void (*CopySubresourceRegion)(void *self, void *dst, uint32_t dstSub, uint32_t x, uint32_t y, uint32_t z, void *src, uint32_t srcSub, const D3D11_BOX *box);
                void (*CopyResource)(void *self, void *dst, void *src);
                void (*UpdateSubresource)(void *self, void *dst, uint32_t sub, const D3D11_BOX *box, const void *data, uint32_t rowPitch, uint32_t depthPitch);
                void *r49;
                void (*ClearRenderTargetView)(void *self, void *view, const float *rgba);
                void *r51[2];
                void (*ClearDepthStencilView)(void *self, void *view, uint32_t flags, float depth, uint8_t stencil);
            } ID3D11DeviceContextVtbl;
            typedef struct { ID3D11DeviceContextVtbl *v; } ID3D11DeviceContext;
            typedef struct { void *r0[7]; int32_t (*GetAdapter)(void *self, void **adapter); } IDXGIDeviceVtbl;
            typedef struct { IDXGIDeviceVtbl *v; } IDXGIDevice;
            typedef struct { void *r0[6]; int32_t (*GetParent)(void *self, const void *iid, void **parent); } IDXGIObjectVtbl;
            typedef struct { IDXGIObjectVtbl *v; } IDXGIObject;
            typedef struct { void *r0[15];
                int32_t (*CreateSwapChainForHwnd)(void *self, void *device, void *hwnd, const DXGI_SWAP_CHAIN_DESC1 *desc, const void *fullscreen, void *output, void **out);
                void *r16[8];
                int32_t (*CreateSwapChainForComposition)(void *self, void *device, const DXGI_SWAP_CHAIN_DESC1 *desc, void *output, void **out);
            } IDXGIFactory2Vtbl;
            typedef struct { IDXGIFactory2Vtbl *v; } IDXGIFactory2;
            typedef struct { void *r0[8];
                int32_t (*Present)(void *self, uint32_t sync, uint32_t flags);
                int32_t (*GetBuffer)(void *self, uint32_t i, const void *iid, void **out);
                void *r10[3];
                int32_t (*ResizeBuffers)(void *self, uint32_t n, uint32_t w, uint32_t h, uint32_t format, uint32_t flags);
            } IDXGISwapChainVtbl;
            typedef struct { IDXGISwapChainVtbl *v; } IDXGISwapChain;

            int32_t D3D11CreateDevice(void *adapter, int32_t type, void *software, uint32_t flags, const int32_t *levels, uint32_t n, uint32_t sdk, void **device, int32_t *level, void **context);
            C, 'd3d11.dll');
        return self::$f = $f;
    }

    /** The device, its context, the shaders and the states, made at the first renderer's start. */
    private static function start(): void
    {
        if (self::$device !== null) {
            return;
        }
        $f = self::ffi();
        $dev = $f->new('void *');
        $ctx = $f->new('void *');
        $level = $f->new('int32_t');
        $hr = getenv('MYPHP_D3D_WARP') === '1' ? -1 : $f->D3D11CreateDevice(null, 1 /* hardware */, null, 0x20 /* BGRA */, null, 0, 7, FFI::addr($dev), FFI::addr($level), FFI::addr($ctx));
        if ($hr >= 0 && $level->cdata < 0xb000) {
            // a GPU of feature level 10: shader model 5 is 11's, which WARP has
            Com::release($ctx);
            Com::release($dev);
            $hr = -1;
        }
        if ($hr < 0) {
            self::$warp = true;
            $hr = $f->D3D11CreateDevice(null, 5 /* WARP */, null, 0x20, null, 0, 7, FFI::addr($dev), FFI::addr($level), FFI::addr($ctx));
            self::hr($hr, 'D3D11CreateDevice');
        }
        self::$device = Com::typed(self::$f, $dev, 'ID3D11Device *');
        self::$context = Com::typed(self::$f, $ctx, 'ID3D11DeviceContext *');
        $d = self::$device;
        [$vs, $ps] = [self::compile(D3dShader::COMMON . "\n" . D3dShader::QUAD, 'quad', 'vs', 'vs_5_0'), self::compile(D3dShader::COMMON . "\n" . D3dShader::QUAD, 'quad', 'ps', 'ps_5_0')];
        self::$quadVS = self::make(fn($out) => ($d->v->CreateVertexShader)($d, $vs, strlen($vs), null, $out), 'CreateVertexShader');
        self::$quadPS = self::make(fn($out) => ($d->v->CreatePixelShader)($d, $ps, strlen($ps), null, $out), 'CreatePixelShader');
        $vs = self::compile(D3dShader::COMMON . "\n" . D3dShader::SHADER_VERTEX, 'shader', 'svs', 'vs_5_0');
        self::$shaderVS = self::make(fn($out) => ($d->v->CreateVertexShader)($d, $vs, strlen($vs), null, $out), 'CreateVertexShader');
        self::$pixelShaders = new \WeakMap();
        [$vs, $ps] = [self::compile(D3dShader::BLUR, 'blur', 'vs', 'vs_5_0'), self::compile(D3dShader::BLUR, 'blur', 'ps', 'ps_5_0')];
        self::$blurVS = self::make(fn($out) => ($d->v->CreateVertexShader)($d, $vs, strlen($vs), null, $out), 'CreateVertexShader');
        self::$blurPS = self::make(fn($out) => ($d->v->CreatePixelShader)($d, $ps, strlen($ps), null, $out), 'CreatePixelShader');
        foreach (['blend' => [self::BLEND_SRC_ALPHA, self::BLEND_SRC_ALPHA], 'canvas' => [self::BLEND_SRC_ALPHA, self::BLEND_ONE], 'hole' => [self::BLEND_ZERO, self::BLEND_ZERO]] as $name => [$src, $srcAlpha]) {
            $b = $f->new('D3D11_BLEND_DESC');
            $t = $b->RenderTarget[0];
            $t->BlendEnable = 1;
            $t->SrcBlend = $src;
            $t->DestBlend = self::BLEND_INV_SRC_ALPHA;
            $t->BlendOp = self::BLEND_OP_ADD;
            $t->SrcBlendAlpha = $srcAlpha;
            $t->DestBlendAlpha = self::BLEND_INV_SRC_ALPHA;
            $t->BlendOpAlpha = self::BLEND_OP_ADD;
            $t->RenderTargetWriteMask = 0xF;
            self::$blends[$name] = self::make(fn($out) => ($d->v->CreateBlendState)($d, FFI::addr($b), $out), 'CreateBlendState');
        }
        $s = $f->new('D3D11_SAMPLER_DESC');
        $s->Filter = 0x15;
        $s->AddressU = $s->AddressV = $s->AddressW = 3;
        $s->MaxAnisotropy = 1;
        $s->ComparisonFunc = 1;
        $s->MaxLOD = 3.402823466e+38;
        self::$sampler = self::make(fn($out) => ($d->v->CreateSamplerState)($d, FFI::addr($s), $out), 'CreateSamplerState');
        $r = $f->new('D3D11_RASTERIZER_DESC');
        $r->FillMode = 3;
        $r->CullMode = 1;
        $r->DepthClipEnable = 1;
        self::$rasterizer = self::make(fn($out) => ($d->v->CreateRasterizerState)($d, FFI::addr($r), $out), 'CreateRasterizerState');
        $cb = $f->new('D3D11_BUFFER_DESC');
        $cb->ByteWidth = FFI::sizeof($f->type('FRAME'));
        $cb->Usage = self::USAGE_DYNAMIC;
        $cb->BindFlags = self::BIND_CONSTANT_BUFFER;
        $cb->CPUAccessFlags = self::CPU_WRITE;
        self::$frameBuffer = self::make(fn($out) => ($d->v->CreateBuffer)($d, FFI::addr($cb), null, $out), 'CreateBuffer (constants)');
        $cb->ByteWidth = FFI::sizeof($f->type('BLUR'));
        self::$blurBuffer = self::make(fn($out) => ($d->v->CreateBuffer)($d, FFI::addr($cb), null, $out), 'CreateBuffer (blur constants)');
        [, self::$noBackdrop] = self::texture(1, 1, self::R8G8B8A8_UNORM, 1, self::BIND_SHADER_RESOURCE, true);
    }

    /** HLSL $src's $entry compiled for $target: its bytecode. A compiler's errors, on one line, are the exception's. */
    public static function compile(string $src, string $name, string $entry, string $target): string
    {
        $c = FFI::cdef('int32_t D3DCompile(const char *src, size_t n, const char *name, const void *defines, void *include, const char *entry, const char *target, uint32_t f1, uint32_t f2, void **code, void **errors);', 'd3dcompiler_47.dll');
        $f = self::ffi();
        $code = $c->new('void *');
        $errors = $c->new('void *');
        $hr = $c->D3DCompile($src, strlen($src), $name, null, null, $entry, $target, 1 << 15 /* optimization level 3 */, 0, FFI::addr($code), FFI::addr($errors));
        $text = static function (CData $p) use ($f): string {
            $b = $f->cast('ID3DBlob *', Mem::addr($p));
            $s = FFI::string(($b->v->GetBufferPointer)($b), ($b->v->GetBufferSize)($b));
            Com::release($b);
            return $s;
        };
        $messages = FFI::isNull($errors) ? '' : $text($errors);
        if ($hr < 0) {
            throw new RuntimeException(sprintf('myphp: HLSL %s %s (%s) failed (HRESULT 0x%08X): %s', $name, $entry, $target, $hr & 0xFFFFFFFF, trim(strtr($messages, ["\r" => '', "\n" => ' | ']), ' |')));
        }
        return $text($code);
    }

    public function setDark(bool $dark): void
    {
        $this->dark = $dark ? 1.0 : 0.0;
    }

    /** The glyph atlas text draws from (R8): its texture, shared, made with its pixels at the first renderer's call; its changed rows go up each frame. */
    public function attachAtlas(Atlas $atlas, float $scale): void
    {
        $this->atlasScale = $scale;
        if (self::$atlas === $atlas) {
            return;
        }
        self::$atlas = $atlas;
        Com::release(self::$atlasView);
        Com::release(self::$atlasTexture);
        [self::$atlasTexture, self::$atlasView] = self::texture($atlas->w, $atlas->h, self::R8_UNORM, 1, self::BIND_SHADER_RESOURCE, false, $atlas->pix, $atlas->w);
        $atlas->dirty = false;
    }

    public function setAtlasScale(float $scale): void
    {
        $this->atlasScale = $scale;
    }

    /** The image atlas: a texture array of ImageAtlas::MAX layers, then CanvasAtlas::PAGES for canvases, each the glyph atlas's size; shared as the glyphs' is. */
    public function attachImages(ImageAtlas $images): void
    {
        if ($images->w !== self::$atlas?->w || $images->h !== self::$atlas->h) {
            throw new RuntimeException('the image atlas must be the glyph atlas\'s size');
        }
        [CanvasAtlas::$w, CanvasAtlas::$h] = [$images->w, $images->h];
        if (self::$images === $images) {
            return;
        }
        self::$images = $images;
        Com::release(self::$imagesView);
        Com::release(self::$imagesTexture);
        [self::$imagesTexture, self::$imagesView] = self::texture($images->w, $images->h, self::R8G8B8A8_UNORM, ImageAtlas::MAX + CanvasAtlas::PAGES, self::BIND_SHADER_RESOURCE, true);
        foreach ($images->pages as $i => $page) {
            self::uploadRows(self::$imagesTexture, $i, $page, 0, $page->h);
            $page->dirty = false;
        }
    }

    /** The list to draw the next frame into, cleared. */
    public function nextList(): DisplayList
    {
        $list = $this->lists[$this->frame % 2];
        $list->reset();
        $list->zoom = 1.0;
        return $list;
    }

    /** The list of the last frame drawn. */
    public function lastList(): DisplayList
    {
        return $this->lists[($this->frame + 1) % 2];
    }

    /**
     * Draws into window $hwnd from now: a flip-model swap chain of two BGRA
     * buffers, which draw() resizes to the frame's size. $composed, for a
     * window without a redirection bitmap (a material behind its content):
     * a swap chain of premultiplied alpha shown through DirectComposition, a
     * visual of it the root of the window's target, its transparent pixels
     * the material's (mygo's compositor.go).
     */
    public function attach(CData $hwnd, int $pw, int $ph, bool $composed = false): void
    {
        $f = self::$f;
        $d = self::$device;
        $dxgi = Com::query($d, self::IID_DXGIDEVICE) ?? throw new RuntimeException('myphp: Direct3D 11: the device is no IDXGIDevice');
        $adapter = $f->new('void *');
        $dx = Com::typed(self::$f, $dxgi, 'IDXGIDevice *');
        $hr = ($dx->v->GetAdapter)($dx, FFI::addr($adapter));
        Com::release($dxgi);
        self::hr($hr, 'IDXGIDevice::GetAdapter');
        $factory = $f->new('void *');
        $iid = Com::guid(self::IID_DXGIFACTORY2);
        $a = Com::typed(self::$f, $adapter, 'IDXGIObject *');
        $hr = ($a->v->GetParent)($a, FFI::addr($iid), FFI::addr($factory));
        Com::release($adapter);
        self::hr($hr, 'IDXGIAdapter::GetParent (IDXGIFactory2)');
        $desc = $f->new('DXGI_SWAP_CHAIN_DESC1');
        $desc->Width = max(1, $pw);
        $desc->Height = max(1, $ph);
        $desc->Format = self::B8G8R8A8_UNORM;
        $desc->SampleDesc->Count = 1;
        $desc->BufferUsage = 0x20;
        $desc->BufferCount = 2;
        // DXGI_SWAP_EFFECT_FLIP_DISCARD
        $desc->SwapEffect = 4;
        $fac = Com::typed(self::$f, $factory, 'IDXGIFactory2 *');
        $chain = $f->new('void *');
        if ($composed) {
            $desc->AlphaMode = 1; // DXGI_ALPHA_MODE_PREMULTIPLIED: what the renderer draws
            $hr = ($fac->v->CreateSwapChainForComposition)($fac, $d, FFI::addr($desc), null, FFI::addr($chain));
            Com::release($factory);
            self::hr($hr, 'IDXGIFactory2::CreateSwapChainForComposition');
            $this->compose($hwnd, $chain);
        } else {
            $hr = ($fac->v->CreateSwapChainForHwnd)($fac, $d, $hwnd, FFI::addr($desc), null, null, FFI::addr($chain));
            Com::release($factory);
            self::hr($hr, 'IDXGIFactory2::CreateSwapChainForHwnd');
        }
        $this->swapChain = Com::typed(self::$f, $chain, 'IDXGISwapChain *');
        $this->size = [max(1, $pw), max(1, $ph)];
        [$this->backTexture, $this->backBuffer] = $this->backBufferView();
    }

    /** DirectComposition's device, the window's target and the visual of the swap chain, held while it draws. @var list<CData> */
    private array $composition = [];

    /** Shows swap chain $chain in window $hwnd through DirectComposition: a device of the renderer's, a target of the window (under its child windows: the web views), a visual of the chain its root. */
    private function compose(CData $hwnd, CData $chain): void
    {
        $c = FFI::cdef(<<<'C'
            typedef struct { void *r0[3]; int32_t (*Commit)(void *self); void *r4[2]; int32_t (*CreateTargetForHwnd)(void *self, void *hwnd, int32_t topmost, void **target); int32_t (*CreateVisual)(void *self, void **visual); } IDCompositionDeviceVtbl;
            typedef struct { IDCompositionDeviceVtbl *v; } IDCompositionDevice;
            typedef struct { void *r0[3]; int32_t (*SetRoot)(void *self, void *visual); } IDCompositionTargetVtbl;
            typedef struct { IDCompositionTargetVtbl *v; } IDCompositionTarget;
            typedef struct { void *r0[15]; int32_t (*SetContent)(void *self, void *content); } IDCompositionVisualVtbl;
            typedef struct { IDCompositionVisualVtbl *v; } IDCompositionVisual;
            int32_t DCompositionCreateDevice(void *dxgi, const void *iid, void **out);
            C, 'dcomp.dll');
        $dxgi = Com::query(self::$device, self::IID_DXGIDEVICE) ?? throw new RuntimeException('myphp: Direct3D 11: the device is no IDXGIDevice');
        $out = $c->new('void *');
        $iid = Com::guid('c37ea93a-e7aa-450d-b16f-9746cb0407f3'); // IDCompositionDevice
        $hr = $c->DCompositionCreateDevice($dxgi, FFI::addr($iid), FFI::addr($out));
        Com::release($dxgi);
        self::hr($hr, 'DCompositionCreateDevice');
        $dev = Com::typed($c, $out, 'IDCompositionDevice *');
        $this->composition[] = Com::typed($c, $out, 'void *');
        $target = $c->new('void *');
        self::hr(($dev->v->CreateTargetForHwnd)($dev, $hwnd, 0, FFI::addr($target)), 'IDCompositionDevice::CreateTargetForHwnd');
        $this->composition[] = Com::typed($c, $target, 'void *');
        $visual = $c->new('void *');
        self::hr(($dev->v->CreateVisual)($dev, FFI::addr($visual)), 'IDCompositionDevice::CreateVisual');
        $this->composition[] = Com::typed($c, $visual, 'void *');
        $v = Com::typed($c, $visual, 'IDCompositionVisual *');
        self::hr(($v->v->SetContent)($v, $chain), 'IDCompositionVisual::SetContent');
        $t = Com::typed($c, $target, 'IDCompositionTarget *');
        self::hr(($t->v->SetRoot)($t, $visual), 'IDCompositionTarget::SetRoot');
        self::hr(($dev->v->Commit)($dev), 'IDCompositionDevice::Commit');
    }

    /** The swap chain's back buffer and its view, to draw into. @return array{0: CData, 1: CData} */
    private function backBufferView(): array
    {
        $f = self::$f;
        $s = $this->swapChain;
        $tex = $f->new('void *');
        $iid = Com::guid(self::IID_TEXTURE2D);
        self::hr(($s->v->GetBuffer)($s, 0, FFI::addr($iid), FFI::addr($tex)), 'IDXGISwapChain::GetBuffer');
        $d = self::$device;
        $view = self::make(fn($out) => ($d->v->CreateRenderTargetView)($d, $tex, null, $out), 'CreateRenderTargetView');
        return [Com::typed(self::$f, $tex, 'void *'), $view];
    }

    /**
     * Draws $list into the window's back buffer, of $pw × $ph pixels (the
     * swap chain resized to them first), $scale pixels a point (the
     * content's zoom times the window's scale), cleared to $clear
     * (straight RGBA, 0 to 1) first; present() shows it.
     * @param array{0: float, 1: float, 2: float, 3: float} $clear
     */
    public function draw(DisplayList $list, int $pw, int $ph, float $scale, array $clear): void
    {
        if ($this->swapChain === null) {
            throw new RuntimeException('myphp: D3d::draw draws into a window: attach() one first');
        }
        [$pw, $ph] = [max(1, $pw), max(1, $ph)];
        if ([$pw, $ph] !== $this->size) {
            // the context lets go of the back buffer's view too: ResizeBuffers fails while anything holds the buffers
            $c = self::$context;
            ($c->v->OMSetRenderTargets)($c, 0, null, null);
            Com::release($this->backBuffer);
            Com::release($this->backTexture);
            $s = $this->swapChain;
            self::hr(($s->v->ResizeBuffers)($s, 0, $pw, $ph, 0, 0), 'IDXGISwapChain::ResizeBuffers');
            $this->size = [$pw, $ph];
            [$this->backTexture, $this->backBuffer] = $this->backBufferView();
        }
        $this->drawList($list, $this->backTexture, $this->backBuffer, $pw, $ph, $scale, $clear);
        // a new frame's list is the last from now; the last drawn again (WM_PAINT's) stays so
        if ($list === $this->lists[$this->frame % 2]) {
            $this->frame++;
        }
    }

    /** Shows the frame drawn, at the display's next refresh. */
    public function present(): void
    {
        $s = $this->swapChain;
        if ($s !== null) {
            $hr = ($s->v->Present)($s, 1, 0);
            if ($hr < 0) {
                Logger::error(sprintf('IDXGISwapChain::Present failed (HRESULT 0x%08X)', $hr & 0xFFFFFFFF), 'd3d11');
            }
        }
    }

    /**
     * $list drawn off screen at $pw × $ph pixels: BGRA, premultiplied,
     * rows top down, as Gl::readBack and Metal::readBack give them.
     * @param array{0: float, 1: float, 2: float, 3: float} $clear
     */
    public function readBack(DisplayList $list, int $pw, int $ph, float $scale, array $clear): string
    {
        $f = self::$f;
        $d = self::$device;
        $c = self::$context;
        [$tex, $view] = self::texture($pw, $ph, self::B8G8R8A8_UNORM, 1, self::BIND_RENDER_TARGET, false, null, 0, true);
        $this->drawList($list, $tex, $view, $pw, $ph, $scale, $clear);
        $desc = $f->new('D3D11_TEXTURE2D_DESC');
        $desc->Width = $pw;
        $desc->Height = $ph;
        $desc->MipLevels = 1;
        $desc->ArraySize = 1;
        $desc->Format = self::B8G8R8A8_UNORM;
        $desc->SampleDesc->Count = 1;
        $desc->Usage = self::USAGE_STAGING;
        $desc->CPUAccessFlags = self::CPU_READ;
        $staging = self::make(fn($out) => ($d->v->CreateTexture2D)($d, FFI::addr($desc), null, $out), 'CreateTexture2D (staging)');
        ($c->v->CopyResource)($c, $staging, $tex);
        $m = $f->new('D3D11_MAPPED_SUBRESOURCE');
        self::hr(($c->v->Map)($c, $staging, 0, self::MAP_READ, 0, FFI::addr($m)), 'Map (read back)');
        $at = Mem::addr($m->pData);
        $out = '';
        for ($y = 0; $y < $ph; $y++) {
            $out .= FFI::string($f->cast('char *', $at + $y * $m->RowPitch), $pw * 4);
        }
        ($c->v->Unmap)($c, $staging, 0);
        foreach ([$staging, $view, $tex] as $o) {
            Com::release($o);
        }
        return $out;
    }

    /** Draws $list into $texture through its render target's $view ($pw × $ph): one pass, split at each glass quad, which reads what is below it blurred. */
    private function drawList(DisplayList $list, CData $texture, CData $view, int $pw, int $ph, float $scale, array $clear): void
    {
        $f = self::$f;
        $c = self::$context;
        $list->showWide(false);
        $this->uploadAtlases();
        foreach (CanvasAtlas::take() as $canvas) {
            $this->renderCanvas($canvas);
        }
        $this->prepareShaders($list);
        $this->target = [$pw / $scale, $ph / $scale, $scale, (float)$ph];
        self::renderTo($view, $pw, $ph);
        $rgba = $f->new('float[4]');
        [$rgba[0], $rgba[1], $rgba[2], $rgba[3]] = [$clear[0] * $clear[3], $clear[1] * $clear[3], $clear[2] * $clear[3], $clear[3]];
        ($c->v->ClearRenderTargetView)($c, $view, $rgba);
        if ($list->n === 0) {
            return;
        }
        $list->cull();
        $this->upload($this->quads, $list->buf, $list->n * DisplayList::FLOATS_PER_RECT * 4);
        $this->upload($this->xforms, $list->xforms, $list->transforms * DisplayList::FLOATS_PER_TRANSFORM * 4);
        $this->bind();
        $start = 0;
        foreach ($list->glass as [$at, $sigma]) {
            $this->drawRuns($list, $start, $at - $start);
            $this->blur($texture, $pw, $ph, $sigma);
            self::renderTo($view, $pw, $ph);
            $this->bind();
            $start = $at;
        }
        $this->drawRuns($list, $start, $list->n - $start);
    }

    /** Draws into render target $view from now, all of its $w × $h. */
    private static function renderTo(CData $view, int $w, int $h): void
    {
        $f = self::$f;
        $c = self::$context;
        $views = $f->new('void *[1]');
        $views[0] = $view;
        ($c->v->OMSetRenderTargets)($c, 1, $views, null);
        $vp = $f->new('D3D11_VIEWPORT');
        $vp->Width = $w;
        $vp->Height = $h;
        $vp->MaxDepth = 1.0;
        ($c->v->RSSetViewports)($c, 1, FFI::addr($vp));
    }

    /**
     * Copies what $texture ($pw × $ph) holds so far and blurs it, at half
     * size, into the backdrop the glass reads: a Gaussian across, then down
     * (Gl::blur's), its sigma halved with the size.
     */
    private function blur(CData $texture, int $pw, int $ph, float $sigma): void
    {
        $c = self::$context;
        if ($this->backdropSize !== [$pw, $ph]) {
            $this->releaseGlass();
            [$hw, $hh] = [max(1, intdiv($pw, 2)), max(1, intdiv($ph, 2))];
            [$snapshot, $snapshotView] = self::texture($pw, $ph, self::B8G8R8A8_UNORM, 1, self::BIND_SHADER_RESOURCE, false);
            $this->glass = ['snapshot' => [$snapshot, null, $snapshotView], 'temp' => self::targetTexture($hw, $hh), 'backdrop' => self::targetTexture($hw, $hh)];
            $this->backdropSize = [$pw, $ph];
        }
        // none of these read as the blur writes them: the shaders let go of the backdrop, the output of the target, first
        $nulls = self::$f->new('void *[5]');
        ($c->v->PSSetShaderResources)($c, 0, 5, $nulls);
        ($c->v->OMSetRenderTargets)($c, 0, null, null);
        ($c->v->CopyResource)($c, $this->glass['snapshot'][0], $texture);
        $s = max(0.5, $sigma / 2);
        [$hw, $hh] = [max(1, intdiv($pw, 2)), max(1, intdiv($ph, 2))];
        $this->blurPass($this->glass['snapshot'][2], $this->glass['temp'][1], $hw, $hh, 1.0, 0.0, $s);
        $this->blurPass($this->glass['temp'][2], $this->glass['backdrop'][1], $hw, $hh, 0.0, 1.0, $s);
    }

    /** Draws $src blurred along ($dx, $dy) into $dst ($w × $h): BLUR's fullscreen triangle, unblended. */
    private function blurPass(CData $src, CData $dst, int $w, int $h, float $dx, float $dy, float $sigma): void
    {
        $f = self::$f;
        $c = self::$context;
        $nulls = $f->new('void *[1]');
        ($c->v->PSSetShaderResources)($c, 0, 1, $nulls);
        self::renderTo($dst, $w, $h);
        $b = $f->new('BLUR');
        [$b->dir[0], $b->dir[1], $b->sigma] = [$dx, $dy, $sigma];
        $m = $f->new('D3D11_MAPPED_SUBRESOURCE');
        self::hr(($c->v->Map)($c, self::$blurBuffer, 0, self::MAP_WRITE_DISCARD, 0, FFI::addr($m)), 'Map (blur constants)');
        FFI::memcpy($m->pData, $b, FFI::sizeof($b));
        ($c->v->Unmap)($c, self::$blurBuffer, 0);
        ($c->v->IASetPrimitiveTopology)($c, self::TRIANGLELIST);
        ($c->v->VSSetShader)($c, self::$blurVS, null, 0);
        ($c->v->PSSetShader)($c, self::$blurPS, null, 0);
        ($c->v->RSSetState)($c, self::$rasterizer);
        ($c->v->OMSetBlendState)($c, null, null, 0xFFFFFFFF);
        $one = $f->new('void *[1]');
        $one[0] = $src;
        ($c->v->PSSetShaderResources)($c, 0, 1, $one);
        $one[0] = self::$sampler;
        ($c->v->PSSetSamplers)($c, 0, 1, $one);
        $one[0] = self::$blurBuffer;
        ($c->v->PSSetConstantBuffers)($c, 0, 1, $one);
        ($c->v->Draw)($c, 3, 0);
    }

    /**
     * Renders canvas $c from the list it was last drawn with into a texture
     * of its size, transparent first, its alpha kept premultiplied as the
     * image atlas holds it, blurred if it asks, then copies that to its
     * place among the canvases' layers of the image texture: a draw may not
     * read the texture it draws into, and the canvases inside it are in
     * that texture (Gl::renderCanvas). Glass and blurs draw nothing in a
     * canvas. Its list goes into the quads' buffers, which the frame's
     * list takes after.
     */
    private function renderCanvas(Canvas $c): void
    {
        if ($c->loose()) {
            $this->warnOnce("canvases larger than a page");
            return;
        }
        $f = self::$f;
        $ctx = self::$context;
        $key = "{$c->w}x{$c->h}";
        if (!isset($this->canvasTargets[$key]) && count($this->canvasTargets) > 16) {
            $this->releaseCanvasTargets();
        }
        $target = $this->canvasTargets[$key] ??= self::targetTexture($c->w, $c->h, self::R8G8B8A8_UNORM);
        if ($c->scene !== null) {
            \MyPHP\Plugins\Scene\Native\D3dRenderPass::instance()->render($c->scene, $c->w, $c->h, $target[1]);
        } else {
            self::renderTo($target[1], $c->w, $c->h);
            $clear = $f->new('float[4]');
            ($ctx->v->ClearRenderTargetView)($ctx, $target[1], $clear);
        }
        $list = $c->list;
        if ($list !== null && $list->n > 0) {
            self::renderTo($target[1], $c->w, $c->h);
            $this->target = [(float)$c->w, (float)$c->h, 1.0, (float)$c->h];
            $this->upload($this->quads, $list->buf, $list->n * DisplayList::FLOATS_PER_RECT * 4);
            $this->upload($this->xforms, $list->xforms, $list->transforms * DisplayList::FLOATS_PER_TRANSFORM * 4);
            $this->bind();
            $this->drawRuns($list, 0, $list->n, true);
        }
        if ($c->blur > 0) {
            // along rows into a texture of its size, then along columns back
            $tmp = $this->canvasBlurTemps[$key] ??= self::targetTexture($c->w, $c->h, self::R8G8B8A8_UNORM);
            $this->blurPass($target[2], $tmp[1], $c->w, $c->h, 1.0, 0.0, $c->blur);
            $this->blurPass($tmp[2], $target[1], $c->w, $c->h, 0.0, 1.0, $c->blur);
        }
        if ($c->slot === null || self::$imagesTexture === null) {
            return;
        }
        [$u, $v, $page] = $c->slot;
        // the image texture the shaders read takes it: nothing reads it, nor writes the canvas's, as it is copied
        $nulls = $f->new('void *[5]');
        ($ctx->v->VSSetShaderResources)($ctx, 0, 5, $nulls);
        ($ctx->v->PSSetShaderResources)($ctx, 0, 5, $nulls);
        ($ctx->v->OMSetRenderTargets)($ctx, 0, null, null);
        ($ctx->v->CopySubresourceRegion)($ctx, self::$imagesTexture, $page, $u, $v, 0, $target[0], 0, null);
    }

    private function releaseCanvasTargets(): void
    {
        foreach ([...$this->canvasTargets, ...$this->canvasBlurTemps] as $views) {
            foreach (array_reverse($views) as $o) {
                Com::release($o);
            }
        }
        $this->canvasTargets = $this->canvasBlurTemps = [];
    }

    /**
     * The textures each shader draw of $list reads, made before its passes
     * (Gl::prepareShaders): an image's, uploaded when it is new or
     * updated; a canvas's bitmap's, copied from its place among the
     * canvases' layers into a texture of its own, which a shader reads as
     * an image of its own size; a transparent pixel for none.
     */
    private function prepareShaders(DisplayList $list): void
    {
        $this->shaderTextures = [];
        $copied = [];
        foreach ($list->shaders as $d => [, , , $images]) {
            $ts = [];
            foreach ($images as $img) {
                $ts[] = $img === null ? self::$noBackdrop : ($img->canvas !== null ? $copied[spl_object_id($img)] ??= $this->canvasTexture($img->canvas) : $this->imageTexture($img));
            }
            $this->shaderTextures[$d] = $ts;
        }
    }

    /** The view of a texture of $img's pixels, premultiplied as it holds them, sent again when it changed. */
    private function imageTexture(Image $img): CData
    {
        $this->imageTextures ??= new \WeakMap();
        [$tex, $view, $version] = $this->imageTextures[$img] ?? [null, null, -1];
        if ($view !== null && $version === $img->version) {
            return $view;
        }
        if ($tex !== null) {
            Com::release($view);
            Com::release($tex);
        }
        [$tex, $view] = self::texture($img->w, $img->h, self::R8G8B8A8_UNORM, 1, self::BIND_SHADER_RESOURCE, false, self::$f->cast('void *', $img->address()), $img->w * 4);
        $img->uploaded();
        $this->imageTextures[$img] = [$tex, $view, $img->version];
        return $view;
    }

    /** The view of a texture of $c's size with its pixels, copied from its place among the canvases' layers; transparent before it was drawn. */
    private function canvasTexture(Canvas $c): CData
    {
        $this->canvasTextures ??= new \WeakMap();
        $this->canvasTextures[$c] ??= self::texture($c->w, $c->h, self::R8G8B8A8_UNORM, 1, self::BIND_SHADER_RESOURCE, true);
        [$tex, $view] = $this->canvasTextures[$c];
        if ($c->slot !== null && self::$imagesTexture !== null) {
            [$u, $v, $page] = $c->slot;
            $f = self::$f;
            $box = $f->new('D3D11_BOX');
            [$box->left, $box->top, $box->front, $box->right, $box->bottom, $box->back] = [$u, $v, 0, $u + $c->w, $v + $c->h, 1];
            $ctx = self::$context;
            ($ctx->v->CopySubresourceRegion)($ctx, $tex, 0, 0, 0, 0, self::$imagesTexture, $page, FFI::addr($box));
        }
        return $view;
    }

    /**
     * Draws the run of $n quads from $at shader draw $d of $list draws
     * (Gl::drawShader): its pixel stage, its uniforms' bytes in the
     * cbuffer, its images; the backdrop of one reading it, what the frame
     * drew before it, blurred at half size when its glass entry asks a
     * blur, or as it is; transparent in a canvas.
     */
    private function drawShader(DisplayList $list, int $d, int $at, int $n, bool $premultiplied): void
    {
        $f = self::$f;
        $c = self::$context;
        [$code, $bytes] = $list->shaders[$d];
        $textures = $this->shaderTextures[$d] ?? [];
        if ($code->backdrop) {
            $blur = 0.0;
            foreach ($list->glass as [$gAt, $sigma]) {
                if ($gAt === $at) { $blur = $sigma; }
            }
            if ($premultiplied || $this->glass === []) {
                $textures[2] = self::$noBackdrop;
            } else {
                $textures[2] = $blur > 0 ? $this->glass['backdrop'][2] : $this->glass['snapshot'][2];
                [$w, $h] = $this->backdropSize;
                if ($blur > 0) { [$w, $h] = [max(1, intdiv($w, 2)), max(1, intdiv($h, 2))]; }
                $put = function (int $word, float $v) use (&$bytes, $code): void {
                    $bytes = substr_replace($bytes, pack('g', $v), $code->lanes[$word], 4);
                };
                $put(Shader::BACKDROP_SIZE_WORD, (float)$w);
                $put(Shader::BACKDROP_SIZE_WORD + 1, (float)$h);
                $put(Shader::AREA_WORD, 0.0);
                $put(Shader::AREA_WORD + 1, 0.0);
                $put(Shader::AREA_WORD + 2, $blur > 0 ? 2.0 : 1.0);
            }
        }
        ($c->v->VSSetShader)($c, self::$shaderVS, null, 0);
        ($c->v->PSSetShader)($c, self::pixelShader($code), null, 0);
        if ($code->size > 0) {
            if (strlen($bytes) > self::$uniformSize) {
                Com::release(self::$uniformBuffer);
                $desc = $f->new('D3D11_BUFFER_DESC');
                $desc->ByteWidth = intdiv(strlen($bytes) + 255, 256) * 256;
                $desc->Usage = self::USAGE_DYNAMIC;
                $desc->BindFlags = self::BIND_CONSTANT_BUFFER;
                $desc->CPUAccessFlags = self::CPU_WRITE;
                $d3 = self::$device;
                self::$uniformBuffer = self::make(fn($out) => ($d3->v->CreateBuffer)($d3, FFI::addr($desc), null, $out), 'CreateBuffer (uniforms)');
                self::$uniformSize = $desc->ByteWidth;
            }
            $m = $f->new('D3D11_MAPPED_SUBRESOURCE');
            self::hr(($c->v->Map)($c, self::$uniformBuffer, 0, self::MAP_WRITE_DISCARD, 0, FFI::addr($m)), 'Map (uniforms)');
            FFI::memcpy($m->pData, $bytes, strlen($bytes));
            ($c->v->Unmap)($c, self::$uniformBuffer, 0);
            $one = $f->new('void *[1]');
            $one[0] = self::$uniformBuffer;
            ($c->v->PSSetConstantBuffers)($c, 2, 1, $one);
        }
        $views = $f->new('void *[4]');
        for ($i = 0; $i < 4; $i++) {
            $views[$i] = $textures[$i] ?? self::$noBackdrop;
        }
        ($c->v->PSSetShaderResources)($c, 6, 4, $views);
        $this->drawQuads($at, $n, $premultiplied ? 'canvas' : 'blend');
        ($c->v->VSSetShader)($c, self::$quadVS, null, 0);
        ($c->v->PSSetShader)($c, self::$quadPS, null, 0);
    }

    /** $code's pixel stage: D3dShader's SHADER tail around its HLSL library, compiled the first time it draws. */
    private static function pixelShader(Code $code): CData
    {
        if (!isset(self::$pixelShaders[$code])) {
            $ps = self::compile(D3dShader::COMMON . "\n" . D3dShader::SHADER_VERTEX . "\n" . $code->source . "\n" . D3dShader::SHADER_PIXEL, 'shader', 'sps', 'ps_5_0');
            $d = self::$device;
            self::$pixelShaders[$code] = self::make(fn($out) => ($d->v->CreatePixelShader)($d, $ps, strlen($ps), null, $out), 'CreatePixelShader (shader)');
        }
        return self::$pixelShaders[$code];
    }

    /** A texture of $w × $h pixels of $format (BGRA's, the targets') drawn into and read: it, its render target's view, its shaders' view. @return array{0: CData, 1: CData, 2: CData} */
    private static function targetTexture(int $w, int $h, int $format = self::B8G8R8A8_UNORM): array
    {
        $d = self::$device;
        [$tex, $target] = self::texture($w, $h, $format, 1, self::BIND_RENDER_TARGET | self::BIND_SHADER_RESOURCE, false, null, 0, true);
        $view = self::make(fn($out) => ($d->v->CreateShaderResourceView)($d, $tex, null, $out), 'CreateShaderResourceView');
        return [$tex, $target, $view];
    }

    private function releaseGlass(): void
    {
        foreach ($this->glass as $views) {
            foreach (array_reverse($views) as $o) {
                Com::release($o);
            }
        }
        $this->glass = [];
        $this->backdropSize = [0, 0];
    }

    /** Draws quads [$from, $from + $count): blended, but the web views' holes, which take away what is there, and the runs shaders draw, with theirs. */
    private function drawRuns(DisplayList $list, int $from, int $count, bool $premultiplied = false): void
    {
        $runs = [];
        foreach ($list->holes as [$h, $slots]) {
            if ($h >= $from && $h < $from + $count) { $runs[] = [$h, $slots, -1]; }
        }
        foreach ($list->shaderRuns as $r) {
            if ($r[0] >= $from && $r[0] < $from + $count) { $runs[] = $r; }
        }
        usort($runs, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $at = $from;
        foreach ($runs as [$r, $n, $d]) {
            $this->drawQuads($at, $r - $at, $premultiplied ? 'canvas' : 'blend');
            if ($d < 0) {
                $this->drawQuads($r, $n, 'hole');
            } else {
                $this->drawShader($list, $d, $r, $n, $premultiplied);
            }
            $at = $r + $n;
        }
        $this->drawQuads($at, $from + $count - $at, $premultiplied ? 'canvas' : 'blend');
    }

    /** The quad shaders, their buffers and textures, the sampler and the rasterizer's state, set for the draws to come. */
    private function bind(): void
    {
        $f = self::$f;
        $c = self::$context;
        ($c->v->IASetPrimitiveTopology)($c, self::TRIANGLESTRIP);
        ($c->v->VSSetShader)($c, self::$quadVS, null, 0);
        ($c->v->PSSetShader)($c, self::$quadPS, null, 0);
        ($c->v->RSSetState)($c, self::$rasterizer);
        $views = $f->new('void *[5]');
        foreach ([$this->quadsView, $this->xformsView, self::$atlasView, self::$imagesView, $this->glass['backdrop'][2] ?? self::$noBackdrop] as $i => $v) {
            $views[$i] = $v;
        }
        ($c->v->VSSetShaderResources)($c, 0, 5, $views);
        ($c->v->PSSetShaderResources)($c, 0, 5, $views);
        $samplers = $f->new('void *[1]');
        $samplers[0] = self::$sampler;
        ($c->v->PSSetSamplers)($c, 0, 1, $samplers);
        $buffers = $f->new('void *[1]');
        $buffers[0] = self::$frameBuffer;
        ($c->v->VSSetConstantBuffers)($c, 0, 1, $buffers);
        ($c->v->PSSetConstantBuffers)($c, 0, 1, $buffers);
    }

    /** Draws $n quads from $at, blended as $blend says: the frame's constants with their first quad (SV_InstanceID counts from 0 whatever the draw's first instance), four vertices each. */
    private function drawQuads(int $at, int $n, string $blend): void
    {
        if ($n <= 0) {
            return;
        }
        $f = self::$f;
        $c = self::$context;
        [$vw, $vh, $scale, $th] = $this->target;
        $frame = $f->new('FRAME');
        $frame->viewport[0] = $vw;
        $frame->viewport[1] = $vh;
        $frame->atlas[0] = (float)(self::$atlas?->w ?? 1);
        $frame->atlas[1] = (float)(self::$atlas?->h ?? 1);
        $frame->scale = $scale;
        $frame->atlasScale = $this->atlasScale;
        $frame->dark = $this->dark;
        $frame->flip = 1.0;
        $frame->targetH = $th;
        $frame->from = $at;
        $m = $f->new('D3D11_MAPPED_SUBRESOURCE');
        self::hr(($c->v->Map)($c, self::$frameBuffer, 0, self::MAP_WRITE_DISCARD, 0, FFI::addr($m)), 'Map (constants)');
        FFI::memcpy($m->pData, $frame, FFI::sizeof($frame));
        ($c->v->Unmap)($c, self::$frameBuffer, 0);
        ($c->v->OMSetBlendState)($c, self::$blends[$blend], null, 0xFFFFFFFF);
        ($c->v->DrawInstanced)($c, 4, $n, 0, 0);
    }

    /** $bytes of $from into dynamic buffer $buffer, all it holds now. */
    private function upload(CData $buffer, CData $from, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }
        $f = self::$f;
        $c = self::$context;
        $m = $f->new('D3D11_MAPPED_SUBRESOURCE');
        self::hr(($c->v->Map)($c, $buffer, 0, self::MAP_WRITE_DISCARD, 0, FFI::addr($m)), 'Map (quads)');
        FFI::memcpy($m->pData, $from, $bytes);
        ($c->v->Unmap)($c, $buffer, 0);
    }

    /** The atlases' rows that changed since they went up: the textures are shared, so the first renderer to draw sends them. */
    private function uploadAtlases(): void
    {
        $a = self::$atlas;
        if ($a !== null && $a->dirty) {
            self::uploadRows(self::$atlasTexture, 0, $a, $a->dirtyFrom, $a->dirtyTo);
            $a->dirty = false;
        }
        foreach (self::$images?->pages ?? [] as $i => $page) {
            if ($page->dirty) {
                self::uploadRows(self::$imagesTexture, $i, $page, $page->dirtyFrom, $page->dirtyTo);
                $page->dirty = false;
            }
        }
    }

    /** Rows [$from, $to) of atlas $a into layer $layer of $texture. */
    private static function uploadRows(CData $texture, int $layer, Atlas $a, int $from, int $to): void
    {
        if ($to <= $from) {
            return;
        }
        $f = self::$f;
        $box = $f->new('D3D11_BOX');
        $box->top = $from;
        $box->bottom = $to;
        $box->right = $a->w;
        $box->back = 1;
        $pitch = $a->w * $a->bpp;
        $c = self::$context;
        ($c->v->UpdateSubresource)($c, $texture, $layer, FFI::addr($box), $f->cast('void *', Mem::addr($a->pix) + $from * $pitch), $pitch, 0);
    }

    /** A dynamic buffer of $floats floats the shaders read as float4s, and its view. @return array{0: CData, 1: CData} */
    private function floatBuffer(int $floats): array
    {
        $f = self::$f;
        $d = self::$device;
        $desc = $f->new('D3D11_BUFFER_DESC');
        $desc->ByteWidth = $floats * 4;
        $desc->Usage = self::USAGE_DYNAMIC;
        $desc->BindFlags = self::BIND_SHADER_RESOURCE;
        $desc->CPUAccessFlags = self::CPU_WRITE;
        $buffer = self::make(fn($out) => ($d->v->CreateBuffer)($d, FFI::addr($desc), null, $out), 'CreateBuffer');
        $v = $f->new('D3D11_SHADER_RESOURCE_VIEW_DESC');
        $v->Format = self::R32G32B32A32_FLOAT;
        $v->ViewDimension = self::SRV_BUFFER;
        $v->u[1] = intdiv($floats, 4);
        $view = self::make(fn($out) => ($d->v->CreateShaderResourceView)($d, $buffer, FFI::addr($v), $out), 'CreateShaderResourceView (buffer)');
        return [$buffer, $view];
    }

    /**
     * A texture of $w × $h pixels of $format, $layers of them (an array
     * where more than one), bound as $bind says, with $pixels ($pitch bytes
     * a row) or zeros ($zero), and its view: a render target's ($target) or
     * the shaders'. @return array{0: CData, 1: CData}
     */
    private static function texture(int $w, int $h, int $format, int $layers, int $bind, bool $zero, ?CData $pixels = null, int $pitch = 0, bool $target = false): array
    {
        $f = self::$f;
        $d = self::$device;
        $desc = $f->new('D3D11_TEXTURE2D_DESC');
        $desc->Width = $w;
        $desc->Height = $h;
        $desc->MipLevels = 1;
        $desc->ArraySize = $layers;
        $desc->Format = $format;
        $desc->SampleDesc->Count = 1;
        $desc->Usage = self::USAGE_DEFAULT;
        $desc->BindFlags = $bind;
        $init = null;
        $keep = null;
        if ($pixels !== null || $zero) {
            $bpp = $format === self::R8_UNORM ? 1 : 4;
            if ($pixels === null) {
                $keep = $f->new('uint8_t[' . ($w * $h * $bpp * $layers) . ']');
                $pixels = $f->cast('void *', Mem::addr(FFI::addr($keep[0])));
                $pitch = $w * $bpp;
            }
            $init = $f->new("D3D11_SUBRESOURCE_DATA[$layers]");
            for ($i = 0; $i < $layers; $i++) {
                $init[$i]->pSysMem = $f->cast('void *', Mem::addr($pixels) + ($keep !== null ? $i * $w * $h * $bpp : 0));
                $init[$i]->SysMemPitch = $pitch;
                $init[$i]->SysMemSlicePitch = $pitch * $h;
            }
        }
        $tex = self::make(fn($out) => ($d->v->CreateTexture2D)($d, FFI::addr($desc), $init, $out), 'CreateTexture2D');
        $view = $target
            ? self::make(fn($out) => ($d->v->CreateRenderTargetView)($d, $tex, null, $out), 'CreateRenderTargetView')
            : self::make(fn($out) => ($d->v->CreateShaderResourceView)($d, $tex, null, $out), 'CreateShaderResourceView');
        return [$tex, $view];
    }

    private function warnOnce(string $what): void
    {
        if (!isset($this->warned[$what])) {
            $this->warned[$what] = true;
            Logger::warn("Direct3D 11: $what are not drawn yet", 'd3d11');
        }
    }

    /** Lets go of the renderer: its buffers, lists and swap chain; the device and the atlases' textures stay, for the others. */
    public function release(): void
    {
        $this->releaseGlass();
        $this->releaseCanvasTargets();
        foreach ([$this->imageTextures, $this->canvasTextures] as $map) {
            foreach ($map ?? [] as $views) {
                Com::release($views[1]);
                Com::release($views[0]);
            }
        }
        $this->imageTextures = $this->canvasTextures = null;
        foreach ([$this->backBuffer, $this->backTexture, $this->swapChain, $this->quadsView, $this->quads, $this->xformsView, $this->xforms] as $o) {
            Com::release($o);
        }
        $this->backBuffer = $this->backTexture = $this->swapChain = null;
        foreach (array_reverse($this->composition) as $o) {
            Com::release($o);
        }
        $this->composition = [];
        foreach ($this->lists as $l) {
            FFI::free($l->buf);
            FFI::free($l->xforms);
        }
        $this->lists = [];
    }

    /** A COM object an out parameter of $call gives, held (its pointer made of its address); a failure is $what's exception. */
    private static function make(\Closure $call, string $what): CData
    {
        $out = self::$f->new('void *');
        self::hr($call(FFI::addr($out)), $what);
        return Com::typed(self::$f, $out, 'void *');
    }

    /** Com::check, its failure Direct3D 11's. */
    private static function hr(int $hr, string $what): void
    {
        Com::check($hr, "Direct3D 11: $what");
    }
}
