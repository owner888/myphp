<?php
// myphp screenshot: runs an app from its project, waits for its window to
// settle, saves its capture and its elements, and quits it; on Linux without
// a display, under an Xvfb it starts. examples/counter-native as it is (it
// adds no plugin), at its size and at one asked for; an app that fails as it
// starts, reported with its output; -theme forcing the appearance, -data the
// app's data apart from the user's.
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
    } finally {
        foreach (["$tmp/broken/app.php", "$tmp/where/app.php", "$tmp/s.png", "$tmp/t.json", "$tmp/big.png", "$tmp/w.png", "$tmp/w.json", "$tmp/out", "$tmp/err"] as $f) { @unlink($f); }
        if (is_dir("$tmp/data")) { // what the app and its toolkit wrote under -data
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$tmp/data", \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f); }
            @rmdir("$tmp/data");
        }
        @rmdir("$tmp/broken"); @rmdir("$tmp/where");
        @rmdir($tmp);
    }
})();
