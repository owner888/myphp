# Calendar

`$c->calendar()` creates a month's calendar choosing the day of a
`DateTimeImmutable` (by reference), as SwiftUI's graphical date picker: a
click chooses a day, as the arrows do while the calendar has the keyboard
focus; Page Up and Page Down, or its buttons, move by months, and Home and
End go to the first and the last day of the month. `$c->changed()` reports
a new date, which keeps the time of day and the time zone of the value.

```php
$app = new class { public DateTimeImmutable $day; public function __construct() { $this->day = new DateTimeImmutable('2026-01-15'); } };
$view = function (Context $c) use ($app) {
    $c->calendar($app->day)->label('Due date');
};
```

It shows six weeks from Sunday, the days of the months around in gray, and
marks today. Its title ("January 2026"), the names of the weekdays and the
first day of the week are en-US's; the buttons' names are
[MyPHP's own strings](../i18n.md#myphps-own-strings).

For a date in a compact field, use a [date input](date-input.md), which
opens this calendar below it.

## Browsing without choosing

`browse()` lets the user look about the calendar without choosing a day:
its buttons and Page Up and Page Down only show other months, and the
arrows, Home and End only move among the days, until a click, Enter or
Space chooses one, as in a date input's calendar. `$c->changed()` then
reports only a day the user picked, as a calendar in a
[popover](popover.md) that closes when a day is picked needs:

```php
$task = new class { public DateTimeImmutable $due; public function __construct() { $this->due = new DateTimeImmutable('2026-02-02'); } };
$app = new class { public bool $dueOpen = false; };
$view = function (Context $c) use ($app, $task) {
    $chip = $c->button($task->due->format('M j'));
    if ($c->clicked($chip)) {
        $app->dueOpen = !$app->dueOpen;
    }
    $c->popover($chip, $app->dueOpen, function () use ($c, $app, $task) {
        if ($c->changed($c->calendar($task->due)->browse())) {
            $app->dueOpen = false;
        }
    });
};
```

The calendar goes back to the value's month as the app sets another date.

## Accessibility

Assistive technology sees a table named by its month, whose days are
buttons named by their dates, the day chosen checked, and reads the day
chosen as the arrows move; with `browse()`, the day the arrows are on.
