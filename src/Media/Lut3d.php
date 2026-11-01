<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * A 3D LUT of a .cube file (Adobe's, Resolve's: LUT_3D_SIZE, DOMAIN_MIN
 * and DOMAIN_MAX, then a line r g b each, red the fastest), as FFmpeg's
 * lut3d reads one: its size and its colors as Core Image's CIColorCube
 * takes them (RGBA floats, red the fastest), the domain made 0 to 1.
 *
 * @internal VideoFilters, Native\Renderers
 */
final class Lut3d
{
    private function __construct(public readonly int $size, public readonly string $data) {}

    /** The LUT of the .cube file at $path; one that is none, or not read, throws. */
    public static function read(string $path): self
    {
        if ($path === '' || !is_file($path) || ($text = @file_get_contents($path)) === false) {
            throw new RuntimeException("lut3d: cannot read \"$path\"");
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'cube') {
            throw new RuntimeException("lut3d: \"$path\" is no .cube file (MyPHP reads .cube alone)");
        }
        return self::parse($text);
    }

    /** The LUT of a .cube file's text. */
    public static function parse(string $text): self
    {
        $size = 0; $min = [0.0, 0.0, 0.0]; $max = [1.0, 1.0, 1.0]; $rows = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            $w = preg_split('/\s+/', $line) ?: [];
            match (strtoupper($w[0])) {
                'LUT_3D_SIZE' => $size = (int)($w[1] ?? 0),
                'DOMAIN_MIN' => $min = array_map('floatval', array_slice($w, 1, 3)),
                'DOMAIN_MAX' => $max = array_map('floatval', array_slice($w, 1, 3)),
                'TITLE', 'LUT_1D_SIZE', 'LUT_3D_INPUT_RANGE' => null,
                default => is_numeric($w[0]) && count($w) >= 3 ? $rows[] = [(float)$w[0], (float)$w[1], (float)$w[2]] : null,
            };
        }
        if ($size < 2 || $size > 128 || count($rows) !== $size ** 3 || count($min) !== 3 || count($max) !== 3) {
            throw new RuntimeException('lut3d: no 3D LUT of ' . ($size > 0 ? "$size³ rows" : 'a size') . ' (' . count($rows) . ' rows)');
        }
        $floats = [];
        foreach ($rows as $c) {
            for ($i = 0; $i < 3; $i++) { $floats[] = $max[$i] > $min[$i] ? ($c[$i] - $min[$i]) / ($max[$i] - $min[$i]) : $c[$i]; }
            $floats[] = 1.0;
        }
        return new self($size, pack('g*', ...$floats));
    }
}
