<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\Audio\Vorbis\Codec;
use MyPHP\Media\Track;
use RuntimeException;

/**
 * An audio track decoded in PHP, as mpv decodes what the system does not
 * (Vorbis, which CoreAudio has no decoder of): each packet decoded by the
 * Vorbis codec of the app's own sound (Audio\Vorbis\Codec, mygo's), its
 * frames made 32-bit float PCM, interleaved, in the order of WAVE's
 * channels (Vorbis's order of them, section 4.3.9, put so), which the
 * audio renderer plays as it plays what it decodes itself.
 *
 * @internal Native\Renderers
 */
final class SoftAudio
{
    /** The channel taken for each of WAVE's, of Vorbis's order, by the channels there are (1, 2 and 4 are in WAVE's order). */
    private const ORDER = [3 => [0, 2, 1], 5 => [0, 2, 1, 3, 4], 6 => [0, 2, 1, 5, 3, 4], 7 => [0, 2, 1, 6, 5, 3, 4], 8 => [0, 2, 1, 7, 5, 6, 3, 4]];
    /** CoreAudio's channel bits of them (kAudioChannelBit_*: left, right, center, LFE, back left and right, back center, side left and right). */
    public const BITS = [1 => 4, 2 => 3, 3 => 7, 4 => 51, 5 => 55, 6 => 63, 7 => 1807, 8 => 1599];

    private Codec $codec;

    public function __construct(Track $t)
    {
        $this->codec = Codec::fromXiph($t->private);
        if (!isset(self::BITS[$this->codec->channels()])) {
            throw new RuntimeException("myphp: Vorbis of {$this->codec->channels()} channels, which have no order the system knows (8 at most)");
        }
    }

    /** Whether track $t is decoded so: Vorbis. */
    public static function wanted(Track $t): bool
    {
        return $t->type === Track::AUDIO && $t->codec === 'A_VORBIS';
    }

    public function channels(): int { return $this->codec->channels(); }
    public function rate(): int { return $this->codec->rate(); }

    /**
     * Packet $data decoded: its frames as float PCM bytes, interleaved in
     * WAVE's order, and how many there are; none for the first after a
     * start (it only overlaps the next).
     * @return array{0: string, 1: int}
     */
    public function decode(string $data): array
    {
        $s = $this->codec->decode($data);
        $ch = $this->codec->channels();
        $n = intdiv(count($s), $ch);
        if ($n === 0) {
            return ['', 0];
        }
        if (isset(self::ORDER[$ch])) {
            $order = self::ORDER[$ch]; $out = [];
            for ($f = 0, $at = 0; $f < $n; $f++, $at += $ch) {
                foreach ($order as $c) { $out[] = $s[$at + $c]; }
            }
            $s = $out;
        }
        return [pack('g*', ...$s), $n];
    }

    /** Forgets the block before, for a seek. */
    public function reset(): void
    {
        $this->codec->reset();
    }
}
