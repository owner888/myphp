<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\{App, L10n, Notification};
use MyPHP\Platform\AppHandler;

/**
 * Notifications through the freedesktop notification server, on the
 * session bus (mygo's linux system.go): a default action reports the
 * click, and a notification shown again with its ID replaces the one it
 * showed.
 */
final class Notifications
{
    private const NAME = 'org.freedesktop.Notifications', PATH = '/org/freedesktop/Notifications';

    /** @var array<int, string> the app's IDs of the notifications showing, by the server's */
    private array $byServer = [];
    /** @var array<string, int> the server's IDs, by the app's */
    private array $byApp = [];
    private bool $subscribed = false;

    public function __construct(private readonly AppHandler $h) {}

    public function supported(): bool
    {
        return DBus::bus(DBus::SESSION) !== null;
    }

    public function show(Notification $n): void
    {
        if (!$this->subscribed) {
            $this->subscribed = true;
            DBus::subscribe(DBus::SESSION, self::NAME, self::NAME, 'ActionInvoked', self::PATH, function (string $signal, $params): void {
                $id = $this->byServer[DBus::child($params, 0, 'g_variant_get_uint32')] ?? null;
                if ($id !== null) { $this->h->notificationClicked($id); }
            });
        }
        $o = $n->options;
        $body = $o->subtitle !== '' ? "$o->subtitle\n$o->body" : $o->body;
        $hints = $o->silent ? "{'suppress-sound': <true>}" : '@a{sv} {}';
        $params = '(' . DBus::str(App::name()) . ', uint32 ' . ($this->byApp[$n->id] ?? 0) . ", '', " . DBus::str($o->title) . ', ' . DBus::str($body)
            . ", ['default', " . DBus::str(L10n::in(App::locale())->get('open')) . "], $hints, -1)";
        $res = DBus::call(DBus::SESSION, self::NAME, self::PATH, self::NAME, 'Notify', $params, '(u)', $error)
            ?? throw new \RuntimeException("myphp: cannot show the notification: $error");
        $serverID = DBus::child($res, 0, 'g_variant_get_uint32');
        Gtk::ffi()->g_variant_unref($res);
        $this->byServer[$serverID] = $n->id;
        $this->byApp[$n->id] = $serverID;
    }

    public function remove(string $id): void
    {
        $serverID = $this->byApp[$id] ?? null;
        if ($serverID === null) {
            return;
        }
        unset($this->byApp[$id], $this->byServer[$serverID]);
        $res = DBus::call(DBus::SESSION, self::NAME, self::PATH, self::NAME, 'CloseNotification', "(uint32 $serverID,)");
        if ($res !== null) { Gtk::ffi()->g_variant_unref($res); }
    }

    public function removeAll(): void
    {
        foreach (array_keys($this->byApp) as $id) { $this->remove((string)$id); }
    }
}
