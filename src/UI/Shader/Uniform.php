<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader;

use MyPHP\UI\Shader\Ir\Type;

/** A uniform variable of a shader, found by its name once (Shader::uniform), which Values sets (mygo's Uniform). */
final class Uniform
{
    /** @internal Shader::uniform makes it */
    public function __construct(
        public readonly Shader $shader,
        public readonly string $name,
        /** @internal its type */
        public readonly Type $type,
        /** @internal its first word, and how many */
        public readonly int $word,
        public readonly int $words,
    ) {}
}
