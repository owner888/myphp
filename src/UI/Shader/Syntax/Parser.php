<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/**
 * Parses a Go source file into its syntax tree, as Go's go/parser does,
 * for the shader compiler: all of Go's syntax but generics, the constructs
 * a shader cannot have (pointers, maps, channels, function literals,
 * switch, go, defer...) parsed into Unsupported nodes that the compiler
 * reports. It stops at the first syntax error.
 *
 * @internal
 */
final class Parser
{
    /** @var list<array{0: string, 1: string, 2: int}> */
    private array $toks;
    private int $p = 0;
    /** The token's kind, text and offset. */
    private string $tok;
    private string $lit;
    private int $pos;
    /** How deep in parentheses or brackets the parser is, below 0 in the header of a control clause, where T{ starts its block, not a literal (Go's exprLev). */
    private int $exprLev = 0;

    private function __construct(private readonly string $src)
    {
        $s = new Scanner($src);
        if ($s->errors !== []) {
            [$at, $msg] = $s->errors[0];
            throw new SyntaxError($at, $msg);
        }
        $this->toks = $s->tokens;
        $this->load();
    }

    /** The tree of $src. @throws SyntaxError at the first thing wrong */
    public static function parse(string $src): File
    {
        return (new self($src))->file();
    }

    /** The line and column of byte $off of $src, from 1, the column in bytes, as Go's token.Position. @return array{0: int, 1: int} */
    public static function position(string $src, int $off): array
    {
        $off = max(0, min($off, strlen($src)));
        $before = substr($src, 0, $off);
        $nl = strrpos($before, "\n");
        return [substr_count($before, "\n") + 1, $off - ($nl === false ? 0 : $nl + 1) + 1];
    }

    private function load(): void
    {
        [$this->tok, $this->lit, $this->pos] = $this->toks[$this->p];
    }

    private function next(): void
    {
        if ($this->tok !== Token::EOF) {
            $this->p++;
            $this->load();
        }
    }

    /** The kind of the token after this one. */
    private function peek(): string
    {
        return $this->toks[min($this->p + 1, count($this->toks) - 1)][0];
    }

    /** What the token is, in an error: a name or a literal itself, newline for an inserted semicolon. */
    private function found(): string
    {
        return match (true) {
            $this->tok === ';' && $this->lit === "\n" => 'newline',
            $this->tok === Token::EOF => "'EOF'",
            in_array($this->tok, [Token::IDENT, Token::INT, Token::FLOAT, Token::IMAG, Token::CHAR, Token::STRING], true) => $this->lit,
            default => "'{$this->tok}'",
        };
    }

    private function fail(string $what, ?int $at = null): never
    {
        throw new SyntaxError($at ?? $this->pos, "expected $what, found {$this->found()}");
    }

    /** Takes a token of $kind, and returns where it was. */
    private function expect(string $kind): int
    {
        if ($this->tok !== $kind) {
            $this->fail("'$kind'");
        }
        $at = $this->pos;
        $this->next();
        return $at;
    }

    /** The semicolon ending a declaration or a statement: none is needed before ) or }. */
    private function semi(): void
    {
        if ($this->tok === ')' || $this->tok === '}') {
            return;
        }
        if ($this->tok !== ';') {
            $this->fail("';'");
        }
        $this->next();
    }

    private function ident(): Ident
    {
        $at = $this->pos;
        $name = $this->tok === Token::IDENT ? $this->lit : '_';
        $this->expect(Token::IDENT);
        return new Ident($at, $name);
    }

    private function file(): File
    {
        $at = $this->pos;
        $this->expect('package');
        $name = $this->ident();
        $this->semi();
        $decls = [];
        while ($this->tok === 'import') {
            $decls[] = $this->genDecl();
        }
        while ($this->tok !== Token::EOF) {
            $decls[] = match ($this->tok) {
                'const', 'var', 'type' => $this->genDecl(),
                'func' => $this->funcDecl(),
                'import' => throw new SyntaxError($this->pos, 'imports must appear before other declarations'),
                default => $this->fail('declaration'),
            };
        }
        return new File($at, $name, $decls);
    }

    private function genDecl(): GenDecl
    {
        $at = $this->pos;
        $tok = $this->tok;
        $this->next();
        $specs = [];
        if ($this->tok === '(') {
            $this->next();
            while ($this->tok !== ')' && $this->tok !== Token::EOF) {
                $specs[] = $this->spec($tok);
                $this->semi();
            }
            $this->expect(')');
        } else {
            $specs[] = $this->spec($tok);
        }
        $this->semi();
        return new GenDecl($at, $tok, $specs);
    }

