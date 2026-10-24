<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A completion the editor offers (mygo's Completion). */
final class Completion
{
    /**
     * @param string $label shows in the list, and is what the prefix typed matches
     * @param string $detail shows dimmed after it, as a type or a signature
     * @param string $insert replaces the word before the caret, $label when empty
     * @param string $documentation shows beside the list for the completion chosen
     * @param string $filterText is matched instead of $label when set
     */
    public function __construct(
        public string $label, public string $detail = '', public CompletionKind $kind = CompletionKind::CompletionText,
        public string $insert = '', public string $documentation = '', public string $filterText = '',
    ) {}
}
