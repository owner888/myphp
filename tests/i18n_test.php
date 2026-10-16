<?php
// Languages and regional formats: tags normalized and matched, the locale
// model without a running app, MyPHP's own strings in the languages they
// are in, and the date, calendar and time controls writing a locale's
// formats (ICU's, through ext-intl).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, I18n, L10n, Locales, Region};
use MyPHP\UI\{Context, Node};

same(['zh-CN', 'en-GB', '', 'zh-Hans', 'en-US'], [I18n::normalize('zh_CN.UTF-8'), I18n::normalize('EN-gb'), I18n::normalize('xx!'), I18n::normalize('zh-Hans'), I18n::normalize('en_US@rg=gbzzzz')], 'tags normalized, POSIX locales read');
same(['zh-Hans', 'zh-Hant', '', 'en', 'zh-Hans'], [I18n::match(['zh-Hans-CN', 'en-GB'], ['en', 'zh-Hans']), I18n::match(['zh-TW'], ['en', 'zh-Hans', 'zh-Hant']), I18n::match(['fr'], ['en', 'de']), I18n::match(['en-GB'], ['en', 'de']), I18n::match(['zh-CN'], ['en', 'zh-Hans'])], 'the best match: a region finds its script, a stranger none');
same([true, true, false], [I18n::rightToLeft('ar'), I18n::rightToLeft('he-IL'), I18n::rightToLeft('en')], 'right-to-left languages');
same([0, 1, 12, 24, 12], [I18n::firstWeekday('en-US'), I18n::firstWeekday('de-DE'), I18n::hourCycle('en-US'), I18n::hourCycle('de-DE'), I18n::hourCycle('en-GB-u-hc-h12')], 'CLDR\'s first weekday and hour cycle, or the extension\'s');
same(["h:mm\u{202f}a", 'HH:mm', 'ah:mm'], [I18n::pattern('en-US', 'jmm'), I18n::pattern('en-US', 'jmm', 24), I18n::pattern('zh-Hans-CN', 'jmm', 12)], 'the pattern of a skeleton, in an hour cycle (CLDR 42+ sets the day period off with a narrow no-break space)');
$d = new \DateTimeImmutable('2026-01-05 15:07', new \DateTimeZone('Asia/Singapore'));
same(['1/5/2026', '05/01/2026', 'Januar 2026', '2026年1月5日'], [I18n::formatDate('en-US', $d, 'yMd'), I18n::formatDate('en-GB', $d, 'yMd'), I18n::formatDate('de-DE', $d, 'yMMMM'), I18n::formatDate('zh-Hans-CN', $d, 'yMMMMd')], 'dates in a locale');
same(['07', '٧', ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], ['上午', '下午']], [I18n::formatNumber('en', 7, 2), I18n::formatNumber('ar-EG', 7), I18n::weekdays('en', I18n::SHORT, true), I18n::dayPeriods('zh-Hans')], 'numbers in the locale\'s digits, weekdays, day periods');
same('en-GB-u-fw-mon-hc-h23', (new Region('en-GB', 1, 24))->formatLocale('en'), 'the format locale: the language with the region of the formats and their keys');
same('zh-Hans-GB-u-fw-mon-hc-h23', (new Region('en-GB', 1, 24))->formatLocale('zh-Hans'), 'another language with the same formats');
same('de', (new Region)->formatLocale('de'), 'the zero region: the language alone');

// MyPHP's own strings: the languages they are in, formatted messages, what an app adds.
same(['en', 'zh-Hans', 'zh-Hant'], L10n::languages(), 'the languages of the catalogs');
same(['zh-Hans', '尋找', '3 of 12', '移除php', 'Search'], [L10n::in('zh-CN')->lang(), L10n::in('zh-TW')->get('find'), L10n::in('en')->get('matches', ['current' => 3, 'total' => 12]), L10n::in('zh-Hans')->get('removeToken', ['token' => 'php']), L10n::in('fr')->get('search')], 'a reader of zh-CN gets zh-Hans, of zh-TW zh-Hant, of French English');
L10n::add('fr', ['search' => 'Rechercher']); same(['Rechercher', 'Find'], [L10n::in('fr')->get('search'), L10n::in('fr')->get('find')], 'an app adds a string; the rest stay English');

// The locale model without an app: English alone, then the languages the app is written in, the one it chose, the listeners.
same(['en', ['en'], [], '', 0], [App::locale(), App::languages(), App::preferredLanguages(), App::region()->locale, App::region()->hourCycle], 'without an app: English, no preferred languages, the zero region');
$heard = []; $off = App::onLocaleChanged(function (string $l) use (&$heard) { $heard[] = $l; });
App::setLanguages('en', 'zh-Hans', 'zh-Hant');
App::setLocale('zh-TW'); same(['zh-Hant', ['zh-Hant']], [App::locale(), $heard], 'setLocale picks the language written in that matches, and tells');
App::setLocale('fr'); same('en', App::locale(), 'a language the app is not written in: its own');
try { App::setLocale('!!'); check(false, 'a tag that names no language'); } catch (\RuntimeException $e) { check(true, 'a tag that names no language is refused'); }
$off(); App::setLocale('zh-Hans'); same(['zh-Hant', 'en'], $heard, 'the listener stopped hears no more');

// The controls in zh-Hans: the date as 2026/10/16, the calendar's title and weekdays, the time in 24 hours.
$date = new \DateTimeImmutable('2026-10-16 09:30'); $n = [];
$h = new Headless(function (Context $c) use (&$n, &$date) { $c->column(0, 8); $n['d'] = $c->dateInput($date); $n['t'] = $c->timeInput($date); $n['c'] = $c->calendar($date); $c->end(); }, 400, 500);
$h->frame();
same('2026/10/16', $n['d']->kids[0]->text, 'the date input in zh-Hans');
same(['09', ':', '30'], [$n['t']->kids[0]->kids[0]->text, $n['t']->kids[1]->kids[0]->text, $n['t']->kids[2]->kids[0]->text], '24 hours: two digits, no half of the day');
same(2, count(array_filter($n['t']->kids, fn($k) => $k->focusable)), 'two segments');
same(['2026年10月', '周一', '周日'], [$n['c']->kids[0]->kids[1]->kids[0]->text, $n['c']->kids[1]->kids[0]->kids[0]->text, $n['c']->kids[1]->kids[6]->kids[0]->text], 'the calendar\'s title and weekdays, Monday first');
same('搜索', (function () { $n = null; $h = new Headless(function (Context $c) use (&$n) { $n = $c->searchField(new \MyPHP\UI\TextState); }, 300, 100); $h->frame(); return $n->text; })(), 'a search field\'s placeholder');
App::setLocale(''); App::setLanguages(); same('en', App::locale(), 'back to English');
