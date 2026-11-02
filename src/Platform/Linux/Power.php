<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use MyPHP\Logger;
use MyPHP\Platform;
use MyPHP\Platform\AppHandler;

/**
 * Power through D-Bus and sysfs (mygo's linux power.go): logind tells of
 * sleep, the screen savers of the screen locking; the desktop portal, else
 * the screen saver, keeps the system awake; the batteries are
 * /sys/class/power_supply's.
 */
final class Power implements Platform\Power
{
    private const SAVER = 'org.freedesktop.ScreenSaver', SAVER_PATH = '/org/freedesktop/ScreenSaver';

    public function __construct(private readonly AppHandler $h) {}

    /** Listens to logind for sleep, on the system bus, and to GNOME's and the freedesktop screen savers, which lock the screen, on the session bus. */
    public function watch(): void
    {
        $h = $this->h;
        DBus::subscribe(DBus::SYSTEM, 'org.freedesktop.login1', 'org.freedesktop.login1.Manager', 'PrepareForSleep', '/org/freedesktop/login1',
            fn(string $signal, $params) => $h->powerEvent(DBus::child($params, 0, 'g_variant_get_boolean') ? 'suspend' : 'resume'));
        foreach (['org.gnome.ScreenSaver', self::SAVER] as $iface) {
            DBus::subscribe(DBus::SESSION, null, $iface, 'ActiveChanged', null,
                fn(string $signal, $params) => $h->powerEvent(DBus::child($params, 0, 'g_variant_get_boolean') ? 'lock-screen' : 'unlock-screen'));
        }
    }

    /** Asks the desktop portal to inhibit suspending (and, with $display, the session going idle), else the freedesktop screen saver to inhibit idleness (mygo's KeepAwake). */
    public function keepAwake(bool $display, string $reason): Closure
    {
        $f = Gtk::ffi();
        $flags = 4 | ($display ? 8 : 0); // suspend, idle
        $res = Portal::call(Portal::PATH, 'org.freedesktop.portal.Inhibit', 'Inhibit',
            "('', uint32 $flags, {'reason': <" . DBus::str($reason) . '>})', '(o)', $why);
        if ($res !== null) {
            $handle = DBus::child($res, 0, 'g_variant_get_string');
            $f->g_variant_unref($res);
            return function () use ($handle, $f): void {
                $r = Portal::call($handle, 'org.freedesktop.portal.Request', 'Close');
                if ($r !== null) { $f->g_variant_unref($r); }
            };
        }
        $res = DBus::call(DBus::SESSION, self::SAVER, self::SAVER_PATH, self::SAVER, 'Inhibit', '(' . DBus::str(\MyPHP\App::name()) . ', ' . DBus::str($reason) . ')', '(u)', $why2);
        if ($res === null) {
            Logger::warn("nothing keeps the system awake: the portal: $why; the screen saver: $why2", 'linux');
            return static function (): void {};
        }
        $cookie = DBus::child($res, 0, 'g_variant_get_uint32');
        $f->g_variant_unref($res);
        return function () use ($cookie, $f): void {
            $r = DBus::call(DBus::SESSION, self::SAVER, self::SAVER_PATH, self::SAVER, 'UnInhibit', "(uint32 $cookie,)");
            if ($r !== null) { $f->g_variant_unref($r); }
        };
    }

    /** Whether a battery discharges while no power supply is online (mygo's OnBattery). */
    public function onBattery(): bool
    {
        $discharging = false;
        foreach (self::supplies() as $s) {
            switch (self::read($s, 'type')) {
                case 'Mains': case 'USB':
                    if (self::read($s, 'online') === '1') { return false; }
                    break;
                case 'Battery':
                    if (self::read($s, 'scope') !== 'Device' && self::read($s, 'status') === 'Discharging') { $discharging = true; }
                    break;
            }
        }
        return $discharging;
    }

    /** The charge of the computer's own battery in percent, null without one (myphp's). */
    public function batteryLevel(): ?int
    {
        foreach (self::supplies() as $s) {
            if (self::read($s, 'type') === 'Battery' && self::read($s, 'scope') !== 'Device' && ($c = self::read($s, 'capacity')) !== '') {
                return (int)$c;
            }
        }
        return null;
    }

    /** How long the user has been idle: GNOME's idle monitor's, else the freedesktop screen saver's (KDE's), else 0 (mygo's IdleTime). */
    public function idleTime(): float
    {
        $f = Gtk::ffi();
        $res = DBus::call(DBus::SESSION, 'org.gnome.Mutter.IdleMonitor', '/org/gnome/Mutter/IdleMonitor/Core', 'org.gnome.Mutter.IdleMonitor', 'GetIdletime', null, '(t)');
        if ($res !== null) {
            $ms = DBus::child($res, 0, 'g_variant_get_uint64');
            $f->g_variant_unref($res);
            return $ms / 1000;
        }
        $res = DBus::call(DBus::SESSION, self::SAVER, self::SAVER_PATH, self::SAVER, 'GetSessionIdleTime', null, '(u)');
        if ($res !== null) {
            $s = DBus::child($res, 0, 'g_variant_get_uint32');
            $f->g_variant_unref($res);
            return (float)$s;
        }
        return 0.0;
    }

    /** The power supplies' folders (scandir: a static app has no glob). @return list<string> */
    private static function supplies(): array
    {
        $dir = '/sys/class/power_supply';
        $names = @scandir($dir);
        return $names === false ? [] : array_map(fn(string $n): string => "$dir/$n", array_values(array_diff($names, ['.', '..'])));
    }

    private static function read(string $dir, string $name): string
    {
        $b = @file_get_contents("$dir/$name");
        return $b === false ? '' : trim($b);
    }
}
