<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\{App, Clipboard, DateOptions, Dialog, I18n, MessageOptions, NumberOptions, Style as DateStyle, Width, Window};
use MyPHP\Plugins\Glass\{Blur, Glass, ScrollEdge, Style as GlassStyle};
use MyPHP\Text\Params;
use MyPHP\Transfer\{Drop, DropOptions, Format};
use MyPHP\UI\{Anchor, Oklch, BorderStyle, GradientStop, RadialGradient, ContentTransition, Context, Cursor, Direction, DraggableOptions, Easing, ElementTransition, Font as Face, Keyframe, KeyframeOptions, Key, LinearGradient, Menu, MeterLevels, Motion, Node, Painter, Pose, Route, Span, SpringOptions, SVG, TableColumn, TextState, Theme, Track, TransitionMotion, WideColor};
use MyPHP\UI\Markdown\Reveal;
use MyPHP\UI\RichText\{Document, EditorOptions, FormatState, ListKind, RichText};

// The gallery in SwiftUI's views: a sidebar of pages beside the page chosen, under a toolbar going back and forward
// through the history. What SwiftUI has a view of is that view; what only MyPHP's engine has (its bases, grids of
// tracks, borders of each side, stripes, cursors, the outline table, the grid view, the theme-drawn menus, its motion
// primitives) is built with the engine's builder in a representable, as an NSView is in SwiftUI.

/** A part built with the engine's builder, as an NSView in a representable: what SwiftUI has no view of. */
$legacy = fn(Closure $build) => Representable(function (Context $c) use ($build) {
    $n = $c->column(0, 16)->alignSelf(Node::STRETCH);
      $build($c);
    $c->end();
    return $n;
});

/** A card: a titled panel. */
$card = fn(string $title, View|array|null ...$body) => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    $title === '' ? null : Text($title)->font(Font::system(size: 15, weight: Weight::bold)), ...$body,
)->padding(18)->frame(maxWidth: INF, alignment: Alignment::topLeading)->background(Color::background)->cornerRadius(10)
    ->overlay(RoundedRectangle(cornerRadius: 10)->stroke(Color::separator))->shadow(Color::black->opacity(0.06), radius: 1.5, y: 1);

