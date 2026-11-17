<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\{App, Clipboard, DateOptions, Dialog, I18n, MessageOptions, NumberOptions, Style as DateStyle, Width};
use MyPHP\Plugins\Glass\{Blur, Glass, ScrollEdge, Style as GlassStyle};
use MyPHP\Text\Params;
use MyPHP\Transfer\{Drop, DropOptions, Format};
use MyPHP\UI\{GradientStop, LinearGradient as UILinearGradient, Motion, Oklch, Painter, Path, RadialGradient as UIRadialGradient, WideColor};
use MyPHP\UI\Markdown\Reveal;
use MyPHP\UI\RichText\{Document, EditorOptions, FormatState, ListKind};

// The gallery in SwiftUI's views: a sidebar of pages beside the page chosen, under a toolbar going back and forward
// through the history.

/** A card: a titled panel. */
$card = fn(string $title, View|array|null ...$body) => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    $title === '' ? null : Text($title)->font(Font::system(size: 15, weight: Weight::bold)), ...$body,
)->padding(18)->frame(maxWidth: INF, alignment: Alignment::topLeading)->background(Color::background)->cornerRadius(10)
    ->overlay(RoundedRectangle(cornerRadius: 10)->stroke(Color::separator))->shadow(Color::black->opacity(0.06), radius: 1.5, y: 1);

