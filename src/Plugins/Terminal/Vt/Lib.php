<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Binds libghostty-vt, the terminal emulator of Ghostty, which it loads at
 * run time through ext-ffi (mygo's plugins/terminal/internal/vt lib.go).
 * It declares the structs of the C API it passes, checks them against the
 * layouts the library describes (ghostty_type_json) as it loads, and reads
 * packed cells by the bit positions the library reports, so that a library
 * of another version fails to load instead of corrupting memory.
 *
 * @internal
 */
final class Lib
{
    // GhosttyResult.
    public const SUCCESS = 0, OUT_OF_MEMORY = -1, INVALID = -2, OUT_OF_SPACE = -3, NO_VALUE = -4, IO_ERROR = -5, EXCEEDED = -6, REJECTED = -7;

    private const CDEF = <<<'C'
        typedef struct { uint8_t r, g, b; } GhosttyColorRgb;
        typedef struct { int32_t tag; int32_t _pad; uint8_t value[8]; } GhosttyStyleColor;
        typedef struct { size_t size; GhosttyStyleColor fg_color, bg_color, underline_color;
            bool bold, italic, faint, blink, inverse, invisible, strikethrough, overline; int32_t underline; } GhosttyStyle;
        typedef struct { size_t size; bool viewport_has_value; uint16_t viewport_x, viewport_y;
            bool wide_tail, visible, blinking, password_input; int32_t visual_style; } GhosttyRenderStateCursor;
        typedef struct { size_t size; GhosttyColorRgb background, foreground, cursor; bool cursor_has_value; GhosttyColorRgb palette[256]; } GhosttyRenderStateColors;
        typedef struct { size_t size; uint16_t start_x, end_x; } GhosttyRenderStateRowSelection;
        typedef struct { const uint64_t *ptr; size_t len; } GhosttyCellsView;
        typedef struct { const uint8_t *ptr; size_t len; } GhosttyString;
        typedef struct { uint64_t total, offset, len; } GhosttyTerminalScrollbar;
        typedef struct { int32_t tag; int32_t _pad; uint64_t value[2]; } GhosttyTerminalScrollViewport;
        typedef struct { uint16_t mode; bool value; } GhosttyTerminalModeConfig;
        typedef struct { size_t size; void *node; uint16_t x, y; } GhosttyGridRef;
        typedef struct { uint16_t x; uint16_t _pad; uint32_t y; } GhosttyPointCoordinate;
        typedef struct { int32_t tag; int32_t _pad; GhosttyPointCoordinate value; uint64_t _pad2; } GhosttyPoint;
        typedef struct { size_t size; GhosttyGridRef start, end; bool rectangle; } GhosttySelection;
        typedef struct { size_t size; int32_t emit; bool unwrap; bool trim; const GhosttySelection *selection; } GhosttyTerminalSelectionFormatOptions;
        typedef struct { size_t size; bool cursor, style, hyperlink, protection, kitty_keyboard, charsets; } GhosttyFormatterScreenExtra;
        typedef struct { size_t size; bool palette, modes, scrolling_region, tabstops, pwd, keyboard; GhosttyFormatterScreenExtra screen; } GhosttyFormatterTerminalExtra;
        typedef struct { size_t size; int32_t emit; bool unwrap; bool trim; GhosttyFormatterTerminalExtra extra; const GhosttySelection *selection; } GhosttyFormatterTerminalOptions;
        typedef struct { size_t size; uint32_t screen_width, screen_height, cell_width, cell_height, padding_top, padding_bottom, padding_right, padding_left; } GhosttyMouseEncoderSize;
        typedef struct { float x, y; } GhosttyMousePosition;
        typedef struct { uint32_t columns, cell_width, padding_left, screen_height; } GhosttySelectionGestureGeometry;
        typedef struct { double x, y; } GhosttySurfacePosition;
        typedef struct { uint16_t x; uint16_t _pad; uint32_t y; } GhosttyViewportCoordinate;
        typedef struct { uint16_t rows, columns; uint32_t cell_width, cell_height; } GhosttySizeReportSize;
        typedef struct { GhosttyString mime, data; } GhosttyClipboardContent;
        typedef struct { size_t size; int32_t result; bool remember; } GhosttyClipboardWriteReply;
        typedef struct GhosttyClipboardWrite GhosttyClipboardWrite;
        struct GhosttyClipboardWrite { size_t size; int32_t location; const GhosttyClipboardContent *contents; size_t contents_len;
            GhosttyString name; bool granted, can_remember; const void *ctx;
            void (*reply)(const GhosttyClipboardWrite *write, const GhosttyClipboardWriteReply *reply); };
        typedef struct { size_t size; int32_t result; const GhosttyClipboardContent *contents; size_t contents_len;
            const GhosttyString *available; size_t available_len; bool remember; } GhosttyClipboardReadReply;
        typedef struct GhosttyClipboardRead GhosttyClipboardRead;
        struct GhosttyClipboardRead { size_t size; int32_t location; const GhosttyString *mimes; size_t mimes_len; bool list;
            GhosttyString name; bool granted, can_remember; const void *ctx;
            void (*reply)(const GhosttyClipboardRead *read, const GhosttyClipboardReadReply *reply); };
        typedef struct { void *read; void *userdata; } GhosttyMimeReader;
        typedef struct { size_t size; int32_t location; int32_t source; const GhosttyString *mimes; size_t mimes_len; GhosttyMimeReader reader; bool allow_unsafe; } GhosttyPaste;
        typedef struct { size_t size; GhosttyString title, body; } GhosttyTerminalDesktopNotification;
        typedef struct { size_t size; int32_t state; int8_t progress; } GhosttyTerminalProgressReport;
        typedef struct { size_t size; int32_t kind; int32_t prompt_kind; bool has_exit_code; int32_t exit_code; GhosttyString command, error; } GhosttyTerminalSemanticPrompt;
        typedef struct { uint16_t conformance; uint16_t features[64]; size_t num_features; uint16_t device_type, firmware, rom_cartridge; uint32_t unit_id; } GhosttyDeviceAttributes;
        typedef struct { uint64_t bits[2]; } GhosttyRenderStateRowId;

        typedef void (*GhosttyTerminalWritePtyFn)(void *terminal, void *userdata, const uint8_t *data, size_t len);
        typedef void (*GhosttyTerminalBellFn)(void *terminal, void *userdata);
        typedef void (*GhosttyTerminalRenderHoldFn)(void *terminal, void *userdata, bool held);
        typedef void (*GhosttyTerminalClipboardWriteFn)(void *terminal, void *userdata, const GhosttyClipboardWrite *write);
        typedef void (*GhosttyTerminalClipboardReadFn)(void *terminal, void *userdata, const GhosttyClipboardRead *read);
        typedef bool (*GhosttyTerminalSizeFn)(void *terminal, void *userdata, GhosttySizeReportSize *out);
        typedef bool (*GhosttyTerminalColorSchemeFn)(void *terminal, void *userdata, int32_t *out);
        typedef bool (*GhosttyTerminalDeviceAttributesFn)(void *terminal, void *userdata, GhosttyDeviceAttributes *out);
        typedef void (*GhosttyTerminalDesktopNotificationFn)(void *terminal, void *userdata, const GhosttyTerminalDesktopNotification *n);
        typedef void (*GhosttyTerminalProgressReportFn)(void *terminal, void *userdata, const GhosttyTerminalProgressReport *r);
        typedef void (*GhosttyTerminalSemanticPromptFn)(void *terminal, void *userdata, const GhosttyTerminalSemanticPrompt *e);
        typedef bool (*GhosttyMimeReaderFn)(void *userdata, const uint8_t *mime_ptr, size_t mime_len, void *writer_a, void *writer_b); /* GhosttyString and GhosttyWriter by value, as two registers each */
        typedef struct {
            GhosttyTerminalWritePtyFn write_pty; GhosttyTerminalBellFn bell, title_changed, pwd_changed; GhosttyTerminalRenderHoldFn render_hold;
            GhosttyTerminalClipboardWriteFn clipboard_write; GhosttyTerminalClipboardReadFn clipboard_read; GhosttyTerminalSizeFn size;
            GhosttyTerminalColorSchemeFn color_scheme; GhosttyTerminalDeviceAttributesFn device_attributes;
            GhosttyTerminalDesktopNotificationFn desktop_notification; GhosttyTerminalProgressReportFn progress_report; GhosttyMimeReaderFn no_read;
            GhosttyTerminalSemanticPromptFn semantic_prompt;
        } MyPHPTerminalCallbacks;

        int32_t ghostty_terminal_new(const void *allocator, void **terminal, uint16_t cols, uint16_t rows);
        void ghostty_terminal_free(void *terminal);
        void ghostty_terminal_reset(void *terminal);
        int32_t ghostty_terminal_resize(void *terminal, uint16_t cols, uint16_t rows, uint32_t cell_width_px, uint32_t cell_height_px);
        int32_t ghostty_terminal_set(void *terminal, int32_t option, const void *value);
        int32_t ghostty_terminal_get(void *terminal, int32_t data, void *out);
        void ghostty_terminal_vt_write(void *terminal, const uint8_t *data, size_t len);
        int32_t ghostty_terminal_compress(void *terminal, int32_t mode, int32_t *out_result);
        int32_t ghostty_terminal_compression_activity(void *terminal, uint64_t *out_activity);
        void ghostty_terminal_scroll_viewport(void *terminal, GhosttyTerminalScrollViewport behavior);
        int32_t ghostty_terminal_grid_ref(void *terminal, GhosttyPoint point, GhosttyGridRef *out_ref);
        int32_t ghostty_render_state_new(const void *allocator, void **state);
        void ghostty_render_state_free(void *state);
        int32_t ghostty_render_state_begin_update(void *state, void *terminal);
        int32_t ghostty_render_state_end_update(void *state);
        int32_t ghostty_render_state_clean(void *state);
        int32_t ghostty_render_state_get(void *state, int32_t data, void *out);
        int32_t ghostty_render_state_row_iterator_new(const void *allocator, void **out_iterator);
        void ghostty_render_state_row_iterator_free(void *iterator);
        bool ghostty_render_state_row_iterator_next(void *iterator);
        int32_t ghostty_render_state_row_get(void *iterator, int32_t data, void *out);
        int32_t ghostty_render_state_row_cells_new(const void *allocator, void **out_cells);
        void ghostty_render_state_row_cells_free(void *cells);
        int32_t ghostty_render_state_row_cells_select(void *cells, uint16_t x);
        int32_t ghostty_render_state_row_cells_get(void *cells, int32_t data, void *out);
        int32_t ghostty_key_encoder_new(const void *allocator, void **encoder);
        void ghostty_key_encoder_free(void *encoder);
        void ghostty_key_encoder_setopt(void *encoder, int32_t option, const void *value);
        void ghostty_key_encoder_setopt_from_terminal(void *encoder, void *terminal);
        int32_t ghostty_key_encoder_encode(void *encoder, void *event, char *out_buf, size_t out_buf_size, size_t *out_len);
        int32_t ghostty_key_event_new(const void *allocator, void **event);
        void ghostty_key_event_free(void *event);
        void ghostty_key_event_set_action(void *event, int32_t action);
        void ghostty_key_event_set_key(void *event, int32_t key);
        void ghostty_key_event_set_mods(void *event, uint16_t mods);
        void ghostty_key_event_set_consumed_mods(void *event, uint16_t mods);
        void ghostty_key_event_set_composing(void *event, bool composing);
        void ghostty_key_event_set_utf8(void *event, const char *utf8, size_t len);
        void ghostty_key_event_set_unshifted_codepoint(void *event, uint32_t codepoint);
        int32_t ghostty_mouse_encoder_new(const void *allocator, void **encoder);
        void ghostty_mouse_encoder_free(void *encoder);
        void ghostty_mouse_encoder_setopt(void *encoder, int32_t option, const void *value);
        void ghostty_mouse_encoder_setopt_from_terminal(void *encoder, void *terminal);
        void ghostty_mouse_encoder_reset(void *encoder);
        int32_t ghostty_mouse_encoder_encode(void *encoder, void *event, char *out_buf, size_t out_buf_size, size_t *out_len);
        int32_t ghostty_mouse_event_new(const void *allocator, void **event);
        void ghostty_mouse_event_free(void *event);
        void ghostty_mouse_event_set_action(void *event, int32_t action);
        void ghostty_mouse_event_set_button(void *event, int32_t button);
        void ghostty_mouse_event_clear_button(void *event);
        void ghostty_mouse_event_set_mods(void *event, uint16_t mods);
        void ghostty_mouse_event_set_position(void *event, GhosttyMousePosition position);
        int32_t ghostty_focus_encode(int32_t event, char *buf, size_t buf_len, size_t *out_written);
        int32_t ghostty_paste_encode(char *data, size_t data_len, bool bracketed, char *buf, size_t buf_len, size_t *out_written);
        int32_t ghostty_terminal_paste(void *terminal, const GhosttyPaste *paste, bool *out_written);
        bool ghostty_paste_is_safe(const char *data, size_t len);
        void ghostty_free(const void *allocator, uint8_t *ptr, size_t len);
        int32_t ghostty_selection_gesture_new(const void *allocator, void **out_gesture);
        void ghostty_selection_gesture_free(void *gesture, void *terminal);
        void ghostty_selection_gesture_reset(void *gesture, void *terminal);
        int32_t ghostty_selection_gesture_get(void *gesture, void *terminal, int32_t data, void *value);
        int32_t ghostty_selection_gesture_event_new(const void *allocator, void **out_event, int32_t type);
        void ghostty_selection_gesture_event_free(void *event);
        int32_t ghostty_selection_gesture_event_set(void *event, int32_t option, const void *value);
        int32_t ghostty_selection_gesture_event(void *gesture, void *terminal, void *event, GhosttySelection *out_selection);
        int32_t ghostty_terminal_select_all(void *terminal, GhosttySelection *out_selection);
        int32_t ghostty_terminal_selection_format_alloc(void *terminal, const void *allocator, GhosttyTerminalSelectionFormatOptions options, uint8_t **out_ptr, size_t *out_len);
        int32_t ghostty_grid_ref_hyperlink_uri(const GhosttyGridRef *ref, uint8_t *buf, size_t buf_len, size_t *out_len);
        int32_t ghostty_grid_ref_graphemes(const GhosttyGridRef *ref, uint32_t *buf, size_t buf_len, size_t *out_len);
        int32_t ghostty_grid_ref_cell(const GhosttyGridRef *ref, uint64_t *out_cell);
        const char *ghostty_type_json(void);
        int32_t ghostty_formatter_terminal_new(const void *allocator, void **formatter, void *terminal, GhosttyFormatterTerminalOptions options);
        int32_t ghostty_formatter_format_alloc(void *formatter, const void *allocator, uint8_t **out_ptr, size_t *out_len);
        void ghostty_formatter_free(void *formatter);
        C;

    /** The structs this binds and the fields to check, as the library names them. */
    private const LAYOUTS = [
        'GhosttyColorRgb' => ['r', 'g', 'b'],
        'GhosttyStyleColor' => ['tag', 'value'],
        'GhosttyStyle' => ['fg_color', 'bg_color', 'underline_color', 'bold', 'italic', 'faint', 'blink', 'inverse', 'invisible', 'strikethrough', 'overline', 'underline'],
        'GhosttyRenderStateCursor' => ['viewport_has_value', 'viewport_x', 'viewport_y', 'wide_tail', 'visible', 'blinking', 'password_input', 'visual_style'],
        'GhosttyRenderStateColors' => ['background', 'foreground', 'cursor', 'cursor_has_value', 'palette'],
        'GhosttyRenderStateRowSelection' => ['start_x', 'end_x'],
        'GhosttyCellsView' => ['ptr', 'len'],
        'GhosttyString' => ['ptr', 'len'],
        'GhosttyTerminalScrollbar' => ['total', 'offset', 'len'],
        'GhosttyTerminalScrollViewport' => ['tag', 'value'],
        'GhosttyTerminalModeConfig' => ['mode', 'value'],
        'GhosttyGridRef' => ['node', 'x', 'y'],
        'GhosttyPoint' => ['tag', 'value'],
        'GhosttyPointCoordinate' => ['x', 'y'],
        'GhosttySelection' => ['start', 'end', 'rectangle'],
        'GhosttyTerminalSelectionFormatOptions' => ['emit', 'unwrap', 'trim', 'selection'],
        'GhosttyMouseEncoderSize' => ['screen_width', 'screen_height', 'cell_width', 'cell_height', 'padding_top', 'padding_left'],
        'GhosttyMousePosition' => ['x', 'y'],
        'GhosttySelectionGestureGeometry' => ['columns', 'cell_width', 'padding_left', 'screen_height'],
        'GhosttySurfacePosition' => ['x', 'y'],
        'GhosttySizeReportSize' => ['rows', 'columns', 'cell_width', 'cell_height'],
        'GhosttyClipboardWrite' => ['location', 'contents', 'contents_len', 'name', 'granted', 'ctx', 'reply'],
        'GhosttyClipboardContent' => ['mime', 'data'],
        'GhosttyClipboardWriteReply' => ['result', 'remember'],
        'GhosttyClipboardRead' => ['location', 'mimes', 'mimes_len', 'list', 'name', 'granted', 'can_remember', 'ctx', 'reply'],
        'GhosttyClipboardReadReply' => ['result', 'contents', 'contents_len', 'available', 'available_len', 'remember'],
        'GhosttyPaste' => ['location', 'source', 'mimes', 'mimes_len', 'reader', 'allow_unsafe'],
        'GhosttyMimeReader' => ['read', 'userdata'],
        'GhosttyTerminalDesktopNotification' => ['title', 'body'],
        'GhosttyTerminalProgressReport' => ['state', 'progress'],
        'GhosttyDeviceAttributes' => ['primary' => 'conformance', 'secondary' => 'device_type', 'tertiary' => 'unit_id'],
        'GhosttyRenderStateRowId' => ['bits'],
        'GhosttyFormatterTerminalOptions' => ['emit', 'unwrap', 'trim', 'extra', 'selection'],
        'GhosttyFormatterTerminalExtra' => ['keyboard', 'screen'],
        'GhosttyFormatterScreenExtra' => ['charsets'],
    ];

    /** Values of enums this package hardcodes, which the manifest must agree with. */
    private const ENUMS = [
        ['GhosttyKey', 'A', Key::A], ['GhosttyKey', 'ARROW_UP', Key::ARROW_UP], ['GhosttyKey', 'ESCAPE', Key::ESCAPE], ['GhosttyKey', 'F1', Key::F1],
        ['GhosttyKeyAction', 'REPEAT', KeyEvent::REPEAT], ['GhosttyMouseAction', 'MOTION', MouseEncoder::MOTION], ['GhosttyMouseButton', 'FIVE', MouseEncoder::WHEEL_DOWN],
        ['GhosttyRenderStateCursorVisualStyle', 'BLOCK_HOLLOW', Terminal::CURSOR_BLOCK_HOLLOW], ['GhosttyRenderStateDirty', 'FULL', RenderState::FULL],
        ['GhosttyCellWide', 'SPACER_HEAD', Cell::SPACER_HEAD], ['GhosttyStyleColorTag', 'RGB', 2], ['GhosttySgrUnderline', 'DASHED', Style::UNDERLINE_DASHED],
        ['GhosttyTerminalScrollViewportTag', 'ROW', 3], ['GhosttyPointTag', 'VIEWPORT', 1], ['GhosttyClipboardWriteResult', 'UNSUPPORTED', 2],
        ['GhosttySelectionGestureEventType', 'DRAG', Gesture::DRAG],
    ];

    private static ?FFI $ffi = null;
    private static ?string $err = null;
    /** The bit fields of a packed GhosttyCell: [lsb, width] each. @var array<string, array{0: int, 1: int}> */
    public static array $cell = [];

    /** The library, loaded. */
    public static function ffi(): FFI
    {
        return self::$ffi ?? throw new RuntimeException('libghostty-vt is not loaded');
    }

    /** Loads the library at $path. Only the first call loads one: later calls report how it went, whatever their path. */
    public static function load(string $path): void
    {
        if (self::$ffi !== null) { return; }
        if (self::$err !== null) { throw new RuntimeException(self::$err); }
        try {
            $ffi = \MyPHP\Plugins\NativeLibrary::cdef(self::CDEF, $path); // bound to its own symbols on Linux
        } catch (FFI\Exception $e) {
            self::$err = "loading $path: {$e->getMessage()}";
            throw new RuntimeException(self::$err);
        }
        $why = self::check($ffi);
        if ($why !== '') {
            self::$err = "$path is not a libghostty-vt this package binds: $why";
            throw new RuntimeException(self::$err);
        }
        self::$ffi = $ffi;
    }

    /** An FFI of no library, for C values of plain types (FFI's static new() and cast() are deprecated). */
    private static function plain(): FFI
    {
        static $c = null;
        return $c ??= FFI::cdef();
    }

    /** Whether load() succeeded. */
    public static function loaded(): bool { return self::$ffi !== null; }

    /** The integer address of a pointer. */
    public static function addr(?CData $p): int
    {
        static $slot = null, $slotInt = null;
        if ($p === null) { return 0; }
        if ($slot === null) {
            $slot = self::plain()->new('void*[1]');
            $slotInt = self::plain()->cast('uint64_t[1]', $slot);
        }
        $slot[0] = $p;
        return $slotInt[0];
    }

    /** The bytes the library gave at $p, $n long. */
    public static function bytes(?CData $p, int $n): string
    {
        return $p === null || $n <= 0 ? '' : FFI::string($p, $n);
    }

    /** A GhosttyString's bytes. */
    public static function str(CData $s): string { return self::bytes($s->ptr, (int)$s->len); }

    /** What the library's type manifest disagrees with in these declarations, or ''. */
    private static function check(FFI $ffi): string
    {
        $json = $ffi->ghostty_type_json();
        if (!is_string($json) || $json === '') { return 'no type manifest'; }
        $m = json_decode($json, true);
        if (!is_array($m)) { return 'the type manifest is no JSON'; }
        if (($m['abi']['pointer_size'] ?? 0) !== PHP_INT_SIZE) { return 'built for ' . ($m['abi']['pointer_size'] ?? 0) . '-byte pointers'; }
        foreach (self::LAYOUTS as $name => $fields) {
            $t = $m['types'][$name] ?? null;
            if ($t === null) { return "no type $name"; }
            $s = $ffi->new($name);
            if (($t['size'] ?? -1) !== FFI::sizeof($s)) { return "$name is {$t['size']} bytes, not " . FFI::sizeof($s); }
            $base = self::addr(FFI::addr($s));
            foreach ($fields as $k => $field) {
                $cName = is_string($k) ? $k : $field;
                $off = self::addr(FFI::addr($s->$field)) - $base;
                $want = $t['fields'][$cName]['offset'] ?? null;
                if ($want === null) { return "$name has no field $cName"; }
                if ($want !== $off) { return "$name.$cName is at $want, not $off"; }
            }
        }
        foreach (self::ENUMS as [$typ, $name, $value]) {
            if (($m['types'][$typ]['values'][$name] ?? null) !== $value) { return "$typ $name is not $value"; }
        }
        $c = $m['types']['GhosttyCell'] ?? null;
        if ($c === null || ($c['kind'] ?? '') !== 'packed' || ($c['size'] ?? 0) !== 8) { return 'GhosttyCell is not a packed 64-bit value'; }
        $field = function (string $name) use ($c): ?array {
            $b = $c['bits'][$name] ?? null;
            return $b === null ? null : [$b['lsb'], $b['width']];
        };
        $arm = function (string $tag, string $name) use ($c): ?array {
            $content = $c['bits']['content'] ?? null;
            $b = $content['arms'][$tag]['bits'][$name] ?? null;
            return $b === null ? null : [$content['lsb'] + $b['lsb'], $b['width']];
        };
        self::$cell = [
            'tag' => $field('content_tag'), 'styleID' => $field('style_id'), 'wide' => $field('wide'), 'hyperlink' => $field('hyperlink'),
            'codepoint' => $arm('CODEPOINT', 'codepoint'), 'paletteIndex' => $arm('BG_COLOR_PALETTE', 'index'),
            'r' => $arm('BG_COLOR_RGB', 'r'), 'g' => $arm('BG_COLOR_RGB', 'g'), 'b' => $arm('BG_COLOR_RGB', 'b'),
        ];
        foreach (self::$cell as $k => $v) {
            if ($v === null) { return "GhosttyCell has no $k"; }
        }
        return '';
    }

    /** The bits of field $f of the packed cell $v. */
    public static function bits(int $v, string $f): int
    {
        [$lsb, $width] = self::$cell[$f];
        return ($v >> $lsb) & ((1 << $width) - 1);
    }

    /** The message of a GhosttyResult other than SUCCESS. */
    public static function error(int $r): string
    {
        return match ($r) {
            self::OUT_OF_MEMORY => 'libghostty-vt: out of memory',
            self::INVALID => 'libghostty-vt: invalid value',
            self::OUT_OF_SPACE => 'libghostty-vt: out of space',
            self::NO_VALUE => 'libghostty-vt: no value',
            self::IO_ERROR => 'libghostty-vt: I/O error',
            self::EXCEEDED => 'libghostty-vt: limit exceeded',
            self::REJECTED => 'libghostty-vt: rejected',
            default => "libghostty-vt: error $r",
        };
    }

    /** Throws unless $r is SUCCESS. */
    public static function must(int $r): void
    {
        if ($r !== self::SUCCESS) { throw new RuntimeException(self::error($r)); }
    }

    /** A pointer of C type $type to the first byte of buffer $b, which the caller keeps while it is used. */
    public static function ptr(CData $b, string $type): CData
    {
        return self::ffi()->cast($type, FFI::addr($b[0]));
    }

    /** A C buffer holding $s, kept by the caller while the library reads it. */
    public static function buffer(string $s): CData
    {
        $n = max(strlen($s), 1);
        $b = self::plain()->new("uint8_t[$n]");
        FFI::memcpy($b, $s, strlen($s));
        return $b;
    }
}
