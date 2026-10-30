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

/** The basic types of the shader language (shaderir's BasicType): its values as Go's, Uint and its vectors after Ebitengine's own. @internal */
final class BasicType
{
    public const NONE = 0, BOOL = 1, INT = 2, FLOAT = 3, VEC2 = 4, VEC3 = 5, VEC4 = 6, IVEC2 = 7, IVEC3 = 8, IVEC4 = 9,
        MAT2 = 10, MAT3 = 11, MAT4 = 12, TEXTURE = 13, ARRAY = 14, STRUCT = 15,
        UINT = 16, UVEC2 = 17, UVEC3 = 18, UVEC4 = 19;
}
