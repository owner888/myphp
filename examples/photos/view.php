<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

$size = function (string $f): string {
    $b = filesize($f);
    return $b >= 1 << 20 ? sprintf('%.1f MB', $b / (1 << 20)) : sprintf('%d KB', intdiv($b, 1024));
};

/** The folder's photos in a grid: only the rows that show are built, so only their thumbnails are wanted. Return, a double click or Space shows one. */
$grid = function () {
    $chosen = new Binding(get: fn() => $this->files[$this->selected] ?? null, set: fn(?string $f) => $this->select((int)array_search($f, $this->files, true)));
    return LazyVGrid(columns: [GridItem::adaptive(minimum: Photos::THUMB)], selection: $chosen)(
        Each($this->files)(fn(string $f) => (($img = $this->thumbnail($f)) !== null ? Image($img)->resizable()->scaledToFit() : Rectangle()->fill(Color::clear)) // its place kept while it decodes
            ->frame(maxWidth: INF, height: Photos::THUMB - 4)->padding(2)->background(Color::gray->opacity(0.12))->cornerRadius(6)->help(basename($f))),
    )->contextMenu(primaryAction: fn() => $this->viewing = true)
        ->onKeyPress(KeyEquivalent::space, fn() => $this->viewing = true);
};

/** One photo, as large as the window lets it, no larger than its pixels: the arrows go to its neighbors, Escape, Return, Space or a double click back to the grid. */
$viewer = function () use ($size) {
    $f = $this->files[$this->selected];
    $img = $this->shown();
    $back = function () { $this->viewing = false; };
    return VStack(spacing: 8)(
        $img === null ? Spacer() : Image($img)->resizable()->scaledToFit()
            ->frame(maxWidth: $img->w / $this->scale * ($this->full[$f] ?? null ? 1 : Photos::FULL / max($img->w, $img->h)), maxHeight: INF)
            ->onTapGesture($back, count: 2),
        HStack(spacing: 12)(
            Text(verbatim: basename($f))->foregroundStyle(Color::white),
            Text(verbatim: sprintf('%d / %d · %s', $this->selected + 1, count($this->files), $size($f)))->font(Font::caption)->foregroundStyle(Color::gray),
        ),
    )->padding(Edge::bottom, 12)->frame(maxWidth: INF, maxHeight: INF)->background(Color::black)
        ->onKeyPress(KeyEquivalent::leftArrow, fn() => $this->select($this->selected - 1))
        ->onKeyPress(KeyEquivalent::rightArrow, fn() => $this->select($this->selected + 1))
        ->onKeyPress(KeyEquivalent::escape, $back)->onKeyPress(KeyEquivalent::return, $back)->onKeyPress(KeyEquivalent::space, $back);
};

return function () use ($grid, $viewer) {
    if ($this->files === []) {
        return VStack(spacing: 8)(
            Text('No photos')->font(Font::title3)->bold(),
            Text(verbatim: $this->dir === '' ? 'Open a folder (Cmd+O) or drop one on the window.' : "No images in {$this->dir}.")->foregroundStyle(Color::secondary),
        );
    }
    $this->wanted = []; // the grid's rows as they show want their thumbnails
    return $this->viewing ? $viewer() : $grid();
};
