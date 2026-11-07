<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Image, Node};

/**
 * NavigationSplitView, as SwiftUI's: a sidebar beside the detail. The
 * sidebar is a ListView(selection:) of Labels and Texts tagged with what
 * they choose, in Sections that fold; the detail the view of the choice,
 * taking the rest of the room. Up, Down and typing choose in the sidebar
 * while it has the focus.
 */
final class NavigationSplitView extends View
{
    /** What a binding of NavigationSplitView() not given holds. @internal */
    public const UNBOUND = "\0unbound";

    private mixed $visibility = null;
    private bool $hasVisibility = false;

    /** $columnVisibility, bound: whether the sidebar shows (NavigationSplitViewVisibility), which a button by the window controls toggles, as SwiftUI's sidebar toggle. */
    public function __construct(public readonly ListView $sidebar, public readonly View|array $detail, mixed &$columnVisibility = null, bool $hasVisibility = false)
    {
        if ($sidebar->events !== [] || $sidebar->mods !== []) { throw new \LogicException('the sidebar is drawn as a sidebar, its own modifiers not: put them on the NavigationSplitView, NavigationSplitView(sidebar: ListView(selection: $this->page)(…), detail: …)->onChange(of: $this->page, action: …)'); }
        if ($hasVisibility) { $this->visibility = &$columnVisibility; $this->hasVisibility = true; }
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $t = $env->theme;
        $selection = &$this->sidebar->selection();
        $chosen = $selection ?? '';
        $vibrant = $c->vibrancy(); // the window's material shows through the sidebar, as a Mac's
        $bar = $c->titleBar(); // a hidden title bar: the content goes under it, the window controls over the sidebar
        $inset = $bar['height'] > 0;
        $barH = ($bar['controls'] ?? 0) > 0 ? 2 * $bar['controls'] : $bar['height']; // a row with the window controls in its middle
        $width = $this->sidebar->columnWidth ?? 220.0;
        // The sidebar's button, a circle as macOS 26's: at the sidebar's end as it shows, after the window controls as it hides, gliding between.
        $button = 36.0;
        [$open, $closed] = [$width - 10 - $button, $bar['left'] + 16];
        $n = $c->row(0, 0)->alignItems(Node::STRETCH);
          $visible = !$this->hasVisibility || $this->visibility !== \NavigationSplitViewVisibility::detailOnly;
          $shown = $c->animate($n, 'sidebar', $visible ? 1 : 0, 0.25);
          $w = $width * $shown; // it slides out to the left as it hides
          $c->row()->size($w, 0)->shrink(0)->alignItems(Node::STRETCH)->clip();
            $side = $c->column()->size($width, 0)->shrink(0)->margin(0, 0, 0, $w - $width);
            if (!$vibrant) { $side->background($t->surface); }
              if ($inset) { $c->box(0, $barH, 0)->fillWidth()->shrink(0)->dragWindow()->color = null; } // under the window controls: the window moves by it
              $list = $c->sidebar($chosen, function () use ($c, $env): void { self::items($c, $env, $this->sidebar->content()); }, $width)->grow(1);
              if ($vibrant) { $list->color = null; }
            $c->end();
          $c->end();
          if ($chosen !== ($selection ?? '')) { $selection = $chosen; $this->sidebar->commitBinding(); } // chosen in the sidebar: before the detail, whose actions may choose too
          if ($w > 0) { $c->box(1, 0, $t->border); }
          $detail = $c->column(0, 0, 1)->minWidth(0)->minHeight(0)->alignSelf(Node::STRETCH);
            $views = is_array($this->detail) ? $this->detail : [$this->detail];
            $title = count($views) === 1 && $views[0] instanceof View ? ($views[0]->sets['title'] ?? null) : null;
            $toolbar = count($views) === 1 && $views[0] instanceof View ? ($views[0]->sets['toolbar'] ?? null) : null; // the detail's toolbar, at the end of the window's toolbar, as SwiftUI's on a Mac
            $items = $toolbar === null ? null : function () use ($c, $env, $toolbar): void { View::renderAll($c, $env->along(Node::ROW)->with(['buttonStyle' => \ButtonStyle::borderless]), $toolbar); };
            // A background of the detail reaches under the title bar too, as SwiftUI's backgrounds go into the safe area.
            $bg = null;
            if (count($views) === 1 && $views[0] instanceof View) { foreach ($views[0]->mods as [$name, $m]) { if ($name === 'background') { $bg = $m[0]; } } }
            if ($bg !== null && $bg->alpha > 0) { $detail->background($bg->rgb($env), $bg->alpha); }
            if ($inset) { // the title bar's room: the detail's navigationTitle, left of the window controls when the sidebar hides
                $c->row()->size(0, $barH)->fillWidth()->shrink(0)->alignItems(Node::CENTER)->padding(0, $bar['right'] + 20, 0, 20 + (1 - $shown) * ($this->hasVisibility ? $closed + $button + 12 - 20 : $bar['left'])) // right of the button as the sidebar hides
                  ->dragWindow();
                  if ($title !== null) { $c->text(Localized::string($title, $env), $env->theme->fontSize)->preferredFont(\MyPHP\UI\Font::TITLE3)->fontWeight(700)->singleLine(); } // a toolbar's title, 15 bold on the Mac
                  if ($items !== null) { $c->spacer(); $c->toolbar($items); }
                $c->end();
                $c->divider();
            } elseif ($items !== null) { // a title bar of the system's: the toolbar a row under it
                $c->row()->fillWidth()->shrink(0)->alignItems(Node::CENTER)->padding(6, 12);
                  $c->spacer(); $c->toolbar($items);
                $c->end();
                $c->divider();
            }
            View::renderAll($c, $env->along(Node::COLUMN), $views);
          $c->end();
        $c->end();
        if ($this->hasVisibility && $inset) { // the sidebar's button, next to the window controls whether it shows or not
            $label = Localized::string($visible ? 'Hide Sidebar' : 'Show Sidebar', $env);
            $toggle = $c->key('sidebar toggle')->buttonBase()->absolute()->left($closed + ($open - $closed) * $shown)->top(($barH - $button) / 2)->size($button, $button)->radius($button / 2)
                ->label($label)->tooltip($label)->center()
                ->material(new \MyPHP\Plugins\Glass\Glass(tint: $t->surface, tintAlpha: 0.6, interactive: true)); // Liquid Glass, the Glass plugin's, tinted as the surface: under a vibrant sidebar nothing myphp painted shows through to frost (the developer asked for it less clear); it grows as pressed and lights up under the pointer
              $c->image(Image::symbol('sidebar.left', 18, $t->text), 18, 18);
            $c->end();
            if ($c->clicked($toggle)) { $this->visibility = $visible ? \NavigationSplitViewVisibility::detailOnly : \NavigationSplitViewVisibility::all; }
        }
        View::takeRoom($n, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $n;
    }

    /** The sidebar's rows of $content: Sections fold, Labels and Texts are items choosing their tags. @param array<mixed> $content */
    private static function items(Context $c, Env $env, array $content): void
    {
        foreach (ListView::flat($content) as $v) {
            if ($v instanceof Section) {
                $own = $c->runtime?->local($c->upcomingId(), fn() => (object)['open' => true]) ?? (object)['open' => true];
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
            $item = $c->sidebarItem((string)$v->tagValue, Localized::string($title, $env), $icon, $badge);
            foreach ($v->events as [$kind, $a]) { if ($kind === 'help') { $item->tooltip(Localized::string($a, $env)); } } // help(), as an item's
        }
    }
}
