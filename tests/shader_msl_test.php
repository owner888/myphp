<?php
// The MSL backend (UI\Shader\Msl): what it writes of the oracle's programs
// is what mygo's msl.Compile and msl.Fragment wrote, byte for byte, and the
// uniforms' lanes are where msl.UniformLanes put them. The oracle's cases
// come from mygo's compiler tests; fragment.json.gz's are real shaders
// (mygo's examples and ui/shader's tests) as ui/shader compiles them, what
// the runtime draws with.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Shader\Msl\Msl;

require_once __DIR__ . '/ShaderOracle.php';

$cases = [...shaderOracle(), ...json_decode(gzdecode((string)file_get_contents(__DIR__ . '/testdata/shader/fragment.json.gz')), true, 512, JSON_THROW_ON_ERROR)];

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
$counts = ['msl' => [0, 0], 'fragment_msl' => [0, 0], 'lanes' => [0, 0]]; // [matched, all]
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
    if (isset($case['msl'])) {
        $compare('msl', $n, $case['msl'], Msl::compile(irLoad($case['ir'])), $file);
    }
    if (isset($case['fragment_msl'])) {
        $compare('fragment_msl', $n, $case['fragment_msl'], Msl::fragment(irLoad($case['ir']), 'shaderFragment'), $file);
    }
    if (isset($case['lanes'])) {
        $compare('lanes', $n, [$case['lanes'], $case['lanes_size']], Msl::uniformLanes(irLoad($case['ir'])), $file);
    }
}
foreach ($counts as $what => [$ok, $all]) {
    echo "  $what: $ok of $all match\n";
    check($all > 0 && $ok === $all, "$what: as mygo's ($ok of $all)");
}
