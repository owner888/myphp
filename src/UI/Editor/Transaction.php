<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * A step of undo (mygo's editor.transaction): the text before and after,
 * the changes of each version it made, and where the editor making it
 * had its selections before and after.
 */
final class Transaction
{
    /** Groups edits into undo steps: typing in a row is one step, as Zed groups edits within 300 ms; COMPOSE is the text of an input method's composition and its commit, which make one step however long they take. */
    public const OTHER = 0, TYPING = 1, DELETING = 2, COMPOSE = 3;

    /**
     * @param list<list<Change>> $changes of each version
     * @param list<RopeText> $texts the texts the versions of the step made, one a changes, which undo and redo log as they go through them, so that each entry's text is its changes'
     */
    public function __construct(
        public RopeText $before,
        public RopeText $after,
        public array $changes,
        public array $texts,
        public mixed $owner,
        public mixed $selBefore,
        public int $kind,
        public mixed $selAfter = null,
    ) {}
}
