<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * OpenSubtitles' downloads allowed are spent (its 406, remaining -1): IINA's
 * "Subtitle download limit reached", told when they come again.
 *
 * @internal UI\MoviePlayerState
 */
final class OpenSubtitlesQuota extends RuntimeException
{
    /** @param ?int $reset when the downloads come again (Unix time), null where the server does not say */
    public function __construct(public readonly ?int $reset)
    {
        parent::__construct('OpenSubtitles: the downloads allowed are spent' . ($reset === null ? '' : ' until ' . gmdate('Y-m-d H:i', $reset) . ' UTC'));
    }
}
