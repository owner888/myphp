<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\App;
use MyPHP\Audio\{Device, Mixer, Output};
use MyPHP\Logger;
use MyPHP\Native\Pcm;

/**
 * The app's sound output on Linux (mygo's output_linux.go, Media::
 * audioDevice): PulseAudio, else ALSA (Native\Pcm), of 48 kHz stereo
 * floats, holding as much ahead as the latency keeps (Latency::buffers of
 * Output::FRAMES), as Native\AudioQueue does on macOS. mygo mixes on a
 * goroutine that waits in the device's write; here a timer on the main
 * loop mixes every TICK seconds what the device takes then (Output::render),
 * so that PHP never waits on it.
 * @internal
 */
final class AudioOutput implements Device
{
    /** Seconds between the mixes, as mygo writes 10 ms at a time. */
    private const TICK = 0.01;
    private Pcm $pcm;
    private int $timer = 0;
    private bool $closed = false;

    public function __construct()
    {
        $this->pcm = Pcm::open(Mixer::RATE, Mixer::CHANNELS, $this->held() / Mixer::RATE, App::name());
    }

    public function start(): void
    {
        $this->fill();
    }

    /** Mixes what the device takes now into it, and again in TICK seconds; a device that failed is opened again (Output::lost). */
    private function fill(): void
    {
        $this->timer = 0;
        if ($this->closed) {
            return;
        }
        try {
            if (($n = $this->pcm->room()) > 0) { $this->pcm->write(Output::render($n)); }
        } catch (\RuntimeException $e) {
            Logger::error($e->getMessage(), 'audio');
            App::later(fn() => Output::lost($this));
            return;
        }
        $self = \WeakReference::create($this);
        $this->timer = Gtk::timeout(self::TICK, static function () use ($self): void { $self->get()?->fill(); });
    }

    /** The frames it keeps ahead (Latency::buffers of Output::FRAMES), as it opened. */
    public function held(): int
    {
        return Output::$latency->buffers() * Output::FRAMES;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->timer !== 0) { Gtk::cancel($this->timer); }
        $this->pcm->close();
    }
}