    private function spec(string $tok): ValueSpec|TypeSpec|ImportSpec
    {
        $at = $this->pos;
        if ($tok === 'import') {
            if ($this->tok === Token::IDENT || $this->tok === '.') {
                $this->next();
            }
            $path = $this->lit;
            $this->expect(Token::STRING);
            return new ImportSpec($at, $path);
        }
        if ($tok === 'type') {
            $name = $this->ident();
            $alias = $this->tok === '=';
            if ($alias) {
                $this->next();
            }
            return new TypeSpec($at, $name, $this->type(), $alias);
        }
        $names = [$this->ident()];
        while ($this->tok === ',') {
            $this->next();
            $names[] = $this->ident();
        }
        $type = null; $values = [];
        if ($this->tok !== '=' && $this->tok !== ';' && $this->tok !== ')') {
            $type = $this->type();
        }
        if ($this->tok === '=') {
            $this->next();
            $values = $this->exprList();
        } elseif ($tok === 'var' && $type === null) {
            $this->fail('type');
        }
        return new ValueSpec($at, $names, $type, $values);
    }

    private function funcDecl(): FuncDecl
    {
        $at = $this->expect('func');
        $recv = null;
        if ($this->tok === '(') {
            $recv = $this->params();
        }
        $name = $this->ident();
        if ($this->tok === '[') {
            throw new SyntaxError($this->pos, 'generic function cannot be used in a shader');
        }
        $type = $this->signature($at);
        $body = null;
        if ($this->tok === '{') {
            $body = $this->block();
        }
        $this->semi();
        return new FuncDecl($at, $recv, $name, $type, $body);
    }

    private function signature(int $at): FuncType
    {
        $params = $this->params();
        $results = null;
        if ($this->tok === '(') {
            $results = $this->params();
        } elseif ($this->startsType()) {
            $p = $this->pos;
            $results = [new Field($p, [], $this->type())];
        }
        return new FuncType($at, $params, $results);
    }

    /** Whether the token starts a type. */
    private function startsType(): bool
    {
        return in_array($this->tok, [Token::IDENT, '[', 'struct', '*', 'func', 'interface', 'map', 'chan', '(', '<-'], true);
    }

    /**
     * A list of parameters in parentheses: all named, as (a, b vec2, c
     * float), or none, as (vec4, float).
     * @return list<Field>
     */
    private function params(): array
    {
        $this->expect('(');
        /** @var list<array{0: ?Ident, 1: Expr, 2: int}> each a name and its type, or a type alone */
        $items = [];
        while ($this->tok !== ')' && $this->tok !== Token::EOF) {
            $at = $this->pos;
            if ($this->tok === Token::IDENT) {
                $x = $this->ident();
                if ($this->tok === '.') { // a qualified type, pkg.T
                    $this->next();
                    $items[] = [null, new SelectorExpr($x->pos, $x, $this->ident()), $at];
                } elseif ($this->tok === ',' || $this->tok === ')') {
                    $items[] = [null, $x, $at];
                } else {
                    $items[] = [$x, $this->paramType(), $at];
                }
            } else {
                $items[] = [null, $this->paramType(), $at];
            }
            if ($this->tok !== ',') {
                if ($this->tok !== ')') {
                    throw new SyntaxError($this->pos, "missing ',' in parameter list");
                }
                break;
            }
            $this->next();
        }
        $this->expect(')');
        $named = array_filter($items, fn(array $it): bool => $it[0] !== null) !== [];
        if (!$named) {
            return array_map(fn(array $it): Field => new Field($it[2], [], $it[1]), $items);
        }
        $fields = []; $names = []; $start = -1;
        foreach ($items as [$name, $type, $at]) {
            if ($start < 0) { $start = $at; }
            if ($name === null) {
                if (!$type instanceof Ident) {
                    throw new SyntaxError($at, 'mixed named and unnamed parameters');
                }
                $names[] = $type; // a name, typed by the next one that has a type
                continue;
            }
            $names[] = $name;
            $fields[] = new Field($start, $names, $type);
            $names = []; $start = -1;
        }
        if ($names !== []) {
            throw new SyntaxError($names[count($names) - 1]->pos, 'mixed named and unnamed parameters');
        }
        return $fields;
    }

