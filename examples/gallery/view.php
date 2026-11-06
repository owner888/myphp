<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\{App, Clipboard, DateOptions, Dialog, I18n, MessageOptions, NumberOptions, Style as DateStyle, Width, Window};
use MyPHP\Plugins\Glass\{Blur, Glass, ScrollEdge, Style as GlassStyle};
use MyPHP\Text\Params;
use MyPHP\UI\{Anchor, Animation, Oklch, BorderStyle, GradientStop, RadialGradient, ContentTransition, Context, Cursor, Direction, DraggableOptions, Easing, ElementTransition, Font, Keyframe, KeyframeOptions, Key, LinearGradient, Menu, MeterLevels, Motion, Node, Painter, Path, Pose, Route, Span, SpringOptions, SVG, TableColumn, TextState, Theme, Track, TransitionMotion, WideColor};
use MyPHP\UI\Chart\{Axes, Band, Chart, Curve, Linear, Stacking, Tick, Time};
use MyPHP\UI\Markdown\{Markdown, Reveal};
use MyPHP\UI\RichText\{Document, EditorOptions, FormatState, ListKind, RichText};

$page = function (Context $c, Route $r) use (&$listRow, &$overview, &$controls, &$richText, &$list, &$charts, &$markdownPage, &$styling, &$drawing, &$glassPage, &$overlays, &$motion, &$languages): void {
    if ($r->match('/list/{row}')) {
        $listRow($c, $r);
        return;
    }
    $page = pageOf($r->path());
    if ($page === '') {
        $r->title('Not found');
        $c->text('Not found', 26, style: Font::BOLD);
        return;
    }
    $r->title($page);
    $c->text($page, 26, style: Font::BOLD);
    match ($page) {
        'Overview' => $overview($c),
        'Controls' => $controls($c),
        'Text' => $this->text($c),
        'Rich Text' => $richText($c),
        'List' => $list($c),
        'Charts' => $charts($c),
        'Markdown' => $markdownPage($c),
        'Styling' => $styling($c),
        'Drawing' => $drawing($c),
        'Glass' => $glassPage($c),
        'Overlays' => $overlays($c),
        'Motion' => $motion($c),
        'Languages' => $languages($c),
    };
};

/**
 * Goes back and forward in the history of the pages, and shows the
 * path of the page: Cmd+[ and Cmd+], and a mouse's side buttons, go
 * back and forward too.
 */
$toolbar = function (Context $c): void {
    $c->toolbar(function () use ($c): void {
        $c->backButton($this->router);
        $c->forwardButton($this->router);
        $page = pageOf($this->router->path());
        $path = [$page];
        $prefix = pagePath($page) . '/';
        if (str_starts_with($this->router->path(), $prefix)) { $path[] = 'Row ' . substr($this->router->path(), strlen($prefix)); }
        $chosen = -1;
        if ($c->changed($c->breadcrumbs($path, $chosen)->label('Path'))) { $this->router->push(pagePath($page)); }
        $c->spacer();
        $c->toggle($this->rtl, 'Right to left')->tooltip('Lay the gallery out right to left');
    })->label('Navigation')->padding(8, 24, 0);
};

/** A row of the list, from the List page: the arrows in the toolbar, or the side buttons of a mouse, go back to the list as it was. */
$listRow = function (Context $c, Route $r): void {
    $t = $c->theme;
    $param = $r->param('row');
    $n = ctype_digit($param) ? (int)$param : -1;
    if ($n < 0 || $n >= 10000) {
        $r->title('Not found');
        $c->text('Not found', 26, style: Font::BOLD);
        return;
    }
    $r->title("Row $n");
    $c->text("Row $n", 26, style: Font::BOLD);
    card($c, '')->maxWidth(420);
      foreach (['Square' => $n * $n, 'Cube' => $n * $n * $n] as $label => $value) {
          $c->row(0, 12);
            $c->text($label, color: $t->textMuted)->size(80, 0);
            $c->text((string)$value)->font('monospace');
          $c->end();
      }
      $starred = $this->starred[$n] ?? false;
      if ($c->changed($c->checkbox('Starred', $starred))) { $this->starred[$n] = $starred; }
    $c->end();
    // Links to paths go there in the router, relative to the page as on the web.
    $c->row(0, 16);
      if ($n > 0) { $c->link('← Previous row', (string)($n - 1)); }
      if ($n < 9999) { $c->link('Next row →', (string)($n + 1)); }
      $c->link('All rows', '/list');
    $c->end();
};

$sidebar = function (Context $c): void {
    $t = $c->theme;
    $c->column(0, 0, 0, $t->surface)->size(200, 0)->paddingY(16)->shrink(0);
      $c->text('MyPHP UI', 13, $t->textMuted, Font::BOLD)->padding(4, 20, 6);
      // The pages, in two sections that hide and show; the arrows choose among them while the sidebar has the focus.
      $page = pageOf($this->router->path());
      $side = $c->sidebar($page, function () use ($c): void {
          foreach ([['Widgets', array_slice(PAGES, 0, 6)], ['Look', array_slice(PAGES, 6)]] as $k => [$title, $pages]) {
              $c->sidebarSection($title, $this->sectionsOpen[$k], function () use ($c, $pages): void {
                  foreach ($pages as $p) {
                      $c->sidebarItem($p, $p, pageIcon($p), $p === 'List' ? fn() => $c->badge('10k') : null);
                  }
              });
          }
      });
      $side->grow(1)->label('Pages');
      if ($c->changed($side)) { $this->router->push(pagePath($page)); }
      $c->text($this->now->format('H:i:s'), 12, $t->textMuted)->padding(0, 20);
    $c->end();
};

$overview = function (Context $c) use (&$sparkline): void {
    $t = $c->theme;
    $c->text('Everything here is laid out with flexbox and grids and drawn by MyPHP itself: no HTML, no JavaScript, no extension but FFI. '
        . 'The view is a PHP function of the app\'s state that runs again after every event.', color: $t->textMuted);
    $c->row(0, 16)->wrap(0)->alignItems(Node::START);
      card($c, 'Counter');
        $c->text((string)$this->count, 40, style: Font::BOLD);
        $c->row(0, 8);
          if ($c->clicked($c->button('−')->size(44, 0))) { $this->count--; }
          if ($c->clicked($c->primaryButton('Increment'))) { $this->count++; }
        $c->end();
      $c->end();
      card($c, 'Live data');
        $c->text('A timer pushes a sample every second with Window::update.', color: $t->textMuted)->maxWidth(260);
        $sparkline($c)->size(260, 80);
      $c->end();
      card($c, 'Files');
        $zone = $c->column(0, 2, 0, $t->surface)->size(260, 80)->padding(8, 12)->radius(6)->border(1, $t->border)->justify(Node::CENTER)->alignItems(Node::CENTER);
        if (($files = $c->droppedFiles($zone)) !== null) { $this->files = $files; }
        if ($c->fileDragOver($zone)) { $zone->border(2, $t->accent); }
          if ($this->files === []) { $c->text('Drop files here', color: $t->textMuted); }
          foreach ($this->files as $i => $f) {
              if ($i === 3) {
                  $c->text(sprintf('and %d more', count($this->files) - $i), 12, $t->textMuted);
                  break;
              }
              $c->text(basename($f), 12)->maxLines(1);
          }
        $c->end();
      $c->end();
    $c->end();
};

$sparkline = function (Context $c): Node {
    $t = $c->theme;
    return $c->box(0, 0, $t->surface)->radius(6)->draw(function (Painter $p, array $r) use ($t): void {
        $n = count($this->samples);
        if ($n < 2) { return; }
        $path = new Path();
        foreach ($this->samples as $i => $v) {
            $x = $r[0] + 6 + $i / ($n - 1) * ($r[2] - 12);
            $y = $r[1] + $r[3] - 6 - $v * ($r[3] - 12);
            $i === 0 ? $path->moveTo($x, $y) : $path->lineTo($x, $y);
        }
        $p->strokePath($path, 2, $t->accent);
    });
};

