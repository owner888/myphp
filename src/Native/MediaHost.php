<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;

/**
 * What the players over FFmpeg (AvRenderers) and the app's mixer
 * (SoundDevice) ask of the system they run on: the main loop's timers, its
 * sound output, its windows (Platform\Linux\Media, Platform\Windows\Media).
 *
 * @internal
 */
interface MediaHost
{
    /** A timer of the main loop running $fn once in $seconds, which the main loop runs on while a window resizes or a menu is open: its id. */
    public function timeout(float $seconds, Closure $fn): int;

    /** Removes a timer timeout() made, unless it ran. */
    public function cancel(int $id): void;

    /** A sound output of $channels channels at $rate holding $ahead seconds: $device, one of Media::audioOutputs' names, else the default; throws why it did not open. */
    public function sound(int $rate, int $channels, float $ahead, ?string $device): Sound;

    /** The platform's windows, each with its window (w) and requestFrame(), whose nodes showing a film paint again as a frame shows. @return list<object> */
    public function windows(): array;
}
