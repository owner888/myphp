<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Haptics;

/**
 * The widgets of a phone (not mygo's, which has no mobile platform;
 * docs/upstream-deviations.md): a tab bar across the bottom, a navigation
 * bar across the top with the page's title and the way back, a sheet
 * rising from the bottom, an action sheet of choices, and the safe area
 * they extend into and content keeps out of. Their sizes are UIKit's
 * (UITabBar, UINavigationBar, UISheetPresentationController,
 * UIAlertController's action sheet), the one design on every platform,
 * in the theme's colors.
 */
trait ContextMobile
{
    /** What actionSheet returns in the frame its cancel button, a tap outside or Escape closes it, choosing no action. */
    public const SHEET_DISMISSED = -2;

    /** Where tabView put its tabs: across the bottom (a phone), across the top (a tablet upright), as a sidebar (a tablet on its side, the desktop). */
    public const TABS_BOTTOM = 'bottom', TABS_TOP = 'top', TABS_SIDEBAR = 'sidebar';

    /** The row the last swipeActions() built around its content, which a stack along a row makes as wide as it (the declarative views'). @internal */
    public ?Node $swipedRow = null;

    /** How many sheets the frame built so far, open or closed, which keys each (sheet()). */
    private int $sheets = 0;

    /**
     * The tabs of an app, placed as Apple places them (SwiftUI's TabView
     * with the sidebarAdaptable style): a tab bar across the bottom on a
     * phone — a touch screen of compact width or height, under 600 by 500
     * points — floating over the page, whose first scroll takes its height
     * as room after its content ($bottomInset) and so goes on under it; the tabs across the top, as iPadOS's, on a tablet held
     * upright, and a sidebar on a tablet on its side and on the desktop.
     * $items are [icon, label] pairs, an icon an SVG or a function of the
     * color drawing one (an SF Symbol's); $content builds the page of tab
     * $index, told the placement too, and builds its own navigationBar and
     * safeArea inside (tabView takes the safe area's top itself when the
     * tabs are across the top). Fills what holds it; changed() reports a
     * tab chosen. tabsPlacement() tells the placement alone. With
     * $minimize 1 the phone's bar shrinks to the chosen tab as the page
     * scrolls down (-1: up), as iOS 26's tabBarMinimizeBehavior; a tap
     * on it, the page scrolling the other way or back at its top, brings
     * it back.
     * @param list<array{0: SVG|Closure(int): Image|null, 1: string}> $items @param Closure(int, string): void $content
     */
    public function tabView(array $items, int &$index, Closure $content, int $minimize = 0, ?object $backdrop = null): Node
    {
        $t = $this->theme;
        $index = max(0, min($index, count($items) - 1));
        $where = $this->tabsPlacement();
        if ($where === self::TABS_BOTTOM) { // the bar floats over the page, as iOS 26's: the page's scroll goes on under it
            $bottom = $this->safeArea[2];
            $inset = $this->tabBarInset();
            $n = $this->column(0, 0, 1)->fill();
              $small = $this->runtime?->local($n->id, fn() => (object)['y' => null, 'small' => false, 'tab' => $index, 'hidden' => false], \stdClass::class, 'tab') ?? (object)['y' => null, 'small' => false, 'tab' => $index, 'hidden' => false];
              $wasHidden = $small->hidden ?? false;
              $this->safeArea[2] = $wasHidden ? $bottom : 0.0; // the tabBar takes the bottom safe area, unless the page hid it
              $was = $this->bottomInset;
              $this->bottomInset = $wasHidden ? 0.0 : $inset; // the page's first scroll takes it as room after its content
              $this->insetScroll = null;
              $page = $this->column(0, 0, 1)->alignSelf(Node::STRETCH);
                $content($index, $where);
              $this->end();
              $hiddenNow = (bool)($backdrop->tabBarHidden ?? false);
              if ($hiddenNow !== $wasHidden) {
                  $small->hidden = $hiddenNow;
                  $this->runtime?->changed();
              }
              if ($small->tab !== $index) { [$small->y, $small->small, $small->tab] = [null, false, $index]; } // another tab's page: its own scroll
              if ($minimize !== 0 && $this->insetScroll !== null) { // the page's scroll: down (or up) by more than a few points shrinks the bar, the other way or its top brings it back
                  $y = $this->insetScroll->extent()[0];
                  if ($small->y !== null && abs($y - $small->y) > 4) { $small->small = ($y - $small->y) * $minimize > 0 && $y > 20; }
                  if ($y <= 0) { $small->small = false; }
                  $small->y = $y;
              } else {
                  $small->small = false;
              }
              if (!$hiddenNow) {
                  if ($this->bottomInset > 0) { $page->padding(0, 0, $inset, 0); } // no scroll took it: the page above the bar
                  else { // iOS 26's scroll edge: what scrolls under the bar fades into the page's color there, not sharp through its glass
                      $tabs = \WeakReference::create($n); // not $n itself: the page it holds keeping it would be a cycle each frame
                      $page->drawOver(static function (Painter $p, array $r) use ($tabs, $inset): void {
                          $top = $r[1] + $r[3] - $inset - 16;
                          $p->displayList()->gradient($r[0], $top, $r[2], $inset + 16, $tabs->get()?->color ?? $p->theme()->background, 0.0, 0.9);
                      });
                  }
              }
              $this->bottomInset = $was;
              $this->safeArea[2] = $bottom;
              if (!$hiddenNow) {
                  $bar = $this->tabBar($items, $index, $this->animate($n, 'tabsSmall', $small->small ? 1 : 0, 0.25), $expand);
                  if ($expand) { $small->small = false; $this->runtime?->changed(); } // a tap on the shrunk bar
                  $bar->absolute()->bottom(0)->start(0)->end(0);
                  $bar->floats = true; // a tap on it is its, not the page's under it
                  if ($bar->changed) { $n->changed = true; }
              }
            $this->end();
            return $n;
        }
        if ($where === self::TABS_TOP) {
            $top = $this->safeArea[0];
            $n = $this->column(0, 0, 1)->fill();
              // iPadOS 26's: a capsule of glass centered over the page under the status bar, each tab's title in it, the chosen one on a capsule of its own
              $this->row(0, 0)->alignSelf(Node::STRETCH)->shrink(0)->size(0, 52 + $top)->padding($top + 4, 16, 4, 16)->alignItems(Node::CENTER)->justify(Node::CENTER)->focusGroup(Node::HORIZONTAL, selects: true)->role(Role::TAB_LIST);
                $pill = $this->row(0, 0)->size(0, 44)->padding(4)->radius(22)->systemMaterial('glass')->alignItems(Node::STRETCH)->shrink(1)->minWidth(0);
                  if (!($this->runtime?->systemMaterials ?? true)) { $this->box(0, 0, $t->background)->absolute()->top(0)->bottom(0)->start(0)->end(0)->radius(22)->role(Role::NONE)->bgAlpha = 0.72; }
                  foreach ($items as $i => [, $label]) {
                      $on = $i === $index;
                      $tab = $this->row(0, 0)->padding(0, 16)->radius(18)->alignItems(Node::CENTER)->focusable()->role(Role::TAB)->label($label);
                      if ($on) { $tab->background($t->text, 0.08); }
                      $tab->on = $on; $tab->checked($on);
                        $this->text($label, 15, $on ? $t->accent : $t->text)->singleLine()->fontWeight($on ? 600 : 500);
                      $this->end();
                      if ($this->clicked($tab) && !$on) { $index = $i; $n->changed = true; }
                  }
                $this->end();
              $this->end();
              $this->safeArea[0] = 0.0; // the tabs took the top: the page's navigationBar sits under them
              $this->column(0, 0, 1)->alignSelf(Node::STRETCH);
                $content($index, $where);
              $this->end();
              $this->safeArea[0] = $top;
            $this->end();
            return $n;
        }
        $n = $this->row(0, 0, 1)->fill()->alignItems(Node::STRETCH);
          $selected = (string)$index;
          $side = $this->sidebar($selected, function () use ($items): void {
              foreach ($items as $i => [$icon, $label]) { $this->sidebarItem((string)$i, $label, $icon); }
          });
          $side->padding($t->space(2.5) + $this->safeArea[0], $t->space(2.5), $t->space(2.5) + $this->safeArea[2], $t->space(2.5) + $this->safeArea[3]); // its own padding, and the safe area it fills
          if ($side->changed) { $index = (int)$selected; $n->changed = true; }
          $this->column(0, 0, 1)->alignSelf(Node::STRETCH);
            $content($index, $where);
          $this->end();
        $this->end();
        return $n;
    }

