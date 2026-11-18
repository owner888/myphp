<?php
declare(strict_types=1);

namespace MyPHP\Audio;

use Closure;
use MyPHP\App;

/**
 * Opens the device while anything plays, and mixes into it (mygo's
 * output.go): the system's (Platform\Media::audioDevice: an Audio Queue on
 * the default output on macOS, which follows it, PulseAudio or ALSA on
 * Linux), taking frames on the main thread, so that the mixing is in
 * PHP; a device that does not open is stood in for by one that plays
 * nothing, on time, and tries the device again after a while. It closes
 * five seconds after everything stopped, so that an app that plays
 * nothing keeps no audio device busy; unless something holds it open
 * (hold(), Audio::hold): a game's, whose sounds come one by one with
 * silences between, each of which would open the device anew, which
 * keeps the main thread 50 to 100 ms, a stop in the game.
 * @internal
 */
final class Output
{
    /** The frames a buffer of the device holds (21.3 ms); how many it keeps queued is the latency's (Latency::buffers). */
    public const FRAMES = 1024;
    /** How far ahead it mixes (Audio::setLatency). */
    public static Latency $latency = Latency::Interactive;
    /** How long the output stays open with nothing playing, in seconds. */
    public const IDLE_CLOSE = 5.0;

    /** Tests mix by hand (Mixer::mix): nothing opens. */
    public static bool $testMode = false;
    /** @var ?Closure(): Device what opens the device; tests replace it */
    public static ?Closure $open = null;
    private static ?Device $dev = null;
    private static float $idle = 0.0;
    private static bool $closing = false;
    /** How many hold the output open while nothing plays (hold()). */
    private static int $holds = 0;

    /** Keeps the output open while nothing plays, until release(): opened now, or as the app starts when it does not run yet. */
    public static function hold(): void
    {
        self::$holds++;
        App::whenReady(static function (): void { if (self::$holds > 0) { self::wake(); } });
    }

    /** Lets go of a hold(): with none left, the output closes after five seconds of silence again. */
    public static function release(): void
    {
        if (self::$holds === 0) { throw new \LogicException('audio: the output was released more times than it was held'); }
        self::$holds--;
    }

    /** Whether something holds the output open (hold()). */
    public static function holding(): bool
    {
        return self::$holds > 0;
    }

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

    /**
     * Opens the device and starts it. One that does not open, or opens and
     * will not start (macOS refuses an Audio Queue while the output it
     * follows is changing or the machine wakes, OSStatus -66681), is closed
     * and stood in for by the NullDevice that tries again after a while: it
     * never throws, being called from the device's own news (lost()), a
     * callback, where PHP's FFI cannot throw.
     */
    private static function start(): void
    {
        self::$idle = 0.0; self::$closing = false;
        try {
            self::$dev = (self::$open ?? fn(): Device => App::backend()->media()->audioDevice())();
            Mixer::setHeld(self::$dev->held());
            self::$dev->start();
            Audio::setErr(null);
            return;
        } catch (\Throwable $e) {
            Audio::setErr($e);
            $failed = self::$dev;
            self::$dev = null;
            try { $failed?->close(); } catch (\Throwable) {}
        }
        self::$dev = new NullDevice(null, retry: true);
        Mixer::setHeld(self::$dev->held());
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
        } elseif ((self::$idle += $frames / Mixer::RATE) >= self::IDLE_CLOSE && self::$holds === 0 && !self::$closing && self::$dev !== null) {
            self::$closing = true;
            $dev = self::$dev;
            App::later(function () use ($dev): void { if (self::$dev === $dev && Mixer::$voices === [] && self::$holds === 0) { self::close(); } else { self::$closing = false; self::$idle = 0.0; } }); // not from the device's own callback
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

    /** The device stopped taking frames, as when it went away, or a stand-in tries the device again: it opens anew while anything plays, or something holds it. */
    public static function lost(Device $dev): void
    {
        if (self::$dev !== $dev) { return; }
        self::close();
        if (Mixer::$voices !== [] || self::$holds > 0) { self::wake(); }
    }
}
