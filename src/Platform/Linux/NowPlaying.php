<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI\CData;
use MyPHP\{App, Logger, MediaCommand};
use MyPHP\Platform;
use MyPHP\Platform\AppHandler;

/**
 * An MPRIS player (org.mpris.MediaPlayer2) on the session bus (mygo's
 * linux nowplaying.go), which GNOME's and KDE's media controls, their lock
 * screens and playerctl show and drive, and which the desktops send the
 * media keys to. The app owns its bus name only while it shows something,
 * so the player leaves the controls once it shows nothing. Values go out
 * as GVariant's text format, parsed.
 */
final class NowPlaying implements Platform\NowPlaying
{
    private const PATH = '/org/mpris/MediaPlayer2', ROOT = 'org.mpris.MediaPlayer2', PLAYER = 'org.mpris.MediaPlayer2.Player';
    private const NO_TRACK = '/org/mpris/MediaPlayer2/TrackList/NoTrack';
    private const XML = '<node>
  <interface name="org.mpris.MediaPlayer2">
    <method name="Raise"/><method name="Quit"/>
    <property name="CanQuit" type="b" access="read"/><property name="CanRaise" type="b" access="read"/>
    <property name="HasTrackList" type="b" access="read"/><property name="Identity" type="s" access="read"/>
    <property name="DesktopEntry" type="s" access="read"/>
    <property name="SupportedUriSchemes" type="as" access="read"/><property name="SupportedMimeTypes" type="as" access="read"/>
  </interface>
  <interface name="org.mpris.MediaPlayer2.Player">
    <method name="Next"/><method name="Previous"/><method name="Pause"/><method name="PlayPause"/><method name="Stop"/><method name="Play"/>
    <method name="Seek"><arg direction="in" name="Offset" type="x"/></method>
    <method name="SetPosition"><arg direction="in" name="TrackId" type="o"/><arg direction="in" name="Position" type="x"/></method>
    <method name="OpenUri"><arg direction="in" name="Uri" type="s"/></method>
    <signal name="Seeked"><arg name="Position" type="x"/></signal>
    <property name="PlaybackStatus" type="s" access="read"/><property name="Rate" type="d" access="read"/>
    <property name="Metadata" type="a{sv}" access="read"/><property name="Volume" type="d" access="read"/>
    <property name="Position" type="x" access="read"/><property name="MinimumRate" type="d" access="read"/>
    <property name="MaximumRate" type="d" access="read"/><property name="CanGoNext" type="b" access="read"/>
    <property name="CanGoPrevious" type="b" access="read"/><property name="CanPlay" type="b" access="read"/>
    <property name="CanPause" type="b" access="read"/><property name="CanSeek" type="b" access="read"/>
    <property name="CanControl" type="b" access="read"/>
  </interface>
</node>';

    private ?CData $conn = null, $vtable = null;
    private int $owner = 0;
    /** @var ?array<string, mixed> what shows, as the core gave it */
    private ?array $info = null;
    /** When the info's position was (hrtime, seconds). */
    private float $at = 0.0;
    /** Counts the tracks, for their ids. */
    private int $track = 0;
    /** The artwork's file, whose URL the metadata gives. */
    private string $art = '';

    public function __construct(private readonly AppHandler $h) {}

    public function set(?array $info): void
    {
        if (!$this->register()) {
            return;
        }
        $f = Gtk::ffi();
        $old = $this->info;
        if ($info === null) {
            $this->info = null;
            if ($this->owner !== 0) {
                $f->g_bus_unown_name($this->owner);
                $this->owner = 0;
            }
            $this->setArt('');
            return;
        }
        $expected = $this->position();
        if ($old === null || $old['title'] !== $info['title'] || $old['artist'] !== $info['artist'] || $old['album'] !== $info['album'] || $old['duration'] != $info['duration']) {
            $this->track++;
        }
        [$this->info, $this->at] = [$info, hrtime(true) / 1e9];
        $this->setArt((string)($info['artwork'] ?? ''));
        if ($this->owner === 0) {
            $this->owner = $f->g_bus_own_name_on_connection($this->conn, 'org.mpris.MediaPlayer2.' . self::busElement(App::name()) . '.instance' . getmypid(), 0, null, null, null, null);
            return;
        }
        $this->changed(['PlaybackStatus', 'Rate', 'Metadata', 'CanGoNext', 'CanGoPrevious', 'CanPlay', 'CanPause', 'CanSeek']);
        if ($old !== null && abs($info['position'] - $expected) > 0.5) { // clients advance the position at the rate, and hear only of jumps
            $f->g_dbus_connection_emit_signal($this->conn, null, self::PATH, self::PLAYER, 'Seeked', self::parse('(int64 ' . (int)round($info['position'] * 1e6) . ',)'), null);
        }
    }

