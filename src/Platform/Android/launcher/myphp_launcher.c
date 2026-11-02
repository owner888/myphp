#include <jni.h>
#include <android/log.h>
#include <android/looper.h>
#include <android/native_window.h>
#include <android/native_window_jni.h>
#include <android/choreographer.h>
#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <fcntl.h>
#include <errno.h>

#include <main/php.h>
#include <sapi/embed/php_embed.h>
#include <Zend/zend.h>

#define LOG_TAG "MyPHP"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGW(...) __android_log_print(ANDROID_LOG_WARN, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

// Event types between Java/C and PHP
enum MyphpEventType {
    MYPHP_EVENT_SURFACE_CREATED = 1,
    MYPHP_EVENT_SURFACE_CHANGED = 2,
    MYPHP_EVENT_SURFACE_DESTROYED = 3,
    MYPHP_EVENT_TOUCH = 4,
    MYPHP_EVENT_CHOREOGRAPHER = 5,
    MYPHP_EVENT_SIGNAL = 6,
    MYPHP_EVENT_IME_COMMIT = 7,
    MYPHP_EVENT_IME_COMPOSE = 8,
    MYPHP_EVENT_IME_DELETE = 9,
    MYPHP_EVENT_KEY = 10,
};

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

static char g_files_dir[1024] = {0};
static char g_cache_dir[1024] = {0};
static pthread_t g_php_thread;
static JavaVM *g_vm = NULL;
static jobject g_activity_obj = NULL;
static jclass g_activity_class = NULL;

static ANativeWindow *g_native_window = NULL;
static int g_surface_width = 0;
static int g_surface_height = 0;
static float g_density = 1.0f;

static int g_event_pipe[2] = {-1, -1};
static AChoreographer *g_choreographer = NULL;
static int g_frame_requested = 0;

// Stdio redirect to logcat
static int pfd[2];
static pthread_t thr_log;

static void* stdio_logger(void *p) {
    ssize_t rdsz;
    char buf[1024];
    while ((rdsz = read(pfd[0], buf, sizeof(buf) - 1)) > 0) {
        if (buf[rdsz - 1] == '\n') --rdsz;
        buf[rdsz] = 0;
        LOGI("%s", buf);
    }
    return NULL;
}

static void start_stdio_redirect(void) {
    setvbuf(stdout, 0, _IONBF, 0);
    setvbuf(stderr, 0, _IONBF, 0);
    pipe(pfd);
    dup2(pfd[1], fileno(stdout));
    dup2(pfd[1], fileno(stderr));
    pthread_create(&thr_log, 0, stdio_logger, 0);
    pthread_detach(thr_log);
}

static size_t myphp_ub_write(const char *str, size_t str_length) {
    LOGI("%.*s", (int)str_length, str);
    return str_length;
}

static void myphp_log_message(const char *message, int syslog_type_int) {
    LOGE("%s", message);
}

// Public API for PHP FFI to query native state
ANativeWindow* myphp_get_native_window(void) {
    return g_native_window;
}

int myphp_get_surface_width(void) {
    return g_surface_width;
}

int myphp_get_surface_height(void) {
    return g_surface_height;
}

float myphp_get_density(void) {
    return g_density > 0.0f ? g_density : 1.0f;
}

int myphp_read_event(MyphpEvent *ev, int timeout_ms) {
    if (g_event_pipe[0] < 0) return 0;

    // Poll ALooper so that AChoreographer callbacks are dispatched on this thread
    ALooper_pollOnce(timeout_ms > 0 ? timeout_ms : 0, NULL, NULL, NULL);

    ssize_t n = read(g_event_pipe[0], ev, sizeof(MyphpEvent));
    if (n == sizeof(MyphpEvent)) {
        return 1;
    }
    return 0;
}

void myphp_signal_backend(void) {
    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {.type = MYPHP_EVENT_SIGNAL};
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
}

static void on_choreographer_frame(int64_t frameTimeNanos, void *data) {
    g_frame_requested = 0;
    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {
            .type = MYPHP_EVENT_CHOREOGRAPHER,
            .time_ms = frameTimeNanos / 1000000
        };
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
}

void myphp_request_frame(void) {
    if (!g_choreographer) {
        g_choreographer = AChoreographer_getInstance();
    }
    if (g_choreographer && !g_frame_requested) {
        g_frame_requested = 1;
        AChoreographer_postFrameCallback64(g_choreographer, on_choreographer_frame, NULL);
    }
}

// JNI font shaping & rasterizing helpers
static jmethodID g_mid_shape = NULL;
static jmethodID g_mid_rasterize = NULL;
static jmethodID g_mid_show_keyboard = NULL;
static jmethodID g_mid_hide_keyboard = NULL;

static JNIEnv* get_jni_env(void) {
    JNIEnv *env = NULL;
    if (g_vm) {
        (*g_vm)->GetEnv(g_vm, (void**)&env, JNI_VERSION_1_6);
        if (!env) {
            (*g_vm)->AttachCurrentThread(g_vm, &env, NULL);
        }
    }
    return env;
}

