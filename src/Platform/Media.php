<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use Closure;
use MyPHP\Media\{Player, Track};

/**
 * What Media\Player asks of the system (myphp's: mygo has no player).
 * The core reads Matroska, WebM, MPEG-TS, HLS and MP4 over the network in
 * PHP and keeps its packets ahead of the clock; what reads the other
 * files, what decodes and plays the packets, and what keeps the display
 * awake while it plays are the system's: AVFoundation on macOS, FFmpeg
 * and PulseAudio (or ALSA) on Linux.
 */
interface Media
{
    /** The reader of the local file at $path, of a format the core does not read (all but Matroska, WebM and MPEG-TS: MP4, MOV, MP3…). */
    public function reader(string $path): MediaReader;

    /** What reader() reads with, as the Inspector names a file's format ("AVFoundation (MP3)"). */
    public function readerName(): string;

    /** Whether the system decodes track $t (an audio or a video one) for the renderers. */
    public function playable(Track $t): bool;

    /** Renderers of a film's packets, whose video the system decodes in hardware unless $hardware is off (IINA's hardware decoding). */
    public function output(bool $hardware): Renderers;

    /** A decoder of video track $video's keyframes into pictures $width wide (Player::thumbnail), in software where $hardware is off. */
    public function thumbnails(Track $video, int $width, bool $hardware): Thumbnails;

    /** Whether video track $video decodes on the system's hardware decoders as $hardware asks (Player::hardwareDecoding). */
    public function hardwareDecodes(Track $video, bool $hardware): bool;

    /** Whether video track $video can be decoded either way, in hardware or in software (Player::decodingSwitchable). */
    public function decodingSwitchable(Track $video): bool;

    /** The name of what decodes video track $video ($hardware as Player has it), as the Inspector shows it. */
    public function videoDecoder(Track $video, bool $hardware): string;

    /** The name of what decodes audio track $audio, as the Inspector shows it. */
    public function audioDecoder(Track $audio): string;

    /** Calls $tick on the main thread every Native\MediaTicker::INTERVAL seconds, with the other players', until removeTicker($player); it runs on while a window resizes or a menu is open. */
    public function addTicker(object $player, Closure $tick): void;

    public function removeTicker(object $player): void;

    /** What keeps the display awake while a film plays (Player::setKeepAwake), told $reason. */
    public function awake(string $reason): Awake;

    /** The system's picture in picture of $p's video, $changed told as it starts (true) and stops; null where the system has none. */
    public function pictureInPicture(Player $p, Closure $changed): ?object;

    /**
     * Shows what $owner (a player's state) plays in the system's Now
     * Playing and takes its commands, $handlers by name, as
     * Native\NowPlaying::show does; endNowPlaying($owner) ends it.
     * @param array<string, Closure> $handlers
     */
    public function showNowPlaying(object $owner, array $handlers, string $path, float $duration, float $time, float $rate, bool $paused, int $index, int $count, int $chapter, int $chapters): void;

    public function endNowPlaying(object $owner): void;

    /** $w by $h pixels $rgba (premultiplied, top row first, opaque) as a JPEG of $quality, 0 to 1: the thumbnails' cache's (Media\ThumbnailCache). */
    public function jpeg(string $rgba, int $w, int $h, float $quality): string;

    /** The RGBA pixels of JPEG $bytes, at most $max on a side, top row first (the thumbnails' cache's). */
    public function jpegPixels(string $bytes, int $max): string;

    /** What shows the picture, what plays the sound and what decodes in hardware, by their names (mpv's current-vo, current-ao, hwdec-current: the Inspector's). @return array{video: string, audio: string, hwdec: string} */
    public function names(): array;

    /** The system's audio outputs, by mpv's names of them ("coreaudio/<unique ID>", "pulse/<sink>", "alsa/<device>"), with their names; Player::setAudioDevice takes the name after the driver on macOS ("coreaudio/" off), the whole on Linux. @return array<string, string> */
    public function audioOutputs(): array;

    /** The audio output of the app's own sound (Audio\Output: Audio\Mixer's frames, 48 kHz stereo floats). */
    public function audioDevice(): \MyPHP\Audio\Device;
}