$controls = function (Context $c): void {
    $t = $c->theme;
    card($c, 'Choices');
      $c->checkbox('I agree to the terms', $this->agree);
      $c->row(0, 10);
        $c->switch('', $this->notify)->label('Notifications'); // named for assistive technology, as mygo's: the text after it shows
        $c->text($this->notify ? 'Notifications on' : 'Notifications off');
      $c->end();
      $c->radioGroup(function () use ($c): void {
          foreach (['Free', 'Pro', 'Team'] as $p) { $c->radio($p, $this->plan, $p); }
      }, row: true)->label('Plan')->gap = 18;
      $c->row(0, 10)->alignItems(Node::CENTER);
        $c->text('Size');
        $c->select(SIZES, $this->size)->label('Size');
      $c->end();
    $c->end();
    card($c, 'Search and choose');
      $c->row(0, 10)->wrap(0);
        $c->searchField($this->search, 220)->label('Search');
        $c->combobox($this->font, ['Avenir', 'Courier', 'Futura', 'Georgia', 'Gill Sans', 'Helvetica', 'Menlo', 'Optima', 'Palatino', 'Times'], 200)->label('Font');
        $c->autocomplete($this->city, ['Amsterdam', 'Berlin', 'Lisbon', 'London', 'Madrid', 'Paris', 'Prague', 'Rome', 'Vienna'], 'City', 200)->label('City');
      $c->end();
      $c->tokenField($this->tags, ['design', 'native', 'performance', 'php', 'release', 'typescript'])->label('Tags');
      $c->text('Type to filter; Up and Down move, Enter chooses. In the tags, Enter or a comma adds a tag, and Backspace takes out the last.', color: $t->textMuted);
    $c->end();
    card($c, 'Drag and drop');
      if ($this->tasks === []) {
          $this->tasks = [new GalleryTask('Write the docs'), new GalleryTask('Fix the layout'), new GalleryTask('Ship it'), new GalleryTask('Plan the release', true)];
      }
      $c->row(0, 12)->alignItems(Node::START);
        foreach (['To do', 'Done'] as $col => $title) {
            $done = $col === 1;
            $bin = $c->column(10, 6, 1, $t->surface)->basis(0)->radius(8)->minHeight(150)->border(1, $t->border)->label($title);
            $task = null;
            if ($c->drop($bin, GalleryTask::class, $task)) { $task->done = $done; }
            if ($c->dragOver($bin, GalleryTask::class, $task) && $task->done !== $done) { $bin->border(2, $t->accent); }
              $c->text($title)->fontWeight(600);
              foreach ($this->tasks as $task) {
                  if ($task->done !== $done) { continue; }
                  $row = $c->key($task->name)->row(0, 0, 0, $t->background)->padding(7, 10)->radius(6)->border(1, $t->border)->cursor(Cursor::Pointer);
                  $c->drag($row, $task);
                    $c->text($task->name);
                  $c->end();
              }
            $c->end();
        }
      $c->end();
      $c->text('Drag a task to the other column; Escape gives up.', 12, $t->textMuted);
    $c->end();
    card($c, 'Groups and paths');
      $c->checkboxGroup('Notifications', function () use ($c): void {
          $c->checkbox('Mail', $this->notifyMail);
          $c->checkbox('Calendar', $this->notifyCalendar);
          $c->checkbox('Messages', $this->notifyMessages);
      });
      $path = ['Macintosh HD', 'Users', 'ada', 'Documents', 'Reports'];
      if ($c->changed($c->breadcrumbs(array_slice($path, 0, $this->pathDepth + 1), $this->pathAt)->label('Path'))) { $this->pathDepth = $this->pathAt; }
      if ($this->pathDepth < count($path) - 1 && $c->clicked($c->button('Open ' . $path[$this->pathDepth + 1]))) { $this->pathDepth++; }
    $c->end();
    card($c, 'Disclosure');
      $c->collapsible('Advanced options', $this->advanced, function () use ($c, $t): void {
          $c->checkbox('Verbose logging', $this->verbose);
          $c->text('The arrow turns and the content grows into view, at once where the desktop asks for less motion.', color: $t->textMuted);
      });
      $c->accordion(function () use ($c, $t): void {
          $c->accordionItem('General', $this->sections[0], fn() => $c->text('Startup, appearance and updates.'));
          $c->accordionItem('Privacy', $this->sections[1], fn() => $c->checkbox('Share usage data', $this->share));
          $c->accordionItem('Keyboard', $this->sections[2], fn() => $c->text('Up and Down move between the headers, as do Home and End; Enter and Space open and close them.', color: $t->textMuted));
      });
    $c->end();
    card($c, 'Ranges');
      $c->row(0, 12)->alignItems(Node::CENTER);
        $c->slider($this->volume, 0, 100, 0)->label('Volume')->grow(1);
        $c->text(sprintf('%3.0f%%', $this->volume))->size(48, 0)->textAlign(Node::END);
      $c->end();
      $c->progress($this->volume / 100, 0)->fillWidth();
      $c->progress(-1, 0)->fillWidth();
      $c->row(0, 10)->alignItems(Node::CENTER);
        $c->text('Copies');
        $c->numberInput($this->copies, 1, 99, 1)->label('Copies');
      $c->end();
      $c->row(0, 12)->alignItems(Node::CENTER);
        $c->text('Quality')->size(64, 0);
        $c->stepSlider($this->quality, 0, 100, 25, 0)->label('Quality')->grow(1);
        $c->text(sprintf('%3.0f%%', $this->quality))->size(48, 0)->textAlign(Node::END);
      $c->end();
      $c->row(0, 12)->alignItems(Node::CENTER);
        $c->text('Price')->size(64, 0);
        $c->rangeSlider($this->priceLow, $this->priceHigh, 0, 500, 10)->label('Price')->grow(1);
        $c->text(sprintf('$%.0f–%.0f', $this->priceLow, $this->priceHigh))->size(72, 0)->textAlign(Node::END);
      $c->end();
    $c->end();
    card($c, 'Dates, times and colors');
      $c->row(0, 20)->alignItems(Node::START)->wrap(0);
        $c->calendar($this->meeting)->label('Meeting');
        $c->column(0, 12);
          $c->form(function () use ($c): void {
              $c->field('Time', fn() => $c->timeInput($this->meeting)->label('Meeting'));
              $c->field('Tint', fn() => $c->colorWell($this->tint, $this->tintAlpha));
              $c->field('Due', function () use ($c): void {
                  $chip = $c->button($this->due->format('M j'));
                  if ($c->clicked($chip)) { $this->dueOpen = !$this->dueOpen; }
                  // Browse pages through the months without choosing, so the popover closes only on a day picked.
                  $c->popover($chip, $this->dueOpen, function () use ($c): void {
                      if ($c->changed($c->calendar($this->due)->browse())) { $this->dueOpen = false; }
                  });
              });
          });
          $c->text($this->meeting->format('l, F j \a\t H:i'), color: $this->tint)->alpha = $this->tintAlpha;
        $c->end();
      $c->end();
    $c->end();
    card($c, 'Indicators');
      $c->row(0, 12)->alignItems(Node::CENTER);
        $c->spinner()->label('Syncing');
        $c->text('Syncing…', color: $t->textMuted)->grow(1);
        $c->rating($this->stars, 5)->label('Rating');
      $c->end();
      $disk = 412.0;
      $c->text(sprintf('Disk: %.0f of 500 GB', $disk), 12, $t->textMuted);
      $c->meter($disk, 0, 500, new MeterLevels(400, 475), 0)->label('Disk')->fillWidth();
      $c->text(sprintf('Battery: %.0f%%', $this->battery), 12, $t->textMuted);
      $c->row(0, 10)->alignItems(Node::CENTER);
        $c->meter($this->battery, 0, 100, new MeterLevels(20, 10), 0)->label('Battery')->grow(1);
        $c->stepper($this->battery, 0, 100, 5)->label('Battery');
      $c->end();
      $c->row(0, 8);
        foreach (['Ada Lovelace', 'Grace Hopper', 'Alan Turing', 'Margaret Hamilton'] as $name) { $c->avatar($name)->tooltip($name); }
      $c->end();
    $c->end();
    card($c, 'Buttons');
      $c->row(0, 8)->wrap(0);
        if ($c->clicked($c->primaryButton('Save'))) { $c->toast('Saved'); }
        $c->button('Cancel');
        $c->button('Disabled')->disabled(true);
        $c->menuButton('Export', function (Menu $m) use ($c): void {
            foreach (['PDF', 'PNG', 'SVG'] as $as) {
                if ($m->item("As $as")->chosen()) { $c->toast("Exported as $as"); }
            }
        });
        $c->link('Open myphp on GitHub', 'https://github.com/owner888/myphp');
      $c->end();
      $c->text('Tab moves the focus; Enter or Space presses the focused button.', color: $t->textMuted);
    $c->end();
    card($c, 'Toolbar');
      $c->toolbar(function () use ($c): void {
          $c->button('New');
          $c->button('Open');
          $c->toggleGroup(function () use ($c): void {
              $c->toggle($this->bold, 'Bold');
              $c->toggle($this->italic, 'Italic');
              $c->toggle($this->underline, 'Underline');
          })->label('Style');
          $c->segmented(['List', 'Grid', 'Columns'], $this->layout)->label('View');
          $c->spacer();
          $c->toggle($this->inspector, 'Inspector');
      })->label('Document')->border(1, $t->border)->radius(8);
      $c->text('Tab stops once in the toolbar, and the arrows move between its controls. Narrow the window: what does not fit goes into the » menu.', color: $t->textMuted);
    $c->end();
    card($c, 'Tabs and panes');
      $c->tabs(['Files', 'Search', 'History'], $this->tab);
      $c->split($this->split, function () use ($c, $t): void {
          $c->column(10, 6, 1, $t->surface)->fill();
            foreach ([['index.php', 'composer.json', 'README.md'], ['Results'], ['Yesterday', 'Last week']][$this->tab] as $name) { $c->text($name)->singleLine(); }
          $c->end();
      }, function () use ($c, $t): void {
          $c->column(10, 0, 1)->fill();
            $c->text('Drag the divider, or focus it and press the arrows.', color: $t->textMuted);
          $c->end();
      })->size(0, 140)->border(1, $t->border)->radius($t->radius)->clip();
    $c->end();
    card($c, 'Built on bases');
      $c->text('Bases are the widgets without their look: the pointer, the keys, the focus and accessibility, styled here anew.', color: $t->textMuted);
      $c->row(0, 16)->wrap(0);
        // A segmented control on tabsBase.
        $tabs = $c->tabsBase($this->period, 3);
        $tabs->list->padding(3)->radius(999)->background($t->surface);
          foreach (['Day', 'Week', 'Month'] as $i => $name) {
              $seg = $tabs->tab($i)->padding(5, 14)->radius(999);
              if ($i === $this->period) { $seg->background($t->background)->shadow(0, 1, 2, 0, 0x000000, 0.15); }
                $c->text($name);
              $c->end();
          }
        $c->end();
        // A pill that toggles, on switchBase.
        $pill = $c->switchBase($this->pinned)->padding(5, 12)->radius(999)->border(1, $t->border);
        $pill->gap = 6;
        if ($this->pinned) { $pill->background($t->accent)->textColor($t->accentText)->border(1, $t->accent); }
          $c->icon(glyph('star'));
          $c->text('Starred');
        $c->end();
        // A select with check marks, on selectBase.
        $sel = $c->selectBase($this->fruit);
        $sel->trigger->padding(6, 10)->radius(8)->border(1, $t->border)->gap = 6;
          $c->text($this->fruit);
          $c->icon(glyph('chevron'), null, $t->textMuted);
        $c->end();
        $sel->popup(function (Node $panel) use ($c, $t, $sel): void {
            $panel->margin(4, 0, 0, 0)->padding(4)->radius(10)->background($t->background)->border(1, $t->border)->shadow(0, 8, 24, 0, 0x000000, 0.15);
            foreach (['Apple', 'Banana', 'Cherry', 'Durian'] as $fruit) {
                $item = $sel->item($fruit)->padding(6, 10)->radius(6);
                $item->gap = 8;
                if ($item->highlighted) { $item->background($t->accent)->textColor($t->accentText); }
                  $c->icon(glyph('check'))->opacity($fruit === $this->fruit ? 1 : 0);
                  $c->text($fruit);
                $c->end();
            }
        });
      $c->end();
    $c->end();
};

/** The table's files in the order it is sorted by. @return list<TableFile> */
$sortedFiles = function (): array {
    if ($this->tableFiles === []) {
        $kinds = ['pdf' => 'PDF document', 'jpg' => 'JPEG image', 'md' => 'Markdown', 'xlsx' => 'Spreadsheet', 'key' => 'Presentation', 'mp3' => 'MP3 audio', 'zip' => 'ZIP archive', 'svg' => 'SVG image'];
        foreach (['report.pdf', 'photo.jpg', 'notes.md', 'budget.xlsx', 'slides.key', 'song.mp3', 'archive.zip', 'logo.svg'] as $i => $name) {
            $this->tableFiles[] = new TableFile($i, $name, $kinds[pathinfo($name, PATHINFO_EXTENSION)], ($i * 37 % 11 + 1) * 173);
        }
    }
    $rows = $this->tableFiles;
    $by = $this->table->sortBy ?? [];
    usort($rows, function (TableFile $a, TableFile $b) use ($by): int { // stable, as PHP's sorts are; by each column in turn
        foreach ($by as $o) {
            $d = match ($o->column) {
                'Name' => strcmp(strtolower($a->name), strtolower($b->name)),
                'Kind' => strcmp($a->kind, $b->kind),
                'Size' => $a->size - $b->size,
                default => 0,
            };
            if ($d !== 0) { return $o->descending ? -$d : $d; }
        }
        return 0;
    });
    return $rows;
};

