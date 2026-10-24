<?php
declare(strict_types=1);

namespace MyPHP\Audio;

use Closure;
use MyPHP\App;

/**
 * Opens the device while anything plays, and mixes into it (mygo's
 * output.go): an Audio Queue on the default output, which follows it,
 * calling back on the main thread's run loop, so that the mixing is in
 * PHP; a device that does not open is stood in for by one that plays
 * nothing, on time, and tries the device again after a while. It closes
 * five seconds after everything stopped, so that an app that plays
 * nothing keeps no audio device busy.
 * @internal
 */
final class Output
{
    /** The frames a buffer of the device holds (21.3 ms), and how many it keeps queued: about 85 ms ahead. */
    public const FRAMES = 1024, BUFFERS = 4;
    /** How long the output stays open with nothing playing, in seconds. */
    public const IDLE_CLOSE = 5.0;

    /** Tests mix by hand (Mixer::mix): nothing opens. */
    public static bool $testMode = false;
    /** @var ?Closure(): Device what opens the device; tests replace it */
    public static ?Closure $open = null;
    private static ?Device $dev = null;
    private static float $idle = 0.0;
    private static bool $closing = false;

    /** A voice started: opens the output, unless it is open. */
    public static function wake(): void
    {
        if (self::$testMode || self::$dev !== null) { return; }
        if (!App::running()) {
            Audio::setErr(new \RuntimeException('audio: the output plays while the app runs (App::run)'));
            return;
        }
        self::start();
    }

    private static function start(): void
    {
        self::$idle = 0.0; self::$closing = false;
        try {
            self::$dev = (self::$open ?? fn(): Device => new \MyPHP\Native\AudioQueue())();
            Audio::setErr(null);
        } catch (\Throwable $e) {
            Audio::setErr($e);
            self::$dev = new NullDevice(null, retry: true);
        }
        self::$dev->start();
    }

    /** Whether a device is open. */
    public static function open(): bool
    {
        return self::$dev !== null;
    }

    /**
     * The next $frames frames of what plays, as the device takes them:
     * interleaved stereo 32-bit floats. The streams decode a little ahead
     * first; after five seconds of silence the output closes.
     */
    public static function render(int $frames): string
    {
        Stream::fillAll();
        [$out, $busy] = Mixer::mix($frames);
        if ($busy) {
            self::$idle = 0.0;
        } elseif ((self::$idle += $frames / Mixer::RATE) >= self::IDLE_CLOSE && !self::$closing && self::$dev !== null) {
            self::$closing = true;
            $dev = self::$dev;
            App::later(function () use ($dev): void { if (self::$dev === $dev && Mixer::$voices === []) { self::close(); } else { self::$closing = false; self::$idle = 0.0; } }); // not from the device's own callback
        }
        return pack('g*', ...$out);
    }

    /** Closes the device; one wanted again opens anew. */
    public static function close(): void
    {
        $dev = self::$dev;
        self::$dev = null;
        $dev?->close();
    }

    /** The device stopped taking frames, as when it went away, or a stand-in tries the device again: it opens anew while anything plays. */
    public static function lost(Device $dev): void
    {
        if (self::$dev !== $dev) { return; }
        self::close();
        if (Mixer::$voices !== []) { self::wake(); }
    }
}
