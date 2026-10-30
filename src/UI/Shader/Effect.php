<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader;

use MyPHP\UI\{Image, Painter};

/** A shader drawing an element in place of the element as it is (Node::effect), as Shader::effect returns (mygo's Effect). */
final class Effect
{
    /** @internal Shader::effect makes it */
    public function __construct(private readonly Shader $shader, private readonly Options $options) {}

    /** How far the effect reads its content beyond the element's box, in points: its Options::$maxSampleOffset. @return array{0: float, 1: float} */
    public function effectMargin(): array
    {
        return $this->options->maxSampleOffset;
    }

    /**
     * @internal the runtime: paints the element from its content, the box's
     * corner at $inset in the content's pixels: the shader over all of the
     * content, reading it as its last image.
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     * @param array{0: float, 1: float} $inset
     */
    public function paintEffect(Painter $p, array $box, Image $content, array $inset): void
    {
        $this->shader->paintEffect($p, $box, $content, $inset, $this->options);
    }
}
