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

// Ported to PHP from MyGo's internal/shaderir/glsl, MyGo's fork of
// Ebitengine's (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Glsl;

use LogicException;
use MyPHP\UI\Shader\Ir\{BasicType, Block, BuiltinFunc, Constant, Expr, ExprType, Func, Op, Program, StmtType, Type};

/**
 * The GLSL backend (shaderir's glsl), as far as fragment libraries go:
 * fragment() writes a program's fragment entry point as a function of
 * GLSL 3.30 and GLSL ES 3.00 for a renderer's own fragment shader, and
 * uniformLanes() where its uniforms lie in the std140 block it declares.
 * An instance is a compilation's state (Go's compileContext). glsl.go,
 * type.go, uniform.go and fragment.go, in that order.
 *
 * mygo's glsl.Compile (a whole program with its entry points, its
 * preludes, the old GLSL's modInt, adjustProgram and the projection
 * matrix's flips) is left out: a library has no entry points of its own,
 * so its variables are all functions' (l0, l1, ...), and GLSL ES 3.00 has
 * the % that the old GLSL lacks.
 * @internal
 */
final class Glsl
{
    /** @var array<string, string> struct names by their types' strings */
    private array $structNames = [];
    /** @var list<Type> the struct types, in the order of their names */
    private array $structTypes = [];

    /**
     * $flipY is the renderer's expression telling that its window y counts
     * up, which negates dFdy, or ""; $flipRows the ivec4 uniform telling,
     * for each texture, the height of an image whose rows go up, which
     * texel reads flip, or 0 (mygo's), or "".
     */
    private function __construct(private readonly string $flipY = '', private readonly string $flipRows = '') {}

    private function structName(Type $t): string
    {
        if ($t->main !== BasicType::STRUCT) {
            throw new LogicException('glsl: the given type at structName must be a struct');
        }
        $s = $t->string();
        if (isset($this->structNames[$s])) {
            return $this->structNames[$s];
        }
        $n = sprintf('S%d', count($this->structNames));
        $this->structNames[$s] = $n;
        $this->structTypes[] = $t;
        return $n;
    }

    /** @return array{0: string, 1: string} */
    private function typ(Type $t): array
    {
        return match ($t->main) {
            BasicType::NONE => ['void', ''],
            BasicType::STRUCT => [$this->structName($t), ''],
            default => self::typeString($t),
        };
    }

    private function varDecl(Type $t, string $varname): string
    {
        switch ($t->main) {
            case BasicType::NONE:
                return '?(none)';
            case BasicType::STRUCT:
                return $this->structName($t) . " $varname";
        }
        [$t0, $t1] = self::typeString($t);
        return "$t0 $varname$t1";
    }

    private function varInit(Type $t): string
    {
        switch ($t->main) {
            case BasicType::NONE:
                return '?(none)';
            case BasicType::ARRAY:
                $init = $this->varInit($t->sub[0]);
                [$t0, $t1] = self::typeString($t);
                return sprintf('%s%s(%s)', $t0, $t1, implode(', ', array_fill(0, $t->length, $init)));
            case BasicType::STRUCT:
                throw new LogicException('not implemented');
            case BasicType::BOOL:
                return 'false';
            case BasicType::INT:
                return '0';
            case BasicType::UINT:
                return '0u';
            case BasicType::FLOAT: case BasicType::VEC2: case BasicType::VEC3: case BasicType::VEC4:
            case BasicType::IVEC2: case BasicType::IVEC3: case BasicType::IVEC4:
            case BasicType::UVEC2: case BasicType::UVEC3: case BasicType::UVEC4:
            case BasicType::MAT2: case BasicType::MAT3: case BasicType::MAT4:
                return self::basicTypeString($t->main) . '(0)';
        }
        [$t0, $t1] = $this->typ($t);
        throw new LogicException("glsl: unexpected type: $t0$t1");
    }

