<?php
// The smaller widgets in one window: switch, segmented control, tabs with
// their content, a collapsible section, a stepper, a combobox, an
// autocomplete, a token field, a date and a time input, a color well, a form,
// a checkbox group, breadcrumbs, an alert, icons, avatars, a reorderable
// list, badges, a spinner, dividers and spacers. Each writes into $s and reports with changed().
// Run: ./run.sh widgets
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, ListState, Selection, SVG, TextState};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

$s = (object)['wifi' => true, 'view' => 0, 'tab' => 0, 'more' => false, 'count' => 3, 'asking' => false, 'options' => false, 'bold' => false, 'sort' => 'Name', 'query' => new TextState, 'open' => [], 'chosen' => '', 'scale' => 1.0, 'italic' => false, 'stars' => 3, 'low' => 20.0, 'high' => 70.0, 'acc' => [true, false, false], 'fruit' => 'Banana', 'city' => new TextState, 'tags' => ['php', 'ui'], 'when' => new DateTimeImmutable('today 09:30'), 'color' => 0x3b82f6, 'alpha' => 1.0, 'name' => new TextState, 'size' => 1, 'notify' => true, 'resetting' => false, 'mail' => true, 'calendar' => false, 'crumb' => 3, 'steps' => ['Plan', 'Design', 'Build', 'Test', 'Ship'], 'stepList' => new ListState, 'last' => 'nothing yet'];
$s->stepList->selected = -1; $s->stepList->selection = new Selection; $s->stepList->key = function (int $i) use (&$s) { return $s->steps[$i]; }; // the choice follows the steps as they move (by reference: an arrow function would keep the steps as they were)
$s->stepList->reorder = function (array $rows, int $to) use (&$s) { // the rows dragged go before row $to: take them out, put them back there
    $moving = array_map(fn($r) => $s->steps[$r], $rows); $out = []; $placed = false;
    foreach ($s->steps as $i => $step) {
        if (!$placed && $i >= $to) { array_push($out, ...$moving); $placed = true; }
        if (!in_array($i, $rows, true)) { $out[] = $step; }
    }
    if (!$placed) { array_push($out, ...$moving); }
    $s->steps = $out; $s->last = 'steps ' . implode(' › ', $out);
};
const FRUITS = ['Apple', 'Banana', 'Cherry', 'Grape', 'Mango', 'Orange', 'Peach', 'Pear', 'Pineapple', 'Plum'];
const CITIES = ['Beijing', 'Berlin', 'Boston', 'Chengdu', 'Chicago', 'Guangzhou', 'Hangzhou', 'London', 'Paris', 'Shanghai', 'Shenzhen', 'Tokyo'];
const TAGS = ['php', 'ui', 'metal', 'ffi', 'layout', 'text', 'input', 'native'];
// Icons are parsed once, not in the view.
$s->icons = ['check' => SVG::fromFile(__DIR__ . '/icons/check.svg'), 'star' => SVG::fromFile(__DIR__ . '/icons/star.svg'), 'bell' => SVG::fromFile(__DIR__ . '/icons/bell.svg'), 'palette' => SVG::fromFile(__DIR__ . '/icons/palette.svg')];

App::setName('Widgets');
App::setLanguages('en', 'zh-Hans', 'zh-Hant'); // the languages the example is written in: MyPHP's own strings are in them; the example's own stay English
App::whenReady(function () use (&$s) {
    new Window(new WindowOptions(title: 'MyPHP native — widgets', width: 560, height: 420,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
