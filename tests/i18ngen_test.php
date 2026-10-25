<?php
// The catalogs' checks and accessors (mygo's internal/i18ngen tests): the
// golden catalog of an app (tests/i18n/app) loads with its warnings, its
// keys' parameters, the PHP class it generates says its messages, the
// TypeScript module is tests/i18n/app/locales.ts (MYPHP_UPDATE=1 rewrites
// it), and the bad catalogs (tests/i18n/bad) say what is wrong with them.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Catalog, I18n};
use MyPHP\Native\I18nGen;

$tmp = sys_get_temp_dir() . '/myphp-i18ngen-' . getmypid();
@mkdir("$tmp/lib", 0777, true);
file_put_contents("$tmp/lib/en.json", '{"cancel": "Cancel", "quitApp": "Quit {app}"}');
[$lib, $diags] = I18nGen::load("$tmp/lib", 'l10n', 'en', 'myphp');
same([], $diags, 'the library loads');
$libs = ['myphp' => $lib];

[$c, $diags] = I18nGen::load(__DIR__ . '/i18n/app', 'locales', 'en', '', $libs);
same([
    'locales/de.json: warning: 6 of 12 messages missing, which say the language they fall back to: progress, positional, keyword, untyped, object, save_as',
    'locales/en-GB.json: warning: 11 of 12 messages missing, which say the language they fall back to: title, filesSelected, greeting, replied, place, guests, positional, keyword and 3 more',
    'locales/zh-Hant.json: warning: 9 of 12 messages missing, which say the language they fall back to: replied, progress, place, guests, positional, keyword, untyped, object and 1 more',
], array_map(I18nGen::line(...), $diags), 'the golden catalog: warnings only');
same([[], ['en', 'de', 'en-GB', 'zh-Hant'], ['en-GB', 'en']], [I18nGen::errors($diags), $c['languages'], I18nGen::fallbacks($c, 'en-GB')], 'no errors, the languages, the fallbacks');
same([
    'title()', 'filesSelected(count:int:count:count)', 'greeting(name:String:name:name)', 'replied(gender:String:gender:gender, when:DateTime:when:when)', 'progress(done:double:done:done)', 'place(n:int:n:n)',
    'guests(host:String:host:host, n:num:n:n)', 'positional(0:String:arg0:arg0, 1:String:arg1:arg1)', 'keyword(type:String:type:type, default:String:default:default_, string:String:string:string, any:String:any:any)',
    'untyped(value:String:value:value)', 'object(item:Object:item:item)', 'save_as()',
], array_map(fn($k) => $k['name'] . '(' . implode(', ', array_map(fn($p) => "{$p['name']}:{$p['type']}:{$p['php']}:{$p['ts']}", $k['params'])) . ')', $c['keys']), 'the keys and their parameters');

// The PHP class, in a copy of the catalogs, required as an app requires it.
@mkdir("$tmp/locales");
foreach (glob(__DIR__ . '/i18n/app/*.json') as $f) { copy($f, "$tmp/locales/" . basename($f)); }
same('Locales', I18nGen::className("$tmp/locales"), 'the class is named after the directory');
same(['MyStrings', 'L10n', 'Locales'], [I18nGen::className('My-Strings'), I18nGen::className('2l10n'), I18nGen::className('---')], 'names of directories');
file_put_contents("$tmp/locales/Locales.php", I18nGen::php($c, 'Locales'));
exec(PHP_BINARY . ' -l ' . escapeshellarg("$tmp/locales/Locales.php"), $out, $code);
same(0, $code, 'the class parses');
Catalog::resetRegistry();
require "$tmp/locales/Locales.php";
$when = new \DateTimeImmutable('2026-01-02 15:04', new \DateTimeZone('UTC'));
same(['Notes', 'Notizen', '3 files selected', 'Keine Dateien ausgewählt', '已選取 1 個檔案', 'Ada and 2 others', 'She replied 1/2/26.', '50% complete', 'a of b', 'x y z w', '[Ñöţéš one]', ['en', 'de', 'en-GB', 'zh-Hant']],
    [\Locales::title(), \Locales::in('de')->title(), \Locales::filesSelected(3), \Locales::in('de')->filesSelected(0), \Locales::in('zh-TW')->filesSelected(1), \Locales::in('en')->guests('Ada', 3), \Locales::in('en')->replied('female', $when), \Locales::in('en-GB')->progress(0.5), \Locales::in('en')->positional('a', 'b'), \Locales::in('en')->keyword('x', 'y', 'z', 'w'), \Locales::in('en-XA')->title(), I18n::languages()],
    'the class says the messages, its catalog registered');
$lcat = Catalog::library("$tmp/lib", 'en', 'myphp');
same(['Dismiss', 'Abbrechen'], [$lcat->format('en', 'cancel'), $lcat->format('de', 'cancel')], 'its keys change the library\'s messages');
Catalog::resetRegistry();

// The TypeScript module, golden.
$ts = I18nGen::ts($c);
$golden = __DIR__ . '/i18n/app/locales.ts';
if (getenv('MYPHP_UPDATE') === '1') { file_put_contents($golden, $ts); }
same(true, is_file($golden) && file_get_contents($golden) === $ts, 'the TypeScript module is tests/i18n/app/locales.ts (MYPHP_UPDATE=1 rewrites it)');

