<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * What reads a film over the network into memory ahead of the player
 * (HttpBytes, Hls): how much it holds and how fast it comes, which the
 * player's buffering indicator shows (IINA's).
 *
 * @internal Media\Player
 */
interface Cache
{
    /** The bytes it holds. */
    public function cached(): int;

    /** How fast bytes came over the last second, a second. */
    public function speed(): float;

    /** Counts its bytes in $b with the others' of the film (one cache of all its readers). */
    public function share(CacheBudget $b): void;
}
