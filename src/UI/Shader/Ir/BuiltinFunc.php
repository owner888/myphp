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


/** The built-in functions of the shader language, by name (shaderir's BuiltinFunc): conversions, GLSL's functions, and a few of MyGo's. @internal */
final class BuiltinFunc
{
    public const LEN = 'len';
    public const CAP = 'cap';
    public const BOOL_F = 'bool';
    public const INT_F = 'int';
    public const FLOAT_F = 'float';
    public const VEC2_F = 'vec2';
    public const VEC3_F = 'vec3';
    public const VEC4_F = 'vec4';
    public const IVEC2_F = 'ivec2';
    public const IVEC3_F = 'ivec3';
    public const IVEC4_F = 'ivec4';
    public const UINT_F = 'uint';
    public const UVEC2_F = 'uvec2';
    public const UVEC3_F = 'uvec3';
    public const UVEC4_F = 'uvec4';
    public const MAT2_F = 'mat2';
    public const MAT3_F = 'mat3';
    public const MAT4_F = 'mat4';
    public const RADIANS = 'radians';
    public const DEGREES = 'degrees';
    public const SIN = 'sin';
    public const COS = 'cos';
    public const TAN = 'tan';
    public const ASIN = 'asin';
    public const ACOS = 'acos';
    public const ATAN = 'atan';
    public const ATAN2 = 'atan2';
    public const POW = 'pow';
    public const EXP = 'exp';
    public const LOG = 'log';
    public const EXP2 = 'exp2';
    public const LOG2 = 'log2';
    public const SQRT = 'sqrt';
    public const INVERSESQRT = 'inversesqrt';
    public const ABS = 'abs';
    public const SIGN = 'sign';
    public const FLOOR = 'floor';
    public const CEIL = 'ceil';
    public const FRACT = 'fract';
    public const MOD = 'mod';
    public const MIN = 'min';
    public const MAX = 'max';
    public const CLAMP = 'clamp';
    public const MIX = 'mix';
    public const STEP = 'step';
    public const SMOOTHSTEP = 'smoothstep';
    public const LENGTH = 'length';
    public const DISTANCE = 'distance';
    public const DOT = 'dot';
    public const CROSS = 'cross';
    public const NORMALIZE = 'normalize';
    public const FACEFORWARD = 'faceforward';
    public const REFLECT = 'reflect';
    public const REFRACT = 'refract';
    public const TRANSPOSE = 'transpose';
    public const DFDX = 'dfdx';
    public const DFDY = 'dfdy';
    public const FWIDTH = 'fwidth';
    public const DISCARD_F = 'discard';
    public const TEXEL_AT = '__texelAt';
    public const FRONT_FACING = 'frontfacing';

    /** The names parse() takes: all but radians and degrees, which nothing uses yet. */
    private const NAMES = ['len' => true, 'cap' => true, 'bool' => true, 'int' => true, 'float' => true, 'vec2' => true, 'vec3' => true, 'vec4' => true, 'ivec2' => true, 'ivec3' => true, 'ivec4' => true, 'uint' => true, 'uvec2' => true, 'uvec3' => true, 'uvec4' => true, 'mat2' => true, 'mat3' => true, 'mat4' => true, 'sin' => true, 'cos' => true, 'tan' => true, 'asin' => true, 'acos' => true, 'atan' => true, 'atan2' => true, 'pow' => true, 'exp' => true, 'log' => true, 'exp2' => true, 'log2' => true, 'sqrt' => true, 'inversesqrt' => true, 'abs' => true, 'sign' => true, 'floor' => true, 'ceil' => true, 'fract' => true, 'mod' => true, 'min' => true, 'max' => true, 'clamp' => true, 'mix' => true, 'step' => true, 'smoothstep' => true, 'length' => true, 'distance' => true, 'dot' => true, 'cross' => true, 'normalize' => true, 'faceforward' => true, 'reflect' => true, 'refract' => true, 'transpose' => true, 'dfdx' => true, 'dfdy' => true, 'fwidth' => true, 'discard' => true, '__texelAt' => true, 'frontfacing' => true];

    /** The built-in function named $s, or null (shaderir's ParseBuiltinFunc). */
    public static function parse(string $s): ?string
    {
        return isset(self::NAMES[$s]) ? $s : null;
    }
}
