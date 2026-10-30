<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;

/**
 * The system's Now Playing (Control Center's, the menu bar's) and its
 * remote commands (the keyboard's media keys, a headset's buttons), as
 * IINA's NowPlayingInfoManager and RemoteCommandController: what plays,
 * where and at what rate, shown; the commands IINA takes, sent to the
 * one that showed it last. One for the process, as the system's is: a
 * player shows it as it changes and ends it when nothing plays, the
 * system's own (Music's) taking it back then (IINA's issue 4331).
 * MyPHP\NowPlaying shows what an app plays here too (media()), as mygo's
 * darwin backend: the one to show it last has the commands, those it
 * handles enabled.
 *
 * @internal UI\VideoPlayerState, MyPHP\NowPlaying
 */
final class NowPlaying
{
    /** The commands, by the name a handler takes: MPRemoteCommandCenter's of each, and what its event carries ('d' a double, 'f' a float), if anything. */
    private const COMMANDS = [
        'play' => ['playCommand', null, null], 'pause' => ['pauseCommand', null, null], 'toggle' => ['togglePlayPauseCommand', null, null],
        'stop' => ['stopCommand', null, null], 'next' => ['nextTrackCommand', null, null], 'previous' => ['previousTrackCommand', null, null],
        'skipForward' => ['skipForwardCommand', 'interval', 'd'], 'skipBackward' => ['skipBackwardCommand', 'interval', 'd'],
        'loop' => ['changeRepeatModeCommand', null, null], 'rate' => ['changePlaybackRateCommand', 'playbackRate', 'f'],
        'position' => ['changePlaybackPositionCommand', 'positionTime', 'd'],
    ];
    /** IINA's: the skips' interval, the rates offered. */
    private const SKIP = 15.0, RATES = [0.5, 1.0, 1.5, 2.0];
    /** MPNowPlayingPlaybackState's playing, paused and stopped; MPNowPlayingInfoMediaType's video. */
    private const PLAYING = 1, PAUSED = 2, STOPPED = 3, AUDIO = 1, VIDEO = 2;
    /** MyPHP\NowPlaying's commands (MediaCommand's bits), by the name of the handler of each. */
    private const MEDIA = ['play' => 1, 'pause' => 2, 'toggle' => 4, 'stop' => 8, 'next' => 16, 'previous' => 32, 'position' => 64];
    /** The info's keys, MediaPlayer's constants by their names. */
    private const KEYS = ['MPMediaItemPropertyTitle', 'MPMediaItemPropertyArtist', 'MPMediaItemPropertyAlbumTitle', 'MPMediaItemPropertyPlaybackDuration', 'MPNowPlayingInfoPropertyElapsedPlaybackTime',
        'MPNowPlayingInfoPropertyPlaybackRate', 'MPNowPlayingInfoPropertyDefaultPlaybackRate', 'MPNowPlayingInfoPropertyMediaType',
        'MPNowPlayingInfoPropertyAssetURL', 'MPNowPlayingInfoPropertyPlaybackQueueCount', 'MPNowPlayingInfoPropertyPlaybackQueueIndex',
        'MPNowPlayingInfoPropertyChapterCount', 'MPNowPlayingInfoPropertyChapterNumber'];

    /** What showed it last (and its id, which its destructor ends it by), and its handlers by command. */
    private static ?\WeakReference $owner = null;
    private static int $ownerId = 0;
    /** @var array<string, Closure> */
    private static array $handlers = [];
    /** Whether the commands have their targets, and the path shown. */
    private static bool $enabled = false;
    private static ?string $path = null;
    /** @var array<string, mixed> the keys' NSStrings */
    private static array $keys = [];
    /** @var array<string, mixed> the blocks the commands call, made once */
    private static array $blocks = [];
    /** What MyPHP\NowPlaying shows as. */
    private static ?object $media = null;

