<?php
// myphp test, as flutter test, and the least MyPHP a project needs (myphp.json's "myphp"): the command run on small
// projects in a temporary directory.
declare(strict_types=1);

namespace MyPHP\Tests;

$bin = dirname(__DIR__) . '/bin/myphp';
$src = (string)file_get_contents($bin);
check(str_contains($src, "'test' => test(\$args),") && preg_match('/^\ttest \[file \.\.\.\]\s+run the project\'s tests/m', $src) === 1, 'myphp test is a command, and myphp help lists it');
check(str_contains((string)file_get_contents(dirname(__DIR__) . '/docs/cli.md'), '## myphp test'), 'docs/cli.md has myphp test');
preg_match("/^const VERSION = '([^']*)';$/m", $src, $m);
$version = $m[1];

$dir = sys_get_temp_dir() . '/myphp_test_command_' . getmypid();
mkdir("$dir/tests", 0755, true);
$run = function (array $args = []) use ($bin, $dir): array { // [exit code, what it printed]
    $p = proc_open([PHP_BINARY, $bin, 'test', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
try {
    [$code, $out] = $run();
    check($code === 1 && str_contains($out, 'no tests'), "a project without tests fails saying so: $out");

    file_put_contents("$dir/tests/a_test.php", '<?php echo is_file(getenv("MYPHP_HOME") . "/src/autoload.php") ? "home ok\n" : "no home\n";');
    [$code, $out] = $run();
    same([0, true], [$code, str_contains($out, 'home ok')], "a test finds MyPHP as \$MYPHP_HOME: $out");

    file_put_contents("$dir/tests/b_test.php", '<?php exit(3);');
    [$code, $out] = $run();
    check($code === 1 && str_contains($out, '1 of 2 test files failed: b_test.php'), "a failing file fails the command, named: $out");
    [$code] = $run(['tests/a_test.php']);
    same(0, $code, 'given files, only they run');

    file_put_contents("$dir/myphp.json", json_encode(['myphp' => '999.0']));
    [$code, $out] = $run(['tests/a_test.php']);
    check($code === 1 && str_contains($out, "the project needs myphp 999.0 or later, this is $version: myphp upgrade"), "a project needing a newer MyPHP is told to upgrade: $out");
    foreach ([$version, ">=$version", "^$version", '1'] as $needs) {
        file_put_contents("$dir/myphp.json", json_encode(['myphp' => $needs]));
        same(0, $run(['tests/a_test.php'])[0], "\"myphp\": \"$needs\" is met by $version");
    }
    file_put_contents("$dir/myphp.json", json_encode(['myphp' => 'latest']));
    [$code, $out] = $run(['tests/a_test.php']);
    check($code === 1 && str_contains($out, 'is not a version'), "a version that is none is refused: $out");

    // myphp init: a project that carries no path of this machine, needs this MyPHP at least, and whose test myphp test runs.
    foreach (['native', '3d'] as $template) {
        $made = "$dir/$template";
        $p = proc_open([PHP_BINARY, $bin, 'init', '-template', $template, $made], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        same(0, proc_close($p), "myphp init -template $template: $out");
        $json = json_decode((string)file_get_contents("$made/myphp.json"), true);
        same([$version, false], [$json['myphp'] ?? null, isset($json['framework'])], "its myphp.json needs this MyPHP and names no framework path");
        $all = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($made, \FilesystemIterator::SKIP_DOTS)) as $f) { if ($f->getExtension() !== 'png') { $all .= file_get_contents($f->getPathname()); } }
        check(!str_contains($all, dirname(__DIR__)), 'and none of its files has the path of this checkout');
        check(str_contains($out, 'myphp test'), 'init says how to test it');
        $p = proc_open([PHP_BINARY, $bin, 'test'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $made);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        same(0, proc_close($p), "and myphp test runs its test: $out");
        $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', "$made/tests/ui_test.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $made, array_diff_key(getenv(), ['MYPHP_HOME' => 1]));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        check(proc_close($p) !== 0 && str_contains($out, 'myphp test'), 'run without the command, the test says to use it');
    }

    // SwiftUI anti-pattern hints on hand-drawn bordered cards and redundant split-view WindowOptions.
    file_put_contents("$dir/view.php", '<?php return fn() => TabView(selection: $this->t)(Tab(\'A\', value: \'a\')(VStack()()->cornerRadius(12)->border(Color::separator)))->tabViewStyle(TabViewStyle::sidebarAdaptable);');
    file_put_contents("$dir/app.php", "<?php new WindowOptions(vibrancy: 'sidebar', titleBarStyle: 'hiddenInset');");
    file_put_contents("$dir/myphp.json", json_encode(['myphp' => $version]));
    [$code, $out] = $run(['tests/a_test.php']);
    same([0, true, true], [$code, str_contains($out, 'hand-drawn bordered card'), str_contains($out, 'enables hiddenInset + sidebar vibrancy automatically')], "myphp test emits non-fatal SwiftUI hints: $out");

    // Flutter-aligned CLI: create alias, help <cmd>, analyze, clean, and global install-skills symlink.
    $created = "$dir/created_app";
    $p = proc_open([PHP_BINARY, $bin, 'create', $created], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    same([0, false, true], [proc_close($p), is_dir("$created/.agents"), is_file("$created/app.php")], "myphp create scaffolds a clean project: $out");

    $p = proc_open([PHP_BINARY, $bin, 'help', 'analyze'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    same([0, true], [proc_close($p), str_contains($out, 'Analyzes the MyPHP project')], "myphp help <cmd> shows command help: $out");

    $p = proc_open([PHP_BINARY, $bin, 'analyze', $created], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    same([0, true], [proc_close($p), str_contains($out, 'No issues found!')], "myphp analyze passes on a clean template: $out");

    // analyze catches named function in view.php and Swift dot syntax
    file_put_contents("$created/view.php", "<?php function badHelper() {}\nreturn fn() => Text('Hi')->foregroundColor(Color.blue);\n");
    $p = proc_open([PHP_BINARY, $bin, 'analyze', $created], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    same([1, true, true], [proc_close($p), str_contains($out, 'do not declare named function badHelper()'), str_contains($out, '->foregroundStyle')], "myphp analyze catches view declarations and Swift typos: $out");

    // clean removes build/, dist/, .myphp/
    mkdir("$created/build/tmp", 0755, true);
    mkdir("$created/.myphp", 0755, true);
    $p = proc_open([PHP_BINARY, $bin, 'clean', $created], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    same([0, false, false], [proc_close($p), is_dir("$created/build"), is_dir("$created/.myphp")], "myphp clean deletes build artifacts: $out");

    // install-skills with no dir links globally into $HOME/.agents/skills/myphp-app
    $fakeHome = "$dir/fake_home";
    mkdir("$fakeHome/.pig/agent", 0755, true);
    $p = proc_open([PHP_BINARY, $bin, 'install-skills'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, ['HOME' => $fakeHome, 'USERPROFILE' => $fakeHome] + getenv());
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    same([0, true, true], [proc_close($p), is_file("$fakeHome/.agents/skills/myphp-app/SKILL.md"), is_file("$fakeHome/.pig/agent/skills/myphp-app/SKILL.md")], "myphp install-skills installs globally: $out");
    if (PHP_OS_FAMILY !== 'Windows') {
        check(is_link("$fakeHome/.agents/skills/myphp-app") && is_link("$fakeHome/.pig/agent/skills/myphp-app"), 'global install uses symlinks so myphp upgrade stays in sync');
    }
} finally {
    $rm = function (string $d) use (&$rm): void {
        foreach (scandir($d) ?: [] as $e) { if ($e !== '.' && $e !== '..') { is_dir("$d/$e") && !is_link("$d/$e") ? $rm("$d/$e") : unlink("$d/$e"); } }
        rmdir($d);
    };
    $rm($dir);
    $rm = null;
}
