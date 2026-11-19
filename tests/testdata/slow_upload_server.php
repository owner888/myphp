<?php
// A server that reads a request's body slowly (32 KB every 10 ms), so that an upload is seen going out in pieces, and answers
// 200 when it is in; and sends GET /download/<bytes> slowly (32 KB every 10 ms), so that a download is seen coming in pieces.
// For tests/url_session_progress_test.php. Usage: php slow_upload_server.php <port>
declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:' . (int)($argv[1] ?? 0), $errno, $error);
if ($server === false) {
    fwrite(STDERR, "cannot listen: $error\n");
    exit(1);
}
while (($c = @stream_socket_accept($server, 30)) !== false) {
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && ($b = fread($c, 1)) !== false && $b !== '') {
        $head .= $b;
    }
    if (preg_match('~^GET /download/(\d+)~', $head, $m) === 1) {
        $size = (int)$m[1];
        fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: $size\r\nConnection: close\r\n\r\n");
        for ($sent = 0; $sent < $size; $sent += 32768) {
            if (@fwrite($c, str_repeat('d', min(32768, $size - $sent))) === false) { break; }
            usleep(10000);
        }
        fclose($c);
        continue;
    }
    $length = preg_match('/^content-length:\s*(\d+)/mi', $head, $m) === 1 ? (int)$m[1] : 0;
    for ($read = 0; $read < $length;) {
        $chunk = fread($c, min(32768, $length - $read));
        if ($chunk === false || $chunk === '') { break; }
        $read += strlen($chunk);
        usleep(10000);
    }
    fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
    fclose($c);
}
