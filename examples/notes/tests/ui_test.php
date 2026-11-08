<?php
// Builds the notes' view headless, with no window or display, and drives it
// as a user would: php -d ffi.enable=1 examples/notes/tests/ui_test.php
declare(strict_types=1);

$framework = getenv('MYPHP_HOME') ?: dirname(__DIR__, 3); // the checkout this example is in
require "$framework/tests/Headless.php";
require __DIR__ . '/../src/Note.php';
require __DIR__ . '/../src/Notes.php';

use MyPHP\Tests\Headless;
use MyPHP\UI\Content;
use function MyPHP\Tests\{check, same};

$dir = sys_get_temp_dir() . '/myphp-notes-' . getmypid(); // never the user's notes
mkdir($dir);
register_shutdown_function(function () use ($dir) { array_map('unlink', glob("$dir/*") ?: []); rmdir($dir); });

// The first run: the welcome note; a new note takes a title and a list typed with Markdown, and shows in the sidebar.
$s = new Notes("$dir/notes.json");
$h = new Headless(Content::file(__DIR__ . '/../view.php', $s)->view, 900, 640);
$h->paints = true; // the welcome note's chip measures its name
$h->frame();
same('Welcome to Notes', $s->current()->title(), 'the welcome note');
$h->clickLabel('New note');
$h->clickLabel('Note'); // the editor, by its label
$h->type('Shopping');
$h->enter();
$h->type('- milk');
$h->frame();
same('Shopping', $s->current()->title(), 'the new note is titled by its first line');
$md = $s->current()->document()->text()->markdown();
check(str_contains($md, '# Shopping') && str_contains($md, '- milk'), "its Markdown: $md");
check($h->byText('Shopping') !== null, 'the sidebar shows it: ' . json_encode($h->texts()));

// The search: the notes with its words; none, said so, the field kept.
$s->query = 'welcome'; $h->frame();
same([false, true], [$h->byText('Shopping') !== null, $h->byText('Welcome to Notes') !== null], 'the search finds the notes with its words');
$s->query = 'nothing like it'; $h->frame();
check($h->byText('No note matches.') !== null && $h->byLabel('Search') !== null, 'none found: says so, the search field kept: ' . json_encode($h->texts()));
$s->query = ''; $h->frame();

// Saved as it is typed: read again, the notes are as they were.
same(['Shopping', 'Welcome to Notes'], array_map(fn(Note $n) => $n->title(), (new Notes("$dir/notes.json"))->notes), 'saved, and read again');

// Deleting asks first; Delete in the alert deletes the note shown and shows the next.
$h->clickLabel('Delete note');
$h->clickText('Delete'); // the alert's: it is over the toolbar's
$h->frame();
same(['Welcome to Notes'], array_map(fn(Note $n) => $n->title(), $s->notes), 'Delete asks, then deletes the note shown');
same(['Welcome to Notes'], array_map(fn(Note $n) => $n->title(), (new Notes("$dir/notes.json"))->notes), 'and the delete is saved');

exit(empty($GLOBALS['failures']) ? 0 : 1);
