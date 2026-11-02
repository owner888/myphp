<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use FFI;
use FFI\CData;
use MyPHP\Logger;
use MyPHP\Platform\{Accelerator, AppHandler, Unsupported};
use RuntimeException;

/**
 * Global shortcuts through the desktop portal (mygo's linux
 * hotkey_portal.go). Wayland lets apps grab no keys: the desktop binds
 * global shortcuts for the apps that ask its GlobalShortcuts portal, in a
 * session. A session binds its shortcuts once, so a change replaces it with
 * a new session binding them all. Desktops remember the keys the user picks
 * for each shortcut id, the canonical accelerator, and may ask the user to
 * confirm new shortcuts.
 */
final class PortalShortcuts
{
    private const IFACE = 'org.freedesktop.portal.GlobalShortcuts';

    private bool $available = false;
    /** @var array<int, Accelerator> by the shortcut's id */
    private array $shortcuts = [];
    private string $session = ''; // the session the shortcuts are bound in
    private bool $busy = false;   // a session is being created and bound
    private bool $stale = false;  // the shortcuts changed while busy
    /** GDK's Wayland functions, false where GTK was built without Wayland (no display can be one then) */
    private static FFI|false|null $wayland = null;

    public function __construct(private readonly AppHandler $h) {}

    /** Whether the desktop offers the GlobalShortcuts portal. */
    public static function offered(): bool
    {
        return Portal::version(self::IFACE) !== 0;
    }

    public function bind(int $id, Accelerator $a): void
    {
        if (!$this->available) {
            if (!self::offered()) {
                throw new Unsupported('myphp: global shortcuts need an X11 session or a desktop with the GlobalShortcuts portal');
            }
            $this->available = true;
            DBus::subscribe(DBus::SESSION, Portal::NAME, self::IFACE, 'Activated', Portal::PATH, fn(string $signal, CData $params) => $this->activated($params));
        }
        $this->shortcuts[$id] = $a;
        try {
            $this->sync();
        } catch (RuntimeException $e) {
            unset($this->shortcuts[$id]);
            throw $e;
        }
    }

    public function unbind(int $id): void
    {
        if (isset($this->shortcuts[$id])) {
            unset($this->shortcuts[$id]);
            try {
                $this->sync();
            } catch (RuntimeException $e) {
                Logger::error("global shortcuts: {$e->getMessage()}", 'linux');
            }
        }
    }

    /** Replaces the session with one binding the shortcuts. A session on its way binds them once it is created. */
    private function sync(): void
    {
        if ($this->busy) {
            $this->stale = true;
            return;
        }
        if ($this->session !== '') {
            $this->close($this->session);
            $this->session = '';
        }
        if ($this->shortcuts === []) {
            return;
        }
        [, $list] = $this->list();
        $why = Portal::request(self::IFACE, 'CreateSession', [], [
            "'session_handle_token': <" . DBus::str(self::sessionToken()) . '>',
            // KDE Plasma 5 binds the shortcuts given here, as drafts of the
            // portal had it; portals before 1.17 pass them on.
            "'shortcuts': <$list>",
        ], function (int $code, CData $results): void {
            $session = Portal::vardictString($results, 'session_handle');
            if ($code !== 0 || $session === '') {
                $this->busy = false;
                Logger::error("global shortcuts: the desktop portal did not create a session (response $code)", 'linux');
                return;
            }
            $this->stale = false;
            if ($this->shortcuts === []) {
                $this->busy = false;
                $this->close($session);
                return;
            }
            $this->session = $session;
            try {
                $this->bindAll();
            } catch (RuntimeException $e) {
                $this->busy = false;
                Logger::error("global shortcuts: {$e->getMessage()}", 'linux');
            }
        });
        if ($why !== null) {
            if (str_contains($why, 'Error.NotAllowed')) {
                throw new RuntimeException('myphp: global shortcuts on Wayland need the app installed, with its desktop entry ' . Portal::desktopEntryId() . ": $why");
            }
            throw new RuntimeException("myphp: global shortcuts: $why");
        }
        [$this->busy, $this->stale] = [true, false];
    }

    /** Binds the shortcuts in the new session. */
    private function bindAll(): void
    {
        [$accs, $list] = $this->list();
        $why = Portal::request(self::IFACE, 'BindShortcuts', ['objectpath ' . DBus::str($this->session), $list, "''"], [], function (int $code, CData $results) use ($accs): void { // '': no parent window
            $this->busy = false;
            if ($code !== 0) {
                Logger::error("global shortcuts: the desktop did not bind them (response $code)", 'linux');
            } else {
                self::reportUnbound($accs, $results);
            }
            if ($this->stale) {
                try {
                    $this->sync();
                } catch (RuntimeException $e) {
                    Logger::error("global shortcuts: {$e->getMessage()}", 'linux');
                }
            }
        });
        if ($why !== null) {
            throw new RuntimeException("myphp: global shortcuts: $why");
        }
    }

