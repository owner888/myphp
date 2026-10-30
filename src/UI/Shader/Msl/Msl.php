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

// Ported to PHP from MyGo's internal/shaderir/msl, MyGo's fork of
// Ebitengine's (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Msl;

use LogicException;
use MyPHP\UI\Shader\Ir\{BasicType, Block, BuiltinFunc, Constant, Expr, ExprType, Func, Op, Program, StmtType, Type};

/**
 * The Metal Shading Language backend (shaderir's msl): compile() writes a
 * program with its entry points, fragment() its fragment entry point as a
 * function for a renderer's own fragment shader, and uniformLayout() and
 * uniformLanes() where its uniforms lie in the struct Uniforms. An instance
 * is a compilation's state (Go's compileContext). msl.go, type.go,
 * uniform.go and fragment.go, in that order.
 * @internal
 */
final class Msl
{
    private const VERTEX_OUT = 'varyings';
    public const VERTEX_NAME = 'Vertex';
    public const FRAGMENT_NAME = 'Fragment';

    /** @var array<string, string> struct names by their types' strings */
    private array $structNames = [];
    /** @var list<Type> the struct types, in the order of their names */
    private array $structTypes = [];
    /** @var list<bool> */
    private array $assignedAttributes = [];
    /**
     * @var array<int, true> the indices of the functions reachable from the vertex entry point:
     * Metal allows [[front_facing]] only in fragment functions, so these functions do not take front_facing
     */
    private array $vertexFuncIndices = [];

    /** For a fragment library (fragment()), whose functions take no front_facing: MyGo's renderers draw only front faces. */
    private function __construct(private readonly bool $library = false) {}

    private function isVertexFunc(int $index): bool
    {
        return $this->library || isset($this->vertexFuncIndices[$index]);
    }

