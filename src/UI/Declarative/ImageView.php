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
    private bool $resizable = false, $fit = false;

    public function __construct(public readonly Image|SVG|null $image, public readonly ?string $systemName = null) {}

    /** The picture fills the room it is given (->frame()), as SwiftUI's resizable. */
    public function resizable(): static { $this->resizable = true; return $this; }

    /** A resizable picture keeps its aspect ratio: as wide as its room, as high as that makes it, as SwiftUI's scaledToFit. */
    public function scaledToFit(): static { $this->fit = true; return $this; }

    protected function build(Context $c, Env $env): ?Node
    {
        if ($this->systemName !== null) {
            $size = ($env->font?->size ?? $env->theme->fontSize) * 1.2;
            $img = Image::symbol($this->systemName, $size, $env->color?->rgb($env) ?? $env->theme->text);
            return $c->image($img, $size, $size);
        }
        if ($this->image instanceof SVG && !$this->resizable) { // an icon, as a symbol: the font's size, the foreground color
            return $c->icon($this->image, ($env->font?->size ?? $env->theme->fontSize) * 1.2, $env->color?->rgb($env) ?? $env->theme->text);
        }
        $n = $c->image($this->image, 0, 0);
        if ($this->resizable) {
            [$w, $h] = [$n->fw, $n->fh];
            $n->size(0, 0); // its own size let go: the room's
            if ($this->fit && $w > 0 && $h > 0) {
                $n->aspectRatio($w / $h)->fit(Node::CONTAIN);
                View::takeRoom($n, $env->axis, View::FLEX_X);
            } else {
                $n->fit(Node::FILL_BOX);
                View::takeRoom($n, $env->axis, View::FLEX_X | View::FLEX_Y);
            }
        }
        return $n;
    }
}
