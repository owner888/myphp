# Time input

`$c->timeInput()` creates a field of the time of day of a
`DateTimeImmutable` (by reference), in the 12-hour cycle of en-US: 3:04 PM.
Its hours, minutes and half of the day each take the keyboard focus, as the
segments of AppKit's date picker: Up and Down step the one with the focus
round the clock, digits typed set it, going on to the minutes once the
hours are typed, A or P chooses the half of the day, and Left and Right
move between them. `$c->changed()` reports a new time, which keeps the date
and the time zone of the value.

```php
$app = new class { public DateTimeImmutable $alarm; public function __construct() { $this->alarm = new DateTimeImmutable('2026-01-01 07:30'); } };
$view = function (Context $c) use ($app) {
    $c->timeInput($app->alarm)->label('Alarm');
};
```

A click on the hours or the minutes gives them the focus. With a
[date input](date-input.md), the two edit one `DateTimeImmutable`.

## Accessibility

Assistive technology sees spin buttons, named after the field's `label()`
and "hours", "minutes" and "AM/PM".
