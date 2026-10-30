<?php
// Copyright 2020 The Ebiten Authors
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.

// Ported to PHP from MyGo's internal/shader (expr.go), MyGo's fork of
// Ebitengine's (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Compiler;

use MyPHP\UI\Shader\Ir\{BasicType, BuiltinFunc, Check, Constant, Expr, ExprType, Op, Stmt, StmtType, SwizzlingSet, Type};
use MyPHP\UI\Shader\Syntax;

/** The compiler's expressions (expr.go). @internal */
trait Exprs
{
    private static function canTruncateToInteger(Constant $v): bool
    {
        return Constant::toInt($v)->kind !== Constant::UNKNOWN;
    }

    private static function canTruncateToFloat(Constant $v): bool
    {
        return Constant::toFloat($v)->kind !== Constant::UNKNOWN;
    }

    /** A phrase naming the $i-th of the $n arguments to the function $fn in an error message. */
    private static function argumentName(string $fn, int $i, int $n): string
    {
        return $n > 1 ? sprintf('the %s argument to %s', self::ordinal($i + 1), $fn) : "argument to $fn";
    }

    /** Where the first argument after the $n expected ones is, or the closing parenthesis of $e if there are no more than $n. @param list<int> $argPositions */
    private static function argCountErrorPos(Syntax\CallExpr $e, array $argPositions, int $n): int
    {
        return $argPositions[$n] ?? $e->rparen;
    }

    /**
     * The constant shift count $c of the operator $op, checked: below
     * 1 << 16, so that folding cannot make an enormous number.
     */
    private function shiftCount(int $pos, string $op, Constant $c): int
    {
        if ($c->kind !== Constant::INT) {
            $this->fail($pos, "unexpected $c type for: $op");
        }
        if (Constant::sign($c) < 0) {
            $this->fail($pos, "negative shift count: $c");
        }
        [$shift, $ok] = Constant::int64Val($c);
        if (!$ok || $shift > 1 << 16) {
            $this->fail($pos, "shift count too large: $c");
        }
        return $shift;
    }

    /**
     * $node as Go's fmt prints a syntax node with %v or %#v in an error:
     * an identifier's name; another node's kind, where Go prints its
     * fields and the addresses of the nodes in them.
     */
    private static function nodeString(Syntax\Expr|Syntax\Stmt $node): string
    {
        if ($node instanceof Syntax\Ident) {
            return $node->name;
        }
        $kind = $node instanceof Syntax\Unsupported || $node instanceof Syntax\UnsupportedStmt ? $node->what : (new \ReflectionClass($node))->getShortName();
        return "&ast.$kind{...}";
    }

    /** The operator of the token $t of operands typed $lhs and $rhs (shaderir's OpFromToken), or null. */
    private static function opFromToken(string $t, Type $lhs, Type $rhs): ?int
    {
        $vector = $lhs->isFloatVector() || $lhs->isIntegerVector() || $rhs->isFloatVector() || $rhs->isIntegerVector();
        return match ($t) {
            '+' => Op::ADD,
            '-' => Op::SUB,
            '!' => Op::NOT_OP,
            '*' => $lhs->isMatrix() || $rhs->isMatrix() ? Op::MATRIX_MUL : Op::COMPONENT_WISE_MUL,
            // /= is an integer division, as go/constant's QUO_ASSIGN.
            '/', '/=' => Op::DIV,
            '%' => Op::MOD_OP,
            '<<' => Op::LEFT_SHIFT,
            '>>' => Op::RIGHT_SHIFT,
            '<' => Op::LESS_THAN_OP,
            '<=' => Op::LESS_THAN_EQUAL_OP,
            '>' => Op::GREATER_THAN_OP,
            '>=' => Op::GREATER_THAN_EQUAL_OP,
            '==' => $vector ? Op::VECTOR_EQUAL_OP : Op::EQUAL_OP,
            '!=' => $vector ? Op::VECTOR_NOT_EQUAL_OP : Op::NOT_EQUAL_OP,
            '&' => Op::AND,
            '&^' => Op::AND_NOT,
            '^' => Op::XOR,
            '|' => Op::OR,
            '&&' => Op::AND_AND,
            '||' => Op::OR_OR,
            default => null,
        };
    }

    /** @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>} */
    private function parseExpr(Block $block, string $fname, Syntax\Expr $expr, bool $markLocalVariableUsed): array
    {
        return match (true) {
            $expr instanceof Syntax\BasicLit => $this->parseBasicLit($expr),
            $expr instanceof Syntax\BinaryExpr => $this->parseBinaryExpr($block, $fname, $expr, $markLocalVariableUsed),
            $expr instanceof Syntax\CallExpr => $this->parseCallExpr($block, $fname, $expr, $markLocalVariableUsed),
            $expr instanceof Syntax\Ident => $this->parseIdentExpr($block, $expr, $markLocalVariableUsed),
            $expr instanceof Syntax\ParenExpr => $this->parseExpr($block, $fname, $expr->x, $markLocalVariableUsed),
            $expr instanceof Syntax\SelectorExpr => $this->parseSelectorExpr($block, $fname, $expr),
            $expr instanceof Syntax\UnaryExpr => $this->parseUnaryExpr($block, $fname, $expr, $markLocalVariableUsed),
            $expr instanceof Syntax\CompositeLit => $this->parseCompositeLit($block, $fname, $expr, $markLocalVariableUsed),
            $expr instanceof Syntax\IndexExpr => $this->parseIndexExpr($block, $fname, $expr, $markLocalVariableUsed),
            default => $this->fail($expr->pos, 'expression not implemented: ' . self::nodeString($expr)),
        };
    }

    /** @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>} */
    private function parseBasicLit(Syntax\BasicLit $e): array
    {
        if ($e->kind === Syntax\Token::INT || $e->kind === Syntax\Token::FLOAT) {
            // The type is not determined yet.
            return [[new Expr(ExprType::NUMBER_EXPR, const: Constant::fromLiteral($e->value, $e->kind))], [new Type], []];
        }
        // As Go's %#v prints it: the position from 1, and the token's number.
        $kind = [Syntax\Token::IMAG => 7, Syntax\Token::CHAR => 8, Syntax\Token::STRING => 9][$e->kind];
        $this->fail($e->pos, sprintf('literal not implemented: &ast.BasicLit{ValuePos:%d, Kind:%d, Value:%s}', $e->pos + 1, $kind, self::goQuote($e->value)));
    }

    /** $s quoted as Go's %#v quotes a string. */
    private static function goQuote(string $s): string
    {
        return '"' . preg_replace_callback('/[\x00-\x1f"\\\\\x7f]/', fn(array $m): string => match ($m[0]) {
            '"' => '\\"', '\\' => '\\\\', "\n" => '\\n', "\t" => '\\t', "\r" => '\\r',
            default => sprintf('\\x%02x', ord($m[0])),
        }, $s) . '"';
    }

