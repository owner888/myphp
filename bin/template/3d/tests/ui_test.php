<?php
// Builds the 3D view headless and tests its interactions: myphp test
declare(strict_types=1);

require (getenv('MYPHP_HOME') ?: throw new RuntimeException('run the tests with: myphp test')) . '/tests/Headless.php';
require __DIR__ . '/../src/SceneState.php';

use MyPHP\Tests\Headless;
use MyPHP\UI\Content;
use function MyPHP\Tests\{check, same};

$state = new SceneState();
$h = new Headless(Content::file(__DIR__ . '/../view.php', $state)->view, 800, 600);
$h->frame(2);
$h->clickText('Spark Burst');
$h->frame(2);
same(1, $state->taps, 'Spark Burst increments tap count');
check($state->sparks->activeCount() === 40, 'Spark Burst spawns 40 3D particles');
\MyPHP\UI\CanvasAtlas::take();
exit(empty($GLOBALS['failures']) ? 0 : 1);