<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * The commands of the media keys and the system's media controls (mygo's
 * MediaCommand), as bits, so that NowPlayingInfo::$commands is a set of
 * them: MediaCommand::PLAY | MediaCommand::PAUSE.
 */
final class MediaCommand
{
    /** PLAY and PAUSE are also what the play/pause key sends: PAUSE while the info's state is PlaybackState::Playing, else PLAY. */
    public const PLAY = 1, PAUSE = 2;
    public const STOP = 8;
    /** NEXT and PREVIOUS go to the next and the previous track. */
    public const NEXT = 16, PREVIOUS = 32;
    /** SEEK moves to the position NowPlaying::onCommand's function gets: the system's scrubber. */
    public const SEEK = 64;
    /** @internal the play/pause key, which NowPlaying makes PLAY or PAUSE */
    public const TOGGLE = 4;

    private function __construct() {}
}