    /** Where tabView puts the tabs for this window (TABS_BOTTOM, TABS_TOP, TABS_SIDEBAR): by the screen being touched and the window's size. */
    public function tabsPlacement(): string
    {
        if (!($this->runtime?->touch ?? false)) {
            return self::TABS_SIDEBAR;
        }
        if ($this->width < 600 || $this->height < 500) {
            return self::TABS_BOTTOM;
        }
        return $this->width < $this->height ? self::TABS_TOP : self::TABS_SIDEBAR;
    }

    /**
     * A column of what $fn builds kept out of the safe area: padded by
     * the system's bars and the cutout on each side, and at the bottom
     * by the keyboard while it is up, so that what it covers moves into
     * view (as a scroll inside it does). Fills the window: build it at
     * the root, with the bars inside it.
     */
    public function safeArea(Closure $fn): Node
    {
        [$top, $right, $bottom, $left] = $this->safeArea;
        $n = $this->column(0, 0, 1)->alignSelf(Node::STRETCH)->padding($top, $right, max($bottom, $this->keyboard), $left);
          $fn();
        $this->end();
        return $n;
    }

    /**
     * A tab bar across the bottom of the window, as UITabBar on iOS 26: a
     * capsule of glass floating 16 points in from the sides and 8 over the
     * safe area's bottom (8 at least), 56 high, its items sharing its width,
     * each a 24-point icon over a 10-point label, the chosen one in the
     * accent on a capsule of its own, the others muted; a tap chooses, which
     * changed() reports. The band it floats in is clear, so the page shows
     * around it. Build it last in a column that fills the window, the
     * page's content growing above it (a Router's view); tabBarInset() is
     * the height the band takes. $small, from 0 to 1, shrinks it to the
     * chosen tab alone, a capsule at its start (iOS 26's minimized bar);
     * a tap on that sets $expand. An item marked prominent (its third,
     * true) is apart at the bar's end, a circle of glass 56 wide holding its
     * icon, as iOS 27's TabRole.prominent (iOS 26's search tab); minimized,
     * it stays. Not mygo's.
     * @param list<array{0: SVG|Closure(int): Image|null, 1: string, 2?: bool}> $items an icon (an SVG, or a function of the color drawing one), a label, and whether it is apart (prominent)
     */
    public function tabBar(array $items, int &$index, float $small = 0.0, ?bool &$expand = null): Node
    {
        $expand = false;
        $t = $this->theme;
        $index = max(0, min($index, count($items) - 1));
        $bottom = max($this->safeArea[2], 8.0);
        $search = null; // the search tab's index, apart
        foreach ($items as $i => $it) { if ($it[2] ?? false) { $search = $i; } }
        $apart = $search === null ? 0.0 : 56.0 + 8.0; // its circle and the gap before it
        $n = $this->row(0, 0)->alignSelf(Node::STRETCH)->shrink(0)->padding(8, 16 + $apart, $bottom, 16)->justify($small > 0 ? Node::START : Node::CENTER)->alignItems(Node::STRETCH)
            ->focusGroup(Node::HORIZONTAL, selects: true)->role(Role::TAB_LIST);
          $full = max($this->width - 32 - $apart, 64.0);
          $pill = $this->row(4, 0, 1)->size(0, 56)->radius(28)->alignItems(Node::STRETCH)->systemMaterial('glass');
          if ($small > 0) { $pill->maxWidth($full + (64 - $full) * $small); } // narrowing to the chosen tab's 64
            if (!($this->runtime?->systemMaterials ?? true)) { // myphp's own glass (Android): frosted as iOS 26's bar is, what passes under it a haze, not bent text
                $this->box(0, 0, $t->background)->absolute()->top(0)->bottom(0)->start(0)->end(0)->radius(28)->role(Role::NONE)->bgAlpha = 0.72;
            }
            foreach ($items as $i => [$svg, $label]) {
                if ($i === $search) { continue; } // apart, below
                $on = $i === $index;
                if ($small >= 0.5 && !$on) { continue; } // shrunk: the chosen tab alone
                $color = $on ? $t->accent : $t->textMuted;
                $tab = $this->column(0, 2, 1)->basis(0)->radius(24)->alignItems(Node::CENTER)->justify(Node::CENTER)->focusable()->role(Role::TAB)->label($label);
                if ($on) { $tab->background($t->text, 0.08); } // the chosen tab's capsule, a shade of the text over the glass
                $tab->on = $on; $tab->checked($on);
                  if ($svg instanceof SVG) { $this->icon($svg, 24, $color); }
                  elseif ($svg instanceof Closure) { $this->image($svg($color), 24, 24)->role(Role::NONE); } // an SF Symbol's, drawn in the tab's color
                  if ($small < 0.5) { $this->text($label, 10, $color)->singleLine()->fontWeight($on ? 600 : 500); }
                $this->end();
                if ($small >= 0.5 && $this->clicked($tab)) { $expand = true; } // the shrunk bar, tapped: whole again
                elseif ($this->clicked($tab) && !$on) {
                    $index = $i;
                    $n->changed = true;
                }
            }
          $this->end();
          if ($search !== null) { // the search tab: a circle of glass at the bar's end
              [$svg, $label] = $items[$search];
              $on = $search === $index;
              $color = $on ? $t->accent : $t->text;
              $circle = $this->column(0, 0)->absolute()->end(16)->bottom($bottom)->size(56, 56)->radius(28)->systemMaterial('glass')->center()->focusable()->role(Role::TAB)->label($label);
              $circle->on = $on; $circle->checked($on);
                if (!($this->runtime?->systemMaterials ?? true)) { $this->box(0, 0, $t->background)->absolute()->top(0)->bottom(0)->start(0)->end(0)->radius(28)->role(Role::NONE)->bgAlpha = 0.72; }
                if ($on) { $this->box(0, 0, $t->text)->absolute()->top(4)->bottom(4)->start(4)->end(4)->radius(24)->role(Role::NONE)->bgAlpha = 0.08; } // chosen: a capsule of its own, as the bar's tabs
                if ($svg instanceof SVG) { $this->icon($svg, 24, $color); }
                elseif ($svg instanceof Closure) { $this->image($svg($color), 24, 24)->role(Role::NONE); }
              $this->end();
              if ($this->clicked($circle) && !$on) {
                  $index = $search;
                  $n->changed = true;
              }
          }
        $this->end();
        return $n;
    }