    /** @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>} */
    private function parseBinaryExpr(Block $block, string $fname, Syntax\BinaryExpr $e, bool $markLocalVariableUsed): array
    {
        // Parse LHS first for the order of the statements.
        [$lhs, $ts, $ss] = $this->parseExpr($block, $fname, $e->x, $markLocalVariableUsed);
        if (count($lhs) !== 1) {
            $this->fail($e->pos, 'multiple-value context is not available at a binary operator: ' . self::nodeString($e->x));
        }
        $stmts = $ss;
        if ($ts === []) {
            $this->fail($e->pos, 'unexpected binary operator: ' . self::nodeString($e->x));
        }
        $lhst = $ts[0];

        [$rhs, $ts, $ss] = $this->parseExpr($block, $fname, $e->y, $markLocalVariableUsed);
        if (count($rhs) !== 1) {
            $this->fail($e->pos, 'multiple-value context is not available at a binary operator: ' . self::nodeString($e->y));
        }
        array_push($stmts, ...$ss);
        if ($ts === []) {
            $this->fail($e->pos, 'unexpected binary operator: ' . self::nodeString($e->y));
        }
        $rhst = $ts[0];
        $l = $lhs[0]; $r = $rhs[0];

        if ($l->type === ExprType::BLANK || $r->type === ExprType::BLANK) {
            $this->fail($e->pos, 'cannot use _ as value');
        }

        // To force integer division of Int operands, go/constant takes /= for /: the result is an Int.
        $op = $e->op;
        if ($op === '/' && $l->const?->kind === Constant::INT && $r->const?->kind === Constant::INT) {
            $op = '/=';
        }

        $op2 = self::opFromToken($e->op, $lhst, $rhst) ?? $this->fail($e->pos, "unexpected operator: {$e->op}");

        // Resolve untyped constants.
        $resolved = Check::resolveUntypedConstsForBinaryOp($op2, $l->const, $r->const, $lhst, $rhst)
            ?? $this->fail($e->pos, sprintf("types don't match: %s %s %s", self::typeString($lhst, $l->const), $op, self::typeString($rhst, $r->const)));
        [$l->const, $r->const] = $resolved;

        // A shift of an untyped constant by a count that is not one is
        // untyped (Expr's untyped), as are operations of such shifts and
        // untyped constants: their type comes from their context.
        $untypedInt = fn(Expr $x, Type $t): bool => $x->untyped || $x->const !== null && $t->main === BasicType::NONE && $x->const->kind === Constant::INT;
        $shift = $op2 === Op::LEFT_SHIFT || $op2 === Op::RIGHT_SHIFT;
        $untyped = match ($op2) {
            Op::LEFT_SHIFT, Op::RIGHT_SHIFT => $untypedInt($l, $lhst),
            Op::ADD, Op::SUB, Op::COMPONENT_WISE_MUL, Op::DIV, Op::MOD_OP, Op::AND, Op::AND_NOT, Op::OR, Op::XOR => $untypedInt($l, $lhst) && $untypedInt($r, $rhst),
            default => false,
        };
        // An untyped shift takes the type of a uint it goes with.
        if ($l->untyped && !$shift && $rhst->scalar() === BasicType::UINT) {
            $lhst = new Type(BasicType::UINT);
        }
        if ($r->untyped && !$shift && $lhst->scalar() === BasicType::UINT) {
            $rhst = new Type(BasicType::UINT);
        }

        // If either is typed, resolve the other type.
        // If both are untyped, keep them untyped.
        if ($lhst->main !== BasicType::NONE || $rhst->main !== BasicType::NONE) {
            // An integer constant takes the other operand's kind of integer, or int
            // for a shift's count.
            $lkind = $rhst->scalar() === BasicType::UINT && !$shift ? BasicType::UINT : BasicType::INT;
            $rkind = $lhst->scalar() === BasicType::UINT && !$shift ? BasicType::UINT : BasicType::INT;
            if ($l->const !== null && $lhst->main === BasicType::NONE) {
                $lhst = self::constType($l->const, $lkind) ?? $lhst;
            }
            if ($r->const !== null && $rhst->main === BasicType::NONE) {
                $rhst = self::constType($r->const, $rkind) ?? $rhst;
            }
        }

        $t = Check::typeFromBinaryOp($op2, $lhst, $rhst, $l->const, $r->const)
            ?? $this->fail($e->pos, sprintf("types don't match: %s %s %s", self::typeString($lhst, $l->const), $op, self::typeString($rhst, $r->const)));

        // A constant zero divisor is an error even when the dividend is not a constant.
        if (($op === '/' || $op === '/=' || $op === '%') && self::isConstZero($r->const)) {
            $this->fail($e->y->pos, 'division by zero');
        }

        if ($l->const !== null && $r->const !== null) {
            $v = match ($op) {
                '&&', '||' => Constant::bool(Constant::boolVal(Constant::binaryOp($l->const, $op, $r->const))),
                '==', '!=', '<', '<=', '>', '>=' => Constant::bool(Constant::compare($l->const, $op, $r->const)),
                '<<', '>>' => Constant::shift($l->const, $op, $this->shiftCount($e->pos, $e->op, $r->const)),
                default => Constant::binaryOp($l->const, $op, $r->const),
            };
            return [[new Expr(ExprType::NUMBER_EXPR, const: $v, unsigned: $t->main === BasicType::UINT)], [$t], $stmts];
        }

        if (($e->op === '<<' || $e->op === '>>') && $r->const !== null) {
            $this->shiftCount($e->pos, $e->op, $r->const);
        }

        return [[new Expr(ExprType::BINARY, [$l, $r], op: $op2, untyped: $untyped)], [$t], $stmts];
    }

    /** The type an untyped constant $c takes beside a typed operand: float, bool, or $intKind for an integer; null for another kind. */
    private static function constType(Constant $c, int $intKind): ?Type
    {
        return match ($c->kind) {
            Constant::FLOAT => new Type(BasicType::FLOAT),
            Constant::INT => new Type($intKind),
            Constant::BOOL => new Type(BasicType::BOOL),
            default => null,
        };
    }

