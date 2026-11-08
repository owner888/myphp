<?php
// Builds the view headless, with no window or display, and drives it as a
// user would: php -d ffi.enable=1 tests/ui_test.php
declare(strict_types=1);

$framework = getenv('MYPHP_HOME') ?: json_decode(file_get_contents(__DIR__ . '/../myphp.json'), true)['framework'];
require "$framework/tests/Headless.php";
require __DIR__ . '/../src/Counter.php';

use MyPHP\Tests\Headless;
use MyPHP\UI\Content;
use function MyPHP\Tests\{check, same};

$state = new Counter();
$h = new Headless(Content::file(__DIR__ . '/../view.php', $state)->view, 420, 260);
$h->frame();
$h->clickText('Increment');
$h->frame();
same(1, $state->count, 'Increment counts');
check($h->byText('Count: 1') !== null, 'the count shows: ' . json_encode($h->texts()));
$h->clickText('Reset');
$h->frame();
same(0, $state->count, 'Reset sets it back');
exit(empty($GLOBALS['failures']) ? 0 : 1);
