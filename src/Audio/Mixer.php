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

    /**
     * Mixes the next $frames frames, interleaved stereo, and whether a
     * voice plays.
     * @return array{0: list<float>, 1: bool}
     */
    public static function mix(int $frames): array
    {
        $out = array_fill(0, $frames * 2, 0.0);
        $kept = [];
        foreach (self::$voices as $v) {
            if ($v->closed || !$v->mix($out, 0, $frames, self::$volume)) {
                $v->active = false;
                if ($v->orphan) { $v->close(); }
                continue;
            }
            $kept[] = $v;
        }
        self::$voices = $kept;
        foreach ($out as $i => $s) {
            if ($s > 1.0) { $out[$i] = 1.0; } elseif ($s < -1.0) { $out[$i] = -1.0; }
        }
        return [$out, $kept !== []];
    }
}
