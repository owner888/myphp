<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use MyPHP\Media\{Player, Track};
use MyPHP\Native\{Av, AvDecoder, AvReader, AvRenderers, AvThumbnails, MediaHost, MediaTicker, Sound, SoundDevice, Wasapi, Wic};
use MyPHP\Platform;

/**
 * The player's system on Windows: FFmpeg, as on Linux (libavformat reads
 * what the core does not, libavcodec decodes, libswscale and libswresample
 * convert: the DLLs of a shared build, Native\Av), the sound through
 * WASAPI (Native\Wasapi), the picture drawn by the window's own renderer
 * (Native\AvRenderers, this its MediaHost: the app window's timers, WASAPI,
 * the windows). Video decodes with D3D11VA where it can and hardware
 * decoding is asked (its frames copied to memory), else in software. JPEGs are WIC's (Native\Wic); staying awake is Power's.
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

    public function output(bool $hardware): Platform\Renderers
    {
        return new AvRenderers($this, $hardware);
    }

    public function thumbnails(Track $video, int $width, bool $hardware): Platform\Thumbnails
    {
        return new AvThumbnails($video, $width);
    }

    /** With D3D11VA where it is asked, the codec has it and a Direct3D 11 device opens (Native\AvDecoder::hardwareFor). */
    public function hardwareDecodes(Track $video, bool $hardware): bool
    {
        return $hardware && AvDecoder::hardwareFor($video)[0];
    }

    /** Where D3D11VA decodes it: either way, as software decodes every track. */
    public function decodingSwitchable(Track $video): bool
    {
        return AvDecoder::hardwareFor($video)[0];
    }

    /** FFmpeg's decoder of the track, by its name ("FFmpeg h264", "FFmpeg h264 (D3D11VA)"). */
    public function videoDecoder(Track $video, bool $hardware): string
    {
        return $this->decoderName($video) . ($this->hardwareDecodes($video, $hardware) ? ' (D3D11VA)' : '');
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

    /** MediaCommand's bits of the handlers' names (Native\NowPlaying's MEDIA, a position a seek), as Linux\Media's. */
    private const COMMANDS = ['play' => 1, 'pause' => 2, 'toggle' => 4, 'stop' => 8, 'next' => 16, 'previous' => 32, 'position' => 64];

    /** What showed the player's state last, by its id. */
    private int $nowPlayingOwner = 0;

    /**
     * The film in the system's media controls (Windows\NowPlaying): its
     * name, duration, position, rate and state, its commands calling
     * $handlers with $owner (held weakly), as Linux\Media's do.
     */
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

    /** WIC's JPEG (Native\Wic), its alpha left out. */
    public function jpeg(string $rgba, int $w, int $h, float $quality): string
    {
        return Wic::jpeg($rgba, $w, $h, $quality);
    }

    /** WIC's decoding, scaled down to $max on a side, made RGBA. */
    public function jpegPixels(string $bytes, int $max): string
    {
        return Wic::pixels($bytes, $max);
    }

    /** FFmpeg's (libswscale's pictures the window's renderer draws), WASAPI's, D3D11VA's. */
    public function names(): array
    {
        return ['video' => 'FFmpeg', 'audio' => 'WASAPI', 'hwdec' => 'd3d11va'];
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
