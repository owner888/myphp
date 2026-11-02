# Date input

`$c->dateInput()` creates a field showing a `DateTimeImmutable` (by
reference) as a date in numbers, as en-US writes it, 1/5/2026, which a
[calendar](calendar.md) below it changes. A click, Enter or Space opens the
calendar, where a click chooses a day, as do the arrows and Enter; Page Up
and Page Down, or its buttons, move by months, and Escape closes it.
`$c->changed()` reports a new date, which keeps the time of day and the
time zone of the value.

```php
$app = new class { public DateTimeImmutable $birthday; public function __construct() { $this->birthday = new DateTimeImmutable('1990-05-01'); } };
$view = function (Context $c) use ($app) {
    $c->field('Birthday', function () use ($c, $app) {
        $c->dateInput($app->birthday);
    });
};
```

With a [time input](time-input.md) beside it, the two edit one
`DateTimeImmutable`:

```php
$app = new class { public DateTimeImmutable $meeting; public function __construct() { $this->meeting = new DateTimeImmutable('2026-03-02 14:00'); } };
$view = function (Context $c) use ($app) {
    $c->row(gap: 8);
      $c->dateInput($app->meeting)->label('Date');
      $c->timeInput($app->meeting)->label('Time');
    $c->end();
};
```

## Accessibility

Assistive technology sees a pop-up button whose value is the date, named by
its `label()` or its [field](form.md), and the calendar while it is open.
