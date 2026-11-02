<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * The sound output on Linux (mygo's output_linux.go): PulseAudio, which
 * PipeWire serves as well, or else ALSA's default device, of interleaved
 * 32-bit floats. mygo writes to it from a goroutine of its own, through
 * PulseAudio's simple API, where a write waits while the device holds
 * enough; PHP has one thread, so the callers write from a timer only what
 * it takes now (room()), which never waits. PulseAudio's simple API tells
 * no such room (a write waited 80 ms each 10 ms tick), so this is its
 * asynchronous API on a threaded main loop of its own, without a callback
 * (no PHP on that thread): states polled under the loop's lock, the room
 * pa_stream_writable_size's, the stream's latency ADJUST_LATENCY's total
 * of $ahead seconds. ALSA's device is opened non-blocking, its buffer
 * $ahead seconds, room() what it says is free. delay() is what was
 * written and is not heard yet, which the player's clock goes by.
 *
 * @internal Platform\Linux\Renderers, Platform\Linux\AudioOutput
 */
final class Pcm
{
    /** PA_SAMPLE_FLOAT32LE, PA_CONTEXT_NOAUTOSPAWN, PA_CONTEXT_READY, PA_STREAM_READY, PA_STREAM_INTERPOLATE_TIMING | AUTO_TIMING_UPDATE | ADJUST_LATENCY, PA_SEEK_RELATIVE. */
    private const PA_FLOAT32LE = 5, PA_NOAUTOSPAWN = 1, PA_CONTEXT_READY = 4, PA_STREAM_READY = 2, PA_FLAGS = 0x2 | 0x8 | 0x2000, PA_SEEK_RELATIVE = 0;
    /** The states past which a context (FAILED, TERMINATED) or a stream (FAILED, TERMINATED) never gets ready. */
    private const PA_CONTEXT_FAILED = 5, PA_STREAM_FAILED = 3;
    /** Seconds the server is waited for to connect and make the stream. */
    private const PA_WAIT = 2.0;
    /** SND_PCM_STREAM_PLAYBACK, SND_PCM_FORMAT_FLOAT_LE, SND_PCM_ACCESS_RW_INTERLEAVED, SND_PCM_NONBLOCK; -EAGAIN. */
    private const SND_PLAYBACK = 0, SND_FLOAT_LE = 14, SND_INTERLEAVED = 3, SND_NONBLOCK = 1, EAGAIN = -11;

    private static ?FFI $pa = null, $alsa = null;
    /** Why each did not load, once tried. */
    private static ?string $paFailed = null, $alsaFailed = null;

    /** The system the last output opened plays through (the Inspector's current-ao), null before one. */
    public static ?string $opened = null;
    /** The system it plays through: 'PulseAudio' or 'ALSA'. */
    public readonly string $system;
    /** PulseAudio's main loop, context and stream; ALSA's snd_pcm_t*. */
    private ?CData $loop = null, $context = null, $h = null;
    /** The frames it holds at most; the bytes of a frame. */
    private int $ahead, $frame;
    private bool $closed = false;

    private function __construct(string $system, private readonly int $rate, int $channels, int $ahead)
    {
        [$this->system, $this->ahead, $this->frame] = [$system, $ahead, $channels * 4];
        self::$opened = $system;
    }

    /**
     * An output of $channels channels at $rate holding $ahead seconds, named
     * $name for the system's mixer: PulseAudio's, else ALSA's; throws why
     * neither opened. $device, mpv's name of one of outputs() ("pulse/<sink>",
     * "alsa/<pcm>"), opens that one alone; null the default.
     */
    public static function open(int $rate, int $channels, float $ahead, string $name, ?string $device = null): self
    {
        $frames = (int)round($ahead * $rate);
        if ($device !== null) {
            [$driver, $id] = explode('/', $device, 2) + [1 => ''];
            return match ($driver) {
                'pulse' => self::pulse($rate, $channels, $frames, $name, $id),
                'alsa' => self::alsa($rate, $channels, $frames, $id),
                default => throw new RuntimeException("audio: no output \"$device\" (pulse/<sink> or alsa/<device>)"),
            };
        }
        try {
            return self::pulse($rate, $channels, $frames, $name);
        } catch (RuntimeException $pulse) {
            try {
                return self::alsa($rate, $channels, $frames);
            } catch (RuntimeException $alsa) {
                throw new RuntimeException('audio: ' . $pulse->getMessage() . '; ' . $alsa->getMessage());
            }
        }
    }

    /**
     * The outputs there are, by mpv's names of them ("pulse/<sink>",
     * "alsa/<pcm>") with their descriptions: PulseAudio's sinks, else ALSA's
     * PCM devices that play. PulseAudio is asked on a main loop of its own
     * iterated here (its callbacks run on this thread, as PHP must); the
     * player's stream keeps its threaded loop, which never calls PHP.
     * @return array<string, string>
     */
    public static function outputs(string $name): array
    {
        try {
            return self::pulseOutputs($name);
        } catch (RuntimeException $pulse) {
            try {
                return self::alsaOutputs();
            } catch (RuntimeException $alsa) {
                throw new RuntimeException('audio: ' . $pulse->getMessage() . '; ' . $alsa->getMessage());
            }
        }
    }

    /** @return array<string, string> */
    private static function pulseOutputs(string $name): array
    {
        $pa = self::pa() ?? throw new RuntimeException('PulseAudio: ' . self::$paFailed);
        $ml = $pa->pa_mainloop_new();
        $ctx = $pa->pa_context_new($pa->pa_mainloop_get_api($ml), $name);
        $out = [];
        try {
            if ($pa->pa_context_connect($ctx, null, self::PA_NOAUTOSPAWN, null) < 0) {
                throw new RuntimeException('PulseAudio: ' . $pa->pa_strerror($pa->pa_context_errno($ctx)));
            }
            $until = microtime(true) + self::PA_WAIT;
            while (($st = $pa->pa_context_get_state($ctx)) !== self::PA_CONTEXT_READY) {
                if ($st >= self::PA_CONTEXT_FAILED || microtime(true) > $until) {
                    throw new RuntimeException('PulseAudio: connecting: ' . ($st >= self::PA_CONTEXT_FAILED ? $pa->pa_strerror($pa->pa_context_errno($ctx)) : 'no answer in ' . self::PA_WAIT . ' s'));
                }
                $pa->pa_mainloop_iterate($ml, 0, null);
                usleep(1000);
            }
            $cb = function ($c, $info, int $eol, $data) use (&$out): void {
                if ($eol === 0 && $info !== null) { $out['pulse/' . FFI::string($info->name)] = FFI::string($info->description); }
            };
            $op = $pa->pa_context_get_sink_info_list($ctx, $cb, null);
            while ($op !== null && $pa->pa_operation_get_state($op) === 0) { // PA_OPERATION_RUNNING
                $pa->pa_mainloop_iterate($ml, 1, null);
            }
            if ($op !== null) { $pa->pa_operation_unref($op); }
        } finally {
            $pa->pa_context_disconnect($ctx);
            $pa->pa_context_unref($ctx);
            $pa->pa_mainloop_free($ml);
        }
        return $out;
    }

    /** ALSA's PCM devices that play (snd_device_name_hint's, IOID none or Output), by mpv's names. @return array<string, string> */
    private static function alsaOutputs(): array
    {
        $a = self::alsaFfi() ?? throw new RuntimeException('ALSA: ' . self::$alsaFailed);
        $hints = $a->new('void**');
        if (($e = $a->snd_device_name_hint(-1, 'pcm', FFI::addr($hints))) < 0) {
            throw new RuntimeException('ALSA: ' . $a->snd_strerror($e));
        }
        $hint = fn($h, string $id): ?string => ($p = $a->snd_device_name_get_hint($h, $id)) === null ? null : (function () use ($a, $p): string { $s = FFI::string($p); $a->free($p); return $s; })();
        $out = [];
        for ($i = 0; $hints[$i] !== null; $i++) {
            $io = $hint($hints[$i], 'IOID');
            $name = $hint($hints[$i], 'NAME');
            if ($name !== null && ($io === null || $io === 'Output')) {
                $out["alsa/$name"] = str_replace("\n", ' ', $hint($hints[$i], 'DESC') ?? $name);
            }
        }
        $a->snd_device_name_free_hint($hints);
        return $out;
    }

    private static function pa(): ?FFI
    {
        if (self::$pa === null && self::$paFailed === null) {
            try {
                self::$pa = FFI::cdef('
                    typedef struct { int format; uint32_t rate; uint8_t channels; } pa_sample_spec;
                    typedef struct { uint32_t maxlength, tlength, prebuf, minreq, fragsize; } pa_buffer_attr;
                    void* pa_threaded_mainloop_new(void);
                    int pa_threaded_mainloop_start(void*);
                    void pa_threaded_mainloop_stop(void*);
                    void pa_threaded_mainloop_free(void*);
                    void pa_threaded_mainloop_lock(void*);
                    void pa_threaded_mainloop_unlock(void*);
                    void* pa_threaded_mainloop_get_api(void*);
                    void* pa_context_new(void*, const char*);
                    int pa_context_connect(void*, const char*, int, const void*);
                    int pa_context_get_state(void*);
                    int pa_context_errno(void*);
                    void pa_context_disconnect(void*);
                    void pa_context_unref(void*);
                    void* pa_stream_new(void*, const char*, const pa_sample_spec*, const void*);
                    int pa_stream_connect_playback(void*, const char*, const pa_buffer_attr*, int, const void*, void*);
                    int pa_stream_get_state(void*);
                    size_t pa_stream_writable_size(void*);
                    int pa_stream_write(void*, const void*, size_t, void*, int64_t, int);
                    int pa_stream_get_latency(void*, uint64_t*, int*);
                    void* pa_stream_flush(void*, void*, void*);
                    void pa_operation_unref(void*);
                    void pa_stream_disconnect(void*);
                    void pa_stream_unref(void*);
                    const char* pa_strerror(int);
                    void* pa_mainloop_new(void);
                    void* pa_mainloop_get_api(void*);
                    int pa_mainloop_iterate(void*, int, int*);
                    void pa_mainloop_free(void*);
                    typedef struct { const char *name; uint32_t index; const char *description; } pa_sink_info;
                    void* pa_context_get_sink_info_list(void*, void (*)(void*, const pa_sink_info*, int, void*), void*);
                    int pa_operation_get_state(void*);
                ', 'libpulse.so.0');
            } catch (FFI\Exception $e) {
                self::$paFailed = $e->getMessage();
            }
        }
        return self::$pa;
    }

    /** PulseAudio's stream on sink $sink ('' its default). */
    private static function pulse(int $rate, int $channels, int $frames, string $name, string $sink = ''): self
    {
        $pa = self::pa() ?? throw new RuntimeException('PulseAudio: ' . self::$paFailed);
        $out = new self('PulseAudio', $rate, $channels, $frames);
        $out->loop = $pa->pa_threaded_mainloop_new();
        $out->context = $pa->pa_context_new($pa->pa_threaded_mainloop_get_api($out->loop), $name);
        $pa->pa_threaded_mainloop_lock($out->loop);
        try {
            if ($pa->pa_context_connect($out->context, null, self::PA_NOAUTOSPAWN, null) < 0 || $pa->pa_threaded_mainloop_start($out->loop) < 0) {
                throw new RuntimeException('PulseAudio: ' . $pa->pa_strerror($pa->pa_context_errno($out->context)));
            }
            $out->await(fn(): int => $pa->pa_context_get_state($out->context), self::PA_CONTEXT_READY, self::PA_CONTEXT_FAILED, 'connecting');
            $spec = $pa->new('pa_sample_spec');
            [$spec->format, $spec->rate, $spec->channels] = [self::PA_FLOAT32LE, $rate, $channels];
            $frame = $channels * 4;
            $attr = $pa->new('pa_buffer_attr');
            // $frames ahead in all, asked for more every 10 ms (mygo's); it starts once 10 ms are written, not once it holds all (a short end of a film after a seek would never start).
            [$attr->maxlength, $attr->tlength, $attr->prebuf, $attr->minreq, $attr->fragsize] = [0xFFFFFFFF, $frames * $frame, intdiv($rate, 100) * $frame, intdiv($rate, 100) * $frame, 0xFFFFFFFF];
            $out->h = $pa->pa_stream_new($out->context, $name, FFI::addr($spec), null);
            if ($out->h === null || $pa->pa_stream_connect_playback($out->h, $sink === '' ? null : $sink, FFI::addr($attr), self::PA_FLAGS, null, null) < 0) {
                throw new RuntimeException('PulseAudio: ' . $pa->pa_strerror($pa->pa_context_errno($out->context)));
            }
            $out->await(fn(): int => $pa->pa_stream_get_state($out->h), self::PA_STREAM_READY, self::PA_STREAM_FAILED, 'opening the stream');
        } catch (RuntimeException $e) {
            $pa->pa_threaded_mainloop_unlock($out->loop);
            $out->close();
            throw $e;
        }
        $pa->pa_threaded_mainloop_unlock($out->loop);
        return $out;
    }

    /** Waits, the loop's lock held but while it sleeps, for $state() to be $ready; throws once it is $failed or past it, or after PA_WAIT. */
    private function await(\Closure $state, int $ready, int $failed, string $doing): void
    {
        $pa = self::$pa;
        $until = microtime(true) + self::PA_WAIT;
        while (($st = $state()) !== $ready) {
            if ($st >= $failed || microtime(true) > $until) {
                throw new RuntimeException("PulseAudio: $doing: " . ($st >= $failed ? $pa->pa_strerror($pa->pa_context_errno($this->context)) : 'no answer in ' . self::PA_WAIT . ' s'));
            }
            $pa->pa_threaded_mainloop_unlock($this->loop);
            usleep(1000);
            $pa->pa_threaded_mainloop_lock($this->loop);
        }
    }

    /** ALSA's PCM $device ('' its default). */
    private static function alsa(int $rate, int $channels, int $frames, string $device = ''): self
    {
        $a = self::alsaFfi() ?? throw new RuntimeException('ALSA: ' . self::$alsaFailed);
        $pcm = $a->new('void*');
        if (($e = $a->snd_pcm_open(FFI::addr($pcm), $device === '' ? 'default' : $device, self::SND_PLAYBACK, self::SND_NONBLOCK)) < 0) {
            throw new RuntimeException('ALSA: ' . $a->snd_strerror($e));
        }
        if (($e = $a->snd_pcm_set_params($pcm, self::SND_FLOAT_LE, self::SND_INTERLEAVED, $channels, $rate, 1, (int)round($frames * 1e6 / $rate))) < 0) {
            $a->snd_pcm_close($pcm);
            throw new RuntimeException('ALSA: ' . $a->snd_strerror($e));
        }
        $out = new self('ALSA', $rate, $channels, $frames);
        $out->h = $pcm;
        return $out;
    }

    private static function alsaFfi(): ?FFI
    {
        if (self::$alsa === null && self::$alsaFailed === null) {
            try {
                self::$alsa = FFI::cdef('
                    int snd_pcm_open(void**, const char*, int, int);
                    int snd_pcm_set_params(void*, int, int, unsigned int, unsigned int, int, unsigned int);
                    long snd_pcm_writei(void*, const void*, unsigned long);
                    long snd_pcm_avail_update(void*);
                    int snd_pcm_delay(void*, long*);
                    int snd_pcm_drop(void*);
                    int snd_pcm_prepare(void*);
                    int snd_pcm_recover(void*, int, int);
                    int snd_pcm_close(void*);
                    const char* snd_strerror(int);
                    int snd_device_name_hint(int, const char*, void***);
                    char* snd_device_name_get_hint(const void*, const char*);
                    int snd_device_name_free_hint(void**);
                    void free(void*);
                ', 'libasound.so.2');
            } catch (FFI\Exception $e) {
                self::$alsaFailed = $e->getMessage();
            }
        }
        return self::$alsa;
    }

    /** The frames it takes now without waiting. */
    public function room(): int
    {
        if ($this->system === 'PulseAudio') {
            $pa = self::$pa;
            $pa->pa_threaded_mainloop_lock($this->loop);
            $n = $pa->pa_stream_writable_size($this->h);
            $pa->pa_threaded_mainloop_unlock($this->loop);
            return $n === PHP_INT_MAX || $n < 0 ? 0 : intdiv($n, $this->frame); // (size_t)-1: the stream failed, which write() tells
        }
        $n = self::$alsa->snd_pcm_avail_update($this->h);
        if ($n < 0) { // an underrun: prepared again, all of it free
            $this->recover((int)$n);
            return $this->ahead;
        }
        return min((int)$n, $this->ahead);
    }

    /** Plays $pcm (whole frames) after what it was written, at most room() of them; throws as the device failed. */
    public function write(string $pcm): void
    {
        if ($this->system === 'PulseAudio') {
            $pa = self::$pa;
            $pa->pa_threaded_mainloop_lock($this->loop);
            $st = $pa->pa_stream_write($this->h, $pcm, strlen($pcm), null, 0, self::PA_SEEK_RELATIVE); // copied
            $pa->pa_threaded_mainloop_unlock($this->loop);
            if ($st < 0) {
                throw new RuntimeException('audio: PulseAudio: ' . $pa->pa_strerror($pa->pa_context_errno($this->context)));
            }
            return;
        }
        $a = self::$alsa;
        $frames = intdiv(strlen($pcm), $this->frame);
        while ($frames > 0) {
            $n = $a->snd_pcm_writei($this->h, $pcm, $frames);
            if ($n === self::EAGAIN) {
                return; // full after all: what was not taken is dropped, as late
            }
            if ($n < 0) {
                $this->recover((int)$n);
                continue;
            }
            $pcm = substr($pcm, $n * $this->frame);
            $frames -= $n;
        }
    }

    /** The frames written not heard yet; null while the device has not told (PulseAudio's first timing, a stream just opened or flushed). */
    public function delay(): ?int
    {
        if ($this->system === 'PulseAudio') {
            $pa = self::$pa;
            $us = $pa->new('uint64_t'); $negative = $pa->new('int');
            $pa->pa_threaded_mainloop_lock($this->loop);
            $st = $pa->pa_stream_get_latency($this->h, FFI::addr($us), FFI::addr($negative));
            $pa->pa_threaded_mainloop_unlock($this->loop);
            if ($st < 0) { // no timing yet (PA_ERR_NODATA)
                return null;
            }
            return $negative->cdata !== 0 ? 0 : (int)round($us->cdata * $this->rate / 1e6);
        }
        $d = self::$alsa->new('long');
        return self::$alsa->snd_pcm_delay($this->h, FFI::addr($d)) < 0 ? 0 : max(0, (int)$d->cdata);
    }

    /** Drops what it holds, unheard. */
    public function flush(): void
    {
        if ($this->system === 'PulseAudio') {
            $pa = self::$pa;
            $pa->pa_threaded_mainloop_lock($this->loop);
            $op = $pa->pa_stream_flush($this->h, null, null);
            if ($op !== null) { $pa->pa_operation_unref($op); }
            $pa->pa_threaded_mainloop_unlock($this->loop);
            return;
        }
        self::$alsa->snd_pcm_drop($this->h);
        self::$alsa->snd_pcm_prepare($this->h);
    }

    /** ALSA's error $e (an underrun, a suspend) recovered from by preparing the device again (mygo's); throws one it does not recover from. */
    private function recover(int $e): void
    {
        if (($r = self::$alsa->snd_pcm_recover($this->h, $e, 1)) < 0) {
            throw new RuntimeException('audio: ALSA: ' . self::$alsa->snd_strerror($r));
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->system === 'ALSA') {
            self::$alsa->snd_pcm_close($this->h);
            return;
        }
        $pa = self::$pa;
        $pa->pa_threaded_mainloop_stop($this->loop); // its thread joined: no lock needed after
        if ($this->h !== null) {
            $pa->pa_stream_disconnect($this->h);
            $pa->pa_stream_unref($this->h);
        }
        $pa->pa_context_disconnect($this->context);
        $pa->pa_context_unref($this->context);
        $pa->pa_threaded_mainloop_free($this->loop);
    }

    public function __destruct()
    {
        $this->close();
    }
}
