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

// Ported to PHP from MyGo's internal/shaderir (exprtype.go, MyGo's own), by
// the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Ir;

use Closure;

/**
 * The types of expressions the compiler checked (shaderir's TypeOf). What
 * they refer to is typed by $env (Go's TypeEnv), called with the
 * ExprType of the reference and its index: LOCAL_VARIABLE for a local
 * variable by its index in its function, UNIFORM_VARIABLE for a uniform
 * variable, FUNCTION_EXPR for a function's result.
 * @internal
 */
final class TypeOf
{
    /**
     * The type of $e. A constant has the type of its kind, bool, float or
     * int, or uint when it is unsigned; a call of a function with output
     * parameters, which only statements make, has none.
     * @param Closure(int, int): Type $env
     */
    public static function of(Expr $e, Closure $env): Type
    {
        switch ($e->type) {
            case ExprType::NUMBER_EXPR:
                return match ($e->const->kind) {
                    Constant::BOOL => new Type(BasicType::BOOL),
                    Constant::FLOAT => new Type(BasicType::FLOAT),
                    Constant::INT => new Type($e->unsigned ? BasicType::UINT : BasicType::INT),
                    default => new Type,
                };
            case ExprType::UNIFORM_VARIABLE:
            case ExprType::LOCAL_VARIABLE:
                return $env($e->type, $e->index);
            case ExprType::TEXTURE_VARIABLE:
                return new Type(BasicType::TEXTURE);
            case ExprType::UNARY:
                return $e->op === Op::NOT_OP ? new Type(BasicType::BOOL) : self::of($e->exprs[0], $env);
            case ExprType::BINARY:
                return self::binaryType($e, $env);
            case ExprType::SELECTION:
                return self::operandType(array_slice($e->exprs, 1), $env);
            case ExprType::CALL:
                return self::callType($e, $env);
            case ExprType::FIELD_SELECTOR:
                $base = self::of($e->exprs[0], $env);
                $m = $e->exprs[1];
                return match ($m->type) {
                    ExprType::SWIZZLING_EXPR => new Type(Type::vectorOf($base->scalar(), strlen($m->swizzling))),
                    ExprType::STRUCT_MEMBER => $base->sub[$m->index],
                    default => new Type,
                };
            case ExprType::INDEX:
                $base = self::of($e->exprs[0], $env);
                return match (true) {
                    $base->main === BasicType::ARRAY => $base->sub[0],
                    $base->isMatrix() => new Type(Type::vectorOf(BasicType::FLOAT, $base->matrixSize())),
                    default => new Type($base->scalar()),
                };
        }
        return new Type;
    }

    /**
     * The type of the first of $es that is not a constant, or of the first
     * when all are: the type that the operands of an operation or a
     * built-in function share.
     * @param list<Expr> $es
     * @param Closure(int, int): Type $env
     */
    private static function operandType(array $es, Closure $env): Type
    {
        foreach ($es as $x) {
            if ($x->type !== ExprType::NUMBER_EXPR) {
                return self::of($x, $env);
            }
        }
        return $es === [] ? new Type : self::of($es[0], $env);
    }

    /** @param Closure(int, int): Type $env */
    private static function binaryType(Expr $e, Closure $env): Type
    {
        $l = self::of($e->exprs[0], $env);
        $r = self::of($e->exprs[1], $env);
        switch ($e->op) {
            case Op::LESS_THAN_OP: case Op::LESS_THAN_EQUAL_OP: case Op::GREATER_THAN_OP: case Op::GREATER_THAN_EQUAL_OP:
            case Op::EQUAL_OP: case Op::NOT_EQUAL_OP: case Op::VECTOR_EQUAL_OP: case Op::VECTOR_NOT_EQUAL_OP:
            case Op::AND_AND: case Op::OR_OR:
                return new Type(BasicType::BOOL);
            case Op::LEFT_SHIFT: case Op::RIGHT_SHIFT:
                return $l;
            case Op::MATRIX_MUL:
                return match (true) {
                    $l->isMatrix() && $r->isMatrix() => $l,
                    $l->isMatrix() && $r->isFloatVector() => $r,
                    $l->isFloatVector() && $r->isMatrix() => $l,
                    $r->isMatrix() => $r,
                    default => $l,
                };
        }
        // A vector or a matrix with a scalar has the vector's or the matrix's
        // type; a constant takes the other operand's.
        $scalars = [BasicType::FLOAT, BasicType::INT, BasicType::UINT, BasicType::BOOL];
        $lscalar = in_array($l->main, $scalars, true);
        $rscalar = in_array($r->main, $scalars, true);
        return match (true) {
            $lscalar && !$rscalar => $r,
            $rscalar && !$lscalar => $l,
            $e->exprs[0]->type === ExprType::NUMBER_EXPR && $e->exprs[1]->type !== ExprType::NUMBER_EXPR => $r,
            default => $l,
        };
    }

    /** @param Closure(int, int): Type $env */
    private static function callType(Expr $e, Closure $env): Type
    {
        $callee = $e->exprs[0];
        $args = array_slice($e->exprs, 1);
        if ($callee->type === ExprType::FUNCTION_EXPR) {
            return $env(ExprType::FUNCTION_EXPR, $callee->index);
        }
        $main = match ($callee->builtinFunc) {
            BuiltinFunc::BOOL_F, BuiltinFunc::FRONT_FACING => BasicType::BOOL,
            BuiltinFunc::INT_F, BuiltinFunc::LEN, BuiltinFunc::CAP => BasicType::INT,
            BuiltinFunc::UINT_F => BasicType::UINT,
            BuiltinFunc::FLOAT_F, BuiltinFunc::LENGTH, BuiltinFunc::DISTANCE, BuiltinFunc::DOT => BasicType::FLOAT,
            BuiltinFunc::VEC2_F => BasicType::VEC2,
            BuiltinFunc::VEC3_F, BuiltinFunc::CROSS => BasicType::VEC3,
            BuiltinFunc::VEC4_F, BuiltinFunc::TEXEL_AT => BasicType::VEC4,
            BuiltinFunc::IVEC2_F => BasicType::IVEC2,
            BuiltinFunc::IVEC3_F => BasicType::IVEC3,
            BuiltinFunc::IVEC4_F => BasicType::IVEC4,
            BuiltinFunc::UVEC2_F => BasicType::UVEC2,
            BuiltinFunc::UVEC3_F => BasicType::UVEC3,
            BuiltinFunc::UVEC4_F => BasicType::UVEC4,
            BuiltinFunc::MAT2_F => BasicType::MAT2,
            BuiltinFunc::MAT3_F => BasicType::MAT3,
            BuiltinFunc::MAT4_F => BasicType::MAT4,
            default => null,
        };
        if ($main !== null) {
            return new Type($main);
        }
        return match ($callee->builtinFunc) {
            BuiltinFunc::STEP => self::of($args[1], $env),
            BuiltinFunc::SMOOTHSTEP => self::of($args[2], $env),
            // A vector comes first when vectors and scalars mix.
            BuiltinFunc::MIN, BuiltinFunc::MAX => self::of($args[0], $env),
            default => self::operandType($args, $env),
        };
    }
}