/** A label leading a control in a row. */
$row = fn(string $label, float $width, View ...$views) => HStack(spacing: 12)(Text($label)->foregroundStyle(Color::secondary)->frame(width: $width, alignment: Alignment::leading), ...$views);

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
                  $c->text($label, 12, style: Face::BOLD);
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
          $c->text($label, 12, 0x000000, Face::BOLD);
        $c->end();
        return $n;
    };
    $in = fn(bool $wide, int $color): int => $wide ? $color : WideColor::srgb($color); // the color, or its sRGB twin
    $pair(fn(bool $wide) => $c->text($wide ? 'Oklch' : 'sRGB', 12, $t->textMuted, Face::BOLD));
    $pair(fn(bool $wide) => $box('Fill')->background($in($wide, $vivid)));
    $pair(fn(bool $wide) => $box('Gradient')->linearGradient(new LinearGradient($in($wide, $warm), 1, $in($wide, $vivid), 1, 90, oklab: true)));
    $pair(fn(bool $wide) => $box('Stripes')->background(0xffffff)->stripes($in($wide, $vivid), 4, 6, 45));
    $pair(function (bool $wide) use ($c, $in, $vivid): Node {
        $n = $c->row(0, 0, 0, 0x141414)->size(0, 30)->radius(6)->center();
          $c->text('Text', 14, $in($wide, $vivid), Face::BOLD);
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
          $c->text('A header with a line below', style: Face::BOLD);
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
              $c->text($label, 12, 0xffffff, Face::BOLD);
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
            $c->text('3', 10, 0xffffff, Face::BOLD);
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
                $c->text(sprintf('Photo %d', $i + 1), style: Face::BOLD);
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
          $c->text('Done', color: $t->accentText, style: Face::BOLD);
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
            $c->text('Nested interactive content', 12, style: Face::BOLD);
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
            $c->text('Four timed keyframes', 12, $t->accentText, Face::BOLD);
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
            $c->text('Throw me', 12, $t->accentText, Face::BOLD);
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
                  $c->text(sprintf('Tile %d', $i + 1), style: Face::BOLD)->grow(1);
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
              $c->text('Front', style: Face::BOLD);
              $c->text('Click to turn over', 12, $t->textMuted);
            $c->end();
            $back = $c->key('back')->column(0, 0, 0, $t->accent)->absolute()->fill()->center()->radius(14)->rotateY($turn + 180)->perspective(800)->backfaceHidden();
              $c->text('Back', color: $t->accentText, style: Face::BOLD);
            $c->end();
            if ($c->clicked($front) || $c->clicked($back)) { $this->cardFlipped = !$this->cardFlipped; }
          $c->end();
        $c->end();
      $c->end();
      card($c, 'Counters, check marks and lifts');
        $c->text('Digits roll as they change, a check mark draws itself in, and tiles lift in frames that only paint.', 12, $t->textMuted);
        $c->row(0, 16)->wrap(0)->gapY(8)->alignItems(Node::CENTER);
          if ($c->clicked($c->button('−'))) { $this->counter--; $this->countDown = true; }
          $c->text((string)$this->counter, 32, style: Face::BOLD)->size(80, 0)->textAlign(Node::CENTER)->contentTransition(new ContentTransition(numeric: true, down: $this->countDown));
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
                  $c->text((string)(3 + $i * 9), 10, 0xffffff, Face::BOLD);
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

$overview = fn() => [
    Text('Everything here is laid out and drawn by MyPHP itself: no HTML, no JavaScript, no extension but FFI. The view is a PHP function of the app\'s state that runs again after every event.')->foregroundStyle(Color::secondary),
    HStack(alignment: VerticalAlignment::top, spacing: 16)(
        $card('Counter',
            Text(verbatim: (string)$this->count)->font(Font::system(size: 40, weight: Weight::bold)),
            HStack(spacing: 8)(Button('−', action: fn() => $this->count--)->frame(width: 44), Button('Increment', action: fn() => $this->count++)->buttonStyle(ButtonStyle::borderedProminent)),
        )->frame(width: 300),
        $card('Live data',
            Text('A timer pushes a sample every second.')->foregroundStyle(Color::secondary)->frame(maxWidth: 260, alignment: Alignment::leading),
            Canvas(function (GraphicsContext $g, CGSize $s) {
                $n = count($this->samples);
                if ($n < 2) { return; }
                $path = new Path();
                foreach ($this->samples as $i => $v) {
                    [$x, $y] = [6 + $i / ($n - 1) * ($s->width - 12), $s->height - 6 - $v * ($s->height - 12)];
                    $i === 0 ? $path->moveTo($x, $y) : $path->lineTo($x, $y);
                }
                $g->stroke($path, with: Color::accentColor, lineWidth: 2);
            })->frame(width: 260, height: 80)->background(Color::gray->opacity(0.12))->cornerRadius(6),
        )->frame(width: 300),
        $card('Files',
            VStack(spacing: 2)(
                $this->files === [] ? Text('Drop files here')->foregroundStyle(Color::secondary) : null,
                Each(array_slice($this->files, 0, 3))(fn(string $f) => Text(verbatim: basename($f))->font(Font::caption)->lineLimit(1)),
                count($this->files) > 3 ? Text(verbatim: sprintf('and %d more', count($this->files) - 3))->font(Font::caption)->foregroundStyle(Color::secondary) : null,
            )
                ->padding(Edge::vertical, 8)->padding(Edge::horizontal, 12)->frame(width: 260, height: 80)->background(Color::gray->opacity(0.12))->cornerRadius(6)
                ->overlay(RoundedRectangle(cornerRadius: 6)->stroke($this->filesTargeted ? Color::accentColor : Color::separator, lineWidth: $this->filesTargeted ? 2 : 1))
                ->dropDestination(for: new DropOptions([Format::FILE_LIST]), action: fn(Drop $d) => $this->files = $d->data->paths(), isTargeted: fn(bool $over) => $this->filesTargeted = $over),
        )->frame(width: 300),
    ),
];

$controls = function () use ($card, $legacy, $row) {
    $path = ['Macintosh HD', 'Users', 'ada', 'Documents', 'Reports'];
    return [
    $card('Choices',
        Toggle('I agree to the terms', isOn: $this->agree)->toggleStyle(ToggleStyle::checkbox),
        HStack(spacing: 10)(Toggle('Notifications', isOn: $this->notify)->toggleStyle(ToggleStyle::switch)->labelsHidden(), Text($this->notify ? 'Notifications on' : 'Notifications off')),
        Picker('Plan', selection: $this->plan)(Each(['Free', 'Pro', 'Team'])(fn(string $p) => Text($p)->tag($p)))->pickerStyle(PickerStyle::radioGroup)->horizontalRadioGroupLayout(),
        Picker('Size', selection: $this->size)(Each(SIZES)(fn(string $s, int $i) => Text($s)->tag($i))),
    ),
    $card('Search and choose',
        HStack(spacing: 10)(
            SearchField(text: $this->searchText)->frame(width: 220),
            ComboBox('Font', text: $this->font, items: ['Avenir', 'Courier', 'Futura', 'Georgia', 'Gill Sans', 'Helvetica', 'Menlo', 'Optima', 'Palatino', 'Times'])->frame(width: 200),
            TextField('City', text: $this->cityText)->textInputSuggestions(['Amsterdam', 'Berlin', 'Lisbon', 'London', 'Madrid', 'Paris', 'Prague', 'Rome', 'Vienna'])->frame(width: 200),
        )->labelsHidden(),
        TokenField('Tags', tokens: $this->tags, suggestions: ['design', 'native', 'performance', 'php', 'release', 'typescript'])->labelsHidden(),
        Text('Type to filter; Up and Down move, Enter chooses. In the tags, Enter or a comma adds a tag, and Backspace takes out the last.')->foregroundStyle(Color::secondary),
    ),
    $legacy(function (Context $c): void {
        $t = $c->theme;
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
    }),
    $card('Groups and paths',
        // A toggle of toggles: mixed while some are on; switching it switches them all.
        Toggle('Notifications', sources: [&$this->notifyMail, &$this->notifyCalendar, &$this->notifyMessages]),
        VStack(alignment: HorizontalAlignment::leading, spacing: 6)(Toggle('Mail', isOn: $this->notifyMail), Toggle('Calendar', isOn: $this->notifyCalendar), Toggle('Messages', isOn: $this->notifyMessages))->padding(Edge::leading, 20),
        Breadcrumbs(array_slice($path, 0, $this->pathDepth + 1), selection: $this->pathAt)->onChange(of: $this->pathAt, action: fn() => $this->pathDepth = $this->pathAt),
        $this->pathDepth < count($path) - 1 ? Button('Open ' . $path[$this->pathDepth + 1], action: function () { $this->pathDepth++; $this->pathAt = $this->pathDepth; }) : null,
    ),
    $card('Disclosure',
        DisclosureGroup('Advanced options', isExpanded: $this->advanced)(VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
            Toggle('Verbose logging', isOn: $this->verbose),
            Text('The arrow turns and the content grows into view, at once where the desktop asks for less motion.')->foregroundStyle(Color::secondary),
        )),
        // An accordion: groups in a box, each opening by itself.
        GroupBox()(VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
            DisclosureGroup('General', isExpanded: $this->sections[0])(Text('Startup, appearance and updates.')),
            Divider(),
            DisclosureGroup('Privacy', isExpanded: $this->sections[1])(Toggle('Share usage data', isOn: $this->share)),
            Divider(),
            DisclosureGroup('Keyboard', isExpanded: $this->sections[2])(Text('Up and Down move between the headers; Enter and Space open and close them.')->foregroundStyle(Color::secondary)),
        )),
    ),
    $card('Ranges',
        HStack(spacing: 12)(Slider(value: $this->volume, in: [0, 100], title: 'Volume')->labelsHidden(), Text(verbatim: sprintf('%3.0f%%', $this->volume))->frame(width: 48, alignment: Alignment::trailing)),
        ProgressView(value: $this->volume / 100),
        ProgressView('Working'),
        Stepper(sprintf('Copies: %d', $this->copies), value: $this->copies, in: [1, 99]),
        $row('Quality', 64, Slider(value: $this->quality, in: [0, 100], step: 25, title: 'Quality')->labelsHidden(), Text(verbatim: sprintf('%3.0f%%', $this->quality))->frame(width: 48, alignment: Alignment::trailing)),
        $row('Price', 64, RangeSlider(lower: $this->priceLow, upper: $this->priceHigh, in: [0, 500], step: 10, title: 'Price')->labelsHidden(), Text(verbatim: sprintf('$%.0f–%.0f', $this->priceLow, $this->priceHigh))->frame(width: 72, alignment: Alignment::trailing)),
    ),
    $card('Dates, times and colors',
        HStack(alignment: VerticalAlignment::top, spacing: 20)(
            DatePicker('Meeting', selection: $this->meeting, displayedComponents: [DatePickerComponents::date])->datePickerStyle(DatePickerStyle::graphical)->labelsHidden(),
            VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
                $row('Time', 48, DatePicker('Meeting', selection: $this->meeting, displayedComponents: [DatePickerComponents::hourAndMinute])->labelsHidden()),
                $row('Tint', 48, ColorPicker('Tint', selection: $this->tint, supportsOpacity: true, opacity: $this->tintAlpha)->labelsHidden()),
                // A day picked in the popover closes it; browsing the months does not.
                $row('Due', 48, Button($this->due->format('M j'), action: fn() => $this->dueOpen = !$this->dueOpen)
                    ->popover(isPresented: $this->dueOpen, content: fn() => DatePicker('Due', selection: $this->due, displayedComponents: [DatePickerComponents::date])->datePickerStyle(DatePickerStyle::graphical)->labelsHidden()
                        ->onChange(of: $this->due, action: fn() => $this->dueOpen = false)->padding(8))),
                Text(verbatim: $this->meeting->format('l, F j \a\t H:i'))->foregroundStyle(Color::rgb($this->tint, $this->tintAlpha)),
            ),
        ),
    ),
    $card('Indicators',
        HStack(spacing: 12)(ProgressView(), Text('Syncing…')->foregroundStyle(Color::secondary), Spacer(), Rating('Rating', value: $this->stars)->labelsHidden()),
        Text(verbatim: sprintf('Disk: %.0f of 500 GB', 412))->font(Font::caption)->foregroundStyle(Color::secondary),
        Gauge(value: 412, in: [0, 500], title: 'Disk')->labelsHidden(),
        Text(verbatim: sprintf('Battery: %.0f%%', $this->battery))->font(Font::caption)->foregroundStyle(Color::secondary),
        HStack(spacing: 10)(Gauge(value: $this->battery, in: [0, 100], title: 'Battery')->labelsHidden(), Stepper('Battery', value: $this->battery, in: [0, 100], step: 5)->labelsHidden()),
        HStack(spacing: 8)(Each(['Ada Lovelace', 'Grace Hopper', 'Alan Turing', 'Margaret Hamilton'])(fn(string $name) => Avatar($name)->help($name))),
    ),
    $card('Buttons',
        HStack(spacing: 8)(
            Button('Save', action: fn() => $this->toast = 'Saved')->buttonStyle(ButtonStyle::borderedProminent),
            Button('Cancel'),
            Button('Disabled')->disabled(),
            Menu('Export')(Each(['PDF', 'PNG', 'SVG'])(fn(string $as) => Button("As $as", action: fn() => $this->toast = "Exported as $as"))),
            WebLink('Open MyPHP on GitHub', destination: 'https://github.com/owner888/myphp'),
        ),
        Text('Tab moves the focus; Enter or Space presses the focused button.')->foregroundStyle(Color::secondary),
    ),
    $legacy(function (Context $c): void {
        $t = $c->theme;
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
    }),
    $card('Tabs and panes',
        Picker('Tab', selection: $this->tab)(Text('Files')->tag(0), Text('Search')->tag(1), Text('History')->tag(2))->pickerStyle(PickerStyle::segmented)->labelsHidden(),
        HSplitView()(
            VStack(alignment: HorizontalAlignment::leading, spacing: 6)(Each([['index.php', 'composer.json', 'README.md'], ['Results'], ['Yesterday', 'Last week']][$this->tab])(fn(string $name) => Text(verbatim: $name)->lineLimit(1)))
                ->padding(10)->frame(idealWidth: 160, maxHeight: INF, alignment: Alignment::topLeading)->background(Color::gray->opacity(0.12)),
            Text('Drag the divider, or focus it and press the arrows.')->foregroundStyle(Color::secondary)->padding(10)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading),
        )->frame(height: 140)->cornerRadius(6)->overlay(RoundedRectangle(cornerRadius: 6)->stroke(Color::separator)),
    ),
    $legacy(function (Context $c): void {
        $t = $c->theme;
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
    }),
    ];
};