    /** The height the floating tabBar's band takes at the bottom of the window: the capsule, its margins and the safe area's bottom (8 at least). */
    public function tabBarInset(): float
    {
        return 8.0 + 56.0 + max($this->safeArea[2], 8.0);
    }

    /**
     * A navigation bar across the top of the window, as UINavigationBar on
     * iOS 26: $title centered in 17 points semibold, 44 points high under the
     * safe area's top, which it extends into, clear over the page. With a
     * $router that can go back, a chevron in a 44-point circle of glass
     * leads, in the accent, and a tap on it goes back (iOS 26 shows the
     * chevron alone); $trailing builds what ends the bar (buttons, text in
     * the accent), in a capsule of glass 44 high. With $largeTitle, what
     * scrolls a page that begins with largeTitle(), the bar's title
     * shows only once that one scrolled under it, as iOS's large titles do.
     * Where the screen is not touched (a Mac, a PC), the bar is a window's
     * toolbar, as macOS 26's: 52 high, the title in 15 points, the back
     * button and the trailing capsule 32 high. Build it first in a column
     * that fills the window. Not mygo's.
     */
    public function navigationBar(string $title, ?Router $router = null, ?Closure $trailing = null, ?Scrollable $largeTitle = null): Node
    {
        $t = $this->theme;
        $top = $this->safeArea[0];
        $this->safeArea[0] = 0.0; // the bar took the top: a safeArea() built below it starts at the bar's bottom
        $touch = $this->runtime?->touch ?? false;
        [$high, $button, $size, $side] = $touch ? [44.0, 44.0, 17.0, 16.0] : [52.0, 32.0, 15.0, 12.0];
        $n = $this->row(0, 0)->alignSelf(Node::STRETCH)->shrink(0)->size(0, $high + $top)->padding($top, $side, 0, $side)->alignItems(Node::CENTER)->role(Role::TOOLBAR);
          // the leading third, the title and the trailing third share the width, so the title centers in the bar
          $lead = $this->row(0, 0, 1)->basis(0)->alignItems(Node::CENTER)->justify(Node::START);
            if ($router !== null && $router->canGoBack()) {
                $back = $this->column(0, 0)->size($button, $button)->radius($button / 2)->systemMaterial('glass')->center()->focusable()->role(Role::BUTTON)->label($this->texts()->get('back'));
                  $a = $this->box(12, 20, 0)->shrink(0); $a->color = null;
                  $a->draw(static fn(Painter $p, array $r) => self::disclosure($p->displayList(), [$r[0] - 4, $r[1], 20, 20], 180, $p->theme()->accent));
                $this->end();
                if ($this->clicked($back)) { $router->back(); }
            }
          $this->end();
          $inline = $this->text($title, $size, $t->text)->fontWeight(600)->singleLine()->shrink(1);
          if ($largeTitle !== null) { // the large title in the page's scroll: the bar's title fades in over the last 10 points of its 52 going under the bar
              $y = $largeTitle instanceof ListState ? ($largeTitle->row > 0 ? INF : $largeTitle->offset) : $largeTitle->extent()[0]; // a list's title its first row: past it, gone under the bar (its estimate would say otherwise)
              $inline->opacity(max(0.0, min(1.0, ($y - 42) / 10)));
          }
          $this->row(0, 0, 1)->basis(0)->alignItems(Node::CENTER)->justify(Node::END);
            if ($trailing !== null) {
                $this->row(0, $side)->minHeight($button)->padding(0, $side)->radius($button / 2)->systemMaterial('glass')->alignItems(Node::CENTER)->shrink(0); // as wide as its items, each on one line ("13 frames" did not wrap in UIKit's bar either)
                  $trailing();
                $this->end();
            }
          $this->end();
        $this->end();
        return $n;
    }

