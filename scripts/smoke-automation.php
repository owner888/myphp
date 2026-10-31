<?php
// Drives examples/automation on a Mac through the automation plugin and
// reports each check: the plugin against real windows (windows, tree,
// act, input, menu, capture, eval, idle, errors, quitting), a node
// ignoring input at rest, and a window made hidden that shows once drawn.
// It saves the main window's capture as smoke-automation.png, whose
// rings, border and shadow show the shapes drawn in bands.
//
//     php -d ffi.enable=1 scripts/smoke-automation.php
//
// Exits 1 when a check fails.
declare(strict_types=1);

$root = dirname(__DIR__);
$sock = sys_get_temp_dir() . '/myphp-smoke-' . getmypid() . '.sock';
$png = getcwd() . '/smoke-automation.png';
$failed = 0;

function report(bool $ok, string $what): void
{
    global $failed;
    echo ($ok ? "  ok    " : "  FAIL  ") . "$what\n";
    if (!$ok) { $failed++; }
}

// The app, with the socket named.
$env = ['MYPHP_AUTOMATION' => $sock] + getenv();
$app = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', "$root/examples/automation/main.php"], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root, $env);
if ($app === false) {
    fwrite(STDERR, "cannot start examples/automation\n");
    exit(1);
}
$quit = function () use ($app): void {
    if (proc_get_status($app)['running']) { proc_terminate($app); }
};
register_shutdown_function($quit);
for ($i = 0; $i < 200 && !file_exists($sock); $i++) {
    if (!proc_get_status($app)['running']) { fwrite(STDERR, "the app ended before it listened\n"); exit(1); }
    usleep(100_000);
}
$conn = @stream_socket_client("unix://$sock", $errno, $err, 5.0);
if ($conn === false) {
    fwrite(STDERR, "no socket at $sock: $err\n");
    exit(1);
}
stream_set_timeout($conn, 30);

