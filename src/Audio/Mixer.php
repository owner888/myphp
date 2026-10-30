<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * Mixes the voices playing into the output's frames (mygo's mixer.go):
 * each voice resampled to the output's rate with a Catmull-Rom spline, at
 * its speed, through its gains, which ramp over 5 ms so that no change
 * clicks; the sum clipped to [-1, 1].
 * @internal
 */
final class Mixer
{
    /** The output's format, which every player is resampled to. */
    public const RATE = 48000, CHANNELS = 2;
    /** How long volume changes, fades in and fades out take: 5 ms. */
    public const RAMP = self::RATE / 200;

    /** @var list<Voice> the voices playing, or fading out */
    public static array $voices = [];
    /** The volume of everything (Audio::setVolume). */
    public static float $volume = 1.0;
    /** The output's clock: the frames mixed so far, how many of the latest the device holds, not heard yet, and how many it holds at most. */
    public static int $mixed = 0, $unheard = 0, $held = 0;

    /** A device opened which holds $n frames ahead of what is heard, and none of what was mixed before. */
    public static function setHeld(int $n): void
    {
        if (!Output::$testMode) { [self::$held, self::$unheard] = [$n, 0]; }
    }

    /** The output's frame heard now: the frames mixed before it are. */
    public static function heard(): int
    {
        return self::$mixed - self::$unheard;
    }

    /**
     * Mixes the next $frames frames, interleaved stereo, and whether a
     * voice plays.
     * @return array{0: list<float>, 1: bool}
     */
    public static function mix(int $frames): array
    {
        $out = array_fill(0, $frames * 2, 0.0);
        $kept = $ended = [];
        [$at, $heard] = [self::$mixed, self::heard()];
        foreach (self::$voices as $v) {
            if ($v->closed || !$v->mix($out, $frames, self::$volume, $at, $heard)) {
                if ($v->atEnd) {
                    $v->atEnd = false;
                    $ended[] = $v->onEnd;
                }
                $v->active = false;
                if ($v->orphan) { $v->close(); }
                continue;
            }
            $kept[] = $v;
        }
        self::$voices = $kept;
        self::$mixed += $frames;
        self::$unheard = min(self::$unheard + $frames, self::$held);
        foreach ($ended as $listeners) {
            foreach ($listeners as $fn) { $fn(); }
        }
        foreach ($out as $i => $s) {
            if ($s > 1.0) { $out[$i] = 1.0; } elseif ($s < -1.0) { $out[$i] = -1.0; }
        }
        return [$out, $kept !== []];
    }
}