$list = function (Context $c) use (&$chatCard, &$sortedFiles): void {
    $t = $c->theme;
    $c->input($this->filter, 'Filter 10,000 rows', 0)->label('Filter');
    $rows = [];
    $f = $this->filter->text;
    for ($i = 0; $i < 10000; $i++) {
        if ($f === '' || str_contains((string)$i, $f)) { $rows[] = $i; }
    }
    // The row of the number picked: the list chooses rows by their index, which the filter changes. The keys moved the choice before this build, among the rows the last one showed.
    if ($this->rows->changed() && isset($this->shownRows[$this->rows->selected])) { $this->picked = $this->shownRows[$this->rows->selected]; }
    $this->shownRows = $rows;
    $at = array_search($this->picked, $rows, true);
    $at = $at === false ? -1 : $at;
    $c->row(0, 12);
      $c->text(sprintf('%d rows; only those in view are built. Pick one with a click or the arrows, open it with a double click or Enter; right-click one for its menu.', count($rows)), color: $t->textMuted)->grow(1);
      $show = $c->button('Show picked')->disabled($at < 0);
      if ($c->clicked($show)) { $this->rows->scrollTo($at); } // the row may not be built: the list scrolls to it all the same
    $c->end();
    // A row opens in a page of its own, which slides in; back, the list is as it was.
    $open = fn(int $n) => $this->router->push("/list/$n");
    $this->rows->selected = $at;
    $this->rows->key = fn(int $i): int => $rows[$i];
    $c->list($this->rows, count($rows), function (int $i) use ($c, $rows, $open): void {
        $n = $rows[$i];
        $row = $c->row(0, 10)->size(0, 32)->paddingX(12)->alignItems(Node::CENTER);
        $row->contextMenu(function (Menu $m) use ($n, $open): void {
            if ($m->item('Open')->chosen()) { $open($n); }
            if ($m->item('Pick')->chosen()) { $this->picked = $n; }
            if ($m->item('Starred')->checked($this->starred[$n] ?? false)->chosen()) { $this->starred[$n] = !($this->starred[$n] ?? false); }
            $m->separator();
            if ($m->item('Copy Square')->chosen()) { Clipboard::writeText((string)($n * $n)); }
        });
          $c->text("Row $n" . (($this->starred[$n] ?? false) ? '  ★' : ''))->grow(1);
          $c->text(sprintf('%d²  =  %d', $n, $n * $n), 12)->font('monospace');
        $c->end();
    })->size(0, 420)->border(1, $t->border)->radius(8)->padding(4);
    if ($this->rows->changed() && $this->rows->selected >= 0) { $this->picked = $rows[$this->rows->selected]; }
    if ($this->rows->submitted() && $this->rows->selected >= 0) { $open($rows[$this->rows->selected]); }
    $chatCard($c);
    $c->row(0, 18)->wrap(0)->gapY(18)->alignItems(Node::STRETCH);
      card($c, 'Tree')->size(220, 260);
        $item = function (string $path, string $label, ?Closure $children = null) use ($c): void {
            $open = null;
            if ($children !== null) { $open = $this->tree[$path] ?? false; }
            $n = $c->treeItem($label, $open, $children, $this->leaf === $path);
            if ($children !== null) { $this->tree[$path] = $open; }
            if ($c->clicked($n)) { $this->leaf = $path; }
        };
        $c->tree(function () use ($item): void {
            $item('src', 'src', function () use ($item): void {
                $item('src/App.php', 'App.php');
                $item('src/UI', 'UI', fn() => $item('src/UI/Context.php', 'Context.php'));
            });
            $item('composer.json', 'composer.json');
        });
      $c->end();
      card($c, 'Table')->basis(360)->grow(1)->size(0, 260);
        $cols = [new TableColumn('Name', sortable: true), new TableColumn('Kind', 120, sortable: true), new TableColumn('Size', 90, align: Node::END, sortable: true)];
        // Several files chosen by their ids, the one last chosen opening; typing a name goes to it. The names are renamed in place.
        $this->table->sortBy ??= []; // Cmd-click a header to sort by it after the others
        $files = $sortedFiles();
        $this->table->key = fn(int $i): int => $files[$i]->id;
        $this->table->label = fn(int $i): string => $files[$i]->name;
        $this->table->selected ??= -1;
        $this->table->selection ??= $this->chosen;
        $c->table($this->table, $cols, count($files), function (int $row, int $col) use ($c, $files): void {
            $f = $files[$row];
            match ($col) {
                0 => $c->editableText($f->name),
                1 => $c->text($f->kind)->singleLine(),
                2 => $c->text("{$f->size} KB"),
            };
        })->grow(1);
        if ($this->table->submitted() && $this->table->selected >= 0) { $c->toast('Opened ' . $files[$this->table->selected]->name); }
        $c->text(sprintf('%d chosen. Shift-click or Cmd-click to choose several; type a name to go to it; Return renames. Click a header to sort, Cmd-click to sort by several; drag a header to move it, its edge to resize it.', $this->chosen->count()), 12, $t->textMuted);
      $c->end();
    $c->end();
    card($c, 'Outline')->size(0, 340);
      // 100 folders of 100 files, in the order the Name column sorts.
      $this->outline->list->selected ??= -1;
      $descending = $this->outline->list->sort?->descending ?? false;
      $names = function (string $prefix, int $n) use ($descending): array {
          $items = [];
          for ($i = 0; $i < $n; $i++) { $items[] = sprintf('%s %03d', $prefix, $descending ? $n - $i : $i + 1); }
          return $items;
      };
      $children = fn(string $item): ?array => !str_starts_with($item, 'Folder') || str_contains($item, '/') ? null : $names("$item/File", 100);
      $c->outlineTable($this->outline, [new TableColumn('Name', sortable: true), new TableColumn('Kind', 110)], $names('Folder', 100), $children, function (string $item, int $col) use ($c, $children): void {
          match (true) {
              $col === 0 => $c->text(substr($item, (int)strrpos('/' . $item, '/')))->singleLine(),
              $children($item) !== null => $c->text('Folder'),
              default => $c->text('Document'),
          };
      })->grow(1);
      $c->text('10,100 items, built only as they show. Right and Left open and close the item chosen, and Option-click or Option with them all inside.', 12, $t->textMuted);
    $c->end();
    card($c, 'Grid view')->size(0, 380);
      // 10,000 swatches, as many columns as fit, several chosen by their numbers, in the order the user drags them to.
      if ($this->swatchOrder === []) { $this->swatchOrder = range(0, 9999); }
      $g = $this->swatches;
      $g->key = fn(int $i): int => $this->swatchOrder[$i];
      $g->selects = true;
      $g->selection = $this->swatchesChosen;
      $g->label = fn(int $i): string => 'Swatch ' . $this->swatchOrder[$i];
      $g->reorder = function (array $items, int $to): void { $this->swatchOrder = reorder($this->swatchOrder, $items, $to); };
      $c->gridView($g, count($this->swatchOrder), 96, 96, function (int $i) use ($c): void {
          $n = $this->swatchOrder[$i];
          $c->box(0, 0, hsl(($n % 36) * 10, 0.6, 0.65))->grow(1)->margin(6)->radius(6);
          $c->text((string)$n, 12)->alignSelf(Node::CENTER)->margin(0, 0, 4);
      })->grow(1);
      $c->text(sprintf('%d chosen of 10,000, built only as they show. The arrows move in both directions; Shift and Cmd choose several, and dragging moves them.', $this->swatchesChosen->count()), 12, $t->textMuted);
    $c->end();
};

/**
 * A chat: messages of every height, which the list measures as they
 * show, the header of each day pinned at the top, older messages
 * loading above without moving those in view, and new ones followed
 * at the end.
 */
$chatCard = function (Context $c): void {
    $t = $c->theme;
    if ($this->messages === []) {
        for ($id = 0; $id < 200; $id++) { $this->messages[] = makeMessage($id); }
    }
    // The rows: a header before each day's messages, [header, day, message].
    $items = [];
    foreach ($this->messages as $i => $m) {
        if ($i === 0 || $m->day() !== $this->messages[$i - 1]->day()) { $items[] = [true, $m->day(), null]; }
        $items[] = [false, $m->day(), $m];
    }
    $this->chat->followEnd = true;
    $this->chat->key = fn(int $i): int|string => $items[$i][0] ? 'day ' . $items[$i][1] : $items[$i][2]->id;
    $this->chat->header = fn(int $i): bool => $items[$i][0];
    card($c, 'Chat');
      $c->row(0, 8);
        $c->text(sprintf('%d messages of every height; older ones load above without moving those in view.', count($this->messages)), color: $t->textMuted)->grow(1);
        if ($c->clicked($c->button('Load older'))) {
            $first = $this->messages[0]->id;
            $older = [];
            for ($id = $first - 24; $id < $first; $id++) { $older[] = makeMessage($id); }
            $this->messages = [...$older, ...$this->messages];
        }
        if (!$this->chat->atEnd && $c->clicked($c->button('Jump to latest'))) { $this->chat->scrollToEnd(); }
      $c->end();
      $c->list($this->chat, count($items), function (int $i) use ($c, $t, $items): void {
          [$header, $day, $m] = $items[$i];
          if ($header) {
              $label = $day < 0 ? (new DateTimeImmutable())->modify("$day days")->format('l, F j') : 'Today';
              $c->row(0, 0)->justify(Node::CENTER)->paddingY(6);
                $c->row(0, 0, 0, $t->surface)->padding(3, 10)->radius(999)->border(1, $t->border);
                  $c->text($label, 12, style: Font::BOLD);
                $c->end();
              $c->end();
              return;
          }
          $c->row(0, 0)->padding(3, 12);
            $bubble = $c->column(0, 0, 0, $t->surface)->maxWidthPercent(72)->padding(7, 12)->radius(14);
            if ($m->mine) { $bubble->margin(0, 0, 0, Node::AUTO)->background($t->accent)->textColor($t->accentText); }
              $c->text($m->text);
            $c->end();
          $c->end();
      })->size(0, 380)->justify(Node::END)->paddingY(4)->border(1, $t->border)->radius(8);
      $c->row(0, 8);
        $input = $c->input($this->draft, 'Message', 0)->label('Message')->grow(1);
        $empty = trim($this->draft->text) === '';
        $send = $c->button('Send')->disabled($empty);
        if (($c->clicked($send) || $c->submitted($input)) && !$empty) {
            $last = end($this->messages);
            $this->messages[] = new Message($last->id + 1, trim($this->draft->text), true);
            $this->draft = new TextState('');
        }
      $c->end();
    $c->end();
};

/**
 * What each kind of color paints (fills, gradients, stripes, text,
 * shadows) in the sRGB color nearest to an Oklch one, on the left, and
 * in the Oklch color itself, on the right: on a Display P3 screen the
 * right column shows the colors sRGB cannot (a gradient's end, as
 * stripes' ground, in its sRGB color in both).
 */
