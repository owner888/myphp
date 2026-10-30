<?php
// Copyright 2023 The Ebitengine Authors
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

// Ported to PHP from MyGo's internal/shaderir (check.go), MyGo's fork of
// Ebitengine's (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Ir;

use LogicException;

/** The types of binary operations and their untyped constants (shaderir's check.go). @internal */
final class Check
{
    /**
     * $lhs and $rhs, constants of the operands of $op typed $lhst and
     * $rhst (null for none), as the operation takes them: an untyped one
     * converted to the other's kind. Null where they can't be.
     * @return ?array{0: ?Constant, 1: ?Constant}
     */
    public static function resolveUntypedConstsForBinaryOp(int $op, ?Constant $lhs, ?Constant $rhs, Type $lhst, Type $rhst): ?array
    {
        if ($lhst->main === BasicType::NONE && $rhst->main === BasicType::NONE) {
            if ($op === Op::LEFT_SHIFT || $op === Op::RIGHT_SHIFT) {
                $newLhs = Constant::toInt($lhs);
                $newRhs = Constant::toInt($rhs);
                if ($newLhs->kind === Constant::UNKNOWN || $newRhs->kind === Constant::UNKNOWN) {
                    return null;
                }
                return [$newLhs, $newRhs];
            }
            if ($lhs->kind === $rhs->kind) {
                return [$lhs, $rhs];
            }
            if ($lhs->kind === Constant::FLOAT && Constant::toFloat($rhs)->kind !== Constant::UNKNOWN) {
                return [$lhs, Constant::toFloat($rhs)];
            }
            if ($rhs->kind === Constant::FLOAT && Constant::toFloat($lhs)->kind !== Constant::UNKNOWN) {
                return [Constant::toFloat($lhs), $rhs];
            }
            if ($lhs->kind === Constant::INT && Constant::toInt($rhs)->kind !== Constant::UNKNOWN) {
                return [$lhs, Constant::toInt($rhs)];
            }
            if ($rhs->kind === Constant::INT && Constant::toInt($lhs)->kind !== Constant::UNKNOWN) {
                return [Constant::toInt($lhs), $rhs];
            }
            return null;
        }

        if ($lhst->main === BasicType::NONE) {
            if (($rhst->main === BasicType::FLOAT || $rhst->isFloatVector() || $rhst->isMatrix()) && Constant::toFloat($lhs)->kind !== Constant::UNKNOWN) {
                return [Constant::toFloat($lhs), $rhs];
            }
            if ($rhst->isInteger() && Constant::toInt($lhs)->kind !== Constant::UNKNOWN) {
                return [Constant::toInt($lhs), $rhs];
            }
            if ($rhst->main === BasicType::BOOL && $lhs->kind === Constant::BOOL) {
                return [$lhs, $rhs];
            }
            return null;
        }

        if ($rhst->main === BasicType::NONE) {
            if (($lhst->main === BasicType::FLOAT || $lhst->isFloatVector() || $lhst->isMatrix()) && Constant::toFloat($rhs)->kind !== Constant::UNKNOWN) {
                return [$lhs, Constant::toFloat($rhs)];
            }
            if ($lhst->isInteger() && Constant::toInt($rhs)->kind !== Constant::UNKNOWN) {
                return [$lhs, Constant::toInt($rhs)];
            }
            if ($lhst->main === BasicType::BOOL && $rhs->kind === Constant::BOOL) {
                return [$lhs, $rhs];
            }
            return null;
        }

        // lhst and rhst might not match, but this has nothing to do with resolving untyped consts.
        return [$lhs, $rhs];
    }

