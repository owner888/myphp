<?php
// Copyright 2022 The Ebiten Authors
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

// Ported to PHP from MyGo's internal/shaderir/hlsl, MyGo's fork of
// Ebitengine's (see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Hlsl;

use LogicException;
use MyPHP\UI\Shader\Ir\{BasicType, Block, BuiltinFunc, Constant, Expr, ExprType, Func, Op, Program, StmtType, Type};

/**
 * The HLSL backend (shaderir's hlsl), as far as fragment libraries go:
 * fragment() writes a program's fragment entry point as a function of
 * shader model 5 for a renderer's own pixel shader, and uniformLanes()
 * where its uniforms lie in the cbuffer it declares. An instance is a
 * compilation's state (Go's compileContext). hlsl.go, type.go, uniform.go
 * and fragment.go, in that order.
 *
 * mygo's hlsl.Compile (a whole program with its entry points and their
 * preludes) is left out, as the Glsl port leaves glsl.Compile: a library
 * has no entry points of its own, so its variables are all functions'
 * (l0, l1, ...).
 * @internal
 */
final class Hlsl
{
    /** @var array<string, string> struct names by their types' strings */
    private array $structNames = [];
    /** @var list<Type> the struct types, in the order of their names */
    private array $structTypes = [];

    private function __construct() {}