$wideColors = function (Context $c): void {
    $t = $c->theme;
    $vivid = Oklch::rgb(0.85, 0.3, 145); $warm = Oklch::rgb(0.7, 0.3, 30);
    $c->text('Compare the columns on a Display P3 screen: the right one is more vivid.', 12, $t->textMuted);
    // pair builds a sample twice, in sRGB and then in Oklch, in equal columns.
    $pair = function (Closure $sample) use ($c): void {
        $c->row(0, 12);
          foreach ([false, true] as $wide) { $sample($wide)->basis(0)->grow(1); }
        $c->end();
    };
    $box = function (string $label) use ($c): Node {
        $n = $c->row(0, 0)->size(0, 30)->radius(6)->center();
          $c->text($label, 12, 0x000000, Font::BOLD);
        $c->end();
        return $n;
    };
    $in = fn(bool $wide, int $color): int => $wide ? $color : WideColor::srgb($color); // the color, or its sRGB twin
    $pair(fn(bool $wide) => $c->text($wide ? 'Oklch' : 'sRGB', 12, $t->textMuted, Font::BOLD));
    $pair(fn(bool $wide) => $box('Fill')->background($in($wide, $vivid)));
    $pair(fn(bool $wide) => $box('Gradient')->linearGradient(new LinearGradient($in($wide, $warm), 1, $in($wide, $vivid), 1, 90, oklab: true)));
    $pair(fn(bool $wide) => $box('Stripes')->background(0xffffff)->stripes($in($wide, $vivid), 4, 6, 45));
    $pair(function (bool $wide) use ($c, $in, $vivid): Node {
        $n = $c->row(0, 0, 0, 0x141414)->size(0, 30)->radius(6)->center();
          $c->text('Text', 14, $in($wide, $vivid), Font::BOLD);
        $c->end();
        return $n;
    });
    $pair(fn(bool $wide) => $box('Shadow')->margin(6)->background(0xffffff)->shadow(0, 4, 12, 0, $in($wide, $vivid)));
};

$styling = function (Context $c) use (&$wideColors): void {
    $t = $c->theme;
    $c->text('Grids, borders of each side, gradients, stripes, text decorations, and motion along easings.', color: $t->textMuted);
    cards($c);
      card($c, 'Grid');
        $c->grid([Track::fit(), Track::fr(1), Track::fr(1)], 6);
          $cell = function (string $s) use ($c, $t): Node {
              $n = $c->row(0, 0, 0, $t->surface)->padding(8, 10)->radius(6);
                $c->text($s, 12);
              $c->end();
              return $n;
          };
          $cell('columnSpan(-1)')->columnSpan(-1)->background($t->accent)->textColor($t->accentText);
          $cell('Track::fit()')->rowSpan(2);
          $cell('Track::fr(1)');
          $cell('Track::fr(1)');
          $cell('columnSpan(2)')->columnSpan(2)->justifySelf(Node::CENTER);
        $c->end();
      $c->end();
      card($c, 'Borders');
        $c->row(0, 0)->paddingY(6)->borderWidth(0, 0, 1, 0)->borderColor($t->border);
          $c->text('A header with a line below', style: Font::BOLD);
        $c->end();
        $c->row(0, 8, 0, $t->surface)->padding(8, 12)->borderWidth(0, 0, 0, 4)->borderColor($t->accent)->radius(0, 6, 6, 0);
          $c->text('A note with an accent on its left');
        $c->end();
        $c->column(0, 0)->size(0, 56)->radius(8)->border(2, $t->border)->borderStyle(BorderStyle::Dashed)->center();
          $c->text('Dashed, as a place to drop files', color: $t->textMuted);
        $c->end();
      $c->end();
      card($c, 'Fills');
        $blue = 0x2563eb; $yellow = 0xfacc15;
        $bar = function (string $label) use ($c): Node {
            $n = $c->row(0, 0)->size(0, 30)->paddingX(10)->radius(6)->alignItems(Node::CENTER);
              $c->text($label, 12, 0xffffff, Font::BOLD);
            $c->end();
            return $n;
        };
        $bar('sRGB')->gradient($blue, $yellow, 90);
        $bar('Oklab')->linearGradient(new LinearGradient($blue, 1, $yellow, 1, 90, oklab: true));
        $bar('Stops at 40% and 60%')->linearGradient(new LinearGradient($blue, 1, $yellow, 1, 90, 0.4, 0.6));
        $bar('Radial, from the right')->radialGradient(new RadialGradient(centerX: 0.9, centerY: 0.5, oklab: true, stops: [new GradientStop($yellow, 0), new GradientStop($blue, 1)]));
        $c->row(0, 0, 0, $t->surface)->size(0, 30)->radius(6)->stripes($t->border, 4, 6, 45)->center();
          $c->text('Stripes: unavailable', 12, $t->textMuted);
        $c->end();
      $c->end();
      card($c, 'Wide colors (Oklch)');
        $wideColors($c);
      $c->end();
      card($c, 'Text decorations');
        $c->richText([new Span('Spell checkers mark '), new Span('mispeled', wavyUnderline: true, decorationColor: $t->danger), new Span(' words with waves.')]);
        $c->richText([new Span('Search results '), new Span('stand out', background: 0xfacc15, backgroundAlpha: 0.45), new Span(' with a background.')]);
        $c->text('Underlines of their own color and thickness')->underline()->decorationColor($t->accent)->decorationThickness(2);
        $c->text('A highlight behind every line')->textBackground($t->selection);
        $c->text(str_repeat('A line cut with an ellipsis of its own. ', 3))->singleLine()->ellipsis(' →');
      $c->end();
      card($c, 'Motion');
        $c->row(0, 14)->alignItems(Node::CENTER);
          $spin = $c->icon(glyph('loader'), 24, $t->accent);
          $spin->rotate($c->loop($spin, 'spin', 1, Easing::linear(...)) * 360);
          $pulse = $c->box(0, 14, $t->border)->grow(1)->radius(7);
          $pulse->opacity(0.4 + 0.6 * $c->loop($pulse, 'pulse', 1.6, Easing::bounce(Easing::easeInOut(...))));
        $c->end();
        if ($c->clicked($c->button('Move along each easing'))) { $this->eased = !$this->eased; }
        foreach (['Linear' => Easing::linear(...), 'EaseIn' => Easing::easeIn(...), 'EaseOut' => Easing::easeOut(...), 'EaseInOut' => Easing::easeInOut(...)] as $name => $ease) {
            $track = $c->key($name)->row(0, 0)->size(0, 18)->alignItems(Node::CENTER);
            $at = $c->animateWith($track, 'x', $this->eased ? 1 : 0, 0.9, $ease);
              $c->text($name, 12, $t->textMuted)->size(70, 0);
              $c->column(0, 0, 1)->size(0, 18);
                $c->box(18, 18, $t->accent)->radius(9)->absolute()->leftPercent($at * 0.9);
              $c->end();
            $c->end();
        }
      $c->end();
      card($c, 'Layout');
        $c->row(0, 6)->reverse();
          foreach (['1', '2', '3'] as $s) {
              $c->row(0, 0, 0, $t->surface)->size(28, 28)->radius(6)->center();
                $c->text($s);
              $c->end();
          }
          $c->text('reverse()', 12, $t->textMuted)->margin(0, Node::AUTO, 0, 0);
        $c->end();
        $c->row(0, 6)->alignItems(Node::CENTER);
          $c->text('margin(…, Node::AUTO) pushes to the end', 12, $t->textMuted);
          $c->button('Save')->margin(0, 0, 0, Node::AUTO);
        $c->end();
        $c->row(0, 6)->alignItems(Node::CENTER);
          $c->text('Inbox');
          $c->row(0, 0, 0, $t->danger)->padding(1, 5)->radius(8)->top(-6);
            $c->text('3', 10, 0xffffff, Font::BOLD);
          $c->end();
          $c->text('top(-6) moves a badge up', 12, $t->textMuted);
        $c->end();
      $c->end();
      card($c, 'Scrolling both ways');
        $c->scrollBoth(0, 0)->size(0, 150)->radius(6)->border(1, $t->border);
          $c->grid(array_fill(0, 16, Track::fixed(56)), 4, pad: 6);
            for ($i = 0; $i < 16 * 12; $i++) {
                $c->row(0, 0, 0, $t->accent)->size(0, 28)->radius(4)->center()->alpha = 0.08 + 0.6 * ($i % 16) / 16 * intdiv($i, 16) / 12;
                  $c->text(sprintf('%s%d', chr(ord('A') + $i % 16), intdiv($i, 16) + 1), 11);
                $c->end();
            }
          $c->end();
        $c->end();
      $c->end();
      card($c, 'Cursors');
        $c->row(0, 6)->wrap(0);
          foreach (['ResizeColumn' => Cursor::ResizeColumn, 'ResizeRow' => Cursor::ResizeRow, 'ResizeE' => Cursor::ResizeE, 'Copy' => Cursor::Copy, 'Alias' => Cursor::Alias,
              'ContextMenu' => Cursor::ContextMenu, 'VerticalText' => Cursor::VerticalText, 'None' => Cursor::None] as $name => $cursor) {
              $c->row(0, 0, 0, $t->surface)->padding(6, 10)->radius(6)->cursor($cursor);
                $c->text($name, 12);
              $c->end();
          }
        $c->end();
      $c->end();
    $c->end();
};

$drawing = function (Context $c): void {
    $t = $c->theme;
    $c->text('Node::draw paints with rectangles, shadows, paths and text. This drawing moves with the time of each frame, which paints it again without building the page.', color: $t->textMuted);
    $c->box(0, 320, $t->surface)->radius(10)->draw(function (Painter $p, array $r) use ($t): void {
        $p->animationFrame();
        $phase = intdiv($p->now(), 1_000_000) % 4000 / 4000 * 2 * M_PI;
        // Bars, in the left half of the box at most, 28 points apart where it is wide enough.
        $step = min(28.0, ($r[2] / 2 - 24) / 12);
        for ($i = 0; $i < 12; $i++) {
            $h = 60 + 50 * sin($phase + $i * 0.6);
            $p->fill([$r[0] + 24 + $i * $step, $r[1] + $r[3] - 24 - $h, $step * 0.65, $h], $t->accent, 4, 0.35 + 0.05 * $i);
        }
        // A sine wave, across what the bars leave, up to 300 points wide: it stays in the box at any width.
        $x0 = $r[0] + 24 + 12 * $step + 16;
        $len = min(300.0, $r[0] + $r[2] - 24 - $x0);
        $wave = new Path();
        for ($i = 0; $i <= 100; $i++) {
            $x = $x0 + $i * $len / 100; $y = $r[1] + $r[3] / 2 + 60 * sin($phase * 2 + $i / 12);
            $i === 0 ? $wave->moveTo($x, $y) : $wave->lineTo($x, $y);
        }
        $p->strokePath($wave, 3, $t->danger);
        $p->fillPath((new Path())->circle($r[0] + $r[2] - 70, $r[1] + 70, 36), $t->accent);
        $p->text($r[0] + 24, $r[1] + 20, "Animated at the display's rate", 14, $t->text);
    });
    card($c, 'Vector images');
      $c->text('SVGs stay sharp at any size: icons in the color of the text, pictures in their own colors.', color: $t->textMuted);
      $gold = 0xf5b400;
      $c->row(0, 14)->alignItems(Node::CENTER);
        foreach ([16, 24, 40] as $size) { $c->icon(glyph('star'), $size, $gold); }
        // A button laid out as a row of its own children.
        $done = $c->buttonBase()->padding($t->space(1.5), $t->space(3.5))->radius($t->radius)->background($t->accent)->textColor($t->accentText);
        $done->gap = $t->space(1.5);
          $c->icon(glyph('check'));
          $c->text('Done')->singleLine();
        $c->end();
        if ($c->clicked($done)) { $c->toast('Done'); }
        $c->image(glyph('badge'), 64, 64)->textColor($gold);
        $c->image(glyph('badge'), 32, 32)->textColor($gold);
      $c->end();
    $c->end();
};

