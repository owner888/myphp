<?php
// Loads Composer's autoloader if present, then MyPHP, as a Flutter project finds its SDK: the one installed on the
// machine, which the myphp command (dev, run, test, screenshot, build) names in $MYPHP_HOME; inside a built app, the
// copy in ./myphp; or the checkout myphp.json's "framework" names, for a project that pins one of its own.
declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
$configured = is_file(__DIR__ . '/myphp.json') ? (json_decode((string)file_get_contents(__DIR__ . '/myphp.json'), true)['framework'] ?? null) : null;
if (is_string($configured) && $configured !== '' && !str_starts_with($configured, '/')) {
    $configured = __DIR__ . '/' . $configured;
}
$framework = is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp'
    : (is_dir(__DIR__ . '/src') && is_file(__DIR__ . '/src/autoload.php') ? __DIR__
    : (getenv('MYPHP_HOME') ?: ($configured ?: null)));
if ($framework !== null && is_file($framework . '/src/autoload.php')) {
    require_once $framework . '/src/autoload.php';
} elseif (!class_exists(\MyPHP\App::class)) {
    throw new RuntimeException('MyPHP was not found: run the app and its tests through the myphp command (myphp dev, myphp run, myphp test), or set MYPHP_HOME to a MyPHP checkout');
}