$id = 0;
/** The answer to a request: its result, or ['error' => …]; the app gone, the run stops there. */
$call = function (string $method, array $params = []) use ($conn, &$id): array {
    $line = @fwrite($conn, json_encode(['id' => ++$id, 'method' => $method, 'params' => (object)$params], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false ? false : fgets($conn);
    if ($line === false) {
        fwrite(STDERR, "the app is gone (no answer to $method): its error is above\n");
        exit(1);
    }
    $m = json_decode($line, true);
    return isset($m['error']) ? ['error' => $m['error']] : (array)$m['result'];
};
$code = fn(array $r): string => $r['error']['code'] ?? '';

echo "windows\n";
$wins = [];
for ($i = 0; $i < 30; $i++) { // the hidden one shows once its first frame is drawn
    $wins = array_column($call('windows')['windows'] ?? [], null, 'title');
    if (($wins['Shown when ready']['visible'] ?? false) && isset($wins['Automation'], $wins['Automation page'])) { break; }
    usleep(100_000);
}
if (!isset($wins['Automation'], $wins['Automation page'])) {
    fwrite(STDERR, 'the windows are not there: ' . json_encode(array_keys($wins)) . "\n");
    exit(1);
}
$main = $wins['Automation']['id'] ?? 0;
$page = $wins['Automation page']['id'] ?? 0;
report($main !== 0 && ($wins['Automation']['kind'] ?? '') === 'content', 'the main window, of native UI');
report($page !== 0 && ($wins['Automation page']['kind'] ?? '') === 'page', 'the page, as a page');
report(($wins['Shown when ready']['visible'] ?? false) === true, 'a window made hidden shows once its first frame is drawn (readyToShow)');

$idle = fn(int $w = 0) => $call('idle', ['window' => $w ?: $main, 'timeout' => 5000]);
/** @return array{0: ?array, 1: list<array>} the node of $key, and the nodes */
$tree = function (?string $key = null) use ($call, &$main): array {
    $nodes = $call('tree', ['window' => $main])['nodes'] ?? [];
    foreach ($nodes as $n) { if ($key !== null && ($n['key'] ?? null) === $key) { return [$n, $nodes]; } }
    return [null, $nodes];
};
$shows = function (string $text) use ($tree): bool {
    foreach ($tree()[1] as $n) { if (($n['label'] ?? '') === $text) { return true; } }
    return false;
};
$middle = fn(?array $n): array => $n === null ? [0.0, 0.0] : [$n['bounds']['x'] + $n['bounds']['width'] / 2, $n['bounds']['y'] + $n['bounds']['height'] / 2];

echo "tree and act\n";
report($code($idle()) === '', 'idle answers once the window settled');
[$add, $nodes] = $tree('add');
report($add !== null && $add['role'] === 'button' && in_array('press', $add['actions'] ?? [], true), 'the button keyed add, which presses');
report($shows('Count: 0'), 'the text Count: 0');
$call('act', ['window' => $main, 'key' => 'add', 'action' => 'press']);
$idle();
report($shows('Count: 1'), 'act press by key counts');
[$add] = $tree('add');
$call('act', ['window' => $main, 'id' => $add['id'], 'action' => 'press']);
$idle();
report($shows('Count: 2'), 'act press by ID counts');

echo "input\n";
[$x, $y] = $middle($add);
$call('input', ['window' => $main, 'events' => [['type' => 'click', 'x' => $x, 'y' => $y]]]);
$idle();
report($shows('Count: 3'), 'a click on the button counts');
$call('act', ['window' => $main, 'key' => 'name', 'action' => 'focus']);
$idle();
$call('input', ['window' => $main, 'events' => [['type' => 'text', 'text' => 'Ada'], ['type' => 'key', 'key' => 'Backspace'], ['type' => 'key', 'key' => 'a']]]);
$idle();
[$name] = $tree('name');
report(($name['value'] ?? null) === 'Ad' && $shows('Hello, Ad'), 'typed Ada, Backspace took the a, a key typed nothing: ' . json_encode($name['value'] ?? null));

echo "a node ignoring input\n";
[$del] = $tree('delete');
report($del !== null && in_array('press', $del['actions'] ?? [], true) && !in_array('focus', $del['actions'] ?? [], true), 'Delete at rest: assistive technology presses it, not focuses it');
$call('input', ['window' => $main, 'events' => [['type' => 'pointerMove', 'x' => 400, 'y' => 380]]]);
$idle();
[$dx, $dy] = $middle($del);
$call('input', ['window' => $main, 'events' => [['type' => 'click', 'x' => $dx, 'y' => $dy]]]);
$idle();
report($shows('Row clicks: 1, deletes: 0'), "a click on Delete at rest is the row's");
$call('input', ['window' => $main, 'events' => [['type' => 'pointerMove', 'x' => $dx, 'y' => $dy]]]);
$idle();
$call('input', ['window' => $main, 'events' => [['type' => 'click', 'x' => $dx, 'y' => $dy]]]);
$idle();
report($shows('Row clicks: 1, deletes: 1'), 'shown on hover, Delete takes the click');
$call('input', ['window' => $main, 'events' => [['type' => 'pointerMove', 'x' => 400, 'y' => 380]]]);
$idle();
$call('act', ['window' => $main, 'key' => 'delete', 'action' => 'press']);
$idle();
report($shows('Row clicks: 1, deletes: 2'), 'at rest, assistive technology presses it');

echo "menu, capture, eval\n";
$call('menu', ['path' => ['Actions', 'Reset']]);
$idle();
report($shows('Count: 0'), 'Actions > Reset');
report($code($call('menu', ['path' => ['Actions', 'Nothing']])) === 'failed', 'a menu item that is not there fails');
$cap = $call('capture', ['window' => $main]);
$bytes = base64_decode($cap['png'] ?? '');
report(($cap['width'] ?? 0) > 0 && ($cap['height'] ?? 0) > 0 && str_starts_with($bytes, "\x89PNG"), "capture: a PNG of {$cap['width']}×{$cap['height']}");
file_put_contents($png, $bytes);
echo "        (saved as $png: look at the rings, the border and the shadow)\n";
report($code($idle($page)) === '', 'idle on the page');
$title = $call('eval', ['window' => $page, 'script' => 'document.title']);
report(($title['value'] ?? null) === 'Automation page', 'eval in the page: ' . json_encode($title['value'] ?? $title));
report($code($call('eval', ['window' => $page, 'script' => 'throw new Error("no")'])) === 'failed', 'a script that throws fails');

echo "errors\n";
report($code($call('tree', ['window' => $page])) === 'unsupported', 'a page has no tree');
report($code($call('eval', ['window' => $main, 'script' => '1'])) === 'unsupported', 'native UI has no eval');
report($code($call('tree', ['window' => 9999])) === 'no_window', 'no window 9999');
report($code($call('act', ['window' => $main, 'key' => 'nope', 'action' => 'press'])) === 'no_element', 'no element of key nope');
report($code($call('fly')) === 'unknown_method', 'no method fly');

echo "quitting\n";
fwrite($conn, json_encode(['id' => ++$id, 'method' => 'menu', 'params' => ['path' => ['Actions', 'Quit']]]) . "\n");
for ($i = 0; $i < 100 && proc_get_status($app)['running']; $i++) { usleep(100_000); }
report(!proc_get_status($app)['running'], 'Actions > Quit ends the app');
clearstatcache();
report(!file_exists($sock), 'and its socket is gone');

echo $failed === 0 ? "all checks passed\n" : "$failed failed\n";
echo "By hand: ./run.sh video [film] still opens, plays and shows its settings (the player renamed MoviePlayer in v1.118).\n";
exit($failed === 0 ? 0 : 1);
