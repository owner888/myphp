<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader;

use Closure;
use InvalidArgumentException;
use MyPHP\UI\Shader\Ir\{BasicType, Type};

/**
 * The values of a shader's uniform variables, which Options::$values
 * draws with (mygo's Values), as Flutter's FragmentShader and Skia's
 * SkRuntimeShaderBuilder hold them: set them once or each frame, by
 * Uniform, and draw with them as often as needed; drawing copies them.
 * Variables not set are zero. The setters throw for a variable of another
 * type or of another shader, and return the values.
 */
final class Values
{
    /** @internal the words of the uniforms */
    public array $words;
    /** @var array<int, array{0: int, 1: int, 2: float}> @internal the colors set, by their first word: words, color, alpha */
    public array $colors = [];

    /** @internal Shader::newValues makes them */
    public function __construct(public readonly Shader $shader, int $words)
    {
        $this->words = array_fill(0, $words, 0);
    }

    /** Throws unless $u is a variable of these values' shader of a type $ok takes, named $what. @param Closure(Type): bool $ok */
    private function check(Uniform $u, string $what, Closure $ok): void
    {
        if ($u->shader !== $this->shader) {
            throw new InvalidArgumentException("shader: {$u->name} is not a uniform variable of the values' shader");
        }
        if (!$ok($u->type)) {
            throw new InvalidArgumentException("shader: {$u->name} is a {$u->type->string()}, not $what");
        }
    }

    private static function is(int $main): Closure
    {
        return fn(Type $t): bool => $t->main === $main;
    }

    /** The types whose lanes are numbers of $scalar: scalars, vectors, matrices and arrays of them. */
    private static function lanesOf(int $scalar): Closure
    {
        return fn(Type $t): bool => ($t->main === BasicType::ARRAY ? $t->sub[0] : $t)->scalar() === $scalar;
    }

    /** Throws for more numbers than $u holds. */
    private function fits(Uniform $u, int $n): void
    {
        if ($n > $u->words) {
            throw new InvalidArgumentException("shader: $n numbers for {$u->name}, which holds {$u->words}");
        }
    }

    private static function bits(float $x): int
    {
        return unpack('V', pack('g', $x))[1];
    }

    /** @param list<float> $xs */
    private function setFloats(Uniform $u, array $xs): void
    {
        $this->fits($u, count($xs));
        foreach ($xs as $i => $x) { $this->words[$u->word + $i] = self::bits($x); }
        unset($this->colors[$u->word]);
    }

    public function float(Uniform $u, float $x): self
    {
        $this->check($u, 'a float', self::is(BasicType::FLOAT));
        $this->setFloats($u, [$x]);
        return $this;
    }

    public function vec2(Uniform $u, float $x, float $y): self
    {
        $this->check($u, 'a vec2', self::is(BasicType::VEC2));
        $this->setFloats($u, [$x, $y]);
        return $this;
    }

    public function vec3(Uniform $u, float $x, float $y, float $z): self
    {
        $this->check($u, 'a vec3', self::is(BasicType::VEC3));
        $this->setFloats($u, [$x, $y, $z]);
        return $this;
    }

    public function vec4(Uniform $u, float $x, float $y, float $z, float $w): self
    {
        $this->check($u, 'a vec4', self::is(BasicType::VEC4));
        $this->setFloats($u, [$x, $y, $z, $w]);
        return $this;
    }

    /** Sets the components of a vector, a matrix (by columns) or an array of floats or of them (elements in order), from the first. */
    public function floats(Uniform $u, float ...$xs): self
    {
        $this->check($u, 'of floats', self::lanesOf(BasicType::FLOAT));
        $this->setFloats($u, array_values($xs));
        return $this;
    }

    public function int(Uniform $u, int $x): self
    {
        $this->check($u, 'an int', self::is(BasicType::INT));
        $this->words[$u->word] = $x & 0xffffffff;
        return $this;
    }

    /** Sets the components of an ivec or an array of ints or of them, as floats() does. */
    public function ints(Uniform $u, int ...$xs): self
    {
        $this->check($u, 'of ints', self::lanesOf(BasicType::INT));
        $this->fits($u, count($xs));
        foreach (array_values($xs) as $i => $x) { $this->words[$u->word + $i] = $x & 0xffffffff; }
        return $this;
    }

    public function uint(Uniform $u, int $x): self
    {
        $this->check($u, 'a uint', self::is(BasicType::UINT));
        $this->words[$u->word] = $x & 0xffffffff;
        return $this;
    }

    /** Sets the components of a uvec or an array of uints or of them, as floats() does. */
    public function uints(Uniform $u, int ...$xs): self
    {
        $this->check($u, 'of uints', self::lanesOf(BasicType::UINT));
        $this->fits($u, count($xs));
        foreach (array_values($xs) as $i => $x) { $this->words[$u->word + $i] = $x & 0xffffffff; }
        return $this;
    }

    public function bool(Uniform $u, bool $b): self
    {
        $this->check($u, 'a bool', self::is(BasicType::BOOL));
        $this->words[$u->word] = $b ? 1 : 0;
        return $this;
    }

    /**
     * Sets a vec3 or vec4 to $rgb's components from 0 to 1, and $alpha, not
     * premultiplied: its sRGB ones, or, where the window draws a wide
     * gamut, those of a color outside sRGB (as Oklch makes) in extended
     * sRGB, below 0 or above 1.
     */
    public function color(Uniform $u, int $rgb, float $alpha = 1.0): self
    {
        $this->check($u, 'a vec3 or vec4', fn(Type $t): bool => $t->main === BasicType::VEC3 || $t->main === BasicType::VEC4);
        $this->colors[$u->word] = [$u->words, $rgb, $alpha];
        return $this;
    }
}
