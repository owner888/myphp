<?php
// Languages and regional formats: tags normalized and matched, the locale
// model without a running app, MyPHP's own strings in the languages they
// are in, and the date, calendar and time controls writing a locale's
// formats (ICU's, through ext-intl).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, Catalog, DateFormatter, DateOptions, Dir, I18n, L10n, Locales, NumberOptions, Region, Style, Width};
use MyPHP\UI\{Context, Node};

same(['zh-CN', 'en-GB', '', 'zh-Hans', 'en-US'], [I18n::normalize('zh_CN.UTF-8'), I18n::normalize('EN-gb'), I18n::normalize('xx!'), I18n::normalize('zh-Hans'), I18n::normalize('en_US@rg=gbzzzz')], 'tags normalized, POSIX locales read');
same(['zh-Hans', 'zh-Hant', '', 'en', 'zh-Hans'], [I18n::match(['zh-Hans-CN', 'en-GB'], ['en', 'zh-Hans']), I18n::match(['zh-TW'], ['en', 'zh-Hans', 'zh-Hant']), I18n::match(['fr'], ['en', 'de']), I18n::match(['en-GB'], ['en', 'de']), I18n::match(['zh-CN'], ['en', 'zh-Hans'])], 'the best match: a region finds its script, a stranger none');
same([Dir::RightToLeft, Dir::RightToLeft, Dir::RightToLeft, Dir::LeftToRight, Dir::LeftToRight], [I18n::direction('ar'), I18n::direction('he-IL'), I18n::direction('ar-XB'), I18n::direction('en'), I18n::direction('!!')], 'the direction of a language, its script\'s');
same(['zh', 'he', '', 'en-001', 'en', 'zh-Hant-HK', 'no', 'de', '', '', 'es-419', ''], [I18n::base('zh-Hant-TW'), I18n::base('iw'), I18n::base('!!'), I18n::parent('en-AU'), I18n::parent('en-001'), I18n::parent('zh-Hant-MO'), I18n::parent('nb'), I18n::parent('de-CH-u-ca-buddhist'), I18n::parent('zh-Hant'), I18n::parent('sr-Latn'), I18n::parent('es-MX'), I18n::parent('de')], 'the language of a tag; CLDR\'s parents, nonlikely scripts the root\'s');
same(['sr-Cyrl', 'sr', 'en-US-u-hc-h23'], [I18n::parent('sr-Cyrl-RS'), I18n::parent('sr-Cyrl'), I18n::normalize('en_us-U-HC-H23')], 'a likely script keeps its parent (Serbian is Cyrillic); extensions kept');
same([true, true, false], [I18n::isPseudo('en-XA'), I18n::isPseudo('ar-XB-u-nu-latn'), I18n::isPseudo('en-US')], 'the pseudo locales');
same(['English', 'British English', 'Deutsch', 'español latinoamericano', '繁體中文', '日本語', 'français', 'português (Brasil)', 'en-XA'], array_map(I18n::autonym(...), ['en', 'en-GB', 'de', 'es-419', 'zh-Hant', 'ja', 'fr', 'pt-BR', 'en-XA']), 'languages named in themselves');
check(in_array('ar-AE', I18n::formatLocales(), true) && !in_array('root', I18n::formatLocales(), true), 'the locales of formats');
same([0, 1, 12, 24, 12], [I18n::firstWeekday('en-US'), I18n::firstWeekday('de-DE'), I18n::hourCycle('en-US'), I18n::hourCycle('de-DE'), I18n::hourCycle('en-GB-u-hc-h12')], 'CLDR\'s first weekday and hour cycle, or the extension\'s');
same(["h:mm\u{202f}a", 'HH:mm', 'ah:mm', 'ah:mm', 'MMM d, y', 'EEEE, MMMM d, y \'at\' HH:mm'], [(new DateFormatter('en-US', new DateOptions(skeleton: 'jmm')))->pattern(), (new DateFormatter('en-US', new DateOptions(skeleton: 'jmm', hourCycle: 24)))->pattern(), (new DateFormatter('zh-Hans-CN', new DateOptions(skeleton: 'jmm', hourCycle: 12)))->pattern(), (new DateFormatter('ja', new DateOptions(skeleton: 'hm')))->pattern(), (new DateFormatter('en-US', new DateOptions(Style::Medium)))->pattern(), (new DateFormatter('en-US', new DateOptions(Style::Full, Style::Short, hourCycle: 24)))->pattern()], 'the pattern of a skeleton or of styles, in an hour cycle; an hour letter of the skeleton is one');
$d = new \DateTimeImmutable('2026-01-05 15:07', new \DateTimeZone('Asia/Singapore'));
same(['1/5/2026', '05/01/2026', 'Januar 2026', '2026年1月5日'], [I18n::formatDate('en-US', $d), I18n::formatDate('en-GB', $d), I18n::formatDate('de-DE', $d, new DateOptions(skeleton: 'yMMMM')), I18n::formatDate('zh-Hans-CN', $d, new DateOptions(skeleton: 'yMMMMd'))], 'dates in a locale');
same(['3:07 PM', 'Jan 5, 2026, 3:07 PM'], [I18n::formatDate('en-US', $d, new DateOptions(time: Style::Short)), I18n::formatDate('en-US', $d, new DateOptions(Style::Medium, Style::Short))], 'by style, the narrow no-break space a space');
same(['January', 'Jan', 'J', 'января', 'январь'], [I18n::months('en')[0], I18n::months('en', Width::Short)[0], I18n::months('en', Width::Narrow)[0], I18n::months('ru')[0], I18n::months('ru', Width::Wide, true)[0]], 'months, in a date or standing alone');
same(['07', '٧', ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], ['上午', '下午']], [I18n::formatNumber('en', 7, new NumberOptions(minIntegerDigits: 2)), I18n::formatNumber('ar-EG', 7), I18n::weekdays('en', Width::Short, true), I18n::dayPeriods('zh-Hans')], 'numbers in the locale\'s digits, weekdays, day periods');
// Numbers as mygo writes them (number_test.go): grouping, the minimum of grouping, the Indian groups, exact decimals, rounding, percents.
same(['1,234.5', '1234', '12.345', '12,34,567', '1.50', '1.01', '2', '3', '25%', "25\u{a0}%", '%25', '-1,234', '123456789012345678901.123', '07', '12345', 'NaN', '-∞', '۱۲٫۵'],
    [I18n::formatNumber('en', 1234.5), I18n::formatNumber('es', 1234), I18n::formatNumber('es', 12345), I18n::formatNumber('hi', 1234567), I18n::formatNumber('en', '1.50', new NumberOptions(minFractionDigits: 2)), I18n::formatNumber('en', 1.005, new NumberOptions(maxFractionDigits: 2)), I18n::formatNumber('en', 2.5, new NumberOptions(integer: true, halfEven: true)), I18n::formatNumber('en', 2.5, new NumberOptions(integer: true)), I18n::formatNumber('en', 0.25, new NumberOptions(percent: true)), I18n::formatNumber('de', 0.25, new NumberOptions(percent: true)), I18n::formatNumber('tr', 0.25, new NumberOptions(percent: true)), I18n::formatNumber('en', -1234), I18n::formatNumber('en', '123456789012345678901.123', new NumberOptions(noGrouping: true)), I18n::formatNumber('en', 7, new NumberOptions(minIntegerDigits: 2)), I18n::formatNumber('en', 12345, new NumberOptions(noGrouping: true)), I18n::formatNumber('en', NAN), I18n::formatNumber('en', -INF), I18n::formatNumber('fa', '12.5')],
    'numbers in a locale');
