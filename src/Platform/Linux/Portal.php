<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use FFI\CData;
use MyPHP\App;
use MyPHP\Logger;

/**
 * The XDG desktop portal (mygo's linux portal.go): what the desktop
 * decides on, as inhibiting sleep or global shortcuts. Methods that may
 * involve the user return a request object at once and answer later with
 * its Response signal, routed here by the object's path. Parameters are in
 * GVariant's text format, as DBus::call takes them.
 */
final class Portal
{
    public const NAME = 'org.freedesktop.portal.Desktop', PATH = '/org/freedesktop/portal/desktop';

    private static bool $registered = false, $listening = false;
    /** @var array<string, Closure(int, CData): void> what to do with each request's response, by its object's path */
    private static array $requests = [];
    private static int $tokens = 0;

    /**
     * Calls a method of the portal, after registering the app with it,
     * which must come first; the reply as DBus::call returns it.
     */
    public static function call(string $path, string $iface, string $method, ?string $params = null, ?string $replyType = null, ?string &$error = null): ?CData
    {
        self::register();
        return DBus::call(DBus::SESSION, self::NAME, $path, $iface, $method, $params, $replyType, $error);
    }

    /**
     * Tells the portal the app's ID, the name of its desktop entry: portals
     * know it only for sandboxed apps and apps the desktop launched, and
     * global shortcuts need it. The portal accepts it before any other
     * call, when the desktop entry is installed.
     */
    private static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        $id = substr(self::desktopEntryId(), 0, -strlen('.desktop'));
        $res = DBus::call(DBus::SESSION, self::NAME, self::PATH, 'org.freedesktop.host.portal.Registry', 'Register', '(' . DBus::str($id) . ', @a{sv} {})', null, $why);
        if ($res === null) {
            Logger::debug("the desktop portal did not register the app as $id: $why", 'linux'); // portals before 1.19 have no registry
            return;
        }
        Gtk::ffi()->g_variant_unref($res);
    }

    /**
     * The file name of the app's desktop entry: the one it was launched
     * from, else one named after the program (g_set_prgname): a packaged
     * app's launcher, as mygo's executable, else the app's name, the
     * executable being php.
     */
    public static function desktopEntryId(): string
    {
        foreach (['GIO_LAUNCHED_DESKTOP_FILE', 'BAMF_DESKTOP_FILE_HINT'] as $env) {
            $p = getenv($env);
            if (is_string($p) && $p !== '') {
                return basename($p);
            }
        }
        return (\MyPHP\Native\Bundle::info('CFBundleExecutable') ?? App::name()) . '.desktop';
    }

    /**
     * Calls a portal method whose last argument is a vardict of options,
     * and which answers with a Response signal: $done then gets the
     * response code, 0 when the request succeeded, and the results (a{sv}),
     * which it must not keep. $args are the arguments before the options,
     * $options its entries, each in GVariant's text format ("'key': <value>").
     * Returns null when the call went out, else why it failed.
     *
     * @param list<string> $args
     * @param list<string> $options
     * @param Closure(int, CData): void $done
     */
    public static function request(string $iface, string $method, array $args, array $options, Closure $done): ?string
    {
        $f = Gtk::ffi();
        $conn = DBus::bus(DBus::SESSION);
        if ($conn === null) {
            return 'no session bus';
        }
        if (!self::$listening) {
            self::$listening = true;
            DBus::subscribe(DBus::SESSION, self::NAME, 'org.freedesktop.portal.Request', 'Response', null, self::response(...));
        }
        $token = 'myphp' . ++self::$tokens;
        $sender = str_replace('.', '_', ltrim($f->g_dbus_connection_get_unique_name($conn), ':'));
        $path = self::PATH . "/request/$sender/$token";
        self::$requests[$path] = $done;
        $options[] = "'handle_token': <" . DBus::str($token) . '>';
        $all = [...$args, '{' . implode(', ', $options) . '}'];
        $params = '(' . implode(', ', $all) . (count($all) === 1 ? ',)' : ')');
        $res = self::call(self::PATH, $iface, $method, $params, '(o)', $why);
        if ($res === null) {
            unset(self::$requests[$path]);
            return $why;
        }
        $handle = DBus::child($res, 0, 'g_variant_get_string');
        $f->g_variant_unref($res);
        if ($handle !== $path) { // portals before 0.9 pick the path
            unset(self::$requests[$path]);
            self::$requests[$handle] = $done;
        }
        return null;
    }

    /** A request's Response: (response code, results). */
    private static function response(string $signal, CData $params, string $path): void
    {
        $done = self::$requests[$path] ?? null;
        if ($done === null) {
            return;
        }
        unset(self::$requests[$path]);
        $f = Gtk::ffi();
        $results = $f->g_variant_get_child_value($params, 1);
        try {
            $done(DBus::child($params, 0, 'g_variant_get_uint32'), $results);
        } finally {
            $f->g_variant_unref($results);
        }
    }

    /** The version of a portal interface, 0 when the desktop does not offer it. */
    public static function version(string $iface): int
    {
        $f = Gtk::ffi();
        $res = self::call(self::PATH, 'org.freedesktop.DBus.Properties', 'Get', '(' . DBus::str($iface) . ", 'version')", '(v)', $why);
        if ($res === null) {
            Logger::debug("the desktop portal has no $iface: $why", 'linux');
            return 0;
        }
        $v = $f->g_variant_get_child_value($res, 0);
        $inner = $f->g_variant_get_variant($v);
        $version = $f->g_variant_get_type_string($inner) === 'u' ? $f->g_variant_get_uint32($inner) : 0;
        $f->g_variant_unref($inner);
        $f->g_variant_unref($v);
        $f->g_variant_unref($res);
        return $version;
    }

    /** The string (or object path) under $key in an a{sv}, '' when there is none. */
    public static function vardictString(CData $dict, string $key): string
    {
        $f = Gtk::ffi();
        $v = $f->g_variant_lookup_value($dict, $key, null);
        if ($v === null) {
            return '';
        }
        $t = $f->g_variant_get_type_string($v);
        $s = $t === 's' || $t === 'o' ? $f->g_variant_get_string($v, null) : '';
        $f->g_variant_unref($v);
        return $s;
    }
}
