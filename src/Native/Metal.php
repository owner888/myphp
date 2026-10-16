<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use FFI;
use MyPHP\UI\DisplayList;
use MyPHP\UI\{Atlas, ImageAtlas};
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
    private const PIXEL_FORMAT_R8 = 10, PIXEL_FORMAT_RGBA8 = 70;
    private const PRIMITIVE_TRIANGLE_STRIP = 4; // MTLPrimitiveType: point 0, line 1, lineStrip 2, triangle 3, triangleStrip 4
    private const RING = 4;

    private const SHADER = <<<'MSL'
    #include <metal_stdlib>
    using namespace metal;
    struct Inst { float4 rect; float4 color; float4 params; float4 clip; };   // params: radius, kind, u, v; kind 2 (image): color.xy = slot size in pixels, color.z = the atlas page; kind 3 (shadow): u = blur; kind 4 (ring): u = thickness; kind 5 (glass): u = style, color = tint; kind 6 (gradient): alpha from color.a at the top to u at the bottom; kind 7 (blur) and 8 (hard scroll edge): backdrop mixed with color by color.a, shown from u at the top to v at the bottom; kind 9 (line): radius = half the thickness, u, v = the direction's signs; kinds 10 and 11 (gradient across, down): from color to u (rgb packed) at alpha v; kind 12: an image (as 2) in shades of gray
    struct Uniforms { float2 viewport; float2 atlas; float scale; float atlasScale; float dark; float pad; };   // scale: this view's pixels per point; atlasScale: what glyphs were rasterized at; dark: the theme
    struct V { float4 pos [[position]]; float2 local; float2 extent; float2 uv; float4 color; float radius; float kind; float4 clip [[flat]]; float4 rect [[flat]]; };
    vertex V quad_vertex(uint vid [[vertex_id]], uint iid [[instance_id]],
                         const device Inst* in [[buffer(0)]], constant Uniforms& u [[buffer(1)]]) {
        Inst i = in[iid];
        float2 corner = float2(vid & 1, vid >> 1);
        float2 p = i.rect.xy + corner * i.rect.zw;
        V o;
        o.pos = float4(p.x / u.viewport.x * 2.0 - 1.0, 1.0 - p.y / u.viewport.y * 2.0, 0.0, 1.0);
        o.local = (corner - 0.5) * i.rect.zw;
        o.extent = i.rect.zw * 0.5;
        bool image = i.params.y > 1.5 && (i.params.y < 2.5 || i.params.y > 11.5);   // an image, in color or gray: its uv spans the slot (color.xy pixels)
        o.uv = i.params.y > 2.5 && !image ? i.params.zw   // a shadow's blur, a ring's thickness, a glass's style, a gradient's end alpha, a blur's fade
             : (i.params.zw + corner * (image ? i.color.xy : i.rect.zw * u.atlasScale)) / u.atlas;
        o.color = i.color;
        o.radius = i.params.x;
        o.kind = i.params.y;
        o.clip = float4(i.clip.xy, i.clip.xy + i.clip.zw) * u.scale;   // x0 y0 x1 y1 in pixels
        o.rect = i.rect * u.scale;   // the quad in pixels, for the glass
        return o;
    }

    // Liquid Glass, as mygo's plugins/glass draws it (after macOS 26's NSGlassEffectView):
    // the blurred backdrop, bent near the edge as through the rim of a lens, toned, tinted, lit along the rim.
    float sdRoundRect(float2 p, float4 rect, float r) {
        float2 h = rect.zw * 0.5;
        float2 q = abs(p - rect.xy - h) - (h - r);
        return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - r;
    }
    float glassLens(float t, float bezel) {   // how far, as a share of the refraction, the glass bends what shows t pixels inside its edge
        if (bezel <= 0.0 || t >= bezel) { return 0.0; }
        float u = 1.0 - max(t, 0.0) / bezel;
        float v = 1.0 - u * u * u * u;
        float a = sqrt(v * sqrt(v));
        float b = u * u * u;
        float n = sqrt(a * a + b * b);
        float sn = b / n, cs = a / n;
        float st = sn / 1.5;
        float ct = sqrt(1.0 - st * st);
        return (sn * ct - cs * st) / (cs * ct + sn * st) / 1.1180340;
    }
    float2 glassNormal(float2 p, float4 rect, float radius) {   // the edge's normal at p, smoothed around the corners
        float2 h = rect.zw * 0.5;
        float2 q = p - rect.xy - h;
        float r = min(radius * 1.5, min(h.x, h.y));
        float2 a = abs(q) - (h - r);
        if (a.x > 0.0 || a.y > 0.0) {
            float2 m = max(a, float2(0.0));
            return sign(q) * m / sqrt(m.x * m.x + m.y * m.y);
        }
        return a.x > a.y ? float2(sign(q.x), 0.0) : float2(0.0, sign(q.y));
    }
    float3 glassTone(float3 c, float4 tone) {   // tone: low, high, curve, saturation
        float l = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
        float t = tone.x + (tone.y - tone.x) * (1.0 - pow(max(1.0 - l, 0.0), tone.z));
        float k = (tone.y - tone.x) * tone.w;
        return clamp(t + (c - l) * k, 0.0, 1.0);
    }
    float4 glass(V v, constant Uniforms& u, texture2d<float> backdrop) {
        constexpr sampler s(filter::linear, address::clamp_to_edge);
        float2 p = v.pos.xy;
        float r = v.radius * u.scale;
        float sd = sdRoundRect(p, v.rect, r);
        float t = max(-sd, 0.0);
        float bezel = min(36.0 * u.scale, min(v.rect.z, v.rect.w) * 0.5);
        float refraction = 1.6 * bezel;
        float rimWidth = 1.0 * u.scale;
        float2 n = float2(0.0);
        if (t < bezel || t < 3.0 * rimWidth) { n = glassNormal(p, v.rect, r); }
        float2 q = p;
        if (t < bezel) { q -= n * (refraction * glassLens(t, bezel)); }
        float3 c = backdrop.sample(s, q / (u.viewport * u.scale)).rgb;
        float4 tone = v.uv.x > 0.5 ? float4(0.125, 1.082, 1.0, 1.0)              // clear
                    : (u.dark > 0.5 ? float4(0.15, 0.51, 2.0, 2.2) : float4(0.541, 1.0, 1.2, 1.0));   // regular
        c = glassTone(c, tone);
        c += (v.color.rgb - c) * v.color.a;   // the tint
        if (t < 3.0 * rimWidth) {   // the rim, lit from above and below
            float rr = t / rimWidth;
            float a = 0.35 * exp(-rr * rr);
            float l = abs(n.y);
            c += (l - c) * a;
        }
        return float4(c, 1.0 - smoothstep(-0.5, 0.5, sd));
    }
    fragment float4 quad_fragment(V v [[stage_in]], constant Uniforms& u [[buffer(1)]], texture2d<float> atlas [[texture(0)]], texture2d_array<float> images [[texture(1)]], texture2d<float> backdrop [[texture(2)]]) {
        if (v.pos.x < v.clip.x || v.pos.y < v.clip.y || v.pos.x >= v.clip.z || v.pos.y >= v.clip.w) {
            discard_fragment();
        }
        constexpr sampler s(filter::linear);
        if (v.kind > 1.5 && (v.kind < 2.5 || v.kind > 11.5)) {   // an image from the atlas page color.z (2), in shades of gray (12): BT.709 luminance of the premultiplied color
            float4 c = images.sample(s, v.uv, uint(v.color.z + 0.5));   // premultiplied; the blend expects straight alpha
            if (v.kind > 11.5) { c.rgb = float3(dot(c.rgb, float3(0.2126, 0.7152, 0.0722))); }
            float2 q = abs(v.local) - (v.extent - v.radius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - v.radius;
            float a = 1.0 - smoothstep(-0.5, 0.5, d);
            return float4(c.rgb / max(c.a, 1e-4), c.a * v.color.a * a);
        }
        if (v.kind > 9.5) {   // a two-color gradient across (10) or down (11) the rounded rect; u packs the other color's rgb, v is its alpha
            uint p = uint(v.uv.x + 0.5);
            float4 to = float4(float((p >> 16) & 255u), float((p >> 8) & 255u), float(p & 255u), 0.0) / 255.0;
            to.a = v.uv.y;
            float t = v.kind > 10.5 ? (v.local.y + v.extent.y) / max(2.0 * v.extent.y, 1.0) : (v.local.x + v.extent.x) / max(2.0 * v.extent.x, 1.0);
            float4 c = mix(v.color, to, clamp(t, 0.0, 1.0));
            float2 q = abs(v.local) - (v.extent - v.radius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - v.radius;
            return float4(c.rgb, c.a * (1.0 - smoothstep(-0.5, 0.5, d)));
        }
        if (v.kind > 8.5) {   // a line: a capsule from one corner of the rect, inset by its radius (half the thickness), to the opposite corner; uv = the signs of its direction
            float2 h = v.extent - v.radius;
            float2 a = -h * v.uv;
            float2 pa = v.local - a, ba = -2.0 * a;
            float t = clamp(dot(pa, ba) / max(dot(ba, ba), 1e-4), 0.0, 1.0);
            float d = length(pa - ba * t) - v.radius;
            return float4(v.color.rgb, v.color.a * (1.0 - smoothstep(-0.5, 0.5, d)));
        }
        if (v.kind > 6.5) {   // a backdrop blur, faded along the quad; 8 tones it as macOS's hard scroll edge
            constexpr sampler bs(filter::linear, address::clamp_to_edge);
            float ty = (v.local.y + v.extent.y) / max(v.rect.w / u.scale, 1.0);
            float w = v.uv.x + (v.uv.y - v.uv.x) * ty;
            float3 c = backdrop.sample(bs, v.pos.xy / (u.viewport * u.scale)).rgb;
            if (v.kind > 7.5) {
                float l = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
                c = clamp(l + (c - l) * 1.25 + 0.03, 0.0, 1.0);
            }
            c += (v.color.rgb - c) * v.color.a;
            float2 q = abs(v.local) - (v.extent - v.radius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - v.radius;
            return float4(c, w * (1.0 - smoothstep(-0.5, 0.5, d)));
        }
        if (v.kind > 5.5) {   // a vertical alpha gradient of the color
            float ty = (v.local.y + v.extent.y) / max(v.rect.w / u.scale, 1.0);
            float2 q = abs(v.local) - (v.extent - v.radius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - v.radius;
            return float4(v.color.rgb, (v.color.a + (v.uv.x - v.color.a) * ty) * (1.0 - smoothstep(-0.5, 0.5, d)));
        }
        if (v.kind > 4.5) { return glass(v, u, backdrop); }
        if (v.kind > 3.5) {   // a ring: the band between the rect's edge and thickness inside it
            float2 q = abs(v.local) - (v.extent - v.radius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - v.radius;
            float a = (1.0 - smoothstep(-0.5, 0.5, d)) * smoothstep(-0.5, 0.5, d + v.uv.x);
            return float4(v.color.rgb, v.color.a * a);
        }
        if (v.kind > 2.5) {   // a shadow: the rect inset by the blur, its edge feathered over the blur
            float blur = v.uv.x;
            float2 q = abs(v.local) - (v.extent - blur - v.radius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - v.radius;
            float a = 1.0 - smoothstep(-blur, blur, d);
            return float4(v.color.rgb, v.color.a * a);
        }
        if (v.kind > 0.5) {
            return float4(v.color.rgb, v.color.a * atlas.sample(s, v.uv).r);
        }
        float2 q = abs(v.local) - (v.extent - v.radius);
        float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - v.radius;
        float a = 1.0 - smoothstep(-0.5, 0.5, d);
        return float4(v.color.rgb, v.color.a * a);
    }

    // The blur of the backdrop: a fullscreen triangle, a 1-D Gaussian along dir (texels of the source), run twice.
    struct Blur { float2 dir; float sigma; float pad; };
    struct BV { float4 pos [[position]]; float2 uv; };
    vertex BV blur_vertex(uint vid [[vertex_id]]) {
        float2 uv = float2((vid << 1) & 2, vid & 2);
        BV o; o.pos = float4(uv * 2.0 - 1.0, 0.0, 1.0); o.uv = float2(uv.x, 1.0 - uv.y); return o;
    }
    fragment float4 blur_fragment(BV v [[stage_in]], constant Blur& b [[buffer(0)]], texture2d<float> src [[texture(0)]]) {
        constexpr sampler s(filter::linear, address::clamp_to_edge);
        float2 texel = 1.0 / float2(src.get_width(), src.get_height());
        float4 sum = float4(0.0); float wsum = 0.0;
        int taps = int(ceil(b.sigma * 3.0));
        for (int i = -taps; i <= taps; i++) {
            float w = exp(-float(i * i) / (2.0 * b.sigma * b.sigma));
            sum += src.sample(s, v.uv + b.dir * float(i) * texel) * w;
            wsum += w;
        }
        return sum / wsum;
    }
    MSL;

    public $device;
    private $blurPipeline;
    /** The textures the glass reads: a copy of the drawable, the half-size blur in progress, the blurred backdrop. */
    private $snapshot = null, $blurTemp = null, $backdrop = null;
    private array $backdropSize = [0, 0];
    private $blurParams;
    private $queue;
    private $pipeline;
    private array $buffers = [];
    private array $lists = [];
    private int $frame = 0;
    private $uniforms;
    private ?Atlas $atlas = null;
    private $atlasTexture = null;
    private ?ImageAtlas $images = null;
    private $imagesTexture = null;
    private const TEXTURE_2D_ARRAY = 3; // MTLTextureType
    /** @var array<int, Closure> draw callbacks by view address */
    private static array $onDraw = [];
    private static $delegate = null;

    public function __construct(public int $capacity = 65536)
    {
        $mtl = FFI::cdef('void* MTLCreateSystemDefaultDevice(void);', '/System/Library/Frameworks/Metal.framework/Metal');
        $this->device = ObjC::import($mtl->MTLCreateSystemDefaultDevice() ?? throw new RuntimeException('no Metal device'));
        $this->queue = ObjC::msg('p:', $this->device, 'newCommandQueue');
        [$this->pipeline, $this->blurPipeline] = $this->buildPipelines();
        $floats = $capacity * DisplayList::FLOATS_PER_RECT;
        for ($i = 0; $i < self::RING; $i++) {
            $buf = ObjC::msg('p:LL', $this->device, 'newBufferWithLength:options:', $floats * 4, 0 /* shared */);
            $this->buffers[] = $buf;
            $this->lists[] = new DisplayList(ObjC::$c->cast("float[$floats]", ObjC::msg('p:', $buf, 'contents')), $capacity);
        }
        $this->uniforms = ObjC::$c->new('float[8]'); // viewport.xy, atlas.xy, scale, atlasScale, dark, pad
        $this->blurParams = ObjC::$c->new('float[4]'); // dir.xy, sigma, pad
    }

    /** Whether the theme is dark: the glass tones its backdrop differently. */
    public function setDark(bool $dark): void
    {
        $this->uniforms[6] = $dark ? 1.0 : 0.0;
    }

    /** The glyph atlas text draws from; the rows that changed are uploaded whenever it is dirty. */
    public function attachAtlas(Atlas $atlas, float $scale): void
    {
        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:',
            self::PIXEL_FORMAT_R8, $atlas->w, $atlas->h, false);
        $this->atlasTexture = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc)
            ?? throw new RuntimeException('atlas texture');
        $this->atlas = $atlas;
        $this->uniforms[2] = $atlas->w; $this->uniforms[3] = $atlas->h; $this->uniforms[5] = $scale;
    }

    /** The scale glyphs are rasterized at changed (the atlas was reset at the new one). */
    public function setAtlasScale(float $scale): void
    {
        $this->uniforms[5] = $scale;
    }

    /** The image atlas: a texture array of ImageAtlas::MAX slices, each the glyph atlas's size (the shader divides by one size). */
    public function attachImages(ImageAtlas $images): void
    {
        if ($images->w !== $this->atlas->w || $images->h !== $this->atlas->h) {
            throw new RuntimeException('the image atlas must be the glyph atlas\'s size');
        }
        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:',
            self::PIXEL_FORMAT_RGBA8, $images->w, $images->h, false);
        ObjC::msg('v:L', $desc, 'setTextureType:', self::TEXTURE_2D_ARRAY);
        ObjC::msg('v:L', $desc, 'setArrayLength:', ImageAtlas::MAX);
        $this->imagesTexture = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc)
            ?? throw new RuntimeException('image atlas texture');
        $this->images = $images;
    }

    /** The quad pipeline (blended) and the blur pipeline (opaque, for the backdrop textures). */
    private function buildPipelines(): array
    {
        $err = ObjC::$c->new('void*[1]');
        $lib = ObjC::msg('p:ppp', $this->device, 'newLibraryWithSource:options:error:', ObjC::str(self::SHADER), null, $err);
        if ($lib === null) {
            throw new RuntimeException('MSL: ' . ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription')));
        }
        $make = function (string $vertex, string $fragment, bool $blend) use ($lib, $err) {
            $desc = ObjC::msg('p:', ObjC::alloc('MTLRenderPipelineDescriptor'), 'init');
            ObjC::msg('v:p', $desc, 'setVertexFunction:', ObjC::msg('p:p', $lib, 'newFunctionWithName:', ObjC::str($vertex)));
            ObjC::msg('v:p', $desc, 'setFragmentFunction:', ObjC::msg('p:p', $lib, 'newFunctionWithName:', ObjC::str($fragment)));
            $ca = ObjC::msg('p:L', ObjC::msg('p:', $desc, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
            ObjC::msg('v:L', $ca, 'setPixelFormat:', self::PIXEL_FORMAT_BGRA8);
            if ($blend) {
                ObjC::msg('v:b', $ca, 'setBlendingEnabled:', true);
                ObjC::msg('v:L', $ca, 'setSourceRGBBlendFactor:', self::BLEND_SRC_ALPHA);
                ObjC::msg('v:L', $ca, 'setDestinationRGBBlendFactor:', self::BLEND_ONE_MINUS_SRC_ALPHA);
                ObjC::msg('v:L', $ca, 'setSourceAlphaBlendFactor:', self::BLEND_SRC_ALPHA);
                ObjC::msg('v:L', $ca, 'setDestinationAlphaBlendFactor:', self::BLEND_ONE_MINUS_SRC_ALPHA);
            }
            $ps = ObjC::msg('p:pp', $this->device, 'newRenderPipelineStateWithDescriptor:error:', $desc, $err);
            if ($ps === null) {
                throw new RuntimeException("pipeline $fragment: " . ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription')));
            }
            return $ps;
        };
        return [$make('quad_vertex', 'quad_fragment', true), $make('blur_vertex', 'blur_fragment', false)];
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
        ObjC::msg('v:b', $view, 'setFramebufferOnly:', false); // the glass reads a copy of the drawable
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

    /** Sets what the view clears to before each frame. */
    public static function setClearColor($view, int $rgb): void
    {
        $c = ObjC::$c->new('MTLClearColor');
        $c->r = (($rgb >> 16) & 255) / 255; $c->g = (($rgb >> 8) & 255) / 255; $c->b = ($rgb & 255) / 255; $c->a = 1.0;
        ObjC::msg('v:k', $view, 'setClearColor:', $c);
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
        $window = ObjC::msg('p:', $view, 'window');
        $this->uniforms[4] = $window === null ? $this->uniforms[5] : ObjC::msg('d:', $window, 'backingScaleFactor');
        if ($this->atlas !== null && $this->atlas->dirty) {
            $this->upload($this->atlasTexture, 0, $this->atlas);
        }
        if ($this->imagesTexture !== null) {
            foreach ($this->images->pages as $i => $page) {
                if ($page->dirty) {
                    $this->upload($this->imagesTexture, $i, $page);
                }
            }
        }
        $cb = ObjC::msg('p:', $this->queue, 'commandBuffer');
        $texture = ObjC::msg('p:', $drawable, 'texture');
        // One pass draws everything; a glass quad splits it: what is below it is drawn, copied and
        // blurred into the backdrop, and the pass goes on from the glass, which reads the blur.
        $start = 0;
        foreach ($list->glass as $g) {
            [$at, $sigma] = $g;
            $this->pass($cb, $pass, $start, $at - $start, $start === 0);
            $this->blur($cb, $texture, $sigma);
            $start = $at;
        }
        $this->pass($cb, $pass, $start, $list->n - $start, $start === 0);
        ObjC::msg('v:p', $cb, 'presentDrawable:', $drawable);
        ObjC::msg('v:', $cb, 'commit');
    }

    private const LOAD_LOAD = 1; // MTLLoadAction: dontCare 0, load 1, clear 2

    /** Draws quads [$from, $from + $count) of the frame's buffer onto the drawable, clearing it first for the first pass. */
    private function pass($cb, $pass, int $from, int $count, bool $first): void
    {
        if (!$first) {
            ObjC::msg('v:L', ObjC::msg('p:L', ObjC::msg('p:', $pass, 'colorAttachments'), 'objectAtIndexedSubscript:', 0), 'setLoadAction:', self::LOAD_LOAD);
        }
        $enc = ObjC::msg('p:p', $cb, 'renderCommandEncoderWithDescriptor:', $pass);
        ObjC::msg('v:p', $enc, 'setRenderPipelineState:', $this->pipeline);
        ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $this->buffers[$this->frame % self::RING], $from * DisplayList::FLOATS_PER_RECT * 4, 0);
        ObjC::msg('v:pLL', $enc, 'setVertexBytes:length:atIndex:', $this->uniforms, 32, 1);
        ObjC::msg('v:pLL', $enc, 'setFragmentBytes:length:atIndex:', $this->uniforms, 32, 1);
        if ($this->atlasTexture !== null) {
            ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $this->atlasTexture, 0);
        }
        if ($this->imagesTexture !== null) {
            ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $this->imagesTexture, 1);
        }
        if ($this->backdrop !== null) {
            ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $this->backdrop, 2);
        }
        if ($count > 0) {
            ObjC::msg('v:LLLL', $enc, 'drawPrimitives:vertexStart:vertexCount:instanceCount:', self::PRIMITIVE_TRIANGLE_STRIP, 0, 4, $count);
        }
        ObjC::msg('v:', $enc, 'endEncoding');
    }

    /** Uploads the rows of $atlas that changed since the last upload into slice $slice of $texture. */
    private function upload($texture, int $slice, Atlas $atlas): void
    {
        $from = $atlas->dirtyFrom; $rows = $atlas->dirtyTo - $from;
        $region = ObjC::$c->new('MTLRegion');
        $region->w = $atlas->w; $region->h = $rows; $region->d = 1; $region->y = $from;
        $bytes = ObjC::$c->cast('char*', $atlas->pix) + $from * $atlas->w * $atlas->bpp;
        ObjC::msg('v:RLLpLL', $texture, 'replaceRegion:mipmapLevel:slice:withBytes:bytesPerRow:bytesPerImage:',
            $region, 0, $slice, $bytes, $atlas->w * $atlas->bpp, $rows * $atlas->w * $atlas->bpp);
        $atlas->dirty = false;
    }

    private const TEXTURE_SHADER_READ = 1, TEXTURE_RENDER_TARGET = 4; // MTLTextureUsage

    /** Copies the drawable so far and blurs it, at half size, into the backdrop the glass reads. */
    private function blur($cb, $texture, float $sigma): void
    {
        $w = ObjC::msg('L:', $texture, 'width'); $h = ObjC::msg('L:', $texture, 'height');
        if ($this->backdropSize !== [$w, $h]) {
            $this->snapshot = $this->texture($w, $h);
            $this->blurTemp = $this->texture(max(1, intdiv($w, 2)), max(1, intdiv($h, 2)));
            $this->backdrop = $this->texture(max(1, intdiv($w, 2)), max(1, intdiv($h, 2)));
            $this->backdropSize = [$w, $h];
        }
        $blit = ObjC::msg('p:', $cb, 'blitCommandEncoder');
        ObjC::msg('v:pp', $blit, 'copyFromTexture:toTexture:', $texture, $this->snapshot);
        ObjC::msg('v:', $blit, 'endEncoding');
        // Half size, so the blur's sigma is halved; each pass is a fullscreen triangle.
        $this->blurPass($cb, $this->snapshot, $this->blurTemp, 1.0, 0.0, max(0.5, $sigma / 2));
        $this->blurPass($cb, $this->blurTemp, $this->backdrop, 0.0, 1.0, max(0.5, $sigma / 2));
    }

    private function blurPass($cb, $src, $dst, float $dx, float $dy, float $sigma): void
    {
        $desc = ObjC::msg('p:', ObjC::cls('MTLRenderPassDescriptor'), 'renderPassDescriptor');
        $ca = ObjC::msg('p:L', ObjC::msg('p:', $desc, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:p', $ca, 'setTexture:', $dst);
        ObjC::msg('v:L', $ca, 'setLoadAction:', 0);
        ObjC::msg('v:L', $ca, 'setStoreAction:', 1); // store
        $enc = ObjC::msg('p:p', $cb, 'renderCommandEncoderWithDescriptor:', $desc);
        ObjC::msg('v:p', $enc, 'setRenderPipelineState:', $this->blurPipeline);
        $this->blurParams[0] = $dx; $this->blurParams[1] = $dy; $this->blurParams[2] = $sigma;
        ObjC::msg('v:pLL', $enc, 'setFragmentBytes:length:atIndex:', $this->blurParams, 16, 0);
        ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $src, 0);
        ObjC::msg('v:LLL', $enc, 'drawPrimitives:vertexStart:vertexCount:', 3, 0, 3); // triangle
        ObjC::msg('v:', $enc, 'endEncoding');
    }

    private function texture(int $w, int $h)
    {
        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:', self::PIXEL_FORMAT_BGRA8, $w, $h, false);
        ObjC::msg('v:L', $desc, 'setUsage:', self::TEXTURE_SHADER_READ | self::TEXTURE_RENDER_TARGET);
        ObjC::msg('v:L', $desc, 'setStorageMode:', 2); // private: the GPU alone touches it
        return ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc) ?? throw new RuntimeException('backdrop texture');
    }
}