    private function paramType(): Expr
    {
        if ($this->tok === '...') {
            $at = $this->pos;
            $this->next();
            return new Ellipsis($at, $this->type());
        }
        if ($this->tok === '[' && $this->peek() === '...') { // a parameter's array has a length, as Go's parser reads it as an instantiation's
            $this->next();
            $this->fail('operand');
        }
        return $this->type();
    }

    private function type(): Expr
    {
        $at = $this->pos;
        switch ($this->tok) {
            case Token::IDENT:
                $x = $this->ident();
                if ($this->tok === '.') {
                    $this->next();
                    return new SelectorExpr($at, $x, $this->ident());
                }
                return $x;
            case '[':
                $this->next();
                if ($this->tok === ']') {
                    $this->next();
                    return new ArrayType($at, null, $this->type());
                }
                $this->exprLev++;
                if ($this->tok === '...') {
                    $len = new Ellipsis($this->pos, null);
                    $this->next();
                } else {
                    $len = $this->expr();
                }
                $this->exprLev--;
                $this->expect(']');
                return new ArrayType($at, $len, $this->type());
            case 'struct':
                $this->next();
                $this->expect('{');
                $fields = [];
                while ($this->tok !== '}' && $this->tok !== Token::EOF) {
                    $fat = $this->pos;
                    $names = [];
                    if ($this->tok === Token::IDENT && !in_array($this->peek(), ['.', ';', '}'], true)) {
                        $names[] = $this->ident();
                        while ($this->tok === ',') {
                            $this->next();
                            $names[] = $this->ident();
                        }
                    }
                    $fields[] = new Field($fat, $names, $this->type());
                    if ($this->tok === Token::STRING) { $this->next(); } // a tag
                    $this->semi();
                }
                $this->expect('}');
                return new StructType($at, $fields);
            case '(':
                $this->next();
                $t = $this->type();
                $this->expect(')');
                return new ParenExpr($at, $t);
            case '*':
                $this->next();
                $this->type();
                return new Unsupported($at, 'StarExpr');
            case 'map':
                $this->next();
                $this->expect('[');
                $this->type();
                $this->expect(']');
                $this->type();
                return new Unsupported($at, 'MapType');
            case 'chan':
            case '<-':
                $this->next();
                if ($this->tok === 'chan' || $this->tok === '<-') { $this->next(); }
                $this->type();
                return new Unsupported($at, 'ChanType');
            case 'func':
                $this->next();
                $this->signature($at);
                return new Unsupported($at, 'FuncType');
            case 'interface':
                $this->next();
                $this->skipBraces();
                return new Unsupported($at, 'InterfaceType');
        }
        $this->fail('type');
    }

    /** Skips a block in braces, whatever it holds. */
    private function skipBraces(): void
    {
        $this->expect('{');
        for ($depth = 1; $depth > 0; $this->next()) {
            if ($this->tok === Token::EOF) {
                $this->fail("'}'");
            }
            if ($this->tok === '{') { $depth++; }
            if ($this->tok === '}') { $depth--; }
        }
    }

    private function block(): BlockStmt
    {
        $at = $this->expect('{');
        $list = $this->stmtList();
        $end = $this->expect('}');
        return new BlockStmt($at, $list, $end);
    }

    /** @return list<Stmt> */
    private function stmtList(): array
    {
        $list = [];
        while ($this->tok !== '}' && $this->tok !== Token::EOF && $this->tok !== 'case' && $this->tok !== 'default') {
            $list[] = $this->stmt();
        }
        return $list;
    }

