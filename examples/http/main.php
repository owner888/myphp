<?php
// An HTTP client: method, URL, headers and body in, status, headers and
// body out, every request kept in a list. Built to shake out text input,
// lists and network together: curl runs as a child process (Process) so
// the window stays live while a request is in flight.
// Run: ./run.sh http
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Process, Window, WindowOptions};
use MyPHP\Native\Pasteboard;
use MyPHP\UI\{Content, Context, Font, Key, ListState, Node, Span, TableColumn, TextState, Theme};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

final class Http
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
    public const SHOW = 256 * 1024; // bytes of a body shown; the rest is counted
    public int $method = 0;
    public TextState $url, $headers, $body;
    /** @var list<array{id: int, method: string, url: string, status: ?int, time: float, headers: string, body: string, error: string, at: int, lines: list<array{string, string}>}> newest first; lines: what the response pane lists, [kind, text] */
    public array $history = [];
    public ListState $list, $lines;
    /** @var list<TableColumn> */
    public array $columns;
    public ?Process $inFlight = null;
    private int $nextId = 0;
    public ?Window $window = null;

    public function __construct()
    {
        $this->url = new TextState('https://httpbin.org/get');
        $this->headers = new TextState("Accept: application/json");
        $this->body = new TextState('');
        $this->list = new ListState; $this->lines = new ListState;
        $this->columns = [new TableColumn('Method', 64, sortable: true), new TableColumn('Status', 56, sortable: true), new TableColumn('Time', 60, align: Node::END, sortable: true), new TableColumn('URL', minWidth: 120)];
    }

    /** Reorders the history by the table's sort, keeping the chosen request chosen. */
    public function sort(): void
    {
        [$by, $desc] = $this->list->sort;
        $chosen = $this->history[$this->list->selected ?? -1] ?? null;
        $key = fn(array $r) => match ($by) { 'Method' => $r['method'], 'Status' => $r['status'] ?? -1, 'Time' => $r['time'], default => $r['url'] };
        usort($this->history, fn($a, $b) => $desc ? $key($b) <=> $key($a) : $key($a) <=> $key($b));
        if ($chosen !== null) {
            $this->list->choose((int)array_search($chosen, $this->history, true));
        }
    }

    public function send(): void
    {
        if ($this->inFlight !== null) {
            return;
        }
        $method = self::METHODS[$this->method];
        $url = trim($this->url->text);
        $argv = ['curl', '-sS', '-i', '-L', '--max-time', '30', '-X', $method, '-w', "\n\x1f%{http_code} %{time_total}"];
        foreach (preg_split('/\R/', $this->headers->text) as $h) {
            if (trim($h) !== '') { $argv[] = '-H'; $argv[] = trim($h); }
        }
        $stdin = '';
        if ($this->body->text !== '' && !in_array($method, ['GET', 'HEAD'], true)) {
            $argv[] = '--data-binary'; $argv[] = '@-'; $stdin = $this->body->text;
        }
        $argv[] = $url;
        $id = ++$this->nextId;
        array_unshift($this->history, ['id' => $id, 'method' => $method, 'url' => $url, 'status' => null, 'time' => 0.0, 'headers' => '', 'body' => '', 'error' => '', 'at' => time(), 'lines' => []]);
        $this->list->choose(0);
        $this->lines->scrollTo(0, false);
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
            $this->window->update();
        });
    }
}

