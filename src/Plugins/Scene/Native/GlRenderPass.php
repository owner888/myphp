<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene\Native;

use FFI;
use MyPHP\App;
use MyPHP\Native\Gl;
use MyPHP\Plugins\Scene\{Entity, Mesh, Scene};
use MyPHP\UI\Image;
use RuntimeException;
use WeakMap;

/**
 * High-performance OpenGL 3.3 Core / OpenGL ES 3.0 3D RenderPass for Linux & Android:
 * - 24-bit hardware depth buffer (GL_DEPTH_COMPONENT24 Renderbuffer)
 * - Zero-allocation per-frame: meshes & instances stay resident in VRAM (GL_STATIC_DRAW VBO/EBO)
 * - Blinn-Phong directional lighting + hardware texture mapping (GL_LINEAR & GL_NEAREST)
 * - Hardware GPU Instancing via glVertexAttribDivisor + glDrawElementsInstanced
 * - Automatic Y-flip when rendering into Canvas FBOs so top-row matches CanvasAtlas
 */
final class GlRenderPass
{
    private const TEXTURE_2D       = 0x0DE1;
    private const TEXTURE0         = 0x84C0;
    private const TEXTURE_MIN_FILTER = 0x2801;
    private const TEXTURE_MAG_FILTER = 0x2800;
    private const TEXTURE_WRAP_S   = 0x2802;
    private const TEXTURE_WRAP_T   = 0x2803;
    private const LINEAR           = 0x2601;
    private const NEAREST          = 0x2600;
    private const REPEAT           = 0x2901;

    private const RGBA8            = 0x8058;
    private const RGBA             = 0x1908;
    private const UNSIGNED_BYTE    = 0x1401;
    private const UNSIGNED_INT     = 0x1405;
    private const FLOAT            = 0x1406;
    private const UNPACK_ALIGNMENT = 0x0CF5;

    private const ARRAY_BUFFER         = 0x8892;
    private const ELEMENT_ARRAY_BUFFER = 0x8893;
    private const FRAMEBUFFER          = 0x8D40;
    private const RENDERBUFFER         = 0x8D41;
    private const DEPTH_COMPONENT24    = 0x81A6;
    private const DEPTH_ATTACHMENT     = 0x8D00;

    private const DEPTH_TEST       = 0x0B71;
    private const CULL_FACE        = 0x0B44;
    private const BLEND            = 0x0BE2;
    private const LESS             = 0x0201;
    private const BACK             = 0x0405;
    private const CW               = 0x0900;
    private const CCW              = 0x0901;
    private const TRIANGLES        = 0x0004;
    private const COLOR_BUFFER_BIT = 0x4000;
    private const DEPTH_BUFFER_BIT = 0x0100;

    private const VERTEX_SHADER    = 0x8B31;
    private const FRAGMENT_SHADER  = 0x8B30;
    private const COMPILE_STATUS   = 0x8B81;
    private const LINK_STATUS      = 0x8B82;

    private static ?self $instance = null;

    private FFI $g;
    private int $program = 0;
    private int $vao = 0;
    private int $whiteTexture = 0;
    /** @var array<string, int> Uniform locations */
    private array $u = [];
    /** @var array<string, int> Cached depth renderbuffers by "widthxheight" */
    private array $depthRbos = [];
    /** @var WeakMap<Image, array{0: int, 1: int}> Cached [glTexId, version] */
    private WeakMap $gpuTextures;

    public const VERTEX_GLSL = <<<'GLSL'
    layout(location = 0) in vec3 aPos;
    layout(location = 1) in vec3 aNormal;
    layout(location = 2) in vec2 aUv;
    layout(location = 3) in vec4 aColor;

    // Instanced attributes (locations 4..8)
    layout(location = 4) in vec4 iModelC0;
    layout(location = 5) in vec4 iModelC1;
    layout(location = 6) in vec4 iModelC2;
    layout(location = 7) in vec4 iModelC3;
    layout(location = 8) in vec4 iColor;

    uniform mat4 uMvp;
    uniform mat4 uModel;
    uniform vec4 uLightColor;    // w > 0.5 = instanced
    uniform vec4 uMaterialColor;
    uniform float uFlipY;        // -1.0 for Canvas FBOs, 1.0 for normal

