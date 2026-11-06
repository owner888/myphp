<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

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
     * points — the tabs across the top, as iPadOS's, on a tablet held
     * upright, and a sidebar on a tablet on its side and on the desktop.
     * $items are [SVG icon, label] pairs; $content builds the page of tab
     * $index, told the placement too, and builds its own navigationBar and
     * safeArea inside (tabView takes the safe area's top itself when the
     * tabs are across the top). Fills what holds it; changed() reports a
     * tab chosen. tabsPlacement() tells the placement alone.
     * @param list<array{0: SVG, 1: string}> $items @param Closure(int, string): void $content
     */
    public function tabView(array $items, int &$index, Closure $content): Node
    {
        $t = $this->theme;
        $index = max(0, min($index, count($items) - 1));
        $where = $this->tabsPlacement();
        if ($where === self::TABS_BOTTOM) {
            $bottom = $this->safeArea[2];
            $n = $this->column(0, 0, 1)->fill();
              $this->safeArea[2] = 0.0; // the tabBar takes the bottom safe area below the page
              $this->column(0, 0, 1)->alignSelf(Node::STRETCH);
                $content($index, $where);
              $this->end();
              $this->safeArea[2] = $bottom;
              $bar = $this->tabBar($items, $index);
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
     * A tab bar across the bottom of the window, as UITabBar: $items are
     * [SVG icon, label] pairs sharing the width, the chosen one in the
     * accent, the others muted; a tap chooses, which changed() reports.
     * 49 points high (the hairline above it included) over the safe area's
     * bottom, which it extends into. Build it last in a column that fills the
     * window, the page's content growing above it (a Router's view).
     * @param list<array{0: SVG, 1: string}> $items
     */
    public function tabBar(array $items, int &$index): Node
    {
        $t = $this->theme;
        $index = max(0, min($index, count($items) - 1));
        $bottom = $this->safeArea[2];
        $n = $this->row(0, 0, 0, $t->background)->alignSelf(Node::STRETCH)->shrink(0)->padding(0, 0, $bottom, 0)->borderWidth(1, 0, 0, 0)->borderColor($t->border)
            ->focusGroup(Node::HORIZONTAL, selects: true)->role(Role::TAB_LIST);
        foreach ($items as $i => [$svg, $label]) {
            $on = $i === $index;
            $color = $on ? $t->accent : $t->textMuted;
            $tab = $this->column(0, 2, 1)->basis(0)->size(0, 48)->padding(6, 0, 0, 0)->alignItems(Node::CENTER)->focusable()->role(Role::TAB)->label($label);
            $tab->on = $on; $tab->checked($on);
              $this->icon($svg, 25, $color);
              $this->text($label, 10, $color)->singleLine()->fontWeight($on ? 500 : 400);
            $this->end();
            if ($this->clicked($tab) && !$on) {
                $index = $i;
                $n->changed = true;
            }
        }
        $this->end();
        return $n;
    }

    /**
     * A navigation bar across the top of the window, as UINavigationBar:
     * $title centered in 17 points semibold, 44 points high (the hairline
     * below it included) under the safe area's top, which it extends into. With a
     * $router that can go back, a chevron and the previous page's title
     * (or "Back") lead, in the accent, and a tap on them goes back; $trailing
     * builds what ends the bar (buttons, text in the accent). Build it first
     * in a column that fills the window.
     */
    public function navigationBar(string $title, ?Router $router = null, ?Closure $trailing = null): Node
    {
        $t = $this->theme;
        $top = $this->safeArea[0];
        $this->safeArea[0] = 0.0; // the bar took the top: a safeArea() built below it starts at the bar's bottom
        $n = $this->row(0, 0, 0, $t->background)->alignSelf(Node::STRETCH)->shrink(0)->size(0, 44 + $top)->padding($top, 8, 0, 8)->alignItems(Node::CENTER)
            ->borderWidth(0, 0, 1, 0)->borderColor($t->border)->role(Role::TOOLBAR);
          // the leading third, the title and the trailing third share the width, so the title centers in the bar
          $lead = $this->row(0, 0, 1)->basis(0)->alignItems(Node::CENTER)->justify(Node::START);
            if ($router !== null && $router->canGoBack()) {
                $back = $this->row(0, 6)->padding(0, 8, 0, 0)->radius($t->radius)->alignItems(Node::CENTER)->focusable()->role(Role::BUTTON)->label($this->texts()->get('back'));
                  $a = $this->box(12, 20, 0)->shrink(0); $a->color = null;
                  $a->draw(fn(Painter $p, array $r) => self::disclosure($p->displayList(), [$r[0] - 4, $r[1], 20, 20], 180, $p->theme()->accent));
                  $prev = $router->previousTitle();
                  $this->text($prev !== '' ? $prev : $this->texts()->get('back'), 17, $t->accent)->singleLine();
                $this->end();
                if ($this->clicked($back)) { $router->back(); }
            }
          $this->end();
          $this->text($title, 17, $t->text)->fontWeight(600)->singleLine()->shrink(1);
          $this->row(0, 16, 1)->basis(0)->alignItems(Node::CENTER)->justify(Node::END);
            if ($trailing !== null) { $trailing(); }
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
     * false, which dismissed() reports. Not mygo's.
     */
    public function sheet(bool &$open, Closure $fn): ?Node
    {
        if (!$open) {
            return null;
        }
        $t = $this->theme;
        $rt = $this->runtime;
        $back = $panel = null;
        $this->overlay(function () use (&$back, &$panel, $t, $rt, $fn): void {
            $back = $this->key('sheet')->column(0, 0, 0, 0x000000)->at(0, 0)->size($this->width, $this->height)->justify(Node::END)->block();
            $back->alpha = 0.4;
              $panel = $this->column(0, 0)->alignSelf(Node::STRETCH)->maxHeight($this->height - $this->windowSafeArea[0] - 10)->role(Role::DIALOG);
              $h = $rt?->rectOf($panel->id)[3] ?? 0.0;
              $p = $this->animate($panel, 'rise', 1, 0.35);
              $panel->translate(0, (1 - $p) * $h); // rises from below the window
              $panel->draw(fn(Painter $pt, array $r) => $pt->fill([$r[0], $r[1], $r[2], $r[3] + 10], $pt->theme()->background, 10)); // its bottom corners below the window
                $this->column(0, 0)->alignSelf(Node::STRETCH)->alignItems(Node::CENTER)->padding(5, 0, 0, 0)->shrink(0);
                  $g = $this->box(36, 5, $t->textMuted)->radius(2.5); $g->alpha = 0.5; // the grabber
                $this->end();
                $this->column(16, $t->space(2), 1)->alignSelf(Node::STRETCH)->padding(16, 16, 16 + $this->windowSafeArea[2], 16);
                  $fn();
                $this->end();
              $this->end();
            $this->end();
        });
        if ($this->overlayShortcut($back, Key::ESCAPE) || ($this->clicked($back) && !$this->hovered($panel))) {
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
                        $this->text($title, 13, $t->textMuted)->fontWeight(500);
                      $this->end();
                  }
                  foreach ($actions as $i => $label) {
                      $destructive = $label !== '' && $label[0] === '!';
                      if ($destructive) { $label = substr($label, 1); }
                      $row = $this->column(0, 0)->alignSelf(Node::STRETCH)->size(0, 57)->center()->focusable()->role(Role::MENU_ITEM)->label($label);
                      if ($i < count($actions) - 1) { $row->borderWidth(0, 0, 1, 0)->borderColor($t->border); }
                      if ($this->hovered($row) && ($rt?->input->mouseDown ?? false)) { $row->color = $t->surfacePressed; }
                        $this->text($label, 20, $destructive ? $t->danger : $t->accent)->singleLine();
                      $this->end();
                      if ($this->clicked($row)) { $chosen = $i; }
                  }
                $this->end();
                $cancel = $this->column(0, 0, 0, $t->background)->alignSelf(Node::STRETCH)->radius(13)->size(0, 57)->center()->focusable()->role(Role::BUTTON);
                if ($this->hovered($cancel) && ($rt?->input->mouseDown ?? false)) { $cancel->color = $t->surfacePressed; }
                  $this->text($this->texts()->get('cancel'), 20, $t->accent)->fontWeight(600)->singleLine();
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
     * A section of an inset grouped list, as UITableView's insetGrouped
     * style (Settings) and SwiftUI's Section in a List or Form: $header in
     * 13-point capitals, muted; the rows $rows builds in a group of the
     * theme's surface, its corners rounded 10, inset 16 from the sides,
     * a hairline between each two rows starting 16 in, as UITableView's
     * separators; $footer below in 13 points, muted. The header and the
     * footer sit 16 in from the group's edge. Stretches across its
     * parent; build sections one under the other in a scroll(). cell()
     * builds a row of UIKit's. Not mygo's.
     * @param Closure(): void $rows
     */
    public function section(string $header, Closure $rows, string $footer = ''): Node
    {
        $t = $this->theme;
        $n = $this->column(0, 0)->alignSelf(Node::STRETCH)->padding(0, 16)->role(Role::GROUP);
        if ($header !== '') { $n->label($header); }
          if ($header !== '') {
              $this->text(mb_strtoupper($header, 'UTF-8'), 13, $t->textMuted)->padding(16, 16, 7, 16)->role(Role::HEADING);
          }
          $group = $this->column(0, 0, 0, $t->surface)->alignSelf(Node::STRETCH)->radius(10)->clip();
            $rows();
          $this->end();
          $group->drawOver(static function (Painter $p, array $r) use ($group): void { // the separators: under each row but the last, from 16 in to the end
              $rows = array_values(array_filter($group->kids, static fn(Node $k): bool => !$k->absolute));
              for ($i = 0, $last = count($rows) - 1; $i < $last; $i++) {
                  $k = $rows[$i];
                  $p->fill([$r[0] + 16, $k->y + $k->h - 0.5, $r[2] - 16, 1.0], $p->theme()->border);
              }
          });
          if ($footer !== '') {
              $this->text($footer, 13, $t->textMuted)->padding(7, 16, 16, 16);
          }
        $this->end();
        return $n;
    }

    /**
     * A row of an inset grouped list, as UITableViewCell with the value1
     * style and SwiftUI's LabeledContent: $title in 17 points at the start,
     * $detail in 17 points muted at the end, and with $disclosure a
     * chevron after it, as a row that opens a page; 44 points high at
     * least, padded 16 at the sides. Takes the focus and reports clicked();
     * pressed, it fills with the theme's pressed surface. Build it in a
     * section(). Not mygo's.
     */
    public function cell(string $title, string $detail = '', bool $disclosure = false): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = $this->row(0, 8)->alignSelf(Node::STRETCH)->minHeight(44)->padding(0, 16)->alignItems(Node::CENTER)->focusable()->role(Role::BUTTON)->label($detail === '' ? $title : "$title, $detail");
        if ($this->hovered($n) && ($rt?->input->mouseDown ?? false)) { $n->color = $t->surfacePressed; }
          $this->text($title, 17, $t->text)->singleLine()->grow(1);
          if ($detail !== '') {
              $this->text($detail, 17, $t->textMuted)->singleLine()->shrink(0);
          }
          if ($disclosure) {
              $a = $this->box(8, 20, 0)->shrink(0); $a->color = null;
              $a->draw(static fn(Painter $p, array $r) => self::disclosure($p->displayList(), [$r[0] - 6, $r[1], 20, 20], 0, Theme::mix($p->theme()->textMuted, $p->theme()->surface, 0.3)));
          }
        $this->end();
        return $n;
    }
}