/** A responsive two-column grid of cards. */
$cards = fn(View ...$items) => LazyVGrid(columns: [GridItem::adaptive(minimum: 320)], spacing: 16)(...$items);

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
    $by = is_array($this->tableSort) ? $this->tableSort : ($this->tableSort !== null ? [$this->tableSort] : []);
    usort($rows, function (TableFile $a, TableFile $b) use ($by): int {
        foreach ($by as $o) {
            $d = match ($o->column) {
                'Name', 'name' => strcmp(strtolower($a->name), strtolower($b->name)),
                'Kind', 'kind' => strcmp($a->kind, $b->kind),
                'Size', 'size' => $a->size - $b->size,
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
 * show, older messages loading above, and new ones followed at the end.
 */
$chatCard = function () use ($card): View {
    if ($this->messages === []) {
        for ($id = 0; $id < 200; $id++) { $this->messages[] = makeMessage($id); }
    }
    $items = [];
    foreach ($this->messages as $i => $m) {
        if ($i === 0 || $m->day() !== $this->messages[$i - 1]->day()) { $items[] = ['id' => 'day ' . $m->day(), 'header' => true, 'day' => $m->day(), 'msg' => null]; }
        $items[] = ['id' => 'msg ' . $m->id, 'header' => false, 'day' => $m->day(), 'msg' => $m];
    }
    $empty = trim($this->draft) === '';
    $send = function () use ($empty): void {
        if ($empty) { return; }
        $last = end($this->messages);
        $this->messages[] = new Message($last->id + 1, trim($this->draft), true);
        $this->draft = '';
    };
    return $card('Chat',
        ScrollViewReader(fn(ScrollViewProxy $proxy) => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
            HStack(spacing: 8)(
                Text(verbatim: sprintf('%d messages of every height; older ones load above without moving those in view.', count($this->messages)))->foregroundStyle(Color::secondary),
                Spacer(),
                Button('Load older', action: function () {
                    $first = $this->messages[0]->id;
                    $older = [];
                    for ($id = $first - 24; $id < $first; $id++) { $older[] = makeMessage($id); }
                    $this->messages = [...$older, ...$this->messages];
                }),
                Button('Jump to latest', action: fn() => $proxy->scrollTo(array_last($items)['id'])),
            ),
            ScrollView()(LazyVStack(spacing: 6)(
                Each($items, id: 'id')(function (array $row): View {
                    if ($row['header']) {
                        $day = $row['day'];
                        $label = $day < 0 ? (new DateTimeImmutable())->modify("$day days")->format('l, F j') : 'Today';
                        return HStack()(Spacer(), Text(verbatim: $label)->font(Font::caption)->bold()->padding(Edge::vertical, 3)->padding(Edge::horizontal, 10)->background(Color::secondarySystemBackground)->clipShape(Capsule())->overlay(Capsule()->stroke(Color::separator)), Spacer())->padding(Edge::vertical, 4);
                    }
                    /** @var Message $m */
                    $m = $row['msg'];
                    $bubble = Text(verbatim: $m->text)->foregroundStyle($m->mine ? Color::white : Color::primary)
                        ->padding(Edge::vertical, 7)->padding(Edge::horizontal, 12)
                        ->background($m->mine ? Color::accentColor : Color::secondarySystemBackground)
                        ->cornerRadius(14)->frame(maxWidth: 460, alignment: $m->mine ? Alignment::trailing : Alignment::leading);
                    return HStack()($m->mine ? Spacer() : null, $bubble, !$m->mine ? Spacer() : null)->padding(Edge::horizontal, 12);
                }),
            ))->defaultScrollAnchor(UnitPoint::bottom)->frame(height: 380)->cornerRadius(8)->overlay(RoundedRectangle(cornerRadius: 8)->stroke(Color::separator)),
            HStack(spacing: 8)(
                TextField('Message', text: $this->draft, prompt: 'Message')->labelsHidden()->onSubmit($send),
                Button('Send', action: $send)->disabled($empty),
            ),
        )),
    );
};

/**
 * What each kind of color paints (fills, gradients, text, shadows) in the
 * sRGB color nearest to an Oklch one, on the left, and in the Oklch color
 * itself, on the right.
 */
$wideColors = function (): View {
    $vivid = Oklch::rgb(0.85, 0.3, 145); $warm = Oklch::rgb(0.7, 0.3, 30);
    $in = fn(bool $wide, int $color): int => $wide ? $color : WideColor::srgb($color);
    $pair = fn(Closure $sample) => HStack(spacing: 12)($sample(false)->frame(maxWidth: INF), $sample(true)->frame(maxWidth: INF));
    $box = fn(string $label) => HStack()(Spacer(), Text($label)->font(Font::caption)->bold()->foregroundStyle(Color::black), Spacer())->frame(height: 30)->cornerRadius(6);
    return VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
        Text('Compare the columns on a Display P3 screen: the right one is more vivid.')->font(Font::caption)->foregroundStyle(Color::secondary),
        $pair(fn(bool $wide) => Text($wide ? 'Oklch' : 'sRGB')->font(Font::caption)->bold()->foregroundStyle(Color::secondary)),
        $pair(fn(bool $wide) => $box('Fill')->background(Color::rgb($in($wide, $vivid)))),
        $pair(fn(bool $wide) => $box('Gradient')->background(new UILinearGradient($in($wide, $warm), 1, $in($wide, $vivid), 1, 90, oklab: true))),
        $pair(fn(bool $wide) => HStack()(Spacer(), Text('Text')->font(Font::system(size: 14, weight: Weight::bold))->foregroundStyle(Color::rgb($in($wide, $vivid))), Spacer())->frame(height: 30)->background(Color::rgb(0x141414))->cornerRadius(6)),
        $pair(fn(bool $wide) => $box('Shadow')->background(Color::white)->shadow(Color::rgb($in($wide, $vivid)), radius: 6, y: 4)->padding(4)),
    );
};

$styling = function () use ($card, $cards, $wideColors): array {
    $blue = 0x2563eb; $yellow = 0xfacc15;
    $cell = fn(string $s) => Text(verbatim: $s)->font(Font::caption)->padding(Edge::vertical, 8)->padding(Edge::horizontal, 10)->frame(maxWidth: INF, alignment: Alignment::leading)->background(Color::secondarySystemBackground)->cornerRadius(6);
    $bar = fn(string $label, mixed $bg) => HStack()(Text($label)->font(Font::caption)->bold()->foregroundStyle(Color::white), Spacer())->padding(Edge::horizontal, 10)->frame(height: 30, maxWidth: INF)->background($bg)->cornerRadius(6);
    return [
        Text('Grids, borders, gradients, wide-gamut colors, text decorations, and motion along easings.')->foregroundStyle(Color::secondary),
        $cards(
            $card('Grid',
                Grid(horizontalSpacing: 6, verticalSpacing: 6)(
                    GridRow()(Text('gridCellColumns(3)')->font(Font::caption)->foregroundStyle(Color::white)->padding(Edge::vertical, 8)->padding(Edge::horizontal, 10)->frame(maxWidth: INF, alignment: Alignment::leading)->background(Color::accentColor)->cornerRadius(6)->gridCellColumns(3)),
                    GridRow()($cell('Col 1'), $cell('Col 2'), $cell('Col 3')),
                    GridRow()($cell('Span 1'), Text('gridCellColumns(2)')->font(Font::caption)->padding(Edge::vertical, 8)->padding(Edge::horizontal, 10)->background(Color::secondarySystemBackground)->cornerRadius(6)->gridCellColumns(2)),
                ),
            ),
            $card('Borders',
                VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                    Text('A header with a line below')->bold(),
                    Divider(),
                ),
                HStack(spacing: 0)(
                    Rectangle()->fill(Color::accentColor)->frame(width: 4, height: 36),
                    Text('A note with an accent on its left')->padding(Edge::vertical, 8)->padding(Edge::horizontal, 12)->frame(maxWidth: INF, alignment: Alignment::leading)->background(Color::secondarySystemBackground),
                )->cornerRadius(6),
                ZStack()(
                    Canvas(function (GraphicsContext $g, CGSize $s) {
                        $p = Path::roundedRect(1, 1, $s->width - 2, $s->height - 2, 8)->dash(0, 6, 4);
                        $g->stroke($p, with: Color::separator, lineWidth: 2);
                    }),
                    Text('Dashed, as a place to drop files')->foregroundStyle(Color::secondary),
                )->frame(height: 56, maxWidth: INF),
            ),
            $card('Fills',
                $bar('sRGB', LinearGradient([Color::rgb($blue), Color::rgb($yellow)], startPoint: UnitPoint::leading, endPoint: UnitPoint::trailing)),
                $bar('Oklab', new UILinearGradient($blue, 1, $yellow, 1, 90, oklab: true)),
                $bar('Stops at 40% and 60%', new UILinearGradient($blue, 1, $yellow, 1, 90, 0.4, 0.6)),
                $bar('Radial, from the right', new UIRadialGradient(centerX: 0.9, centerY: 0.5, oklab: true, stops: [new GradientStop($yellow, 0), new GradientStop($blue, 1)])),
            ),
            $card('Wide colors (Oklch)', $wideColors()),
            $card('Text decorations',
                Text([Text('Rich text mixes '), Text('highlighted')->background(Color::rgb(0xfacc15, 0.45)), Text(' and '), Text('underlined')->underline()->foregroundStyle(Color::accentColor), Text(' runs.')]),
                Text('Underlines and strikethrough styles')->underline()->strikethrough(),
                Text(verbatim: str_repeat('A line cut with an ellipsis when it does not fit. ', 3))->lineLimit(1),
            ),
            $card('Motion',
                TimelineView()(function (TimelineViewContext $ctx) {
                    $t = ($ctx->nanoseconds / 1_000_000_000);
                    return HStack(spacing: 14)(
                        Image(glyph('loader'))->foregroundStyle(Color::accentColor)->frame(width: 24, height: 24)->rotationEffect(fmod($t * 360, 360)),
                        Capsule()->fill(Color::separator)->frame(height: 14, maxWidth: INF)->opacity(0.4 + 0.6 * (0.5 + 0.5 * sin($t * 4))),
                    );
                }),
                Button('Move along each easing', action: fn() => $this->eased = !$this->eased),
                Each(['Linear' => Animation::linear(0.9), 'EaseIn' => Animation::easeIn(0.9), 'EaseOut' => Animation::easeOut(0.9), 'EaseInOut' => Animation::easeInOut(0.9)])(
                    fn(Animation $anim, string $name) => HStack(spacing: 8)(
                        Text(verbatim: $name)->font(Font::caption)->foregroundStyle(Color::secondary)->frame(width: 70, alignment: Alignment::leading),
                        GeometryReader(fn(GeometryProxy $g) => Circle()->fill(Color::accentColor)->frame(width: 18, height: 18)
                            ->offset(x: $this->eased ? max(0, $g->size[0] - 18) : 0)->animation($anim, value: $this->eased)
                        )->frame(height: 18),
                    )->id($name)
                ),
            ),
            $card('Layout',
                HStack(spacing: 6)(
                    Text('Spacer() pushes to the end')->font(Font::caption)->foregroundStyle(Color::secondary),
                    Spacer(),
                    Each(['3', '2', '1'])(fn(string $s) => Text(verbatim: $s)->frame(width: 28, height: 28)->background(Color::secondarySystemBackground)->cornerRadius(6)),
                ),
                HStack(spacing: 6)(
                    Text('Spacer() before Save')->font(Font::caption)->foregroundStyle(Color::secondary),
                    Spacer(),
                    Button('Save'),
                ),
                HStack(spacing: 6)(
                    Text('Inbox'),
                    Text('3')->font(Font::system(size: 10, weight: Weight::bold))->foregroundStyle(Color::white)->padding(Edge::vertical, 1)->padding(Edge::horizontal, 5)->background(Color::red)->cornerRadius(8)->offset(y: -6),
                    Text('offset(y: -6) moves a badge up')->font(Font::caption)->foregroundStyle(Color::secondary),
                ),
            ),
            $card('Scrolling',
                ScrollView(Axis::horizontal)(
                    HStack(spacing: 6)(
                        Each(range(1, 16))(fn(int $col) => VStack(spacing: 4)(
                            Each(range(1, 4))(fn(int $r) => Text(verbatim: sprintf('%s%d', chr(ord('A') + $col - 1), $r))->font(Font::caption)
                                ->frame(width: 56, height: 28)->background(Color::accentColor->opacity(0.12 + 0.5 * ($col / 16) * ($r / 4)))->cornerRadius(4))
                        ))
                    )->padding(6)
                )->frame(height: 150)->cornerRadius(6)->overlay(RoundedRectangle(cornerRadius: 6)->stroke(Color::separator)),
            ),
        ),
    ];
};

$drawing = function () use ($card): array {
    $gold = Color::rgb(0xf5b400);
    return [
        Text('Canvas paints with rectangles, shadows, paths and text. TimelineView animates the drawing at the display\'s rate.')->foregroundStyle(Color::secondary),
        TimelineView()(fn(TimelineViewContext $ctx) => Canvas(function (GraphicsContext $g, CGSize $s) use ($ctx) {
            $g->withPainter(function (Painter $p, array $r) use ($ctx): void {
                $t = $p->theme();
                $phase = intdiv($ctx->nanoseconds, 1_000_000) % 4000 / 4000 * 2 * M_PI;
                $step = min(28.0, ($r[2] / 2 - 24) / 12);
                for ($i = 0; $i < 12; $i++) {
                    $h = 60 + 50 * sin($phase + $i * 0.6);
                    $p->fill([$r[0] + 24 + $i * $step, $r[1] + $r[3] - 24 - $h, $step * 0.65, $h], $t->accent, 4, 0.35 + 0.05 * $i);
                }
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
        })->frame(height: 320, maxWidth: INF)->background(Color::secondarySystemBackground)->cornerRadius(10)),
        $card('Vector images',
            Text('SVGs stay sharp at any size: icons in the color of the text, pictures in their own colors.')->foregroundStyle(Color::secondary),
            HStack(spacing: 14)(
                Each([16, 24, 40])(fn(int $size) => Image(glyph('star'))->foregroundStyle($gold)->frame(width: $size, height: $size)),
                Button('Done', action: fn() => $this->toast = 'Done', label: HStack(spacing: 6)(Image(glyph('check')), Text('Done')))->buttonStyle(ButtonStyle::borderedProminent),
                Image(glyph('badge'))->resizable()->foregroundStyle($gold)->frame(width: 64, height: 64),
                Image(glyph('badge'))->resizable()->foregroundStyle($gold)->frame(width: 32, height: 32),
            ),
        ),
    ];
};

/** Liquid Glass: a toolbar floating over content that scrolls under it, on a bar of glass, a scroll edge or a progressive blur, a tinted button, and a lens to drag around. */
$glassPage = function (): array {
    $style = $this->glassStyle === 1 ? GlassStyle::Clear : GlassStyle::Regular;
    $tiles = [0xef4444, 0xf59e0b, 0x10b981, 0x06b6d4, 0x6366f1, 0xec4899];
    $barH = $this->glassEdge === 3 ? 88 : ($this->glassEdge === 1 ? 74 : 64);
    $toolbar = HStack(spacing: 6)(
        Each(['chevron', 'star', 'check'])(fn(string $ic) => Button($ic, action: fn() => $this->toast = 'Clicked', label: Image(glyph($ic)))
            ->buttonStyle(ButtonStyle::plain)->frame(width: 40, height: 40)->cornerRadius(20)->glassEffect(new Glass($style, interactive: true))),
        Spacer(),
        Button('Done', action: fn() => $this->toast = 'Done')->buttonStyle(ButtonStyle::plain)->bold()->foregroundStyle(Color::white)
            ->padding(Edge::vertical, 8)->padding(Edge::horizontal, 16)->cornerRadius(20)->glassEffect(new Glass($style, interactive: true), tint: Color::accentColor),
    )->padding(Edge::vertical, 6)->padding(Edge::horizontal, 8)->cornerRadius(26);
    if ($this->glassEdge === 0) {
        $toolbar = $toolbar->glassEffect(new Glass($style));
    }
    return [
        Text("The glass plugin's Liquid Glass, a material, as macOS draws it: what is under it shows through, frosted and bent along its edges. Scroll under the toolbar, and drag the lens.")->foregroundStyle(Color::secondary),
        HStack(spacing: 16)(
            Picker('Glass', selection: $this->glassStyle)(Text('Regular')->tag(0), Text('Clear')->tag(1))->pickerStyle(PickerStyle::segmented)->labelsHidden()->frame(width: 180),
            Picker('Under the toolbar', selection: $this->glassEdge)(Text('Glass bar')->tag(0), Text('Soft edge')->tag(1), Text('Hard edge')->tag(2), Text('Progressive blur')->tag(3))->pickerStyle(PickerStyle::segmented)->labelsHidden()->frame(width: 420),
        ),
        ZStack(alignment: Alignment::topLeading)(
            ScrollView()(
                VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
                    Each(range(0, 11))(fn(int $i) => HStack(spacing: 14)(
                        LinearGradient([Color::rgb($tiles[$i % 6]), Color::rgb($tiles[($i + 2) % 6])], angle: 135)->frame(width: 180, height: 96)->cornerRadius(12),
                        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                            Text(verbatim: sprintf('Photo %d', $i + 1))->bold(),
                            Text('Glass bends the light along its rim, and frosts what is under its middle.')->foregroundStyle(Color::secondary),
                        )->frame(maxWidth: INF, alignment: Alignment::leading),
                    )),
                )->padding(Edge::top, 76)->padding(Edge::horizontal, 16)->padding(Edge::bottom, 16),
            ),
            $this->glassEdge > 0 ? Color(Color::clear)->frame(maxWidth: INF, height: $barH)->allowsHitTesting(false)->glassEffect(match ($this->glassEdge) {
                1 => new ScrollEdge(),
                2 => new ScrollEdge(hard: true),
                default => new Blur(6, new UILinearGradient(0x000000, 1, 0x000000, 0, 180, 0.3, 1)),
            }) : null,
            $toolbar->padding(12),
            Circle()->fill(Color::clear)->frame(width: 110, height: 110)->glassEffect(new Glass($style, interactive: true))
                ->offset(x: $this->lens[0], y: $this->lens[1])
                ->gesture(DragGesture(minimumDistance: 0)->onChanged(function (DragGestureValue $v) {
                    $this->lens = [max(0.0, $v->location->x - 55), max(0.0, $v->location->y - 55)];
                })),
        )->frame(height: 420, maxWidth: INF)->cornerRadius(12)->overlay(RoundedRectangle(cornerRadius: 12)->stroke(Color::separator)),
    ];
};

$motion = function () use ($card, $cards): array {
    $flingStyles = [Animation::smooth(), Animation::snappy(), Animation::bouncy()];
    $travel = 200.0;
    return [
        $cards(
            $card('Transforms and interruptible springs',
                Text('Click repeatedly while moving. Edit the transformed field or open its anchored panel.')->font(Font::caption)->foregroundStyle(Color::secondary),
                Button('Retarget the spring', action: fn() => withAnimation(Animation::spring(duration: 0.55, bounce: 0.25), fn() => $this->motionTarget = !$this->motionTarget)),
                ZStack(alignment: Alignment::topLeading)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
                        Text('Nested interactive content')->font(Font::caption)->bold(),
                        TextField('Type here', text: $this->motionText, prompt: 'Type here')->labelsHidden()->frame(width: 155),
                        Button('Open a popover', action: fn() => $this->transformPopover = !$this->transformPopover)
                            ->popover(isPresented: $this->transformPopover, content: fn() => Text('I follow the transformed anchor.')->padding(10)),
                    )->padding(12)->frame(width: 185, height: 125)->background(Color::secondarySystemBackground)->cornerRadius(10)->overlay(RoundedRectangle(cornerRadius: 10)->stroke(Color::separator))
                        ->scaleEffect($this->motionTarget ? 1.05 : 0.95)
                        ->rotationEffect($this->motionTarget ? 8 : -8)
                        ->offset(x: 35 + ($this->motionTarget ? 65 : 0), y: 45)
                        ->animation(Animation::spring(duration: 0.55, bounce: 0.25), value: $this->motionTarget),
                )->frame(height: 220, maxWidth: INF)->cornerRadius(10)->overlay(RoundedRectangle(cornerRadius: 10)->stroke(Color::separator)),
            ),
            $card('Keyframe sequences',
                Text('Replay during a sequence to interrupt it continuously. Motion follows the desktop preference.')->font(Font::caption)->foregroundStyle(Color::secondary),
                Button('Replay the sequence', action: fn() => $this->motionRun++),
                ZStack(alignment: Alignment::topLeading)(
                    KeyframeAnimator(
                        initialValue: 0.0,
                        trigger: $this->motionRun,
                        content: fn(float $p) => HStack()(Spacer(), Text('Four timed keyframes')->font(Font::caption)->bold()->foregroundStyle(Color::white), Spacer())
                            ->frame(width: 160, height: 55)->background(Color::accentColor)->cornerRadius(8)
                            ->scaleEffect(1 + 0.08 * $p)->rotationEffect(12 * $p)->offset(x: 40 + 80 * $p, y: 80),
                        keyframes: [
                            LinearKeyframe(1.0, 0.25, UnitCurve::easeOut),
                            LinearKeyframe(0.4, 0.40, UnitCurve::easeInOut),
                            LinearKeyframe(0.0, 0.35, UnitCurve::easeInOut),
                        ],
                    ),
                )->frame(height: 220, maxWidth: INF)->cornerRadius(10)->overlay(RoundedRectangle(cornerRadius: 10)->stroke(Color::separator)),
            ),
            $card('Flings that land',
                Text("Throw the card (DragGesture): a spring takes over at the pointer's velocity, to the side the throw carries it to.")->font(Font::caption)->foregroundStyle(Color::secondary),
                Picker('Fling', selection: $this->flingStyle)(Text('Smooth')->tag(0), Text('Snappy')->tag(1), Text('Bouncy')->tag(2))->pickerStyle(PickerStyle::segmented)->labelsHidden(),
                ZStack(alignment: Alignment::topLeading)(
                    HStack()(Spacer(), Text('Throw me')->font(Font::caption)->bold()->foregroundStyle(Color::white), Spacer())
                        ->frame(width: 90, height: 60)->background(Color::accentColor)->cornerRadius(10)
                        ->offset(x: 16 + max(0.0, min($travel, $this->flingX + $this->flingDrag)), y: 24)
                        ->gesture(DragGesture(minimumDistance: 0)
                            ->onChanged(fn(DragGestureValue $v) => $this->flingDrag = $v->translation->width)
                            ->onEnded(function (DragGestureValue $v) use ($travel, $flingStyles) {
                                $end = $this->flingX + $v->predictedEndTranslation->width;
                                $this->flingX = max(0.0, min($travel, $this->flingX + $v->translation->width));
                                $this->flingDrag = 0.0;
                                withAnimation($flingStyles[$this->flingStyle], fn() => $this->flingX = $end >= $travel / 2 ? $travel : 0.0);
                            })),
                )->frame(height: 110, maxWidth: INF)->cornerRadius(10)->overlay(RoundedRectangle(cornerRadius: 10)->stroke(Color::separator)),
            ),
            $card('Shared elements',
                Text('A tile opens into its detail view from where it was, and goes back to its place.')->font(Font::caption)->foregroundStyle(Color::secondary),
                ZStack(alignment: Alignment::topLeading)(
                    $this->tileOpen === 0
                        ? HStack(spacing: 12)(
                            Each(array_slice(MOTION_COLORS, 0, 3))(fn(int $col, int $i) => RoundedRectangle(cornerRadius: 12)->fill(Color::rgb($col))->frame(width: 64, height: 64)
                                ->matchedGeometryEffect(id: "tile $i", in: 'tiles')
                                ->onTapGesture(fn() => withAnimation(Animation::spring(duration: 0.45, bounce: 0.12), fn() => $this->tileOpen = $i + 1)))
                        )->padding(12)
                        : VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
                            RoundedRectangle(cornerRadius: 16)->fill(Color::rgb(MOTION_COLORS[$this->tileOpen - 1]))->frame(width: 240, height: 120)
                                ->matchedGeometryEffect(id: 'tile ' . ($this->tileOpen - 1), in: 'tiles'),
                            HStack(spacing: 10)(
                                Text(verbatim: sprintf('Tile %d', $this->tileOpen))->bold(),
                                Spacer(),
                                Button('Back', action: fn() => withAnimation(Animation::spring(duration: 0.45, bounce: 0.12), fn() => $this->tileOpen = 0)),
                            ),
                        )->padding(12),
                )->frame(height: 190, maxWidth: INF)->cornerRadius(10)->overlay(RoundedRectangle(cornerRadius: 10)->stroke(Color::separator)),
            ),
            $card('Cards that turn over',
                Text('Turned in 3D and seen in perspective, each face shows as it turns.')->font(Font::caption)->foregroundStyle(Color::secondary),
                ZStack()(
                    VStack(spacing: 4)(
                        Text($this->cardFlipped ? 'Back' : 'Front')->bold()->foregroundStyle($this->cardFlipped ? Color::white : Color::primary),
                        $this->cardFlipped ? null : Text('Click to turn over')->font(Font::caption)->foregroundStyle(Color::secondary),
                    )->frame(width: 200, height: 120)->background($this->cardFlipped ? Color::accentColor : Color::secondarySystemBackground)->cornerRadius(14)
                        ->overlay(RoundedRectangle(cornerRadius: 14)->stroke(Color::separator))->shadow(Color::black->opacity(0.12), radius: 8, y: 4)
                        ->rotation3DEffect($this->cardFlipped ? 180 : 0, axis: [0, 1, 0])
                        ->animation(Animation::spring(duration: 0.6, bounce: 0.15), value: $this->cardFlipped)
                        ->onTapGesture(fn() => $this->cardFlipped = !$this->cardFlipped),
                )->frame(height: 150, maxWidth: INF),
            ),
            $card('Counters, check marks and lifts',
                Text('Digits update, a check mark toggles, and tiles lift on hover.')->font(Font::caption)->foregroundStyle(Color::secondary),
                HStack(spacing: 16)(
                    Button('−', action: function () { $this->counter--; $this->countDown = true; }),
                    Text(verbatim: (string)$this->counter)->font(Font::system(size: 32, weight: Weight::bold))->frame(width: 80),
                    Button('+', action: function () { $this->counter++; $this->countDown = false; }),
                    Button('Check', action: fn() => withAnimation(Animation::smooth(), fn() => $this->taskDone = !$this->taskDone),
                        label: ZStack()(
                            RoundedRectangle(cornerRadius: 8)->stroke(Color::separator, lineWidth: 2)->frame(width: 36, height: 36),
                            $this->taskDone ? Image(glyph('check'))->foregroundStyle(Color::accentColor)->transition(AnyTransition::scale()) : null,
                        ))->buttonStyle(ButtonStyle::plain),
                ),
                HStack(spacing: 10)(
                    Each(array_slice(MOTION_COLORS, 0, 4))(function (int $col, int $i) {
                        $over = $this->hoveredTile[$i] ?? false;
                        return RoundedRectangle(cornerRadius: 8)->fill(Color::rgb($col))->frame(width: 56, height: 40)
                            ->scaleEffect($over ? 1.06 : 1.0)->offset(y: $over ? -6 : 0)->animation(Animation::snappy(), value: $over)
                            ->onHover(fn(bool $h) => $this->hoveredTile[$i] = $h)->id("lift $i");
                    }),
                ),
            ),
        ),
        Text('Transitions move views where the layout puts them, and in and out as they come and go. F12 (Alt+Cmd+I) opens the inspector of the window\'s nodes.')->foregroundStyle(Color::secondary),
        $cards(
            $card('A list that moves',
                HStack(spacing: 8)(
                    Button('Add', action: fn() => withAnimation(Animation::easeInOut(0.25), function () {
                        $this->nextItem++;
                        $at = $this->items === [] ? 0 : $this->nextItem % (count($this->items) + 1);
                        array_splice($this->items, $at, 0, [new MotionItem($this->nextItem, "Item {$this->nextItem}")]);
                    })),
                    Button('Shuffle', action: fn() => withAnimation(Animation::easeInOut(0.25), function () {
                        $before = $this->items;
                        while ($this->items === $before) { shuffle($this->items); }
                    }))->disabled(count($this->items) < 2),
                    Button('Sort', action: fn() => withAnimation(Animation::easeInOut(0.25), function () {
                        usort($this->items, fn(MotionItem $a, MotionItem $b) => $a->id <=> $b->id);
                    }))->disabled(count($this->items) < 2),
                ),
                VStack(spacing: 0)(
                    Each($this->items, id: 'id')(fn(MotionItem $it) => HStack(spacing: 10)(
                        Circle()->fill(Color::rgb(MOTION_COLORS[$it->id % count(MOTION_COLORS)]))->frame(width: 10, height: 10),
                        Text(verbatim: $it->name),
                        Spacer(),
                        Button('Remove', action: fn() => withAnimation(Animation::easeInOut(0.25), function () use ($it) {
                            $this->items = array_values(array_filter($this->items, fn(MotionItem $o) => $o->id !== $it->id));
                        })),
                    )->padding(Edge::vertical, 8)->padding(Edge::horizontal, 10)->transition(AnyTransition::scale(0.9)->combined(AnyTransition::opacity()))),
                )->cornerRadius(8)->overlay(RoundedRectangle(cornerRadius: 8)->stroke(Color::separator)),
                $this->items === [] ? Text('No items: add some.')->foregroundStyle(Color::secondary) : null,
            ),
            $card('A panel that opens',
                Button('Toggle the panel', action: fn() => withAnimation(Animation::easeInOut(0.25), fn() => $this->panelOpen = !$this->panelOpen)),
                HStack(spacing: 0)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
                        Each(['Overview', 'Styling', 'Drawing'])(fn(string $p) => HStack(spacing: 8)(
                            Image(pageIcon($p))->frame(width: 18, height: 18),
                            $this->panelOpen ? Text(verbatim: $p)->lineLimit(1) : null,
                        )),
                    )->padding(10)->frame(width: $this->panelOpen ? 160 : 48, maxHeight: INF, alignment: Alignment::topLeading)->background(Color::secondarySystemBackground),
                    VStack(alignment: HorizontalAlignment::leading)(
                        Text('The content beside the panel.')->foregroundStyle(Color::secondary),
                    )->padding(12)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading),
                )->frame(height: 120)->cornerRadius(8)->overlay(RoundedRectangle(cornerRadius: 8)->stroke(Color::separator)),
            ),
            $card('ZStack alignments',
                Text('ZStack places badges and labels on any corner of a view.')->font(Font::caption)->foregroundStyle(Color::secondary),
                HStack(spacing: 24)(
                    Each(['Ada Lovelace', 'Alan Turing'])(fn(string $name, int $i) => ZStack(alignment: Alignment::topTrailing)(
                        Avatar($name),
                        Text(verbatim: (string)(3 + $i * 9))->font(Font::system(size: 10, weight: Weight::bold))->foregroundStyle(Color::white)
                            ->padding(Edge::vertical, 1)->padding(Edge::horizontal, 5)->background(Color::red)->cornerRadius(8)->offset(x: 6, y: -4),
                    )),
                    ZStack(alignment: Alignment::bottomTrailing)(
                        ZStack()(
                            RoundedRectangle(cornerRadius: 8)->fill(Color::secondarySystemBackground),
                            Text('Center')->font(Font::caption),
                        ),
                        Text('Bottom right')->font(Font::caption)->foregroundStyle(Color::secondary)->padding(Edge::trailing, 6)->padding(Edge::bottom, 4),
                    )->frame(width: 120, height: 64),
                )->padding(Edge::vertical, 8),
            ),
        ),
    ];
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

