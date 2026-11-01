<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\Native;
use MyPHP\Platform;

/** MPNowPlayingInfoCenter and MPRemoteCommandCenter (mygo's darwin nowplaying.go). */
final class NowPlaying implements Platform\NowPlaying
{
    public function __construct(private readonly Backend $b) {}

    public function set(?array $info): void
    {
        $h = $this->b->h;
        Native\NowPlaying::media($info, static fn(int $cmd, float $position) => $h->mediaCommand($cmd, $position));
    }
}
