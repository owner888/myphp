<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Logger;
use MyPHP\UI\{Atlas, CanvasAtlas, DisplayList, ImageAtlas};
use RuntimeException;

/**
 * The Direct3D 11 renderer (mygo's gpu/d3d11), as Gl.php is the OpenGL
 * one: a frame's DisplayList drawn with instanced draws of the quads
 * (D3dShader's HLSL, GlShader's line for line), into a window's flip-model
 * swap chain (attach, draw, present) or off screen (readBack).
 *
 * One device serves every window, as Metal's does: the shaders, states and
 * the atlases' textures are made once and shared; a renderer has its
 * quads' and transforms' buffers, its lists and its swap chain. Where the
 * machine has no GPU Direct3D's own, WARP, draws (CI's runners).
 *
 * Not yet here: the glass's blurred backdrop (all of a list is drawn in
 * one pass, the backdrop transparent), canvases and custom shaders.
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
    private const TRIANGLESTRIP = 5;
    private const IID_TEXTURE2D = '6f15aaf2-d208-4e89-9ab4-489535d34f9c';
    private const IID_DXGIDEVICE = '54ec77fa-1377-44e6-8c32-88fd5f44c84c';
    private const IID_DXGIFACTORY2 = '50c83a1c-e072-4c48-87b0-3630fa36a6d0';

    private static ?FFI $f = null;
    /** The device and its immediate context, shared by the renderers; the shaders and states they draw with. */
    private static ?CData $device = null, $context = null;
    private static CData $quadVS, $quadPS, $blurVS, $blurPS, $sampler, $rasterizer, $frameBuffer, $noBackdrop;
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
    private ?CData $swapChain = null, $backBuffer = null;
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
            typedef struct { int32_t BlendEnable; uint32_t SrcBlend, DestBlend, BlendOp, SrcBlendAlpha, DestBlendAlpha, BlendOpAlpha; uint8_t RenderTargetWriteMask; } D3D11_RENDER_TARGET_BLEND_DESC;
            typedef struct { int32_t AlphaToCoverageEnable, IndependentBlendEnable; D3D11_RENDER_TARGET_BLEND_DESC RenderTarget[8]; } D3D11_BLEND_DESC;
            typedef struct { uint32_t Filter, AddressU, AddressV, AddressW; float MipLODBias; uint32_t MaxAnisotropy, ComparisonFunc; float BorderColor[4]; float MinLOD, MaxLOD; } D3D11_SAMPLER_DESC;
            typedef struct { uint32_t FillMode, CullMode; int32_t FrontCounterClockwise, DepthBias; float DepthBiasClamp, SlopeScaledDepthBias; int32_t DepthClipEnable, ScissorEnable, MultisampleEnable, AntialiasedLineEnable; } D3D11_RASTERIZER_DESC;
            typedef struct { float TopLeftX, TopLeftY, Width, Height, MinDepth, MaxDepth; } D3D11_VIEWPORT;
            typedef struct { uint32_t left, top, front, right, bottom, back; } D3D11_BOX;
            typedef struct { uint32_t Width, Height, Format; int32_t Stereo; DXGI_SAMPLE_DESC SampleDesc; uint32_t BufferUsage, BufferCount, Scaling, SwapEffect, AlphaMode, Flags; } DXGI_SWAP_CHAIN_DESC1;
            typedef struct { float viewport[2], atlas[2]; float scale, atlasScale, dark, flip, targetH; int32_t from; float pad[2]; } FRAME;

            typedef struct { void *r0[3];
                int32_t (*CreateBuffer)(void *self, const D3D11_BUFFER_DESC *desc, const D3D11_SUBRESOURCE_DATA *init, void **out);
                void *r4;
                int32_t (*CreateTexture2D)(void *self, const D3D11_TEXTURE2D_DESC *desc, const D3D11_SUBRESOURCE_DATA *init, void **out);
                void *r6;
                int32_t (*CreateShaderResourceView)(void *self, void *resource, const D3D11_SHADER_RESOURCE_VIEW_DESC *desc, void **out);
                void *r8;
                int32_t (*CreateRenderTargetView)(void *self, void *resource, const void *desc, void **out);
                void *r10[2];
                int32_t (*CreateVertexShader)(void *self, const void *code, size_t n, void *linkage, void **out);
                void *r13[2];
                int32_t (*CreatePixelShader)(void *self, const void *code, size_t n, void *linkage, void **out);
                void *r16[4];
                int32_t (*CreateBlendState)(void *self, const D3D11_BLEND_DESC *desc, void **out);
                void *r21;
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
                void *r12;
                void (*Draw)(void *self, uint32_t vertices, uint32_t start);
                int32_t (*Map)(void *self, void *resource, uint32_t sub, uint32_t type, uint32_t flags, D3D11_MAPPED_SUBRESOURCE *out);
                void (*Unmap)(void *self, void *resource, uint32_t sub);
                void (*PSSetConstantBuffers)(void *self, uint32_t start, uint32_t n, void **buffers);
                void *r17[4];
                void (*DrawInstanced)(void *self, uint32_t vertices, uint32_t instances, uint32_t startVertex, uint32_t startInstance);
                void *r22[2];
                void (*IASetPrimitiveTopology)(void *self, uint32_t topology);
                void (*VSSetShaderResources)(void *self, uint32_t start, uint32_t n, void **views);
                void *r26[7];
                void (*OMSetRenderTargets)(void *self, uint32_t n, void **views, void *depth);
                void *r34;
                void (*OMSetBlendState)(void *self, void *state, const float *factor, uint32_t mask);
                void *r36[7];
                void (*RSSetState)(void *self, void *state);
                void (*RSSetViewports)(void *self, uint32_t n, const D3D11_VIEWPORT *viewports);
                void *r45;
                void (*CopySubresourceRegion)(void *self, void *dst, uint32_t dstSub, uint32_t x, uint32_t y, uint32_t z, void *src, uint32_t srcSub, const D3D11_BOX *box);
                void (*CopyResource)(void *self, void *dst, void *src);
                void (*UpdateSubresource)(void *self, void *dst, uint32_t sub, const D3D11_BOX *box, const void *data, uint32_t rowPitch, uint32_t depthPitch);
                void *r49;
                void (*ClearRenderTargetView)(void *self, void *view, const float *rgba);
            } ID3D11DeviceContextVtbl;
            typedef struct { ID3D11DeviceContextVtbl *v; } ID3D11DeviceContext;
            typedef struct { void *r0[7]; int32_t (*GetAdapter)(void *self, void **adapter); } IDXGIDeviceVtbl;
            typedef struct { IDXGIDeviceVtbl *v; } IDXGIDevice;
            typedef struct { void *r0[6]; int32_t (*GetParent)(void *self, const void *iid, void **parent); } IDXGIObjectVtbl;
            typedef struct { IDXGIObjectVtbl *v; } IDXGIObject;
            typedef struct { void *r0[15];
                int32_t (*CreateSwapChainForHwnd)(void *self, void *device, void *hwnd, const DXGI_SWAP_CHAIN_DESC1 *desc, const void *fullscreen, void *output, void **out);
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
        [$vs, $ps] = [self::compile(D3dShader::QUAD, 'quad', 'vs', 'vs_5_0'), self::compile(D3dShader::QUAD, 'quad', 'ps', 'ps_5_0')];
        self::$quadVS = self::make(fn($out) => ($d->v->CreateVertexShader)($d, $vs, strlen($vs), null, $out), 'CreateVertexShader');
        self::$quadPS = self::make(fn($out) => ($d->v->CreatePixelShader)($d, $ps, strlen($ps), null, $out), 'CreatePixelShader');
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
            ($b->v->Release)($b);
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
        [self::$atlasTexture, self::$atlasView] = self::texture($atlas->w, $atlas->h, self::R8_UNORM, 1, self::BIND_SHADER_RESOURCE, false, self::$f->cast('void *', $atlas->base), $atlas->w);
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

    /** Draws into window $hwnd from now: a flip-model swap chain of two BGRA buffers, which draw() resizes to the frame's size. */
    public function attach(CData $hwnd, int $pw, int $ph): void
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
        $hr = ($fac->v->CreateSwapChainForHwnd)($fac, $d, $hwnd, FFI::addr($desc), null, null, FFI::addr($chain));
        Com::release($factory);
        self::hr($hr, 'IDXGIFactory2::CreateSwapChainForHwnd');
        $this->swapChain = Com::typed(self::$f, $chain, 'IDXGISwapChain *');
        $this->size = [max(1, $pw), max(1, $ph)];
        $this->backBuffer = $this->backBufferView();
    }

    /** The view of the swap chain's back buffer, to draw into. */
    private function backBufferView(): CData
    {
        $f = self::$f;
        $s = $this->swapChain;
        $tex = $f->new('void *');
        $iid = Com::guid(self::IID_TEXTURE2D);
        self::hr(($s->v->GetBuffer)($s, 0, FFI::addr($iid), FFI::addr($tex)), 'IDXGISwapChain::GetBuffer');
        $d = self::$device;
        $view = self::make(fn($out) => ($d->v->CreateRenderTargetView)($d, $tex, null, $out), 'CreateRenderTargetView');
        Com::release($tex);
        return $view;
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
            Com::release($this->backBuffer);
            $s = $this->swapChain;
            self::hr(($s->v->ResizeBuffers)($s, 0, $pw, $ph, 0, 0), 'IDXGISwapChain::ResizeBuffers');
            $this->size = [$pw, $ph];
            $this->backBuffer = $this->backBufferView();
        }
        $this->drawList($list, $this->backBuffer, $pw, $ph, $scale, $clear);
        $this->frame++;
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
        $this->drawList($list, $view, $pw, $ph, $scale, $clear);
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

    /** Draws $list into render target $view ($pw × $ph): one pass, all of it, for now (the glass's backdrop is the next step's). */
    private function drawList(DisplayList $list, CData $view, int $pw, int $ph, float $scale, array $clear): void
    {
        $f = self::$f;
        $c = self::$context;
        $list->showWide(false);
        $this->uploadAtlases();
        if (CanvasAtlas::take() !== []) {
            $this->warnOnce('canvases');
        }
        $this->target = [$pw / $scale, $ph / $scale, $scale, (float)$ph];
        $views = $f->new('void *[1]');
        $views[0] = $view;
        ($c->v->OMSetRenderTargets)($c, 1, $views, null);
        $vp = $f->new('D3D11_VIEWPORT');
        $vp->Width = $pw;
        $vp->Height = $ph;
        $vp->MaxDepth = 1.0;
        ($c->v->RSSetViewports)($c, 1, FFI::addr($vp));
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
        if ($list->glass !== []) {
            $this->warnOnce('glass');
        }
        $this->drawRuns($list, 0, $list->n);
    }

    /** Draws quads [$from, $from + $count): blended, but the web views' holes, which take away what is there, and the runs shaders draw, which are left out for now. */
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
                $this->warnOnce('shaders');
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
        foreach ([$this->quadsView, $this->xformsView, self::$atlasView, self::$imagesView, self::$noBackdrop] as $i => $v) {
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
        ($c->v->UpdateSubresource)($c, $texture, $layer, FFI::addr($box), $f->cast('void *', $a->base + $from * $pitch), $pitch, 0);
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
        foreach ([$this->backBuffer, $this->swapChain, $this->quadsView, $this->quads, $this->xformsView, $this->xforms] as $o) {
            Com::release($o);
        }
        $this->backBuffer = $this->swapChain = null;
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
