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
use MyPHP\UI\Shader\Ir\{BasicType, BuiltinFunc, Constant, Expr, ExprType, Op, TypeOf};

/** Marks the expressions of type uint or uvecN (unsigned.go's unsignedMarker). @internal */
final class UnsignedMarker
{
    public function __construct(
        /** @var Closure(int, int): \MyPHP\UI\Shader\Ir\Type */
        private readonly Closure $env,
        /** @var list<Func> */
        private readonly array $funcs,
    ) {}

    /** Marks $e and its operands, $e an expression whose integer constants have the type $want. */
    public function mark(Expr $e, int $want): void
    {
        switch ($e->type) {
            case ExprType::NUMBER_EXPR:
                $e->unsigned = $e->unsigned || $want === BasicType::UINT && $e->const->kind === Constant::INT;
                return;
            case ExprType::UNARY:
                $this->mark($e->exprs[0], $e->op === Op::NOT_OP ? BasicType::BOOL : $want);
                break;
            case ExprType::BINARY:
                if ($e->op === Op::LEFT_SHIFT || $e->op === Op::RIGHT_SHIFT) {
                    $this->operands([$e->exprs[0]], $want);
                    $this->operands([$e->exprs[1]], BasicType::INT);
                } else {
                    $this->operands($e->exprs, $want);
                }
                break;
            case ExprType::SELECTION:
                $this->mark($e->exprs[0], BasicType::BOOL);
                $this->operands(array_slice($e->exprs, 1), $want);
                break;
            case ExprType::CALL:
                $this->call($e);
                break;
            case ExprType::FIELD_SELECTOR:
                $this->own($e->exprs[0]);
                break;
            case ExprType::INDEX:
                $this->own($e->exprs[0]);
                $this->mark($e->exprs[1], BasicType::INT);
                break;
        }
        $e->unsigned = TypeOf::of($e, $this->env)->scalar() === BasicType::UINT;
    }

    /** Marks $e as of its own type. */
    private function own(Expr $e): void
    {
        $this->mark($e, TypeOf::of($e, $this->env)->scalar());
    }

    /**
     * Marks $es, operands of one type: constants and untyped shifts among
     * them take the type of the others, or $want when all are such.
     * @param list<Expr> $es
     */
    private function operands(array $es, int $want): void
    {
        foreach ($es as $x) {
            if (!self::flexible($x)) {
                $want = TypeOf::of($x, $this->env)->scalar();
                break;
            }
        }
        foreach ($es as $x) {
            if (self::flexible($x)) {
                $this->mark($x, $want);
            } else {
                $this->own($x);
            }
        }
    }

    /** Whether $e takes the type of its context: a constant not yet marked as a uint, or an untyped shift. */
    private static function flexible(Expr $e): bool
    {
        return $e->type === ExprType::NUMBER_EXPR && !$e->unsigned || $e->untyped;
    }

    private function call(Expr $e): void
    {
        $callee = $e->exprs[0];
        $args = array_slice($e->exprs, 1);
        if ($callee->type === ExprType::FUNCTION_EXPR) {
            $f = $this->funcs[$callee->index]->ir;
            foreach ($args as $i => $a) {
                if ($i < count($f->inParams)) {
                    $this->mark($a, $f->inParams[$i]->scalar());
                } else {
                    $this->own($a);
                }
            }
            return;
        }
        // A constructor's constants take its kind; other functions' arguments
        // share a type.
        $kind = match ($callee->builtinFunc) {
            BuiltinFunc::UINT_F, BuiltinFunc::UVEC2_F, BuiltinFunc::UVEC3_F, BuiltinFunc::UVEC4_F => BasicType::UINT,
            BuiltinFunc::INT_F, BuiltinFunc::IVEC2_F, BuiltinFunc::IVEC3_F, BuiltinFunc::IVEC4_F => BasicType::INT,
            BuiltinFunc::FLOAT_F, BuiltinFunc::VEC2_F, BuiltinFunc::VEC3_F, BuiltinFunc::VEC4_F,
            BuiltinFunc::MAT2_F, BuiltinFunc::MAT3_F, BuiltinFunc::MAT4_F => BasicType::FLOAT,
            BuiltinFunc::BOOL_F => BasicType::BOOL,
            default => null,
        };
        if ($kind === null) {
            $this->operands($args, BasicType::INT);
            return;
        }
        foreach ($args as $a) {
            if (self::flexible($a)) {
                $this->mark($a, $kind);
            } else {
                $this->own($a);
            }
        }
    }
}
