<?php
// An HTTP client: method, URL, headers and body in, status, headers and
// body out, every request kept in a list. Built to shake out text input,
// lists and network together: curl runs as a child process (Process) so
// the window stays live while a request is in flight.
// Run: ./run.sh http
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Process, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

final class Http
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
    public const SHOW = 256 * 1024; // bytes of a body shown; the rest is counted
    public int $method = 0;
    public string $url = 'https://httpbin.org/get', $headers = "Accept: application/json", $body = '';
    /** @var list<array{id: int, method: string, url: string, status: ?int, time: float, headers: string, body: string, error: string, at: int, lines: list<array{string, string}>}> newest first; lines: what the response pane lists, [kind, text] */
    public array $history = [];
    /** The id of the request chosen, whose response shows. */
    public ?int $chosen = null;
    /** The header the history is sorted by. */
    public ?SortOrder $sortOrder = null;
    public ?Process $inFlight = null;
    private int $nextId = 0;
    public ?Window $window = null;

    /** Reorders the history by the table's sort; the chosen request stays chosen, by its id. */
    public function sort(): void
    {
        $by = $this->sortOrder?->column; $desc = $this->sortOrder?->descending ?? false;
        $key = fn(array $r) => match ($by) { 'method' => $r['method'], 'status' => $r['status'] ?? -1, 'time' => $r['time'], default => $r['url'] };
        usort($this->history, fn($a, $b) => $desc ? $key($b) <=> $key($a) : $key($a) <=> $key($b));
    }

    /** The request chosen, or null. */
    public function shown(): ?array
    {
        foreach ($this->history as $r) { if ($r['id'] === $this->chosen) { return $r; } }
        return null;
    }

    public function send(): void
    {
        if ($this->inFlight !== null) {
            return;
        }
        $method = self::METHODS[$this->method];
        $url = trim($this->url);
        $argv = ['curl', '-sS', '-i', '-L', '--max-time', '30', '-X', $method, '-w', "\n\x1f%{http_code} %{time_total}"];
        foreach (preg_split('/\R/', $this->headers) as $h) {
            if (trim($h) !== '') { $argv[] = '-H'; $argv[] = trim($h); }
        }
        $stdin = '';
        if ($this->body !== '' && !in_array($method, ['GET', 'HEAD'], true)) {
            $argv[] = '--data-binary'; $argv[] = '@-'; $stdin = $this->body;
        }
        $argv[] = $url;
        $id = ++$this->nextId;
        array_unshift($this->history, ['id' => $id, 'method' => $method, 'url' => $url, 'status' => null, 'time' => 0.0, 'headers' => '', 'body' => '', 'error' => '', 'at' => time(), 'lines' => []]);
        $this->chosen = $id;
        $this->inFlight = Process::run($argv, $stdin, function (string $out, string $err, int $code) use ($id) {
            $this->inFlight = null;
            $r = &$this->history[array_search($id, array_column($this->history, 'id'), true)]; // the table may have sorted it away from the top
            if ($code !== 0) {
                $r['error'] = trim($err) !== '' ? trim($err) : "curl exited with $code";
            } else {
                [$out, $trailer] = explode("\x1f", $out, 2) + [1 => ''];
                [$status, $time] = explode(' ', trim($trailer)) + [1 => '0'];
                $r['status'] = (int)$status; $r['time'] = (float)$time;
                $out = substr($out, 0, -1); // the newline before the trailer
                // -L leaves a header block per hop; the last is the response's.
                while (str_starts_with($out, 'HTTP/') && ($at = strpos($out, "\r\n\r\n")) !== false) {
                    $r['headers'] = substr($out, 0, $at);
                    $out = substr($out, $at + 4);
                    if (!str_starts_with($out, 'HTTP/')) { break; }
                }
                $r['body'] = $out;
            }
            $r['lines'] = lines($r);
            $this->window->invalidate();
        });
    }
}

/** What the response pane lists for $r: the status row, the headers, a divider, the body line by line (JSON pretty-printed), cut at Http::SHOW. */
function lines(array $r): array
{
    if ($r['error'] !== '') {
        return [['error', $r['error']]];
    }
    $lines = [['status', '']];
    foreach (explode("\r\n", $r['headers']) as $hl) {
        $lines[] = ['header', $hl];
    }
    $lines[] = ['divider', ''];
    $body = $r['body'];
    $json = preg_match('#^content-type:\s*application/(\w+\+)?json#im', $r['headers']) === 1 && ($decoded = json_decode($body)) !== null;
    if ($json) {
        $body = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $cut = strlen($body) > Http::SHOW;
    if ($cut) {
        $body = substr($body, 0, Http::SHOW);
    }
    foreach (explode("\n", $body) as $bl) {
        $lines[] = [$json ? 'json' : 'text', $bl];
    }
    if ($cut) {
        $lines[] = ['note', 'showing the first ' . size(Http::SHOW) . ' of ' . size(strlen($r['body'])) . '; Copy body takes it all'];
    }
    return $lines;
}

function size(int $b): string
{
    return $b >= 1 << 20 ? sprintf('%.1f MB', $b / (1 << 20)) : ($b >= 1024 ? sprintf('%.1f KB', $b / 1024) : "$b B");
}

$h = new Http;
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('Request', submenu: [new MenuItem('Send', fn() => $h->send(), 'CmdOrCtrl+Return'), MenuItem::separator(), new MenuItem(role: 'close')]),
    new MenuItem('Edit', submenu: [new MenuItem(role: 'undo'), new MenuItem(role: 'cut'), new MenuItem(role: 'copy'), new MenuItem(role: 'paste'), new MenuItem(role: 'selectAll')]),
    new MenuItem(role: 'windowMenu'),
]));
App::setName('HTTP');
App::whenReady(function () use ($h) {
    $h->window = new Window(new WindowOptions(title: 'HTTP', width: 1000, height: 680, stateKey: 'http',
        content: Content::file(__DIR__ . '/view.php', $h)));
});
App::run();
