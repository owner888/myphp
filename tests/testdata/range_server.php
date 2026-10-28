<?php
// The server of http_bytes_test.php, run by php -S: the files of the folder
// MYPHP_FILES names, as a server of films sends them: a range asked for
// (206, Content-Range), unless ?norange (all of it, 200); ?rate=N sends N
// bytes a second; ?cut=N breaks the connection after N bytes of each
// response; ?fail answers 404.
$root = (string)getenv('MYPHP_FILES');
$path = $root . '/' . rawurldecode(basename((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
if (isset($q['fail']) || !is_file($path)) {
    http_response_code(404);
    echo 'not found';
    return;
}
$size = filesize($path);
[$from, $to] = [0, $size - 1];
if (!isset($q['norange']) && preg_match('~^bytes=(\d+)-(\d*)$~', $_SERVER['HTTP_RANGE'] ?? '', $m) === 1) {
    [$from, $to] = [(int)$m[1], $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1)];
    http_response_code(206);
    header("Content-Range: bytes $from-$to/$size");
}
header('Accept-Ranges: bytes');
header('Content-Type: application/octet-stream');
header('Content-Length: ' . ($to - $from + 1));
while (ob_get_level() > 0) { ob_end_flush(); }
$f = fopen($path, 'rb');
fseek($f, $from);
$left = $to - $from + 1;
$rate = isset($q['rate']) ? (int)$q['rate'] : 0;
$chunk = $rate > 0 ? max(1, intdiv($rate, 20)) : 65536;
if (isset($q['cut'])) { $left = min($left, (int)$q['cut']); } // the response cut short of its length: a connection that broke
while ($left > 0 && !connection_aborted()) {
    $s = fread($f, min($chunk, $left));
    if ($s === false || $s === '') { break; }
    echo $s;
    flush();
    $left -= strlen($s);
    if ($rate > 0) { usleep(50000); }
}