    /** @return list<string> */
    private function function(Program $p, Func $f, bool $prototype): array
    {
        $args = [];
        $idx = 0;
        foreach ($f->inParams as $t) {
            $args[] = 'in ' . $this->varDecl($t, 'l' . $idx++);
        }
        foreach ($f->outParams as $t) {
            $args[] = 'out ' . $this->varDecl($t, 'l' . $idx++);
        }
        $argsstr = $args === [] ? 'void' : implode(', ', $args);

        [$t0, $t1] = $this->typ($f->return);
        $sig = sprintf('%s%s F%d(%s)', $t0, $t1, $f->index, $argsstr);
        if ($prototype) {
            return ["$sig;"];
        }
        $block = $f->block === null ? [] : $this->block($p, $f->block, $f->block, 0);
        return ["$sig {", ...$block, '}'];
    }

    private static function constantToNumberLiteral(Constant $v): string
    {
        return self::numberLiteral($v, false);
    }

    /** $v as a literal, as an unsigned integer for $unsigned (1u); a float as Go's %d.0 when integral, %.10e otherwise. */
    private static function numberLiteral(Constant $v, bool $unsigned): string
    {
        switch ($v->kind) {
            case Constant::BOOL:
                return Constant::boolVal($v) ? 'true' : 'false';
            case Constant::INT:
                [$x] = Constant::int64Val($v);
                return $unsigned ? sprintf('%du', $x & 0xFFFFFFFF) : (string)$x;
            case Constant::FLOAT:
                [$x] = Constant::float64Val($v);
                if (floor($x) === $x && abs($x) < 2 ** 63) {
                    return sprintf('%d.0', (int)$x);
                }
                // PHP writes the exponent with as few digits as it takes, Go with two at least.
                [$m, $e] = explode('e', sprintf('%.10e', $x));
                return sprintf('%se%s%02d', $m, $e[0], (int)substr($e, 1));
        }
        return sprintf('?(unexpected literal: %s)', $v);
    }

    /** A library's variables are all its functions': their params, then their locals. */
    private static function localVariableName(int $idx): string
    {
        return "l$idx";
    }

    /** @return list<string> */
    private function initVariable(Program $p, Block $topBlock, Block $block, int $index, bool $decl, int $level): array
    {
        $idt = str_repeat("\t", $level + 1);
        $name = self::localVariableName($index);
        $t = $p->localVariableType($topBlock, $block, $index);

        switch ($t->main) {
            case BasicType::ARRAY:
                $lines = [];
                if ($decl) {
                    $lines[] = sprintf('%s%s;', $idt, $this->varDecl($t, $name));
                }
                $init = $this->varInit($t->sub[0]);
                for ($i = 0; $i < $t->length; $i++) {
                    $lines[] = sprintf('%s%s[%d] = %s;', $idt, $name, $i, $init);
                }
                return $lines;
            case BasicType::NONE:
                // The type is None e.g., when the variable is a for-loop counter.
                return [];
        }
        return [sprintf('%s%s = %s;', $idt, $decl ? $this->varDecl($t, $name) : $name, $this->varInit($t))];
    }

