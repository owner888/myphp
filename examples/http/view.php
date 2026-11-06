<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Native\Pasteboard;
use MyPHP\UI\{Context, Font, Key, Node, Span, Theme};

$response = function (Context $c, Http $h) use (&$jsonSpans): void {
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
    $c->list($h->lines, count($r['lines']), function (int $n) use ($c, $t, $h, $r, $width, $jsonSpans) {
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
            case 'error': $c->richText([new Span($text, color: $t->danger, style: Font::MONO)], 12, width: $width, wrap: true); break;
            case 'header': $c->richText([new Span($text, color: $t->textMuted, style: Font::MONO)], 11, width: $width, wrap: true); break;
            case 'divider': $c->column(0, 4); $c->divider(); $c->end(); break;
            case 'json': $c->richText($jsonSpans($text, $t), 12, width: $width, wrap: true); break;
            case 'note': $c->muted($text, 11); break;
            default: $c->richText([new Span($text === '' ? ' ' : $text, color: $t->text, style: Font::MONO)], 12, width: $width, wrap: true);
        }
    });
};

/** A line of pretty-printed JSON as spans colored by token: keys, strings, numbers and literals, punctuation. */
$jsonSpans = function (string $line, Theme $t): array {
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
};

return function (Context $c) use (&$response): void {
    $t = $c->theme;
    if ($c->shortcut(Key::RETURN, Key::COMMAND) || $this->url->submitted) { $this->url->submitted = false; $this->send(); }
    $c->row(0, 1, 1, $t->border)->alignItems(Node::STRETCH);
      // History
      $c->column(0, 0, 0, $t->surface)->size(320);
        $c->row(10, 8); $c->text('Requests', 15, style: Font::BOLD); $c->spacer(); $c->muted(count($this->history) . ''); $c->end();
        // A table of the requests: the headers sort them, their edges resize the columns.
        if ($this->list->sortChanged()) { $this->sort(); }
        $c->table($this->list, $this->columns, count($this->history), function (int $i, int $j) use ($c, $t) {
            $r = $this->history[$i];
            switch ($j) {
                case 0: $c->text($r['method'], 12, $t->textMuted, Font::BOLD | Font::MONO); break;
                case 1:
                    $color = $r['error'] !== '' ? $t->danger : ($r['status'] === null ? $t->textMuted : ($r['status'] < 400 ? $t->success : $t->danger));
                    $c->text($r['status'] === null ? ($r['error'] !== '' ? 'failed' : '…') : (string)$r['status'], 12, $color, Font::BOLD | Font::MONO);
                    break;
                case 2: $c->muted($r['time'] > 0 ? sprintf('%.0f ms', $r['time'] * 1000) : '', 11); break;
                default: $c->text(preg_replace('#^https?://#', '', $r['url']), 12)->singleLine()->tooltip($r['url']);
            }
        });
      $c->end();
      // Request and response
      $c->column(16, 10, 1, $t->background);
        $c->row(0, 8);
          $c->select(Http::METHODS, $this->method, 90);
          $c->input($this->url, 'https://…', 0); // 0: the rest of the row
          $send = $c->primaryButton($this->inFlight === null ? 'Send' : 'Sending…')->tooltip('Cmd+Enter');
          if ($c->clicked($send)) { $this->send(); }
        $c->end();
        $c->row(0, 10)->alignItems(Node::STRETCH);
          $c->column(0, 4, 1); $c->muted('Headers, one a line', 11); $c->textarea($this->headers, 'Name: value', 0, 72, 12); $c->end();
          $c->column(0, 4, 1); $c->muted('Body', 11); $c->textarea($this->body, 'sent with POST, PUT, PATCH', 0, 72, 12); $c->end();
        $c->end();
        $c->divider();
        $response($c, $this);
      $c->end();
    $c->end();
};
