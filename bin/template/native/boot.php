<?php
// Loads MyPHP: from ./myphp inside a built app, else from $MYPHP_HOME, which
// myphp dev and myphp generate set, else from the checkout myphp.json names.
declare(strict_types=1);

// loaded already in a phone's app, whose launcher loads the framework it carries
if (class_exists(\MyPHP\App::class)) {
    return;
}
$framework = is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp'
    : (is_dir(__DIR__ . '/src') && is_file(__DIR__ . '/src/autoload.php') ? __DIR__
    : (getenv('MYPHP_HOME') ?: (is_file(__DIR__ . '/myphp.json') ? json_decode((string)file_get_contents(__DIR__ . '/myphp.json'), true)['framework'] : __DIR__)));
require $framework . '/src/autoload.php';
