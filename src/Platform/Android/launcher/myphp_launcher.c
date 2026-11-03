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
#include <poll.h>

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
    MYPHP_EVENT_PAUSE = 11,
    MYPHP_EVENT_RESUME = 12,
    MYPHP_EVENT_CONFIGURATION = 13,
    MYPHP_EVENT_DIALOG_RESULT = 14,
    MYPHP_EVENT_WEB_MESSAGE = 15,
};

typedef struct {
    int32_t type;
    int32_t action;
    float x;
    float y;
    int32_t width;
    int32_t height;
    int64_t time_ms;
    int32_t insets[4];
    int32_t night_mode;
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

    // Dispatch pending AChoreographer vsync callbacks without blocking
    ALooper_pollOnce(0, NULL, NULL, NULL);

    // Wait on event pipe with standard poll()
    struct pollfd pfd = {
        .fd = g_event_pipe[0],
        .events = POLLIN,
        .revents = 0
    };
    int ret = poll(&pfd, 1, timeout_ms > 0 ? timeout_ms : 0);
    if (ret > 0 && (pfd.revents & POLLIN)) {
        ssize_t n = read(g_event_pipe[0], ev, sizeof(MyphpEvent));
        if (n == sizeof(MyphpEvent)) {
            LOGI("myphp_read_event: got event type=%d", ev->type);
            return 1;
        }
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
static jmethodID g_mid_clipboard_get = NULL;
static jmethodID g_mid_clipboard_set = NULL;
static jmethodID g_mid_open_url = NULL;
static jmethodID g_mid_keep_screen_on = NULL;
static jmethodID g_mid_show_alert_dialog = NULL;
static jmethodID g_mid_show_notification = NULL;
static jmethodID g_mid_is_network_connected = NULL;
static jmethodID g_mid_create_web_view = NULL;
static jmethodID g_mid_destroy_web_view = NULL;
static jmethodID g_mid_load_url = NULL;
static jmethodID g_mid_load_html = NULL;
static jmethodID g_mid_eval_js = NULL;
static jmethodID g_mid_place_web_view = NULL;
static jmethodID g_mid_bt_req_perm = NULL;
static jmethodID g_mid_bt_get_status = NULL;
static jmethodID g_mid_bt_scan_ble = NULL;
static jmethodID g_mid_bt_connect_l2cap = NULL;
static jmethodID g_mid_bt_disconnect = NULL;
static jmethodID g_mid_bt_is_connected = NULL;
static jmethodID g_mid_bt_send = NULL;
static jmethodID g_mid_bt_poll = NULL;
static jmethodID g_mid_bt_last_error = NULL;

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
    g_mid_clipboard_get = (*env)->GetStaticMethodID(env, clazz, "getClipboardText", "()Ljava/lang/String;");
    g_mid_clipboard_set = (*env)->GetStaticMethodID(env, clazz, "setClipboardText", "(Ljava/lang/String;)V");
    g_mid_open_url = (*env)->GetStaticMethodID(env, clazz, "openUrl", "(Ljava/lang/String;)V");
    g_mid_keep_screen_on = (*env)->GetStaticMethodID(env, clazz, "setKeepScreenOn", "(Z)V");
    g_mid_show_alert_dialog = (*env)->GetStaticMethodID(env, clazz, "showAlertDialog", "(Ljava/lang/String;Ljava/lang/String;[Ljava/lang/String;I)V");
    g_mid_show_notification = (*env)->GetStaticMethodID(env, clazz, "showNotification", "(Ljava/lang/String;Ljava/lang/String;I)V");
    g_mid_is_network_connected = (*env)->GetStaticMethodID(env, clazz, "isNetworkConnected", "()Z");
    g_mid_create_web_view = (*env)->GetStaticMethodID(env, clazz, "createWebView", "(I)V");
    g_mid_destroy_web_view = (*env)->GetStaticMethodID(env, clazz, "destroyWebView", "(I)V");
    g_mid_load_url = (*env)->GetStaticMethodID(env, clazz, "loadUrl", "(ILjava/lang/String;)V");
    g_mid_load_html = (*env)->GetStaticMethodID(env, clazz, "loadHtml", "(ILjava/lang/String;Ljava/lang/String;)V");
    g_mid_eval_js = (*env)->GetStaticMethodID(env, clazz, "evalJs", "(ILjava/lang/String;)V");
    g_mid_place_web_view = (*env)->GetStaticMethodID(env, clazz, "placeWebView", "(IIIIIZ)V");
    g_mid_bt_req_perm = (*env)->GetStaticMethodID(env, clazz, "btRequestPermissions", "()V");
    g_mid_bt_get_status = (*env)->GetStaticMethodID(env, clazz, "btGetStatus", "()Ljava/lang/String;");
    g_mid_bt_scan_ble = (*env)->GetStaticMethodID(env, clazz, "btScanBle", "()[B");
    g_mid_bt_connect_l2cap = (*env)->GetStaticMethodID(env, clazz, "btConnectL2cap", "(Ljava/lang/String;I)Z");
    g_mid_bt_disconnect = (*env)->GetStaticMethodID(env, clazz, "btDisconnect", "()V");
    g_mid_bt_is_connected = (*env)->GetStaticMethodID(env, clazz, "btIsConnected", "()Z");
    g_mid_bt_send = (*env)->GetStaticMethodID(env, clazz, "btSend", "([B)Z");
    g_mid_bt_poll = (*env)->GetStaticMethodID(env, clazz, "btPoll", "()[B");
    g_mid_bt_last_error = (*env)->GetStaticMethodID(env, clazz, "btGetLastError", "()Ljava/lang/String;");

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

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativePause(JNIEnv *env, jclass clazz) {
    LOGI("nativePause");
    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {.type = MYPHP_EVENT_PAUSE};
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeResume(JNIEnv *env, jclass clazz) {
    LOGI("nativeResume");
    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {.type = MYPHP_EVENT_RESUME};
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeConfigurationChanged(JNIEnv *env, jclass clazz, jboolean isDark, jint insetTop, jint insetBottom, jint insetLeft, jint insetRight) {
    LOGI("nativeConfigurationChanged: dark=%d insets=[%d, %d, %d, %d]", isDark, insetTop, insetBottom, insetLeft, insetRight);
    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {
            .type = MYPHP_EVENT_CONFIGURATION,
            .night_mode = isDark ? 1 : 0,
            .insets = {insetTop, insetBottom, insetLeft, insetRight}
        };
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
}

int myphp_clipboard_get_text(char *out, int max_len) {
    if (!out || max_len <= 0) return 0;
    out[0] = 0;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_clipboard_get) return 0;

    jstring jtext = (jstring)(*env)->CallStaticObjectMethod(env, g_activity_class, g_mid_clipboard_get);
    if (!jtext) return 0;

    const char *chars = (*env)->GetStringUTFChars(env, jtext, NULL);
    if (!chars) {
        (*env)->DeleteLocalRef(env, jtext);
        return 0;
    }

    strncpy(out, chars, max_len - 1);
    out[max_len - 1] = 0;
    int len = (int)strlen(out);

    (*env)->ReleaseStringUTFChars(env, jtext, chars);
    (*env)->DeleteLocalRef(env, jtext);
    return len;
}

void myphp_clipboard_set_text(const char *text) {
    if (!text) return;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_clipboard_set) return;

    jstring jtext = (*env)->NewStringUTF(env, text);
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_clipboard_set, jtext);
    (*env)->DeleteLocalRef(env, jtext);
}

void myphp_open_url(const char *url) {
    if (!url) return;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_open_url) return;

    jstring jurl = (*env)->NewStringUTF(env, url);
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_open_url, jurl);
    (*env)->DeleteLocalRef(env, jurl);
}