/** Liquid Glass: a toolbar floating over content that scrolls under it, on a bar of glass, a scroll edge or a progressive blur, a tinted button, and a lens to drag around. */
$glassPage = function (Context $c): void {
    $t = $c->theme;
    $c->text("The glass plugin's Liquid Glass, a material, as macOS draws it: what is under it shows through, frosted and bent along its edges. Scroll under the toolbar, and drag the lens.", color: $t->textMuted);
    $c->row(0, 16)->alignItems(Node::CENTER)->wrap(0);
      $c->segmented(['Regular', 'Clear'], $this->glassStyle)->label('Glass');
      $c->segmented(['Glass bar', 'Soft edge', 'Hard edge', 'Progressive blur'], $this->glassEdge)->label('Under the toolbar');
    $c->end();
    $style = $this->glassStyle === 1 ? GlassStyle::Clear : GlassStyle::Regular;
    $tiles = [0xef4444, 0xf59e0b, 0x10b981, 0x06b6d4, 0x6366f1, 0xec4899];
    $c->column(0, 0)->size(0, 420)->radius(12)->clip()->border(1, $t->border);
      // What shows through: photos and text, which start below the toolbar, 64 points down, and scroll under it, with the scroll bar below what floats over the content.
      $bars = $this->glassEdge === 3 ? 88 : 64;
      $c->scroll(0, 12, 1)->fill()->padding(76, 16, 16)->scrollbarInsets($bars, 0, 0);
        for ($i = 0; $i < 12; $i++) {
            $c->row(0, 14);
              $c->box(180, 96, 0)->radius(12)->gradient($tiles[$i % 6], $tiles[($i + 2) % 6], 135);
              $c->column(0, 4, 1);
                $c->text(sprintf('Photo %d', $i + 1), style: Font::BOLD);
                $c->text('Glass bends the light along its rim, and frosts what is under its middle.', color: $t->textMuted);
              $c->end();
            $c->end();
        }
      $c->end();
      // A toolbar of buttons floating on glass, or over a scroll edge as macOS's, which fades the content into the background or frosts it, or over a blur, the stronger the nearer the top.
      if ($this->glassEdge > 0) {
          $edge = $c->column(0, 0)->absolute()->top(0)->left(0)->right(0)->passThrough();
          match ($this->glassEdge) {
              1 => $edge->size(0, 74)->material(new ScrollEdge()),
              2 => $edge->size(0, 64)->material(new ScrollEdge(hard: true)),
              3 => $edge->size(0, 88)->material(new Blur(6, new LinearGradient(0x000000, 1, 0x000000, 0, 180, 0.3, 1))),
          };
          $c->end();
      }
      $bar = $c->row(0, 6)->absolute()->top(12)->left(12)->right(12)->padding(6, 8)->alignItems(Node::CENTER)->radius(26);
      $this->glassEdge === 0 ? $bar->material(new Glass($style)) : $bar->passThrough();
        foreach (['chevron', 'star', 'check'] as $ic) {
            $b = $c->row(0, 0)->size(40, 40)->radius(20)->center()->material(new Glass($style, interactive: true));
              $c->icon(glyph($ic), 18);
            $c->end();
            if ($c->clicked($b)) { $c->toast('Clicked'); }
        }
        $c->box(0, 0, 0)->grow(1)->alpha = 0.0;
        $done = $c->row(0, 0)->padding(8, 16)->radius(20)->material(new Glass($style, $t->accent, interactive: true));
          $c->text('Done', color: $t->accentText, style: Font::BOLD);
        $c->end();
        if ($c->clicked($done)) { $c->toast('Done'); }
      $c->end();
      // A lens to drag over what is under it.
      $lens = $c->column(0, 0)->absolute()->left($this->lens[0])->top($this->lens[1])->size(110, 110)->radius(55)->material(new Glass($style, interactive: true));
      $c->end();
      if (($d = $c->dragged($lens)) !== null) {
          $this->lens = [max(0.0, $this->lens[0] + $d[0]), max(0.0, $this->lens[1] + $d[1])];
      }
    $c->end();
};

