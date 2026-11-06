<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Plugins\Glass\{Blur, Glass, ScrollEdge, Style};
use MyPHP\UI\LinearGradient;

// Liquid Glass over content that scrolls: a toolbar, a pill the slider moves, a blur fading downward, and the scroll
// edge of a bar at the bottom, each placed in the window (offset in a ZStack) as the glass plugin shows them.
return fn() => GeometryReader(function (GeometryProxy $g) {
    [$w, $h] = $g->size;
    $glass = new Glass($this->clear ? Style::Clear : Style::Regular, null, 0.5);
    $tint = $this->tinted ? Color::accentColor : null;
    return ZStack(alignment: Alignment::topLeading)(
        // What is under the glass: the photo filling the width, then a strip of colors, then lines, all scrolling.
        ScrollView()(VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
            Image($this->photo)->resizable()->scaledToFit()->frame(width: $w),
            HStack(spacing: 10)(Each(range(0, 39))(fn(int $i) => RoundedRectangle(cornerRadius: 18)->fill(Color::rgb(($i * 2654435761) & 0xffffff))->frame(width: 48, height: 48)))->padding(16),
            VStack(alignment: HorizontalAlignment::leading, spacing: 8)(Each(range(0, 11))(fn(int $i) => Text(verbatim: "Line $i under the glass — 玻璃下面的文字")->font(Font::system(size: 16))))->padding(16),
        )),
        // The toolbar: a glass row floating over the content.
        HStack(spacing: 14)(
            Text('Liquid Glass')->font(Font::system(size: 15, weight: Weight::bold)),
            Toggle('Clear', isOn: $this->clear)->toggleStyle(ToggleStyle::checkbox),
            Toggle('Tinted', isOn: $this->tinted)->toggleStyle(ToggleStyle::checkbox),
            Slider(value: $this->x, in: [0, max(1, $w - 260)])->frame(width: 160)->help('moves the pill'),
            Button(action: fn() => $this->presses++, label: Text('Press me')->font(Font::system(size: 13)))->buttonStyle(ButtonStyle::plain)
                ->padding(6)->cornerRadius(14)->glassEffect(new Glass(interactive: true)),
            Text(verbatim: "pressed {$this->presses}")->font(Font::caption)->foregroundStyle(Color::secondary),
        )->padding(10)->cornerRadius(22)->glassEffect($glass, tint: $tint)->offset(x: 16, y: 16),
        // A pill the slider moves across the photo: the lens bends what shows near its edge.
        Text('**glass** over the photo')->font(Font::system(size: 14))->padding(14)->cornerRadius(28)->glassEffect($glass, tint: $tint)->offset(x: $this->x, y: 120),
        // A blur fading from full at its top to nothing at its bottom (a progressive blur, in levels), across the window.
        Text('Blur, fading downward')->font(Font::system(size: 13, weight: Weight::bold))->padding(12)
            ->frame(width: $w, height: 90, alignment: Alignment::topLeading)->glassEffect(new Blur(10, new LinearGradient(0x000000, 1, 0x000000, 0, 180)))->offset(y: 300),
        // The scroll edge at the bottom: a bar of controls stands out from what scrolls under it.
        HStack(spacing: 12)(
            Toggle('Hard scroll edge', isOn: $this->hard)->toggleStyle(ToggleStyle::checkbox),
            Text('scroll the content: it blurs and fades under this bar')->font(Font::caption)->foregroundStyle(Color::secondary),
        )->padding(16)->frame(width: $w, height: 64, alignment: Alignment::leading)->glassEffect(new ScrollEdge(hard: $this->hard, bottom: true))->offset(y: $h - 64),
    );
});
