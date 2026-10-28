<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * A reader of a film over the network, of the cache it shares with the
 * others of the film (CacheBudget): HttpBytes, Hls.
 *
 * @internal Media\CacheBudget
 */
interface CacheMember extends Cache
{
    /** The bytes it holds ahead of its reader. */
    public function ahead(): int;

    /**
     * What of it may go: when each was last read, and what lets it go
     * (returning the bytes it freed). @return list<array{0: int, 1: \Closure(): int}>
     */
    public function evictable(): array;

    /** Reads ahead again, while the cache has room. */
    public function resume(): void;
}
