<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/** How far ahead of what is heard the output mixes, as Web Audio's latencyHint (mygo's audio.Latency). */
enum Latency
{
    /** About 85 ms ahead (four buffers of 21.3 ms), so that sounds start, stop and change as soon as they are asked to, as games and interfaces want. The default. */
    case Interactive;
    /**
     * About 256 ms ahead (twelve buffers), for apps that play music and
     * other long audio: it plays on through the moments the system keeps
     * the app waiting (macOS, up to 0.2 s while its appearance changes),
     * where the interactive latency runs dry and is heard to stop. Plays,
     * pauses, seeks and volume changes are heard that much later.
     */
    case Playback;

    /** How many of the device's buffers (Output::FRAMES each) it keeps queued. */
    public function buffers(): int
    {
        return $this === self::Playback ? 12 : 4;
    }
}