    /**
     * A sheet rising from the bottom of the window while $open, over the
     * dimmed window, as a UISheetPresentationController's: the panel is as
     * high as what $fn builds, at most the window less the safe area's top
     * and 10 points, its top corners rounded 10, a grabber over the
     * content, which is padded 16 and keeps out of the safe area's bottom.
     * What is behind it is inert. A tap outside or Escape sets $open
     * false, which dismissed() reports. With $detents, heights in points
     * (UISheetPresentationController's detents, SwiftUI's
     * presentationDetents), the panel is as high as the largest, shows as
     * high as the smallest at first, and a drag moves it between them,
     * coming to rest at the nearest where the throw carries it; dragged
     * down past the smallest it goes, as a tap outside does. On a tablet
     * (tabsPlacement() not TABS_BOTTOM) it is iPadOS's page sheet instead:
     * centered, 700 wide at most and as high as the window less 40 under
     * its safe area, rounded all round, rising from the bottom; detents are
     * a phone's, as UIKit's in a regular width. Not mygo's.
     * @param list<float> $detents
     */
    public function sheet(bool &$open, Closure $fn, array $detents = [], bool $dismissDisabled = false): ?Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $back = $panel = null;
        $gone = false;
        $shown = $open;
        $key = 'sheet' . $this->sheets++; // each sheet of the frame its own, open or closed: two (one going, one coming) do not share a state
        $tablet = ($rt?->touch ?? false) && $this->tabsPlacement() !== self::TABS_BOTTOM;
        if ($tablet) { $detents = []; } // a page sheet has no detents
        $this->overlay(function () use (&$back, &$panel, &$gone, $t, $rt, $fn, $detents, $shown, $key, $tablet, $dismissDisabled): void {
            $this->key($key);
            $rise = $rt?->motions[$this->upcomingId()]['rise'] ?? null;
            if (!$shown && ($rise === null || ($rise->value <= 0.001 && !$rise->moving))) { // closed, and gone below the window
                $this->nextKey = null;
                return;
            }
            $back = $this->column(0, 0, 0, 0x000000)->at(0, 0)->size($this->width, $this->height)->justify(Node::END);
            if ($shown) { $back->block(); } else { $back->passThrough(); } // going down, the window under it takes the pointer again
              $large = $this->height - $this->windowSafeArea[0] - 10;
              $panel = $this->column(0, 0)->alignSelf(Node::STRETCH)->maxHeight($large)->role(Role::DIALOG);
              if ($tablet) { // iPadOS's page sheet: centered, as high as the window less 40 under its top
                  $page = $this->height - $this->windowSafeArea[0] - 40 - max($this->windowSafeArea[2], 20.0);
                  $back->justify(Node::CENTER)->alignItems(Node::CENTER);
                  $panel->alignSelf(Node::CENTER)->size(min(700.0, $this->width - 40), 0)->minHeight($page)->maxHeight($page)->margin($this->windowSafeArea[0] + 20, 0, 0, 0);
              }
              $down = 0.0; // how far a drag moved it down from the largest
              if ($detents !== []) { // as high as the largest, moved down to show the others: the offset of each its height short of the largest
                  $heights = array_values(array_unique(array_map(fn(float $h) => min(max($h, 0.0), $large), $detents)));
                  sort($heights);
                  $top = $heights[count($heights) - 1];
                  $panel->minHeight($top)->maxHeight($top);
                  $kept = $rt?->local($back->id, fn() => (object)['offset' => [0.0, $top - $heights[0]]], \stdClass::class, 'offset') ?? (object)['offset' => [0.0, $top - $heights[0]]];
                  $stops = array_map(fn(float $h) => [0.0, $top - $h], $heights);
                  if (!$dismissDisabled) { $stops[] = [0.0, $top]; } // below the window: gone
                  $this->draggable($panel, $kept->offset, new DraggableOptions(Node::VERTICAL, bounds: [0, 0, 0, $top], snap: Motion::nearest(...$stops)));
                  $down = $this->dragOffset($panel)[1];
                  $panel->minHeight(max($top - $down, 0.0))->maxHeight(max($top - $down, 0.0)); // as high as it shows, its content laid out in it (a scroll reaches its end at every height), the drag's move taken back below
                  $gone = $kept->offset[1] >= $top - 0.5 && !$this->dragging($panel) && $down >= $top - 2; // let go toward below the window, and there: gone
              }
              // iOS 26's: short of the whole window, a sheet floats, 8 in from the window's sides and bottom, rounded all round; at the whole, it meets them
              $float = ($rt?->touch ?? false) ? ($detents === [] ? 1.0 : min(1.0, max(0.0, $down / 30))) : 0.0;
              if (!$tablet) { $panel->margin(0, 8 * $float, 8 * $float, 8 * $float); }
              $corner = ($rt?->touch ?? false) ? 32.0 : 10.0;
              $h = $rt?->rectOf($panel->id)[3] ?? $this->height; // not yet laid out: the window's height, below it all the same
              if ($rt !== null && !isset($rt->motions[$back->id]['rise'])) { $this->jump($back, 'rise', 0.0); } // shown now: from below the window, not at its place
              $p = $this->animate($back, 'rise', $shown ? 1 : 0, $shown ? 0.35 : 0.3); // up as it shows, back down as it closes
              $panel->translate(0, (1 - $p) * ($tablet ? $this->height : $h) - $down); // rises from below the window; the drag's move is its height's
              $back->alpha = 0.4 * $p; // the window dims as it rises
              $panel->draw(static fn(Painter $pt, array $r) => $pt->displayList()->box($r[0], $r[1], $r[2], $r[3], [$corner, $corner, $corner * $float, $corner * $float], $pt->theme()->dark ? $pt->theme()->surface : $pt->theme()->background, 1.0)); // in the dark UIKit's elevated background, a gray over the black
                if (!$tablet) { // a page sheet has none
                    $this->column(0, 0)->alignSelf(Node::STRETCH)->alignItems(Node::CENTER)->padding(5, 0, 0, 0)->shrink(0);
                      $g = $this->box(36, 5, $t->textMuted)->radius(2.5); $g->alpha = 0.5; // the grabber
                    $this->end();
                }
                $this->column(16, $t->space(2), 1)->alignSelf(Node::STRETCH)->padding($tablet ? 20 : 16, $tablet ? 20 : 16, $tablet ? 20 : 16 + max(0.0, $this->windowSafeArea[2] - 8 * $float), $tablet ? 20 : 16);
                  $fn();
                $this->end();
              $this->end();
            $this->end();
        });
        if ($back === null || !$open) { // closed: nothing, or what goes down
            return $back;
        }
        if (!$dismissDisabled && ($gone || $this->overlayShortcut($back, Key::ESCAPE) || ($this->clicked($back) && !$this->hovered($panel)))) { // dragged away, Escape, or a tap outside
            $open = false;
            $back->dismissed = true;
        }
        return $back;
    }

    /**
     * A phone's context menu (Runtime::$touchMenu, what a long press on a
     * node with a contextMenu opens), as UIKit's: the window blurred and
     * dimmed, that node lifted over it (painted again, Painter::copyOf, a
     * little larger, rounded, on the theme's surface, with a shadow), and
     * a rounded list of its items 250 wide
     * below the node, or above where there is no room; the finger held on
     * slides over the items, the one under it pressed, and lifted on one
     * chooses it (Runtime::$touchMenuFinger); an item 44 high, a
     * destructive one in the danger color, a disabled one muted, a checked
     * one marked; a group's line a wider gap. A tap on an item chooses it,
     * a tap beside closes it; it fades in as it opens and out as it closes
     * (closing: the window under it takes the taps again). Build it last,
     * over the view (Window does).
     * Not mygo's.
     */
    public function touchMenu(): void
    {
        $rt = $this->runtime;
        $m = $rt?->touchMenu;
        if ($m === null) {
            return;
        }
        $t = $this->theme;
        $items = array_values(array_filter($m['items'], static fn(\MyPHP\MenuItem $it) => !$it->hidden));
        $high = 0.0;
        $wide = 0.0; // its longest label
        foreach ($items as $it) {
            $high += match ($it->type) { \MyPHP\MenuItem::SEPARATOR => 17.0, \MyPHP\MenuItem::HEADER => 30.0, default => 44.0 };
            if ($it->type !== \MyPHP\MenuItem::SEPARATOR) { $wide = max($wide, $this->measureText(0, new Span($it->label, $t->fontSize))[0]); }
        }
        $high += 16; // 8 over its first item and under its last
        $icons = array_filter($items, static fn(\MyPHP\MenuItem $it) => $it->systemImage !== '') !== []; // any item with a symbol: all the labels after a column of them
        if (!array_key_exists('target', $m)) { $m['target'] = $rt->touchMenu['target'] = $rt->contextMenuTarget($m['x'], $m['y']); $rt->liftNode = null; } // the node it lifts, the next frames' record keeping it (Runtime::$liftNode)
        $lifted = $rt->liftNode;
        if ($lifted !== null) { // where it shows in the window, scrolled and moved as it is
            $b = $lifted->world->bounds($lifted->x, $lifted->y, $lifted->w, $lifted->h);
            $m['rect'] = $rt->touchMenu['rect'] = [$b[0], $b[1], $b[2], $b[3]];
        } elseif (!array_key_exists('rect', $m)) {
            $m['rect'] = $rt->touchMenu['rect'] = $m['target'] !== 0 ? $rt->rectOf($m['target']) : null; // not yet recorded: its box as laid out
        }
        $at = $m['rect'] ?? [$m['x'], $m['y'] - 12, 0.0, 24.0]; // none: the finger's point
        $w = min(max($wide + 48 + 24 + ($icons ? 34 : 0), 200.0), 280.0, $this->width - 16); // as wide as its longest label and 24 each side, 200 to 280
        $grown = $m['rect'] !== null ? min(1.02, ($this->width - 16) / max($m['rect'][2], 1.0)) : 1.0; // how much the lifted node grows (or narrows, as wide as the window), lifted
        $liftedAt = $m['rect'] === null ? null : [$m['rect'][0] + $m['rect'][2] * (1 - $grown) / 2, $m['rect'][2] * $grown]; // its start and width, lifted
        if ($liftedAt === null) { $edge = $m['x'] - 24; }
        elseif ($liftedAt[1] >= $this->width * 0.8) { $edge = $this->direction() === Direction::RightToLeft ? $liftedAt[0] + $liftedAt[1] - $w : $liftedAt[0]; } // as wide as the window: at its leading edge
        else { $edge = $liftedAt[0] + $liftedAt[1] / 2 < $this->width / 2 ? $liftedAt[0] : $liftedAt[0] + $liftedAt[1] - $w; } // lined up with the side of the window it is on, as UIKit's (a message's bubble, left or right)
        $x = max(8.0, min($edge, $this->width - 8 - $w));
        $y = $at[1] + $at[3] + 8; // below the node
        if ($y + $high > $this->height - $this->windowSafeArea[2] - 16) { $y = $at[1] - 8 - $high; } // no room below: above it
        if ($y < $this->windowSafeArea[0] + 16) { $y = max($this->windowSafeArea[0] + 16, $this->height - $this->windowSafeArea[2] - 16 - $high); } // nor above (a tall node): over it, at the bottom
        $finger = $rt->touchMenuFinger;
        $lift = null; // where the finger held on lifted, having moved: the item there is chosen
        if ($finger !== null && $finger[2]) {
            $rt->touchMenuFinger = null;
            if ($m['moved'] ?? false) { $lift = [$finger[0], $finger[1]]; }
        } elseif ($finger !== null && !($m['moved'] ?? false) && hypot($finger[0] - $m['x'], $finger[1] - $m['y']) > 10) {
            $m['moved'] = $rt->touchMenu['moved'] = true;
        }
        $slide = ($finger !== null && !$finger[2] && ($m['moved'] ?? false)) ? [$finger[0], $finger[1]] : null; // the finger sliding over the items
        $inside = static fn(?array $r, ?array $pt) => $r !== null && $pt !== null && $pt[0] >= $r[0] && $pt[0] < $r[0] + $r[2] && $pt[1] >= $r[1] && $pt[1] < $r[1] + $r[3];
        $chosen = null;
        $back = $panel = null;
        $shown = !($m['closing'] ?? false);
        $gone = false;
        $under = null; // the item the finger slides over
        $this->overlay(function () use (&$chosen, &$back, &$panel, &$gone, &$under, $shown, $grown, $icons, $rt, $items, $t, $w, $x, $y, $m, $slide, $lift, $inside): void {
            $this->key('touchMenu');
            $fade = $rt->motions[$this->upcomingId()]['fade'] ?? null;
            if (!$shown && ($fade === null || ($fade->value <= 0.001 && !$fade->moving))) { // closed, and faded out
                $this->nextKey = null;
                $gone = true;
                return;
            }
            $back = $this->column(0, 0)->at(0, 0)->size($this->width, $this->height);
            if ($shown) { $back->block(); } else { $back->passThrough(); }
            if (!isset($rt->motions[$back->id]['fade'])) { $this->jump($back, 'fade', 0.0); } // opened now: from nothing
            $p = $this->animate($back, 'fade', $shown ? 1 : 0, $shown ? 0.2 : 0.25);
              // the window blurred and dimmed, the node lifted over it: painted again, a little larger, rounded on the surface, as UIKit's preview
              $this->column(0, 0)->absolute()->top(0)->start(0)->size($this->width, $this->height)->role(Role::NONE)->material(new \MyPHP\Plugins\Glass\Blur(10 * $p));
                $this->box(0, 0, 0x000000)->absolute()->top(0)->bottom(0)->start(0)->end(0)->role(Role::NONE)->bgAlpha = 0.12 * $p;
              $this->end();
              if (($r = $m['rect']) !== null && $m['target'] !== 0) {
                  $s = 1 + ($grown - 1) * $p; // a little larger as it lifts, as UIKit's; a cell as wide as the window a little narrower, 8 in from its sides
                  $cx = $r[0] + $r[2] / 2; $cy = $r[1] + $r[3] / 2;
                  $big = [$cx - $r[2] * $s / 2, $cy - $r[3] * $s / 2, $r[2] * $s, $r[3] * $s];
                  $map = Affine::translation($cx, $cy)->mul(Affine::scaling($s, $s))->mul(Affine::translation(-$cx, -$cy));
                  $rtRef = \WeakReference::create($rt); $bg = $rt->liftColor ?? $t->background; $up = $p;
                  $this->box(0, 0, 0)->absolute()->top(0)->start(0)->size($this->width, $this->height)->role(Role::NONE)->draw(static function (Painter $pa) use ($rtRef, $big, $map, $bg, $up): void { // holds no node: the runtime's, found as it paints
                      $node = $rtRef->get()?->liftNode;
                      if ($node === null) { return; }
                      $pa->shadow($big, 13, 0, 8, 30, 0, 0x000000, 0.2 * $up);
                      $pa->fill($big, $bg, 13 * $up);
                      $pa->clip($big, static fn() => $pa->copyOf($node, $map), 13 * $up);
                  })->color = null;
              }
              $panel = $this->column(0, 0)->absolute()->top($y)->left($x)->size($w, 0)->padding(8, 0)->radius(32)->systemMaterial('glass')->clip()->role(Role::MENU)->opacity($p); // iOS 26's: of glass, rounded 32, its items apart by no line, a group's by a hairline
                $tint = $this->box(0, 0, $t->dark ? 0x1c1c1e : 0xffffff)->absolute()->top(0)->bottom(0)->start(0)->end(0)->radius(32)->role(Role::NONE)->borderWidth(1)->borderColor($t->dark ? 0x000000 : 0xd1d1d6); // tinted, as the system's menu, its items read over any page; a darker edge, floating, as iOS 27's
                $tint->bgAlpha = 0.6;
                foreach ($items as $it) {
                    if ($it->type === \MyPHP\MenuItem::SEPARATOR) { // a group's end: a hairline 24 in, 8 apart from the items, as iOS 26's
                        $this->box(0, 1 / max($this->scale, 1.0), $t->border)->alignSelf(Node::STRETCH)->margin(8, 24)->shrink(0);
                        continue;
                    }
                    if ($it->type === \MyPHP\MenuItem::HEADER) {
                        $this->text($it->label, 13.0 * $t->fontSize / 17.0, $t->textMuted)->padding(10, 24, 4, 24)->singleLine();
                        continue;
                    }
                    $off = $it->disabled || $it->submenu !== [];
                    $row = $this->row(0, 14)->alignSelf(Node::STRETCH)->minHeight(44)->padding(0, 24)->alignItems(Node::CENTER)->focusable()->role(Role::MENU_ITEM)->label($it->label);
                    $rect = $rt->rectOf($row->id); // as last laid out: where the finger slides and lifts
                    if (!$off && $inside($rect, $slide)) { $under = (int)$it->id; }
                    if (!$off && (($this->hovered($row) && ($this->runtime?->input->mouseDown ?? false)) || $inside($rect, $slide))) { $row->color = $t->dark ? 0x3a3a3c : 0xe5e5ea; }
                      $fg = $off ? $t->textMuted : ($it->role === 'destructive' ? $t->danger : $t->text);
                      if ($icons) { // a column of the items' symbols at their start, 20 wide, as UIMenu's images
                          if ($it->systemImage !== '') { $this->image(Image::symbol($it->systemImage, 20, $fg), 20, 20)->shrink(0)->role(Role::NONE); }
                          else { $this->box(20, 20, 0)->shrink(0)->role(Role::NONE)->color = null; }
                      }
                      $this->text($it->label, $t->fontSize, $fg)->singleLine()->grow(1)->shrink(1)->minWidth(0);
                      if ($it->checked) { $this->text('✓', $t->fontSize, $t->accent)->shrink(0); }
                    $this->end();
                    if ($shown && !$off && ($this->clicked($row) || $inside($rect, $lift))) { $chosen = (int)$it->id; }
                }
              $this->end();
            $this->end();
        });
        if ($rt->touchMenu !== null && $under !== ($m['under'] ?? null)) { // slid onto another item: felt, as UIKit's
            if ($under !== null) { $rt->haptic('selection'); }
            $rt->touchMenu['under'] = $under;
        }
        if ($gone) {
            $rt->touchMenu = null;
            $rt->touchMenuFinger = null;
            $rt->liftNode = null;
        } elseif ($chosen !== null) { // chosen: it fades out, the choice reaching the item in the next frame (chosen())
            $rt->touchMenu['closing'] = true;
            ($m['chosen'])($chosen);
            $rt->changed();
        } elseif ($shown && ($this->overlayShortcut($back, Key::ESCAPE) || ($this->clicked($back) && !$this->hovered($panel)))) { // a tap beside, Escape, Back: it fades out, nothing chosen
            $rt->touchMenu['closing'] = true;
            $rt->changed();
        }
    }

    /**
     * A sheet of choices rising from the bottom while $open, as
     * UIAlertController's action sheet: $title muted over the $actions,
     * each a row 57 points high in the accent (one starting with "!" in
     * the danger color, destructive), and a cancel button apart below them,
     * bold. Returns the index of the action tapped in the frame it is,
     * which closes the sheet, SHEET_DISMISSED in the frame the cancel
     * button, a tap outside or Escape closes it, and -1 otherwise.
     */
    public function actionSheet(bool &$open, string $title, string ...$actions): int
    {
        $this->key('actionSheet'); // keyed: it comes and goes
        if (!$open) {
            $this->nextKey = null;
            return -1;
        }
        $t = $this->theme;
        $rt = $this->runtime;
        $chosen = -1;
        $back = $panel = $cancel = null;
        $this->overlay(function () use (&$back, &$panel, &$cancel, &$chosen, $t, $rt, $title, $actions): void {
            $back = $this->column(0, 0, 0, 0x000000)->at(0, 0)->size($this->width, $this->height)->justify(Node::END)->block();
            $back->alpha = 0.4;
              $panel = $this->column(0, 8)->alignSelf(Node::STRETCH)->padding(0, 8, 8 + $this->windowSafeArea[2], 8)->role(Role::MENU)->label($title);
              $h = $rt?->rectOf($panel->id)[3] ?? 0.0;
              $p = $this->animate($panel, 'rise', 1, 0.35);
              $panel->translate(0, (1 - $p) * $h);
                $group = $this->column(0, 0, 0, $t->background)->alignSelf(Node::STRETCH)->radius(13)->clip();
                  if ($title !== '') {
                      $this->column(0, 0)->alignSelf(Node::STRETCH)->padding(20, 16)->alignItems(Node::CENTER)->borderWidth(0, 0, 1, 0)->borderColor($t->border);
                        $this->text($title, $t->fontSize, $t->textMuted)->preferredFont(Font::FOOTNOTE)->fontWeight(500);
                      $this->end();
                  }
                  foreach ($actions as $i => $label) {
                      $destructive = $label !== '' && $label[0] === '!';
                      if ($destructive) { $label = substr($label, 1); }
                      $row = $this->column(0, 0)->alignSelf(Node::STRETCH)->size(0, 57)->center()->focusable()->role(Role::MENU_ITEM)->label($label);
                      if ($i < count($actions) - 1) { $row->borderWidth(0, 0, 1, 0)->borderColor($t->border); }
                      if ($this->hovered($row) && ($rt?->input->mouseDown ?? false)) { $row->color = $t->surfacePressed; }
                        $this->text($label, $t->fontSize, $destructive ? $t->danger : $t->accent)->preferredFont(Font::TITLE3)->singleLine();
                      $this->end();
                      if ($this->clicked($row)) { $chosen = $i; }
                  }
                $this->end();
                $cancel = $this->column(0, 0, 0, $t->background)->alignSelf(Node::STRETCH)->radius(13)->size(0, 57)->center()->focusable()->role(Role::BUTTON);
                if ($this->hovered($cancel) && ($rt?->input->mouseDown ?? false)) { $cancel->color = $t->surfacePressed; }
                  $this->text($this->texts()->get('cancel'), $t->fontSize, $t->accent)->preferredFont(Font::TITLE3)->fontWeight(600)->singleLine();
                $this->end();
              $this->end();
            $this->end();
        });
        if ($chosen < 0 && ($this->clicked($cancel) || $this->overlayShortcut($back, Key::ESCAPE) || ($this->clicked($back) && !$this->hovered($panel)))) {
            $chosen = self::SHEET_DISMISSED;
        }
        if ($chosen !== -1) { $open = false; }
        return $chosen;
    }

    /**
     * A scroll whose content a finger pulls down past its top to refresh,
     * as UIRefreshControl and SwiftUI's .refreshable: the content follows
     * the finger rubber-banded, a spinner showing in the room it leaves;
     * let go 60 points down or more, $refreshing turns true, which
     * changed() reports, and the content rests 60 down under the spinner
     * until the app sets $refreshing false again (its work done), when it
     * goes back up. $state keeps the scroll's place, as scroll()'s; $fn
     * builds the content. On a touch screen; the desktop's wheel scrolls it
     * as any scroll. Not mygo's.
     * @param Closure(): void $fn
     */
    public function refreshable(ScrollState $state, bool &$refreshing, Closure $fn): Node
    {
        $t = $this->theme;
        $state->overscrolls = true;
        $n = $this->column(0, 0, 1)->alignSelf(Node::STRETCH)->scroll($state);
          $holder = $this->column(0, 0)->alignSelf(Node::STRETCH);
            $fn();
          $this->end();
        $this->end();
        $rest = 60.0;
        $vp = $state->viewport > 0 ? $state->viewport : $this->height;
        if ($state->pull > 0 && !$state->released) { // the finger holds it: rubber-banded, as UIScrollView past its top
            $shown = $this->jump($holder, 'pull', Motion::rubberband(-$state->pull, 0, 0, $vp) * -1);
        } else {
            if ($state->released) {
                $shownNow = Motion::rubberband(-$state->pull, 0, 0, $vp) * -1;
                if ($shownNow >= $rest && !$refreshing) { $refreshing = true; $n->changed = true; $this->runtime?->haptic('medium'); }
                $state->pull = 0.0; $state->released = false;
            }
            $shown = $this->animateTo($holder, 'pull', $refreshing ? $rest : 0.0, Animation::smooth());
        }
        if ($shown > 0.5) {
            $holder->translate(0, $shown);
            $spin = $this->spinner()->absolute()->top(max(0.0, ($shown - 20) / 2))->left(0)->right(0)->size(0, 20); // in the room the content leaves
            $spin->opacity($refreshing ? 1.0 : min(1.0, $shown / $rest));
            $spin->color = $t->textMuted;
        }
        return $n;
    }

    /**
     * A page's title in large type, as UINavigationBar's prefersLargeTitles:
     * 34 points bold at the start of the content, 52 high, padded 16; build
     * it first in the page's scroll, and give the page's navigationBar the
     * same ScrollState, whose title then shows only once this one scrolled
     * under the bar. Not mygo's.
     */
    public function largeTitle(string $title): Node
    {
        $t = $this->theme;
        $n = $this->row(0, 0)->alignSelf(Node::STRETCH)->minHeight(52)->padding(0, 16)->alignItems(Node::END)->role(Role::HEADING); // 52 high, higher as the text is larger
          $this->text($title, Font::preferred(Font::LARGE_TITLE, true, $t->textScale())[0], $t->text)->fontWeight(700)->singleLine()->shrink(1); // UIKit's large title, 34 bold, which only iOS has
        $this->end();
        return $n;
    }

    /**
     * The page of an inset grouped list, as UITableView's insetGrouped style
     * and SwiftUI's List with .insetGrouped: a scroll filling what holds it,
     * on the grouped background (the gray in the light appearance, black in
     * the dark), with the sections $fn builds (section()) one under the
     * other. Not mygo's.
     * @param Closure(): void $fn
     */
    public function insetGroupedList(Closure $fn): Node
    {
        $t = $this->theme;
        $n = $this->scroll(0, 0, 1, $t->groupBackground ?? $t->background)->alignSelf(Node::STRETCH)->role(Role::LIST);
          $fn();
        $this->end();
        return $n;
    }

    /**
     * A section of an inset grouped list, as UITableView's insetGrouped
     * style (Settings) and SwiftUI's Section in a List or Form: $header as
     * written in the body style, muted, on a phone (iOS 26's), the headline
     * style on the desktop; the rows $rows builds in a group of the theme's
     * surface, its corners rounded the theme's groupRadius (26 on a phone,
     * 10 on the Mac), inset 16 from the sides, a hairline between each two
     * rows starting 16 in, as UITableView's separators;
     * $footer below, muted, in the footnote style on a phone, the
     * subheadline style on the desktop. The header and the footer sit 16 in
     * from the group's edge; a section without a header keeps 16 above its
     * group, as at its sides. Stretches across its
     * parent; build sections one under the other in a scroll(). cell()
     * builds a row of UIKit's. Not mygo's.
     * @param Closure(): void $rows
     */
    public function section(string $header, Closure $rows, string $footer = ''): Node
    {
        $t = $this->theme;
        $n = $this->column(0, 0)->alignSelf(Node::STRETCH)->padding($header === '' ? 16 : 0, 16, 0, 16)->role(Role::GROUP); // without a header, as much room above its group as at its sides
        if ($header !== '') { $n->label($header); }
          $touch = $this->runtime?->touch ?? false;
          if ($header !== '') { // iOS 26's: as written, the body's size, muted; the Mac's: the headline style, as System Settings' groups
              $this->text($header, $t->fontSize, $touch ? $t->textMuted : $t->text)->preferredFont($touch ? Font::BODY : Font::HEADLINE)->padding(16, 16, 7, 16)->role(Role::HEADING);
          }
          $group = $this->column(0, 0, 0, $t->groupSurface ?? $t->surface)->alignSelf(Node::STRETCH)->radius($t->groupRadius)->clip();
            $rows();
          $this->end();
          $group->drawOver(static function (Painter $p, array $r, Node $group) use ($touch): void { // the separators: under each row but the last, a hairline from 16 in, to 16 short of the end on a phone (iOS 26's), to the end on the Mac
              $rows = array_values(array_filter($group->kids, static fn(Node $k): bool => !$k->absolute));
              for ($i = 0, $last = count($rows) - 1; $i < $last; $i++) {
                  $k = $rows[$i];
                  $hair = $touch ? 1 / max($p->scale(), 1.0) : 1.0; // a phone's: one pixel, as UIKit's separators on every screen
                  $p->fill([$r[0] + 16, $k->y + $k->h - $hair, $r[2] - ($touch ? 32 : 16), $hair], $p->theme()->border);
              }
          });
          if ($footer !== '') {
              $this->text($footer, $t->fontSize, $t->textMuted)->preferredFont(($this->runtime?->touch ?? false) ? Font::FOOTNOTE : Font::SUBHEADLINE)->padding(7, 16, 16, 16);
          }
        $this->end();
        return $n;
    }

    /**
     * A row swiped toward the start to show actions at its end, as
     * UITableView's trailing swipe actions and SwiftUI's .swipeActions:
     * $content builds the row, which the finger drags sideways
     * (draggable, along that axis alone, so the list still scrolls); the
     * buttons of $actions, each as wide as its label and 74 points at least,
     * show in the room it leaves, the first nearest the content, gray, or
     * red for one marked "!Delete"; let go (or flicked) past half their
     * width the row stays open, else it closes. Dragged past half the
     * row's width (48 points past the buttons at least) the first button
     * takes the whole room, with a tap felt as it crosses; let go there the
     * row slides out and then performs the first action, back in place at
     * once for a destructive one (the row goes), sliding back for another
     * (UIKit's full swipe), decided by where the
     * finger let go, not by where a flick would carry it: a flick only
     * opens or closes. A tap on an open row closes it;
     * a tap on a closed one is the row's clicked(). $action is the index of
     * the action tapped this frame, -1 for none. Returns the content's node,
     * the one dragged, which clicked() and focused() ask about; a key given
     * before the call names the row around it. Not mygo's.
     * @param Closure(): void $content
     */
    public function swipeActions(?int &$action, Closure $content, string ...$actions): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $action = -1;
        $rtl = $this->direction() === Direction::RightToLeft;
        $widths = [];
        foreach ($actions as $label) {
            $widths[] = max(74.0, $this->measureText(0, new Span(ltrim($label, '!'), 17))[0] + 32);
        }
        $total = array_sum($widths);
        $sign = $rtl ? 1.0 : -1.0; // the content moves toward the start
        $n = $this->row(0, 0)->alignSelf(Node::STRETCH)->clip();
        $this->swipedRow = $n;
        $width = max($rt?->rectOf($n->id)[2] ?? $this->width, $total + 48); // the row's, last laid out
        $full = max($total + 48, $width * 0.5); // let go past this, the first action is performed
        $kept = $rt?->local($n->id, fn() => (object)['past' => false], \stdClass::class, 'past') ?? (object)['past' => false]; // whether the finger last held it past $full
          $inner = $this->column(0, 0, 1)->alignSelf(Node::STRETCH)->alignItems(Node::START)->focusable()->role(Role::LIST_ITEM); // the press target, so the drag is its; its content at the start, as a row's
            $content();
          $this->end();
          $offset = $rt?->dragRecs[$inner->id]->own ?? [0.0, 0.0]; // where the row rests, kept with its drag record between frames
          $this->draggable($inner, $offset, new DraggableOptions(Node::HORIZONTAL,
              bounds: $rtl ? [0, 0, $width, 0] : [-$width, 0, $width, 0],
              snap: fn(array $p) => $kept->past ? [$sign * $width, 0.0] : (abs($p[0]) > $total / 2 ? [$sign * $total, 0.0] : [0.0, 0.0]))); // the full swipe by where the finger was, open or closed by where the flick carries it
          $shown = $this->dragOffset($inner);
          $open = abs($shown[0]) > 0.5;
          if ($this->dragging($inner)) {
              $past = abs($shown[0]) >= $full;
              if ($past !== $kept->past) { $rt?->haptic('medium'); } // crossing it, either way, as UIKit's full swipe
              $kept->past = $past;
          }
          $buttons = [];
          if ($open) { // the room the content leaves at its end, the buttons in it, the first nearest the content; closed, there is nothing of them to tap or read
              $room = min(abs($shown[0]), $width);
              $strip = $this->row(0, 0)->absolute()->top(0)->bottom(0)->end(0)->size($room, 0)->clip()->justify(Node::END)->alignItems(Node::STRETCH);
              foreach ($actions as $i => $label) {
                  if ($kept->past && $i > 0) { continue; } // past it, the first button alone, across the room
                  $destructive = $label !== '' && $label[0] === '!';
                  if ($destructive) { $label = substr($label, 1); }
                  $bg = $destructive ? $t->danger : $t->textMuted;
                  $b = $this->column(0, 0, 0, $bg)->size($kept->past ? max($room, $widths[$i]) : $widths[$i], 0)->shrink(0)->center()->focusable()->role(Role::BUTTON)->label($label);
                  if ($this->hovered($b) && ($rt?->input->mouseDown ?? false)) { $b->color = Theme::mix($bg, 0x000000, 0.15); }
                    $this->text($label, $t->fontSize, (Theme::luminance($bg) & 255) > 165 ? $t->text : 0xffffff)->singleLine(); // white on the gray and the red, as UIKit's; the theme's text, 17 on a phone
                  $this->end();
                  $buttons[$i] = $b;
              }
              $this->end();
          }
        $this->end();
        $close = static function () use (&$offset, $rt): void { $offset = [0.0, 0.0]; $rt?->changed(); };
        if (abs($offset[0]) == $width && !$this->dragging($inner) && abs($shown[0]) >= $width * 0.97) { // let go past $full, and slid out of the row (the spring's tail not waited for): the full swipe performs the first action
            $action = 0;
            $kept->past = false;
            if ($actions[0] !== '' && $actions[0][0] === '!') { // destructive: the row goes, so it is back in place at once, not sliding in again (the next row takes its place)
                $offset = [0.0, 0.0];
                $rt?->dragRecs[$inner->id]?->hold($offset, $rt->now);
                $rt?->changed();
            } else {
                $close();
            }
        }
        foreach ($buttons as $i => $b) {
            if ($this->clicked($b)) { $action = $i; $close(); }
        }
        if ($rt !== null) { // its actions offered by a screen reader, as UIKit's swipe actions are (the row's custom actions): one chosen there is performed as tapped
            $rt->swipeActionsOf[$inner->id] = array_map(static fn(string $l) => ltrim($l, '!'), $actions);
            if (($chosen = $rt->takeSwipeAction($inner->id)) >= 0 && $chosen < count($actions)) { $action = $chosen; $close(); }
        }
        if ($open && abs($offset[0]) == $total && $this->clicked($inner)) { // a tap on an open row closes it
            $close();
        }
        if ($rt !== null) { $rt->dragRecs[$inner->id]->own = $offset; }
        return $inner;
    }

    /**
     * A row of an inset grouped list, as UITableViewCell with the value1
     * style and SwiftUI's LabeledContent: $title in the theme's text at the
     * start, $detail muted at the end, and with $disclosure a chevron after
     * it, as a row that opens a page; 44 points high at least, as the
     * list's other rows, padded 16 at the sides. Takes the focus and reports clicked();
     * pressed, it fills with the theme's pressed surface. Build it in a
     * section(). Not mygo's.
     */
    public function cell(string $title, string $detail = '', bool $disclosure = false): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = $this->row(0, 8)->alignSelf(Node::STRETCH)->minHeight($t->rowHeight)->padding(0, 16)->alignItems(Node::CENTER)->focusable()->role(Role::BUTTON)->label($detail === '' ? $title : "$title, $detail"); // 44 high, as a grouped list's other rows; its text the theme's: a phone's 17, the desktop's as its other rows'
        if ($this->hovered($n) && ($rt?->input->mouseDown ?? false)) { $n->color = $t->surfacePressed; }
          $this->text($title, $t->fontSize, $t->text)->singleLine()->grow(1)->shrink($detail === '' ? 1 : 0); // the title whole, as UIKit's value1 cell: the detail shortens first
          if ($detail !== '') {
              $this->text($detail, $t->fontSize, $t->textMuted)->singleLine()->shrink(1)->minWidth(0);
          }
          if ($disclosure) {
              $a = $this->box(8, 20, 0)->shrink(0); $a->color = null;
              $a->draw(static fn(Painter $p, array $r) => self::disclosure($p->displayList(), [$r[0] - 6, $r[1], 20, 20], 0, Theme::mix($p->theme()->textMuted, $p->theme()->surface, 0.3)));
          }
        $this->end();
        return $n;
    }
}
