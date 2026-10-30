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

// Ported to PHP from MyGo's internal/shader (stmt.go), MyGo's fork of
// Ebitengine's (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Compiler;

use MyPHP\UI\Shader\Ir\{BasicType, BuiltinFunc, Constant, Expr, ExprType, Op, Stmt, StmtType, Type};
use MyPHP\UI\Shader\Syntax;

/** The compiler's statements (stmt.go). @internal */
trait Stmts
{
    /** Makes the constant of $expr an integer, reporting at $pos one that isn't. */
    private function forceToInt(int $pos, Expr $expr): void
    {
        if (!self::canTruncateToInteger($expr->const)) {
            $this->fail($pos, "constant {$expr->const} truncated to integer");
        }
        $expr->const = Constant::toInt($expr->const);
    }

    /**
     * @param list<Variable> $inParams
     * @param list<Variable> $outParams
     * @return list<Stmt>
     */
    private function parseStmt(Block $block, string $fname, Syntax\Stmt $stmt, array $inParams, array $outParams, Type $returnType): array
    {
        switch (true) {
            case $stmt instanceof Syntax\AssignStmt:
                return $this->parseAssignStmt($block, $fname, $stmt);
            case $stmt instanceof Syntax\BlockStmt:
                $b = $this->parseBlock($block, $fname, $stmt->list, $inParams, $outParams, $returnType, true);
                return [new Stmt(StmtType::BLOCK_STMT, blocks: [$b->ir])];
            case $stmt instanceof Syntax\DeclStmt:
                return $this->parseDecl($block, $fname, $stmt->decl);
            case $stmt instanceof Syntax\ForStmt:
                return $this->parseFor($block, $fname, $stmt, $inParams, $outParams, $returnType);
            case $stmt instanceof Syntax\RangeStmt:
                return $this->parseForRange($block, $fname, $stmt, $inParams, $outParams, $returnType);
            case $stmt instanceof Syntax\IfStmt:
                return $this->parseIfStmt($block, $fname, $stmt, $inParams, $outParams, $returnType);
            case $stmt instanceof Syntax\IncDecStmt:
                return $this->parseIncDecStmt($block, $fname, $stmt);
            case $stmt instanceof Syntax\ReturnStmt:
                return $this->parseReturnStmt($block, $fname, $stmt, $inParams, $outParams, $returnType);
            case $stmt instanceof Syntax\BranchStmt:
                if ($stmt->tok !== 'break' && $stmt->tok !== 'continue') {
                    $this->fail($stmt->pos, "{$stmt->tok} is not supported");
                }
                if ($stmt->label !== null) {
                    $this->fail($stmt->pos, "{$stmt->tok} with a label is not supported");
                }
                if (!$block->inLoop()) {
                    $this->fail($stmt->pos, "{$stmt->tok} is not in a loop");
                }
                return [new Stmt($stmt->tok === 'break' ? StmtType::BREAK : StmtType::CONTINUE)];
            case $stmt instanceof Syntax\LabeledStmt:
                $this->fail($stmt->pos, 'labeled statement is not supported');
            case $stmt instanceof Syntax\ExprStmt:
                return $this->parseExprStmt($block, $fname, $stmt);
        }
        $this->fail($stmt->pos, 'unexpected statement: ' . self::nodeString($stmt));
    }

