<?php
declare(strict_types=1);

namespace MyPHP;

/** A kind of file that Documents reads and writes (mygo's DocumentType). */
final class DocumentType
{
    /** @param list<string> $extensions */
    public function __construct(
        /** Describes the files, e.g. "Markdown Document". Open and save dialogs show it. */
        public string $name,
        /** The file name extensions, without dots, e.g. ['md', 'markdown']. New documents are saved with the first. */
        public array $extensions,
    ) {}
}
