<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader;

use MyPHP\UI\Shader\Ir\{BasicType, Program};
use MyPHP\UI\Shader\Msl\Msl;

/**
 * A compiled shader as Metal draws it (mygo's scene.Effect with its
 * Metal code and ShaderLayout): the library's source, the shader's code
 * then the renderer's tail, which draws its rectangles and fills glyphs'
 * masks with it, and where its uniforms' words go in the buffer the
 * library reads them from. Metal builds its pipelines the first time it
 * draws it, and keeps them here.
 *
 * @internal
 */
final class Code
{
    /** The library's source. */
    public readonly string $source;
    /** @var list<int> the byte at which each word of the uniforms lies in their buffer */
    public readonly array $lanes;
    /** The buffer's size in bytes. */
    public readonly int $size;
    /** @var array<int, true> the words that are bools, a byte each in the buffer */
    public readonly array $bools;
    /** @var array<int, mixed> Metal's pipelines of it, by the pixel format they draw to and whether they keep alpha premultiplied (a canvas's) */
    public array $pipelines = [];

    public function __construct(Program $p, public readonly bool $backdrop)
    {
        $this->source = Msl::fragment($p, 'shaderFragment') . "\n" . self::TAIL;
        [$this->lanes, $this->size] = Msl::uniformLanes($p);
        $bools = []; $word = 0;
        foreach ($p->uniforms as $t) {
            $n = $t->dwordCount();
            $scalar = $t->main === BasicType::ARRAY ? $t->sub[0]->main : $t->main;
            if ($scalar === BasicType::BOOL) {
                for ($i = 0; $i < $n; $i++) { $bools[$word + $i] = true; }
            }
            $word += $n;
        }
        $this->bools = $bools;
    }

    /** $words, the uniforms' 32-bit words, as the bytes of their buffer: each at its lane, a bool's in a byte. @param list<int> $words */
    public function bytes(array $words): string
    {
        $b = str_repeat("\0", $this->size);
        foreach ($words as $i => $w) {
            $at = $this->lanes[$i];
            if (isset($this->bools[$i])) {
                $b[$at] = $w !== 0 ? "\1" : "\0";
            } else {
                $b = substr_replace($b, pack('V', $w & 0xffffffff), $at, 4);
            }
        }
        return $b;
    }