    private function expr(Expr $e): string
    {
        switch ($e->type) {
            case ExprType::NUMBER_EXPR:
                return self::numberLiteral($e->const, $e->unsigned);
            case ExprType::UNIFORM_VARIABLE:
                return "U$e->index";
            case ExprType::TEXTURE_VARIABLE:
                return "T$e->index";
            case ExprType::LOCAL_VARIABLE:
                return self::localVariableName($e->index);
            case ExprType::STRUCT_MEMBER:
                return "M$e->index";
            case ExprType::BUILTIN_FUNC_EXPR:
                return self::builtinFuncString($e->builtinFunc);
            case ExprType::SWIZZLING_EXPR:
                if (!self::isValidSwizzling($e->swizzling)) {
                    return "?(unexpected swizzling: $e->swizzling)";
                }
                return $e->swizzling;
            case ExprType::FUNCTION_EXPR:
                return "F$e->index";
            case ExprType::UNARY:
                $op = match ($e->op) {
                    Op::ADD, Op::SUB, Op::NOT_OP, Op::COMPLEMENT_OP => self::opString($e->op),
                    default => "?(unexpected op: $e->op)",
                };
                return sprintf('%s(%s)', $op, $this->expr($e->exprs[0]));
            case ExprType::BINARY:
                if ($e->op === Op::AND_NOT) {
                    return sprintf('(%s) & ~(%s)', $this->expr($e->exprs[0]), $this->expr($e->exprs[1]));
                }
                return sprintf('(%s) %s (%s)', $this->expr($e->exprs[0]), self::opString($e->op), $this->expr($e->exprs[1]));
            case ExprType::SELECTION:
                return sprintf('(%s) ? (%s) : (%s)', $this->expr($e->exprs[0]), $this->expr($e->exprs[1]), $this->expr($e->exprs[2]));
            case ExprType::CALL:
                $args = [];
                foreach (array_slice($e->exprs, 1) as $exp) {
                    $args[] = $this->expr($exp);
                }
                $callee = $e->exprs[0];
                if ($callee->type === ExprType::BUILTIN_FUNC_EXPR) {
                    $f = $this->expr($callee);
                    switch ($callee->builtinFunc) {
                        case BuiltinFunc::TEXEL_AT:
                            if ($this->flipRows !== '' && $e->exprs[1]->type === ExprType::TEXTURE_VARIABLE) {
                                return sprintf('%s(%s, flipRows(ivec2(floor(%s)), %s[%d]), 0)', $f, $args[0], $args[1], $this->flipRows, $e->exprs[1]->index);
                            }
                            // Floor the position so that a negative position is out of the texture.
                            return sprintf('%s(%s, ivec2(floor(%s)), 0)', $f, $args[0], $args[1]);
                        case BuiltinFunc::MIN: case BuiltinFunc::MAX:
                            $result = $args[0];
                            for ($i = 1; $i < count($args); $i++) {
                                $result = "$f($result, {$args[$i]})";
                            }
                            return $result;
                        case BuiltinFunc::DFDY:
                            if ($this->flipY !== '') {
                                return sprintf('(%s(%s) * ((%s) ? -1.0 : 1.0))', $f, $args[0], $this->flipY);
                            }
                            break;
                        case BuiltinFunc::FRONT_FACING:
                            // A library is drawn only with front faces.
                            return 'true';
                    }
                }
                // Using parentheses at the callee is illegal.
                return sprintf('%s(%s)', $this->expr($callee), implode(', ', $args));
            case ExprType::FIELD_SELECTOR:
                return sprintf('(%s).%s', $this->expr($e->exprs[0]), $this->expr($e->exprs[1]));
            case ExprType::INDEX:
                return sprintf('(%s)[%s]', $this->expr($e->exprs[0]), $this->expr($e->exprs[1]));
        }
        return "?(unexpected expr: $e->type)";
    }

