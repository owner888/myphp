<?php
// Haptic feedback (MyPHP\Haptics, Platform\Shell::haptic; not mygo's):
// every kind the facade plays has a code on each platform that has a
// Taptic Engine or a vibrator, and an impact style that is not UIKit's
// throws.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Haptics;
use MyPHP\Platform\{Android, Darwin, iOS};

foreach (['iOS' => iOS\Shell::CODES, 'Android' => Android\Shell::CONSTANTS, 'macOS' => Darwin\Shell::PATTERNS] as $platform => $table) {
    same(Haptics::KINDS, array_keys($table), "$platform plays every kind, and no other");
}
same(0, iOS\Shell::CODES['light'], 'the light impact keeps the launcher\'s code 0, which the taps and beep() use');
same(16, Android\Shell::CONSTANTS['success'], 'success is Android\'s CONFIRM');
same(2, Darwin\Shell::PATTERNS['selection'], 'a selection is the Mac\'s LevelChange tick');

$threw = false;
try { Haptics::impact('strong'); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'an impact style that is not UIKit\'s throws');
