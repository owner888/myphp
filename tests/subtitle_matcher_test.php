<?php
// IINA's auto load of subtitle files (Media\SubtitleMatcher): a series'
// subtitles of the same number, those containing the film's name, the
// priority words first, the search path's folders, none when off.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\SubtitleMatcher;

$d = sys_get_temp_dir() . '/myphp-match-' . getmypid();
mkdir($d); mkdir("$d/Subs");
$files = ['[Grp] Anime - 01.mkv', '[Grp] Anime - 02.mkv', '[Grp] Anime - 03.mkv', 'Anime 01.ass', 'Anime 02.ass', 'Anime 03.ass', 'Film.mkv', 'Film.zh.srt', 'Film.en.srt', 'Other.srt', 'Subs/Film.sc.srt'];
foreach ($files as $f) { touch("$d/$f"); }
$names = fn(array $paths) => array_map('basename', $paths);
same(['Anime 02.ass'], $names(SubtitleMatcher::match("$d/[Grp] Anime - 02.mkv", 2, '', './*', true)), "a series' film: its series' subtitles of its number");
same(['Film.en.srt', 'Film.zh.srt', 'Film.sc.srt'], $names(SubtitleMatcher::match("$d/Film.mkv", 2, 'en', './*', true)), 'those containing its name, the priority word first, the search path\'s after');
same(['Film.en.srt', 'Film.zh.srt'], $names(SubtitleMatcher::match("$d/Film.mkv", 1, '', '', true)), 'containing its name only, of no search path');
same([], SubtitleMatcher::match("$d/Film.mkv", 0, '', './*', true), 'none when off');
foreach (array_reverse($files) as $f) { unlink("$d/$f"); }
rmdir("$d/Subs"); rmdir($d);
