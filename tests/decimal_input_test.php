<?php
// mygo's decimal_test.go: decimalInput (Context::decimalInput) edits a
// string as an exact decimal, never rounded; what is wrong is marked,
// and an enclosing field shows it below the control.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, DecimalInput, Key, Node, Role, TextState};

/** A decimalInput of $value in a field named Amount, and a text input named Note, which takes the focus from it (mygo's decimalTester). */
function decimalTester(string &$value, string $lo, string $hi, string $step): Headless
{
    $note = new TextState('');
    $h = new Headless(function (Context $c) use (&$value, $lo, $hi, $step, $note): void {
        $c->column(10, 12)->alignItems(Node::START);
          $c->field('Amount', function () use ($c, &$value, $lo, $hi, $step) { $c->decimalInput($value, $lo, $hi, $step); });
          $c->field('Note', function () use ($c, $note) { $c->input($note); });
        $c->end();
    }, 400, 300);
    $h->input->push(['accessOn']);
    $h->frame();
    return $h;
}

/** The node of the text field named $label (mygo's accessNode). */
function decimalNode(Headless $h, int $role, string $label): AccessNode
{
    foreach ($h->rt->accessTree->nodes as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    throw new \RuntimeException("no $label");
}

/** The node of the input named Amount (mygo's amount). */
function decimalAmount(Headless $h): AccessNode
{
    return decimalNode($h, Role::TEXT_FIELD, 'Amount');
}

/** Focuses the input named Amount and replaces its text with $s (mygo's retype). */
function decimalRetype(Headless $h, string $s): void
{
    $h->clickText('Amount');
    $h->input->focus->selectAll();
    if ($s === '') {
        $h->key(Key::DELETE, 'deleteBackward:');
        return;
    }
    $h->type($s);
}

/** Whether the frame shows a text containing $s (mygo's HasText). */
function decimalHasText(Headless $h, string $s): bool
{
    foreach ($h->texts() as $t) { if (str_contains($t, $s)) { return true; } }
    return false;
}

function decimalInvalid(AccessNode $n): bool
{
    return ($n->states & AccessNode::INVALID) !== 0;
}

// Exact: a float holds 90071992547409.94 here (TestDecimalInputExact).
$v = '0.00';
$h = decimalTester($v, '0', '1000000000000000', '0.01');
decimalRetype($h, '90071992547409.93');
same('90071992547409.93', $v, 'typed 90071992547409.93');
$h->up();
same('90071992547409.94', $v, 'Up');
$h->down(); $h->down();
same('90071992547409.92', $v, 'Down twice');
$n = decimalAmount($h);
check($n->value === '90071992547409.92' && $n->step === 0.01 && $n->min === 0.0 && $n->max === 1e15, 'assistive technology reads the value as its text, exactly: ' . json_encode([$n->value, $n->step, $n->min, $n->max]));

// Decimals (TestDecimalInputDecimals): zeros past the step's decimals round nothing.
$v = '1.00';
$h = decimalTester($v, '', '', '0.01');
decimalRetype($h, '1.500');
same('1.50', $v, 'typed 1.500');
$n = decimalAmount($h);
check($n->value === '1.500' && !decimalInvalid($n), "as typed while focused: {$n->value}");
$h->clickText('Note');
same('1.50', decimalAmount($h)->value, 'once the focus went');
// Digits grouped by commas, and a sign.
foreach ([['1,234.5', '1234.50'], ['-12,345,678', '-12345678.00'], ['+.5', '0.50'], [' 7 ', '7.00']] as [$typed, $want]) {
    decimalRetype($h, $typed);
    same($want, $v, "typed \"$typed\"");
}
// A third decimal would round: the value stays, and the input says why, below it too, until it is corrected.
decimalRetype($h, '1.005');
$msg = 'Use at most 2 decimal places';
same('7.00', $v, '1.005 leaves the value');
$n = decimalAmount($h);
check(decimalInvalid($n) && $n->description === $msg && decimalHasText($h, $msg), '1.005 is marked: ' . json_encode([$n->description, $h->texts()]));
$h->clickText('Note');
$n = decimalAmount($h);
check($n->value === '1.005' && decimalInvalid($n) && decimalHasText($h, $msg) && $v === '7.00', "once the focus went: {$n->value}, $v");
decimalRetype($h, '1.01');
$n = decimalAmount($h);
check($v === '1.01' && !decimalInvalid($n) && !decimalHasText($h, $msg), "corrected: $v");
// What is not a number.
foreach (['abc', '1,2', '1.2.3', '1e3', '-', '12,34.5', '1 000'] as $s) {
    decimalRetype($h, $s);
    $n = decimalAmount($h);
    check($v === '1.01' && decimalInvalid($n) && $n->description === 'Enter a number', "typed \"$s\": $v, {$n->description}");
}
// Nothing typed leaves the value, shown again once the focus goes.
decimalRetype($h, '');
check($v === '1.01' && !decimalInvalid(decimalAmount($h)), "emptied: $v");
$h->clickText('Note');
same('1.01', decimalAmount($h)->value, 'emptied, then the focus went');

// Whole numbers (TestDecimalInputWholeNumbers).
$v = '3';
$h = decimalTester($v, '1', '99', '1');
decimalRetype($h, '4.0');
same('4', $v, 'typed 4.0');
decimalRetype($h, '4.5');
check($v === '4' && decimalAmount($h)->description === 'Enter a whole number', "typed 4.5: $v");

// Bounds (TestDecimalInputBounds).
$v = '50.00';
$h = decimalTester($v, '0', '100', '0.01');
foreach ([['100.01', 'Enter 100.00 or less'], ['-0.01', 'Enter 0.00 or more']] as [$typed, $msg]) {
    decimalRetype($h, $typed);
    $n = decimalAmount($h);
    check($v === '50.00' && decimalInvalid($n) && $n->description === $msg && decimalHasText($h, $msg), "typed $typed: $v, {$n->description}");
}
decimalRetype($h, '100');
same('100.00', $v, 'typed 100, the top');
decimalRetype($h, '0');
same('0.00', $v, 'typed 0, the bottom');
// "" is no limit, which assistive technology reads as infinite.
$w = '0';
$h = decimalTester($w, '', '', '1');
decimalRetype($h, '-123456789012345678901234567890');
same('-123456789012345678901234567890', $w, 'no limits');
$n = decimalAmount($h);
check($n->min === -INF && $n->max === INF && $n->step === 1.0, 'no limits: ' . json_encode([(string)$n->min, (string)$n->max, $n->step]));
// One limit.
$x = '5.0';
$h = decimalTester($x, '', '10.0', '0.5');
decimalRetype($h, '10.5');
check($x === '5.0' && decimalAmount($h)->description === 'Enter 10.0 or less', "above the top: $x");
decimalRetype($h, '-1000000000.5');
same('-1000000000.5', $x, 'no bottom');

// Steps (TestDecimalInputSteps).
$v = '0.75';
$h = decimalTester($v, '0', '1', '0.25');
$h->clickText('Amount');
$h->up();
same('1.00', $v, 'Up from 0.75');
$h->up();
same('1.00', $v, 'Up past the top');
// At the top, the button stepping up is disabled.
check((decimalNode($h, Role::BUTTON, 'Increase')->states & AccessNode::DISABLED) !== 0, 'Increase at the top is disabled');
for ($i = 0; $i < 5; $i++) { $h->down(); }
same('0.00', $v, 'Down past the bottom');
$n = decimalAmount($h);
check($n->value === '0.00' && $n->now === 0.0, "at the bottom: {$n->value}, {$n->now}");
$h->clickLabel('Increase'); $h->clickLabel('Increase'); $h->clickLabel('Decrease');
same('0.25', $v, 'two steps up and one down from 0');
// A step replaces what is wrong with the value stepped.
decimalRetype($h, '0.333');
$h->up();
$n = decimalAmount($h);
check($v === '0.50' && $n->value === '0.50' && !decimalInvalid($n), "Up from 0.333 mistyped: $v, {$n->value}");
// Steps from nothing start at 0.
$e = '';
$h = decimalTester($e, '', '', '0.1');
$h->clickLabel('Increase');
same('0.1', $e, 'Increase from nothing');

// What the app sets (TestDecimalInputAppSets).
$v = '1.00';
$h = decimalTester($v, '0', '', '0.01');
decimalRetype($h, '1.005');
// The app sets the value while the mistyped text has the focus.
$v = '2.5';
$h->frame();
$n = decimalAmount($h);
check($n->value === '2.50' && !decimalInvalid($n) && !decimalHasText($h, 'decimal places'), "the app set 2.5: {$n->value}");
same('2.5', $v, 'showing the app\'s value leaves it');
// What the app sets is never rounded either.
$v = '3.141';
$h->frame();
$n = decimalAmount($h);
check($n->value === '3.141' && decimalInvalid($n), "the app set 3.141: {$n->value}");
$v = '-1.00';
$h->frame();
$n = decimalAmount($h);
check($n->value === '-1.00' && $n->description === 'Enter 0.00 or more', "the app set -1.00: {$n->value}, {$n->description}");
$v = '';
$h->frame();
$n = decimalAmount($h);
check($n->value === '' && !decimalInvalid($n), "the app set nothing: {$n->value}");

// changed() reports a new value (TestDecimalInputChanged).
$committed = '1.00'; $changes = 0;
$h = new Headless(function (Context $c) use (&$committed, &$changes): void {
    $value = $committed;
    $c->field('Amount', function () use ($c, &$value, &$committed, &$changes) {
        if ($c->changed($c->decimalInput($value, '0', '10', '0.01'))) {
            $committed = $value;
            $changes++;
        }
    });
}, 300, 150);
$h->input->push(['accessOn']);
$h->frame();
decimalRetype($h, '2.5');
check($committed === '2.50' && $changes === 1, "typed 2.5: $committed, $changes changes");
$h->frame();
same(1, $changes, 'no change reported again on a rebuild');
// The same value written another way, and a value rejected, change nothing.
decimalRetype($h, '2.500');
decimalRetype($h, '20');
check($committed === '2.50' && $changes === 1, "2.500 and 20: $committed, $changes changes");
$h->up();
check($committed === '2.51' && $changes === 2, "Up: $committed, $changes changes");
$h->clickLabel('Increase');
check($committed === '2.52' && $changes === 3, "Increase: $committed, $changes changes");

// Limits that are not decimals of the step throw (TestDecimalInputLimits).
foreach ([['0', '', '0'], ['0', '', '-0.01'], ['0', '', 'a'], ['0.001', '', '0.01'], ['', 'x', '1']] as [$lo, $hi, $step]) {
    $threw = false;
    try {
        $none = '';
        (new Headless(function (Context $c) use (&$none, $lo, $hi, $step): void { $c->decimalInput($none, $lo, $hi, $step); }, 200, 100))->frame();
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    check($threw, "\"$lo\", \"$hi\", \"$step\" throws");
}

// parseDecimal and formatDecimal (TestParseDecimal).
foreach ([
    ['0', 2, '0', DecimalInput::FINE],
    ['1.', 2, '100', DecimalInput::FINE],
    ['.5', 2, '50', DecimalInput::FINE],
    ['-0.00', 2, '0', DecimalInput::FINE],
    ['123,456.7', 1, '1234567', DecimalInput::FINE],
    ['1.230000', 2, '123', DecimalInput::FINE],
    ['1.231', 2, null, DecimalInput::TOO_EXACT],
    ['1.5', 0, null, DecimalInput::TOO_EXACT],
    ['', 2, null, DecimalInput::INVALID],
    ['.', 2, null, DecimalInput::INVALID],
    ['-.', 2, null, DecimalInput::INVALID],
    [',123', 0, null, DecimalInput::INVALID],
    ['1234,567', 0, null, DecimalInput::INVALID],
    ['1,234,', 0, null, DecimalInput::INVALID],
    ['1.2,3', 2, null, DecimalInput::INVALID],
    ['--1', 0, null, DecimalInput::INVALID],
    ['١٢', 0, null, DecimalInput::INVALID],
] as [$s, $scale, $want, $fault]) {
    same([$want, $fault], DecimalInput::parseDecimal($s, $scale), "parseDecimal(\"$s\", $scale)");
}
foreach ([['0', 2, '0.00'], ['5', 2, '0.05'], ['-5', 2, '-0.05'], ['-150', 2, '-1.50'], ['42', 0, '42'], ['123456', 3, '123.456']] as [$units, $scale, $want]) {
    same($want, DecimalInput::formatDecimal($units, $scale), "formatDecimal($units, $scale)");
}
// The integers in units: math/big's Add and Cmp in mygo, digit strings here.
same(['0', '-1', '100000000000000000000', '-99999999999999999999', '1'], [DecimalInput::add('-5', '5'), DecimalInput::add('5', '-6'), DecimalInput::add('99999999999999999999', '1'), DecimalInput::add('-100000000000000000000', '1'), DecimalInput::add('-1', '2')], 'add');
same([-1, 0, 1, -1, 1], [DecimalInput::cmp('-2', '-1'), DecimalInput::cmp('7', '7'), DecimalInput::cmp('10', '9'), DecimalInput::cmp('-1', '0'), DecimalInput::cmp('123456789012345678901', '123456789012345678900')], 'cmp');

// Outside a field (TestDecimalInputOutsideField): marked, with nowhere to show the message but to assistive technology.
$v = '1.00';
$h = new Headless(function (Context $c) use (&$v): void {
    $c->decimalInput($v, '0', '', '0.01')->label('Price');
}, 300, 100);
$h->input->push(['accessOn']);
$h->frame();
[$rx, $ry, , $rh] = $h->rect($h->byLabel('Price'));
$h->click($rx + 20, $ry + $rh / 2);
$h->input->focus->selectAll();
$h->type('1.001');
$n = decimalNode($h, Role::TEXT_FIELD, '');
check($v === '1.00' && decimalInvalid($n) && $n->description === 'Use at most 2 decimal places' && !decimalHasText($h, 'decimal places'), "typed 1.001: $v, {$n->description}");
