<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * The HLSL of the Direct3D 11 renderer (Native\D3d): GlShader's GLSL, line
 * for line, as GlShader's is Metal.php's MSL. The quads and their slots
 * are read from a buffer of float4s (uQuads), the transforms from another
 * (uXforms); the frame's values are a constant buffer. Direct3D's pixel
 * positions count rows from the top in a window's back buffer and in a
 * texture alike, as Metal's do: nothing is drawn upside down.
 */
final class D3dShader
{
    /** The quads' vertex (vs) and pixel (ps) stages. */
    public const QUAD = <<<'HLSL'
    // GlShader's VERTEX and FRAGMENT, line for line, as HLSL (shader model 5); the atlases have no
    // mipmaps, so they are sampled at level 0, as GLSL's texture() samples them there.
    // Direct3D's pixel positions count rows from the top, as Metal's do, in a window's back
    // buffer and in a texture alike: uFlip is 1 and pos is SV_Position's.
    #define saturate(x) clamp(x, 0.0, 1.0)
    cbuffer Frame : register(b0) {
        float2 uViewport;   // points
        float2 uAtlas;      // the atlases' size in pixels
        float uScale;       // pixels per point
        float uAtlasScale;
        float uDark;
        float uFlip;
        float uTargetH;     // the target's height in pixels
        int uFrom;
        float2 uPad;
    };
    Buffer<float4> uQuads : register(t0);
    Buffer<float4> uXforms : register(t1);
    Texture2D uAtlasTex : register(t2);
    Texture2DArray uImages : register(t3);
    Texture2D uBackdrop : register(t4);
    SamplerState sLinear : register(s0);
    struct Inst { float4 rect; float4 color; float4 params; float4 clip; };
    Inst inst(int i) { Inst r; r.rect = uQuads.Load(i * 4); r.color = uQuads.Load(i * 4 + 1); r.params = uQuads.Load(i * 4 + 2); r.clip = uQuads.Load(i * 4 + 3); return r; }
    struct Varyings {
        float4 position : SV_Position;
        float2 local : TEXCOORD0;
        float2 uv : TEXCOORD1;
        nointerpolation float2 extent : TEXCOORD2;
        nointerpolation float4 color : TEXCOORD3;
        nointerpolation float radius : TEXCOORD4;
        nointerpolation float kind : TEXCOORD5;
        nointerpolation float4 clip : TEXCOORD6;
        nointerpolation float4 rect : TEXCOORD7;
        nointerpolation float4 r4 : TEXCOORD8;
        nointerpolation float4 b4 : TEXCOORD9;
        nointerpolation float4 e4 : TEXCOORD10;
        nointerpolation float4 g4 : TEXCOORD11;
        nointerpolation float4 mrect : TEXCOORD12;
        nointerpolation float4 mslot : TEXCOORD13;
        nointerpolation int inst : TEXCOORD14;
    };
    Varyings nothing() {
        Varyings o = (Varyings)0;
        o.position = float4(0.0, 0.0, 0.0, 1.0);
        return o;
    }
    Varyings vs(uint vertexID : SV_VertexID, uint instanceID : SV_InstanceID) {
        Varyings o = (Varyings)0;
        int iid = uFrom + (int)instanceID;
        Inst i = inst(iid);
        if (i.params.y < 0.0) { return nothing(); }   // the second slot of a box or a box shadow
        float entry = floor(i.params.y / 32.0);
        float kind = i.params.y - entry * 32.0;
        int e = int(entry);
        float4 abcd = uXforms.Load(e * 4), xygh = uXforms.Load(e * 4 + 1);
        float2 corner = float2(float(vertexID & 1), float(vertexID >> 1));
        bool sdf = kind < 0.5 || (kind > 2.5 && kind < 4.5) || (kind > 5.5 && kind < 6.5) || (kind > 8.5 && kind < 11.5) || kind > 13.5;
        float pad = entry > 0.5 && sdf ? 1.0 : 0.0;
        float2 p = i.rect.xy - pad + corner * (i.rect.zw + 2.0 * pad);
        if (entry < 0.5 && sdf) {
            float2 a = max(i.rect.xy, i.clip.xy), b = min(i.rect.xy + i.rect.zw, i.clip.xy + i.clip.zw);
            if (b.x <= a.x || b.y <= a.y) { return nothing(); }
            p = lerp(a, b, corner);
        }
        float w = xygh.z * p.x + xygh.w * p.y + 1.0;
        float2 q = float2(abcd.x * p.x + abcd.z * p.y + xygh.x, abcd.y * p.x + abcd.w * p.y + xygh.y);
        o.position = float4(q.x / uViewport.x * 2.0 - w, (w - q.y / uViewport.y * 2.0) * uFlip, 0.0, w);
        o.local = p - (i.rect.xy + 0.5 * i.rect.zw);
        o.extent = i.rect.zw * 0.5;
        bool image = (kind > 1.5 && kind < 2.5) || (kind > 11.5 && kind < 13.5);
        o.uv = kind > 2.5 && !image ? i.params.zw
            : (i.params.zw + corner * (image ? i.color.xy : i.rect.zw * uAtlasScale)) / uAtlas;
        o.color = i.color;
        o.radius = i.params.x;
        o.kind = kind;
        o.clip = float4(i.clip.xy, i.clip.xy + i.clip.zw) * uScale;
        o.mrect = uXforms.Load(e * 4 + 2); o.mslot = uXforms.Load(e * 4 + 3);
        o.rect = i.rect * uScale;
        o.inst = iid;
        if (kind > 13.5) {
            Inst x = inst(iid + 1);
            o.r4 = x.rect; o.b4 = x.color; o.e4 = x.params; o.g4 = x.clip;
            if (kind > 14.5) { o.b4.xy -= i.rect.xy; }
        }
        return o;
    }
    // The fragment's varyings, as the GLSL's globals, and its pixel from the top left, as Metal's [[position]].
    static float2 vLocal; static float2 vUV; static float2 vExtent; static float4 vColor; static float vRadius; static float vKind;
    static float4 vClip; static float4 vRect; static float4 vR4; static float4 vB4; static float4 vE4; static float4 vG4;
    static float4 vMrect; static float4 vMslot; static int vInst;
    static float2 pos;
    float4 backdropAt(float2 p) {   // the backdrop at pixel p (from the top left): copied from the target as it is, its rows top down as Direct3D's
        float2 t = p / (uViewport * uScale);
        return uBackdrop.SampleLevel(sLinear, t, 0);
    }
    float sdRoundRect(float2 p, float4 rect, float r) {
        float2 h = rect.zw * 0.5;
        float2 q = abs(p - rect.xy - h) - (h - r);
        return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - r;
    }
    float glassLens(float t, float bezel) {
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
    float2 glassNormal(float2 p, float4 rect, float radius) {
        float2 h = rect.zw * 0.5;
        float2 q = p - rect.xy - h;
        float r = min(radius * 1.5, min(h.x, h.y));
        float2 a = abs(q) - (h - r);
        if (a.x > 0.0 || a.y > 0.0) {
            float2 m = max(a, ((float2)(0.0)));
            return sign(q) * m / sqrt(m.x * m.x + m.y * m.y);
        }
        return a.x > a.y ? float2(sign(q.x), 0.0) : float2(0.0, sign(q.y));
    }
    float3 glassTone(float3 c, float4 tone) {
        float l = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
        float t = tone.x + (tone.y - tone.x) * (1.0 - pow(max(1.0 - l, 0.0), tone.z));
        float k = (tone.y - tone.x) * tone.w;
        return clamp(t + (c - l) * k, 0.0, 1.0);
    }
    float cover(float d, float scale) { return saturate(0.5 - d * scale); }
    static const float contExtent = 1.528665;
    struct ContCorner { float r; float e; float eff; float cx; float cy; };
    ContCorner contCorner(float r, float rh, float rv, float w, float h) {
        ContCorner c;
        c.r = r;
        c.e = contExtent * r;
        c.cx = saturate((contExtent - w / max(r + rh, 1e-6)) / (contExtent - 1.0));
        c.cy = saturate((contExtent - h / max(r + rv, 1e-6)) / (contExtent - 1.0));
        c.eff = c.e + (r - c.e) * max(c.cx, c.cy);
        return c;
    }
    float contDist(ContCorner c, float u, float v) {
        float2 a = max(((float2)(0.0)), 1.0 - float2(u, v) / c.e);
        float l = length(a);
        float hi = max(a.x, a.y);
        float rho = hi > 0.0 ? min(min(a.x, a.y) / hi, 1.0) : 0.0;
        float pp = (((-0.926054 * rho + 3.15601) * rho - 3.64122) * rho + 1.26803) * rho + 0.268531;
        float f1 = l + 1.0 - 1.0 / (1.0 - rho * rho * min(l, 1.0) * pp);
        float f2 = length(max(((float2)(0.0)), a * contExtent - (contExtent - 1.0))) * 0.654166 + 0.345834;
        float sg = a.y > a.x ? 1.0 : -1.0;
        float w = saturate(0.5 - sg + sg * rho);
        float fx = f1 + (f2 - f1) * c.cx, fy = f1 + (f2 - f1) * c.cy;
        return min(max(c.eff - u, c.eff - v), 0.0) + c.e * (fx + (fy - fx) * w - 1.0);
    }
    float contInset(ContCorner c, float v) {
        if (v >= c.e || c.r <= 0.0) { return 0.0; }
        float lo = v < c.r ? c.r - sqrt(v * (2.0 * c.r - v)) : 0.0;
        float hi = c.e - sqrt(v * (2.0 * c.e - v));
        float dlo = contDist(c, lo, v), dhi = contDist(c, hi, v);
        if (dlo <= 0.0) { return lo; }
        if (dhi >= 0.0) { return hi; }
        int side = 0;
        for (int n = 0; n < 5; n++) {
            float m = (lo * dhi - hi * dlo) / (dhi - dlo);
            float dm = contDist(c, m, v);
            if (dm > 0.0) {
                lo = m; dlo = dm;
                if (side == 1) { dhi *= 0.5; }
                side = 1;
            } else {
                hi = m; dhi = dm;
                if (side == -1) { dlo *= 0.5; }
                side = -1;
            }
        }
        return (lo * dhi - hi * dlo) / (dhi - dlo);
    }
    float areaCoverage(float2 p, float4 rect) {
        float2 c = saturate(min(rect.xy + rect.zw, p + 0.5) - max(rect.xy, p - 0.5));
        return c.x * c.y;
    }
    float contValue(float2 p, float4 rect, ContCorner c[4], out bool curved) {
        float4 d = float4(p - rect.xy, rect.xy + rect.zw - p);
        float4 uu = d.xzzx, vv = d.yyww;
        float value = max(max(-d.x, -d.y), max(-d.z, -d.w));
        curved = false;
        for (int i = 0; i < 4; i++) {
            if (c[i].r > 0.0 && uu[i] < c[i].e && vv[i] < c[i].e) {
                value = max(value, contDist(c[i], uu[i], vv[i]));
                curved = true;
            }
        }
        return value;
    }
    float contCoverage(float2 p, float4 rect, float4 r) {
        ContCorner c[4] = {contCorner(r.x, r.y, r.w, rect.z, rect.w), contCorner(r.y, r.x, r.z, rect.z, rect.w),
                                        contCorner(r.z, r.w, r.y, rect.z, rect.w), contCorner(r.w, r.z, r.x, rect.z, rect.w)};
        bool curved, ignored;
        float d = contValue(p, rect, c, curved);
        if (!curved) { return areaCoverage(p, rect); }
        if (abs(d) > 2.0) { return d < 0.0 ? 1.0 : 0.0; }
        const float h = 1.0 / 16.0;
        float dx = contValue(p + float2(h, 0.0), rect, c, ignored);
        float dy = contValue(p + float2(0.0, h), rect, c, ignored);
        return saturate(0.5 - d * h / max(abs(dx - d) + abs(dy - d), 1e-6));
    }
    float shapeCover(float2 p, float2 size, float4 radii, float scale) {
        return contCoverage(p * scale, float4(0.0, 0.0, size * scale), max(radii, ((float4)(0.0))) * scale);
    }
    float imageValue(float2 p, float2 size, float radius) {
        ContCorner k = contCorner(radius, radius, radius, size.x, size.y);
        ContCorner c[4] = {k, k, k, k};
        bool curved;
        return contValue(p, float4(0.0, 0.0, size), c, curved);
    }
    float rectCover(float radius, float scale) {
        return shapeCover(vLocal + vExtent, 2.0 * vExtent, ((float4)(radius)), scale);
    }
    float4 glass() {
        float2 p = pos;
        float r = vRadius * uScale;
        float sd = sdRoundRect(p, vRect, r);
        float t = max(-sd, 0.0);
        Inst m = inst(vInst + 1);
        float bezel = min(m.rect.x * uScale, min(vRect.z, vRect.w) * 0.5);
        float refraction = 1.6 * bezel;
        float rimWidth = 1.0 * uScale;
        float2 n = ((float2)(0.0));
        if (t < bezel || t < 3.0 * rimWidth) { n = glassNormal(p, vRect, r); }
        float2 q = p;
        if (t < bezel) { q -= n * (refraction * glassLens(t, bezel)); }
        float3 c = backdropAt(q).rgb;
        float4 tone = vUV.x > 0.5 ? float4(0.125, 1.082, 1.0, 1.0)
                  : (uDark > 0.5 ? float4(m.color.x, m.color.y, 2.0, 2.2) : float4(m.rect.z, 1.0, 1.2, 1.0));
        c = glassTone(c, tone);
        c += (vColor.rgb - c) * vColor.a;
        if (t < 3.0 * rimWidth) {
            float rr = t / rimWidth;
            float a = m.rect.y * exp(-rr * rr);
            float l = abs(n.y);
            c += (l - c) * a;
        }
        return float4(c, cover(sd, uScale));
    }
    float4 fitRadii(float2 size, float4 r) {
        float f = 1.0;
        if (r.x + r.y > size.x) { f = min(f, size.x / (r.x + r.y)); }
        if (r.w + r.z > size.x) { f = min(f, size.x / (r.w + r.z)); }
        if (r.x + r.w > size.y) { f = min(f, size.y / (r.x + r.w)); }
        if (r.y + r.z > size.y) { f = min(f, size.y / (r.y + r.z)); }
        return max(r * f, ((float4)(0.0)));
    }
    float3 unpackRGB(float v) {
        uint p = uint(round(v));
        return float3(float((p >> 16) & 255u), float((p >> 8) & 255u), float(p & 255u)) / 255.0;
    }
    float3 toLinear(float3 c) { float3 a = abs(c); return sign(c) * lerp(pow((a + 0.055) / 1.055, ((float3)(2.4))), a / 12.92, (float3)(a <= 0.04045)); }
    float3 toSRGB(float3 c) { float3 a = abs(c); return sign(c) * lerp(1.055 * pow(a, ((float3)(1.0 / 2.4))) - 0.055, a * 12.92, (float3)(a <= 0.0031308)); }
    float3 cbrt3(float3 v) { return sign(v) * pow(abs(v), ((float3)(1.0 / 3.0))); }
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
    float4 stopsAt(int at, int n, float t, bool lab) {
        if (n <= 0) { return ((float4)(0.0)); }
        Inst a = inst(at), last = inst(at + n - 1);
        if (t <= a.color.x) { return premul(a.rect); }
        if (t >= last.color.x) { return premul(last.rect); }
        Inst b = last;
        for (int i = 1; i < n - 1; i++) {
            Inst c = inst(at + i);
            if (t < c.color.x) { b = c; break; }
            a = c;
        }
        float f = saturate((t - a.color.x) / max(b.color.x - a.color.x, 1e-6));
        float alpha = lerp(a.rect.a, b.rect.a, f);
        if (!lab) { return float4(lerp(a.rect.rgb * a.rect.a, b.rect.rgb * b.rect.a, f), alpha); }
        if (alpha <= 0.0) { return ((float4)(0.0)); }
        return float4(saturate(fromOklab(lerp(a.color.yzw * a.rect.a, b.color.yzw * b.rect.a, f) / alpha)) * alpha, alpha);
    }
    float4 paintAt(float2 p, float mode, float4 c1, float4 c2, float4 g, float scale, int at, int n) {
        if (mode < 0.5) { return premul(c1); }
        if (mode > 3.5) {
            float t;
            if (mode < 5.5) {
                float2 d = g.zw - g.xy;
                t = dot(p - g.xy, d) / max(dot(d, d), 0.0001);
            } else if (mode < 7.5) {
                float2 d = p - g.xy;
                t = (atan2(d.x, -d.y) - g.z) * 0.15915494309189535;
                t -= floor(t);
            } else {
                t = length(p - g.xy) / max(g.z, 0.0001);
            }
            return stopsAt(at, n, t, (mode > 4.5 && mode < 5.5) || (mode > 6.5 && mode < 7.5) || mode > 8.5);
        }
        if (mode < 2.5) {
            float2 d = g.zw - g.xy;
            float t = saturate(dot(p - g.xy, d) / max(dot(d, d), 0.0001));
            float a = lerp(c1.a, c2.a, t);
            if (mode < 1.5) { return float4(lerp(c1.rgb * c1.a, c2.rgb * c2.a, t), a); }
            if (a <= 0.0) { return ((float4)(0.0)); }
            float3 lab = lerp(oklab(c1.rgb) * c1.a, oklab(c2.rgb) * c2.a, t);
            return float4(saturate(fromOklab(lab / a)) * a, a);
        }
        float s = dot(p, g.xy);
        float phase = s - g.w * floor(s / g.w);
        float cov = cover(min(max(-phase, phase - g.z), g.w - phase), scale);
        return premul(c1) * cov + premul(c2) * (1.0 - cov);
    }
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
        n += n * period <= len ? 1.0 : 0.0;
        n -= (n - 1.0) * period > len ? 1.0 : 0.0;
        float seg = len / (2.0 * n - 1.0);
        float k = floor(s / seg);
        float f = s - k * seg;
        float edge = min(f, seg - f);
        return cover(k - 2.0 * floor(k * 0.5) < 0.5 ? -edge : edge, scale);
    }
    float2 erf2(float2 x) {
        float2 s = sign(x), a = abs(x);
        x = 1.0 + (0.278393 + (0.230389 + 0.078108 * (a * a)) * a) * a;
        x *= x;
        return s - s / (x * x);
    }
    float gaussian(float x, float sigma) { return exp(-(x * x) / (2.0 * sigma * sigma)) / (2.50662827463 * sigma); }
    float shadowX(float x, float y, float sigma, float corner, float2 h, ContCorner cc) {
        float v = h.y - abs(y);
        float curved = corner > 0.0 && v < cc.e ? h.x - contInset(cc, v) : h.x;
        float2 integral = 0.5 + 0.5 * erf2((x + float2(-curved, curved)) * (sqrt(0.5) / sigma));
        return integral.y - integral.x;
    }
    float boxShadow(float2 p, float2 size, float sigma, float4 radii) {
        float corner = max(max(radii.x, radii.y), max(radii.z, radii.w));
        ContCorner cc = contCorner(corner, corner, corner, size.x, size.y);
        float2 h = size * 0.5;
        p -= h;
        float from = clamp(-3.0 * sigma, p.y - h.y, p.y + h.y);
        float to = clamp(3.0 * sigma, p.y - h.y, p.y + h.y);
        float dy = (to - from) / 4.0;
        float y = from + dy * 0.5;
        float v = 0.0;
        for (int n = 0; n < 4; n++) {
            v += shadowX(p.x, p.y - y, sigma, corner, h, cc) * gaussian(y, sigma) * dy;
            y += dy;
        }
        return v;
    }
    float4 box() {
        float scale = uScale;
        float2 size = 2.0 * vExtent;
        float2 p = vLocal + vExtent;
        float4 rad = vR4;
        float flags = vE4.w;
        bool borderPaint = flags > 63.5;
        if (borderPaint) { flags -= 64.0; }
        bool mask = flags > 31.5;
        if (mask) { flags -= 32.0; }
        bool dashed = flags > 15.5;
        if (dashed) { flags -= 16.0; }
        float outer = mask ? uAtlasTex.SampleLevel(sLinear, (rad.xy + p * uAtlasScale) / uAtlas, 0).r : shapeCover(p, size, rad, scale);
        float4 pc = paintAt(p, flags, vColor, float4(unpackRGB(vUV.x), vUV.y), vG4, scale, vInst + 2, flags > 3.5 ? int(vUV.x + 0.5) : 0);
        float4 res = (borderPaint ? premul(vColor) : pc) * outer;
        float4 bw = vB4;
        if (!mask && any(bw > 0.0)) {
            float2 isz = size - bw.yz - bw.wx;
            float inner = 0.0;
            if (isz.x > 0.0 && isz.y > 0.0) {
                float4 ir = max(rad - float4(max(bw.x, bw.w), max(bw.x, bw.y), max(bw.z, bw.y), max(bw.z, bw.w)), ((float4)(0.0)));
                inner = shapeCover(p - bw.wx, isz, fitRadii(isz, ir), scale);
            }
            float bc = saturate(outer - inner);
            if (dashed) { bc *= dash(p, size, bw, scale); }
            float4 b = (borderPaint ? pc : ((float4)(1.0))) * float4(unpackRGB(vE4.x) * vE4.z, vE4.z) * bc;
            res = b + res * (1.0 - b.a);
        }
        return float4(res.a > 0.0 ? res.rgb / res.a : ((float3)(0.0)), res.a);
    }
    float4 boxShadowQuad(float scale) {
        float2 size = 2.0 * vExtent;
        float2 p = vLocal + vExtent;
        float sigma = vUV.x;
        float4 other = vB4;
        float4 orad = float4(vE4.x, vE4.z, vE4.w, vG4.x);
        bool hasOther = other.z > 0.0 && other.w > 0.0;
        float a;
        if (vUV.y < 0.5) {
            float e = 3.0 * sigma;
            float2 ss = size - 2.0 * e;
            a = sigma > 0.0 ? boxShadow(p - e, ss, sigma, vR4) : shapeCover(p - e, ss, vR4, scale);
            if (hasOther) { a *= 1.0 - shapeCover(p - other.xy, other.zw, orad, scale); }
        } else {
            float hole = 0.0;
            if (hasOther) { hole = sigma > 0.0 ? boxShadow(p - other.xy, other.zw, sigma, orad) : shapeCover(p - other.xy, other.zw, orad, scale); }
            a = shapeCover(p, size, vR4, scale) * (1.0 - hole);
        }
        return float4(vColor.rgb, vColor.a * a);
    }
    float4 quadPaint() {
        if (vKind > 14.5) { return boxShadowQuad(uScale); }
        if (vKind > 13.5) { return box(); }
        if (vKind > 1.5 && (vKind < 2.5 || vKind > 11.5)) {
            float layer = floor(vColor.z + 0.5);
            float4 c = vKind > 12.5 ? uImages.Load(int4(int2(floor(vUV * uAtlas)), int(layer), 0)) : uImages.SampleLevel(sLinear, float3(vUV, layer), 0);
            if (vKind > 11.5 && vKind < 12.5) { c.rgb = ((float3)(dot(c.rgb, float3(0.2126, 0.7152, 0.0722)))); }
            float radius = vRadius;
            if (radius < -0.5) { c.rgb *= unpackRGB(-radius - 1.0); radius = 0.0; }
            float d = imageValue(vLocal + vExtent, 2.0 * vExtent, radius);
            float aa = max(fwidth(d), 1e-4);
            float a = 1.0 - smoothstep(-0.5 * aa, 0.5 * aa, d);
            return float4(c.rgb / max(c.a, 1e-4), c.a * vColor.a * a);
        }
        if (vKind > 9.5) {
            uint p = uint(round(vUV.x));
            float4 to = float4(float((p >> 16) & 255u), float((p >> 8) & 255u), float(p & 255u), 0.0) / 255.0;
            to.a = vUV.y;
            float t = vKind > 10.5 ? (vLocal.y + vExtent.y) / max(2.0 * vExtent.y, 1.0) : (vLocal.x + vExtent.x) / max(2.0 * vExtent.x, 1.0);
            float4 c = lerp(vColor, to, clamp(t, 0.0, 1.0));
            return float4(c.rgb, c.a * rectCover(vRadius, uScale));
        }
        if (vKind > 8.5) {
            float2 h = vExtent - vRadius;
            float2 a = -h * vUV;
            float2 pa = vLocal - a, ba = -2.0 * a;
            float t = clamp(dot(pa, ba) / max(dot(ba, ba), 1e-4), 0.0, 1.0);
            float d = length(pa - ba * t) - vRadius;
            return float4(vColor.rgb, vColor.a * cover(d, uScale));
        }
        if (vKind > 6.5) {
            float3 c = backdropAt(pos).rgb;
            float w = 1.0;
            if (vKind < 7.5) {
                float2 lp = vLocal + vExtent;
                float t = clamp(vColor.z + vColor.x * lp.x + vColor.y * lp.y, 0.0, 1.0);
                w = clamp(vUV.x + vUV.y * t, 0.0, 1.0) * vColor.a;
            } else {
                float l = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
                c = clamp(l + (c - l) * 1.25 + 0.03, 0.0, 1.0);
                c += (vColor.rgb - c) * vColor.a;
            }
            float2 q = abs(vLocal) - (vExtent - vRadius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - vRadius;
            return float4(c, w * cover(d, uScale));
        }
        if (vKind > 5.5) {
            float ty = (vLocal.y + vExtent.y) / max(vRect.w / uScale, 1.0);
            return float4(vColor.rgb, (vColor.a + (vUV.x - vColor.a) * ty) * rectCover(vRadius, uScale));
        }
        if (vKind > 4.5) { return glass(); }
        if (vKind > 3.5) {
            float t = vUV.x;
            float2 p = vLocal + vExtent, size = 2.0 * vExtent;
            float a = shapeCover(p, size, ((float4)(vRadius)), uScale) * (1.0 - shapeCover(p - t, size - 2.0 * t, ((float4)(max(vRadius - t, 0.0))), uScale));
            return float4(vColor.rgb, vColor.a * a);
        }
        if (vKind > 2.5) {
            float blur = vUV.x;
            float2 q = abs(vLocal) - (vExtent - blur - vRadius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - vRadius;
            float a = 1.0 - smoothstep(-blur, blur, d);
            return float4(vColor.rgb, vColor.a * a);
        }
        if (vKind > 0.5) {
            return float4(vColor.rgb, vColor.a * uAtlasTex.SampleLevel(sLinear, vUV, 0).r);
        }
        return float4(vColor.rgb, vColor.a * rectCover(vRadius, uScale));
    }
    float4 ps(Varyings v) : SV_Target {
        vLocal = v.local; vUV = v.uv; vExtent = v.extent; vColor = v.color; vRadius = v.radius; vKind = v.kind;
        vClip = v.clip; vRect = v.rect; vR4 = v.r4; vB4 = v.b4; vE4 = v.e4; vG4 = v.g4; vMrect = v.mrect; vMslot = v.mslot; vInst = v.inst;
        pos = v.position.xy;
        if (pos.x < vClip.x || pos.y < vClip.y || pos.x >= vClip.z || pos.y >= vClip.w) {
            discard;
        }
        float4 c = quadPaint();
        if (vMrect.z != 0.0) {
            float2 t = (pos / uScale - vMrect.xy) / float2(abs(vMrect.z), vMrect.w);
            float m = 0.0;
            if (vMslot.z < 0.5) {
                float4 r = float4(vMslot.x, vMslot.y, vMslot.z < -0.5 ? -1.0 - vMslot.z : 0.0, vMslot.w);
                m = contCoverage(pos, float4(vMrect.xy, abs(vMrect.z), vMrect.w) * uScale, r * uScale);
            } else if (t.x >= 0.0 && t.y >= 0.0 && t.x <= 1.0 && t.y <= 1.0) {
                float page = floor(vMslot.x / 4096.0);
                m = uImages.SampleLevel(sLinear, float3((float2(vMslot.x - page * 4096.0, vMslot.y) + t * vMslot.zw) / uAtlas, page), 0).a;
            }
            c.a *= vMrect.z < 0.0 ? 1.0 - m : m;
        }
        return c;
    }
    HLSL;

    /** The backdrop's blur: a fullscreen triangle, a 1-D Gaussian along uDir (texels of the source); vs and ps. */
    public const BLUR = <<<'HLSL'
    cbuffer Blur : register(b0) { float2 uDir; float uSigma; float uPad; };
    Texture2D uSrc : register(t0);
    SamplerState sLinear : register(s0);
    struct V { float4 position : SV_Position; float2 uv : TEXCOORD0; };
    V vs(uint id : SV_VertexID) {
        V o;
        float2 uv = float2(float((id << 1) & 2), float(id & 2));
        o.position = float4(uv.x * 2.0 - 1.0, 1.0 - uv.y * 2.0, 0.0, 1.0);
        o.uv = uv;
        return o;
    }
    float4 ps(V v) : SV_Target {
        uint w, h;
        uSrc.GetDimensions(w, h);
        float2 texel = 1.0 / float2(w, h);
        float4 sum = (float4)0.0; float wsum = 0.0;
        int taps = int(ceil(uSigma * 3.0));
        for (int i = -taps; i <= taps; i++) {
            float k = exp(-float(i * i) / (2.0 * uSigma * uSigma));
            sum += uSrc.SampleLevel(sLinear, v.uv + uDir * float(i) * texel, 0) * k;
            wsum += k;
        }
        return sum / wsum;
    }
    HLSL;
}
