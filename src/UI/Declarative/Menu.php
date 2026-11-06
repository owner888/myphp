<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Menu as UIMenu, Node};

/**
 * Menu, as SwiftUI's: a button that opens its content as a menu — Buttons,
 * Toggles (checked), Dividers, Sections (a header) and Menus (submenus).
 * contextMenu() fills a view's context menu the same way.
 */
final class Menu extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(public readonly string $title) {}

    /** The items. */
    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->menuButton(Localized::string($this->title, $env), fn(UIMenu $m) => self::items($m, $this->content, $env));
        if ($env->disabled) { $n->disabled(); }
        return $n;
    }

    /** Fills the native menu $m with the items $content, running the action of the one chosen. @internal @param array<mixed> $content */
    public static function items(UIMenu $m, array $content, Env $env): void
    {
        foreach (ListView::flat($content) as $v) {
            match (true) {
                $v instanceof Button => $m->item(Localized::string($v->title, $env))->chosen() && $v->run(),
                $v instanceof Toggle => $m->item(Localized::string($v->title, $env))->checked($v->isOn())->chosen() && $v->flip(),
                $v instanceof Divider => $m->separator(),
                $v instanceof Menu => $m->submenu(Localized::string($v->title, $env), fn(UIMenu $sub) => self::items($sub, $v->content, $env)),
                $v instanceof Section => self::section($m, $v, $env),
                default => throw new \LogicException('a menu holds Buttons, Toggles, Dividers, Sections and Menus, not ' . get_debug_type($v)),
            };
        }
    }

    private static function section(UIMenu $m, Section $s, Env $env): void
    {
        if ($s->header !== '') { $m->header(Localized::string($s->header, $env)); }
        self::items($m, $s->content(), $env);
    }
}
