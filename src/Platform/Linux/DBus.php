<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use FFI\CData;

/**
 * D-Bus through GDBus (mygo's linux system.go bus, dbusCall): synchronous
 * method calls on the session or system bus, their parameters written in
 * GVariant's text format, and signals subscribed to.
 */
final class DBus
{
    public const SYSTEM = 1, SESSION = 2;

    /** @var array<int, CData> the buses, connected once */
    private static array $buses = [];
    /** @var list<CData> the handlers of the signals subscribed to, kept as GDBus calls them */
    private static array $handlers = [];

    /** The connection to a bus, null without one. */
    public static function bus(int $type): ?CData
    {
        if (isset(self::$buses[$type])) {
            return self::$buses[$type];
        }
        $f = Gtk::ffi();
        $error = $f->new('void*[1]');
        $bus = $f->g_bus_get_sync($type, null, $error);
        if ($bus === null) {
            $f->g_error_free($f->cast('GError*', $error[0]));
            return null;
        }
        return self::$buses[$type] = $bus;
    }

    /**
     * Calls $method and returns its reply (a GVariant the caller unrefs),
     * of $replyType when given; null when there is no bus, or the call
     * fails, $error then telling why. $params is a tuple in GVariant's
     * text format, as "('a', uint32 4)", null for none.
     */
    public static function call(int $bus, string $name, string $path, string $iface, string $method, ?string $params = null, ?string $replyType = null, ?string &$error = null): ?CData
    {
        $f = Gtk::ffi();
        $conn = self::bus($bus);
        if ($conn === null) {
            $error = 'no bus';
            return null;
        }
        $p = $params === null ? null : $f->g_variant_parse(null, $params, null, null, null);
        $type = $replyType === null ? null : $f->g_variant_type_new($replyType);
        $e = $f->new('void*[1]');
        $reply = $f->g_dbus_connection_call_sync($conn, $name, $path, $iface, $method, $p, $type, 0, -1, null, $e);
        if ($type !== null) { $f->g_variant_type_free($type); }
        if ($reply === null) {
            $ge = $f->cast('GError*', $e[0]);
            $error = \FFI::string($ge->message);
            $f->g_error_free($ge);
        }
        return $reply;
    }

    /** Calls $fn($signal, $params, $path) on each signal of $iface ($member, null for any) from $sender on $bus. */
    public static function subscribe(int $bus, ?string $sender, string $iface, ?string $member, ?string $path, Closure $fn): void
    {
        $conn = self::bus($bus);
        if ($conn === null) {
            return;
        }
        $f = Gtk::ffi();
        $h = $f->new('HSignal', false);
        $h->fn = function ($conn, $sender, $path, $iface, $signal, $params, $data) use ($fn): void {
            $fn((string)$signal, $params, (string)$path);
        };
        self::$handlers[] = $h;
        $f->g_dbus_connection_signal_subscribe($conn, $sender, $iface, $member, $path, null, 0, $h->fn, null, null);
    }

    /** The $i-th child of a tuple, as $get reads it (g_variant_get_boolean…). */
    public static function child(CData $v, int $i, string $get): mixed
    {
        $f = Gtk::ffi();
        $c = $f->g_variant_get_child_value($v, $i);
        $out = $get === 'g_variant_get_string' ? $f->g_variant_get_string($c, null) : $f->$get($c);
        $f->g_variant_unref($c);
        return $out;
    }

    /** $s as a string of GVariant's text format. */
    public static function str(string $s): string
    {
        return "'" . addcslashes($s, "'\\") . "'";
    }
}
