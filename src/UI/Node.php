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
    public const CONTAINER = 0, TEXT = 1, BUTTON = 2, BOX = 3, INPUT = 4, TEXTAREA = 5, IMAGE = 6, RICH = 7, CHECKBOX = 8, RADIO = 9, SLIDER = 10, SELECT = 11, PROGRESS = 12, SWITCH = 13, SPINNER = 14;
    public const ROW = 0, COLUMN = 1;
    /** Alignments for align(), alignSelf() and justify(); AUTO is a row's CENTER and a column's STRETCH across, START along. */
    public const AUTO = -1, START = 0, CENTER = 1, END = 2, STRETCH = 3;
    /** How this container places its children across its axis (align) and along it when none grows (justify), and how it sits across its parent's (self). */
    public int $align = self::AUTO, $self = self::AUTO, $justify = self::START;

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
    /** Makes what was built before this node inert to the pointer and the keys while it shows (a modal's backdrop). */
    public bool $blocks = false;
    /** For a field: room kept clear of the text at its left and right, beyond the usual inset (a search field's glass and clear button). */
    public float $insetL = 0, $insetR = 0;
    /** A text kept to one line: it shrinks to the room its row or column has and ends in an ellipsis where cut (mygo's SingleLine). */
    public bool $single = false;
    /** For a button or select: a chevron (mygo's, a Space(2.5) box stroked 1.5) after the label, Space(1.5) apart. */
    public bool $chevron = false;
    /** A one-point border in the theme's border color around this colored node (a plain button, a select). */
    public bool $bordered = false;
    /** A soft shadow under this container: [dy, blur, alpha], or null. */
    public ?array $shadow = null;
    /** Padding across (top and bottom) and along (left and right) when they differ from $pad: set by padding(); null means $pad. */
    public ?float $padY = null, $padX = null;
    /** Paints this node itself, given the display list, the node (laid out) and the theme, before its children (mygo's Draw): icons, custom marks. */
    public ?\Closure $draw = null;
    /** The pointer over this node: 'pointer', 'ibeam', 'resizeEW', 'resizeNS', or null for the arrow. */
    public ?string $cursor = null;
    /** Focused from the keyboard, so the painter rings it (mygo's FocusVisible); a press focuses without a ring. */
    public bool $focusVisible = false;
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
    /** For an image node: what it shows; for images and colored nodes, the corner radius and opacity. */
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

    /** Paints this node with $fn(DisplayList $list, Node $n, Theme $t) once laid out, before its children: a mark the list's rects, rings and lines draw. */
    public function draw(\Closure $fn): static
    {
        $this->draw = $fn;
        return $this;
    }

    /** Keeps this text to one line: where its row or column is short of its width it shrinks and ends in "…" (mygo's SingleLine). */
    public function single(): static
    {
        $this->single = true;
        return $this;
    }

    /** Keeps $left and $right points at a field's ends clear of its text, for what is drawn over them. */
    public function inset(float $left, float $right = 0): static
    {
        $this->insetL = $left; $this->insetR = $right;
        return $this;
    }

    /** Draws a one-point border in the theme's border color around this colored container. */
    public function border(): static
    {
        $this->bordered = true;
        return $this;
    }

    /** Casts a soft shadow under this container, $blur points wide, $dy points down, at $alpha. */
    public function shadow(float $dy = 6, float $blur = 20, float $alpha = 0.18): static
    {
        $this->shadow = [$dy, $blur, $alpha];
        return $this;
    }

    /** Makes what was built before this node inert while it shows: the pointer and Tab reach only it, what is inside it and what comes after (mygo's overlays). */
    public function block(): static
    {
        $this->blocks = true;
        return $this;
    }

    /** Shows $cursor ('pointer', 'ibeam', 'resizeEW', 'resizeNS') while the pointer is over this node. */
    public function cursor(string $cursor): static
    {
        $this->cursor = $cursor;
        return $this;
    }

    /** Shows $text in a tip when the pointer rests on this node for a moment. */
    public function tooltip(string $text): static
    {
        $this->tooltip = $text;
        return $this;
    }

    /** Pads this container $y points at its top and bottom and $x at its left and right (mygo's Padding(y, x)); row()/column()'s $pad is both. */
    public function padding(float $y, float $x): static
    {
        $this->padY = $y; $this->padX = $x;
        return $this;
    }

    /** The padding at the top and bottom, and at the left and right. */
    public function padY(): float { return $this->padY ?? $this->pad; }
    public function padX(): float { return $this->padX ?? $this->pad; }

    /** Places this container's children across its axis: a row's vertically, a column's horizontally (mygo's AlignItems); rows center by default, columns stretch. */
    public function align(int $align): static
    {
        $this->align = $align;
        return $this;
    }

    /** Places this node across its parent's axis, over the parent's align() (mygo's AlignSelf). */
    public function alignSelf(int $align): static
    {
        $this->self = $align;
        return $this;
    }

    /** Places this container's children along its axis when none grows: packed at the START, in the CENTER, or at the END (mygo's Justify). */
    public function justify(int $justify): static
    {
        $this->justify = $justify;
        return $this;
    }

    /** Centers this container's children along and across its axis (mygo's Center). */
    public function center(): static
    {
        $this->justify = self::CENTER; $this->align = self::CENTER;
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
