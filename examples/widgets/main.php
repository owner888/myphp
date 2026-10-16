<?php
// The smaller widgets in one window: switch, segmented control, tabs with
// their content, a collapsible section, a stepper, a combobox, an
// autocomplete, a token field, a date and a time input, a color well, a form,
// a checkbox group, breadcrumbs, an alert, icons, avatars, a reorderable
// list, badges, a spinner, dividers and spacers. Each writes into $s and reports with changed().
// Run: ./run.sh widgets
declare(strict_types=1);

use Closure, DateTimeImmutable;
use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, ListState, Node, Selection, SVG, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$s = ['wifi' => true, 'view' => 0, 'tab' => 0, 'more' => false, 'count' => 3, 'asking' => false, 'options' => false, 'bold' => false, 'sort' => 'Name', 'query' => new TextState, 'open' => [], 'chosen' => '', 'scale' => 1.0, 'italic' => false, 'stars' => 3, 'low' => 20.0, 'high' => 70.0, 'acc' => [true, false, false], 'fruit' => 'Banana', 'city' => new TextState, 'tags' => ['php', 'ui'], 'when' => new DateTimeImmutable('today 09:30'), 'color' => 0x3b82f6, 'alpha' => 1.0, 'name' => new TextState, 'size' => 1, 'notify' => true, 'resetting' => false, 'mail' => true, 'calendar' => false, 'crumb' => 3, 'steps' => ['Plan', 'Design', 'Build', 'Test', 'Ship'], 'stepList' => new ListState, 'last' => 'nothing yet'];
$s['stepList']->selected = -1; $s['stepList']->selection = new Selection; $s['stepList']->key = fn(int $i) => $s['steps'][$i]; // the choice follows the steps as they move
$s['stepList']->reorder = function (array $rows, int $to) use (&$s) { // the rows dragged go before row $to: take them out, put them back there
    $moving = array_map(fn($r) => $s['steps'][$r], $rows); $out = []; $placed = false;
    foreach ($s['steps'] as $i => $step) {
        if (!$placed && $i >= $to) { array_push($out, ...$moving); $placed = true; }
        if (!in_array($i, $rows, true)) { $out[] = $step; }
    }
    if (!$placed) { array_push($out, ...$moving); }
    $s['steps'] = $out; $s['last'] = 'steps ' . implode(' › ', $out);
};
const FRUITS = ['Apple', 'Banana', 'Cherry', 'Grape', 'Mango', 'Orange', 'Peach', 'Pear', 'Pineapple', 'Plum'];
const CITIES = ['Beijing', 'Berlin', 'Boston', 'Chengdu', 'Chicago', 'Guangzhou', 'Hangzhou', 'London', 'Paris', 'Shanghai', 'Shenzhen', 'Tokyo'];
const TAGS = ['php', 'ui', 'metal', 'ffi', 'layout', 'text', 'input', 'native'];
// Icons are parsed once, not in the view.
$s['icons'] = ['check' => SVG::fromFile(__DIR__ . '/icons/check.svg'), 'star' => SVG::fromFile(__DIR__ . '/icons/star.svg'), 'bell' => SVG::fromFile(__DIR__ . '/icons/bell.svg')];

