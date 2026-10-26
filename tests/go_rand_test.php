<?php
// Go's math/rand/v2 in PHP (Random\PCG, Random\Rand): the numbers Go 1.27
// printed for the same seeds, for a mygo app's seeded drawing to come out
// the same: PCG's 64 bits, IntN by powers of two and not, Float64,
// Shuffle, Perm, Float32, Int64, Uint32, a seed past 2^63 and NormFloat64.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Random\{PCG, Rand, U64};

$pcg = new PCG(1, 2);
$bits = [];
for ($i = 0; $i < 5; $i++) { $bits[] = U64::string($pcg->uint64()); }
same(['14192431797130687760', '11371241257079532652', '14470142590855381128', '14694613213362438554', '4321634407747778896'], $bits, "NewPCG(1, 2)'s Uint64s");
$r = Rand::pcg(7, 11);
same([2, 392988, 2, 0, 1, 292945354863, 4], array_map(fn(int $n) => $r->intN($n), [10, 1000003, 16, 1, 3, 1 << 40, 7]), 'IntN of 10, 1000003, 16, 1, 3, 2^40 and 7');
same(['0.90542219604215146', '0.80984078582152852', '0.8556297552337454'], [sprintf('%.17g', $r->float64()), sprintf('%.17g', $r->float64()), sprintf('%.17g', $r->float64())], 'Float64');
$deck = range(0, 7);
$r->shuffle(8, function (int $i, int $j) use (&$deck): void { [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]]; });
same([7, 5, 2, 3, 6, 1, 4, 0], $deck, 'Shuffle');
same([0, 3, 1, 4, 2, 5], $r->perm(6), 'Perm');
same('0.140125096', sprintf('%.9g', $r->float32()), 'Float32');
same([2033830426870759724, 2106399340, 27, 571251815277], [$r->int64(), $r->uint32(), $r->intN(100), $r->intN(1000000000000)], 'Int64, Uint32, Int32N, Int64N');
$big = Rand::pcg(-7046029254386353131, -1); // 0x9e3779b97f4a7c15, 0xffffffffffffffff
same(['11942475409000941526', 7], [U64::string($big->uint64()), $big->intN(100)], 'seeds past 2^63');

// NormFloat64: its first eight, then 200 000 more, the ziggurat's rare strips among them, summed, with the bits after them.
$n = Rand::pcg(1, 2);
same(['0.37944549835531083', '0.074738046591193985', '0.20006841200604142', '-1.1253144115495104', '-0.4005883316435388', '-3.0853771402394736', '1.932330243076978', '1.7261313937192639'],
    array_map(fn() => sprintf('%.17g', $n->normFloat64()), range(1, 8)), "NormFloat64's first");
$sum = 0.0; $tail = 0;
for ($i = 0; $i < 200000; $i++) { $v = $n->normFloat64(); $sum += $v; if (abs($v) > 3.442619855899) { $tail++; } }
same(['-647.29794839495366', 114, '10391928478546070106'], [sprintf('%.17g', $sum), $tail, U64::string($n->uint64())], 'and 200 000 more, as Go draws them');