    /** @return list<string> */
    private function block(Program $p, Block $topBlock, Block $block, int $level): array
    {
        $lines = [];
        foreach ($block->localVars as $i => $_) {
            array_push($lines, ...$this->initVariable($p, $topBlock, $block, $block->localVarIndexOffset + $i, true, $level));
        }

        $idt = str_repeat("\t", $level + 1);
        foreach ($block->stmts as $s) {
            switch ($s->type) {
                case StmtType::EXPR_STMT:
                    $lines[] = sprintf('%s%s;', $idt, $this->expr($s->exprs[0]));
                    break;
                case StmtType::BLOCK_STMT:
                    array_push($lines, $idt . '{', ...$this->block($p, $topBlock, $s->blocks[0], $level + 1));
                    $lines[] = $idt . '}';
                    break;
                case StmtType::ASSIGN:
                    [$lhs, $rhs] = $s->exprs;
                    if ($lhs->type === ExprType::LOCAL_VARIABLE) {
                        $t = $p->localVariableType($topBlock, $block, $lhs->index);
                        if ($t->main === BasicType::ARRAY) {
                            $l = $this->expr($lhs);
                            $r = $this->expr($rhs);
                            for ($i = 0; $i < $t->length; $i++) {
                                $lines[] = "$idt{$l}[$i] = {$r}[$i];";
                            }
                            break;
                        }
                    }
                    $lines[] = sprintf('%s%s = %s;', $idt, $this->expr($lhs), $this->expr($rhs));
                    break;
                case StmtType::INIT:
                    array_push($lines, ...$this->initVariable($p, $topBlock, $block, $s->initIndex, false, $level));
                    break;
                case StmtType::IF:
                    $lines[] = sprintf('%sif (%s) {', $idt, $this->expr($s->exprs[0]));
                    array_push($lines, ...$this->block($p, $topBlock, $s->blocks[0], $level + 1));
                    if (count($s->blocks) > 1) {
                        $lines[] = "$idt} else {";
                        array_push($lines, ...$this->block($p, $topBlock, $s->blocks[1], $level + 1));
                    }
                    $lines[] = "$idt}";
                    break;
                case StmtType::FOR:
                    $v = self::localVariableName($s->forVarIndex);
                    [$val] = Constant::float64Val($s->forDelta);
                    $unsigned = $s->forVarType->scalar() === BasicType::UINT;
                    $delta = match (true) {
                        $val == 0 => "?(unexpected delta: $s->forDelta)",
                        $val == 1 => "$v++",
                        $val == -1 => "$v--",
                        $val > 0 => "$v += " . self::numberLiteral($s->forDelta, $unsigned),
                        default => "$v -= " . self::numberLiteral(Constant::unaryOp('-', $s->forDelta, 0), $unsigned),
                    };
                    $op = match ($s->forOp) {
                        Op::LESS_THAN_OP, Op::LESS_THAN_EQUAL_OP, Op::GREATER_THAN_OP, Op::GREATER_THAN_EQUAL_OP, Op::EQUAL_OP, Op::NOT_EQUAL_OP => self::opString($s->forOp),
                        default => "?(unexpected op: $s->forOp)",
                    };
                    if (count($s->exprs) === 2) {
                        $init = $this->expr($s->exprs[0]);
                        $end = $this->expr($s->exprs[1]);
                    } else {
                        $init = self::constantToNumberLiteral($s->forInit);
                        $end = self::constantToNumberLiteral($s->forEnd);
                    }
                    [$t0, $t1] = self::typeString($s->forVarType);
                    $lines[] = "{$idt}for ($t0 $v$t1 = $init; $v $op $end; $delta) {";
                    array_push($lines, ...$this->block($p, $topBlock, $s->blocks[0], $level + 1));
                    $lines[] = "$idt}";
                    break;
                case StmtType::CONTINUE:
                    $lines[] = $idt . 'continue;';
                    break;
                case StmtType::BREAK:
                    $lines[] = $idt . 'break;';
                    break;
                case StmtType::RETURN:
                    $lines[] = $s->exprs === [] ? $idt . 'return;' : sprintf('%sreturn %s;', $idt, $this->expr($s->exprs[0]));
                    break;
                case StmtType::DISCARD:
                    // 'discard' is invoked only in the fragment shader entry point.
                    array_push($lines, $idt . 'discard;', $idt . 'return vec4(0.0);');
                    break;
                default:
                    $lines[] = "$idt?(unexpected stmt: $s->type)";
            }
        }

        return $lines;
    }

    /** Whether $s swizzles one to four components all of one set: xyzw, rgba or stpq (shaderir's IsValidSwizzling). */
    private static function isValidSwizzling(string $s): bool
    {
        if (strlen($s) < 1 || strlen($s) > 4) {
            return false;
        }
        foreach (['xyzw', 'rgba', 'stpq'] as $set) {
            if (str_contains($set, $s[0])) {
                return strspn($s, $set) === strlen($s);
            }
        }
        return false;
    }

    // type.go: the names of operators, types and built-in functions in GLSL.

    private static function opString(int $op): string
    {
        return match ($op) {
            Op::ADD => '+',
            Op::SUB => '-',
            Op::NOT_OP => '!',
            Op::COMPLEMENT_OP => '~',
            Op::COMPONENT_WISE_MUL, Op::MATRIX_MUL => '*',
            Op::DIV => '/',
            Op::MOD_OP => '%',
            Op::LEFT_SHIFT => '<<',
            Op::RIGHT_SHIFT => '>>',
            Op::LESS_THAN_OP => '<',
            Op::LESS_THAN_EQUAL_OP => '<=',
            Op::GREATER_THAN_OP => '>',
            Op::GREATER_THAN_EQUAL_OP => '>=',
            Op::EQUAL_OP, Op::VECTOR_EQUAL_OP => '==',
            Op::NOT_EQUAL_OP, Op::VECTOR_NOT_EQUAL_OP => '!=',
            Op::AND => '&',
            Op::XOR => '^',
            Op::OR => '|',
            Op::AND_AND => '&&',
            Op::OR_OR => '||',
            default => "?(unexpected operator: $op)",
        };
    }

