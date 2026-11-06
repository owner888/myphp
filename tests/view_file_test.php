<?php
// Content::file: the view a file returns, run bound to the app's state ($this,
// its private members and self::), loaded again by a hot reload (the file
// MYPHP_HOT_RELOAD names lists it) with the state as it was; a file that
// declares a named class, or fails to load, keeps the view it had, said in
// the log; a change to another file is said too.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Logger;
use MyPHP\UI\{Content, Context, ViewFile};

final class ViewFileState
{
    private const LABEL = 'count';
    public int $n = 0;
    private string $title = 'Counter';
}

$dir = sys_get_temp_dir() . '/myphp-view-file-' . getmypid();
@mkdir($dir);
$view = "$dir/view.php";
$trigger = "$dir/.reload";
putenv("MYPHP_HOT_RELOAD=$trigger");
$write = function (string $code) use ($view): void { file_put_contents($view, "<?php\nuse MyPHP\\UI\\Context;\n$code"); touch($view, time() + random_int(1, 1000)); };
$ask = function (string ...$files) use ($trigger): void { file_put_contents($trigger, implode("\n", $files) . "\n# " . microtime(true)); ViewFile::check(); };
$lastLog = fn() => Logger::line(Logger::entries()[count(Logger::entries()) - 1]);

$write('$add = function (): void { $this->n++; };
return function (Context $c) use ($add): void {
    $c->text("{$this->title}: " . self::LABEL . " {$this->n}");
    if ($c->clicked($c->button("+"))) { $add(); }
};');
$s = new ViewFileState;
$content = Content::file($view, $s);
$h = new Headless(fn(Context $c) => ($content->view)($c));
$h->frame();
check($h->byText('Counter: count 0') !== null, 'bound to the state: $this, a private property and self::: ' . json_encode($h->texts()));
$h->clickText('+'); $h->frame();
same(1, $s->n, 'its helper closures are bound too');

$write('return function (Context $c): void { $c->text("now {$this->n}"); };');
$ask($view);
$h->frame();
check($h->byText('now 1') !== null, 'a hot reload: the new code, the state as it was: ' . json_encode($h->texts()));

$write('return function (Context $c): void { $c->text("broken" };');
$ask($view);
$h->frame();
check($h->byText('now 1') !== null && str_contains($lastLog(), '[E]') && str_contains($lastLog(), 'did not load'), 'a file that fails to load keeps the view it had, the error said: ' . $lastLog());

$write('final class Declared {} return function (Context $c): void { $c->text("class"); };');
$ask($view);
$h->frame();
check($h->byText('now 1') !== null && str_contains($lastLog(), 'declares class Declared'), 'a named class is not loaded twice: said: ' . $lastLog());

$write('return function (Context $c): void { $c->text("all {$this->n}"); };');
$ask('*');
$h->frame();
check($h->byText('all 1') !== null, '* loads every view of a file again (r with nothing saved)');

$ask("$dir/other.php");
check(str_contains($lastLog(), 'other.php') && str_contains($lastLog(), 'restart'), 'another file: a restart takes it: ' . $lastLog());

same(['class A', 'function f', 'enum E'], ViewFile::declared('<?php class A { function m() {} } function f() {} enum E {} $x = new class {}; $y = function () {}; A::class;'), 'the named declarations at the top level, not anonymous ones');
putenv('MYPHP_HOT_RELOAD');