    /** @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>} */
    private function parseCallExpr(Block $block, string $fname, Syntax\CallExpr $e, bool $markLocalVariableUsed): array
    {
        $args = []; $argts = []; $argPositions = []; $stmts = [];

        // Parse the argument first for the order of the statements.
        foreach ($e->args as $a) {
            [$es, $ts, $ss] = $this->parseExpr($block, $fname, $a, $markLocalVariableUsed);
            if ($es === []) {
                $this->fail($a->pos, 'cannot use an expression with no value as an argument');
            }
            if (count($es) > 1 && count($e->args) > 1) {
                $this->fail($a->pos, 'single-value context and multiple-value context cannot be mixed: ' . self::nodeString($e->fun));
            }
            foreach ($es as $x) {
                if ($x->type === ExprType::BLANK) {
                    $this->fail($a->pos, 'cannot use _ as value');
                }
                $argPositions[] = $a->pos;
            }
            array_push($args, ...$es);
            array_push($argts, ...$ts);
            array_push($stmts, ...$ss);
        }

        $callee = $this->parseCallee($block, $e->fun);

        // For built-in functions, we can call this in this position. Return an expression for the function
        // call.
        if ($callee->type === ExprType::BUILTIN_FUNC_EXPR) {
            return $this->parseBuiltinCall($fname, $e, $callee, $args, $argts, $argPositions, $stmts);
        }

        $f = $this->funcs[$callee->index];
        $nparams = count($f->ir->inParams);
        if ($nparams < count($args)) {
            $this->fail(self::argCountErrorPos($e, $argPositions, $nparams), 'too many arguments in call to ' . self::nodeString($e->fun));
        }
        if ($nparams > count($args)) {
            $this->fail(self::argCountErrorPos($e, $argPositions, $nparams), 'not enough arguments in call to ' . self::nodeString($e->fun));
        }

        foreach ($f->ir->inParams as $i => $p) {
            if (!self::canAssign($p, $argts[$i], $args[$i])) {
                $this->fail($argPositions[$i], sprintf('cannot use type %s as type %s in %s', self::typeString($argts[$i], $args[$i]->const), $p->string(), self::argumentName($f->name, $i, count($args))));
            }
            if ($args[$i]->const !== null) {
                switch ($p->main) {
                    case BasicType::INT: case BasicType::UINT:
                        $args[$i]->const = Constant::toInt($args[$i]->const);
                        $argts[$i] = new Type($p->main);
                        break;
                    case BasicType::FLOAT:
                        $args[$i]->const = Constant::toFloat($args[$i]->const);
                        $argts[$i] = new Type(BasicType::FLOAT);
                        break;
                }
            }
        }

        $outParams = [];
        foreach ($f->ir->outParams as $p) {
            $idx = $block->totalLocalVariableCount();
            $block->vars[] = new Variable('', $p);
            $args[] = new Expr(ExprType::LOCAL_VARIABLE, index: $idx);
            $outParams[] = $idx;
        }

        $call = new Expr(ExprType::CALL, [$callee, ...$args]);
        if ($f->ir->return->main !== BasicType::NONE) {
            if ($outParams !== []) {
                $this->fail($e->pos, 'a function returning value cannot have out-params so far: ' . self::nodeString($e->fun));
            }
            // The actual expression here is just a local variable that includes the result of the
            // function call.
            return [[$call], [$f->ir->return], $stmts];
        }

        // Even if the function doesn't return anything, calling the function should be done earlier to keep
        // the evaluation order.
        $stmts[] = new Stmt(StmtType::EXPR_STMT, [$call]);

        // These local-variable expressions are used by callers of an outside function.
        $exprs = array_map(fn(int $p): Expr => new Expr(ExprType::LOCAL_VARIABLE, index: $p), $outParams);
        return [$exprs, $f->ir->outParams, $stmts];
    }

    /**
     * A call of the built-in function $callee: a constant for those that
     * compile-time evaluates, an error for wrong arguments.
     * @param list<Expr> $args
     * @param list<Type> $argts
     * @param list<int> $argPositions
     * @param list<Stmt> $stmts
     * @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>}
     */
    private function parseBuiltinCall(string $fname, Syntax\CallExpr $e, Expr $callee, array $args, array $argts, array $argPositions, array $stmts): array
    {
        $fn = $callee->builtinFunc;
        $n = count($args);
        // Process compile-time evaluations.
        switch ($fn) {
            case BuiltinFunc::LEN:
            case BuiltinFunc::CAP:
                if ($n !== 1) {
                    $this->fail(self::argCountErrorPos($e, $argPositions, 1), "number of $fn's arguments must be 1 but $n");
                }
                if ($argts[0]->main !== BasicType::ARRAY) {
                    $this->fail($argPositions[0], sprintf('%s takes an array but %s', $fn, self::typeString($argts[0], $args[0]->const)));
                }
                return [[new Expr(ExprType::NUMBER_EXPR, const: Constant::int($argts[0]->length))], [new Type(BasicType::INT)], $stmts];
            case BuiltinFunc::BOOL_F:
                if ($n === 1 && $args[0]->const !== null) {
                    if ($args[0]->const->kind !== Constant::BOOL) {
                        $this->fail($argPositions[0], "cannot convert {$args[0]->const} to type bool");
                    }
                    return [[new Expr(ExprType::NUMBER_EXPR, const: $args[0]->const)], [new Type(BasicType::BOOL)], $stmts];
                }
                break;
            case BuiltinFunc::INT_F:
                if ($n === 1 && $args[0]->const !== null) {
                    // For constants, a cast-like function doesn't work as a cast.
                    // For example, `int(1.1)` is invalid.
                    $v = Constant::toInt($args[0]->const);
                    if ($v->kind === Constant::UNKNOWN) {
                        $this->fail($argPositions[0], "cannot convert {$args[0]->const} to type int");
                    }
                    return [[new Expr(ExprType::NUMBER_EXPR, const: $v)], [new Type(BasicType::INT)], $stmts];
                }
                break;
            case BuiltinFunc::UINT_F:
                if ($n === 1 && $args[0]->const !== null) {
                    $v = Constant::toInt($args[0]->const);
                    if ($v->kind === Constant::UNKNOWN || Constant::sign($v) < 0) {
                        $this->fail($argPositions[0], "cannot convert {$args[0]->const} to type uint");
                    }
                    return [[new Expr(ExprType::NUMBER_EXPR, const: $v, unsigned: true)], [new Type(BasicType::UINT)], $stmts];
                }
                break;
            case BuiltinFunc::FLOAT_F:
                if ($n === 1 && $args[0]->const !== null) {
                    $v = Constant::toFloat($args[0]->const);
                    if ($v->kind === Constant::UNKNOWN) {
                        $this->fail($argPositions[0], "cannot convert {$args[0]->const} to type float");
                    }
                    return [[new Expr(ExprType::NUMBER_EXPR, const: $v)], [new Type(BasicType::FLOAT)], $stmts];
                }
                break;
        }

        // The constants among the arguments as floats.
        $toFloats = function () use (&$args, &$argts): void {
            foreach ($args as $i => $a) {
                if ($a->const !== null) {
                    $a->const = Constant::toFloat($a->const);
                    $argts[$i] = new Type(BasicType::FLOAT);
                }
            }
        };

        // Process the expression as a regular function call.
        switch ($fn) {
            case BuiltinFunc::BOOL_F:
            case BuiltinFunc::INT_F:
            case BuiltinFunc::UINT_F:
            case BuiltinFunc::FLOAT_F:
                $err = self::checkArgsForScalarBuiltinFunc($fn, $args, $argts);
                if ($err !== null) {
                    $this->fail($e->pos, $err);
                }
                $finalType = new Type(match ($fn) { BuiltinFunc::BOOL_F => BasicType::BOOL, BuiltinFunc::INT_F => BasicType::INT, BuiltinFunc::UINT_F => BasicType::UINT, default => BasicType::FLOAT });
                break;
            case BuiltinFunc::UVEC2_F:
            case BuiltinFunc::UVEC3_F:
            case BuiltinFunc::UVEC4_F:
                $vn = (int)substr($fn, 4);
                $err = self::checkArgsForUVecBuiltinFunc($vn, $args, $argts);
                if ($err !== null) {
                    $this->fail($e->pos, $err);
                }
                foreach ($args as $i => $a) {
                    if ($a->const !== null && $argts[$i]->main === BasicType::NONE) {
                        $a->const = Constant::toInt($a->const);
                        $argts[$i] = new Type(BasicType::UINT);
                    }
                }
                $finalType = new Type(Type::vectorOf(BasicType::UINT, $vn));
                break;
            case BuiltinFunc::VEC2_F:
            case BuiltinFunc::VEC3_F:
            case BuiltinFunc::VEC4_F:
                $vn = (int)substr($fn, 3);
                $err = self::checkArgsForVecBuiltinFunc($vn, false, $args, $argts);
                if ($err !== null) {
                    $this->fail($e->pos, $err);
                }
                $toFloats();
                $finalType = new Type(Type::vectorOf(BasicType::FLOAT, $vn));
                break;
            case BuiltinFunc::IVEC2_F:
            case BuiltinFunc::IVEC3_F:
            case BuiltinFunc::IVEC4_F:
                $vn = (int)substr($fn, 4);
                $err = self::checkArgsForVecBuiltinFunc($vn, true, $args, $argts);
                if ($err !== null) {
                    $this->fail($e->pos, $err);
                }
                $finalType = new Type(Type::vectorOf(BasicType::INT, $vn));
                break;
            case BuiltinFunc::MAT2_F:
            case BuiltinFunc::MAT3_F:
            case BuiltinFunc::MAT4_F:
                $mn = (int)substr($fn, 3);
                $mat = BasicType::MAT2 + $mn - 2;
                $err = self::checkArgsForMatBuiltinFunc($mn, $args, $argts);
                if ($err !== null) {
                    $this->fail($e->pos, $err);
                }
                if ($n === 1 && $argts[0]->main === $mat) {
                    // A matrix constructor with a matrix argument is a copy. Return the argument itself
                    // so that the backends see a single argument only for a scalar.
                    return [$args, $argts, $stmts];
                }
                $toFloats();
                $finalType = new Type($mat);
                break;
            case BuiltinFunc::TEXEL_AT:
                if ($n !== 2) {
                    $this->fail(self::argCountErrorPos($e, $argPositions, 2), "number of $fn's arguments must be 2 but $n");
                }
                if ($argts[0]->main !== BasicType::TEXTURE) {
                    $this->fail($argPositions[0], sprintf('cannot use %s as texture value in %s', $argts[0]->string(), self::argumentName($fn, 0, $n)));
                }
                if ($argts[1]->main !== BasicType::VEC2) {
                    $this->fail($argPositions[1], sprintf('cannot use %s as vec2 value in %s', $argts[1]->string(), self::argumentName($fn, 1, $n)));
                }
                $finalType = new Type(BasicType::VEC4);
                break;
            case BuiltinFunc::FRONT_FACING:
            case BuiltinFunc::DISCARD_F:
                if ($n !== 0) {
                    $this->fail(self::argCountErrorPos($e, $argPositions, 0), "number of $fn's arguments must be 0 but $n");
                }
                if ($fname !== $this->fragmentEntry) {
                    $this->fail($e->pos, "$fn is available only in {$this->fragmentEntry}");
                }
                if ($fn === BuiltinFunc::DISCARD_F) {
                    $stmts[] = new Stmt(StmtType::DISCARD);
                    return [[], [], $stmts];
                }
                $finalType = new Type(BasicType::BOOL);
                break;
            case BuiltinFunc::CLAMP:
            case BuiltinFunc::MIX:
            case BuiltinFunc::SMOOTHSTEP:
            case BuiltinFunc::FACEFORWARD:
            case BuiltinFunc::REFRACT:
                $finalType = $this->checkArgsFor3($fn, $e, $args, $argts, $argPositions);
                break;
            case BuiltinFunc::MIN:
            case BuiltinFunc::MAX:
                $finalType = $this->checkArgsForMinMax($fn, $e, $args, $argts, $argPositions);
                break;
            case BuiltinFunc::ATAN2:
            case BuiltinFunc::POW:
            case BuiltinFunc::MOD:
            case BuiltinFunc::STEP:
            case BuiltinFunc::DISTANCE:
            case BuiltinFunc::DOT:
            case BuiltinFunc::CROSS:
            case BuiltinFunc::REFLECT:
                $finalType = $this->checkArgsFor2($fn, $e, $args, $argts, $argPositions);
                break;
            default:
                $finalType = $this->checkArgsFor1($fn, $e, $args, $argts, $argPositions);
        }
        return [[new Expr(ExprType::CALL, [$callee, ...$args])], [$finalType], $stmts];
    }

