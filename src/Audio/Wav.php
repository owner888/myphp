<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * Decodes WAV files of integer PCM of 8 to 32 bits, or of 32 or 64 bit
 * floats (mygo's wavDecoder).
 */
final class Wav implements Decoder
{
    private int $rate = 0, $channels = 0, $bits = 0;
    private bool $float = false;
    /** The data's offset in the stream, its frames (-1 for unknown), and the frame read next. */
    private int $start = 0, $length = -1, $pos = 0;

    /** @param resource $r a seekable stream at the start of the file */
    public function __construct(private $r)
    {
        $start = (int)ftell($r);
        if (strlen((string)fread($r, 12)) !== 12) { self::invalid(); }
        $off = $start + 12;
        $haveFmt = false;
        while (true) {
            $h = (string)fread($r, 8);
            if (strlen($h) !== 8) { self::invalid(); }
            $size = unpack('V', $h, 4)[1];
            $off += 8;
            switch (substr($h, 0, 4)) {
                case 'fmt ':
                    if ($size < 16 || $size > 1 << 16) { self::invalid(); }
                    $b = (string)fread($r, $size);
                    if (strlen($b) !== $size) { self::invalid(); }
                    ['tag' => $tag, 'ch' => $this->channels, 'rate' => $this->rate] = unpack('vtag/vch/Vrate', $b);
                    $this->bits = unpack('v', $b, 14)[1];
                    if ($tag === 0xfffe && $size >= 40) { $tag = unpack('v', $b, 24)[1]; } // WAVE_FORMAT_EXTENSIBLE: the tag starts the sub-format
                    if ($tag === 1 && $this->bits >= 8 && $this->bits <= 32 && $this->bits % 8 === 0) {
                        // integer PCM
                    } elseif ($tag === 3 && ($this->bits === 32 || $this->bits === 64)) {
                        $this->float = true;
                    } else {
                        throw new \RuntimeException('audio: WAV of an encoding other than PCM or float');
                    }
                    if ($this->channels < 1 || $this->rate < 1) { self::invalid(); }
                    $haveFmt = true;
                    break;
                case 'data':
                    if (!$haveFmt) { self::invalid(); }
                    $this->start = $off;
                    if ($size !== 0 && $size !== 0xffffffff) { $this->length = intdiv($size, $this->frameBytes()); } // not one written as it streamed
                    return;
            }
            $size += $size & 1; // chunks are padded to even sizes
            if (fseek($r, $off + $size) !== 0) { self::invalid(); }
            $off += $size;
        }
    }

    private static function invalid(): never
    {
        throw new \RuntimeException('audio: invalid WAV');
    }

    private function frameBytes(): int { return $this->channels * intdiv($this->bits, 8); }
    public function sampleRate(): int { return $this->rate; }
    public function channels(): int { return $this->channels; }
    public function len(): int { return $this->length; }

    public function read(int $frames): array
    {
        $fb = $this->frameBytes();
        $n = $this->length >= 0 ? min($frames, $this->length - $this->pos) : $frames;
        if ($n <= 0) { return []; }
        $b = (string)fread($this->r, $n * $fb);
        $n = intdiv(strlen($b), $fb);
        if ($n === 0) { return []; }
        $count = $n * $this->channels;
        $bs = intdiv($this->bits, 8);
        $this->pos += $n;
        if ($this->float) {
            return array_values(unpack(($bs === 4 ? 'g' : 'e') . $count, $b));
        }
        $out = [];
        switch ($bs) {
            case 1:
                foreach (unpack('C' . $count, $b) as $v) { $out[] = ($v - 128) / 128; }
                break;
            case 2:
                foreach (unpack('v' . $count, $b) as $v) { $out[] = ($v >= 0x8000 ? $v - 0x10000 : $v) / 32768; }
                break;
            case 3:
                for ($i = 0, $o = 0; $i < $count; $i++, $o += 3) {
                    $v = ord($b[$o]) | ord($b[$o + 1]) << 8 | ord($b[$o + 2]) << 16;
                    $out[] = ($v >= 0x800000 ? $v - 0x1000000 : $v) / 8388608;
                }
                break;
            default:
                foreach (unpack('V' . $count, $b) as $v) { $out[] = ($v >= 0x80000000 ? $v - 0x100000000 : $v) / 2147483648; }
        }
        return $out;
    }

    public function setPosition(int $frame): void
    {
        if ($frame < 0 || ($this->length >= 0 && $frame > $this->length)) {
            throw new \RuntimeException('audio: seek out of range');
        }
        if (fseek($this->r, $this->start + $frame * $this->frameBytes()) !== 0) {
            throw new \RuntimeException('audio: the stream cannot seek');
        }
        $this->pos = $frame;
    }
}
