<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Image, Node, SVG};

/**
 * Image, as SwiftUI's: a picture (MyPHP\UI\Image, an SVG), a file's, or a
 * symbol of the system's (systemName: SF Symbols on Apple's systems), at
 * its size, or ->resizable() to the room ->frame() gives it.
 */
final class ImageView extends View
{
    private bool $resizable = false;

    public function __construct(public readonly Image|SVG|null $image, public readonly ?string $systemName = null) {}

    public function resizable(): static { $this->resizable = true; return $this; }

    protected function build(Context $c, Env $env): ?Node
    {
        if ($this->systemName !== null) {
            $size = ($env->font?->size ?? $env->theme->fontSize) * 1.2;
            $img = Image::symbol($this->systemName, $size, $env->color?->rgb($env) ?? $env->theme->text);
            return $c->image($img, $size, $size);
        }
        $n = $c->image($this->image, 0, 0);
        if ($this->resizable) { $n->alignSelf(Node::STRETCH); }
        return $n;
    }
}
