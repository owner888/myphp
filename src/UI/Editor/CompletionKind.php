<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** What a completion is (mygo's editor.CompletionKind), which its icon shows. */
enum CompletionKind: int
{
    case CompletionText = 0;
    case CompletionKeyword = 1;
    case CompletionFunction = 2;
    case CompletionMethod = 3;
    case CompletionVariable = 4;
    case CompletionField = 5;
    case CompletionType = 6;
    case CompletionModule = 7;
    case CompletionConstant = 8;
    case CompletionSnippet = 9;

    /** A letter or symbol for the kind, as the default list shows. */
    public function icon(): string
    {
        return match ($this) {
            self::CompletionKeyword => 'k', self::CompletionFunction, self::CompletionMethod => 'ƒ', self::CompletionVariable => 'x', self::CompletionField => '·',
            self::CompletionType => 'T', self::CompletionModule => '{}', self::CompletionConstant => 'π', self::CompletionSnippet => '⌘', default => '≡',
        };
    }

    /** The kind of token the kind is (Token), whose color tints its icon. */
    public function token(): int
    {
        return match ($this) {
            self::CompletionKeyword => Token::KEYWORD, self::CompletionFunction, self::CompletionMethod => Token::FUNCTION, self::CompletionField => Token::PROPERTY,
            self::CompletionType, self::CompletionModule => Token::TYPE, self::CompletionConstant => Token::CONSTANT, default => Token::TEXT,
        };
    }
}