function view(Context $c, Http $h): void
{
    $t = $c->theme;
    if ($c->shortcut(Key::RETURN, Key::COMMAND) || $h->url->submitted) { $h->url->submitted = false; $h->send(); }
    $c->row(0, 1, 1, $t->border)->align(Node::STRETCH);
      // History
      $c->column(0, 0, 0, $t->surface)->size(320);
        $c->row(10, 8); $c->text('Requests', 15, style: Font::BOLD); $c->spacer(); $c->muted(count($h->history) . ''); $c->end();
        // A table of the requests: the headers sort them, their edges resize the columns.
        if ($h->list->sortChanged()) { $h->sort(); }
        $c->table($h->list, $h->columns, count($h->history), function (int $i, int $j) use ($c, $t, $h) {
            $r = $h->history[$i];
            switch ($j) {
                case 0: $c->text($r['method'], 12, $t->textMuted, Font::BOLD | Font::MONO); break;
                case 1:
                    $color = $r['error'] !== '' ? $t->danger : ($r['status'] === null ? $t->textMuted : ($r['status'] < 400 ? $t->success : $t->danger));
                    $c->text($r['status'] === null ? ($r['error'] !== '' ? 'failed' : '…') : (string)$r['status'], 12, $color, Font::BOLD | Font::MONO);
                    break;
                case 2: $c->muted($r['time'] > 0 ? sprintf('%.0f ms', $r['time'] * 1000) : '', 11); break;
                default: $c->text(preg_replace('#^https?://#', '', $r['url']), 12)->tooltip($r['url']);
            }
        });
      $c->end();
      // Request and response
      $c->column(16, 10, 1, $t->background);
        $c->row(0, 8);
          $c->select(Http::METHODS, $h->method, 90);
          $c->input($h->url, 'https://…', 0); // 0: the rest of the row
          $send = $c->button($h->inFlight === null ? 'Send' : 'Sending…')->tooltip('Cmd+Enter');
          if ($c->clicked($send)) { $h->send(); }
        $c->end();
        $c->row(0, 10)->align(Node::STRETCH);
          $c->column(0, 4, 1); $c->muted('Headers, one a line', 11); $c->textarea($h->headers, 'Name: value', 0, 72, 12); $c->end();
          $c->column(0, 4, 1); $c->muted('Body', 11); $c->textarea($h->body, 'sent with POST, PUT, PATCH', 0, 72, 12); $c->end();
        $c->end();
        $c->divider();
        response($c, $h);
      $c->end();
    $c->end();
}

function response(Context $c, Http $h): void
{
    $t = $c->theme;
    $i = $h->list->selected;
    if ($i === null || !isset($h->history[$i])) {
        $c->column(0, 0, 1)->center(); $c->muted('Send a request (Cmd+Enter); responses show here.'); $c->end();
        return;
    }
    if ($h->list->changed()) { $h->lines->scrollTo(0, false); }
    $r = $h->history[$i];
    if ($r['status'] === null && $r['error'] === '') {
        $c->row(0, 8); $c->spinner(); $c->muted('waiting for ' . $r['url']); $c->end();
        return;
    }
    $width = $c->width - 320 - 1 - 32;
    // The response is listed line by line, so a body of any size scrolls at frame rate: only the lines in view are shaped.
    $c->list($h->lines, count($r['lines']), function (int $n) use ($c, $t, $h, $r, $width) {
        [$kind, $text] = $r['lines'][$n];
        switch ($kind) {
            case 'status':
                $c->row(0, 10);
                  $c->text((string)$r['status'], 15, $r['status'] < 400 ? $t->success : $t->danger, Font::BOLD);
                  $c->muted(sprintf('%.0f ms · %s', $r['time'] * 1000, size(strlen($r['body']))));
                  $c->spacer();
                  if ($c->clicked($c->button('Copy body'))) { Pasteboard::setText($r['body']); }
                $c->end();
                break;
            case 'error': $c->rich([new Span($text, color: $t->danger, style: Font::MONO)], 12, width: $width, wrap: true); break;
            case 'header': $c->rich([new Span($text, color: $t->textMuted, style: Font::MONO)], 11, width: $width, wrap: true); break;
            case 'divider': $c->column(0, 4); $c->divider(); $c->end(); break;
            case 'json': $c->rich(jsonSpans($text, $t), 12, width: $width, wrap: true); break;
            case 'note': $c->muted($text, 11); break;
            default: $c->rich([new Span($text === '' ? ' ' : $text, color: $t->text, style: Font::MONO)], 12, width: $width, wrap: true);
        }
    });
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

/** A line of pretty-printed JSON as spans colored by token: keys, strings, numbers and literals, punctuation. */
function jsonSpans(string $line, Theme $t): array
{
    if ($line === '') {
        return [new Span(' ')];
    }
    preg_match_all('/"(?:\\\\.|[^"\\\\])*"(\s*:)?|-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?|true|false|null|[^"\d\-tfn]+|./', $line, $m, PREG_SET_ORDER);
    $spans = [];
    foreach ($m as $tok) {
        $s = $tok[0];
        $color = match (true) {
            isset($tok[1]) && $tok[1] !== '' => $t->accent,          // a key
            $s[0] === '"' => $t->success,                              // a string
            $s === 'true', $s === 'false', $s === 'null' => $t->warning,
            is_numeric($s) => $t->warning,
            default => $t->textMuted,
        };
        $spans[] = new Span($s, color: $color, style: Font::MONO);
    }
    return $spans;
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
        content: Content::view(fn(Context $c) => view($c, $h))));
});
App::run();
