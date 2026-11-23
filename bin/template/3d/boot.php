<?php
// Loads Composer's autoloader if present, then MyPHP from ./myphp inside a built app,
// $MYPHP_HOME in dev/screenshot, or myphp.json's framework, prepended ahead of vendor/.
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
}
