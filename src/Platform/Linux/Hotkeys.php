<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI;
use FFI\CData;
use MyPHP\Platform\{Accelerator, AppHandler, Unsupported};
use RuntimeException;

/**
 * Global shortcuts on X11 (mygo's linux hotkey.go): each grabs its key on
 * the root window, with Caps Lock and Num Lock either way, and the presses
 * arrive through a GDK event filter. Elsewhere (Wayland) they need the
 * desktop portal's GlobalShortcuts, which is not here yet.
 */
final class Hotkeys
{
    private const SHIFT = 1 << 0, LOCK = 1 << 1, CONTROL = 1 << 2, MOD1 = 1 << 3, MOD2 = 1 << 4, MOD4 = 1 << 6; // X's masks: Caps Lock LOCK, Alt MOD1, Num Lock MOD2, Super MOD4
    private const LOCKS = [0, self::LOCK, self::MOD2, self::LOCK | self::MOD2];

    private ?FFI $x = null;
    private ?CData $filter = null;
    /** @var array<int, array{0: int, 1: int}> the keycode and modifiers grabbed, by the shortcut's id */
    private array $grabs = [];

    public function __construct(private readonly AppHandler $h) {}

    /** libX11, and the filter of the grabbed keys' presses, once GDK is known to run on an X server. */
    private function x11(): FFI
    {
        if ($this->x !== null) {
            return $this->x;
        }
        $f = Gtk::ffi();
        if (!$f->g_type_check_instance_is_a($f->gdk_display_get_default(), $f->gdk_x11_display_get_type())) {
            throw new Unsupported('myphp: global shortcuts need an X server; the desktop portal\'s are not on Linux yet');
        }
        $this->x = FFI::cdef('
            unsigned long XStringToKeysym(const char*); unsigned char XKeysymToKeycode(void*, unsigned long);
            int XGrabKey(void*, int, unsigned int, unsigned long, int, int, int); int XUngrabKey(void*, int, unsigned int, unsigned long);
            unsigned long XDefaultRootWindow(void*); int XFlush(void*);
        ', 'libX11.so.6');
        $this->filter = $f->new('HEvent', false);
        $this->filter->fn = function ($xevent, $event, $data): int {
            $e = Gtk::ffi()->cast('unsigned int*', $xevent); // XKeyEvent: type at 0, state and keycode after the time (80, 84)
            if ($e[0] !== 2) { // KeyPress
                return 0; // GDK_FILTER_CONTINUE
            }
            $state = $e[20] & (self::SHIFT | self::CONTROL | self::MOD1 | self::MOD4);
            foreach ($this->grabs as $id => [$code, $mods]) {
                if ($code === ($e[21] & 0xff) && $mods === $state) {
                    $this->h->hotkeyPressed($id);
                    return 2; // GDK_FILTER_REMOVE
                }
            }
            return 0;
        };
        $f->gdk_window_add_filter(null, $this->filter->fn, null);
        return $this->x;
    }

    public function register(int $id, string $accelerator): void
    {
        $a = Accelerator::parse($accelerator, 'Linux');
        $x = $this->x11();
        $f = Gtk::ffi();
        $display = $f->gdk_display_get_default();
        $dpy = $x->cast('void*', $f->gdk_x11_display_get_xdisplay($display));
        $code = $x->XKeysymToKeycode($dpy, $x->XStringToKeysym($a->keysym()));
        if ($code === 0) {
            throw new RuntimeException("myphp: the keyboard has no key for \"$accelerator\"");
        }
        $mods = 0;
        foreach ([Accelerator::SHIFT => self::SHIFT, Accelerator::CTRL => self::CONTROL, Accelerator::ALT => self::MOD1, Accelerator::SUPER => self::MOD4] as $m => $xm) {
            if ($a->has($m)) { $mods |= $xm; }
        }
        $root = $x->XDefaultRootWindow($dpy);
        $f->gdk_x11_display_error_trap_push($display);
        foreach (self::LOCKS as $lock) {
            $x->XGrabKey($dpy, $code, $mods | $lock, $root, 0, 1, 1); // GrabModeAsync
        }
        if ($f->gdk_x11_display_error_trap_pop($display) !== 0) {
            $this->ungrab($code, $mods);
            throw new RuntimeException("myphp: $accelerator is already a global shortcut of another app");
        }
        $this->grabs[$id] = [$code, $mods];
    }

    public function unregister(int $id): void
    {
        if (isset($this->grabs[$id])) {
            [$code, $mods] = $this->grabs[$id];
            unset($this->grabs[$id]);
            $this->ungrab($code, $mods);
        }
    }

    private function ungrab(int $code, int $mods): void
    {
        $x = $this->x11();
        $f = Gtk::ffi();
        $display = $f->gdk_display_get_default();
        $dpy = $x->cast('void*', $f->gdk_x11_display_get_xdisplay($display));
        $root = $x->XDefaultRootWindow($dpy);
        $f->gdk_x11_display_error_trap_push($display);
        foreach (self::LOCKS as $lock) {
            $x->XUngrabKey($dpy, $code, $mods | $lock, $root);
        }
        $x->XFlush($dpy);
        $f->gdk_x11_display_error_trap_pop($display);
    }
}
