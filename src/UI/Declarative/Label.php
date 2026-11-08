<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, SVG};

/** Label, as SwiftUI's: an icon (a system symbol, or an SVG) and a title, side by side. */
final class Label extends View
{
    public function __construct(public readonly string $title, public readonly ?string $systemImage = null, public readonly ?SVG $image = null) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $style = $env->labelStyle ?? \LabelStyle::automatic;
        $icon = $this->image !== null || ($this->systemImage !== null && \MyPHP\App::backend()->symbols()->exists($this->systemImage)); // a symbol the system has
        $showIcon = $style !== \LabelStyle::titleOnly;
        $showTitle = $style !== \LabelStyle::iconOnly || !$icon; // its icon alone, where there is one: else its title
        $n = $c->row(0, 6)->alignItems(Node::CENTER);
          if ($showIcon && $this->systemImage !== null && ($icon || !$showTitle)) { (new ImageView(null, $this->systemImage))->render($c, $env->along(Node::ROW)); }
          if ($showIcon && $this->image !== null) { $size = $env->theme->fontSize * 1.2; $c->icon($this->image, $size, $env->color?->rgb($env) ?? $env->theme->text); }
          if ($showTitle) { (new Text($this->title))->render($c, $env->along(Node::ROW)); }
        $c->end();
        $n->label(Localized::string($this->title, $env));
        if (!$showTitle) { $n->tooltip(Localized::string($this->title, $env)); } // its title on hover, as a toolbar's icon says it
        return $n;
    }

    /** The style of the Labels of a toolbar's items: their icons alone, as SwiftUI's toolbar, unless the app chose one. @internal */
    public static function inToolbar(Env $env): \LabelStyle
    {
        return $env->labelStyle !== null && $env->labelStyle !== \LabelStyle::automatic ? $env->labelStyle : \LabelStyle::iconOnly;
    }
}