    /**
     * Checks the 3 arguments of clamp, mix, smoothstep, faceforward or
     * refract, typing their constants, and returns the call's type.
     * @param list<Expr> $args
     * @param list<Type> $argts
     * @param list<int> $argPositions
     */
    private function checkArgsFor3(string $fn, Syntax\CallExpr $e, array $args, array $argts, array $argPositions): Type
    {
        if (count($args) !== 3) {
            $this->fail(self::argCountErrorPos($e, $argPositions, 3), sprintf("number of %s's arguments must be 3 but %d", $fn, count($args)));
        }
        if ($fn === BuiltinFunc::CLAMP) {
            $kind = self::resolveConstKind($args, $argts);
            if ($kind !== Constant::UNKNOWN) {
                $mismatch = fn(int $i, array $argts): string => sprintf("%s's arguments don't match: %s, %s, and %s", $fn, $argts[0]->string(), $argts[1]->string(), $argts[2]->string());
                $this->resolveConsts($kind, $args, $argts, $argPositions, $mismatch);
            }
            if ($argts[0]->isIntegerVector() || $argts[0]->isFloatVector()) {
                $this->convertConstsTo($argts[0]->isFloatVector() ? BasicType::FLOAT : $argts[0]->scalar(), $args, $argts, $argPositions, 1);
            }
        } else {
            foreach ($args as $i => $a) {
                // If the argument is a non-typed constant value, treat this as a float value (#1874).
                if ($a->const !== null && $argts[$i]->main === BasicType::NONE && self::canTruncateToFloat($a->const)) {
                    $a->const = Constant::toFloat($a->const);
                    $argts[$i] = new Type(BasicType::FLOAT);
                }
            }
        }
        foreach ($args as $i => $_) {
            if ($fn === BuiltinFunc::CLAMP) {
                if ($argts[$i]->main !== BasicType::FLOAT && !$argts[$i]->isFloatVector() && !$argts[$i]->isInteger()) {
                    $this->fail($argPositions[$i], sprintf('cannot use %s as float, vecN, int, or ivecN value in %s', $argts[$i]->string(), self::argumentName($fn, $i, 3)));
                }
            } elseif ($argts[$i]->main !== BasicType::FLOAT && !$argts[$i]->isFloatVector()) {
                $this->fail($argPositions[$i], sprintf('cannot use %s as float, vec2, vec3, or vec4 value in %s', $argts[$i]->string(), self::argumentName($fn, $i, 3)));
            }
        }
        [$t0, $t1, $t2] = $argts;
        switch ($fn) {
            case BuiltinFunc::CLAMP:
                if (!(($t0->equal($t1) && $t0->equal($t2))
                    || ($t0->isFloatVector() && $t1->main === BasicType::FLOAT && $t2->main === BasicType::FLOAT)
                    || ($t0->isIntVector() && $t1->main === BasicType::INT && $t2->main === BasicType::INT)
                    || ($t0->isUintVector() && $t1->main === BasicType::UINT && $t2->main === BasicType::UINT))) {
                    $this->fail($e->pos, sprintf('the second and the third arguments for %s must equal the first argument %s or float or int but %s and %s', $fn, $t0->string(), $t1->string(), $t2->string()));
                }
                break;
            case BuiltinFunc::MIX:
                if (!$t0->equal($t1)) {
                    $this->fail($argPositions[1], sprintf("%s and %s don't match in argument to %s", $t0->string(), $t1->string(), $fn));
                }
                if (!$t0->equal($t2) && $t2->main !== BasicType::FLOAT) {
                    $this->fail($argPositions[2], sprintf('the third argument for %s must equal the first/second argument %s or float but %s', $fn, $t0->string(), $t2->string()));
                }
                break;
            case BuiltinFunc::SMOOTHSTEP:
                if ((!$t0->equal($t1) || !$t0->equal($t2)) && ($t0->main !== BasicType::FLOAT || $t1->main !== BasicType::FLOAT)) {
                    $this->fail($e->pos, sprintf('the first and the second arguments for %s must equal the third argument %s or float but %s and %s', $fn, $t2->string(), $t0->string(), $t1->string()));
                }
                break;
            case BuiltinFunc::REFRACT:
                if (!$t0->equal($t1)) {
                    $this->fail($argPositions[1], sprintf("%s and %s don't match in argument to %s", $t0->string(), $t1->string(), $fn));
                }
                if ($t2->main !== BasicType::FLOAT) {
                    $this->fail($argPositions[2], sprintf('cannot use %s as float value in %s', $t2->string(), self::argumentName($fn, 2, 3)));
                }
                break;
            default:
                if (!$t0->equal($t1) || !$t0->equal($t2)) {
                    $this->fail($t0->equal($t1) ? $argPositions[2] : $argPositions[1], sprintf('all the argument types for %s must be the same but %s, %s, and %s', $fn, $t0->string(), $t1->string(), $t2->string()));
                }
        }
        return $fn === BuiltinFunc::SMOOTHSTEP ? $t2 : $t0;
    }