/** The text page: the engine's fields, forms, editors and find bar, as NSViews in representables. */
$text = fn() => [$legacy(fn(Context $c) => $this->text($c))];

/** A rich text editor with a toolbar formatting its selection, which shows the selection's formats; its buttons leave the focus in the editor. */
$richText = function () {
    $doc = $this->note ??= new Document(sampleNote());
    $f = $doc->formatting();
    $b = fn(string $label, bool $on, Closure $run) => Button($label, action: $run)->padding(Edge::horizontal, 2)->background($on ? Color::accentColor->opacity(0.18) : Color::clear)->cornerRadius(5);
    $rule = fn() => Divider()->frame(height: 20);
    return [
        HStack(spacing: 4)(
            $b('B', $f->bold === FormatState::FormatOn, $doc->toggleBold(...)),
            $b('I', $f->italic === FormatState::FormatOn, $doc->toggleItalic(...)),
            $b('U', $f->underline === FormatState::FormatOn, $doc->toggleUnderline(...)),
            $b('S', $f->strikethrough === FormatState::FormatOn, $doc->toggleStrikethrough(...)),
            $b('</>', $f->code === FormatState::FormatOn, $doc->toggleCode(...)),
            $rule(),
            Each(['Body', 'H1', 'H2', 'H3'])(fn(string $name, int $level) => $b($name, !$f->paragraphsMixed && $f->paragraph->heading === $level, fn() => $doc->setHeading($level))),
            $rule(),
            $b('• List', $f->paragraph->list === ListKind::Bullets, fn() => $doc->toggleList(ListKind::Bullets)),
            $b('1. List', $f->paragraph->list === ListKind::Numbers, fn() => $doc->toggleList(ListKind::Numbers)),
            $b('☑ Tasks', $f->paragraph->list === ListKind::Tasks, fn() => $doc->toggleList(ListKind::Tasks)),
            $b('❝', $f->paragraph->quote, $doc->toggleQuote(...)),
            $b('{ }', $f->paragraph->codeBlock, $doc->toggleCodeBlock(...)),
            $rule(),
            $b('Left', $f->paragraph->align === Params::START, fn() => $doc->setAlign(Params::START)),
            $b('Center', $f->paragraph->align === Params::CENTER, fn() => $doc->setAlign(Params::CENTER)),
            $b('Right', $f->paragraph->align === Params::END, fn() => $doc->setAlign(Params::END)),
            $rule(),
            Button('Undo', action: fn() => $doc->undo())->disabled(!$doc->canUndo()),
            Button('Redo', action: fn() => $doc->redo())->disabled(!$doc->canRedo()),
        ),
        TextEditor(document: $doc, options: new EditorOptions(label: 'Note'))->frame(height: 460),
        Text('⌘B, ⌘I, ⌘U, ⇧⌘X, ⌘E and ⌘K format; ⇧⌘7, ⇧⌘8 and ⇧⌘L make lists, Tab and ⇧Tab indent them; ⌥⌘1–3 make headings.')->font(Font::caption)->foregroundStyle(Color::secondary),
    ];
};

