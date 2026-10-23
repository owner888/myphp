<?php
declare(strict_types=1);

namespace MyPHP;

/** How documents save their changes (mygo's Autosave). */
enum Autosave
{
    /**
     * A document's changes are written to its file a moment after the user
     * stops editing, when its window loses the keyboard, and when it
     * closes, as macOS apps do: saved documents never ask to be saved.
     * Untitled documents ask when they close (Delete, Cancel or Save), and
     * so do documents that can't be written (read-only, changed on disk).
     */
    case InPlace;
    /** Documents are written when the user saves them, and ask to save their changes when they close, as Windows and Linux apps traditionally do. */
    case Off;
}