    private function stmt(): Stmt
    {
        $at = $this->pos;
        switch ($this->tok) {
            case 'const': case 'var': case 'type':
                return new DeclStmt($at, $this->genDecl());
            case '{':
                $b = $this->block();
                $this->semi();
                return $b;
            case 'if':
                return $this->ifStmt();
            case 'for':
                return $this->forStmt();
            case 'return':
                $this->next();
                $results = $this->tok !== ';' && $this->tok !== '}' ? $this->exprList() : [];
                $this->semi();
                return new ReturnStmt($at, $results);
            case 'break': case 'continue': case 'goto': case 'fallthrough':
                $tok = $this->tok;
                $this->next();
                $label = $tok !== 'fallthrough' && $this->tok === Token::IDENT ? $this->ident() : null;
                $this->semi();
                return new BranchStmt($at, $tok, $label);
            case ';':
                $this->next();
                return new EmptyStmt($at);
            case '}':
                return new EmptyStmt($at);
            case 'switch': case 'select':
                $what = $this->tok === 'switch' ? 'SwitchStmt' : 'SelectStmt';
                $this->next();
                $this->exprLev = -1;
                while ($this->tok !== '{' && $this->tok !== Token::EOF) { $this->next(); } // its header, whatever it is
                $this->exprLev = 0;
                $this->skipBraces();
                $this->semi();
                return new UnsupportedStmt($at, $what);
            case 'go': case 'defer':
                $what = $this->tok === 'go' ? 'GoStmt' : 'DeferStmt';
                $this->next();
                $this->expr();
                $this->semi();
                return new UnsupportedStmt($at, $what);
        }
        if (!in_array($this->tok, [Token::IDENT, Token::INT, Token::FLOAT, Token::IMAG, Token::CHAR, Token::STRING, '(', '[', 'struct', 'map', 'chan', 'interface', 'func', '+', '-', '!', '^', '*', '&', '<-', '~'], true)) {
            $this->fail('statement');
        }
        $s = $this->simpleStmt(true, false);
        if (!$s instanceof LabeledStmt) {
            $this->semi();
        }
        return $s;
    }

    /** An expression, an assignment, ++ or --, or with $labelOk a label; with $rangeOk the range clause of a for. */
    private function simpleStmt(bool $labelOk, bool $rangeOk): Stmt
    {
        $at = $this->pos;
        if ($rangeOk && $this->tok === 'range') { // for range x
            $this->next();
            return new RangeStmt($at, null, null, '', $this->expr(), new BlockStmt($at, [], $at));
        }
        $lhs = $this->exprList();
        switch ($this->tok) {
            case ':=': case '=':
            case '+=': case '-=': case '*=': case '/=': case '%=': case '&=': case '|=': case '^=': case '<<=': case '>>=': case '&^=':
                $tok = $this->tok;
                $this->next();
                if ($rangeOk && $this->tok === 'range' && ($tok === ':=' || $tok === '=')) {
                    $this->next();
                    if (count($lhs) > 2) {
                        throw new SyntaxError($lhs[2]->pos, 'range clause permits at most two iteration variables');
                    }
                    return new RangeStmt($at, $lhs[0], $lhs[1] ?? null, $tok, $this->expr(), new BlockStmt($at, [], $at));
                }
                return new AssignStmt($at, $lhs, $tok, $this->exprList());
            case ':':
                if ($labelOk && count($lhs) === 1 && $lhs[0] instanceof Ident) {
                    $this->next();
                    if ($this->tok === '}') {
                        return new LabeledStmt($at, $lhs[0], new EmptyStmt($this->pos));
                    }
                    return new LabeledStmt($at, $lhs[0], $this->stmt());
                }
                break;
            case '<-':
                $this->next();
                $this->expr();
                return new UnsupportedStmt($at, 'SendStmt');
            case '++': case '--':
                $tok = $this->tok;
                $this->next();
                if (count($lhs) > 1) {
                    $this->fail("1 expression");
                }
                return new IncDecStmt($at, $lhs[0], $tok);
        }
        if (count($lhs) > 1) {
            $this->fail("1 expression", $lhs[1]->pos);
        }
        return new ExprStmt($at, $lhs[0]);
    }

    private function ifStmt(): IfStmt
    {
        $at = $this->expect('if');
        [$init, $cond] = $this->header();
        $body = $this->block();
        $else = null;
        if ($this->tok === 'else') {
            $this->next();
            $else = match ($this->tok) {
                'if' => $this->ifStmt(),
                '{' => (function (): BlockStmt { $b = $this->block(); $this->semi(); return $b; })(),
                default => $this->fail('if statement or block'),
            };
            return new IfStmt($at, $init, $cond, $body, $else);
        }
        $this->semi();
        return new IfStmt($at, $init, $cond, $body, $else);
    }