$motion = function (Context $c): void {
    $t = $c->theme;
    // The items move in, collapse out, and slide to their places; the tiles move into their detail view and back.
    $itemMotion = new ElementTransition(0.25, Easing::easeInOut(...), enter: new TransitionMotion(collapse: true, scale: 0.9), exit: new TransitionMotion(collapse: true, scale: 0.9));
    $tileMotion = fn(int $i) => new ElementTransition(animation: new Animation(spring: new SpringOptions(0.45, 0.12)), match: "tile $i");
    $flingStyles = [Animation::smooth(), Animation::snappy(), Animation::bouncy()];
    cards($c);
      card($c, 'Transforms and interruptible springs');
        $c->text('Click repeatedly while moving. Edit the transformed field or open its anchored panel.', 12, $t->textMuted);
        if ($c->clicked($c->button('Retarget the spring'))) { $this->motionTarget = !$this->motionTarget; }
        $stage = $c->key('transform-stage')->column(0, 0)->size(0, 220)->radius(10)->border(1, $t->border)->clip();
        $pose = $c->spring($stage, 'pose', $this->motionTarget ? 1 : 0, new SpringOptions(damping: 14));
          $c->key('transformed-card')->column(12, 8, 0, $t->surface)->absolute()->left(35)->top(45)->size(185, 125)->radius(10)->border(1, $t->border)
              ->scale(0.95 + 0.1 * $pose, 1)->rotate(-8 + 16 * $pose)->translate(65 * $pose, 0);
            $c->text('Nested interactive content', 12, style: Font::BOLD);
            $c->input($this->motionText, 'Type here', 155);
            $anchor = $c->button('Open a popover');
            if ($c->clicked($anchor)) { $this->transformPopover = !$this->transformPopover; }
            $c->popover($anchor, $this->transformPopover, fn() => $c->text('I follow the transformed anchor.'));
          $c->end();
        $c->end();
      $c->end();
      card($c, 'Keyframe sequences');
        $c->text('Replay during a sequence to interrupt it continuously. Motion follows the desktop preference.', 12, $t->textMuted);
        if ($c->clicked($c->button('Replay the sequence'))) { $this->motionRun++; }
        $stage = $c->key('sequence-stage')->column(0, 0)->size(0, 220)->radius(10)->border(1, $t->border)->clip();
        $progress = $c->keyframes($stage, 'pose', [
            new Keyframe(0, 0), new Keyframe(0.25, 1, Easing::easeOut(...)),
            new Keyframe(0.65, 0.4, Easing::easeInOut(...)), new Keyframe(1, 0, Easing::easeInOut(...)),
        ], new KeyframeOptions(run: $this->motionRun));
          $c->row(0, 0, 0, $t->accent)->absolute()->left(40)->top(80)->size(160, 55)->center()->radius(8)
              ->scale(1 + 0.08 * $progress, 1 + 0.08 * $progress)->rotate(12 * $progress)->translate(80 * $progress, 0);
            $c->text('Four timed keyframes', 12, $t->accentText, Font::BOLD);
          $c->end();
        $c->end();
      $c->end();
    $c->end();

    cards($c);
      card($c, 'Flings that land');
        $c->text("Throw the card (draggable): a spring takes over at the pointer's velocity, to the side the throw carries it to.", 12, $t->textMuted);
        $c->segmented(['Smooth', 'Snappy', 'Bouncy'], $this->flingStyle);
        $travel = 200;
        $c->key('fling-track')->column(0, 0)->size(0, 110)->radius(10)->border(1, $t->border)->clip();
          $thrown = $c->key('fling-card')->row(0, 0, 0, $t->accent)->absolute()->left(16)->top(24)->size(90, 60)->center()->radius(10);
          // Under the pointer, a little past either end, and to the side the throw carries it to once let go.
          $c->draggable($thrown, $this->fling, new DraggableOptions(Node::HORIZONTAL, [0, 0, $travel, 0], snap: Motion::nearest([0, 0], [$travel, 0]), animation: $flingStyles[$this->flingStyle]));
          $dragging = $c->dragging($thrown);
          $thrown->animatePose($dragging ? new Pose(scale: 1.06) : new Pose(), Animation::snappy())->cursor($dragging ? Cursor::Grabbing : Cursor::Grab);
            $c->text('Throw me', 12, $t->accentText, Font::BOLD);
          $c->end();
        $c->end();
      $c->end();
      card($c, 'Shared elements');
        $c->text('A tile opens into its detail view from where it was, and goes back to its place.', 12, $t->textMuted);
        $c->key('tile-stage')->column(12, 0)->size(0, 190)->radius(10)->border(1, $t->border)->clip();
          if ($this->tileOpen === 0) {
              $c->row(0, 12);
                foreach (array_slice(MOTION_COLORS, 0, 3) as $i => $col) {
                    $tile = $c->key("tile $i")->box(64, 64, $col)->radius(12)->cursor(Cursor::Pointer);
                    $c->transition($tile, $tileMotion($i));
                    if ($c->clicked($tile)) { $this->tileOpen = $i + 1; }
                }
              $c->end();
          } else {
              $i = $this->tileOpen - 1;
              $c->column(0, 10);
                $c->transition($c->key('hero')->box(240, 120, MOTION_COLORS[$i])->radius(16), $tileMotion($i));
                $c->row(0, 10)->alignItems(Node::CENTER);
                  $c->text(sprintf('Tile %d', $i + 1), style: Font::BOLD)->grow(1);
                  if ($c->clicked($c->button('Back'))) { $this->tileOpen = 0; }
                $c->end();
              $c->end();
          }
        $c->end();
      $c->end();
    $c->end();

    cards($c);
      card($c, 'Cards that turn over');
        $c->text('Turned in 3D and seen in perspective, each face hides while it is turned away.', 12, $t->textMuted);
        $stage = $c->key('flip-stage')->column(0, 0)->size(0, 150)->center();
        $turn = $c->animateTo($stage, 'turn', $this->cardFlipped ? 180 : 0, new Animation(spring: new SpringOptions(0.6, 0.15)));
          $c->key('flip-card')->column(0, 0)->size(200, 120);
            $front = $c->key('front')->column(0, 4, 0, $t->surface)->absolute()->fill()->center()->radius(14)->border(1, $t->border)->shadow(0, 4, 16, 0, 0x000000, 0.12)
                ->rotateY($turn)->perspective(800)->backfaceHidden();
              $c->text('Front', style: Font::BOLD);
              $c->text('Click to turn over', 12, $t->textMuted);
            $c->end();
            $back = $c->key('back')->column(0, 0, 0, $t->accent)->absolute()->fill()->center()->radius(14)->rotateY($turn + 180)->perspective(800)->backfaceHidden();
              $c->text('Back', color: $t->accentText, style: Font::BOLD);
            $c->end();
            if ($c->clicked($front) || $c->clicked($back)) { $this->cardFlipped = !$this->cardFlipped; }
          $c->end();
        $c->end();
      $c->end();
      card($c, 'Counters, check marks and lifts');
        $c->text('Digits roll as they change, a check mark draws itself in, and tiles lift in frames that only paint.', 12, $t->textMuted);
        $c->row(0, 16)->wrap(0)->gapY(8)->alignItems(Node::CENTER);
          if ($c->clicked($c->button('−'))) { $this->counter--; $this->countDown = true; }
          $c->text((string)$this->counter, 32, style: Font::BOLD)->size(80, 0)->textAlign(Node::CENTER)->contentTransition(new ContentTransition(numeric: true, down: $this->countDown));
          if ($c->clicked($c->button('+'))) { $this->counter++; $this->countDown = false; }
          $check = $c->key('check')->box(36, 36, 0)->radius(8)->border(2, $t->border)->cursor(Cursor::Pointer);
          $check->color = null;
          $drawn = $c->animateTo($check, 'drawn', $this->taskDone ? 1 : 0, Animation::smooth());
          $check->draw(function (Painter $p, array $r) use ($drawn, $t): void {
              $mark = (new Path())->moveTo($r[0] + 9, $r[1] + 19)->lineTo($r[0] + 15, $r[1] + 25)->lineTo($r[0] + 27, $r[1] + 11);
              $p->strokePath($mark->trim(0, $drawn), 3, $t->accent);
          });
          if ($c->clicked($check)) { $this->taskDone = !$this->taskDone; }
        $c->end();
        $c->row(0, 10);
          foreach (array_slice(MOTION_COLORS, 0, 4) as $i => $col) {
              $tile = $c->key("lift $i")->box(56, 40, $col)->radius(8);
              $tile->animatePose($c->hovered($tile) ? new Pose(y: -6, scale: 1.06) : new Pose(), Animation::snappy());
          }
        $c->end();
      $c->end();
    $c->end();

    $c->text('Transitions move nodes where the layout puts them, and in and out as they come and go. F12 (Alt+Cmd+I) opens the inspector of the window\'s nodes.', color: $t->textMuted);
    cards($c);
      card($c, 'A list that moves');
        $c->row(0, 8)->wrap(0)->gapY(8);
          if ($c->clicked($c->button('Add'))) {
              $this->nextItem++;
              $at = $this->items === [] ? 0 : $this->nextItem % (count($this->items) + 1);
              array_splice($this->items, $at, 0, [new MotionItem($this->nextItem, "Item {$this->nextItem}")]);
          }
          if ($c->clicked($c->button('Shuffle')->disabled(count($this->items) < 2))) {
              // Shuffle until the order changes, so that each click moves the items.
              $before = $this->items;
              while ($this->items === $before) { shuffle($this->items); }
          }
          if ($c->clicked($c->button('Sort')->disabled(count($this->items) < 2))) {
              usort($this->items, fn(MotionItem $a, MotionItem $b) => $a->id <=> $b->id);
          }
        $c->end();
        // Each row has the same transition, so that the rows move in step, and the column grows and shrinks with them; the lines between the rows come with the column.
        $col = $c->column(0, 0)->radius(8)->border(1, $t->border)->clip()->dividers(1, $t->border);
        $c->transition($col, new ElementTransition(0.25, Easing::easeInOut(...), size: true));
          $removed = -1;
          foreach ($this->items as $it) {
              $row = $c->key("item {$it->id}")->row(0, 10, 0, $t->background)->padding(8, 10)->alignItems(Node::CENTER);
              $c->transition($row, $itemMotion);
                $c->box(10, 10, MOTION_COLORS[$it->id % count(MOTION_COLORS)])->radius(5);
                $c->text($it->name)->grow(1);
                if ($c->clicked($c->button('Remove'))) { $removed = $it->id; } // once the loop over the items is done
              $c->end();
          }
          if ($removed >= 0) { $this->items = array_values(array_filter($this->items, fn(MotionItem $o) => $o->id !== $removed)); }
        $c->end();
        if ($this->items === []) { $c->text('No items: add some.', color: $t->textMuted); }
      $c->end();
      card($c, 'A panel that opens');
        if ($c->clicked($c->button('Toggle the panel'))) { $this->panelOpen = !$this->panelOpen; }
        $c->row(0, 0)->size(0, 120)->radius(8)->border(1, $t->border)->clip();
          // The panel lays out its content at each width on the way; the content beside it moves with it.
          $panel = $c->column(10, 8, 0, $t->surface)->size($this->panelOpen ? 160 : 48, 0)->shrink(0)->clipX();
          $c->transition($panel, new ElementTransition(ease: Easing::easeInOut(...), size: true));
            foreach (['Overview', 'Styling', 'Drawing'] as $p) {
                $c->row(0, 8)->alignItems(Node::CENTER);
                  $c->icon(pageIcon($p), 18)->shrink(0);
                  $c->text($p)->singleLine();
                $c->end();
            }
          $c->end();
          $beside = $c->column(12, 0, 1);
          $c->transition($beside, new ElementTransition(ease: Easing::easeInOut(...)));
            $c->text('The content beside the panel.', color: $t->textMuted);
          $c->end();
        $c->end();
      $c->end();
      card($c, 'Colors that fade');
        $c->text('Their backgrounds and borders fade as the pointer comes and goes.', 12, $t->textMuted);
        $c->row(0, 10);
          foreach (array_slice(MOTION_COLORS, 0, 3) as $i => $col) {
              $tile = $c->key("fade $i")->box(64, 48, $col)->radius(8)->border(2, $t->border);
              $tile->background($col, 0.15);
              if ($c->hovered($tile)) { $tile->background($col)->borderColor(Theme::mix($col, 0x000000, 0.3)); }
              $c->transition($tile, new ElementTransition(0.15, colors: true));
          }
        $c->end();
      $c->end();
      card($c, 'Attached');
        $c->text('attach() puts a point of a node on a point of its parent.', 12, $t->textMuted);
        $c->row(0, 24)->paddingY(8);
          foreach (['Ada Lovelace', 'Alan Turing'] as $i => $name) {
              $c->column(0, 0);
                $c->avatar($name);
                $c->row(0, 0, 0, $t->danger)->padding(1, 5)->radius(8)->attach(Anchor::TopRight, Anchor::Center);
                  $c->text((string)(3 + $i * 9), 10, 0xffffff, Font::BOLD);
                $c->end();
              $c->end();
          }
          $c->column(0, 0, 0, $t->surface)->size(120, 64)->radius(8);
            $c->text('Bottom right', 11, $t->textMuted)->attach(Anchor::BottomRight, Anchor::BottomRight)->right(6)->bottom(4);
            $c->text('Center', 11)->attach(Anchor::Center, Anchor::Center);
          $c->end();
        $c->end();
      $c->end();
    $c->end();
};

$overlays = function (Context $c): void {
    $t = $c->theme;
    card($c, 'Overlays');
      $c->row(0, 10);
        if ($c->clicked($c->primaryButton('Open dialog'))) { $this->dialog = true; }
        $menu = $c->button('Menu ▾');
        if ($c->clicked($menu)) { $this->menu = !$this->menu; }
        $c->popover($menu, $this->menu, function () use ($c, $t): void {
            foreach (['New file', 'Open…', 'Save as…'] as $item) {
                $entry = $c->key($item)->row(0, 0)->padding(6, 12)->radius(5)->size(180, 0);
                if ($c->hovered($entry)) { $entry->background($t->accent)->textColor($t->accentText); }
                if ($c->clicked($entry)) { $this->menu = false; }
                  $c->text($item);
                $c->end();
            }
        });
        // A menu drawn in the window, with the theme's look, rather than the system's: on a right-click, and below the card's ⋯ button.
        $cardMenu = function (Menu $m): void {
            if ($m->item('Duplicate')->shortcut(Key::COMMAND, Key::D)->chosen()) { $this->cardDid = 'Duplicated'; }
            if ($m->item('Pinned')->checked($this->cardPinned)->chosen()) { $this->cardPinned = !$this->cardPinned; }
            $m->submenu('Zoom', function (Menu $m): void {
                foreach (['Small', 'Medium', 'Large'] as $z) {
                    if ($m->item($z)->radio($this->cardZoom === $z)->chosen()) { $this->cardZoom = $z; }
                }
            });
            $m->separator();
            if ($m->item('Delete')->disabled($this->cardPinned)->chosen()) { $this->cardDid = 'Deleted'; }
        };
        $card = $c->row(0, 4)->alignItems(Node::CENTER)->padding(2, 2, 2, 12)->radius($t->radius)->border(1, $t->border)->focusable()->label('Card');
        $card->popoverMenu($cardMenu);
          $c->text($this->cardDid !== '' ? $this->cardDid : 'Right-click me');
          $more = $c->buttonBase()->label('More')->padding(4)->radius($t->radius);
          if ($c->hovered($more)) { $more->background($t->surfaceHover); }
            $c->icon(glyph('dots'));
          $c->end();
          $more->popoverMenuButton($cardMenu);
        $c->end();
        $c->button('Hover me')->tooltip('Tooltips show after the pointer rests a moment.');
        if ($c->clicked($c->button('Alert'))) { $this->alert = true; }
        if ($c->clicked($c->button('Delete a note'))) {
            $this->notes--;
            $c->toastAction('Note deleted', 'Undo', function (): void { $this->notes++; });
        }
        if ($c->clicked($c->button('Native dialog'))) {
            // After the click, not in it: the dialog runs its own loop.
            App::later(fn() => Dialog::message(new MessageOptions(parent: $this->win, message: 'Native dialogs work from MyPHP UI windows too.')));
        }
      $c->end();
    $c->end();
    $c->text(sprintf('%d notes. Undo in the toast brings a deleted one back.', $this->notes), 12, $t->textMuted);
    if ($c->alertDialog($this->alert, 'Delete “Notes”?', "This deletes the note on all your devices. You can't undo this.", 'Cancel', 'Delete') === 1) {
        $this->notes--;
        $c->toast('Note deleted');
    }
    $c->modal($this->dialog, function () use ($c, $t): void {
        $c->text('A modal dialog', 18, style: Font::BOLD);
        $c->text('Click outside or press Escape to close it.', color: $t->textMuted);
        $c->row(0, 8)->justify(Node::END);
          if ($c->clicked($c->primaryButton('Done'))) { $this->dialog = false; }
        $c->end();
    });
};

