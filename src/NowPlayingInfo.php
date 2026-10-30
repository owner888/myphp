<?php
declare(strict_types=1);

namespace MyPHP;

/** What the app plays, for NowPlaying::set (mygo's NowPlayingInfo); times are in seconds. */
final class NowPlayingInfo
{
    public function __construct(
        public string $title = '',
        public string $artist = '',
        public string $album = '',
        /** The image of the album or the episode, PNG or JPEG bytes. macOS does not show it yet (docs/mygo-deviations.md). */
        public string $artwork = '',
        /** How long the track is; 0 is unknown, as for a live stream, which shows no progress. */
        public float $duration = 0.0,
        /** Where playback is as the info is set. The system advances it while the state is PlaybackState::Playing, at $rate, so the app sets it again only when playback jumps (NowPlaying::setPlayback). */
        public float $position = 0.0,
        /** The speed of playback; 0 is 1. */
        public float $rate = 0.0,
        public PlaybackState $state = PlaybackState::Playing,
        /** The commands the system's controls offer, MediaCommand's bits: MediaCommand::PLAY | MediaCommand::PAUSE | MediaCommand::NEXT; 0 is PLAY, PAUSE and STOP. */
        public int $commands = 0,
    ) {}
}
