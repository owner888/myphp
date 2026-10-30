<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;

/**
 * Shows what the app plays where the system shows media, Now Playing in
 * Control Center and the menu bar (MPNowPlayingInfoCenter), and reports
 * the media keys of keyboards and headsets, and those controls, as
 * commands while it shows something (mygo's NowPlaying):
 *
 *     NowPlaying::set(new NowPlayingInfo(title: 'Spring', artist: 'Vivaldi',
 *         duration: 200, commands: MediaCommand::PLAY | MediaCommand::PAUSE | MediaCommand::NEXT));
 *     NowPlaying::onCommand(function (int $cmd, float $position): void { … });
 *
 * An app playing with Audio\Player can let NowPlaying drive the player
 * instead (control()). Times are in seconds. A video player showing in Now
 * Playing (VideoPlayerOptions::$nowPlaying) takes it over, as the last to
 * show it: the system has one Now Playing for the app.
 */
final class NowPlaying
{
    private static NowPlayingInfo $info;
    /** When $info's position was, hrtime. */
    private static int $at = 0;
    private static bool $showing = false;
    private static ?MediaPlayer $player = null;
    /** @var array<int, Closure(int, float): void> */
    private static array $onCommand = [];
    private static int $next = 0;
    /**
     * What shows the info where the system shows media: given a function
     * of the info to show, null for none, it calls it once the app is
     * ready, and shows that. Tests replace it.
     * @internal
     * @var ?Closure(Closure(): ?array<string, mixed>): void
     */
    public static ?Closure $show = null;

    /** Shows $info where the system shows media, replacing what it showed. It may be called before App::run; the system shows it once the app is ready. */
    public static function set(NowPlayingInfo $info): void
    {
        self::$info = clone $info;
        [self::$at, self::$showing] = [hrtime(true), true];
        self::syncPlayer();
        self::apply();
    }

    /** Changes the state and position of what it shows, as the app plays, pauses, stops or seeks. */
    public static function setPlayback(PlaybackState $state, float $position): void
    {
        self::$info ??= new NowPlayingInfo();
        [self::$info->state, self::$info->position, self::$at] = [$state, $position, hrtime(true)];
        if (self::$showing) { self::apply(); }
    }

    /** Removes what it shows: the system's controls no longer show the app, and the media keys go to other apps. */
    public static function clear(): void
    {
        if (!self::$showing) { return; }
        self::$showing = false;
        self::apply();
    }

    /** What it shows, its position where playback is now, or null if it shows nothing. */
    public static function info(): ?NowPlayingInfo
    {
        if (!self::$showing) { return null; }
        $info = clone self::$info;
        $info->position = self::position();
        return $info;
    }

    /**
     * Calls $fn on the main thread with each command (MediaCommand's) of
     * the media keys and the system's controls while it shows something,
     * and a position: where MediaCommand::SEEK moves to, within the track,
     * and for the other commands where playback is as the command comes,
     * so that setPlayback(PlaybackState::Paused, $position) keeps the
     * place. The app plays, pauses or moves as it asks, and says so with
     * setPlayback (or set, for another track). Returns a function that
     * removes it.
     * @param Closure(int, float): void $fn
     */
    public static function onCommand(Closure $fn): Closure
    {
        $k = self::$next++;
        self::$onCommand[$k] = $fn;
        return function () use ($k): void { unset(self::$onCommand[$k]); };
    }

