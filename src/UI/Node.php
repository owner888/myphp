<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * One element of a frame's tree. Trees are rebuilt every frame (immediate
 * mode, as in mygo/ui) and hold no parent pointers: without cycles, PHP
 * frees a frame's tree by reference counting alone and the GC never runs.
 */
final class Node
{
    public const CONTAINER = 0, TEXT = 1, BUTTON = 2, BOX = 3, INPUT = 4, TEXTAREA = 5, IMAGE = 6, RICH = 7, CHECKBOX = 8, RADIO = 9, SLIDER = 10, SELECT = 11, PROGRESS = 12;
    public const ROW = 0, COLUMN = 1;

    /** Order of creation in the build, or a hash of its key path; what Runtime remembers rects by. */
    public int $id = 0;
    /** Set on a container that scrolls its children, on the axes scrollX/scrollY say. */
    public ?Scrollable $scroll = null;
    public bool $scrollX = false;
    public bool $scrollY = true;
    /** For an input field: its text and selection. */
    public ?TextState $edit = null;
    /** Whether this node has the keyboard focus this frame. */
    public bool $focused = false;
    /** Whether this node is a list's chosen row, painted in the selection color. */
    public bool $selected = false;
    /** For text: Font style bits; for rich text: its spans. */
    public int $style = 0;
    /** @var Span[] */
    public array $spans = [];
    /** For rich text: its lines, wrapped at the width Context::rich was given, or at last frame's width ($wrapWidth). */
    public ?RichLayout $rich = null;
    public ?float $wrapWidth = null;
    /** Whether this node takes the keyboard focus (Tab, a click): see focusable(). */
    public bool $focusable = false;
    /** For a checkbox or radio: whether it is on; for a slider: its value as a share of the range, and whether it is held. */
    public bool $on = false;
    public float $fraction = 0;
    public bool $held = false;
    /** Whether a control changed its value this frame: Context::changed(). */
    public bool $changed = false;
    /** Shown in a tip when the pointer rests on the node: tooltip(). */
    public ?string $tooltip = null;
    /** For text: an underline (links). */
    public bool $underline = false;
    /** For a link: where it goes; the pointer shows a hand over it. */
    public ?string $url = null;
    /** Placed at (ax, ay) from its parent's top left instead of in the flow, at its own size: at(). */
    public bool $absolute = false;
    public float $ax = 0, $ay = 0;
    /** A material painted in place of the color: Glass, Blur or ScrollEdge. */
    public Glass|Blur|ScrollEdge|null $material = null;
    /** Whether the pointer is pressing this node (for interactive glass); Context sets it on material nodes. */
    public bool $pressed = false;
    /** For an image node: what it shows, its corner radius and opacity. */
    public ?Image $image = null;
    public float $radius = 0;
    public float $alpha = 1;
    /** @var Node[] */
    public array $kids = [];
    /** Laid-out rectangle. */
    public float $x = 0, $y = 0, $w = 0, $h = 0;
    /** Measured intrinsic size. */
    public float $mw = 0, $mh = 0;

    public function __construct(
        public int $kind,
        public int $dir,
        public float $pad,
        public float $gap,
        public float $fw,
        public float $fh,
        public int $grow,
        public ?string $text,
        /** Fill color as 0xRRGGBB; null paints no background. */
        public ?int $color,
        /** For text: font size in points and text color. */
        public float $fontSize = 0,
        public int $fg = 0,
    ) {}

    /** Takes this node out of its parent's flow and puts it at ($x, $y) from the parent's top left, at its own size (or size()). Later siblings paint over earlier ones. */
    public function at(float $x, float $y): static
    {
        $this->absolute = true;
        $this->ax = $x; $this->ay = $y;
        return $this;
    }

    /** Fills this container with a material instead of a color (Glass, Blur, ScrollEdge), shaped by round(). */
    public function material(Glass|Blur|ScrollEdge $material): static
    {
        $this->material = $material;
        return $this;
    }

    /** Rounds this container's corners by $radius (containers are square by default). */
    public function round(float $radius): static
    {
        $this->radius = $radius;
        return $this;
    }

    /** Shows $text in a tip when the pointer rests on this node for a moment. */
    public function tooltip(string $text): static
    {
        $this->tooltip = $text;
        return $this;
    }

    /** Fixes a container's width and/or height (points) instead of its content's size; 0 leaves one to the content. */
    public function size(float $w = 0, float $h = 0): static
    {
        $this->fw = $w; $this->fh = $h;
        return $this;
    }

    /** Lets this node take the keyboard focus like a button: Tab reaches it, a click gives it, Enter and Space click it, a ring shows it. */
    public function focusable(): static
    {
        $this->focusable = true;
        return $this;
    }

    /** Makes this container scroll its children, keeping the offsets in $state: vertically, horizontally (a ScrollState), or both. */
    public function scroll(Scrollable $state, bool $horizontal = false, bool $vertical = true): static
    {
        $this->scroll = $state;
        $this->scrollX = $horizontal && $state instanceof ScrollState;
        $this->scrollY = $vertical;
        return $this;
    }
}