    /** Exports the player's object on the session bus, once; false without a bus. */
    private function register(): bool
    {
        if ($this->conn !== null) {
            return true;
        }
        $conn = DBus::bus(DBus::SESSION);
        if ($conn === null) {
            Logger::warn('no session bus: the desktop shows nothing of what plays', 'linux');
            return false;
        }
        $f = Gtk::ffi();
        $this->vtable = $f->new('GDBusInterfaceVTable', false); // GDBus keeps it
        $this->vtable->method_call = function ($conn, $sender, $path, $iface, $method, $params, $inv, $data): void {
            $this->call((string)$method, $params, $inv);
        };
        $this->vtable->get_property = fn($conn, $sender, $path, $iface, $prop, $error, $data) => $this->property((string)$prop);
        $node = $f->g_dbus_node_info_new_for_xml(self::XML, null);
        foreach ([self::ROOT, self::PLAYER] as $iface) {
            $f->g_dbus_connection_register_object($conn, self::PATH, $f->g_dbus_node_info_lookup_interface($node, $iface), \FFI::addr($this->vtable), null, null, null);
        }
        $this->conn = $conn;
        return true;
    }

    /** Where playback is now, in seconds. */
    private function position(): float
    {
        $i = $this->info;
        if ($i === null) {
            return 0.0;
        }
        $pos = (float)$i['position'];
        if ($i['state'] === 'playing') { $pos += (hrtime(true) / 1e9 - $this->at) * $i['rate']; }
        if ($i['duration'] > 0) { $pos = min($pos, (float)$i['duration']); }
        return max($pos, 0.0);
    }

    private function trackID(): string
    {
        return $this->info === null ? self::NO_TRACK : '/org/myphp/track/' . $this->track;
    }

    private function can(int $cmd): bool
    {
        return $this->info !== null && ($this->info['commands'] & $cmd) !== 0;
    }

    /** A property's value, a new GVariant. */
    private function property(string $prop): ?CData
    {
        $i = $this->info;
        $b = fn(bool $v): CData => self::parse($v ? 'true' : 'false');
        return match ($prop) {
            'CanQuit', 'CanRaise', 'HasTrackList' => $b(false),
            'Identity' => self::parse(DBus::str(App::name())),
            'DesktopEntry' => self::parse(DBus::str(App::name())),
            'SupportedUriSchemes', 'SupportedMimeTypes' => self::parse('@as []'),
            'PlaybackStatus' => self::parse(DBus::str($i === null ? 'Stopped' : ucfirst($i['state']))),
            'Rate' => self::parse(sprintf('%.6F', $i === null ? 1.0 : (float)$i['rate'])),
            'Volume', 'MinimumRate', 'MaximumRate' => self::parse('1.0'),
            'Position' => self::parse('int64 ' . (int)round($this->position() * 1e6)),
            'Metadata' => self::parse($this->metadata()),
            'CanGoNext' => $b($this->can(MediaCommand::NEXT)),
            'CanGoPrevious' => $b($this->can(MediaCommand::PREVIOUS)),
            'CanPlay' => $b($this->can(MediaCommand::PLAY)),
            'CanPause' => $b($this->can(MediaCommand::PAUSE)),
            'CanSeek' => $b($this->can(MediaCommand::SEEK) && $i['duration'] > 0),
            'CanControl' => $b(true),
            default => null,
        };
    }

    private function metadata(): string
    {
        $e = ["'mpris:trackid': <objectpath " . DBus::str($this->trackID()) . '>'];
        if (($i = $this->info) !== null) {
            if ($i['title'] !== '') { $e[] = "'xesam:title': <" . DBus::str($i['title']) . '>'; }
            if ($i['artist'] !== '') { $e[] = "'xesam:artist': <[" . DBus::str($i['artist']) . ']>'; }
            if ($i['album'] !== '') { $e[] = "'xesam:album': <" . DBus::str($i['album']) . '>'; }
            if ($i['duration'] > 0) { $e[] = "'mpris:length': <int64 " . (int)round($i['duration'] * 1e6) . '>'; }
            if ($this->art !== '') { $e[] = "'mpris:artUrl': <" . DBus::str('file://' . $this->art) . '>'; }
        }
        return '{' . implode(', ', $e) . '}';
    }

