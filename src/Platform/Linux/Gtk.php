<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use FFI;
use FFI\CData;
use RuntimeException;

/**
 * GTK 3, GDK, GLib and GObject over one FFI instance (mygo's linux ffi.go):
 * the functions the Linux backend calls, the structs of GDK's events, and
 * signal handlers and sources made of PHP closures, kept alive here for as
 * long as their object lives (C keeps only their thunks).
 */
final class Gtk
{
    private static ?FFI $f = null;
    /** @var array<int, list<CData>> the holders of the closures connected to each object, by its address */
    private static array $kept = [];
    private static ?CData $slot = null, $slotInt = null;

    public static function ffi(): FFI
    {
        if (self::$f !== null) {
            return self::$f;
        }
        self::$f = FFI::cdef('
            typedef int gboolean; typedef unsigned int guint; typedef unsigned long gulong; typedef void* gpointer;
            typedef int (*CbEvent)(void*, void*, void*);
            typedef void (*CbWidget)(void*, void*);
            typedef void (*CbSize)(void*, int, int, void*);
            typedef void (*CbString)(void*, const char*, void*);
            typedef int (*CbSource)(void*);
            typedef int (*CbTick)(void*, void*, void*);
            typedef struct { CbEvent fn; } HEvent; typedef struct { CbWidget fn; } HWidget; typedef struct { CbSize fn; } HSize;
            typedef struct { CbString fn; } HString; typedef struct { CbSource fn; } HSource; typedef struct { CbTick fn; } HTick;
            typedef struct { int x, y, width, height; } GdkRectangle;
            typedef struct { int min_width, min_height, max_width, max_height, base_width, base_height, width_inc, height_inc; double min_aspect, max_aspect; int win_gravity; } GdkGeometry;
            typedef struct { int type; void* window; signed char send_event; unsigned int time; double x; double y; double* axes; unsigned int state; unsigned int button; void* device; double x_root, y_root; } GdkEventButton;
            typedef struct { int type; void* window; signed char send_event; unsigned int time; double x; double y; double* axes; unsigned int state; short is_hint; void* device; double x_root, y_root; } GdkEventMotion;
            typedef struct { int type; void* window; signed char send_event; unsigned int time; double x; double y; unsigned int state; int direction; void* device; double x_root, y_root; double delta_x; double delta_y; unsigned int is_stop : 1; } GdkEventScroll;
            typedef struct { int type; void* window; signed char send_event; unsigned int time; unsigned int state; unsigned int keyval; int length; char* string; unsigned short hardware_keycode; unsigned char group; unsigned int is_modifier : 1; } GdkEventKey;
            typedef struct { int type; void* window; signed char send_event; void* subwindow; unsigned int time; double x; double y; double x_root; double y_root; int mode; int detail; int focus; unsigned int state; } GdkEventCrossing;
            typedef struct { int type; void* window; signed char send_event; short in; } GdkEventFocus;
            typedef struct { int type; void* window; signed char send_event; int changed_mask; int new_window_state; } GdkEventWindowState;
            typedef struct { int type; void* window; signed char send_event; int x, y; int width; int height; } GdkEventConfigure;

            gboolean gtk_init_check(int*, char***);
            void gtk_main(void); void gtk_main_quit(void); guint gtk_main_level(void);
            guint g_idle_add_full(int, CbSource, void*, void*);
            guint g_timeout_add_full(int, guint, CbSource, void*, void*);
            gboolean g_source_remove(guint);
            gboolean g_main_context_iteration(void*, gboolean);
            void g_main_context_wakeup(void*);
            gulong g_signal_connect_data(void*, const char*, void*, void*, void*, int);
            void* g_object_ref(void*); void g_object_unref(void*); void g_free(void*);
            void g_object_get(void*, const char*, ...);
            void g_set_prgname(const char*); void g_set_application_name(const char*);

            void* gtk_window_new(int);
            void gtk_window_set_title(void*, const char*);
            void gtk_window_set_default_size(void*, int, int);
            void gtk_window_resize(void*, int, int);
            void gtk_window_move(void*, int, int);
            void gtk_window_get_size(void*, int*, int*);
            void gtk_window_get_position(void*, int*, int*);
            void gtk_window_set_resizable(void*, gboolean); gboolean gtk_window_get_resizable(void*);
            void gtk_window_set_decorated(void*, gboolean);
            void gtk_window_set_deletable(void*, gboolean); gboolean gtk_window_get_deletable(void*);
            void gtk_window_present(void*);
            void gtk_window_iconify(void*); void gtk_window_deiconify(void*);
            void gtk_window_maximize(void*); void gtk_window_unmaximize(void*); gboolean gtk_window_is_maximized(void*);
            void gtk_window_fullscreen(void*); void gtk_window_unfullscreen(void*);
            void gtk_window_set_keep_above(void*, gboolean);
            gboolean gtk_window_is_active(void*);
            void gtk_window_set_urgency_hint(void*, gboolean);
            void gtk_window_set_modal(void*, gboolean);
            void gtk_window_set_transient_for(void*, void*);
            void gtk_window_set_type_hint(void*, int);
            void gtk_window_set_skip_taskbar_hint(void*, gboolean);
            void gtk_window_set_accept_focus(void*, gboolean);
            void gtk_window_set_focus_on_map(void*, gboolean);
            void gtk_window_set_position(void*, int);
            void gtk_window_set_geometry_hints(void*, void*, GdkGeometry*, int);
            void gtk_window_begin_move_drag(void*, int, int, int, unsigned int);
            void gtk_window_stick(void*); void gtk_window_unstick(void*);
            void gtk_window_close(void*);
            void gtk_widget_show(void*); void gtk_widget_show_all(void*); void gtk_widget_hide(void*);
            gboolean gtk_widget_get_visible(void*); gboolean gtk_widget_get_mapped(void*);
            void gtk_widget_destroy(void*);
            void gtk_widget_set_opacity(void*, double); double gtk_widget_get_opacity(void*);
            int gtk_widget_get_scale_factor(void*);
            void gtk_container_add(void*, void*);
            void* gtk_widget_get_window(void*);
            void gtk_widget_grab_focus(void*);
            void gtk_widget_set_can_focus(void*, gboolean);
            void gtk_widget_add_events(void*, int);
            int gtk_widget_get_allocated_width(void*); int gtk_widget_get_allocated_height(void*);
            void gtk_widget_queue_draw(void*);
            guint gtk_widget_add_tick_callback(void*, CbTick, void*, void*);
            void gtk_widget_set_app_paintable(void*, gboolean);
            void* gtk_widget_get_display(void*);
            void gtk_widget_realize(void*);

            void* gtk_gl_area_new(void);
            void gtk_gl_area_set_required_version(void*, int, int);
            void gtk_gl_area_set_has_alpha(void*, gboolean);
            void gtk_gl_area_set_auto_render(void*, gboolean);
            void gtk_gl_area_queue_render(void*);
            void gtk_gl_area_make_current(void*);
            void gtk_gl_area_attach_buffers(void*);
            void* gtk_gl_area_get_error(void*);
            void* gtk_gl_area_get_context(void*);

            void* gtk_im_multicontext_new(void);
            void gtk_im_context_set_client_window(void*, void*);
            gboolean gtk_im_context_filter_keypress(void*, void*);
            void gtk_im_context_focus_in(void*); void gtk_im_context_focus_out(void*);
            void gtk_im_context_reset(void*);
            void gtk_im_context_set_cursor_location(void*, GdkRectangle*);
            void gtk_im_context_get_preedit_string(void*, char**, void**, int*);
            void pango_attr_list_unref(void*);

            void* gtk_settings_get_default(void);
            unsigned int gdk_keyval_to_unicode(unsigned int);
            void* gdk_display_get_default(void);
            int gdk_display_get_n_monitors(void*);
            void* gdk_display_get_monitor(void*, int);
            void* gdk_display_get_primary_monitor(void*);
            void* gdk_display_get_monitor_at_window(void*, void*);
            void gdk_monitor_get_geometry(void*, GdkRectangle*);
            void gdk_monitor_get_workarea(void*, GdkRectangle*);
            int gdk_monitor_get_scale_factor(void*);
            int gdk_monitor_get_refresh_rate(void*);
            const char* gdk_monitor_get_model(void*);
            void* gdk_cursor_new_from_name(void*, const char*);
            void gdk_window_set_cursor(void*, void*);
            void* gdk_display_get_default_seat(void*);
            void* gdk_seat_get_pointer(void*);
            void gdk_device_get_position(void*, void**, int*, int*);
            void gdk_display_beep(void*);
            void* gdk_atom_intern(const char*, gboolean);
            void* gtk_clipboard_get(void*);
            void gtk_clipboard_set_text(void*, const char*, int);
            char* gtk_clipboard_wait_for_text(void*);
            void gtk_clipboard_clear(void*);
            void gtk_clipboard_store(void*);
            gboolean gtk_clipboard_wait_is_text_available(void*);
            void* cairo_image_surface_create_for_data(unsigned char*, int, int, int, int);
            int cairo_surface_write_to_png(void*, const char*);
            void cairo_surface_destroy(void*);
            int gdk_seat_grab(void*, void*, int, gboolean, void*, void*, void*, void*);
            void gdk_seat_ungrab(void*);
            void gtk_grab_add(void*); void gtk_grab_remove(void*);
            int gdk_window_get_origin(void*, int*, int*);
            void gdk_window_get_frame_extents(void*, GdkRectangle*);
        ', 'libgtk-3.so.0');
        self::$slot = self::$f->new('void*[1]');
        self::$slotInt = self::$f->cast('unsigned long[1]', self::$slot);
        return self::$f;
    }

    /** The integer address of a pointer. */
    public static function addr(?CData $p): int
    {
        if ($p === null) {
            return 0;
        }
        self::ffi();
        self::$slot[0] = $p;
        return self::$slotInt[0];
    }

    /**
     * Connects $fn to $signal of $obj: $kind says its C signature, 'event'
     * (widget, event, data) → gboolean, 'widget' (object, data), 'size'
     * (widget, int, int, data), 'string' (object, const char*, data).
     */
    public static function connect(CData $obj, string $signal, string $kind, Closure $fn): void
    {
        $f = self::ffi();
        $h = match ($kind) {
            'event' => $f->new('HEvent', false), 'widget' => $f->new('HWidget', false),
            'size' => $f->new('HSize', false), 'string' => $f->new('HString', false),
        };
        $h->fn = $fn;
        self::$kept[self::addr($obj)][] = $h;
        $f->g_signal_connect_data($obj, $signal, $f->cast('void*', $h->fn), null, null, 0);
    }


    /**
     * The one C function every source made here calls, its data the key of
     * the closure to run: a thunk of a closure lives as long as its FFI
     * instance, so one a source would pile up.
     */
    private static function sourceFn(): CData
    {
        static $h = null;
        if ($h === null) {
            $f = self::ffi();
            $h = $f->new('HSource', false);
            $h->fn = function ($data): int {
                $key = self::addr($data);
                $fn = self::$sources[$key] ?? null;
                unset(self::$sources[$key], self::$ids[$key]);
                $fn?->__invoke();
                return 0; // G_SOURCE_REMOVE
            };
        }
        return $h->fn;
    }

    /** @var array<int, Closure> the closures of the sources made here, by key */
    private static array $sources = [];
    /** @var array<int, int> GLib's id of each source, by key */
    private static array $ids = [];
    private static int $nextKey = 0;

    /** Runs $fn once from the main loop when it is idle; returns the key cancel() takes. */
    public static function idle(Closure $fn): int
    {
        $f = self::ffi();
        $key = ++self::$nextKey;
        self::$sources[$key] = $fn;
        self::$ids[$key] = $f->g_idle_add_full(200, self::sourceFn(), $f->cast('void*', $key), null); // G_PRIORITY_DEFAULT_IDLE
        return $key;
    }

    /** Runs $fn once, $seconds from now, from the main loop; returns the key cancel() takes. */
    public static function timeout(float $seconds, Closure $fn): int
    {
        $f = self::ffi();
        $key = ++self::$nextKey;
        self::$sources[$key] = $fn;
        self::$ids[$key] = $f->g_timeout_add_full(0, max(0, (int)round($seconds * 1000)), self::sourceFn(), $f->cast('void*', $key), null); // G_PRIORITY_DEFAULT
        return $key;
    }

    /** Removes a source idle() or timeout() made, unless it ran. */
    public static function cancel(int $key): void
    {
        if (isset(self::$ids[$key])) {
            self::ffi()->g_source_remove(self::$ids[$key]);
            unset(self::$sources[$key], self::$ids[$key]);
        }
    }

    /** Calls $fn at each tick of $widget's frame clock while it returns true (gtk_widget_add_tick_callback). */
    public static function tick(CData $widget, Closure $fn): int
    {
        $f = self::ffi();
        $h = $f->new('HTick', false);
        $h->fn = fn($w, $clock, $data): int => $fn() ? 1 : 0;
        self::$kept[self::addr($widget)][] = $h;
        return $f->gtk_widget_add_tick_callback($widget, $h->fn, null, null);
    }
}
