<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use FFI;
use MyPHP\UI\DisplayList;
use MyPHP\UI\{Atlas, Canvas, CanvasAtlas, ImageAtlas};
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
    private const BLEND_ZERO = 0, BLEND_ONE = 1, BLEND_SRC_ALPHA = 4, BLEND_ONE_MINUS_SRC_ALPHA = 5;
    private const PIXEL_FORMAT_R8 = 10, PIXEL_FORMAT_RGBA8 = 70;
    private const PRIMITIVE_TRIANGLE_STRIP = 4; // MTLPrimitiveType: point 0, line 1, lineStrip 2, triangle 3, triangleStrip 4
    private const RING = 4;

    private const SHADER = <<<'MSL'
    #include <metal_stdlib>
    using namespace metal;
    struct Inst { float4 rect; float4 color; float4 params; float4 clip; };   // params: radius, kind, u, v; kind 2 (image): color.xy = slot size in pixels, color.z = the atlas page; kind 3 (shadow): u = blur; kind 4 (ring): u = thickness; kind 5 (glass): u = style, color = tint; kind 6 (gradient): alpha from color.a at the top to u at the bottom; kind 7 (a blur's level): shown by clamp(u + v t), t = clamp(color.z + color.x x + color.y y) of the quad's points, color.a its opacity; kind 8 (hard scroll edge): backdrop toned under color by color.a; kind 9 (line): radius = half the thickness, u, v = the direction's signs; kinds 10 and 11 (gradient across, down): from color to u (rgb packed) at alpha v; kind 12: an image (as 2) in shades of gray; an image's radius below 0 is -1 - a tint's packed rgb; kind 13: an image (as 2) sampled at the nearest pixel, pixel art; kind 14 (a box: DisplayList::box) and 15 (a box shadow: boxShadow) take the slot after them too, its kind -1
    struct Uniforms { float2 viewport; float2 atlas; float scale; float atlasScale; float dark; float pad; };   // scale: this view's pixels per point; atlasScale: what glyphs were rasterized at; dark: the theme
    struct V { float4 pos [[position]]; float2 local; float2 extent; float2 uv; float4 color; float radius; float kind; float4 clip [[flat]]; float4 rect [[flat]];
               float4 r4 [[flat]]; float4 b4 [[flat]]; float4 e4 [[flat]]; float4 g4 [[flat]]; };   // kinds 14 and 15: the slot after the quad (DisplayList::box, boxShadow)
    struct Xform { float4 abcd; float4 xygh; };   // the map from the quad's points to the window's: ((a x + c y + x0) / w, (b x + d y + y0) / w), w = g x + h y + 1
    vertex V quad_vertex(uint vid [[vertex_id]], uint iid [[instance_id]],
                         const device Inst* in [[buffer(0)]], constant Uniforms& u [[buffer(1)]], const device Xform* xf [[buffer(2)]]) {
        Inst i = in[iid];
        V o;
        if (i.params.y < 0.0) {   // the second slot of a box or a box shadow: no quad of its own
            o.pos = float4(0.0, 0.0, 0.0, 1.0);
            return o;
        }
        float entry = floor(i.params.y / 16.0);   // the kind carries the quad's transform, as kind + 16 * entry
        float kind = i.params.y - entry * 16.0;
        Xform m = xf[uint(entry)];
        float2 corner = float2(vid & 1, vid >> 1);
        bool sdf = kind < 0.5 || (kind > 2.5 && kind < 4.5) || (kind > 5.5 && kind < 6.5) || (kind > 8.5 && kind < 11.5) || kind > 13.5;
        float pad = entry > 0.5 && sdf ? 1.0 : 0.0;   // turned or scaled, a shape's edge is smoothed past the quad's: the quad grows to take it in
        float2 p = i.rect.xy - pad + corner * (i.rect.zw + 2.0 * pad);
        float w = m.xygh.z * p.x + m.xygh.w * p.y + 1.0;
        float2 q = float2(m.abcd.x * p.x + m.abcd.z * p.y + m.xygh.x, m.abcd.y * p.x + m.abcd.w * p.y + m.xygh.y);   // times w: divided by it after interpolation, in perspective
        o.pos = float4(q.x / u.viewport.x * 2.0 - w, w - q.y / u.viewport.y * 2.0, 0.0, w);
        o.local = (corner - 0.5) * (i.rect.zw + 2.0 * pad);
        o.extent = i.rect.zw * 0.5;
        bool image = (kind > 1.5 && kind < 2.5) || (kind > 11.5 && kind < 13.5);   // an image, in color or gray: its uv spans the slot (color.xy pixels)
        o.uv = kind > 2.5 && !image ? i.params.zw   // a shadow's blur, a ring's thickness, a glass's style, a gradient's end alpha, a blur's fade
             : (i.params.zw + corner * (image ? i.color.xy : i.rect.zw * u.atlasScale)) / u.atlas;
        o.color = i.color;
        o.radius = i.params.x;
        o.kind = kind;
        o.clip = float4(i.clip.xy, i.clip.xy + i.clip.zw) * u.scale;   // x0 y0 x1 y1 in pixels
        o.rect = i.rect * u.scale;   // the quad in pixels, for the glass
        if (kind > 13.5) {
            Inst x = in[iid + 1];
            o.r4 = x.rect; o.b4 = x.color; o.e4 = x.params; o.g4 = x.clip;
            if (kind > 14.5) { o.b4.xy -= i.rect.xy; }   // a shadow's other box, from the quad's top left
        } else {
            o.r4 = float4(0.0); o.b4 = float4(0.0); o.e4 = float4(0.0); o.g4 = float4(0.0);
        }
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
    // Boxes (14) and box shadows (15), as mygo's shader paints its fills and shadows: p from the box's top left, in points; coverage in pixels.
    float sdBox(float2 p, float2 size, float4 radii) {   // radii: top left, top right, bottom right, bottom left
        float2 h = size * 0.5;
        float2 q = p - h;
        float r = q.x < 0.0 ? (q.y < 0.0 ? radii.x : radii.w) : (q.y < 0.0 ? radii.y : radii.z);
        float2 a = abs(q) - h + r;
        return length(max(a, float2(0.0))) + min(max(a.x, a.y), 0.0) - r;
    }
    float cover(float d, float scale) { return saturate(0.5 - d * scale); }
    float4 fitRadii(float2 size, float4 r) {   // as CSS, scaled down together where two of a side's overlap
        float f = 1.0;
        if (r.x + r.y > size.x) { f = min(f, size.x / (r.x + r.y)); }
        if (r.w + r.z > size.x) { f = min(f, size.x / (r.w + r.z)); }
        if (r.x + r.w > size.y) { f = min(f, size.y / (r.x + r.w)); }
        if (r.y + r.z > size.y) { f = min(f, size.y / (r.y + r.z)); }
        return max(r * f, float4(0.0));
    }
    float3 unpackRGB(float v) {
        uint p = uint(v + 0.5);
        return float3(float((p >> 16) & 255u), float((p >> 8) & 255u), float(p & 255u)) / 255.0;
    }
    float3 toLinear(float3 c) { float3 a = abs(c); return sign(c) * select(pow((a + 0.055) / 1.055, float3(2.4)), a / 12.92, a <= 0.04045); }
    float3 toSRGB(float3 c) { float3 a = abs(c); return sign(c) * select(1.055 * pow(a, float3(1.0 / 2.4)) - 0.055, a * 12.92, a <= 0.0031308); }
    float3 cbrt3(float3 v) { return sign(v) * pow(abs(v), float3(1.0 / 3.0)); }
    float3 oklab(float3 srgb) {
        float3 c = toLinear(srgb);
        float3 lms = cbrt3(float3(0.4122214708 * c.r + 0.5363325363 * c.g + 0.0514459929 * c.b,
                                  0.2119034982 * c.r + 0.6806995451 * c.g + 0.1073969566 * c.b,
                                  0.0883024619 * c.r + 0.2817188376 * c.g + 0.6299787005 * c.b));
        return float3(0.2104542553 * lms.x + 0.7936177850 * lms.y - 0.0040720468 * lms.z,
                      1.9779984951 * lms.x - 2.4285922050 * lms.y + 0.4505937099 * lms.z,
                      0.0259040371 * lms.x + 0.7827717662 * lms.y - 0.8086757660 * lms.z);
    }
    float3 fromOklab(float3 lab) {
        float3 lms = float3(lab.x + 0.3963377774 * lab.y + 0.2158037573 * lab.z,
                            lab.x - 0.1055613458 * lab.y - 0.0638541728 * lab.z,
                            lab.x - 0.0894841775 * lab.y - 1.2914855480 * lab.z);
        lms = lms * lms * lms;
        return toSRGB(float3(4.0767416621 * lms.x - 3.3077115913 * lms.y + 0.2309699292 * lms.z,
                             -1.2684380046 * lms.x + 2.6097574011 * lms.y - 0.3413193965 * lms.z,
                             -0.0041960863 * lms.x - 0.7034186147 * lms.y + 1.7076147010 * lms.z));
    }
    float4 premul(float4 c) { return float4(c.rgb * c.a, c.a); }
    // The premultiplied color at p of a plain color (0), a gradient from g.xy to g.zw mixed in sRGB (1) or Oklab (2), or stripes (3) of c1 over c2, g.xy across them, g.z wide every g.w.
    float4 paintAt(float2 p, float mode, float4 c1, float4 c2, float4 g, float scale) {
        if (mode < 0.5) { return premul(c1); }
        if (mode < 2.5) {
            float2 d = g.zw - g.xy;
            float t = saturate(dot(p - g.xy, d) / max(dot(d, d), 0.0001));
            float a = mix(c1.a, c2.a, t);
            if (mode < 1.5) { return float4(mix(c1.rgb * c1.a, c2.rgb * c2.a, t), a); }
            if (a <= 0.0) { return float4(0.0); }
            float3 lab = mix(oklab(c1.rgb) * c1.a, oklab(c2.rgb) * c2.a, t);
            return float4(saturate(fromOklab(lab / a)) * a, a);
        }
        float s = dot(p, g.xy);
        float phase = s - g.w * floor(s / g.w);
        float cov = cover(min(max(-phase, phase - g.z), g.w - phase), scale);
        return premul(c1) * cov + premul(c2) * (1.0 - cov);
    }
    // How much of a dashed border shows at p: each side, the one p is nearest in widths of its border, has an odd number of dashes and gaps of equal length, about three widths, starting and ending with a dash.
    float dash(float2 q, float2 size, float4 w, float scale) {
        float dt = w.x > 0.0 ? q.y / w.x : 1e9;
        float dr = w.y > 0.0 ? (size.x - q.x) / w.y : 1e9;
        float db = w.z > 0.0 ? (size.y - q.y) / w.z : 1e9;
        float dl = w.w > 0.0 ? q.x / w.w : 1e9;
        float s, len, bw;
        if (dt <= dr && dt <= db && dt <= dl) { s = q.x; len = size.x; bw = w.x; }
        else if (dr <= db && dr <= dl) { s = q.y; len = size.y; bw = w.y; }
        else if (db <= dl) { s = size.x - q.x; len = size.x; bw = w.z; }
        else { s = size.y - q.y; len = size.y; bw = w.w; }
        float n = max(1.0, floor((len / (3.0 * bw) + 1.0) * 0.5 + 0.5));
        float period = 6.0 * bw;
        n += n * period <= len ? 1.0 : 0.0;   // counted again by multiplying back: GPUs divide less exactly
        n -= (n - 1.0) * period > len ? 1.0 : 0.0;
        float seg = len / (2.0 * n - 1.0);
        float k = floor(s / seg);
        float f = s - k * seg;
        float edge = min(f, seg - f);
        return cover(k - 2.0 * floor(k * 0.5) < 0.5 ? -edge : edge, scale);
    }
    float2 erf2(float2 x) {   // Abramowitz and Stegun 7.1.27
        float2 s = sign(x), a = abs(x);
        x = 1.0 + (0.278393 + (0.230389 + 0.078108 * (a * a)) * a) * a;
        x *= x;
        return s - s / (x * x);
    }
    float gaussian(float x, float sigma) { return exp(-(x * x) / (2.0 * sigma * sigma)) / (2.50662827463 * sigma); }
    float shadowX(float x, float y, float sigma, float corner, float2 h) {
        float delta = min(h.y - corner - abs(y), 0.0);
        float curved = h.x - corner + sqrt(max(0.0, corner * corner - delta * delta));
        float2 integral = 0.5 + 0.5 * erf2((x + float2(-curved, curved)) * (sqrt(0.5) / sigma));
        return integral.y - integral.x;
    }
    float boxShadow(float2 p, float2 size, float sigma, float4 radii) {   // the blurred rounded box of Evan Wallace: exact along x, four samples along y
        float corner = max(max(radii.x, radii.y), max(radii.z, radii.w));
        float2 h = size * 0.5;
        p -= h;
        float from = clamp(-3.0 * sigma, p.y - h.y, p.y + h.y);
        float to = clamp(3.0 * sigma, p.y - h.y, p.y + h.y);
        float dy = (to - from) / 4.0;
        float y = from + dy * 0.5;
        float v = 0.0;
        for (int n = 0; n < 4; n++) {
            v += shadowX(p.x, p.y - y, sigma, corner, h) * gaussian(y, sigma) * dy;
            y += dy;
        }
        return v;
    }
    float4 box(V v, constant Uniforms& u, texture2d<float> atlas) {   // the slot after: radii (or, for a mask, its place in the glyph atlas), border widths (top right bottom left), the border's rgb (packed), its alpha, the paint + 4 when dashed + 8 for a mask, the paint's g
        constexpr sampler s(filter::linear);
        float scale = u.scale;
        float2 size = 2.0 * v.extent;
        float2 p = v.local + v.extent;
        float4 rad = v.r4;
        float flags = v.e4.w;
        bool mask = flags > 7.5;   // a path's mask from the atlas, painted (Painter::fillPathGradient)
        if (mask) { flags -= 8.0; }
        float outer = mask ? atlas.sample(s, (rad.xy + p * u.atlasScale) / u.atlas).r : cover(sdBox(p, size, rad), scale);
        float4 res = paintAt(p, flags > 3.5 ? flags - 4.0 : flags, v.color, float4(unpackRGB(v.uv.x), v.uv.y), v.g4, scale) * outer;
        float4 bw = v.b4;
        if (!mask && any(bw > 0.0)) {
            float2 isz = size - bw.yz - bw.wx;
            float inner = 0.0;
            if (isz.x > 0.0 && isz.y > 0.0) {
                float4 ir = max(rad - float4(max(bw.x, bw.w), max(bw.x, bw.y), max(bw.z, bw.y), max(bw.z, bw.w)), float4(0.0));
                inner = cover(sdBox(p - bw.wx, isz, fitRadii(isz, ir)), scale);
            }
            float bc = saturate(outer - inner);
            if (flags > 3.5) { bc *= dash(p, size, bw, scale); }
            float4 b = float4(unpackRGB(v.e4.x) * v.e4.z, v.e4.z) * bc;
            res = b + res * (1.0 - b.a);
        }
        return float4(res.a > 0.0 ? res.rgb / res.a : float3(0.0), res.a);
    }
    float4 boxShadowQuad(V v, float scale) {   // u = sigma, v = 1 inside; the slot after: the shape's radii, the other box (cut out, or the hole) and its radii
        float2 size = 2.0 * v.extent;
        float2 p = v.local + v.extent;
        float sigma = v.uv.x;
        float4 other = v.b4;
        float4 orad = float4(v.e4.x, v.e4.z, v.e4.w, v.g4.x);
        bool hasOther = other.z > 0.0 && other.w > 0.0;
        float a;
        if (v.uv.y < 0.5) {   // outside: the shape, the quad less three sigmas, blurred, outside the box casting it
            float e = 3.0 * sigma;
            float2 ss = size - 2.0 * e;
            a = sigma > 0.0 ? boxShadow(p - e, ss, sigma, v.r4) : cover(sdBox(p - e, ss, v.r4), scale);
            if (hasOther) { a *= 1.0 - cover(sdBox(p - other.xy, other.zw, orad), scale); }
        } else {   // inside the quad, what the hole, blurred, leaves
            float hole = 0.0;
            if (hasOther) { hole = sigma > 0.0 ? boxShadow(p - other.xy, other.zw, sigma, orad) : cover(sdBox(p - other.xy, other.zw, orad), scale); }
            a = cover(sdBox(p, size, v.r4), scale) * (1.0 - hole);
        }
        return float4(v.color.rgb, v.color.a * a);
    }
    fragment float4 quad_fragment(V v [[stage_in]], constant Uniforms& u [[buffer(1)]], texture2d<float> atlas [[texture(0)]], texture2d_array<float> images [[texture(1)]], texture2d<float> backdrop [[texture(2)]]) {
        if (v.pos.x < v.clip.x || v.pos.y < v.clip.y || v.pos.x >= v.clip.z || v.pos.y >= v.clip.w) {
            discard_fragment();
        }
        constexpr sampler s(filter::linear);
        constexpr sampler nearest(filter::nearest);
        if (v.kind > 14.5) { return boxShadowQuad(v, u.scale); }
        if (v.kind > 13.5) { return box(v, u, atlas); }
        if (v.kind > 1.5 && (v.kind < 2.5 || v.kind > 11.5)) {   // an image from the atlas page color.z (2), in shades of gray (12): BT.709 luminance of the premultiplied color; pixelated (13)
            float4 c = v.kind > 12.5 ? images.sample(nearest, v.uv, uint(v.color.z + 0.5)) : images.sample(s, v.uv, uint(v.color.z + 0.5));   // premultiplied; the blend expects straight alpha
            if (v.kind > 11.5 && v.kind < 12.5) { c.rgb = float3(dot(c.rgb, float3(0.2126, 0.7152, 0.0722))); }
            float radius = v.radius;
            if (radius < -0.5) { c.rgb *= unpackRGB(-radius - 1.0); radius = 0.0; }   // tinted (ImageOptions): the radius carries the tint's rgb, its alpha is in color.a
            float2 q = abs(v.local) - (v.extent - radius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - radius;
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
        if (v.kind > 6.5) {   // a level of a backdrop blur (7), shown along its mask by w; macOS's hard scroll edge (8), toned under the color by its alpha
            constexpr sampler bs(filter::linear, address::clamp_to_edge);
            float3 c = backdrop.sample(bs, v.pos.xy / (u.viewport * u.scale)).rgb;
            float w = 1.0;
            if (v.kind < 7.5) {
                float2 lp = v.local + v.extent;   // the quad's points from its top left
                float t = clamp(v.color.z + v.color.x * lp.x + v.color.y * lp.y, 0.0, 1.0);
                w = clamp(v.uv.x + v.uv.y * t, 0.0, 1.0) * v.color.a;
            } else {
                float l = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
                c = clamp(l + (c - l) * 1.25 + 0.03, 0.0, 1.0);
                c += (v.color.rgb - c) * v.color.a;
            }
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
    private $blurPipeline, $canvasPipeline, $canvasUniforms;
    /** The pipeline of web views' holes: what is there goes, by the quad's coverage (DisplayList::hole). */
    private $holePipeline;
    /** @var array<string, mixed> the textures canvases are rendered in before they are copied to their place, by size */
    private array $canvasTargets = [];
    /** The textures the glass reads: a copy of the drawable, the half-size blur in progress, the blurred backdrop. */
    private $snapshot = null, $blurTemp = null, $backdrop = null;
    private array $backdropSize = [0, 0];
    /** @var array<int, string> each view's drawable size as its last frame drew it: a frame for a new size presents with the transaction */
    private array $drawn = [];
    private $blurParams;
    private $queue;
    private $pipeline;
    private array $buffers = [];
    /** The transforms' buffers, one for each of $buffers (DisplayList's $xforms). */
    private array $xformBuffers = [];
    private const TRANSFORMS = 16384;
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
    private static $delegate = null, $commonModes = null;
    /** @var array<int, bool> the views drawing a frame now, true once a frame was asked for meanwhile */
    private static array $drawing = [];

    public function __construct(public int $capacity = 65536)
    {
        $mtl = FFI::cdef('void* MTLCreateSystemDefaultDevice(void);', '/System/Library/Frameworks/Metal.framework/Metal');
        $this->device = ObjC::import($mtl->MTLCreateSystemDefaultDevice() ?? throw new RuntimeException('no Metal device'));
        $this->queue = ObjC::msg('p:', $this->device, 'newCommandQueue');
        [$this->pipeline, $this->blurPipeline, $this->canvasPipeline, $this->holePipeline] = $this->buildPipelines();
        $this->canvasUniforms = ObjC::$c->new('float[8]');
        $floats = $capacity * DisplayList::FLOATS_PER_RECT;
        for ($i = 0; $i < self::RING; $i++) {
            $buf = ObjC::msg('p:LL', $this->device, 'newBufferWithLength:options:', $floats * 4, 0 /* shared */);
            $this->buffers[] = $buf;
            $xfloats = self::TRANSFORMS * DisplayList::FLOATS_PER_TRANSFORM;
            $xbuf = ObjC::msg('p:LL', $this->device, 'newBufferWithLength:options:', $xfloats * 4, 0 /* shared */);
            $this->xformBuffers[] = $xbuf;
            $this->lists[] = new DisplayList(ObjC::$c->cast("float[$floats]", ObjC::msg('p:', $buf, 'contents')), $capacity,
                ObjC::$c->cast("float[$xfloats]", ObjC::msg('p:', $xbuf, 'contents')), self::TRANSFORMS);
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

    /** The image atlas: a texture array of ImageAtlas::MAX slices, then CanvasAtlas::PAGES for canvases, each the glyph atlas's size (the shader divides by one size). */
    public function attachImages(ImageAtlas $images): void
    {
        if ($images->w !== $this->atlas->w || $images->h !== $this->atlas->h) {
            throw new RuntimeException('the image atlas must be the glyph atlas\'s size');
        }
        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:',
            self::PIXEL_FORMAT_RGBA8, $images->w, $images->h, false);
        ObjC::msg('v:L', $desc, 'setTextureType:', self::TEXTURE_2D_ARRAY);
        ObjC::msg('v:L', $desc, 'setArrayLength:', ImageAtlas::MAX + CanvasAtlas::PAGES); // the canvases' pages after the images'
        $this->imagesTexture = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc)
            ?? throw new RuntimeException('image atlas texture');
        $this->images = $images;
        [CanvasAtlas::$w, CanvasAtlas::$h] = [$images->w, $images->h];
    }

    /** The quad pipeline (blended), the blur pipeline (opaque, for the backdrop textures), and the canvases' quad pipeline (RGBA, keeping alpha premultiplied, as the image atlas holds it). */
    private function buildPipelines(): array
    {
        $err = ObjC::$c->new('void*[1]');
        $lib = ObjC::msg('p:ppp', $this->device, 'newLibraryWithSource:options:error:', ObjC::str(self::SHADER), null, $err);
        if ($lib === null) {
            throw new RuntimeException('MSL: ' . ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription')));
        }
        $make = function (string $vertex, string $fragment, bool $blend, int $format = self::PIXEL_FORMAT_BGRA8, int $alphaFrom = self::BLEND_SRC_ALPHA, int $rgbFrom = self::BLEND_SRC_ALPHA) use ($lib, $err) {
            $desc = ObjC::msg('p:', ObjC::alloc('MTLRenderPipelineDescriptor'), 'init');
            ObjC::msg('v:p', $desc, 'setVertexFunction:', ObjC::msg('p:p', $lib, 'newFunctionWithName:', ObjC::str($vertex)));
            ObjC::msg('v:p', $desc, 'setFragmentFunction:', ObjC::msg('p:p', $lib, 'newFunctionWithName:', ObjC::str($fragment)));
            $ca = ObjC::msg('p:L', ObjC::msg('p:', $desc, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
            ObjC::msg('v:L', $ca, 'setPixelFormat:', $format);
            if ($blend) {
                ObjC::msg('v:b', $ca, 'setBlendingEnabled:', true);
                ObjC::msg('v:L', $ca, 'setSourceRGBBlendFactor:', $rgbFrom);
                ObjC::msg('v:L', $ca, 'setDestinationRGBBlendFactor:', self::BLEND_ONE_MINUS_SRC_ALPHA);
                ObjC::msg('v:L', $ca, 'setSourceAlphaBlendFactor:', $alphaFrom);
                ObjC::msg('v:L', $ca, 'setDestinationAlphaBlendFactor:', self::BLEND_ONE_MINUS_SRC_ALPHA);
            }
            $ps = ObjC::msg('p:pp', $this->device, 'newRenderPipelineStateWithDescriptor:error:', $desc, $err);
            if ($ps === null) {
                throw new RuntimeException("pipeline $fragment: " . ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription')));
            }
            return $ps;
        };
        return [$make('quad_vertex', 'quad_fragment', true), $make('blur_vertex', 'blur_fragment', false), $make('quad_vertex', 'quad_fragment', true, self::PIXEL_FORMAT_RGBA8, self::BLEND_ONE),
            $make('quad_vertex', 'quad_fragment', true, self::PIXEL_FORMAT_BGRA8, self::BLEND_ZERO, self::BLEND_ZERO)];
    }

    /**
     * An MTKView (or a subclass, $class) that calls $draw(view, DisplayList)
     * for each frame. Frames are drawn when asked (requestFrame), from the
     * run loop, or at once (`draw`); never by MTKView itself, whose display
     * link and setNeedsDisplay: draw from the main dispatch queue, where a
     * frame that waits for a sheet (App::wait) would hold the queue its
     * completion is to run on. The callback fills the list; this presents it.
     */
    public function createView($frame, Closure $draw, string $class = 'MTKView', int $fps = 60, array $clear = [1, 1, 1, 1])
    {
        self::$delegate ??= self::defineDelegate();
        $view = ObjC::msg('p:rp', ObjC::alloc($class), 'initWithFrame:device:', $frame, $this->device);
        ObjC::msg('v:b', $view, 'setPaused:', true);
        ObjC::msg('v:b', $view, 'setEnableSetNeedsDisplay:', false);
        ObjC::msg('v:L', $view, 'setColorPixelFormat:', self::PIXEL_FORMAT_BGRA8);
        ObjC::msg('v:b', $view, 'setFramebufferOnly:', false); // the glass reads a copy of the drawable
        $c = ObjC::$c->new('MTLClearColor');
        [$c->r, $c->g, $c->b, $c->a] = $clear;
        ObjC::msg('v:k', $view, 'setClearColor:', $c);
        ObjC::msg('v:l', $view, 'setPreferredFramesPerSecond:', $fps);
        ObjC::msg('v:p', $view, 'setDelegate:', self::$delegate);
        self::$onDraw[ObjC::addr($view)] = function ($view) use ($draw) {
            $key = ObjC::addr($view);
            if (isset(self::$drawing[$key])) { self::$drawing[$key] = true; return; } // asked while a frame waits for a sheet: drawn after it
            self::$drawing[$key] = false;
            $list = $this->lists[$this->frame % self::RING];
            $list->reset();
            $draw($view, $list);
            $this->present($view, $list);
            $this->frame++;
            $again = self::$drawing[$key];
            unset(self::$drawing[$key]);
            if ($again) { self::requestFrame($view); }
        };
        return $view;
    }

    /** Sets what the view clears to before each frame. */
    public static function setClearColor($view, int $rgb, float $alpha = 1.0): void
    {
        $c = ObjC::$c->new('MTLClearColor');
        $c->r = (($rgb >> 16) & 255) / 255; $c->g = (($rgb >> 8) & 255) / 255; $c->b = ($rgb & 255) / 255; $c->a = $alpha;
        ObjC::msg('v:k', $view, 'setClearColor:', $c);
    }

    /** Asks a view for a frame on the next turn of the run loop, in any of its common modes (a live resize, a modal panel); asked again before then, it draws once. */
    public static function requestFrame($view): void
    {
        self::$commonModes ??= ObjC::msg('p:', ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', ObjC::str('kCFRunLoopCommonModes')), 'retain'); // kept past the autorelease pool
        ObjC::msg('v:ppp', ObjC::cls('NSObject'), 'cancelPreviousPerformRequestsWithTarget:selector:object:', $view, ObjC::sel('draw'), null);
        ObjC::msg('v:ppdp', $view, 'performSelector:withObject:afterDelay:inModes:', ObjC::sel('draw'), null, 0.0, self::$commonModes);
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
        // A frame for a new size presents with the Core Animation transaction, as mygo's: the window's new frame and the
        // view's content show together, or the layer would stretch the last frame while the window zooms or resizes.
        // Others present as soon as the GPU has them, sparing a commit of the transaction for each. The view is told
        // (MTKView sets its layer from its own property) before it hands out the drawable, which presents as set then.
        $ds = ObjC::msg('z:', $view, 'drawableSize');
        $size = "{$ds->w}x{$ds->h}";
        $sync = ($this->drawn[ObjC::addr($view)] ?? '') !== $size;
        $this->drawn[ObjC::addr($view)] = $size;
        ObjC::msg('v:b', ObjC::msg('p:', $view, 'layer'), 'setPresentsWithTransaction:', $sync);
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
        if ($this->imagesTexture !== null) {
            foreach (CanvasAtlas::take() as $canvas) { // before the frame, which shows them
                $this->renderCanvas($cb, $canvas);
            }
        }
        $texture = ObjC::msg('p:', $drawable, 'texture');
        // One pass draws everything; a glass quad splits it: what is below it is drawn, copied and
        // blurred into the backdrop, and the pass goes on from the glass, which reads the blur.
        $this->drawList($cb, $pass, $texture, $list, [$this->buffers[$this->frame % self::RING], $this->xformBuffers[$this->frame % self::RING]]);
        if ($sync) {
            ObjC::msg('v:', $cb, 'commit');
            ObjC::msg('v:', $cb, 'waitUntilScheduled');
            ObjC::msg('v:', $drawable, 'present');
            ObjC::msg('v:', ObjC::cls('CATransaction'), 'flush'); // committed now, with the layer's new size, not at the end of the pass: a live resize commits its step before the view displays
        } else {
            ObjC::msg('v:p', $cb, 'presentDrawable:', $drawable);
            ObjC::msg('v:', $cb, 'commit');
        }
    }

    private const LOAD_LOAD = 1, LOAD_CLEAR = 2; // MTLLoadAction: dontCare 0, load 1, clear 2

    /**
     * The last frame $view showed, drawn again off screen from its list,
     * which the ring keeps until it comes round: BGRA pixels,
     * premultiplied, rows top down, [bytes, width, height]. For a native
     * drag's image of its source; it waits for the GPU.
     */
    public function lastFrame($view): array
    {
        $ds = ObjC::msg('z:', $view, 'drawableSize');
        $pw = max(1, (int)$ds->w); $ph = max(1, (int)$ds->h);
        $at = ($this->frame - 1 + self::RING) % self::RING;
        $list = $this->lists[$at];
        $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:', self::PIXEL_FORMAT_BGRA8, $pw, $ph, false);
        ObjC::msg('v:L', $desc, 'setUsage:', self::TEXTURE_SHADER_READ | self::TEXTURE_RENDER_TARGET);
        ObjC::msg('v:L', $desc, 'setStorageMode:', 0); // shared: read back here
        $texture = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc) ?? throw new RuntimeException('snapshot texture');
        $pass = ObjC::msg('p:', ObjC::cls('MTLRenderPassDescriptor'), 'renderPassDescriptor');
        $ca = ObjC::msg('p:L', ObjC::msg('p:', $pass, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:p', $ca, 'setTexture:', $texture);
        ObjC::msg('v:L', $ca, 'setLoadAction:', self::LOAD_CLEAR);
        ObjC::msg('v:L', $ca, 'setStoreAction:', 1); // store
        ObjC::msg('v:k', $ca, 'setClearColor:', ObjC::msg('k:', $view, 'clearColor'));
        $cb = ObjC::msg('p:', $this->queue, 'commandBuffer');
        $this->drawList($cb, $pass, $texture, $list, [$this->buffers[$at], $this->xformBuffers[$at]]);
        ObjC::msg('v:', $cb, 'commit');
        ObjC::msg('v:', $cb, 'waitUntilCompleted');
        $bytes = ObjC::$c->new("char[" . ($pw * $ph * 4) . "]");
        $region = ObjC::$c->new('MTLRegion');
        $region->w = $pw; $region->h = $ph; $region->d = 1;
        ObjC::msg('v:pLRL', $texture, 'getBytes:bytesPerRow:fromRegion:mipmapLevel:', ObjC::$c->cast('void*', FFI::addr($bytes)), $pw * 4, $region, 0);
        ObjC::msg('v:', $texture, 'release');
        return [FFI::string($bytes, $pw * $ph * 4), $pw, $ph];
    }

    /** Draws $list onto $texture through $pass: one pass, split at each glass quad, which reads what is below it blurred. */
    private function drawList($cb, $pass, $texture, DisplayList $list, array $buffers): void
    {
        $start = 0;
        foreach ($list->glass as $g) {
            [$at, $sigma] = $g;
            $this->pass($cb, $pass, $start, $at - $start, $start === 0, $buffers, $list->holes);
            $this->blur($cb, $texture, $sigma);
            $start = $at;
        }
        $this->pass($cb, $pass, $start, $list->n - $start, $start === 0, $buffers, $list->holes);
    }

    /**
     * Draws quads [$from, $from + $count) of the frame's buffer onto the
     * drawable, clearing it first for the first pass; the holes among them
     * with their pipeline.
     * @param list<array{0: int, 1: int}> $holes
     */
    private function pass($cb, $pass, int $from, int $count, bool $first, array $buffers, array $holes = []): void
    {
        [$quads, $xforms] = $buffers;
        if (!$first) {
            ObjC::msg('v:L', ObjC::msg('p:L', ObjC::msg('p:', $pass, 'colorAttachments'), 'objectAtIndexedSubscript:', 0), 'setLoadAction:', self::LOAD_LOAD);
        }
        $enc = ObjC::msg('p:p', $cb, 'renderCommandEncoderWithDescriptor:', $pass);
        ObjC::msg('v:p', $enc, 'setRenderPipelineState:', $this->pipeline);
        ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $quads, $from * DisplayList::FLOATS_PER_RECT * 4, 0);
        ObjC::msg('v:pLL', $enc, 'setVertexBytes:length:atIndex:', $this->uniforms, 32, 1);
        ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $xforms, 0, 2);
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
        $draw = function (int $at, int $n, $pipeline) use ($enc, $quads, $from): void {
            if ($n <= 0) { return; }
            ObjC::msg('v:p', $enc, 'setRenderPipelineState:', $pipeline);
            ObjC::msg('v:LL', $enc, 'setVertexBufferOffset:atIndex:', $at * DisplayList::FLOATS_PER_RECT * 4, 0);
            ObjC::msg('v:LLLL', $enc, 'drawPrimitives:vertexStart:vertexCount:instanceCount:', self::PRIMITIVE_TRIANGLE_STRIP, 0, 4, $n);
        };
        $at = $from;
        foreach ($holes as [$h, $slots]) {
            if ($h < $from || $h >= $from + $count) { continue; }
            $draw($at, $h - $at, $this->pipeline);
            $draw($h, $slots, $this->holePipeline);
            $at = $h + $slots;
        }
        $draw($at, $from + $count - $at, $this->pipeline);
        ObjC::msg('v:', $enc, 'endEncoding');
    }

    /**
     * Renders $c from the list it was last drawn with into a texture of its
     * size, cleared to transparent, then copies that to its place among the
     * canvases' pages: a pass may not read the texture it draws into, and
     * the canvases inside it are in that texture. Glass and blurs, which
     * read what is below them, draw nothing in a canvas.
     */
    private function renderCanvas($cb, Canvas $c): void
    {
        $list = $c->list;
        [$u, $v, $page] = $c->slot;
        $key = "{$c->w}x{$c->h}";
        if (!isset($this->canvasTargets[$key])) {
            $desc = ObjC::msg('p:LLLb', ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:', self::PIXEL_FORMAT_RGBA8, $c->w, $c->h, false);
            ObjC::msg('v:L', $desc, 'setUsage:', self::TEXTURE_SHADER_READ | self::TEXTURE_RENDER_TARGET);
            ObjC::msg('v:L', $desc, 'setStorageMode:', 2); // private
            if (count($this->canvasTargets) > 16) {
                foreach ($this->canvasTargets as $t) { ObjC::msg('v:', $t, 'release'); }
                $this->canvasTargets = [];
            }
            $this->canvasTargets[$key] = ObjC::msg('p:p', $this->device, 'newTextureWithDescriptor:', $desc) ?? throw new RuntimeException('canvas texture');
        }
        $target = $this->canvasTargets[$key];
        $pass = ObjC::msg('p:', ObjC::cls('MTLRenderPassDescriptor'), 'renderPassDescriptor');
        $ca = ObjC::msg('p:L', ObjC::msg('p:', $pass, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
        ObjC::msg('v:p', $ca, 'setTexture:', $target);
        ObjC::msg('v:L', $ca, 'setLoadAction:', self::LOAD_CLEAR);
        ObjC::msg('v:L', $ca, 'setStoreAction:', 1); // store
        ObjC::msg('v:k', $ca, 'setClearColor:', ObjC::$c->new('MTLClearColor')); // transparent
        $enc = ObjC::msg('p:p', $cb, 'renderCommandEncoderWithDescriptor:', $pass);
        if ($list->n > 0) {
            $quads = ObjC::msg('p:pLL', $this->device, 'newBufferWithBytes:length:options:', ObjC::$c->cast('void*', FFI::addr($list->buf)), $list->n * DisplayList::FLOATS_PER_RECT * 4, 0);
            $xforms = ObjC::msg('p:pLL', $this->device, 'newBufferWithBytes:length:options:', ObjC::$c->cast('void*', FFI::addr($list->xforms)), $list->transforms * DisplayList::FLOATS_PER_TRANSFORM * 4, 0);
            $uni = $this->canvasUniforms;
            [$uni[0], $uni[1], $uni[2], $uni[3], $uni[4], $uni[5], $uni[6]] = [$c->w, $c->h, $this->uniforms[2], $this->uniforms[3], 1.0, $this->uniforms[5], $this->uniforms[6]];
            ObjC::msg('v:p', $enc, 'setRenderPipelineState:', $this->canvasPipeline);
            ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $quads, 0, 0);
            ObjC::msg('v:pLL', $enc, 'setVertexBytes:length:atIndex:', $uni, 32, 1);
            ObjC::msg('v:pLL', $enc, 'setVertexBuffer:offset:atIndex:', $xforms, 0, 2);
            ObjC::msg('v:pLL', $enc, 'setFragmentBytes:length:atIndex:', $uni, 32, 1);
            ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $this->atlasTexture, 0);
            ObjC::msg('v:pL', $enc, 'setFragmentTexture:atIndex:', $this->imagesTexture, 1);
            ObjC::msg('v:LLLL', $enc, 'drawPrimitives:vertexStart:vertexCount:instanceCount:', self::PRIMITIVE_TRIANGLE_STRIP, 0, 4, $list->n);
            ObjC::msg('v:', $quads, 'release');
            ObjC::msg('v:', $xforms, 'release');
        }
        ObjC::msg('v:', $enc, 'endEncoding');
        $blit = ObjC::msg('p:', $cb, 'blitCommandEncoder');
        $from = ObjC::$c->new('MTLOrigin');
        $size = ObjC::$c->new('MTLSize');
        [$size->w, $size->h, $size->d] = [$c->w, $c->h, 1];
        $to = ObjC::$c->new('MTLOrigin');
        [$to->x, $to->y] = [$u, $v];
        ObjC::msg('v:pLLoZpLLo', $blit, 'copyFromTexture:sourceSlice:sourceLevel:sourceOrigin:sourceSize:toTexture:destinationSlice:destinationLevel:destinationOrigin:', $target, 0, 0, $from, $size, $this->imagesTexture, $page, 0, $to);
        ObjC::msg('v:', $blit, 'endEncoding');
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
