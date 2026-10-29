<?php
// Dates, times and colors (mygo's datetime_test.go TestCalendar,
// TestTimeInput, TestTimeInput12, TestCalendarRegion, TestColorPicker,
// TestColorWell, TestTimeLayout): what clicks and keys choose, in the
// formats of the language shown. mygo sets the regional formats of a
// tester (SetRegion); here the language shown (setLocale) brings CLDR's,
// as it does without the system's.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\App;
use MyPHP\UI\{AccessNode, AccessTree, Context, Node, Role};

/** The node of $tree with $role named $label exactly. */
function dtNode(AccessTree $tree, int $role, string $label): AccessNode
{
    foreach ($tree->nodes as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    throw new \RuntimeException("no $label among " . json_encode(array_map(fn(AccessNode $n) => $n->label, array_filter($tree->nodes, fn(AccessNode $n) => $n->role === $role))));
}

/** Whether the node with the focus shows $text. */
function dtFocused(Headless $h, string $text): bool
{
    $f = $h->focused();
    return $f !== null && in_array($text, $h->texts($f), true);
}

App::setLanguages('en', 'en-GB', 'zh-Hans', 'zh-Hant', 'fa', 'de', 'fr-CA');
App::setLocale('en');

// A calendar: a click chooses a day, keeping the time; the arrows choose as they move; it follows the day into its month; what assistive technology reads.
$day = new \DateTimeImmutable('2026-10-15 09:41:00', new \DateTimeZone('UTC')); $changes = 0;
$h = new Headless(function (Context $c) use (&$day, &$changes): void {
    if ($c->changed($c->calendar($day))) { $changes++; }
}, 400, 400);
$h->frame();
$h->clickLabel('October 20, 2026');
check($day->format('Y-m-d H:i') === '2026-10-20 09:41' && $changes === 1, "a click: {$day->format('Y-m-d H:i')} ($changes changes)");
foreach ([['right', '2026-10-21'], ['down', '2026-10-28'], ['end', '2026-10-31'], ['home', '2026-10-01'], ['pageDown', '2026-11-01'], ['left', '2026-10-31']] as [$key, $want]) {
    $h->$key();
    same($want, $day->format('Y-m-d'), $key);
}
check($h->byText('October 2026') !== null, 'the calendar follows the day into its month');
$h->input->push(['accessOn']); $h->frame();
$n = dtNode($h->rt->accessTree, Role::BUTTON, 'October 31, 2026');
check($h->rt->accessTree->focus === $n->id && ($n->states & AccessNode::CHECKED) !== 0, 'assistive technology is on the day chosen');

// Browsing (mygo's b7e6bdd): the buttons, Page Down and the arrows move without choosing; a click, Enter or Space chooses; the cursor follows a date the app sets.
$day = new \DateTimeImmutable('2026-10-15 09:41:00', new \DateTimeZone('UTC')); $changes = 0;
$h = new Headless(function (Context $c) use (&$day, &$changes): void {
    if ($c->changed($c->calendar($day)->browse())) { $changes++; }
}, 400, 400);
$h->frame();
$h->clickLabel('Next month');
check($h->byText('November 2026') !== null && $day->format('Y-m-d') === '2026-10-15' && $changes === 0, 'the button shows the next month, choosing none');
$h->clickLabel('November 3, 2026');
check($day->format('Y-m-d') === '2026-11-03' && $changes === 1, 'a click chooses');
$h->right(); $h->pageDown();
check($day->format('Y-m-d') === '2026-11-03' && $h->byText('December 2026') !== null && $changes === 1, 'the keys move the cursor alone');
$h->enter();
check($day->format('Y-m-d') === '2026-12-04' && $changes === 2, 'Enter chooses where it is: ' . $day->format('Y-m-d'));
$day = new \DateTimeImmutable('2027-03-09', new \DateTimeZone('UTC')); $h->frame();
check($h->byText('March 2027') !== null, 'the cursor follows the date the app sets');
check($h->byLabel('Previous month')?->kids[0]?->draw !== null, 'the month buttons draw their chevrons');

// A time input in 24 hours: Up, round the clock, digits going on to the minutes, one that can't begin two digits; the date kept; what it says.
App::setLocale('en-GB');
$alarm = new \DateTimeImmutable('2026-10-04 07:30:00', new \DateTimeZone('UTC')); $changes = 0;
$h = new Headless(function (Context $c) use (&$alarm, &$changes): void {
    if ($c->changed($c->timeInput($alarm)->label('Alarm'))) { $changes++; }
}, 300, 100);
$h->frame();
check($h->byText('07') !== null && $h->byText('30') !== null, 'shows 07 and 30: ' . json_encode($h->texts()));
$h->clickText('07');
$h->up();
check((int)$alarm->format('G') === 8 && $changes === 1, 'Up');
for ($k = 0; $k < 9; $k++) { $h->down(); }
same(23, (int)$alarm->format('G'), 'Down nine times from 8');
$h->type('1'); $h->type('5');
check((int)$alarm->format('G') === 15 && dtFocused($h, '30'), '1 and 5: the hours, then the minutes');
$h->type('4'); $h->type('5');
same(45, (int)$alarm->format('i'), '4 and 5 in the minutes');
$h->left(); $h->type('9');
check((int)$alarm->format('G') === 9 && dtFocused($h, '45'), '9, which can\'t begin two digits of hours, goes on');
same('2026-10-04', $alarm->format('Y-m-d'), 'the date kept');
$h->input->push(['accessOn']); $h->frame();
$hours = dtNode($h->rt->accessTree, Role::STEPPER, 'Alarm hours');
check($hours->now == 9 && $hours->max == 23, 'hours: ' . json_encode([$hours->now, $hours->max]));
dtNode($h->rt->accessTree, Role::STEPPER, 'Alarm minutes');
$h->input->push(['access', $hours->id, AccessNode::INCREMENT]); $h->frame();
same(10, (int)$alarm->format('G'), 'assistive technology steps the hours');

// In 12 hours: the half of the day a segment of its own, after the hours in English.
App::setLocale('en');
$alarm = new \DateTimeImmutable('2026-10-04 07:30:00', new \DateTimeZone('UTC'));
$h = new Headless(function (Context $c) use (&$alarm): void { $c->timeInput($alarm)->label('Alarm'); }, 300, 100);
$h->frame();
same(['7', ':', '30', 'AM'], $h->texts(), 'in English');
$h->clickText('AM');
$h->up();
same(19, (int)$alarm->format('G'), 'Up on AM');
$h->type('a');
same(7, (int)$alarm->format('G'), 'A');
$h->clickText('7');
for ($k = 0; $k < 7; $k++) { $h->down(); }
check((int)$alarm->format('G') === 0 && $h->byText('12') !== null, 'seven hours down from 7 AM: 12 AM');
$h->type('1'); $h->type('1');
check((int)$alarm->format('G') === 11 && dtFocused($h, '30'), '1 and 1');
App::setLocale('zh-Hans'); $h->frame();
$texts = $h->texts();
same(['11', ':', '30'], $texts, 'in Chinese, whose formats are of 24 hours without the system\'s: no half of the day');

// A calendar's weeks start on the first day of the formats; its names are in the language shown.
App::setLocale('en');
$day = new \DateTimeImmutable('2026-10-15 09:41:00', new \DateTimeZone('UTC'));
$h = new Headless(function (Context $c) use (&$day): void { $c->calendar($day); }, 400, 400);
$h->frame();
$week = function (string $first) use ($h): array {
    $texts = $h->texts(); $i = array_search($first, $texts, true);
    return $i === false ? $texts : array_slice($texts, $i, 7);
};
same(['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], $week('Su'), 'the days of a week from Sunday');
$h->input->push(['accessOn']); $h->frame();
dtNode($h->rt->accessTree, Role::BUTTON, 'September 27, 2026'); // October 2026 starts on a Thursday
App::setLocale('en-GB'); $h->frame();
same(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'], $week('Mo'), 'from Monday');
$first = null;
foreach ($h->rt->accessTree->nodes as $n) { if ($n->role === Role::BUTTON && str_contains($n->label, '2026') && $first === null) { $first = $n->label; } }
same('28 September 2026', $first, 'the first day shown: the Monday before');
App::setLocale('zh-Hans'); $h->frame();
check($h->byText('2026年10月') !== null && array_filter($h->texts(), fn(string $s) => str_contains($s, '一')) !== [], 'in Chinese, the month and the days of the week');
App::setLocale('fa'); $h->frame();
check($h->byText('۱۵') !== null, 'in Persian, the days in its digits');
App::setLocale('de'); $h->frame();
dtNode($h->rt->accessTree, Role::BUTTON, '15. Oktober 2026');
dtNode($h->rt->accessTree, Role::BUTTON, 'Next month'); // MyPHP's own strings have no German: the source language's
App::setLocale('zh-Hant'); $h->frame();
dtNode($h->rt->accessTree, Role::BUTTON, \MyPHP\L10n::in('zh-Hant')->get('nextMonth'));
App::setLocale('en');

// A color picker: a swatch, the hex typed, the square's corners, a gray keeping its hue, the app's color, its sliders.
$rgb = 0x3b82f6; $alpha = 1.0; $changes = 0;
$h = new Headless(function (Context $c) use (&$rgb, &$alpha, &$changes): void {
    if ($c->changed($c->colorPicker($rgb, $alpha))) { $changes++; }
}, 400, 500);
$h->frame();
$h->clickLabel('Red');
check($rgb === 0xef4444 && $changes === 1, 'Red: ' . Context::hexOf($rgb, $alpha));
$h->clickLabel('Hex');
$h->input->focus->selectAll();
$h->type('#00ff0080');
same('#00ff0080', Context::hexOf($rgb, $alpha), 'typed');
$h->input->push(['accessOn']); $h->frame();
$sq = dtNode($h->rt->accessTree, Role::SLIDER, 'Saturation and brightness')->bounds;
$h->click($sq[0] + $sq[2] - 1, $sq[1] + 1);
[$hue, $sat, $val] = Context::toHSV($rgb);
check($sat >= 0.98 && $val >= 0.98 && $hue >= 115 && $hue <= 125, 'the top-right corner is the hue itself: ' . Context::hexOf($rgb, $alpha));
$h->click($sq[0] + 1, $sq[1] + $sq[3] / 2);
$h->right();
[$hue] = Context::toHSV($rgb);
check($hue >= 115 && $hue <= 125, "out of the grays, the hue is kept: $hue");
$rgb = 0xffffff; $alpha = 1.0; $h->frame();
same('#ffffff', dtNode($h->rt->accessTree, Role::TEXT_FIELD, 'Hex')->value, 'the app\'s color in the hex');
same(360.0, dtNode($h->rt->accessTree, Role::SLIDER, 'Hue')->max, 'the hue goes to 360');

// A color well: its value, opening the picker, a swatch, Escape.
$rgb = 0x3b82f6; $alpha = 1.0;
$h = new Headless(function (Context $c) use (&$rgb, &$alpha): void { $c->colorWell($rgb, $alpha)->label('Tint'); }, 400, 500);
$h->frame();
$h->input->push(['accessOn']); $h->frame();
$n = dtNode($h->rt->accessTree, Role::COLOR_WELL, 'Tint');
check($n->value === '#3b82f6' && ($n->states & AccessNode::EXPANDED) === 0, 'the well, closed');
$h->clickLabel('Tint');
check($h->byLabel('Red') !== null, 'the picker opened');
$h->clickLabel('Green');
same(0x22c55e, $rgb, 'chose green');
$n = dtNode($h->rt->accessTree, Role::COLOR_WELL, 'Tint');
check($n->value === '#22c55e' && ($n->states & AccessNode::EXPANDED) !== 0, 'the well, open, says it');
$h->escape();
check($h->byLabel('Red') === null, 'Escape closes the picker');

// What separates the hours from the minutes, and whether the day period comes first; French of Canada writes 07 h 30.
$layout = new \ReflectionMethod(Context::class, 'timeLayout');
foreach ([['h:mm a', ':', false], ['ah:mm', ':', true], ["H 'h' mm", ' h ', false], ['HH.mm', '.', false], ["H'm' mm", 'm ', false], ["a h'''h' mm", "'h ", true], ['B h:mm', ':', true]] as [$pattern, $sep, $first]) {
    same([$sep, $first], $layout->invoke(null, $pattern), "timeLayout($pattern)");
}
App::setLocale('fr-CA');
$alarm = new \DateTimeImmutable('2026-10-04 07:30:00', new \DateTimeZone('UTC'));
$h = new Headless(function (Context $c) use (&$alarm): void { $c->timeInput($alarm); }, 300, 100);
$h->frame();
check($h->byText(' h ') !== null, 'fr-CA: ' . json_encode($h->texts()));

App::setLocale('');
App::setLanguages();
