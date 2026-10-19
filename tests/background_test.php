<?php
// A window's background colors (mygo's color.go): the CSS colors they are
// written in, and light-dark() for the two appearances.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Background;


foreach ([
    '#1e1e1e' => [30, 30, 30, 255], '#abc' => [170, 187, 204, 255], '#abcd' => [170, 187, 204, 221], '#11223344' => [17, 34, 51, 68],
    'rgb(30 30 30)' => [30, 30, 30, 255], 'rgba(10, 20, 30, 0.5)' => [10, 20, 30, 128], 'rgb(100% 0% 50% / 50%)' => [255, 0, 128, 128],
    'transparent' => [0, 0, 0, 0], ' #FFF ' => [255, 255, 255, 255],
] as $css => $want) {
    same($want, Background::parse($css)->light, "the color of $css");
}
$b = Background::parse('light-dark(#f5f5f7, rgb(30, 30, 30))');
same([245, 245, 247, 255], $b->for(false), 'light-dark, light');
same([30, 30, 30, 255], $b->for(true), 'light-dark, dark');
foreach (['#12', 'red', 'rgb(1 2)', 'rgb(a b c)', 'light-dark(#fff)', '#ggg'] as $css) {
    $threw = false; try { Background::parse($css); } catch (\InvalidArgumentException) { $threw = true; }
    check($threw, "$css accepted");
}