    /** The type of $lhst $op $rhst, their constants $lhsConst and $rhsConst (null for none), or null where the operation is not defined. */
    public static function typeFromBinaryOp(int $op, Type $lhst, Type $rhst, ?Constant $lhsConst, ?Constant $rhsConst): ?Type
    {
        if (in_array($op, [Op::ADD, Op::SUB, Op::COMPONENT_WISE_MUL, Op::MATRIX_MUL, Op::DIV], true)) {
            foreach ([BasicType::BOOL, BasicType::ARRAY, BasicType::TEXTURE] as $m) {
                if ($lhst->main === $m || $rhst->main === $m) {
                    return null;
                }
            }
            if ($lhsConst?->kind === Constant::BOOL || $rhsConst?->kind === Constant::BOOL) {
                return null;
            }
        }

        // If both are untyped consts, compare the constants and try to truncate them if necessary.
        if ($lhst->main === BasicType::NONE && $rhst->main === BasicType::NONE) {
            // Assume that the constant types are already adjusted.
            if ($lhsConst->kind !== $rhsConst->kind) {
                throw new LogicException('shaderir: const types for a binary op must be adjusted');
            }
            if ($op === Op::AND_AND || $op === Op::OR_OR) {
                return $lhsConst->kind === Constant::BOOL ? new Type(BasicType::BOOL) : null;
            }
            // For %, both operands must be integers if both are constants. Truncatable to an integer is not enough.
            // So for the bitwise operators and the shifts.
            if (in_array($op, [Op::MOD_OP, Op::AND, Op::AND_NOT, Op::OR, Op::XOR, Op::LEFT_SHIFT, Op::RIGHT_SHIFT], true)) {
                return $lhsConst->kind === Constant::INT ? new Type : null;
            }
            if (in_array($op, [Op::EQUAL_OP, Op::NOT_EQUAL_OP, Op::LESS_THAN_OP, Op::LESS_THAN_EQUAL_OP, Op::GREATER_THAN_OP, Op::GREATER_THAN_EQUAL_OP], true)) {
                return new Type(BasicType::BOOL);
            }
            // If both operands are untyped, keep untyped.
            return new Type;
        }

        // Both types must not be untyped.
        if ($lhst->main === BasicType::NONE || $rhst->main === BasicType::NONE) {
            throw new LogicException('shaderir: cannot resolve untyped values');
        }

        if ($op === Op::AND_AND || $op === Op::OR_OR) {
            return $lhst->main === BasicType::BOOL && $rhst->main === BasicType::BOOL ? new Type(BasicType::BOOL) : null;
        }

        if ($op === Op::VECTOR_EQUAL_OP || $op === Op::VECTOR_NOT_EQUAL_OP) {
            return ($lhst->isFloatVector() || $lhst->isIntegerVector()) && $lhst->equal($rhst) ? new Type(BasicType::BOOL) : null;
        }

        if (in_array($op, [Op::LESS_THAN_OP, Op::LESS_THAN_EQUAL_OP, Op::GREATER_THAN_OP, Op::GREATER_THAN_EQUAL_OP], true)) {
            $scalar = in_array($lhst->main, [BasicType::INT, BasicType::UINT, BasicType::FLOAT], true);
            return $scalar && $lhst->main === $rhst->main ? new Type(BasicType::BOOL) : null;
        }

        // Comparing matrices is forbidden (#2187).
        // Comparing arrays is forbidden as well, as most of the shading languages don't have the
        // operation (#3535).
        // Comparing textures is forbidden as well, as a texture is an opaque handle.
        if ($op === Op::EQUAL_OP || $op === Op::NOT_EQUAL_OP) {
            if ($lhst->isMatrix() || $rhst->isMatrix()) {
                return null;
            }
            if ($lhst->main === BasicType::ARRAY || $rhst->main === BasicType::ARRAY || $lhst->main === BasicType::TEXTURE || $rhst->main === BasicType::TEXTURE) {
                return null;
            }
            return $lhst->equal($rhst) ? new Type(BasicType::BOOL) : null;
        }

        if ($op === Op::DIV && $rhst->isMatrix()) {
            return null;
        }

        // The operands of %, &, &^, |, ^ are integers of one kind: int and ivecN,
        // or uint and uvecN (MyGo's), a vector with a vector of its size or a
        // scalar (on either side, but for %).
        if (in_array($op, [Op::MOD_OP, Op::AND, Op::AND_NOT, Op::OR, Op::XOR], true)) {
            if (!$lhst->isInteger() || !$rhst->isInteger() || $lhst->scalar() !== $rhst->scalar()) {
                return null;
            }
            if ($lhst->equal($rhst)) {
                return $lhst;
            }
            if ($lhst->isIntegerVector() && !$rhst->isIntegerVector()) {
                return $lhst;
            }
            if ($op !== Op::MOD_OP && !$lhst->isIntegerVector() && $rhst->isIntegerVector()) {
                return $rhst;
            }
            return null;
        }

        // A shift's count may be of either kind of integer, as in Go: a scalar,
        // or a vector of the shifted vector's size.
        if ($op === Op::LEFT_SHIFT || $op === Op::RIGHT_SHIFT) {
            if (!$lhst->isInteger() || !$rhst->isInteger()) {
                return null;
            }
            if (!$rhst->isIntegerVector()) {
                return $lhst;
            }
            return $lhst->isIntegerVector() && $lhst->vectorElementCount() === $rhst->vectorElementCount() ? $lhst : null;
        }

        if ($lhst->equal($rhst)) {
            return $lhst;
        }

        if ($op === Op::MATRIX_MUL) {
            return match (true) {
                $lhst->isMatrix() && $rhst->main === BasicType::FLOAT => $lhst,
                $lhst->main === BasicType::MAT2 && $rhst->main === BasicType::VEC2,
                $lhst->main === BasicType::MAT3 && $rhst->main === BasicType::VEC3,
                $lhst->main === BasicType::MAT4 && $rhst->main === BasicType::VEC4 => $rhst,
                $lhst->main === BasicType::FLOAT && $rhst->isMatrix() => $rhst,
                $lhst->main === BasicType::VEC2 && $rhst->main === BasicType::MAT2,
                $lhst->main === BasicType::VEC3 && $rhst->main === BasicType::MAT3,
                $lhst->main === BasicType::VEC4 && $rhst->main === BasicType::MAT4 => $lhst,
                default => null,
            };
        }

        if ($op === Op::DIV && $lhst->isMatrix() && $rhst->main === BasicType::FLOAT) {
            return $lhst;
        }

        return match (true) {
            $lhst->isFloatVector() && $rhst->main === BasicType::FLOAT => $lhst,
            $lhst->main === BasicType::FLOAT && $rhst->isFloatVector() => $rhst,
            $lhst->isIntVector() && $rhst->main === BasicType::INT => $lhst,
            $lhst->main === BasicType::INT && $rhst->isIntVector() => $rhst,
            $lhst->isUintVector() && $rhst->main === BasicType::UINT => $lhst,
            $lhst->main === BasicType::UINT && $rhst->isUintVector() => $rhst,
            default => null,
        };
    }
}
