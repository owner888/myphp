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

/** The kinds of a statement (shaderir's StmtType), as Go's values. @internal */
final class StmtType
{
    public const EXPR_STMT = 0, BLOCK_STMT = 1, ASSIGN = 2, INIT = 3, IF = 4, FOR = 5, CONTINUE = 6, BREAK = 7, RETURN = 8, DISCARD = 9;
}
