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

// The platform's text size the system theme starts from (Theme::defaultFontSize): 17 on a phone, the desktop's otherwise; textScale measures against it, so a phone's body is 17 at scale 1.
$was = getenv('MYPHP_PLATFORM');
putenv('MYPHP_PLATFORM=ios');
same(17.0, Theme::defaultFontSize(), 'iOS starts from 17 points');
putenv('MYPHP_PLATFORM=android');
same(17.0, Theme::defaultFontSize(), 'Android too, the one design');
putenv($was === false ? 'MYPHP_PLATFORM' : "MYPHP_PLATFORM=$was");
same(PHP_OS_FAMILY === 'Darwin' ? 13.0 : 14.0, Theme::defaultFontSize(), 'the desktop: 13 on macOS, 14 elsewhere, as mygo');
$phone = Theme::light(); $phone->fontSize = $phone->baseFontSize = 17.0;
same(1.0, $phone->textScale(), 'a phone theme at the platform\'s size scales text by 1');
closeFloat(17.0, Font::preferred(Font::BODY, true, $phone->textScale())[0], 'so body is 17 on it');
$larger = $phone->follow(new Preferences(textScale: 1.3));
closeFloat(22.1, $larger->fontSize, 'larger text: 17 × 1.3');
closeFloat(1.3, $larger->textScale(), 'measured against the phone\'s 17, not 13');
