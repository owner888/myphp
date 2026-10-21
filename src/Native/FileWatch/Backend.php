<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

/**
 * A system's file watch (mygo's platform.FileWatch with its driver): the
 * scan adds and removes targets, and polls it for what changed, on the
 * main thread.
 *
 * @internal
 */
interface Backend
{
    /** Watches $t; throws FsError (with the system's errno), Unsupported, or another error. */
    public function add(Target $t): void;

    public function remove(int $id): void;

    /**
     * What the system reported, waiting up to $timeout seconds (INF:
     * until something comes) when nothing has yet. An error it throws ends
     * the watch.
     *
     * @return list<Notice>
     */
    public function poll(float $timeout): array;

    /** Releases every handle. */
    public function close(): void;
}