    private function structName(Type $t): string
    {
        if ($t->main !== BasicType::STRUCT) {
            throw new LogicException('hlsl: the given type at structName must be a struct');
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

    // The macros below evaluate their arguments more than once. This is safe as expressions in
    // the shader IR have no side effects: there are no pointers or writable global variables,
    // and a call to a function with output parameters is always a statement rather than a part
    // of an expression.
    private const UTIL_FUNCS = <<<'HLSL'
        // mod and modInt are macros rather than functions. Some HLSL compilers cannot
        // resolve overloads on the vector size, as a scalar argument is implicitly convertible
        // to any vector type (#3356).

        // mod is a floored modulo like GLSL's mod. HLSL's fmod is a truncated modulo.
        #define mod(x, y) ((x) - (y) * floor((x) / (y)))

        // modInt is a truncated modulo like the '%' operator. fxc emits warning X3556 for signed
        // integer % and /, and suggests uints, so the magnitude is computed with unsigned modulo
        // and the sign of the dividend is reapplied. This matches '%' except for the minimum
        // integer, where abs overflows.
        #define modInt(x, y) (sign(x) * asint(asuint(abs(x)) % asuint(abs(y))))

        float2x2 float2x2FromScalar(float x) {
        	return float2x2(x, 0, 0, x);
        }

        float3x3 float3x3FromScalar(float x) {
        	return float3x3(x, 0, 0, 0, x, 0, 0, 0, x);
        }

        float4x4 float4x4FromScalar(float x) {
        	return float4x4(x, 0, 0, 0, 0, x, 0, 0, 0, 0, x, 0, 0, 0, 0, x);
        }
        HLSL;

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
            case BasicType::INT: case BasicType::IVEC2: case BasicType::IVEC3: case BasicType::IVEC4:
                return '0';
            case BasicType::UINT: case BasicType::UVEC2: case BasicType::UVEC3: case BasicType::UVEC4:
                return '0u';
            case BasicType::FLOAT: case BasicType::VEC2: case BasicType::VEC3: case BasicType::VEC4:
            case BasicType::MAT2: case BasicType::MAT3: case BasicType::MAT4:
                return '0.0';
        }
        [$t0, $t1] = $this->typ($t);
        throw new LogicException("hlsl: unexpected type: $t0$t1");
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
                [$a, $b] = $e->exprs;
                switch ($e->op) {
                    case Op::VECTOR_EQUAL_OP:
                        return sprintf('all((%s) == (%s))', $this->expr($a), $this->expr($b));
                    case Op::VECTOR_NOT_EQUAL_OP:
                        return sprintf('!all((%s) == (%s))', $this->expr($a), $this->expr($b));
                    case Op::MATRIX_MUL:
                        // If either is a matrix, use the mul function.
                        // Swap the order of the lhs and the rhs since matrices are row-major in HLSL.
                        return sprintf('mul(%s, %s)', $this->expr($b), $this->expr($a));
                    case Op::MOD_OP:
                        if ($e->unsigned) {
                            break; // unsigned integers have the plain operator
                        }
                        // Use modInt instead of '%' to avoid fxc's warning X3556 for signed integers.
                        return sprintf('modInt((%s), (%s))', $this->expr($a), $this->expr($b));
                    case Op::AND_NOT:
                        return sprintf('(%s) & ~(%s)', $this->expr($a), $this->expr($b));
                }
                return sprintf('(%s) %s (%s)', $this->expr($a), self::opString($e->op), $this->expr($b));
            case ExprType::SELECTION:
                return sprintf('(%s) ? (%s) : (%s)', $this->expr($e->exprs[0]), $this->expr($e->exprs[1]), $this->expr($e->exprs[2]));
            case ExprType::CALL:
                $callee = $e->exprs[0];
                $args = [];
                foreach (array_slice($e->exprs, 1) as $exp) {
                    $args[] = $this->expr($exp);
                }
                if ($callee->type === ExprType::BUILTIN_FUNC_EXPR) {
                    switch ($callee->builtinFunc) {
                        case BuiltinFunc::VEC2_F: case BuiltinFunc::VEC3_F: case BuiltinFunc::VEC4_F:
                        case BuiltinFunc::IVEC2_F: case BuiltinFunc::IVEC3_F: case BuiltinFunc::IVEC4_F:
                        case BuiltinFunc::UVEC2_F: case BuiltinFunc::UVEC3_F: case BuiltinFunc::UVEC4_F:
                            if (count($args) === 1) {
                                // Use casting. For example, `float4(1)` doesn't work.
                                return sprintf('(%s)(%s)', $this->expr($callee), $args[0]);
                            }
                            break;
                        case BuiltinFunc::MAT2_F:
                            // A single argument is always a float scalar: the front end lowers a matrix
                            // argument to the argument itself. In HLSL, casting a scalar to a matrix
                            // initializes all the components; there seems to be no easy way to have an identity matrix.
                            if (count($args) === 1) {
                                return sprintf('float2x2FromScalar(%s)', $args[0]);
                            }
                            break;
                        case BuiltinFunc::MAT3_F:
                            if (count($args) === 1) {
                                return sprintf('float3x3FromScalar(%s)', $args[0]);
                            }
                            break;
                        case BuiltinFunc::MAT4_F:
                            if (count($args) === 1) {
                                return sprintf('float4x4FromScalar(%s)', $args[0]);
                            }
                            break;
                        case BuiltinFunc::TEXEL_AT:
                            // Floor the position so that a negative position is out of the texture.
                            return sprintf('%s.Load(int3(int2(floor(%s)), 0))', $args[0], implode(', ', array_slice($args, 1)));
                        case BuiltinFunc::MIN: case BuiltinFunc::MAX:
                            $f = $this->expr($callee);
                            $result = $args[0];
                            for ($i = 1; $i < count($args); $i++) {
                                $result = "$f($result, {$args[$i]})";
                            }
                            return $result;
                        case BuiltinFunc::FRONT_FACING:
                            // A library is drawn only with front faces.
                            return '(true)';
                    }
                }
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
                    array_push($lines, $idt . 'discard;', $idt . 'return float4(0.0, 0.0, 0.0, 0.0);');
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

    // type.go: the names of operators, types and built-in functions in HLSL.

    private static function opString(int $op): string
    {
        return match ($op) {
            Op::ADD => '+',
            Op::SUB => '-',
            Op::NOT_OP => '!',
            Op::COMPLEMENT_OP => '~',
            Op::COMPONENT_WISE_MUL => '*',
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

    /** $t as HLSL writes it, around a name: the type, then what follows the name ([4] of an array). @return array{0: string, 1: string} */
    private static function typeString(Type $t): array
    {
        switch ($t->main) {
            case BasicType::ARRAY:
                [$t0, $t1] = self::typeString($t->sub[0]);
                return [$t0 . $t1, sprintf('[%d]', $t->length)];
            case BasicType::STRUCT:
                throw new LogicException('hlsl: a struct is not implemented');
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
            BuiltinFunc::VEC2_F => 'float2',
            BuiltinFunc::VEC3_F => 'float3',
            BuiltinFunc::VEC4_F => 'float4',
            BuiltinFunc::UVEC2_F => 'uint2',
            BuiltinFunc::UVEC3_F => 'uint3',
            BuiltinFunc::UVEC4_F => 'uint4',
            BuiltinFunc::IVEC2_F => 'int2',
            BuiltinFunc::IVEC3_F => 'int3',
            BuiltinFunc::IVEC4_F => 'int4',
            BuiltinFunc::MAT2_F => 'float2x2',
            BuiltinFunc::MAT3_F => 'float3x3',
            BuiltinFunc::MAT4_F => 'float4x4',
            BuiltinFunc::INVERSESQRT => 'rsqrt',
            BuiltinFunc::FRACT => 'frac',
            BuiltinFunc::MIX => 'lerp',
            BuiltinFunc::DFDX => 'ddx',
            BuiltinFunc::DFDY => 'ddy',
            BuiltinFunc::TEXEL_AT => '?(__texelAt)',
            default => $f,
        };
    }

    // uniform.go: the layout of the cbuffer.

    public const UNIFORM_VARIABLE_BOUNDARY_IN_DWORDS = 4;

    /**
     * The offsets of $p's uniform variables in dwords in the HLSL layout
     * (mygo's UniformVariableOffsetsInDwords): a vector does not straddle a
     * register, a matrix and an array start one, an array's elements but
     * its last take whole registers.
     * @see https://docs.microsoft.com/en-us/windows/win32/direct3dhlsl/dx-graphics-hlsl-packing-rules
     * @return list<int>
     */
    public static function uniformVariableOffsetsInDwords(Program $p): array
    {
        $b = self::UNIFORM_VARIABLE_BOUNDARY_IN_DWORDS;
        $align = fn(int $x): int => $x === 0 ? 0 : (intdiv($x - 1, $b) + 1) * $b;
        $offsets = [];
        $head = 0;
        foreach ($p->uniforms as $u) {
            switch ($u->main) {
                case BasicType::BOOL: case BasicType::FLOAT: case BasicType::INT: case BasicType::UINT:
                    $offsets[] = $head;
                    $head += 1;
                    break;
                case BasicType::VEC2: case BasicType::IVEC2: case BasicType::UVEC2:
                    if ($head % $b >= 3) { $head = $align($head); }
                    $offsets[] = $head;
                    $head += 2;
                    break;
                case BasicType::VEC3: case BasicType::IVEC3: case BasicType::UVEC3:
                    if ($head % $b >= 2) { $head = $align($head); }
                    $offsets[] = $head;
                    $head += 3;
                    break;
                case BasicType::VEC4: case BasicType::IVEC4: case BasicType::UVEC4:
                    if ($head % $b >= 1) { $head = $align($head); }
                    $offsets[] = $head;
                    $head += 4;
                    break;
                case BasicType::MAT2:
                    // For matrices, each column is aligned to the boundary.
                    $head = $align($head);
                    $offsets[] = $head;
                    $head += 6;
                    break;
                case BasicType::MAT3:
                    $head = $align($head);
                    $offsets[] = $head;
                    $head += 11;
                    break;
                case BasicType::MAT4:
                    $head = $align($head);
                    $offsets[] = $head;
                    $head += 16;
                    break;
                case BasicType::ARRAY:
                    // Each array is 16-byte aligned.
                    $head = $align($head);
                    $offsets[] = $head;
                    $n = match ($u->sub[0]->main) {
                        BasicType::MAT2 => 6,
                        BasicType::MAT3 => 11,
                        BasicType::MAT4 => 16,
                        default => $u->sub[0]->dwordCount(),
                    };
                    // Kage rejects a zero-length array, but even if one reaches here, the head must not move backwards.
                    if ($u->length > 1) {
                        $head += ($u->length - 1) * $align($n);
                    }
                    // The last element is not followed by padding.
                    $head += $n;
                    break;
                case BasicType::STRUCT:
                    throw new LogicException('hlsl: offset for a struct is not implemented yet');
                default:
                    throw new LogicException(sprintf('hlsl: unexpected type: %s', $u->string()));
            }
        }
        return $offsets;
    }

    /**
     * For each lane of $p's uniforms as an interpreter keeps them (in
     * order, columns of matrices, elements of arrays), its offset in bytes
     * in the cbuffer of fragment(), and the cbuffer's size, a multiple of
     * 16: an array's elements and a matrix's rows start at a register each,
     * and the backend keeps matrices transposed (rows of HLSL are columns of
     * GLSL) (mygo's hlsl.UniformLanes).
     * @return array{0: list<int>, 1: int}
     */
    public static function uniformLanes(Program $p): array
    {
        $offsets = self::uniformVariableOffsetsInDwords($p);
        $lanes = [];
        $walk = function (Type $t, int $dword) use (&$walk, &$lanes): void {
            if ($t->main === BasicType::ARRAY) {
                $stride = match ($t->sub[0]->main) {
                    BasicType::MAT2 => 8,
                    BasicType::MAT3 => 12,
                    default => $t->sub[0]->dwordCount(),
                };
                $stride = intdiv($stride + 3, 4) * 4;
                for ($i = 0; $i < $t->length; $i++) {
                    $walk($t->sub[0], $dword + $i * $stride);
                }
            } elseif ($t->isMatrix()) {
                $n = $t->matrixSize();
                for ($col = 0; $col < $n; $col++) {
                    for ($row = 0; $row < $n; $row++) {
                        $lanes[] = 4 * ($dword + $row * 4 + $col);
                    }
                }
            } else {
                for ($i = 0; $i < $t->dwordCount(); $i++) {
                    $lanes[] = 4 * ($dword + $i);
                }
            }
        };
        foreach ($p->uniforms as $i => $u) {
            $walk($u, $offsets[$i]);
        }
        $walk = null; // a recursive closure holds itself: let it go, or it and what it reached wait for the cycle collector (docs/architecture.md)
        $size = 0;
        foreach ($lanes as $l) {
            $size = max($size, $l + 4);
        }
        return [$lanes, intdiv($size + 15, 16) * 16];
    }

    // fragment.go: the fragment entry point as a function.

    /**
     * $p's fragment entry point as an HLSL (shader model 5) function named
     * $name, for a renderer's pixel shader to call, after the helpers, the
     * uniforms (a cbuffer in register b$cbuffer, laid out as
     * uniformVariableOffsetsInDwords() says), the textures (Texture2D T0,
     * T1, ... in registers t$texture on) and the functions it reaches
     * (mygo's hlsl.Fragment):
     *
     *     float4 name(float4 position, ...varyings)
     *
     * "" for a program without a fragment entry point.
     */
    public static function fragment(Program $p, string $name, int $cbuffer, int $texture): string
    {
        [$lib, $entry, $funcs] = $p->fragmentLibrary();
        if ($entry === null) {
            return '';
        }
        $c = new self();
        $offsets = self::uniformVariableOffsetsInDwords($lib);

        $lines = [...explode("\n", self::UTIL_FUNCS), '', '{{.Structs}}'];
        if ($lib->uniforms !== []) {
            array_push($lines, '', sprintf('cbuffer Uniforms : register(b%d) {', $cbuffer));
            foreach ($lib->uniforms as $i => $t) {
                $offset = sprintf('c%d', intdiv($offsets[$i], self::UNIFORM_VARIABLE_BOUNDARY_IN_DWORDS));
                $k = $offsets[$i] % self::UNIFORM_VARIABLE_BOUNDARY_IN_DWORDS;
                if ($k > 0) {
                    $offset .= '.' . 'xyzw'[$k];
                }
                $lines[] = sprintf("\t%s : packoffset(%s);", $c->varDecl($t, "U$i"), $offset);
            }
            $lines[] = '}';
        }
        $lines[] = '';
        for ($i = 0; $i < $lib->textureCount; $i++) {
            $lines[] = sprintf('Texture2D T%d : register(t%d);', $i, $texture + $i);
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
        array_push($lines, '', sprintf('float4 %s(%s) {', $name, implode(', ', $params)), sprintf("\treturn F%d(%s);", $entry->index, implode(', ', $args)), '}');

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
