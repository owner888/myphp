<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\{FlyoutOptions, Rectangle, Window};
use MyPHP\UI\{Content, Context, Layout, Node};

/**
 * What a popover() of a window's view keeps between frames: the flyout it
 * shows in, outside the window as SwiftUI's on a Mac (an NSPopover there),
 * sized to its content's ideal size, and the binding it clears as it
 * closes. @internal
 */
final class PopoverKept
{
    public ?Window $flyout = null;
    /** The binding of the view's popover(isPresented:), which the flyout clears as the user closes it. */
    public mixed $presented = null;
    public ?Closure $content = null;
    /** @var array{0: int, 1: int}|null the content's size the flyout was last given */
    private ?array $size = null;
    private ?Rectangle $anchor = null;
    private bool $shown = false;
    /** Which window's frame asked for the other's: neither asks back, or they would draw each other forever. */
    private bool $byParent = false, $byFlyout = false;

    /** Opens the flyout, or keeps it open and placed, while $presented; closes it otherwise. */
    public function follow(Context $c, Node $anchor, bool &$presented, Closure $content, ?\Edge $edge): void
    {
        $this->presented = &$presented;
        $this->content = $content;
        $parent = $c->runtime?->window?->get();
        [$x, $y, $w, $h] = $c->bounds($anchor);
        $rect = new Rectangle((int)round($x), (int)round($y), (int)round($w), (int)round($h));
        if (!$presented) { $this->close(); return; }
        if ($this->flyout !== null) {
            if ($rect != $this->anchor) { $this->anchor = $rect; $this->flyout->setAnchor($rect); } // the view moved: the popover follows it
            if ($this->byFlyout) { $this->byFlyout = false; } // drawn for what the popover changed: it has drawn it
            else { $this->byParent = true; $this->flyout->invalidate(); } // its view reads the app's state, which this frame may have changed
            return;
        }
        if (!$parent instanceof Window || $w <= 0) { return; } // placed once the anchor has a box
        [$this->size, $this->anchor, $this->shown, $this->byParent, $this->byFlyout] = [null, $rect, false, false, false];
        $fly = $this->flyout = Window::flyout(new FlyoutOptions(parent: $parent, anchor: $rect, placement: self::placement($edge), gap: 4, width: 200, height: 100,
            focusable: true, hidden: true, shadow: true, popover: true, cornerRadius: 10, content: Content::view($this->render(...))));
        $fly->on('closed', function () use ($parent, $fly): void {
            if ($this->flyout !== $fly) { return; } // one the app closed before: the binding is the one shown since
            $this->flyout = null;
            $this->presented = false;
            $parent->invalidate();
        });
        $fly->invalidate(); // its first frame, which sizes it and shows it
        \MyPHP\App::after(0.2, function () use ($fly): void { if ($this->flyout === $fly && !$this->shown) { $this->shown = true; $fly->show(); } }); // should that frame not come, shown as it is
    }

    /** The flyout's view: the popover's content at its ideal size, which the flyout takes, then shows. */
    private function render(Context $c): void
    {
        if ($this->byParent) { $this->byParent = false; } // drawn for the window's frame
        elseif (($parent = $c->runtime?->window?->get()?->parent()) !== null) { $this->byFlyout = true; $parent->invalidate(); } // what is typed or chosen in it shows in the window as it changes, as SwiftUI's
        $c->root->color = null;
        $panel = !$c->vibrancy(); // without the popover's material (not a Mac): a panel of the theme's
        $n = $c->column(0, 0)->alignSelf(Node::START)->shrink(0); // its own height, not the window's, which follows it
        if ($panel) { $n->background($c->theme->surface)->radius(10)->border(1, $c->theme->border); }
          View::renderAll($c, Env::root($c), ($this->content)());
        $c->end();
        if (!$this->shown && ($field = self::firstField($n)) !== null) { $field->autoFocus(); } // as AppKit's popover: its first field has the keyboard
        [$w, $h] = Layout::fit($c, $n); // its texts wrapped at its width, as high as their lines: the window takes that size before it shows
        $size = [(int)ceil($w), (int)ceil($h)];
        if ($this->flyout === null) { return; }
        $flyout = $this->flyout;
        if ($size !== $this->size) { // a size, or another (its text wrapped, laid out at the first): the flyout takes it; it shows at the first, a hidden window drawing no more frames
            $this->size = $size;
            $show = !$this->shown;
            $this->shown = true;
            \MyPHP\App::later(static function () use ($flyout, $size, $show): void { $flyout->setContentSize(max($size[0], 1), max($size[1], 1)); if ($show) { $flyout->show(); } else { $flyout->invalidate(); } });
        }
    }

    /** The first field in $n, depth first. */
    private static function firstField(Node $n): ?Node
    {
        if ($n->kind === Node::INPUT || $n->kind === Node::TEXTAREA) { return $n; }
        foreach ($n->kids as $k) { if (($f = self::firstField($k)) !== null) { return $f; } }
        return null;
    }

    public function close(): void
    {
        $f = $this->flyout;
        $this->flyout = null;
        $f?->close();
    }

    public function __destruct() { $this->close(); } // its view gone: so is its popover

    /** Where the flyout goes from its anchor, the side $edge names (SwiftUI's arrowEdge), below by default. */
    private static function placement(?\Edge $edge): string
    {
        return match ($edge) {
            \Edge::top => FlyoutOptions::TOP,
            \Edge::leading => FlyoutOptions::LEFT,
            \Edge::trailing => FlyoutOptions::RIGHT,
            default => FlyoutOptions::BOTTOM,
        };
    }
}