    /** @return list<Stmt> */
    private function parseAssignStmt(Block $block, string $fname, Syntax\AssignStmt $stmt): array
    {
        switch ($stmt->tok) {
            case ':=':
            case '=':
                if (count($stmt->lhs) !== count($stmt->rhs) && count($stmt->rhs) !== 1) {
                    $this->fail($stmt->pos, 'single-value context and multiple-value context cannot be mixed');
                }
                return $this->assign($block, $fname, $stmt->pos, $stmt->lhs, $stmt->rhs, $stmt->tok === ':=');
        }

        if (count($stmt->lhs) !== 1 || count($stmt->rhs) !== 1) {
            $this->fail($stmt->pos, 'single-value context and multiple-value context cannot be mixed');
        }

        [$rhs, $rts, $stmts] = $this->parseExpr($block, $fname, $stmt->rhs[0], true);
        if (count($rhs) !== 1 || count($rts) !== 1) {
            $this->fail($stmt->rhs[0]->pos, "the right-hand side of {$stmt->tok} must be a single value");
        }
        [$lhs, $lts, $ss] = $this->parseExpr($block, $fname, $stmt->lhs[0], true);
        if (count($lhs) !== 1 || count($lts) !== 1) {
            $this->fail($stmt->pos, "the left-hand side of {$stmt->tok} must be a single value");
        }
        array_push($stmts, ...$ss);
        $l = $lhs[0]; $r = $rhs[0]; $lt = $lts[0]; $rt = $rts[0];

        $this->checkAssignmentTarget($stmt->pos, $l);

        if (($stmt->tok === '/=' || $stmt->tok === '%=') && self::isConstZero($r->const)) {
            $this->fail($stmt->rhs[0]->pos, 'division by zero');
        }

        $op = match ($stmt->tok) {
            '+=' => Op::ADD,
            '-=' => Op::SUB,
            '*=' => $lt->isMatrix() || $rt->isMatrix() ? Op::MATRIX_MUL : Op::COMPONENT_WISE_MUL,
            '/=' => Op::DIV,
            '%=' => Op::MOD_OP,
            '&=' => Op::AND,
            '&^=' => Op::AND_NOT,
            '|=' => Op::OR,
            '^=' => Op::XOR,
            '<<=' => Op::LEFT_SHIFT,
            '>>=' => Op::RIGHT_SHIFT,
            default => $this->fail($stmt->pos, "unexpected token: {$stmt->tok}"),
        };
        $bitwise = in_array($op, [Op::AND, Op::AND_NOT, Op::OR, Op::XOR, Op::LEFT_SHIFT, Op::RIGHT_SHIFT], true);
        // Whether the right-hand side is a constant that can be a float.
        $floatConst = fn(): bool => $r->const !== null && ($rt->main === BasicType::NONE || $rt->main === BasicType::FLOAT) && self::canTruncateToFloat($r->const);
        $mismatched = fn(): string => sprintf('invalid operation: mismatched types %s and %s', $lt->string(), self::typeString($rt, $r->const));

        if ($lt->main === $rt->main) {
            if ($lt->main !== BasicType::FLOAT && !$lt->isInteger() && !$lt->isFloatVector() && !$lt->isMatrix()) {
                $this->fail($stmt->pos, "invalid operation: operator {$stmt->tok} not defined on {$lt->string()}");
            }
            if ($op === Op::DIV && $rt->isMatrix()) {
                $this->fail($stmt->pos, "invalid operation: operator / not defined on {$rt->string()}");
            }
            if ($bitwise) {
                if (!$lt->isInteger()) {
                    $this->fail($stmt->pos, "invalid operation: operator {$stmt->tok} not defined on {$lt->string()}");
                }
                if (!$rt->isInteger()) {
                    $this->fail($stmt->pos, "invalid operation: operator {$stmt->tok} not defined on {$rt->string()}");
                }
            }
            if (($lt->main === BasicType::INT || $lt->main === BasicType::UINT) && $r->const !== null) {
                $this->forceToInt($stmt->pos, $r);
            }
        } else {
            // A shift's count may be of either kind of integer.
            $shift = $op === Op::LEFT_SHIFT || $op === Op::RIGHT_SHIFT;
            switch ($lt->main) {
                case BasicType::UINT: case BasicType::UVEC2: case BasicType::UVEC3: case BasicType::UVEC4:
                    // An untyped shift takes the type of the uint.
                    if ($rt->main !== BasicType::UINT && !($shift && $rt->main === BasicType::INT) && !$r->untyped) {
                        if (!$rt->equal(new Type)) {
                            $this->fail($stmt->pos, $mismatched());
                        }
                        $this->forceToInt($stmt->pos, $r);
                    }
                    break;
                case BasicType::INT: case BasicType::IVEC2: case BasicType::IVEC3: case BasicType::IVEC4:
                    if ($rt->main !== BasicType::INT && !($shift && $rt->main === BasicType::UINT)) {
                        if (!$rt->equal(new Type)) {
                            $this->fail($stmt->pos, $mismatched());
                        }
                        $this->forceToInt($stmt->pos, $r);
                    }
                    break;
                case BasicType::FLOAT:
                    if ($bitwise) {
                        $this->fail($stmt->pos, "invalid operation: operator {$stmt->tok} not defined on {$lt->string()}");
                    }
                    if (!$floatConst()) {
                        $this->fail($stmt->pos, $mismatched());
                    }
                    $r->const = Constant::toFloat($r->const);
                    break;
                case BasicType::VEC2: case BasicType::VEC3: case BasicType::VEC4:
                case BasicType::MAT2: case BasicType::MAT3: case BasicType::MAT4:
                    if ($bitwise) {
                        $this->fail($stmt->pos, "invalid operation: operator {$stmt->tok} not defined on {$lt->string()}");
                    }
                    $byScalar = $rt->main === BasicType::FLOAT || $floatConst();
                    if (($op === Op::MATRIX_MUL || $op === Op::DIV) && $byScalar) {
                        if ($r->const !== null) {
                            $r->const = Constant::toFloat($r->const);
                        }
                    } elseif ($op === Op::MATRIX_MUL && ($lt->main === BasicType::VEC2 && $rt->main === BasicType::MAT2
                        || $lt->main === BasicType::VEC3 && $rt->main === BasicType::MAT3
                        || $lt->main === BasicType::VEC4 && $rt->main === BasicType::MAT4)) {
                        // OK
                    } elseif (($op === Op::MATRIX_MUL || $op === Op::COMPONENT_WISE_MUL || $lt->isFloatVector()) && $byScalar) {
                        if ($r->const !== null) {
                            $r->const = Constant::toFloat($r->const);
                        }
                    } else {
                        $this->fail($stmt->pos, $mismatched());
                    }
                    break;
                default:
                    $this->fail($stmt->pos, $mismatched());
            }
        }

        if ($op === Op::MOD_OP && !$lt->isInteger()) {
            $this->fail($stmt->pos, "invalid operation: operator % not defined on {$lt->string()}");
        }

        if (($op === Op::LEFT_SHIFT || $op === Op::RIGHT_SHIFT) && $r->const !== null) {
            $this->shiftCount($stmt->pos, $stmt->tok, $r->const);
        }

        $stmts[] = new Stmt(StmtType::ASSIGN, [$l, new Expr(ExprType::BINARY, [clone $l, $r], op: $op)]);
        return $stmts;
    }

