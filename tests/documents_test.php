<?php
// Documents (mygo's document_test.go): opening, saving and autosaving,
// keeping links, closing that asks, files other apps change, recovery
// copies and the sessions a crash leaves, quitting, renaming and moving,
// the File menu, untitled names. Windows are fake frames, dialogs answer
// as a test says, and the run loop's turns and timers run on a test clock.
declare(strict_types=1);

namespace MyPHP\Tests;

use Closure;
use MyPHP\{App, Autosave, Document, DocumentCanceled, DocumentChanged, Documents, DocumentsOptions, DocumentState, DocumentType, MessageOptions, MessageResult};
use MyPHP\Native\RecentDocuments;
use MyPHP\Native\Docs\{Files, Frame, Host, Registry, Untitled, Watches};

/** A document's window, without AppKit. */
final class DocFrame implements Frame
{
    /** @var list<Closure> */
    public array $close = [], $closed = [], $focus = [], $blur = [];
    public bool $destroyed = false;
    public ?DocumentState $shown = null;
    public int $reloads = 0, $shows = 0;

    public function window(): ?\MyPHP\Window { return null; }
    public function onClose(Closure $fn): void { $this->close[] = $fn; }
    public function onClosed(Closure $fn): void { $this->closed[] = $fn; }
    public function onFocus(Closure $fn): void { $this->focus[] = $fn; }
    public function onBlur(Closure $fn): void { $this->blur[] = $fn; }
    public function show(): void { $this->shows++; }
    public function close(): void
    {
        if ($this->destroyed) { return; }
        $canceled = false;
        foreach ($this->close as $fn) { $canceled = $fn($canceled) || $canceled; }
        if (!$canceled) { $this->destroy(); }
    }
    public function destroy(): void
    {
        if ($this->destroyed) { return; }
        $this->destroyed = true;
        foreach ($this->closed as $fn) { $fn(); }
    }
    public function isDestroyed(): bool { return $this->destroyed; }
    public function present(DocumentState $s): void { $this->shown = $s; }
    public function reloaded(): void { $this->reloads++; }
}

/** The test's run loop: what runs on its next turn, the timers, its clock, and what dialogs answer. */
final class DocLoop
{
    /** @var list<Closure> */
    public static array $later = [];
    /** @var list<array{0: float, 1: Closure}> */
    public static array $timers = [];
    public static float $clock = 1_000_000.0;
    /** @var list<string> the messages of the message boxes shown */
    public static array $asked = [];
    public static string $answer = 'Cancel';
    public static string $savePath = '';
    public static int $quits = 0;

    /** Runs what waits for the run loop, and the timers that are due. */
    public static function pump(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            if (self::$later !== []) {
                $fn = array_shift(self::$later);
                $fn();
                continue;
            }
            $due = null;
            foreach (self::$timers as $k => [$at]) {
                if ($at <= self::$clock && ($due === null || $at < self::$timers[$due][0])) { $due = $k; }
            }
            if ($due === null) { return; }
            $fn = self::$timers[$due][1];
            unset(self::$timers[$due]);
            $fn();
        }
        throw new \RuntimeException('the run loop never settles');
    }

    public static function advance(float $seconds): void
    {
        self::$clock += $seconds;
        self::pump();
    }

    /** Message boxes answer with the button labeled $label. */
    public static function answer(string $label): void
    {
        self::$answer = $label;
        self::$asked = [];
    }
}

Host::$later = function (Closure $fn): void { DocLoop::$later[] = $fn; };
Host::$after = function (float $s, Closure $fn): void { DocLoop::$timers[] = [DocLoop::$clock + $s, $fn]; };
Host::$now = fn(): float => DocLoop::$clock;
Host::$message = function (MessageOptions $o): MessageResult {
    DocLoop::$asked[] = $o->message;
    $i = array_search(DocLoop::$answer, $o->buttons, true);
    return new MessageResult($i === false ? -1 : $i, false);
};
Host::$save = fn() => DocLoop::$savePath;
Host::$quit = function (): void { DocLoop::$quits++; };
Host::$focused = fn() => null;
Files::$replace = function (string $path, string $tmp): void { // NSFileManager's keeps the file's permissions too; a new file is renamed into place, as Files::replaceItem does
    if (file_exists($path)) { chmod($tmp, fileperms($path) & 0777); }
    rename($tmp, $path) || throw new \RuntimeException("cannot replace $path");
};
$docsRoot = sys_get_temp_dir() . '/myphp-docs-' . getmypid();
@mkdir($docsRoot);
RecentDocuments::$file = "$docsRoot/recent-documents.json";
RecentDocuments::$system = false;
RecentDocuments::reset();
$docsN = 0;
/** @var list<Documents> */
$docsKinds = [];

