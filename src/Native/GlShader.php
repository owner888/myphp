<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * The GLSL of the OpenGL renderer (Native\Gl): Metal.php's MSL, line for
 * line, as GLSL 3.30. A quad's instance and the slots after it are read
 * from a buffer texture of RGBA32F texels, four a quad (uQuads), the
 * transforms from another (uXforms). Metal's pixel position counts rows
 * from the top; gl_FragCoord counts them from the bottom, so the pixel's
 * position is taken from it by the target's height (uFlip 1: a window's
 * framebuffer, shown as it is); a target drawn to be read as a texture
 * (a canvas's, a read back) is drawn upside down (uFlip -1), so that its
 * first row is the top one, as the atlases' rows are.
 */
final class GlShader
{
    /** Adapts desktop GLSL 3.30 to GLSL ES on Android: $esVersion, esVersion()'s header for the context's GPU, in place of the #version line. */
    public static function adapt(string $glsl, string $esVersion = "#version 320 es\n"): string
    {
        if (!\MyPHP\App::isAndroid()) {
            return $glsl;
        }
        $header = $esVersion
            . "precision highp float;\n"
            . "precision highp int;\n"
            . "precision highp sampler2D;\n"
            . "precision highp sampler2DArray;\n";
        if (self::texelRows($esVersion)) {
            $header .= "vec4 texelRow(highp sampler2D s, int i) { return texelFetch(s, ivec2(i & " . (self::TEXEL_ROW - 1) . ", i >> " . (int)log(self::TEXEL_ROW, 2) . "), 0); }\n";
            $glsl = preg_replace('/\buniform\s+samplerBuffer\s+(\w+)\s*;/', 'uniform highp sampler2D $1;', $glsl);
            $glsl = preg_replace('/\btexelFetch\((uQuads|uXforms),/', 'texelRow($1,', $glsl);
        } else {
            $header .= "precision highp samplerBuffer;\n";
        }
        return preg_replace('/^\s*#version\s+330\s+core\s*/m', $header, $glsl);
    }

    /** The texels of a row of the 2D textures that hold the quads and transforms on a GPU without texture buffers. */
    public const TEXEL_ROW = 2048;

    /** Whether shaders of $esVersion read the quads and transforms from rows of 2D textures (GLSL ES 3.00: no texture buffers). */
    public static function texelRows(string $esVersion): bool
    {
        return str_starts_with($esVersion, '#version 300 es');
    }

    /**
     * The #version line (and #extension) of the shaders for a GPU of GLSL ES $glsl (GL_SHADING_LANGUAGE_VERSION, as
     * "OpenGL ES GLSL ES 3.10") and $extensions (GL_EXTENSIONS): 3.20, whose texture buffers the shaders read
     * (samplerBuffer), on a phone's GPU; 3.10 with GL_EXT_texture_buffer or GL_OES_texture_buffer on one that has no
     * 3.2; 3.00 otherwise, as the Android emulator's on a Mac, whose shaders read rows of 2D textures in place of
     * texture buffers (texelRows). Throws for a GPU below 3.00.
     */
    public static function esVersion(string $glsl, string $extensions): string
    {
        $v = preg_match('/(\d+)\.(\d+)/', $glsl, $m) ? (int)$m[1] * 100 + (int)$m[2] : 0;
        if ($v >= 320) {
            return "#version 320 es\n";
        }
        $names = preg_split('/\s+/', trim($extensions)) ?: [];
        foreach (['GL_EXT_texture_buffer', 'GL_OES_texture_buffer'] as $ext) {
            if ($v >= 310 && in_array($ext, $names, true)) {
                return "#version 310 es\n#extension $ext : require\n";
            }
        }
        if ($v >= 300) {
            return "#version 300 es\n";
        }
        throw new \RuntimeException("myphp: the GPU offers GLSL ES " . ($v > 0 ? sprintf('%d.%02d', intdiv($v, 100), $v % 100) : "\"$glsl\"") . ': myphp needs 3.00 or later');
    }

    public const COMMON = <<<'GLSL'
    #version 330 core
    #define saturate(x) clamp(x, 0.0, 1.0)
    uniform samplerBuffer uQuads;
    uniform vec2 uViewport;   // points
    uniform vec2 uAtlas;      // the atlases' size in pixels
    uniform float uScale;     // pixels per point
    uniform float uAtlasScale;
    uniform float uDark;
    uniform float uFlip;      // 1: a window's framebuffer; -1: a texture read top row first
    uniform float uTargetH;   // the target's height in pixels
    struct Inst { vec4 rect; vec4 color; vec4 params; vec4 clip; };
    Inst inst(int i) { return Inst(texelFetch(uQuads, i * 4), texelFetch(uQuads, i * 4 + 1), texelFetch(uQuads, i * 4 + 2), texelFetch(uQuads, i * 4 + 3)); }
    GLSL;

    public const VERTEX = <<<'GLSL'
    uniform samplerBuffer uXforms;
    uniform int uFrom;
    out vec2 vLocal;
    out vec2 vUV;
    flat out vec2 vExtent;
    flat out vec4 vColor;
    flat out float vRadius;
    flat out float vKind;
    flat out vec4 vClip;
    flat out vec4 vRect;
    flat out vec4 vR4; flat out vec4 vB4; flat out vec4 vE4; flat out vec4 vG4;
    flat out vec4 vMrect; flat out vec4 vMslot;
    flat out int vInst;
    void nothing() {
        gl_Position = vec4(0.0, 0.0, 0.0, 1.0);
        vLocal = vec2(0.0); vUV = vec2(0.0); vExtent = vec2(0.0); vColor = vec4(0.0); vRadius = 0.0; vKind = 0.0;
        vClip = vec4(0.0); vRect = vec4(0.0); vR4 = vec4(0.0); vB4 = vec4(0.0); vE4 = vec4(0.0); vG4 = vec4(0.0);
        vMrect = vec4(0.0); vMslot = vec4(0.0); vInst = 0;
    }
    void main() {
        int iid = uFrom + gl_InstanceID;
        Inst i = inst(iid);
        if (i.params.y < 0.0) { nothing(); return; }   // the second slot of a box or a box shadow
        float entry = floor(i.params.y / 32.0);
        float kind = i.params.y - entry * 32.0;
        int e = int(entry);
        vec4 abcd = texelFetch(uXforms, e * 4), xygh = texelFetch(uXforms, e * 4 + 1);
        vec2 corner = vec2(float(gl_VertexID & 1), float(gl_VertexID >> 1));
        bool sdf = kind < 0.5 || (kind > 2.5 && kind < 4.5) || (kind > 5.5 && kind < 6.5) || (kind > 8.5 && kind < 11.5) || kind > 13.5;
        float pad = entry > 0.5 && sdf ? 1.0 : 0.0;
        vec2 p = i.rect.xy - pad + corner * (i.rect.zw + 2.0 * pad);
        if (entry < 0.5 && sdf) {
            vec2 a = max(i.rect.xy, i.clip.xy), b = min(i.rect.xy + i.rect.zw, i.clip.xy + i.clip.zw);
            if (b.x <= a.x || b.y <= a.y) { nothing(); return; }
            p = mix(a, b, corner);
        }
        float w = xygh.z * p.x + xygh.w * p.y + 1.0;
        vec2 q = vec2(abcd.x * p.x + abcd.z * p.y + xygh.x, abcd.y * p.x + abcd.w * p.y + xygh.y);
        gl_Position = vec4(q.x / uViewport.x * 2.0 - w, (w - q.y / uViewport.y * 2.0) * uFlip, 0.0, w);
        vLocal = p - (i.rect.xy + 0.5 * i.rect.zw);
        vExtent = i.rect.zw * 0.5;
        bool image = (kind > 1.5 && kind < 2.5) || (kind > 11.5 && kind < 13.5);
        vUV = kind > 2.5 && !image ? i.params.zw
            : (i.params.zw + corner * (image ? i.color.xy : i.rect.zw * uAtlasScale)) / uAtlas;
        vColor = i.color;
        vRadius = i.params.x;
        vKind = kind;
        vClip = vec4(i.clip.xy, i.clip.xy + i.clip.zw) * uScale;
        vMrect = texelFetch(uXforms, e * 4 + 2); vMslot = texelFetch(uXforms, e * 4 + 3);
        vRect = i.rect * uScale;
        vInst = iid;
        if (kind > 13.5) {
            Inst x = inst(iid + 1);
            vR4 = x.rect; vB4 = x.color; vE4 = x.params; vG4 = x.clip;
            if (kind > 14.5) { vB4.xy -= i.rect.xy; }
        } else {
            vR4 = vec4(0.0); vB4 = vec4(0.0); vE4 = vec4(0.0); vG4 = vec4(0.0);
        }
    }
    GLSL;

    public const FRAGMENT = <<<'GLSL'
    uniform sampler2D uAtlasTex;
    uniform sampler2DArray uImages;
    uniform sampler2D uBackdrop;
    in vec2 vLocal;
    in vec2 vUV;
    flat in vec2 vExtent;
    flat in vec4 vColor;
    flat in float vRadius;
    flat in float vKind;
    flat in vec4 vClip;
    flat in vec4 vRect;
    flat in vec4 vR4; flat in vec4 vB4; flat in vec4 vE4; flat in vec4 vG4;
    flat in vec4 vMrect; flat in vec4 vMslot;
    flat in int vInst;
    out vec4 fragColor;
    vec2 pos;   // the fragment's pixel, from the top left, as Metal's [[position]]

    vec4 backdropAt(vec2 p) {   // the backdrop at pixel p (from the top left): copied from the target as it is, its rows bottom up in a window's
        vec2 t = p / (uViewport * uScale);
        return texture(uBackdrop, vec2(t.x, uFlip > 0.0 ? 1.0 - t.y : t.y));
    }
    float sdRoundRect(vec2 p, vec4 rect, float r) {
        vec2 h = rect.zw * 0.5;
        vec2 q = abs(p - rect.xy - h) - (h - r);
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
    vec2 glassNormal(vec2 p, vec4 rect, float radius) {
        vec2 h = rect.zw * 0.5;
        vec2 q = p - rect.xy - h;
        float r = min(radius * 1.5, min(h.x, h.y));
        vec2 a = abs(q) - (h - r);
        if (a.x > 0.0 || a.y > 0.0) {
            vec2 m = max(a, vec2(0.0));
            return sign(q) * m / sqrt(m.x * m.x + m.y * m.y);
        }
        return a.x > a.y ? vec2(sign(q.x), 0.0) : vec2(0.0, sign(q.y));
    }
    vec3 glassTone(vec3 c, vec4 tone) {
        float l = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
        float t = tone.x + (tone.y - tone.x) * (1.0 - pow(max(1.0 - l, 0.0), tone.z));
        float k = (tone.y - tone.x) * tone.w;
        return clamp(t + (c - l) * k, 0.0, 1.0);
    }
    float cover(float d, float scale) { return saturate(0.5 - d * scale); }
    const float contExtent = 1.528665;
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
        vec2 a = max(vec2(0.0), 1.0 - vec2(u, v) / c.e);
        float l = length(a);
        float hi = max(a.x, a.y);
        float rho = hi > 0.0 ? min(min(a.x, a.y) / hi, 1.0) : 0.0;
        float pp = (((-0.926054 * rho + 3.15601) * rho - 3.64122) * rho + 1.26803) * rho + 0.268531;
        float f1 = l + 1.0 - 1.0 / (1.0 - rho * rho * min(l, 1.0) * pp);
        float f2 = length(max(vec2(0.0), a * contExtent - (contExtent - 1.0))) * 0.654166 + 0.345834;
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
    float areaCoverage(vec2 p, vec4 rect) {
        vec2 c = saturate(min(rect.xy + rect.zw, p + 0.5) - max(rect.xy, p - 0.5));
        return c.x * c.y;
    }
    float contValue(vec2 p, vec4 rect, ContCorner c[4], out bool curved) {
        vec4 d = vec4(p - rect.xy, rect.xy + rect.zw - p);
        vec4 uu = d.xzzx, vv = d.yyww;
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
    float contCoverage(vec2 p, vec4 rect, vec4 r) {
        ContCorner c[4] = ContCorner[4](contCorner(r.x, r.y, r.w, rect.z, rect.w), contCorner(r.y, r.x, r.z, rect.z, rect.w),
                                        contCorner(r.z, r.w, r.y, rect.z, rect.w), contCorner(r.w, r.z, r.x, rect.z, rect.w));
        bool curved, ignored;
        float d = contValue(p, rect, c, curved);
        if (!curved) { return areaCoverage(p, rect); }
        if (abs(d) > 2.0) { return d < 0.0 ? 1.0 : 0.0; }
        const float h = 1.0 / 16.0;
        float dx = contValue(p + vec2(h, 0.0), rect, c, ignored);
        float dy = contValue(p + vec2(0.0, h), rect, c, ignored);
        return saturate(0.5 - d * h / max(abs(dx - d) + abs(dy - d), 1e-6));
    }
    float shapeCover(vec2 p, vec2 size, vec4 radii, float scale) {
        return contCoverage(p * scale, vec4(0.0, 0.0, size * scale), max(radii, vec4(0.0)) * scale);
    }
    float imageValue(vec2 p, vec2 size, float radius) {
        ContCorner k = contCorner(radius, radius, radius, size.x, size.y);
        ContCorner c[4] = ContCorner[4](k, k, k, k);
        bool curved;
        return contValue(p, vec4(0.0, 0.0, size), c, curved);
    }
    float rectCover(float radius, float scale) {
        return shapeCover(vLocal + vExtent, 2.0 * vExtent, vec4(radius), scale);
    }
    vec4 glass() {
        vec2 p = pos;
        float r = vRadius * uScale;
        float sd = sdRoundRect(p, vRect, r);
        float t = max(-sd, 0.0);
        Inst m = inst(vInst + 1);
        float bezel = min(m.rect.x * uScale, min(vRect.z, vRect.w) * 0.5);
        float refraction = 1.6 * bezel;
        float rimWidth = 1.0 * uScale;
        vec2 n = vec2(0.0);
        if (t < bezel || t < 3.0 * rimWidth) { n = glassNormal(p, vRect, r); }
        vec2 q = p;
        if (t < bezel) { q -= n * (refraction * glassLens(t, bezel)); }
        vec3 c = backdropAt(q).rgb;
        vec4 tone = vUV.x > 0.5 ? vec4(0.125, 1.082, 1.0, 1.0)
                  : (uDark > 0.5 ? vec4(m.color.x, m.color.y, 2.0, 2.2) : vec4(m.rect.z, 1.0, 1.2, 1.0));
        c = glassTone(c, tone);
        c += (vColor.rgb - c) * vColor.a;
        if (t < 3.0 * rimWidth) {
            float rr = t / rimWidth;
            float a = m.rect.y * exp(-rr * rr);
            float l = abs(n.y);
            c += (l - c) * a;
        }
        return vec4(c, cover(sd, uScale));
    }
    vec4 fitRadii(vec2 size, vec4 r) {
        float f = 1.0;
        if (r.x + r.y > size.x) { f = min(f, size.x / (r.x + r.y)); }
        if (r.w + r.z > size.x) { f = min(f, size.x / (r.w + r.z)); }
        if (r.x + r.w > size.y) { f = min(f, size.y / (r.x + r.w)); }
        if (r.y + r.z > size.y) { f = min(f, size.y / (r.y + r.z)); }
        return max(r * f, vec4(0.0));
    }
    vec3 unpackRGB(float v) {
        uint p = uint(roundEven(v));
        return vec3(float((p >> 16) & 255u), float((p >> 8) & 255u), float(p & 255u)) / 255.0;
    }
    vec3 toLinear(vec3 c) { vec3 a = abs(c); return sign(c) * mix(pow((a + 0.055) / 1.055, vec3(2.4)), a / 12.92, lessThanEqual(a, vec3(0.04045))); }
    vec3 toSRGB(vec3 c) { vec3 a = abs(c); return sign(c) * mix(1.055 * pow(a, vec3(1.0 / 2.4)) - 0.055, a * 12.92, lessThanEqual(a, vec3(0.0031308))); }
    vec3 cbrt3(vec3 v) { return sign(v) * pow(abs(v), vec3(1.0 / 3.0)); }
    vec3 oklab(vec3 srgb) {
        vec3 c = toLinear(srgb);
        vec3 lms = cbrt3(vec3(0.4122214708 * c.r + 0.5363325363 * c.g + 0.0514459929 * c.b,
                              0.2119034982 * c.r + 0.6806995451 * c.g + 0.1073969566 * c.b,
                              0.0883024619 * c.r + 0.2817188376 * c.g + 0.6299787005 * c.b));
        return vec3(0.2104542553 * lms.x + 0.7936177850 * lms.y - 0.0040720468 * lms.z,
                    1.9779984951 * lms.x - 2.4285922050 * lms.y + 0.4505937099 * lms.z,
                    0.0259040371 * lms.x + 0.7827717662 * lms.y - 0.8086757660 * lms.z);
    }
    vec3 fromOklab(vec3 lab) {
        vec3 lms = vec3(lab.x + 0.3963377774 * lab.y + 0.2158037573 * lab.z,
                        lab.x - 0.1055613458 * lab.y - 0.0638541728 * lab.z,
                        lab.x - 0.0894841775 * lab.y - 1.2914855480 * lab.z);
        lms = lms * lms * lms;
        return toSRGB(vec3(4.0767416621 * lms.x - 3.3077115913 * lms.y + 0.2309699292 * lms.z,
                           -1.2684380046 * lms.x + 2.6097574011 * lms.y - 0.3413193965 * lms.z,
                           -0.0041960863 * lms.x - 0.7034186147 * lms.y + 1.7076147010 * lms.z));
    }
    vec4 premul(vec4 c) { return vec4(c.rgb * c.a, c.a); }
    vec4 stopsAt(int at, int n, float t, bool lab) {
        if (n <= 0) { return vec4(0.0); }
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
        float alpha = mix(a.rect.a, b.rect.a, f);
        if (!lab) { return vec4(mix(a.rect.rgb * a.rect.a, b.rect.rgb * b.rect.a, f), alpha); }
        if (alpha <= 0.0) { return vec4(0.0); }
        return vec4(saturate(fromOklab(mix(a.color.yzw * a.rect.a, b.color.yzw * b.rect.a, f) / alpha)) * alpha, alpha);
    }
    vec4 paintAt(vec2 p, float mode, vec4 c1, vec4 c2, vec4 g, float scale, int at, int n) {
        if (mode < 0.5) { return premul(c1); }
        if (mode > 3.5) {
            float t;
            if (mode < 5.5) {
                vec2 d = g.zw - g.xy;
                t = dot(p - g.xy, d) / max(dot(d, d), 0.0001);
            } else if (mode < 7.5) {
                vec2 d = p - g.xy;
                t = (atan(d.x, -d.y) - g.z) * 0.15915494309189535;
                t -= floor(t);
            } else {
                t = length(p - g.xy) / max(g.z, 0.0001);
            }
            return stopsAt(at, n, t, (mode > 4.5 && mode < 5.5) || (mode > 6.5 && mode < 7.5) || mode > 8.5);
        }
        if (mode < 2.5) {
            vec2 d = g.zw - g.xy;
            float t = saturate(dot(p - g.xy, d) / max(dot(d, d), 0.0001));
            float a = mix(c1.a, c2.a, t);
            if (mode < 1.5) { return vec4(mix(c1.rgb * c1.a, c2.rgb * c2.a, t), a); }
            if (a <= 0.0) { return vec4(0.0); }
            vec3 lab = mix(oklab(c1.rgb) * c1.a, oklab(c2.rgb) * c2.a, t);
            return vec4(saturate(fromOklab(lab / a)) * a, a);
        }
        float s = dot(p, g.xy);
        float phase = s - g.w * floor(s / g.w);
        float cov = cover(min(max(-phase, phase - g.z), g.w - phase), scale);
        return premul(c1) * cov + premul(c2) * (1.0 - cov);
    }
    float dash(vec2 q, vec2 size, vec4 w, float scale) {
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
    vec2 erf2(vec2 x) {
        vec2 s = sign(x), a = abs(x);
        x = 1.0 + (0.278393 + (0.230389 + 0.078108 * (a * a)) * a) * a;
        x *= x;
        return s - s / (x * x);
    }
    float gaussian(float x, float sigma) { return exp(-(x * x) / (2.0 * sigma * sigma)) / (2.50662827463 * sigma); }
    float shadowX(float x, float y, float sigma, float corner, vec2 h, ContCorner cc) {
        float v = h.y - abs(y);
        float curved = corner > 0.0 && v < cc.e ? h.x - contInset(cc, v) : h.x;
        vec2 integral = 0.5 + 0.5 * erf2((x + vec2(-curved, curved)) * (sqrt(0.5) / sigma));
        return integral.y - integral.x;
    }
    float boxShadow(vec2 p, vec2 size, float sigma, vec4 radii) {
        float corner = max(max(radii.x, radii.y), max(radii.z, radii.w));
        ContCorner cc = contCorner(corner, corner, corner, size.x, size.y);
        vec2 h = size * 0.5;
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
    vec4 box() {
        float scale = uScale;
        vec2 size = 2.0 * vExtent;
        vec2 p = vLocal + vExtent;
        vec4 rad = vR4;
        float flags = vE4.w;
        bool borderPaint = flags > 63.5;
        if (borderPaint) { flags -= 64.0; }
        bool mask = flags > 31.5;
        if (mask) { flags -= 32.0; }
        bool dashed = flags > 15.5;
        if (dashed) { flags -= 16.0; }
        float outer = mask ? texture(uAtlasTex, (rad.xy + p * uAtlasScale) / uAtlas).r : shapeCover(p, size, rad, scale);
        vec4 pc = paintAt(p, flags, vColor, vec4(unpackRGB(vUV.x), vUV.y), vG4, scale, vInst + 2, flags > 3.5 ? int(vUV.x + 0.5) : 0);
        vec4 res = (borderPaint ? premul(vColor) : pc) * outer;
        vec4 bw = vB4;
        if (!mask && any(greaterThan(bw, vec4(0.0)))) {
            vec2 isz = size - bw.yz - bw.wx;
            float inner = 0.0;
            if (isz.x > 0.0 && isz.y > 0.0) {
                vec4 ir = max(rad - vec4(max(bw.x, bw.w), max(bw.x, bw.y), max(bw.z, bw.y), max(bw.z, bw.w)), vec4(0.0));
                inner = shapeCover(p - bw.wx, isz, fitRadii(isz, ir), scale);
            }
            float bc = saturate(outer - inner);
            if (dashed) { bc *= dash(p, size, bw, scale); }
            vec4 b = (borderPaint ? pc : vec4(1.0)) * vec4(unpackRGB(vE4.x) * vE4.z, vE4.z) * bc;
            res = b + res * (1.0 - b.a);
        }
        return vec4(res.a > 0.0 ? res.rgb / res.a : vec3(0.0), res.a);
    }
    vec4 boxShadowQuad(float scale) {
        vec2 size = 2.0 * vExtent;
        vec2 p = vLocal + vExtent;
        float sigma = vUV.x;
        vec4 other = vB4;
        vec4 orad = vec4(vE4.x, vE4.z, vE4.w, vG4.x);
        bool hasOther = other.z > 0.0 && other.w > 0.0;
        float a;
        if (vUV.y < 0.5) {
            float e = 3.0 * sigma;
            vec2 ss = size - 2.0 * e;
            a = sigma > 0.0 ? boxShadow(p - e, ss, sigma, vR4) : shapeCover(p - e, ss, vR4, scale);
            if (hasOther) { a *= 1.0 - shapeCover(p - other.xy, other.zw, orad, scale); }
        } else {
            float hole = 0.0;
            if (hasOther) { hole = sigma > 0.0 ? boxShadow(p - other.xy, other.zw, sigma, orad) : shapeCover(p - other.xy, other.zw, orad, scale); }
            a = shapeCover(p, size, vR4, scale) * (1.0 - hole);
        }
        return vec4(vColor.rgb, vColor.a * a);
    }
    vec4 quadPaint() {
        if (vKind > 14.5) { return boxShadowQuad(uScale); }
        if (vKind > 13.5) { return box(); }
        if (vKind > 1.5 && (vKind < 2.5 || vKind > 11.5)) {
            float layer = floor(vColor.z + 0.5);
            vec4 c = vKind > 12.5 ? texelFetch(uImages, ivec3(floor(vUV * uAtlas), int(layer)), 0) : texture(uImages, vec3(vUV, layer));
            if (vKind > 11.5 && vKind < 12.5) { c.rgb = vec3(dot(c.rgb, vec3(0.2126, 0.7152, 0.0722))); }
            float radius = vRadius;
            if (radius < -0.5) { c.rgb *= unpackRGB(-radius - 1.0); radius = 0.0; }
            float d = imageValue(vLocal + vExtent, 2.0 * vExtent, radius);
            float aa = max(fwidth(d), 1e-4);
            float a = 1.0 - smoothstep(-0.5 * aa, 0.5 * aa, d);
            return vec4(c.rgb / max(c.a, 1e-4), c.a * vColor.a * a);
        }
        if (vKind > 9.5) {
            uint p = uint(roundEven(vUV.x));
            vec4 to = vec4(float((p >> 16) & 255u), float((p >> 8) & 255u), float(p & 255u), 0.0) / 255.0;
            to.a = vUV.y;
            float t = vKind > 10.5 ? (vLocal.y + vExtent.y) / max(2.0 * vExtent.y, 1.0) : (vLocal.x + vExtent.x) / max(2.0 * vExtent.x, 1.0);
            vec4 c = mix(vColor, to, clamp(t, 0.0, 1.0));
            return vec4(c.rgb, c.a * rectCover(vRadius, uScale));
        }
        if (vKind > 8.5) {
            vec2 h = vExtent - vRadius;
            vec2 a = -h * vUV;
            vec2 pa = vLocal - a, ba = -2.0 * a;
            float t = clamp(dot(pa, ba) / max(dot(ba, ba), 1e-4), 0.0, 1.0);
            float d = length(pa - ba * t) - vRadius;
            return vec4(vColor.rgb, vColor.a * cover(d, uScale));
        }
        if (vKind > 6.5) {
            vec3 c = backdropAt(pos).rgb;
            float w = 1.0;
            if (vKind < 7.5) {
                vec2 lp = vLocal + vExtent;
                float t = clamp(vColor.z + vColor.x * lp.x + vColor.y * lp.y, 0.0, 1.0);
                w = clamp(vUV.x + vUV.y * t, 0.0, 1.0) * vColor.a;
            } else {
                float l = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
                c = clamp(l + (c - l) * 1.25 + 0.03, 0.0, 1.0);
                c += (vColor.rgb - c) * vColor.a;
            }
            vec2 q = abs(vLocal) - (vExtent - vRadius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - vRadius;
            return vec4(c, w * cover(d, uScale));
        }
        if (vKind > 5.5) {
            float ty = (vLocal.y + vExtent.y) / max(vRect.w / uScale, 1.0);
            return vec4(vColor.rgb, (vColor.a + (vUV.x - vColor.a) * ty) * rectCover(vRadius, uScale));
        }
        if (vKind > 4.5) { return glass(); }
        if (vKind > 3.5) {
            float t = vUV.x;
            vec2 p = vLocal + vExtent, size = 2.0 * vExtent;
            float a = shapeCover(p, size, vec4(vRadius), uScale) * (1.0 - shapeCover(p - t, size - 2.0 * t, vec4(max(vRadius - t, 0.0)), uScale));
            return vec4(vColor.rgb, vColor.a * a);
        }
        if (vKind > 2.5) {
            float blur = vUV.x;
            vec2 q = abs(vLocal) - (vExtent - blur - vRadius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - vRadius;
            float a = 1.0 - smoothstep(-blur, blur, d);
            return vec4(vColor.rgb, vColor.a * a);
        }
        if (vKind > 0.5) {
            return vec4(vColor.rgb, vColor.a * texture(uAtlasTex, vUV).r);
        }
        return vec4(vColor.rgb, vColor.a * rectCover(vRadius, uScale));
    }
    void main() {
        pos = vec2(gl_FragCoord.x, uFlip > 0.0 ? uTargetH - gl_FragCoord.y : gl_FragCoord.y);
        if (pos.x < vClip.x || pos.y < vClip.y || pos.x >= vClip.z || pos.y >= vClip.w) {
            discard;
        }
        vec4 c = quadPaint();
        if (vMrect.z != 0.0) {
            vec2 t = (pos / uScale - vMrect.xy) / vec2(abs(vMrect.z), vMrect.w);
            float m = 0.0;
            if (vMslot.z < 0.5) {
                vec4 r = vec4(vMslot.x, vMslot.y, vMslot.z < -0.5 ? -1.0 - vMslot.z : 0.0, vMslot.w);
                m = contCoverage(pos, vec4(vMrect.xy, abs(vMrect.z), vMrect.w) * uScale, r * uScale);
            } else if (t.x >= 0.0 && t.y >= 0.0 && t.x <= 1.0 && t.y <= 1.0) {
                float page = floor(vMslot.x / 4096.0);
                m = texture(uImages, vec3((vec2(vMslot.x - page * 4096.0, vMslot.y) + t * vMslot.zw) / uAtlas, page)).a;
            }
            c.a *= vMrect.z < 0.0 ? 1.0 - m : m;
        }
        fragColor = c;
    }
    GLSL;

    /** The backdrop's blur: a fullscreen triangle, a 1-D Gaussian along uDir (texels of the source). */
    public const BLUR_VERTEX = <<<'GLSL'
    #version 330 core
    out vec2 vUV;
    void main() {
        vec2 uv = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
        gl_Position = vec4(uv * 2.0 - 1.0, 0.0, 1.0);
        vUV = uv;
    }
    GLSL;

    public const BLUR_FRAGMENT = <<<'GLSL'
    #version 330 core
    uniform sampler2D uSrc;
    uniform vec2 uDir;
    uniform float uSigma;
    in vec2 vUV;
    out vec4 fragColor;
    void main() {
        vec2 texel = 1.0 / vec2(textureSize(uSrc, 0));
        vec4 sum = vec4(0.0); float wsum = 0.0;
        int taps = int(ceil(uSigma * 3.0));
        for (int i = -taps; i <= taps; i++) {
            float w = exp(-float(i * i) / (2.0 * uSigma * uSigma));
            sum += texture(uSrc, vUV + uDir * float(i) * texel) * w;
            wsum += w;
        }
        fragColor = sum / wsum;
    }
    GLSL;

    /**
     * The tail of a shader's draws (Code's MSL TAIL, as GLSL): its own vertex
     * stage, which places the quads as VERTEX does, and the fragment stage
     * around the shader's library (Glsl::fragment's shaderFragment), which
     * colors a rectangle (kind 16) or a glyph's mask (17) with it: the pixel
     * in the target's pixels from the top, the point in points from the
     * corner of the box it fills; its premultiplied color is scaled by the
     * quad's coverage, mask and opacity. Its images are T0 to T3, units 5 to 8.
     */
    public const SHADER_VERTEX = <<<'GLSL'
    uniform samplerBuffer uXforms;
    uniform int uFrom;
    out vec2 vLocal;
    out vec2 vUV;
    out vec2 vBox;
    flat out vec2 vExtent;
    flat out float vAlpha;
    flat out float vRadius;
    flat out float vKind;
    flat out vec4 vClip;
    flat out vec4 vMrect; flat out vec4 vMslot;
    void main() {
        Inst i = inst(uFrom + gl_InstanceID);
        vLocal = vec2(0.0); vUV = vec2(0.0); vBox = vec2(0.0); vExtent = vec2(0.0); vAlpha = 0.0; vRadius = 0.0; vKind = 0.0;
        vClip = vec4(0.0); vMrect = vec4(0.0); vMslot = vec4(0.0);
        if (i.params.y < 0.0) { gl_Position = vec4(0.0, 0.0, 0.0, 1.0); return; }   // hidden (DisplayList::cull)
        float entry = floor(i.params.y / 32.0);
        float kind = i.params.y - entry * 32.0;
        int e = int(entry);
        vec4 abcd = texelFetch(uXforms, e * 4), xygh = texelFetch(uXforms, e * 4 + 1);
        vec2 corner = vec2(float(gl_VertexID & 1), float(gl_VertexID >> 1));
        bool glyph = kind > 16.5;
        float pad = entry > 0.5 && !glyph ? 1.0 : 0.0;
        vec2 p = i.rect.xy - pad + corner * (i.rect.zw + 2.0 * pad);
        float w = xygh.z * p.x + xygh.w * p.y + 1.0;
        vec2 q = vec2(abcd.x * p.x + abcd.z * p.y + xygh.x, abcd.y * p.x + abcd.w * p.y + xygh.y);
        gl_Position = vec4(q.x / uViewport.x * 2.0 - w, (w - q.y / uViewport.y * 2.0) * uFlip, 0.0, w);
        vLocal = (corner - 0.5) * (i.rect.zw + 2.0 * pad);
        vExtent = i.rect.zw * 0.5;
        vUV = glyph ? (i.params.zw + corner * i.rect.zw * uAtlasScale) / uAtlas : vec2(0.0);
        vBox = glyph ? p - i.color.yz : p - i.rect.xy;
        vAlpha = i.color.a;
        vRadius = i.params.x;
        vKind = kind;
        vClip = vec4(i.clip.xy, i.clip.xy + i.clip.zw) * uScale;
        vMrect = texelFetch(uXforms, e * 4 + 2); vMslot = texelFetch(uXforms, e * 4 + 3);
    }
    GLSL;

    /** The fragment stage's declarations, before the shader's library. */
    public const SHADER_FRAGMENT_HEAD = <<<'GLSL'
    uniform sampler2D uAtlasTex;
    uniform sampler2DArray uImages;
    in vec2 vLocal;
    in vec2 vUV;
    in vec2 vBox;
    flat in vec2 vExtent;
    flat in float vAlpha;
    flat in float vRadius;
    flat in float vKind;
    flat in vec4 vClip;
    flat in vec4 vMrect; flat in vec4 vMslot;
    out vec4 fragColor;
    GLSL;

    /** The fragment stage's main, after the shader's library. */
    public const SHADER_FRAGMENT_MAIN = <<<'GLSL'
    void main() {
        vec2 pos = vec2(gl_FragCoord.x, uFlip > 0.0 ? uTargetH - gl_FragCoord.y : gl_FragCoord.y);
        if (pos.x < vClip.x || pos.y < vClip.y || pos.x >= vClip.z || pos.y >= vClip.w) {
            discard;
        }
        float cov;
        if (vKind > 16.5) {
            cov = texture(uAtlasTex, vUV).r;
        } else {
            vec2 q = abs(vLocal) - (vExtent - vRadius);
            float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - vRadius;
            cov = 1.0 - smoothstep(-0.5, 0.5, d);
        }
        if (vMrect.z != 0.0) {
            vec2 t = (pos / uScale - vMrect.xy) / vec2(abs(vMrect.z), vMrect.w);
            float m = 0.0;
            if (vMslot.z < 0.5) {
                vec2 inside = min(t, 1.0 - t) * vec2(abs(vMrect.z), vMrect.w) * uScale;
                m = clamp(min(inside.x, inside.y) + 0.5, 0.0, 1.0);
            } else if (t.x >= 0.0 && t.y >= 0.0 && t.x <= 1.0 && t.y <= 1.0) {
                float page = floor(vMslot.x / 4096.0);
                m = texture(uImages, vec3((vec2(vMslot.x - page * 4096.0, vMslot.y) + t * vMslot.zw) / uAtlas, page)).a;
            }
            cov *= vMrect.z < 0.0 ? 1.0 - m : m;
        }
        vec4 c = shaderFragment(vec4(pos, 0.0, 1.0), vBox);   // premultiplied
        float a = clamp(c.a, 0.0, 1.0);
        fragColor = vec4(a > 0.0 ? c.rgb / a : vec3(0.0), a * cov * vAlpha);
    }
    GLSL;
}
