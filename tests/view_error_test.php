<?php
// UI\ViewError, the development error screen: a view that throws builds the error into a fresh context in its place —
// its message, where it was thrown — and the log says it once, not every frame; a view that builds again is shown.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Logger;
use MyPHP\UI\{Context, Theme, ViewError};

$h = new Headless(fn(Context $c) => null, 600, 400);
$make = fn(): Context => new Context($h->metrics, $h->rt, 600, 400, Theme::light());
$texts = function (Context $c): array { $out = []; $walk = function ($n) use (&$walk, &$out) { if ($n->text !== null && $n->text !== '') { $out[] = $n->text; } foreach ($n->kids as $k) { $walk($k); } }; $walk($c->root); $walk = null; return $out; };
$errors = fn(): int => count(array_filter(Logger::entries(), fn(array $e) => str_contains(Logger::line($e), 'the window shows it until the view')));

$broken = function (Context $c): void { $c->column(); $c->text('half built'); throw new \RuntimeException('no such method labelsHidden'); }; $thrownAt = __LINE__;
$before = $errors();
$c = ViewError::guard($make, $broken);
$shown = $texts($c);
check(in_array('RuntimeException: no such method labelsHidden', $shown, true), 'the error screen shows the class and message: ' . json_encode($shown, JSON_UNESCAPED_UNICODE));
check(!in_array('half built', $shown, true), 'and nothing of the view half built before it threw');
check(array_filter($shown, fn(string $t) => str_ends_with($t, "view_error_test.php:$thrownAt")) !== [], 'and where it was thrown');
ViewError::guard($make, $broken);
same(1, $errors() - $before, 'a view throwing every frame is logged once');
$c = ViewError::guard($make, function (Context $c): void { $c->text('fixed'); });
same(['fixed'], $texts($c), 'the view, fixed, shows again');
ViewError::guard($make, $broken);
same(2, $errors() - $before, 'and throwing again after a good frame is logged again');
