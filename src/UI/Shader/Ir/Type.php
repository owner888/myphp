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

// Ported to PHP from MyGo's internal/shaderir, MyGo's fork of Ebitengine's
// (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Ir;


/** A type of the shader language (shaderir's Type): a basic type, an array of $length of $sub[0], or a struct of $sub. @internal */
final class Type
{
    public function __construct(
        public int $main = BasicType::NONE,
        /** @var list<Type> */
        public array $sub = [],
        public int $length = 0,
    ) {}

    public function equal(Type $rhs): bool
    {
        if ($this->main !== $rhs->main || $this->length !== $rhs->length || count($this->sub) !== count($rhs->sub)) {
            return false;
        }
        foreach ($this->sub as $i => $s) {
            if (!$s->equal($rhs->sub[$i])) {
                return false;
            }
        }
        return true;
    }

    public function string(): string
    {
        return match ($this->main) {
            BasicType::NONE => 'none', BasicType::BOOL => 'bool', BasicType::INT => 'int', BasicType::FLOAT => 'float',
            BasicType::VEC2 => 'vec2', BasicType::VEC3 => 'vec3', BasicType::VEC4 => 'vec4',
            BasicType::IVEC2 => 'ivec2', BasicType::IVEC3 => 'ivec3', BasicType::IVEC4 => 'ivec4',
            BasicType::MAT2 => 'mat2', BasicType::MAT3 => 'mat3', BasicType::MAT4 => 'mat4',
            BasicType::UINT => 'uint', BasicType::UVEC2 => 'uvec2', BasicType::UVEC3 => 'uvec3', BasicType::UVEC4 => 'uvec4',
            BasicType::TEXTURE => 'texture',
            BasicType::ARRAY => sprintf('[%d]%s', $this->length, $this->sub[0]->string()),
            BasicType::STRUCT => 'struct{' . implode(',', array_map(fn(Type $t): string => $t->string(), $this->sub)) . '}',
            default => sprintf('?(unknown type: %d)', $this->main),
        };
    }

    /** How many 32-bit words it takes, -1 for a struct. */
    public function dwordCount(): int
    {
        return match ($this->main) {
            BasicType::BOOL, BasicType::INT, BasicType::FLOAT, BasicType::UINT => 1, // a bool's size varies by language: 1 for simplicity
            BasicType::VEC2, BasicType::IVEC2, BasicType::UVEC2 => 2,
            BasicType::VEC3, BasicType::IVEC3, BasicType::UVEC3 => 3,
            BasicType::VEC4, BasicType::IVEC4, BasicType::UVEC4, BasicType::MAT2 => 4,
            BasicType::MAT3 => 9,
            BasicType::MAT4 => 16,
            BasicType::ARRAY => $this->length * $this->sub[0]->dwordCount(),
            default => -1,
        };
    }

    public function isFloatVector(): bool { return in_array($this->main, [BasicType::VEC2, BasicType::VEC3, BasicType::VEC4], true); }

    public function isIntVector(): bool { return in_array($this->main, [BasicType::IVEC2, BasicType::IVEC3, BasicType::IVEC4], true); }

    /** Whether it is uvec2, uvec3 or uvec4. */
    public function isUintVector(): bool { return in_array($this->main, [BasicType::UVEC2, BasicType::UVEC3, BasicType::UVEC4], true); }

    /** Whether it is a vector of int or uint. */
    public function isIntegerVector(): bool { return $this->isIntVector() || $this->isUintVector(); }

    /** Whether it is int, uint, or a vector of either. */
    public function isInteger(): bool { return $this->main === BasicType::INT || $this->main === BasicType::UINT || $this->isIntegerVector(); }

    /** The type of its components: float for a float vector or a matrix, int or uint for an integer vector, its own for a scalar. */
    public function scalar(): int
    {
        return match ($this->main) {
            BasicType::VEC2, BasicType::VEC3, BasicType::VEC4, BasicType::MAT2, BasicType::MAT3, BasicType::MAT4 => BasicType::FLOAT,
            BasicType::IVEC2, BasicType::IVEC3, BasicType::IVEC4 => BasicType::INT,
            BasicType::UVEC2, BasicType::UVEC3, BasicType::UVEC4 => BasicType::UINT,
            default => $this->main,
        };
    }

    /** The vector of $n components of $scalar, or $scalar itself for 1; NONE for none. */
    public static function vectorOf(int $scalar, int $n): int
    {
        if ($n === 1) {
            return $scalar;
        }
        $v = match ($scalar) {
            BasicType::FLOAT => [BasicType::VEC2, BasicType::VEC3, BasicType::VEC4],
            BasicType::INT => [BasicType::IVEC2, BasicType::IVEC3, BasicType::IVEC4],
            BasicType::UINT => [BasicType::UVEC2, BasicType::UVEC3, BasicType::UVEC4],
            default => null,
        };
        if ($v === null || $n < 2 || $n > 4) {
            return BasicType::NONE;
        }
        return $v[$n - 2];
    }

    public function vectorElementCount(): int
    {
        return match ($this->main) {
            BasicType::VEC2, BasicType::IVEC2, BasicType::UVEC2 => 2,
            BasicType::VEC3, BasicType::IVEC3, BasicType::UVEC3 => 3,
            BasicType::VEC4, BasicType::IVEC4, BasicType::UVEC4 => 4,
            default => -1,
        };
    }

    public function isMatrix(): bool { return in_array($this->main, [BasicType::MAT2, BasicType::MAT3, BasicType::MAT4], true); }

    public function matrixSize(): int
    {
        return match ($this->main) { BasicType::MAT2 => 2, BasicType::MAT3 => 3, BasicType::MAT4 => 4, default => -1 };
    }
}
