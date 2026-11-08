<?php
// The documentation's examples (docs/ui/*.md, docs/plugins/*.md and
// docs/getting-started.md) built headlessly, so the
// pages cannot drift from the API: every ```php block is run as it stands.
// A block that sets $view is run and its view built: a declarative view
// (`$view = fn() => VStack()(…)`, what a view file returns), or the engine's
// builder (a Closure(Context), for a Representable's inside); one that does
// not is the body of a builder view, $c its Context; one that only declares
// (a class, a function) is declared. In a declarative block the global
// views and enums (Font, Color, Image…) win over MyPHP\UI's classes. Classes are imported by the
// short names the block uses (MyPHP\UI first, then MyPHP, MyPHP\Transfer,
// then PHP's own),
// each block in a namespace of its own. A block that needs a window or the
// app's run loop has <!-- no-test --> on the line above its fence. The
// examples run in tests/testdata/docs, which has the files they open.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Context;

/** @return list<array{file: string, line: int, code: string}> the php blocks of the pages, those marked no-test left out */
function docBlocks(string ...$globs): array
{
    $blocks = [];
    foreach (array_merge(...array_map(fn(string $g) => glob($g) ?: [], $globs)) as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            if (trim($lines[$i]) !== '```php') { continue; }
            $skip = $i > 0 && trim($lines[$i - 1]) === '<!-- no-test -->';
            $start = $i + 1; $code = [];
            for ($i++; $i < $n && trim($lines[$i]) !== '```'; $i++) { $code[] = $lines[$i]; }
            if (!$skip) { $blocks[] = ['file' => basename(dirname($file)) . '/' . basename($file), 'line' => $start, 'code' => implode("\n", $code)]; }
        }
    }
    return $blocks;
}

/** @return array<string, string> short class name => the class, MyPHP\UI's before MyPHP's before MyPHP\Transfer's */
function docClasses(): array
{
    $src = dirname(__DIR__) . '/src';
    $map = [];
    foreach (['Transfer' => 'MyPHP\\Transfer\\', '' => 'MyPHP\\', 'UI' => 'MyPHP\\UI\\'] as $dir => $ns) {
        foreach (glob("$src/" . ($dir === '' ? '' : "$dir/") . '*.php') as $f) {
            if (ctype_upper(basename($f)[0])) { $map[basename($f, '.php')] = $ns . basename($f, '.php'); }
        }
    }
    $map['Headless'] = Headless::class; // the testing page's harness
    return $map;
}

/** The block made a file of its own: its namespace, the imports it lacks, and a function that runs it and returns its view. */
function docProgram(string $code, int $n, array $classes): string
{
    $code = preg_replace(['/^<\?php\s*/', '/^declare\(strict_types=1\);\s*/m'], '', $code);
    $uses = [];
    $code = preg_replace_callback('/^use [^(;]+;\s*$/m', function (array $m) use (&$uses) { $uses[] = $m[0]; return ''; }, $code);
    $own = [];
    foreach ($uses as $u) {
        // use A\B, use A\{B, C as D}: the names they bring
        if (preg_match('/\{([^}]*)\}/', $u, $g)) { $parts = explode(',', $g[1]); } else { $parts = [substr(trim($u), 4, -1)]; }
        foreach ($parts as $p) { $p = trim($p); $own[preg_match('/\sas\s+(\w+)$/', $p, $a) ? $a[1] : substr(strrchr('\\' . $p, '\\'), 1)] = true; }
    }
    preg_match_all('/\b(?:class|enum|interface|trait)\s+(\w+)/', $code, $decl);
    foreach ($decl[1] as $d) { $own[$d] = true; }
    $declares = preg_match('/^(?:final |abstract |readonly )*(?:class|enum|interface|trait|function)\s+\w/m', $code) === 1;
    $declarative = preg_match('/^\$view\s*=\s*(?:static\s+)?(?:fn|function)\s*\(\s*\)/m', $code) === 1; // a view file's closure, of no Context
    $hasView = preg_match('/^\$view\s*=/m', $code) === 1;
    if (!$hasView && !$declares) { $code = "\$view = function (Context \$c) {\n$code\n};"; }
    preg_match_all('/(?<![\\\\$\w>:])([A-Z]\w*)\b/', $code . ' Context', $names);
    foreach (array_unique($names[1]) as $name) {
        if (isset($own[$name])) { continue; }
        if ($declarative && (class_exists('\\' . $name, false) || enum_exists('\\' . $name, false))) { $uses[] = "use $name;"; continue; } // the global views' enums and classes (Font, Color, Binding…) of functions.php
        if (isset($classes[$name])) { $uses[] = "use {$classes[$name]};"; }
        elseif (class_exists($name, false) || interface_exists($name, false) || enum_exists($name, false)) { $uses[] = "use $name;"; } // PHP's own: DateTimeImmutable, Closure
    }
    foreach (['check', 'same'] as $f) {
        if (preg_match('/(?<![\\w>:$])' . $f . '\(/', $code)) { $uses[] = "use function MyPHP\\Tests\\$f;"; } // the testing page's assertions
    }
    return "namespace MyPHP\\DocExamples\\B$n;\n" . implode("\n", $uses) . "\nreturn function () {\n$code\nreturn \$view ?? null;\n};";
}

require_once dirname(__DIR__) . '/src/UI/Declarative/functions.php'; // the global views and enums, which declarative blocks use
$docClasses = docClasses();
$docCwd = getcwd();
chdir(__DIR__ . '/testdata/docs');
$docs = dirname(__DIR__) . '/docs';
$docBlocks = docBlocks("$docs/ui/*.md", "$docs/plugins/*.md", "$docs/getting-started.md", "$docs/swiftui.md", "$docs/mobile.md");
foreach ($docBlocks as $i => $b) {
    $where = "{$b['file']}:{$b['line']}";
    $errors = count($failures);
    try {
        $view = (eval(docProgram($b['code'], $i, $docClasses)))();
        if ($view instanceof \Closure) {
            if ((new \ReflectionFunction($view))->getNumberOfParameters() === 0) { $declared = $view; $view = static fn(Context $c) => \MyPHP\UI\Content::render($declared, $c); } // a view file's: rendered as Content::file renders it
            $h = new Headless($view, 600, 400);
            $h->frame();
            $h->paint();
        }
        check(count($failures) === $errors, "$where: warnings above"); // what PHP warned of, run.php counted
    } catch (\Throwable $e) {
        check(false, "$where: " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}
chdir($docCwd);
check(count($docBlocks) > 0, 'the pages have examples');