void myphp_set_keep_screen_on(int on) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_keep_screen_on) return;
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_keep_screen_on, (jboolean)(on ? JNI_TRUE : JNI_FALSE));
}

void myphp_show_alert_dialog(const char *title, const char *message, const char **buttons, int button_count, int callback_id) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_show_alert_dialog) return;

    jstring jtitle = title ? (*env)->NewStringUTF(env, title) : NULL;
    jstring jmsg = message ? (*env)->NewStringUTF(env, message) : NULL;

    jclass string_cls = (*env)->FindClass(env, "java/lang/String");
    jobjectArray jbtns = NULL;
    if (buttons && button_count > 0 && string_cls) {
        jbtns = (*env)->NewObjectArray(env, button_count, string_cls, NULL);
        for (int i = 0; i < button_count; i++) {
            if (buttons[i]) {
                jstring bstr = (*env)->NewStringUTF(env, buttons[i]);
                (*env)->SetObjectArrayElement(env, jbtns, i, bstr);
                (*env)->DeleteLocalRef(env, bstr);
            }
        }
    }

    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_show_alert_dialog, jtitle, jmsg, jbtns, callback_id);

    if (jtitle) (*env)->DeleteLocalRef(env, jtitle);
    if (jmsg) (*env)->DeleteLocalRef(env, jmsg);
    if (jbtns) (*env)->DeleteLocalRef(env, jbtns);
    if (string_cls) (*env)->DeleteLocalRef(env, string_cls);
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeDialogResult(JNIEnv *env, jclass clazz, jint callbackId, jint buttonIndex) {
    LOGI("nativeDialogResult: callbackId=%d buttonIndex=%d", callbackId, buttonIndex);
    if (g_event_pipe[1] >= 0) {
        MyphpEvent ev = {
            .type = MYPHP_EVENT_DIALOG_RESULT,
            .action = buttonIndex,
            .width = callbackId
        };
        write(g_event_pipe[1], &ev, sizeof(ev));
    }
}

