<?php
declare(strict_types=1);

namespace MyPHP\Audio;

use FFI;

/**
 * Decodes what the system's decoders read (AudioToolbox's ExtAudioFile,
 * over the stream through AudioFile's callbacks): MP3, AAC and ALAC in
 * M4A, FLAC, AIFF and CAF, as 32-bit floats of the file's channels at its
 * rate. mygo decodes MP3 with go-mp3 (package audio/mp3); MyPHP takes the
 * system's, which leaves out the encoder's delay and padding.
 */
final class SystemDecoder implements Decoder
{
    /** The formats it reads: name, the start of their files ('?' any byte), the type AudioFile is told. */
    public const FORMATS = [
        ['mp3', 'ID3'], ['mp3', "\xff\xfb"], ['mp3', "\xff\xfa"], ['mp3', "\xff\xf3"], ['mp3', "\xff\xf2"], ['mp3', "\xff\xe3"], ['mp3', "\xff\xe2"], // an ID3 tag, or the sync word of a frame of MPEG-1 or 2 layer III
        ['m4a', '????ftyp'], ['flac', 'fLaC'], ['aiff', 'FORM????AIFF'], ['aiff', 'FORM????AIFC'], ['caf', 'caff'],
    ];
    private const TYPES = ['mp3' => 0x4D504733, 'm4a' => 0x6D346166, 'flac' => 0x666C6163, 'aiff' => 0, 'caf' => 0x63616666]; // 'MPG3', 'm4af', 'flac', AIFF and AIFC told apart by the file, 'caff'
    private const FILE_FORMAT = 0x66666d74, CLIENT_FORMAT = 0x63666d74, LENGTH = 0x2366726d; // 'ffmt', 'cfmt', '#frm'
    private const LINEAR_PCM = 0x6C70636D, FLOAT_PACKED = 1 | 8; // kAudioFormatFlagIsFloat | IsPacked

    private static ?FFI $c = null;
    private $file, $ext;
    private int $rate, $channels, $length;
    private int $start;
    /** The callbacks AudioFile reads the stream through, kept alive with the decoder. */
    private \Closure $readProc, $sizeProc;
    private ?FFI\CData $buf = null;
    private int $bufFrames = 0;
    private bool $closed = false;

