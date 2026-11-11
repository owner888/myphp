# Date picker

A `DatePicker` edits a `DateTimeImmutable` (bound by reference): its date in
the user's regional format (`App::region()`, e.g. `2026/1/5` in `zh-CN` or
`1/5/2026` in `en-US`), which a [calendar](calendar.md) below it changes, and
its time of day. A click,
Enter or Space opens the calendar, where a click chooses a day, as do the
arrows and Enter; Page Up and Page Down, or its buttons, move by months,
and Escape closes it. A change keeps the time zone of the value.

```php
$app = new class {
    public DateTimeImmutable $due;
    public function __construct() { $this->due = new DateTimeImmutable('2026-01-05 15:04'); }
};
$view = fn() => Form()(
    DatePicker('Due', selection: $app->due),
    DatePicker('Day only', selection: $app->due, displayedComponents: [DatePickerComponents::date]),
);
```

`displayedComponents:` chooses the date, the time of day
(`DatePickerComponents::hourAndMinute`, [time](time-input.md)) or both;
`->datePickerStyle(DatePickerStyle::graphical)` shows the calendar itself.

## Accessibility

Assistive technology sees a pop-up button whose value is the date, named by
its title, and the calendar while it is open.
