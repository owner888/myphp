<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * NavigationLink, as SwiftUI's: goes to the page of $value, a path
 * ('/notes/42'), in the NavigationStack it is in; a row with a chevron in a
 * ListView, Section or Form, a button elsewhere. $label a view of its own.
 */
final class NavigationLink extends View
{
    public function __construct(public readonly string $title, public readonly string $value, public readonly ?View $label = null) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $router = $env->stack === null ? null : $env->route?->router;
        $go = function () use ($router): void { ($router ?? throw new \LogicException("NavigationLink to {$this->value} outside a NavigationStack"))->push($this->value); };
        if (!$env->inList) {
            return (new Button($this->title, $go, null, $this->label))->render($c, $env->with(['buttonStyle' => $env->buttonStyle ?? \ButtonStyle::borderless]));
        }
        $n = $this->label === null ? $c->cell(Localized::string($this->title, $env), '', true) : self::row($c, $env, $this->label, true);
        if ($c->clicked($n)) { $go(); }
        return $n;
    }

    /** A row of a list holding $view, as a cell is: 44 high at least, padded 16 at the sides, a chevron at its end with $disclosure. @internal */
    public static function row(Context $c, Env $env, View $view, bool $disclosure = false): Node
    {
        $n = $c->row(0, 8)->alignSelf(Node::STRETCH)->minHeight(44)->padding(11, 16)->alignItems(Node::CENTER);
          $view->render($c, $env->along(Node::ROW)->with(['inList' => false]));
          if ($disclosure) { $c->spacer(); $c->text('›', 22, $env->theme->textMuted); }
        $c->end();
        if ($disclosure) { $n->focusable(); }
        return $n;
    }
}