    /**
     * The renderer's tail of a shader's library (mygo's shadereffect.metal,
     * for myphp's quads): its own vertex stage, which places the quads as
     * quad_vertex does (Metal::SHADER), and the fragment stage that colors
     * a rectangle (kind 16) or a glyph's mask (17) with the shader:
     * shaderFragment, Msl::fragment's, gets the pixel's center in the
     * target's pixels and the point in points from the corner of the box
     * it fills, and returns a premultiplied color, which the quad's
     * coverage, clip, mask and opacity scale. The uniforms are buffer 3,
     * the images textures 5 to 8; Metal's names are prefixed, so that the
     * shader's own (F1, S0, Uniforms) never meet them.
     */
    private const TAIL = <<<'MSL'
    struct MyphpInst { float4 rect; float4 color; float4 params; float4 clip; };
    struct MyphpView { float2 viewport; float2 atlas; float scale; float atlasScale; float dark; float pad; };
    struct MyphpXform { float4 abcd; float4 xygh; float4 mrect; float4 mslot; };
    struct MyphpV { float4 pos [[position]]; float2 local; float2 extent; float2 uv; float2 box; float alpha; float radius; float kind;
                    float4 clip [[flat]]; float4 mrect [[flat]]; float4 mslot [[flat]]; };
    vertex MyphpV shader_vertex(uint vid [[vertex_id]], uint iid [[instance_id]],
                                const device MyphpInst* in [[buffer(0)]], constant MyphpView& u [[buffer(1)]], const device MyphpXform* xf [[buffer(2)]]) {
        MyphpInst i = in[iid];
        MyphpV o;
        float entry = floor(i.params.y / 32.0);   // DisplayList::KIND_STRIDE
        float kind = i.params.y - entry * 32.0;
        if (i.params.y < 0.0) {   // hidden (DisplayList::cull)
            o.pos = float4(0.0, 0.0, 0.0, 1.0);
            return o;
        }
        MyphpXform m = xf[uint(entry)];
        float2 corner = float2(vid & 1, vid >> 1);
        bool glyph = kind > 16.5;
        float pad = entry > 0.5 && !glyph ? 1.0 : 0.0;   // turned or scaled, a rectangle's edge is smoothed past the quad's
        float2 p = i.rect.xy - pad + corner * (i.rect.zw + 2.0 * pad);
        float w = m.xygh.z * p.x + m.xygh.w * p.y + 1.0;
        float2 q = float2(m.abcd.x * p.x + m.abcd.z * p.y + m.xygh.x, m.abcd.y * p.x + m.abcd.w * p.y + m.xygh.y);
        o.pos = float4(q.x / u.viewport.x * 2.0 - w, w - q.y / u.viewport.y * 2.0, 0.0, w);
        o.local = (corner - 0.5) * (i.rect.zw + 2.0 * pad);
        o.extent = i.rect.zw * 0.5;
        o.uv = glyph ? (i.params.zw + corner * i.rect.zw * u.atlasScale) / u.atlas : float2(0.0);
        o.box = glyph ? p - i.color.yz : p - i.rect.xy;   // from the corner of the box it fills, in points
        o.alpha = i.color.a;
        o.radius = i.params.x;
        o.kind = kind;
        o.clip = float4(i.clip.xy, i.clip.xy + i.clip.zw) * u.scale;
        o.mrect = m.mrect; o.mslot = m.mslot;
        return o;
    }
    fragment float4 shader_fragment(MyphpV v [[stage_in]], constant MyphpView& u [[buffer(1)]], constant Uniforms& uniforms [[buffer(3)]],
                                    texture2d<float> atlas [[texture(0)]], texture2d_array<float> images [[texture(1)]],
                                    texture2d<float> T0 [[texture(5)]], texture2d<float> T1 [[texture(6)]], texture2d<float> T2 [[texture(7)]], texture2d<float> T3 [[texture(8)]]) {
        if (v.pos.x < v.clip.x || v.pos.y < v.clip.y || v.pos.x >= v.clip.z || v.pos.y >= v.clip.w) {
            discard_fragment();
        }
        constexpr sampler s(filter::linear);
        float cov;
        if (v.kind > 16.5) {
            cov = atlas.sample(s, v.uv).r;
        } else {
            float2 q = abs(v.local) - (v.extent - v.radius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - v.radius;
            cov = 1.0 - smoothstep(-0.5, 0.5, d);
        }
        if (v.mrect.z != 0.0) {   // the mask it is cut by, as quad_fragment's
            float2 t = (v.pos.xy / u.scale - v.mrect.xy) / float2(abs(v.mrect.z), v.mrect.w);
            float m = 0.0;
            if (v.mslot.z < 0.5) {
                float2 inside = min(t, 1.0 - t) * float2(abs(v.mrect.z), v.mrect.w) * u.scale;
                m = clamp(min(inside.x, inside.y) + 0.5, 0.0, 1.0);
            } else if (t.x >= 0.0 && t.y >= 0.0 && t.x <= 1.0 && t.y <= 1.0) {
                constexpr sampler ms(filter::linear);
                float page = floor(v.mslot.x / 4096.0);
                m = images.sample(ms, (float2(v.mslot.x - page * 4096.0, v.mslot.y) + t * v.mslot.zw) / u.atlas, uint(page + 0.5)).a;
            }
            cov *= v.mrect.z < 0.0 ? 1.0 - m : m;
        }
        float4 c = shaderFragment(uniforms, T0, T1, T2, T3, float4(v.pos.xy, 0.0, 1.0), v.box);   // premultiplied
        float a = clamp(c.a, 0.0, 1.0);
        return float4(a > 0.0 ? c.rgb / a : float3(0.0), a * cov * v.alpha);   // straight: the pipelines blend by the source's alpha
    }
    MSL;
}