    /** The init statement and the condition of an if. @return array{0: ?Stmt, 1: Expr} */
    private function header(): array
    {
        if ($this->tok === '{') {
            $this->fail('condition');
        }
        $old = $this->exprLev;
        $this->exprLev = -1;
        $init = null; $cond = null;
        if ($this->tok !== ';') {
            $init = $this->simpleStmt(false, false);
        }
        if ($this->tok === ';') {
            $semiAt = $this->pos; $newline = $this->lit === "\n";
            $this->next();
            if ($this->tok === '{') {
                $this->exprLev = $old;
                throw new SyntaxError($newline ? $semiAt : $this->pos, $newline ? 'unexpected newline, expected { after if clause' : 'missing condition in if statement');
            }
            $c = $this->simpleStmt(false, false);
            $cond = $this->condOf($c);
        } else {
            $cond = $init === null ? null : $this->condOf($init);
            $init = null;
        }
        $this->exprLev = $old;
        if ($cond === null) {
            $this->fail('condition');
        }
        return [$init, $cond];
    }

    /** The expression of a statement that is a condition. */
    private function condOf(Stmt $s): Expr
    {
        if (!$s instanceof ExprStmt) {
            throw new SyntaxError($s->pos, sprintf('cannot use %s as value', $s instanceof AssignStmt && $s->tok === ':=' ? 'short variable declaration' : 'assignment'));
        }
        return $s->x;
    }

    private function forStmt(): Stmt
    {
        $at = $this->expect('for');
        $init = $cond = $post = null;
        $range = null;
        if ($this->tok !== '{') {
            $old = $this->exprLev;
            $this->exprLev = -1;
            $s2 = null;
            if ($this->tok !== ';') {
                $s2 = $this->simpleStmt(false, true);
            }
            if ($s2 instanceof RangeStmt) {
                $range = $s2;
            } elseif ($this->tok === ';') {
                $this->next();
                $init = $s2;
                $c = null;
                if ($this->tok !== ';') {
                    $c = $this->simpleStmt(false, false);
                }
                if ($this->tok !== ';') {
                    $this->fail("';'"); // a for of three clauses has its second semicolon
                }
                $this->next();
                if ($this->tok !== '{') {
                    $post = $this->simpleStmt(false, false);
                }
                $cond = $c === null ? null : $this->condOf($c);
            } else {
                $cond = $s2 === null ? null : $this->condOf($s2);
            }
            $this->exprLev = $old;
        }
        $body = $this->block();
        $this->semi();
        if ($range !== null) {
            return new RangeStmt($at, $range->key, $range->value, $range->tok, $range->x, $body);
        }
        return new ForStmt($at, $init, $cond, $post, $body);
    }

    /** @return list<Expr> */
    private function exprList(): array
    {
        $list = [$this->expr()];
        while ($this->tok === ',') {
            $this->next();
            $list[] = $this->expr();
        }
        return $list;
    }

    private function expr(): Expr
    {
        return $this->binary(1);
    }

    private function binary(int $prec): Expr
    {
        $x = $this->unary();
        while (($p = Token::PRECEDENCE[$this->tok] ?? 0) >= $prec) {
            $op = $this->tok; $opAt = $this->pos;
            $this->next();
            $y = $this->binary($p + 1);
            $x = new BinaryExpr($x->pos, $x, $op, $y, $opAt);
        }
        return $x;
    }

    private function unary(): Expr
    {
        $at = $this->pos;
        switch ($this->tok) {
            case '+': case '-': case '!': case '^': case '&': case '~':
                $op = $this->tok;
                $this->next();
                return new UnaryExpr($at, $op, $this->unary());
            case '<-':
                $this->next();
                $this->unary();
                return new Unsupported($at, 'UnaryExpr <-');
            case '*':
                $this->next();
                $this->unary();
                return new Unsupported($at, 'StarExpr');
        }
        return $this->primary();
    }

    private function operand(): Expr
    {
        $at = $this->pos;
        switch ($this->tok) {
            case Token::IDENT:
                return $this->ident();
            case Token::INT: case Token::FLOAT: case Token::IMAG: case Token::CHAR: case Token::STRING:
                $x = new BasicLit($at, $this->tok, $this->lit);
                $this->next();
                return $x;
            case '(':
                $this->next();
                $this->exprLev++;
                $x = $this->exprOrType();
                $this->exprLev--;
                $this->expect(')');
                return new ParenExpr($at, $x);
            case 'func':
                $this->next();
                $this->signature($at);
                if ($this->tok === '{') {
                    $this->exprLev++;
                    $this->block();
                    $this->exprLev--;
                    return new Unsupported($at, 'FuncLit');
                }
                return new Unsupported($at, 'FuncType');
            case '[': case 'struct': case 'map': case 'chan': case 'interface': case '*':
                return $this->type();
        }
        $this->fail('operand');
    }

