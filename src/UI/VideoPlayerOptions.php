<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What Context::videoPlayer builds besides the picture and its control
 * bar: the title bar over the picture (for a window of a hidden title
 * bar), the messages at the top left, the sidebar of quick settings,
 * IINA's default keys (off, the app binds its own), going on from where
 * a film was left, keeping the display awake while a film plays, the
 * system's Now Playing and media keys, and finding subtitles online.
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
        /** Shows the film in the system's Now Playing and takes its commands, the media keys' too, as IINA's "Use system media control", on by default. */
        public readonly bool $nowPlaying = true,
        /**
         * The API key of OpenSubtitles (opensubtitles.com's consumers) the
         * subtitles tab's Search Online uses, as IINA's Find Online Subtitles:
         * null, myphp's own, as IINA has its own; an app's, its; '', none,
         * and the button stays disabled. A key an app is built with, anyone
         * can read out of it.
         */
        public readonly ?string $openSubtitlesKey = null,
    ) {}
}
