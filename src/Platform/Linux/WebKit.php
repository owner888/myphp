<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI;
use FFI\CData;
use MyPHP\Platform\Unsupported;

/**
 * WebKitGTK 4.1, with JavaScriptCore and libsoup 3, over an FFI instance
 * of its own (mygo's loadWebKit): GTK's (Linux\Gtk) cannot resolve them,
 * as dlsym searches a library and its dependencies only. GLib's, GIO's
 * and libc's functions a page needs come through it too, being its
 * dependencies. Pointers pass between the two instances as void*.
 */
final class WebKit
{
    private const LIBRARY = 'libwebkit2gtk-4.1.so.0';
    private static ?FFI $f = null;
    private static ?string $error = null;

    /** The instance, loaded the first time; throws Unsupported where WebKitGTK 4.1 is not installed. */
    public static function ffi(): FFI
    {
        if (self::$f !== null) {
            return self::$f;
        }
        if (self::$error !== null) {
            throw new Unsupported(self::$error);
        }
        try {
            // Loaded for good (RTLD_NOW | RTLD_NODELETE): PHP closes the FFI's library as it shuts down, and WebKit's threads, still
            // running as the process exits, crashed in code unmapped under them.
            FFI::cdef('void* dlopen(const char*, int);', 'libc.so.6')->dlopen(self::LIBRARY, 2 | 0x1000);
            self::$f = FFI::cdef(self::CDEF, self::LIBRARY);
        } catch (FFI\Exception $e) {
            self::$error = 'myphp: web pages need WebKitGTK 4.1 (libwebkit2gtk-4.1-0 on Debian/Ubuntu, webkit2gtk4.1 on Fedora): ' . $e->getMessage();
            throw new Unsupported(self::$error);
        }
        return self::$f;
    }

    /** Whether WebKitGTK loads (mygo's WebViewAvailable). */
    public static function available(): bool
    {
        try {
            self::ffi();
            return true;
        } catch (Unsupported) {
            return false;
        }
    }

    /** The integer a void* holds, its address. */
    public static function addr(?CData $p): int
    {
        return Gtk::addr($p);
    }

    /** A GError's message, the error freed. */
    public static function errorMessage(CData $error): string
    {
        $e = self::ffi()->cast('GError*', $error);
        $message = \FFI::string($e->message);
        self::ffi()->g_error_free($e);
        return $message;
    }

    /** A string WebKit, GLib or JavaScriptCore gave away (char*), freed; '' for NULL. */
    public static function take(?CData $s): string
    {
        if ($s === null) {
            return '';
        }
        $out = \FFI::string($s);
        self::ffi()->g_free($s);
        return $out;
    }

    private const CDEF = '
        typedef int gboolean; typedef unsigned int guint; typedef unsigned long gulong;
        typedef struct { unsigned int domain; int code; char* message; } GError;
        typedef void (*CbLoad)(void*, int, void*);
        typedef int (*CbFailed)(void*, int, const char*, void*, void*);
        typedef void (*CbNotify)(void*, void*, void*);
        typedef void (*CbPlain)(void*, void*);
        typedef int (*CbDecide)(void*, void*, int, void*);
        typedef void* (*CbCreate)(void*, void*, void*);
        typedef void (*CbGone)(void*, int, void*);
        typedef void (*CbScheme)(void*, void*);
        typedef void (*CbHeader)(const char*, const char*, void*);
        typedef int (*CbFd)(int, unsigned int, void*);
        typedef struct { CbLoad fn; } HLoad; typedef struct { CbFailed fn; } HFailed; typedef struct { CbNotify fn; } HNotify;
        typedef struct { CbPlain fn; } HPlain; typedef struct { CbDecide fn; } HDecide; typedef struct { CbCreate fn; } HCreate;
        typedef struct { CbGone fn; } HGone; typedef struct { CbScheme fn; } HScheme; typedef struct { CbHeader fn; } HHeader;
        typedef struct { CbFd fn; } HFd;

        gulong g_signal_connect_data(void*, const char*, void*, void*, void*, int);
        void* g_object_ref(void*); void g_object_unref(void*); void g_free(void*);
        void g_object_set(void*, const char*, ...);
        void* g_object_get_data(void*, const char*);
        const char* g_param_spec_get_name(void*);
        void g_error_free(GError*);
        GError* g_error_new_literal(unsigned int, int, const char*);
        unsigned int g_quark_from_string(const char*);
        guint g_unix_fd_add_full(int, int, unsigned int, CbFd, void*, void*);
        gboolean g_source_remove(guint);
        gboolean g_unix_open_pipe(int*, int, void**);
        gboolean g_unix_set_fd_nonblocking(int, gboolean, void**);
        void* g_unix_input_stream_new(int, gboolean);
        gboolean g_input_stream_read_all(void*, void*, size_t, size_t*, void*, void**);
        void* g_file_new_for_path(const char*); char* g_file_get_uri(void*);
        long write(int, const char*, size_t); int close(int); int* __errno_location(void);
        void gtk_event_controller_set_propagation_phase(void*, int);

