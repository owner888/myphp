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

/**
 * A statement (shaderir's Stmt). A For loop's counter starts from
 * $forInit and runs while it compares with $forEnd as $forOp says,
 * $forDelta at a time; where the compiler made it, $exprs holds the start
 * and the end as expressions, which need not be constants (MyGo's), and
 * $forInit and $forEnd are null for those that are not.
 * @internal
 */
final class Stmt
{
    public function __construct(
        public int $type = StmtType::EXPR_STMT,
        /** @var list<Expr> */
        public array $exprs = [],
        /** @var list<Block> */
        public array $blocks = [],
        public Type $forVarType = new Type,
        public int $forVarIndex = 0,
        public ?Constant $forInit = null,
        public ?Constant $forEnd = null,
        public int $forOp = Op::ADD,
        public ?Constant $forDelta = null,
        public int $initIndex = 0,
    ) {}
}
