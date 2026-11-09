<?php
// The HLSL backend (UI\Shader\Hlsl): what it writes of the oracle's
// programs is what mygo's hlsl.Fragment wrote, byte for byte, with
// ui/shader's registers (the cbuffer b2, the textures from t6), and the
// uniforms' lanes are where hlsl.UniformLanes put them in the cbuffer.
// The cases are shader_glsl_test's; testdata/shader/hlsl.json.gz has
// mygo's HLSL of each, made by its compiler from the cases' sources (a
// fragment library of those GLSL has one of).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Shader\Hlsl\Hlsl;

require_once __DIR__ . '/ShaderOracle.php';

$sets = ['oracle' => shaderOracle(), 'fragment' => json_decode(gzdecode((string)file_get_contents(__DIR__ . '/testdata/shader/fragment.json.gz')), true, 512, JSON_THROW_ON_ERROR)];
$want = json_decode(gzdecode((string)file_get_contents(__DIR__ . '/testdata/shader/hlsl.json.gz')), true, 512, JSON_THROW_ON_ERROR);

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
$counts = ['fragment_hlsl' => [0, 0], 'hlsl_lanes' => [0, 0]]; // [matched, all]
$compare = function (string $what, string $at, mixed $want, mixed $got) use (&$counts, &$shown, $firstDiff): void {
    $counts[$what][1]++;
    if ($want === $got) {
        $counts[$what][0]++;
        return;
    }
    if ($shown++ < 5) {
        echo "  $at $what: ", is_string($want) && is_string($got) ? $firstDiff($want, $got) : json_encode([$want, $got]), "\n";
    }
};

foreach ($sets as $set => $cases) {
    foreach ($cases as $n => $case) {
        $w = $want[$set][$n] ?? null;
        if (!isset($case['ir']) || $w === null) {
            continue;
        }
        $at = "$set case $n (" . ($case['file'] ?? 'oracle') . ')';
        $p = irLoad($case['ir']);
        if (isset($w['fragment_hlsl'])) {
            $compare('fragment_hlsl', $at, $w['fragment_hlsl'], Hlsl::fragment($p, 'shaderFragment', 2, 6));
        }
        $compare('hlsl_lanes', $at, [$w['hlsl_lanes'], $w['hlsl_lanes_size']], Hlsl::uniformLanes($p));
    }
}
foreach ($counts as $what => [$ok, $all]) {
    echo "  $what: $ok of $all match\n";
    check($all > 0 && $ok === $all, "$what: as mygo's ($ok of $all)");
}
