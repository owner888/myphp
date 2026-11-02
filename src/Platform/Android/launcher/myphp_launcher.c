#include <jni.h>
#include <android/log.h>
#include <android/looper.h>
#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <fcntl.h>

#include <main/php.h>
#include <sapi/embed/php_embed.h>
#include <Zend/zend.h>

#define LOG_TAG "MyPHP"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

static char g_files_dir[1024] = {0};
static char g_cache_dir[1024] = {0};
static pthread_t g_php_thread;

// Redirect stdout / stderr to Android logcat
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

static void* php_worker_thread(void *arg) {
    LOGI("PHP thread starting...");

    // Prepare ALooper for this thread (will be used by run loop in P1)
    ALooper *looper = ALooper_prepare(ALOOPER_PREPARE_ALLOW_NON_CALLBACKS);
    if (!looper) {
        LOGE("Failed to prepare ALooper on PHP thread");
    } else {
        LOGI("ALooper prepared on PHP thread");
    }

    // Set custom SAPI write/log handlers and default INI entries
    php_embed_module.ub_write = myphp_ub_write;
    php_embed_module.log_message = myphp_log_message;
    php_embed_module.ini_entries = "ffi.enable=1\nopcache.enable=1\nopcache.enable_cli=1\n";

    // Set up dummy argv
    char *argv[] = {"myphp", NULL};
    int argc = 1;

    LOGI("Initializing PHP Embed SAPI...");
    if (php_embed_init(argc, argv) != SUCCESS) {
        LOGE("Failed to initialize PHP embed");
        return NULL;
    }

    LOGI("PHP Embed initialized successfully! PHP_VERSION=%s", PHP_VERSION);

    zend_first_try {
        // Hello test
        char init_code[2048];
        snprintf(init_code, sizeof(init_code),
            "echo \"[myphp] hello from PHP \" . PHP_VERSION . \" on Android (PID: \" . posix_getpid() . \")\\n\";"
            "$app = '%s/app.php';"
            "if (file_exists($app)) {"
            "    require $app;"
            "} else {"
            "    echo \"[myphp] no app.php found at \" . $app . \"\\n\";"
            "}",
            g_files_dir
        );

        LOGI("Executing initial PHP code...");
        zend_eval_string(init_code, NULL, "myphp_bootstrap");
    } zend_catch {
        LOGE("Uncaught PHP exception/bailout in bootstrap");
    } zend_end_try();

    LOGI("PHP bootstrap execution completed.");

    // Keep the thread alive for subsequent events (or run looper in P1)
    while (1) {
        // Poll looper with timeout
        int ident = ALooper_pollOnce(1000, NULL, NULL, NULL);
        if (ident == ALOOPER_POLL_ERROR) {
            LOGE("ALooper_pollOnce error");
            break;
        }
    }

    php_embed_shutdown();
    LOGI("PHP thread exited.");
    return NULL;
}

JNIEXPORT void JNICALL
Java_com_owner888_myphp_MyphpActivity_nativeInit(JNIEnv *env, jobject thiz, jstring filesDir, jstring cacheDir) {
    const char *files_c = (*env)->GetStringUTFChars(env, filesDir, NULL);
    const char *cache_c = (*env)->GetStringUTFChars(env, cacheDir, NULL);

    strncpy(g_files_dir, files_c, sizeof(g_files_dir) - 1);
    strncpy(g_cache_dir, cache_c, sizeof(g_cache_dir) - 1);

    (*env)->ReleaseStringUTFChars(env, filesDir, files_c);
    (*env)->ReleaseStringUTFChars(env, cacheDir, cache_c);

    LOGI("nativeInit called: filesDir=%s, cacheDir=%s", g_files_dir, g_cache_dir);

    start_stdio_redirect();

    // Start PHP thread
    if (pthread_create(&g_php_thread, NULL, php_worker_thread, NULL) != 0) {
        LOGE("Failed to create PHP pthread");
    } else {
        pthread_detach(g_php_thread);
        LOGI("PHP thread created");
    }
}

JNIEXPORT jint JNI_OnLoad(JavaVM *vm, void *reserved) {
    LOGI("libmyphp.so JNI_OnLoad");
    return JNI_VERSION_1_6;
}