int myphp_shape_text(const char *text, float size, int style, float *out_buffer, int max_floats) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_shape) return 0;

    jstring jstr = (*env)->NewStringUTF(env, text);
    jfloatArray jarr = (jfloatArray)(*env)->CallStaticObjectMethod(env, g_activity_class, g_mid_shape, jstr, size, style);
    (*env)->DeleteLocalRef(env, jstr);

    if (!jarr) return 0;
    jsize len = (*env)->GetArrayLength(env, jarr);
    if (len > max_floats) len = max_floats;

    (*env)->GetFloatArrayRegion(env, jarr, 0, len, out_buffer);
    (*env)->DeleteLocalRef(env, jarr);
    return (int)len;
}

int myphp_rasterize_glyph(int glyph_id, float size, int style, int *out_metrics, uint8_t *out_pixels, int max_pixels) {
    LOGI("myphp_rasterize_glyph: gid=%d size=%f style=%d", glyph_id, size, style);
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_rasterize) {
        LOGE("myphp_rasterize_glyph missing env or method: env=%p class=%p mid=%p", env, g_activity_class, g_mid_rasterize);
        return 0;
    }

    jintArray jmetrics = (*env)->NewIntArray(env, 4);
    jbyteArray jpix = (jbyteArray)(*env)->CallStaticObjectMethod(env, g_activity_class, g_mid_rasterize, glyph_id, size, style, jmetrics);

    if (!jpix) {
        (*env)->DeleteLocalRef(env, jmetrics);
        return 0;
    }

    (*env)->GetIntArrayRegion(env, jmetrics, 0, 4, (jint*)out_metrics);
    (*env)->DeleteLocalRef(env, jmetrics);

    jsize len = (*env)->GetArrayLength(env, jpix);
    if (len > max_pixels) len = max_pixels;

    (*env)->GetByteArrayRegion(env, jpix, 0, len, (jbyte*)out_pixels);
    (*env)->DeleteLocalRef(env, jpix);
    return (int)len;
}

static void* php_worker_thread(void *arg) {
    LOGI("PHP thread starting...");

    setenv("HOME", g_files_dir, 1);
    setenv("TMPDIR", g_cache_dir, 1);
    setenv("XDG_CONFIG_HOME", g_files_dir, 1);
    setenv("XDG_CACHE_HOME", g_cache_dir, 1);
    setenv("XDG_DATA_HOME", g_files_dir, 1);

    ALooper *looper = ALooper_prepare(ALOOPER_PREPARE_ALLOW_NON_CALLBACKS);
    if (g_event_pipe[0] >= 0) {
        ALooper_addFd(looper, g_event_pipe[0], ALOOPER_POLL_CALLBACK, ALOOPER_EVENT_INPUT, NULL, NULL);
    }
    g_choreographer = AChoreographer_getInstance();

    php_embed_module.ub_write = myphp_ub_write;
    php_embed_module.log_message = myphp_log_message;
    php_embed_module.ini_entries = "ffi.enable=1\nopcache.enable=1\nopcache.enable_cli=1\n";

    char *argv[] = {"myphp", NULL};
    int argc = 1;

    LOGI("Initializing PHP Embed SAPI...");
    if (php_embed_init(argc, argv) != SUCCESS) {
        LOGE("Failed to initialize PHP embed");
        return NULL;
    }
    LOGI("PHP Embed initialized successfully!");

    zend_first_try {
        char init_code[2048];
        snprintf(init_code, sizeof(init_code),
            "putenv('MYPHP_PLATFORM=android');"
            "$app = '%s/app.php';"
            "if (file_exists($app)) {"
            "    require $app;"
            "} else {"
            "    echo \"[myphp] no app.php found at \" . $app . \"\\n\";"
            "}",
            g_files_dir
        );

        LOGI("Executing app.php...");
        zend_eval_string(init_code, NULL, "myphp_bootstrap");
    } zend_catch {
        LOGE("Uncaught PHP exception/bailout in bootstrap");
    } zend_end_try();

    LOGI("PHP bootstrap execution completed. Shutting down SAPI...");
    php_embed_shutdown();
    return NULL;
}

