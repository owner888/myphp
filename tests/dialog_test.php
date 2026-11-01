<?php
// Message dialogs (mygo's dialog.go, darwin/dialog.go): the cancel button
// found by its label; Escape and Cmd+. cancel, Shift allowed for the
// period; on macOS, Return only the default's key, none for -1, and the
// destructive buttons set apart.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Dialog;
use MyPHP\Native\ObjC;

same([1, 0, -1, 2], [Dialog::cancelOf(['Delete', 'Cancel']), Dialog::cancelOf(['No', 'Yes']), Dialog::cancelOf(['OK']), Dialog::cancelOf(['Save', 'Don\'t Save', 'cancel'])], 'the cancel button: the first labeled Cancel or No');
same([1, 0, 1, -1], [Dialog::cancelFor(0, ['Delete', 'Cancel']), Dialog::cancelFor(0, ['Delete', 'Keep']), Dialog::cancelFor(1, ['Delete', 'Keep']), Dialog::cancelFor(-1, ['Delete', 'Keep'])],
    'Escape: for 0 the first labeled Cancel or No, else the first, even a destructive one; the one named; none for -1 (mygo\'s CancelButton)');
$cmd = 1 << 20; $shift = 1 << 17;
same([true, true, true, false, false, false], [\MyPHP\Platform\Darwin\Dialogs::cancels(0, "\e"), \MyPHP\Platform\Darwin\Dialogs::cancels($cmd, '.'), \MyPHP\Platform\Darwin\Dialogs::cancels($cmd | $shift, '.'), \MyPHP\Platform\Darwin\Dialogs::cancels($shift, "\e"), \MyPHP\Platform\Darwin\Dialogs::cancels($cmd | 1 << 19, '.'), \MyPHP\Platform\Darwin\Dialogs::cancels(0, '.')],
    'Escape alone and Cmd+. cancel, Shift allowed for the period');

if (PHP_OS_FAMILY !== 'Darwin') { return; }
ObjC::boot(['Foundation', 'AppKit']);
$keys = fn($alert): array => array_map(fn(int $i) => ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:L', ObjC::msg('p:', $alert, 'buttons'), 'objectAtIndex:', $i), 'keyEquivalent')), range(0, ObjC::msg('L:', ObjC::msg('p:', $alert, 'buttons'), 'count') - 1));
$a = \MyPHP\Platform\Darwin\Dialogs::alert('warning', 'Delete 3 notes?', 'You cannot undo this.', ['Cancel', 'Delete'], 0, [1]);
same(["\r", ''], $keys($a), 'Return the default\'s, the cancel taking Escape by the monitor, not as a key of its own');
$destructive = fn(int $i): bool => ObjC::msg('b:', ObjC::msg('p:L', ObjC::msg('p:', $a, 'buttons'), 'objectAtIndex:', $i), 'hasDestructiveAction');
same([false, true], [$destructive(0), $destructive(1)], 'the destructive button set apart');
same(['', ''], $keys(\MyPHP\Platform\Darwin\Dialogs::alert('info', 'Choose', '', ['A', 'B'], -1)), 'no default: Return activates no button');