function view(Context $c, array &$s): void
{
    $t = $c->theme;
    $c->column(20, 14, 1);
      $c->row(0, 10);
        $c->text('Widgets', 20, style: Font::BOLD);
        if ($c->changed($c->searchField($s['query'], 180))) { $s['last'] = 'search ' . json_encode($s['query']->text); }
        $c->spacer(); $c->spinner(); $c->muted('working…');
      $c->end();
      $c->divider();
      // A toolbar: toggles and buttons without a face until hovered.
      $c->toolbar(function () use ($c, &$s) {
          if ($c->changed($c->toggle($s['bold'], 'Bold'))) { $s['last'] = 'bold ' . ($s['bold'] ? 'on' : 'off'); }
          $c->toggle($s['italic'], 'Italic');
          $c->divider(true);
          if ($c->clicked($c->button('Share'))) { $c->toast('Shared'); }
          $c->menuButton('View', ['Icons' => fn() => $s['last'] = 'icons', 'List' => fn() => $s['last'] = 'list']);
      });
      $c->row(0, 16);
        if ($c->changed($c->switch('Wi-Fi', $s['wifi']))) { $s['last'] = 'Wi-Fi ' . ($s['wifi'] ? 'on' : 'off'); }
        $c->badge($s['wifi'] ? 'connected' : 'off');
        $c->spacer();
        if ($c->changed($c->segmented(['List', 'Grid', 'Map'], $s['view']))) { $s['last'] = 'view ' . $s['view']; }
      $c->end();
      $c->divider();
      if ($c->changed($c->tabs(['General', 'Advanced', 'About'], $s['tab']))) { $s['last'] = 'tab ' . $s['tab']; }
      $c->key('tab' . $s['tab'])->column(12, 10, 0, $t->surface)->round($t->radius); // keyed by tab: each tab's controls keep their own state (hover, focus, locals) apart from the others'
        if ($s['tab'] === 0) {
            $c->row(0, 10); $c->text('Copies'); $c->text((string)$s['count']); if ($c->changed($c->stepper($s['count'], 1, 10))) { $s['last'] = "count {$s['count']}"; } $c->end();
            $c->row(0, 10); $c->text('Scale'); if ($c->changed($c->numberInput($s['scale'], 0.5, 4, 0.25))) { $s['last'] = "scale {$s['scale']}"; } $c->end();
            $c->row(0, 10); $c->text('Disk'); $c->meter($s['count'] * 10, 0, 100, 80, 95); $c->muted("{$s['count']}0 % used"); $c->end();
            // Icons in the text's color at the font size, or a size and color of their own; avatars from names.
            $c->row(0, 10)->align(Node::CENTER); $c->text('Icons'); $c->icon($s['icons']['check']); $c->icon($s['icons']['star'], 20, $t->warning); $c->icon($s['icons']['bell'], 28); $c->avatar('Ada Lovelace'); $c->avatar('Grace Hopper'); $c->avatar('Linus'); $c->end();
            $c->row(0, 10)->align(Node::CENTER); $c->text('Fruit'); if ($c->changed($c->combobox($s['fruit'], FRUITS))) { $s['last'] = "fruit {$s['fruit']}"; } $c->end();
            $c->row(0, 10)->align(Node::CENTER); $c->text('City'); $ac = $c->autocomplete($s['city'], CITIES, 'City'); if ($c->changed($ac)) { $s['last'] = "city {$s['city']->text}"; } if ($c->submitted($ac)) { $s['last'] = "city {$s['city']->text} ⏎"; } $c->end();
            $c->row(0, 10)->align(Node::CENTER); $c->text('When'); if ($c->changed($c->dateInput($s['when']))) { $s['last'] = 'date ' . $s['when']->format('Y-m-d'); } if ($c->changed($c->timeInput($s['when']))) { $s['last'] = 'time ' . $s['when']->format('H:i'); } $c->end();
            $c->row(0, 10)->align(Node::START); $c->text('Tags'); if ($c->changed($c->tokenField($s['tags'], TAGS))) { $s['last'] = 'tags ' . implode(', ', $s['tags']); } $c->end();
            if ($c->collapsible('More options', $s['more'])) {
                $c->column(12, 6);
                  $c->muted('Shown while the section is open; the header toggles it.');
                  $c->row(0, 8); $c->badge('new'); $c->badge('3'); $c->badge('beta'); $c->end();
                  // A check box over check boxes: mixed while some are checked; its click checks or clears them all.
                  if ($c->changed($c->checkboxGroup('Notifications', function () use ($c, &$s) { $c->checkbox('Mail', $s['mail']); $c->checkbox('Calendar', $s['calendar']); }))) { $s['last'] = 'notifications ' . (int)$s['mail'] . (int)$s['calendar']; }
                $c->end();
            }
        } elseif ($s['tab'] === 1) {
            // A tree: the arrows open and close, Up/Down/Left/Right move the focus, a click chooses.
            $c->tree(function () use ($c, &$s) {
                $item = function (string $path, ?Closure $kids = null) use ($c, &$s, &$item) {
                    $name = basename($path);
                    if ($kids === null) { $leaf = null; $n = $c->treeItem($name, $leaf, null, $s['chosen'] === $path); }
                    else { $s['open'][$path] ??= $path === 'src'; $n = $c->treeItem($name, $s['open'][$path], $kids, $s['chosen'] === $path); }
                    if ($c->clicked($n)) { $s['chosen'] = $path; $s['last'] = "chose $path"; }
                };
                $item('src', function () use ($item) { $item('src/App.php'); $item('src/UI', function () use ($item) { $item('src/UI/Context.php'); $item('src/UI/Node.php'); }); });
                $item('examples', function () use ($item) { $item('examples/widgets'); });
                $item('README.md');
            });
            // A form: labels in a column of their own, right-aligned, level with their controls; a click on one focuses its control.
            $c->form(function () use ($c, &$s) {
                $c->field('Name', function () use ($c, &$s) { $c->input($s['name'], 'Jane Doe', 0); }, 'Shown on receipts.', trim($s['name']->text) === '' ? 'Required' : '');
                $c->field('Size', function () use ($c, &$s) { $c->select(['Small', 'Medium', 'Large'], $s['size']); });
                $c->fieldset('Notifications', function () use ($c, &$s) {
                    $c->field('Mail', function () use ($c, &$s) { if ($c->changed($c->checkbox('Send me mail', $s['notify']))) { $s['last'] = 'notify ' . ($s['notify'] ? 'on' : 'off'); } });
                });
            });
            // A list whose rows drag to another place (Cmd-click chooses several to drag together); the choice follows them by key.
            $c->row(0, 10)->align(Node::START); $c->text('Steps');
              $c->column(0, 0, 0, $t->background)->size(200, 5 * 28 + 2)->round($t->radius)->border();
                $c->list($s['stepList'], count($s['steps']), function (int $i) use ($c, $t, &$s) { $c->row(0, 0)->size(0, 28)->padding(0, 10)->align(Node::CENTER); $c->text($s['steps'][$i]); $c->end(); });
              $c->end();
            $c->end();
            // A calendar: a click or the arrows choose a day, Page Up and Page Down move by months.
            $c->row(0, 10)->align(Node::START); $c->text('Calendar'); if ($c->changed($c->calendar($s['when']))) { $s['last'] = 'calendar ' . $s['when']->format('Y-m-d'); } $c->end();
        } else {
            $c->rich([new \MyPHP\UI\Span('MyPHP ', style: Font::BOLD), new \MyPHP\UI\Span(App::VERSION, color: $t->textMuted)]);
            $crumbs = ['Macintosh HD', 'Users', 'developer', 'Documents', 'Widgets']; // a path: the items but the last are links
            if ($c->changed($c->breadcrumbs(array_slice($crumbs, 0, $s['crumb'] + 1), $s['crumb']))) { $s['last'] = 'went to ' . $crumbs[$s['crumb']]; }
            $c->row(0, 10)->align(Node::CENTER); $c->text('Color'); if ($c->changed($c->colorWell($s['color'], $s['alpha']))) { $s['last'] = 'color ' . Context::hexOf($s['color'], $s['alpha']); } $c->muted(Context::hexOf($s['color'], $s['alpha'])); $c->end();
            $c->row(0, 10); $c->text('Rating'); if ($c->changed($c->rating($s['stars']))) { $s['last'] = "stars {$s['stars']}"; } $c->end();
            $c->row(0, 10); $c->text('Range'); if ($c->changed($c->rangeSlider($s['low'], $s['high'], 0, 100, 5, 240))) { $s['last'] = sprintf('range %d–%d', $s['low'], $s['high']); } $c->muted(sprintf('%d–%d', $s['low'], $s['high'])); $c->end();
            // An accordion: sections in a bordered box, Up and Down between their headers.
            $c->accordion(function () use ($c, &$s) {
                $c->accordionItem('Appearance', $s['acc'][0], function () use ($c) { $c->muted('Theme, accent, text size.'); });
                $c->accordionItem('Shortcuts', $s['acc'][1], function () use ($c) { $c->muted('Cmd+N new, Cmd+S save.'); });
                $c->accordionItem('About', $s['acc'][2], function () use ($c) { $c->muted('The widgets example.'); });
            });
        }
      $c->end();
      $c->row(0, 8);
        if ($c->clicked($c->primaryButton('Save'))) { $c->toast('Saved'); $s['last'] = 'saved'; }
        if ($c->clicked($c->button('Delete…'))) { $s['asking'] = true; }
        if ($c->clicked($c->button('Reset…'))) { $s['resetting'] = true; }
        $c->menuButton('Sort by', ['Name' => function () use (&$s) { $s['sort'] = 'Name'; $s['last'] = 'sort by name'; }, 'Date' => function () use (&$s) { $s['sort'] = 'Date'; $s['last'] = 'sort by date'; }]);
        $anchor = $c->button('Options');
        if ($c->clicked($anchor)) { $s['options'] = !$s['options']; }
      $c->end();
      // A popover below the Options button: a press elsewhere closes it.
      $c->popover($anchor, $s['options'], function () use ($c, &$s) {
          $c->column(8, 8);
            $c->checkbox('Bold titles', $s['bold']);
            $c->muted('Sorted by ' . $s['sort'], 11);
          $c->end();
      });
      $c->spacer();
      $c->divider();
      $c->muted('last change: ' . $s['last'], 12);
    $c->end();
    // A modal over it all: what is behind is inert until it closes.
    $modal = $c->modal($s['asking'], function () use ($c, &$s) {
        $c->text('Delete the draft?', 15, style: Font::BOLD);
        $c->muted("You can't undo this.");
        $c->row(0, 8)->justify(Node::END);
          if ($c->clicked($c->button('Cancel'))) { $s['asking'] = false; }
          if ($c->clicked($c->primaryButton('Delete'))) { $s['asking'] = false; $c->toast('Draft deleted'); $s['last'] = 'deleted'; }
        $c->end();
    });
    if ($modal !== null && $c->changed($modal)) { $s['last'] = 'dismissed'; }
    // An alert: the last button is the default, Enter clicks it, Escape chooses none.
    switch ($c->alertDialog($s['resetting'], 'Reset all settings?', 'Every control goes back to how it started.', 'Cancel', 'Reset')) {
        case 1: $s['last'] = 'reset'; $c->toast('Settings reset'); break;
        case Context::ALERT_DISMISSED: $s['last'] = 'reset dismissed'; break;
    }
}

App::setName('Widgets');
App::whenReady(function () use (&$s) {
    new Window(new WindowOptions(title: 'MyPHP native — widgets', width: 560, height: 420,
        content: Content::view(function (Context $c) use (&$s) { view($c, $s); })));
});
App::run();