// JNI entry points
JNIEXPORT jint JNI_OnLoad(JavaVM *vm, void *reserved) {
    g_vm = vm;
    LOGI("libmyphp.so JNI_OnLoad");
    return JNI_VERSION_1_6;
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeInit(JNIEnv *env, jclass clazz, jstring filesDir, jstring cacheDir, jfloat density) {
    const char *files_c = (*env)->GetStringUTFChars(env, filesDir, NULL);
    const char *cache_c = (*env)->GetStringUTFChars(env, cacheDir, NULL);

    strncpy(g_files_dir, files_c, sizeof(g_files_dir) - 1);
    strncpy(g_cache_dir, cache_c, sizeof(g_cache_dir) - 1);
    g_density = density;

    (*env)->ReleaseStringUTFChars(env, filesDir, files_c);
    (*env)->ReleaseStringUTFChars(env, cacheDir, cache_c);

    g_activity_class = (jclass)(*env)->NewGlobalRef(env, clazz);
    g_mid_shape = (*env)->GetStaticMethodID(env, clazz, "shapeText", "(Ljava/lang/String;FI)[F");
    g_mid_rasterize = (*env)->GetStaticMethodID(env, clazz, "rasterizeGlyph", "(IFI[I)[B");
    g_mid_show_keyboard = (*env)->GetStaticMethodID(env, clazz, "showSoftKeyboard", "()V");
    g_mid_hide_keyboard = (*env)->GetStaticMethodID(env, clazz, "hideSoftKeyboard", "()V");

    if (pipe(g_event_pipe) == 0) {
        fcntl(g_event_pipe[0], F_SETFL, O_NONBLOCK);
        fcntl(g_event_pipe[1], F_SETFL, O_NONBLOCK);
    }

    start_stdio_redirect();

    if (pthread_create(&g_php_thread, NULL, php_worker_thread, NULL) != 0) {
        LOGE("Failed to create PHP pthread");
    } else {
        pthread_detach(g_php_thread);
        LOGI("PHP thread created");
    }
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeSurfaceCreated(JNIEnv *env, jclass clazz, jobject surface) {
    if (g_native_window) {
        ANativeWindow_release(g_native_window);
        g_native_window = NULL;
    }
    if (surface) {
        g_native_window = ANativeWindow_fromSurface(env, surface);
        LOGI("nativeSurfaceCreated: ANativeWindow=%p", g_native_window);
    }
    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {.type = MYPHP_EVENT_SURFACE_CREATED};
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeSurfaceChanged(JNIEnv *env, jclass clazz, jobject surface, jint width, jint height) {
    g_surface_width = width;
    g_surface_height = height;
    if (surface) {
        if (g_native_window) {
            ANativeWindow_release(g_native_window);
        }
        g_native_window = ANativeWindow_fromSurface(env, surface);
    }
    LOGI("nativeSurfaceChanged: %dx%d, ANativeWindow=%p", width, height, g_native_window);

    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {
            .type = MYPHP_EVENT_SURFACE_CHANGED,
            .width = width,
            .height = height
        };
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeSurfaceDestroyed(JNIEnv *env, jclass clazz) {
    LOGI("nativeSurfaceDestroyed");
    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {.type = MYPHP_EVENT_SURFACE_DESTROYED};
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
    if (g_native_window) {
        ANativeWindow_release(g_native_window);
        g_native_window = NULL;
    }
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeTouchEvent(JNIEnv *env, jclass clazz, jint action, jfloat x, jfloat y, jlong timeMs) {
    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {
            .type = MYPHP_EVENT_TOUCH,
            .action = action,
            .x = x,
            .y = y,
            .time_ms = timeMs
        };
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
}

void myphp_show_keyboard(void) {
    JNIEnv *env = get_jni_env();
    if (env && g_activity_class && g_mid_show_keyboard) {
        (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_show_keyboard);
    }
}

void myphp_hide_keyboard(void) {
    JNIEnv *env = get_jni_env();
    if (env && g_activity_class && g_mid_hide_keyboard) {
        (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_hide_keyboard);
    }
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeImeCommit(JNIEnv *env, jclass clazz, jstring jtext) {
    if (g_event_pipe[1] < 0 || !jtext) return;
    const char *chars = (*env)->GetStringUTFChars(env, jtext, NULL);
    if (!chars) return;

    MyphpEvent ev = {.type = MYPHP_EVENT_IME_COMMIT};
    strncpy(ev.text, chars, sizeof(ev.text) - 1);
    (*env)->ReleaseStringUTFChars(env, jtext, chars);

    write(g_event_pipe[1], &ev, sizeof(ev));
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeImeCompose(JNIEnv *env, jclass clazz, jstring jtext) {
    if (g_event_pipe[1] < 0) return;
    MyphpEvent ev = {.type = MYPHP_EVENT_IME_COMPOSE};
    if (jtext) {
        const char *chars = (*env)->GetStringUTFChars(env, jtext, NULL);
        if (chars) {
            strncpy(ev.text, chars, sizeof(ev.text) - 1);
            (*env)->ReleaseStringUTFChars(env, jtext, chars);
        }
    }
    write(g_event_pipe[1], &ev, sizeof(ev));
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeImeDelete(JNIEnv *env, jclass clazz) {
    if (g_event_pipe[1] < 0) return;
    MyphpEvent ev = {.type = MYPHP_EVENT_IME_DELETE};
    write(g_event_pipe[1], &ev, sizeof(ev));
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeKeyEvent(JNIEnv *env, jclass clazz, jint keyCode, jint action, jint unicodeChar) {
    if (g_event_pipe[1] < 0) return;
    MyphpEvent ev = {
        .type = MYPHP_EVENT_KEY,
        .action = action,
        .width = keyCode,
        .height = unicodeChar
    };
    write(g_event_pipe[1], &ev, sizeof(ev));
}
