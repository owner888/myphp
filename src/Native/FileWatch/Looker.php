<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

/**
 * What a watch looks with (mygo's fileWatchLooker): the snapshot (Scan), or
 * with FileWatchOptions::$hintsOnly the directories alone (Hints).
 *
 * @internal
 */
interface Looker
{
    /** Enrolls the tree; the events are of what changed while it looked. @return list<\MyPHP\FileEvent> */
    public function start(): array;

    /** Records what $n says changed, for the next update; it throws when $n ends the watch. */
    public function mark(Notice $n): void;

    public function pending(): bool;

    /** Looks again at what changed. @return list<\MyPHP\FileEvent> */
    public function update(): array;

    /** The watched file the last update found gone, as the watch last saw it, or null. */
    public function removedFile(): ?array;

    /** Whether the watched directory, or the directory of the watched file, went away or was replaced. */
    public function gone(): bool;

    /** The last events of a watch whose directory went away. @return list<\MyPHP\FileEvent> */
    public function goneEvents(): array;
}
