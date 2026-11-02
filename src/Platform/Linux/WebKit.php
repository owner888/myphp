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
        typedef int (*CbBool)(void*, void*, void*);
        typedef int (*CbDestination)(void*, const char*, void*);
        typedef int (*CbTls)(void*, const char*, void*, unsigned int, void*);
        typedef int (*CbMenu)(void*, void*, void*, void*, void*);
        typedef void (*CbPng)(void*, void*);
        typedef struct { CbBool fn; } HBool; typedef struct { CbDestination fn; } HDestination; typedef struct { CbTls fn; } HTls;
        typedef struct { CbMenu fn; } HMenu;
        typedef struct { unsigned char* data; unsigned int len; } GByteArray;
        typedef struct { unsigned long g_type; } GTypeClass;
        typedef struct { int value; const char* value_name; const char* value_nick; } GEnumValue;
        typedef struct _GList { void* data; struct _GList* next; struct _GList* prev; } GList;

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
        gboolean g_type_check_instance_is_a(void*, unsigned long);
        void* g_type_class_ref(unsigned long);
        GEnumValue* g_enum_get_value(void*, int);
        void g_object_get(void*, const char*, ...);
        void g_byte_array_unref(GByteArray*);
        void* g_bytes_new(const char*, size_t); void* g_bytes_get_data(void*, size_t*); void g_bytes_unref(void*);
        void* g_simple_action_new(const char*, void*); void* g_simple_action_new_stateful(const char*, void*, void*);
        void g_simple_action_set_enabled(void*, gboolean); void g_simple_action_set_state(void*, void*);
        unsigned long g_simple_action_get_type(void);
        gboolean g_action_get_enabled(void*); void* g_action_get_state(void*);
        void* g_variant_new_boolean(gboolean); gboolean g_variant_get_boolean(void*); const char* g_variant_get_type_string(void*); void g_variant_unref(void*);
        const char* gtk_action_get_label(void*);
        void* gtk_print_settings_new(void); void gtk_print_settings_set(void*, const char*, const char*);
        void* gtk_page_setup_new(void); void gtk_page_setup_set_paper_size(void*, void*); void gtk_page_setup_set_orientation(void*, int);
        void gtk_page_setup_set_top_margin(void*, double, int); void gtk_page_setup_set_bottom_margin(void*, double, int);
        void gtk_page_setup_set_left_margin(void*, double, int); void gtk_page_setup_set_right_margin(void*, double, int);
        void* gtk_paper_size_new_custom(const char*, const char*, double, double, int); void gtk_paper_size_free(void*);
        int cairo_surface_write_to_png(void*, const char*); void cairo_surface_destroy(void*);
        gboolean gtk_widget_has_focus(void*);

        void* webkit_web_view_get_context(void*);
        void webkit_web_context_allow_tls_certificate_for_host(void*, void*, const char*);
        const char* webkit_authentication_request_get_host(void*);
        unsigned int webkit_authentication_request_get_port(void*);
        const char* webkit_authentication_request_get_realm(void*);
        int webkit_authentication_request_get_scheme(void*);
        gboolean webkit_authentication_request_is_for_proxy(void*);
        void webkit_authentication_request_authenticate(void*, void*);
        void* webkit_credential_new(const char*, const char*, int); void webkit_credential_free(void*);

        void* webkit_download_get_web_view(void*); void* webkit_download_get_request(void*);
        void webkit_download_cancel(void*); void webkit_download_set_allow_overwrite(void*, gboolean);
        void webkit_download_set_destination(void*, const char*);
        void* webkit_response_policy_decision_get_response(void*);
        gboolean webkit_response_policy_decision_is_mime_type_supported(void*);
        void webkit_policy_decision_download(void*);
        const char* webkit_uri_response_get_uri(void*); void* webkit_uri_response_get_http_headers(void*);
        const char* soup_message_headers_get_one(void*, const char*);

        unsigned long webkit_user_media_permission_request_get_type(void);
        unsigned long webkit_geolocation_permission_request_get_type(void);
        unsigned long webkit_notification_permission_request_get_type(void);
        gboolean webkit_user_media_permission_is_for_audio_device(void*);
        gboolean webkit_user_media_permission_is_for_video_device(void*);
        void webkit_permission_request_allow(void*); void webkit_permission_request_deny(void*);

        int webkit_script_dialog_get_dialog_type(void*);
        const char* webkit_script_dialog_get_message(void*);
        void webkit_script_dialog_confirm_set_confirmed(void*, gboolean);
        const char* webkit_script_dialog_prompt_get_default_text(void*);
        void webkit_script_dialog_prompt_set_text(void*, const char*);
        gboolean webkit_file_chooser_request_get_select_multiple(void*);
        void webkit_file_chooser_request_select_files(void*, const char**);
        void webkit_file_chooser_request_cancel(void*);

        void* webkit_web_view_get_inspector(void*);
        void webkit_web_inspector_show(void*); void webkit_web_inspector_close(void*);
        void* webkit_web_inspector_get_web_view(void*);

        void webkit_web_view_get_snapshot(void*, int, int, void*, CbNotify, void*);
        void* webkit_web_view_get_snapshot_finish(void*, void*, void**);
        void* webkit_print_operation_new(void*);
        int webkit_print_operation_run_dialog(void*, void*);
        void webkit_print_operation_set_print_settings(void*, void*);
        void webkit_print_operation_set_page_setup(void*, void*);
        void webkit_print_operation_print(void*);
        gboolean webkit_settings_get_print_backgrounds(void*); void webkit_settings_set_print_backgrounds(void*, gboolean);

        void* webkit_web_view_get_session_state(void*);
        void webkit_web_view_restore_session_state(void*, void*);
        void* webkit_web_view_session_state_new(void*);
        void* webkit_web_view_session_state_serialize(void*);
        void webkit_web_view_session_state_unref(void*);
        void* webkit_web_view_get_back_forward_list(void*);
        void* webkit_back_forward_list_get_current_item(void*);
        void webkit_web_view_go_to_back_forward_list_item(void*, void*);

        void* webkit_context_menu_new(void);
        GList* webkit_context_menu_get_items(void*);
        void webkit_context_menu_append(void*, void*);
        void webkit_context_menu_remove_all(void*);
        gboolean webkit_context_menu_item_is_separator(void*);
        int webkit_context_menu_item_get_stock_action(void*);
        void* webkit_context_menu_item_get_submenu(void*);
        void* webkit_context_menu_item_get_action(void*);
        void* webkit_context_menu_item_get_gaction(void*);
        void* webkit_context_menu_item_new_from_stock_action_with_label(int, const char*);
        void* webkit_context_menu_item_new_from_gaction(void*, const char*, void*);
        void* webkit_context_menu_item_new_separator(void);
        void* webkit_context_menu_item_new_with_submenu(const char*, void*);
        unsigned long webkit_context_menu_action_get_type(void);
        void webkit_web_view_execute_editing_command(void*, const char*);
        void webkit_web_view_set_background_color(void*, void*);

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