$controls = function () use ($card, $row) {
    $path = ['Macintosh HD', 'Users', 'ada', 'Documents', 'Reports'];
    if ($this->tasks === []) {
        $this->tasks = [new GalleryTask('Write the docs'), new GalleryTask('Fix the layout'), new GalleryTask('Ship it'), new GalleryTask('Plan the release', true)];
    }
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
    $card('Drag and drop',
        HStack(alignment: VerticalAlignment::top, spacing: 12)(
            Each(['To do', 'Done'])(function (string $title, int $col): View {
                $done = $col === 1;
                $filtered = array_values(array_filter($this->tasks, fn(GalleryTask $t) => $t->done === $done));
                return VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                    Text($title)->bold(),
                    Each($filtered, id: 'name')(fn(GalleryTask $task) => Text(verbatim: $task->name)
                        ->padding(Edge::vertical, 7)->padding(Edge::horizontal, 10)->frame(maxWidth: INF, alignment: Alignment::leading)
                        ->background(Color::background)->cornerRadius(6)->overlay(RoundedRectangle(cornerRadius: 6)->stroke(Color::separator))
                        ->draggable($task->name, value: $task)),
                )->padding(10)->frame(minHeight: 150, maxWidth: INF, alignment: Alignment::topLeading)
                    ->background(Color::secondarySystemBackground)->cornerRadius(8)->overlay(RoundedRectangle(cornerRadius: 8)->stroke(Color::separator))
                    ->accessibilityLabel($title)
                    ->dropDestination(for: GalleryTask::class, action: function (GalleryTask $t) use ($done): void { $t->done = $done; });
            }),
        ),
        Text('Drag a task to the other column; Escape gives up.')->font(Font::caption)->foregroundStyle(Color::secondary),
    ),
    $card('Groups and paths',
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
    $card('Toolbar',
        HStack(spacing: 6)(
            Button('New'),
            Button('Open'),
            Toggle('Bold', isOn: $this->bold)->toggleStyle(ToggleStyle::button),
            Toggle('Italic', isOn: $this->italic)->toggleStyle(ToggleStyle::button),
            Toggle('Underline', isOn: $this->underline)->toggleStyle(ToggleStyle::button),
            Picker('View', selection: $this->layout)(Text('List')->tag(0), Text('Grid')->tag(1), Text('Columns')->tag(2))->pickerStyle(PickerStyle::segmented)->labelsHidden()->frame(width: 210),
            Spacer(),
            Toggle('Inspector', isOn: $this->inspector)->toggleStyle(ToggleStyle::button),
        )->buttonStyle(ButtonStyle::borderless)->padding(6)->cornerRadius(8)->overlay(RoundedRectangle(cornerRadius: 8)->stroke(Color::separator)),
        Text('Toggles and segmented controls in a toolbar row.')->foregroundStyle(Color::secondary),
    ),
    $card('Tabs and panes',
        Picker('Tab', selection: $this->tab)(Text('Files')->tag(0), Text('Search')->tag(1), Text('History')->tag(2))->pickerStyle(PickerStyle::segmented)->labelsHidden(),
        HSplitView()(
            VStack(alignment: HorizontalAlignment::leading, spacing: 6)(Each([['index.php', 'composer.json', 'README.md'], ['Results'], ['Yesterday', 'Last week']][$this->tab])(fn(string $name) => Text(verbatim: $name)->lineLimit(1)))
                ->padding(10)->frame(idealWidth: 160, maxHeight: INF, alignment: Alignment::topLeading)->background(Color::gray->opacity(0.12)),
            Text('Drag the divider, or focus it and press the arrows.')->foregroundStyle(Color::secondary)->padding(10)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading),
        )->frame(height: 140)->cornerRadius(6)->overlay(RoundedRectangle(cornerRadius: 6)->stroke(Color::separator)),
    ),
    $card('Custom-styled controls',
        Text('Views styled with modifiers: pills, segmented tabs and menus of your own look.')->foregroundStyle(Color::secondary),
        HStack(spacing: 16)(
            HStack(spacing: 2)(
                Each(['Day', 'Week', 'Month'])(fn(string $name, int $i) => Button($name, action: fn() => $this->period = $i)
                    ->buttonStyle(ButtonStyle::plain)->padding(Edge::vertical, 5)->padding(Edge::horizontal, 14)
                    ->background($i === $this->period ? Color::background : Color::clear)->clipShape(Capsule()))
            )->padding(3)->background(Color::secondarySystemBackground)->clipShape(Capsule()),
            Button('Starred', action: fn() => $this->pinned = !$this->pinned, label: HStack(spacing: 6)(Image(glyph('star')), Text('Starred')))
                ->buttonStyle(ButtonStyle::plain)->foregroundStyle($this->pinned ? Color::white : Color::primary)
                ->padding(Edge::vertical, 5)->padding(Edge::horizontal, 12)->background($this->pinned ? Color::accentColor : Color::clear)
                ->clipShape(Capsule())->overlay(Capsule()->stroke($this->pinned ? Color::accentColor : Color::separator)),
            Picker('Fruit', selection: $this->fruit)(Each(['Apple', 'Banana', 'Cherry', 'Durian'])(fn(string $f) => Text($f)->tag($f)))->labelsHidden()->frame(width: 140),
        ),
    ),
    ];
};