    /**
     * @param list<Variable> $inParams
     * @param list<Variable> $outParams
     * @return list<Stmt>
     */
    private function parseIfStmt(Block $block, string $fname, Syntax\IfStmt $stmt, array $inParams, array $outParams, Type $returnType): array
    {
        if ($stmt->init !== null) {
            // The init statement and the if-statement without it make a block of their own.
            $init = $stmt->init;
            $stmt->init = null;
            $b = $this->parseBlock($block, $fname, [$init, $stmt], $inParams, $outParams, $returnType, true);
            return [new Stmt(StmtType::BLOCK_STMT, blocks: [$b->ir])];
        }

        [$exprs, $ts, $stmts] = $this->parseExpr($block, $fname, $stmt->cond, true);
        if (count($ts) !== 1) {
            $this->fail($stmt->cond->pos, 'if-condition must be bool but: ' . implode(', ', array_map(fn(Type $t): string => $t->string(), $ts)));
        }
        if (!($ts[0]->main === BasicType::BOOL || $ts[0]->main === BasicType::NONE && $exprs[0]->const?->kind === Constant::BOOL)) {
            $this->fail($stmt->cond->pos, 'if-condition must be bool but: ' . self::typeString($ts[0], $exprs[0]->const));
        }

        $bs = [$this->parseBlock($block, $fname, $stmt->body->list, $inParams, $outParams, $returnType, true)->ir];
        if ($stmt->else !== null) {
            $list = $stmt->else instanceof Syntax\BlockStmt ? $stmt->else->list : [$stmt->else];
            $bs[] = $this->parseBlock($block, $fname, $list, $inParams, $outParams, $returnType, true)->ir;
        }
        $stmts[] = new Stmt(StmtType::IF, $exprs, $bs);
        return $stmts;
    }

    /** @return list<Stmt> */
    private function parseIncDecStmt(Block $block, string $fname, Syntax\IncDecStmt $stmt): array
    {
        [$exprs, $ts, $stmts] = $this->parseExpr($block, $fname, $stmt->x, true);
        if (count($exprs) !== 1 || count($ts) !== 1) {
            $this->fail($stmt->pos, "the operand of {$stmt->tok} must be a single value");
        }
        $this->checkAssignmentTarget($stmt->pos, $exprs[0]);
        $t = $ts[0];
        $c = match (true) {
            $t->isInteger() => Constant::int(1),
            $t->main === BasicType::FLOAT, $t->isFloatVector() => Constant::float(1.0),
            default => $this->fail($stmt->pos, "invalid operation {$stmt->tok} (non-numeric type {$t->string()})"),
        };
        $op = $stmt->tok === '++' ? Op::ADD : Op::SUB;
        $stmts[] = new Stmt(StmtType::ASSIGN, [$exprs[0], new Expr(ExprType::BINARY, [clone $exprs[0], new Expr(ExprType::NUMBER_EXPR, const: $c)], op: $op)]);
        return $stmts;
    }

