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
 * A shader program (shaderir's Program). $vertexFunc and $fragmentFunc are
 * the blocks of the entry points (Go's VertexFunc.Block and
 * FragmentFunc.Block), null for none: the vertex entry takes the
 * attributes, then the position (vec4) and the varyings as out-params; the
 * fragment entry the fragment's coordinate (vec4) and the varyings.
 * @internal
 */
final class Program
{
    public function __construct(
        /** @var list<string> */
        public array $uniformNames = [],
        /** @var list<Type> */
        public array $uniforms = [],
        public int $textureCount = 0,
        /** @var list<Type> */
        public array $attributes = [],
        /** @var list<Type> */
        public array $varyings = [],
        /** @var list<Func> */
        public array $funcs = [],
        public ?Block $vertexFunc = null,
        public ?Block $fragmentFunc = null,
        /** The Kage source it was compiled from, or null. */
        public ?string $fragmentSource = null,
    ) {}

    /** The functions reachable from $block through calls, by index (shaderir's ReachableFuncsFromBlock). @return list<Func> */
    public function reachableFuncsFromBlock(Block $block): array
    {
        $indexToFunc = [];
        foreach ($this->funcs as $f) {
            $indexToFunc[$f->index] = $f;
        }

        $visited = [];
        $f = function (Expr $expr) use (&$f, &$visited, $indexToFunc): void {
            if ($expr->type !== ExprType::FUNCTION_EXPR || isset($visited[$expr->index])) {
                return;
            }
            $visited[$expr->index] = true;
            self::walkExprs($f, $indexToFunc[$expr->index]->block);
        };
        self::walkExprs($f, $block);

        ksort($visited);
        return array_map(fn(int $i): Func => $indexToFunc[$i], array_keys($visited));
    }

    /** Whether the vertex entry point assigns to each attribute, whole or a part of it (shaderir's AssignedAttributes). @return list<bool> */
    public function assignedAttributes(): array
    {
        $assigned = array_fill(0, count($this->attributes), false);
        $walk = function (?Block $block) use (&$walk, &$assigned): void {
            if ($block === null) {
                return;
            }
            foreach ($block->stmts as $s) {
                if ($s->type === StmtType::ASSIGN) {
                    // Attributes are the first in-params, whose indices are never reused by local variables.
                    $e = $s->exprs[0];
                    while ($e->type === ExprType::FIELD_SELECTOR || $e->type === ExprType::INDEX) {
                        $e = $e->exprs[0];
                    }
                    if ($e->type === ExprType::LOCAL_VARIABLE && $e->index < count($assigned)) {
                        $assigned[$e->index] = true;
                    }
                }
                foreach ($s->blocks as $b) {
                    $walk($b);
                }
            }
        };
        $walk($this->vertexFunc);
        return $assigned;
    }

    /** Calls $f with each expression of $block and of the blocks in it, outer before inner. @param \Closure(Expr): void $f */
    private static function walkExprs(\Closure $f, ?Block $block): void
    {
        if ($block === null) {
            return;
        }
        foreach ($block->stmts as $s) {
            foreach ($s->exprs as $e) {
                self::walkExprsInExpr($f, $e);
            }
            foreach ($s->blocks as $b) {
                self::walkExprs($f, $b);
            }
        }
    }

    /** @param \Closure(Expr): void $f */
    private static function walkExprsInExpr(\Closure $f, Expr $expr): void
    {
        $f($expr);
        foreach ($expr->exprs as $e) {
            self::walkExprsInExpr($f, $e);
        }
    }

    /**
     * The type of the local variable $idx of $block, a block in $topBlock
     * (shaderir's LocalVariableType): in an entry point, the pseudo params
     * come first, as $vertexFunc and $fragmentFunc take them.
     */
    public function localVariableType(Block $topBlock, Block $block, int $idx): Type
    {
        if ($topBlock === $this->vertexFunc) {
            $na = count($this->attributes);
            $nv = count($this->varyings);
            return match (true) {
                $idx < $na => $this->attributes[$idx],
                $idx === $na => new Type(BasicType::VEC4),
                $idx < $na + $nv + 1 => $this->varyings[$idx - $na - 1],
                default => $this->funcLocalVariableType($topBlock, $block, $idx - ($na + $nv + 1)),
            };
        }
        if ($topBlock === $this->fragmentFunc) {
            $nv = count($this->varyings);
            return match (true) {
                $idx === 0 => new Type(BasicType::VEC4),
                $idx < $nv + 1 => $this->varyings[$idx - 1],
                default => $this->funcLocalVariableType($topBlock, $block, $idx - ($nv + 1)),
            };
        }
        return $this->funcLocalVariableType($topBlock, $block, $idx);
    }

    /** The type of the variable $absidx of a function's own: its params, then the locals of $topBlock down to $block (shaderir's localVariableType). */
    private function funcLocalVariableType(Block $topBlock, Block $block, int $absidx): Type
    {
        $ts = [];
        foreach ($this->funcs as $f) {
            if ($f->block === $topBlock) {
                $ts = [...$f->inParams, ...$f->outParams];
                break;
            }
        }
        return [...$ts, ...(self::descendantLocalVars($topBlock, $block) ?? [])][$absidx];
    }

    /** The local variables of $block and those of its descendants down to $target, which they see, or null if $target is not in it (shaderir's descendantLocalVars). @return ?list<Type> */
    private static function descendantLocalVars(Block $block, Block $target): ?array
    {
        if ($block === $target) {
            return $block->localVars;
        }
        foreach ($block->stmts as $s) {
            foreach ($s->blocks as $b) {
                if (($ts = self::descendantLocalVars($b, $target)) !== null) {
                    $n = $b->localVarIndexOffset - $block->localVarIndexOffset;
                    return [...array_slice($block->localVars, 0, $n), ...$ts];
                }
            }
        }
        return null;
    }

    /**
     * The program as a library for a renderer that draws with a vertex
     * stage of its own, as MyGo's do (shaderir's FragmentLibrary): a program
     * without entry points, whose fragment entry point is the function
     * entry, taking the fragment's position (vec4) and the varyings, and the
     * functions entry reaches, entry last. Backends emit it as a function of
     * the renderer's fragment shader (Msl::fragment). Without a fragment
     * entry point, the program itself, no entry and no functions.
     * @return array{0: Program, 1: ?Func, 2: list<Func>}
     */
    public function fragmentLibrary(): array
    {
        if ($this->fragmentFunc === null) {
            return [$this, null, []];
        }
        $reached = $this->reachableFuncsFromBlock($this->fragmentFunc);

        $index = 0;
        foreach ($this->funcs as $f) {
            $index = max($index, $f->index + 1);
        }
        $entry = new Func($index, [new Type(BasicType::VEC4), ...$this->varyings], [], new Type(BasicType::VEC4), $this->fragmentFunc);
        $l = clone $this;
        $l->vertexFunc = $l->fragmentFunc = null;
        $l->funcs = [...$this->funcs, $entry];
        return [$l, $entry, [...$reached, $entry]];
    }
}
