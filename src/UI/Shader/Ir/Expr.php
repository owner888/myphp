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

/** An expression (shaderir's Expr). @internal */
final class Expr
{
    public function __construct(
        public int $type = ExprType::BLANK,
        /** @var list<Expr> */
        public array $exprs = [],
        public ?Constant $const = null,
        /** One of BuiltinFunc's, or "". */
        public string $builtinFunc = '',
        /** Spelled in the xyzw set whatever $swizzlingSet. */
        public string $swizzling = '',
        public int $swizzlingSet = SwizzlingSet::XYZW,
        public int $index = 0,
        public int $op = Op::ADD,
        /** An expression of type uint or uvecN, as the compiler infers it: a constant of it is written as one (1u). */
        public bool $unsigned = false,
        /** An integer expression that is not a constant but takes the type of its context, as in Go: int where the context gives none. */
        public bool $untyped = false,
    ) {}

    /** The swizzle of a SWIZZLING_EXPR spelled in its set (shaderir's SourceSwizzling). */
    public function sourceSwizzling(): string
    {
        $names = match ($this->swizzlingSet) { SwizzlingSet::RGBA => 'rgba', SwizzlingSet::STPQ => 'stpq', default => null };
        return $names === null ? $this->swizzling : strtr($this->swizzling, 'xyzw', $names);
    }
}
