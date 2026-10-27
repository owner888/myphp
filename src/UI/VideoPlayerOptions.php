<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What Context::videoPlayer builds besides the picture and its control
 * bar: the title bar over the picture (for a window of a hidden title
 * bar), the messages at the top left, the sidebar of quick settings,
 * IINA's default keys (off, the app binds its own), going on from where
 * a film was left, and keeping the display awake while a film plays.
 */
final class VideoPlayerOptions
{
    public function __construct(
        public readonly bool $titleBar = true,
        public readonly bool $osd = true,
        public readonly bool $sidebar = true,
        public readonly bool $keys = true,
        /** Goes on from where the film was left (Media\PlaybackPositions), as IINA does, and notes where it is left. */
        public readonly bool $resume = true,
        /** Keeps the display awake, its screen saver off, while a film plays (Player::setKeepAwake), as IINA does by default. */
        public readonly bool $keepAwake = true,
    ) {}
}
