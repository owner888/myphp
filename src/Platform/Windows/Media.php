<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use MyPHP\Media\{Player, Track};
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;

/**
 * The player's system on Windows, before its stage: nothing decodes or
 * plays (opening a film throws Unsupported), what a player's state asks
 * of it while none plays (Now Playing, its ticker, the names the Inspector
 * shows) is nothing, and staying awake is Power's.
 */
final class Media implements Platform\Media
{
    public function __construct(private readonly Backend $b) {}

    public function reader(string $path): Platform\MediaReader
    {
        throw new Unsupported('myphp: the player is not on Windows yet');
    }

    public function readerName(): string
    {
        return '';
    }

    public function playable(Track $t): bool
    {
        return false;
    }

    public function output(bool $hardware): Platform\Renderers
    {
        throw new Unsupported('myphp: the player is not on Windows yet');
    }

    public function thumbnails(Track $video, int $width, bool $hardware): Platform\Thumbnails
    {
        throw new Unsupported('myphp: the player is not on Windows yet');
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
        return '';
    }

    public function audioDecoder(Track $audio): string
    {
        return '';
    }

    public function addTicker(object $player, Closure $tick): void {}

    public function removeTicker(object $player): void {}

    /** The display, or the system, kept awake through Power while held. */
    public function awake(string $reason): Platform\Awake
    {
        $power = $this->b->power();
        return new class($power, $reason) implements Platform\Awake {
            private ?Closure $release = null;
            private bool $display = true;

            public function __construct(private readonly Platform\Power $power, private readonly string $reason) {}

            public function hold(): void
            {
                $this->release ??= $this->power->keepAwake($this->display, $this->reason);
            }

            public function setDisplay(bool $display): void
            {
                $this->display = $display;
                if ($this->release !== null) {
                    $this->release();
                    $this->hold();
                }
            }

            public function release(): void
            {
                ($this->release)?->__invoke();
                $this->release = null;
            }

            public function held(): bool
            {
                return $this->release !== null;
            }
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
        throw new Unsupported('myphp: JPEG is not on Windows yet');
    }

    public function jpegPixels(string $bytes, int $max): string
    {
        throw new Unsupported('myphp: JPEG is not on Windows yet');
    }

    public function names(): array
    {
        return ['video' => '', 'audio' => '', 'hwdec' => ''];
    }

    public function audioOutputs(): array
    {
        return [];
    }

    public function audioDevice(): \MyPHP\Audio\Device
    {
        throw new Unsupported('myphp: sound is not on Windows yet');
    }
}
