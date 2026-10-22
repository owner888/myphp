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
    /** That min() set $minW, which is then the least width a row shrinks the container to, rather than its content's (CSS's min-width, as mygo's MinWidth). */
    public bool $minSet = false;
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
    /** @var ?array{0: float, 1: float, 2: float, 3: float} the padding of each side, top, right, bottom and left, when padding() set them apart; null for $padY and $padX */
    public ?array $pads = null;
    /** @var array{0: float, 1: float, 2: float, 3: float} the widths of the border on each side, inside which the content is (mygo's BorderWidth), in $borderColor */
    public array $borders = [0.0, 0.0, 0.0, 0.0];
    /** @var array{0: float, 1: float, 2: float, 3: float} the space around the node on each side, INF for an automatic margin (mygo's Margin) */
    public array $margins = [0.0, 0.0, 0.0, 0.0];
    /** How much the node gives up of its width when its row overflows, against the others' (mygo's Shrink): 1 by default, 0 for none. */
    public float $shrink = 1.0;
    /** The opacity of the background (background()). */
    public float $bgAlpha = 1.0;
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
    /** For rich text: the width its lines wrap at, given or its container's last frame ($wrapWidth); none for null. */
    public ?float $wrapWidth = null;
    /** That $wrapWidth is its container's last frame, so a frame follows when that differs. */
    public bool $wrapLast = false;
    /** That the node is built inside a rich text's children(), continuing its paragraph (mygo's isInline). */
    public bool $inline = false;
    /** That a paragraph's spans are those of the text inside it too (inlineSpans), as laid out. */
    private bool $merged = false;
    /** The text styling this node sets (fontSize(), font(), textColor()…), which the text inside it inherits, and, for text, what it inherited from the nodes around it as it was built. */
    public ?TextStyle $ts = null, $inherit = null;
    /** For rich text: what lays it out, and its layout once laid out (textLayout). */
    public ?TextMetrics $metrics = null;
    public ?\MyPHP\Text\Layout $layout = null;
    /** For text: at most this many lines, ending in $ellipsis ("…" when empty), 0 for any (maxLines); lines broken only at newlines (noWrap). */
    public int $maxLines = 0;
    public bool $noWrap = false;
    public string $ellipsis = '';
    /** The context building the node: a rich text's builds its children(), a Selectable container's has its selection. */
    public ?Context $context = null;
    /** Text, and the text inside a container, that the user selects and copies (selectable()), or none of it (unselectable()); what a Selectable container's Copy puts on the clipboard (copyData()). */
    public bool $selectable = false, $unselectable = false;
    /** That the node has a context menu (contextMenu()), or is a menu button (menu()). */
    public bool $contextMenu = false, $menuButton = false;
    public ?\Closure $copyData = null;
    /** For text: the Selectable container around it, as it was built, 0 for none; and the container whose selection it is in, as last recorded (its own id for a Selectable text alone). */
    public int $scope = 0, $textScope = 0;
    /** For a node inside a paragraph (a rich text's children()): the runes of its text in the paragraph's, and the boxes of its words in its layout, in window points. @var list<array{0: float, 1: float, 2: float, 3: float}> */
    public array $runes = [0, 0];
    public array $frags = [];
    /** A grid's column tracks and its first rows' (Track); null for a flex container. The rows beyond fit their content. */
    public ?array $gridCols = null;
    public array $gridRows = [];
    /** Where this node goes in its grid (mygo's gridCell): lines from 1, 0 for automatic placement, and spans, 0 for 1 and negative to the last line. */
    public int $cellCol = 0, $cellRow = 0, $colSpan = 0, $rowSpan = 0;

    /** A row that wraps its children onto more lines where they overflow $wrapWidth (mygo's Wrap), the width it had last frame. */
    public bool $wraps = false;
    /** Whether this node takes the keyboard focus (Tab, a click): see focusable(). */
    public bool $focusable = false;
    /** A focusable node Tab goes past, into what is inside it, which takes the focus from code alone (a router's page, mygo's focus target). */
    public bool $focusTarget = false;
    /** A router's page (mygo's flagPage), whose nodes' state lives while the router keeps the page; inert, what is in it takes neither the pointer nor the keyboard, and assistive technology does not see it (a page going away). */
    public bool $page = false, $inert = false;
    /** Drags $dragValue within the window once the pointer pressing it moves a few points (Context::drag); takes values of type $accepts dragged onto it (Context::drop, dragOver). */
    public bool $drags = false;
    public mixed $dragValue = null;
    public ?string $accepts = null;
    /** A native drag source (Context::dragData): what makes its data as the drag starts, and its options; a native drop target's options (Context::dropData); whether it takes files dragged from other apps (Context::droppedFiles). @var ?array{0: \Closure(): \MyPHP\Transfer\Data, 1: \MyPHP\Transfer\DragOptions} */
    public ?array $dataSource = null;
    public ?\MyPHP\Transfer\DropOptions $dataTarget = null;
    public bool $takesFiles = false;
    /** Moved by the pointer from its place in the layout (Context::draggable): its offset joins its pose. */
    public bool $draggable = false;
    /** Lays a row out from right to left and a column from bottom to top, as CSS's row-reverse and column-reverse (mygo's Reverse): justify()'s START is then the right or the bottom. A check box or radio button puts its box after its label, a progress bar fills from the right. Rows that wrap and grids keep their order. */
    public bool $reverse = false;
    /** A control that does nothing for now (mygo's Disabled): it takes no focus, and assistive technology hears it is disabled. */
    public bool $disabled = false;
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
    /** The pose the node animates to (animatePose) and how, and what its pose shows now: the map after its transform, and how faded it is (Runtime sets them). */
    public ?Pose $pose = null;
    public ?Animation $poseAnim = null;
    public Affine $poseM;
    public float $poseFade = 0.0;
    /** How the node's content changes (contentTransition). */
    public ?ContentTransition $content = null;
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
        $this->poseM = $this->world;
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

    /**
     * Moves the node to pose $p as $a says, after its own transform, and
     * fades it (mygo's AnimatePose): the cheapest motion there is, as Core
     * Animation runs transforms and opacity. The pointer follows it as it
     * follows transforms. It starts at the first pose. While the desktop
     * asks for less motion, it moves at once, and only fades smoothly.
     *
     *     $card->animatePose(new Pose(y: $lift, scale: 1.02), Animation::snappy());
     */
    public function animatePose(Pose $p, Animation $a): static
    {
        foreach ($p->vals() as $v) {
            if (!is_finite($v)) {
                throw new \InvalidArgumentException('transform values must be finite');
            }
        }
        if ($a->duration < 0 || $a->delay < 0) {
            throw new \InvalidArgumentException('animation durations must not be negative');
        }
        if ($a->duration == 0) {
            $a->spring->physics(); // throws now for invalid options
        }
        $this->pose = $p; $this->poseAnim = $a;
        return $this;
    }

    /** Animates the node's content as it changes, as $t says (mygo's ContentTransition): the text of a text, the icon of an icon, the picture of an image. While the desktop asks for less motion, content fades without moving. */
    public function contentTransition(ContentTransition $t): static
    {
        $this->content = $t;
        return $this;
    }

    /** The node's map from its layout to the window: its transform around its origin, after its ancestors'. */
    public function transformAt(): Affine
    {
        $m = $this->poseM->mul($this->transform->flat()); // its pose acts after its own transform
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
    /** See $reverse. */
    public function reverse(): static
    {
        $this->reverse = true;
        return $this;
    }

    public function grayscale(): static
    {
        $this->gray = true;
        return $this;
    }

    /** Keeps this node at least $w wide and $h high, whatever its content measures (mygo's MinWidth and MinHeight); 0 leaves one to the content. */
    public function min(float $w = 0, float $h = 0): static
    {
        $this->minW = $w; $this->minH = $h; $this->minSet = true;
        return $this;
    }

    /** Keeps this text to one line: where its row or column is short of its width it shrinks and ends in "…" (mygo's SingleLine). */
    public function singleLine(): static
    {
        $this->single = true;
        return $this;
    }

    private function ts(): TextStyle
    {
        $this->layout = null; // laid out anew with the style
        return $this->ts ??= new TextStyle();
    }

    /** Sets the size of text in points, for the node's text and its descendants' (mygo's FontSize). */
    public function fontSize(float $v): static { $this->ts()->size = $v; return $this; }

    /** Sets the weight of text from 100 (thin) to 900 (black). */
    public function fontWeight(int $w): static { $this->ts()->weight = $w; return $this; }

    /** Sets a bold font weight. */
    public function bold(): static { return $this->fontWeight(700); }

    /** Sets an italic font. */
    public function italic(): static { $this->ts()->italic = true; return $this; }

    /**
     * Sets the font family, a comma-separated list: the first family the
     * system has draws the text, and the others, in order, what it lacks,
     * before the system's choice. "monospace" and "system-ui" are the
     * system's own fonts (mygo's Font).
     */
    public function font(string $family): static { $this->ts()->family = $family; return $this; }

    /** Sets the color of text, 0xRRGGBB, at $alpha. */
    public function textColor(int $rgb, float $alpha = 1.0): static
    {
        $t = $this->ts(); $t->color = $rgb; $t->alpha = $alpha;
        return $this;
    }

    /** Sets the height of lines of text as a multiple of the font size. */
    public function lineHeight(float $m): static { $t = $this->ts(); $t->lineHeight = $m; $t->fixedLine = false; return $this; }

    /** Sets the height of lines of text in points, whatever the font size, as for rows of text that line up with a grid. */
    public function fixedLineHeight(float $v): static { $t = $this->ts(); $t->lineHeight = $v; $t->fixedLine = true; return $this; }

    /** Aligns the lines of text: Node::START, CENTER or END. */
    public function textAlign(int $a): static { $this->ts()->align = $a; return $this; }

    /** Underlines text. */
    public function underline(): static { $t = $this->ts(); $t->underline = true; $t->wavy = false; return $this; }

    /** Underlines text with a wave, as spell checkers mark words. */
    public function wavyUnderline(): static { $t = $this->ts(); $t->underline = true; $t->wavy = true; return $this; }

    /** Sets the color of underlines and strikethroughs, the text's otherwise. */
    public function decorationColor(int $rgb): static { $this->ts()->decoColor = $rgb; return $this; }

    /** Sets the thickness of underlines and strikethroughs in points, which follows the font size otherwise. */
    public function decorationThickness(float $v): static { $this->ts()->decoThick = $v; return $this; }

    /** Fills the lines of text behind it with $rgb, as a highlight. */
    public function textBackground(int $rgb): static { $this->ts()->background = $rgb; return $this; }

    /** Strikes text through. */
    public function strikethrough(): static { $this->ts()->strike = true; return $this; }

    /** Adds $v points after every character of text, or tightens it with a negative $v, as for labels in capitals. */
    public function letterSpacing(float $v): static { $this->ts()->spacing = $v; return $this; }

    /** Turns on OpenType features of the font, by tag, or sets them with tag=value, as "tnum" (digits of one width) or "liga=0"; a font without a feature ignores it. */
    public function fontFeatures(string ...$features): static { $this->ts()->features = implode(',', $features); return $this; }

    /** Sets the language of the node's text, and of the text inside it, as a BCP 47 tag, as HTML's lang attribute does; "" takes that of the nodes around it. */
    public function lang(string $tag): static { $this->ts()->lang = $tag === '' ? null : $tag; return $this; }

    /** Sets the highlight of selected text in the node and the text inside it, $rgb at $alpha; the theme's selection otherwise. */
    public function selectionColor(int $rgb, float $alpha = 1.0): static { $t = $this->ts(); $t->selection = $rgb; $t->selectionAlpha = $alpha; return $this; }

    /** Shows at most $n lines of the node's text, ending it with an ellipsis. */
    public function maxLines(int $n): static { $this->maxLines = $n; $this->layout = null; return $this; }

    /** Keeps each line of the node's text whole, breaking it only at newlines, even where it overflows the node. */
    public function noWrap(): static { $this->noWrap = true; $this->layout = null; return $this; }

    /** Sets what ends text that maxLines() cuts, "…" by default. */
    public function ellipsis(string $s): static { $this->ellipsis = $s; $this->layout = null; return $this; }

    /**
     * Makes a text a rich text of one span, its text in what text() was
     * given over its text style, for a style text() does not measure
     * (TextStyle::needsLayout), maxLines() or noWrap().
     */
    public function toRich(): void
    {
        $this->spans = [$this->given($this->text ?? '')];
        $this->kind = self::RICH;
        $this->text = null;
        $this->layout = null;
    }

    /** The text of a rich text, that of the text inside it included. */
    public function plainText(): string
    {
        if ($this->kids !== [] && !$this->merged) {
            $this->spans = $this->inlineSpans();
            $this->merged = true;
        }
        return implode('', array_map(static fn(Span $s): string => $s->text, $this->spans));
    }

    /** $text in what text() was given apart from what the text inherited: a size, a color, Font bits and an underline. */
    private function given(string $text): Span
    {
        $s = new Span($text, style: $this->style & ~self::fontBits($this->inherit), underline: $this->underline);
        if (($this->ts?->size) === null && $this->fontSize != $this->inherit?->size) { $s->size = $this->fontSize; }
        if (($this->ts?->color) === null && $this->fg !== $this->inherit?->color) { $s->color = $this->fg; }
        return $s;
    }

    /** The Font bits of a weight of 600 or more, italics and a monospaced family in $t. */
    public static function fontBits(?TextStyle $t): int
    {
        if ($t === null) {
            return 0;
        }
        return (($t->weight ?? 0) >= 600 ? Font::BOLD : 0) | ($t->italic ? Font::ITALIC : 0) | ($t->family !== null && str_starts_with($t->family, 'monospace') ? Font::MONO : 0);
    }

    /**
     * Lays out a rich text, the text inside it included, once a frame: its
     * spans over its text style, wrapped at $wrapWidth (mygo's textLayout).
     */
    public function textLayout(): \MyPHP\Text\Layout
    {
        if ($this->layout !== null) {
            return $this->layout;
        }
        if ($this->kids !== [] && !$this->merged) {
            $this->spans = $this->inlineSpans();
            $this->merged = true;
        }
        $text = ''; $runes = 0; $spans = [];
        foreach ($this->spans as $sp) {
            $text .= $sp->text;
            $runes += mb_strlen($sp->text, 'UTF-8');
            $spans[] = $sp->textSpan($runes);
        }
        $t = $this->textStyle();
        $p = new \MyPHP\Text\Params($text, $t->style($this->fontSize), $this->wrapWidth ?? 0, $this->maxLines, $this->ellipsis, $this->noWrap, $t->align ?? self::START, spans: $spans);
        return $this->layout = $this->metrics->textSystem()->layout($p);
    }

    /**
     * Lets the user select and copy text by dragging, double-clicking a
     * word, triple-clicking a line, or using Shift with the arrows; Cmd+C
     * copies the selection (mygo's Selectable). On a container, the text
     * built inside it shares one selection: Copy joins their selected text
     * with newlines, in the order they were built. Nested Selectable
     * containers have selections of their own; text inside controls keeps
     * the control's interaction, and unselectable() leaves a subtree out.
     * On a text alone, the selection stays in its paragraph. The text
     * inside a paragraph shares its selection: set it on the paragraph.
     */
    public function selectable(): static
    {
        if ($this->inline) {
            return $this;
        }
        if ($this->kind === self::TEXT || $this->kind === self::RICH || $this->kind === self::CONTAINER) {
            $this->unselectable = false;
            $this->selectable = true;
        }
        return $this;
    }

    /** Leaves text, or a container's subtree, out of the selection of a Selectable container around it; a Selectable container inside it has its own (mygo's Unselectable). For the text inside a paragraph, set it on the paragraph. */
    public function unselectable(): static
    {
        if ($this->inline) {
            return $this;
        }
        $this->selectable = false;
        $this->unselectable = true;
        return $this;
    }

    /**
     * The parts of the paragraphs that the selection of this Selectable
     * container holds, in the order they were built, or none, as of the
     * last frame: to copy them otherwise, as a context menu's item does
     * (mygo's SelectedTexts).
     * @return list<SelectedText>
     */
    public function selectedTexts(): array
    {
        return $this->context?->runtime?->selectedTexts($this->id) ?? [];
    }

    /**
     * Sets what Copy and its shortcut put on the clipboard for the
     * selection of this Selectable container: $fn gets its parts, as
     * selectedTexts() returns them, and returns the data, as a document
     * copies its paragraphs with blank lines between them as text, and as
     * HTML too to keep their formatting (mygo's CopyData).
     * @param \Closure(list<SelectedText>): \MyPHP\Transfer\Data $fn
     */
    public function copyData(\Closure $fn): static
    {
        $this->copyData = $fn;
        return $this;
    }

    /**
     * Scrolls the containers around the node as little as shows it, once
     * the frame is laid out: a node added in this frame comes into view
     * with it. Call it in the frame it should come into view, as when it is
     * added or chosen: in every frame, it would keep the user from scrolling
     * it away (mygo's ScrollIntoView).
     */
    public function scrollIntoView(): static
    {
        if ($this->context !== null && !in_array($this->id, $this->context->reveal, true)) {
            $this->context->reveal[] = $this->id;
        }
        return $this;
    }

    /**
     * Gives the node a context menu, which $build fills with items (mygo's
     * ContextMenu). The system shows it where the node is clicked with the
     * secondary button, or Control-clicked, and, while the node or one
     * inside it has the focus, below it for the menu key or Shift+F10.
     * $build runs when the menu opens, and again in the frame after an item
     * was chosen, where the item's chosen() reports it:
     *
     *     $row->contextMenu(function (Menu $m) use ($app, $i) {
     *         if ($m->item('Rename')->chosen()) { $app->renaming = $i; }
     *         $m->separator();
     *         if ($m->item('Delete')->disabled($app->locked)->chosen()) { $app->delete($i); }
     *     });
     *
     * The innermost node with a context menu gets the click. Text inputs and
     * selectable text have one with their editing commands, which this
     * replaces: on a text input, on a selectable text, or on the Selectable
     * container whose text was clicked; Menu::editItems() puts those
     * commands in a menu of one's own.
     * @param \Closure(Menu): void $build
     */
    public function contextMenu(\Closure $build): static
    {
        $this->contextMenu = true;
        $this->context?->runtime?->buildMenu($this->id, $this->disabled, false, $build);
        return $this;
    }

    /**
     * Makes the node a menu button: it opens a menu below it, which $build
     * fills with items, as the pointer goes down on it, as a pop-up button
     * does, and for Enter, Space or Down while it has the focus. It takes the
     * focus. $build runs as for contextMenu() (mygo's Menu).
     * @param \Closure(Menu): void $build
     */
    public function menu(\Closure $build): static
    {
        $this->menuButton = true;
        $this->focusable = true;
        if ($this->role === Role::AUTO || $this->role === Role::BUTTON) {
            $this->role = Role::MENU_BUTTON;
        }
        $this->context?->runtime?->buildMenu($this->id, $this->disabled, true, $build);
        return $this;
    }

    /**
     * Builds the text inside a rich text: the nodes $fn builds continue
     * its paragraph (Context::richText; mygo's Children on a text).
     */
    public function children(\Closure $fn): static
    {
        if ($this->kind === self::TEXT && $this->context !== null) { // a text with text inside it is a paragraph (mygo's Text with Children), as a link around a rich text
            $this->toRich();
            $this->fw = $this->fh = 0.0;
        }
        if ($this->kind !== self::RICH || $this->context === null) {
            throw new \LogicException('children() is for text and rich text: containers take theirs until end()');
        }
        $this->context->within($this, $fn);
        return $this;
    }

    /** The text style the node's text takes: its own over what it inherited. */
    public function textStyle(): TextStyle
    {
        return ($this->ts ?? new TextStyle())->over($this->inherit);
    }

    /**
     * The spans of a rich text, its own then those of the text nodes inside
     * it (a paragraph's), each in the style that node sets over the
     * paragraph's, and each such node given the runes of its text (mygo's
     * inlineSpans and inlineText).
     *
     * @return list<Span>
     */
    public function inlineSpans(): array
    {
        $out = $this->kind === self::TEXT ? [new Span($this->text ?? '')] : $this->spans;
        $n = 0;
        foreach ($out as $sp) { $n += mb_strlen($sp->text, 'UTF-8'); }
        foreach ($this->kids as $k) {
            $spans = $k->inlineSpans();
            $base = $k->spanStyle();
            $m = 0;
            foreach ($spans as $sp) {
                $out[] = $sp->over($base);
                $m += mb_strlen($sp->text, 'UTF-8');
            }
            $k->runes = [$n, $n + $m];
            $n += $m;
        }
        return $out;
    }

    /** The text style the node sets, as a span (mygo's spanStyle): a text node inside a paragraph its size, color and style. */
    private function spanStyle(): Span
    {
        $t = $this->ts ?? new TextStyle();
        $s = new Span('', $t->size, 0, $t->color, $t->underline ?? false, $t->strike ?? false, $t->background, $t->family, $t->weight, $t->italic ?? false, $t->alpha,
            wavyUnderline: $t->wavy ?? false, decorationColor: $t->decoColor, decorationThickness: $t->decoThick, letterSpacing: $t->spacing, features: $t->features, lang: $t->lang);
        if ($this->kind === self::TEXT || $this->kind === self::RICH) { // what text() or a link was given, as a link's color
            $s = $s->over($this->given(''));
        }
        if ($t->background === null && $this->color !== null) { // the background of an inline node is that of its text
            $s->background = $this->color;
        }
        if ($this->disabled || $this->opacity < 1) { // what dims the node dims its text
            $s->alpha = ($s->alpha ?? 1.0) * ($this->disabled ? 0.5 : 1.0) * $this->opacity;
        }
        return $s;
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
    public function padding(float ...$v): static
    {
        $this->pads = self::edges($v);
        $this->padY = ($this->pads[0] + $this->pads[2]) / 2; $this->padX = ($this->pads[1] + $this->pads[3]) / 2;
        return $this;
    }

    /** Sets the left and right padding (mygo's PaddingX). */
    public function paddingX(float $v): static { $p = $this->sides(); return $this->padding($p[0], $v, $p[2], $v); }

    /** Sets the top and bottom padding (mygo's PaddingY). */
    public function paddingY(float $v): static { $p = $this->sides(); return $this->padding($v, $p[1], $v, $p[3]); }

    /** Sets the padding on the side lines start: the left (mygo's PaddingStart; myphp lays out left to right). */
    public function paddingStart(float $v): static { $p = $this->sides(); return $this->padding($p[0], $p[1], $p[2], $v); }

    /** The padding of each side, top, right, bottom and left. @return array{0: float, 1: float, 2: float, 3: float} */
    private function sides(): array
    {
        return $this->pads ?? [$this->padY ?? $this->pad, $this->padX ?? $this->pad, $this->padY ?? $this->pad, $this->padX ?? $this->pad];
    }

    /** Four sides CSS style from one to four values: all, vertical and horizontal, top, horizontal and bottom, or top, right, bottom and left (mygo's edges). @param list<float> $v @return array{0: float, 1: float, 2: float, 3: float} */
    private static function edges(array $v): array
    {
        return match (count($v)) {
            1 => [$v[0], $v[0], $v[0], $v[0]],
            2 => [$v[0], $v[1], $v[0], $v[1]],
            3 => [$v[0], $v[1], $v[2], $v[1]],
            4 => [$v[0], $v[1], $v[2], $v[3]],
            default => [0.0, 0.0, 0.0, 0.0],
        };
    }

    /** The padding at the top and bottom, and at the left and right, the border inside the node's edges included: the mean of the two sides where they differ. */
    public function padY(): float { return ($this->padT() + $this->padB()) / 2; }
    public function padX(): float { return ($this->padL() + $this->padR()) / 2; }

    /** The room between the node's edge and its content on each side: its border and its padding. */
    public function padT(): float { return ($this->pads[0] ?? $this->padY ?? $this->pad) + $this->borders[0]; }
    public function padR(): float { return ($this->pads[1] ?? $this->padX ?? $this->pad) + $this->borders[1]; }
    public function padB(): float { return ($this->pads[2] ?? $this->padY ?? $this->pad) + $this->borders[2]; }
    public function padL(): float { return ($this->pads[3] ?? $this->padX ?? $this->pad) + $this->borders[3]; }

    /**
     * Sets the space around the node, as padding() does (mygo's Margin);
     * Node::AUTO, an automatic margin, takes the free space on its side, as
     * in CSS: margin(0, Node::AUTO) centers a node horizontally, and a row's
     * child with margin(0, 0, 0, Node::AUTO) goes to the end with those
     * after it.
     */
    public function margin(int|float ...$v): static
    {
        $this->margins = self::edges(array_map(static fn(int|float $m): float => $m === self::AUTO ? INF : (float)$m, $v));
        return $this;
    }

    /** Sets the left and right margins (mygo's MarginX). */
    public function marginX(int|float $v): static { $m = $v === self::AUTO ? INF : (float)$v; $this->margins[1] = $this->margins[3] = $m; return $this; }

    /** Sets the top and bottom margins (mygo's MarginY). */
    public function marginY(int|float $v): static { $m = $v === self::AUTO ? INF : (float)$v; $this->margins[0] = $this->margins[2] = $m; return $this; }

    /** Sets the widths of the border on each side, as padding() does, inside which the content is (mygo's BorderWidth), in borderColor(). */
    public function borderWidth(float ...$v): static
    {
        $this->borders = self::edges($v);
        return $this;
    }

    /** Sets the width of the border on the side lines start: the left (mygo's BorderStartWidth). */
    public function borderStartWidth(float $v): static { $this->borders[3] = $v; return $this; }

    /** Sets the color of the border (mygo's BorderColor). */
    public function borderColor(int $rgb): static { $this->borderColor = $rgb; return $this; }

    /** Fills the node's box with $rgb at $alpha (mygo's Background). */
    public function background(int $rgb, float $alpha = 1.0): static
    {
        $this->color = $rgb; $this->bgAlpha = $alpha;
        return $this;
    }

    /** Sets how much the node gives up of its width when its row overflows, against the others (mygo's Shrink): 0 keeps its width. */
    public function shrink(float $s): static { $this->shrink = $s; return $this; }

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

    /** Gives the node the keyboard focus in the frame it appears, as the first field of a dialog (mygo's AutoFocus); it makes it focusable. */
    public function autoFocus(): static
    {
        $this->focusable = true;
        $rt = $this->context?->runtime;
        if ($rt !== null && $rt->rectOf($this->id) === null) {
            $rt->focus = $this->id;
        }
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