    /**
     * @param list<Variable> $inParams
     * @param list<Variable> $outParams
     * @return list<Stmt>
     */
    private function parseReturnStmt(Block $block, string $fname, Syntax\ReturnStmt $stmt, array $inParams, array $outParams, Type $returnType): array
    {
        $nres = count($stmt->results);
        $nout = count($outParams);
        $want = $nout === 0 && $returnType->main !== BasicType::NONE ? 1 : $nout;
        if ($nres !== $nout && $nres !== 1) {
            if (!($nres === 0 && $nout > 0 && $outParams[0]->name !== '')) {
                // TODO: Check variable shadowings.
                // https://go.dev/ref/spec#Return_statements
                $this->fail(self::returnCountErrorPos($stmt, $nres, $want), "the number of returning variables must be $want but $nres");
            }
        }

        $stmts = []; $exprs = []; $types = []; $positions = [];
        foreach ($stmt->results as $r) {
            [$es, $ts, $ss] = $this->parseExpr($block, $fname, $r, true);
            array_push($stmts, ...$ss);

            if (count($es) > 1 && ($nres > 1 || $nout === 1)) {
                $this->fail($r->pos, 'single-value context and multiple-value context cannot be mixed');
            }
            if ($nout > 1 && $nres === 1) {
                if (count($es) === 1) {
                    $this->fail(self::returnCountErrorPos($stmt, $nres, $want), "the number of returning variables must be $want but $nres");
                }
                if (count($es) > 1 && count($es) !== $nout) {
                    $this->fail(self::returnCountErrorPos($stmt, count($es), $want), sprintf('the number of returning variables must be %d but %d', $want, count($es)));
                }
            }

            array_push($exprs, ...$es);
            array_push($types, ...$ts);
            foreach ($es as $_) {
                $positions[] = $r->pos;
            }
        }

        if ($nres > 0) {
            // A function returning nothing must not return even a call yielding no values.
            $got = $want === 0 ? $nres : count($exprs);
            if ($got !== $want) {
                $this->fail(self::returnCountErrorPos($stmt, $got, $want), "the number of returning variables must be $want but $got");
            }
        }

        foreach ($types as $i => $t) {
            $expr = $exprs[$i];
            $outT = $nout === 0 ? $returnType : $outParams[$i]->typ;
            $result = count($types) > 1 ? sprintf('the %s return argument', self::ordinal($i + 1)) : 'return argument';
            $cannot = fn(): string => sprintf('cannot use type %s as type %s in %s', self::typeString($t, $expr->const), $outT->string(), $result);
            if ($expr->const !== null) {
                switch ($outT->main) {
                    case BasicType::BOOL:
                        if ($expr->const->kind !== Constant::BOOL) {
                            $this->fail($positions[$i], $cannot());
                        }
                        $t = new Type(BasicType::BOOL);
                        break;
                    case BasicType::INT: case BasicType::UINT:
                        if (!self::canTruncateToInteger($expr->const)) {
                            $this->fail($positions[$i], $cannot());
                        }
                        $expr->const = Constant::toInt($expr->const);
                        $t = new Type($outT->main);
                        break;
                    case BasicType::FLOAT:
                        if (!self::canTruncateToFloat($expr->const)) {
                            $this->fail($positions[$i], $cannot());
                        }
                        $expr->const = Constant::toFloat($expr->const);
                        $t = new Type(BasicType::FLOAT);
                        break;
                }
            }

            if (!self::canAssign($outT, $t, $expr)) {
                $this->fail($positions[$i], sprintf('cannot use type %s as type %s in %s', self::typeString($t, $expr->const), $outT->string(), $result));
            }

            if ($nout > 0) {
                $stmts[] = new Stmt(StmtType::ASSIGN, [new Expr(ExprType::LOCAL_VARIABLE, index: count($inParams) + $i), $expr]);
            } else {
                // When a return type is specified, there should be only one expr here.
                $stmts[] = new Stmt(StmtType::RETURN, [$expr]);
                break;
            }
        }

        if ($nout > 0) {
            $stmts[] = new Stmt(StmtType::RETURN);
        } elseif ($nres === 0) {
            if ($returnType->main !== BasicType::NONE) {
                $this->fail($stmt->pos, 'cannot use a bare return in a function that returns a value');
            }
            $stmts[] = new Stmt(StmtType::RETURN);
        }
        return $stmts;
    }

    /** @return list<Stmt> */
    private function parseExprStmt(Block $block, string $fname, Syntax\ExprStmt $stmt): array
    {
        if (!$stmt->x instanceof Syntax\CallExpr) {
            $this->fail($stmt->pos, 'the statement is evaluated but not used');
        }
        [$exprs, , $stmts] = $this->parseExpr($block, $fname, $stmt->x, true);
        // Constant folding can replace a built-in call with a non-call expression.
        $callee = $this->parseCallee($block, $stmt->x->fun);
        if ($callee->type === ExprType::BUILTIN_FUNC_EXPR && $callee->builtinFunc !== BuiltinFunc::DISCARD_F) {
            $this->fail($stmt->pos, 'the statement is evaluated but not used');
        }
        foreach ($exprs as $expr) {
            // There can be a non-call expr like LocalVariable expressions.
            // These are necessary to be used as arguments for callers of an outside function.
            if ($expr->type === ExprType::CALL) {
                $stmts[] = new Stmt(StmtType::EXPR_STMT, [$expr]);
            }
        }
        return $stmts;
    }

    /**
     * Where to report $stmt returning $got values instead of $want: the
     * first extra result if it has more than $want of them, the last
     * result otherwise, or $stmt itself if it has none.
     */
    private static function returnCountErrorPos(Syntax\ReturnStmt $stmt, int $got, int $want): int
    {
        $n = count($stmt->results);
        return match (true) {
            $n === 0 => $stmt->pos,
            $got > $want && $n > $want => $stmt->results[$want]->pos,
            default => $stmt->results[$n - 1]->pos,
        };
    }

