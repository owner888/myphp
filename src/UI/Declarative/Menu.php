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

    /**
     * $systemImage, an SF Symbol's name, or $label, a view: the button shows it as the label of Menu(_:systemImage:)
     * does, and in a toolbar the icon alone, as SwiftUI's; the title names it to assistive technology.
     */
    public function __construct(public readonly string $title, public readonly ?string $systemImage = null, public readonly ?View $label = null) {}

    /** The items. */
    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        if ($this->systemImage !== null || $this->label !== null) { return $this->buildIcon($c, $env); }
        $n = $c->menuButton(Localized::string($this->title, $env), fn(UIMenu $m) => self::items($m, $this->content, $env));
        if ($env->disabled) { $n->disabled(); }
        return $n;
    }

    /** A menu button with a label of its own (an icon, a Label): the label alone, in a toolbar the icon in its room, no chevron. */
    private function buildIcon(Context $c, Env $env): Node
    {
        $t = $env->theme;
        $label = $this->label ?? new Label($this->title, $this->systemImage);
        $n = $c->open(new Node(Node::CONTAINER, Node::ROW))->center()->focusable()->shrink(0)->role(\MyPHP\UI\Role::BUTTON)->alignItems(Node::CENTER);
        if ($env->labelStyle === \LabelStyle::iconOnly) { $n->padding($t->space(1.5), $t->space(2))->radius($t->radius); }
        $label->render($c, $env->along(Node::ROW));
        $c->end();
        $n->fg = $env->color?->rgb($env) ?? $t->text;
        $n->label(Localized::string($this->title, $env));
        $n->menu(fn(UIMenu $m) => self::items($m, $this->content, $env));
        if ($env->disabled) { $n->disabled(); }
        return $n;
    }

    /** Fills the native menu $m with the items $content, running the action of the one chosen. @internal @param array<mixed> $content */
    public static function items(UIMenu $m, array $content, Env $env): void
    {
        $before = null; // what came before: a Section's end and what follows it are apart by a line, as SwiftUI's
        foreach (ListView::flat($content) as $v) {
            if ($before !== null && ($v instanceof Section || $before instanceof Section) && !($v instanceof Divider) && !($before instanceof Divider)) { $m->separator(); }
            $before = $v;
            match (true) {
                $v instanceof Button => $m->item(Localized::string($v->title, $env))->destructive($v->role === \ButtonRole::destructive)->systemImage($v->systemImage)->chosen() && $v->run(),
                $v instanceof Toggle => $m->item(Localized::string($v->title, $env))->checked($v->isOn())->chosen() && $v->flip(),
                $v instanceof Picker => $v->menuItems($m, $env), // its choices inline, the one chosen checked
                $v instanceof Divider => $m->separator(),
                $v instanceof Menu => $m->submenu(Localized::string($v->title, $env), fn(UIMenu $sub) => self::items($sub, $v->content, $env)),
                $v instanceof Section => self::section($m, $v, $env),
                default => throw new \LogicException('a menu holds Buttons, Toggles, Pickers, Dividers, Sections and Menus, not ' . get_debug_type($v)),
            };
        }
    }

    private static function section(UIMenu $m, Section $s, Env $env): void
    {
        if ($s->header !== '') { $m->header(Localized::string($s->header, $env)); }
        self::items($m, $s->content(), $env);
    }
}
