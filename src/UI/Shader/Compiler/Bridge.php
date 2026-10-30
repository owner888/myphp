<?php
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

// Ported to PHP from MyGo's internal/shader (bridge.go, MyGo's own, under its
// package's license, see ../LICENSE), by the MyPHP contributors.
declare(strict_types=1);

namespace MyPHP\UI\Shader\Compiler;

/**
 * The source compileFragment appends to a MyGo shader (bridge.go): the
 * uniform variables that only renderers set, the functions that read them
 * and the images, and the vertex entry point, which only gives the
 * fragment entry point its arguments: renderers draw with vertex stages
 * of their own. All of it is in the internal region.
 * @internal
 */
final class Bridge
{
    /** How many images a shader reads, image0 to image3. */
    public const MAX_IMAGES = 4;

    /**
     * The name of a shader's entry point, which returns the color of a
     * pixel, premultiplied: func Fragment(dst vec4, pos vec2) vec4. dst is
     * the pixel's center in the target, in its pixels from its top left
     * corner, and pos the same point in DIPs from the top left corner of
     * the rectangle the shader fills. It may take dst alone, or nothing.
     */
    public const ENTRY = 'Fragment';

    /**
     * The uniform variables the bridge declares, which renderers set,
     * before the shader's own: the images' sizes in pixels, the size of
     * the rectangle the shader fills in DIPs, the pixels a DIP, and, for an
     * effect's shader, where the corner of the element's box lies in its
     * content (LAYER_IMAGE), in its pixels.
     */
    public const IMAGE_SIZES_UNIFORM = '__imageSizes';
    public const RECT_SIZE_UNIFORM = '__rectSize';
    public const SCALE_UNIFORM = '__scale';
    public const LAYER_INSET_UNIFORM = '__layerInset';
    /**
     * The backdrop of a shader reading it (BACKDROP_IMAGE): the rows of
     * the map from its positions to the frame's pixels, and where its
     * texels start in the frame, with how many pixels each covers across.
     */
    public const BACKDROP_ROW0_UNIFORM = '__backdropRow0';
    public const BACKDROP_ROW1_UNIFORM = '__backdropRow1';
    public const BACKDROP_AREA_UNIFORM = '__backdropArea';

    /**
     * The image a shader reads its backdrop from, through backdropAt and
     * backdropSample: what the window drew behind it, which the renderers
     * capture and blur as for effects reading their backdrop.
     */
    public const BACKDROP_IMAGE = self::MAX_IMAGES - 2;

    /** The image an effect's shader reads its element's content from, through layerAt and layerSample: the last, which its own images leave free. */
    public const LAYER_IMAGE = self::MAX_IMAGES - 1;

    /** The bridge's vertex entry point. */
    public const VERTEX_ENTRY = '__vertex';

    /** The source, as Go's bridge builds it. */
    public static function source(): string
    {
        static $source = null;
        if ($source !== null) {
            return $source;
        }
        $b = sprintf(<<<'KAGE'

%1$s

var %2$s [%3$d]vec2
var %4$s vec2
var %5$s float
var %6$s vec2
var %8$s vec3
var %9$s vec3
var %10$s vec4

// rectSize returns the size of the rectangle the shader fills, in DIPs.
func rectSize() vec2 {
	return %4$s
}

// pixelScale returns how many pixels of the target make a DIP.
func pixelScale() float {
	return %5$s
}

// layerAt returns the pixel of the content of the element an effect
// draws at p, in DIPs from the corner of its box, premultiplied:
// transparent beyond what renders.
func layerAt(p vec2) vec4 {
	return image%7$dAt(p*%5$s + %6$s)
}

// layerSample returns the content of the element an effect draws at p,
// in DIPs from the corner of its box, blended between the centers of the
// four pixels around p, premultiplied: transparent beyond what renders.
func layerSample(p vec2) vec4 {
	q := p*%5$s + %6$s
	in := step(vec2(0), q) - step(%2$s[%7$d], q)
	return image%7$dSample(q) * in.x * in.y
}

// __backdropTexel returns where p, in DIPs from the corner of the box the
// shader fills, lies in the backdrop's texels.
func __backdropTexel(p vec2) vec2 {
	q := vec2(dot(%8$s, vec3(p, 1)), dot(%9$s, vec3(p, 1)))
	return (q - %10$s.xy) / %10$s.z
}

// backdropAt returns the backdrop's texel at p, in DIPs from the corner of
// the box the shader fills, premultiplied: what the window drew behind it,
// blurred as Options.BackdropBlur says.
func backdropAt(p vec2) vec4 {
	return image%11$dAt(__backdropTexel(p))
}

// backdropSample returns the backdrop at p, in DIPs from the corner of the
// box the shader fills, blended between the texels around it.
func backdropSample(p vec2) vec4 {
	return image%11$dSample(__backdropTexel(p))
}

KAGE, Compiler::INTERNAL_REGION_BEGIN, self::IMAGE_SIZES_UNIFORM, self::MAX_IMAGES, self::RECT_SIZE_UNIFORM, self::SCALE_UNIFORM, self::LAYER_INSET_UNIFORM,
            self::LAYER_IMAGE, self::BACKDROP_ROW0_UNIFORM, self::BACKDROP_ROW1_UNIFORM, self::BACKDROP_AREA_UNIFORM, self::BACKDROP_IMAGE);
        for ($i = 0; $i < self::MAX_IMAGES; $i++) {
            $b .= sprintf(<<<'KAGE'

// image%1$dSize returns the size of image %1$d, in its pixels.
func image%1$dSize() vec2 {
	return %2$s[%1$d]
}

// image%1$dAt returns the pixel of image %1$d at p, in its pixels from its
// top left corner, premultiplied: transparent outside the image.
func image%1$dAt(p vec2) vec4 {
	in := step(vec2(0), p) - step(%2$s[%1$d], p)
	return __texelAt(__t%1$d, p) * in.x * in.y
}

// image%1$dSample returns image %1$d at p, in its pixels from its top left
// corner, blended between the centers of the four pixels around p, and
// clamped to its edges, as linear filtering samples a texture.
func image%1$dSample(p vec2) vec4 {
	q := p - 0.5
	f := floor(q)
	w := q - f
	hi := %2$s[%1$d] - 1
	a := __texelAt(__t%1$d, clamp(f, vec2(0), hi))
	b := __texelAt(__t%1$d, clamp(f+vec2(1, 0), vec2(0), hi))
	c := __texelAt(__t%1$d, clamp(f+vec2(0, 1), vec2(0), hi))
	d := __texelAt(__t%1$d, clamp(f+vec2(1), vec2(0), hi))
	return mix(mix(a, b, w.x), mix(c, d, w.x), w.y)
}

KAGE, $i, self::IMAGE_SIZES_UNIFORM);
        }
        $b .= sprintf(<<<'KAGE'

func %s(dst vec2, pos vec2) (vec4, vec2) {
	return vec4(dst, 0, 1), pos
}

%s

KAGE, self::VERTEX_ENTRY, Compiler::INTERNAL_REGION_END);
        return $source = $b;
    }
}
