# Calendar

A `DatePicker` with `->datePickerStyle(DatePickerStyle::graphical)` is a
month's calendar choosing the day of a `DateTimeImmutable` (bound by
reference), as SwiftUI's graphical date picker: a click chooses a day, as
the arrows do while the calendar has the keyboard focus; Page Up and Page
Down, or its buttons, move by months, and Home and End go to the first and
the last day of the month. A change keeps the time of day and the time
zone of the value.

```php
$app = new class {
    public DateTimeImmutable $day;
    public function __construct() { $this->day = new DateTimeImmutable('2026-01-05'); }
};
$view = fn() => DatePicker('Day', selection: $app->day, displayedComponents: [DatePickerComponents::date])
    ->datePickerStyle(DatePickerStyle::graphical)->padding();
```

It shows six weeks from Sunday, the days of the months around in gray, and
marks today. Its title ("January 2026"), the names of the weekdays and the
first day of the week are en-US's; the buttons' names are
[MyPHP's own strings](../i18n.md#myphps-own-strings).

## Accessibility

Assistive technology sees a grid of the days, the chosen one selected.
