<?php
// A phone's settings reaching the theme (not mygo's, which has no phones):
// Android's configuration event carries night mode, the font scale and
// animations removed, which Platform\Android\Theme answers as isDark()
// and the preferences the theme follows; a theme following a larger font
// scale sizes its text and the text styles by it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Platform\Android;
use MyPHP\UI\{Font, Preferences, Theme};

$t = new Android\Theme();
same([false, 1.0, false], [$t->isDark(), $t->preferences()->textScale, $t->preferences()->reduceMotion], 'before the first configuration: light, the default size, animations');
$t->setConfiguration(true, 1.3, true);
same([true, 1.3, true], [$t->isDark(), $t->preferences()->textScale, $t->preferences()->reduceMotion], 'the configuration: night mode, a 1.3 font scale, animations removed');
$t->setConfiguration(false, 0.0, false);
same(1.0, $t->preferences()->textScale, 'a font scale the configuration lacks (0) is the default');
$t->setSource(\MyPHP\ThemeSource::Dark);
check($t->isDark(), 'an app that fixes the dark appearance is dark whatever the configuration');

// The theme following the phone's settings: its text, and the text styles, scale with the font size.
$p = $t->preferences();
$theme = Theme::light()->follow(new Preferences($p->accent, $p->reduceMotion, $p->highContrast, 1.3));
closeFloat(16.9, $theme->fontSize, 'the theme\'s text: 13 × 1.3');
closeFloat(1.3, $theme->textScale(), 'the scale the text styles use');
closeFloat(22.1, Font::preferred(Font::BODY, true, $theme->textScale())[0], 'body on the phone at that size: 17 × 1.3');