/** 10,000 rows, filtered; a row opens in a page of its own. Then the chat, the tree, the table, the outline and the grid view, the engine's. */
$list = function () use ($legacy, &$chatCard, &$sortedFiles) {
    $rows = [];
    for ($i = 0; $i < 10000; $i++) { if ($this->filterText === '' || str_contains((string)$i, $this->filterText)) { $rows[] = $i; } }
    $open = fn(int $n) => $this->router->push("/list/$n");
    return [
        TextField('Filter', text: $this->filterText, prompt: 'Filter 10,000 rows'),
        ScrollViewReader(fn(ScrollViewProxy $proxy) => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
            HStack(spacing: 12)(
                Text(verbatim: sprintf('%d rows; only those in view are built. Pick one with a click or the arrows, open it with a double click or Enter; right-click one for its menu.', count($rows)))->foregroundStyle(Color::secondary),
                Spacer(),
                Button('Show picked', action: fn() => $proxy->scrollTo($this->picked))->disabled(!in_array($this->picked, $rows, true)), // the row may not be built: the list scrolls to it all the same
            ),
            ListView($rows, selection: $this->picked)(fn(int $n) => HStack(spacing: 10)(
                Text(verbatim: "Row $n" . (($this->starred[$n] ?? false) ? '  ★' : '')),
                Spacer(),
                Text(verbatim: sprintf('%d²  =  %d', $n, $n * $n))->font(Font::caption)->monospaced(),
            ))
                ->contextMenu(menu: function (array $ids) use ($open) {
                    $n = $ids[0];
                    $this->starred[$n] ??= false;
                    return [Button('Open', action: fn() => $open($n)), Button('Pick', action: fn() => $this->picked = $n), Toggle('Starred', isOn: $this->starred[$n]), Divider(),
                        Button('Copy Square', action: fn() => Clipboard::writeText((string)($n * $n)))];
                }, primaryAction: fn(array $ids) => $open($ids[0]))
                ->frame(height: 420)->overlay(RoundedRectangle(cornerRadius: 8)->stroke(Color::separator)),
        )),
        $legacy(function (Context $c) use (&$chatCard, &$sortedFiles): void {
            $t = $c->theme;
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
        }),
    ];
};

