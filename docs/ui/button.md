# Button

A `Button` shows a title and runs its `action:` on a click, a tap, or Enter
or Space while it has the keyboard focus:

```php
$app = new class {
    public bool $editing = true;
    public int $saves = 0;
    public function save(): void { $this->saves++; }
};
$view = fn() => HStack(spacing: 8)(
    Button('Cancel', action: fn() => $app->editing = false),
    Button('Save', action: fn() => $app->save())->buttonStyle(ButtonStyle::borderedProminent)->keyboardShortcut(KeyEquivalent::return, modifiers: []),
)->padding();
```

## Styles

`->buttonStyle()` gives it SwiftUI's look: `automatic` (bordered on the
desktop, a capsule on a phone, its title in the tint in a phone's form
row), `borderedProminent` (filled with the tint, for the main action of a
window or a dialog), `bordered`, `borderless` (its title in the tint) and
`plain` (its label alone). `role: ButtonRole::destructive` makes it red;
`ButtonRole::cancel` is the Cancel of an alert or a confirmation dialog.

## Content

`systemImage:` adds an SF Symbol before the title; `label:` takes any view.
A button showing only an icon needs an `accessibilityLabel`, which names it
for assistive technology and tests, and a `help()`:

```php
$app = new class { public int $adds = 0; };
$view = fn() => HStack(spacing: 8)(
    Button('Add', action: fn() => $app->adds++, systemImage: 'plus'),
    Button(action: fn() => $app->adds++, label: Image(systemName: 'plus'))->buttonStyle(ButtonStyle::borderless)->accessibilityLabel('Add')->help('Add an item'),
)->padding();
```

## States

`->disabled()` grays a button out: it takes neither clicks nor the focus.
In a page's `toolbar()`, buttons take no face of their own until hovered.
`->keyboardShortcut('s')` runs it from ⌘S (Ctrl+S on Linux).

## Accessibility

Assistive technology sees a button named by its title or
`accessibilityLabel`, which it presses as a click does.
