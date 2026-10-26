<?php
// Catalogs of messages (mygo's catalog_test.go and languages_test.go): the
// languages of a catalog's files, the message a locale gets along parents
// and matches, the pseudo locales, a library's catalog and the keys of the
// app's that change it, translations added, the registered languages.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Catalog, I18n, Message};

$tmp = sys_get_temp_dir() . '/myphp-catalog-' . getmypid();
$dirs = 0;
$files = function (array $m) use ($tmp, &$dirs): string {
    $d = "$tmp/" . $dirs++;
    @mkdir($d, 0777, true);
    foreach ($m as $name => $data) { file_put_contents("$d/$name", $data); }
    return $d;
};
$appFiles = [
    'en.json' => '{"@@locale": "en", "hello": "Hello, {name}!", "@hello": {"description": "A greeting.", "placeholders": {"name": {"type": "String"}}}, "color": "Color", "files": "{n, plural, one {# file} other {# files}}", "onlyEnglish": "Only in English", "broken": "Fine in English"}',
    'en-001.json' => '{"color": "Colour"}',
    'de.json' => '{"@@locale": "de", "hello": "Hallo, {name}!", "files": "{n, plural, one {# Datei} other {# Dateien}}", "broken": "{kaputt"}',
    'zh-Hans.json' => '{"hello": "你好，{name}！"}',
    'zh-Hant.json' => '{"hello": "你好，{name}！", "color": "顏色"}',
    'pt.json' => '{"color": "Cor"}',
    'pt-PT.json' => '{"hello": "Olá, {name}!"}',
];
$app = $files($appFiles);
$c = new Catalog($app, 'en');
same([['en', 'de', 'en-001', 'pt', 'pt-PT', 'zh-Hans', 'zh-Hant'], 'en', ''], [$c->languages(), $c->source(), $c->namespace()], 'the languages, the source first');
$errors = [];
foreach ([[['en.json' => '{}'], '', 'not a language'], [['en.json' => '{}'], 'en-XA', 'not a language'], [['de.json' => '{}'], 'en', 'no en.json'], [['en.json' => '{}', 'zh_CN.json' => '{}'], 'en', 'zh_CN.json is not named'],
    [['en.json' => '{}', 'EN-gb.json' => '{}'], 'en', 'EN-gb.json is not named'], [['en.json' => '{}', 'messages.json' => '{}'], 'en', 'messages.json is not named'], [['en.json' => '{}', 'en-XA.json' => '{}'], 'en', 'pseudo']] as [$fs, $src, $want]) {
    try { new Catalog($files($fs), $src); $errors[] = "no error for $want"; } catch (\InvalidArgumentException $e) { if (!str_contains($e->getMessage(), $want)) { $errors[] = $e->getMessage(); } }
}
same([], $errors, 'catalogs that are not');
try { Catalog::library($app, 'en', 'my.php'); check(false, 'a namespace with a dot'); } catch (\InvalidArgumentException) { check(true, 'a namespace has no dot'); }

$rlm = "\u{200F}"; $rlo = "\u{202E}"; $pdf = "\u{202C}";
$got = $want = [];
foreach ([['en', 'hello', ['name' => 'Ada'], 'Hello, Ada!'], ['', 'hello', ['name' => 'Ada'], 'Hello, Ada!'], ['de', 'hello', ['name' => 'Ada'], 'Hallo, Ada!'], ['de-CH', 'hello', ['name' => 'Ada'], 'Hallo, Ada!'],
    ['de', 'color', [], 'Color'], ['de', 'broken', [], 'Fine in English'], ['en-AU', 'color', [], 'Colour'], ['en-GB', 'hello', ['name' => 'Ada'], 'Hello, Ada!'], ['en-US', 'color', [], 'Color'],
    ['zh-TW', 'color', [], '顏色'], ['zh-Hant-HK', 'color', [], '顏色'], ['zh-CN', 'color', [], 'Color'], ['zh', 'hello', ['name' => 'Ada'], '你好，Ada！'], ['pt-AO', 'hello', ['name' => 'Ada'], 'Olá, Ada!'],
    ['pt-AO', 'color', [], 'Cor'], ['pt-BR', 'hello', ['name' => 'Ada'], 'Hello, Ada!'], ['fr', 'hello', ['name' => 'Ada'], 'Hello, Ada!'], ['C', 'color', [], 'Color'], ['de', 'missing', [], 'missing'],
    ['de', 'files', ['n' => 1], '1 Datei'], ['fr', 'files', ['n' => 0], '0 files'], ['en-XA', 'color', [], '[Çöļöŕ one]'], ['en-XA', 'missing', [], 'missing'], ['ar-XB', 'color', [], "$rlm{$rlo}Color$pdf$rlm"]] as [$l, $k, $a, $w]) {
    $got[] = "$l $k: " . $c->format($l, $k, $a); $want[] = "$l $k: $w";
}
same($want, $got, 'the message of a locale, along parents and matches; English\'s plural rules for an English message');
[$m, $lang] = $c->message('en-AU', 'color');
same(['en-001', 'Colour', null], [$lang, (string)$m, $c->message('en', 'nope')], 'the message and its language');
same($c->format('de', 'color'), $c->format('de', 'color'), 'a text kept');