void myphp_show_notification(const char *title, const char *body, int id) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_show_notification) return;

    jstring jtitle = title ? (*env)->NewStringUTF(env, title) : NULL;
    jstring jbody = body ? (*env)->NewStringUTF(env, body) : NULL;

    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_show_notification, jtitle, jbody, id);

    if (jtitle) (*env)->DeleteLocalRef(env, jtitle);
    if (jbody) (*env)->DeleteLocalRef(env, jbody);
}

int myphp_is_network_connected(void) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_is_network_connected) return 0;
    jboolean conn = (*env)->CallStaticBooleanMethod(env, g_activity_class, g_mid_is_network_connected);
    return conn ? 1 : 0;
}

void myphp_create_web_view(int page_id) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_create_web_view) return;
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_create_web_view, page_id);
}

void myphp_destroy_web_view(int page_id) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_destroy_web_view) return;
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_destroy_web_view, page_id);
}

void myphp_load_url(int page_id, const char *url) {
    if (!url) return;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_load_url) return;
    jstring jurl = (*env)->NewStringUTF(env, url);
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_load_url, page_id, jurl);
    (*env)->DeleteLocalRef(env, jurl);
}

void myphp_load_html(int page_id, const char *html, const char *base_url) {
    if (!html) return;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_load_html) return;
    jstring jhtml = (*env)->NewStringUTF(env, html);
    jstring jbase = base_url ? (*env)->NewStringUTF(env, base_url) : NULL;
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_load_html, page_id, jhtml, jbase);
    (*env)->DeleteLocalRef(env, jhtml);
    if (jbase) (*env)->DeleteLocalRef(env, jbase);
}

void myphp_eval_js(int page_id, const char *script) {
    if (!script) return;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_eval_js) return;
    jstring jscript = (*env)->NewStringUTF(env, script);
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_eval_js, page_id, jscript);
    (*env)->DeleteLocalRef(env, jscript);
}