    /** $t as GLSL writes it, around a name: the type, then what follows the name ([4] of an array). @return array{0: string, 1: string} */
    private static function typeString(Type $t): array
    {
        switch ($t->main) {
            case BasicType::ARRAY:
                [$t0, $t1] = self::typeString($t->sub[0]);
                return [$t0 . $t1, sprintf('[%d]', $t->length)];
            case BasicType::STRUCT:
                throw new LogicException('glsl: a struct is not implemented');
        }
        return [self::basicTypeString($t->main), ''];
    }

    private static function basicTypeString(int $t): string
    {
        return match ($t) {
            BasicType::NONE => '?(none)',
            BasicType::BOOL => 'bool',
            BasicType::INT => 'int',
            BasicType::FLOAT => 'float',
            BasicType::VEC2 => 'vec2',
            BasicType::VEC3 => 'vec3',
            BasicType::VEC4 => 'vec4',
            BasicType::IVEC2 => 'ivec2',
            BasicType::IVEC3 => 'ivec3',
            BasicType::IVEC4 => 'ivec4',
            BasicType::MAT2 => 'mat2',
            BasicType::MAT3 => 'mat3',
            BasicType::MAT4 => 'mat4',
            BasicType::UINT => 'uint',
            BasicType::UVEC2 => 'uvec2',
            BasicType::UVEC3 => 'uvec3',
            BasicType::UVEC4 => 'uvec4',
            BasicType::ARRAY => '?(array)',
            BasicType::STRUCT => '?(struct)',
            default => "?(unknown type: $t)",
        };
    }

    private static function builtinFuncString(string $f): string
    {
        return match ($f) {
            BuiltinFunc::ATAN2 => 'atan',
            BuiltinFunc::DFDX => 'dFdx',
            BuiltinFunc::DFDY => 'dFdy',
            BuiltinFunc::TEXEL_AT => 'texelFetch',
            default => $f,
        };
    }

    // uniform.go: the layout of the std140 uniform block.

    /**
     * For each lane of $p's uniforms as an interpreter keeps them (in order,
     * columns of matrices, elements of arrays), its offset in bytes in the
     * std140 uniform block of fragment(), and the block's size, a multiple
     * of 16: a vector of 3 takes the room of 4, and an array's elements and
     * a matrix's columns are 16 bytes apart at least (mygo's
     * glsl.UniformLanes).
     * @return array{0: list<int>, 1: int}
     */
    public static function uniformLanes(Program $p): array
    {
        $lanes = [];
        $walk = function (Type $t, int $base) use (&$walk, &$lanes): void {
            if ($t->main === BasicType::ARRAY) {
                [$s] = self::std140($t->sub[0]);
                $stride = self::roundUp($s, 16);
                for ($i = 0; $i < $t->length; $i++) {
                    $walk($t->sub[0], $base + $i * $stride);
                }
            } elseif ($t->isMatrix()) {
                $n = $t->matrixSize();
                for ($col = 0; $col < $n; $col++) {
                    for ($row = 0; $row < $n; $row++) {
                        $lanes[] = $base + $col * 16 + $row * 4;
                    }
                }
            } else {
                $n = max($t->vectorElementCount(), 1);
                for ($i = 0; $i < $n; $i++) {
                    $lanes[] = $base + 4 * $i;
                }
            }
        };
        $size = 0;
        foreach ($p->uniforms as $u) {
            [$s, $a] = self::std140($u);
            $size = self::roundUp($size, $a);
            $walk($u, $size);
            $size += $s;
        }
        $walk = null; // a recursive closure holds itself: let it go, or it and what it reached wait for the cycle collector (docs/architecture.md)
        return [$lanes, self::roundUp($size, 16)];
    }

    /** The size and the alignment of $t in a std140 block, in bytes. @return array{0: int, 1: int} */
    private static function std140(Type $t): array
    {
        switch ($t->main) {
            case BasicType::BOOL: case BasicType::INT: case BasicType::UINT: case BasicType::FLOAT:
                return [4, 4];
            case BasicType::VEC2: case BasicType::IVEC2: case BasicType::UVEC2:
                return [8, 8];
            case BasicType::VEC3: case BasicType::IVEC3: case BasicType::UVEC3:
                return [12, 16];
            case BasicType::VEC4: case BasicType::IVEC4: case BasicType::UVEC4:
                return [16, 16];
            case BasicType::MAT2: case BasicType::MAT3: case BasicType::MAT4:
                return [$t->matrixSize() * 16, 16];
            case BasicType::ARRAY:
                [$s] = self::std140($t->sub[0]);
                return [$t->length * self::roundUp($s, 16), 16];
        }
        throw new LogicException(sprintf('glsl: no std140 layout for %s', $t->string()));
    }

