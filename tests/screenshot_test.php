<?php
// myphp screenshot: runs an app from its project, waits for its window to
// settle, saves its capture and its elements, and quits it; on Linux without
// a display, under an Xvfb it starts. examples/counter-native as it is (it
// adds no plugin), at its size and at one asked for; an app that fails as it
// starts, reported with its output; -theme forcing the appearance, -data the
// app's data apart from the user's; a web page captured once what its calls
// answered shows, -tree on it a warning; App::wait on a time and MyPHP\Http
// in a running app on Linux; a run stopped or failing leaves no Xvfb or app
// behind.
declare(strict_types=1);

namespace MyPHP\Tests;

(function (): void {
    if (PHP_OS_FAMILY !== 'Linux' || \MyPHP\Native\Build\Cmd::look('Xvfb') === '') {
        echo "  skipped: Linux's, with Xvfb (on a Mac it opens a window on the screen)\n";
        return;
    }
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/myphp-screenshot-test-' . getmypid();
    @mkdir($tmp, 0700);
    $env = getenv();
    unset($env['DISPLAY'], $env['WAYLAND_DISPLAY']); // no display: the command starts Xvfb
    /** @return array{0: int, 1: string, 2: string} the exit status, the output and the errors */
    $run = function (array $args) use ($root, $env, $tmp): array {
        $p = proc_open([PHP_BINARY, "$root/bin/myphp", 'screenshot', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tmp/out", 'w'], 2 => ['file', "$tmp/err", 'w']], $pipes, $root, $env);
        $code = proc_close($p);
        return [$code, (string)file_get_contents("$tmp/out"), (string)file_get_contents("$tmp/err")];
    };
    $png = function (string $path): array {
        $b = (string)@file_get_contents($path);
        return strlen($b) > 24 && str_starts_with($b, "\x89PNG\r\n\x1a\n") ? array_values(unpack('Nw/Nh', $b, 16)) + [2 => strlen($b)] : [0, 0, 0];
    };
    try {
        [$code, $out, $err] = $run(['examples/counter-native/main.php', '-out', "$tmp/s.png", '-tree', "$tmp/t.json", '-timeout', '60']);
        same(0, $code, "the counter's screenshot: $err");
        [$w, $h, $bytes] = $png("$tmp/s.png");
        check($w === 360 && $h === 260 && $bytes > 1000, "a PNG of its 360×260 window: {$w}×{$h}, $bytes bytes");
        $tree = json_decode((string)@file_get_contents("$tmp/t.json"), true);
        $labels = array_column($tree['nodes'] ?? [], 'label', 'key');
        same('Increment', $labels['increment'] ?? null, 'the tree has the button Increment, by its key');
        check(str_contains($out, 'button "Reset" key="reset" (disabled)'), 'the summary lists the elements: ' . $out);
        check(!str_contains($err, 'killed'), 'the app quit at SIGTERM, as Quit');

        [$code, , $err] = $run(['examples/counter-native/main.php', '-out', "$tmp/big.png", '-size', '480x320', '-timeout', '60']);
        same(0, $code, "-size: $err");
        [$w, $h] = $png("$tmp/big.png");
        check($w === 480 && $h === 320, "-size 480x320 resizes the content before the capture: {$w}×{$h}");

        mkdir("$tmp/broken");
        file_put_contents("$tmp/broken/app.php", "<?php\nthrow new RuntimeException('broken on purpose');\n");
        [$code, , $err] = $run(["$tmp/broken", '-out', "$tmp/b.png", '-timeout', '20']);
        same(1, $code, 'an app that fails as it starts fails the command');
        check(str_contains($err, 'the app exited') && str_contains($err, 'broken on purpose'), 'and its output says why: ' . $err);
        check(!file_exists("$tmp/b.png"), 'no PNG then');

        [$code, , $err] = $run(['examples/counter-native/main.php', '-size', '480']);
        same(1, $code, 'a size that is not WxH fails');
        check(str_contains($err, 'is not WxH'), 'saying so');

        // -theme and -data: an app that shows its appearance and where its data goes, and writes a file there.
        mkdir("$tmp/where");
        file_put_contents("$tmp/where/app.php", '<?php
declare(strict_types=1);
require ' . var_export("$root/src/autoload.php", true) . ';
use MyPHP\{App, PathName, Window, WindowOptions};
use MyPHP\UI\{Content, Context};
App::setName("Where");
App::whenReady(function () {
    $data = App::path(PathName::UserData);
    file_put_contents("$data/touched", "x");
    new Window(new WindowOptions(title: "Where", width: 300, height: 120, content: Content::view(function (Context $c) use ($data) {
        $c->column();
          $c->key("theme")->text($c->theme->dark ? "dark" : "light");
          $c->key("data")->text($data);
        $c->end();
    })));
});
App::run();
');
        $shown = function (string $json): array { return array_column(json_decode((string)@file_get_contents($json), true)['nodes'] ?? [], 'label', 'key'); };
        foreach (['dark', 'light'] as $theme) {
            [$code, , $err] = $run(["$tmp/where", '-out', "$tmp/w.png", '-tree', "$tmp/w.json", '-theme', $theme, '-data', "$tmp/data", '-timeout', '60']);
            same(0, $code, "-theme $theme: $err");
            same($theme, $shown("$tmp/w.json")['theme'] ?? null, "-theme $theme: the app shows $theme");
        }
        same("$tmp/data/config/Where", $shown("$tmp/w.json")['data'] ?? null, '-data: UserData under the directory (XDG_CONFIG_HOME), after App::setName');
        check(is_file("$tmp/data/config/Where/touched"), 'and what the app writes there lands there');
        [$code, , $err] = $run(["$tmp/where", '-theme', 'blue']);
        same(1, $code, 'a theme that is not light or dark fails');
        check(str_contains($err, 'is not light or dark'), 'saying so: ' . $err);

        // A web page: captured once the reply to its call of a slow PHP method has been rendered (its background turns red);
        // -tree saves the PNG alone, with a warning. The pixel at (0, 0) is read from the PNG's first row, which no filter changes.
        mkdir("$tmp/page");
        file_put_contents("$tmp/page/app.php", '<?php
declare(strict_types=1);
require ' . var_export("$root/src/autoload.php", true) . ';
use MyPHP\{App, Channel, Ipc, Window, WindowOptions};
final class Slow { public function color(): \Generator { yield Channel::wait(1.0); return "rgb(255, 0, 0)"; } }
App::setName("Page");
Ipc::bind(new Slow());
App::whenReady(function () {
    new Window(new WindowOptions(title: "Page", width: 200, height: 100, html: "<body style=\"margin:0;background:#fff\"><script>myphp.call(\"Slow.color\").then((c) => { document.body.style.background = c; });</script></body>"));
});
App::run();
');
        $first = function (string $path): ?array {
            $b = (string)@file_get_contents($path);
            if (strlen($b) < 33 || !str_starts_with($b, "\x89PNG")) { return null; }
            $color = ord($b[25]); $idat = ''; $at = 8;
            while ($at < strlen($b)) {
                ['n' => $n] = unpack('Nn', $b, $at); $type = substr($b, $at + 4, 4);
                if ($type === 'IDAT') { $idat .= substr($b, $at + 8, $n); }
                $at += 12 + $n;
            }
            $raw = @gzuncompress($idat);
            return $raw === false ? null : [ord($raw[1]), ord($raw[2]), ord($raw[3]), $color];
        };
        if (\MyPHP\Platform\Linux\WebKit::available()) {
            [$code, , $err] = $run(["$tmp/page", '-out', "$tmp/p.png", '-tree', "$tmp/p.json", '-timeout', '60']);
            same(0, $code, "a web page with -tree: $err");
            check(str_contains($err, 'has no tree of elements') && !file_exists("$tmp/p.json"), '-tree on a page warns and writes no tree: ' . $err);
            same([255, 0, 0], array_slice($first("$tmp/p.png") ?? [], 0, 3), 'the page captured once the reply of its call was rendered');
        }

        // App::wait on a condition only time makes true returns in time on Linux too: its turn of the main loop waits 50 ms at most.
        mkdir("$tmp/waits");
        file_put_contents("$tmp/waits/app.php", '<?php
declare(strict_types=1);
require ' . var_export("$root/src/autoload.php", true) . ';
use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context};
App::setName("Waits");
App::whenReady(function () {
    $t = microtime(true);
    App::wait(fn() => microtime(true) - $t > 0.3);
    new Window(new WindowOptions(title: "Waits", width: 200, height: 80, content: Content::view(fn(Context $c) => $c->key("waited")->text("waited"))));
});
App::run();
');
        [$code, , $err] = $run(["$tmp/waits", '-out', "$tmp/w.png", '-tree', "$tmp/w.json", '-timeout', '20']);
        same(0, $code, "App::wait on a time: $err");
        same('waited', $shown("$tmp/w.json")['waited'] ?? null, 'the window shows after the wait');

        // MyPHP\Http in a fiber of a running app on Linux: GLib's main loop drives the transfer (CurlLoop), the window shows what came.
        mkdir("$tmp/net");
        file_put_contents("$tmp/net/repo.json", '{"stars": 42}');
        [$base, $stop] = rangeServer("$tmp/net");
        file_put_contents("$tmp/net/app.php", '<?php
declare(strict_types=1);
require ' . var_export("$root/src/autoload.php", true) . ';
use MyPHP\{App, Http, Window, WindowOptions};
use MyPHP\UI\{Content, Context};
App::setName("Net");
App::whenReady(function () {
    App::go(function () {
        $r = Http::get(' . var_export("$base/repo.json", true) . ');
        $shown = $r->status . " " . $r->json()["stars"];
        new Window(new WindowOptions(title: "Net", width: 200, height: 80, content: Content::view(fn(Context $c) => $c->key("got")->text($shown))));
    });
});
App::run();
');
        try {
            [$code, , $err] = $run(["$tmp/net", '-out', "$tmp/w.png", '-tree', "$tmp/w.json", '-timeout', '20']);
        } finally {
            $stop();
        }
        same(0, $code, "Http in a running app: $err");
        same('200 42', $shown("$tmp/w.json")['got'] ?? null, 'the response came in the fiber, the app running');

        // Stopped by a signal, or failing, it leaves neither the app nor Xvfb behind.
        $children = function (int $pid): array { // the processes whose parent is $pid
            $out = [];
            foreach (glob('/proc/[0-9]*/stat') as $f) {
                $st = (string)@file_get_contents($f);
                if (preg_match('/^(\d+) \(.*\) (\S) (\d+)/s', $st, $m) === 1 && (int)$m[3] === $pid && $m[2] !== 'Z') { $out[] = (int)$m[1]; }
            }
            return $out;
        };
        mkdir("$tmp/sleepy");
        file_put_contents("$tmp/sleepy/app.php", "<?php\nsleep(60);\n");
        $p = proc_open([PHP_BINARY, "$root/bin/myphp", 'screenshot', "$tmp/sleepy", '-timeout', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tmp/out", 'w'], 2 => ['file', "$tmp/err", 'w']], $pipes, $root, $env);
        $pid = proc_get_status($p)['pid'];
        for ($end = microtime(true) + 20; count($children($pid)) < 2 && microtime(true) < $end;) { usleep(50_000); }
        $kids = $children($pid);
        same(2, count($kids), 'the command runs Xvfb and the app');
        proc_terminate($p, 15);
        same(1, proc_close($p), 'SIGTERM fails the command');
        usleep(200_000);
        $left = array_values(array_filter($kids, fn(int $k) => is_dir("/proc/$k") && !str_contains((string)@file_get_contents("/proc/$k/stat"), ') Z ')));
        same([], $left, 'and it ended them first: ' . file_get_contents("$tmp/err"));
        array_map(fn(int $k) => posix_kill($k, 9), $left);
        [$code, , $err] = $run(["$tmp/sleepy", '-timeout', '3']);
        same(1, $code, 'an app that never listens fails the command');
        check(str_contains($err, 'did not listen'), 'saying so: ' . $err);
    } finally {
        foreach (["$tmp/broken/app.php", "$tmp/where/app.php", "$tmp/page/app.php", "$tmp/sleepy/app.php", "$tmp/waits/app.php", "$tmp/net/app.php", "$tmp/net/repo.json", "$tmp/p.png", "$tmp/p.json", "$tmp/s.png", "$tmp/t.json", "$tmp/big.png", "$tmp/w.png", "$tmp/w.json", "$tmp/out", "$tmp/err"] as $f) { @unlink($f); }
        if (is_dir("$tmp/data")) { // what the app and its toolkit wrote under -data
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$tmp/data", \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f); }
            @rmdir("$tmp/data");
        }
        @rmdir("$tmp/broken"); @rmdir("$tmp/where"); @rmdir("$tmp/page"); @rmdir("$tmp/sleepy"); @rmdir("$tmp/waits"); @rmdir("$tmp/net");
        @rmdir($tmp);
    }
})();