void myphp_place_web_view(int page_id, int x, int y, int w, int h, int visible) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_place_web_view) return;
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_place_web_view, page_id, x, y, w, h, (jboolean)(visible ? JNI_TRUE : JNI_FALSE));
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeOnWebMessage(JNIEnv *env, jclass clazz, jint pageId, jstring jmsg) {
    if (g_event_pipe[1] < 0 || !jmsg) return;
    const char *chars = (*env)->GetStringUTFChars(env, jmsg, NULL);
    if (!chars) return;

    MyphpEvent ev = {
        .type = MYPHP_EVENT_WEB_MESSAGE,
        .action = pageId
    };
    strncpy(ev.text, chars, sizeof(ev.text) - 1);
    (*env)->ReleaseStringUTFChars(env, jmsg, chars);

    write(g_event_pipe[1], &ev, sizeof(ev));
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeSignal(JNIEnv *env, jclass clazz) {
    myphp_signal_backend();
}

// Probes which BluetoothSocket L2CAP constructor signature exists in ART via JNI
JNIEXPORT jstring JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeProbeL2capConstructor(JNIEnv *env, jclass clazz) {
    jclass sockCls = (*env)->FindClass(env, "android/bluetooth/BluetoothSocket");
    if (!sockCls) {
        if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
        return (*env)->NewStringUTF(env, "missing_class");
    }

    // 1. Android 16 QPR3 / Android 17 signature: (BluetoothAdapter, BluetoothDevice, int, boolean, boolean, int, ParcelUuid)
    jmethodID mid1 = (*env)->GetMethodID(env, sockCls, "<init>",
        "(Landroid/bluetooth/BluetoothAdapter;Landroid/bluetooth/BluetoothDevice;IZZILandroid/os/ParcelUuid;)V");
    if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
    if (mid1) {
        (*env)->DeleteLocalRef(env, sockCls);
        return (*env)->NewStringUTF(env, "A16QPR3_A17(Adapter,Device,type,auth,enc,psm,uuid)");
    }

    // 2. Android 14-16 signature: (BluetoothDevice, int, boolean, boolean, int, ParcelUuid)
    jmethodID mid2 = (*env)->GetMethodID(env, sockCls, "<init>",
        "(Landroid/bluetooth/BluetoothDevice;IZZILandroid/os/ParcelUuid;)V");
    if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
    if (mid2) {
        (*env)->DeleteLocalRef(env, sockCls);
        return (*env)->NewStringUTF(env, "A14_16(Device,type,auth,enc,psm,uuid)");
    }

    // 3. Android 12-13 signature: (int, int, boolean, boolean, BluetoothDevice, int, ParcelUuid)
    jmethodID mid3 = (*env)->GetMethodID(env, sockCls, "<init>",
        "(IIZZLandroid/bluetooth/BluetoothDevice;ILandroid/os/ParcelUuid;)V");
    if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
    (*env)->DeleteLocalRef(env, sockCls);
    if (mid3) {
        return (*env)->NewStringUTF(env, "A12_13(type,fd,auth,enc,Device,psm,uuid)");
    }
    return (*env)->NewStringUTF(env, "unknown_ctor");
}

// Instantiates BluetoothSocket for Classic L2CAP (type = 3) directly from JNI
JNIEXPORT jobject JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeCreateL2capSocket(
    JNIEnv *env, jclass clazz, jobject adapter, jobject device, jint psm, jobject uuid
) {
    jclass sockCls = (*env)->FindClass(env, "android/bluetooth/BluetoothSocket");
    if (!sockCls) {
        if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
        return NULL;
    }

    const jint TYPE_L2CAP = 3;
    jobject sock = NULL;

    // 1. Android 16 QPR3 / Android 17 signature
    jmethodID mid1 = (*env)->GetMethodID(env, sockCls, "<init>",
        "(Landroid/bluetooth/BluetoothAdapter;Landroid/bluetooth/BluetoothDevice;IZZILandroid/os/ParcelUuid;)V");
    if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
    if (mid1) {
        sock = (*env)->NewObject(env, sockCls, mid1, adapter, device, TYPE_L2CAP, JNI_TRUE, JNI_TRUE, psm, uuid);
        if ((*env)->ExceptionCheck(env)) {
            (*env)->ExceptionClear(env);
            sock = NULL;
        }
        (*env)->DeleteLocalRef(env, sockCls);
        return sock;
    }

    // 2. Android 14-16 signature
    jmethodID mid2 = (*env)->GetMethodID(env, sockCls, "<init>",
        "(Landroid/bluetooth/BluetoothDevice;IZZILandroid/os/ParcelUuid;)V");
    if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
    if (mid2) {
        sock = (*env)->NewObject(env, sockCls, mid2, device, TYPE_L2CAP, JNI_TRUE, JNI_TRUE, psm, uuid);
        if ((*env)->ExceptionCheck(env)) {
            (*env)->ExceptionClear(env);
            sock = NULL;
        }
        (*env)->DeleteLocalRef(env, sockCls);
        return sock;
    }

    // 3. Android 12-13 signature
    jmethodID mid3 = (*env)->GetMethodID(env, sockCls, "<init>",
        "(IIZZLandroid/bluetooth/BluetoothDevice;ILandroid/os/ParcelUuid;)V");
    if ((*env)->ExceptionCheck(env)) (*env)->ExceptionClear(env);
    if (mid3) {
        sock = (*env)->NewObject(env, sockCls, mid3, TYPE_L2CAP, -1, JNI_TRUE, JNI_TRUE, device, psm, uuid);
        if ((*env)->ExceptionCheck(env)) {
            (*env)->ExceptionClear(env);
            sock = NULL;
        }
    }
    (*env)->DeleteLocalRef(env, sockCls);
    return sock;
}

void myphp_bt_request_permissions(void) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_bt_req_perm) return;
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_bt_req_perm);
}

