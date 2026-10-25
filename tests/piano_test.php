<?php
// The piano example (mygo's examples/piano/main_test.go): its buttons, its
// keys' geometry, its tunes on the keyboard, and A4 at 440 Hz. Keys are
// not pressed, which would sound.
declare(strict_types=1);

namespace MyPHP\Tests;

use function MyPHP\Examples\Piano\{events, keyAt, noteNumber, synthSamples, whites, keyboardWidth};
use const MyPHP\Examples\Piano\{LOWEST, HIGHEST, SYNTH_RATE, WHITE_WIDTH, BLACK_WIDTH, KEYBOARD_HEIGHT, BLACK_HEIGHT, SONGS, COMPUTER_KEYS}; // the example's constants, in its namespace

[$view] = Headless::piano();
$t = new Headless($view, keyboardWidth() + 48, 330);
$t->frame();
foreach (SONGS as [$name]) { check($t->byText($name) !== null, "a button of $name"); }

same(22, whites(), 'white keys');
same(49, keyAt(WHITE_WIDTH - 2, 10), 'the left half of C#3\'s black key');
same(48, keyAt(WHITE_WIDTH - 2, BLACK_HEIGHT + 10), 'C3 below it');
same(50, keyAt(WHITE_WIDTH + BLACK_WIDTH, 10), 'D3 right of it');
same(-1, keyAt(keyboardWidth() + 5, 10), 'past the keyboard');

$off = [];
foreach (SONGS as [$name, , $notes]) {
    foreach (events($notes) as [$ns]) { foreach ($ns as $n) { if ($n < LOWEST || $n > HIGHEST) { $off[] = "$name $n"; } } }
}
same([], $off, 'the tunes keep to the keyboard');
check(noteNumber('A4') === 69 && noteNumber('C#4') === 61, 'note numbers');

// A4 sounds at 440 Hz, after its hammer: the lag it best correlates with itself at, between 300 and 600 Hz.
$s = synthSamples(69);
$d = count($s) / SYNTH_RATE;
check($d >= 1.4 && $d <= 4, "A4 lasts $d s");
$best = 0.0; $lag = 0;
for ($l = intdiv(SYNTH_RATE, 600); $l <= intdiv(SYNTH_RATE, 300); $l++) {
    $c = 0.0;
    for ($i = intdiv(SYNTH_RATE, 10), $n = intdiv(SYNTH_RATE * 6, 10); $i < $n; $i++) { $c += $s[$i] * $s[$i + $l]; }
    if ($c > $best) { $best = $c; $lag = $l; }
}
$f = SYNTH_RATE / $lag;
check($f >= 430 && $f <= 450, "A4 at $f Hz");
