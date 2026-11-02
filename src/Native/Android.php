<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;

/**
 * FFI bridge to Android NDK and launcher primitives (EGL, ANativeWindow, AChoreographer, events).
 */
final class Android
{
    public const EVENT_SURFACE_CREATED = 1;
    public const EVENT_SURFACE_CHANGED = 2;
    public const EVENT_SURFACE_DESTROYED = 3;
    public const EVENT_TOUCH = 4;
    public const EVENT_CHOREOGRAPHER = 5;
    public const EVENT_SIGNAL = 6;
    public const EVENT_IME_COMMIT = 7;
    public const EVENT_IME_COMPOSE = 8;
    public const EVENT_IME_DELETE = 9;
    public const EVENT_KEY = 10;

    // MotionEvent action constants
    public const ACTION_DOWN = 0;
    public const ACTION_UP = 1;
    public const ACTION_MOVE = 2;
    public const ACTION_CANCEL = 3;

    // EGL constants
    public const EGL_DEFAULT_DISPLAY = 0;
    public const EGL_NO_CONTEXT = 0;
    public const EGL_NO_SURFACE = 0;
    public const EGL_NO_DISPLAY = 0;
    public const EGL_FALSE = 0;
    public const EGL_TRUE = 1;
    public const EGL_SUCCESS = 0x3000;
    public const EGL_BUFFER_SIZE = 0x3020;
    public const EGL_RED_SIZE = 0x3024;
    public const EGL_GREEN_SIZE = 0x3023;
    public const EGL_BLUE_SIZE = 0x3022;
    public const EGL_ALPHA_SIZE = 0x3021;
    public const EGL_DEPTH_SIZE = 0x3025;
    public const EGL_STENCIL_SIZE = 0x3026;
    public const EGL_SURFACE_TYPE = 0x3033;
    public const EGL_WINDOW_BIT = 0x0004;
    public const EGL_RENDERABLE_TYPE = 0x3040;
    public const EGL_OPENGL_ES3_BIT = 0x00000040;
    public const EGL_NONE = 0x3038;
    public const EGL_CONTEXT_CLIENT_VERSION = 0x3098;

    private static ?FFI $f = null;
    private static ?FFI $egl = null;

    public static function ffi(): FFI
    {
        return self::$f ??= FFI::cdef('
            typedef struct {
                int32_t type;
                int32_t action;
                float x;
                float y;
                int32_t width;
                int32_t height;
                int64_t time_ms;
                char text[256];
            } MyphpEvent;

            void* myphp_get_native_window(void);
            int myphp_get_surface_width(void);
            int myphp_get_surface_height(void);
            float myphp_get_density(void);
            int myphp_read_event(MyphpEvent *ev, int timeout_ms);
            void myphp_signal_backend(void);
            void myphp_request_frame(void);
            void myphp_show_keyboard(void);
            void myphp_hide_keyboard(void);
            int myphp_shape_text(const char *text, float size, int style, float *out_buffer, int max_floats);
            int myphp_rasterize_glyph(int glyph_id, float size, int style, int *out_metrics, uint8_t *out_pixels, int max_pixels);
        ', 'libmyphp.so');
    }

    public static function egl(): FFI
    {
        return self::$egl ??= FFI::cdef('
            typedef int EGLint;
            typedef unsigned int EGLBoolean;
            typedef unsigned int EGLenum;
            typedef void *EGLConfig;
            typedef void *EGLContext;
            typedef void *EGLDisplay;
            typedef void *EGLSurface;
            typedef void *EGLNativeDisplayType;
            typedef void *EGLNativeWindowType;

            EGLDisplay eglGetDisplay(EGLNativeDisplayType display_id);
            EGLBoolean eglInitialize(EGLDisplay dpy, EGLint *major, EGLint *minor);
            EGLBoolean eglChooseConfig(EGLDisplay dpy, const EGLint *attrib_list, EGLConfig *configs, EGLint config_size, EGLint *num_config);
            EGLContext eglCreateContext(EGLDisplay dpy, EGLConfig config, EGLContext share_context, const EGLint *attrib_list);
            EGLSurface eglCreateWindowSurface(EGLDisplay dpy, EGLConfig config, EGLNativeWindowType win, const EGLint *attrib_list);
            EGLBoolean eglMakeCurrent(EGLDisplay dpy, EGLSurface draw, EGLSurface read, EGLContext ctx);
            EGLBoolean eglSwapBuffers(EGLDisplay dpy, EGLSurface surface);
            EGLBoolean eglDestroySurface(EGLDisplay dpy, EGLSurface surface);
            EGLBoolean eglDestroyContext(EGLDisplay dpy, EGLContext ctx);
            EGLBoolean eglTerminate(EGLDisplay dpy);
            EGLint eglGetError(void);
        ', 'libEGL.so');
    }
}
