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

// Ported to PHP from MyGo's internal/shader (type.go), MyGo's fork of
// Ebitengine's (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Compiler;

use MyPHP\UI\Shader\Ir\{BasicType, Constant, Expr, ExprType, Type};
use MyPHP\UI\Shader\Syntax;

/** The compiler's types (type.go): the types it reads, and the arguments of the conversions. @internal */
trait Types
{
    private function parseType(Block $block, string $fname, Syntax\Expr $expr): Type
    {
        if ($expr instanceof Syntax\Ident) {
            $main = match ($expr->name) {
                'bool' => BasicType::BOOL, 'int' => BasicType::INT, 'float' => BasicType::FLOAT,
                'vec2' => BasicType::VEC2, 'vec3' => BasicType::VEC3, 'vec4' => BasicType::VEC4,
                'ivec2' => BasicType::IVEC2, 'ivec3' => BasicType::IVEC3, 'ivec4' => BasicType::IVEC4,
                'uint' => BasicType::UINT, 'uvec2' => BasicType::UVEC2, 'uvec3' => BasicType::UVEC3, 'uvec4' => BasicType::UVEC4,
                'mat2' => BasicType::MAT2, 'mat3' => BasicType::MAT3, 'mat4' => BasicType::MAT4,
                default => $this->fail($expr->pos, "unexpected type: {$expr->name}"),
            };
            return new Type($main);
        }
        if ($expr instanceof Syntax\ArrayType) {
            if ($expr->len === null) {
                $this->fail($expr->pos, 'array length must be specified');
            }
            if ($expr->len instanceof Syntax\Ellipsis) {
                $this->fail($expr->pos, 'invalid use of [...] array (outside a composite literal)');
            }
            [$exprs] = $this->parseExpr($block, $fname, $expr->len, true);
            if (count($exprs) !== 1) {
                $this->fail($expr->pos, 'invalid length of array');
            }
            if ($exprs[0]->type !== ExprType::NUMBER_EXPR) {
                $this->fail($expr->pos, 'length of array must be a constant number');
            }
            if ($exprs[0]->const->kind !== Constant::INT) {
                $this->fail($expr->pos, "invalid array length {$exprs[0]->const}");
            }
            [$l, $exact] = Constant::int64Val($exprs[0]->const);
            if (!$exact) {
                $this->fail($expr->pos, "invalid array length {$exprs[0]->const}");
            }
            return $this->parseArrayType($block, $fname, $expr, $l);
        }
        if ($expr instanceof Syntax\StructType) {
            $this->fail($expr->pos, 'struct is not implemented');
        }
        $this->fail($expr->pos, 'unexpected type: ' . self::nodeString($expr));
    }

    /**
     * The array type $t of $length elements. The backends emit code for
     * each element, so a huge length takes them an enormous time and
     * memory; a uniform variable cannot be longer anyway: a DirectX 11
     * constant buffer has at most 4096 16-byte registers, an element
     * taking at least one, and Metal passes uniform variables in at most
     * 4 KB.
     */
    private function parseArrayType(Block $block, string $fname, Syntax\ArrayType $t, int $length): Type
    {
        // An array must have at least one element. A zero-length array has nothing to declare, and
        // HLSL rejects it as the error X3059 (array dimensions must be between 1 and 65536).
        if ($length <= 0) {
            $this->fail($t->pos, "invalid array length $length");
        }
        if ($length > 4096) {
            $this->fail($t->pos, "array length $length exceeds the limit 4096");
        }
        $elm = $this->parseType($block, $fname, $t->elt);
        if ($elm->main === BasicType::ARRAY) {
            $this->fail($t->pos, 'array of array is forbidden');
        }
        return new Type(BasicType::ARRAY, [$elm], $length);
    }

    private static function isFloat(Expr $expr, Type $t): bool
    {
        if ($expr->const !== null) {
            return $t->main === BasicType::FLOAT || self::canTruncateToFloat($expr->const);
        }
        return $t->main === BasicType::FLOAT;
    }

    private static function isInt(Expr $expr, Type $t): bool
    {
        if ($expr->const !== null) {
            return $t->main === BasicType::FLOAT || self::canTruncateToInteger($expr->const);
        }
        return $t->main === BasicType::INT;
    }

    private static function isUint(Expr $expr, Type $t): bool
    {
        if ($expr->const !== null) {
            return $t->main === BasicType::UINT || $t->main === BasicType::NONE && self::canTruncateToInteger($expr->const);
        }
        return $t->main === BasicType::UINT;
    }

    /** "(t1, t2...)", the types of $args as an error lists them. @param list<Expr> $args @param list<Type> $argts */
    private static function argTypes(array $args, array $argts): string
    {
        $str = [];
        foreach ($argts as $i => $t) {
            $str[] = self::typeString($t, $args[$i]->const);
        }
        return '(' . implode(', ', $str) . ')';
    }

    /** Whether $t is a float vector of $n components. */
    private static function isVecN(Type $t, int $n): bool
    {
        return $t->isFloatVector() && $t->vectorElementCount() === $n;
    }

    /** Whether $t is an int vector of $n components. */
    private static function isIVecN(Type $t, int $n): bool
    {
        return $t->isIntVector() && $t->vectorElementCount() === $n;
    }

    /** Whether $t is a vector of any kind of $n components, which a vector's constructor converts. */
    private static function isAnyVecN(Type $t, int $n): bool
    {
        return ($t->isFloatVector() || $t->isIntegerVector()) && $t->vectorElementCount() === $n;
    }