/** The text page: fields, forms, editors, find navigator and typography. */
$text = function () use ($card, $row): array {
    $sampleText = 'The quick brown fox jumps over the lazy dog. The dog sleeps; the fox runs on.';
    $q = $this->findQuery;
    $matches = $q === '' ? 0 : substr_count(strtolower($sampleText), strtolower($q));
    $invalid = $this->email !== '' && !str_contains($this->email, '@') ? 'Enter an email address, such as ada@example.com.' : '';
    return [
        $card('Form',
            $row('Name', 90, TextField('Name', text: $this->name, prompt: 'Ada Lovelace')->labelsHidden()),
            $row('Email', 90, VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                TextField('Email', text: $this->email, prompt: 'ada@example.com')->labelsHidden()->onSubmit(fn() => $this->dialog = true),
                Text($invalid !== '' ? $invalid : 'Enter opens a dialog.')->font(Font::caption)->foregroundStyle($invalid !== '' ? Color::red : Color::secondary),
            )),
            $row('About you', 90, VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                TextEditor(text: $this->bio)->frame(height: 110),
                Text(verbatim: sprintf('%d characters', mb_strlen($this->bio)))->font(Font::caption)->foregroundStyle(Color::secondary),
            )),
            GroupBox('Optional')(VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
                $row('Birthday', 80, DatePicker('Birthday', selection: $this->birthday, displayedComponents: [DatePickerComponents::date])->labelsHidden()),
                $row('Updates', 80, Toggle('Send me the newsletter', isOn: $this->news)->toggleStyle(ToggleStyle::checkbox)),
            )),
        ),
        $card('Find',
            VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
                !$this->finding ? Button('Find…', action: fn() => $this->finding = true) : null,
                Text($sampleText),
            )->findNavigator(isPresented: $this->finding, text: $this->findQuery, matches: $matches, current: $this->findAt),
        ),
        $card('Typography',
            Text('Display 28')->font(Font::system(size: 28, weight: Weight::bold)),
            Text('Italic, underlined and struck through')->italic()->underline()->strikethrough(),
            Text([
                Text('Rich text mixes '), Text('bold')->bold(), Text(', '),
                Text('colored')->foregroundStyle(Color::accentColor), Text(', '), Text('large')->font(Font::system(size: 20)),
                Text(' and '), Text('underlined')->underline(), Text(' runs in one paragraph.'),
            ]),
            Text('Monospace: function main() {}')->monospaced(),
            Text('SPACED CAPITALS')->font(Font::caption)->bold()->foregroundStyle(Color::secondary),
            Text('Tabular digits: 1,111.11 / 8,888.88')->monospaced(),
            Text('Mixed scripts: English, Ελληνικά, Русский, 日本語, 한국어, العربية, עברית, हिन्दी 🎉')->textSelection(TextSelectability::enabled),
            Text(verbatim: str_repeat('Long text wraps to the width it gets. ', 6))->foregroundStyle(Color::secondary),
            Text(verbatim: str_repeat('A single line that ends with an ellipsis when it does not fit. ', 4))->lineLimit(1),
        ),
        $card('Text selection',
            VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
                Text('Drag from this paragraph into the next, then copy the selected text.'),
                Text([Text('Each paragraph keeps its own layout. '), Text('They share one selection.')->bold()]),
                Text('This hint is excluded from selection.')->foregroundStyle(Color::secondary)->textSelection(TextSelectability::disabled),
            )->textSelection(TextSelectability::enabled),
        ),
    ];
};

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