$charts = function (Context $c) use (&$salesChart, &$liveChart, &$stackedChart, &$scatterChart, &$donutChart): void {
    $t = $c->theme;
    $c->text('UI\\Chart has scales, axes and marks without a look: the marks are nodes styled here, and lines, areas and slices paths these cards paint.', color: $t->textMuted);
    $c->row(0, 16)->wrap(0)->alignItems(Node::START);
      foreach (['Sales' => $salesChart(...), 'Live data' => $liveChart(...), 'Visits by source' => $stackedChart(...), 'Weight and fuel use' => $scatterChart(...), 'Users by platform' => $donutChart(...)] as $title => $chart) {
          card($c, $title)->basis(320)->grow(1)->minWidth(0);
            $chart($c);
          $c->end();
      }
    $c->end();
};

/** A bar chart whose bars grow to new values, show tooltips and choose a month when clicked, with the value above the one chosen. */
$salesChart = function (Context $c): void {
    $t = $c->theme;
    $ch = $this->chart;
    $c->row(0, 8)->alignItems(Node::CENTER);
      $c->text($ch->month >= 0 ? sprintf('%s: $%.1fk', CHART_MONTHS[$ch->month], $ch->sales[$ch->month]) : 'Click a bar to choose its month.', color: $t->textMuted)->grow(1);
      if ($c->clicked($c->button('Shuffle'))) {
          foreach ($ch->sales as $i => $_) { $ch->sales[$i] = 10 + 110 * mt_rand() / mt_getrandmax(); }
      }
    $c->end();
    $x = (new Band(...CHART_MONTHS))->padding(0.3, 0.1);
    [, $hi] = Chart::extent(...$ch->sales);
    $y = (new Linear(0, $hi))->nice(4);
    $y->format = fn(float $v) => $v == 0 ? '$0' : sprintf('$%.0fk', $v);
    $ticks = $y->ticks(4);
    $months = array_values(array_filter($x->ticks(), fn(Tick $tk) => (int)$tk->value % 2 === 0)); // every other month, so that the labels keep apart
    Chart::plot($c, new Axes(left: $ticks, bottom: $months, label: axisLabel($c)), function (Node $area) use ($c, $ch, $x, $y, $ticks, $t): void {
        gridLines($c, $area, [], $ticks, $t->border);
        foreach ($ch->sales as $i => $v) {
            $bar = Chart::rect($c->key($i), $x->band($i), $y->span(0, $v))->radius(3)->background($t->accent, $ch->month >= 0 && $i !== $ch->month ? 0.35 : 1.0)
                ->tooltip(sprintf('%s: $%.1fk', CHART_MONTHS[$i], $v))->label(CHART_MONTHS[$i])->cursor(Cursor::Pointer);
            $c->transition($bar, new ElementTransition(0.35)); // bars grow and shrink to new values
            if ($c->clicked($bar)) { $ch->month = $ch->month === $i ? -1 : $i; }
        }
        if (($i = $ch->month) >= 0) {
            Chart::anchor($c, $x->at($i), $y->at($ch->sales[$i]), Node::CENTER, Node::END, function () use ($c, $ch, $i): void {
                $c->text(sprintf('%.0f', $ch->sales[$i]), 11, style: Font::BOLD)->noWrap()->margin(0, 0, 4, 0);
            });
        }
    })->label('Sales by month')->size(0, 200)->gapX(8)->gapY(6);
};

/** A line of the last minute of samples, which a timer adds to every second, with the point under the pointer, or the arrows, chosen. */
$liveChart = function (Context $c): void {
    $t = $c->theme;
    $ch = $this->chart;
    $n = count($this->samples);
    $end = $this->now->setTime((int)$this->now->format('H'), (int)$this->now->format('i'), (int)$this->now->format('s'));
    $x = new Time($end->modify('-59 seconds'), $end);
    $y = new Linear(0, 1, format: fn(float $v) => sprintf('%.0f%%', 100 * $v));
    [$xt, $yt] = [$x->ticks(4), $y->ticks(4)];
    $at = fn(int $i): DateTimeImmutable => $end->modify(($i - $n + 1) . ' seconds');
    $xs = $ys = [];
    foreach ($this->samples as $i => $v) { [$xs[], $ys[]] = [$x->at($at($i)), $y->at($v)]; }
    $describe = fn(int $i) => sprintf('%s: %.0f%%', $at($i)->format('H:i:s'), 100 * $this->samples[$i]);
    $c->text($ch->selected >= 0 && $ch->selected < $n ? $describe($ch->selected) : 'Point at the line, or focus it and use the arrows.', color: $t->textMuted);
    Chart::plot($c, new Axes(left: $yt, bottom: $xt, label: axisLabel($c)), function (Node $area) use ($c, $ch, $xs, $ys, $yt, $describe, $n, $t): void {
        $area->draw(function (Painter $p, array $r) use ($xs, $ys, $yt, $t): void {
            foreach ($yt as $tick) { $p->line($r[0], Chart::y($r, $tick->at), $r[0] + $r[2], Chart::y($r, $tick->at), 1, $t->border); }
            if (count($xs) < 2) { return; }
            $p->fillPath(Chart::area($r, $xs, null, $ys, Curve::Monotone), $t->accent, 0.12);
            $p->strokePath(Chart::line($r, $xs, $ys, Curve::Monotone), 2, $t->accent);
        });
        Chart::select($c, $area, $ch->selected, $xs, $describe);
        if (($i = $ch->selected) >= 0 && $i < $n) {
            Chart::ruleX($c, $xs[$i], 1)->background($t->textMuted);
            Chart::point($c, $xs[$i], $ys[$i], 10, 10)->radius(5)->background($t->accent)->border(2, $t->background);
        }
    })->label('Live samples')->size(0, 200)->gapX(8)->gapY(6);
};

/** Three series of areas piled up, as values or as shares. */
$stackedChart = function (Context $c): void {
    $t = $c->theme;
    $ch = $this->chart;
    $c->segmented(['Values', 'Shares'], $ch->stacking)->label('Stacking')->alignSelf(Node::START);
    $how = $ch->stacking === 1 ? Stacking::Normalized : Stacking::Standard;
    $stack = Chart::stack(CHART_VISITS, $how);
    $hi = max(array_map(fn($s) => $s->hi, end($stack)));
    $y = $how === Stacking::Normalized ? new Linear(0, 1, format: fn(float $v) => sprintf('%.0f%%', 100 * $v)) : (new Linear(0, $hi))->nice(4);
    $weeks = count(CHART_VISITS[0]);
    $x = new Linear(1, $weeks, format: fn(float $v) => sprintf('W%.0f', $v));
    [$xt, $yt] = [$x->ticks(6), $y->ticks(4)];
    $xs = array_map(fn(int $i) => $x->at($i + 1), range(0, $weeks - 1));
    Chart::plot($c, new Axes(left: $yt, bottom: $xt, label: axisLabel($c)), function (Node $area) use ($c, $stack, $xs, $y, $yt, $t): void {
        $area->draw(function (Painter $p, array $r) use ($stack, $xs, $y, $yt, $t): void {
            foreach ($yt as $tick) { $p->line($r[0], Chart::y($r, $tick->at), $r[0] + $r[2], Chart::y($r, $tick->at), 1, $t->border); }
            foreach ($stack as $s => $series) {
                $lo = array_map(fn($g) => $y->at($g->lo), $series);
                $hi = array_map(fn($g) => $y->at($g->hi), $series);
                $p->fillPath(Chart::area($r, $xs, $lo, $hi, Curve::Monotone), CHART_COLORS[$s], 0.75);
                $p->strokePath(Chart::line($r, $xs, $hi, Curve::Monotone), 1.5, CHART_COLORS[$s]);
            }
        });
    })->label('Visits by source')->size(0, 180)->gapX(8)->gapY(6);
    legend($c, CHART_SOURCES);
};

/** Cars as points, by weight and fuel use, in three classes, each point with a tooltip. */
$scatterChart = function (Context $c): void {
    $t = $c->theme;
    $ch = $this->chart;
    legend($c, ['Small', 'Midsize', 'Large']);
    $weights = $uses = [];
    foreach ($ch->scatter as $class) { foreach ($class as [$w, $u]) { [$weights[], $uses[]] = [$w, $u]; } }
    $x = (new Linear(...Chart::extent(...$weights)))->nice(4);
    $y = (new Linear(...Chart::extent(...$uses)))->nice(4);
    $x->format = fn(float $v) => sprintf('%.1f t', $v / 1000);
    $y->format = fn(float $v) => sprintf('%.0f L', $v);
    [$xt, $yt] = [$x->ticks(4), $y->ticks(4)];
    Chart::plot($c, new Axes(left: $yt, bottom: $xt, label: axisLabel($c)), function (Node $area) use ($c, $ch, $x, $y, $xt, $yt, $t): void {
        gridLines($c, $area, $xt, $yt, $t->border);
        foreach ($ch->scatter as $k => $class) {
            foreach ($class as [$w, $u]) {
                Chart::point($c, $x->at($w), $y->at($u), 9, 9)->radius(4.5)->background(CHART_COLORS[$k], 0.8)->border(1, CHART_COLORS[$k])
                    ->tooltip(sprintf('%.0f kg, %.1f L/100 km', $w, $u));
            }
        }
    })->label('Weight and fuel use of cars')->size(0, 200)->gapX(8)->gapY(6);
};

/** A ring divided among platforms, growing the slice under the pointer, whose share shows in the middle. */
$donutChart = function (Context $c): void {
    $t = $c->theme;
    $slices = Chart::pie(CHART_SHARES, 0.025);
    [$size, $inner, $outer] = [180.0, 56.0, 86.0];
    $c->row(0, 28)->alignItems(Node::CENTER);
      $ring = $c->column(0, 0)->size($size, $size)->label('Users by platform')->alignItems(Node::CENTER)->justify(Node::CENTER);
        $hover = null;
        [$px, $py, $over] = $c->pointerPosition($ring);
        if ($over) { $hover = Chart::sliceAt($slices, $px - $size / 2, $py - $size / 2, $inner, $outer); }
        $ring->draw(function (Painter $p, array $r) use ($slices, $hover, $size, $inner, $outer): void {
            foreach ($slices as $i => $s) {
                $p->fillPath(Chart::sector($r[0] + $size / 2, $r[1] + $size / 2, $inner, $i === $hover ? $outer : $outer - 4, $s), CHART_COLORS[$i]);
            }
        });
        $c->text($hover === null ? '4.2M' : sprintf('%.0f%%', CHART_SHARES[$hover]), 24, style: Font::BOLD);
        $c->text($hover === null ? 'users' : CHART_PLATFORMS[$hover], 12, $t->textMuted);
      $c->end();
      $c->column(0, 8);
        foreach (CHART_PLATFORMS as $i => $name) {
            $c->row(0, 8)->alignItems(Node::CENTER);
              $c->box(10, 10, CHART_COLORS[$i])->radius(3);
              $c->text($name, 13)->size(70, 0);
              $c->text(sprintf('%.0f%%', CHART_SHARES[$i]), 13, $t->textMuted);
            $c->end();
        }
      $c->end();
    $c->end();
};

