<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

// What scripts/smoke-automation.php drives through the automation plugin, by the keys (id) it finds them by.
return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    Text(verbatim: "Count: {$this->count}"),
    Button('Add', action: fn() => $this->count++)->id('add'),
    TextField('Name', text: $this->name)->frame(width: 240)->id('name'),
    Text(verbatim: "Hello, {$this->name}"),
    // Its Delete button shows while the row hovers; at rest it lets presses through to the row, but assistive technology presses it.
    HStack(spacing: 8)(
        Text('A row')->frame(maxWidth: INF, alignment: Alignment::leading),
        Button('Delete', action: fn() => $this->deletes++)->opacity($this->rowHovered ? 1 : 0)->allowsHitTesting($this->rowHovered)->id('delete'),
    )->padding(8)->frame(width: 320, height: 44)->background(Color::gray->opacity(0.12))->cornerRadius(8)->accessibilityLabel('Row')
        ->onHover(fn(bool $over) => $this->rowHovered = $over)->onTapGesture(fn() => $this->rowClicks++)->id('row'),
    Text(verbatim: "Row clicks: {$this->rowClicks}, deletes: {$this->deletes}"),
    // Shapes drawn in bands of rows around their round corners (v1.115): their edges should look as ever.
    HStack(spacing: 16)(
        Circle()->stroke(Color::accentColor, lineWidth: 2)->frame(width: 64, height: 64),
        RoundedRectangle(cornerRadius: 12)->stroke(Color::separator)->frame(width: 120, height: 64),
        RoundedRectangle(cornerRadius: 16)->fill(Color::background)->frame(width: 120, height: 64)->shadow(Color::black->opacity(0.35), radius: 9, y: 6),
    ),
)->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading);