/** 10,000 rows, filtered; a row opens in a page of its own. Then the chat, the tree, the table, the outline and the grid view. */
$list = function () use ($card, &$chatCard, &$sortedFiles) {
    $rows = [];
    for ($i = 0; $i < 10000; $i++) { if ($this->filterText === '' || str_contains((string)$i, $this->filterText)) { $rows[] = $i; } }
    $open = fn(int $n) => $this->router->push("/list/$n");
    $files = $sortedFiles();
    if ($this->swatchOrder === []) { $this->swatchOrder = range(0, 9999); }
    $treeFile = function (string $path, ?Closure $children = null) use (&$treeFile): View {
        if ($children === null) {
            return Button(basename($path), action: fn() => $this->leaf = $path)->buttonStyle(ButtonStyle::plain)
                ->fontWeight($this->leaf === $path ? Weight::semibold : Weight::regular)
                ->foregroundStyle($this->leaf === $path ? Color::accentColor : Color::primary);
        }
        $this->tree[$path] ??= false;
        return DisclosureGroup(basename($path), isExpanded: $this->tree[$path])(VStack(alignment: HorizontalAlignment::leading, spacing: 4)(...$children()));
    };
    $outlineData = [
        ['id' => 'f1', 'name' => 'Folder 001', 'children' => [['id' => 'f1-1', 'name' => 'File 001.php'], ['id' => 'f1-2', 'name' => 'File 002.php']]],
        ['id' => 'f2', 'name' => 'Folder 002', 'children' => [['id' => 'f2-1', 'name' => 'Notes.md'], ['id' => 'f2-2', 'name' => 'Diagram.svg']]],
        ['id' => 'f3', 'name' => 'README.md'],
    ];
    return [
        TextField('Filter', text: $this->filterText, prompt: 'Filter 10,000 rows'),
        ScrollViewReader(fn(ScrollViewProxy $proxy) => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
            HStack(spacing: 12)(
                Text(verbatim: sprintf('%d rows; only those in view are built. Pick one with a click or the arrows, open it with a double click or Enter; right-click one for its menu.', count($rows)))->foregroundStyle(Color::secondary),
                Spacer(),
                Button('Show picked', action: fn() => $proxy->scrollTo($this->picked))->disabled(!in_array($this->picked, $rows, true)),
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
        $chatCard(),
        HStack(alignment: VerticalAlignment::top, spacing: 18)(
            $card('Tree',
                VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                    $treeFile('src', fn() => [$treeFile('src/App.php'), $treeFile('src/UI', fn() => [$treeFile('src/UI/Context.php')])]),
                    $treeFile('composer.json'),
                ),
            )->frame(width: 220, height: 260),
            $card('Table',
                Table($files, id: 'id', selection: $this->chosen, sortOrder: $this->tableSort)(
                    TableColumn('Name', fn(TableFile $f) => EditableText(text: $f->name), value: 'name')->width(min: 140),
                    TableColumn('Kind', value: 'kind')->width(ideal: 120),
                    TableColumn('Size', fn(TableFile $f) => Text(verbatim: "{$f->size} KB")->foregroundStyle(Color::secondary), value: 'size')->width(ideal: 90)->alignment(HorizontalAlignment::trailing),
                )->contextMenu(primaryAction: function (array $ids) use ($files) {
                    foreach ($files as $f) { if ($f->id === ($ids[0] ?? -1)) { $this->toast = 'Opened ' . $f->name; } }
                })->frame(height: 170),
                Text(verbatim: sprintf('%d chosen. Shift-click or Cmd-click to choose several; double-click a name to rename; click a header to sort.', count($this->chosen)))->font(Font::caption)->foregroundStyle(Color::secondary),
            )->frame(height: 260),
        ),
        $card('Outline',
            OutlineGroup($outlineData, children: 'children', id: 'id')(fn(array $item) => Label($item['name'], image: glyph(isset($item['children']) ? 'chevron' : 'check'))),
        ),
        $card('Grid view',
            LazyVGrid(columns: [GridItem::adaptive(minimum: 96)], selection: $this->swatchesChosen)(
                Each($this->swatchOrder, id: fn(int $n) => $n)(fn(int $n) => VStack(spacing: 4)(
                    RoundedRectangle(cornerRadius: 6)->fill(Color::rgb(hsl(($n % 36) * 10, 0.6, 0.65)))->frame(width: 84, height: 64),
                    Text(verbatim: (string)$n)->font(Font::caption),
                )->padding(4)),
            )->frame(height: 300),
            Text(verbatim: sprintf('%d chosen of 10,000, built only as they show. The arrows move in both directions; Shift and Cmd choose several.', count($this->swatchesChosen)))->font(Font::caption)->foregroundStyle(Color::secondary),
        ),
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
        HStack(spacing: 6)(
            Text(verbatim: $this->cardDid !== '' ? $this->cardDid : 'Right-click me'),
            Menu('⋯')(
                Button('Duplicate', action: fn() => $this->cardDid = 'Duplicated')->keyboardShortcut('d'),
                Toggle('Pinned', isOn: $this->cardPinned),
                Picker('Zoom', selection: $this->cardZoom)(Each(['Small', 'Medium', 'Large'])(fn(string $z) => Text($z)->tag($z))),
                Divider(),
                Button('Delete', role: ButtonRole::destructive, action: fn() => $this->cardDid = 'Deleted')->disabled($this->cardPinned),
            ),
        )->padding(Edge::vertical, 4)->padding(Edge::horizontal, 10)->cornerRadius(6)->overlay(RoundedRectangle(cornerRadius: 6)->stroke(Color::separator))
            ->contextMenu(
                Button('Duplicate', action: fn() => $this->cardDid = 'Duplicated')->keyboardShortcut('d'),
                Toggle('Pinned', isOn: $this->cardPinned),
                Divider(),
                Button('Delete', role: ButtonRole::destructive, action: fn() => $this->cardDid = 'Deleted')->disabled($this->cardPinned),
            ),
        Button('Delete a note', action: function () { $this->notes--; $this->toast = 'Note deleted'; }),
        Button('Hover me')->help('Tooltips show after the pointer rests a moment.'),
        Button('Alert', action: fn() => $this->alert = true),
        Button('Native dialog', action: fn() => App::later(fn() => Dialog::message(new MessageOptions(parent: $this->win, message: 'Native dialogs work from MyPHP UI windows too.')))),
    )),
    Text(verbatim: sprintf('%d notes.', $this->notes))->font(Font::caption)->foregroundStyle(Color::secondary),
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
$page = function (string $path) use ($overview, $controls, $text, $richText, $list, $charts, $markdownPage, $overlays, $languages, $listRow, &$styling, &$drawing, &$glassPage, &$motion) {
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
        'Styling' => $styling(),
        'Drawing' => $drawing(),
        'Glass' => $glassPage(),
        'Overlays' => $overlays(),
        'Motion' => $motion(),
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
                HistoryButtons($this->router),
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