        void* webkit_web_context_get_default(void);
        void webkit_web_context_register_uri_scheme(void*, const char*, CbScheme, void*, void*);
        void* webkit_web_context_get_security_manager(void*);
        void webkit_security_manager_register_uri_scheme_as_secure(void*, const char*);
        void webkit_security_manager_register_uri_scheme_as_cors_enabled(void*, const char*);

        void* webkit_user_content_manager_new(void);
        gboolean webkit_user_content_manager_register_script_message_handler(void*, const char*);
        void webkit_user_content_manager_unregister_script_message_handler(void*, const char*);
        void webkit_user_content_manager_add_script(void*, void*);
        void webkit_user_content_manager_remove_all_scripts(void*);
        void* webkit_user_script_new(const char*, int, int, void*, void*);
        void webkit_user_script_unref(void*);
        void* webkit_javascript_result_get_js_value(void*);
        char* jsc_value_to_string(void*);
        gboolean jsc_value_is_undefined(void*); gboolean jsc_value_is_null(void*);

        void* webkit_web_view_new_with_user_content_manager(void*);
        void* webkit_web_view_get_settings(void*);
        void webkit_settings_set_enable_developer_extras(void*, gboolean);
        void webkit_settings_set_allow_file_access_from_file_urls(void*, gboolean);
        void webkit_settings_set_javascript_can_access_clipboard(void*, gboolean);
        void webkit_settings_set_user_agent(void*, const char*);
        const char* webkit_settings_get_user_agent(void*);
        void* webkit_settings_get_all_features(void);
        size_t webkit_feature_list_get_length(void*);
        void* webkit_feature_list_get(void*, size_t);
        void webkit_feature_list_unref(void*);
        const char* webkit_feature_get_identifier(void*);
        void webkit_settings_set_feature_enabled(void*, void*, gboolean);

        void webkit_web_view_load_uri(void*, const char*);
        void webkit_web_view_load_html(void*, const char*, const char*);
        void webkit_web_view_reload(void*); void webkit_web_view_reload_bypass_cache(void*);
        void webkit_web_view_stop_loading(void*);
        void webkit_web_view_go_back(void*); void webkit_web_view_go_forward(void*);
        gboolean webkit_web_view_can_go_back(void*); gboolean webkit_web_view_can_go_forward(void*);
        const char* webkit_web_view_get_uri(void*);
        const char* webkit_web_view_get_title(void*);
        gboolean webkit_web_view_is_loading(void*);
        void webkit_web_view_set_zoom_level(void*, double); double webkit_web_view_get_zoom_level(void*);
        gboolean webkit_web_view_is_playing_audio(void*);
        void webkit_web_view_set_is_muted(void*, gboolean); gboolean webkit_web_view_get_is_muted(void*);
        int webkit_web_view_get_camera_capture_state(void*); void webkit_web_view_set_camera_capture_state(void*, int);
        int webkit_web_view_get_microphone_capture_state(void*); void webkit_web_view_set_microphone_capture_state(void*, int);
        int webkit_web_view_get_display_capture_state(void*); void webkit_web_view_set_display_capture_state(void*, int);
        void webkit_web_view_evaluate_javascript(void*, const char*, long, const char*, const char*, void*, void*, void*);
        void webkit_web_view_call_async_javascript_function(void*, const char*, long, void*, const char*, const char*, void*, CbNotify, void*);
        void* webkit_web_view_call_async_javascript_function_finish(void*, void*, void**);

        void* webkit_navigation_policy_decision_get_navigation_action(void*);
        const char* webkit_navigation_policy_decision_get_frame_name(void*);
        int webkit_navigation_action_get_navigation_type(void*);
        gboolean webkit_navigation_action_is_user_gesture(void*);
        void* webkit_navigation_action_get_request(void*);
        const char* webkit_uri_request_get_uri(void*);
        void webkit_policy_decision_use(void*); void webkit_policy_decision_ignore(void*);
        void webkit_policy_decision_use_with_policies(void*, void*);
        void* webkit_website_policies_new_with_policies(const char*, ...);

        const char* webkit_uri_scheme_request_get_uri(void*);
        void* webkit_uri_scheme_request_get_web_view(void*);
        const char* webkit_uri_scheme_request_get_http_method(void*);
        void* webkit_uri_scheme_request_get_http_headers(void*);
        void* webkit_uri_scheme_request_get_http_body(void*);
        void webkit_uri_scheme_request_finish_error(void*, GError*);
        void webkit_uri_scheme_request_finish_with_response(void*, void*);
        void* webkit_uri_scheme_response_new(void*, long);
        void webkit_uri_scheme_response_set_status(void*, unsigned int, const char*);
        void webkit_uri_scheme_response_set_content_type(void*, const char*);
        void webkit_uri_scheme_response_set_http_headers(void*, void*);
        void* soup_message_headers_new(int);
        void soup_message_headers_append(void*, const char*, const char*);
        void soup_message_headers_foreach(void*, CbHeader, void*);
    ';
}
