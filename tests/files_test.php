<?php
// The files example, headless, on a folder made for it: what it shows,
// opening and going up, finding, the table's sort, choosing, Delete….
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Key, Node};

$dir = realpath('/tmp') . '/myphp-files-' . getmypid(); // a short path: the breadcrumbs of macOS's temporary folder, nine deep, leave the toolbar no room to show them
mkdir("$dir/Docs", 0777, true); mkdir("$dir/Docs/Inner");
file_put_contents("$dir/a.txt", str_repeat('a', 10)); file_put_contents("$dir/b.txt", str_repeat('b', 3000)); file_put_contents("$dir/zed.txt", 'z'); file_put_contents("$dir/.hidden", '');
[$view, $f] = Headless::files($dir);
$h = new Headless($view, 960, 640);
$names = fn() => array_map(fn($e) => $e['name'], $f->entries);
/** The node showing text $s, or null. */
$showing = function (string $s, ?Node $from = null) use (&$showing, $h): ?Node {
    $from ??= $h->root;
    if ($from->text === $s) { return $from; }
    foreach ($from->kids as $k) { if (($r = $showing($s, $k)) !== null) { return $r; } }
    return null;
};

// The folder: folders first, then files by name; hidden ones left out; the grid, 4 items.
$h->frame();
same($dir, $f->path, 'the folder');
same(['Docs', 'a.txt', 'b.txt', 'zed.txt'], $names(), 'entries folders first then by name');
same('', $f->place, 'a temp folder is no place');
check($showing('4 items') !== null, 'the status counts the items');
check($showing('a.txt') !== null, 'the grid shows the names');

// A click chooses; Cmd+click adds; the status says.
$h->clickOn($showing('a.txt'));
same(['a.txt'], array_map(fn($e) => $e['name'], $f->chosenEntries()), 'a click chooses');
$h->clickOn($showing('zed.txt'), Key::COMMAND);
same(['a.txt', 'zed.txt'], array_map(fn($e) => $e['name'], $f->chosenEntries()), 'Cmd+click adds');
check($showing('2 of 4 chosen') !== null, 'the status counts the chosen');

// A double click on a folder opens it; Backspace goes up; the breadcrumbs show the path.
$h->clickOn($showing('Docs'), 0, 2);
same("$dir/Docs", $f->path, 'a double click opens the folder');
same(['Inner'], $names(), 'its entries');
same([], $f->chosenEntries(), 'nothing chosen in the folder opened');
check($showing('Docs') !== null, 'the breadcrumbs end with the folder');
$h->key(Key::DELETE, 'deleteBackward:');
same($dir, $f->path, 'Backspace goes up');
// Enter opens the entry chosen.
$h->clickOn($showing('Docs')); $h->enter();
same("$dir/Docs", $f->path, 'Enter opens the folder chosen');
$h->key(Key::DELETE, 'deleteBackward:');

// Cmd+F: the find bar filters the entries as the query is typed; Escape closes it and they come back.
$h->shortcut('f', Key::COMMAND);
check($f->finding, 'Cmd+F opens the find bar');
$h->type('b');
same(['b.txt'], $names(), 'the query filters the entries');
$h->type('zz');
same([], $names(), 'no match: no entries');
check($showing('0 items') !== null, 'and the status says');
$h->escape();
check(!$f->finding, 'Escape closes the bar');
same(['Docs', 'a.txt', 'b.txt', 'zed.txt'], $names(), 'the entries come back');

// The table: its columns, the Size header sorts (folders stay first), the sort reads the entries again.
$f->view = 1; $h->frame();
check($showing('Size') !== null && $showing('Modified') !== null, 'the table has its headers');
check($showing('2.9 KB') !== null && $showing('10 B') !== null && $showing('—') !== null, 'sizes: KB, B, — for a folder');
$h->clickOn($showing('Size'));
same(['Docs', 'zed.txt', 'a.txt', 'b.txt'], $names(), 'Size sorts ascending, folders first');
$h->clickOn($showing('Size'));
same(['Docs', 'b.txt', 'a.txt', 'zed.txt'], $names(), 'again: descending');
$h->clickOn($showing('a.txt'));
same(['a.txt'], array_map(fn($e) => $e['name'], $f->chosenEntries()), 'a click on a row chooses');
$h->clickOn($showing('Size'));
same(['a.txt'], array_map(fn($e) => $e['name'], $f->chosenEntries()), 'the choice follows the entry through a sort');

// Delete…: Cmd+Backspace asks; Escape cancels; nothing was moved.
$h->key(Key::DELETE, 'deleteBackward:', Key::COMMAND);
check($f->asking, 'Cmd+Backspace asks');
check($showing('Move “a.txt” to the Trash?') !== null, 'the alert names the entry');
$h->escape();
check(!$f->asking, 'Escape cancels');
check(file_exists("$dir/a.txt"), 'the file is still there');
// Icons and List switch back; the status line.
$f->view = 0; $h->frame();
check($showing('a.txt') !== null, 'the grid again');

foreach (["$dir/Docs/Inner", "$dir/Docs"] as $d) { rmdir($d); }
foreach (glob("$dir/{.,}*", GLOB_BRACE) as $p) { if (is_file($p)) { unlink($p); } }
rmdir($dir);
