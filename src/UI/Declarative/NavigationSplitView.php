<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Image, Node, Role};

/**
 * NavigationSplitView, as SwiftUI's: a sidebar beside the detail. The
 * sidebar is a ListView(selection:) of Labels and Texts tagged with what
 * they choose, in Sections that fold, or any list, of the app's data
 * (ListView($items, id:, selection:)), with a search field over it; the
 * detail the view of the choice,
 * taking the rest of the room. Up, Down and typing choose in the sidebar
 * while it has the focus.
 */
final class NavigationSplitView extends View
{
    /** What a binding of NavigationSplitView() not given holds. @internal */
    public const UNBOUND = "\0unbound";

    private mixed $visibility = null;
    private bool $hasVisibility = false;
    private ?\Closure $accessory = null;

    /**
     * A view pinned at the bottom of the sidebar on the desktop (Arc's update pill), or floating centered over the
     * detail's bottom on a touch screen: NavigationSplitView(…)->bottomAccessory(fn() => UpdateCapsule(…)).
     */
    public function bottomAccessory(?\Closure $content): static { $this->accessory = $content; return $this; }

    /** $columnVisibility, bound: whether the sidebar shows (NavigationSplitViewVisibility), which a button by the window controls toggles, as SwiftUI's sidebar toggle. */
    public function __construct(public readonly ListView $sidebar, public readonly View|array $detail, mixed &$columnVisibility = null, bool $hasVisibility = false)
    {
        if ($hasVisibility) { $this->visibility = &$columnVisibility; $this->hasVisibility = true; }
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $t = $env->theme;
        $asList = $this->asList(); // a list keeps its own selection, if it has one: a list of a search finding nothing has none
        if (!$asList) { $selection = &$this->sidebar->selection(); }
        $chosen = $asList ? '' : (string)($selection ?? ''); // the sidebar's items are keyed by their tags as strings
        $touch = $c->runtime?->touch ?? false;
        if (!$touch) { $c->runtime?->askWindow('splitChrome', true); }
        [$safeTop, $safeRight, $safeBottom, $safeLeft] = $c->safeArea;
        $vibrant = $c->vibrancy(); // the window's material shows through the sidebar, as a Mac's
        $bar = $c->titleBar(); // a hidden title bar: the content goes under it, the window controls over the sidebar
        $inset = $bar['height'] > 0;
        $showToggle = $this->hasVisibility;
        $barH = $inset
            ? (($bar['controls'] ?? 0) > 0 ? 2 * $bar['controls'] : $bar['height'])
            : (($touch || $showToggle) ? 44.0 : 0.0); // 44pt unified toolbar row on Windows, Linux, iPadOS & Android Pad
        $topOffset = $inset ? 0.0 : $safeTop;
        $width = $this->sidebar->columnWidth ?? ($touch ? 260.0 : 220.0); // iPadOS primary column width (260pt at 17pt UIKit text) vs macOS 220pt
        $button = $touch ? 36.0 : 28.0;
        $iconSz = $touch ? 18 : 16;
        [$open, $closed] = [$width - 12 - $button, ($inset ? $bar['left'] : $safeLeft) + 12];
        $n = $c->open(new Node(Node::CONTAINER, Node::ROW))->alignItems(Node::STRETCH);
          $visible = !$this->hasVisibility || $this->visibility !== \NavigationSplitViewVisibility::detailOnly;
          $shown = $c->animate($n, 'sidebar', $visible ? 1 : 0, 0.25);
          $w = $width * $shown; // it slides out to the left as it hides
          $c->open(new Node(Node::CONTAINER, Node::ROW))->size($w, 0)->shrink(0)->alignItems(Node::STRETCH)->clip();
            $side = $c->open(new Node(Node::CONTAINER, Node::COLUMN))->size($width, 0)->shrink(0)->margin(0, 0, 0, $w - $width);
            if (!$vibrant) { $side->background($t->dark ? \MyPHP\UI\Theme::mix($t->background, $t->surface, 0.55) : $t->surface); }
              $sideTop = $inset ? $barH : ($showToggle ? $topOffset + $barH : $topOffset);
              if ($sideTop > 0) {
                  $hdr = $c->attach(new Node(Node::BOX, 0, 0, 0, 0, $sideTop))->fillWidth()->shrink(0);
                  if ($inset) { $hdr->dragWindow(); } // under the window controls: the window moves by it
              }
              if ($asList) { // a list of the app's data, or one with modifiers (searchable, contextMenu): the ListView itself in the sidebar, as SwiftUI's
                  $c->open(new Node(Node::CONTAINER, Node::COLUMN, 0, 0, 0, 0, 1))->alignSelf(Node::STRETCH)->minHeight(0)->padding(0, 0, $safeBottom, $safeLeft);
                    $list = $this->sidebar->render($c, $env->along(Node::COLUMN));
                  $c->end();
                  if ($list !== null) { $list->color = null; }
              } else {
                  $list = $c->sidebar($chosen, function () use ($c, $env): void { self::items($c, $env, $this->sidebar->content()); }, $width)->grow(1);
                  $list->color = null;
                  if ($safeBottom > 0 || $safeLeft > 0) {
                      $list->padding($t->space(2.5), $t->space(2.5), $t->space(2.5) + $safeBottom, $t->space(2.5) + $safeLeft);
                  }
              }
              if ($this->accessory !== null && !$touch) { // desktop: pinned at the bottom of the sidebar, as Arc's update pill
                  $acc = ($this->accessory)();
                  if ($acc instanceof View) {
                      $c->key('sidebar accessory');
                      $foot = $c->open(new Node(Node::CONTAINER, Node::COLUMN))->alignSelf(Node::STRETCH)->shrink(0)->padding(4, 8, 8 + $safeBottom, 8 + $safeLeft);
                        View::renderAll($c, $env->along(Node::COLUMN), [$acc]);
                      $c->end();
                      if ($foot->kids === []) { $foot->padding(0)->gone = true; }
                  }
              }
            $c->end();
          $c->end();
          if (!$asList && $chosen !== (string)($selection ?? '')) { $selection = self::tagOf($this->sidebar->content(), $chosen) ?? $chosen; $this->sidebar->commitBinding(); } // chosen in the sidebar, the tag itself (an int id stays an int, as SwiftUI's Hashable): before the detail, whose actions may choose too
          if ($w > 0) { $c->attach(new Node(Node::BOX, 0, 0, 0, 1, 0, 0, null, $t->dark ? 0x000000 : $t->border)); }
          $detail = $c->open(new Node(Node::CONTAINER, Node::COLUMN, 0, 0, 0, 0, 1))->minWidth(0)->minHeight(0)->alignSelf(Node::STRETCH);
            $views = is_array($this->detail) ? $this->detail : [$this->detail];
            $title = count($views) === 1 && $views[0] instanceof View ? ($views[0]->sets['title'] ?? null) : null;
            $toolbar = count($views) === 1 && $views[0] instanceof View ? ($views[0]->sets['toolbar'] ?? null) : null; // the detail's toolbar, at the end of the window's toolbar, as SwiftUI's on a Mac / iPad
            $items = $toolbar === null ? null : function () use ($c, $env, $toolbar): void { View::renderAll($c, $env->along(Node::ROW)->with(['buttonStyle' => \ButtonStyle::borderless, 'labelStyle' => Label::inToolbar($env)]), $toolbar); };
            // A background of the detail reaches under the title bar too, as SwiftUI's backgrounds go into the safe area.
            $bg = null;
            if (count($views) === 1 && $views[0] instanceof View) { foreach ($views[0]->mods as [$name, $m]) { if ($name === 'background') { $bg = $m[0]; } } }
            if ($bg !== null) {
                if ($bg->alpha > 0) { $detail->background($bg->rgb($env), $bg->alpha); }
            } elseif ($vibrant) {
                $detail->background($t->background);
            }
            $hasDetailBar = $inset || $showToggle || ($touch && ($title !== null || $items !== null));
            if ($hasDetailBar) { // macOS hiddenInset unified toolbar or iPadOS UISplitViewController detail navigation bar
                $leadBase = $inset ? $bar['left'] : $safeLeft;
                $leadPad = 20 + (1 - $shown) * ($showToggle ? $closed + $button + 12 - 20 : $leadBase);
                $trailPad = ($inset ? $bar['right'] : $safeRight) + 20;
                $hdrRow = $c->open(new Node(Node::CONTAINER, Node::ROW))->size(0, $topOffset + $barH)->fillWidth()->shrink(0)->alignItems(Node::CENTER)->padding($topOffset, $trailPad, 0, $leadPad);
                if ($inset) { $hdrRow->dragWindow(); }
                  if ($title !== null) { (new Text($title))->font($touch ? \Font::headline : \Font::title3)->bold()->lineLimit(1)->render($c, $env->along(Node::ROW)); }
                  if ($items !== null) { $c->toolbar($items)->grow(1)->minWidth(0)->justify(Node::END); }
                $c->end();
            } elseif ($title !== null || $items !== null) { // a title bar of the system's: the detail's title and toolbar a row under it
                $c->open(new Node(Node::CONTAINER, Node::ROW))->size(0, 44)->fillWidth()->shrink(0)->alignItems(Node::CENTER)->padding(0, 20, 0, 20);
                  if ($title !== null) { (new Text($title))->font(\Font::title3)->bold()->lineLimit(1)->render($c, $env->along(Node::ROW)); }
                  if ($items !== null) { $c->toolbar($items)->grow(1)->minWidth(0)->justify(Node::END); }
                $c->end();
            }
            $savedSafe = $c->safeArea;
            if ($hasDetailBar) { $c->safeArea[0] = 0.0; }
            if ($w > 0) { $c->safeArea[3] = 0.0; }
            View::renderAll($c, $env->along(Node::COLUMN)->with(['toolbarTaken' => $toolbar !== null]), $views);
            $c->safeArea = $savedSafe;
            if ($this->accessory !== null && $touch) { // tablet: floating centered over the detail's bottom
                $acc = ($this->accessory)();
                if ($acc instanceof View) {
                    $c->key('detail accessory');
                    $row = $c->open(new Node(Node::CONTAINER, Node::ROW))->absolute()->bottom(16.0 + $safeBottom)->start(0)->end(0)->justify(Node::CENTER)->alignItems(Node::CENTER);
                    $row->floats = true;
                      View::renderAll($c, $env->along(Node::ROW), [$acc]);
                    $c->end();
                    if ($row->kids === []) { $row->gone = true; }
                }
            }
          $c->end();
        $c->end();
        if ($showToggle) { // the sidebar's button, next to the window controls on macOS or in the iPadOS navigation bar
            $label = Localized::string($visible ? 'Hide Sidebar' : 'Show Sidebar', $env);
            $c->key('sidebar toggle');
            $curLeft = $closed + ($open - $closed) * $shown;
            $btnTop = $topOffset + ($barH - $button) / 2;
            $toggle = $c->open(new Node(Node::CONTAINER, Node::ROW))->center()->focusable()->shrink(0)->role(Role::BUTTON)->absolute()
                ->left($curLeft)->top($btnTop)->size($button, $button)->radius($touch ? $button / 2 : 6)
                ->label($label)->tooltip($label)->center();
            $toggle->floats = true;
            $hovered = $c->hovered($toggle);
            if ($hovered) {
                $pressed = $c->runtime?->input->mouseDown ?? false;
                $toggle->background($t->dark ? 0xffffff : 0x000000, $pressed ? ($t->dark ? 0.13 : 0.10) : ($t->dark ? 0.08 : 0.05));
            }
              $c->image(Image::symbol('sidebar.left', $iconSz, $touch ? $t->accent : ($hovered ? $t->text : $t->textMuted)), $iconSz, $iconSz);
            $c->end();
            // Accept standard click, or catch clicks landed across the moving button bounds during 0.25s slide animation
            $tClicked = $c->clicked($toggle);
            if (!$tClicked && ($c->runtime?->input->mouseInside ?? false) && !($c->runtime?->input->mouseDown ?? true)) {
                $upX = $c->runtime?->input->mouseX ?? -1.0;
                $upY = $c->runtime?->input->mouseY ?? -1.0;
                if ($upY >= $btnTop - 4.0 && $upY <= $btnTop + $button + 4.0) {
                    $minX = min($closed, $curLeft, $open) - 4.0;
                    $maxX = max($closed, $curLeft, $open) + $button + 4.0;
                    if ($upX >= $minX && $upX <= $maxX && $c->runtime?->pressTarget($upX, $upY) === $toggle->id) {
                        $tClicked = true;
                    }
                }
            }
            if ($tClicked) { $this->visibility = $visible ? \NavigationSplitViewVisibility::detailOnly : \NavigationSplitViewVisibility::all; $c->runtime?->changed(); }
        }
        View::takeRoom($n, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $n;
    }

    /** The sidebar's rows of $content: Sections fold, Labels and Texts are items choosing their tags. @param array<mixed> $content */
    /**
     * Whether the sidebar is drawn as its ListView rather than as AppKit's source list of tagged Labels and Texts: a list
     * of data (ListView($items, id:, selection:)(fn($item) => …), rows of any views), or one with modifiers of its own
     * (searchable, contextMenu…), as SwiftUI's sidebar takes any List.
     */
    private function asList(): bool
    {
        if ($this->sidebar->items !== null || $this->sidebar->events !== [] || $this->sidebar->mods !== []) { return true; }
        return array_any(ListView::flat($this->sidebar->content()), static fn(View $v): bool => !$v instanceof Section && (!($v instanceof Label || $v instanceof Text) || !$v->hasTag));
    }

    /** The tag of the item of $content keyed $key, null for none. @param array<mixed> $content */
    private static function tagOf(array $content, string $key): mixed
    {
        foreach (ListView::flat($content) as $v) {
            if ($v instanceof Section) { $t = self::tagOf($v->content(), $key); if ($t !== null) { return $t; } continue; }
            if ($v->hasTag && (string)$v->tagValue === $key) { return $v->tagValue; }
        }
        return null;
    }

    private static function items(Context $c, Env $env, array $content): void
    {
        foreach (ListView::flat($content) as $v) {
            if ($v instanceof Section) {
                $make = static fn() => new class { public bool $open = true; };
                $own = $c->runtime?->local($c->upcomingId(), $make) ?? $make();
                $open = $own->open;
                $c->sidebarSection(Localized::string($v->header, $env), $open, function () use ($c, $env, $v): void { self::items($c, $env, $v->content()); });
                $own->open = $open;
                continue;
            }
            if (!$v->hasTag) { throw new \LogicException('a sidebar item takes a tag(), what it chooses: Label(\'Inbox\', systemImage: \'tray\')->tag(\'inbox\')'); }
            [$title, $icon] = match (true) {
                $v instanceof Label => [$v->title, $v->image ?? ($v->systemImage === null ? null : static fn(int $rgb) => Image::symbol($v->systemImage, 16, $rgb))],
                $v instanceof Text => [$v->text, null],
                default => throw new \LogicException('a sidebar item is a Label or a Text, not ' . get_debug_type($v)),
            };
            $badge = $v->badgeValue === null ? null : static fn() => $c->badge(is_int($v->badgeValue) ? number_format($v->badgeValue) : Localized::string($v->badgeValue, $env));
            $item = $c->sidebarItem((string)$v->tagValue, Localized::string($title, $env), $icon, $badge, $env->tint?->rgb($env));
            foreach ($v->events as [$kind, $a]) { if ($kind === 'help') { $item->tooltip(Localized::string($a, $env)); } } // help(), as an item's
        }
    }
}
