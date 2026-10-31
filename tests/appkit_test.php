<?php
// The app's smaller services (mygo's recent_docs, login, network,
// clipboard and secrets tests), as far as they run without macOS: the
// recent documents' list and file, the checks of secrets' keys and sizes,
// the clipboard's guard against providers, the network's listeners, the
// login argument.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, Clipboard, ClipboardReentrant, Network, NetworkStatus, Secrets};
use MyPHP\Native\{Login, Pasteboard, RecentDocuments};

// Recent documents: newest first, each once, ten at most, kept in a file; those gone left out.
$dir = sys_get_temp_dir() . '/myphp-recent-' . getmypid();
@mkdir($dir);
RecentDocuments::$file = "$dir/recent-documents.json";
RecentDocuments::$system = false;
RecentDocuments::reset();
same([], App::recentDocuments(), 'none at first');
for ($i = 0; $i < 12; $i++) { touch("$dir/doc$i.txt"); App::addRecentDocument("$dir/doc$i.txt"); }
App::addRecentDocument("$dir/doc5.txt");
$list = App::recentDocuments();
same(10, count($list), 'ten at most');
same(["$dir/doc5.txt", "$dir/doc11.txt", "$dir/doc10.txt"], array_slice($list, 0, 3), 'newest first, each once');
check(!in_array("$dir/doc0.txt", $list, true), 'the oldest drop off');
unlink("$dir/doc11.txt");
same(9, count(App::recentDocuments()), 'a file gone is left out');
RecentDocuments::reset();
same(9, count(App::recentDocuments()), 'read back from the file');
touch("$dir/doc11.txt");
same("$dir/doc11.txt", App::recentDocuments()[1], 'and comes back if it does');
$cwd = getcwd();
chdir($dir);
App::addRecentDocument('doc3.txt');
chdir($cwd);
same("$dir/doc3.txt", App::recentDocuments()[0], 'a relative path, against the working directory');
App::clearRecentDocuments();
same([], App::recentDocuments(), 'cleared');
same('[]', file_get_contents("$dir/recent-documents.json"), 'and so is its file');
$threw = false;
try { App::addRecentDocument(''); } catch (\RuntimeException) { $threw = true; }
check($threw, 'a path is needed');
RecentDocuments::$file = null;
RecentDocuments::$system = true;
RecentDocuments::reset();
exec('rm -rf ' . escapeshellarg($dir));

// Secrets: what is checked before the keychain is asked.
$err = function (\Closure $fn): string { try { $fn(); } catch (\InvalidArgumentException $e) { return $e->getMessage(); } return ''; };
same('myphp: empty secret key', $err(fn() => Secrets::set('', 'x')), 'an empty key');
check(str_contains($err(fn() => Secrets::get(str_repeat('k', 257))), 'more than 256'), 'a key too long');
check(str_contains($err(fn() => Secrets::delete("a\0b")), 'contains NUL'), 'a key with NUL');
check(str_contains($err(fn() => Secrets::set('token', str_repeat('x', Secrets::MAX_SECRET_SIZE + 1))), 'more than MAX_SECRET_SIZE (2560)'), 'a secret too large');
check(str_contains($err(fn() => Secrets::service("a\0")->get('k')), 'secret service'), 'a service is checked too');

// The clipboard is not a provider's to use while it gives its data.
Pasteboard::$providing = true;
$threw = false;
try { Clipboard::formats(); } catch (ClipboardReentrant) { $threw = true; }
check($threw, 'a provider reading the clipboard is refused');
Pasteboard::$providing = false;

// The network: listeners hear changes only.
$heard = [];
$off = Network::onChanged(function (NetworkStatus $s) use (&$heard): void { $heard[] = $s->jsonSerialize(); });
Network::changed(new NetworkStatus(true, false));
Network::changed(new NetworkStatus(true, false));
Network::changed(new NetworkStatus(true, true));
$off();
Network::changed(new NetworkStatus(false, false));
same([['online' => true, 'metered' => false], ['online' => true, 'metered' => true]], $heard, 'changes, once each, until removed');
same('{"online":true,"metered":false}', json_encode(new NetworkStatus(true)), 'as pages get it');

// Started at login: the launch agent's argument leaves the command line.
$argv0 = $_SERVER['argv'];
$_SERVER['argv'] = ['app.php', 'x', Login::ARG, 'y'];
Login::takeArg();
same(['app.php', 'x', 'y'], $_SERVER['argv'], 'the login argument is taken out');
check(App::wasOpenedAtLogin(), 'and the app knows it was opened at login');
$_SERVER['argv'] = $argv0;
$GLOBALS['argv'] = $argv0;
Login::$openedAtLogin = false;
$threw = '';
try { App::setOpenAtLogin(true); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'needs an app bundle'), "starting at login needs a bundle: $threw");
check(!App::openAtLogin(), 'without one, it does not');

// A tray icon's right-clicks: the right button's, and the primary's with Control held, as a Control-click is a secondary click across macOS (mygo's TestSecondaryClick).
[$leftUp, $rightUp] = [2, 4];
[$shift, $control, $option, $command] = [1 << 17, 1 << 18, 1 << 19, 1 << 20];
same([false, false, false, false, true, true, true, true], array_map(fn(array $c) => \MyPHP\Tray::secondaryClick(...$c),
    [[$leftUp, 0], [$leftUp, $shift], [$leftUp, $option], [$leftUp, $command], [$leftUp, $control], [$leftUp, $control | $shift], [$rightUp, 0], [$rightUp, $control]]), 'a Control-click is a right-click');

// The copy of a menu bar item a click hit, from the pointer's place on screen and the click's in the item's window: here on an external display, far from the window (mygo's TestClickedCopy);
// kept while the window keeps the frame it had at the click, the window's without a click or once it moved (TestItemFrame).
same([2221.0, 1410.0, 54.0, 24.0], \MyPHP\Tray::clickedCopy([2251.0, 1422.0], [30.0, 12.0], [54.0, 24.0]), 'the copy of the icon clicked');
$window = [159.0, 1050.0, 54.0, 24.0]; $clicked = [2221.0, 1410.0, 54.0, 24.0]; $moved = [119.0, 1050.0, 54.0, 24.0];
same([$window, $clicked, $moved], [\MyPHP\Tray::itemFrame($window, null, null), \MyPHP\Tray::itemFrame($window, $clicked, $window), \MyPHP\Tray::itemFrame($moved, $clicked, $window)], 'the icon shows where it was clicked, while its window stays');