    /**
     * @param list<Syntax\Expr> $lhs
     * @param list<Syntax\Expr> $rhs
     * @return list<Stmt>
     */
    private function assign(Block $block, string $fname, int $pos, array $lhs, array $rhs, bool $define): array
    {
        $stmts = [];
        $newVariable = false;
        $lhsNames = [];

        // Declares the new variable $e of a :=, typed as $t says, unless it is declared in the block, which assigns it.
        $declare = function (Syntax\Expr $e, \Closure $t) use ($block, &$lhsNames, &$newVariable): void {
            if (!$e instanceof Syntax\Ident) {
                $this->fail($e->pos, 'non-name on the left side of :=');
            }
            $name = $e->name;
            if ($name !== '_') {
                if (isset($lhsNames[$name])) {
                    $this->fail($e->pos, "$name repeated on left side of :=");
                }
                $lhsNames[$name] = true;
            }
            if (!self::declared($name, $block->vars, $block->consts)) {
                $typ = $t();
                $this->checkLocalVariableType($e->pos, $typ);
                $block->addNamedLocalVariable($name, $typ, $e->pos);
                if ($name !== '_') {
                    $newVariable = true;
                }
            }
        };

        if (count($lhs) === count($rhs)) {
            $localVariablIndicesToAssignLater = [];
            $leftExprsToAssignLater = [];

            // Parse all the right-hand sides before any new variable on the left-hand side is declared, as the new variables
            // are not in scope on the right-hand side.
            $rhsValues = [];
            foreach ($rhs as $r) {
                $rhsValues[] = $this->parseExpr($block, $fname, $r, true);
            }

            foreach ($lhs as $i => $e) {
                [$r, $rts, $ss] = $rhsValues[$i];
                array_push($stmts, ...$ss);

                if ($define) {
                    $declare($e, function () use ($rhs, $i, $rts, $r): Type {
                        $ts = $this->functionReturnTypes($rhs[$i]) ?? $rts;
                        if (count($ts) > 1) {
                            $this->fail($rhs[$i]->pos, 'single-value context and multiple-value context cannot be mixed');
                        }
                        if ($ts === []) {
                            $this->fail($rhs[$i]->pos, 'the right-hand side of := has no value');
                        }
                        return $ts[0]->main === BasicType::NONE ? self::toDefaultType($r[0]->const) : $ts[0];
                    });
                }

                if (count($r) > 1) {
                    $this->fail($rhs[$i]->pos, 'single-value context and multiple-value context cannot be mixed');
                }

                [$l, $lts, $ss] = $this->parseExpr($block, $fname, $lhs[$i], false);
                array_push($stmts, ...$ss);

                if (count($l) !== count($r)) {
                    if ($r === []) {
                        $this->fail($rhs[$i]->pos, 'right-hand side (no value) used as value');
                    }
                    $this->fail($rhs[$i]->pos, sprintf('assignment mismatch: %d variables but the right-hand side has %d values', count($l), count($r)));
                }

                if ($l[0]->type === ExprType::BLANK) {
                    continue;
                }

                $this->checkAssignmentTarget($e->pos, $l[0]);

                foreach ($lts as $j => $lt) {
                    if (!self::canAssign($lt, $rts[$j], $r[$j])) {
                        $this->fail($e->pos, sprintf('cannot use type %s as type %s in assignment', self::typeString($rts[$j], $r[$j]->const), $lt->string()));
                    }
                    if ($r[$j]->const !== null) {
                        $r[$j]->const = match ($lts[0]->main) {
                            BasicType::INT, BasicType::UINT => Constant::toInt($r[$j]->const),
                            BasicType::FLOAT => Constant::toFloat($r[$j]->const),
                            default => $r[$j]->const,
                        };
                    }
                }

                if (count($lhs) === 1) {
                    $stmts[] = new Stmt(StmtType::ASSIGN, [$l[0], $r[0]]);
                } else {
                    // For variable swapping, use temporary variables.
                    $t = $rts[0]->main === BasicType::NONE ? self::toDefaultType($r[0]->const) : $rts[0];
                    $block->vars[] = new Variable('', $t);
                    $idx = $block->totalLocalVariableCount() - 1;
                    $stmts[] = new Stmt(StmtType::ASSIGN, [new Expr(ExprType::LOCAL_VARIABLE, index: $idx), $r[0]]);
                    $localVariablIndicesToAssignLater[] = $idx;
                    $leftExprsToAssignLater[] = $l[0];
                }
            }
            foreach ($localVariablIndicesToAssignLater as $i => $idx) {
                $stmts[] = new Stmt(StmtType::ASSIGN, [$leftExprsToAssignLater[$i], new Expr(ExprType::LOCAL_VARIABLE, index: $idx)]);
            }
        } else {
            [$rhsExprs, $rhsTypes, $ss] = $this->parseExpr($block, $fname, $rhs[0], true);
            if (count($lhs) !== count($rhsExprs)) {
                $this->fail($pos, sprintf('assignment mismatch: %d variables but %d', count($lhs), count($rhsExprs)));
            }
            array_push($stmts, ...$ss);

            foreach ($lhs as $i => $e) {
                if ($define) {
                    // TODO: This is to determine a type when the rhs values are constants (not literals),
                    // but there are no actual cases when len(lhs) != len(rhs). Is this correct?
                    $declare($e, fn(): Type => $rhsTypes[$i]->main === BasicType::NONE ? self::toDefaultType($rhsExprs[$i]->const) : $rhsTypes[$i]);
                }

                [$l, $lts, $ss] = $this->parseExpr($block, $fname, $lhs[$i], false);
                array_push($stmts, ...$ss);
                if (count($l) !== 1) {
                    $this->fail($pos, sprintf('unexpected count of expressions in lhs: %d', count($l)));
                }
                if (count($lts) !== 1) {
                    $this->fail($pos, sprintf('unexpected count of types in lhs: %d', count($lts)));
                }

                if ($l[0]->type === ExprType::BLANK) {
                    continue;
                }

                $this->checkAssignmentTarget($e->pos, $l[0]);

                if (!self::canAssign($lts[0], $rhsTypes[$i], $rhsExprs[$i])) {
                    $this->fail($e->pos, sprintf('cannot use type %s as type %s in assignment', $rhsTypes[$i]->string(), $lts[0]->string()));
                }
                $stmts[] = new Stmt(StmtType::ASSIGN, [$l[0], $rhsExprs[$i]]);
            }
        }

        if ($define && !$newVariable) {
            $this->fail($pos, 'no new variables on left side of :=');
        }
        return $stmts;
    }

