<?php
// Loads MyPHP: from ./myphp inside a built app, else from $MYPHP_HOME, which
// myphp dev and myphp generate set to the checkout they belong to.
declare(strict_types=1);

// loaded already in a phone's app, whose launcher loads the framework it carries
if (class_exists(\MyPHP\App::class)) {
    return;
}
$framework = is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp' : getenv('MYPHP_HOME');
is_string($framework) && $framework !== '' || exit("Run the app with myphp dev, or set MYPHP_HOME to the MyPHP checkout.\n");
require $framework . '/src/autoload.php';