    private static function roundUp(int $x, int $a): int
    {
        return intdiv($x + $a - 1, $a) * $a;
    }

    // fragment.go: the fragment entry point as a function.

    /**
     * $p's fragment entry point as a function named $name of GLSL 3.30 and
     * GLSL ES 3.00, for a renderer's fragment shader to call, after the
     * uniforms (U0, U1, ...), the textures (sampler2D T0, T1, ...) and the
     * functions it reaches (mygo's glsl.Fragment):
     *
     *     vec4 name(vec4 position, ...varyings)
     *
     * The position counts its y from the top of the target, as the renderer
     * passes it. $options (mygo's glsl.Options), each "" where unset:
     * 'flipY', a boolean expression of the renderer's, true where GL's
     * window y counts up, as the position's does not, which negates dFdy;
     * 'block', the name of a uniform block, laid out as std140
     * (uniformLanes() says how), whose members the uniforms are;
     * 'flipRows', the name of a uniform ivec4 that the renderer sets, which
     * gives, for each texture, the height of an image whose rows go up, as
     * GL's framebuffers draw them, or 0: texel reads flip those. "" for a
     * program without a fragment entry point.
     * @param array{flipY?: string, block?: string, flipRows?: string} $options
     */
    public static function fragment(Program $p, string $name, array $options = []): string
    {
        [$lib, $entry, $funcs] = $p->fragmentLibrary();
        if ($entry === null) {
            return '';
        }
        $block = $options['block'] ?? '';
        $flipRows = $options['flipRows'] ?? '';
        $c = new self($options['flipY'] ?? '', $flipRows);

        $lines = ['{{.Structs}}', ''];
        if ($block !== '' && $lib->uniforms !== []) {
            $lines[] = "layout(std140) uniform $block {";
            foreach ($lib->uniforms as $i => $t) {
                $lines[] = sprintf("\t%s;", $c->varDecl($t, "U$i"));
            }
            $lines[] = '};';
        } else {
            foreach ($lib->uniforms as $i => $t) {
                $lines[] = sprintf('uniform %s;', $c->varDecl($t, "U$i"));
            }
        }
        for ($i = 0; $i < $lib->textureCount; $i++) {
            $lines[] = "uniform sampler2D T$i;";
        }
        if ($flipRows !== '') {
            array_push($lines, "uniform ivec4 $flipRows;", '',
                'ivec2 flipRows(ivec2 p, int h) {',
                "\treturn h > 0 ? ivec2(p.x, h - 1 - p.y) : p;",
                '}');
        }
        $lines[] = '';
        foreach ($funcs as $f) {
            array_push($lines, ...$c->function($lib, $f, true));
        }
        foreach ($funcs as $f) {
            array_push($lines, '', ...$c->function($lib, $f, false));
        }

        $params = $args = [];
        foreach ($entry->inParams as $i => $t) {
            $n = $i > 0 ? sprintf('v%d', $i - 1) : 'position';
            $params[] = $c->varDecl($t, $n);
            $args[] = $n;
        }
        array_push($lines, '', sprintf('vec4 %s(%s) {', $name, implode(', ', $params)), sprintf("\treturn F%d(%s);", $entry->index, implode(', ', $args)), '}');

        // Struct types are determined after converting the program.
        $st = [];
        foreach ($c->structTypes as $i => $t) {
            $st[] = "struct S$i {";
            foreach ($t->sub as $j => $m) {
                $st[] = sprintf("\t%s;", $c->varDecl($m, "M$j"));
            }
            $st[] = '};';
        }
        $ls = str_replace('{{.Structs}}', implode("\n", $st), implode("\n", $lines));
        return trim((string)preg_replace('/\n\n+/', "\n\n", $ls)) . "\n";
    }
}
