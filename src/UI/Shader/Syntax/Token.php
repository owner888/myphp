<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/**
 * The tokens of Go's syntax, as the scanner reads them for the shader
 * compiler (Go's go/token): an operator or a keyword is its own text, a
 * literal or a name one of the kinds here.
 *
 * @internal
 */
final class Token
{
    public const EOF = 'EOF', IDENT = 'IDENT', INT = 'INT', FLOAT = 'FLOAT', IMAG = 'IMAG', CHAR = 'CHAR', STRING = 'STRING';

    /** Go's keywords. */
    public const KEYWORDS = [
        'break' => true, 'case' => true, 'chan' => true, 'const' => true, 'continue' => true, 'default' => true, 'defer' => true,
        'else' => true, 'fallthrough' => true, 'for' => true, 'func' => true, 'go' => true, 'goto' => true, 'if' => true,
        'import' => true, 'interface' => true, 'map' => true, 'package' => true, 'range' => true, 'return' => true,
        'select' => true, 'struct' => true, 'switch' => true, 'type' => true, 'var' => true,
    ];

    /** The binary operators by precedence, 5 binding tightest (Go's Token.Precedence). */
    public const PRECEDENCE = [
        '||' => 1, '&&' => 2,
        '==' => 3, '!=' => 3, '<' => 3, '<=' => 3, '>' => 3, '>=' => 3,
        '+' => 4, '-' => 4, '|' => 4, '^' => 4,
        '*' => 5, '/' => 5, '%' => 5, '<<' => 5, '>>' => 5, '&' => 5, '&^' => 5,
    ];

    /** The assignment operators and the binary operator each applies. */
    public const ASSIGN_OPS = [
        '+=' => '+', '-=' => '-', '*=' => '*', '/=' => '/', '%=' => '%', '&=' => '&', '|=' => '|', '^=' => '^',
        '<<=' => '<<', '>>=' => '>>', '&^=' => '&^',
    ];

    /** The operators, longest first, as the scanner matches them. */
    public const OPERATORS = [
        '<<=', '>>=', '&^=', '...',
        '&&', '||', '<-', '++', '--', '==', '!=', '<=', '>=', ':=', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '<<', '>>', '&^', '~',
        '+', '-', '*', '/', '%', '&', '|', '^', '<', '>', '=', '!', '(', ')', '[', ']', '{', '}', ',', ';', '.', ':',
    ];
}
