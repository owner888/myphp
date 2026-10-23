<?php
// Deep links, files opened with the app and the single instance (mygo's
// deeplink.go and single_instance.go tests): Info.plist's schemes and
// document types read back, the arguments that are URLs or files, a second
// instance handing its command line over to the first.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\App;
use MyPHP\Native\{Bundle, DeepLink, Plist, SingleInstance};

// Property lists, as `myphp build` writes Info.plist.
$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CFBundleIdentifier</key>
	<string>com.example.notes</string>
	<key>NSHighResolutionCapable</key>
	<true/>
	<key>Count</key>
	<integer>3</integer>
	<key>Empty</key>
	<string/>
	<key>CFBundleURLTypes</key>
	<array>
		<dict>
			<key>CFBundleURLName</key>
			<string>com.example.notes</string>
			<key>CFBundleURLSchemes</key>
			<array>
				<string>Notes</string>
				<string>notes-dev</string>
			</array>
		</dict>
	</array>
	<key>CFBundleDocumentTypes</key>
	<array>
		<dict>
			<key>CFBundleTypeExtensions</key>
			<array>
				<string>md</string>
				<string>MARKDOWN</string>
			</array>
			<key>CFBundleTypeName</key>
			<string>Markdown &amp; notes</string>
		</dict>
	</array>
	<key>Nothing</key>
	<array/>
</dict>
</plist>
XML;
$p = Plist::parse($xml);
same('com.example.notes', $p['CFBundleIdentifier'], 'a string');
same([true, 3, ''], [$p['NSHighResolutionCapable'], $p['Count'], $p['Empty']], 'true, an integer, an empty string');
same(['Notes', 'notes-dev'], $p['CFBundleURLTypes'][0]['CFBundleURLSchemes'], 'arrays in dicts in arrays');
same('Markdown & notes', $p['CFBundleDocumentTypes'][0]['CFBundleTypeName'], 'entities');
same([], $p['Nothing'], 'an empty array');

// The bundle says what the app opens.
$setPlist = \Closure::bind(function (?array $p): void { self::$info = $p; }, null, Bundle::class);
$setPlist($p);
same(['notes', 'notes-dev'], DeepLink::declared(), 'the schemes it declares, lowercase');
same(['md', 'markdown'], DeepLink::extensions(), 'the extensions of its document types');
check(DeepLink::handles('NOTES') && !DeepLink::handles('http'), 'it handles its schemes, whatever their case');
check(DeepLink::isScheme('a+b-c.d') && !DeepLink::isScheme('1x') && !DeepLink::isScheme('') && !DeepLink::isScheme('a b'), 'what a scheme is');
same(['notes://open?id=1', 'Notes:x'], DeepLink::urlArgs(['--flag', 'notes://open?id=1', 'C:\\notes', 'http://example.com', 'Notes:x', 'plain']), 'the arguments that are its URLs');
$dir = sys_get_temp_dir() . '/myphp-open-' . getmypid();
@mkdir($dir);
touch("$dir/a.md"); touch("$dir/b.MarkDown"); touch("$dir/c.txt"); @mkdir("$dir/d.md");
same(["$dir/a.md", "$dir/b.MarkDown", "$dir/a.md"], DeepLink::fileArgs(['a.md', "$dir/b.MarkDown", 'c.txt', 'd.md', 'gone.md', "file://$dir/a.md"], $dir), 'the arguments that are files it opens, absolute; file URLs too; not folders nor missing files');
// Delivered to the listeners, which can be removed.
$urls = $files = [];
$offURL = App::onOpenURL(function (string $u) use (&$urls): void { $urls[] = $u; });
$offFile = App::onOpenFile(function (string $f) use (&$files): void { $files[] = $f; });
DeepLink::deliverArgs(['notes://x', 'a.md', 'other'], $dir);
same([['notes://x'], ["$dir/a.md"]], [$urls, $files], 'the launch\'s URLs and files reach the listeners');
$offURL();
DeepLink::openURLs(['notes://y']);
DeepLink::openFiles(["$dir/b.MarkDown"]);
same([['notes://x'], ["$dir/a.md", "$dir/b.MarkDown"]], [$urls, $files], 'a listener removed hears no more');
$offFile();
// Registering checks the bundle declares it first.
$threw = '';
try { App::registerURLScheme('other'); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'does not declare the URL scheme "other"'), "a scheme the bundle does not declare: $threw");
$threw = '';
try { App::registerURLScheme('9x'); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'invalid URL scheme'), 'an invalid scheme');
check(DeepLink::handles('other'), 'registering it still makes the app take its URLs, as mygo\'s');

// One instance: the lock's socket, per user and app, short.
same("com.example.notes\nNotes", SingleInstance::key('Notes'), 'the lock of a bundle is its identifier\'s and name\'s');
$sock = SingleInstance::socket("test\n" . getmypid());
check(strlen($sock) < 100 && str_contains($sock, 'myphp-' . getmyuid() . '-') && str_ends_with($sock, '.sock'), "a short per-user socket: $sock");
$setPlist(null);
same('Notes', SingleInstance::key('Notes'), 'without a bundle, the name\'s');
// A second instance hands its command line over to the first.
App::setName('myphp-test-' . getmypid());
@unlink(SingleInstance::socket(SingleInstance::key(App::name())));
check(App::requestSingleInstanceLock(), 'the first instance takes the lock');
check(App::requestSingleInstanceLock(), 'and keeps it');
check(file_exists(SingleInstance::socket(SingleInstance::key(App::name()))), 'its socket');
$got = null;
$off = App::onSecondInstance(function (array $args, string $wd) use (&$got): void { $got = [$args, $wd]; });
$script = "$dir/second.php";
file_put_contents($script, '<?php
spl_autoload_register(fn($c) => require ' . var_export(realpath(__DIR__ . '/../src'), true) . ' . "/" . str_replace("\\\\", "/", substr($c, 6)) . ".php");
MyPHP\App::setName(' . var_export(App::name(), true) . ');
MyPHP\Native\SingleInstance::$self = [["notes://second", "a.md"], ' . var_export($dir, true) . '];
echo var_export(MyPHP\App::requestSingleInstanceLock(), true);
');
$proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$end = microtime(true) + 5;
while ($got === null && microtime(true) < $end) {
    SingleInstance::accept();
    usleep(10_000);
}
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
proc_close($proc);
same('false', $out, "the second instance does not get the lock ($err)");
same([['notes://second', 'a.md'], $dir], $got, 'the first gets its arguments and working directory');
$off();
SingleInstance::release();
check(!file_exists(SingleInstance::socket(SingleInstance::key(App::name()))), 'released, its socket is gone');
DeepLink::reset();
exec('rm -rf ' . escapeshellarg($dir));