    /**
     * Types the constants among $args as $kind, Int or Float, which the
     * typed ones must be: int or uint for Int, as the typed ones are
     * (integerKind), float for Float. $mismatch is the error of a typed
     * argument at $i that isn't, given the types as they are then.
     * @param list<Expr> $args
     * @param list<Type> $argts
     * @param list<int> $argPositions
     * @param \Closure(int, list<Type>): string $mismatch
     */
    private function resolveConsts(int $kind, array $args, array &$argts, array $argPositions, \Closure $mismatch): void
    {
        $main = $kind === Constant::INT ? self::integerKind($args, $argts) : BasicType::FLOAT;
        foreach ($args as $i => $a) {
            if ($a->const === null) {
                if ($argts[$i]->main !== $main) {
                    $this->fail($argPositions[$i], $mismatch($i, $argts));
                }
                continue;
            }
            $v = $kind === Constant::INT ? Constant::toInt($a->const) : Constant::toFloat($a->const);
            if ($v->kind === Constant::UNKNOWN) {
                $this->fail($argPositions[$i], sprintf('cannot convert %s to type %s', $a->const, $kind === Constant::INT ? 'int' : 'float'));
            }
            $a->const = $v;
            $argts[$i] = new Type($main);
        }
    }

    /**
     * Converts the constants among $args from $from on to $scalar, float,
     * int or uint, the component of the vector they go with.
     * @param list<Expr> $args
     * @param list<Type> $argts
     * @param list<int> $argPositions
     */
    private function convertConstsTo(int $scalar, array $args, array &$argts, array $argPositions, int $from): void
    {
        for ($i = $from, $n = count($args); $i < $n; $i++) {
            if ($args[$i]->const === null) {
                continue;
            }
            $v = $scalar === BasicType::FLOAT ? Constant::toFloat($args[$i]->const) : Constant::toInt($args[$i]->const);
            if ($v->kind === Constant::UNKNOWN) {
                $this->fail($argPositions[$i], sprintf('cannot convert %s to type %s', $args[$i]->const, $scalar === BasicType::FLOAT ? 'float' : 'int'));
            }
            $args[$i]->const = $v;
            $argts[$i] = new Type($scalar);
        }
    }

    /**
     * Checks the arguments of min or max, typing their constants, and
     * returns the call's type.
     * @param list<Expr> $args
     * @param list<Type> $argts
     * @param list<int> $argPositions
     */
    private function checkArgsForMinMax(string $fn, Syntax\CallExpr $e, array $args, array $argts, array $argPositions): Type
    {
        $n = count($args);
        if ($n < 1) {
            $this->fail(self::argCountErrorPos($e, $argPositions, 1), "number of $fn's arguments must be 1 or more but $n");
        }

        $hasVectors = false; $hasScalars = false;
        foreach ($argts as $t) {
            if ($t->isFloatVector() || $t->isIntegerVector()) {
                $hasVectors = true;
            } elseif (in_array($t->main, [BasicType::FLOAT, BasicType::INT, BasicType::UINT, BasicType::NONE], true)) {
                $hasScalars = true;
            }
        }

        // Resolve the constant values.
        if ($hasVectors && $hasScalars) {
            // Vector values and scalar values are mixed. Resolve the constant kind based on the first vector argument.
            if (!$argts[0]->isIntegerVector() && !$argts[0]->isFloatVector()) {
                $this->fail($argPositions[0], sprintf('cannot use %s as vecN or ivecN value in %s', $argts[0]->string(), self::argumentName($fn, 0, $n)));
            }
            $this->convertConstsTo($argts[0]->isFloatVector() ? BasicType::FLOAT : $argts[0]->scalar(), $args, $argts, $argPositions, 1);
        } else {
            // Vector values and scalar values are not mixed. Resolve the constant kind based on all the arguments.
            $kind = self::resolveConstKind($args, $argts);
            if ($kind !== Constant::UNKNOWN) {
                $this->resolveConsts($kind, $args, $argts, $argPositions, fn(int $i, array $argts): string => sprintf("%s's arguments must all be compatible types but got %s", $fn, $argts[$i]->string()));
            }
        }

        foreach ($argts as $i => $t) {
            if ($t->main !== BasicType::FLOAT && !$t->isFloatVector() && !$t->isInteger()) {
                $this->fail($argPositions[$i], sprintf('cannot use %s as float, vecN, int, or ivecN value in %s', $t->string(), self::argumentName($fn, $i, $n)));
            }
        }

        $baseType = $argts[0];
        for ($i = 1; $i < $n; $i++) {
            if ($baseType->equal($argts[$i])) {
                continue;
            }
            if ($n === 2) {
                // Only allow vector + scalar (not scalar + vector)
                if (!(($baseType->isFloatVector() && $argts[$i]->main === BasicType::FLOAT) || ($baseType->isIntegerVector() && $argts[$i]->main === $baseType->scalar()))) {
                    $this->fail($argPositions[$i], sprintf('invalid arguments %s and %s for %s', $baseType->string(), $argts[$i]->string(), $fn));
                }
            } else {
                $this->fail($argPositions[$i], sprintf('all argument types must be the same but got %s and %s for variadic %s', $baseType->string(), $argts[$i]->string(), $fn));
            }
        }
        return $baseType;
    }

