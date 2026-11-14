<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Clipboard;
use MyPHP\Device;

/** A line of pretty-printed JSON, its tokens colored: keys, strings, numbers and literals, punctuation. */
$json = function (string $line): View {
    preg_match_all('/"(?:\\\\.|[^"\\\\])*"(\s*:)?|-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?|true|false|null|[^"\d\-tfn]+|./', $line, $m, PREG_SET_ORDER);
    return Text(array_map(fn(array $tok) => Text(verbatim: $tok[0])->foregroundStyle(match (true) {
        isset($tok[1]) && $tok[1] !== '' => Color::accentColor,                  // a key
        $tok[0][0] === '"' => Color::green,                                       // a string
        in_array($tok[0], ['true', 'false', 'null'], true), is_numeric($tok[0]) => Color::orange,
        default => Color::secondary,
    }), $m ?: [['']]));
};

/** The response of request $r, line by line: only the lines in view are built, so a body of any size scrolls at frame rate. */
$response = fn(?array $r) => match (true) {
    $r === null => Text('Send a request (Cmd+Enter); responses show here.')->foregroundStyle(Color::secondary)->frame(maxWidth: INF, maxHeight: INF),
    $r['status'] === null && $r['error'] === '' => HStack(spacing: 8)(ProgressView(), Text(verbatim: 'waiting for ' . $r['url'])->foregroundStyle(Color::secondary))->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading),
    default => ScrollView()(LazyVStack(alignment: HorizontalAlignment::leading, spacing: 2)(
        Each($r['lines'], id: fn($l, int $i) => $i)(fn(array $l) => match ($l[0]) {
            'status' => HStack(spacing: 10)(
                Text(verbatim: (string)$r['status'])->font(Font::system(size: 15, weight: Weight::bold))->monospaced()->foregroundStyle($r['status'] < 400 ? Color::green : Color::red),
                Text(verbatim: sprintf('%.0f ms · %s', $r['time'] * 1000, size(strlen($r['body']))))->foregroundStyle(Color::secondary),
                Spacer(),
                Button('Copy body', action: fn() => Clipboard::writeText($r['body'])),
            )->padding(Edge::bottom, 6),
            'error' => Text(verbatim: $l[1])->monospaced()->foregroundStyle(Color::red),
            'header' => Text(verbatim: $l[1])->font(Font::caption)->monospaced()->foregroundStyle(Color::secondary),
            'divider' => Divider()->padding(Edge::vertical, 4),
            'json' => $json($l[1])->monospaced(),
            'note' => Text(verbatim: $l[1])->font(Font::caption)->foregroundStyle(Color::secondary),
            default => Text(verbatim: $l[1] === '' ? ' ' : $l[1])->monospaced(),
        }),
    ))->font(Font::callout)->id($r['id'] . '/' . count($r['lines'])), // from the top for each response
};

$historyPane = fn() => VStack(spacing: 0)(
    HStack()(Text('Requests')->font(Font::headline), Spacer(), Text(verbatim: (string)count($this->history))->foregroundStyle(Color::secondary))->padding(10),
    Table($this->history, id: 'id', selection: $this->chosen, sortOrder: $this->sortOrder)(
        TableColumn('Method', fn(array $r) => Text(verbatim: $r['method'])->bold()->foregroundStyle(Color::secondary), value: 'method')->width(ideal: 64),
        TableColumn('Status', fn(array $r) => Text(verbatim: $r['status'] === null ? ($r['error'] !== '' ? 'failed' : '…') : (string)$r['status'])->bold()
            ->foregroundStyle($r['error'] !== '' ? Color::red : ($r['status'] === null ? Color::secondary : ($r['status'] < 400 ? Color::green : Color::red))), value: 'status')->width(ideal: 56),
        TableColumn('Time', fn(array $r) => Text(verbatim: $r['time'] > 0 ? sprintf('%.0f ms', $r['time'] * 1000) : '')->foregroundStyle(Color::secondary), value: 'time')->width(ideal: 60)->alignment(HorizontalAlignment::trailing),
        TableColumn('URL', fn(array $r) => Text(verbatim: preg_replace('#^https?://#', '', $r['url']))->lineLimit(1)->help($r['url']), value: 'url')->width(min: 120),
    )->font(Font::caption)->monospaced()->onChange(of: $this->sortOrder, action: $this->sort(...)),
)->background(Color::gray->opacity(0.08));

$editorPane = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
    HStack(spacing: 8)(
        Picker('Method', selection: $this->method)(Each(Http::METHODS)(fn(string $m, int $i) => Text(verbatim: $m)->tag($i)))->labelsHidden(),
        TextField('https://…', text: $this->url)->onSubmit($this->send(...)),
        Button($this->inFlight === null ? 'Send' : 'Sending…', action: $this->send(...))->buttonStyle(ButtonStyle::borderedProminent)->help('Cmd+Enter'),
    ),
    HStack(alignment: VerticalAlignment::top, spacing: 10)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(Text('Headers, one a line')->font(Font::caption)->foregroundStyle(Color::secondary), TextField('Name: value', text: $this->headers, axis: Axis::vertical)->lineLimit(3, reservesSpace: true)->monospaced()),
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(Text('Body')->font(Font::caption)->foregroundStyle(Color::secondary), TextField('sent with POST, PUT, PATCH', text: $this->body, axis: Axis::vertical)->lineLimit(3, reservesSpace: true)->monospaced()),
    ),
    Divider(),
    $response($this->shown()),
)->padding(16)->frame(maxWidth: INF, maxHeight: INF);

return fn() => Device::isDesktop()
    ? HStack(spacing: 0)(
        $historyPane()->frame(width: 320),
        Divider(),
        $editorPane(),
    )
    : VStack(spacing: 0)(
        $editorPane(),
        Divider(),
        $historyPane()->frame(height: 220),
    );