/** Documents of plain text, .txt, with their own user data. */
$testDocs = function (DocumentsOptions $o) use (&$docsN, &$docsKinds, $docsRoot): Documents {
    $docsN++;
    Host::$dataDir = "$docsRoot/data$docsN";
    @mkdir(Host::$dataDir);
    $o->types = [new DocumentType("Text $docsN", ['txt'])];
    $o->noUntitled = true;
    $o->read = fn(string $b): string => $b;
    $o->write ??= fn(string $s): string => $s;
    $o->window = fn(Document $d) => new DocFrame;
    return $docsKinds[] = new Documents($o);
};
/** Closes the documents of the kinds made, and forgets them. */
$docsDone = function () use (&$docsKinds): void {
    foreach ($docsKinds as $k) {
        foreach ($k->documents() as $d) {
            $d->setEdited(false);
            $d->frame()?->destroy();
        }
        DocLoop::pump();
        $k->recovery->close();
        Registry::forget($k);
    }
    $docsKinds = [];
};
$tmp = function (string $name, string $content) use ($docsRoot): string {
    $dir = "$docsRoot/" . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/$name", $content);
    return "$dir/$name";
};
$frame = fn(Document $d): DocFrame => $d->frame();

// Open.
$ds = $testDocs(new DocumentsOptions(autosave: Autosave::Off, equal: fn($a, $b) => $a === $b));
$path = $tmp('notes.txt', 'hello');
$d = $ds->open($path);
same(['hello', $path, 'notes.txt', false], [$d->content(), $d->path(), $d->name(), $d->isEdited()], 'opened');
check($ds->documents() === [$d] && Registry::documentOf($d->frame()) === $d, 'its window shows it');
DocLoop::pump();
same(['notes.txt', $path, false], [$frame($d)->shown->name, $frame($d)->shown->path, $frame($d)->shown->edited], 'the window\'s title and file');
check($ds->open($path) === $d && count($ds->documents()) === 1 && $frame($d)->shows === 1, 'opening it again focuses it');
$d->set('hello!');
DocLoop::pump();
check($d->isEdited() && $frame($d)->shown->edited, 'edited: the dot');
$d->set('hello');
DocLoop::pump();
check(!$d->isEdited() && !$frame($d)->shown->edited, 'back to what the file has is unedited, with equal');
check(in_array($path, App::recentDocuments(), true), 'a recent document');
$threw = false;
try { $ds->open(dirname($path)); } catch (\RuntimeException) { $threw = true; }
check($threw, 'a directory is no document');
$docsDone();

// Save.
$block = null;
$ds = $testDocs(new DocumentsOptions(autosave: Autosave::Off, write: function (string $s) use (&$block): string {
    if ($block !== null) { $f = $block; $block = null; $f(); }
    return $s;
}));
$path = $tmp('a.txt', 'one');
chmod($path, 0640);
$d = $ds->open($path);
$d->set('two');
$d->save();
check(file_get_contents($path) === 'two' && !$d->isEdited(), 'saved');
clearstatcache();
same(0640, fileperms($path) & 0777, 'its permissions kept');
same(['a.txt'], array_values(array_diff(scandir(dirname($path)), ['.', '..'])), 'no file left next to it');
// Edits made while it is written stay unsaved.
$d->set('three');
$block = fn() => $d->set('four');
$d->save();
check(file_get_contents($path) === 'three' && $d->isEdited() && $d->content() === 'four', 'the edit made while writing stays unsaved');
$docsDone();

// Saving keeps links.
$ds = $testDocs(new DocumentsOptions(autosave: Autosave::Off));
$target = $tmp('target.txt', 'x');
$link = dirname($target) . '/link.txt';
$hard = dirname($target) . '/hard.txt';
symlink($target, $link);
link($target, $hard);
$d = $ds->open($link);
$d->set('y');
$d->save();
check(is_link($link) && file_get_contents($target) === 'y' && file_get_contents($hard) === 'y', 'the symlink, its target and a hard link');
$docsDone();

