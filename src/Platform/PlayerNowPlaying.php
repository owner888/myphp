<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * The player's film in the system's media controls, for the Media of a
 * system whose NowPlaying takes a player's state and where its commands
 * go (Linux\Media: MPRIS; Windows\Media: SystemMediaTransportControls): its
 * name, duration, position, rate and state, its commands calling $handlers
 * with $owner (held weakly), as Native\NowPlaying's do on macOS: Play,
 * Pause, PlayPause, Stop, Next, Previous, and a seek as the position's.
 * The last owner to show takes the commands; ending another's does
 * nothing. The Media using it has its backend as $b, whose nowPlaying()
 * has show(?array $info, ?Closure $command).
 *
 * @internal
 */
trait PlayerNowPlaying
{
    /** MediaCommand's bits of the handlers' names (Native\NowPlaying's MEDIA, a position a seek). */
    private const COMMANDS = ['play' => 1, 'pause' => 2, 'toggle' => 4, 'stop' => 8, 'next' => 16, 'previous' => 32, 'position' => 64];

    /** What showed the player's state last, by its id. */
    private int $nowPlayingOwner = 0;

    public function showNowPlaying(object $owner, array $handlers, string $path, float $duration, float $time, float $rate, bool $paused, int $index, int $count, int $chapter, int $chapters): void
    {
        $bits = 0;
        foreach (self::COMMANDS as $name => $bit) {
            if (isset($handlers[$name])) { $bits |= $bit; }
        }
        $this->nowPlayingOwner = spl_object_id($owner);
        $ref = \WeakReference::create($owner);
        $this->b->nowPlaying()->show(['title' => pathinfo($path, PATHINFO_FILENAME), 'artist' => '', 'album' => '', 'artwork' => '', 'duration' => $duration, 'position' => $time,
            'rate' => $rate, 'state' => $paused ? 'paused' : 'playing', 'commands' => $bits], static function (int $cmd, float $at) use ($ref, $handlers): void {
            $o = $ref->get();
            $name = array_search($cmd, self::COMMANDS, true);
            if ($o === null || $name === false || !isset($handlers[$name])) {
                return;
            }
            $name === 'position' ? $handlers[$name]($o, $at) : $handlers[$name]($o);
        });
    }

    public function endNowPlaying(object $owner): void
    {
        if ($this->nowPlayingOwner !== spl_object_id($owner)) {
            return;
        }
        $this->nowPlayingOwner = 0;
        $this->b->nowPlaying()->show(null, null);
    }
}