    /**
     * Lets the media keys and the system's controls drive $p, as an
     * Audio\Player: they play, pause, stop and seek it, and Now Playing
     * shows its state, position and duration, which set() takes from $p
     * too. The commands it handles are offered on top of those the info
     * sets, SEEK when $p knows its duration; NEXT and PREVIOUS still go to
     * onCommand, for the app to change tracks:
     *
     *     $release = NowPlaying::control($music);
     *     NowPlaying::set(new NowPlayingInfo(title: 'Spring', artist: 'Vivaldi'));
     *     $music->play();
     *     NowPlaying::sync();
     *
     * The system learns what the app does with $p itself when it calls
     * sync(); an Audio\Player is synced at its end. Returns a function
     * that stops driving $p.
     */
    public static function control(MediaPlayer $p): Closure
    {
        $off = self::onCommand(function (int $cmd, float $pos) use ($p): void {
            switch ($cmd) {
                case MediaCommand::PLAY: $p->play(); break;
                case MediaCommand::PAUSE: $p->pause(); break;
                case MediaCommand::STOP: $p->stop(); break;
                case MediaCommand::SEEK: $p->seek($pos); break;
                default: return;
            }
            self::sync();
        });
        $offEnd = method_exists($p, 'onEnd') ? $p->onEnd(self::sync(...)) : null; // a player telling of its end, as Audio\Player
        self::$player = $p;
        self::sync();
        $done = false;
        return function () use ($p, $off, $offEnd, &$done): void {
            if ($done) { return; }
            $done = true;
            $off();
            if ($offEnd !== null) { $offEnd(); }
            if (self::$player === $p) { self::$player = null; }
        };
    }

    /** Shows the state, position and duration of the player control() drives, after the app played, paused, stopped or moved it itself. */
    public static function sync(): void
    {
        if (self::syncPlayer() && self::$showing) { self::apply(); }
    }

    /** Takes the state of the player control() drives, if any. */
    private static function syncPlayer(): bool
    {
        $p = self::$player;
        if ($p === null) { return false; }
        self::$info ??= new NowPlayingInfo();
        [$pos, $dur] = [$p->position(), $p->duration()];
        self::$info->state = match (true) {
            $p->playing() => PlaybackState::Playing,
            $pos == 0 || $dur > 0 && $pos >= $dur => PlaybackState::Stopped,
            default => PlaybackState::Paused,
        };
        [self::$info->position, self::$info->duration, self::$at] = [$pos, $dur, hrtime(true)];
        return true;
    }

    /** Where playback is now. */
    private static function position(): float
    {
        $pos = self::$info->position;
        if (self::$info->state === PlaybackState::Playing) {
            $pos += (hrtime(true) - self::$at) / 1e9 * self::rate();
        }
        if (($d = self::$info->duration) > 0 && $pos > $d) { $pos = $d; }
        return max($pos, 0.0);
    }

    private static function rate(): float
    {
        return self::$info->rate > 0 ? self::$info->rate : 1.0;
    }

    /** The commands offered. */
    private static function commands(): int
    {
        $c = self::$info->commands;
        if ($c === 0 || self::$player !== null) { $c |= MediaCommand::PLAY | MediaCommand::PAUSE | MediaCommand::STOP; }
        if (self::$player !== null && self::$info->duration > 0) { $c |= MediaCommand::SEEK; }
        return $c;
    }

    /** Has the system show the latest info, once the app is ready; each reads the latest, so they may run in any number. */
    private static function apply(): void
    {
        $show = self::$show ??= static function (Closure $info): void {
            App::whenReady(static fn() => Native\NowPlaying::media($info(), self::command(...)));
        };
        $show(static function (): ?array {
            if (!self::$showing) { return null; }
            $i = self::$info;
            return [
                'title' => $i->title, 'artist' => $i->artist, 'album' => $i->album, 'artwork' => $i->artwork,
                'duration' => max($i->duration, 0.0), 'position' => self::position(), 'rate' => self::rate(),
                'state' => match ($i->state) { PlaybackState::Playing => 'playing', PlaybackState::Paused => 'paused', PlaybackState::Stopped => 'stopped' },
                'commands' => self::commands(),
            ];
        });
    }

    /** A command of the system's (MediaCommand's, TOGGLE the play/pause key's), and for SEEK where it moves to. @internal */
    public static function command(int $cmd, float $position): void
    {
        if (!self::$showing) { return; }
        if ($cmd === MediaCommand::TOGGLE) {
            $cmd = self::$info->state === PlaybackState::Playing ? MediaCommand::PAUSE : MediaCommand::PLAY;
        }
        if (($cmd & self::commands()) === 0) { return; }
        if ($cmd !== MediaCommand::SEEK) { $position = self::position(); }
        if (($d = self::$info->duration) > 0) { $position = min($position, $d); }
        $position = max($position, 0.0);
        foreach (self::$onCommand as $fn) { $fn($cmd, $position); }
    }
}
