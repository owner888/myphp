<?php
// Loads MyPHP: from ./myphp inside a built app, else from $MYPHP_HOME, which
// myphp dev and myphp generate set, else from myphp.json's framework or Composer's vendor/owner888/myphp.
declare(strict_types=1);

// loaded already in a phone's app, whose launcher loads the framework it carries
if (class_exists(\MyPHP\App::class)) {
    return;
}
$configured = is_file(__DIR__ . '/myphp.json') ? (json_decode((string)file_get_contents(__DIR__ . '/myphp.json'), true)['framework'] ?? null) : null;
if (is_string($configured) && $configured !== '' && !str_starts_with($configured, '/')) {
    $configured = __DIR__ . '/' . $configured;
}
$framework = is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp'
    : (is_dir(__DIR__ . '/src') && is_file(__DIR__ . '/src/autoload.php') ? __DIR__
    : (getenv('MYPHP_HOME') ?: ($configured ?: (is_dir(__DIR__ . '/vendor/owner888/myphp') ? __DIR__ . '/vendor/owner888/myphp' : __DIR__))));
if (is_file($framework . '/src/autoload.php')) {
    require_once $framework . '/src/autoload.php';
}
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
