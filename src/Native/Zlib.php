<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * The system's zlib through FFI (/usr/lib/libz.1.dylib), as PHP's zlib
 * extension is not in every PHP that runs an app: a stream that deflates
 * or inflates gzip (Go's compress/gzip) or raw DEFLATE (compress/flate)
 * chunk by chunk.
 *
 *     $z = Zlib::inflater(Zlib::GZIP);
 *     foreach ($chunks as $c) { $out .= $z->update($c); }
 *     $out .= $z->finish();
 *
 * @internal
 */
final class Zlib
{
    /** Window bits: a gzip stream, or raw DEFLATE. */
    public const GZIP = 31, RAW = -15;
    private const Z_OK = 0, Z_STREAM_END = 1, Z_BUF_ERROR = -5, Z_NO_FLUSH = 0, Z_FINISH = 4;
    private const CHUNK = 1 << 16;

    private static ?FFI $c = null;
    private CData $s;
    private CData $out;
    private ?CData $in = null;
    private bool $ended = false;

    private function __construct(private readonly bool $deflate, int $window, int $level)
    {
        $c = self::c();
        $this->s = $c->new('z_stream');
        $this->out = $c->new('unsigned char[' . self::CHUNK . ']');
        $size = FFI::sizeof($this->s);
        $rc = $deflate
            ? $c->deflateInit2_(FFI::addr($this->s), $level, 8, $window, 9, 0, $c->zlibVersion(), $size)
            : $c->inflateInit2_(FFI::addr($this->s), $window, $c->zlibVersion(), $size);
        if ($rc !== self::Z_OK) {
            throw new RuntimeException("zlib: init failed ($rc)");
        }
    }

    public function __destruct()
    {
        $this->deflate ? self::c()->deflateEnd(FFI::addr($this->s)) : self::c()->inflateEnd(FFI::addr($this->s));
    }

    /** A stream that compresses, at $level 9 (Go's BestCompression) unless told. */
    public static function deflater(int $window, int $level = 9): self
    {
        return new self(true, $window, $level);
    }

    public static function inflater(int $window): self
    {
        return new self(false, $window, 0);
    }

    /** Compresses $data at once. */
    public static function deflate(string $data, int $window, int $level = 9): string
    {
        $z = self::deflater($window, $level);
        return $z->update($data) . $z->finish();
    }

    /** Decompresses $data at once; it must be a whole stream. */
    public static function inflate(string $data, int $window): string
    {
        $z = self::inflater($window);
        $out = $z->update($data);
        return $out . $z->finish();
    }

    /** What $data makes so far. */
    public function update(string $data): string
    {
        return $this->run($data, self::Z_NO_FLUSH);
    }

    /** The rest; an inflater throws when the stream did not end. */
    public function finish(): string
    {
        $out = $this->run('', self::Z_FINISH);
        if (!$this->ended) {
            throw new RuntimeException($this->deflate ? 'zlib: deflate did not end' : 'unexpected EOF');
        }
        return $out;
    }

    /** Whether an inflater reached the end of its stream. */
    public function ended(): bool
    {
        return $this->ended;
    }

    private function run(string $data, int $flush): string
    {
        $c = self::c();
        $n = strlen($data);
        if ($n > 0) {
            $this->in = $c->new("unsigned char[$n]", false);
            FFI::memcpy($this->in, $data, $n);
            $this->s->next_in = $c->cast('unsigned char *', $this->in);
            $this->s->avail_in = $n;
        }
        $out = '';
        try {
            while (true) {
                if ($this->ended) {
                    if ($this->s->avail_in > 0) {
                        throw new RuntimeException('zlib: data after the end of the stream');
                    }
                    return $out;
                }
                $this->s->next_out = $c->cast('unsigned char *', $this->out);
                $this->s->avail_out = self::CHUNK;
                $rc = $this->deflate ? $c->deflate(FFI::addr($this->s), $flush) : $c->inflate(FFI::addr($this->s), $flush);
                $made = self::CHUNK - $this->s->avail_out;
                if ($made > 0) {
                    $out .= FFI::string($this->out, $made);
                }
                if ($rc === self::Z_STREAM_END) {
                    $this->ended = true;
                    continue;
                }
                if ($rc === self::Z_BUF_ERROR) {
                    return $out; // no progress without more input
                }
                if ($rc !== self::Z_OK) {
                    $msg = FFI::isNull($this->s->msg) ? "error $rc" : FFI::string($this->s->msg);
                    throw new RuntimeException("zlib: $msg");
                }
                if ($this->s->avail_out > 0 && $flush !== self::Z_FINISH) {
                    return $out; // took all the input
                }
            }
        } finally {
            if ($this->s->avail_in === 0 && $this->in !== null) {
                FFI::free($this->in);
                $this->in = null;
            }
        }
    }

    private static function c(): FFI
    {
        return self::$c ??= FFI::cdef('
            typedef struct {
                unsigned char *next_in; unsigned int avail_in; unsigned long total_in;
                unsigned char *next_out; unsigned int avail_out; unsigned long total_out;
                char *msg; void *state;
                void *zalloc; void *zfree; void *opaque;
                int data_type; unsigned long adler; unsigned long reserved;
            } z_stream;
            const char *zlibVersion(void);
            int deflateInit2_(z_stream *s, int level, int method, int windowBits, int memLevel, int strategy, const char *version, int size);
            int deflate(z_stream *s, int flush);
            int deflateEnd(z_stream *s);
            int inflateInit2_(z_stream *s, int windowBits, const char *version, int size);
            int inflate(z_stream *s, int flush);
            int inflateEnd(z_stream *s);
        ', PHP_OS_FAMILY === 'Darwin' ? '/usr/lib/libz.1.dylib' : 'libz.so.1');
    }
}
