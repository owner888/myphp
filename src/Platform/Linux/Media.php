<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use MyPHP\Audio\Device;
use MyPHP\Media\{Player, Track};
use MyPHP\Native\{Av, AvDecoder, AvReader, AvRenderers, AvThumbnails, MediaHost, MediaTicker, Pcm, Sound, SoundDevice};
use MyPHP\Platform;

/**
 * The player's system on Linux: FFmpeg (libavformat reads what the core
 * does not, libavcodec decodes, libswscale and libswresample convert), the
 * sound through PulseAudio or ALSA, the picture drawn by the window's own
 * renderer (Native\AvRenderers, this its MediaHost: GLib's timers, Pcm,
 * the windows). Video decodes with VA-API where it can and
 * hardware decoding is asked (its frames copied to memory), else in
 * software.
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

    /** With VA-API where it is asked, the codec has it and a device opens (Native\AvDecoder::hardwareFor). */
    public function hardwareDecodes(Track $video, bool $hardware): bool
    {
        return $hardware && AvDecoder::hardwareFor($video)[0];
    }

    /** Where VA-API decodes it: either way, as software decodes every track. */
    public function decodingSwitchable(Track $video): bool
    {
        return AvDecoder::hardwareFor($video)[0];
    }

    /** FFmpeg's decoder of the track, by its name ("FFmpeg h264", "FFmpeg h264 (VA-API)"). */
    public function videoDecoder(Track $video, bool $hardware): string
    {
        return $this->decoderName($video) . ($this->hardwareDecodes($video, $hardware) ? ' (VA-API)' : '');
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

    /** A GLib timeout of the main loop, which runs on while a window resizes or a menu is open. */
    public function addTicker(object $player, Closure $tick): void
    {
        $this->ticks[spl_object_id($player)] = $tick;
        if ($this->timer === 0) { $this->tick(); }
    }

    private function tick(): void
    {
        $this->timer = Gtk::timeout(MediaTicker::INTERVAL, function (): void {
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
            Gtk::cancel($this->timer);
            $this->timer = 0;
        }
    }

    public function awake(string $reason): Platform\Awake
    {
        return new Awake($this->b->power(), $reason);
    }

    /** None: Linux has no system picture in picture. */
    public function pictureInPicture(Player $p, Closure $changed): ?object
    {
        return null;
    }

    /** MediaCommand's bits of the handlers' names (Native\NowPlaying's MEDIA, a position a seek); MPRIS has no skips, rates nor loop. */
    private const COMMANDS = ['play' => 1, 'pause' => 2, 'toggle' => 4, 'stop' => 8, 'next' => 16, 'previous' => 32, 'position' => 64];

    /** What showed the player's state last, by its id. */
    private int $nowPlayingOwner = 0;

    /**
     * The film in the desktop's media controls: the MPRIS player
     * (Linux\NowPlaying) shows its name, duration, position, rate and
     * state, and its commands call $handlers with $owner (held weakly), as
     * Native\NowPlaying's do: Play, Pause, PlayPause, Stop, Next, Previous,
     * and Seek and SetPosition as the position's.
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

    /** GdkPixbuf's JPEG, its alpha left out. */
    public function jpeg(string $rgba, int $w, int $h, float $quality): string
    {
        if (strlen($rgba) !== $w * $h * 4) {
            throw new \RuntimeException("myphp: $w by $h pixels are not " . strlen($rgba) . ' bytes');
        }
        $g = self::pixbuf();
        $pix = \MyPHP\Native\Mem::alloc($w * $h * 4);
        \FFI::memcpy($pix, $rgba, $w * $h * 4);
        $pb = $g->gdk_pixbuf_new_from_data($g->cast('unsigned char*', \MyPHP\Native\Mem::addr($pix)), 0, 1, 8, $w, $h, $w * 4, null, null); // GDK_COLORSPACE_RGB, alpha: the pixels stay ours
        $buf = $g->new('char*'); $size = $g->new('size_t');
        $keys = $g->new('char*[2]'); $values = $g->new('char*[2]');
        $key = self::cstr($g, 'quality'); $value = self::cstr($g, (string)max(0, min(100, (int)round($quality * 100))));
        $keys[0] = $g->cast('char*', \FFI::addr($key[0])); $values[0] = $g->cast('char*', \FFI::addr($value[0]));
        try {
            if (!$g->gdk_pixbuf_save_to_bufferv($pb, \FFI::addr($buf), \FFI::addr($size), 'jpeg', $keys, $values, null)) {
                throw new \RuntimeException('myphp: GdkPixbuf made no JPEG');
            }
            $out = \FFI::string($buf, $size->cdata);
            $g->g_free($buf);
            return $out;
        } finally {
            $g->g_object_unref($pb);
            \MyPHP\Native\Mem::free($pix);
        }
    }

    /** GdkPixbuf's decoding, scaled down to $max on a side, made RGBA. */
    public function jpegPixels(string $bytes, int $max): string
    {
        $g = self::pixbuf();
        $loader = $g->gdk_pixbuf_loader_new();
        $ok = $g->gdk_pixbuf_loader_write($loader, $bytes, strlen($bytes), null);
        $ok = $g->gdk_pixbuf_loader_close($loader, null) && $ok;
        $pb = $ok ? $g->gdk_pixbuf_loader_get_pixbuf($loader) : null;
        if ($pb === null) {
            $g->g_object_unref($loader);
            throw new \RuntimeException('myphp: GdkPixbuf decodes no image of these bytes');
        }
        [$w, $h] = [$g->gdk_pixbuf_get_width($pb), $g->gdk_pixbuf_get_height($pb)];
        $owned = [];
        if (max($w, $h) > $max) {
            $k = $max / max($w, $h);
            [$w, $h] = [max(1, (int)round($w * $k)), max(1, (int)round($h * $k))];
            $pb = $owned[] = $g->gdk_pixbuf_scale_simple($pb, $w, $h, 2); // GDK_INTERP_BILINEAR
        }
        if ($g->gdk_pixbuf_get_n_channels($pb) !== 4) {
            $pb = $owned[] = $g->gdk_pixbuf_add_alpha($pb, 0, 0, 0, 0);
        }
        $stride = $g->gdk_pixbuf_get_rowstride($pb);
        $all = \FFI::string($g->gdk_pixbuf_get_pixels($pb), $stride * ($h - 1) + $w * 4);
        $rgba = '';
        for ($y = 0; $y < $h; $y++) { $rgba .= substr($all, $y * $stride, $w * 4); }
        foreach ($owned as $o) { $g->g_object_unref($o); }
        $g->g_object_unref($loader);
        return $rgba;
    }

    private static ?\FFI $pixbuf = null;

    /** GdkPixbuf, an FFI of its own. */
    private static function pixbuf(): \FFI
    {
        return self::$pixbuf ??= \FFI::cdef('
            void* gdk_pixbuf_new_from_data(const unsigned char*, int, int, int, int, int, int, void*, void*);
            int gdk_pixbuf_save_to_bufferv(void*, char**, size_t*, const char*, char**, char**, void**);
            void* gdk_pixbuf_loader_new(void);
            int gdk_pixbuf_loader_write(void*, const char*, size_t, void**);
            int gdk_pixbuf_loader_close(void*, void**);
            void* gdk_pixbuf_loader_get_pixbuf(void*);
            void* gdk_pixbuf_scale_simple(void*, int, int, int);
            void* gdk_pixbuf_add_alpha(void*, int, unsigned char, unsigned char, unsigned char);
            int gdk_pixbuf_get_width(void*);
            int gdk_pixbuf_get_height(void*);
            int gdk_pixbuf_get_rowstride(void*);
            int gdk_pixbuf_get_n_channels(void*);
            unsigned char* gdk_pixbuf_get_pixels(void*);
            void g_object_unref(void*);
            void g_free(void*);
        ', 'libgdk_pixbuf-2.0.so.0');
    }

    /** $s as a C string of $g's, NUL-terminated. */
    private static function cstr(\FFI $g, string $s): \FFI\CData
    {
        $c = $g->new('char[' . (strlen($s) + 1) . ']');
        \FFI::memcpy($c, $s, strlen($s));
        return $c;
    }

    /** FFmpeg's (libswscale's pictures the window's renderer draws), the sound's system (PulseAudio, else ALSA: the last opened), VA-API's. */
    public function names(): array
    {
        return ['video' => 'FFmpeg', 'audio' => Pcm::$opened ?? 'PulseAudio', 'hwdec' => 'vaapi'];
    }

    /** PulseAudio's sinks, else ALSA's devices (Native\Pcm::outputs). */
    public function audioOutputs(): array
    {
        return Pcm::outputs(\MyPHP\App::name());
    }

    public function audioDevice(): Device
    {
        return new SoundDevice($this);
    }

    public function timeout(float $seconds, Closure $fn): int
    {
        return Gtk::timeout($seconds, $fn);
    }

    public function cancel(int $id): void
    {
        Gtk::cancel($id);
    }

    /** PulseAudio's, else ALSA's (Native\Pcm), named as the app. */
    public function sound(int $rate, int $channels, float $ahead, ?string $device): Sound
    {
        return Pcm::open($rate, $channels, $ahead, \MyPHP\App::name(), $device);
    }

    public function windows(): array
    {
        return Window::all();
    }
}
