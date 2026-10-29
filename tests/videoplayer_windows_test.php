<?php
// The player's welcome and playback history windows (Context::videoPlayerWelcome,
// Context::videoPlayerHistory), as IINA's: what they show of the films played
// (Media\PlaybackHistory, App::recentDocuments) and what they open.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\App;
use MyPHP\Media\{PlaybackHistory, PlaybackPositions};
use MyPHP\UI\{Context, Key};

if (PHP_OS_FAMILY !== 'Darwin') { return; } // their symbols are the system's (SF Symbols)

$dir = sys_get_temp_dir() . '/myphp-history-' . getmypid();
@mkdir($dir);
$a = "$dir/Alpha.mkv"; $b = "$dir/Beta.mkv"; $gone = "$dir/Gone.mkv";
file_put_contents($a, ''); file_put_contents($b, '');
$history = PlaybackHistory::default();
$history->clear();
$history->add($gone, 'Gone.mkv', '', 60.0);
$history->add('https://example.com/web.mkv', 'https://example.com/web.mkv', 'A Web Film', 100.0);
$history->add($b, 'Beta.mkv', 'The Beta Film', 200.0);
$history->add($a, 'Alpha.mkv', '', 100.0);
PlaybackPositions::default()->set($a, 25.0);

// The history: a group of the day, the latest first; a title over its file's name; where it was left.
$opened = [];
$h = new Headless(function (Context $c) use (&$opened) { $c->videoPlayerHistory(function (array $paths, bool $new) use (&$opened) { $opened[] = [$paths, $new]; }); }, 800, 600); // by reference all the way: an arrow function would hold a copy
$h->frame();
$texts = $h->texts();
check(in_array('Playback History', $texts, true) && in_array('Media', $texts, true) && in_array('Progress', $texts, true), 'the title, the columns: ' . json_encode($texts));
$names = array_values(array_filter($texts, fn(string $t) => in_array($t, ['Alpha.mkv', 'The Beta Film', 'A Web Film', 'Gone.mkv'], true)));
same(['Alpha.mkv', 'The Beta Film', 'A Web Film', 'Gone.mkv'], $names, 'the latest first');
check($h->byText('Beta.mkv') !== null, 'a title over its file\'s name');
check($h->byText('00:25') !== null, 'where a film was left');

// A double click plays it; Command-A and Return all of them there are, Command-Return in a new window.
$h->clickOn($h->byText('Alpha.mkv')); $h->clickOn($h->byText('Alpha.mkv'), 0, 2); $h->frame(); // the second press of two on it (Runtime::countPress)
same([[[$a], false]], $opened, 'a double click plays it');
$opened = [];
$h->shortcut('a', Key::COMMAND); $h->frame();
$h->enter(); $h->frame();
same([[[$a, $b, 'https://example.com/web.mkv'], false]], $opened, 'Command-A, Return: all, but the film whose file is gone');
$opened = [];
$h->enter(Key::COMMAND); $h->frame();
same([[[$a, $b, 'https://example.com/web.mkv'], true]], $opened, 'Command-Return: in a new window');

// By folder: the folder's group, the website's.
$h->clickOn($h->byLabel('Sort by Folder and Website')); $h->frame();
check($h->byText($dir) !== null && $h->byText('example.com') !== null, 'by folder: ' . json_encode($h->texts()));

// The right click's menu: IINA's.
$h->rightClick(...$h->middle($h->byText('Alpha.mkv'))); $h->frame();
same(['Play', 'Play in New Window', 'Show in Finder', 'Delete…', 'Move to Trash…'], array_values(array_filter($h->menuLabels() ?? [], fn($l) => $l !== '-')), 'the menu');
$h->closeMenu();

// The welcome window: the film played last to resume, the recent documents.
App::clearRecentDocuments();
App::addRecentDocument($b); App::addRecentDocument($a);
$w = new Headless(function (Context $c) use (&$opened) { $c->videoPlayerWelcome(function (string $p) use (&$opened) { $opened[] = $p; }, fn() => null); }, 560, 440);
$w->frame();
check($w->byText('Resume') !== null && $w->byText('Open…') !== null && $w->byText('Open URL…') !== null, 'Resume, Open… and Open URL…');
same(1, count(array_keys($w->texts(), 'Alpha.mkv', true)), 'the film played last, once: to resume, not among the recent');
check($w->byText('Beta.mkv') !== null, 'the recent documents');
$opened = [];
$w->enter(); $w->frame();
same([$a], $opened, 'Return resumes the film played last');
$opened = [];
$w->down(); $w->frame(); $w->enter(); $w->frame();
same([$b], $opened, 'Down, Return: the recent one');

$history->clear();
App::clearRecentDocuments();
unlink($a); unlink($b); rmdir($dir);
