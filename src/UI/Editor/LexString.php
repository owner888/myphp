<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A kind of string literal a Lexer knows (mygo's lexString). */
final class LexString
{
    /**
     * @param string $escape starts escape sequences, '' for raw strings
     * @param bool $multiline strings go on past the end of a line
     * @param string $interpOpen with $interpClose, enclose code inside the string, as ${ and } in JavaScript
     * @param string $prefix an identifier that must come right before $open, as f in Python's f"…"
     * @param bool $dollar marks $name variables inside the string, as shells do
     */
    public function __construct(
        public readonly string $open, public readonly string $close, public readonly string $escape = '', public readonly bool $multiline = false,
        public readonly string $interpOpen = '', public readonly string $interpClose = '', public readonly string $prefix = '',
        public readonly bool $dollar = false, public readonly int $kind = Token::STRING,
    ) {}

    /** A string in quotes $q with backslash escapes, on one line, as C's. */
    public static function c(string $q): self { return new self($q, $q, '\\'); }
}
