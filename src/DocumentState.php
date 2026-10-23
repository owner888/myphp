<?php
declare(strict_types=1);

namespace MyPHP;

/** Describes a document, as Document::state() returns it and pages of its window get it (mygo's DocumentState). */
final class DocumentState
{
    public function __construct(
        /** The document's file name, or a name such as "Untitled 2" for an untitled document. */
        public readonly string $name,
        /** The document's file, '' while it is untitled. */
        public readonly string $path,
        /** Whether the document has unsaved changes. */
        public readonly bool $edited,
        /** Whether the document's file can't be written: saving asks for another file. */
        public readonly bool $readOnly,
        /** Whether another app changed the document's file while the document had unsaved changes: saving asks which to keep. */
        public readonly bool $conflict,
        /** Whether the document's file was deleted: saving writes it again. */
        public readonly bool $deleted,
    ) {}
}
