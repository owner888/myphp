<?php
// Loads MyPHP: from ./myphp inside a built app, else from $MYPHP_HOME, which
// myphp dev and myphp generate set, else from the checkout myphp.json names.
declare(strict_types=1);

$framework = is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp'
    : (getenv('MYPHP_HOME') ?: json_decode(file_get_contents(__DIR__ . '/myphp.json'), true)['framework']);
require $framework . '/src/autoload.php';