    out vec3 vWorldPos;
    out vec3 vNormal;
    out vec2 vUv;
    out vec4 vColor;

    void main() {
        vec4 clipPos;
        if (uLightColor.w > 0.5) {
            mat4 instModel = mat4(iModelC0, iModelC1, iModelC2, iModelC3);
            vec4 world = instModel * vec4(aPos, 1.0);
            clipPos = uMvp * world;
            vWorldPos = world.xyz;
            vNormal = (instModel * vec4(aNormal, 0.0)).xyz;
            vColor = aColor * uMaterialColor * iColor;
        } else {
            clipPos = uMvp * vec4(aPos, 1.0);
            vWorldPos = (uModel * vec4(aPos, 1.0)).xyz;
            vNormal = (uModel * vec4(aNormal, 0.0)).xyz;
            vColor = aColor * uMaterialColor;
        }
        // Mat4::perspective produces z in [0, w] (Metal/D3D convention);
        // map to OpenGL's [-w, w] clip-space depth range: z_gl = z * 2.0 - w
        clipPos.z = clipPos.z * 2.0 - clipPos.w;
        clipPos.y *= uFlipY;
        gl_Position = clipPos;
        vUv = aUv;
    }
    GLSL;

    public const FRAGMENT_GLSL = <<<'GLSL'
    in vec3 vWorldPos;
    in vec3 vNormal;
    in vec2 vUv;
    in vec4 vColor;

    uniform vec4 uLightDir;
    uniform vec4 uLightColor;
    uniform vec4 uAmbientColor;
    uniform vec4 uCameraPos; // xyz = pos, w = shininess
    uniform vec4 uPointPos0; // xyz = pos, w = radius
    uniform vec4 uPointCol0; // rgb = color, w = intensity
    uniform vec4 uPointPos1;
    uniform vec4 uPointCol1;
    uniform float uPointCount;
    uniform sampler2D uTex;

    out vec4 fragColor;

    void main() {
        vec4 texColor = texture(uTex, vUv);
        vec4 baseColor = vColor * texColor;
        if (baseColor.a < 0.01) {
            discard;
        }

        vec3 N = normalize(vNormal);
        vec3 L = normalize(-uLightDir.xyz);
        vec3 V = normalize(uCameraPos.xyz - vWorldPos);
        vec3 H = normalize(L + V);

        vec3 ambient  = uAmbientColor.rgb * baseColor.rgb;
        float diff    = max(dot(N, L), 0.0);
        vec3 diffuse  = diff * uLightColor.rgb * baseColor.rgb;
        float spec    = pow(max(dot(N, H), 0.0), max(uCameraPos.w, 1.0));
        vec3 specular = spec * uLightColor.rgb * 0.35;

        vec3 finalColor = ambient + diffuse + specular;

        if (uPointCount > 0.5) {
            vec3 toL = uPointPos0.xyz - vWorldPos;
            float d = length(toL);
            float r = max(uPointPos0.w, 0.1);
            if (d < r) {
                vec3 pL = toL / max(d, 1e-4);
                float att = (1.0 - d / r);
                att = att * att * uPointCol0.w;
                float pDiff = max(dot(N, pL), 0.0);
                vec3 pH = normalize(pL + V);
                float pSpec = pow(max(dot(N, pH), 0.0), max(uCameraPos.w, 1.0));
                finalColor += att * uPointCol0.rgb * (pDiff * baseColor.rgb + pSpec * 0.4);
            }
        }
        if (uPointCount > 1.5) {
            vec3 toL = uPointPos1.xyz - vWorldPos;
            float d = length(toL);
            float r = max(uPointPos1.w, 0.1);
            if (d < r) {
                vec3 pL = toL / max(d, 1e-4);
                float att = (1.0 - d / r);
                att = att * att * uPointCol1.w;
                float pDiff = max(dot(N, pL), 0.0);
                vec3 pH = normalize(pL + V);
                float pSpec = pow(max(dot(N, pH), 0.0), max(uCameraPos.w, 1.0));
                finalColor += att * uPointCol1.rgb * (pDiff * baseColor.rgb + pSpec * 0.4);
            }
        }

        if (uLightDir.w > 0.01) {
            float ndotv = max(dot(N, V), 0.0);
            float rim = pow(1.0 - ndotv, 3.0) * uLightDir.w;
            finalColor += rim * vec3(0.35, 0.9, 1.0);
        }

        if (uAmbientColor.w > 0.0001) {
            float camDist = length(uCameraPos.xyz - vWorldPos);
            float fogFactor = clamp(exp(-pow(camDist * uAmbientColor.w, 2.0)), 0.0, 1.0);
            vec3 horizon = uAmbientColor.rgb * 0.6;
            finalColor = mix(horizon, finalColor, fogFactor);
        }

        fragColor = vec4(finalColor, baseColor.a);
    }
    GLSL;

