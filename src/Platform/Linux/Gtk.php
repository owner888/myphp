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
            typedef void (*CbGet)(void*, void*, unsigned int, void*);
            typedef struct { CbGet fn; } HGet;
            typedef struct { const char* target; unsigned int flags; unsigned int info; } GtkTargetEntry;
            typedef void (*CbSignal)(void*, const char*, const char*, const char*, const char*, void*, void*);
            typedef struct { CbSignal fn; } HSignal;
            typedef int (*CbDragAt)(void*, void*, int, int, unsigned int, void*);
            typedef void (*CbDragLeave)(void*, void*, unsigned int, void*);
            typedef void (*CbDragReceived)(void*, void*, int, int, void*, unsigned int, unsigned int, void*);
            typedef void (*CbDragGet)(void*, void*, void*, unsigned int, unsigned int, void*);
            typedef int (*CbDragFailed)(void*, void*, int, void*);
            typedef struct { CbDragAt fn; } HDragAt; typedef struct { CbDragLeave fn; } HDragLeave; typedef struct { CbDragReceived fn; } HDragReceived;
            typedef struct { CbDragGet fn; } HDragGet; typedef struct { CbDragFailed fn; } HDragFailed;
            typedef void (*CbMethod)(void*, const char*, const char*, const char*, const char*, void*, void*, void*);
            typedef void* (*CbProperty)(void*, const char*, const char*, const char*, const char*, void*, void*);
            typedef struct { CbMethod method_call; CbProperty get_property; void* set_property; void* padding[8]; } GDBusInterfaceVTable;
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
            void gdk_window_get_device_position(void*, void*, int*, int*, void*);
            void* gdk_event_copy(void*); void gdk_event_free(void*);

            typedef struct _GList { void* data; struct _GList* next; struct _GList* prev; } GList;
            GList* gtk_container_get_children(void*); void g_list_free(GList*);
            void gtk_container_remove(void*, void*); void gtk_container_check_resize(void*);
            void* gtk_box_new(int, int); void gtk_box_pack_start(void*, void*, gboolean, gboolean, guint); void gtk_box_reorder_child(void*, void*, int);
            void gtk_widget_set_size_request(void*, int, int);
            void gtk_widget_set_sensitive(void*, gboolean); gboolean gtk_widget_is_sensitive(void*);
            void gtk_widget_set_no_show_all(void*, gboolean); void gtk_widget_set_visible(void*, gboolean);
            void gtk_widget_set_tooltip_text(void*, const char*); void gtk_widget_set_direction(void*, int);
            void gtk_widget_get_preferred_height(void*, int*, int*);
            gboolean gtk_widget_translate_coordinates(void*, void*, int, int, int*, int*);
            gboolean gtk_widget_mnemonic_activate(void*, gboolean);
            void* gtk_menu_bar_new(void); void* gtk_menu_new(void);
            void* gtk_menu_item_new_with_label(const char*); void* gtk_separator_menu_item_new(void);
            void* gtk_check_menu_item_new_with_label(const char*);
            void gtk_check_menu_item_set_draw_as_radio(void*, gboolean); void gtk_check_menu_item_set_active(void*, gboolean);
            void gtk_menu_item_set_submenu(void*, void*); void* gtk_menu_item_get_submenu(void*);
            void gtk_menu_item_set_label(void*, const char*);
            void gtk_menu_shell_append(void*, void*);
            void gtk_menu_popup_at_rect(void*, void*, GdkRectangle*, int, int, void*);
            void gtk_menu_popup_at_pointer(void*, void*);
            void* gtk_accel_group_new(void);
            void gtk_window_add_accel_group(void*, void*); void gtk_window_remove_accel_group(void*, void*);
            void gtk_widget_add_accelerator(void*, const char*, void*, guint, int, int);
            gboolean gtk_window_propagate_key_event(void*, void*);
            guint gdk_keyval_from_name(const char*); guint gdk_unicode_to_keyval(guint); guint gdk_keyval_to_lower(guint);
            void* gdk_keymap_get_for_display(void*);
            gboolean gdk_keymap_get_entries_for_keyval(void*, guint, void**, int*);
            gboolean gdk_keymap_translate_keyboard_state(void*, guint, int, int, guint*, int*, int*, int*);
            void* g_main_loop_new(void*, gboolean); void g_main_loop_run(void*); void g_main_loop_quit(void*); void g_main_loop_unref(void*);
            void* gtk_bin_get_child(void*); void* gtk_image_new(void); void gtk_image_set_from_surface(void*, void*);
            void gtk_label_set_label(void*, const char*);
            void* gdk_pixbuf_loader_new(void);
            gboolean gdk_pixbuf_loader_write(void*, const char*, size_t, void*); gboolean gdk_pixbuf_loader_close(void*, void*);
            void* gdk_pixbuf_loader_get_pixbuf(void*);
            void* gdk_pixbuf_scale_simple(void*, int, int, int); int gdk_pixbuf_get_width(void*); int gdk_pixbuf_get_height(void*);
            void* gdk_cairo_surface_create_from_pixbuf(void*, int, void*);

            typedef struct _GSList { void* data; struct _GSList* next; } GSList;
            void g_slist_free(GSList*);
            void* gtk_file_chooser_native_new(const char*, void*, int, const char*, const char*);
            int gtk_native_dialog_run(void*);
            void gtk_file_chooser_set_select_multiple(void*, gboolean); void gtk_file_chooser_set_show_hidden(void*, gboolean);
            void gtk_file_chooser_set_create_folders(void*, gboolean); void gtk_file_chooser_set_do_overwrite_confirmation(void*, gboolean);
            gboolean gtk_file_chooser_set_current_folder(void*, const char*); void gtk_file_chooser_set_current_name(void*, const char*);
            void* gtk_file_filter_new(void); void gtk_file_filter_set_name(void*, const char*); void gtk_file_filter_add_pattern(void*, const char*);
            void gtk_file_chooser_add_filter(void*, void*);
            GSList* gtk_file_chooser_get_filenames(void*); char* gtk_file_chooser_get_filename(void*);
            void* gtk_message_dialog_new(void*, int, int, int, const char*, ...);
            void gtk_message_dialog_format_secondary_text(void*, const char*, ...);
            void* gtk_message_dialog_get_message_area(void*);
            void* gtk_dialog_add_button(void*, const char*, int); void gtk_dialog_set_default_response(void*, int);
            void* gtk_dialog_get_widget_for_response(void*, int);
            int gtk_dialog_run(void*);
            void* gtk_widget_get_style_context(void*); void gtk_style_context_add_class(void*, const char*);
            void* gtk_check_button_new_with_label(const char*);
            void gtk_toggle_button_set_active(void*, gboolean); gboolean gtk_toggle_button_get_active(void*);
            void* gtk_entry_new(void); void gtk_entry_set_text(void*, const char*); const char* gtk_entry_get_text(void*);
            void gtk_entry_set_activates_default(void*, gboolean); void gtk_entry_set_visibility(void*, gboolean);
            void gtk_entry_set_placeholder_text(void*, const char*);
            void* gtk_label_new(const char*); void gtk_label_set_xalign(void*, float);

            gboolean gtk_clipboard_set_with_data(void*, GtkTargetEntry*, unsigned int, CbGet, CbWidget, void*);
            void gtk_clipboard_set_can_store(void*, GtkTargetEntry*, int);
            gboolean gtk_clipboard_wait_for_targets(void*, void***, int*);
            void* gtk_clipboard_wait_for_contents(void*, void*);
            void* gtk_clipboard_wait_for_image(void*);
            gboolean gdk_display_supports_clipboard_persistence(void*);
            void gtk_selection_data_set(void*, void*, int, const char*, int);
            void* gtk_selection_data_get_target(void*);
            int gtk_selection_data_get_length(void*); void* gtk_selection_data_get_data(void*);
            void gtk_selection_data_free(void*);
            char* gdk_atom_name(void*);
            gboolean gdk_pixbuf_save_to_bufferv(void*, char**, size_t*, const char*, char**, char**, void**);

            void* g_file_new_for_path(const char*); char* g_file_get_uri(void*);
            gboolean g_file_trash(void*, void*, void**);
            gboolean g_app_info_launch_default_for_uri(const char*, void*, void**);
            typedef struct { unsigned int domain; int code; char* message; } GError;
            void g_error_free(GError*);
            void* g_file_query_info(void*, const char*, int, void*, void**);
            void* g_file_query_filesystem_info(void*, const char*, void*, void**);
            void* g_file_info_get_icon(void*);
            gboolean g_file_info_get_attribute_boolean(void*, const char*);
            void* g_desktop_app_info_new_from_filename(const char*);
            void* g_app_info_get_icon(void*);
            gboolean g_app_info_launch(void*, void*, void*, void**);
            GList* g_app_info_get_all(void);
            gboolean g_app_info_should_show(void*);
            const char* g_app_info_get_display_name(void*); const char* g_app_info_get_id(void*);
            const char* g_desktop_app_info_get_filename(void*);
            void* gdk_display_get_app_launch_context(void*);
            void* g_themed_icon_new(const char*);
            const char* g_get_prgname(void);
            void* gtk_icon_theme_get_default(void);
            void* gtk_icon_theme_lookup_by_gicon(void*, void*, int, int);
            void* gtk_icon_info_load_icon(void*, void**);
            void* g_bus_get_sync(int, void*, void**);
            void* g_variant_parse(void*, const char*, void*, void*, void**);
            void* g_dbus_connection_call_sync(void*, const char*, const char*, const char*, const char*, void*, void*, int, int, void*, void**);
            void g_variant_unref(void*);
            void* g_variant_get_child_value(void*, size_t);
            gboolean g_variant_get_boolean(void*); unsigned int g_variant_get_uint32(void*); unsigned long g_variant_get_uint64(void*);
            const char* g_variant_get_string(void*, size_t*);
            void* g_variant_type_new(const char*); void g_variant_type_free(void*);
            unsigned int g_dbus_connection_signal_subscribe(void*, const char*, const char*, const char*, const char*, const char*, int, CbSignal, void*, void*);
            void* g_network_monitor_get_default(void);
            gboolean g_network_monitor_get_network_available(void*); gboolean g_network_monitor_get_network_metered(void*);
            unsigned long gdk_x11_display_get_type(void); void* gdk_x11_display_get_xdisplay(void*);
            void gdk_x11_display_error_trap_push(void*); int gdk_x11_display_error_trap_pop(void*);
            void gdk_window_add_filter(void*, CbEvent, void*);
            gboolean g_type_check_instance_is_a(void*, unsigned long);
            void gtk_drag_dest_set(void*, int, GtkTargetEntry*, int, int);
            void gtk_drag_get_data(void*, void*, void*, unsigned int);
            void gtk_drag_finish(void*, gboolean, gboolean, unsigned int);
            void gdk_drag_status(void*, int, unsigned int);
            GList* gdk_drag_context_list_targets(void*);
            int gdk_drag_context_get_actions(void*); int gdk_drag_context_get_suggested_action(void*); int gdk_drag_context_get_selected_action(void*);
            void* gtk_drag_get_source_widget(void*);
            void* gtk_target_list_new(GtkTargetEntry*, unsigned int); void gtk_target_list_unref(void*);
            void* gtk_drag_begin_with_coordinates(void*, void*, int, int, void*, int, int);
            void gtk_drag_cancel(void*);
            void gtk_drag_set_icon_pixbuf(void*, void*, int, int);
            void* gdk_pixbuf_get_from_surface(void*, int, int, int, int);
            void* g_dbus_node_info_new_for_xml(const char*, void**);
            void* g_dbus_node_info_lookup_interface(void*, const char*);
            unsigned int g_dbus_connection_register_object(void*, const char*, void*, GDBusInterfaceVTable*, void*, void*, void**);
            unsigned int g_bus_own_name_on_connection(void*, const char*, int, void*, void*, void*, void*);
            void g_bus_unown_name(unsigned int);
            void g_dbus_method_invocation_return_value(void*, void*);
            void g_dbus_method_invocation_return_dbus_error(void*, const char*, const char*);
            long g_variant_get_int64(void*);
            void* g_variant_ref_sink(void*); char* g_variant_print(void*, gboolean);
            gboolean g_dbus_connection_emit_signal(void*, const char*, const char*, const char*, const char*, void*, void**);
        ', 'libgtk-3.so.0');
        self::$slot = self::$f->new('void*[1]');
        self::$slotInt = self::$f->cast('unsigned long[1]', self::$slot);
        return self::$f;
    }

    /**
     * Throws the GError of a call that failed, which it frees, as a
     * RuntimeException: $what, then GLib's message.
     */
    public static function fail(CData $error, string $what): never
    {
        $e = self::ffi()->cast('GError*', $error[0]);
        $message = $e === null ? 'failed' : \FFI::string($e->message);
        if ($e !== null) { self::ffi()->g_error_free($e); }
        throw new RuntimeException("myphp: $what: $message");
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
     * (widget, int, int, data), 'string' (object, const char*, data), and
     * the drags': 'dragAt' (widget, context, x, y, time, data) → gboolean,
     * 'dragLeave' (widget, context, time, data), 'dragReceived' (widget,
     * context, x, y, selection, info, time, data), 'dragGet' (widget,
     * context, selection, info, time, data), 'dragFailed' (widget,
     * context, result, data) → gboolean.
     */
    public static function connect(CData $obj, string $signal, string $kind, Closure $fn): void
    {
        $f = self::ffi();
        $h = match ($kind) {
            'event' => $f->new('HEvent', false), 'widget' => $f->new('HWidget', false),
            'size' => $f->new('HSize', false), 'string' => $f->new('HString', false),
            'dragAt' => $f->new('HDragAt', false), 'dragLeave' => $f->new('HDragLeave', false), 'dragReceived' => $f->new('HDragReceived', false),
            'dragGet' => $f->new('HDragGet', false), 'dragFailed' => $f->new('HDragFailed', false),
        };
        $h->fn = $fn;
        self::$kept[self::addr($obj)][] = $h;
        $f->g_signal_connect_data($obj, $signal, $f->cast('void*', $h->fn), null, null, 0);
    }


    /**
     * Connects the C function of holder $fn, made once and kept by its
     * caller, to $signal of $obj with $data: for the handlers of many
     * objects (menu items) told apart by their data, which would each
     * leave a thunk behind if each had a closure of its own.
     */
    public static function connectShared(CData $obj, string $signal, CData $fn, int $data): void
    {
        $f = self::ffi();
        $f->g_signal_connect_data($obj, $signal, $f->cast('void*', $fn), $f->cast('void*', $data), null, 0);
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
