<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;

/**
 * Configures Documents (mygo's DocumentsOptions). $read, $write, $window
 * and at least one type are required.
 */
final class DocumentsOptions
{
    /**
     * @param list<DocumentType> $types
     * @param ?Closure(string): mixed $read
     * @param ?Closure(mixed): string $write
     * @param ?Closure(Document): Window $window
     * @param ?Closure(): mixed $new
     * @param ?Closure(mixed): mixed $snapshot
     * @param ?Closure(mixed, mixed): bool $equal
     */
    public function __construct(
        /** The kinds of files the documents are. Open dialogs show them, and new documents are saved as the first. */
        public array $types = [],
        /** Makes a document's content of its file's bytes; throwing tells why it can't. */
        public ?Closure $read = null,
        /** Makes the bytes of a document's file of its content, as it was when saving started. */
        public ?Closure $write = null,
        /** Creates the window that shows a document. MyPHP sets its title, represented file and edited state, asks to save before it closes, and closes the document with it. */
        public ?Closure $window = null,
        /** The content of a new, untitled document; without it, null. */
        public ?Closure $new = null,
        /** Copies a mutable content (an object the app changes) before $write gets it, when set. */
        public ?Closure $snapshot = null,
        /**
         * Compares contents, when set: a document whose content equals what
         * its file has is not edited, so that undoing back to it, or typing
         * a character and deleting it, leaves the document unedited.
         * Without it, every set() and changed() edits the document until it
         * is saved.
         */
        public ?Closure $equal = null,
        /** How documents save their changes. */
        public Autosave $autosave = Autosave::InPlace,
        /** How long after the last edit a document is saved in place, in seconds: 5 when 0. A document edited without a pause is saved at least every 30 seconds. */
        public float $autosaveDelay = 0,
        /** Asks to save the documents with unsaved changes when the app quits, rather than keeping them and opening them again, still edited, the next time the app starts, as VS Code and Zed do. */
        public bool $askOnQuit = false,
        /** Opens no untitled document when the app starts without documents to open, or when its Dock icon is clicked while it has no windows. */
        public bool $noUntitled = false,
    ) {}
}
