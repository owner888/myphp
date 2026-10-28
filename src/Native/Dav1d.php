<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * AV1 decoded in software by libdav1d through FFI, as mpv decodes it where
 * the system has no AV1 decoder (FFmpeg's libdav1d.c): packets sent, the
 * pictures got as dav1d's threads decode them (each carrying the time it
 * was sent with), drained at the end. The library is the app's own
 * (Contents/Resources, where bin/myphp build puts it beside the plugins'
 * libraries), else Homebrew's (libdav1d.so.7 off macOS, for the tests).
 *
 * @internal Native\Renderers, Native\VideoDecoder
 */
final class Dav1d
{
    private const CDEF = <<<'C'
        typedef struct Dav1dContext Dav1dContext;
        typedef struct Dav1dRef Dav1dRef;
        typedef struct { const uint8_t *data; Dav1dRef *ref; } Dav1dUserData;
        typedef struct { int64_t timestamp; int64_t duration; int64_t offset; size_t size; Dav1dUserData user_data; } Dav1dDataProps;
        typedef struct { const uint8_t *data; size_t sz; Dav1dRef *ref; Dav1dDataProps m; } Dav1dData;
        typedef struct { int w; int h; int layout; int bpc; } Dav1dPictureParameters;
        typedef struct { uint8_t profile; int max_width, max_height; int layout, pri, trc, mtrx, chr; uint8_t hbd; uint8_t color_range; } Dav1dSequenceHeaderHead;
        typedef struct {
            Dav1dSequenceHeaderHead *seq_hdr; void *frame_hdr;
            void *data[3]; ptrdiff_t stride[2];
            Dav1dPictureParameters p; Dav1dDataProps m;
            void *content_light; void *mastering_display; void *itut_t35; size_t n_itut_t35;
            uintptr_t reserved[4];
            Dav1dRef *frame_hdr_ref, *seq_hdr_ref, *content_light_ref, *mastering_display_ref, *itut_t35_ref;
            uintptr_t reserved_ref[4];
            Dav1dRef *ref; void *allocator_data;
        } Dav1dPicture;
        typedef struct { void *cookie; void *alloc; void *release; } Dav1dPicAllocator;
        typedef struct { void *cookie; void *callback; } Dav1dLogger;
        typedef struct {
            int n_threads; int max_frame_delay; int apply_grain; int operating_point; int all_layers; unsigned frame_size_limit;
            Dav1dPicAllocator allocator; Dav1dLogger logger;
            int strict_std_compliance; int output_invisible_frames; int inloop_filters; int decode_frame_type;
            uint8_t reserved[16];
        } Dav1dSettings;
        const char *dav1d_version(void);
        void dav1d_default_settings(Dav1dSettings *s);
        int dav1d_open(Dav1dContext **c_out, const Dav1dSettings *s);
        int dav1d_send_data(Dav1dContext *c, Dav1dData *in);
        int dav1d_get_picture(Dav1dContext *c, Dav1dPicture *out);
        void dav1d_close(Dav1dContext **c_out);
        void dav1d_flush(Dav1dContext *c);
        uint8_t *dav1d_data_create(Dav1dData *data, size_t sz);
        void dav1d_data_unref(Dav1dData *data);
        void dav1d_picture_unref(Dav1dPicture *p);
        C;
    /** DAV1D_ERR(EAGAIN): EAGAIN negated, of the system's errno. */
    private const AGAIN = PHP_OS_FAMILY === 'Darwin' ? -35 : -11;

    private static ?FFI $ffi = null;
    private static ?string $error = null;
    private CData $ctx;
    /** The packet sent that dav1d did not take yet (EAGAIN): sent again once pictures were taken. */
    private ?CData $pending = null;

    /** The library, loaded at the first; null when there is none (why: unavailable()). */
    public static function ffi(): ?FFI
    {
        if (self::$ffi === null && self::$error === null) {
            $tried = [];
            foreach (self::candidates() as $lib) {
                try {
                    self::$ffi = FFI::cdef(self::CDEF, $lib);
                    break;
                } catch (\FFI\Exception $e) {
                    $tried[] = $lib;
                }
            }
            if (self::$ffi === null) {
                self::$error = 'myphp: no libdav1d to decode AV1 in software (looked for ' . implode(', ', $tried) . '; brew install dav1d)';
            }
        }
        return self::$ffi;
    }

