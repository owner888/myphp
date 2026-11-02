<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use MyPHP\Media\{AudioFilters, Packet, Track};

/**
 * What decodes and plays a Player's packets on one clock (Media::output):
 * the player tops them up at its ticks while they take more. Two public
 * properties the player sets: ?\Closure $videoWanted, called when the
 * renderers take more video between ticks (the player gives what it
 * queued), and float $audioDelay, the seconds the sound plays after its
 * time in the file.
 */
interface Renderers
{
    /** Whether the renderer of $t's packets takes more now. */
    public function ready(Track $t): bool;

    /** What follows is a slice of the main thread of its own, its packets given while it lasts. */
    public function slice(): void;

    /** At the end of the film ($drain), what the decoders hold, given to be shown. */
    public function pump(bool $drain): void;

    /** Gives $p to its renderer: a video frame shown unless $show is false (decoded for those after it). */
    public function enqueue(Packet $p, bool $show = true): void;

    /** The equalizer's gains (IINA's ten bands, dB), null or flat for none. Flush and feed again after. @param ?list<float> $gains */
    public function setEqualizer(?array $gains): void;

    /** A gain of the audio, linear (a volume past 100%, ReplayGain's), 1 for none. Flush and feed again after. */
    public function setGain(float $linear): void;

    /** The audio filters (mpv's af), null for none. Flush and feed again after. */
    public function setAudioFilters(?AudioFilters $filters): void;

    /** The frame shown as a PNG of the film's pixels; null before one shows. */
    public function snapshot(): ?string;

    /** Decodes on the system's hardware decoders or not from the next packet on. */
    public function setHardware(bool $on): void;

    /** Drops what the renderers hold, for a seek or another audio track. */
    public function flush(): void;

    /** The time of the clock, in seconds. */
    public function time(): float;

    /** Runs the clock at $rate (0 stops it), from $at seconds when given. */
    public function setRate(float $rate, ?float $at = null): void;

    public function setVolume(float $v): void;

    public function setMuted(bool $m): void;

    /** What failed, a renderer's error, or null. */
    public function failure(): ?string;

    /** The output the audio plays on, by its unique ID, the system's default for null. */
    public function setAudioDevice(?string $uid): void;

    /** Whether HDR shows in HDR, else tone mapped to the standard range. */
    public function setDynamicRange(bool $high): void;

    /**
     * The video equalizer's colors and the video filters' effects on the
     * picture, $px points a pixel of the decoded one, $w by $h points.
     * @param array{brightness: int, contrast: int, saturation: int, gamma: int, hue: int} $eq
     * @param list<array<int, mixed>> $effects
     */
    public function setPictureFilters(array $eq, array $effects = [], float $px = 1.0, float $w = 0.0, float $h = 0.0): void;

    /** Decodes the video here and deinterlaces it ($on). Flush and feed again after. */
    public function setDeinterlace(bool $on): void;

    /**
     * The picture as an image the window's renderer draws where
     * Context::movie's node is (Linux: decoded frames converted to RGBA);
     * null where the system shows it itself, under the node's hole (macOS:
     * the display layer).
     */
    public function frame(): ?\MyPHP\UI\Image;
}
