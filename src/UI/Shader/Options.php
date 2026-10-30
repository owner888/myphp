<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader;

use MyPHP\UI\Image;

/** What a shader draws with (mygo's ui/shader Options). */
final class Options
{
    /**
     * @param array<string, int|float|bool|list<int|float|bool>> $uniforms
     * @param list<?Image> $images
     * @param array{0: float, 1: float} $maxSampleOffset
     */
    public function __construct(
        /**
         * The values of the shader's uniform variables, by name: a number
         * or a bool for a scalar; a list of numbers for a vector, a matrix
         * (by columns) or an array (elements in order); an int, a color
         * (0xRRGGBB, or a wide one, as Oklch makes), for a vec3 or vec4, its
         * components from 0 to 1, alpha 1. A variable left out is zero, and
         * a name the shader has not is ignored. Integers set ints and uints
         * by their low 32 bits.
         */
        public array $uniforms = [],
        /** Values set by Shader::uniform, with no names looked up each frame; $uniforms then sets the variables it names over them. */
        public ?Values $values = null,
        /** The images image0 to image3 read: images, or canvases' bitmaps (Canvas::bitmap); null for none, which reads as transparent. */
        public array $images = [null, null, null, null],
        /** Rounds the corners of the rectangle draw() fills, in points. */
        public float $radius = 0.0,
        /** How far, at most, across and down in points, an effect reads its content away from the pixel it draws, as a distortion moves pixels: the content renders that much beyond the element's box, and the effect draws over all of it. */
        public array $maxSampleOffset = [0.0, 0.0],
        /** For a shader reading its backdrop (backdropAt, backdropSample), the standard deviation of the backdrop's blur, in points, 0 for none. */
        public float $backdropBlur = 0.0,
    ) {
        $this->images = array_pad(array_slice($images, 0, 4), 4, null);
    }
}
