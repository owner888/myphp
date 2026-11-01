<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * IINA's filter presets (FilterPreset.vfPresets and afPresets): what the
 * New Filter sheet lists, each its parameters (a text, an int or a float
 * of a slider, a choice of a pop-up), in IINA's order and defaults, and
 * the filter its values make (IINA's transformers). The audio's are
 * IINA's custom two.
 *
 * @internal UI\MediaPlayerFilters
 */
final class FilterPresets
{
    /**
     * The presets of video ($video) or audio: name => parameters, by name
     * in their order: ['text', default] | ['int', min, max, step, default]
     * | ['float', min, max, default] | ['choose', choices].
     * @return array<string, array<string, array<int, mixed>>>
     */
    public static function all(bool $video): array
    {
        $custom = [
            'custom_mpv' => ['name' => ['text', ''], 'string' => ['text', '']],
            'custom_ffmpeg' => ['name' => ['text', ''], 'string' => ['text', '']],
        ];
        if (!$video) {
            return $custom;
        }
        return [
            'crop' => ['w' => ['text', ''], 'h' => ['text', ''], 'x' => ['text', ''], 'y' => ['text', '']],
            'expand' => ['w' => ['text', ''], 'h' => ['text', ''], 'x' => ['text', ''], 'y' => ['text', ''], 'aspect' => ['text', '0'], 'round' => ['text', '1']],
            'sharpen' => ['msize' => ['int', 3, 13, 2, 5], 'amount' => ['float', 0.0, 1.5, 0.0]],
            'blur' => ['msize' => ['int', 3, 13, 2, 5], 'amount' => ['float', 0.0, 1.5, 0.0]],
            'delogo' => ['x' => ['text', '1'], 'y' => ['text', '1'], 'w' => ['text', '1'], 'h' => ['text', '1']],
            'negative' => [],
            'vflip' => [],
            'hflip' => [],
            'lut3d' => ['file' => ['text', ''], 'interp' => ['choose', ['nearest', 'trilinear', 'tetrahedral']]],
        ] + $custom;
    }

    /** The default value of parameter $p (all()'s). @param array<int, mixed> $p */
    public static function default(array $p): string|int|float
    {
        return match ($p[0]) { 'text' => $p[1], 'int' => $p[4], 'float' => $p[3], 'choose' => $p[1][0] };
    }

    /**
     * The filter preset $name makes of $values (by parameter, all of them),
     * as IINA's transformers: crop and expand mpv's, sharpen and blur
     * FFmpeg's unsharp, delogo, negative (lutrgb) and lut3d FFmpeg's, vflip
     * and hflip mpv's, the custom ones their name and string; null of a
     * custom one that makes no filter.
     * @param array<string, string|int|float> $values
     */
    public static function filter(string $name, array $values): ?Filter
    {
        $v = fn(string $k): string => (string)($values[$k] ?? '');
        return match ($name) {
            'crop' => Filter::of('crop', ['w' => $v('w'), 'h' => $v('h'), 'x' => $v('x'), 'y' => $v('y')]),
            'expand' => Filter::of('expand', ['w' => $v('w'), 'h' => $v('h'), 'x' => $v('x'), 'y' => $v('y'), 'aspect' => $v('aspect'), 'round' => $v('round')]),
            'sharpen' => Filter::unsharp((float)$values['amount'], (int)$values['msize']),
            'blur' => Filter::unsharp(-(float)$values['amount'], (int)$values['msize']),
            'delogo' => Filter::lavfi('delogo', ['x' => $v('x'), 'y' => $v('y'), 'w' => $v('w'), 'h' => $v('h')]),
            'negative' => Filter::lavfi('lutrgb', ['r' => 'negval', 'g' => 'negval', 'b' => 'negval']),
            'vflip', 'hflip' => Filter::of($name),
            'lut3d' => Filter::lavfi('lut3d', ['file' => $v('file'), 'interp' => $v('interp')]),
            'custom_mpv' => Filter::parse($v('name') . '=' . $v('string')),
            'custom_ffmpeg' => new Filter('lavfi', null, null, '[' . $v('name') . '=' . $v('string') . ']'),
        };
    }
}