    /**
     * Checks the 2 arguments of atan2, pow, mod, step, distance, dot,
     * cross or reflect, typing their constants, and returns the call's
     * type.
     * @param list<Expr> $args
     * @param list<Type> $argts
     * @param list<int> $argPositions
     */
    private function checkArgsFor2(string $fn, Syntax\CallExpr $e, array $args, array $argts, array $argPositions): Type
    {
        $n = count($args);
        if ($n !== 2) {
            $this->fail(self::argCountErrorPos($e, $argPositions, 2), "number of $fn's arguments must be 2 but $n");
        }
        foreach ($args as $i => $a) {
            // If the argument is a non-typed constant value, treat this as a float value (#1874).
            if ($a->const !== null && $argts[$i]->main === BasicType::NONE && self::canTruncateToFloat($a->const)) {
                $a->const = Constant::toFloat($a->const);
                $argts[$i] = new Type(BasicType::FLOAT);
            }
        }
        foreach ($argts as $i => $t) {
            if ($t->main !== BasicType::FLOAT && !$t->isFloatVector()) {
                $this->fail($argPositions[$i], sprintf('cannot use %s as float, vec2, vec3, or vec4 value in %s', $t->string(), self::argumentName($fn, $i, $n)));
            }
        }
        [$t0, $t1] = $argts;
        switch ($fn) {
            case BuiltinFunc::MOD:
                if (!$t0->equal($t1) && $t1->main !== BasicType::FLOAT) {
                    $this->fail($argPositions[1], sprintf('the second argument for %s must equal the first argument %s or float but %s', $fn, $t0->string(), $t1->string()));
                }
                break;
            case BuiltinFunc::STEP:
                if (!$t0->equal($t1) && $t0->main !== BasicType::FLOAT) {
                    $this->fail($argPositions[0], sprintf('the first argument for %s must equal the second argument %s or float but %s', $fn, $t1->string(), $t0->string()));
                }
                break;
            case BuiltinFunc::CROSS:
                foreach ($argts as $i => $t) {
                    if ($t->main !== BasicType::VEC3) {
                        $this->fail($argPositions[$i], sprintf('cannot use %s as vec3 value in %s', $t->string(), self::argumentName($fn, $i, $n)));
                    }
                }
                break;
            default:
                if (!$t0->equal($t1)) {
                    $this->fail($argPositions[1], sprintf("%s and %s don't match in argument to %s", $t0->string(), $t1->string(), $fn));
                }
        }
        return match ($fn) {
            BuiltinFunc::DISTANCE, BuiltinFunc::DOT => new Type(BasicType::FLOAT),
            BuiltinFunc::STEP => $t1,
            default => $t0,
        };
    }

    /**
     * Checks the argument of a built-in function of 1, typing a constant,
     * and returns the call's type.
     * @param list<Expr> $args
     * @param list<Type> $argts
     * @param list<int> $argPositions
     */
    private function checkArgsFor1(string $fn, Syntax\CallExpr $e, array $args, array $argts, array $argPositions): Type
    {
        $n = count($args);
        if ($n !== 1) {
            $this->fail(self::argCountErrorPos($e, $argPositions, 1), "number of $fn's arguments must be 1 but $n");
        }
        $a = $args[0];
        if ($a->const !== null && $argts[0]->main === BasicType::NONE) {
            if ($fn === BuiltinFunc::ABS || $fn === BuiltinFunc::SIGN) {
                if ($a->const->kind === Constant::INT) {
                    $argts[0] = new Type(BasicType::INT);
                }
                if ($a->const->kind === Constant::FLOAT) {
                    $argts[0] = new Type(BasicType::FLOAT);
                }
            } elseif (self::canTruncateToFloat($a->const)) {
                // If the argument is a non-typed constant value, treat this as a float value (#1874).
                $a->const = Constant::toFloat($a->const);
                $argts[0] = new Type(BasicType::FLOAT);
            }
        }
        $t = $argts[0];
        switch ($fn) {
            case BuiltinFunc::TRANSPOSE:
                if (!$t->isMatrix()) {
                    $this->fail($argPositions[0], sprintf('cannot use %s as mat2, mat3, or mat4 value in %s', $t->string(), self::argumentName($fn, 0, 1)));
                }
                break;
            case BuiltinFunc::ABS:
            case BuiltinFunc::SIGN:
                if ($t->main !== BasicType::FLOAT && !$t->isFloatVector() && $t->main !== BasicType::INT && !$t->isIntVector()) {
                    $this->fail($argPositions[0], sprintf('cannot use %s as float, vecN, int, or ivecN value in %s', $t->string(), self::argumentName($fn, 0, 1)));
                }
                break;
            default:
                if ($t->main !== BasicType::FLOAT && !$t->isFloatVector()) {
                    $this->fail($argPositions[0], sprintf('cannot use %s as float, vec2, vec3, or vec4 value in %s', $t->string(), self::argumentName($fn, 0, 1)));
                }
        }
        return $fn === BuiltinFunc::LENGTH ? new Type(BasicType::FLOAT) : $t;
    }

    /** @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>} */
    private function parseIdentExpr(Block $block, Syntax\Ident $e, bool $markLocalVariableUsed): array
    {
        if ($e->name === '_') {
            // In the context where a local variable is marked as used, any expression must have its
            // meaning. Then, a blank identifier is not available there.
            if ($markLocalVariableUsed) {
                $this->fail($e->pos, 'cannot use _ as value');
            }
            return [[new Expr(ExprType::BLANK)], [new Type], []];
        }
        [$expr, $t] = $this->parseIdent($block, $e, $markLocalVariableUsed);
        if ($expr->type === ExprType::FUNCTION_EXPR || $expr->type === ExprType::BUILTIN_FUNC_EXPR) {
            $this->fail($e->pos, "function {$e->name} is used as a value without a call");
        }
        return [[$expr], [$t], []];
    }

    /** @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>} */
    private function parseSelectorExpr(Block $block, string $fname, Syntax\SelectorExpr $e): array
    {
        [$exprs, $types, $stmts] = $this->parseExpr($block, $fname, $e->x, true);
        if (count($exprs) !== 1) {
            $this->fail($e->pos, 'multiple-value context is not available at a selector: ' . self::nodeString($e->x));
        }
        $name = $e->sel->name;
        if ($types === [] || !self::isValidSwizzling($name, $types[0])) {
            $this->fail($e->sel->pos, "unexpected swizzling: $name");
        }
        $scalar = $types[0]->scalar();
        $main = $types[0]->isFloatVector() || $types[0]->isIntegerVector() ? Type::vectorOf($scalar, strlen($name)) : BasicType::NONE;
        if ($main === BasicType::NONE) {
            $this->fail($e->sel->pos, "unexpected swizzling: $name");
        }
        [$swizzling, $set] = self::normalizeSwizzling($name);
        return [[new Expr(ExprType::FIELD_SELECTOR, [$exprs[0], new Expr(ExprType::SWIZZLING_EXPR, swizzling: $swizzling, swizzlingSet: $set)])], [new Type($main)], $stmts];
    }