/** The charts, as Swift Charts'. */
$charts = function () use ($card) {
    $ch = $this->chart;
    $styles = fn(array $names) => array_combine($names, array_map(fn(int $i) => Color::rgb(CHART_COLORS[$i]), array_keys($names)));
    $n = count($this->samples);
    $timeOf = fn(int $i) => $this->now->modify(($i - $n + 1) . ' seconds');
    $i = $ch->at === null ? null : $n - 1 - ($this->now->getTimestamp() - $ch->at->getTimestamp());
    if ($i !== null && !isset($this->samples[$i])) { $i = null; }
    $chosen = null;
    if ($ch->angle !== null) { $sum = 0; foreach (CHART_SHARES as $k => $v) { $sum += $v; if ($chosen === null && $ch->angle <= $sum) { $chosen = $k; } } }
    return [
        Text('Swift Charts\' marks, laid out on the scales the chart picks from their values.')->foregroundStyle(Color::secondary),
        LazyVGrid(columns: [GridItem::adaptive(minimum: 320)], spacing: 16)(
            $card('Sales',
                Text(verbatim: $ch->hoverMonth !== null ? sprintf('%s: $%.1fk', $ch->hoverMonth, $ch->sales[array_search($ch->hoverMonth, CHART_MONTHS, true)]) : 'Point at a bar to see its month.')->foregroundStyle(Color::secondary),
                Chart(array_map(null, CHART_MONTHS, $ch->sales))(fn(array $s) => BarMark(x: PlottableValue::value('Month', $s[0]), y: PlottableValue::value('Sales', $s[1]))
                    ->cornerRadius(3)->opacity($ch->hoverMonth !== null && $ch->hoverMonth !== $s[0] ? 0.35 : 1.0)
                    ->annotation(fn() => $ch->hoverMonth === $s[0] ? Text(sprintf('%.0f', $s[1]))->font(Font::caption)->bold() : null))
                    ->chartXSelection(value: $ch->hoverMonth)->chartYAxis(new AxisMarks(format: fn(float $v) => $v == 0 ? '$0' : sprintf('$%.0fk', $v)))->frame(height: 200),
            ),
            $card('Live data',
                Text(verbatim: $i !== null ? sprintf('%s: %.0f%%', $ch->at->format('H:i:s'), 100 * $this->samples[$i]) : 'Point at the line, or focus it and use the arrows.')->foregroundStyle(Color::secondary),
                Chart()(array_map(fn(int $k) => [
                    AreaMark(x: PlottableValue::value('Time', $timeOf($k)), y: PlottableValue::value('Load', $this->samples[$k]), stacking: MarkStackingMethod::unstacked)->foregroundStyle(Color::accentColor->opacity(0.12))->interpolationMethod(InterpolationMethod::monotone),
                    LineMark(x: PlottableValue::value('Time', $timeOf($k)), y: PlottableValue::value('Load', $this->samples[$k]))->foregroundStyle(Color::accentColor)->interpolationMethod(InterpolationMethod::monotone),
                ], array_keys($this->samples)), $i !== null ? [RuleMark(x: $ch->at), PointMark(x: $ch->at, y: $this->samples[$i])->foregroundStyle(Color::accentColor)->symbolSize(100)] : null)
                    ->chartXSelection(value: $ch->at)->chartYScale(domain: [0, 1])->chartYAxis(new AxisMarks(format: fn(float $v) => sprintf('%.0f%%', 100 * $v)))->frame(height: 200),
            ),
            $card('Visits by source',
                Picker('Stacking', selection: $ch->stacking)(Text('Values')->tag(0), Text('Shares')->tag(1))->pickerStyle(PickerStyle::segmented)->labelsHidden(),
                Chart()(...array_merge(...array_map(fn(int $s) => array_map(fn(int $w) => AreaMark(x: PlottableValue::value('Week', $w + 1), y: PlottableValue::value('Visits', CHART_VISITS[$s][$w]),
                    stacking: $ch->stacking === 1 ? MarkStackingMethod::normalized : MarkStackingMethod::standard)->foregroundStyle(by: PlottableValue::value('Source', CHART_SOURCES[$s]))->interpolationMethod(InterpolationMethod::monotone)->opacity(0.8),
                    array_keys(CHART_VISITS[$s])), array_keys(CHART_SOURCES))))
                    ->chartForegroundStyleScale($styles(CHART_SOURCES))->chartXScale(domain: [1, 12])->chartXAxis(new AxisMarks(desiredCount: 6, format: fn(float $v) => sprintf('W%.0f', $v)))->frame(height: 200),
            ),
            $card('Weight and fuel use',
                Chart()(...array_merge(...array_map(fn(int $k) => array_map(fn(array $car) => PointMark(x: PlottableValue::value('Weight', $car[0]), y: PlottableValue::value('Fuel use', $car[1]))
                    ->foregroundStyle(by: PlottableValue::value('Class', ['Small', 'Midsize', 'Large'][$k]))->opacity(0.8), $ch->scatter[$k]), array_keys($ch->scatter))))
                    ->chartForegroundStyleScale($styles(['Small', 'Midsize', 'Large']))->chartXAxis(new AxisMarks(desiredCount: 4, format: fn(float $v) => sprintf('%.1f t', $v / 1000)))
                    ->chartYAxis(new AxisMarks(format: fn(float $v) => sprintf('%.0f L', $v)))->frame(height: 200),
            ),
            $card('Users by platform', HStack(spacing: 20)(
                ZStack()(
                    Chart(array_keys(CHART_PLATFORMS))(fn(int $k) => SectorMark(angle: PlottableValue::value('Share', CHART_SHARES[$k]), innerRadius: MarkDimension::ratio(0.62),
                        outerRadius: MarkDimension::ratio($chosen === $k ? 1.0 : 0.95), angularInset: 1.5)->foregroundStyle(by: PlottableValue::value('Platform', CHART_PLATFORMS[$k])))
                        ->chartForegroundStyleScale($styles(CHART_PLATFORMS))->chartLegend(Visibility::hidden)->chartAngleSelection(value: $ch->angle),
                    VStack(spacing: 0)(
                        Text(verbatim: $chosen === null ? '4.2M' : sprintf('%.0f%%', CHART_SHARES[$chosen]))->font(Font::system(size: 24, weight: Weight::bold)),
                        Text(verbatim: $chosen === null ? 'users' : CHART_PLATFORMS[$chosen])->font(Font::caption)->foregroundStyle(Color::secondary),
                    )->allowsHitTesting(false),
                )->frame(width: 140, height: 140),
                VStack(alignment: HorizontalAlignment::leading, spacing: 8)(Each(array_keys(CHART_PLATFORMS))(fn(int $k) => HStack(spacing: 8)(
                    RoundedRectangle(cornerRadius: 3)->fill(Color::rgb(CHART_COLORS[$k]))->frame(width: 10, height: 10),
                    Text(verbatim: CHART_PLATFORMS[$k]), Spacer(minLength: 8), Text(verbatim: sprintf('%.0f%%', CHART_SHARES[$k]))->foregroundStyle(Color::secondary),
                )))->frame(width: 120),
            )),
        ),
    ];
};

