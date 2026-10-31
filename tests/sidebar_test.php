<?php
// A sidebar (mygo's sidebar_test.go TestSidebar): a click chooses an item,
// the keys choose among the items across sections, as does typing their
// first letters; one stop of Tab; a section hidden is skipped.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Node};

$place = 'recents'; $changes = 0; $locations = true; $other = null;
$h = new Headless(function (Context $c) use (&$place, &$changes, &$locations, &$other): void {
    $c->row(0, 0, 1)->alignItems(Node::STRETCH);
      $fav = null;
      $sb = $c->sidebar($place, function () use ($c, &$locations, &$fav): void {
          $c->sidebarSection('Favorites', $fav, function () use ($c): void {
              $c->sidebarItem('recents', 'Recents');
              $c->sidebarItem('desktop', 'Desktop', trailing: fn() => $c->badge('3'));
              $c->sidebarItem('documents', 'Documents');
          });
          $c->sidebarSection('Locations', $locations, function () use ($c): void {
              $c->sidebarItem('mac', 'Macintosh HD');
          });
      });
      if ($c->changed($sb)) { $changes++; }
      $other = $c->button('Other');
    $c->end();
}, 500, 400);
$h->frame();
$h->clickText('Desktop');
same(['desktop', 1], [$place, $changes], 'a click chooses');
foreach ([['down', 'documents'], ['down', 'mac'], ['down', 'mac'], ['up', 'documents'], ['home', 'recents'], ['end', 'mac']] as [$key, $want]) {
    $h->$key();
    same($want, $place, "$key across the sections");
}
$h->type('d');
same('desktop', $place, 'd chooses the first item after the chosen starting with it');
$h->type('o');
same('documents', $place, 'do');
$h->tab();
same($other->id, $h->rt->focus, 'Tab leaves the sidebar');
$h->tab(true);
$h->clickText('Locations');
check(!$locations && $h->byText('Macintosh HD') === null, 'a click on the title hides the section');
$h->end();
same('documents', $place, 'End skips the hidden section');
