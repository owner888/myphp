<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use Closure;
use MyPHP\Audio\Device;
use MyPHP\Media\{Player, Track};
use MyPHP\Native\{AssetReader, AudioDevices, AudioQueue, Awake, CoreMedia, ImageIO, MediaTicker, Mem, NowPlaying, PictureInPicture, Renderers, SoftAudio, SoftVideo, ThumbnailDecoder};
use MyPHP\Platform;

/**
 * The player's system on macOS: AVFoundation's reader, an
 * AVSampleBufferDisplayLayer and AVSampleBufferAudioRenderer on one clock
 * (Native\Renderers), VideoToolbox for thumbnails, libdav1d for AV1 the
 * Mac does not decode, an Audio Queue for the app's sound.
 */
final class Media implements Platform\Media
{
    public function reader(string $path): Platform\MediaReader
    {
        return new AssetReader($path);
    }

    public function readerName(): string
    {
        return 'AVFoundation';
    }

    public function playable(Track $t): bool
    {
        return CoreMedia::playable($t) || SoftVideo::wanted($t, false) || SoftAudio::wanted($t);
    }

    public function output(bool $hardware): Platform\Renderers
    {
        return new Renderers($hardware);
    }

    public function thumbnails(Track $video, int $width, bool $hardware): Platform\Thumbnails
    {
        return new ThumbnailDecoder($video, $width, $hardware);
    }

    public function hardwareDecodes(Track $video, bool $hardware): bool
    {
        return !SoftVideo::wanted($video, $hardware);
    }

    public function decodingSwitchable(Track $video): bool
    {
        return SoftVideo::wanted($video, false) && CoreMedia::playable($video);
    }

    public function videoDecoder(Track $video, bool $hardware): string
    {
        return $this->hardwareDecodes($video, $hardware) ? 'VideoToolbox' : 'libdav1d';
    }

    public function audioDecoder(Track $audio): string
    {
        return SoftAudio::wanted($audio) ? 'MyPHP Vorbis' : 'AudioToolbox';
    }

    public function addTicker(object $player, Closure $tick): void
    {
        MediaTicker::add($player, $tick);
    }

    public function removeTicker(object $player): void
    {
        MediaTicker::remove($player);
    }

    public function awake(string $reason): Platform\Awake
    {
        return new Awake($reason);
    }

    public function pictureInPicture(Player $p, Closure $changed): ?PictureInPicture
    {
        return PictureInPicture::supported() ? new PictureInPicture($p, $changed) : null;
    }

    public function showNowPlaying(object $owner, array $handlers, string $path, float $duration, float $time, float $rate, bool $paused, int $index, int $count, int $chapter, int $chapters): void
    {
        NowPlaying::show($owner, $handlers, $path, $duration, $time, $rate, $paused, $index, $count, $chapter, $chapters);
    }

    public function endNowPlaying(object $owner): void
    {
        NowPlaying::end($owner);
    }

    public function jpeg(string $rgba, int $w, int $h, float $quality): string
    {
        return ImageIO::jpeg($rgba, $w, $h, $quality);
    }

    public function jpegPixels(string $bytes, int $max): string
    {
        $img = ImageIO::decode($bytes, $max);
        $rgba = \FFI::string($img['pix'], $img['w'] * $img['h'] * 4);
        Mem::free($img['pix']);
        return $rgba;
    }

    public function names(): array
    {
        return ['video' => Player::VIDEO_OUTPUT, 'audio' => Player::AUDIO_OUTPUT, 'hwdec' => 'VideoToolbox'];
    }

    public function audioOutputs(): array
    {
        $out = [];
        foreach (AudioDevices::outputs() as $uid => $name) { $out["coreaudio/$uid"] = $name; }
        return $out;
    }

    public function audioDevice(): Device
    {
        return new AudioQueue();
    }
}
