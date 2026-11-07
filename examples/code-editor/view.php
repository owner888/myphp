<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\Editor\{DiffInline, DiffLayout};

/** The editor of $f, and its status bar: where the caret is, the changes, the lines and the language. */
$editorPage = function (File $f) {
    $st = $f->editor;
    $pos = $st->cursorPos();
    $sels = count($st->selections());
    [$added, $deleted] = $f->diff->stats();
    return VStack(spacing: 0)(
        CodeEditor($st)->id($f->name)->defaultFocus() // each file's editor its own: it shows, its TODOs are found
            ->onAppear($f->check(...))->onChange(of: $st->buffer->version(), action: $f->check(...)),
        Divider(),
        HStack(spacing: 16)(
            Text(verbatim: sprintf('Ln %d, Col %d', $pos->line + 1, $pos->col + 1) . ($sels > 1 ? sprintf(' · %d selections', $sels) : '')),
            Text(verbatim: sprintf('+%d −%d', $added, $deleted)),
            Spacer(),
            Text(verbatim: sprintf('%d lines · %s', $st->buffer->lineCount(), $st->buffer->language()?->name ?? 'Plain Text')),
        )->font(Font::caption)->foregroundStyle(Color::secondary)->padding(Edge::horizontal, 12)->padding(Edge::vertical, 4),
    );
};

/** The changes of $f, split or unified, as the toolbar says. */
$diffPage = function (File $f) {
    $d = $f->diff;
    $d->layout = $this->layout === 1 ? DiffLayout::DiffUnified : DiffLayout::DiffSplit;
    $d->inline = [DiffInline::DiffInlineWordAlt, DiffInline::DiffInlineChar, DiffInline::DiffInlineNone][$this->inline];
    return $d->hunks() === []
        ? VStack(spacing: 6)(
            Text('No changes')->font(Font::title3)->bold(),
            Text(verbatim: 'Edit ' . $f->name . ' to see its changes here.')->foregroundStyle(Color::secondary),
        )->frame(maxWidth: INF, maxHeight: INF)
        : DiffView($d);
};

return function () use ($editorPage, $diffPage) {
    $f = $this->current();
    $f->editor->softWrap = $f->diff->wrap = $this->wrap;
    return NavigationSplitView(
        sidebar: ListView(selection: $this->selected)(
            Each($this->files, id: 'name')(fn(File $f) => Text(verbatim: $f->name . ($f->editor->buffer->modified() ? ' •' : ''))->tag($f->name)),
        ),
        detail: VStack(spacing: 0)(
            HStack(spacing: 12)(
                Picker('Page', selection: $this->page)(Text('Editor')->tag(0), Text('Changes')->tag(1))->pickerStyle(PickerStyle::segmented),
                $this->page === 1 ? Picker('Layout', selection: $this->layout)(Text('Split')->tag(0), Text('Unified')->tag(1))->pickerStyle(PickerStyle::segmented) : null,
                $this->page === 1 ? Picker('Within lines', selection: $this->inline)(Text('Words')->tag(0), Text('Characters')->tag(1), Text('Off')->tag(2))->labelsHidden() : null,
                Spacer(),
                Toggle('Wrap', isOn: $this->wrap)->toggleStyle(ToggleStyle::switch)->accessibilityLabel('Soft wrap'),
            )->padding(Edge::horizontal, 12)->padding(Edge::vertical, 8),
            Divider(),
            $this->page === 0 ? $editorPage($f) : $diffPage($f),
        ),
    );
};
