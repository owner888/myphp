<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * Where a Player's packets come from: a file's tracks and its frames in
 * the order stored, encoded, with a seek to a time. Matroska, Mp4, Ts and
 * Hls read in PHP; Native\AssetReader reads what AVFoundation does (a
 * local MP4, MOV, M4V).
 */
interface Source
{
    /** @return list<Track> */
    public function tracks(): array;

    /** In seconds, 0 when the file does not say. */
    public function duration(): float;

    /** The film's chapters, by their start; none when the file has none. @return list<Chapter> */
    public function chapters(): array;

    /** The next frame of any track; null at the end, or when what it reads is not here yet (ready() false: a film over HTTP). */
    public function next(): ?Packet;

    /** Whether next() has what it reads, without waiting (false asks for it); true at the end. */
    public function ready(): bool;

    /** Goes back to where decoding $seconds starts, a keyframe of the video at or before it, where next() goes on. */
    public function seek(float $seconds): void;

    /**
     * The tracks the player plays, null for none: a source whose tracks
     * come from apart (HLS's audio renditions) reads only theirs from the
     * next seek on; one of a file's reads all its tracks anyway.
     */
    public function choose(?Track $video, ?Track $audio): void;

    /** The keyframe of the video a seek to $seconds goes to, read alone (a thumbnail's), null for none; next() goes on only after a seek(). */
    public function keyframe(float $seconds): ?Packet;
}
