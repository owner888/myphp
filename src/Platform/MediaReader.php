<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** The system's reader of a local film (Media::reader): its packets as Media\Source gives them, and the file's tags. */
interface MediaReader extends \MyPHP\Media\Source
{
    /** The file's title, artist and album, '' each where it has none. @return array{title: string, artist: string, album: string} */
    public function tags(): array;

    /** The file's ReplayGain tags: dB, and the peaks' linear amplitudes; null where none. @return array{trackGain: ?float, trackPeak: ?float, albumGain: ?float, albumPeak: ?float} */
    public function replayGain(): array;

    /** The file's artwork (its cover), its image's bytes; null for none. */
    public function cover(): ?string;
}