// A library's catalog, and the app's keys that change it.
Catalog::resetRegistry();
$lib = Catalog::library($files(['en.json' => '{"cancel": "Cancel", "quitApp": "Quit {app}", "close": "Close"}', 'zh-Hans.json' => '{"cancel": "取消", "quitApp": "退出{app}"}', 'zh-Hant.json' => '{"cancel": "取消", "quitApp": "結束{app}"}']), 'en', 'myphp');
same(['取消', 'Cancel'], [$lib->format('zh-TW', 'cancel'), $lib->format('de', 'cancel')], 'without an app\'s catalog: the library\'s own');
same([], I18n::languages(), 'no languages before a catalog is registered');
$appCat = new Catalog($files(['de.json' => '{"title": "Titel", "myphp.cancel": "Abbrechen"}', 'en.json' => '{"title": "Title", "myphp.close": "Dismiss"}', 'zh-Hans.json' => '{"title": "标题", "myphp.quitApp": "退出 {app}"}', 'zh-TW.json' => '{"title": "標題"}']), 'de');
I18n::register($appCat); I18n::register($appCat);
same(['de', 'en', 'zh-Hans', 'zh-TW'], I18n::languages(), 'the registered languages, once');
$got = $want = [];
foreach ([['de', 'cancel', 'Abbrechen'], ['de-AT', 'cancel', 'Abbrechen'], ['en', 'close', 'Dismiss'], ['en', 'cancel', 'Cancel'], ['zh-Hans', 'quitApp', '退出 Notes'], ['zh-Hans', 'cancel', '取消'], ['zh-TW', 'quitApp', '結束Notes'],
    ['ja', 'cancel', 'Abbrechen'], ['ja', 'close', 'Dismiss'], ['ja', 'quitApp', 'Quit Notes'], ['', 'cancel', 'Abbrechen'], ['en-XA', 'cancel', '[Åƀƀŕéçĥéñ one]'], ['de', 'missing', 'missing']] as [$l, $k, $w]) {
    $got[] = "$l $k: " . $lib->format($l, $k, ['app' => 'Notes']); $want[] = "$l $k: $w";
}
same($want, $got, 'the library\'s messages, as the app changes and translates them');
same(['title', '標題'], [$lib->format('de', 'title'), $appCat->format('zh-TW', 'title')], 'the app\'s own keys are not the library\'s');
try { I18n::register($lib); check(false, 'a library registered'); } catch (\InvalidArgumentException) { check(true, 'a library\'s catalog is not registered'); }
Catalog::resetRegistry();

$ex = new Catalog($files(['en.json' => '{"files": "{n, plural, =0 {No files} one {# file} other {# files}}"}', 'de.json' => '{"files": "{n, plural, =0 {Keine Dateien} one {# Datei} other {# Dateien}}"}']), 'en');
same(['1 file', 'Keine Dateien', '[3 ƒîļéš one]'], [$ex->format('en', 'files', ['n' => 1]), $ex->format('de-AT', 'files', ['n' => 0]), $ex->format('en-XA', 'files', ['n' => 3])], 'mygo\'s example');

// Translations added from elsewhere.
$c = Catalog::library($files(['en.json' => '{"undo": "Undo", "redo": "Redo"}']), 'en', 'lib');
same('Undo', $c->format('de', 'undo'), 'before German is added');
$c->add($files(['de.json' => '{"undo": "Rückgängig"}']));
$c->add($files(['zh-Hant.json' => '{"undo": "復原"}']));
same([['en', 'de', 'zh-Hant'], 'Rückgängig', 'Rückgängig', 'Redo', '復原'], [$c->languages(), $c->format('de', 'undo'), $c->format('de-AT', 'undo'), $c->format('de', 'redo'), $c->format('zh-TW', 'undo')], 'the languages added, the texts kept before forgotten');
foreach ([['de.json' => '{}'], ['german.json' => '{}']] as $fs) {
    try { $c->add($files($fs)); check(false, 'added ' . key($fs)); } catch (\InvalidArgumentException) { check(true, 'not added: ' . key($fs)); }
}

// A catalog in a phar, as a micro app's are (v1.28): glob finds nothing there.
$pharFile = $files([]) . "/catalogs.phar";
$made = shell_exec(escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 -r ' . escapeshellarg('$p = new Phar($argv[1]); $p["l10n/en.json"] = "{\"hello\": \"Hello\"}"; $p["l10n/de.json"] = "{\"hello\": \"Hallo\"}"; echo "ok";') . ' ' . escapeshellarg($pharFile));
same('ok', $made, 'a phar of catalogs');
$inPhar = new Catalog("phar://$pharFile/l10n", 'en');
same([['en', 'de'], 'Hallo'], [$inPhar->languages(), $inPhar->format('de', 'hello')], 'its languages and messages');

foreach (glob("$tmp/*/*") as $f) { unlink($f); }
foreach (glob("$tmp/*") as $d) { rmdir($d); }
rmdir($tmp);