    /**
     * Shows what $owner plays: the film at $path (titled by its name), its
     * duration, where it is, its rate (0 while paused, as Now Playing
     * wants), its place in a playlist and its chapter (-1, -1 for none),
     * and takes the commands for it, $handlers by name (COMMANDS'), each
     * given $owner (held weakly: what it holds goes on) and a skip's
     * seconds, a rate's rate, a position's time.
     * @param array<string, Closure> $handlers
     */
    public static function show(object $owner, array $handlers, string $path, float $duration, float $time, float $rate, bool $paused, int $index, int $count, int $chapter, int $chapters): void
    {
        self::boot();
        self::$owner = \WeakReference::create($owner);
        self::$ownerId = spl_object_id($owner);
        self::$handlers = $handlers;
        $k = self::$keys;
        $info = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        $set = fn(string $key, $value) => ObjC::msg('v:pp', $info, 'setObject:forKey:', $value, $k[$key]);
        $double = fn(float $v) => ObjC::msg('p:d', ObjC::cls('NSNumber'), 'numberWithDouble:', $v);
        $long = fn(int $v) => ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', $v);
        $set('MPNowPlayingInfoPropertyMediaType', $long(self::VIDEO));
        $set('MPMediaItemPropertyTitle', ObjC::str(pathinfo($path, PATHINFO_FILENAME)));
        $set('MPNowPlayingInfoPropertyAssetURL', ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($path)));
        if ($index >= 0) {
            $set('MPNowPlayingInfoPropertyPlaybackQueueCount', $long($count));
            $set('MPNowPlayingInfoPropertyPlaybackQueueIndex', $long($index));
        }
        if ($chapters > 0) {
            $set('MPNowPlayingInfoPropertyChapterCount', $long($chapters));
            $set('MPNowPlayingInfoPropertyChapterNumber', $long(max(0, $chapter)));
        }
        $set('MPMediaItemPropertyPlaybackDuration', $double($duration));
        $set('MPNowPlayingInfoPropertyElapsedPlaybackTime', $double($time));
        $set('MPNowPlayingInfoPropertyPlaybackRate', $double($paused ? 0.0 : $rate));
        $set('MPNowPlayingInfoPropertyDefaultPlaybackRate', $double(1.0));
        $center = self::center();
        ObjC::msg('v:p', $center, 'setNowPlayingInfo:', $info);
        self::enable();
        if ($path !== self::$path && $paused) { // a film opened paused: Now Playing drops the app for Music's unless it played first (IINA's workaround)
            ObjC::msg('v:L', $center, 'setPlaybackState:', self::PLAYING);
        }
        self::$path = $path;
        ObjC::msg('v:L', $center, 'setPlaybackState:', $paused ? self::PAUSED : self::PLAYING);
    }

    /**
     * Shows what the app plays, for MyPHP\NowPlaying (mygo's
     * setNowPlaying): $info as it gives it (title, artist, album,
     * duration, position, rate, state, commands), or null to show nothing;
     * the commands it offers call $command with MediaCommand's bit and,
     * for a seek, where to. The artwork is not shown: MPMediaItemArtwork
     * asks for its image on another thread, where PHP cannot run.
     * @param ?array<string, mixed> $info
     * @param Closure(int, float): void $command
     */
    public static function media(?array $info, Closure $command): void
    {
        $owner = self::$media ??= new \stdClass();
        if ($info === null) {
            self::end($owner);
            return;
        }
        self::boot();
        $enabled = $info['commands'];
        if ($enabled & (self::MEDIA['play'] | self::MEDIA['pause'])) { $enabled |= self::MEDIA['toggle']; }
        if ($info['duration'] <= 0) { $enabled &= ~self::MEDIA['position']; }
        $handlers = [];
        foreach (self::MEDIA as $name => $bit) {
            if ($enabled & $bit) { $handlers[$name] = static fn(object $o, float $at = 0.0) => $command($bit, $at); }
        }
        self::$owner = \WeakReference::create($owner);
        self::$ownerId = spl_object_id($owner);
        self::$handlers = $handlers;
        self::$path = '';
        $k = self::$keys;
        $d = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        $set = fn(string $key, $value) => ObjC::msg('v:pp', $d, 'setObject:forKey:', $value, $k[$key]);
        $double = fn(float $v) => ObjC::msg('p:d', ObjC::cls('NSNumber'), 'numberWithDouble:', $v);
        foreach (['title' => 'MPMediaItemPropertyTitle', 'artist' => 'MPMediaItemPropertyArtist', 'album' => 'MPMediaItemPropertyAlbumTitle'] as $field => $key) {
            if ($info[$field] !== '') { $set($key, ObjC::str($info[$field])); }
        }
        if ($info['duration'] > 0) { $set('MPMediaItemPropertyPlaybackDuration', $double($info['duration'])); }
        $set('MPNowPlayingInfoPropertyMediaType', ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', self::AUDIO));
        $set('MPNowPlayingInfoPropertyElapsedPlaybackTime', $double($info['position']));
        $set('MPNowPlayingInfoPropertyDefaultPlaybackRate', $double(1.0));
        $set('MPNowPlayingInfoPropertyPlaybackRate', $double($info['state'] === 'playing' ? $info['rate'] : 0.0));
        $center = self::center();
        ObjC::msg('v:p', $center, 'setNowPlayingInfo:', $d);
        self::enable();
        ObjC::msg('v:L', $center, 'setPlaybackState:', match ($info['state']) { 'playing' => self::PLAYING, 'stopped' => self::STOPPED, default => self::PAUSED });
    }

    /** Ends what $owner showed, if it showed it last: nothing in Now Playing, the commands let go. */
    public static function end(object $owner): void
    {
        if (self::$ownerId !== spl_object_id($owner) || self::$path === null) {
            return;
        }
        $center = self::center();
        ObjC::msg('v:p', $center, 'setNowPlayingInfo:', null);
        ObjC::msg('v:L', $center, 'setPlaybackState:', self::STOPPED);
        $commands = ObjC::msg('p:', ObjC::cls('MPRemoteCommandCenter'), 'sharedCommandCenter');
        foreach (self::COMMANDS as [$getter]) {
            ObjC::msg('v:p', ObjC::msg('p:', $commands, $getter), 'removeTarget:', null);
        }
        self::$enabled = false;
        self::$path = null;
        self::$owner = null;
        self::$ownerId = 0;
        self::$handlers = [];
    }

    /** Gives each command its target, a block calling the handler of the one that showed it last, once; and enables those it handles. */
    private static function enable(): void
    {
        $commands = ObjC::msg('p:', ObjC::cls('MPRemoteCommandCenter'), 'sharedCommandCenter');
        foreach (self::COMMANDS as $name => [$getter]) {
            ObjC::msg('v:b', ObjC::msg('p:', $commands, $getter), 'setEnabled:', isset(self::$handlers[$name]));
        }
        if (self::$enabled) {
            return;
        }
        self::$enabled = true;
        $numbers = fn(array $vs) => self::array(array_map(fn(float $v) => ObjC::msg('p:d', ObjC::cls('NSNumber'), 'numberWithDouble:', $v), $vs));
        foreach (self::COMMANDS as $name => [$getter, $field, $type]) {
            $command = ObjC::msg('p:', $commands, $getter);
            if ($name === 'skipForward' || $name === 'skipBackward') { ObjC::msg('v:p', $command, 'setPreferredIntervals:', $numbers([self::SKIP])); }
            if ($name === 'rate') { ObjC::msg('v:p', $command, 'setSupportedPlaybackRates:', $numbers(self::RATES)); }
            self::$blocks[$name] ??= ObjC::block('p', function ($event) use ($name, $field, $type): int {
                $fn = self::$handlers[$name] ?? null;
                $owner = self::$owner?->get();
                if ($fn === null || $owner === null) {
                    return 1; // MPRemoteCommandHandlerStatusNoSuchContent
                }
                $field === null ? $fn($owner) : $fn($owner, (float)ObjC::msg("$type:", $event, $field));
                return 0; // MPRemoteCommandHandlerStatusSuccess
            }, false, 'l');
            ObjC::msg('p:p', $command, 'addTargetWithHandler:', self::$blocks[$name]);
        }
    }

    /** An NSArray of $items. @param list<mixed> $items */
    private static function array(array $items)
    {
        $a = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        foreach ($items as $it) { ObjC::msg('v:p', $a, 'addObject:', $it); }
        return $a;
    }

    private static function center()
    {
        return ObjC::msg('p:', ObjC::cls('MPNowPlayingInfoCenter'), 'defaultCenter');
    }

    /** Loads MediaPlayer and reads its keys, once. */
    private static function boot(): void
    {
        if (self::$keys !== []) {
            return;
        }
        ObjC::boot(['Foundation', 'MediaPlayer']);
        $lib = ObjC::$c->dlopen('/System/Library/Frameworks/MediaPlayer.framework/MediaPlayer', 2);
        foreach (self::KEYS as $name) {
            // Each an NSString variable: dlsym gives its address, the cast reads its value.
            self::$keys[$name] = ObjC::$c->cast('void*[1]', ObjC::$c->dlsym($lib, $name))[0];
        }
    }
}
