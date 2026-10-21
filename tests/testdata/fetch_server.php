<?php
// The server of fetch_test.php (mygo's httptest mux), run by php -S.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/echo':
        header('X-Multi: a', false);
        header('X-Multi: b', false);
        header('X-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? ''));
        header('X-Host: ' . ($_SERVER['HTTP_HOST'] ?? ''));
        header('X-Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? '(none)'));
        http_response_code(201);
        echo $_SERVER['REQUEST_METHOD'] . ' ' . file_get_contents('php://input');
        break;
    case '/big':
        echo str_repeat('x', 3 * 65536 / 2);
        break;
    case '/redirect':
        header('Location: /echo', true, 302);
        break;
    case '/gzip':
        header('Content-Encoding: gzip');
        echo gzencode('unzipped');
        break;
    case '/slow':
        while (ob_get_level() > 0) { ob_end_flush(); } // the CLI server buffers what is echoed
        echo 'first';
        flush();
        sleep(5);
        break;
    default:
        http_response_code(404);
}
