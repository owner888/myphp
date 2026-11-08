# Time picker

A `DatePicker` of `displayedComponents: [DatePickerComponents::hourAndMinute]`
edits the time of day of a `DateTimeImmutable` (bound by reference), in the
12-hour cycle of en-US: 3:04 PM. Its hours, minutes and half of the day
each take the keyboard focus, as the segments of AppKit's date picker: Up
and Down step the one with the focus round the clock, digits typed set it,
going on to the minutes once the hours are typed, A or P chooses the half
of the day, and Left and Right move between them. A change keeps the date
and the time zone of the value.

```php
$app = new class {
    public DateTimeImmutable $alarm;
    public function __construct() { $this->alarm = new DateTimeImmutable('2026-01-05 07:30'); }
};
$view = fn() => Form()(
    DatePicker('Alarm', selection: $app->alarm, displayedComponents: [DatePickerComponents::hourAndMinute]),
);
```

## Accessibility

Assistive technology sees spin buttons, named after the title and "hours",
"minutes" and "AM/PM".