    /** Emits PropertiesChanged with the values of $props. @param list<string> $props */
    private function changed(array $props): void
    {
        $f = Gtk::ffi();
        $entries = [];
        foreach ($props as $p) {
            $v = $this->property($p);
            $entries[] = DBus::str($p) . ': <' . self::text($v) . '>';
        }
        $f->g_dbus_connection_emit_signal($this->conn, null, self::PATH, 'org.freedesktop.DBus.Properties', 'PropertiesChanged',
            self::parse('(' . DBus::str(self::PLAYER) . ', {' . implode(', ', $entries) . '}, @as [])'), null);
    }

    /** A method call, reported as the player's commands. */
    private function call(string $method, CData $params, CData $inv): void
    {
        $f = Gtk::ffi();
        [$cmd, $pos] = [0, 0.0];
        switch ($method) {
            case 'Play': $cmd = MediaCommand::PLAY; break;
            case 'Pause': $cmd = MediaCommand::PAUSE; break;
            case 'PlayPause': $cmd = MediaCommand::TOGGLE; break;
            case 'Stop': $cmd = MediaCommand::STOP; break;
            case 'Next': $cmd = MediaCommand::NEXT; break;
            case 'Previous': $cmd = MediaCommand::PREVIOUS; break;
            case 'Seek':
                [$cmd, $pos] = [MediaCommand::SEEK, max($this->position() + DBus::child($params, 0, 'g_variant_get_int64') / 1e6, 0.0)];
                break;
            case 'SetPosition':
                $id = DBus::child($params, 0, 'g_variant_get_string');
                $pos = DBus::child($params, 1, 'g_variant_get_int64') / 1e6;
                $i = $this->info;
                if ($id === $this->trackID() && $i !== null && $pos >= 0 && ($i['duration'] == 0 || $pos <= $i['duration'])) { // the specification ignores positions of other tracks, and out of the track
                    $cmd = MediaCommand::SEEK;
                }
                break;
            case 'OpenUri':
                $f->g_dbus_method_invocation_return_dbus_error($inv, 'org.freedesktop.DBus.Error.NotSupported', 'the player opens no URIs');
                return;
        }
        $f->g_dbus_method_invocation_return_value($inv, null);
        if ($cmd !== 0 && $this->info !== null) {
            $this->h->mediaCommand($cmd, $pos);
        }
    }

    /** Writes the artwork to a file the metadata gives the URL of, removing the one before; clients read it when they like (mygo's mprisSetArt). */
    private function setArt(string $art): void
    {
        $sum = hash('xxh64', $art);
        if ($art !== '' && $this->art !== '' && str_ends_with($this->art, $sum)) {
            return;
        }
        if ($this->art !== '') {
            @unlink($this->art);
            $this->art = '';
        }
        if ($art === '') {
            return;
        }
        $dir = (getenv('XDG_RUNTIME_DIR') ?: sys_get_temp_dir()) . '/' . self::busElement(App::name()); // the runtime directory goes as the session ends
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true)) {
            return;
        }
        $path = "$dir/now-playing-" . getmypid() . "-$sum";
        if (@file_put_contents($path, $art) !== false) {
            chmod($path, 0o600);
            $this->art = $path;
        }
    }

    /** $s as an element of a bus name: ASCII letters, digits, _ and -, not starting with a digit (mygo's busNameElement). */
    private static function busElement(string $s): string
    {
        $out = preg_replace('/[^A-Za-z0-9_-]/', '_', $s) ?? '';
        if ($out !== '' && ctype_digit($out[0])) { $out = "_$out"; }
        return $out === '' ? 'myphp' : $out;
    }

    /** A GVariant of GVariant's text format, floating. */
    private static function parse(string $text): CData
    {
        return Gtk::ffi()->g_variant_parse(null, $text, null, null, null);
    }

    /** A GVariant written in GVariant's text format, typed, and let go. */
    private static function text(CData $v): string
    {
        $f = Gtk::ffi();
        $f->g_variant_ref_sink($v);
        $p = $f->g_variant_print($v, 1);
        $f->g_variant_unref($v);
        $s = \FFI::string($p);
        $f->g_free($p);
        return $s;
    }
}