    /** Reports, and stops, when a local variable cannot have the type $t. */
    private function checkLocalVariableType(int $pos, Type $t): void
    {
        if ($t->main === BasicType::TEXTURE) {
            $this->fail($pos, 'a texture cannot be assigned to a variable');
        }
    }

    /** Reports, and stops, when $e cannot be assigned. */
    private function checkAssignmentTarget(int $pos, Expr $e): void
    {
        switch ($e->type) {
            case ExprType::LOCAL_VARIABLE:
                return;
            case ExprType::UNIFORM_VARIABLE:
                $this->fail($pos, 'a uniform variable cannot be assigned');
            case ExprType::TEXTURE_VARIABLE:
                $this->fail($pos, 'a texture variable cannot be assigned');
            case ExprType::FIELD_SELECTOR:
                $s = $e->exprs[1];
                if ($s->type === ExprType::SWIZZLING_EXPR && self::hasDuplicatedSwizzlingComponent($s->swizzling)) {
                    $this->fail($pos, "cannot assign to a swizzling with a duplicated component: {$s->sourceSwizzling()}");
                }
                $this->checkAssignmentTarget($pos, $e->exprs[0]);
                return;
            case ExprType::INDEX:
                $this->checkAssignmentTarget($pos, $e->exprs[0]);
                return;
            case ExprType::FUNCTION_EXPR:
            case ExprType::BUILTIN_FUNC_EXPR:
                $this->fail($pos, 'a function cannot be assigned');
            case ExprType::CALL:
                $this->fail($pos, 'a function call cannot be assigned');
        }
        $this->fail($pos, 'a non-variable expression cannot be assigned');
    }

    private static function hasDuplicatedSwizzlingComponent(string $swizzling): bool
    {
        return count(array_unique(str_split($swizzling))) !== strlen($swizzling);
    }

    /** Whether $v is a numeric constant equal to zero. */
    private static function isConstZero(?Constant $v): bool
    {
        return $v !== null && ($v->kind === Constant::INT || $v->kind === Constant::FLOAT) && Constant::sign($v) === 0;
    }

    private static function toDefaultType(Constant $v): Type
    {
        return match ($v->kind) {
            Constant::BOOL => new Type(BasicType::BOOL),
            Constant::INT => new Type(BasicType::INT),
            Constant::FLOAT => new Type(BasicType::FLOAT),
            // TODO: Should this be an error?
            default => new Type,
        };
    }

    /**
     * The name of $t, the type of an expression whose constant is $c: for
     * an untyped constant, "untyped " and the name of its default type.
     */
    private static function typeString(Type $t, ?Constant $c): string
    {
        if ($t->main === BasicType::NONE && $c !== null) {
            $d = self::toDefaultType($c);
            if ($d->main !== BasicType::NONE) {
                return 'untyped ' . $d->string();
            }
        }
        return $t->string();
    }

    /** Whether $r, an expression of type $rt, can be assigned to a variable of type $lt. */
    private static function canAssign(Type $lt, Type $rt, Expr $r): bool
    {
        if ($lt->equal($rt)) {
            return true;
        }
        // An untyped shift takes the type it goes to.
        if ($r->untyped && $lt->main === BasicType::UINT) {
            return true;
        }
        $rc = $r->const;
        if ($rc === null || !$rt->equal(new Type)) {
            return false;
        }
        return match ($lt->main) {
            BasicType::BOOL => $rc->kind === Constant::BOOL,
            BasicType::INT, BasicType::UINT => self::canTruncateToInteger($rc),
            BasicType::FLOAT => self::canTruncateToFloat($rc),
            default => false,
        };
    }