same('en-GB-u-fw-mon-hc-h23', (new Region('en-GB', 1, 24))->formatLocale('en'), 'the format locale: the language with the region of the formats and their keys');
same('zh-Hans-GB-u-fw-mon-hc-h23', (new Region('en-GB', 1, 24))->formatLocale('zh-Hans'), 'another language with the same formats');
same(['de', 'zh-Hant-DE', 'en-u-fw-mon-hc-h12'], [(new Region)->formatLocale('de'), (new Region('de-DE'))->formatLocale('zh-HK'), (new Region('', 1, 12))->formatLocale('en-XA')], 'the zero region: the language alone; the script a region implied kept; the pseudo locales\' formats English');

// mygo's i18n_test.go and parent_test.go: tags normalized, matched, their directions, languages and parents.
$bad = [];
foreach (["en-us"=>"en-US","zh_CN.UTF-8"=>"zh-CN","zh_TW"=>"zh-TW","sr_RS@latin"=>"sr-Latn-RS","sr_RS@euro"=>"sr-RS","de_DE.utf8@euro"=>"de-DE","iw"=>"he","in"=>"id","nb"=>"nb","zh-Hant-HK"=>"zh-Hant-HK","es-419"=>"es-419","C"=>"","POSIX"=>"","C.UTF-8"=>"",""=>"","und"=>"","x"=>""," en "=>"en"] as $in=>$w) if (($g=I18n::normalize((string)$in))!==$w) $bad[]="normalize $in = $g want $w";
foreach ([[["zh-TW"],["en","zh-Hans","zh-Hant"],"zh-Hant"],[["zh-HK"],["en","zh-TW"],"zh-TW"],[["zh-TW"],["en","zh-Hans"],""],[["zh"],["en","zh-Hant","zh-Hans"],"zh-Hans"],[["zh_CN.UTF-8"],["en","zh-Hans"],"zh-Hans"],[["es-MX"],["en","es-ES"],"es-ES"],[["es-MX"],["en","es-ES","es-419"],"es-419"],[["pt-PT"],["en","pt-BR"],"pt-BR"],[["en-AU"],["zh-Hans","en-US","en-GB"],"en-GB"],[["fr-CA","de"],["en","de","fr"],"fr"],[["fr-CA","de"],["en","de"],"de"],[["ja"],["en","zh-Hans"],""],[["ja","zh-Hans"],["en","zh-Hans"],"zh-Hans"],[["nb-NO"],["en","no"],"no"],[["no"],["en","nb"],"nb"],[["iw"],["en","he"],"he"],[["sr-Cyrl"],["en","sr-Latn"],""],[["sr_RS@latin"],["en","sr-Latn"],"sr-Latn"],[["en-XA"],["en","en-XA"],"en-XA"],[["C"],["en"],""],[[],["en"],""],[["en"],[],""],[["en-gb"],["zh-hans","en-gb"],"en-gb"]] as [$p,$s,$w]) if (($g=I18n::match($p,$s))!==$w) $bad[]="match ".json_encode($p).json_encode($s)." = $g want $w";
$rtl=[]; foreach (["en","ar","ar-EG","he","iw","fa","ur","ps","yi","dv","ug","ckb","zh","ja","az-Arab","az","ku","ar-XB","en-XA","","C","pa-Arab","nqo"] as $t) if (I18n::direction($t)===Dir::RightToLeft) $rtl[]=$t;
if ($rtl!==["ar","ar-EG","he","iw","fa","ur","ps","yi","dv","ug","ckb","az-Arab","ar-XB","pa-Arab","nqo"]) $bad[]="rtl ".json_encode($rtl);
foreach (["zh-Hant-TW"=>"zh","iw"=>"he","en"=>"en",""=>"","sr_RS@latin"=>"sr"] as $in=>$w) if (($g=I18n::base((string)$in))!==$w) $bad[]="base $in = $g";
foreach (["en-AU"=>"en-001","en-001"=>"en","en-GB"=>"en-001","en-CH"=>"en-150","en-150"=>"en-001","en"=>"","en-US"=>"en","de-CH"=>"de","zh-Hant-MO"=>"zh-Hant-HK","zh-Hant-HK"=>"zh-Hant","zh-Hant"=>"","zh-Hans"=>"zh","zh-Hans-CN"=>"zh-Hans","sr-Latn"=>"","sr-Latn-RS"=>"sr-Latn","sr-Cyrl"=>"sr","ja-Latn"=>"","nb"=>"no","pt-AO"=>"pt-PT","pt-PT"=>"pt","es-MX"=>"es-419","es-419"=>"es","ca-ES-valencia"=>"ca-ES","de-DE-u-co-phonebk"=>"de","en-XA"=>"en","ar-XB"=>"ar",""=>"","C"=>""] as $in=>$w) if (($g=I18n::parent((string)$in))!==$w) $bad[]="parent $in = $g want $w";
foreach (["en-AU"=>["en-AU","en-001","en"],"zh-Hant-MO"=>["zh-Hant-MO","zh-Hant-HK","zh-Hant"],"de"=>["de"],"x"=>[]] as $in=>$w) if (($g=I18n::chain($in))!==$w) $bad[]="chain $in = ".json_encode($g);
same([], $bad, 'Normalize, Match, Direction, Base, Parent and chain as mygo tests them');

