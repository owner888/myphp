<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use MyPHP\App;
use MyPHP\Audio\{Device, Mixer, Output};

/**
 * The system's audio output (mygo's output_darwin.go): an Audio Queue on
 * the default output, which follows it as it changes, of 48 kHz stereo
 * 32-bit floats. mygo's queue calls back on a thread of its own; this one
 * calls back on the main thread's run loop, in its common modes, so that
 * PHP mixes there, Output::FRAMES at a time, Output::BUFFERS ahead.
 * @internal
 */
final class AudioQueue implements Device
{
    private const LINEAR_PCM = 0x6C70636D, FLOAT = 1; // 'lpcm', kAudioFormatFlagIsFloat
    private static ?FFI $c = null;
    /** @var ?\Closure the callback, kept alive while queues use it */
    private static ?\Closure $callback = null;
    /** @var array<int, AudioQueue> the queues open, by their address */
    private static array $open = [];

    private $q;
    private bool $closed = false, $started = false;
    private float $last = 0.0;

    private static function c(): FFI
    {
        return self::$c ??= FFI::cdef('
            typedef struct { double mSampleRate; uint32_t mFormatID, mFormatFlags, mBytesPerPacket, mFramesPerPacket, mBytesPerFrame, mChannelsPerFrame, mBitsPerChannel, mReserved; } AudioStreamBasicDescription;
            typedef struct { uint32_t mAudioDataBytesCapacity; void* mAudioData; uint32_t mAudioDataByteSize; void* mUserData; uint32_t mPacketDescriptionCapacity; void* mPacketDescriptions; uint32_t mPacketDescriptionCount; } AudioQueueBuffer;
            typedef void (*AudioQueueOutputCallback)(void*, void*, AudioQueueBuffer*);
            int32_t AudioQueueNewOutput(const AudioStreamBasicDescription*, AudioQueueOutputCallback, void*, void*, void*, uint32_t, void**);
            int32_t AudioQueueAllocateBuffer(void*, uint32_t, AudioQueueBuffer**);
            int32_t AudioQueueEnqueueBuffer(void*, AudioQueueBuffer*, uint32_t, void*);
            int32_t AudioQueueStart(void*, void*);
            int32_t AudioQueueStop(void*, unsigned char);
            int32_t AudioQueueDispose(void*, unsigned char);
            void* CFRunLoopGetMain(void);
            void* kCFRunLoopCommonModes;
        ', '/System/Library/Frameworks/AudioToolbox.framework/AudioToolbox');
    }

    public function __construct()
    {
        $c = self::c();
        self::$callback ??= function ($user, $q, $buf): void {
            $self = $q === null ? null : (self::$open[self::c()->cast('intptr_t', $q)->cdata] ?? null); // a queue being disposed calls back no more
            if ($self === null || $self->closed) { return; }
            try {
                $self->fill($buf);
            } catch (\Throwable $e) {
                error_log('audio: ' . $e->getMessage());
            }
        };
        $desc = $c->new('AudioStreamBasicDescription');
        $desc->mSampleRate = Mixer::RATE; $desc->mFormatID = self::LINEAR_PCM; $desc->mFormatFlags = self::FLOAT;
        $desc->mBytesPerPacket = $desc->mBytesPerFrame = Mixer::CHANNELS * 4; $desc->mFramesPerPacket = 1;
        $desc->mChannelsPerFrame = Mixer::CHANNELS; $desc->mBitsPerChannel = 32;
        $q = $c->new('void*');
        if (($st = $c->AudioQueueNewOutput(FFI::addr($desc), self::$callback, null, $c->CFRunLoopGetMain(), $c->kCFRunLoopCommonModes, 0, FFI::addr($q))) !== 0) {
            throw new \RuntimeException("audio: AudioQueueNewOutput failed (OSStatus $st)");
        }
        $this->q = $q;
        self::$open[$c->cast('intptr_t', $q)->cdata] = $this;
    }

    public function start(): void
    {
        $c = self::c();
        for ($i = 0; $i < Output::BUFFERS; $i++) { // start once every buffer holds something, not to begin with an underrun
            $b = $c->new('AudioQueueBuffer*');
            if (($st = $c->AudioQueueAllocateBuffer($this->q, Output::FRAMES * Mixer::CHANNELS * 4, FFI::addr($b))) !== 0) {
                $this->close();
                throw new \RuntimeException("audio: AudioQueueAllocateBuffer failed (OSStatus $st)");
            }
            $this->fill($b);
        }
        if (($st = $c->AudioQueueStart($this->q, null)) !== 0) {
            $this->close();
            throw new \RuntimeException("audio: AudioQueueStart failed (OSStatus $st)");
        }
        $this->started = true;
        $this->watch();
    }

    /** Mixes into buffer $b and queues it. */
    private function fill($b): void
    {
        $this->last = microtime(true);
        $s = Output::render(Output::FRAMES);
        FFI::memcpy($b->mAudioData, $s, strlen($s));
        $b->mAudioDataByteSize = strlen($s);
        if (($st = self::c()->AudioQueueEnqueueBuffer($this->q, $b, 0, null)) !== 0) {
            error_log("audio: AudioQueueEnqueueBuffer failed (OSStatus $st)");
            App::later(fn() => Output::lost($this));
        }
    }

    /** A queue that stopped taking buffers for a second, as when its device went, is opened again. */
    private function watch(): void
    {
        App::after(1.0, function (): void {
            if ($this->closed) { return; }
            if (microtime(true) - $this->last > 1.0) {
                Output::lost($this);
                return;
            }
            $this->watch();
        });
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $c = self::c();
        unset(self::$open[$c->cast('intptr_t', $this->q)->cdata]);
        $c->AudioQueueStop($this->q, 1);
        $c->AudioQueueDispose($this->q, 1);
    }
}