    private function close(string $session): void
    {
        $res = Portal::call($session, 'org.freedesktop.portal.Session', 'Close');
        if ($res !== null) { Gtk::ffi()->g_variant_unref($res); }
    }

    /**
     * The shortcuts in the order they were registered, and the a(sa{sv})
     * that describes them to the portal, in GVariant's text format.
     *
     * @return array{0: list<Accelerator>, 1: string}
     */
    private function list(): array
    {
        ksort($this->shortcuts);
        $accs = array_values($this->shortcuts);
        $items = array_map(fn(Accelerator $a) => '(' . DBus::str((string)$a) . ', {'
            . "'description': <" . DBus::str((string)$a) . '>, '
            . "'preferred_trigger': <" . DBus::str($a->xdgTrigger()) . '>})', $accs);
        return [$accs, '[' . implode(', ', $items) . ']'];
    }

    /**
     * Logs the shortcuts the desktop bound to no keys: users may decline
     * them, and KDE Plasma 5 binds none with portals from 1.17 on.
     *
     * @param list<Accelerator> $accs
     */
    private static function reportUnbound(array $accs, CData $results): void
    {
        $f = Gtk::ffi();
        $keys = [];
        $list = $f->g_variant_lookup_value($results, 'shortcuts', null);
        if ($list !== null) {
            if ($f->g_variant_get_type_string($list) === 'a(sa{sv})') {
                for ($i = 0, $n = $f->g_variant_n_children($list); $i < $n; $i++) {
                    $item = $f->g_variant_get_child_value($list, $i);
                    $props = $f->g_variant_get_child_value($item, 1);
                    $keys[DBus::child($item, 0, 'g_variant_get_string')] = Portal::vardictString($props, 'trigger_description');
                    $f->g_variant_unref($props);
                    $f->g_variant_unref($item);
                }
            }
            $f->g_variant_unref($list);
        }
        foreach ($accs as $a) {
            if (($keys[(string)$a] ?? '') === '') {
                Logger::warn("global shortcuts: the desktop bound no keys to $a", 'linux');
            }
        }
    }

    /** The portal's Activated signal: (session handle, shortcut id, timestamp, options). */
    private function activated(CData $params): void
    {
        if (DBus::child($params, 0, 'g_variant_get_string') !== $this->session) {
            return;
        }
        $name = DBus::child($params, 1, 'g_variant_get_string');
        $f = Gtk::ffi();
        $options = $f->g_variant_get_child_value($params, 3);
        $token = Portal::vardictString($options, 'activation_token');
        $f->g_variant_unref($options);
        foreach ($this->shortcuts as $id => $a) {
            if ((string)$a === $name) {
                self::withActivationToken($token, fn() => $this->h->hotkeyPressed($id));
                return;
            }
        }
    }

    /** The session's token, after the app: KDE names the shortcuts of apps without an ID after it, and keeps the keys the user picked for them. */
    private static function sessionToken(): string
    {
        $id = Portal::desktopEntryId();
        if (str_ends_with($id, '.desktop')) {
            $id = substr($id, 0, -strlen('.desktop'));
        }
        return 'myphp_' . preg_replace('/[^0-9A-Za-z]/u', '_', $id);
    }

    /**
     * Runs $fn with the XDG activation token of the key press that caused
     * it: the window $fn shows or focuses first uses it, which lets it take
     * the focus on Wayland.
     */
    private static function withActivationToken(string $token, Closure $fn): void
    {
        if (self::$wayland === null) {
            try {
                self::$wayland = FFI::cdef('unsigned long gdk_wayland_display_get_type(void); void gdk_wayland_display_set_startup_notification_id(void*, const char*);', 'libgtk-3.so.0');
            } catch (FFI\Exception) {
                self::$wayland = false;
            }
        }
        $f = Gtk::ffi();
        $display = $f->gdk_display_get_default();
        if ($token === '' || self::$wayland === false || !$f->g_type_check_instance_is_a($display, self::$wayland->gdk_wayland_display_get_type())) {
            $fn();
            return;
        }
        $w = self::$wayland;
        $d = $w->cast('void*', Gtk::addr($display));
        $w->gdk_wayland_display_set_startup_notification_id($d, $token);
        try {
            $fn();
        } finally {
            $w->gdk_wayland_display_set_startup_notification_id($d, null);
        }
    }
}