// MyPHP's own strings: the languages they are in, formatted messages, what an app adds.
same(['en', 'ja', 'zh-Hans', 'zh-Hant'], L10n::languages(), 'the languages of the catalogs');
same(['尋找', '3 of 12', '移除php', 'Search', '[Šéåŕçĥ one]'], [L10n::in('zh-TW')->get('find'), L10n::in('en')->get('matches', ['current' => 3, 'total' => 12]), L10n::in('zh-Hans')->get('removeToken', ['token' => 'php']), L10n::in('fr')->get('search'), L10n::in('en-XA')->get('search')], 'a reader of zh-TW gets zh-Hant, of French English, of en-XA English transformed');
try { L10n::in('en')->get('noSuchString'); check(false, 'a string MyPHP has not'); } catch (\RuntimeException) { check(true, 'a key MyPHP has no string of throws'); }
$tmp = sys_get_temp_dir() . '/myphp-l10n-' . getmypid(); @mkdir($tmp);
file_put_contents("$tmp/en.json", '{"title": "Notes"}'); file_put_contents("$tmp/fr.json", '{"title": "Notes", "myphp.search": "Rechercher"}');
I18n::register($app = new Catalog($tmp, 'en'));
same(['Rechercher', 'Find', ['en', 'fr']], [L10n::in('fr')->get('search'), L10n::in('fr')->get('find'), I18n::languages()], 'an app\'s catalog translates a string; the rest stay English');
Catalog::resetRegistry(); unlink("$tmp/en.json"); unlink("$tmp/fr.json"); rmdir($tmp);

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
