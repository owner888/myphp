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
    public function __construct(public readonly ListView $sidebar, public readonly View|array $detail) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $selection = &$this->sidebar->selection();
        $chosen = $selection ?? '';
        $n = $c->row(0, 0)->alignItems(Node::STRETCH);
          $c->sidebar($chosen, function () use ($c, $env): void { self::items($c, $env, $this->sidebar->content()); })->alignSelf(Node::STRETCH);
          $detail = $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
            View::renderAll($c, $env->along(Node::COLUMN), is_array($this->detail) ? $this->detail : [$this->detail]);
          $c->end();
        $c->end();
        if ($chosen !== ($selection ?? '')) { $selection = $chosen; }
        View::fill($n, $env->axis, View::FLEX_X | View::FLEX_Y);
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
            $c->sidebarItem((string)$v->tagValue, Localized::string($title, $env), $icon);
        }
    }
}
