<?php
// Custom schemes served by PHP (mygo's Protocol): the app's frontend at
// myphp://localhost/ (App::setFrontend, the frontend folder here), images
// a "shapes" scheme draws at run time, a body streamed a chunk at a time,
// a download served by PHP, and a bound method the page may call, being
// the app's own.
// Run: ./run.sh protocol
declare(strict_types=1);

use MyPHP\{App, Ipc, Protocol, ProtocolRequest, ProtocolResponse, Window, WindowOptions};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** What the page asks PHP. */
final class Shapes
{
    /** The shapes the scheme draws. @return list<string> */
    public function names(): array
    {
        return ['circle', 'square', 'triangle'];
    }
}

// shapes://localhost/circle.svg?hue=200: an SVG drawn per request.
Protocol::handle('shapes', function (ProtocolRequest $r): ProtocolResponse {
    $hue = (int)($r->query()['hue'] ?? 210);
    $name = basename($r->path(), '.svg');
    if ($name === 'ticks') { // streamed: a line a turn of the run loop
        return new ProtocolResponse(200, ['Content-Type' => 'text/plain; charset=utf-8'], (function () {
            for ($i = 1; $i <= 5; $i++) { yield "tick $i at " . date('H:i:s') . "\n"; }
        })());
    }
    if ($name === 'report') { // a download PHP serves
        return new ProtocolResponse(200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="shapes.csv"'], "shape,hue\ncircle,$hue\n");
    }
    $fill = "hsl($hue 70% 55%)";
    $shape = match ($name) {
        'circle' => "<circle cx='50' cy='50' r='40' fill='$fill'/>",
        'square' => "<rect x='12' y='12' width='76' height='76' rx='10' fill='$fill'/>",
        'triangle' => "<path d='M50 10 L90 88 L10 88 Z' fill='$fill'/>",
        default => null,
    };
    return $shape === null
        ? new ProtocolResponse(404, ['Content-Type' => 'text/plain'], "no such shape\n")
        : new ProtocolResponse(200, ['Content-Type' => 'image/svg+xml'], "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'>$shape</svg>");
});
App::setFrontend(__DIR__ . '/frontend');
Ipc::bind(new Shapes);

App::whenReady(function () {
    new Window(new WindowOptions(title: 'Protocol', width: 640, height: 520, url: '/'));
});
App::run();
