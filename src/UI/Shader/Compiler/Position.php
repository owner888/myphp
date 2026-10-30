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

/** Where an error is in the source (go/token's Position): the byte offset, and the line and the column from 1, the column in bytes. @internal */
final class Position
{
    public function __construct(
        public readonly int $offset,
        public readonly int $line,
        public readonly int $column,
    ) {}

    /** As go/token prints a position without a file name: line:column. */
    public function __toString(): string
    {
        return "{$this->line}:{$this->column}";
    }
}