    /** The error of the arguments of bool, int, uint or float ($name), or null for none. @param list<Expr> $args @param list<Type> $argts */
    private static function checkArgsForScalarBuiltinFunc(string $name, array $args, array $argts): ?string
    {
        if (count($args) !== count($argts)) {
            return sprintf("the number of arguments and types doesn't match: %d vs %d", count($args), count($argts));
        }
        if (count($args) !== 1) {
            return sprintf("number of %s's arguments must be 1 but %d", $name, count($args));
        }
        $c = $args[0]->const;
        if ($name === 'bool') {
            if ($argts[0]->main === BasicType::BOOL || $c !== null && $c->kind === Constant::BOOL) {
                return null;
            }
        } else {
            if (in_array($argts[0]->main, [BasicType::INT, BasicType::UINT, BasicType::FLOAT], true)) {
                return null;
            }
            if ($c !== null && ($name === 'float' ? Constant::toFloat($c) : Constant::toInt($c))->kind !== Constant::UNKNOWN) {
                return null;
            }
        }
        return sprintf('invalid arguments for %s: (%s)', $name, self::typeString($argts[0], $c));
    }

    /**
     * The error of the arguments of vec2, vec3 or vec4 ($n components), or
     * ivec2, ivec3 or ivec4 with $int, or null for none: floats (ints) and
     * vectors of their kind of $n components in all, or one scalar, or one
     * vector of any kind of $n components, converted.
     * @param list<Expr> $args
     * @param list<Type> $argts
     */
    private static function checkArgsForVecBuiltinFunc(int $n, bool $int, array $args, array $argts): ?string
    {
        $name = ($int ? 'ivec' : 'vec') . $n;
        if (count($args) !== count($argts)) {
            return sprintf("the number of arguments and types doesn't match: %d vs %d", count($args), count($argts));
        }
        $scalar = fn(int $i): bool => $int ? self::isInt($args[$i], $argts[$i]) : self::isFloat($args[$i], $argts[$i]);
        $vec = fn(int $i, int $m): bool => $int ? self::isIVecN($argts[$i], $m) : self::isVecN($argts[$i], $m);
        // The shapes of the arguments each count takes: s a scalar, a digit a vector of so many.
        $shapes = match ($n) {
            2 => [1 => [], 2 => ['ss']],
            3 => [1 => [], 2 => ['s2', '2s'], 3 => ['sss']],
            4 => [1 => [], 2 => ['s3', '22', '3s'], 3 => ['ss2', 's2s', '2ss'], 4 => ['ssss']],
        };
        if (!isset($shapes[count($args)])) {
            return "invalid number of arguments for $name";
        }
        if (count($args) === 1) {
            // Allow any vectors to perform a cast-like function.
            if ($scalar(0) || self::isAnyVecN($argts[0], $n)) {
                return null;
            }
        }
        foreach ($shapes[count($args)] as $shape) {
            $ok = true;
            foreach (str_split($shape) as $i => $s) {
                if (!($s === 's' ? $scalar($i) : $vec($i, (int)$s))) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return null;
            }
        }
        return "invalid arguments for $name: " . self::argTypes($args, $argts);
    }

    /**
     * The error of the arguments of mat2, mat3 or mat4 ($n columns), or
     * null for none: a float, a matrix of its own, $n vectors of $n, or
     * $n × $n floats.
     * @param list<Expr> $args
     * @param list<Type> $argts
     */
    private static function checkArgsForMatBuiltinFunc(int $n, array $args, array $argts): ?string
    {
        if (count($args) !== count($argts)) {
            return sprintf("the number of arguments and types doesn't match: %d vs %d", count($args), count($argts));
        }
        switch (count($args)) {
            case 1:
                if (self::isFloat($args[0], $argts[0]) || $argts[0]->main === BasicType::MAT2 + $n - 2) {
                    return null;
                }
                break;
            case $n:
                $ok = true;
                foreach ($argts as $t) {
                    $ok = $ok && self::isVecN($t, $n);
                }
                if ($ok) {
                    return null;
                }
                break;
            case $n * $n:
                $ok = true;
                foreach ($argts as $i => $t) {
                    $ok = $ok && self::isFloat($args[$i], $t);
                }
                if ($ok) {
                    return null;
                }
                break;
            default:
                return "invalid number of arguments for mat$n";
        }
        return "invalid arguments for mat$n: " . self::argTypes($args, $argts);
    }

    /**
     * The error of the arguments of uvecN ($n components), or null for
     * none: $n uints, or uints and uvecs of $n components in all, or a
     * vector of any kind of $n components, converted.
     * @param list<Expr> $args
     * @param list<Type> $argts
     */
    private static function checkArgsForUVecBuiltinFunc(int $n, array $args, array $argts): ?string
    {
        if (count($args) !== count($argts)) {
            return sprintf("the number of arguments and types doesn't match: %d vs %d", count($args), count($argts));
        }
        if (count($args) === 1 && (self::isAnyVecN($argts[0], $n) || self::isUint($args[0], $argts[0]))) {
            return null;
        }
        $count = 0;
        foreach ($args as $i => $a) {
            if (self::isUint($a, $argts[$i])) {
                $count++;
            } elseif ($argts[$i]->isUintVector()) {
                $count += $argts[$i]->vectorElementCount();
            } else {
                $count = -1;
                break;
            }
        }
        if ($count === $n && count($args) > 1) {
            return null;
        }
        return "invalid arguments for uvec$n: " . self::argTypes($args, $argts);
    }
}