    /**
     * @param list<Variable> $inParams
     * @param list<Variable> $outParams
     * @return list<Stmt>
     */
    private function parseFor(Block $block, string $fname, Syntax\ForStmt $stmt, array $inParams, array $outParams, Type $returnType): array
    {
        $msg = 'for-statement must follow this format: for (varname) := (expression); (varname) (op) (expression); (varname) (op) (constant) { ...';
        if ($stmt->init === null || $stmt->cond === null || $stmt->post === null) {
            $this->fail($stmt->pos, $msg);
        }

        // Create a new pseudo block for the initial statement, so that the counter variable belongs to the
        // new pseudo block for each for-loop. Without this, the same-named counter variables in different
        // for-loops confuse the parser.
        $pseudoBlock = $this->parseBlock($block, $fname, [$stmt->init], $inParams, $outParams, $returnType, false);
        $pseudoBlock->loop = true;
        $ss = $pseudoBlock->ir->stmts;

        if (count($ss) !== 1 || $ss[0]->type !== StmtType::ASSIGN || $ss[0]->exprs[0]->type !== ExprType::LOCAL_VARIABLE) {
            $this->fail($stmt->init->pos, $msg);
        }
        $varidx = $ss[0]->exprs[0]->index;
        if (count($pseudoBlock->vars) !== 1) {
            $this->fail($stmt->init->pos, $msg);
        }

        $vartype = $pseudoBlock->vars[0]->typ;
        // The loop starts from an expression and runs to another, evaluated
        // before each iteration, as MyGo's renderers' shading languages allow.
        $initExpr = $ss[0]->exprs[1];

        [$exprs, $ts, $ss] = $this->parseExpr($pseudoBlock, $fname, $stmt->cond, true);
        if (count($exprs) !== 1) {
            $this->fail($stmt->cond->pos, $msg);
        }
        if (count($ts) !== 1 || $ts[0]->main !== BasicType::BOOL) {
            $this->fail($stmt->cond->pos, "for-statement's condition must be bool");
        }
        if ($ss !== [] || $exprs[0]->type !== ExprType::BINARY) {
            $this->fail($stmt->cond->pos, $msg);
        }
        $op = $exprs[0]->op;
        if (!in_array($op, [Op::LESS_THAN_OP, Op::LESS_THAN_EQUAL_OP, Op::GREATER_THAN_OP, Op::GREATER_THAN_EQUAL_OP, Op::EQUAL_OP, Op::NOT_EQUAL_OP], true)) {
            $this->fail($stmt->cond->pos, "for-statement's condition must have one of these operators: <, <=, >, >=, ==, !=");
        }
        if ($exprs[0]->exprs[0]->type !== ExprType::LOCAL_VARIABLE || $exprs[0]->exprs[0]->index !== $varidx) {
            $this->fail($stmt->cond->pos, $msg);
        }
        $endExpr = $exprs[0]->exprs[1];

        $postSs = $this->parseStmt($pseudoBlock, $fname, $stmt->post, $inParams, $outParams, $returnType);
        $post = $postSs[0] ?? null;
        if (count($postSs) !== 1 || $post->type !== StmtType::ASSIGN
            || $post->exprs[0]->type !== ExprType::LOCAL_VARIABLE || $post->exprs[0]->index !== $varidx
            || $post->exprs[1]->type !== ExprType::BINARY
            || $post->exprs[1]->exprs[0]->type !== ExprType::LOCAL_VARIABLE || $post->exprs[1]->exprs[0]->index !== $varidx
            || $post->exprs[1]->exprs[1]->const === null) {
            $this->fail($stmt->post->pos, $msg);
        }
        $delta = match ($post->exprs[1]->op) {
            Op::ADD => $post->exprs[1]->exprs[1]->const,
            Op::SUB => Constant::unaryOp('-', $post->exprs[1]->exprs[1]->const, 0),
            default => $this->fail($stmt->post->pos, "for-statement's post statement must have one of these operators: +=, -=, ++, --"),
        };

        $b = $this->parseBlock($pseudoBlock, $fname, [$stmt->body], $inParams, $outParams, $returnType, true);
        $bodyir = $b->ir;
        while (count($bodyir->stmts) === 1 && $bodyir->stmts[0]->type === StmtType::BLOCK_STMT) {
            $bodyir = $bodyir->stmts[0]->blocks[0];
        }

        // As the pseudo block is not actually used, copy the variable part to the actual block.
        // This must be done after parsing the for-loop is done, or the duplicated variables confuse the
        // parsing.
        // The scope of the counter variable ends with this for-loop. Clear its name so that the
        // variable is neither found nor checked by its name anymore. The variable itself is still kept
        // for the local-variable indices.
        $block->vars[] = new Variable('', $vartype, true);

        return [new Stmt(StmtType::FOR, [$initExpr, $endExpr], [$bodyir], $vartype, $varidx, $initExpr->const, $endExpr->const, $op, $delta)];
    }

    /** The name and the position of an iteration variable $e of a range-statement, a blank one at $defaultPos when it has none. @return array{0: string, 1: int} */
    private function parseRangeVar(?Syntax\Expr $e, int $defaultPos, string $msg): array
    {
        if ($e === null) {
            return ['_', $defaultPos];
        }
        if (!$e instanceof Syntax\Ident) {
            $this->fail($e->pos, $msg);
        }
        return [$e->name, $e->pos];
    }

