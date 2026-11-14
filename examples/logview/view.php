<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Device;

/** Line $line in its level's color, the part matching $needle highlighted. */
$line = function (string $line, string $needle): View {
    $color = str_contains($line, 'ERROR') ? Color::red : (str_contains($line, 'WARN') ? Color::orange : Color::primary);
    if ($needle === '' || ($at = stripos($line, $needle)) === false) { return Text(verbatim: $line)->foregroundStyle($color); }
    return Text([
        Text(verbatim: substr($line, 0, $at))->foregroundStyle($color),
        Text(verbatim: substr($line, $at, strlen($needle)))->foregroundStyle(Color::black)->background(Color::yellow),
        Text(verbatim: substr($line, $at + strlen($needle)))->foregroundStyle($color),
    ]);
};

return fn() => ScrollViewReader(fn(ScrollViewProxy $proxy) => VStack(spacing: 0)(
    HStack(spacing: 10)(
        TextField('Filter lines (type to search)', text: $this->filter)->frame(width: Device::isDesktop() ? 320 : 180),
        $this->path === ''
            ? Text([Text('Open a log with '), Text('File > Open…')->bold()])->foregroundStyle(Color::secondary)
            : Text([
                Text(verbatim: number_format(count($this->lines)) . ' lines' . ($this->filter === '' ? '' : ', ' . number_format(count($this->shown)) . ' match'))->foregroundStyle(Color::secondary),
                Text(verbatim: '  ERROR ' . $this->counts['ERROR'])->bold()->foregroundStyle(Color::red),
                Text(verbatim: '  WARN ' . $this->counts['WARN'])->bold()->foregroundStyle(Color::orange),
            ]),
        Spacer(),
    )->padding(10),
    Divider(),
    // A table: the number column and the line, which wraps at the column's width; the header's edge resizes it.
    Table($this->shown, id: fn(int $i) => $i, selection: $this->chosen)(
        TableColumn('#', fn(int $i) => Text(verbatim: (string)($i + 1))->foregroundStyle(Color::secondary))->width(ideal: 72)->alignment(HorizontalAlignment::trailing),
        TableColumn('Line', fn(int $i) => $line($this->lines[$i], $this->filter)),
    )
        ->font(Font::caption)->monospaced()
        ->defaultScrollAnchor(UnitPoint::bottom) // follows the log as it grows
        ->id($this->generation) // a log opened or filtered anew starts again from its end
        // The find bar: Cmd+F opens it; Return, its arrows and Cmd+G step through the lines holding the query, choosing each.
        ->findNavigator(isPresented: $this->finding, text: $this->find, matches: count($this->hits()), current: $this->found)
        ->onChange(of: [$this->find, $this->found], action: function () use ($proxy) {
            $hit = $this->hits()[$this->found] ?? null;
            if ($hit !== null) { $this->chosen = $hit; $proxy->scrollTo($hit, anchor: UnitPoint::center); }
        }),
))->onChange(of: $this->filter, action: $this->refilter(...));