    /** @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>} */
    private function parseUnaryExpr(Block $block, string $fname, Syntax\UnaryExpr $e, bool $markLocalVariableUsed): array
    {
        [$exprs, $ts, $stmts] = $this->parseExpr($block, $fname, $e->x, $markLocalVariableUsed);
        if (count($exprs) !== 1) {
            $this->fail($e->pos, 'multiple-value context is not available at a unary operator: ' . self::nodeString($e->x));
        }
        if ($ts === []) {
            $this->fail($e->pos, 'unexpected unary operator: ' . self::nodeString($e->x));
        }
        $x = $exprs[0];
        if ($x->type === ExprType::BLANK) {
            $this->fail($e->pos, 'cannot use _ as value');
        }

        if ($x->const !== null) {
            // go/constant's UnaryOp is not defined on every kind.
            $valid = match ($e->op) {
                '+', '-' => $x->const->kind === Constant::INT || $x->const->kind === Constant::FLOAT,
                '!' => $x->const->kind === Constant::BOOL,
                '^' => $x->const->kind === Constant::INT,
                default => false,
            };
            if (!$valid) {
                $this->fail($e->pos, "invalid operation: operator {$e->op} not defined on {$x->const}");
            }
            // The complement of a uint is in its 32 bits.
            $v = Constant::unaryOp($e->op, $x->const, $ts[0]->main === BasicType::UINT ? 32 : 0);
            // Use the original type as it is.
            // Keep the type untyped if the original expression is untyped (#2705).
            return [[new Expr(ExprType::NUMBER_EXPR, const: $v, unsigned: $x->unsigned)], [$ts[0]], $stmts];
        }

        $t = $ts[0];
        [$op, $valid] = match ($e->op) {
            '+' => [Op::ADD, $t->isInteger() || $t->main === BasicType::FLOAT || $t->isFloatVector() || $t->isMatrix()],
            '-' => [Op::SUB, $t->isInteger() || $t->main === BasicType::FLOAT || $t->isFloatVector() || $t->isMatrix()],
            '!' => [Op::NOT_OP, $t->main === BasicType::BOOL],
            '^' => [Op::COMPLEMENT_OP, $t->isInteger()],
            default => $this->fail($e->pos, "unexpected operator: {$e->op}"),
        };
        if (!$valid) {
            $this->fail($e->pos, "invalid operation: operator {$e->op} not defined on {$t->string()}");
        }
        return [[new Expr(ExprType::UNARY, $exprs, op: $op, untyped: $x->untyped)], [$t], $stmts];
    }

    /** @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>} */
    private function parseCompositeLit(Block $block, string $fname, Syntax\CompositeLit $e, bool $markLocalVariableUsed): array
    {
        if ($e->type instanceof Syntax\ArrayType && $e->type->len instanceof Syntax\Ellipsis) {
            // The length of a [...] array is the number of the elements.
            $t = $this->parseArrayType($block, $fname, $e->type, count($e->elts));
        } else {
            $t = $this->parseType($block, $fname, $e->type);
        }
        if ($t->main !== BasicType::ARRAY) {
            $this->fail($e->pos, "invalid composite literal type {$t->string()}");
        }
        if ($t->length < count($e->elts)) {
            // KeyValueExpr is not supported yet. Just compare the length.
            $this->fail($e->elts[$t->length]->pos, "too many values in {$t->string()} literal");
        }

        $idx = $block->totalLocalVariableCount();
        $block->vars[] = new Variable('', $t);

        $stmts = [];
        $elt = $t->sub[0];
        foreach ($e->elts as $i => $el) {
            [$exprs, $ets, $ss] = $this->parseExpr($block, $fname, $el, $markLocalVariableUsed);
            if (count($exprs) !== 1 || count($ets) !== 1) {
                $this->fail($el->pos, 'multiple-value context is not available at a composite literal');
            }
            $x = $exprs[0];
            if ($x->const === null) {
                if (!self::canAssign($elt, $ets[0], $x)) {
                    $this->fail($el->pos, "cannot use type {$ets[0]->string()} as type {$elt->string()} in array literal");
                }
            } else {
                switch ($elt->main) {
                    case BasicType::BOOL:
                        if ($x->const->kind !== Constant::BOOL) {
                            $this->fail($el->pos, "cannot convert {$x->const} to type bool");
                        }
                        break;
                    case BasicType::INT: case BasicType::UINT:
                        if (!self::canTruncateToInteger($x->const)) {
                            $this->fail($el->pos, "constant {$x->const} truncated to integer");
                        }
                        $x->const = Constant::toInt($x->const);
                        break;
                    case BasicType::FLOAT:
                        if (!self::canTruncateToFloat($x->const)) {
                            $this->fail($el->pos, "constant {$x->const} truncated to float");
                        }
                        $x->const = Constant::toFloat($x->const);
                        break;
                    default:
                        $this->fail($el->pos, "constant {$x->const} cannot be used for the array type {$t->string()}");
                }
            }

            array_push($stmts, ...$ss);
            $stmts[] = new Stmt(StmtType::ASSIGN, [
                new Expr(ExprType::INDEX, [new Expr(ExprType::LOCAL_VARIABLE, index: $idx), new Expr(ExprType::NUMBER_EXPR, const: Constant::int($i))]),
                $x,
            ]);
        }
        return [[new Expr(ExprType::LOCAL_VARIABLE, index: $idx)], [$t], $stmts];
    }

    /** @return array{0: list<Expr>, 1: list<Type>, 2: list<Stmt>} */
    private function parseIndexExpr(Block $block, string $fname, Syntax\IndexExpr $e, bool $markLocalVariableUsed): array
    {
        // Parse the index first
        [$exprs, $its, $stmts] = $this->parseExpr($block, $fname, $e->index, true);
        if (count($exprs) !== 1 || count($its) !== 1) {
            $this->fail($e->index->pos, 'multiple-value context is not available at an index expression');
        }
        $idx = $exprs[0];
        if ($idx->const !== null) {
            $this->forceToInt($e->index->pos, $idx);
        } elseif ($its[0]->main !== BasicType::INT && $its[0]->main !== BasicType::UINT) {
            $this->fail($e->index->pos, "index must be int but {$its[0]->string()}");
        }

        [$exprs, $ts, $ss] = $this->parseExpr($block, $fname, $e->x, $markLocalVariableUsed);
        array_push($stmts, ...$ss);
        if (count($exprs) !== 1) {
            $this->fail($e->pos, 'multiple-value context is not available at an index expression');
        }
        if ($ts === []) {
            $this->fail($e->pos, 'unexpected index expression');
        }
        $x = $exprs[0];
        $t = $ts[0];

        // Check the length only when the index is a constant.
        if ($idx->const !== null) {
            $length = match (true) {
                $t->main === BasicType::ARRAY => $t->length,
                $t->isFloatVector() || $t->isIntegerVector() => $t->vectorElementCount(),
                $t->isMatrix() => $t->matrixSize(),
                default => $this->fail($e->pos, 'index operator cannot be applied to the type ' . self::typeString($t, $x->const)),
            };
            [$v, $ok] = Constant::int64Val(Constant::toInt($idx->const));
            if (!$ok) {
                $this->fail($e->index->pos, "constant {$idx->const} cannot be used as an index");
            }
            if ($v < 0 || $v >= $length) {
                $this->fail($e->index->pos, "index out of range: $v");
            }
        }

        $typ = match ($t->main) {
            BasicType::VEC2, BasicType::VEC3, BasicType::VEC4 => new Type(BasicType::FLOAT),
            BasicType::IVEC2, BasicType::IVEC3, BasicType::IVEC4 => new Type(BasicType::INT),
            BasicType::UVEC2, BasicType::UVEC3, BasicType::UVEC4 => new Type(BasicType::UINT),
            BasicType::MAT2 => new Type(BasicType::VEC2),
            BasicType::MAT3 => new Type(BasicType::VEC3),
            BasicType::MAT4 => new Type(BasicType::VEC4),
            BasicType::ARRAY => $t->sub[0],
            default => $this->fail($e->pos, 'index operator cannot be applied to the type ' . self::typeString($t, $x->const)),
        };
        return [[new Expr(ExprType::INDEX, [$x, $idx])], [$typ], $stmts];
    }

