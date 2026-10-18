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
    /** How a grid places its children in their cells across (Node::justifyItems), and how this child is placed over that (justifySelf): STRETCH unless said. */
    public int $justifyItems = self::AUTO, $justifySelf = self::AUTO;

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
    /** A focus group's orientation: the arrows that move the focus inside it (HORIZONTAL, VERTICAL or both); 0 for no group. And whether moving the focus chooses the member (a radio group's, a tab list's). */
    public int $focusGroup = 0;
    public bool $groupSelects = false;
    public const HORIZONTAL = 1, VERTICAL = 2;
    /** The least width and height this node measures to (mygo's MinWidth, MinHeight); 0 for none. */
    public float $minW = 0, $minH = 0;
    /** The flex basis along the parent's axis (Node::basis), or null for the content's size. */
    public ?float $basis = null;
    /** A text kept to one line: it shrinks to the room its row or column has and ends in an ellipsis where cut (mygo's SingleLine). */
    public bool $single = false;
    /** For a button or select: a chevron (mygo's, a Space(2.5) box stroked 1.5) after the label, Space(1.5) apart. */
    public bool $chevron = false;
    /** A one-point border around this colored node (a plain button, a select, a field), in $borderColor or the theme's border color. */
    public bool $bordered = false;
    public ?int $borderColor = null;
    public float $borderWidth = 1.0;
    /** A soft shadow under this container: [dy, blur, alpha], or null. */
    public ?array $shadow = null;
    /** Padding across (top and bottom) and along (left and right) when they differ from $pad: set by padding(); null means $pad. */
    public ?float $padY = null, $padX = null;
    /** Paints this node itself, given the display list, the node (laid out) and the theme, before its children (mygo's Draw): icons, custom marks. */
    public ?\Closure $draw = null;
    /** The pointer over this node: 'pointer', 'ibeam', 'resizeEW', 'resizeNS', or null for the arrow. */
    public ?string $cursor = null;
    /** Takes this node's input as it comes (handleInput), and the client its text comes to from the system's input methods (handleTextInput). */
    public ?\Closure $input = null;
    public ?TextInputClient $textClient = null;
    /** This node paints its own focus ring (a slider's knob): the painter's generic ring stays off it. */
    public bool $ownRing = false;
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
    /** A grid's column tracks and its first rows' (Track); null for a flex container. The rows beyond fit their content. */
    public ?array $gridCols = null;
    public array $gridRows = [];
    /** Where this node goes in its grid (mygo's gridCell): lines from 1, 0 for automatic placement, and spans, 0 for 1 and negative to the last line. */
    public int $cellCol = 0, $cellRow = 0, $colSpan = 0, $rowSpan = 0;

    /** A row that wraps its children onto more lines where they overflow $wrapWidth (mygo's Wrap), the width it had last frame. */
    public bool $wraps = false;
    /** Whether this node takes the keyboard focus (Tab, a click): see focusable(). */
    public bool $focusable = false;
    /** For a checkbox or radio: whether it is on; for a slider: its value as a share of the range, and whether it is held. */
    public bool $on = false;
    /** Drawn over the children (mygo's DrawOver): a list's drop line. */
    public ?\Closure $drawOver = null;

    /** A press on this node (not on a control inside it) drags the window, a double click zooms or minimizes it as macOS is set to (mygo's DragWindow): a view's own title bar. */
    public bool $dragsWindow = false;

    /** How an image or SVG fills its box (Node::fit): CONTAIN scales it to fit inside, keeping its aspect ratio; COVER scales it to cover the box, cropping it; FILL_BOX stretches it; SCALE_DOWN shows it at its own size, or as CONTAIN does where that is smaller; NATURAL_SIZE shows it at its own size, centered and cropped. mygo's Fit. */
    public const CONTAIN = 0, COVER = 1, FILL_BOX = 2, SCALE_DOWN = 3, NATURAL_SIZE = 4;
    public int $fit = self::CONTAIN;
    /** An image or icon drawn in shades of gray (mygo's Grayscale). */
    public bool $gray = false;

    /** A check box over a group of them, some but not all checked: a bar instead of a check (mygo's mixed state). */
    public bool $mixed = false;
    public float $fraction = 0;
    /** How far a slider's knob is held, from 0 to 1, moving between them as it is pressed and let go (mygo's "held"). */
    public float $held = 0.0;
    /** The node's visual transform (transform()), its origin as parts of its box, whether it hides while turned away, and its opacity, which its children share (opacity()). */
    public Transform $transform;
    public float $originX = 0.5, $originY = 0.5;
    public bool $backface = false;
    public float $opacity = 1.0;
    /** The map from the node's layout to the window, its ancestors' transforms included: Runtime::record sets it. */
    public Affine $world;
    /** @var list<Node> the copies of the children gone with an exit, which go under the others (RuntimeTransition); and, on such a copy, 1 */
    public array $ghosts = [];
    public int $leaving = 0;
    /** What of the node's children the node cuts off at its edges: CLIP_X, CLIP_Y or both (clip(), clipY()). */
    public int $clip = 0;
    public const CLIP_X = 1, CLIP_Y = 2;
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
    /** For an image node, an SVG shown in its own colors instead; for an icon, the SVG it shows. */
    public ?SVG $svg = null;
    /** Degrees an icon's artwork is turned clockwise around its center (Node::rotate). */
    public float $rotation = 0;
    public float $radius = 0;
    public float $alpha = 1;
    /** @var Node[] */
    public array $kids = [];
    /** Laid-out rectangle. */
    public float $x = 0, $y = 0, $w = 0, $h = 0;
    /** Measured intrinsic size. */
    public float $mw = 0, $mh = 0;
    /** The least width the node shrinks to in a row (Layout: its min-content width, mygo's automatic minimum): a single-line text's minW, a container's children's least widths, a leaf's own width. */
    public float $minMw = 0;

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
    ) {
        $this->transform = Transform::identity();
        $this->world = Affine::identity();
    }

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

    /** Paints this node with $fn(DisplayList $list, Node $n, Theme $t, TextPainter $text) once laid out, before its children: a mark the list's rects, rings and lines draw; $text for what the atlas holds (an icon's mask). */
    public function draw(\Closure $fn): static
    {
        $this->draw = $fn;
        return $this;
    }

    /**
     * Makes the focusable nodes inside this one a single stop of Tab, as a
     * toolbar's controls, a radio group or a tab list are (mygo's
     * FocusGroup): Tab moves into the group, to the member that had the
     * focus last (else the one on, else the first), and on out of it; the
     * arrows $orientation names move among the members, round from the
     * last to the first, Home and End to the first and the last; with
     * $selects, moving chooses the member it moves to, as a radio does.
     */
    public function focusGroup(int $orientation, bool $selects = false): static
    {
        $this->focusGroup = $orientation; $this->groupSelects = $selects;
        return $this;
    }

    /**
     * The size this node starts from along its parent's axis before the room
     * left is shared out by grow, as mygo's Basis: 0 with grow makes the
     * children share the room equally, whatever their content measures,
     * and keeps the content from widening the parent; null is the content's size.
     */
    public function basis(float $basis): static
    {
        $this->basis = $basis;
        return $this;
    }

    /**
     * Turns an icon's artwork clockwise around its center by $degrees, which
     * changes no layout sizes, as mygo's Rotate: a spinner keeps turning
     * its SVG with `$spin->rotate($c->loop(...) * 360)`. Any other node it
     * turns after its transform, around its origin; transform(
     * Transform::rotation()) turns an icon's whole box.
     */
    public function rotate(float $degrees): static
    {
        if (!is_finite($degrees)) {
            throw new \InvalidArgumentException('transform values must be finite');
        }
        if ($this->svg !== null) {
            $this->rotation = $degrees;
        } else {
            $this->transform = $this->transform->then(Transform::rotation($degrees));
        }
        return $this;
    }

    /** Sets the node's visual transform, as mygo's Transform: it changes drawing and the pointer, not the layout. Its origin is the center of the node's box unless transformOrigin() moves it. */
    public function transform(Transform $t): static
    {
        $this->transform = $t;
        return $this;
    }

    /** Moves the node after its transform. */
    public function translate(float $x, float $y): static
    {
        $this->transform = $this->transform->then(Transform::translation($x, $y));
        return $this;
    }

    /** Scales the node after its transform, around its origin. */
    public function scale(float $x, float $y): static
    {
        $this->transform = $this->transform->then(Transform::scaling($x, $y));
        return $this;
    }

    /** Slants the node after its transform, around its origin, by x degrees along the horizontal axis and y along the vertical. */
    public function skew(float $x, float $y): static
    {
        $this->transform = $this->transform->then(Transform::skewing($x, $y));
        return $this;
    }

    /** Turns the node in 3D around the horizontal axis through its origin, after its transform; follow it with perspective() to see it in depth. */
    public function rotateX(float $degrees): static
    {
        $this->transform = $this->transform->then(Transform::rotationX($degrees));
        return $this;
    }

    /** Turns the node in 3D around the vertical axis through its origin, after its transform: a card turning over shows its back past 90 degrees, mirrored. */
    public function rotateY(float $degrees): static
    {
        $this->transform = $this->transform->then(Transform::rotationY($degrees));
        return $this;
    }

    /** Sees the node's transform in 3D from $distance points in front of its origin. */
    public function perspective(float $distance): static
    {
        $this->transform = $this->transform->then(Transform::perspective($distance));
        return $this;
    }

    /** The origin of the node's transform, as parts of its box: (0, 0) its top left, (0.5, 0.5) its center; values outside the box are allowed, and translation does not depend on it. */
    public function transformOrigin(float $x, float $y): static
    {
        if (!is_finite($x) || !is_finite($y)) {
            throw new \InvalidArgumentException('transform values must be finite');
        }
        $this->originX = $x; $this->originY = $y;
        return $this;
    }

    /** Hides the node, and takes it from the pointer, while its 3D transform turns its back to the viewer, as CSS's backface-visibility: hidden: the two faces of a card turning over show one at a time. */
    public function backfaceHidden(): static
    {
        $this->backface = true;
        return $this;
    }

    /** Makes the node and its children translucent, from 0 to 1 (mygo's Opacity). */
    public function opacity(float $o): static
    {
        $this->opacity = max(0.0, min($o, 1.0));
        return $this;
    }

    /** The node's map from its layout to the window: its transform around its origin, after its ancestors'. */
    public function transformAt(): Affine
    {
        $m = $this->transform->flat();
        if (!$m->set) {
            return $m;
        }
        $x = $this->x + $this->originX * $this->w; $y = $this->y + $this->originY * $this->h;
        return Affine::translation($x, $y)->mul($m)->mul(Affine::translation(-$x, -$y));
    }

    /** How this node's image or SVG fills its box: one of Node::CONTAIN, COVER, FILL_BOX, SCALE_DOWN, NATURAL_SIZE (mygo's Fit). */
    public function fit(int $fit): static
    {
        $this->fit = $fit;
        return $this;
    }

    /** Draws this node's image, or icon, in shades of gray (mygo's Grayscale). */
    public function grayscale(): static
    {
        $this->gray = true;
        return $this;
    }

    /** Keeps this node at least $w wide and $h high, whatever its content measures (mygo's MinWidth and MinHeight); 0 leaves one to the content. */
    public function min(float $w = 0, float $h = 0): static
    {
        $this->minW = $w; $this->minH = $h;
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

    /** Paints over this node's children with $fn (list, node, theme), as mygo's DrawOver. */
    public function drawOver(\Closure $fn): static
    {
        $this->drawOver = $fn;
        return $this;
    }

    /** Makes this node a handle that moves the window, as a title bar the view draws (mygo's DragWindow); controls inside it keep their presses. */
    public function dragWindow(): static
    {
        $this->dragsWindow = true;
        return $this;
    }

    /** Puts this node in column $n of its grid, counting from 1 (mygo's ColumnStart). */
    public function columnStart(int $n): static { $this->cellCol = max($n, 1); return $this; }
    /** Puts this node in row $n of its grid, counting from 1 (mygo's RowStart). */
    public function rowStart(int $n): static { $this->cellRow = max($n, 1); return $this; }
    /** Makes this node span $n columns of its grid, or, for a negative $n, to the last column (mygo's ColumnSpan). */
    public function columnSpan(int $n): static { $this->colSpan = $n; return $this; }
    /** Makes this node span $n rows of its grid, or, for a negative $n, to the last row set (mygo's RowSpan). */
    public function rowSpan(int $n): static { $this->rowSpan = $n; return $this; }

    /** Lays this container's children out in a grid, as mygo's Grid: cell by cell, row by row, in columns sized by $cols and rows by $rows (those beyond fit their content); gap between tracks. @param list<Track> $cols @param list<Track> $rows */
    public function grid(array $cols, array $rows = []): static
    {
        $this->gridCols = $cols; $this->gridRows = $rows;
        return $this;
    }

    /** Wraps this row's children onto more lines where they overflow $width, the width it had last frame (Runtime::rectOf); a frame follows when its width differs. */
    public function wrap(float $width): static
    {
        $this->wraps = true; $this->wrapWidth = $width;
        return $this;
    }

    /** Draws a one-point border around this colored container, in $color or the theme's border color. */
    public function border(?int $color = null, float $width = 1.0): static
    {
        $this->bordered = true; $this->borderColor = $color; $this->borderWidth = $width;
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
    /** Places the children of a grid in their cells horizontally: STRETCH (the default), START, CENTER or END, as mygo's JustifyItems. */
    public function justifyItems(int $align): static
    {
        $this->justifyItems = $align;
        return $this;
    }

    /** Places this node in its grid cell horizontally, overriding its grid's justifyItems (mygo's JustifySelf). */
    public function justifySelf(int $align): static
    {
        $this->justifySelf = $align;
        return $this;
    }

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

    /** Cuts what its children draw off at the node's edges, as mygo's Clip; what is cut off does not show in the window (Context::inView). */
    public function clip(): static
    {
        $this->clip = self::CLIP_X | self::CLIP_Y;
        return $this;
    }

    /** Cuts what its children draw off at the node's top and bottom only, as mygo's ClipY: a panel growing into view. */
    public function clipY(): static
    {
        $this->clip |= self::CLIP_Y;
        return $this;
    }

    /** Fixes a container's width and/or height (points) instead of its content's size; 0 leaves one to the content. */
    public function size(float $w = 0, float $h = 0): static
    {
        $this->fw = $w; $this->fh = $h;
        return $this;
    }

    /** A press on this node, or inside it, leaves the keyboard focus where it is (mygo's KeepFocus): a popup's rows, which the field or editor it completes keeps typing at. */
    public bool $keepFocus = false;

    public function keepFocus(): static
    {
        $this->keepFocus = true;
        return $this;
    }

    /** Lets this node take the keyboard focus like a button: Tab reaches it, a click gives it, Enter and Space click it, a ring shows it. */
    public function focusable(): static
    {
        $this->focusable = true;
        return $this;
    }

    /**
     * Has $fn(InputEvent): bool take the node's input as it comes, before
     * the next frame is built (mygo's HandleInput): keys, text and edit
     * commands while the node has the keyboard focus, the pointer pressed
     * on it, moving over it or scrolling over it. $fn reports whether it
     * took the event; one it leaves goes on as it would without $fn, to
     * shortcuts, Tab and scroll containers. A frame follows the events
     * $fn takes. For widgets that need every key as it is pressed, as a
     * code editor or a terminal; most widgets ask about their input as
     * they are built instead (clicked, shortcut, dragged).
     */
    public function handleInput(\Closure $fn): static
    {
        $this->input = $fn;
        return $this;
    }

    /** Whether the system's input methods compose text for the node while it has the focus (inputMethod). */
    public bool $inputMethod = true;

    /**
     * Sets whether the system's input methods compose text for the node
     * while it has the keyboard focus, as they do by default for one that
     * takes text (mygo's InputMethod). Turned off, keys type the
     * characters of the keyboard's layout, which come to its client as
     * text, and nothing is composed: a mode that reads letters as
     * commands, as Vim's normal mode, gets j as j with a Chinese input
     * method on.
     */
    public function inputMethod(bool $on): static
    {
        $this->inputMethod = $on;
        return $this;
    }

    /** Connects the node to the system's text-input services (mygo's HandleTextInput): $client takes what input methods compose and commit while the node has the focus, and places their candidate windows. */
    public function handleTextInput(TextInputClient $client): static
    {
        $this->textClient = $client;
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

    // What assistive technology, such as screen readers, sees of the node (mygo's access.go): its role and name, and the states of a widget of the app's own, as ARIA's on the web.

    /** A Role: AUTO finds it from what the node does. */
    public int $role = Role::AUTO;
    /** Names the node to assistive technology, as an icon button's (mygo's Label); a control with no label of its own is named by its text. */
    public string $label = '';
    /** Whether a check box, switch, radio button, toggle button or menu item is on: 0 unsaid, 1 off, 2 on, 3 partly on (mixed). */
    public int $checked = 0;
    /** Whether what the node opens shows, and whether it is an item of a tree with children, which expanding shows. */
    public bool $expanded = false, $expandable = false;
    /** What assistive technology reads as the node's value, as the choice a pop-up button shows. */
    public string $accValue = '';
    /** @var ?array{0: float, 1: float, 2: float} the range and the value of a slider, progress bar, meter or stepper: lo, hi, value */
    public ?array $accRange = null;
    /** How far the keys move the value of a range. */
    public float $accStep = 0.0;
    /** The rank of a heading, or how deep an item of a tree is, from 1; 0 for neither. */
    public int $level = 0;
    /** Tells more about the node than its name, read after it, as help: its tooltip unless set. */
    public ?string $description = null;
    /** The node that has the keyboard focus while this one does (activeDescendant). */
    public ?Node $activeDescendant = null;
    /** A segment of a segmented control; a slider going up; a text field for searching; a control whose value is not valid. */
    public bool $segment = false, $vertical = false, $search = false, $invalid = false;
    /** The header of the column a table's rows are sorted by: 1 ascending, 2 descending, 0 neither. */
    public int $sort = 0;
    /** Which option of how many the node is, from 1, 0 for unsaid; a popup whose options are chosen; a list choosing several of its items. */
    public int $setPos = 0, $setSize = 0;
    public bool $choosesItems = false, $chooseMany = false;
    /** An item its container chooses as it takes the focus, as a sidebar's: assistive technology focusing it chooses it. */
    public bool $choosable = false;
    /** Names the node when it has no label of its own, or before it ($nameJoin): a range slider names its knobs "minimum" and "maximum" of its own name. */
    public ?Node $nameFrom = null;
    public bool $nameJoin = false;
        /** @var array<int, int> for a list's node: the rows its children start, child index => row index, which assistive technology sees a node of each around (RuntimeAccess) */
    public array $rowStarts = [];
    /** The role of those rows: LIST_ITEM, or a table's ROW. */
    public int $rowRole = Role::LIST_ITEM;
    /** Fills in the node of the tree for the node, after the rest (mygo's Access): what it tells that the node does not. @var ?\Closure(AccessNode): void */
    public ?\Closure $accFill = null;

    /** Sets what the node is to assistive technology (a Role), for a node drawn as a widget it is not built from, such as a toggle of the app's own. */
    public function role(int $r): static
    {
        $this->role = $r;
        return $this;
    }

    /** Names the node to assistive technology (mygo's Label), as a button showing only an icon. */
    public function label(string $s): static
    {
        $this->label = $s;
        return $this;
    }

    /** Tells assistive technology whether the node, as a check box, switch, radio button, toggle button or menu item of the app's own, is on. */
    public function checked(bool $on): static
    {
        $this->checked = $on ? 2 : 1;
        return $this;
    }

    /** Tells assistive technology that the node, a check box, is partly on, as one checking a group whose boxes differ. */
    public function mixed(): static
    {
        $this->checked = 3;
        return $this;
    }

    /** Tells assistive technology whether what the node opens shows, as the popup of a button or the section below a header. */
    public function expanded(bool $open): static
    {
        $this->expanded = $open;
        return $this;
    }

    /** Sets what assistive technology reads as the node's value, as the choice a button opening a popup shows. */
    public function value(string $s): static
    {
        $this->accValue = $s;
        return $this;
    }

    /** Tells assistive technology the range and the value of a slider, progress bar, meter or stepper of the app's own: $value, from $lo to $hi; $step how far the keys move it. */
    public function range(float $lo, float $hi, float $value, float $step = 0.0): static
    {
        $this->accRange = [$lo, $hi, $value];
        if ($step > 0) { $this->accStep = $step; }
        return $this;
    }

    /** Tells assistive technology the rank of a heading, from 1 for the highest, or how deep an item of a tree is, from 1 at the top. */
    public function level(int $n): static
    {
        $this->level = max($n, 0);
        return $this;
    }

    /** Tells assistive technology that $d has the keyboard focus while this node does: the option the arrows are on in a list or a menu that keeps the focus itself, as a combobox's input does. */
    public function activeDescendant(Node $d): static
    {
        $this->activeDescendant = $d;
        return $this;
    }

    /** Has $fn fill in the node of the tree for this node, after the rest (mygo's Access), with what the node does not tell. @param \Closure(AccessNode): void $fn */
    public function access(\Closure $fn): static
    {
        $this->accFill = $fn;
        return $this;
    }
}