/** Markdown shown, and streamed in as a reply streams, at an even pace, fading in. */
$markdownPage = function () use (&$streamMarkdown) {
    $m = $this->md;
    $m->opts->reveal = new Reveal(REVEAL_UNITS[$m->unit]);
    $m->opts->wrapCode = $m->wrap;
    return [
        Text('Markdown shows Markdown, streaming a reply as it comes in, at an even pace, fading in.')->foregroundStyle(Color::secondary),
        HStack(spacing: 12)(
            Button('Stream it', action: fn() => $streamMarkdown())->buttonStyle(ButtonStyle::borderedProminent),
            Picker('Reveal', selection: $m->unit)(Each(REVEAL_NAMES)(fn(string $name, int $k) => Text($name)->tag($k)))->pickerStyle(PickerStyle::segmented)->labelsHidden(),
            Toggle('Wrap code', isOn: $m->wrap)->toggleStyle(ToggleStyle::checkbox),
        ),
        Markdown($m->text, $m->opts, streaming: !$m->done)->padding(Edge::vertical, 20)->padding(Edge::horizontal, 24)
            ->frame(maxWidth: 760, alignment: Alignment::leading)->background(Color::background)->cornerRadius(10)->overlay(RoundedRectangle(cornerRadius: 10)->stroke(Color::separator)),
    ];
};

/** Dialogs, menus, tooltips, alerts and toasts over the window. */
$overlays = fn() => [
    $card('Overlays', HStack(spacing: 10)(
        Button('Open dialog', action: fn() => $this->dialog = true)->buttonStyle(ButtonStyle::borderedProminent),
        Menu('Menu')(Button('New file'), Button('Open…'), Button('Save as…')),
        $legacy(function (Context $c): void {
            $t = $c->theme;
            $c->row(0, 10)->alignItems(Node::CENTER);
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
        if ($c->clicked($c->button('Delete a note'))) {
            $this->notes--;
            $c->toastAction('Note deleted', 'Undo', function (): void { $this->notes++; });
        }
            $c->end();
        }),
        Button('Hover me')->help('Tooltips show after the pointer rests a moment.'),
        Button('Alert', action: fn() => $this->alert = true),
        Button('Native dialog', action: fn() => App::later(fn() => Dialog::message(new MessageOptions(parent: $this->win, message: 'Native dialogs work from MyPHP UI windows too.')))), // after the click, not in it: the dialog runs its own loop
    )),
    Text(verbatim: sprintf('%d notes. Undo in the toast brings a deleted one back.', $this->notes))->font(Font::caption)->foregroundStyle(Color::secondary),
];

