<?php
// Memory: MemoryWatch's samples (its line, and its warnings near memory_limit, while memory keeps growing, and when a
// sample collects many cycles); frames that leave no cycles behind (a node's draw callback holding the node or the
// Context made the whole tree garbage only the collector frees, a megabyte a frame of examples/mobile); and every
// draw and drawOver callback in src static, taking the node as its third argument, never by `use`.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Logger, MemoryWatch};

// php.ini sizes
same([128 << 20, 1 << 30, 512 << 10, 1000, -1, -1], array_map(MemoryWatch::bytes(...), ['128M', '1G', '512k', '1000', '-1', '']), 'memory_limit read as bytes');

// a steady app: its line, no warning
$w = new MemoryWatch(128 << 20);
$warned = [];
for ($i = 0; $i < 10; $i++) { $warned = [...$warned, ...$w->sample((20 << 20) + ($i % 2) * 4096, 0)]; }
same([], $warned, 'memory up and down a little: no warning');
check(str_contains(Logger::line(Logger::entries()[count(Logger::entries()) - 1]), 'MB live (+'), 'the line says what is live and how it changed: ' . Logger::line(Logger::entries()[count(Logger::entries()) - 1]));

// growing in each of 6 samples, by 1 MB or more: a leak; growing less, not
$w = new MemoryWatch(-1);
$out = [];
for ($i = 0; $i <= 6; $i++) { $out[] = $w->sample((20 << 20) + $i * (300 << 10), 0); }
check(count($out[6]) === 1 && str_contains($out[6][0], 'a leak') && array_merge(...array_slice($out, 0, 6)) === [], 'a minute of growth warns, once: ' . ($out[6][0] ?? ''));
$w = new MemoryWatch(-1);
for ($i = 0, $all = []; $i <= 6; $i++) { $all = [...$all, ...$w->sample((20 << 20) + $i * 1024, 0)]; }
same([], $all, 'growth of a few KB a minute is no leak');

// near memory_limit: warns once, again only after it went back under
$w = new MemoryWatch(100 << 20);
$near = fn(int $mb) => $w->sample($mb << 20, 0);
same(0, count($near(70)), '70% of the limit: no warning');
check(str_contains($near(80)[0] ?? '', '80% of memory_limit'), 'over 75% of memory_limit warns');
same(0, count($near(85)), 'and not again while it stays near');
$near(50);
same(1, count($near(90)), 'again once it went back under 60%');

// many cycles collected in a sample
check(str_contains((new MemoryWatch(-1))->sample(10 << 20, 5000)[0] ?? '', 'makes a cycle over and over'), 'a sample collecting thousands of cycles warns');

// frames leave no cycles: examples whose trees were cycles every frame (a callback a node keeps that holds it)
foreach (['mobile' => [390, 844], 'router' => [760, 460], 'gallery' => [820, 720], 'markdown-chat' => [820, 720]] as $name => [$width, $height]) {
    [$view] = Headless::load($name);
    $t = new Headless($view, $width, $height);
    $t->frame(5);
    gc_collect_cycles();
    gc_disable();
    $t->frame(50);
    gc_enable();
    same(0, gc_collect_cycles(), "$name: 50 frames leave no cycles for the collector");
}

// every draw and drawOver callback in src is static, and reaches the node it is on as its third argument, not by `use`
$bad = [];
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src')) as $f) {
    if (!str_ends_with((string)$f, '.php')) { continue; }
    $tk = \PhpToken::tokenize((string)file_get_contents((string)$f));
    for ($i = 1, $n = count($tk); $i < $n - 3; $i++) {
        if (!$tk[$i]->is(T_OBJECT_OPERATOR) || !in_array($tk[$i + 1]->text, ['draw', 'drawOver'], true) || $tk[$i + 2]->text !== '(') { continue; }
        $j = $i + 3;
        while ($tk[$j]->is([T_WHITESPACE, T_COMMENT])) { $j++; }
        $static = $tk[$j]->is(T_STATIC);
        if ($static) { $j++; while ($tk[$j]->is(T_WHITESPACE)) { $j++; } }
        if (!$tk[$j]->is([T_FN, T_FUNCTION])) { continue; }
        $where = basename((string)$f) . ':' . $tk[$j]->line;
        $uses = $this_ = false;
        for ($k = $j, $depth = 0; $k < $n; $k++) {
            $t = $tk[$k]->text;
            if (in_array($t, ['(', '[', '{'], true) || $tk[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) { $depth++; }
            elseif (in_array($t, [')', ']', '}'], true)) { if ($depth === 0) { break; } $depth--; }
            elseif ($t === ',' && $depth === 0) { break; }
            if ($tk[$k]->is(T_USE)) { for ($m = $k + 1; $tk[$m]->text !== ')'; $m++) { if ($tk[$i - 1]->is(T_VARIABLE) && $tk[$m]->text === $tk[$i - 1]->text) { $uses = true; } } }
            if ($tk[$k]->is(T_VARIABLE) && $t === '$this') { $this_ = true; }
        }
        if (!$static && !$this_) { $bad[] = "$where not static"; }
        if ($uses) { $bad[] = "$where holds the node it is on"; }
    }
}
same([], $bad, 'draw and drawOver callbacks are static and take their node as their third argument');
