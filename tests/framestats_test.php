<?php
// Frame statistics (mygo's framestats_test.go TestFrameStatsSetting and
// TestFrameStats): what MYPHP_FRAME_STATS sets, and the line of a frame
// over the threshold, none under it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\FrameStats;

foreach ([['', 0.0, false], ['0', 0.0, false], ['off', 0.0, false], ['1', 0.008, true], ['true', 0.008, true], ['4', 0.004, true], ['16.5', 0.0165, true], ['all', 0.0, true]] as [$v, $threshold, $on]) {
    same([$threshold, $on], FrameStats::setting($v), "MYPHP_FRAME_STATS=\"$v\"");
}
$logged = [];
FrameStats::$log = function (string $line) use (&$logged): void { $logged[] = $line; };
$f = new FrameStats(0.0);
$f->begin(10);
foreach ([FrameStats::BUILD, FrameStats::LAYOUT, FrameStats::BUILD, FrameStats::LAYOUT, FrameStats::PAINT, FrameStats::PRESENT] as $phase) { $f->lap($phase); }
$f->passes = 2;
$f->end(7, 13, 'Metal');
$line = $logged[0] ?? '';
foreach (['frame 7 took', 'build ', ' (2 passes)', 'layout ', 'paint ', 'present ', '(Metal)', 'other ', 'KB', '3 text layouts'] as $want) {
    check(str_contains($line, $want), "the line says $want: $line");
}
$logged = [];
$f = new FrameStats(3600.0);
$f->begin(0);
same(null, $f->end(8, 0, 'Metal'), 'a fast frame logs nothing');
same([], $logged, 'nothing logged');
FrameStats::$log = null;