/** A menu of languages, as an app's settings would have one, numbers and dates in the language the window shows and the user's regional formats, and controls that say MyPHP's own strings in it. */
$languages = function () use ($card, $row) {
    $locale = App::locale(); $region = App::region();
    $formats = $region->formatLocale($locale);
    $language = new Binding(get: fn() => $this->language, set: function (string $l) { $this->language = $l; if ($this->win !== null) { App::setLocale($l); } }); // '' follows the system's
    $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $now = $this->now;
    $rows = [
        ['Number', I18n::formatNumber($formats, 1234567.891)],
        ['Percent', I18n::formatNumber($formats, 0.256, new NumberOptions(percent: true))],
        ['Negative', I18n::formatNumber($formats, -42.5, new NumberOptions(minFractionDigits: 2))],
        ['Full date', I18n::formatDate($formats, $now, new DateOptions(DateStyle::Full))],
        ['Short date and time', I18n::formatDate($formats, $now, new DateOptions(DateStyle::Short, DateStyle::Short))],
        ['Month and year', I18n::formatDate($formats, $now, new DateOptions(skeleton: 'yMMMM'))],
        ['Time', I18n::formatDate($formats, $now, new DateOptions(skeleton: 'jm'))],
        ['Months', implode(' ', I18n::months($locale ?: 'en', Width::Abbreviated, true))],
    ];
    return [
        Text("The window shows one language: MyPHP's controls, its menus and the formats of numbers and dates follow it, and it lays out right to left in Arabic and Hebrew. The gallery's own text stays English.")->foregroundStyle(Color::secondary),
        $card('Language',
            Picker('Language', selection: $language)(Each(['', ...GALLERY_LANGUAGES, I18n::PSEUDO_ACCENTS, I18n::PSEUDO_BIDI])(fn(string $tag) => Text(verbatim: match ($tag) {
                '' => 'System',
                I18n::PSEUDO_ACCENTS => 'Pseudo accents (en-XA)',
                I18n::PSEUDO_BIDI => 'Pseudo right to left (ar-XB)',
                default => I18n::autonym($tag) . " ($tag)",
            })->tag($tag)))->pickerStyle(PickerStyle::radioGroup)->labelsHidden(),
            Text(verbatim: sprintf('Shows %s, %s; formats of %s: weeks start on %s, %d hours.', $locale ?: 'en', I18n::direction($locale)->value, $region->locale ?: 'the language', $weekdays[$region->firstWeekday % 7], $region->hourCycle))
                ->font(Font::system(size: 13))->foregroundStyle(Color::secondary),
        ),
        $card('Numbers and dates', Each($rows, id: fn(array $r) => $r[0])(fn(array $r) => $row($r[0], 150, Text(verbatim: $r[1])->textSelection(TextSelectability::enabled)))),
        $card('The same, in other locales', Each(['en-US', 'en-GB', 'de-DE', 'fr-FR', 'hi-IN', 'ar-EG', 'fa-IR', 'ja-JP', 'zh-Hans-CN'])(fn(string $l) => HStack(spacing: 12)(
            Text(verbatim: $l)->foregroundStyle(Color::secondary)->frame(width: 90, alignment: Alignment::leading),
            Text(verbatim: I18n::formatNumber($l, 1234567.891))->frame(width: 150, alignment: Alignment::leading),
            Text(verbatim: I18n::formatDate($l, $this->now, new DateOptions(DateStyle::Medium, DateStyle::Short))),
        ))),
        $card('Controls', HStack(alignment: VerticalAlignment::top, spacing: 24)(
            DatePicker('Meeting', selection: $this->meeting, displayedComponents: [DatePickerComponents::date])->datePickerStyle(DatePickerStyle::graphical)->labelsHidden(),
            VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
                $row('Day', 48, DatePicker('Day', selection: $this->meeting, displayedComponents: [DatePickerComponents::date])->labelsHidden()),
                $row('Time', 48, DatePicker('Time', selection: $this->meeting, displayedComponents: [DatePickerComponents::hourAndMinute])->labelsHidden()),
                SearchField(text: $this->searchText)->frame(width: 220),
            ),
        )),
    ];
};

/** A row of the list, from the List page: Back, or the side buttons of a mouse, go back to the list as it was. */
$listRow = function (int $n) use ($card, $row) {
    if ($n < 0 || $n >= 10000) { return null; }
    $this->starred[$n] ??= false;
    return [
        $card('',
            $row('Square', 80, Text(verbatim: (string)($n * $n))->monospaced()),
            $row('Cube', 80, Text(verbatim: (string)($n * $n * $n))->monospaced()),
            Toggle('Starred', isOn: $this->starred[$n])->toggleStyle(ToggleStyle::checkbox),
        )->frame(maxWidth: 420),
        HStack(spacing: 16)(
            $n > 0 ? Button('← Previous row', action: fn() => $this->router->push('/list/' . ($n - 1)))->buttonStyle(ButtonStyle::borderless) : null,
            $n < 9999 ? Button('Next row →', action: fn() => $this->router->push('/list/' . ($n + 1)))->buttonStyle(ButtonStyle::borderless) : null,
            Button('All rows', action: fn() => $this->router->push('/list'))->buttonStyle(ButtonStyle::borderless),
        ),
    ];
};

