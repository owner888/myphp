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

/** The operators (shaderir's Op), as Go's values. @internal */
final class Op
{
    public const ADD = 0, SUB = 1, NOT_OP = 2, COMPLEMENT_OP = 3, COMPONENT_WISE_MUL = 4, MATRIX_MUL = 5, DIV = 6, MOD_OP = 7,
        LEFT_SHIFT = 8, RIGHT_SHIFT = 9, LESS_THAN_OP = 10, LESS_THAN_EQUAL_OP = 11, GREATER_THAN_OP = 12, GREATER_THAN_EQUAL_OP = 13,
        EQUAL_OP = 14, NOT_EQUAL_OP = 15, VECTOR_EQUAL_OP = 16, VECTOR_NOT_EQUAL_OP = 17, AND = 18, AND_NOT = 19, XOR = 20, OR = 21,
        AND_AND = 22, OR_OR = 23;
}