int myphp_bt_get_status(char *out, int max_len) {
    if (!out || max_len <= 0) return 0;
    out[0] = 0;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_bt_get_status) return 0;

    jstring jstr = (jstring)(*env)->CallStaticObjectMethod(env, g_activity_class, g_mid_bt_get_status);
    if (!jstr) return 0;
    const char *chars = (*env)->GetStringUTFChars(env, jstr, NULL);
    if (!chars) {
        (*env)->DeleteLocalRef(env, jstr);
        return 0;
    }
    strncpy(out, chars, max_len - 1);
    out[max_len - 1] = 0;
    int len = (int)strlen(out);
    (*env)->ReleaseStringUTFChars(env, jstr, chars);
    (*env)->DeleteLocalRef(env, jstr);
    return len;
}

int myphp_bt_scan_ble(uint8_t *out, int max_len) {
    if (!out || max_len <= 0) return 0;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_bt_scan_ble) return 0;

    jbyteArray jarr = (jbyteArray)(*env)->CallStaticObjectMethod(env, g_activity_class, g_mid_bt_scan_ble);
    if (!jarr) return 0;
    jsize len = (*env)->GetArrayLength(env, jarr);
    if (len > max_len) len = max_len;
    (*env)->GetByteArrayRegion(env, jarr, 0, len, (jbyte*)out);
    (*env)->DeleteLocalRef(env, jarr);
    return (int)len;
}

int myphp_bt_connect_l2cap(const char *mac, int psm, char *err_out, int err_max) {
    if (err_out && err_max > 0) err_out[0] = 0;
    if (!mac) return 0;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_bt_connect_l2cap) return 0;

    jstring jmac = (*env)->NewStringUTF(env, mac);
    jboolean ok = (*env)->CallStaticBooleanMethod(env, g_activity_class, g_mid_bt_connect_l2cap, jmac, psm);
    (*env)->DeleteLocalRef(env, jmac);

    if (!ok && err_out && err_max > 0 && g_mid_bt_last_error) {
        jstring jerr = (jstring)(*env)->CallStaticObjectMethod(env, g_activity_class, g_mid_bt_last_error);
        if (jerr) {
            const char *chars = (*env)->GetStringUTFChars(env, jerr, NULL);
            if (chars) {
                strncpy(err_out, chars, err_max - 1);
                err_out[err_max - 1] = 0;
                (*env)->ReleaseStringUTFChars(env, jerr, chars);
            }
            (*env)->DeleteLocalRef(env, jerr);
        }
    }
    return ok ? 1 : 0;
}

void myphp_bt_disconnect(void) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_bt_disconnect) return;
    (*env)->CallStaticVoidMethod(env, g_activity_class, g_mid_bt_disconnect);
}

int myphp_bt_is_connected(void) {
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_bt_is_connected) return 0;
    jboolean ok = (*env)->CallStaticBooleanMethod(env, g_activity_class, g_mid_bt_is_connected);
    return ok ? 1 : 0;
}

int myphp_bt_send(const uint8_t *data, int len) {
    if (!data || len <= 0) return 0;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_bt_send) return 0;

    jbyteArray jarr = (*env)->NewByteArray(env, len);
    if (!jarr) return 0;
    (*env)->SetByteArrayRegion(env, jarr, 0, len, (const jbyte*)data);
    jboolean ok = (*env)->CallStaticBooleanMethod(env, g_activity_class, g_mid_bt_send, jarr);
    (*env)->DeleteLocalRef(env, jarr);
    return ok ? 1 : 0;
}

int myphp_bt_poll(uint8_t *out, int max_len) {
    if (!out || max_len <= 0) return 0;
    JNIEnv *env = get_jni_env();
    if (!env || !g_activity_class || !g_mid_bt_poll) return 0;

    jbyteArray jarr = (jbyteArray)(*env)->CallStaticObjectMethod(env, g_activity_class, g_mid_bt_poll);
    if (!jarr) return 0;
    jsize len = (*env)->GetArrayLength(env, jarr);
    if (len > max_len) len = max_len;
    (*env)->GetByteArrayRegion(env, jarr, 0, len, (jbyte*)out);
    (*env)->DeleteLocalRef(env, jarr);
    return (int)len;
}
