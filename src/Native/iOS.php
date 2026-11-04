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

            int myphp_ios_get_screen_width(void);
            int myphp_ios_get_screen_height(void);
            float myphp_ios_get_screen_scale(void);
            void myphp_ios_get_safe_insets(float *top, float *bottom, float *left, float *right);
            void* myphp_ios_get_view(void);
            void myphp_ios_request_render(void);
            int myphp_ios_poll_touch_event(MyphpTouchEvent *ev);
            int myphp_ios_poll_input_event(MyphpInputEvent *ev);
            void myphp_ios_show_alert_dialog(const char *title, const char *message, const char **buttons, int button_count, int callback_id);
            int myphp_ios_get_dialog_result(int callback_id);
            void myphp_ios_show_keyboard(void);
            void myphp_ios_hide_keyboard(void);
        ");
    }
}