// Autosave.
$ds = $testDocs(new DocumentsOptions(autosaveDelay: 0.02));
$path = $tmp('auto.txt', 'a');
$d = $ds->open($path);
$d->set('b');
DocLoop::advance(0.01);
same('a', file_get_contents($path), 'not before the delay');
DocLoop::advance(0.02);
check(file_get_contents($path) === 'b' && !$d->isEdited(), 'saved in place after it');
// Edited without a pause, it is saved at least every 30 seconds.
DocLoop::answer('Cancel');
$d->set('c');
$d->close();
DocLoop::pump();
check($d->frame() === null && file_get_contents($path) === 'c' && DocLoop::$asked === [], 'a saved document closes without asking, saving first');
$docsDone();
$ds = $testDocs(new DocumentsOptions(autosaveDelay: 5));
$path = $tmp('busy.txt', '0');
$d = $ds->open($path);
for ($i = 1; $i <= 10; $i++) { $d->set("$i"); DocLoop::advance(4); }
same('8', file_get_contents($path), 'edited every 4 seconds: saved once 30 seconds passed, as it was then');
$docsDone();

// Closing an untitled document asks.
$ds = $testDocs(new DocumentsOptions);
[$d1, $d2] = [$ds->new(), $ds->new()];
same(['Untitled', 'Untitled 2', ''], [$d1->name(), $d2->name(), $d1->path()], 'untitled names');
$d1->set('draft');
DocLoop::answer('Cancel');
$f1 = $frame($d1);
$d1->close();
DocLoop::pump();
same(['Do you want to keep this new document “Untitled”?'], DocLoop::$asked, 'asked');
check(!$f1->destroyed, 'Cancel keeps it open');
DocLoop::answer('Delete');
$d1->close();
DocLoop::pump();
check($f1->destroyed && $d1->frame() === null, 'Delete closes it');
$f2 = $frame($d2);
$d2->close();
check($f2->destroyed, 'an unedited one closes at once');
$d3 = $ds->new();
same('Untitled', $d3->name(), 'the number of one closed is free again');
$docsDone();

// Closing asks to save.
$ds = $testDocs(new DocumentsOptions(autosave: Autosave::Off));
$path = $tmp('ask.txt', '1');
$d = $ds->open($path);
$d->set('2');
DocLoop::answer('Save');
$d->close();
DocLoop::pump();
check($d->frame() === null && file_get_contents($path) === '2' && count(DocLoop::$asked) === 1 && str_contains(DocLoop::$asked[0], '“ask.txt”'), 'Save saves, then closes: ' . json_encode(DocLoop::$asked));
$u = $ds->new();
$u->set('new');
DocLoop::$savePath = dirname($path) . '/named';
DocLoop::answer('Save…');
$u->close();
DocLoop::pump();
same('new', @file_get_contents(dirname($path) . '/named.txt'), 'an untitled one asks where, and gets its extension');
$docsDone();

// Files other apps change.
$ds = $testDocs(new DocumentsOptions(autosave: Autosave::Off));
$path = $tmp('out.txt', 'mine');
$d = $ds->open($path);
$reloaded = 0;
$d->onReload(function () use (&$reloaded): void { $reloaded++; });
$future = time() + 3600;
file_put_contents($path, 'theirs');
touch($path, $future);
$d->checkDisk();
DocLoop::pump();
check($d->content() === 'theirs' && !$d->isEdited() && $reloaded === 1 && $frame($d)->reloads === 1, 'unedited: read again');
$d->set('mine again');
file_put_contents($path, 'theirs again');
touch($path, $future + 3600);
$d->checkDisk();
check($d->hasConflict() && $d->content() === 'mine again', 'edited: in conflict');
DocLoop::answer('Save Anyway');
$d->save();
check(file_get_contents($path) === 'mine again' && !$d->hasConflict() && count(DocLoop::$asked) === 1, 'Save Anyway writes it');
touch($path, $future + 7200);
$d->set('x');
$threw = false;
try { $d->write($path, Document::SAVE); } catch (DocumentChanged) { $threw = true; }
check($threw, 'without asking, saving reports the change');
DocLoop::answer('Cancel');
$threw = false;
try { $d->save(); } catch (DocumentCanceled) { $threw = true; }
check($threw && file_get_contents($path) === 'mine again', 'Cancel keeps the file');
unlink($path);
$d->checkDisk();
check($d->state()->deleted, 'deleted');
$d->save();
same('x', file_get_contents($path), 'saving a deleted document writes it again');
// Read-only: saving asks for another file.
chmod($path, 0444);
touch($path, $future + 9000);
$d->checkDisk();
$d->set('y');
if (!is_writable($path)) { // root writes anything
    check($d->isReadOnly(), 'read-only');
    DocLoop::answer('Save As…');
    DocLoop::$savePath = dirname($path) . '/copy.txt';
    $d->save();
    check($d->path() === DocLoop::$savePath && file_get_contents(DocLoop::$savePath) === 'y' && !$d->isReadOnly(), 'a read-only document is saved as another file');
}
$docsDone();

