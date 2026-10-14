<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use FFI;
use MyPHP\UI\DisplayList;
use MyPHP\UI\GlyphAtlas;
use RuntimeException;

/**
 * Metal renderer: an MTKView paced by the display, one instanced draw call
 * per frame from a DisplayList the frame wrote into a shared MTLBuffer.
 *
 * Buffers form a ring of four: MTKView keeps at most three drawables in
 * flight, so the buffer being written is never one the GPU still reads.
 */
final class Metal
{
    private const PIXEL_FORMAT_BGRA8 = 80;
    private const BLEND_SRC_ALPHA = 4, BLEND_ONE_MINUS_SRC_ALPHA = 5;
    private const PIXEL_FORMAT_R8 = 10;
    private const PRIMITIVE_TRIANGLE_STRIP = 4; // MTLPrimitiveType: point 0, line 1, lineStrip 2, triangle 3, triangleStrip 4
    private const RING = 4;

    private const SHADER = <<<'MSL'
    #include <metal_stdlib>
    using namespace metal;
    struct Inst { float4 rect; float4 color; float4 params; float4 clip; };   // params: radius, kind, u, v
    struct Uniforms { float2 viewport; float2 atlas; float scale; float pad; };
    struct V { float4 pos [[position]]; float2 local; float2 extent; float2 uv; float4 color; float radius; float kind; float4 clip [[flat]]; };
    vertex V quad_vertex(uint vid [[vertex_id]], uint iid [[instance_id]],
                         const device Inst* in [[buffer(0)]], constant Uniforms& u [[buffer(1)]]) {
        Inst i = in[iid];
        float2 corner = float2(vid & 1, vid >> 1);
        float2 p = i.rect.xy + corner * i.rect.zw;
        V o;
        o.pos = float4(p.x / u.viewport.x * 2.0 - 1.0, 1.0 - p.y / u.viewport.y * 2.0, 0.0, 1.0);
        o.local = (corner - 0.5) * i.rect.zw;
        o.extent = i.rect.zw * 0.5;
        o.uv = (i.params.zw + corner * i.rect.zw * u.scale) / u.atlas;
        o.color = i.color;
        o.radius = i.params.x;
        o.kind = i.params.y;
        o.clip = float4(i.clip.xy, i.clip.xy + i.clip.zw) * u.scale;   // x0 y0 x1 y1 in pixels
        return o;
    }
    fragment float4 quad_fragment(V v [[stage_in]], texture2d<float> atlas [[texture(0)]]) {
        if (v.pos.x < v.clip.x || v.pos.y < v.clip.y || v.pos.x >= v.clip.z || v.pos.y >= v.clip.w) {
            discard_fragment();
        }
        if (v.kind > 0.5) {
            constexpr sampler s(filter::linear);
            return float4(v.color.rgb, v.color.a * atlas.sample(s, v.uv).r);
        }
        float2 q = abs(v.local) - (v.extent - v.radius);
        float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - v.radius;
        float a = 1.0 - smoothstep(-0.5, 0.5, d);
        return float4(v.color.rgb, v.color.a * a);
    }
    MSL;

    public $device;
    private $queue;
    private $pipeline;
    private array $buffers = [];
    private array $lists = [];
    private int $frame = 0;
    private $uniforms;
    private ?GlyphAtlas $atlas = null;
    private $atlasTexture = null;
    private $atlasRegion = null;
    /** @var array<int, Closure> draw callbacks by view address */
    private static array $onDraw = [];
    private static $delegate = null;

    public function __construct(public int $capacity = 65536)
    {
        $mtl = FFI::cdef('void* MTLCreateSystemDefaultDevice(void);', '/System/Library/Frameworks/Metal.framework/Metal');
        $this->device = ObjC::import($mtl->MTLCreateSystemDefaultDevice() ?? throw new RuntimeException('no Metal device'));
        $this->queue = ObjC::msg('p:', $this->device, 'newCommandQueue');
        $this->pipeline = $this->buildPipeline();
        $floats = $capacity * DisplayList::FLOATS_PER_RECT;
        for ($i = 0; $i < self::RING; $i++) {
            $buf = ObjC::msg('p:LL', $this->device, 'newBufferWithLength:options:', $floats * 4, 0 /* shared */);
            $this->buffers[] = $buf;
            $this->lists[] = new DisplayList(ObjC::$c->cast("float[$floats]", ObjC::msg('p:', $buf, 'contents')), $capacity);
        }
        $this->uniforms = ObjC::$c->new('float[6]'); // viewport.xy, atlas.xy, scale, pad
    }

