<?php
// The widgets example, headless: its Tab order, what the keys do at each
// kind of control, the pointer's cursor. Numbers are read off the example
// as it is; change them when it changes.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Key, Node};

$w = Headless::widgets(); $view = $w[0]; $s = &$w[1]; // by reference: the view reads the state the test sets
$h = new Headless($view, 900, 700);

// Tab goes round every control once; groups (toolbar, segmented, tabs, accordion) are one stop; every stop shows its focus.
foreach ([0 => 25, 1 => 22, 2 => 19] as $tab => $count) {
    $s['tab'] = $tab; $s['more'] = true; $h->rt->focus = null; $h->frame();
    $stops = $h->tabStops();
    same($count, count($stops), "tab $tab: stops (" . implode(' → ', $stops) . ')');
    same('I(Search)', $stops[0], "tab $tab: the first stop");
    same('B(Options)', end($stops), "tab $tab: the last stop");
    same(1, count(array_filter($stops, fn($l) => $l === 'B(Bold)')), "tab $tab: the toolbar is one stop");
    $h->tab(true);
    same('B(Options)', Headless::label($h->focused()), "tab $tab: Shift+Tab from the first stop goes to the last");
}
$s['tab'] = 2; $h->rt->focus = null; $h->frame(); $stops = $h->tabStops();
same(2, count(array_filter($stops, fn($l) => $l === 'X')), 'both knobs of the range slider are stops');

// Escape: a button lets the focus go and the window still gets the key; a field keeps it.
$probe = ['esc' => false];
$h2 = new Headless(function ($c) use ($view, &$probe) { $view($c); $probe['esc'] = $probe['esc'] || $c->shortcut(Key::ESCAPE); }, 900, 700);
$s['tab'] = 0; $h2->frame(); $h2->tab(); $h2->tab();
same('B(Bold)', Headless::label($h2->focused()), 'Tab twice: the toolbar');
$probe['esc'] = false; $h2->escape();
check($h2->focused() === null, 'Escape on a button drops the focus');
check($probe['esc'], 'the window saw the Escape');
$h2->tab();
same('I(Search)', Headless::label($h2->focused()), 'Tab again starts over');
$h2->type('abc'); $probe['esc'] = false; $h2->escape();
same('I(Search)', Headless::label($h2->focused()), 'a search field keeps the focus on Escape');
same('', $s['query']->text, 'and clears');
check(!$probe['esc'], 'which took the key');
$probe['esc'] = false; $h2->escape();
check($probe['esc'], 'an empty field leaves Escape to the window');

// Keys at the controls of the General tab.
$h->rt->focus = null; $s['tab'] = 0; $h->frame();
$at = function (string $label) use ($h) { for ($i = 0; $i < 40; $i++) { $h->tab(); if (Headless::label($h->focused()) === $label) { return; } } throw new \RuntimeException("no stop $label"); };
$at('SW(Wi-Fi)'); $was = $s['wifi']; $h->space(); same(!$was, $s['wifi'], 'Space toggles the switch'); $h->enter(); same($was, $s['wifi'], 'Enter toggles it back');
$at('C(List)'); $h->right(); same(1, $s['view'], 'Right in the segmented control chooses the next'); $h->left(); same(0, $s['view'], 'Left the previous');
$h->tab(); $h->tab(); // the tabs, then the stepper
$count = $s['count']; $h->up(); same($count + 1, $s['count'], 'Up steps the stepper'); $h->down(); same($count, $s['count'], 'Down steps it back');
$h->tab(); $scale = $s['scale']; $h->up(); same($scale + 0.25, $s['scale'], 'Up steps the number input'); $h->down(); same($scale, $s['scale'], 'Down back');
$h->tab(); $h->tab(); $h->tab(); // −, +, the combobox
same('I', Headless::label($h->focused()), 'the combobox input');
$h->down(); same(2, count($h->root->kids), 'Down opens its options at the root');
$h->escape(); same(1, count($h->root->kids), 'Escape closes them');
$h->tab(); $h->tab(); // the city, the date
same('C(10/16/2026)', Headless::label($h->focused()), 'the date input'); $h->enter();
same(2, count($h->root->kids), 'Enter opens the calendar'); same('C', Headless::label($h->focused()), 'which takes the focus');
$h->escape(); same(1, count($h->root->kids), 'Escape closes it'); same('C(10/16/2026)', Headless::label($h->focused()), 'and the focus comes back to the date input');
$h->tab(); same('C(9)', Headless::label($h->focused()), 'the hours'); $h->right(); same('C(30)', Headless::label($h->focused()), 'Right moves to the minutes');
$m = $s['when']->format('i'); $h->up(); same(sprintf('%02d', ((int)$m + 1) % 60), $s['when']->format('i'), 'Up steps the minutes');

// The alert: Enter and Space on Reset… open it with the default button focused; Escape dismisses, and opening again focuses it again.
$at('B(Reset…)');
foreach (['enter', 'space'] as $how) {
    $h->$how();
    check($s['resetting'], "$how opens the alert"); same('B(Reset)', Headless::label($h->focused()), "the default button has the focus ($how)");
    $h->escape(); check(!$s['resetting'], 'Escape dismisses it'); same('reset dismissed', $s['last'], 'which the app sees');
    $at('B(Reset…)');
}
$h->enter(); $s['resetting'] = false; $h->frame(); // closed by the app
$at('B(Reset…)'); $h->space(); same('B(Reset)', Headless::label($h->focused()), 'closed by the app, it focuses its default again');
$h->escape();

// The cursor: an I-beam over the fields and the fields holding an input, an arrow elsewhere.
$s['tab'] = 0; $h->frame();
$walk = function (Node $n, array &$out) use (&$walk) { $out[] = $n; foreach ($n->kids as $k) { $walk($k, $out); } };
$nodes = []; $walk($h->root, $nodes); $ibeam = 0; $other = [];
foreach ($nodes as $n) {
    if ($n->w <= 0 || $n->h <= 0) { continue; }
    $h->hover($n->x + min(5, $n->w / 2), $n->y + $n->h / 2);
    if ($h->rt->cursor === 'ibeam') { $ibeam++; } elseif ($h->rt->cursor !== 'arrow') { $other[] = $h->rt->cursor; }
}
check($ibeam >= 5, "an I-beam over the fields ($ibeam)"); same([], $other, 'no other cursor in the widgets');
