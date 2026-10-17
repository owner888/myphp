<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A completion the editor offers (mygo's Completion). */
final class Completion
{
    /** What a completion is, which its icon shows. */
    public const TEXT = 0, KEYWORD = 1, FUNCTION = 2, METHOD = 3, VARIABLE = 4, FIELD = 5, TYPE = 6, MODULE = 7, CONSTANT = 8, SNIPPET = 9;

    /**
     * @param string $label shows in the list, and is what the prefix typed matches
     * @param string $detail shows dimmed after it, as a type or a signature
     * @param string $insert replaces the word before the caret, $label when empty
     * @param string $documentation shows beside the list for the completion chosen
     * @param string $filterText is matched instead of $label when set
     */
    public function __construct(
        public string $label, public string $detail = '', public int $kind = self::TEXT,
        public string $insert = '', public string $documentation = '', public string $filterText = '',
    ) {}

    /** A letter or symbol for a kind of completion, as the default list shows. */
    public static function icon(int $kind): string
    {
        return match ($kind) {
            self::KEYWORD => 'k', self::FUNCTION, self::METHOD => 'ƒ', self::VARIABLE => 'x', self::FIELD => '·',
            self::TYPE => 'T', self::MODULE => '{}', self::CONSTANT => 'π', self::SNIPPET => '⌘', default => '≡',
        };
    }

    /** The kind of token a kind of completion is, whose color tints its icon. */
    public static function token(int $kind): int
    {
        return match ($kind) {
            self::KEYWORD => Token::KEYWORD, self::FUNCTION, self::METHOD => Token::FUNCTION, self::FIELD => Token::PROPERTY,
            self::TYPE, self::MODULE => Token::TYPE, self::CONSTANT => Token::CONSTANT, default => Token::TEXT,
        };
    }
}
