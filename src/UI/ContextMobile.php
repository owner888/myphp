<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Haptics;

/**
 * The widgets of a phone (not mygo's, which has no mobile platform;
 * docs/mygo-deviations.md): a tab bar across the bottom, a navigation
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
    public function tabView(array $items, int &$index, Closure $content, int $minimize = 0): Node
    {
        $t = $this->theme;
        $index = max(0, min($index, count($items) - 1));
        $where = $this->tabsPlacement();
        if ($where === self::TABS_BOTTOM) { // the bar floats over the page, as iOS 26's: the page's scroll goes on under it
            $bottom = $this->safeArea[2];
            $inset = $this->tabBarInset();
            $n = $this->column(0, 0, 1)->fill();
              $this->safeArea[2] = 0.0; // the tabBar takes the bottom safe area
              $was = $this->bottomInset;
              $this->bottomInset = $inset; // the page's first scroll takes it as room after its content
              $this->insetScroll = null;
              $page = $this->column(0, 0, 1)->alignSelf(Node::STRETCH);
                $content($index, $where);
              $this->end();
              $small = $this->runtime?->local($n->id, fn() => (object)['y' => null, 'small' => false, 'tab' => $index]) ?? (object)['y' => null, 'small' => false, 'tab' => $index];
              if ($small->tab !== $index) { [$small->y, $small->small, $small->tab] = [null, false, $index]; } // another tab's page: its own scroll
              if ($minimize !== 0 && $this->insetScroll !== null) { // the page's scroll: down (or up) by more than a few points shrinks the bar, the other way or its top brings it back
                  $y = $this->insetScroll->extent()[0];
                  if ($small->y !== null && abs($y - $small->y) > 4) { $small->small = ($y - $small->y) * $minimize > 0 && $y > 20; }
                  if ($y <= 0) { $small->small = false; }
                  $small->y = $y;
              } else {
                  $small->small = false;
              }
              if ($this->bottomInset > 0) { $page->padding(0, 0, $inset, 0); } // no scroll took it: the page above the bar
              else { // iOS 26's scroll edge: what scrolls under the bar fades into the page's color there, not sharp through its glass
                  $page->drawOver(static function (Painter $p, array $r) use ($n, $inset): void {
                      $top = $r[1] + $r[3] - $inset - 16;
                      $p->displayList()->gradient($r[0], $top, $r[2], $inset + 16, $n->color ?? $p->theme()->background, 0.0, 0.9);
                  });
              }
              $this->bottomInset = $was;
              $this->safeArea[2] = $bottom;
              $bar = $this->tabBar($items, $index, $this->animate($n, 'tabsSmall', $small->small ? 1 : 0, 0.25), $expand);
              if ($expand) { $small->small = false; $this->runtime?->changed(); } // a tap on the shrunk bar
              $bar->absolute()->bottom(0)->start(0)->end(0);
              $bar->floats = true; // a tap on it is its, not the page's under it
              if ($bar->changed) { $n->changed = true; }
            $this->end();
            return $n;
        }
        if ($where === self::TABS_TOP) {
            $top = $this->safeArea[0];
            $n = $this->column(0, 0, 1)->fill();
              $this->row(0, 0, 0, $t->background)->alignSelf(Node::STRETCH)->shrink(0)->size(0, 44 + $top)->padding($top, 16, 0, 16)->alignItems(Node::CENTER)->justify(Node::CENTER)->borderWidth(0, 0, 1, 0)->borderColor($t->border);
                $seg = $this->segmented(array_map(static fn(array $it): string => $it[1], $items), $index);
                if ($seg->changed) { $n->changed = true; }
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
     * a tap on that sets $expand. Not mygo's.
     * @param list<array{0: SVG|Closure(int): Image|null, 1: string}> $items an icon (an SVG, or a function of the color drawing one), and a label
     */
    public function tabBar(array $items, int &$index, float $small = 0.0, ?bool &$expand = null): Node
    {
        $expand = false;
        $t = $this->theme;
        $index = max(0, min($index, count($items) - 1));
        $bottom = max($this->safeArea[2], 8.0);
        $n = $this->row(0, 0)->alignSelf(Node::STRETCH)->shrink(0)->padding(8, 16, $bottom, 16)->justify($small > 0 ? Node::START : Node::CENTER)->alignItems(Node::STRETCH)
            ->focusGroup(Node::HORIZONTAL, selects: true)->role(Role::TAB_LIST);
          $full = max($this->width - 32, 64.0);
          $pill = $this->row(4, 0, 1)->size(0, 56)->radius(28)->alignItems(Node::STRETCH)->systemMaterial('glass');
          if ($small > 0) { $pill->maxWidth($full + (64 - $full) * $small); } // narrowing to the chosen tab's 64
            if (!($this->runtime?->systemMaterials ?? true)) { // myphp's own glass (Android): frosted as iOS 26's bar is, what passes under it a haze, not bent text
                $this->box(0, 0, $t->background)->absolute()->top(0)->bottom(0)->start(0)->end(0)->radius(28)->role(Role::NONE)->bgAlpha = 0.72;
            }
            foreach ($items as $i => [$svg, $label]) {
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
                $this->row(0, $side)->minHeight($button)->padding(0, $side)->radius($button / 2)->systemMaterial('glass')->alignItems(Node::CENTER);
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
     * down past the smallest it goes, as a tap outside does. Not mygo's.
     * @param list<float> $detents
     */
    public function sheet(bool &$open, Closure $fn, array $detents = []): ?Node
    {
        if (!$open) {
            return null;
        }
        $t = $this->theme;
        $rt = $this->runtime;
        $back = $panel = null;
        $gone = false;
        $this->overlay(function () use (&$back, &$panel, &$gone, $t, $rt, $fn, $detents): void {
            $back = $this->key('sheet')->column(0, 0, 0, 0x000000)->at(0, 0)->size($this->width, $this->height)->justify(Node::END)->block();
            $back->alpha = 0.4;
              $large = $this->height - $this->windowSafeArea[0] - 10;
              $panel = $this->column(0, 0)->alignSelf(Node::STRETCH)->maxHeight($large)->role(Role::DIALOG);
              if ($detents !== []) { // as high as the largest, moved down to show the others: the offset of each its height short of the largest
                  $heights = array_values(array_unique(array_map(fn(float $h) => min(max($h, 0.0), $large), $detents)));
                  sort($heights);
                  $top = $heights[count($heights) - 1];
                  $panel->minHeight($top)->maxHeight($top);
                  $kept = $rt?->local($back->id, fn() => (object)['offset' => [0.0, $top - $heights[0]]]) ?? (object)['offset' => [0.0, $top - $heights[0]]];
                  $stops = array_map(fn(float $h) => [0.0, $top - $h], $heights);
                  $stops[] = [0.0, $top]; // below the window: gone
                  $this->draggable($panel, $kept->offset, new DraggableOptions(Node::VERTICAL, bounds: [0, 0, 0, $top], snap: Motion::nearest(...$stops)));
                  $gone = $kept->offset[1] >= $top - 0.5 && !$this->dragging($panel) && $this->dragOffset($panel)[1] >= $top - 2; // let go toward below the window, and there: gone
              }
              $h = $rt?->rectOf($panel->id)[3] ?? 0.0;
              $p = $this->animate($panel, 'rise', 1, 0.35);
              $panel->translate(0, (1 - $p) * $h); // rises from below the window
              $panel->draw(static fn(Painter $pt, array $r) => $pt->fill([$r[0], $r[1], $r[2], $r[3] + 10], $pt->theme()->background, 10)); // its bottom corners below the window
                $this->column(0, 0)->alignSelf(Node::STRETCH)->alignItems(Node::CENTER)->padding(5, 0, 0, 0)->shrink(0);
                  $g = $this->box(36, 5, $t->textMuted)->radius(2.5); $g->alpha = 0.5; // the grabber
                $this->end();
                $this->column(16, $t->space(2), 1)->alignSelf(Node::STRETCH)->padding(16, 16, 16 + $this->windowSafeArea[2], 16);
                  $fn();
                $this->end();
              $this->end();
            $this->end();
        });
        if ($gone || $this->overlayShortcut($back, Key::ESCAPE) || ($this->clicked($back) && !$this->hovered($panel))) { // dragged away, Escape, or a tap outside
            $open = false;
            $back->dismissed = true;
        }
        return $back;
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
     * red for one marked "!Delete"; let go past half their width the row
     * stays open, else it closes; swiped on 48 points past them it performs
     * the first action (UIKit's full swipe). A tap on an open row closes it;
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
        $full = $total + 48; // swiped on this far, the first action is performed
        $sign = $rtl ? 1.0 : -1.0; // the content moves toward the start
        $n = $this->row(0, 0)->alignSelf(Node::STRETCH)->clip();
          $inner = $this->column(0, 0, 1)->alignSelf(Node::STRETCH)->focusable()->role(Role::LIST_ITEM); // the press target, so the drag is its
            $content();
          $this->end();
          $offset = $rt?->dragRecs[$inner->id]->own ?? [0.0, 0.0]; // where the row rests, kept with its drag record between frames
          $this->draggable($inner, $offset, new DraggableOptions(Node::HORIZONTAL,
              bounds: $rtl ? [0, 0, $full, 0] : [-$full, 0, $full, 0],
              snap: fn(array $p) => abs($p[0]) >= $full ? [$sign * $full, 0.0] : (abs($p[0]) > $total / 2 ? [$sign * $total, 0.0] : [0.0, 0.0])));
          $shown = $this->dragOffset($inner);
          $open = abs($shown[0]) > 0.5;
          $buttons = [];
          if ($open) { // the room the content leaves at its end, the buttons in it, the first nearest the content; closed, there is nothing of them to tap or read
              $strip = $this->row(0, 0)->absolute()->top(0)->bottom(0)->end(0)->size(min(abs($shown[0]), $full), 0)->clip()->justify(Node::END)->alignItems(Node::STRETCH);
              foreach ($actions as $i => $label) {
                  $destructive = $label !== '' && $label[0] === '!';
                  if ($destructive) { $label = substr($label, 1); }
                  $bg = $destructive ? $t->danger : $t->textMuted;
                  $b = $this->column(0, 0, 0, $bg)->size($widths[$i], 0)->shrink(0)->center()->focusable()->role(Role::BUTTON)->label($label);
                  if ($this->hovered($b) && ($rt?->input->mouseDown ?? false)) { $b->color = Theme::mix($bg, 0x000000, 0.15); }
                    $this->text($label, $t->fontSize, (Theme::luminance($bg) & 255) > 165 ? $t->text : 0xffffff)->singleLine(); // white on the gray and the red, as UIKit's; the theme's text, 17 on a phone
                  $this->end();
                  $buttons[$i] = $b;
              }
              $this->end();
          }
        $this->end();
        $close = static function () use (&$offset, $rt): void { $offset = [0.0, 0.0]; $rt?->changed(); };
        if (abs($offset[0]) == $full) { // let go past the buttons by 48: the full swipe performs the first action
            $action = 0; $close(); $rt?->haptic('medium'); // the full swipe's tap, as UIKit's
        }
        foreach ($buttons as $i => $b) {
            if ($this->clicked($b)) { $action = $i; $close(); }
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
