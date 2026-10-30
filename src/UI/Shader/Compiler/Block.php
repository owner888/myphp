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

// Ported to PHP from MyGo's internal/shader (shader.go), MyGo's fork of
// Ebitengine's (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Compiler;

use MyPHP\UI\Shader\Ir\{Block as IrBlock, Type};

/**
 * A scope as the compiler parses it (shader.go's block): its variables,
 * the positions of the named ones not used yet by index, its constants,
 * the scope around it, and the IR block it makes.
 * @internal
 */
final class Block
{
    /** @var list<Variable> */
    public array $vars = [];
    /** @var array<int, int> */
    public array $unusedVars = [];
    /** @var list<NamedConstant> */
    public array $consts = [];
    /** Whether it is the scope of a for-statement. */
    public bool $loop = false;

    public function __construct(
        public readonly ?Block $outer,
        public readonly IrBlock $ir,
    ) {}

    /** Whether it or a block around it belongs to a for-statement. */
    public function inLoop(): bool
    {
        for ($b = $this; $b !== null; $b = $b->outer) {
            if ($b->loop) {
                return true;
            }
        }
        return false;
    }

    public function totalLocalVariableCount(): int
    {
        $c = 0;
        for ($b = $this; $b !== null; $b = $b->outer) {
            $c += count($b->vars);
        }
        return $c;
    }

    public function addNamedLocalVariable(string $name, Type $typ, int $pos): void
    {
        $this->vars[] = new Variable($name, $typ);
        if ($name !== '_') {
            $this->unusedVars[count($this->vars) - 1] = $pos;
        }
    }

    /** The index in its function and the type of the variable $name in scope, marking it used if $markLocalVariableUsed, or null. @return ?array{0: int, 1: Type} */
    public function findLocalVariable(string $name, bool $markLocalVariableUsed): ?array
    {
        $idx = 0;
        for ($outer = $this->outer; $outer !== null; $outer = $outer->outer) {
            $idx += count($outer->vars);
        }
        foreach ($this->vars as $i => $v) {
            if ($v->name === $name) {
                if ($markLocalVariableUsed) {
                    unset($this->unusedVars[$i]);
                }
                return [$idx + $i, $v->typ];
            }
        }
        return $this->outer?->findLocalVariable($name, $markLocalVariableUsed);
    }

    public function findConstant(string $name): ?NamedConstant
    {
        foreach ($this->consts as $c) {
            if ($c->name === $name) {
                return $c;
            }
        }
        return $this->outer?->findConstant($name);
    }
}
