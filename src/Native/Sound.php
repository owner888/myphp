<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * A sound output of interleaved 32-bit floats the player and the app's
 * mixer write to from a timer (AvRenderers, SoundDevice): Pcm on Linux
 * (PulseAudio, ALSA), Wasapi on Windows. Nothing of it waits.
 *
 * @internal
 */
interface Sound
{
    /** The system it plays through (the Inspector's current-ao): "PulseAudio", "ALSA", "WASAPI". */
    public function name(): string;

    /** The frames it takes now without waiting. */
    public function room(): int;

    /** Plays $pcm (whole frames) after what it was written, at most room() of them; throws as the device failed. */
    public function write(string $pcm): void;

    /** The frames written not heard yet; null while the device has not told. */
    public function delay(): ?int;

    /** Drops what it holds, unheard. */
    public function flush(): void;

    public function close(): void;
}