// The bad catalogs.
[, $diags] = I18nGen::load(__DIR__ . '/i18n/bad', 'locales', 'en', '', $libs);
same([
    'locales/de.json:1: "ok" comes twice',
    'locales/de.json: warning: 6 of 7 messages missing, which say the language they fall back to: files, broken, dup_name, dupName, typed, few',
    'locales/en-XA.json: en-XA is a pseudo locale, made from the source language: remove the file',
    'locales/en.json:5: "broken" does not parse: the plural argument "n" has no other case',
    'locales/en.json:6: "bad-key" is not a key: a letter, then letters, digits and _, as filesSelected',
    'locales/en.json:7: "in" would name its PHP method in, which the generated class declares',
    'locales/en.json:10: "typed" uses n as a plural argument, which its type String cannot be',
    'locales/en.json:11: @typed.placeholders.weird: type "float" is not String, int, double, num, DateTime or Object',
    'locales/en.json:11: warning: @typed declares the placeholder unused, which the message does not use',
    'locales/en.json:11: warning: @typed declares the placeholder weird, which the message does not use',
    'locales/en.json:18: warning: @ghost describes no message',
    'locales/en.json:19: "notString" is not a string',
    'locales/en.json:20: warning: "few": en has no plural category of few (its plural categories are one, other)',
    'locales/en.json:21: "unknown.key": no library has the namespace unknown',
    'locales/en.json:22: "myphp.nope": myphp has no message nope',
    'locales/en.json:23: "myphp.quitApp" uses the argument application, but the source message has the arguments app',
    'locales/en.json:23: warning: "myphp.quitApp" does not use the argument app',
    'locales/fr.json: not a JSON object of messages',
    'locales/ru.json:2: @@locale "uk" is not ru, the language the file is named after',
    'locales/ru.json:3: "ok" uses the argument extra, but the source message has no arguments',
    'locales/ru.json:4: warning: "files": the plural argument n lacks few, many, a plural category of ru, which says other in its place',
    'locales/ru.json:5: "stray" is not a key of locales/en.json',
    'locales/ru.json: warning: 5 of 7 messages missing, which say the language they fall back to: broken, dup_name, dupName, typed, few',
    'locales/zh_CN.json: the name of a catalog is a BCP 47 tag in its canonical form, as en.json or zh-Hant.json: rename it zh-CN.json',
], array_map(I18nGen::line(...), $diags), 'what is wrong with the bad catalogs');
$diag = fn(string $src, string $source = 'en') => (function () use ($src, $source, $tmp) {
    @mkdir($d = "$tmp/s" . md5($src . $source));
    foreach (json_decode($src, true) as $n => $v) { file_put_contents("$d/$n", $v); }
    return array_map(I18nGen::line(...), I18nGen::load($d, 'locales', $source)[1]);
})();
same([['locales/en.json: missing: it holds the messages of the source language, en'], ['locales: the source language "EN" is not a BCP 47 tag in its canonical form (as en, de-CH or zh-Hans)'], ['locales: the source language "en-XA" is not a BCP 47 tag in its canonical form (as en, de-CH or zh-Hans)'], ['l10n/en.json:1: "other.b" is not a key: a letter, then letters, digits and _, as filesSelected']],
    [$diag('{"de.json": "{}"}'), $diag('{"en.json": "{}"}', 'EN'), $diag('{"en.json": "{}"}', 'en-XA'), (function () use ($tmp) { @mkdir("$tmp/ns"); file_put_contents("$tmp/ns/en.json", '{"a": "A", "other.b": "B"}'); return array_map(I18nGen::line(...), I18nGen::load("$tmp/ns", 'l10n', 'en', 'myphp')[1]); })()],
    'sources that are not, a library\'s keys');
same([], I18nGen::errors(I18nGen::load(__DIR__ . '/../src/l10n', 'l10n', 'en', 'myphp')[1]), 'MyPHP\'s own catalog has no errors');

// The command line: myphp i18n check and generate, on a project of the golden catalog.
@mkdir("$tmp/project/locales", 0777, true);
foreach (glob(__DIR__ . '/i18n/app/*.json') as $f) { file_put_contents("$tmp/project/locales/" . basename($f), str_replace('"myphp.quitApp": "{app} beenden",', '"myphp.menuQuitApp": "{app} beenden",', (string)file_get_contents($f))); }
file_put_contents("$tmp/project/myphp.json", '{"name": "Notes", "entry": "app.php", "i18n": {"dir": "locales", "source": "en"}}');
$cli = fn(string ...$args) => (function () use ($args) { exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/../bin/myphp', ...$args])) . ' 2>&1', $out, $code); return [$code, implode("\n", $out)]; })();
[$code, $out] = $cli('i18n', 'check', "$tmp/project");
check($code === 0 && str_contains($out, 'myphp: 12 messages in en, de, en-GB, zh-Hant'), 'myphp i18n check: warnings, no errors');
[$code, $out] = $cli('generate', "$tmp/project");
check($code === 0 && str_contains($out, 'wrote locales/Locales.php') && is_file("$tmp/project/locales/Locales.php"), 'myphp generate writes the class');
[, $out] = $cli('generate', "$tmp/project");
check(str_contains($out, 'the accessors of locales are up to date'), 'and leaves it when nothing changed');
file_put_contents("$tmp/project/locales/fr.json", '{"title": "{broken"}');
[$code, $out] = $cli('i18n', 'check', "$tmp/project");
check($code === 1 && str_contains($out, 'locales/fr.json:1: "title" does not parse') && str_contains($out, '1 error in the catalogs'), 'an error fails the check');
exec('rm -rf ' . escapeshellarg($tmp));
