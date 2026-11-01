<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** What the app plays, where the system shows media (mygo's platform.NowPlaying). */
interface NowPlaying
{
    /**
     * Shows $info (title, artist, album, artwork, duration, position, rate,
     * state, commands), fully defaulted by the core; null removes it and
     * stops reporting AppHandler::mediaCommand.
     * @param ?array<string, mixed> $info
     */
    public function set(?array $info): void;
}