    public function __construct()
    {
        $this->g = Gl::ffi();
        $this->gpuTextures = new WeakMap();
        $this->initShaderAndVao();
        $this->initWhiteTexture();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function initShaderAndVao(): void
    {
        $g = $this->g;
        $header = App::isAndroid()
            ? "#version 300 es\nprecision highp float;\nprecision highp int;\nprecision highp sampler2D;\n"
            : "#version 330 core\n";

        $p = ($g->epoxy_glCreateProgram)();
        foreach ([[self::VERTEX_SHADER, $header . self::VERTEX_GLSL], [self::FRAGMENT_SHADER, $header . self::FRAGMENT_GLSL]] as [$type, $src]) {
            $s = ($g->epoxy_glCreateShader)($type);
            $len = strlen($src);
            $str = $g->new('char[' . ($len + 1) . ']', false);
            FFI::memcpy($str, $src, $len);
            $ptrs = $g->new('const char*[1]');
            $ptrs[0] = $g->cast('const char*', FFI::addr($str[0]));
            ($g->epoxy_glShaderSource)($s, 1, $ptrs, null);
            ($g->epoxy_glCompileShader)($s);
            FFI::free($str);

            $ok = $g->new('int[1]');
            ($g->epoxy_glGetShaderiv)($s, self::COMPILE_STATUS, $ok);
            if ($ok[0] === 0) {
                $log = $g->new('char[4096]');
                ($g->epoxy_glGetShaderInfoLog)($s, 4096, null, $log);
                throw new RuntimeException('GlRenderPass shader compile failed: ' . FFI::string($log));
            }
            ($g->epoxy_glAttachShader)($p, $s);
            ($g->epoxy_glDeleteShader)($s);
        }

        ($g->epoxy_glLinkProgram)($p);
        $ok = $g->new('int[1]');
        ($g->epoxy_glGetProgramiv)($p, self::LINK_STATUS, $ok);
        if ($ok[0] === 0) {
            $log = $g->new('char[4096]');
            ($g->epoxy_glGetProgramInfoLog)($p, 4096, null, $log);
            throw new RuntimeException('GlRenderPass program link failed: ' . FFI::string($log));
        }
        $this->program = $p;

        foreach (['uMvp', 'uModel', 'uLightDir', 'uLightColor', 'uAmbientColor', 'uMaterialColor', 'uCameraPos', 'uPointPos0', 'uPointCol0', 'uPointPos1', 'uPointCol1', 'uPointCount', 'uFlipY', 'uTex'] as $name) {
            $this->u[$name] = ($g->epoxy_glGetUniformLocation)($p, $name);
        }

        $ids = $g->new('unsigned int[1]');
        ($g->epoxy_glGenVertexArrays)(1, $ids);
        $this->vao = (int)$ids[0];
    }

    private function initWhiteTexture(): void
    {
        $g = $this->g;
        $ids = $g->new('unsigned int[1]');
        ($g->epoxy_glGenTextures)(1, $ids);
        $this->whiteTexture = (int)$ids[0];
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $this->whiteTexture);
        ($g->epoxy_glPixelStorei)(self::UNPACK_ALIGNMENT, 1);
        $white = "\xff\xff\xff\xff";
        ($g->epoxy_glTexImage2D)(self::TEXTURE_2D, 0, self::RGBA8, 1, 1, 0, self::RGBA, self::UNSIGNED_BYTE, $white);
        ($g->epoxy_glTexParameteri)(self::TEXTURE_2D, self::TEXTURE_MIN_FILTER, self::NEAREST);
        ($g->epoxy_glTexParameteri)(self::TEXTURE_2D, self::TEXTURE_MAG_FILTER, self::NEAREST);
    }

