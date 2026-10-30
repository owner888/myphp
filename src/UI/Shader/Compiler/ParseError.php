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

use RuntimeException;

/**
 * What a source has wrong (shader.go's ParseError): its message is each
 * error as "line:column: message", one a line, as Go's Error() gives it.
 * A syntax error is one, the first the parser met.
 * @internal
 */
final class ParseError extends RuntimeException
{
    /** @param list<CompileError> $errs */
    public function __construct(private readonly array $errs)
    {
        parent::__construct(implode("\n", array_map(fn(CompileError $e): string => "{$e->position}: {$e->message}", $errs)));
    }

    /** Where the errors are, in the order the message gives them. @return list<Position> */
    public function positions(): array
    {
        return array_map(fn(CompileError $e): Position => $e->position, $this->errs);
    }
}
