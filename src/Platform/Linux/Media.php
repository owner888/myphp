<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use MyPHP\Audio\Device;
use MyPHP\Media\{Player, Track};
use MyPHP\Native\{Av, AvReader, MediaTicker};
use MyPHP\Platform;

/**
 * The player's system on Linux: FFmpeg (libavformat reads what the core
 * does not, libavcodec decodes, libswscale and libswresample convert), the
 * sound through PulseAudio or ALSA, the picture drawn by the window's own
 * renderer (Linux\Renderers). Every track decodes in software:
 * hardware decoding (VA-API) is not here.
 */
final class Media implements Platform\Media
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
        return new Renderers($hardware);
    }

    public function thumbnails(Track $video, int $width, bool $hardware): Platform\Thumbnails
    {
        return new Thumbnails($video, $width);
    }

    public function hardwareDecodes(Track $video, bool $hardware): bool
    {
        return false;
    }

    public function decodingSwitchable(Track $video): bool
    {
        return false;
    }

    /** FFmpeg's decoder of the track, by its name ("FFmpeg h264", "FFmpeg libdav1d"). */
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

    /** Not on Linux yet (MPRIS through Linux\NowPlaying takes the app's, MyPHP\NowPlaying's): told once. */
    public function showNowPlaying(object $owner, array $handlers, string $path, float $duration, float $time, float $rate, bool $paused, int $index, int $count, int $chapter, int $chapters): void
    {
        if (!$this->nowPlayingTold) {
            $this->nowPlayingTold = true;
            \MyPHP\Logger::warn("the player's film is not shown in the system's media controls (MPRIS) on Linux yet", 'media');
        }
    }

    private bool $nowPlayingTold = false;

    public function endNowPlaying(object $owner): void {}

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

    public function audioDevice(): Device
    {
        return new AudioOutput();
    }
}