    private function textureFor(?Image $img): int
    {
        $g = $this->g;
        if ($img === null) {
            return $this->whiteTexture;
        }

        [$tex, $ver] = $this->gpuTextures[$img] ?? [0, -1];
        if ($tex !== 0 && $ver === $img->version) {
            $filter = $img->pixelated ? self::NEAREST : self::LINEAR;
            ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $tex);
            ($g->epoxy_glTexParameteri)(self::TEXTURE_2D, self::TEXTURE_MIN_FILTER, $filter);
            ($g->epoxy_glTexParameteri)(self::TEXTURE_2D, self::TEXTURE_MAG_FILTER, $filter);
            return $tex;
        }

        if ($tex !== 0) {
            $ids = $g->new('unsigned int[1]');
            $ids[0] = $tex;
            ($g->epoxy_glDeleteTextures)(1, $ids);
        }

        $ids = $g->new('unsigned int[1]');
        ($g->epoxy_glGenTextures)(1, $ids);
        $newTex = (int)$ids[0];
        $filter = $img->pixelated ? self::NEAREST : self::LINEAR;

        ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $newTex);
        ($g->epoxy_glPixelStorei)(self::UNPACK_ALIGNMENT, 1);
        ($g->epoxy_glTexImage2D)(self::TEXTURE_2D, 0, self::RGBA8, $img->w, $img->h, 0, self::RGBA, self::UNSIGNED_BYTE, $g->cast('void*', $img->address()));
        ($g->epoxy_glTexParameteri)(self::TEXTURE_2D, self::TEXTURE_MIN_FILTER, $filter);
        ($g->epoxy_glTexParameteri)(self::TEXTURE_2D, self::TEXTURE_MAG_FILTER, $filter);
        ($g->epoxy_glTexParameteri)(self::TEXTURE_2D, self::TEXTURE_WRAP_S, self::REPEAT);
        ($g->epoxy_glTexParameteri)(self::TEXTURE_2D, self::TEXTURE_WRAP_T, self::REPEAT);
        $img->uploaded();

        $this->gpuTextures[$img] = [$newTex, $img->version];
        return $newTex;
    }

    private function depthRbo(int $w, int $h): int
    {
        $key = "{$w}x{$h}";
        if (isset($this->depthRbos[$key])) {
            return $this->depthRbos[$key];
        }

        $g = $this->g;
        if (count($this->depthRbos) > 8) {
            foreach ($this->depthRbos as $old) {
                $ids = $g->new('unsigned int[1]');
                $ids[0] = $old;
                ($g->epoxy_glDeleteRenderbuffers)(1, $ids);
            }
            $this->depthRbos = [];
        }

        $ids = $g->new('unsigned int[1]');
        ($g->epoxy_glGenRenderbuffers)(1, $ids);
        $rbo = (int)$ids[0];
        ($g->epoxy_glBindRenderbuffer)(self::RENDERBUFFER, $rbo);
        ($g->epoxy_glRenderbufferStorage)(self::RENDERBUFFER, self::DEPTH_COMPONENT24, $w, $h);
        ($g->epoxy_glBindRenderbuffer)(self::RENDERBUFFER, 0);

        return $this->depthRbos[$key] = $rbo;
    }

    /**
     * Executes the 3D RenderPass into the currently bound $fbo.
     * $flipY = -1.0 when rendering into a Canvas FBO (so row 0 is top, matching CanvasAtlas).
     */
    public function render(Scene $scene, int $width, int $height, int $fbo, float $flipY = -1.0): void
    {
        $g = $this->g;
        $rbo = $this->depthRbo($width, $height);

        ($g->epoxy_glBindFramebuffer)(self::FRAMEBUFFER, $fbo);
        ($g->epoxy_glFramebufferRenderbuffer)(self::FRAMEBUFFER, self::DEPTH_ATTACHMENT, self::RENDERBUFFER, $rbo);

        ($g->epoxy_glViewport)(0, 0, $width, $height);
        ($g->epoxy_glEnable)(self::DEPTH_TEST);
        ($g->epoxy_glDepthFunc)(self::LESS);
        ($g->epoxy_glDepthMask)(1);
        ($g->epoxy_glEnable)(self::CULL_FACE);
        ($g->epoxy_glCullFace)(self::BACK);
        // Flipping Y inverts triangle winding in screen space
        ($g->epoxy_glFrontFace)($flipY < 0.0 ? self::CW : self::CCW);
        ($g->epoxy_glDisable)(self::BLEND);

        [$cr, $cg, $cb, $ca] = $scene->clearColor;
        ($g->epoxy_glClearColor)($cr, $cg, $cb, $ca);
        ($g->epoxy_glClearDepthf)(1.0);
        ($g->epoxy_glClear)(self::COLOR_BUFFER_BIT | self::DEPTH_BUFFER_BIT);

        ($g->epoxy_glUseProgram)($this->program);
        ($g->epoxy_glBindVertexArray)($this->vao);

        $aspect = (float)$width / max(1.0, (float)$height);
        $view = $scene->camera->viewMatrix();
        $proj = $scene->camera->projectionMatrix($aspect);
        $viewProj = $proj->multiply($view);

        $camPos = $scene->camera->position;
        $lDir = $scene->lightDirection->normalize();
        $lc = $scene->lightColor;
        $ac = $scene->ambientColor;

        $fogDensity = $scene->fogEnabled ? max(0.0, $scene->fogDensity) : 0.0;
        ($g->epoxy_glUniform4f)($this->u['uLightDir'], $lDir->x, $lDir->y, $lDir->z, 0.0);
        ($g->epoxy_glUniform4f)($this->u['uAmbientColor'], $ac[0], $ac[1], $ac[2], $fogDensity);
        ($g->epoxy_glUniform1f)($this->u['uFlipY'], $flipY);
        ($g->epoxy_glUniform1i)($this->u['uTex'], 0);

        $pl0 = $scene->pointLights[0] ?? null;
        $pl1 = $scene->pointLights[1] ?? null;
        $plCount = min(2, count($scene->pointLights));
        ($g->epoxy_glUniform1f)($this->u['uPointCount'], (float)$plCount);
        if ($pl0 !== null) {
            ($g->epoxy_glUniform4f)($this->u['uPointPos0'], $pl0->position->x, $pl0->position->y, $pl0->position->z, $pl0->radius);
            ($g->epoxy_glUniform4f)($this->u['uPointCol0'], $pl0->color[0], $pl0->color[1], $pl0->color[2], $pl0->intensity);
        }
        if ($pl1 !== null) {
            ($g->epoxy_glUniform4f)($this->u['uPointPos1'], $pl1->position->x, $pl1->position->y, $pl1->position->z, $pl1->radius);
            ($g->epoxy_glUniform4f)($this->u['uPointCol1'], $pl1->color[0], $pl1->color[1], $pl1->color[2], $pl1->intensity);
        }

        $matBuf = $g->new('float[16]');

        foreach ($scene->visibleEntities($viewProj) as $entity) {
            $mesh = $entity->mesh;
            $mesh->uploadGl($g);

            $mat = $entity->material;
            $tex = $this->textureFor($mat->texture);
            ($g->epoxy_glActiveTexture)(self::TEXTURE0);
            ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $tex);

            $isInstanced = $entity->instanceCount > 0;
            $model = $entity->modelMatrix();
            $mvp = $isInstanced ? $viewProj : $viewProj->multiply($model);

            FFI::memcpy($matBuf, $mvp->toBinary(), 64);
            ($g->epoxy_glUniformMatrix4fv)($this->u['uMvp'], 1, 0, $matBuf);

            FFI::memcpy($matBuf, $model->toBinary(), 64);
            ($g->epoxy_glUniformMatrix4fv)($this->u['uModel'], 1, 0, $matBuf);

            ($g->epoxy_glUniform4f)($this->u['uLightDir'], $lDir->x, $lDir->y, $lDir->z, $mat->rimStrength);
            ($g->epoxy_glUniform4f)($this->u['uLightColor'], $lc[0], $lc[1], $lc[2], $isInstanced ? 1.0 : 0.0);
            ($g->epoxy_glUniform4f)($this->u['uMaterialColor'], $mat->color[0], $mat->color[1], $mat->color[2], $mat->color[3]);
            ($g->epoxy_glUniform4f)($this->u['uCameraPos'], $camPos->x, $camPos->y, $camPos->z, $mat->shininess);

            // Bind Mesh VBO & EBO
            ($g->epoxy_glBindBuffer)(self::ARRAY_BUFFER, (int)$mesh->nativeVertexBuffer);
            ($g->epoxy_glBindBuffer)(self::ELEMENT_ARRAY_BUFFER, (int)$mesh->nativeIndexBuffer);

            $stride = Mesh::VERTEX_STRIDE;
            // 0: position (3f, offset 0)
            ($g->epoxy_glEnableVertexAttribArray)(0);
            ($g->epoxy_glVertexAttribPointer)(0, 3, self::FLOAT, 0, $stride, null);
            // 1: normal (3f, offset 12)
            ($g->epoxy_glEnableVertexAttribArray)(1);
            ($g->epoxy_glVertexAttribPointer)(1, 3, self::FLOAT, 0, $stride, $g->cast('void*', 12));
            // 2: uv (2f, offset 24)
            ($g->epoxy_glEnableVertexAttribArray)(2);
            ($g->epoxy_glVertexAttribPointer)(2, 2, self::FLOAT, 0, $stride, $g->cast('void*', 24));
            // 3: color (4f, offset 32)
            ($g->epoxy_glEnableVertexAttribArray)(3);
            ($g->epoxy_glVertexAttribPointer)(3, 4, self::FLOAT, 0, $stride, $g->cast('void*', 32));

            if ($isInstanced) {
                $entity->uploadInstancesGl($g);
                ($g->epoxy_glBindBuffer)(self::ARRAY_BUFFER, (int)$entity->nativeInstanceBuffer);
                $iStride = Entity::INSTANCE_STRIDE; // 80 bytes
                for ($attr = 4; $attr <= 8; $attr++) {
                    $offset = ($attr - 4) * 16;
                    ($g->epoxy_glEnableVertexAttribArray)($attr);
                    ($g->epoxy_glVertexAttribPointer)($attr, 4, self::FLOAT, 0, $iStride, $offset === 0 ? null : $g->cast('void*', $offset));
                    ($g->epoxy_glVertexAttribDivisor)($attr, 1);
                }
                ($g->epoxy_glDrawElementsInstanced)(self::TRIANGLES, $mesh->indexCount, self::UNSIGNED_INT, null, $entity->instanceCount);
                for ($attr = 4; $attr <= 8; $attr++) {
                    ($g->epoxy_glVertexAttribDivisor)($attr, 0);
                    ($g->epoxy_glDisableVertexAttribArray)($attr);
                }
            } else {
                ($g->epoxy_glDrawElements)(self::TRIANGLES, $mesh->indexCount, self::UNSIGNED_INT, null);
            }
        }

        // Clean up GL state for 2D quad pipeline
        for ($attr = 0; $attr <= 3; $attr++) {
            ($g->epoxy_glDisableVertexAttribArray)($attr);
        }
        ($g->epoxy_glBindBuffer)(self::ARRAY_BUFFER, 0);
        ($g->epoxy_glBindBuffer)(self::ELEMENT_ARRAY_BUFFER, 0);
        ($g->epoxy_glFramebufferRenderbuffer)(self::FRAMEBUFFER, self::DEPTH_ATTACHMENT, self::RENDERBUFFER, 0);
        ($g->epoxy_glDisable)(self::DEPTH_TEST);
        ($g->epoxy_glDisable)(self::CULL_FACE);
        ($g->epoxy_glFrontFace)(self::CCW);
    }
}
