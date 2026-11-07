<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

const STOPS = [0.0, 206.0, 412.0];
const HEIGHTS = [0.0, 90.0, 180.0];

/** A word's chip, in the row or in the basket: the same word one view, which flies between them. */
$chip = fn(string $w) => Text($w)->padding(Edge::horizontal, 10)->padding(Edge::vertical, 6)
    ->background(Color::gray->opacity(0.15))->cornerRadius(8)->overlay(RoundedRectangle(cornerRadius: 8)->stroke(Color::separator))
    ->matchedGeometryEffect(id: $w, in: 'words');

return fn() => ZStack(alignment: Alignment::bottom)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 16)(
        Text('Drag')->font(Font::title2)->bold(),
        // A card the pointer drags anywhere; it lifts a little while held.
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            Text($this->holding ? 'Dragging…' : 'Drag me')->bold(),
            Text(verbatim: sprintf('%.0f, %.0f', $this->cardX + $this->card->width, $this->cardY + $this->card->height))->font(Font::caption)->foregroundStyle(Color::secondary),
        )
            ->padding(12)->frame(width: 160, height: 70, alignment: Alignment::topLeading)->background(Color::gray->opacity(0.12))->cornerRadius(10)
            ->scaleEffect($this->holding ? 1.04 : 1.0)->animation(Animation::snappy(), value: $this->holding)
            ->offset(x: $this->cardX + $this->card->width, y: $this->cardY + $this->card->height)
            ->gesture(DragGesture(minimumDistance: 0)
                ->onChanged(function (DragGestureValue $v) { [$this->card, $this->holding] = [$v->translation, true]; })
                ->onEnded(function (DragGestureValue $v) { $this->cardX += $v->translation->width; $this->cardY += $v->translation->height; [$this->card, $this->holding] = [CGSize::zero(), false]; })),
        Text('Throw the puck: let go, it glides to the stop nearest to where the throw would carry it — the faster, the farther.')->font(Font::caption)->foregroundStyle(Color::secondary),
        ZStack(alignment: Alignment::leading)(
            HStack(spacing: 0)(Each(STOPS)(fn(float $x, int $i) => Circle()->fill(Color::secondary->opacity(0.5))->frame(width: 12, height: 12)->padding(Edge::leading, $i === 0 ? 18 : 194))), // under the puck's middle at each stop: 206 apart
            Circle()->fill(Color::accentColor)->frame(width: 48, height: 48)
                ->offset(x: max(0, min(412, $this->puck + $this->puckDrag)))
                ->gesture(DragGesture(minimumDistance: 0)
                    ->onChanged(fn(DragGestureValue $v) => $this->puckDrag = $v->translation->width)
                    ->onEnded(function (DragGestureValue $v) {
                        $to = Board::nearest(STOPS, $this->puck + $v->predictedEndTranslation->width);
                        $this->puck = max(0, min(412, $this->puck + $v->translation->width));
                        $this->puckDrag = 0;
                        withAnimation(Animation::spring(duration: 0.4, bounce: 0.2), fn() => $this->puck = $to);
                    })),
        )->frame(width: 460, height: 48, alignment: Alignment::leading)->background(Color::gray->opacity(0.12))->cornerRadius(24),
        // Words into the basket: dragged there, a word flies to its place in it; a click on one in the basket takes it back.
        HStack(alignment: VerticalAlignment::top, spacing: 16)(
            HStack(spacing: 6)(Each($this->words)(fn(string $w) => $chip($w)->draggable($w))),
            VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                Text('Basket')->font(Font::subheadline)->bold(),
                $this->basket === [] ? Text('Drop words here')->font(Font::caption)->foregroundStyle(Color::secondary) : null,
                HStack(spacing: 6)(Each($this->basket)(fn(string $w) => $chip($w)->onTapGesture(fn() => withAnimation(Animation::snappy(), fn() => $this->move($w, false))))),
            )
                ->padding(10)->frame(width: 240, alignment: Alignment::leading)
                ->background($this->targeted ? Color::accentColor->opacity(0.15) : Color::gray->opacity(0.12))->cornerRadius(8)
                ->overlay(RoundedRectangle(cornerRadius: 8)->stroke($this->targeted ? Color::accentColor : Color::clear, lineWidth: 2))
                ->dropDestination(for: 'string', action: fn(string $w) => withAnimation(Animation::snappy(), fn() => $this->move($w, true)), isTargeted: fn(bool $over) => $this->targeted = $over),
        ),
    )->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading),
    // A sheet at the bottom: pulled up or down, it rests at the nearest of three heights, stretching past them.
    VStack(spacing: 8)(
        Capsule()->fill(Color::secondary->opacity(0.5))->frame(width: 40, height: 5),
        Text('A sheet')->font(Font::headline),
        Text($this->sheetDrag != 0 ? 'Let go to rest at the nearest height.' : 'Pull it up or down: it rests at three heights.')->font(Font::caption)->foregroundStyle(Color::secondary),
    )
        ->padding(16)->frame(maxWidth: INF, alignment: Alignment::top)->frame(height: 240, alignment: Alignment::top)
        ->background(Color::background)->cornerRadius(16)->shadow(Color::black->opacity(0.2), radius: 12, y: -4)
        ->offset(y: Board::rubberBand($this->sheet + $this->sheetDrag, 0, 180))
        ->gesture(DragGesture(minimumDistance: 0)
            ->onChanged(fn(DragGestureValue $v) => $this->sheetDrag = $v->translation->height)
            ->onEnded(function (DragGestureValue $v) {
                $to = Board::nearest(HEIGHTS, $this->sheet + $v->predictedEndTranslation->height);
                $this->sheet = Board::rubberBand($this->sheet + $v->translation->height, 0, 180);
                $this->sheetDrag = 0;
                withAnimation(Animation::snappy(), fn() => $this->sheet = $to);
            })),
);