    /** Why there is no libdav1d, or null when there is. */
    public static function unavailable(): ?string
    {
        self::ffi();
        return self::$error;
    }

    /** Where the library is looked for: the app's resources, Homebrew's. @return list<string> */
    private static function candidates(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return ['libdav1d.so.7'];
        }
        $app = Bundle::path();
        return [...($app === null ? [] : ["$app/Contents/Resources/libdav1d.7.dylib"]), '/opt/homebrew/lib/libdav1d.7.dylib', '/usr/local/lib/libdav1d.7.dylib'];
    }

    /** A decoder: $threads of dav1d's own (0: as many as the cores), $delay frames it may hold (0: as many as its threads want; 1: one in, one out, a thumbnail's). */
    public function __construct(int $threads = 0, int $delay = 0)
    {
        $f = self::ffi() ?? throw new RuntimeException((string)self::$error);
        $s = $f->new('Dav1dSettings');
        $f->dav1d_default_settings(FFI::addr($s));
        $s->n_threads = $threads;
        $s->max_frame_delay = $delay;
        $ctx = $f->new('Dav1dContext*');
        if (($st = $f->dav1d_open(FFI::addr($ctx), FFI::addr($s))) < 0) {
            throw new RuntimeException("myphp: dav1d_open: error $st");
        }
        $this->ctx = $ctx;
    }

    /**
     * Sends packet $data shown at $time (microseconds, what its picture
     * carries back); false when dav1d holds as much as it takes, the packet
     * kept and sent first the next time (take pictures meanwhile).
     */
    public function send(string $data, int $time): bool
    {
        $f = self::$ffi;
        if ($this->pending !== null && !$this->flushPending()) {
            return false;
        }
        $d = $f->new('Dav1dData');
        $p = $f->dav1d_data_create(FFI::addr($d), strlen($data));
        if (FFI::isNull($p)) {
            throw new RuntimeException('myphp: dav1d_data_create');
        }
        FFI::memcpy($p, $data, strlen($data));
        $d->m->timestamp = $time;
        $this->pending = $d;
        $this->flushPending();
        return true;
    }

    /** Sends the packet kept; whether dav1d took it. */
    private function flushPending(): bool
    {
        $st = self::$ffi->dav1d_send_data($this->ctx, FFI::addr($this->pending));
        if ($st === self::AGAIN) {
            return false;
        }
        if ($st < 0) {
            self::$ffi->dav1d_data_unref(FFI::addr($this->pending));
            $this->pending = null;
            throw new RuntimeException("myphp: dav1d_send_data: error $st");
        }
        if ($this->pending->sz === 0) { $this->pending = null; } // all of it taken
        return $this->pending === null;
    }

    /** Whether a packet waits to be sent (dav1d was full). */
    public function full(): bool
    {
        return $this->pending !== null;
    }

    /** The next picture decoded, owned (release() it); null while none is (or, draining, none is left). */
    public function picture(): ?CData
    {
        $f = self::$ffi;
        $pic = $f->new('Dav1dPicture');
        $st = $f->dav1d_get_picture($this->ctx, FFI::addr($pic));
        if ($st === self::AGAIN) {
            // None yet: the packet kept sent again, as FFmpeg's libdav1d.c sends before each get. That also keeps the next
            // get from draining (dav1d_get_picture asked twice with nothing sent between drains, which mid-stream, the
            // packet still queued, waits on its frame threads for ever); each get goes on with the packet dav1d queued.
            if ($this->pending !== null) { $this->flushPending(); }
            return null;
        }
        if ($st < 0) {
            throw new RuntimeException("myphp: dav1d_get_picture: error $st");
        }
        if ($this->pending !== null) { $this->flushPending(); } // room for the packet kept, now
        return $pic;
    }

    /** Lets picture $pic go. */
    public static function release(CData $pic): void
    {
        self::$ffi->dav1d_picture_unref(FFI::addr($pic));
    }

    /** Drops what it holds, for a seek. */
    public function flush(): void
    {
        if ($this->pending !== null) {
            self::$ffi->dav1d_data_unref(FFI::addr($this->pending));
            $this->pending = null;
        }
        self::$ffi->dav1d_flush($this->ctx);
    }

    public function __destruct()
    {
        $this->flush();
        self::$ffi->dav1d_close(FFI::addr($this->ctx));
    }
}