    /**
     * @param list<Variable> $inParams
     * @param list<Variable> $outParams
     * @return list<Stmt>
     */
    private function parseForRange(Block $block, string $fname, Syntax\RangeStmt $stmt, array $inParams, array $outParams, Type $returnType): array
    {
        $msg = 'range-statement must follow this format: for (varname) := range (constant integer or array) { ...';

        [$exprs, $ts, $ss] = $this->parseExpr($block, $fname, $stmt->x, true);
        if (count($exprs) !== 1 || count($ts) !== 1) {
            $this->fail($stmt->x->pos, $msg);
        }
        if ($ss !== []) {
            $this->fail($stmt->x->pos, 'a range expression must be a constant or a variable');
        }
        $t = $ts[0];
        if ($t->main === BasicType::NONE && $exprs[0]->const !== null) {
            $t = self::toDefaultType($exprs[0]->const);
        }

        $end = null; $endExpr = null; $elmType = new Type;
        // The counter is of the count's type, int or uint.
        $vartype = new Type(BasicType::INT);
        switch ($t->main) {
            case BasicType::INT:
            case BasicType::UINT:
                $vartype = new Type($t->main);
                if ($stmt->value !== null) {
                    $this->fail($stmt->value->pos, 'range over an integer permits only one iteration variable');
                }
                if ($exprs[0]->const === null) {
                    // The count is evaluated before each iteration.
                    $endExpr = $exprs[0];
                    break;
                }
                $end = Constant::toInt($exprs[0]->const);
                break;
            case BasicType::ARRAY:
                // The array is indexed at each iteration, so the range expression must be one that can be
                // evaluated repeatedly.
                if ($stmt->value !== null && $exprs[0]->type !== ExprType::LOCAL_VARIABLE && $exprs[0]->type !== ExprType::UNIFORM_VARIABLE) {
                    $this->fail($stmt->x->pos, 'a range expression must be a variable to use the second iteration variable');
                }
                $end = Constant::int($t->length);
                $elmType = $t->sub[0];
                break;
            default:
                $this->fail($stmt->x->pos, "cannot range over a value of type {$t->string()}");
        }

        [$keyname, $keypos] = $this->parseRangeVar($stmt->key, $stmt->pos, $msg);
        [$valname, $valpos] = $this->parseRangeVar($stmt->value, $stmt->pos, $msg);
        switch ($stmt->tok) {
            case ':=':
                if ($keyname === '_' && $valname === '_') {
                    $this->fail($keypos, 'no new variables on left side of :=');
                }
                break;
            case '=':
                // A range-statement introduces its own iteration variables, so an existing variable cannot be
                // one of them.
                if ($keyname !== '_' || $valname !== '_') {
                    $this->fail($keypos, $msg);
                }
                break;
        }

        // A count that is not a constant is read once, before the loop, as Go reads it: into a local of
        // its own, which the body cannot reach (MyGo's).
        $count = [];
        if ($end === null) {
            $idx = $block->totalLocalVariableCount();
            $block->vars[] = new Variable('', $vartype);
            $local = new Expr(ExprType::LOCAL_VARIABLE, index: $idx);
            $count[] = new Stmt(StmtType::ASSIGN, [$local, $endExpr]);
            $endExpr = clone $local;
        }

        // Create a new pseudo block for the iteration variables, so that the variables belong to the new
        // pseudo block for each for-loop. Without this, the same-named variables in different for-loops
        // confuse the parser.
        $pseudoBlock = $this->parseBlock($block, $fname, [], $inParams, $outParams, $returnType, false);
        $pseudoBlock->loop = true;
        $varidx = $pseudoBlock->totalLocalVariableCount();
        $pseudoBlock->addNamedLocalVariable($keyname, $vartype, $keypos);
        $validx = $pseudoBlock->totalLocalVariableCount();
        if ($stmt->value !== null) {
            $pseudoBlock->addNamedLocalVariable($valname, $elmType, $valpos);
        }

        $b = $this->parseBlock($pseudoBlock, $fname, [$stmt->body], $inParams, $outParams, $returnType, true);
        foreach ($pseudoBlock->vars as $i => $v) {
            if (isset($pseudoBlock->unusedVars[$i])) {
                $this->fail($pseudoBlock->unusedVars[$i], "local variable {$v->name} is not used");
            }
        }

        $bodyir = $b->ir;
        while (count($bodyir->stmts) === 1 && $bodyir->stmts[0]->type === StmtType::BLOCK_STMT) {
            $bodyir = $bodyir->stmts[0]->blocks[0];
        }
        if ($stmt->value !== null) {
            array_unshift($bodyir->stmts, new Stmt(StmtType::ASSIGN, [
                new Expr(ExprType::LOCAL_VARIABLE, index: $validx),
                new Expr(ExprType::INDEX, [$exprs[0], new Expr(ExprType::LOCAL_VARIABLE, index: $varidx)]),
            ]));
        }

        // As the pseudo block is not actually used, copy the variable part to the actual block.
        // This must be done after parsing the for-loop is done, or the duplicated variables confuse the
        // parsing.
        // The scopes of the iteration variables end with this for-loop. Clear their names so that the
        // variables are neither found nor checked by their names anymore. The variables themselves are
        // still kept for the local-variable indices.
        $block->vars[] = new Variable('', $vartype, true);
        if ($stmt->value !== null) {
            $block->vars[] = new Variable('', $elmType);
        }

        if ($end !== null) {
            $endExpr = new Expr(ExprType::NUMBER_EXPR, const: $end);
        }
        $count[] = new Stmt(StmtType::FOR, [new Expr(ExprType::NUMBER_EXPR, const: Constant::int(0)), $endExpr], [$bodyir], $vartype, $varidx,
            Constant::int(0), $end, Op::LESS_THAN_OP, Constant::int(1));
        return $count;
    }
}