    /** An expression, or a type in parentheses, as (*T) or ([2]vec4). */
    private function exprOrType(): Expr
    {
        return $this->expr();
    }

    private function primary(): Expr
    {
        $x = $this->operand();
        while (true) {
            switch ($this->tok) {
                case '.':
                    $this->next();
                    if ($this->tok === Token::IDENT) {
                        $x = new SelectorExpr($x->pos, $x, $this->ident());
                    } elseif ($this->tok === '(') { // a type assertion
                        $this->next();
                        if ($this->tok === 'type') { $this->next(); } else { $this->type(); }
                        $this->expect(')');
                        $x = new Unsupported($x->pos, 'TypeAssertExpr');
                    } else {
                        $this->fail('selector or type assertion');
                    }
                    break;
                case '[':
                    $this->next();
                    $this->exprLev++;
                    $index = [null, null, null]; $colons = 0;
                    if ($this->tok !== ':') {
                        $index[0] = $this->exprOrType();
                    }
                    $more = false;
                    while ($this->tok === ':' && $colons < 2) {
                        $colons++;
                        $this->next();
                        if ($this->tok !== ':' && $this->tok !== ']') {
                            $index[$colons] = $this->expr();
                        }
                    }
                    while ($colons === 0 && $this->tok === ',') { // instantiation of a generic
                        $more = true;
                        $this->next();
                        if ($this->tok !== ']') { $this->exprOrType(); }
                    }
                    $this->exprLev--;
                    $this->expect(']');
                    if ($colons > 0) {
                        $x = new Unsupported($x->pos, 'SliceExpr');
                    } elseif ($more) {
                        $x = new Unsupported($x->pos, 'IndexListExpr');
                    } elseif ($index[0] === null) {
                        $this->fail('operand');
                    } else {
                        $x = new IndexExpr($x->pos, $x, $index[0]);
                    }
                    break;
                case '(':
                    $this->next();
                    $this->exprLev++;
                    $args = []; $ellipsis = -1;
                    while ($this->tok !== ')' && $this->tok !== Token::EOF) {
                        $args[] = $this->exprOrType();
                        if ($this->tok === '...') {
                            $ellipsis = $this->pos;
                            $this->next();
                        }
                        if ($this->tok !== ',') {
                            if ($this->tok !== ')') {
                                throw new SyntaxError($this->pos, "missing ',' in argument list");
                            }
                            break;
                        }
                        $this->next();
                    }
                    $this->exprLev--;
                    $rparen = $this->pos;
                    if ($this->tok !== ')') {
                        $this->fail("')'");
                    }
                    $this->next();
                    $x = new CallExpr($x->pos, $x, $args, $ellipsis, $rparen);
                    break;
                case '{':
                    if ($this->literalType($x) && ($this->exprLev >= 0 || !($x instanceof Ident || $x instanceof SelectorExpr))) {
                        $x = $this->compositeLit($x);
                        break;
                    }
                    return $x;
                default:
                    return $x;
            }
        }
    }

    /** Whether $x can be the type of a composite literal. */
    private function literalType(Expr $x): bool
    {
        return $x instanceof Ident || $x instanceof ArrayType || $x instanceof StructType
            || $x instanceof SelectorExpr && $x->x instanceof Ident
            || $x instanceof Unsupported && $x->what === 'MapType';
    }

    private function compositeLit(?Expr $type): CompositeLit
    {
        $at = $type === null ? $this->pos : $type->pos;
        $this->expect('{');
        $this->exprLev++;
        $elts = [];
        while ($this->tok !== '}' && $this->tok !== Token::EOF) {
            $e = $this->element();
            if ($this->tok === ':') {
                $this->next();
                $e = new KeyValueExpr($e->pos, $e, $this->element());
            }
            $elts[] = $e;
            if ($this->tok !== ',') {
                break;
            }
            $this->next();
        }
        $this->exprLev--;
        if ($this->tok !== '}') {
            $this->fail("'}'"); // as Go says when a newline follows the last element without a comma
        }
        $this->next();
        return new CompositeLit($at, $type, $elts);
    }

    /** An element of a composite literal: an expression, or a literal of the elided type, {...}. */
    private function element(): Expr
    {
        if ($this->tok === '{') {
            return $this->compositeLit(null);
        }
        return $this->expr();
    }
}