/** The page at $path: its title and its views. @return array{0: string, 1: list<mixed>} */
$page = function (string $path) use ($legacy, $overview, $controls, $text, $richText, $list, $charts, $markdownPage, $overlays, $languages, $listRow, &$styling, &$drawing, &$glassPage, &$motion) {
    if (preg_match('~^/list/(\d+)$~', $path, $m) === 1 && (int)$m[1] < 10000) { return ["Row {$m[1]}", $listRow((int)$m[1])]; }
    $title = pageOf($path);
    return [$title === '' ? 'Not found' : $title, match ($title) {
        'Overview' => $overview(),
        'Controls' => $controls(),
        'Text' => $text(),
        'Rich Text' => $richText(),
        'List' => $list(),
        'Charts' => $charts(),
        'Markdown' => $markdownPage(),
        'Styling' => [$legacy(fn(Context $c) => $styling($c))],
        'Drawing' => [$legacy(fn(Context $c) => $drawing($c))],
        'Glass' => [$legacy(fn(Context $c) => $glassPage($c))],
        'Overlays' => $overlays(),
        'Motion' => [$legacy(fn(Context $c) => $motion($c))],
        'Languages' => $languages(),
        default => [],
    }];
};

// The sidebar of the pages, in two sections; the toolbar going back and forward through the history, the path of the
// page, and the way the gallery lays out; the page, which keeps where it was scrolled as the history comes back to it.
return function () use ($page) {
    $path = $this->router->path();
    [$title, $views] = $page($path);
    $chosen = new Binding(get: fn() => pageOf($this->router->path()), set: fn(string $p) => $p !== pageOf($this->router->path()) && $this->router->push(pagePath($p)));
    $crumbs = [pageOf($path)];
    $prefix = pagePath(pageOf($path)) . '/';
    if (str_starts_with($path, $prefix)) { $crumbs[] = 'Row ' . substr($path, strlen($prefix)); }
    $item = fn(string $p) => Label($p, image: pageIcon($p))->badge($p === 'List' ? '10k' : null)->tag($p);
    return NavigationSplitView(
        sidebar: ListView(selection: $chosen)(
            Section('Widgets')(Each(array_slice(PAGES, 0, 6))($item)),
            Section('Look')(Each(array_slice(PAGES, 6))($item)),
        )->navigationSplitViewColumnWidth(200),
        detail: VStack(spacing: 0)(
            HStack(spacing: 6)(
                // The history's buttons, as Finder's: a right click lists the pages before, or after, to go several at once. SwiftUI has none; the engine's are in a representable.
                Representable(function (Context $c) { $n = $c->row(0, 6); $c->backButton($this->router); $c->forwardButton($this->router); $c->end(); return $n; }),
                Breadcrumbs($crumbs, selection: $this->crumb)->accessibilityLabel('Path')->onChange(of: $this->crumb, action: function ($old, $new) use ($crumbs) { if ($new === 0) { $this->router->push(pagePath($crumbs[0])); } $this->crumb = -1; }),
                Spacer(),
                Text(verbatim: $this->now->format('H:i:s'))->font(Font::caption)->foregroundStyle(Color::secondary)->monospaced(),
                Toggle('Right to left', isOn: $this->rtl)->toggleStyle(ToggleStyle::button)->help('Lay the gallery out right to left'),
                // ⌘1…9 switch to the first nine pages.
                HStack(spacing: 0)(Each(array_slice(PAGES, 0, 9))(fn(string $p, int $i) => Button($p, action: fn() => $this->router->push(pagePath($p)))->keyboardShortcut((string)($i + 1))->frame(width: 0, height: 0)->opacity(0)->accessibilityLabel("Go to $p"))),
            )->buttonStyle(ButtonStyle::borderless)->padding(Edge::top, 8)->padding(Edge::horizontal, 24),
            ScrollView()(VStack(alignment: HorizontalAlignment::leading, spacing: 18)(
                Text(verbatim: $title)->font(Font::system(size: 26, weight: Weight::bold)),
                $views ?? Text('Not found'),
            )->padding(Edge::top, 12)->padding(Edge::horizontal, 32)->padding(Edge::bottom, 28)->frame(maxWidth: INF, alignment: Alignment::topLeading))->id($path),
        )->toast($this->toast)
            ->alert('Delete “Notes”?', isPresented: $this->alert, actions: [
                Button('Cancel', role: ButtonRole::cancel),
                Button('Delete', role: ButtonRole::destructive, action: function () { $this->notes--; $this->toast = 'Note deleted'; }),
            ], message: "This deletes the note on all your devices. You can't undo this.")
            ->sheet(isPresented: $this->dialog, content: fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
                Text('A modal dialog')->font(Font::system(size: 18, weight: Weight::bold)),
                Text('Click outside or press Escape to close it.')->foregroundStyle(Color::secondary),
                HStack()(Spacer(), Button('Done', action: fn() => $this->dialog = false)->buttonStyle(ButtonStyle::borderedProminent)),
            )->padding(20)->frame(width: 360)),
    )->environment(layoutDirection: $this->rtl ? LayoutDirection::rightToLeft : LayoutDirection::leftToRight);
};
