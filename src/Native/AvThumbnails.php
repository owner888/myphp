<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\Media\{Packet, Track};
use MyPHP\Platform;
use RuntimeException;

/**
 * Decodes keyframes of a video track into small pictures on Linux and
 * Windows over FFmpeg, away
 * from what plays (Player::thumbnail), as Native\ThumbnailDecoder does on
 * macOS: a libavcodec decoder of its own, on one thread, each keyframe
 * sent alone and drained (the decoder flushed after), its frame scaled by
 * libswscale to the pictures' size.
 *
 * @internal
 */
final class AvThumbnails implements Platform\Thumbnails
{
    /** The size of the pictures. */
    public readonly int $width, $height;
    private AvDecoder $decoder;
    private AvScaler $scaler;

    public function __construct(Track $track, int $width)
    {
        $this->width = max(1, $width);
        $this->height = max(1, (int)round($this->width * $track->height / max(1, $track->width)));
        $this->decoder = new AvDecoder($track, 1);
        $this->scaler = new AvScaler();
    }

    public function frame(Packet $p): string
    {
        $d = $this->decoder;
        try {
            $d->send($p);
            $d->send(null); // the end: its frame out now, not after the frames that would follow
            $frame = $d->receive() ?? throw new RuntimeException(sprintf('myphp: the keyframe at %.3f s decoded to no frame', $p->time));
            try {
                return $this->scaler->rgba($frame, $this->width, $this->height);
            } finally {
                AvDecoder::release($frame);
            }
        } finally {
            while (($rest = $d->receive()) !== null) { AvDecoder::release($rest); }
            $d->flush();
        }
    }
}
