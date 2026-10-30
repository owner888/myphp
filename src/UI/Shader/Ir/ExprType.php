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

/** The kinds of an expression (shaderir's ExprType), as Go's values. @internal */
final class ExprType
{
    public const BLANK = 0, NUMBER_EXPR = 1, UNIFORM_VARIABLE = 2, TEXTURE_VARIABLE = 3, LOCAL_VARIABLE = 4, STRUCT_MEMBER = 5,
        BUILTIN_FUNC_EXPR = 6, SWIZZLING_EXPR = 7, FUNCTION_EXPR = 8, UNARY = 9, BINARY = 10, SELECTION = 11, CALL = 12,
        FIELD_SELECTOR = 13, INDEX = 14;
}
