# Sheet

`->sheet()` shows a view over the window while a `bool` (bound by
reference) is true, as SwiftUI's: on the desktop a dialog over the dimmed
window, on a phone a sheet rising from the bottom, on an iPad a page sheet
centered. A tap outside, Escape, or a drag down on a phone sets it false.

```php
$app = new class { public bool $editing = true; public string $title = 'Groceries'; };
$view = fn() => Button('Rename…', action: fn() => $app->editing = true)->padding()
    ->sheet(isPresented: $app->editing, content: fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
        Text('Rename')->font(Font::headline),
        TextField('Title', text: $app->title),
        HStack()(Spacer(), Button('Done', action: fn() => $app->editing = false)->buttonStyle(ButtonStyle::borderedProminent)),
    )->padding()->frame(width: 320));
```

On a phone `->presentationDetents([PresentationDetent::medium(),
PresentationDetent::large()])` on the content lets it rest at half the
window or the whole. What is behind it takes no input while it shows; the
focus goes into it and comes back as it closes.

## Accessibility

Assistive technology sees a dialog, its content inside.