// Recovery copies.
$ds = $testDocs(new DocumentsOptions(autosave: Autosave::Off));
$d = $ds->new();
$d->set('unsaved');
DocLoop::advance(0.5);
$root = $ds->recovery->root();
same([], glob("$root/*/{$d->id}"), 'not before a second');
DocLoop::advance(0.6);
$copies = glob("$root/*/{$d->id}");
check(count($copies) === 1 && file_get_contents($copies[0]) === 'unsaved', 'a copy a second after the edit');
$index = json_decode((string)file_get_contents(dirname($copies[0]) . '/index.json'), true);
same([['id' => $d->id, 'stamp' => ['modTime' => 0, 'size' => 0], 'name' => 'Untitled']], $index, 'its entry');
DocLoop::answer('Don’t Save');
$d->close();
DocLoop::pump();
check(!file_exists($copies[0]), 'discarded: gone');
// A session no instance holds, left by a crash, comes back edited, and its file, changed meanwhile, in conflict.
$path = $tmp('crashed.txt', 'on disk');
$orphan = "$root/orphan";
mkdir($orphan, 0700, true);
[$mt, $size] = Files::stamp($path);
file_put_contents("$orphan/index.json", json_encode([['id' => 'a', 'name' => 'Untitled 3', 'stamp' => ['modTime' => 0, 'size' => 0]], ['id' => 'b', 'path' => $path, 'stamp' => ['modTime' => $mt - 1, 'size' => $size]]]));
file_put_contents("$orphan/a", 'draft');
file_put_contents("$orphan/b", 'changes');
// A session another instance holds stays.
mkdir("$root/alive");
$held = fopen("$root/alive/lock", 'c+');
flock($held, LOCK_EX);
same(2, $ds->restore(), 'restored two');
$untitled = $fileDoc = null;
foreach ($ds->documents() as $o) {
    if ($o->path() === $path) { $fileDoc = $o; } elseif ($o->name() === 'Untitled 3') { $untitled = $o; }
}
check($untitled?->content() === 'draft' && $untitled->isEdited() && $untitled->untitled === 3, 'the untitled one, edited, keeping its number');
check($fileDoc?->content() === 'changes' && $fileDoc->isEdited() && $fileDoc->hasConflict(), 'the file\'s, edited and in conflict');
check(!file_exists($orphan) && is_dir("$root/alive"), 'the claimed session is gone, the live one stays');
fclose($held);
check(glob("$root/*/b") !== [], 'their copies are kept in this session');
$docsDone();

// Quitting.
$ds = $testDocs(new DocumentsOptions(autosave: Autosave::Off));
$d = $ds->new();
$d->set('keep me');
DocLoop::answer('Cancel');
DocLoop::$quits = 0;
check(!App::shouldQuit(), 'the quit waits for an unsaved document');
DocLoop::pump();
check(DocLoop::$quits === 1 && DocLoop::$asked === [] && $d->backedUpAt() !== 0, 'kept for the next launch, without asking, then quits');
check(App::shouldQuit(), 'quitting again goes ahead');
$ask = $testDocs(new DocumentsOptions(autosave: Autosave::Off, askOnQuit: true));
$a = $ask->new();
$a->set('ask');
DocLoop::answer('Cancel');
check(!App::shouldQuit(), 'asks first');
DocLoop::pump();
check(count(DocLoop::$asked) === 1 && DocLoop::$quits === 1, 'Cancel: no quit');
DocLoop::answer('Don’t Save');
App::shouldQuit();
DocLoop::pump();
same(2, DocLoop::$quits, 'Don’t Save quits');
App::shouldQuit();
// Several to ask about: reviewed one by one, or discarded together.
$b = $ask->new();
$b->set('b');
$a->set('a');
DocLoop::answer('Discard Changes');
check(!App::shouldQuit(), 'two to ask about');
DocLoop::pump();
check(DocLoop::$quits === 3 && str_contains(DocLoop::$asked[0] ?? '', 'You have 2'), 'discarded together: ' . json_encode(DocLoop::$asked));
App::shouldQuit();
$docsDone();