$markdownPage = function (Context $c) use (&$streamMarkdown): void {
    $m = $this->md;
    $t = $c->theme;
    $c->text('Markdown::view shows Markdown, and Markdown::stream a reply as it streams in, at an even pace, fading in.', color: $t->textMuted);
    $c->row(0, 12)->alignItems(Node::CENTER)->wrap(0);
      if ($c->clicked($c->primaryButton('Stream it'))) { $streamMarkdown(); }
      $c->segmented(REVEAL_NAMES, $m->unit);
      $c->checkbox('Wrap code', $m->wrap);
    $c->end();
    $m->opts->reveal = new Reveal(REVEAL_UNITS[$m->unit]);
    $m->opts->wrapCode = $m->wrap;
    $c->column(0, 0, 0, $t->background)->padding(20, 24)->radius($t->radius + 4)->border(1, $t->border)->maxWidth(760);
      $m->done ? Markdown::view($c, $m->text, $m->opts) : Markdown::stream($c, $m->text, $m->opts);
    $c->end();
};

/** Streams the sample in, a few bytes at a time, at the uneven pace of a language model's tokens. */
$streamMarkdown = function (): void {
    $m = $this->md;
    $id = ++$m->stream;
    $m->text = ''; $m->done = false;
    if ($this->win === null) {
        $m->text = MARKDOWN_SAMPLE; $m->done = true;
        return;
    }
    $src = MARKDOWN_SAMPLE;
    $i = 0;
    $next = function () use (&$next, &$i, $src, $m, $id): void {
        if ($m->stream !== $id) { return; } // a new stream stopped this one
        if ($i >= strlen($src)) {
            $m->done = true;
            $this->win?->invalidate();
            return;
        }
        // Tokens of 1 to 8 bytes, in bursts, whole characters.
        $n = min(strlen($src) - $i, mt_rand(1, 8));
        while ($n < strlen($src) - $i && (ord($src[$i + $n]) & 0xc0) === 0x80) { $n++; }
        $i += $n;
        $m->text = substr($src, 0, $i);
        $this->win?->invalidate();
        App::after(mt_rand(10, 49) / 1000, $next);
    };
    App::after(0.01, $next);
};

/** A rich text editor with a toolbar formatting its selection, which shows the selection's formats. */
$richText = function (Context $c): void {
    $t = $c->theme;
    $doc = $this->note ??= new Document(sampleNote());
    $f = $doc->formatting();
    $button = function (string $label, bool $on, Closure $run) use ($c, $t): void {
        $b = $c->button($label)->keepFocus()->padding(4, 9)->minWidth(30);
        if ($on) { $b->background($t->accent, 0.18); }
        if ($c->clicked($b)) { $run(); }
    };
    $rule = fn() => $c->box(1, 20, $t->border);
    $c->row(0, 4)->wrap(0)->alignItems(Node::CENTER);
      $button('B', $f->bold === FormatState::FormatOn, $doc->toggleBold(...));
      $button('I', $f->italic === FormatState::FormatOn, $doc->toggleItalic(...));
      $button('U', $f->underline === FormatState::FormatOn, $doc->toggleUnderline(...));
      $button('S', $f->strikethrough === FormatState::FormatOn, $doc->toggleStrikethrough(...));
      $button('</>', $f->code === FormatState::FormatOn, $doc->toggleCode(...));
      $rule();
      foreach (['Body', 'H1', 'H2', 'H3'] as $level => $name) {
          $button($name, !$f->paragraphsMixed && $f->paragraph->heading === $level, fn() => $doc->setHeading($level));
      }
      $rule();
      $button('• List', $f->paragraph->list === ListKind::Bullets, fn() => $doc->toggleList(ListKind::Bullets));
      $button('1. List', $f->paragraph->list === ListKind::Numbers, fn() => $doc->toggleList(ListKind::Numbers));
      $button('☑ Tasks', $f->paragraph->list === ListKind::Tasks, fn() => $doc->toggleList(ListKind::Tasks));
      $button('❝', $f->paragraph->quote, $doc->toggleQuote(...));
      $button('{ }', $f->paragraph->codeBlock, $doc->toggleCodeBlock(...));
      $rule();
      $button('Left', $f->paragraph->align === Params::START, fn() => $doc->setAlign(Params::START));
      $button('Center', $f->paragraph->align === Params::CENTER, fn() => $doc->setAlign(Params::CENTER));
      $button('Right', $f->paragraph->align === Params::END, fn() => $doc->setAlign(Params::END));
      $rule();
      if ($c->clicked($c->button('Undo')->keepFocus()->disabled(!$doc->canUndo()))) { $doc->undo(); }
      if ($c->clicked($c->button('Redo')->keepFocus()->disabled(!$doc->canRedo()))) { $doc->redo(); }
    $c->end();
    RichText::editor($c, $doc, new EditorOptions(label: 'Note'))->size(0, 460);
    $c->text('⌘B, ⌘I, ⌘U, ⇧⌘X, ⌘E and ⌘K format; ⇧⌘7, ⇧⌘8 and ⇧⌘L make lists, Tab and ⇧Tab indent them; ⌥⌘1–3 make headings.', 12, $t->textMuted);
};

/**
 * A menu of languages, as an app's settings would have one, numbers
 * and dates in the language the window shows and the user's regional
 * formats, and controls that say MyPHP's own strings in it.
 */
$languages = function (Context $c): void {
    $t = $c->theme;
    $locale = $c->locale(); $region = $c->region();
    $formats = $region->formatLocale($locale);
    $c->text("The window shows one language: MyPHP's controls, its menus and the formats of numbers and dates follow it, and it lays out right to left in Arabic and Hebrew. The gallery's own text stays English.", color: $t->textMuted);
    card($c, 'Language');
      // The language chosen: "" follows the system's.
      $chosen = $this->language;
      $c->radioGroup(function () use ($c, &$chosen): void {
          foreach (['', ...GALLERY_LANGUAGES, I18n::PSEUDO_ACCENTS, I18n::PSEUDO_BIDI] as $tag) {
              $name = match ($tag) {
                  '' => 'System',
                  I18n::PSEUDO_ACCENTS => 'Pseudo accents (en-XA)',
                  I18n::PSEUDO_BIDI => 'Pseudo right to left (ar-XB)',
                  default => I18n::autonym($tag) . " ($tag)",
              };
              $c->radio($name, $chosen, $tag);
          }
      })->label('Language');
      if ($chosen !== $this->language) {
          $this->language = $chosen;
          if ($this->win !== null) { App::setLocale($chosen); }
      }
      $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
      $c->text(sprintf('Shows %s, %s; formats of %s: weeks start on %s, %d hours.',
          $locale ?: 'en', I18n::direction($locale)->value, $region->locale ?: 'the language', $weekdays[$region->firstWeekday % 7], $region->hourCycle), 13, $t->textMuted);
    $c->end();
    card($c, 'Numbers and dates');
      $now = $this->now;
      $rows = [
          ['Number', I18n::formatNumber($formats, 1234567.891)],
          ['Percent', I18n::formatNumber($formats, 0.256, new NumberOptions(percent: true))],
          ['Negative', I18n::formatNumber($formats, -42.5, new NumberOptions(minFractionDigits: 2))],
          ['Full date', I18n::formatDate($formats, $now, new DateOptions(DateStyle::Full))],
          ['Short date and time', I18n::formatDate($formats, $now, new DateOptions(DateStyle::Short, DateStyle::Short))],
          ['Month and year', I18n::formatDate($formats, $now, new DateOptions(skeleton: 'yMMMM'))],
          ['Time', I18n::formatDate($formats, $now, new DateOptions(skeleton: 'jm'))],
      ];
      foreach ($rows as [$label, $value]) {
          $c->row(0, 12);
            $c->text($label, color: $t->textMuted)->size(150, 0)->shrink(0);
            $c->text($value)->selectable();
          $c->end();
      }
      $c->row(0, 12);
        $c->text('Months', color: $t->textMuted)->size(150, 0)->shrink(0);
        $c->text(implode(' ', I18n::months($locale ?: 'en', Width::Abbreviated, true)))->grow(1);
      $c->end();
    $c->end();
    card($c, 'The same, in other locales');
      foreach (['en-US', 'en-GB', 'de-DE', 'fr-FR', 'hi-IN', 'ar-EG', 'fa-IR', 'ja-JP', 'zh-Hans-CN'] as $l) {
          $c->row(0, 12)->wrap(0)->gapY(2); // a narrow window puts the date under the number
            $c->text($l, color: $t->textMuted)->size(90, 0)->shrink(0);
            $c->text(I18n::formatNumber($l, 1234567.891))->size(150, 0)->shrink(0);
            $c->text(I18n::formatDate($l, $this->now, new DateOptions(DateStyle::Medium, DateStyle::Short)))->grow(1);
          $c->end();
      }
    $c->end();
    card($c, 'Controls');
      $c->row(0, 24)->alignItems(Node::START)->wrap(0);
        $c->calendar($this->meeting);
        $c->column(0, 12);
          $c->field('Day', fn() => $c->dateInput($this->meeting));
          $c->field('Time', fn() => $c->timeInput($this->meeting));
          $c->searchField($this->search)->label('Search');
        $c->end();
      $c->end();
    $c->end();
};

return function (Context $c) use (&$sidebar, &$toolbar, &$page): void {
    $root = $c->row(0, 0, 1);
    if ($this->rtl) { $root->direction(Direction::RightToLeft); } // given first, so that what is built inside reads it
    $root->fill()->alignItems(Node::STRETCH);
      $sidebar($c);
      $c->column(0, 0, 1)->minWidth(0);
        $toolbar($c);
        // The pages, which keep their place in the history: back on one, it is scrolled where it was.
        $this->router->view($c, function (Route $r) use ($c, $page): void {
            $c->scroll(0, 18, 1)->padding(12, 32, 28);
              $page($c, $r);
            $c->end();
        });
      $c->end();
    $c->end();
    // Cmd+1…9 switch to the first nine pages.
    foreach (array_slice(PAGES, 0, 9) as $i => $p) {
        if ($c->shortcut((string)($i + 1), Key::COMMAND)) { $this->router->push(pagePath($p)); }
    }
};
