<?php
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

// Ported to PHP from MyGo's internal/shader (unsigned.go, MyGo's own, under its
// package's license, see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Compiler;

use Closure;
use MyPHP\UI\Shader\Ir\{BasicType, Constant, Expr, ExprType, Stmt, StmtType, Type, TypeOf};

/** The typing of a block's integer constants, uint or int (unsigned.go). @internal */
trait Unsigned
{
    /** The types of what the expressions of $b refer to, as TypeOf asks them (unsigned.go's blockEnv). @return Closure(int, int): Type */
    private function blockEnv(Block $b): Closure
    {
        return function (int $what, int $index) use ($b): Type {
            if ($what === ExprType::UNIFORM_VARIABLE) {
                return $this->ir->uniforms[$index];
            }
            if ($what === ExprType::FUNCTION_EXPR) {
                return $this->funcs[$index]->ir->return;
            }
            $chain = [];
            for ($c = $b; $c !== null; $c = $c->outer) {
                $chain[] = $c;
            }
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                if ($index < count($chain[$i]->vars)) {
                    return $chain[$i]->vars[$index]->typ;
                }
                $index -= count($chain[$i]->vars);
            }
            return new Type;
        };
    }

    /**
     * Marks the expressions of $stmts, statements of the block $b in a
     * function returning $returnType, that are of type uint or uvecN
     * (Expr's unsigned), integer constants among them, which take the type
     * of the operands they go with, or of the variable, the parameter or
     * the result they go to. Then it checks that every integer constant
     * fits its type, reporting at $pos those that do not, and stops if one
     * does not. Nested blocks are not marked, as parseBlock marks each
     * block it parses.
     * @param list<Stmt> $stmts
     */
    private function typeConsts(int $pos, Block $b, array $stmts, Type $returnType): void
    {
        $env = $this->blockEnv($b);
        $m = new UnsignedMarker($env, $this->funcs);
        foreach ($stmts as $s) {
            switch ($s->type) {
                case StmtType::EXPR_STMT:
                    $m->mark($s->exprs[0], TypeOf::of($s->exprs[0], $env)->scalar());
                    break;
                case StmtType::ASSIGN:
                    $t = TypeOf::of($s->exprs[0], $env)->scalar();
                    $m->mark($s->exprs[0], $t);
                    $m->mark($s->exprs[1], $t);
                    break;
                case StmtType::RETURN:
                    foreach ($s->exprs as $x) {
                        $m->mark($x, $returnType->scalar());
                    }
                    break;
                case StmtType::IF:
                    $m->mark($s->exprs[0], BasicType::BOOL);
                    break;
                case StmtType::FOR:
                    foreach ($s->exprs as $x) {
                        $m->mark($x, $s->forVarType->scalar());
                    }
                    break;
            }
        }

        $ok = true;
        $check = function (Expr $e) use (&$check, &$ok, $pos): void {
            if ($e->type === ExprType::NUMBER_EXPR && $e->const->kind === Constant::INT) {
                if ($e->unsigned) {
                    if (!$this->checkUintConstRange($pos, $e->const)) {
                        $ok = false;
                    }
                } elseif (!$this->checkIntConstRange($pos, $e->const)) {
                    $ok = false;
                }
            }
            foreach ($e->exprs as $x) {
                $check($x);
            }
        };
        foreach ($stmts as $s) {
            foreach ($s->exprs as $x) {
                $check($x);
            }
            if ($s->type !== StmtType::FOR) {
                continue;
            }
            if ($s->forVarType->main === BasicType::UINT) {
                // A uint counter starts and ends at uints, and steps up or down
                // by a uint.
                $delta = $s->forDelta;
                if ($delta !== null && Constant::sign($delta) < 0) {
                    $delta = Constant::unaryOp('-', $delta, 0);
                }
                foreach ([$s->forInit, $s->forEnd, $delta] as $v) {
                    $ok = $this->checkUintConstRange($pos, $v) && $ok;
                }
                continue;
            }
            foreach ([$s->forInit, $s->forEnd, $s->forDelta] as $v) {
                $ok = $this->checkIntConstRange($pos, $v) && $ok;
            }
        }
        if (!$ok) {
            throw new Abort;
        }
    }
}
