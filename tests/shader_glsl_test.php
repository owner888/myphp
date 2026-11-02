<?php
// The GLSL backend (UI\Shader\Glsl): what it writes of the oracle's
// programs is what mygo's glsl.Fragment wrote, byte for byte, with
// ui/shader's options, and the uniforms' lanes are where glsl.UniformLanes
// put them in the std140 block. The cases are shader_msl_test's.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Shader\Glsl\Glsl;

require_once __DIR__ . '/ShaderOracle.php';

$cases = [...shaderOracle(), ...json_decode(gzdecode((string)file_get_contents(__DIR__ . '/testdata/shader/fragment.json.gz')), true, 512, JSON_THROW_ON_ERROR)];
$options = ['flipY' => 'true', 'block' => 'ShaderUniforms', 'flipRows' => 'uShaderFlipRows']; // ui/shader's code_other.go

$shown = 0;
$firstDiff = function (string $want, string $got): string { // the first line where they differ, both ways
    $w = explode("\n", $want);
    $g = explode("\n", $got);
    for ($i = 0; ; $i++) {
        if (($w[$i] ?? null) !== ($g[$i] ?? null)) {
            return sprintf("line %d: expected %s, got %s", $i + 1, json_encode($w[$i] ?? null), json_encode($g[$i] ?? null));
        }
    }
};
$counts = ['fragment_glsl' => [0, 0], 'glsl_lanes' => [0, 0]]; // [matched, all]
$compare = function (string $what, int $n, mixed $want, mixed $got, string $file) use (&$counts, &$shown, $firstDiff): void {
    $counts[$what][1]++;
    if ($want === $got) {
        $counts[$what][0]++;
        return;
    }
    if ($shown++ < 5) {
        echo "  case $n ($file) $what: ", is_string($want) && is_string($got) ? $firstDiff($want, $got) : json_encode([$want, $got]), "\n";
    }
};

foreach ($cases as $n => $case) {
    if (!isset($case['ir'])) {
        continue;
    }
    $file = $case['file'] ?? 'oracle';
    if (isset($case['fragment_glsl'])) {
        $compare('fragment_glsl', $n, $case['fragment_glsl'], Glsl::fragment(irLoad($case['ir']), 'shaderFragment', $options), $file);
    }
    if (isset($case['glsl_lanes'])) {
        $compare('glsl_lanes', $n, [$case['glsl_lanes'], $case['glsl_lanes_size']], Glsl::uniformLanes(irLoad($case['ir'])), $file);
    }
}
foreach ($counts as $what => [$ok, $all]) {
    echo "  $what: $ok of $all match\n";
    check($all > 0 && $ok === $all, "$what: as mygo's ($ok of $all)");
}