    /** What the non-blank identifier $e names in the scope of $block, and its type: none for a function. @return array{0: Expr, 1: Type} */
    private function parseIdent(Block $block, Syntax\Ident $e, bool $markLocalVariableUsed): array
    {
        $local = $block->findLocalVariable($e->name, $markLocalVariableUsed);
        if ($local !== null) {
            return [new Expr(ExprType::LOCAL_VARIABLE, index: $local[0]), $local[1]];
        }
        $c = $block->findConstant($e->name);
        if ($c !== null) {
            return [new Expr(ExprType::NUMBER_EXPR, const: $c->value, unsigned: $c->typ->main === BasicType::UINT), $c->typ];
        }
        $i = $this->findFunction($e->name);
        if ($i !== null) {
            return [new Expr(ExprType::FUNCTION_EXPR, index: $i), new Type];
        }
        $i = $this->findUniformVariable($e->name);
        if ($i !== null) {
            return [new Expr(ExprType::UNIFORM_VARIABLE, index: $i), $this->ir->uniforms[$i]];
        }
        $f = BuiltinFunc::parse($e->name);
        if ($f !== null) {
            return [new Expr(ExprType::BUILTIN_FUNC_EXPR, builtinFunc: $f), new Type];
        }
        if (preg_match('/\A__t(\d+)\z/', $e->name, $m)) {
            $i = (int)$m[1];
            if ($i >= $this->ir->textureCount) {
                $this->fail($e->pos, "texture index out of range: {$e->name}");
            }
            return [new Expr(ExprType::TEXTURE_VARIABLE, index: $i), new Type(BasicType::TEXTURE)];
        }
        if ($e->name === 'true' || $e->name === 'false') {
            return [new Expr(ExprType::NUMBER_EXPR, const: Constant::bool($e->name === 'true')), new Type(BasicType::BOOL)];
        }
        $this->fail($e->pos, "unexpected identifier: {$e->name}");
    }

    /** The function a call's callee $e names. */
    private function parseCallee(Block $block, Syntax\Expr $e): Expr
    {
        if ($e instanceof Syntax\ParenExpr) {
            return $this->parseCallee($block, $e->x);
        }
        if ($e instanceof Syntax\Ident) {
            if ($e->name === '_') {
                $this->fail($e->pos, 'cannot use _ as value');
            }
            [$expr] = $this->parseIdent($block, $e, false);
            if ($expr->type !== ExprType::FUNCTION_EXPR && $expr->type !== ExprType::BUILTIN_FUNC_EXPR) {
                $this->fail($e->pos, "function callee must be a function name but {$e->name}");
            }
            return $expr;
        }
        $this->fail($e->pos, 'function callee must be a function name but ' . self::nodeString($e));
    }

    /** $s spelled in the xyzw set, and the set it is written in; $s must be a valid swizzle. @return array{0: string, 1: int} */
    private static function normalizeSwizzling(string $s): array
    {
        $set = match ($s[0]) {
            'r', 'g', 'b', 'a' => SwizzlingSet::RGBA,
            's', 't', 'p', 'q' => SwizzlingSet::STPQ,
            default => SwizzlingSet::XYZW,
        };
        return [strtr($s, 'rgbastpq', 'xyzwxyzw'), $set];
    }

    private static function isValidSwizzling(string $swizzling, Type $t): bool
    {
        // shaderir's IsValidSwizzling: 1 to 4 components, all of one set.
        if (!preg_match('/\A(?:[xyzw]{1,4}|[rgba]{1,4}|[stpq]{1,4})\z/', $swizzling)) {
            return false;
        }
        return match ($t->main) {
            BasicType::VEC2, BasicType::IVEC2, BasicType::UVEC2 => strpbrk($swizzling, 'zwbapq') === false,
            BasicType::VEC3, BasicType::IVEC3, BasicType::UVEC3 => strpbrk($swizzling, 'waq') === false,
            BasicType::VEC4, BasicType::IVEC4, BasicType::UVEC4 => true,
            default => false,
        };
    }

    /**
     * The kind the constants among $args take, Int or Float, or UNKNOWN
     * when the typed ones disagree or one is of another type: the typed
     * ones' kind, or for all constants, Float if one is a float.
     * @param list<Expr> $exprs
     * @param list<Type> $ts
     */
    private static function resolveConstKind(array $exprs, array $ts): int
    {
        $allConsts = true;
        foreach ($exprs as $x) {
            if ($x->const === null) {
                $allConsts = false;
            }
        }

        if (!$allConsts) {
            foreach ($ts as $t) {
                if ($t->main === BasicType::NONE) {
                    continue;
                }
                return match ($t->main) {
                    BasicType::FLOAT => Constant::FLOAT,
                    BasicType::INT, BasicType::UINT => Constant::INT,
                    default => Constant::UNKNOWN,
                };
            }
        }

        $kind = Constant::UNKNOWN;
        foreach ($ts as $t) {
            switch ($t->main) {
                case BasicType::INT: case BasicType::UINT:
                    if ($kind === Constant::FLOAT) {
                        return Constant::UNKNOWN;
                    }
                    $kind = Constant::INT;
                    break;
                case BasicType::FLOAT:
                    if ($kind === Constant::INT) {
                        return Constant::UNKNOWN;
                    }
                    $kind = Constant::FLOAT;
                    break;
            }
        }
        if ($kind !== Constant::UNKNOWN) {
            return $kind;
        }

        // Prefer floats over integers for non-typed constant values.
        // For example, max(1.0, 1) should return a float value.
        foreach ($exprs as $x) {
            if ($x->const->kind === Constant::FLOAT) {
                return Constant::FLOAT;
            }
        }
        return Constant::INT;
    }

    /** The kind of integer, int or uint, of the typed ones among $args, int when none is typed. @param list<Expr> $args @param list<Type> $argts */
    private static function integerKind(array $args, array $argts): int
    {
        foreach ($args as $i => $a) {
            if ($a->const === null && $argts[$i]->main === BasicType::UINT) {
                return BasicType::UINT;
            }
        }
        return BasicType::INT;
    }
}
