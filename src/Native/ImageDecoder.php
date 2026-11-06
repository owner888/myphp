<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use Fiber;
use RuntimeException;

/**
 * Decodes PNG, JPEG, WebP, HEIF and whatever else Android decodes into
 * RGBA pixels (premultiplied, top row first), upright as the file's EXIF
 * orientation says, scaled down to fit $max pixels on a side when larger:
 * Android's AImageDecoder, as CoreImage decodes on macOS and iOS. A thread
 * of the launcher's (image_decoder.c) decodes, no PHP running there; in a
 * fiber (App::go) the decoding waits letting the app run; outside one it
 * blocks until done.
 */
final class ImageDecoder
{
    private static ?FFI $f = null;

    /** AImageDecoder's results by their codes (android/imagedecoder.h), for the error. */
    private const ERRORS = [-1 => 'incomplete', -2 => 'erroneous', -3 => 'out of memory', -4 => 'bad parameter', -5 => 'invalid conversion',
        -6 => 'invalid scale', -7 => 'seek error', -8 => 'internal error', -9 => 'invalid input', -10 => 'unsupported format', -100 => 'out of memory'];

    private static function ffi(): FFI
    {
        return self::$f ??= FFI::cdef('
            typedef struct { int state; int w, h, error; void *pix; void *bytes; size_t len; int max; } myphp_image_job;
            myphp_image_job *myphp_image_decode_start(const void *bytes, size_t len, int max);
            int myphp_image_decode_state(myphp_image_job *job);
            void myphp_image_decode_free(myphp_image_job *job);
        ', 'libmyphp.so');
    }

    /**
     * @return array{w: int, h: int, pix: \FFI\CData, addr: int} w*h*4 bytes, malloc'ed (free with Mem::free)
     */
    public static function decode(string $bytes, int $max): array
    {
        $f = self::ffi();
        $job = $f->myphp_image_decode_start($bytes, strlen($bytes), $max);
        if ($job === null) {
            throw new RuntimeException('myphp: no thread or memory to decode an image');
        }
        $done = fn(): bool => $f->myphp_image_decode_state($job) !== 0;
        if (Fiber::getCurrent() !== null) {
            Fibers::until($done, 0.004, 0.016);
        } else {
            while (!$done()) { usleep(1000); }
        }
        $state = $f->myphp_image_decode_state($job);
        [$w, $h, $error, $addr] = [$job->w, $job->h, $job->error, $state === 1 ? Mem::addr($job->pix) : 0];
        $f->myphp_image_decode_free($job);
        if ($state !== 1) {
            throw new RuntimeException('not an image Android can decode: ' . (self::ERRORS[$error] ?? "error $error"));
        }
        return ['w' => $w, 'h' => $h, 'pix' => Mem::pointer($addr), 'addr' => $addr];
    }
}
