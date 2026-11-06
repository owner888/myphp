<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Plugins\Loading\{Cap, Easing, Loading, Options};
use MyPHP\UI\Context;

// The options, then every indicator of the Loading plugin in a card of its own (each the plugin's view, in a
// representable), in as many columns as fit.
return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 0)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 12)( // two rows of them, to fit the window
        HStack(spacing: 12)(
            Picker('Size', selection: $this->size)(Each(['16', '24', '32', '48'])(fn(string $s, int $i) => Text(verbatim: $s)->tag($i))),
            Picker('Color', selection: $this->color)(Text('Text')->tag(0), Text('Accent')->tag(1), Text('Muted')->tag(2)),
        ),
        HStack(spacing: 12)(
            Picker('Easing', selection: $this->easing)(Text('Linear')->tag(0), Text('Ease in out')->tag(1), Text('Stacked')->tag(2)),
            Picker('Cap', selection: $this->cap)(Text('Round')->tag(0), Text('Flat')->tag(1)),
            Toggle('Paused', isOn: $this->paused)->toggleStyle(ToggleStyle::checkbox),
        ),
    )->pickerStyle(PickerStyle::segmented)->padding(16),
    ScrollView()(LazyVGrid(columns: [GridItem::adaptive(minimum: 132)], spacing: 12)(
        Each(Loading::all(), id: fn($s) => $s->name)(fn($s) => VStack(spacing: 14)(
            VStack()(Representable(function (Context $c) use ($s) {
                $t = $c->theme;
                $o = new Options(size: SIZES[$this->size], color: [$t->text, $t->accent, $t->textMuted][$this->color],
                    easing: [Easing::Linear, Easing::EaseInOut, Easing::Stacked][$this->easing], cap: [Cap::RoundCap, Cap::FlatCap][$this->cap], paused: $this->paused);
                return ($s->new)($c, $o)->label($s->name);
            }))->frame(height: 48),
            Text(verbatim: $s->name)->font(Font::caption)->foregroundStyle(Color::secondary),
        )->frame(maxWidth: INF, minHeight: 116)->background(Color::gray->opacity(0.12))->cornerRadius(8)->border(Color::separator)),
    )->padding(Edge::horizontal, 16)->padding(Edge::bottom, 16)),
);
