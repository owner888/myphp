<?php
// A press that chooses shows in the frame it lands in (mygo's
// selection_press_test.go): the pass that saw the press builds again
// before the frame is painted, in rows of the view's own and in a list's;
// a button still clicks on its release, over it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, ListState, TextState};

foreach (['rows of its own' => false, 'a list' => true] as $what => $virtual) {
    $chosen = 0; $builds = 0; $draft = new TextState(''); $state = new ListState;
    $h = new Headless(function (Context $c) use (&$chosen, &$builds, $draft, $state, $virtual): void {
        $builds++;
        $c->column(0, 0, 1);
          $c->text("Selected $chosen");
          $list = $c->column(0, 0, 1)->focusable()->label('Items');
          $row = function (int $i) use ($c, &$chosen): void {
              $e = $c->key("row$i")->row()->height(32)->label("Row $i");
              if ($c->pressed($e) || $c->clicked($e)) { $chosen = $i; }
                $c->text("Row $i");
              $c->end();
          };
          if ($virtual) { $c->list($state, 2, $row)->grow(1); } else { for ($i = 0; $i < 2; $i++) { $row($i); } }
          $c->end();
          $c->input($draft, '', 200)->label('Editor');
        $c->end();
    }, 240, 200);
    $h->frame();
    $h->clickLabel('Editor');
    [$x, $y] = $h->middle($h->byLabel('Row 1'));
    $builds = 0;
    $h->input->mouse($x, $y); $h->input->mouseDown = true; $h->input->push(['down', $x, $y, 0, 1, 0]);
    $h->frame(1);
    check($chosen === 1 && $h->byText('Selected 1') !== null, "$what: the press chose, and the frame painted shows it");
    same(2, $builds, "$what: the press built two passes");
    $builds = 0; $h->frame(1);
    same(1, $builds, "$what: holding the pointer builds one");
}

// A button clicks on its release over it, not on the press, nor on a release away from it.
$clicks = 0;
$h = new Headless(function (Context $c) use (&$clicks): void {
    $b = $c->button('Button')->label('Button control');
    if ($c->clicked($b)) { $clicks++; }
}, 240, 100);
$h->frame();
$r = $h->rect($h->byLabel('Button control'));
[$x, $y] = [$r[0] + $r[2] / 2, $r[1] + $r[3] / 2];
$h->press($x, $y);
same(0, $clicks, 'not on the press');
$h->release($x, $y);
same(1, $clicks, 'on the release');
$h->press($x, $y); $h->drag($r[0] + $r[2] + 10, $y); $h->release($r[0] + $r[2] + 10, $y);
same(1, $clicks, 'not released away from it');
