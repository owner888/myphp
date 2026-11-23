<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node, Role};

/**
 * Button, as SwiftUI's: a title, or a label view (Label, Image…), and the
 * action a click or tap runs — or Return, Space, its keyboardShortcut —
 * in the environment's buttonStyle: automatic (bordered on the desktop, a
 * capsule on a phone, its title in the tint in a phone's form row), borderedProminent (filled with the tint), bordered,
 * borderless and plain (its label alone). A destructive role is red.
 */
final class Button extends View
{
    public readonly ?View $label;

    /** $systemImage, an SF Symbol's name: the label a Label of the title and it, as SwiftUI's Button(_:systemImage:action:), and in a menu the item's icon. */
    public function __construct(
        public readonly string $title,
        public readonly ?Closure $action = null,
        public readonly ?\ButtonRole $role = null,
        ?View $label = null,
        public readonly ?string $systemImage = null,
    ) {
        $this->label = $label ?? ($systemImage !== null ? new Label($title, $systemImage) : null);
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $style = $env->buttonStyle ?? \ButtonStyle::automatic;
        if ($style === \ButtonStyle::automatic && $env->formRow && ($c->runtime?->touch ?? false)) { $style = \ButtonStyle::borderless; } // in a phone's form row, as SwiftUI's on iOS: its title in the tint, no face
        if ($this->label !== null) {
            $t = $env->theme;
            $prominent = $style === \ButtonStyle::borderedProminent;
            $n = $c->open(new Node(Node::CONTAINER, Node::ROW))->center()->focusable()->shrink(0)->role(Role::BUTTON)->alignItems(Node::CENTER);
            if ($style !== \ButtonStyle::borderless && $style !== \ButtonStyle::plain) { // the face of a button() around the label: same outer height as makeButtonNode
                $edge = 2.0;
                $py = $t->space(1.5);
                $px = $t->space(3.5);
                $textH = $c->metrics()->measure('X', $t->fontSize)[1];
                $minH = $t->controlHeight > 0 ? $t->controlHeight : ($textH + 2 * $py + $edge);
                $n->padding($py, ($t->controlHeight > 0 ? 20.0 : $px) + $edge / 2)->minHeight($minH)->radius($t->controlHeight > 0 ? $minH / 2 : $t->radius);
                $n->color = $prominent ? $t->accent : ($c->hovered($n) ? $t->surfaceHover : $t->surface);
                $n->bordered = !$prominent;
            } elseif ($env->labelStyle === \LabelStyle::iconOnly) { // an icon of a toolbar: room around it to click, a face while hovered, as AppKit's toolbar items
                $n->padding($t->space(1.5), $t->space(2))->radius($t->radius);
                if ($c->hovered($n)) { $n->color = $t->surfaceHover; }
            }
            $labelEnv = $prominent ? $env->along(Node::ROW)->with(['color' => new ColorValue(null, $t->accentText)]) : $env->along(Node::ROW);
            $kid = $this->label->render($c, $labelEnv);
            $c->end();
            $flex = View::flexOfKids($n);
            if (($flex & View::FLEX_X) && $kid !== null) {
                $kid->grow = max($kid->grow, 1);
                if (count($kid->kids) === 1 && $kid->dir === Node::COLUMN) {
                    $kid->kids[0]->alignSelf(Node::STRETCH);
                }
            }
            View::takeRoom($n, $env->axis, $flex); // a label with frame(maxWidth: INF) stretches the button too
        } else {
            $title = Localized::string($this->title, $env);
            $n = self::makeButtonNode($c, $env, $title, $style === \ButtonStyle::borderedProminent);
            // Its title in the environment's weight, slant and lines, as a SwiftUI button's Text: Button('B')->bold()
            if ($env->weight !== null && $env->weight !== \Weight::regular) { $n->fontWeight($env->weight->value); }
            if ($env->italic) { $n->italic(); }
            if ($env->underline) { $n->underline(); }
            if ($env->strikethrough) { $n->strikethrough(); }
        }
        if ($style === \ButtonStyle::borderless || $style === \ButtonStyle::plain) {
            $n->bordered = false;
            if (!($this->label !== null && $env->labelStyle === \LabelStyle::iconOnly && $c->hovered($n))) { $n->color = null; }
            $n->fg = $style === \ButtonStyle::plain ? ($env->color?->rgb($env) ?? $env->theme->text) : ($env->tint?->rgb($env) ?? $env->theme->accent);
        } elseif ($style === \ButtonStyle::borderedProminent && $env->tint !== null) {
            $n->color = $env->tint->rgb($env);
        }
        if ($this->role === \ButtonRole::destructive) { $n->fg = $style === \ButtonStyle::borderedProminent ? 0xffffff : $env->theme->danger; if ($style === \ButtonStyle::borderedProminent) { $n->color = $env->theme->danger; } }
        $n->keepFocus(); // a click leaves the keyboard focus where it is, as a macOS button's: the field or editor typed in keeps it
        if ($env->disabled) { $n->disabled(); }
        if ($this->label === null) { $n->label(Localized::string($this->title, $env)); }
        if ($c->clicked($n) && $this->action !== null) { ($this->action)(); }
        return $n;
    }

    private static function makeButtonNode(Context $c, Env $env, string $s, bool $primary): Node
    {
        $t = $env->theme;
        $size = $t->fontSize;
        $metrics = $c->metrics();
        [$w, $h] = $metrics->measure($s, $size);
        $h = max($h, $metrics->measure('X', $size)[1]);
        [$base, $hover, $pressed, $fg] = $primary ? [$t->accent, $t->accentHover, $t->accentPressed, $t->accentText] : [$t->surface, $t->surfaceHover, $t->surfacePressed, $t->text];
        $bordered = !$primary;
        $edge = 2.0; // SwiftUI bordered & borderedProminent share identical outer dimensions
        [$py, $px] = [$t->space(1.5), $t->space(3.5)];
        if ($t->controlHeight > 0) {
            $py = max($py, ($t->controlHeight - $h - $edge) / 2);
            $px = 20.0;
        }
        $n = $c->attach(new Node(Node::BUTTON, 0, 0, 0, $w + 2 * $px + $edge, $h + 2 * $py + $edge, 0, $s, $base, $size, $fg));
        if ($t->controlHeight > 0) { $n->radius($n->fh / 2); }
        $n->madePad = [[$py, $px, $py, $px], [$n->fw, $n->fh]];
        $n->bordered = $bordered;
        $n->metrics = $metrics;
        $n->focused = $c->runtime?->focused($n->id) ?? false;
        if ($c->runtime?->isTouchFlashing($n->id) ?? false) {
            $n->color = $primary ? 0x1d4ed8 : $t->accent;
            $n->fg = $t->accentText;
            $n->bordered = true;
            $n->borderColor = $t->focus;
        } elseif ($c->runtime?->hovered($n->id) ?? false) {
            $n->color = $c->runtime->input->mouseDown ? $pressed : $hover;
        }
        return $n;
    }

    protected function shortcut(): void
    {
        $this->run();
    }

    /** Runs the button's action, as a click does. @internal */
    public function run(): void
    {
        if ($this->action !== null) { ($this->action)(); }
    }
}
