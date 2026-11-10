<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use MyPHP\Media\{Player, Track};
use MyPHP\Native\{Av, AvReader, AvRenderers, AvThumbnails, MediaHost, MediaTicker, Sound, SoundDevice, Wasapi};
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;

/**
 * The player's system on Windows: FFmpeg, as on Linux (libavformat reads
 * what the core does not, libavcodec decodes, libswscale and libswresample
 * convert: the DLLs of a shared build, Native\Av), the sound through
 * WASAPI (Native\Wasapi), the picture drawn by the window's own renderer
 * (Native\AvRenderers, this its MediaHost: the app window's timers, WASAPI,
 * the windows). Video decodes in software; Now Playing and JPEGs are not
 * here yet, and staying awake is Power's.
 */
final class Media implements Platform\Media, MediaHost
{
    /** @var array<int, Closure(): void> what each player does at a tick, by its object id */
    private array $ticks = [];
    private int $timer = 0;

    public function __construct(private readonly Backend $b) {}

    public function reader(string $path): Platform\MediaReader
    {
        return new AvReader($path);
    }

    public function readerName(): string
    {
        return 'FFmpeg';
    }

    /** Whether FFmpeg has a decoder of $t's codec. */
    public function playable(Track $t): bool
    {
        if ($t->type !== Track::VIDEO && $t->type !== Track::AUDIO) {
            return false;
        }
        $id = Av::codecId($t);
        return $id !== null && Av::ffi()->avcodec_find_decoder($id) !== null;
    }

    /** Software decoding: VA-API is Linux's, and D3D11VA not here yet. */
    public function output(bool $hardware): Platform\Renderers
    {
        return new AvRenderers($this, false);
    }

    public function thumbnails(Track $video, int $width, bool $hardware): Platform\Thumbnails
    {
        return new AvThumbnails($video, $width);
    }

    public function hardwareDecodes(Track $video, bool $hardware): bool
    {
        return false;
    }

    public function decodingSwitchable(Track $video): bool
    {
        return false;
    }

    /** FFmpeg's decoder of the track, by its name ("FFmpeg h264"). */
    public function videoDecoder(Track $video, bool $hardware): string
    {
        return $this->decoderName($video);
    }

    public function audioDecoder(Track $audio): string
    {
        return $this->decoderName($audio);
    }

    private function decoderName(Track $t): string
    {
        $id = Av::codecId($t);
        $codec = $id === null ? null : Av::ffi()->avcodec_find_decoder($id);
        return 'FFmpeg' . ($codec === null ? '' : ' ' . \FFI::string($codec->name));
    }

    /** A timer of the app's window, which runs on while a window resizes or a menu is open (their modal loops dispatch it). */
    public function addTicker(object $player, Closure $tick): void
    {
        $this->ticks[spl_object_id($player)] = $tick;
        if ($this->timer === 0) { $this->tick(); }
    }

    private function tick(): void
    {
        $this->timer = $this->b->timeout(MediaTicker::INTERVAL, function (): void {
            $this->timer = 0;
            if ($this->ticks === []) {
                return;
            }
            foreach ($this->ticks as $fn) { $fn(); }
            if ($this->timer === 0 && $this->ticks !== []) { $this->tick(); }
        });
    }

    public function removeTicker(object $player): void
    {
        unset($this->ticks[spl_object_id($player)]);
        if ($this->ticks === [] && $this->timer !== 0) {
            $this->b->cancel($this->timer);
            $this->timer = 0;
        }
    }

    /** The display, or the system, kept awake through Power while held. */
    public function awake(string $reason): Platform\Awake
    {
        $power = $this->b->power();
        return new class($power, $reason) implements Platform\Awake {
            private ?Closure $release = null;
            private bool $display = true;

            public function __construct(private readonly Platform\Power $power, private readonly string $reason) {}

            public function hold(): void
            {
                $this->release ??= $this->power->keepAwake($this->display, $this->reason);
            }

            public function setDisplay(bool $display): void
            {
                $this->display = $display;
                if ($this->release !== null) {
                    $this->release();
                    $this->hold();
                }
            }

            public function release(): void
            {
                ($this->release)?->__invoke();
                $this->release = null;
            }

            public function held(): bool
            {
                return $this->release !== null;
            }
        };
    }

    public function pictureInPicture(Player $p, Closure $changed): ?object
    {
        return null;
    }

    public function showNowPlaying(object $owner, array $handlers, string $path, float $duration, float $time, float $rate, bool $paused, int $index, int $count, int $chapter, int $chapters): void {}

    public function endNowPlaying(object $owner): void {}

    public function jpeg(string $rgba, int $w, int $h, float $quality): string
    {
        throw new Unsupported('myphp: JPEG is not on Windows yet');
    }

    public function jpegPixels(string $bytes, int $max): string
    {
        throw new Unsupported('myphp: JPEG is not on Windows yet');
    }

    /** FFmpeg's (libswscale's pictures the window's renderer draws), WASAPI's, no hardware decoding. */
    public function names(): array
    {
        return ['video' => 'FFmpeg', 'audio' => 'WASAPI', 'hwdec' => ''];
    }

    /** WASAPI's active render endpoints ("wasapi/<endpoint id>"). */
    public function audioOutputs(): array
    {
        return Wasapi::outputs();
    }

    public function audioDevice(): \MyPHP\Audio\Device
    {
        return new SoundDevice($this);
    }

    public function timeout(float $seconds, Closure $fn): int
    {
        return $this->b->timeout($seconds, $fn);
    }

    public function cancel(int $id): void
    {
        $this->b->cancel($id);
    }

    public function sound(int $rate, int $channels, float $ahead, ?string $device): Sound
    {
        return Wasapi::open($rate, $channels, $ahead, $device);
    }

    public function windows(): array
    {
        return Window::all();
    }
}