    private function structName(Type $t): string
    {
        if ($t->main !== BasicType::STRUCT) {
            throw new LogicException('msl: the given type at structName must be a struct');
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

    public static function prelude(): string
    {
        return <<<'MSL'
            #include <metal_stdlib>

            using namespace metal;

            constexpr sampler __texelSampler(coord::pixel, filter::nearest, address::clamp_to_zero);

            template<typename T, typename U>
            T mod(T x, U y) {
            	return x - y * floor(x/y);
            }

            // Metal defines sign only for floating-point types. These overloads cover int and ivecN arguments.
            int sign(int x) {
            	return int(x > 0) - int(x < 0);
            }

            int2 sign(int2 x) {
            	return int2(x > 0) - int2(x < 0);
            }

            int3 sign(int3 x) {
            	return int3(x > 0) - int3(x < 0);
            }

            int4 sign(int4 x) {
            	return int4(x > 0) - int4(x < 0);
            }
            MSL;
    }

    /** $p as a Metal library with its entry points, Vertex and Fragment, those it has (msl.Compile). */
    public static function compile(Program $p): string
    {
        $c = new self;
        $c->assignedAttributes = $p->assignedAttributes();
        if ($p->vertexFunc !== null) {
            foreach ($p->reachableFuncsFromBlock($p->vertexFunc) as $f) {
                $c->vertexFuncIndices[$f->index] = true;
            }
        }

        $hasVertex = $p->vertexFunc !== null && $p->vertexFunc->stmts !== [];
        $hasFragment = $p->fragmentFunc !== null && $p->fragmentFunc->stmts !== [];

        $lines = explode("\n", self::prelude());
        array_push($lines, '', '{{.Structs}}');

        if ($p->uniforms !== []) {
            array_push($lines, '', 'struct Uniforms {');
            foreach ($p->uniforms as $i => $u) {
                $lines[] = sprintf("\t%s;", $c->varDecl($u, "U$i", false));
            }
            $lines[] = '};';
        }

        // The entry functions refer to Attributes and Varyings even when these have no members.
        if ($p->attributes !== [] || $hasVertex) {
            array_push($lines, '', 'struct Attributes {');
            foreach ($p->attributes as $i => $a) {
                $lines[] = sprintf("\t%s;", $c->varDecl($a, "M$i", false));
            }
            $lines[] = '};';
        }

        if ($p->varyings !== [] || $hasVertex || $hasFragment) {
            array_push($lines, '', 'struct Varyings {', "\tfloat4 Position [[position]];");
            foreach ($p->varyings as $i => $v) {
                $lines[] = sprintf("\t%s;", $c->varDecl($v, "M$i", false));
            }
            $lines[] = '};';
        }

        if ($p->funcs !== []) {
            $lines[] = '';
            foreach ($p->funcs as $f) {
                array_push($lines, ...$c->function($p, $f, true));
            }
            foreach ($p->funcs as $f) {
                if ($lines[count($lines) - 1] !== '') {
                    $lines[] = '';
                }
                array_push($lines, ...$c->function($p, $f, false));
            }
        }

        if ($hasVertex) {
            array_push($lines, '', sprintf('vertex Varyings %s(', self::VERTEX_NAME), "\tuint vid [[vertex_id]],", "\tconst device Attributes* attributes [[buffer(0)]]");
            if ($p->uniforms !== []) {
                $lines[count($lines) - 1] .= ',';
                $lines[] = "\tconstant Uniforms& uniforms [[buffer(1)]]";
            }
            for ($i = 0; $i < $p->textureCount; $i++) {
                $lines[count($lines) - 1] .= ',';
                $lines[] = "\ttexture2d<float> T$i [[texture($i)]]";
            }
            $lines[count($lines) - 1] .= ') {';
            $lines[] = sprintf("\tVaryings %s = {};", self::VERTEX_OUT);
            foreach ($p->attributes as $i => $a) {
                if (!$c->assignedAttributes[$i]) {
                    continue;
                }
                $lines[] = sprintf("\t%s = %s;", $c->varDecl($a, self::attributeCopyName($i), false), self::attributeName($i));
            }
            array_push($lines, ...$c->block($p, $p->vertexFunc, $p->vertexFunc, 0));
            $last = sprintf("\treturn %s;", self::VERTEX_OUT);
            if ($lines[count($lines) - 1] !== $last) {
                $lines[] = $last;
            }
            $lines[] = '}';
        }

        if ($hasFragment) {
            array_push($lines, '', sprintf('fragment float4 %s(', self::FRAGMENT_NAME), "\tVaryings varyings [[stage_in]]");
            if ($p->uniforms !== []) {
                $lines[count($lines) - 1] .= ',';
                $lines[] = "\tconstant Uniforms& uniforms [[buffer(0)]]";
            }
            for ($i = 0; $i < $p->textureCount; $i++) {
                $lines[count($lines) - 1] .= ',';
                $lines[] = "\ttexture2d<float> T$i [[texture($i)]]";
            }
            $lines[count($lines) - 1] .= ',';
            $lines[] = "\tbool front_facing [[front_facing]]";
            $lines[count($lines) - 1] .= ') {';
            array_push($lines, ...$c->block($p, $p->fragmentFunc, $p->fragmentFunc, 0));
            $lines[] = '}';
        }

        // Struct types are determined after converting the program.
        return $c->finish(implode("\n", $lines));
    }

    /** $ls with the struct types in place of {{.Structs}}, runs of blank lines as one, and one newline at its end. */
    private function finish(string $ls): string
    {
        $st = [];
        foreach ($this->structTypes as $i => $t) {
            $st[] = "struct S$i {";
            foreach ($t->sub as $j => $m) {
                $st[] = sprintf("\t%s;", $this->varDecl($m, "M$j", false));
            }
            $st[] = '};';
        }
        $ls = str_replace('{{.Structs}}', implode("\n", $st), $ls);
        return trim((string)preg_replace('/\n\n+/', "\n\n", $ls)) . "\n";
    }

    private function typ(Type $t): string
    {
        return match ($t->main) {
            BasicType::NONE => 'void',
            BasicType::STRUCT => $this->structName($t),
            default => self::typeString($t, false),
        };
    }

    private function varDecl(Type $t, string $varname, bool $ref): string
    {
        return match ($t->main) {
            BasicType::NONE => '?(none)',
            BasicType::STRUCT => $this->structName($t) . ($ref ? '&' : '') . " $varname",
            default => self::typeString($t, $ref) . " $varname",
        };
    }

    private function varInit(Type $t): string
    {
        return match ($t->main) {
            BasicType::NONE => '?(none)',
            BasicType::ARRAY, BasicType::STRUCT => '{}',
            BasicType::BOOL => 'false',
            BasicType::INT => '0',
            BasicType::UINT => '0u',
            BasicType::FLOAT, BasicType::VEC2, BasicType::VEC3, BasicType::VEC4,
            BasicType::IVEC2, BasicType::IVEC3, BasicType::IVEC4,
            BasicType::UVEC2, BasicType::UVEC3, BasicType::UVEC4,
            BasicType::MAT2, BasicType::MAT3, BasicType::MAT4 => self::basicTypeString($t->main) . '(0)',
            default => throw new LogicException(sprintf('msl: unexpected type: %s', $this->typ($t))),
        };
    }

    /** @return list<string> */
    private function function(Program $p, Func $f, bool $prototype): array
    {
        $args = [];

        // Uniform variables and texture variables. In Metal, non-const global variables are not available.
        if ($p->uniforms !== []) {
            $args[] = 'constant Uniforms& uniforms';
        }
        for ($i = 0; $i < $p->textureCount; $i++) {
            $args[] = "texture2d<float> T$i";
        }
        if (!$this->isVertexFunc($f->index)) {
            $args[] = 'bool front_facing';
        }

        $idx = 0;
        foreach ($f->inParams as $t) {
            $args[] = $this->varDecl($t, 'l' . $idx++, false);
        }
        foreach ($f->outParams as $t) {
            $args[] = 'thread ' . $this->varDecl($t, 'l' . $idx++, true);
        }
        $argsstr = $args === [] ? 'void' : implode(', ', $args);

        $sig = sprintf('%s F%d(%s)', $this->typ($f->return), $f->index, $argsstr);
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

    private static function attributeName(int $idx): string
    {
        return "attributes[vid].M$idx";
    }

    /**
     * The name of the local variable that holds a copy of an assigned attribute.
     * An attribute itself is read-only as it is accessed through a const pointer.
     */
    private static function attributeCopyName(int $idx): string
    {
        return "a$idx";
    }

    private function localVariableName(Program $p, Block $topBlock, int $idx): string
    {
        if ($topBlock === $p->vertexFunc) {
            $na = count($p->attributes);
            $nv = count($p->varyings);
            return match (true) {
                $idx < $na => $this->assignedAttributes[$idx] ? self::attributeCopyName($idx) : self::attributeName($idx),
                $idx === $na => self::VERTEX_OUT . '.Position',
                $idx < $na + $nv + 1 => sprintf('%s.M%d', self::VERTEX_OUT, $idx - $na - 1),
                default => sprintf('l%d', $idx - ($na + $nv + 1)),
            };
        }
        if ($topBlock === $p->fragmentFunc) {
            $nv = count($p->varyings);
            return match (true) {
                $idx === 0 => self::VERTEX_OUT . '.Position',
                $idx < $nv + 1 => sprintf('%s.M%d', self::VERTEX_OUT, $idx - 1),
                default => sprintf('l%d', $idx - ($nv + 1)),
            };
        }
        return "l$idx";
    }

    private function initVariable(Program $p, Block $topBlock, Block $block, int $index, bool $decl, int $level): string
    {
        $idt = str_repeat("\t", $level + 1);
        $name = $this->localVariableName($p, $topBlock, $index);
        $t = $p->localVariableType($topBlock, $block, $index);
        return sprintf('%s%s = %s;', $idt, $decl ? $this->varDecl($t, $name, false) : $name, $this->varInit($t));
    }

    private function expr(Program $p, Block $topBlock, Expr $e): string
    {
        switch ($e->type) {
            case ExprType::NUMBER_EXPR:
                return self::numberLiteral($e->const, $e->unsigned);
            case ExprType::UNIFORM_VARIABLE:
                return "uniforms.U$e->index";
            case ExprType::TEXTURE_VARIABLE:
                return "T$e->index";
            case ExprType::LOCAL_VARIABLE:
                return $this->localVariableName($p, $topBlock, $e->index);
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
                return sprintf('%s(%s)', $op, $this->expr($p, $topBlock, $e->exprs[0]));
            case ExprType::BINARY:
                $l = $this->expr($p, $topBlock, $e->exprs[0]);
                $r = $this->expr($p, $topBlock, $e->exprs[1]);
                return match ($e->op) {
                    Op::VECTOR_EQUAL_OP => "all(($l) == ($r))",
                    Op::VECTOR_NOT_EQUAL_OP => "!all(($l) == ($r))",
                    Op::AND_NOT => "($l) & ~($r)",
                    default => sprintf('(%s) %s (%s)', $l, self::opString($e->op), $r),
                };
            case ExprType::SELECTION:
                return sprintf('(%s) ? (%s) : (%s)', $this->expr($p, $topBlock, $e->exprs[0]), $this->expr($p, $topBlock, $e->exprs[1]), $this->expr($p, $topBlock, $e->exprs[2]));
            case ExprType::CALL:
                $callee = $e->exprs[0];
                $builtin = $callee->type === ExprType::BUILTIN_FUNC_EXPR;
                $args = [];
                if (!$builtin) {
                    if ($p->uniforms !== []) {
                        $args[] = 'uniforms';
                    }
                    for ($i = 0; $i < $p->textureCount; $i++) {
                        $args[] = "T$i";
                    }
                    if (!$this->isVertexFunc($callee->index)) {
                        $args[] = 'front_facing';
                    }
                }
                foreach (array_slice($e->exprs, 1) as $exp) {
                    $args[] = $this->expr($p, $topBlock, $exp);
                }
                if ($builtin && $callee->builtinFunc === BuiltinFunc::TEXEL_AT) {
                    // Floor the position so that a negative position is out of the texture.
                    // Without this, the nearest filter rounds the position to the nearest texel.
                    return sprintf('%s.sample(__texelSampler, floor(%s))', $args[0], implode(', ', array_slice($args, 1)));
                }
                if ($builtin && ($callee->builtinFunc === BuiltinFunc::MIN || $callee->builtinFunc === BuiltinFunc::MAX)) {
                    $f = $this->expr($p, $topBlock, $callee);
                    $result = $args[0];
                    for ($i = 1; $i < count($args); $i++) {
                        $result = "$f($result, {$args[$i]})";
                    }
                    return $result;
                }
                if ($builtin && $callee->builtinFunc === BuiltinFunc::FRONT_FACING) {
                    return $this->library ? '(true)' : '(front_facing)';
                }
                return sprintf('%s(%s)', $this->expr($p, $topBlock, $callee), implode(', ', $args));
            case ExprType::FIELD_SELECTOR:
                return sprintf('(%s).%s', $this->expr($p, $topBlock, $e->exprs[0]), $this->expr($p, $topBlock, $e->exprs[1]));
            case ExprType::INDEX:
                return sprintf('(%s)[%s]', $this->expr($p, $topBlock, $e->exprs[0]), $this->expr($p, $topBlock, $e->exprs[1]));
        }
        return "?(unexpected expr: $e->type)";
    }

    /** @return list<string> */
    private function block(Program $p, Block $topBlock, Block $block, int $level): array
    {
        $idt = str_repeat("\t", $level + 1);

        $lines = [];
        foreach ($block->localVars as $i => $t) {
            // The type is None e.g., when the variable is a for-loop counter.
            if ($t->main !== BasicType::NONE) {
                $lines[] = $this->initVariable($p, $topBlock, $block, $block->localVarIndexOffset + $i, true, $level);
            }
        }

        foreach ($block->stmts as $s) {
            switch ($s->type) {
                case StmtType::EXPR_STMT:
                    $lines[] = sprintf('%s%s;', $idt, $this->expr($p, $topBlock, $s->exprs[0]));
                    break;
                case StmtType::BLOCK_STMT:
                    array_push($lines, $idt . '{', ...$this->block($p, $topBlock, $s->blocks[0], $level + 1));
                    $lines[] = $idt . '}';
                    break;
                case StmtType::ASSIGN:
                    $lines[] = sprintf('%s%s = %s;', $idt, $this->expr($p, $topBlock, $s->exprs[0]), $this->expr($p, $topBlock, $s->exprs[1]));
                    break;
                case StmtType::INIT:
                    // In the vertex function, varying values are the output parameters.
                    // These values are represented as a struct and do not need to be initialized.
                    if ($topBlock !== $p->vertexFunc || $s->initIndex >= count($p->attributes) + count($p->varyings) + 1) {
                        $lines[] = $this->initVariable($p, $topBlock, $block, $s->initIndex, false, $level);
                    }
                    break;
                case StmtType::IF:
                    $lines[] = sprintf('%sif (%s) {', $idt, $this->expr($p, $topBlock, $s->exprs[0]));
                    array_push($lines, ...$this->block($p, $topBlock, $s->blocks[0], $level + 1));
                    if (count($s->blocks) > 1) {
                        $lines[] = "$idt} else {";
                        array_push($lines, ...$this->block($p, $topBlock, $s->blocks[1], $level + 1));
                    }
                    $lines[] = "$idt}";
                    break;
                case StmtType::FOR:
                    $v = $this->localVariableName($p, $topBlock, $s->forVarIndex);
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
                        $init = $this->expr($p, $topBlock, $s->exprs[0]);
                        $end = $this->expr($p, $topBlock, $s->exprs[1]);
                    } else {
                        $init = self::constantToNumberLiteral($s->forInit);
                        $end = self::constantToNumberLiteral($s->forEnd);
                    }
                    $ts = self::typeString($s->forVarType, false);
                    $lines[] = "{$idt}for ($ts $v = $init; $v $op $end; $delta) {";
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
                    $lines[] = match (true) {
                        $topBlock === $p->vertexFunc => sprintf('%sreturn %s;', $idt, self::VERTEX_OUT),
                        $s->exprs === [] => $idt . 'return;',
                        default => sprintf('%sreturn %s;', $idt, $this->expr($p, $topBlock, $s->exprs[0])),
                    };
                    break;
                case StmtType::DISCARD:
                    // 'discard' is invoked only in the fragment shader entry point.
                    array_push($lines, $idt . 'discard_fragment();', $idt . 'return float4(0.0);');
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

    // type.go: the names of operators, types and built-in functions in Metal.

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
            Op::EQUAL_OP => '==',
            Op::NOT_EQUAL_OP => '!=',
            Op::AND => '&',
            Op::XOR => '^',
            Op::OR => '|',
            Op::AND_AND => '&&',
            Op::OR_OR => '||',
            default => "?(unexpected operator: $op)",
        };
    }

    private static function typeString(Type $t, bool $ref): string
    {
        $s = match ($t->main) {
            BasicType::ARRAY => sprintf('array<%s, %d>', self::typeString($t->sub[0], false), $t->length),
            BasicType::STRUCT => throw new LogicException('msl: a struct is not implemented'),
            default => self::basicTypeString($t->main),
        };
        return $ref ? "$s&" : $s;
    }

    private static function basicTypeString(int $t): string
    {
        return match ($t) {
            BasicType::NONE => '?(none)',
            BasicType::BOOL => 'bool',
            BasicType::INT => 'int',
            BasicType::FLOAT => 'float',
            BasicType::VEC2 => 'float2',
            BasicType::VEC3 => 'float3',
            BasicType::VEC4 => 'float4',
            BasicType::IVEC2 => 'int2',
            BasicType::IVEC3 => 'int3',
            BasicType::IVEC4 => 'int4',
            BasicType::MAT2 => 'float2x2',
            BasicType::MAT3 => 'float3x3',
            BasicType::MAT4 => 'float4x4',
            BasicType::UINT => 'uint',
            BasicType::UVEC2 => 'uint2',
            BasicType::UVEC3 => 'uint3',
            BasicType::UVEC4 => 'uint4',
            BasicType::ARRAY => '?(array)',
            BasicType::STRUCT => '?(struct)',
            default => "?(unknown type: $t)",
        };
    }

    private static function builtinFuncString(string $f): string
    {
        return match ($f) {
            BuiltinFunc::BOOL_F => 'static_cast<bool>',
            BuiltinFunc::INT_F => 'static_cast<int>',
            BuiltinFunc::UINT_F => 'static_cast<uint>',
            BuiltinFunc::UVEC2_F => 'uint2',
            BuiltinFunc::UVEC3_F => 'uint3',
            BuiltinFunc::UVEC4_F => 'uint4',
            BuiltinFunc::FLOAT_F => 'static_cast<float>',
            BuiltinFunc::VEC2_F => 'float2',
            BuiltinFunc::VEC3_F => 'float3',
            BuiltinFunc::VEC4_F => 'float4',
            BuiltinFunc::IVEC2_F => 'int2',
            BuiltinFunc::IVEC3_F => 'int3',
            BuiltinFunc::IVEC4_F => 'int4',
            BuiltinFunc::MAT2_F => 'float2x2',
            BuiltinFunc::MAT3_F => 'float3x3',
            BuiltinFunc::MAT4_F => 'float4x4',
            BuiltinFunc::INVERSESQRT => 'rsqrt',
            BuiltinFunc::TEXEL_AT => '?(__texelAt)',
            default => $f,
        };
    }

    // uniform.go: the layout of the struct Uniforms.

    /**
     * Where each of $p's uniform variables lies in the struct Uniforms of
     * fragment(), in bytes, and the struct's size, as Metal lays it out: a
     * vector of 3 takes the room of 4, a matrix is its columns, and an
     * array's elements are as far apart as their alignment makes them.
     * @return array{0: list<int>, 1: int}
     */
    public static function uniformLayout(Program $p): array
    {
        $offsets = [];
        $size = 0;
        $align = 1;
        foreach ($p->uniforms as $u) {
            [$s, $a] = self::sizeAlign($u);
            $size = self::roundUp($size, $a);
            $offsets[] = $size;
            $size += $s;
            $align = max($align, $a);
        }
        return [$offsets, self::roundUp($size, $align)];
    }

    /**
     * For each lane of $p's uniforms as an interpreter keeps them (in order,
     * columns of matrices, elements of arrays), its offset in bytes in the
     * struct Uniforms of fragment(), and the struct's size.
     * @return array{0: list<int>, 1: int}
     */
    public static function uniformLanes(Program $p): array
    {
        [$offsets, $size] = self::uniformLayout($p);
        $lanes = [];
        foreach ($p->uniforms as $i => $u) {
            foreach (self::typeLanes($u) as $at) {
                $lanes[] = $offsets[$i] + $at;
            }
        }
        return [$lanes, $size];
    }

    /** The offset in bytes of each lane of a value of type $t in its Metal layout. @return list<int> */
    private static function typeLanes(Type $t): array
    {
        $out = [];
        $walk = function (Type $t, int $base) use (&$walk, &$out): void {
            if ($t->main === BasicType::ARRAY) {
                [$s, $a] = self::sizeAlign($t->sub[0]);
                $stride = self::roundUp($s, $a);
                for ($i = 0; $i < $t->length; $i++) {
                    $walk($t->sub[0], $base + $i * $stride);
                }
            } elseif ($t->isMatrix()) {
                $n = $t->matrixSize();
                $col = new Type(Type::vectorOf(BasicType::FLOAT, $n));
                [, $a] = self::sizeAlign($col);
                for ($c = 0; $c < $n; $c++) {
                    $walk($col, $base + $c * self::roundUp(4 * $n, $a));
                }
            } elseif ($t->main === BasicType::BOOL) {
                $out[] = $base;
            } else {
                $n = max($t->vectorElementCount(), 1);
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $base + 4 * $i;
                }
            }
        };
        $walk($t, 0);
        return $out;
    }

    /** The size and the alignment of $t in Metal, in bytes. @return array{0: int, 1: int} */
    private static function sizeAlign(Type $t): array
    {
        switch ($t->main) {
            case BasicType::BOOL:
                return [1, 1];
            case BasicType::INT: case BasicType::UINT: case BasicType::FLOAT:
                return [4, 4];
            case BasicType::VEC2: case BasicType::IVEC2: case BasicType::UVEC2:
                return [8, 8];
            case BasicType::VEC3: case BasicType::IVEC3: case BasicType::UVEC3: case BasicType::VEC4: case BasicType::IVEC4: case BasicType::UVEC4:
                return [16, 16];
            case BasicType::MAT2:
                return [16, 8];
            case BasicType::MAT3:
                return [48, 16];
            case BasicType::MAT4:
                return [64, 16];
            case BasicType::ARRAY:
                [$s, $a] = self::sizeAlign($t->sub[0]);
                return [$t->length * self::roundUp($s, $a), $a];
        }
        throw new LogicException(sprintf('msl: no layout for %s', $t->string()));
    }

    private static function roundUp(int $x, int $a): int
    {
        return intdiv($x + $a - 1, $a) * $a;
    }

    // fragment.go: the fragment entry point as a function.

    /**
     * $p's fragment entry point as a Metal function named $name, for a
     * renderer's fragment shader to call, after the functions it reaches and
     * the prelude (msl.Fragment):
     *
     *     float4 name(constant Uniforms& uniforms, texture2d<float> T0, ..., float4 position, ...varyings)
     *
     * The uniforms parameter is left out when $p has none, and there is a
     * texture parameter for each of $p's textures. "" for a program without
     * a fragment entry point.
     */
    public static function fragment(Program $p, string $name): string
    {
        [$lib, $entry, $funcs] = $p->fragmentLibrary();
        if ($entry === null) {
            return '';
        }
        $c = new self(library: true);

        $lines = explode("\n", self::prelude());
        array_push($lines, '', '{{.Structs}}');
        if ($lib->uniforms !== []) {
            array_push($lines, '', 'struct Uniforms {');
            foreach ($lib->uniforms as $i => $u) {
                $lines[] = sprintf("\t%s;", $c->varDecl($u, "U$i", false));
            }
            $lines[] = '};';
        }
        $lines[] = '';
        foreach ($funcs as $f) {
            array_push($lines, ...$c->function($lib, $f, true));
        }
        foreach ($funcs as $f) {
            array_push($lines, '', ...$c->function($lib, $f, false));
        }

        // The entry point, under its name.
        $params = $args = [];
        if ($lib->uniforms !== []) {
            $params[] = 'constant Uniforms& uniforms';
            $args[] = 'uniforms';
        }
        for ($i = 0; $i < $lib->textureCount; $i++) {
            $params[] = "texture2d<float> T$i";
            $args[] = "T$i";
        }
        foreach ($entry->inParams as $i => $t) {
            $n = $i > 0 ? sprintf('v%d', $i - 1) : 'position';
            $params[] = $c->varDecl($t, $n, false);
            $args[] = $n;
        }
        array_push($lines, '', sprintf('float4 %s(%s) {', $name, implode(', ', $params)), sprintf("\treturn F%d(%s);", $entry->index, implode(', ', $args)), '}');

        return $c->finish(implode("\n", $lines));
    }
}
