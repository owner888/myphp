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
 * The OpenGL renderer (mygo's gpu/gl), as Metal.php is the Metal one: a
 * frame's DisplayList drawn with one instanced draw of the quads, split
 * where a quad reads the backdrop (glass, blurs), whose pixels so far are
 * copied and blurred at half size first. GLSL 3.30 core, through libepoxy,
 * which finds the functions of the context current (GTK's GtkGLArea's).
 *
 * A renderer belongs to one context: each window's GtkGLArea has its own,
 * which shares nothing with another's, so each window has a renderer, with
 * the atlases' textures of its own. The atlases tell one dirty range of
 * rows, which the first renderer to upload clears: what it uploads is kept
 * for the others too ($pending), which upload it at their next frame.
 */
final class Gl
{
    private const TEXTURE_2D = 0x0DE1, TEXTURE_2D_ARRAY = 0x8C1A, TEXTURE_BUFFER = 0x8C2A;
    private const TEXTURE0 = 0x84C0, TEXTURE_MIN_FILTER = 0x2801, TEXTURE_MAG_FILTER = 0x2800, TEXTURE_WRAP_S = 0x2802, TEXTURE_WRAP_T = 0x2803;
    private const TEXTURE_BORDER_COLOR = 0x1004, LINEAR = 0x2601, NEAREST = 0x2600, CLAMP_TO_EDGE = 0x812F, CLAMP_TO_BORDER = 0x812D;
    private const R8 = 0x8229, RED = 0x1903, RGBA8 = 0x8058, RGBA = 0x1908, BGRA = 0x80E1, RGBA32F = 0x8814, UNSIGNED_BYTE = 0x1401, FLOAT = 0x1406;
    private const ARRAY_BUFFER = 0x8892, DYNAMIC_DRAW = 0x88E8, UNPACK_ALIGNMENT = 0x0CF5, PACK_ALIGNMENT = 0x0D05;
    private const FRAMEBUFFER = 0x8D40, READ_FRAMEBUFFER = 0x8CA8, DRAW_FRAMEBUFFER = 0x8CA9, COLOR_ATTACHMENT0 = 0x8CE0, FRAMEBUFFER_COMPLETE = 0x8CD5;
    private const DRAW_FRAMEBUFFER_BINDING = 0x8CA6, READ_FRAMEBUFFER_BINDING = 0x8CAA, VIEWPORT = 0x0BA2, MAX_TEXTURE_BUFFER_SIZE = 0x8C2B;
    private const UNIFORM_BUFFER = 0x8A11, INVALID_INDEX = 0xFFFFFFFF;
    private const BLEND = 0x0BE2, SCISSOR_TEST = 0x0C11, DEPTH_TEST = 0x0B71, CULL_FACE = 0x0B44;
    private const ZERO = 0, ONE = 1, SRC_ALPHA = 0x0302, ONE_MINUS_SRC_ALPHA = 0x0303;
    private const TRIANGLES = 0x0004, TRIANGLE_STRIP = 0x0005, COLOR_BUFFER_BIT = 0x4000;
    private const SHADING_LANGUAGE_VERSION = 0x8B8C, EXTENSIONS = 0x1F03, NUM_EXTENSIONS = 0x821D;
    /** The shaders' #version for the GPU of the context (Android's: GlShader::esVersion), found once. */
    private static ?string $esVersion = null;
    private const VERTEX_SHADER = 0x8B31, FRAGMENT_SHADER = 0x8B30, COMPILE_STATUS = 0x8B81, LINK_STATUS = 0x8B82;
    private const TRANSFORMS = 16384;

    private static ?FFI $f = null;
    /** @var array<int, self> the renderers alive, by id, for the atlases' rows each still has to upload */
    private static array $renderers = [];
    private static int $nextId = 0;
    private int $id;
    /** @var array<int, array{0: int, 1: int}> by atlas (spl_object_id: the glyph atlas, an image page), the rows another renderer uploaded that this one has not */
    private array $pending = [];

    private int $quadProgram, $blurProgram, $vao;
    /** @var array<string, int> the quad program's uniforms' locations */
    private array $u = [];
    /** @var array<string, int> the blur program's */
    private array $bu = [];
    private const RING = 4;
    /** @var list<int> ring of quad/xform buffer objects and texture objects so TBDR GPUs (Adreno) never read an overwritten buffer mid-frame */
    private array $quadBuffers = [], $quadTextures = [], $xformBuffers = [], $xformTextures = [];
    /** Whether the quads and transforms are rows of 2D textures (GlShader::texelRows) rather than buffer textures. */
    private bool $rows = false;
    private int $ringSlot = 0;
    private int $atlasTexture = 0, $imagesTexture = 0;
    private ?Atlas $atlas = null;
    private ?ImageAtlas $images = null;
    private float $atlasScale = 1.0, $dark = 0.0;
    /** The FBO the renderer draws its own targets with, and the textures the glass reads: a copy of the target, the half-size blur in progress, the blurred backdrop. */
    private int $fbo = 0, $snapshot = 0, $blurTemp = 0, $backdrop = 0;
    private array $backdropSize = [0, 0];
    /** @var array<string, int> the textures canvases render into, by size, and those their blurs go through */
    private array $canvasTargets = [], $canvasBlurTemps = [];
    /** @var list<DisplayList> the frames' lists, two: the one drawing, and the last (lastFrame) */
    private array $lists = [];
    private int $frame = 0;
    /** The uniforms of the target being drawn: viewport in points, pixels per point, flip, height in pixels. */
    private array $target = [1.0, 1.0, 1.0, 1.0, 1.0];
    /** The locations of the uniforms of the program drawing now: the quads' ($u) or a shader's. @var array<string, int> */
    private array $current = [];
    /** @var \WeakMap<Code, array{0: int, 1: array<string, int>}> each shader's program and its uniforms' locations */
    private \WeakMap $programs;
    /** @var \WeakMap<Image, array{0: int, 1: int}> the textures shaders read images from, each with the version of the image it holds */
    private \WeakMap $imageTextures;
    /** @var \WeakMap<Canvas, int> the textures shaders read canvases' bitmaps from, copied from their place each frame they are read */
    private \WeakMap $canvasTextures;
    /** @var array<int, list<int>> the textures each shader draw of the list drawing reads, by its draw: four each */
    private array $shaderTextures = [];
    /** A texture of one transparent pixel, which a shader reads in place of an image it was not given; the buffer of shaders' uniforms. */
    private int $noImage = 0, $uniformBuffer = 0;

    public function __construct(public int $capacity = 65536)
    {
        $f = self::ffi();
        self::$esVersion ??= \MyPHP\App::isAndroid() ? GlShader::esVersion((string)(self::$f->epoxy_glGetString)(self::SHADING_LANGUAGE_VERSION), self::extensions()) : '';
        $this->rows = GlShader::texelRows(self::$esVersion);
        if (!$this->rows) {
            $max = $f->new('int[1]');
            (self::$f->epoxy_glGetIntegerv)(self::MAX_TEXTURE_BUFFER_SIZE, $max);
            if ($max[0] < $capacity * 4) {
                throw new RuntimeException("myphp: OpenGL's buffer textures hold {$max[0]} texels, fewer than " . ($capacity * 4));
            }
        }
        $this->programs = new \WeakMap();
        $this->imageTextures = new \WeakMap();
        $this->canvasTextures = new \WeakMap();
        $this->id = self::$nextId++;
        self::$renderers[$this->id] = $this;
        $this->quadProgram = $this->program(GlShader::COMMON . "\n" . GlShader::VERTEX, GlShader::COMMON . "\n" . GlShader::FRAGMENT);
        $this->u = $this->locations($this->quadProgram, self::COMMON_UNIFORMS);
        $this->blurProgram = $this->program(GlShader::BLUR_VERTEX, GlShader::BLUR_FRAGMENT);
        foreach (['uSrc', 'uDir', 'uSigma'] as $n) {
            $this->bu[$n] = (self::$f->epoxy_glGetUniformLocation)($this->blurProgram, $n);
        }
        $ids = $f->new('unsigned int[1]');
        (self::$f->epoxy_glGenVertexArrays)(1, $ids);
        $this->vao = $ids[0];
        for ($i = 0; $i < self::RING; $i++) {
            [$qb, $qt] = $this->bufferTexture($capacity * DisplayList::FLOATS_PER_RECT * 4);
            [$xb, $xt] = $this->bufferTexture(self::TRANSFORMS * DisplayList::FLOATS_PER_TRANSFORM * 4);
            $this->quadBuffers[] = $qb;
            $this->quadTextures[] = $qt;
            $this->xformBuffers[] = $xb;
            $this->xformTextures[] = $xt;
        }
        (self::$f->epoxy_glGenFramebuffers)(1, $ids);
        $this->fbo = $ids[0];
        for ($i = 0; $i < 2; $i++) {
            $this->lists[] = new DisplayList($f->new('float[' . ($capacity * DisplayList::FLOATS_PER_RECT) . ']', false), $capacity,
                $f->new('float[' . (self::TRANSFORMS * DisplayList::FLOATS_PER_TRANSFORM) . ']', false), self::TRANSFORMS);
        }
    }

    /** libepoxy's pointers to the GL functions, which it resolves for the context current at their first call. */
    public static function ffi(): FFI
    {
        return self::$f ??= FFI::cdef('
            typedef unsigned int GLenum; typedef unsigned int GLuint; typedef int GLint; typedef int GLsizei; typedef float GLfloat;
            typedef long GLsizeiptr; typedef long GLintptr; typedef unsigned char GLboolean; typedef unsigned int GLbitfield;
            int epoxy_gl_version(void); int epoxy_is_desktop_gl(void);
            const char* (*epoxy_glGetString)(GLenum);
            const char* (*epoxy_glGetStringi)(GLenum, GLuint);
            GLenum (*epoxy_glGetError)(void);
            void (*epoxy_glGetIntegerv)(GLenum, GLint*);
            GLuint (*epoxy_glCreateShader)(GLenum);
            void (*epoxy_glShaderSource)(GLuint, GLsizei, const char**, const GLint*);
            void (*epoxy_glCompileShader)(GLuint);
            void (*epoxy_glGetShaderiv)(GLuint, GLenum, GLint*);
            void (*epoxy_glGetShaderInfoLog)(GLuint, GLsizei, GLsizei*, char*);
            void (*epoxy_glDeleteShader)(GLuint);
            GLuint (*epoxy_glCreateProgram)(void);
            void (*epoxy_glAttachShader)(GLuint, GLuint);
            void (*epoxy_glLinkProgram)(GLuint);
            void (*epoxy_glGetProgramiv)(GLuint, GLenum, GLint*);
            void (*epoxy_glGetProgramInfoLog)(GLuint, GLsizei, GLsizei*, char*);
            void (*epoxy_glUseProgram)(GLuint);
            void (*epoxy_glDeleteProgram)(GLuint);
            GLint (*epoxy_glGetUniformLocation)(GLuint, const char*);
            void (*epoxy_glUniform1i)(GLint, GLint);
            void (*epoxy_glUniform1f)(GLint, GLfloat);
            void (*epoxy_glUniform2f)(GLint, GLfloat, GLfloat);
            void (*epoxy_glUniform4i)(GLint, GLint, GLint, GLint, GLint);
            GLuint (*epoxy_glGetUniformBlockIndex)(GLuint, const char*);
            void (*epoxy_glUniformBlockBinding)(GLuint, GLuint, GLuint);
            void (*epoxy_glBindBufferBase)(GLenum, GLuint, GLuint);
            void (*epoxy_glFramebufferTextureLayer)(GLenum, GLenum, GLuint, GLint, GLint);
            void (*epoxy_glGenVertexArrays)(GLsizei, GLuint*);
            void (*epoxy_glBindVertexArray)(GLuint);
            void (*epoxy_glDeleteVertexArrays)(GLsizei, const GLuint*);
            void (*epoxy_glGenBuffers)(GLsizei, GLuint*);
            void (*epoxy_glBindBuffer)(GLenum, GLuint);
            void (*epoxy_glBufferData)(GLenum, GLsizeiptr, const void*, GLenum);
            void (*epoxy_glBufferSubData)(GLenum, GLintptr, GLsizeiptr, const void*);
            void (*epoxy_glDeleteBuffers)(GLsizei, const GLuint*);
            void (*epoxy_glGenTextures)(GLsizei, GLuint*);
            void (*epoxy_glBindTexture)(GLenum, GLuint);
            void (*epoxy_glActiveTexture)(GLenum);
            void (*epoxy_glTexBuffer)(GLenum, GLenum, GLuint);
            void (*epoxy_glTexImage2D)(GLenum, GLint, GLint, GLsizei, GLsizei, GLint, GLenum, GLenum, const void*);
            void (*epoxy_glTexImage3D)(GLenum, GLint, GLint, GLsizei, GLsizei, GLsizei, GLint, GLenum, GLenum, const void*);
            void (*epoxy_glTexSubImage2D)(GLenum, GLint, GLint, GLint, GLsizei, GLsizei, GLenum, GLenum, const void*);
            void (*epoxy_glTexSubImage3D)(GLenum, GLint, GLint, GLint, GLint, GLsizei, GLsizei, GLsizei, GLenum, GLenum, const void*);
            void (*epoxy_glCopyTexSubImage2D)(GLenum, GLint, GLint, GLint, GLint, GLint, GLsizei, GLsizei);
            void (*epoxy_glCopyTexSubImage3D)(GLenum, GLint, GLint, GLint, GLint, GLint, GLint, GLsizei, GLsizei);
            void (*epoxy_glTexParameteri)(GLenum, GLenum, GLint);
            void (*epoxy_glTexParameterfv)(GLenum, GLenum, const GLfloat*);
            void (*epoxy_glDeleteTextures)(GLsizei, const GLuint*);
            void (*epoxy_glPixelStorei)(GLenum, GLint);
            void (*epoxy_glViewport)(GLint, GLint, GLsizei, GLsizei);
            void (*epoxy_glEnable)(GLenum);
            void (*epoxy_glDisable)(GLenum);
            void (*epoxy_glBlendFuncSeparate)(GLenum, GLenum, GLenum, GLenum);
            void (*epoxy_glClearColor)(GLfloat, GLfloat, GLfloat, GLfloat);
            void (*epoxy_glClear)(GLbitfield);
            void (*epoxy_glDrawArrays)(GLenum, GLint, GLsizei);
            void (*epoxy_glDrawArraysInstanced)(GLenum, GLint, GLsizei, GLsizei);
            void (*epoxy_glDrawElements)(GLenum, GLsizei, GLenum, const void*);
            void (*epoxy_glDrawElementsInstanced)(GLenum, GLsizei, GLenum, const void*, GLsizei);
            void (*epoxy_glEnableVertexAttribArray)(GLuint);
            void (*epoxy_glDisableVertexAttribArray)(GLuint);
            void (*epoxy_glVertexAttribPointer)(GLuint, GLint, GLenum, GLboolean, GLsizei, const void*);
            void (*epoxy_glVertexAttribDivisor)(GLuint, GLuint);
            void (*epoxy_glDepthFunc)(GLenum);
            void (*epoxy_glDepthMask)(GLboolean);
            void (*epoxy_glClearDepthf)(GLfloat);
            void (*epoxy_glCullFace)(GLenum);
            void (*epoxy_glFrontFace)(GLenum);
            void (*epoxy_glGenRenderbuffers)(GLsizei, GLuint*);
            void (*epoxy_glBindRenderbuffer)(GLenum, GLuint);
            void (*epoxy_glRenderbufferStorage)(GLenum, GLenum, GLsizei, GLsizei);
            void (*epoxy_glFramebufferRenderbuffer)(GLenum, GLenum, GLenum, GLuint);
            void (*epoxy_glDeleteRenderbuffers)(GLsizei, const GLuint*);
            void (*epoxy_glUniform4f)(GLint, GLfloat, GLfloat, GLfloat, GLfloat);
            void (*epoxy_glUniformMatrix4fv)(GLint, GLsizei, GLboolean, const GLfloat*);
            void (*epoxy_glReadPixels)(GLint, GLint, GLsizei, GLsizei, GLenum, GLenum, void*);
            void (*epoxy_glGenFramebuffers)(GLsizei, GLuint*);
            void (*epoxy_glBindFramebuffer)(GLenum, GLuint);
            void (*epoxy_glFramebufferTexture2D)(GLenum, GLenum, GLenum, GLuint, GLint);
            GLenum (*epoxy_glCheckFramebufferStatus)(GLenum);
            void (*epoxy_glDeleteFramebuffers)(GLsizei, const GLuint*);
            void (*epoxy_glFinish)(void);
        ', \MyPHP\App::isAndroid() ? 'libmyphp.so' : 'libepoxy.so.0');
    }

    /** A program of the two shaders, compiled and linked; a failure throws, with GL's log. */
    /** The context's extensions, space separated: glGetStringi's, one by one, as a core context has them (GLES 3 too). */
    private static function extensions(): string
    {
        $g = self::$f;
        $n = $g->new('int[1]');
        ($g->epoxy_glGetIntegerv)(self::NUM_EXTENSIONS, $n);
        $out = [];
        for ($i = 0; $i < $n[0]; $i++) {
            $out[] = (string)($g->epoxy_glGetStringi)(self::EXTENSIONS, $i);
        }
        return implode(' ', $out);
    }

    private function program(string $vertex, string $fragment): int
    {
        $g = self::$f;
        $p = ($g->epoxy_glCreateProgram)();
        foreach ([[self::VERTEX_SHADER, $vertex], [self::FRAGMENT_SHADER, $fragment]] as [$type, $src]) {
            $src = GlShader::adapt($src, self::$esVersion);
            $s = ($g->epoxy_glCreateShader)($type);
            $str = $g->new('char[' . (strlen($src) + 1) . ']', false);
            FFI::memcpy($str, $src, strlen($src));
            $ptrs = $g->new('const char*[1]');
            $ptrs[0] = $g->cast('const char*', FFI::addr($str[0]));
            ($g->epoxy_glShaderSource)($s, 1, $ptrs, null);
            ($g->epoxy_glCompileShader)($s);
            FFI::free($str);
            $ok = $g->new('int[1]');
            ($g->epoxy_glGetShaderiv)($s, self::COMPILE_STATUS, $ok);
            if ($ok[0] === 0) {
                $log = $g->new('char[8192]');
                ($g->epoxy_glGetShaderInfoLog)($s, 8192, null, $log);
                throw new RuntimeException('myphp: GLSL: ' . FFI::string($log));
            }
            ($g->epoxy_glAttachShader)($p, $s);
            ($g->epoxy_glDeleteShader)($s);
        }
        ($g->epoxy_glLinkProgram)($p);
        $ok = $g->new('int[1]');
        ($g->epoxy_glGetProgramiv)($p, self::LINK_STATUS, $ok);
        if ($ok[0] === 0) {
            $log = $g->new('char[8192]');
            ($g->epoxy_glGetProgramInfoLog)($p, 8192, null, $log);
            throw new RuntimeException('myphp: GLSL link: ' . FFI::string($log));
        }
        return $p;
    }

    /** A buffer of $bytes and the RGBA32F buffer texture reading it; with rows, no buffer and an RGBA32F 2D texture of rows of GlShader::TEXEL_ROW texels. @return array{0: int, 1: int} */
    private function bufferTexture(int $bytes): array
    {
        $g = self::$f;
        $ids = $g->new('unsigned int[1]');
        if ($this->rows) {
            ($g->epoxy_glGenTextures)(1, $ids);
            ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $ids[0]);
            ($g->epoxy_glTexImage2D)(self::TEXTURE_2D, 0, self::RGBA32F, GlShader::TEXEL_ROW, intdiv(intdiv($bytes, 16) + GlShader::TEXEL_ROW - 1, GlShader::TEXEL_ROW), 0, self::RGBA, self::FLOAT, null);
            $this->params(self::TEXTURE_2D, self::NEAREST, self::CLAMP_TO_EDGE);
            return [0, $ids[0]];
        }
        ($g->epoxy_glGenBuffers)(1, $ids);
        $buf = $ids[0];
        ($g->epoxy_glBindBuffer)(self::TEXTURE_BUFFER, $buf);
        ($g->epoxy_glBufferData)(self::TEXTURE_BUFFER, $bytes, null, self::DYNAMIC_DRAW);
        ($g->epoxy_glGenTextures)(1, $ids);
        $tex = $ids[0];
        ($g->epoxy_glBindTexture)(self::TEXTURE_BUFFER, $tex);
        ($g->epoxy_glTexBuffer)(self::TEXTURE_BUFFER, self::RGBA32F, $buf);
        return [$buf, $tex];
    }

    /** A texture of $w × $h RGBA8 pixels, linear, clamped to its edge or, $clear, to transparent beyond it. */
    private function texture(int $w, int $h, bool $clear = false): int
    {
        $g = self::$f;
        $ids = $g->new('unsigned int[1]');
        ($g->epoxy_glGenTextures)(1, $ids);
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $ids[0]);
        ($g->epoxy_glTexImage2D)(self::TEXTURE_2D, 0, self::RGBA8, $w, $h, 0, self::RGBA, self::UNSIGNED_BYTE, null);
        $this->params(self::TEXTURE_2D, self::LINEAR, $clear ? self::CLAMP_TO_BORDER : self::CLAMP_TO_EDGE);
        return $ids[0];
    }

    private function params(int $target, int $filter, int $wrap): void
    {
        $g = self::$f;
        ($g->epoxy_glTexParameteri)($target, self::TEXTURE_MIN_FILTER, $filter);
        ($g->epoxy_glTexParameteri)($target, self::TEXTURE_MAG_FILTER, $filter);
        ($g->epoxy_glTexParameteri)($target, self::TEXTURE_WRAP_S, $wrap);
        ($g->epoxy_glTexParameteri)($target, self::TEXTURE_WRAP_T, $wrap);
        if ($wrap === self::CLAMP_TO_BORDER) {
            ($g->epoxy_glTexParameterfv)($target, self::TEXTURE_BORDER_COLOR, $g->new('float[4]'));
        }
    }

    public function setDark(bool $dark): void
    {
        $this->dark = $dark ? 1.0 : 0.0;
    }

    /** The glyph atlas text draws from (R8), uploaded whole now and its changed rows each frame. */
    public function attachAtlas(Atlas $atlas, float $scale): void
    {
        $g = self::$f;
        $ids = $g->new('unsigned int[1]');
        ($g->epoxy_glGenTextures)(1, $ids);
        $this->atlasTexture = $ids[0];
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $this->atlasTexture);
        ($g->epoxy_glPixelStorei)(self::UNPACK_ALIGNMENT, 1);
        ($g->epoxy_glTexImage2D)(self::TEXTURE_2D, 0, self::R8, $atlas->w, $atlas->h, 0, self::RED, self::UNSIGNED_BYTE, $atlas->pix);
        $this->params(self::TEXTURE_2D, self::LINEAR, self::CLAMP_TO_EDGE);
        $this->atlas = $atlas;
        $this->atlasScale = $scale;
    }

    public function setAtlasScale(float $scale): void
    {
        $this->atlasScale = $scale;
    }

    /** The image atlas: a texture array of ImageAtlas::MAX layers, then CanvasAtlas::PAGES for canvases, each the glyph atlas's size. */
    public function attachImages(ImageAtlas $images): void
    {
        if ($images->w !== $this->atlas->w || $images->h !== $this->atlas->h) {
            throw new RuntimeException('the image atlas must be the glyph atlas\'s size');
        }
        $g = self::$f;
        $ids = $g->new('unsigned int[1]');
        ($g->epoxy_glGenTextures)(1, $ids);
        $this->imagesTexture = $ids[0];
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D_ARRAY, $this->imagesTexture);
        ($g->epoxy_glTexImage3D)(self::TEXTURE_2D_ARRAY, 0, self::RGBA8, $images->w, $images->h, ImageAtlas::MAX + CanvasAtlas::PAGES, 0, self::RGBA, self::UNSIGNED_BYTE, null);
        $this->params(self::TEXTURE_2D_ARRAY, self::LINEAR, self::CLAMP_TO_EDGE);
        foreach ($images->pages as $i => $page) {
            $this->uploadRows(self::TEXTURE_2D_ARRAY, $i, $page, 0, $page->h);
        }
        $this->images = $images;
        [CanvasAtlas::$w, CanvasAtlas::$h] = [$images->w, $images->h];
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
     * Draws $list into the framebuffer bound now, of $pw × $ph pixels,
     * $scale pixels a point (the content's zoom times the window's scale),
     * cleared to $clear (straight RGBA, 0 to 1) first.
     * @param array{0: float, 1: float, 2: float, 3: float} $clear
     */
    public function draw(DisplayList $list, int $pw, int $ph, float $scale, array $clear): void
    {
        $g = self::$f;
        $ids = $g->new('int[1]');
        ($g->epoxy_glGetIntegerv)(self::DRAW_FRAMEBUFFER_BINDING, $ids);
        $target = $ids[0];
        $list->showWide(false); // its sRGB colors: the framebuffer is 8 bits a component
        $this->uploadAtlases();
        foreach (CanvasAtlas::take() as $canvas) {
            $this->renderCanvas($canvas);
        }
        $this->prepareShaders($list); // its images' textures, canvases' copied, before the target is bound
        ($g->epoxy_glBindFramebuffer)(self::FRAMEBUFFER, $target);
        $this->target = [$pw / $scale, $ph / $scale, $scale, 1.0, (float)$ph];
        $this->drawList($list, $target, $pw, $ph, $clear);
        $err = ($g->epoxy_glGetError)();
        if ($err !== 0) {
            Logger::error("GL error after drawList: 0x" . dechex($err), "opengl");
        }
        // a new frame's list is the last from now; the last drawn again (the render signal's) stays so
        if ($list === $this->lists[$this->frame % 2]) {
            $this->frame++;
        }
    }

    /**
     * $list drawn off screen at $pw × $ph pixels: BGRA, premultiplied,
     * rows top down, as Metal::readBack gives them. The context must be
     * current.
     * @param array{0: float, 1: float, 2: float, 3: float} $clear
     */
    public function readBack(DisplayList $list, int $pw, int $ph, float $scale, array $clear): string
    {
        $g = self::$f;
        $ids = $g->new('int[1]');
        ($g->epoxy_glGetIntegerv)(self::DRAW_FRAMEBUFFER_BINDING, $ids);
        $was = $ids[0];
        $tex = $this->texture($pw, $ph);
        $fbo = $g->new('unsigned int[1]');
        ($g->epoxy_glGenFramebuffers)(1, $fbo);
        ($g->epoxy_glBindFramebuffer)(self::FRAMEBUFFER, $fbo[0]);
        ($g->epoxy_glFramebufferTexture2D)(self::FRAMEBUFFER, self::COLOR_ATTACHMENT0, self::TEXTURE_2D, $tex, 0);
        $list->showWide(false);
        $this->uploadAtlases();
        $this->prepareShaders($list);
        ($g->epoxy_glBindFramebuffer)(self::FRAMEBUFFER, $fbo[0]);
        $this->target = [$pw / $scale, $ph / $scale, $scale, -1.0, (float)$ph];
        $this->drawList($list, $fbo[0], $pw, $ph, $clear);
        $bytes = $g->new('char[' . ($pw * $ph * 4) . ']');
        ($g->epoxy_glPixelStorei)(self::PACK_ALIGNMENT, 1);
        ($g->epoxy_glReadPixels)(0, 0, $pw, $ph, self::BGRA, self::UNSIGNED_BYTE, $bytes); // drawn upside down: its first row is the top one
        ($g->epoxy_glBindFramebuffer)(self::FRAMEBUFFER, $was);
        ($g->epoxy_glDeleteFramebuffers)(1, $fbo);
        $t = $g->new('unsigned int[1]'); $t[0] = $tex;
        ($g->epoxy_glDeleteTextures)(1, $t);
        return FFI::string($bytes, $pw * $ph * 4);
    }

    /** The rows of the atlases that changed, this renderer's and those another took from the atlases' dirty ranges before it. */
    private function uploadAtlases(): void
    {
        if ($this->atlas !== null) {
            $this->uploadIfDirty(self::TEXTURE_2D, 0, $this->atlas);
        }
        foreach ($this->images?->pages ?? [] as $i => $page) {
            $this->uploadIfDirty(self::TEXTURE_2D_ARRAY, $i, $page);
        }
    }

    private function uploadIfDirty(int $target, int $layer, Atlas $a): void
    {
        $key = spl_object_id($a);
        $range = $this->pending[$key] ?? null;
        unset($this->pending[$key]);
        if ($a->dirty) {
            $from = $a->dirtyFrom; $to = $a->dirtyTo;
            foreach (self::$renderers as $id => $r) { // the others upload them at their next frame
                if ($id !== $this->id) {
                    $p = $r->pending[$key] ?? [$from, $to];
                    $r->pending[$key] = [min($p[0], $from), max($p[1], $to)];
                }
            }
            $range = $range === null ? [$from, $to] : [min($range[0], $from), max($range[1], $to)];
            $a->dirty = false;
        }
        if ($range !== null && $range[1] > $range[0]) {
            $this->uploadRows($target, $layer, $a, $range[0], $range[1]);
        }
    }

    private function uploadRows(int $target, int $layer, Atlas $a, int $from, int $to): void
    {
        $g = self::$f;
        $rows = $to - $from;
        $bytes = $g->cast('char*', $a->pix) + $from * $a->w * $a->bpp;
        ($g->epoxy_glPixelStorei)(self::UNPACK_ALIGNMENT, 1);
        if ($target === self::TEXTURE_2D) {
            ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $this->atlasTexture);
            ($g->epoxy_glTexSubImage2D)(self::TEXTURE_2D, 0, 0, $from, $a->w, $rows, self::RED, self::UNSIGNED_BYTE, $bytes);
        } else {
            ($g->epoxy_glBindTexture)(self::TEXTURE_2D_ARRAY, $this->imagesTexture);
            ($g->epoxy_glTexSubImage3D)(self::TEXTURE_2D_ARRAY, 0, 0, $from, $layer, $a->w, $rows, 1, self::RGBA, self::UNSIGNED_BYTE, $bytes);
        }
    }

    /** Uploads $list's quads and transforms into the next ring slot, and draws with the quad program. */
    private function bindList(DisplayList $list, int $extra = 0): void
    {
        $g = self::$f;
        $slot = $this->ringSlot = ($this->ringSlot + 1) % self::RING;
        if ($this->rows) {
            $this->uploadTexels($this->quadTextures[$slot], $list->buf, ($list->n + $extra) * DisplayList::FLOATS_PER_RECT / 4);
            $this->uploadTexels($this->xformTextures[$slot], $list->xforms, $list->transforms * DisplayList::FLOATS_PER_TRANSFORM / 4);
        } else {
            ($g->epoxy_glBindBuffer)(self::TEXTURE_BUFFER, $this->quadBuffers[$slot]);
            ($g->epoxy_glBufferSubData)(self::TEXTURE_BUFFER, 0, ($list->n + $extra) * DisplayList::FLOATS_PER_RECT * 4, $list->buf);
            ($g->epoxy_glBindBuffer)(self::TEXTURE_BUFFER, $this->xformBuffers[$slot]);
            ($g->epoxy_glBufferSubData)(self::TEXTURE_BUFFER, 0, $list->transforms * DisplayList::FLOATS_PER_TRANSFORM * 4, $list->xforms);
        }
        $this->useProgram($this->quadProgram, $this->u);
    }

    /** Uploads the first $texels RGBA float texels of $floats into the rows of $tex: the whole rows, then what is left of the last. */
    private function uploadTexels(int $tex, \FFI\CData $floats, int $texels): void
    {
        $g = self::$f;
        $w = GlShader::TEXEL_ROW;
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $tex);
        ($g->epoxy_glPixelStorei)(self::UNPACK_ALIGNMENT, 4);
        $full = intdiv($texels, $w);
        if ($full > 0) {
            ($g->epoxy_glTexSubImage2D)(self::TEXTURE_2D, 0, 0, 0, $w, $full, self::RGBA, self::FLOAT, $floats);
        }
        if ($texels % $w > 0) {
            ($g->epoxy_glTexSubImage2D)(self::TEXTURE_2D, 0, 0, $full, $texels % $w, 1, self::RGBA, self::FLOAT, FFI::addr($floats[$full * $w * 4]));
        }
    }

    /** Draws with $program from now, its uniforms of the target ($this->target) and the renderer's textures set. @param array<string, int> $u its uniforms' locations */
    private function useProgram(int $program, array $u): void
    {
        $g = self::$f;
        ($g->epoxy_glUseProgram)($program);
        ($g->epoxy_glBindVertexArray)($this->vao);
        $this->current = $u;
        [$vw, $vh, $scale, $flip, $th] = $this->target;
        ($g->epoxy_glUniform2f)($u['uViewport'], $vw, $vh);
        ($g->epoxy_glUniform2f)($u['uAtlas'], (float)($this->atlas?->w ?? 1), (float)($this->atlas?->h ?? 1));
        ($g->epoxy_glUniform1f)($u['uScale'], $scale);
        ($g->epoxy_glUniform1f)($u['uAtlasScale'], $this->atlasScale);
        ($g->epoxy_glUniform1f)($u['uDark'], $this->dark);
        ($g->epoxy_glUniform1f)($u['uFlip'], $flip);
        ($g->epoxy_glUniform1f)($u['uTargetH'], $th);
        $slot = $this->ringSlot;
        $buffer = $this->rows ? self::TEXTURE_2D : self::TEXTURE_BUFFER;
        foreach ([['uQuads', 0, $buffer, $this->quadTextures[$slot]], ['uXforms', 1, $buffer, $this->xformTextures[$slot]],
                  ['uAtlasTex', 2, self::TEXTURE_2D, $this->atlasTexture], ['uImages', 3, self::TEXTURE_2D_ARRAY, $this->imagesTexture],
                  ['uBackdrop', 4, self::TEXTURE_2D, $this->backdrop]] as [$name, $unit, $kind, $tex]) {
            ($g->epoxy_glActiveTexture)(self::TEXTURE0 + $unit);
            ($g->epoxy_glBindTexture)($kind, $tex);
            if (($u[$name] ?? -1) >= 0) { ($g->epoxy_glUniform1i)($u[$name], $unit); }
        }
        ($g->epoxy_glActiveTexture)(self::TEXTURE0);
    }

    /** The locations of a program's uniforms by name. @param list<string> $names @return array<string, int> */
    private function locations(int $program, array $names): array
    {
        $out = [];
        foreach ($names as $n) { $out[$n] = (self::$f->epoxy_glGetUniformLocation)($program, $n); }
        return $out;
    }

    private const COMMON_UNIFORMS = ['uQuads', 'uXforms', 'uFrom', 'uViewport', 'uAtlas', 'uScale', 'uAtlasScale', 'uDark', 'uFlip', 'uTargetH', 'uAtlasTex', 'uImages', 'uBackdrop'];

    /**
     * The program of shader $code, built the first time: GlShader's tail
     * around its GLSL, its uniforms' block bound to binding 0.
     * @return array{0: int, 1: array<string, int>}
     */
    private function shaderProgram(Code $code): array
    {
        if (!isset($this->programs[$code])) {
            $p = $this->program(GlShader::COMMON . "\n" . GlShader::SHADER_VERTEX,
                GlShader::COMMON . "\n" . GlShader::SHADER_FRAGMENT_HEAD . "\n" . $code->source . "\n" . GlShader::SHADER_FRAGMENT_MAIN);
            $block = (self::$f->epoxy_glGetUniformBlockIndex)($p, 'Uniforms');
            if ($block !== self::INVALID_INDEX) {
                (self::$f->epoxy_glUniformBlockBinding)($p, $block, 0);
            }
            $this->programs[$code] = [$p, $this->locations($p, [...self::COMMON_UNIFORMS, 'T0', 'T1', 'T2', 'T3', 'myphpFlipRows'])];
        }
        return $this->programs[$code];
    }

    /**
     * The textures each shader draw of $list reads, made before its passes:
     * an image's, uploaded when it is new or updated; a canvas's bitmap's,
     * copied from its place among the canvases' layers into a texture of
     * its own, which a shader reads as an image of its own size.
     */
    private function prepareShaders(DisplayList $list): void
    {
        $this->shaderTextures = [];
        $copied = [];
        foreach ($list->shaders as $d => [, , , $images]) {
            $ts = [];
            foreach ($images as $img) {
                $ts[] = $img === null ? $this->noImage() : ($img->canvas !== null ? $copied[spl_object_id($img)] ??= $this->canvasTexture($img->canvas) : $this->imageTexture($img));
            }
            $this->shaderTextures[$d] = $ts;
        }
    }

    private function noImage(): int
    {
        if ($this->noImage === 0) {
            $this->noImage = $this->texture(1, 1);
            ($this->ffiTexImage())(1, 1, null);
        }
        return $this->noImage;
    }

    /** A function uploading RGBA pixels to the texture bound. */
    private function ffiTexImage(): \Closure
    {
        $g = self::$f;
        return function (int $w, int $h, $pixels) use ($g): void {
            ($g->epoxy_glPixelStorei)(self::UNPACK_ALIGNMENT, 1);
            ($g->epoxy_glTexImage2D)(self::TEXTURE_2D, 0, self::RGBA8, $w, $h, 0, self::RGBA, self::UNSIGNED_BYTE, $pixels);
        };
    }

    /** The texture of $img's pixels, premultiplied as it holds them. */
    private function imageTexture(Image $img): int
    {
        [$t, $version] = $this->imageTextures[$img] ?? [0, -1];
        if ($t !== 0 && $version === $img->version) {
            return $t;
        }
        if ($t === 0) {
            $t = $this->texture($img->w, $img->h);
        }
        $g = self::$f;
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $t);
        ($this->ffiTexImage())($img->w, $img->h, $g->cast('void*', $img->address()));
        $img->uploaded();
        $this->imageTextures[$img] = [$t, $img->version];
        return $t;
    }

    /** A texture of $c's size with its pixels, copied from its place among the canvases' layers; transparent before it was drawn. */
    private function canvasTexture(Canvas $c): int
    {
        $t = $this->canvasTextures[$c] ??= $this->texture($c->w, $c->h);
        if ($c->slot === null) {
            return $t;
        }
        [$u, $v, $page] = $c->slot;
        $g = self::$f;
        ($g->epoxy_glBindFramebuffer)(self::READ_FRAMEBUFFER, $this->fbo);
        ($g->epoxy_glFramebufferTextureLayer)(self::READ_FRAMEBUFFER, self::COLOR_ATTACHMENT0, $this->imagesTexture, 0, $page);
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $t);
        ($g->epoxy_glCopyTexSubImage2D)(self::TEXTURE_2D, 0, 0, 0, $u, $v, $c->w, $c->h);
        return $t;
    }

    /**
     * Draws the run of $n quads from $at shader draw $d of $list draws: its
     * program, its uniforms' bytes in the block, its images; the backdrop
     * of one reading it, what the frame drew before it, blurred at half
     * size when its glass entry asks a blur, or as it is (Metal's
     * backdropOf); transparent in a canvas.
     */
    private function drawShader(DisplayList $list, int $d, int $at, int $n, bool $premultiplied): void
    {
        $g = self::$f;
        [$code, $bytes] = $list->shaders[$d];
        $textures = $this->shaderTextures[$d];
        $rowsUp = 0;
        if ($code->backdrop) {
            $blur = 0.0;
            foreach ($list->glass as [$gAt, $sigma]) {
                if ($gAt === $at) { $blur = $sigma; }
            }
            if ($premultiplied || $this->snapshot === 0) {
                $textures[2] = $this->noImage();
            } else {
                $textures[2] = $blur > 0 ? $this->backdrop : $this->snapshot;
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
                $rowsUp = $this->target[3] > 0 ? $h : 0; // copied from a window's framebuffer: its rows go up
            }
        }
        [$program, $u] = $this->shaderProgram($code);
        $this->useProgram($program, $u);
        if ($code->size > 0) {
            if ($this->uniformBuffer === 0) {
                $ids = $g->new('unsigned int[1]');
                ($g->epoxy_glGenBuffers)(1, $ids);
                $this->uniformBuffer = $ids[0];
            }
            ($g->epoxy_glBindBuffer)(self::UNIFORM_BUFFER, $this->uniformBuffer);
            ($g->epoxy_glBufferData)(self::UNIFORM_BUFFER, strlen($bytes), $bytes, self::DYNAMIC_DRAW);
            ($g->epoxy_glBindBufferBase)(self::UNIFORM_BUFFER, 0, $this->uniformBuffer);
        }
        foreach ($textures as $i => $t) {
            if (($u["T$i"] ?? -1) < 0) { continue; }
            ($g->epoxy_glActiveTexture)(self::TEXTURE0 + 5 + $i);
            ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $t);
            ($g->epoxy_glUniform1i)($u["T$i"], 5 + $i);
        }
        ($g->epoxy_glActiveTexture)(self::TEXTURE0);
        if ($u['myphpFlipRows'] >= 0) {
            ($g->epoxy_glUniform4i)($u['myphpFlipRows'], 0, 0, $rowsUp, 0);
        }
        $this->drawQuads($at, $n, $premultiplied ? 'canvas' : 'blend');
        $this->useProgram($this->quadProgram, $this->u);
    }

    /** Draws $list into $fbo ($pw × $ph): one pass, split at each glass quad, which reads what is below it blurred. */
    private function drawList(DisplayList $list, int $fbo, int $pw, int $ph, array $clear): void
    {
        $g = self::$f;
        ($g->epoxy_glDisable)(self::SCISSOR_TEST);
        ($g->epoxy_glDisable)(self::DEPTH_TEST);
        ($g->epoxy_glDisable)(self::CULL_FACE);
        ($g->epoxy_glViewport)(0, 0, $pw, $ph);
        ($g->epoxy_glClearColor)($clear[0] * $clear[3], $clear[1] * $clear[3], $clear[2] * $clear[3], $clear[3]);
        ($g->epoxy_glClear)(self::COLOR_BUFFER_BIT);
        if ($list->n === 0) {
            return;
        }
        $list->cull();
        $this->bindList($list);
        $start = 0;
        foreach ($list->glass as [$at, $sigma]) {
            $this->drawRuns($list, $start, $at - $start);
            $this->blur($fbo, $pw, $ph, $sigma);
            ($g->epoxy_glBindFramebuffer)(self::FRAMEBUFFER, $fbo);
            ($g->epoxy_glViewport)(0, 0, $pw, $ph);
            $this->bindList($list);
            $start = $at;
        }
        $this->drawRuns($list, $start, $list->n - $start);
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
                $this->drawShader($list, $d, $r, $n, $premultiplied);
            }
            $at = $r + $n;
        }
        $this->drawQuads($at, $from + $count - $at, $premultiplied ? 'canvas' : 'blend');
    }

    private function drawQuads(int $at, int $n, string $blend): void
    {
        if ($n <= 0) {
            return;
        }
        $g = self::$f;
        ($g->epoxy_glEnable)(self::BLEND);
        match ($blend) {
            'blend' => ($g->epoxy_glBlendFuncSeparate)(self::SRC_ALPHA, self::ONE_MINUS_SRC_ALPHA, self::SRC_ALPHA, self::ONE_MINUS_SRC_ALPHA),
            'canvas' => ($g->epoxy_glBlendFuncSeparate)(self::SRC_ALPHA, self::ONE_MINUS_SRC_ALPHA, self::ONE, self::ONE_MINUS_SRC_ALPHA), // its alpha kept premultiplied, as the image atlas holds it
            'hole' => ($g->epoxy_glBlendFuncSeparate)(self::ZERO, self::ONE_MINUS_SRC_ALPHA, self::ZERO, self::ONE_MINUS_SRC_ALPHA), // what is there goes, by the quad's coverage
        };
        ($g->epoxy_glUniform1i)($this->current['uFrom'], $at);
        ($g->epoxy_glDrawArraysInstanced)(self::TRIANGLE_STRIP, 0, 4, $n);
        $err = ($g->epoxy_glGetError)();
        if ($err !== 0) {
            Logger::error("GL error in drawQuads at=$at, n=$n: 0x" . dechex($err), "opengl");
        }
    }

    /** Copies $fbo's pixels so far and blurs them, at half size, into the backdrop the glass reads. */
    private function blur(int $fbo, int $pw, int $ph, float $sigma): void
    {
        $g = self::$f;
        if ($this->backdropSize !== [$pw, $ph]) {
            $t = $g->new('unsigned int[3]');
            [$t[0], $t[1], $t[2]] = [$this->snapshot, $this->blurTemp, $this->backdrop];
            if ($this->snapshot !== 0) { ($g->epoxy_glDeleteTextures)(3, $t); }
            $this->snapshot = $this->texture($pw, $ph);
            $this->blurTemp = $this->texture(max(1, intdiv($pw, 2)), max(1, intdiv($ph, 2)));
            $this->backdrop = $this->texture(max(1, intdiv($pw, 2)), max(1, intdiv($ph, 2)));
            $this->backdropSize = [$pw, $ph];
        }
        ($g->epoxy_glBindFramebuffer)(self::READ_FRAMEBUFFER, $fbo);
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $this->snapshot);
        ($g->epoxy_glCopyTexSubImage2D)(self::TEXTURE_2D, 0, 0, 0, 0, 0, $pw, $ph);
        $s = max(0.5, $sigma / 2); // half size: the sigma is halved
        $this->blurPass($this->snapshot, $this->blurTemp, max(1, intdiv($pw, 2)), max(1, intdiv($ph, 2)), 1.0, 0.0, $s);
        $this->blurPass($this->blurTemp, $this->backdrop, max(1, intdiv($pw, 2)), max(1, intdiv($ph, 2)), 0.0, 1.0, $s);
    }

    /** Draws $src blurred along ($dx, $dy) into $dst ($w × $h) through the renderer's FBO. */
    private function blurPass(int $src, int $dst, int $w, int $h, float $dx, float $dy, float $sigma): void
    {
        $g = self::$f;
        ($g->epoxy_glBindFramebuffer)(self::FRAMEBUFFER, $this->fbo);
        ($g->epoxy_glFramebufferTexture2D)(self::FRAMEBUFFER, self::COLOR_ATTACHMENT0, self::TEXTURE_2D, $dst, 0);
        ($g->epoxy_glViewport)(0, 0, $w, $h);
        ($g->epoxy_glDisable)(self::BLEND);
        ($g->epoxy_glUseProgram)($this->blurProgram);
        ($g->epoxy_glBindVertexArray)($this->vao);
        ($g->epoxy_glActiveTexture)(self::TEXTURE0);
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D, $src);
        ($g->epoxy_glUniform1i)($this->bu['uSrc'], 0);
        ($g->epoxy_glUniform2f)($this->bu['uDir'], $dx, $dy);
        ($g->epoxy_glUniform1f)($this->bu['uSigma'], $sigma);
        ($g->epoxy_glDrawArrays)(self::TRIANGLES, 0, 3);
    }

    /**
     * Renders canvas $c from the list it was last drawn with into a
     * texture of its size, transparent first, then copies that to its
     * place among the canvases' layers of the image texture: a draw may
     * not read the texture it draws into, and the canvases inside it are
     * in that texture. Glass and blurs draw nothing in a canvas.
     */
    private function renderCanvas(Canvas $c): void
    {
        if ($c->loose()) {
            Logger::warn("a canvas of {$c->w}x{$c->h}, larger than a page, is not drawn by the OpenGL renderer yet", 'gl');
            return;
        }
        $g = self::$f;
        $key = "{$c->w}x{$c->h}";
        if (!isset($this->canvasTargets[$key])) {
            if (count($this->canvasTargets) > 16) {
                $this->deleteTextures($this->canvasTargets);
                $this->canvasTargets = [];
            }
            $this->canvasTargets[$key] = $this->texture($c->w, $c->h, true);
        }
        $target = $this->canvasTargets[$key];
        ($g->epoxy_glBindFramebuffer)(self::FRAMEBUFFER, $this->fbo);
        ($g->epoxy_glFramebufferTexture2D)(self::FRAMEBUFFER, self::COLOR_ATTACHMENT0, self::TEXTURE_2D, $target, 0);
        if ($c->scene !== null) {
            \MyPHP\Plugins\Scene\Native\GlRenderPass::instance()->render($c->scene, $c->w, $c->h, $this->fbo, -1.0);
        } else {
            ($g->epoxy_glViewport)(0, 0, $c->w, $c->h);
            ($g->epoxy_glClearColor)(0.0, 0.0, 0.0, 0.0);
            ($g->epoxy_glClear)(self::COLOR_BUFFER_BIT);
        }
        $list = $c->list;
        if ($list !== null && $list->n > 0) {
            $this->prepareShaders($list); // copied before the canvas's target is bound
            ($g->epoxy_glBindFramebuffer)(self::FRAMEBUFFER, $this->fbo);
            ($g->epoxy_glFramebufferTexture2D)(self::FRAMEBUFFER, self::COLOR_ATTACHMENT0, self::TEXTURE_2D, $target, 0);
            $this->target = [(float)$c->w, (float)$c->h, 1.0, -1.0, (float)$c->h]; // upside down: its first row the top one, as the atlas's
            $this->bindList($list);
            $this->drawRuns($list, 0, $list->n, true);
        }
        if ($c->blur > 0) { // along rows into a texture of its size, then along columns back
            $tmp = $this->canvasBlurTemps[$key] ??= $this->texture($c->w, $c->h, true);
            $this->blurPass($target, $tmp, $c->w, $c->h, 1.0, 0.0, $c->blur);
            $this->blurPass($tmp, $target, $c->w, $c->h, 0.0, 1.0, $c->blur);
            ($g->epoxy_glFramebufferTexture2D)(self::FRAMEBUFFER, self::COLOR_ATTACHMENT0, self::TEXTURE_2D, $target, 0);
        }
        if ($c->slot === null) {
            return;
        }
        [$u, $v, $page] = $c->slot;
        ($g->epoxy_glBindFramebuffer)(self::READ_FRAMEBUFFER, $this->fbo);
        ($g->epoxy_glBindTexture)(self::TEXTURE_2D_ARRAY, $this->imagesTexture);
        ($g->epoxy_glCopyTexSubImage3D)(self::TEXTURE_2D_ARRAY, 0, $u, $v, $page, 0, 0, $c->w, $c->h);
    }

    /** @param array<string, int> $textures */
    private function deleteTextures(array $textures): void
    {
        $g = self::$f;
        foreach ($textures as $t) {
            $ids = $g->new('unsigned int[1]'); $ids[0] = $t;
            ($g->epoxy_glDeleteTextures)(1, $ids);
        }
    }

    /** Lets go of the renderer: its context goes with its window. */
    public function release(): void
    {
        unset(self::$renderers[$this->id]);
        foreach ($this->lists as $l) {
            FFI::free($l->buf);
            FFI::free($l->xforms);
        }
        $this->lists = [];
    }
}