    private static function c(): FFI
    {
        return self::$c ??= FFI::cdef('
            typedef struct { double mSampleRate; uint32_t mFormatID, mFormatFlags, mBytesPerPacket, mFramesPerPacket, mBytesPerFrame, mChannelsPerFrame, mBitsPerChannel, mReserved; } AudioStreamBasicDescription;
            typedef struct { uint32_t mNumberChannels; uint32_t mDataByteSize; void* mData; } AudioBuffer;
            typedef struct { uint32_t mNumberBuffers; AudioBuffer mBuffers[1]; } AudioBufferList;
            typedef int32_t (*AudioFile_ReadProc)(void*, int64_t, uint32_t, void*, uint32_t*);
            typedef int64_t (*AudioFile_GetSizeProc)(void*);
            int32_t AudioFileOpenWithCallbacks(void*, AudioFile_ReadProc, void*, AudioFile_GetSizeProc, void*, uint32_t, void**);
            int32_t AudioFileClose(void*);
            int32_t ExtAudioFileWrapAudioFileID(void*, unsigned char, void**);
            int32_t ExtAudioFileGetProperty(void*, uint32_t, uint32_t*, void*);
            int32_t ExtAudioFileSetProperty(void*, uint32_t, uint32_t, const void*);
            int32_t ExtAudioFileRead(void*, uint32_t*, AudioBufferList*);
            int32_t ExtAudioFileSeek(void*, int64_t);
            int32_t ExtAudioFileDispose(void*);
        ', '/System/Library/Frameworks/AudioToolbox.framework/AudioToolbox');
    }

    /** @param resource $r a seekable stream at the start of the file */
    public function __construct(private $r, string $format)
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new \RuntimeException("audio: $format decodes with the system's decoders, on macOS");
        }
        $c = self::c();
        $this->start = (int)ftell($r);
        $size = (int)(fstat($r)['size'] ?? 0) - $this->start;
        $this->readProc = function ($client, $pos, $count, $buf, $actual): int {
            fseek($this->r, $this->start + (int)$pos);
            $data = $count > 0 ? (string)fread($this->r, (int)$count) : '';
            if ($data !== '') { FFI::memcpy($buf, $data, strlen($data)); }
            $actual[0] = strlen($data);
            return 0;
        };
        $this->sizeProc = fn($client): int => $size;
        $file = $c->new('void*');
        if (($st = $c->AudioFileOpenWithCallbacks(null, $this->readProc, null, $this->sizeProc, null, self::TYPES[$format] ?? 0, FFI::addr($file))) !== 0) {
            throw new \RuntimeException("audio: not $format the system reads (OSStatus $st)");
        }
        $this->file = $file;
        $ext = $c->new('void*');
        if (($st = $c->ExtAudioFileWrapAudioFileID($file, 0, FFI::addr($ext))) !== 0) {
            $c->AudioFileClose($file);
            throw new \RuntimeException("audio: ExtAudioFileWrapAudioFileID failed (OSStatus $st)");
        }
        $this->ext = $ext;
        $fmt = $c->new('AudioStreamBasicDescription');
        $n = $c->new('uint32_t'); $n->cdata = FFI::sizeof($fmt);
        $this->check($c->ExtAudioFileGetProperty($ext, self::FILE_FORMAT, FFI::addr($n), FFI::addr($fmt)), 'its format');
        $this->rate = (int)round($fmt->mSampleRate);
        $this->channels = (int)$fmt->mChannelsPerFrame;
        if ($this->rate <= 0 || $this->channels < 1) { $this->close(); throw new \RuntimeException("audio: $format of no channels or sample rate"); }
        $client = $c->new('AudioStreamBasicDescription'); // what it decodes to: interleaved 32-bit floats of its channels, at its rate
        $client->mSampleRate = $this->rate; $client->mFormatID = self::LINEAR_PCM; $client->mFormatFlags = self::FLOAT_PACKED;
        $client->mBytesPerPacket = $client->mBytesPerFrame = 4 * $this->channels; $client->mFramesPerPacket = 1;
        $client->mChannelsPerFrame = $this->channels; $client->mBitsPerChannel = 32;
        $this->check($c->ExtAudioFileSetProperty($ext, self::CLIENT_FORMAT, FFI::sizeof($client), FFI::addr($client)), 'its client format');
        $len = $c->new('int64_t'); $n->cdata = 8;
        $this->length = $c->ExtAudioFileGetProperty($ext, self::LENGTH, FFI::addr($n), FFI::addr($len)) === 0 && $len->cdata > 0 ? (int)$len->cdata : -1;
    }

    private function check(int $st, string $what): void
    {
        if ($st !== 0) {
            $this->close();
            throw new \RuntimeException("audio: the system cannot read $what (OSStatus $st)");
        }
    }

    public function sampleRate(): int { return $this->rate; }
    public function channels(): int { return $this->channels; }
    public function len(): int { return $this->length; }

    public function read(int $frames): array
    {
        if ($this->closed || $frames <= 0) { return []; }
        $c = self::c();
        if ($this->bufFrames < $frames) {
            $this->buf = $c->new('float[' . ($frames * $this->channels) . ']');
            $this->bufFrames = $frames;
        }
        $list = $c->new('AudioBufferList');
        $list->mNumberBuffers = 1;
        $list->mBuffers[0]->mNumberChannels = $this->channels;
        $list->mBuffers[0]->mDataByteSize = $frames * $this->channels * 4;
        $list->mBuffers[0]->mData = $c->cast('void*', FFI::addr($this->buf[0]));
        $n = $c->new('uint32_t'); $n->cdata = $frames;
        if (($st = $c->ExtAudioFileRead($this->ext, FFI::addr($n), FFI::addr($list))) !== 0) {
            throw new \RuntimeException("audio: the system's decoder failed (OSStatus $st)");
        }
        $got = $n->cdata * $this->channels;
        return $got === 0 ? [] : array_values(unpack('g' . $got, FFI::string($this->buf, $got * 4)));
    }

    public function setPosition(int $frame): void
    {
        if (($st = self::c()->ExtAudioFileSeek($this->ext, $frame)) !== 0) {
            throw new \RuntimeException("audio: the system's decoder cannot seek there (OSStatus $st)");
        }
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $c = self::c();
        if (isset($this->ext)) { $c->ExtAudioFileDispose($this->ext); }
        if (isset($this->file)) { $c->AudioFileClose($this->file); }
    }

    public function __destruct()
    {
        $this->close();
    }
}
