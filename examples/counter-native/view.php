<?php
// The counter's view, $this the Counter main.php makes (Content::file): saved, it hot reloads.
declare(strict_types=1);

return fn() => VStack(spacing: 16)(
    Text("{$this->n}")->font(Font::system(size: 56, weight: Weight::bold)),
    HStack(spacing: 8)(
        Button('−', action: fn() => $this->n--)
            ->keyboardShortcut(KeyEquivalent::downArrow, modifiers: [])
            ->accessibilityLabel('Decrement')
            ->id('decrement'),
        Button('Reset', action: fn() => $this->n = 0)
            ->disabled($this->n === 0)
            ->id('reset'),
        Button('+', action: fn() => $this->n++)
            ->keyboardShortcut(KeyEquivalent::upArrow, modifiers: [])
            ->buttonStyle(ButtonStyle::borderedProminent)
            ->accessibilityLabel('Increment')
            ->id('increment'),
    ),
    Text('↑ and ↓ count too')->font(Font::caption)->foregroundStyle(Color::secondary),
);
