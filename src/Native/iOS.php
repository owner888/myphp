<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;

final class iOS
{
    private static ?FFI $ffi = null;

    public static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef("
            typedef struct {
                int type;
                float x;
                float y;
                double timestamp;
            } MyphpTouchEvent;

            typedef struct {
                int type;
                char text[256];
            } MyphpInputEvent;

            typedef struct {
                int64_t id;
                float x, y, w, h, radius, alpha;
                int32_t kind, dark;
            } MyphpMaterialPlace;

            int myphp_ios_get_screen_width(void);
            int myphp_ios_get_screen_height(void);
            float myphp_ios_get_screen_scale(void);
            void myphp_ios_get_safe_insets(float *top, float *bottom, float *left, float *right);
            void* myphp_ios_get_view(void);
            void myphp_ios_request_render(void);
            int myphp_ios_take_needs_render(void);
            int myphp_ios_poll_touch_event(MyphpTouchEvent *ev);
            int myphp_ios_poll_input_event(MyphpInputEvent *ev);
            int myphp_ios_poll_open_url(char *out, int max_len);
            void myphp_ios_haptic_feedback(int style);
            void myphp_ios_show_alert_dialog(const char *title, const char *message, const char **buttons, int button_count, int callback_id);
            int myphp_ios_get_dialog_result(int callback_id);
            void myphp_ios_pick_files(const char **extensions, int count, int multiple, int photos, int callback_id);
            int myphp_ios_take_files_result(int callback_id, char *out, int max_len);
            float myphp_ios_get_keyboard_height(void);
            int myphp_ios_is_dark(void);
            void* myphp_ios_get_over_view(void);
            void myphp_ios_log(int level, const char *message);
            void myphp_ios_place_materials(const MyphpMaterialPlace *places, int count);
            float myphp_ios_text_scale(void);
            int myphp_ios_reduce_motion(void);
            char *myphp_ios_clipboard_get_text(void);
            void myphp_ios_clipboard_set_text(const char *text);
            void myphp_ios_free_string(char *s);
            void myphp_ios_open_url(const char *url);
            void myphp_ios_set_idle_timer_disabled(int disabled);
            int myphp_ios_symbol_exists(const char *name);
            int myphp_ios_draw_symbol(const char *name, float size, float weight, int rgb, float scale, int *out_w, int *out_h, uint8_t *out, int max_bytes);
            void myphp_ios_show_notification(const char *title, const char *body, const char *identifier);
            void myphp_ios_remove_notification(const char *identifier);
            void myphp_ios_remove_all_notifications(void);
            int myphp_ios_is_network_connected(void);
            int myphp_ios_is_network_cellular(void);
            int myphp_ios_poll_theme_changed(void);
            int myphp_ios_is_app_paused(void);
            int myphp_ios_poll_memory_warning(void);
            void myphp_ios_create_web_view(int page_id);
            void myphp_ios_destroy_web_view(int page_id);
            void myphp_ios_load_url(int page_id, const char *url);
            void myphp_ios_load_html(int page_id, const char *html, const char *base_url);
            void myphp_ios_eval_js(int page_id, const char *script);
            void myphp_ios_place_web_view(int page_id, float x, float y, float w, float h, int visible);
            char *myphp_ios_poll_web_message(int *out_page_id);
            void myphp_ios_free_web_message(char *msg);
            void myphp_ios_show_keyboard(void);
            void myphp_ios_hide_keyboard(void);
        ");
    }
}
