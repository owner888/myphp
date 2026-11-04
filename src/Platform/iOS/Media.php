<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Audio\Device;
use MyPHP\Media\{Player, Track};
use MyPHP\Native\AudioQueue;
use MyPHP\Platform;

final class Media implements Platform\Media
{
    public function reader(string $path): Platform\MediaReader
    {
        throw new Platform\Unsupported('Video reader not implemented on iOS');
    }

    public function readerName(): string
    {
        return 'AVFoundation';
    }

    public function playable(Track $t): bool
    {
        return false;
    }

    public function output(bool $hardware): Platform\Renderers
    {
        throw new Platform\Unsupported('Video renderers not implemented on iOS');
    }

    public function thumbnails(Track $video, int $width, bool $hardware): Platform\Thumbnails
    {
        throw new Platform\Unsupported('Thumbnails not implemented on iOS');
    }

    public function hardwareDecodes(Track $video, bool $hardware): bool
    {
        return false;
    }

    public function decodingSwitchable(Track $video): bool
    {
        return false;
    }

    public function videoDecoder(Track $video, bool $hardware): string
    {
        return 'VideoToolbox';
    }

    public function audioDecoder(Track $audio): string
    {
        return 'AudioToolbox';
    }

    public function addTicker(object $player, Closure $tick): void {}
    public function removeTicker(object $player): void {}

    public function awake(string $reason): Platform\Awake
    {
        return new class implements Platform\Awake {
            public function release(): void {}
        };
    }

    public function pictureInPicture(Player $p, Closure $changed): ?object
    {
        return null;
    }

    public function showNowPlaying(object $owner, array $handlers, string $path, float $duration, float $time, float $rate, bool $paused, int $index, int $count, int $chapter, int $chapters): void {}
    public function endNowPlaying(object $owner): void {}

    public function jpeg(string $rgba, int $w, int $h, float $quality): string
    {
        return '';
    }

    public function jpegPixels(string $bytes, int $max): string
    {
        return '';
    }

    public function names(): array
    {
        return ['video' => 'metal', 'audio' => 'audioqueue', 'hwdec' => 'VideoToolbox'];
    }

    public function audioOutputs(): array
    {
        return ['default' => 'Default iOS Audio Output'];
    }

    public function audioDevice(): Device
    {
        return new AudioQueue();
    }
}