// Rename, move, duplicate.
$ds = $testDocs(new DocumentsOptions(autosave: Autosave::Off));
$path = $tmp('old.txt', 'x');
$d = $ds->open($path);
$d->rename('new.txt');
$renamed = dirname($path) . '/new.txt';
check($d->path() === $renamed && file_get_contents($renamed) === 'x' && !file_exists($path), 'renamed');
$threw = false;
try { $d->rename('../x.txt'); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'a name, not a path');
$dir = "$docsRoot/moved";
mkdir($dir);
$d->moveTo($dir);
DocLoop::pump();
check($d->path() === "$dir/new.txt" && $frame($d)->shown->path === "$dir/new.txt", 'moved, and the window follows');
check(in_array("$dir/new.txt", App::recentDocuments(), true), 'a recent document where it is now');
$dup = $d->duplicate();
same(['new copy', 'x', true, ''], [$dup->name(), $dup->content(), $dup->isEdited(), $dup->path()], 'the duplicate');
// Another app renames the file: the document follows.
$w = Watches::paths();
check(in_array("$dir/new.txt", $w, true) && !in_array($renamed, $w, true), 'the file watched is where it is');
rename("$dir/new.txt", "$dir/elsewhere.txt");
$end = microtime(true) + 3;
while ($d->path() !== "$dir/elsewhere.txt" && microtime(true) < $end) {
    usleep(20_000);
    DocLoop::advance(Watches::POLL);
}
same("$dir/elsewhere.txt", $d->path(), 'renamed by another app: followed');
// Another app replaces the file and renames it before the watch looks again, the document having read the new file meanwhile (at the look when its window got the keyboard): the watch knows only the file replaced, so the document finds the new one by what it read (mygo's TestDocumentFollowsReplacedRename).
file_put_contents("$dir/elsewhere.txt.tmp", 'two, longer');
rename("$dir/elsewhere.txt.tmp", "$dir/elsewhere.txt");
$d->checkDisk();
same('two, longer', $d->content(), 'the document read the new file');
rename("$dir/elsewhere.txt", "$dir/renamed.txt");
$end = microtime(true) + 3;
while ($d->path() !== "$dir/renamed.txt" && microtime(true) < $end) {
    usleep(20_000);
    DocLoop::advance(Watches::POLL);
}
check($d->path() === "$dir/renamed.txt" && !$d->state()->deleted, 'renamed after it was replaced: followed, not deleted');
$docsDone();
same([], Watches::paths(), 'closed, nothing is watched');

// The File menu.
$ds = $testDocs(new DocumentsOptions(autosave: Autosave::Off));
same(['new', 'open', 'openRecent', '', 'close', 'save', 'duplicate', 'rename', 'moveTo', 'revert'], array_map(fn($it) => $it->role, Registry::fileMenu()), 'DocumentGroup\'s File menu');
$path = $tmp('menu.txt', '1');
$d = $ds->open($path);
$d->set('2');
Registry::perform('save', $d->frame());
same('2', file_get_contents($path), 'Save');
$n = count(Registry::all());
Registry::perform('new', null);
same($n + 1, count(Registry::all()), 'New');
$d->set('3');
DocLoop::answer('Revert');
Registry::perform('revert', $d->frame());
check($d->content() === '2' && !$d->isEdited(), 'Revert to Saved asks, then reads the file again');
$recent = Registry::recentItems();
check(count($recent) >= 3 && $recent[0]->label === 'menu.txt' && end($recent)->label === 'Clear Menu' && !end($recent)->disabled, 'Open Recent');
App::clearRecentDocuments();
$recent = Registry::recentItems();
check(count($recent) === 1 && $recent[0]->disabled, 'cleared, Clear Menu is disabled');
$docsDone();

// What pages get of a document's state.
same('{"name":"a.txt","path":"\/x\/a.txt","edited":true,"readOnly":false,"conflict":false,"deleted":false}', json_encode(new DocumentState('a.txt', '/x/a.txt', true, false, false, false)), 'the state as JSON');

// Untitled names.
foreach (['Untitled' => 1, 'Untitled 2' => 2, 'Untitled 12' => 12, 'Untitled x' => 0, 'Notes' => 0, 'Untitled 1' => 0, 'Untitled ۱۲' => 12] as $uname => $want) {
    same($want, Untitled::number($uname), "the number of $uname");
}
same(3, Untitled::numberIn('未命名 3', 'zh-Hans', 'en'), 'in Chinese');
same(34, Untitled::decimal('٣٤'), 'Arabic-Indic digits');
check(Untitled::decimal('3a') === null && Untitled::decimal('') === null, 'not numbers');
same('Untitled 3', Untitled::name(3), 'the third');

// Invalid options.
$threw = '';
try { new Documents(new DocumentsOptions(types: [new DocumentType('X', ['.x'])], read: fn($b) => $b, write: fn($s) => $s, window: fn() => null)); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'invalid extension'), 'an extension with a dot');

Host::$later = Host::$after = Host::$now = Host::$message = Host::$save = Host::$quit = Host::$focused = null;
Host::$dataDir = null;
Files::$replace = null;
RecentDocuments::$file = null;
RecentDocuments::$system = true;
RecentDocuments::reset();
exec('rm -rf ' . escapeshellarg($docsRoot));