    /** The glyph atlas text draws from; uploaded whenever it is dirty. */
    public function attachAtlas(GlyphAtlas $atlas, float $scale): void
    {
        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:',
            self::PIXEL_FORMAT_R8, $atlas->w, $atlas->h, false);
        $this->atlasTexture = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc)
            ?? throw new RuntimeException('atlas texture');
        $this->atlasRegion = ObjC::$c->new('MTLRegion');
        $this->atlasRegion->w = $atlas->w; $this->atlasRegion->h = $atlas->h; $this->atlasRegion->d = 1;
        $this->atlas = $atlas;
        $this->uniforms[2] = $atlas->w; $this->uniforms[3] = $atlas->h; $this->uniforms[4] = $scale;
    }

    private function buildPipeline()
    {
        $err = ObjC::$c->new('void*[1]');
        $lib = ObjC::msg('p:ppp', $this->device, 'newLibraryWithSource:options:error:', ObjC::str(self::SHADER), null, $err);
        if ($lib === null) {
            throw new RuntimeException('MSL: ' . ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription')));
        }
        $desc = ObjC::msg('p:', ObjC::alloc('MTLRenderPipelineDescriptor'), 'init');
        ObjC::msg('v:p', $desc, 'setVertexFunction:', ObjC::msg('p:p', $lib, 'newFunctionWithName:', ObjC::str('quad_vertex')));
        ObjC::msg('v:p', $desc, 'setFragmentFunction:', ObjC::msg('p:p', $lib, 'newFunctionWithName:', ObjC::str('quad_fragment')));
        $ca = ObjC::msg('p:L', ObjC::msg('p:', $desc, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:L', $ca, 'setPixelFormat:', self::PIXEL_FORMAT_BGRA8);
        ObjC::msg('v:b', $ca, 'setBlendingEnabled:', true);
        ObjC::msg('v:L', $ca, 'setSourceRGBBlendFactor:', self::BLEND_SRC_ALPHA);
        ObjC::msg('v:L', $ca, 'setDestinationRGBBlendFactor:', self::BLEND_ONE_MINUS_SRC_ALPHA);
        ObjC::msg('v:L', $ca, 'setSourceAlphaBlendFactor:', self::BLEND_SRC_ALPHA);
        ObjC::msg('v:L', $ca, 'setDestinationAlphaBlendFactor:', self::BLEND_ONE_MINUS_SRC_ALPHA);
        $ps = ObjC::msg('p:pp', $this->device, 'newRenderPipelineStateWithDescriptor:error:', $desc, $err);
        if ($ps === null) {
            throw new RuntimeException('pipeline: ' . ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription')));
        }
        return $ps;
    }

    /**
     * An MTKView (or a subclass, $class) that calls $draw(view, DisplayList)
     * for each frame. On demand, a frame follows setNeedsDisplay: only (what
     * requestFrame() sends); otherwise frames run at the display's rate.
     * The callback fills the list; this presents it.
     */
    public function createView($frame, Closure $draw, bool $onDemand = true, string $class = 'MTKView', int $fps = 60, array $clear = [1, 1, 1, 1])
    {
        self::$delegate ??= self::defineDelegate();
        $view = ObjC::msg('p:rp', ObjC::alloc($class), 'initWithFrame:device:', $frame, $this->device);
        ObjC::msg('v:b', $view, 'setPaused:', $onDemand);
        ObjC::msg('v:b', $view, 'setEnableSetNeedsDisplay:', $onDemand);
        ObjC::msg('v:L', $view, 'setColorPixelFormat:', self::PIXEL_FORMAT_BGRA8);
        $c = ObjC::$c->new('MTLClearColor');
        [$c->r, $c->g, $c->b, $c->a] = $clear;
        ObjC::msg('v:k', $view, 'setClearColor:', $c);
        ObjC::msg('v:l', $view, 'setPreferredFramesPerSecond:', $fps);
        ObjC::msg('v:p', $view, 'setDelegate:', self::$delegate);
        self::$onDraw[ObjC::addr($view)] = function ($view) use ($draw) {
            $list = $this->lists[$this->frame % self::RING];
            $list->reset();
            $draw($view, $list);
            $this->present($view, $list);
            $this->frame++;
        };
        return $view;
    }

    /** Asks an on-demand view for a frame. */
    public static function requestFrame($view): void
    {
        ObjC::msg('v:b', $view, 'setNeedsDisplay:', true);
    }

    private static function defineDelegate()
    {
        ObjC::defineClass('MyPHPMetalDelegate', 'NSObject', [
            'drawInMTKView:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $view) {
                (self::$onDraw[ObjC::addr($view)])($view);
            }],
            'mtkView:drawableSizeWillChange:' => ['v@:@{CGSize=dd}', 'v(pppz)', function ($self, $cmd, $view, $size) {}],
        ]);
        return ObjC::msg('p:', ObjC::alloc('MyPHPMetalDelegate'), 'init');
    }

    private function present($view, DisplayList $list): void
    {
        $pass = ObjC::msg('p:', $view, 'currentRenderPassDescriptor');
        $drawable = ObjC::msg('p:', $view, 'currentDrawable');
        if ($pass === null || $drawable === null) {
            return;
        }
        $bounds = ObjC::msg('r:', $view, 'bounds');
        $this->uniforms[0] = $bounds->w;
        $this->uniforms[1] = $bounds->h;
        if ($this->atlas !== null && $this->atlas->dirty) {
            ObjC::msg('v:RLpL', $this->atlasTexture, 'replaceRegion:mipmapLevel:withBytes:bytesPerRow:', $this->atlasRegion, 0, $this->atlas->pix, $this->atlas->w);
            $this->atlas->dirty = false;
        }
        $cb = ObjC::msg('p:', $this->queue, 'commandBuffer');
        $enc = ObjC::msg('p:p', $cb, 'renderCommandEncoderWithDescriptor:', $pass);
        ObjC::msg('v:p', $enc, 'setRenderPipelineState:', $this->pipeline);
        ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $this->buffers[$this->frame % self::RING], 0, 0);
        ObjC::msg('v:pLL', $enc, 'setVertexBytes:length:atIndex:', $this->uniforms, 24, 1);
        if ($this->atlasTexture !== null) {
            ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $this->atlasTexture, 0);
        }
        if ($list->n > 0) {
            ObjC::msg('v:LLLL', $enc, 'drawPrimitives:vertexStart:vertexCount:instanceCount:', self::PRIMITIVE_TRIANGLE_STRIP, 0, 4, $list->n);
        }
        ObjC::msg('v:', $enc, 'endEncoding');
        ObjC::msg('v:p', $cb, 'presentDrawable:', $drawable);
        ObjC::msg('v:', $cb, 'commit');
    }
}
