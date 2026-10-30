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
    public const CONTAINER = 0, TEXT = 1, BUTTON = 2, BOX = 3, INPUT = 4, TEXTAREA = 5, IMAGE = 6, RICH = 7, CHECKBOX = 8, RADIO = 9, SLIDER = 10, PROGRESS = 12, SWITCH = 13, SPINNER = 14;
    public const ROW = 0, COLUMN = 1;
    /** Alignments for align(), alignSelf() and justify(); AUTO is a row's CENTER and a column's STRETCH across, START along. */
    public const AUTO = -1, START = 0, CENTER = 1, END = 2, STRETCH = 3;
    /** How justify() and alignContent() share the free room: between the children, around each, or evenly with the edges (mygo's SpaceBetween, SpaceAround, SpaceEvenly). */
    public const SPACE_BETWEEN = 4, SPACE_AROUND = 5, SPACE_EVENLY = 6;
    /**
     * For align() and alignSelf() (mygo's Baseline): lines up the first lines of text of a row's children, as a large
     * price beside its small unit, and of the items of each row of a grid; the row is as high as the highest baseline
     * above and the deepest descent below take. A box's baseline is its first child's (in a row, its first child's
     * aligned by its baseline); one without text lines up its bottom. Across a column it lines up the starts, as Chrome.
     */
    public const BASELINE = 7;
    /** How this container places its children across its axis (align) and along it when none grows (justify), and how it sits across its parent's (self). */
    public int $align = self::AUTO, $self = self::AUTO, $justify = self::START;
    /** How a grid places its children in their cells across (Node::justifyItems), and how this child is placed over that (justifySelf): STRETCH unless said. */
    public int $justifyItems = self::AUTO, $justifySelf = self::AUTO;

    /** Order of creation in the build, or a hash of its key path; what Runtime remembers rects by. */
    public int $id = 0;
    /** The key Context::key gave the node, null for none: the automation plugin finds elements by it (mygo's node key). @internal */
    public string|int|null $key = null;
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
    /** A list's header pinned at its top (ListState::$header), which paints over its rows, and the header row after it that pushes it away; Context::list sets them, Layout places it. */
    public ?Node $pinned = null, $pinStop = null;
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
    /** @var list<array{0: float, 1: float, 2: float, 3: float, 4: int, 5: float, 6: bool}> the box shadows, outside (shadow) and inside (insetShadow), in the order added: x, y, blur, spread, color, alpha, inset */
    public array $shadows = [];
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
    /** The pointer over this node, or null for the arrow (mygo's Cursor). */
    public ?Cursor $cursor = null;
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
    /** That the app gave the node its width (width(), size()), which a column's stretch keeps: a button's own is its label's. */
    public bool $sizedW = false;
    /** That $wrapWidth is the width layout gave the text, narrower than its lines (Layout::wrapTexts, mygo's text wrapping at its width). */
    public bool $autoWrap = false;
    /** For rich text that wraps at no width: the width it was laid out across, wider than its lines, which an alignment or the direction places them in (Layout::arrange); 0 for none. */
    public float $alignWidth = 0.0;
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
    /** The web view the node shows (Context::webView): a MyPHP\WebView, which this package knows only through the window. */
    public ?object $webView = null;
    /** The player whose picture the node shows (Context::video): a MyPHP\Media\Player, which the window places. */
    public ?object $video = null;
    /** The system's material the node's box shows (systemMaterial()): its name and appearance, dark, light or null for the app's. @var ?array{0: string, 1: ?bool} */
    public ?array $systemMaterial = null;
    public ?string $accepts = null;
    /** A native drag source (Context::dragData): what makes its data as the drag starts, and its options; a native drop target's options (Context::dropData); whether it takes files dragged from other apps (Context::droppedFiles). @var ?array{0: \Closure(): \MyPHP\Transfer\Data, 1: \MyPHP\Transfer\DragOptions} */
    public ?array $dataSource = null;
    public ?\MyPHP\Transfer\DropOptions $dataTarget = null;
    public bool $takesFiles = false;
    /** Moved by the pointer from its place in the layout (Context::draggable): its offset joins its pose. */
    public bool $draggable = false;
    /** Lays a row out from right to left and a column from bottom to top, as CSS's row-reverse and column-reverse (mygo's Reverse): justify()'s START is then the right or the bottom. A check box or radio button puts its box after its label, a progress bar fills from the right. Rows that wrap and grids keep their order. */
    public bool $reverse = false;
    /** A control that does nothing for now (mygo's Disabled): it takes no focus, and assistive technology hears it is disabled; set on a container, it is painted at half its opacity. And whether a container around it was disabled as it was built (isDisabled). */
    public bool $disabled = false, $disabledIn = false;
    /** Whether the node ignores the pointer and the keyboard (ignoreInput), and whether a node around it does, as it was recorded. @internal */
    public bool $ignoreInput = false, $ignoringIn = false;
    /** Keeps its room in the layout but paints nothing and, with what is inside it, takes neither the pointer nor the focus (mygo's Invisible); outlined with what is inside it, margins, padding and content (mygo's Debug); passes the pointer to what is under it (mygo's PassThrough). */
    public bool $invisible = false, $debug = false, $passThrough = false;
    /** Taken out of the layout (hidden()); and, set by Layout, given no room, it or a node around it hidden: what is attached to it hides too. */
    public bool $hidden = false, $gone = false;
    /** @internal A sibling, laid out in the parent's flow, whose box an absolute node's insets are measured from instead of the parent's: a split keeps its handle on its divider (mygo's placeOn). */
    public ?Node $placeOn = null;
    /** @internal belowMaterials() */
    public bool $belowMaterials = false;
    /** The corners' radii where they differ (radius() of four): top left, top right, bottom right, bottom left; null for $radius at each. @var ?array{0: float, 1: float, 2: float, 3: float} */
    public ?array $radii = null;
    /** A gradient filling the node in place of its color (gradient, linearGradient, radialGradient, conicGradient), or stripes over its color (stripes): [color, alpha, width, gap, angle]. */
    public LinearGradient|RadialGradient|ConicGradient|null $gradient = null;
    /** The gradient painting the node's border in place of its color (borderLinearGradient, borderConicGradient), or null. */
    public LinearGradient|ConicGradient|null $borderGradient = null;
    /** The standard deviation of the node's blur, with its children, in points (blur); 0 for none. */
    public float $blur = 0.0;
    /** @internal Painter::paintBlurred: painted into its blur's canvas now */
    public bool $blurring = false;
    /** Whether what the node paints, with its children, is kept in a bitmap (cache). */
    public bool $cache = false;
    /**
     * @internal its ink, in the window, all that it and the nodes inside it
     * may paint, as Runtime::record found it: [x0, y0, x1, y1], null before
     * it is recorded; unless $noCull, painting skips it where its ink is
     * clipped out (mygo's ink, noCull)
     * @var ?array{0: float, 1: float, 2: float, 3: float}
     */
    public ?array $ink = null;
    public bool $noCull = true;
    /** The effect the node draws through in place of drawing as it is (effect), or null. */
    public ?\MyPHP\UI\Shader\Effect $effect = null;
    public ?array $stripes = null;
    /** Whether the border is solid or dashed (mygo's BorderStyle). */
    public BorderStyle $borderStyle = BorderStyle::Solid;
    /** Lines between the children (mygo's Dividers): [width, color, alpha], or null. */
    public ?array $dividers = null;
    /** A list's rows' least width (rowWidth), past which it scrolls sideways; and the scroll container whose sideways scrolling this node's content follows (scrollWith). */
    public float $rowMinW = 0;
    /** An input without a look (Context::textInputBase, textAreaBase): no padding around its text. */
    public bool $bare = false;
    /** An option the pointer or the arrows are on (SelectParts::item, ComboboxParts::item; mygo's Highlighted). */
    public bool $highlighted = false;
    /** A step slider's tick marks (Context::stepSlider), 0 for none. */
    public int $ticks = 0;
    public ?Node $followX = null;
    /** A list built in a scrolling container: as high as its rows. @internal */
    public bool $sizedByRows = false;
    /** Fits a table's column to its cells as the table is measured, once (mygo's colFit). @internal */
    public ?\Closure $colFit = null;
    /** @var list<TextRange> the runs of an input's text styled this frame (textRanges) */
    public array $ranges = [];
    /** How far a scroll container's bars keep in from its edges, top, right, bottom and left (mygo's ScrollbarInsets). @var array{0: float, 1: float, 2: float, 3: float} */
    public array $barInsets = [0.0, 0.0, 0.0, 0.0];
    /** For a checkbox or radio: whether it is on; for a slider: its value as a share of the range, and whether it is held. */
    public bool $on = false;
    /** Drawn over the children (mygo's DrawOver): a list's drop line. */
    public ?\Closure $drawOver = null;
    /** Paints the inline objects of a rich text (drawObjects): fn(Painter, int $at, array $box). */
    public ?\Closure $drawObjects = null;
    /** clipMask's: the image whose coverage cuts the node and what is inside it, over its box (null: the box itself), and whether inverted. @var ?array{0: ?Image, 1: bool} */
    public ?array $clipMask = null;

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
    /** Whether a control changed its value this frame: Context::changed(); whether Escape or a press outside closed this dialog or popover: Context::dismissed(). */
    public bool $changed = false, $dismissed = false;
    /** Shown in a tip when the pointer rests on the node: tooltip(). */
    public ?string $tooltip = null;
    /** For text: an underline (links). */
    public bool $underline = false;
    /** A button's label struck through (strikethrough()). */
    public bool $strike = false;
    /** For a link: where it goes; the pointer shows a hand over it. */
    public ?string $url = null;
    /** Placed at (ax, ay) from its parent's top left instead of in the flow, at its own size: at(). */
    public bool $absolute = false;
    public float $ax = 0, $ay = 0;
    /** Whether the node is laid out right to left (Direction), and whether it was given its direction rather than inheriting it. */
    public bool $rtl = false, $dirSet = false;
    /** Which of the horizontal edges were set by their start or end (LOG_*), which move to the other side when the direction changes. */
    public int $logical = 0;
    private const LOG_PAD_START = 1, LOG_PAD_END = 2, LOG_MARGIN_START = 4, LOG_MARGIN_END = 8, LOG_BORDER_START = 16, LOG_BORDER_END = 32, LOG_INSET_START = 64, LOG_INSET_END = 128;
    private const LOG_PAD = 3, LOG_MARGIN = 12, LOG_BORDER = 48, LOG_INSET = 192;
    /**
     * The insets from the sides of the parent, top, right, bottom and left
     * (mygo's Top, Right, Bottom, Left and their percentages): [points,
     * whether a percentage of the parent's padding box] or null. They place
     * an absolute node (absolute()); a node in the flow they move from where
     * the layout put it, relative to it, as CSS's position: relative.
     * @var array{0: ?array{0: float, 1: bool}, 1: ?array{0: float, 1: bool}, 2: ?array{0: float, 1: bool}, 3: ?array{0: float, 1: bool}}
     */
    public array $insets = [null, null, null, null];
    /** Width and height as fractions of the parent's content box (widthPercent(), fill()), null for their own; and the least and largest sizes so (min/maxWidthPercent…), and the basis (basisPercent). */
    public ?float $wPct = null, $hPct = null, $minWPct = null, $minHPct = null, $maxWPct = null, $maxHPct = null, $basisPct = null;
    /** The largest width and height, in points (maxWidth, maxHeight); INF for none. */
    public float $maxW = INF, $maxH = INF;
    /** Width over height, which sets the side not given (aspectRatio); 0 for none. */
    public float $aspect = 0;
    /** The gaps between columns and between rows, where they differ from $gap (gapX, gapY). */
    public ?float $gapX = null, $gapY = null;
    /** How the lines of a wrapping row share its height (alignContent): AUTO stacks them at its start; and whether they go from its bottom (wrapReverse). */
    public int $alignContent = self::AUTO;
    public bool $wrapReverse = false;
    /** Attached to a point of the parent's padding box, or of $attachTarget's box in the window (attach, attachTo): [the point there, the node's own point] as Anchor cases. @var ?array{0: Anchor, 1: Anchor} */
    public ?array $attach = null;
    public ?Node $attachTarget = null;
    /** @internal Context (combobox, tokenField): the input a field's box gives the focus to, which autoFocus() and focusBind() on the box go to (mygo's focusTo). */
    public ?Node $focusTo = null;
    /** Whether arrange() placed it this frame: what attachTo reads its target's box from. */
    public bool $laid = false;
    /** @internal Context::memo: 1 for a memo's node built this frame, 2 for one placed again as it was; and the frame it was last placed in. */
    public int $memo = 0, $placed = 0;
    /** A material painted in place of the color (Node::material). */
    public ?Material $material = null;
    /** For an image node: what it shows; for images and colored nodes, the corner radius and opacity. */
    public ?Image $image = null;
    /** For an image node, an SVG shown in its own colors instead; for an icon, the SVG it shows. */
    public ?SVG $svg = null;
    /** Degrees an icon's artwork is turned clockwise around its center (Node::rotate). */
    public float $rotation = 0;
    public float $radius = 0;
    public float $alpha = 1;
    /** What the node and those inside it paint with in place of the window's theme: the inspector's panel. */
    public ?Theme $theme = null;
    /** @var Node[] */
    public array $kids = [];
    /** Laid-out rectangle. */
    public float $x = 0, $y = 0, $w = 0, $h = 0;
    /** Measured intrinsic size. */
    public float $mw = 0, $mh = 0;
    /** @internal The measured size before the least and largest sizes, margins aside: the flex base size along a row or a column, which only the hypothetical size is clamped from (CSS flexbox §9.2). */
    public float $natW = 0, $natH = 0;
    /** @internal Its padding and border across and down, as Layout::measure found them this frame. */
    public float $padW = 0, $padH = 0;
    /** @internal Its fixed margins across and down, automatic ones as none, as Layout::measure found them this frame. */
    public float $marW = 0, $marH = 0;
    /** The least width the node shrinks to in a row (Layout: its min-content width, mygo's automatic minimum): a single-line text's minW, a container's children's least widths, a leaf's own width. */
    public float $minMw = 0;

    public function __construct(
        public int $kind,
        public int $dir,
        public float $pad,
        public float $gap,
        public float $fw,
        public float $fh,
        public float $grow,
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

    /**
     * Takes this node out of its parent's flow (mygo's Absolute): its
     * insets place it in the parent's padding box (top(), right(),
     * bottom(), left() and their percentages, start() and end() by the
     * direction), both of a pair setting the size between them; without
     * insets it sits at the top left, or the top right right to left.
     * Later siblings paint over earlier ones.
     */
    public function absolute(): static
    {
        $this->absolute = true;
        return $this;
    }

    /** The insets (mygo's Top, Right, Bottom, Left, and their percentages of the parent's padding box). */
    public function top(float $v): static { $this->insets[0] = [$v, false]; return $this; }
    public function right(float $v): static { $this->insets[1] = [$v, false]; $this->logical &= ~self::LOG_INSET; return $this; }
    public function bottom(float $v): static { $this->insets[2] = [$v, false]; return $this; }
    public function left(float $v): static { $this->insets[3] = [$v, false]; $this->logical &= ~self::LOG_INSET; return $this; }
    public function topPercent(float $p): static { $this->insets[0] = [$p / 100, true]; return $this; }
    public function rightPercent(float $p): static { $this->insets[1] = [$p / 100, true]; $this->logical &= ~self::LOG_INSET; return $this; }
    public function bottomPercent(float $p): static { $this->insets[2] = [$p / 100, true]; return $this; }
    public function leftPercent(float $p): static { $this->insets[3] = [$p / 100, true]; $this->logical &= ~self::LOG_INSET; return $this; }
    /** The inset on the side lines start, or end: the left and the right, or the reverse right to left (mygo's Start, End and their percentages). */
    public function start(float $v): static { return $this->logicalInset($this->startSide(), [$v, false], self::LOG_INSET_START); }
    public function end(float $v): static { return $this->logicalInset(4 - $this->startSide(), [$v, false], self::LOG_INSET_END); }
    public function startPercent(float $p): static { return $this->logicalInset($this->startSide(), [$p / 100, true], self::LOG_INSET_START); }
    public function endPercent(float $p): static { return $this->logicalInset(4 - $this->startSide(), [$p / 100, true], self::LOG_INSET_END); }

    private function logicalInset(int $side, array $v, int $bit): static
    {
        $this->insets[$side] = $v;
        $this->logical |= $bit;
        return $this;
    }

    /**
     * Places this absolute node with its point $self on the point $at of
     * its parent's padding box (mygo's Attach): Anchor::BottomRight on
     * Anchor::BottomRight puts it in the corner; its insets move it from
     * there. Start and End anchors follow the direction.
     */
    public function attach(Anchor $at, Anchor $self): static
    {
        $this->absolute = true;
        $this->attach = [$at, $self]; $this->attachTarget = null;
        return $this;
    }

    /**
     * Places this absolute node with its point $self on the point $at of
     * $target's box in the window, as a popover of it (mygo's AttachTo):
     * on the other side of $target, or the other way along it, where that
     * overflows the window less, then moved into the window along it, 4
     * points from its edges. Give it the window's root (Context::overlay)
     * as its parent, to show over everything.
     */
    public function attachTo(Node $target, Anchor $at, Anchor $self): static
    {
        $this->absolute = true;
        $this->attach = [$at, $self]; $this->attachTarget = $target;
        return $this;
    }

    /** Sets the width and the height, in points (mygo's Width, Height); 0 leaves one to the content. */
    public function width(float $v): static { $this->fw = $v; $this->wPct = null; $this->sizedW = $v > 0; return $this; }
    public function height(float $v): static { $this->fh = $v; $this->hPct = null; return $this; }
    /** Sets the width or height as a percentage of the parent's content box (mygo's WidthPercent, HeightPercent); fill() is both at 100, fillWidth() and fillHeight() one. */
    public function widthPercent(float $p): static { $this->wPct = $p / 100; return $this; }
    public function heightPercent(float $p): static { $this->hPct = $p / 100; return $this; }
    public function fill(): static { $this->wPct = $this->hPct = 1.0; return $this; }
    public function fillWidth(): static { $this->wPct = 1.0; return $this; }
    public function fillHeight(): static { $this->hPct = 1.0; return $this; }
    /** The least width and height (mygo's MinWidth, MinHeight); min() sets both. */
    public function minWidth(float $v): static { $this->minW = $v; $this->minSet = true; return $this; }
    public function minHeight(float $v): static { $this->minH = $v; return $this; }
    /** The largest width and height, which growing, stretching and the content stop at (mygo's MaxWidth, MaxHeight). */
    public function maxWidth(float $v): static { $this->maxW = $v; return $this; }
    public function maxHeight(float $v): static { $this->maxH = $v; return $this; }
    /** The least and largest sizes as percentages of the parent's content box (mygo's MinWidthPercent…). */
    public function minWidthPercent(float $p): static { $this->minWPct = $p / 100; return $this; }
    public function minHeightPercent(float $p): static { $this->minHPct = $p / 100; return $this; }
    public function maxWidthPercent(float $p): static { $this->maxWPct = $p / 100; return $this; }
    public function maxHeightPercent(float $p): static { $this->maxHPct = $p / 100; return $this; }
    /** The basis as a percentage of the parent's content box along its axis (mygo's BasisPercent). */
    public function basisPercent(float $p): static { $this->basisPct = $p / 100; $this->basis = null; return $this; }
    /** Keeps width over height at $r, the side not given following the other (mygo's AspectRatio): 16/9 for a video. */
    public function aspectRatio(float $r): static { $this->aspect = max($r, 0.0); return $this; }
    /** The gap between columns (a row's children, a grid's columns, the lines of a wrapping column) and between rows (mygo's GapX, GapY); gap() sets both. */
    public function gap(float $v): static { $this->gap = $v; $this->gapX = $this->gapY = null; return $this; }
    public function gapX(float $v): static { $this->gapX = $v; return $this; }
    public function gapY(float $v): static { $this->gapY = $v; return $this; }
    /** The gap along the container's axis, and across it, between its lines. */
    public function mainGap(): float { return ($this->dir === self::ROW ? $this->gapX : $this->gapY) ?? $this->gap; }
    public function crossGap(): float { return ($this->dir === self::ROW ? $this->gapY : $this->gapX) ?? $this->gap; }
    /** How the lines of a wrapping row share the room across it (mygo's AlignContent): START, CENTER, END, STRETCH or SPACE_*. */
    public function alignContent(int $a): static { $this->alignContent = $a; return $this; }

    /** Sets how much of the room left along its parent's axis this node takes, against its siblings (mygo's Grow): fractions share as numbers do. */
    public function grow(float $g): static
    {
        $this->grow = max($g, 0.0);
        return $this;
    }

    /** Fills this container with a material instead of a color, shaped by round() (mygo's Material): a MaterialBuilder builds the one it paints, following the node. */
    public function material(Material $material): static
    {
        if ($material instanceof MaterialBuilder && $this->context !== null) {
            $material = $material->buildMaterial($this->context, $this);
        }
        $this->material = $material;
        return $this;
    }

    /**
     * Fills the box with one of the system's materials, blurring what
     * shows under it in the window, a video's picture as well (which
     * myphp's own materials, Plugins\Glass, do not reach, as the system
     * draws it): one of WindowOptions::$vibrancy's names (hud, titlebar,
     * sidebar, menu, popover…), an NSVisualEffectView blending within the
     * window, or glass and clear-glass, macOS 26's Liquid Glass
     * (NSGlassEffectView). $dark sets its appearance, null the app's. The
     * node paints a hole through the window's content where the window
     * places the material, inside its border and rounded by its radius
     * (the largest of its corners'); its children draw over it. macOS only.
     */
    public function systemMaterial(string $material, ?bool $dark = null): static
    {
        if ($material !== 'glass' && $material !== 'clear-glass' && !isset(\MyPHP\Window::MATERIALS[$material])) {
            throw new \InvalidArgumentException("no system material \"$material\"");
        }
        $this->systemMaterial = [$material, $dark];
        $this->color = null;
        return $this;
    }

    /** Rounds this container's corners by $radius (containers are square by default). */
    public function round(float $radius): static
    {
        $this->radius = $radius; $this->radii = null;
        return $this;
    }

    /** Rounds the corners (mygo's Radius): one radius for all, or top left, top right, bottom right and bottom left; $radius is then the top left's. */
    public function radius(float ...$r): static
    {
        if (count($r) === 1) { return $this->round($r[0]); }
        if (count($r) !== 4) { throw new \InvalidArgumentException('Node::radius takes one radius or four, not ' . count($r)); }
        $this->radii = [(float)$r[0], (float)$r[1], (float)$r[2], (float)$r[3]]; $this->radius = $this->radii[0];
        return $this;
    }

    /** The radii of the corners: top left, top right, bottom right, bottom left. @return array{0: float, 1: float, 2: float, 3: float} */
    public function corners(): array
    {
        return $this->radii ?? [$this->radius, $this->radius, $this->radius, $this->radius];
    }

    /** Fills the node with a linear gradient from $from to $to, at $angle degrees clockwise from upwards as in CSS: 180 from top to bottom, 90 from left to right (mygo's Gradient). */
    public function gradient(int $from, int $to, float $angle): static
    {
        return $this->linearGradient(new LinearGradient($from, 1.0, $to, 1.0, $angle));
    }

    /** Fills the node with $g, in place of its color (mygo's LinearGradient). */
    public function linearGradient(LinearGradient $g): static
    {
        $this->gradient = $g; $this->stripes = null;
        return $this;
    }

    /** Fills the node with $g, its colors spreading out from a point, as a glow (mygo's RadialGradient, CSS's radial-gradient()), in place of its color. */
    public function radialGradient(RadialGradient $g): static
    {
        $this->gradient = $g; $this->stripes = null;
        return $this;
    }

    /**
     * Fills the node with a conic gradient, its colors turning around a
     * point (mygo's ConicGradient, CSS's conic-gradient()), in place of
     * its color:
     *
     *     $c->box(80, 80)->radius(40)->conicGradient(new ConicGradient([
     *         new GradientStop(0xff0000, 0), new GradientStop(0xffff00, 1 / 3), new GradientStop(0x0000ff, 2 / 3), new GradientStop(0xff0000, 1),
     *     ]));
     */
    public function conicGradient(ConicGradient $g): static
    {
        $this->gradient = $g; $this->stripes = null;
        return $this;
    }

    /** Paints the node's border (border, borderWidth) with a linear gradient across the node, in place of its color (mygo's BorderLinearGradient). */
    public function borderLinearGradient(LinearGradient $g): static
    {
        $this->borderGradient = $g;
        return $this;
    }

    /**
     * Paints the node's border (border, borderWidth) with a conic gradient
     * around the node, in place of its color: a ring of colors, as
     * SwiftUI's strokeBorder with an AngularGradient draws (mygo's
     * BorderConicGradient).
     */
    public function borderConicGradient(ConicGradient $g): static
    {
        $this->borderGradient = $g;
        return $this;
    }

    /**
     * Blurs the node, with its children, by $sigma points, the standard
     * deviation of a Gaussian, as CSS's filter: blur() does (mygo's Blur):
     * what it draws spreads that far around, transparent beyond, on the
     * GPU. It renders into a canvas first, about 3 sigma larger each way.
     * Hit testing, focus and accessibility do not change: only what shows.
     */
    public function blur(float $sigma): static
    {
        $this->blur = max($sigma, 0.0);
        return $this;
    }

    /**
     * Draws the node, with its children, through $fx, the effect of a
     * shader (Shader::effect), in place of drawing it as it is; null draws
     * it as it is (mygo's Effect), as SwiftUI's colorEffect,
     * distortionEffect and layerEffect do:
     *
     *     $c->text('Wavy', 24)->effect($wave->effect(new Options(uniforms: ['Time' => $t], maxSampleOffset: [0, 8])));
     *
     * What the node renders, with its children, shadows and what its draw
     * callbacks paint, renders into a canvas of the size of its box and the
     * effect's margin (Effect::effectMargin) around it, every frame it
     * paints, which the effect then draws over the box and the margin,
     * transformed, clipped and faded as the node would be. Hit testing,
     * focus and accessibility do not change: only what shows. Web views
     * inside it do not show through an effect.
     */
    public function effect(?\MyPHP\UI\Shader\Effect $fx): static
    {
        $this->effect = $fx;
        return $this;
    }

    /**
     * Keeps what the node paints, with its children, in a bitmap of the
     * GPU that frames draw as one image (mygo's Cache), as Flutter's
     * RepaintBoundary and SwiftUI's drawingGroup() do: for a still subtree
     * that costs much to draw, as a card of gradients, blurred images and
     * large shadows, under or around a drawing that moves. The window's
     * frame then draws one image there, and, as the bitmap does not change,
     * redraws only where something else did; moving, scaling or rotating
     * the node (a transform, a pose) moves the bitmap, not what it holds.
     *
     *     $c->column()->size(900, 212)->radius(16)->shadow(0, 10, 40, 0, $shade)->cache();
     *
     * The bitmap is the size of the box at the window's scale, and of what
     * it and those inside it paint around it: shadows, focus rings, and
     * children beyond the box that it does not clip (what draw callbacks
     * paint, within the box and the shadows' reach). Each frame still
     * paints the subtree, to tell: the bitmap is drawn again when what it
     * paints differs, built with other content, laid out at another size,
     * at another scale or in another theme, an image in it updated, or as
     * something inside it moves on its own (animationFrame, a pose, a
     * transition), as then it is every frame. With blur() or effect(),
     * their bitmap is the one kept. Web views inside it do not show, and a
     * subtree larger than 8192 pixels across paints as it is. Drawn at the
     * window's scale, it shows a little softer turned or scaled up.
     */
    public function cache(): static
    {
        $this->cache = true;
        return $this;
    }

    /** @internal how far the node paints around its box: its shadows' reach and 4 points (a focus ring), as its ink (Runtime::setInk) */
    public function inkMargin(): float
    {
        $m = 4.0;
        foreach ($this->shadows as [$sx, $sy, $blur, $spread, , , $inset]) {
            if (!$inset) { $m = max($m, abs($sx) + abs($sy) + $blur + $spread + 4); }
        }
        return $m;
    }

    /** Draws stripes of $rgb at $alpha over the background, $width points wide with $gap points between them, at $angle degrees clockwise from upwards: 0 vertical, 90 horizontal, 45 slanting like slashes (mygo's Stripes). */
    public function stripes(int $rgb, float $width, float $gap, float $angle, float $alpha = 1.0): static
    {
        $this->stripes = [$rgb, $alpha, $width, $gap, $angle]; $this->gradient = null;
        return $this;
    }

    /** Sets whether the border is solid, as by default, or dashed (mygo's BorderStyle). */
    public function borderStyle(BorderStyle $s): static
    {
        $this->borderStyle = $s;
        return $this;
    }

    /** Draws a line $width points thick in $rgb between each two children of a row or a column (between a list's rows), across it inside its border, in the middle of the room between them, which a gap() should give; a wrapping row draws them between the children of each line, a grid none (mygo's Dividers). */
    public function dividers(float $width, int $rgb, float $alpha = 1.0): static
    {
        $this->dividers = $width > 0 && $alpha > 0 ? [$width, $rgb, $alpha] : null;
        return $this;
    }

    /** Adds a box shadow, offset by $x and $y, blurred by $blur and grown by $spread points, in $rgb at $alpha; as CSS's box-shadow it shows only outside the box (mygo's Shadow). */
    public function shadow(float $x, float $y, float $blur, float $spread, int $rgb, float $alpha = 1.0): static
    {
        $this->shadows[] = [$x, $y, $blur, $spread, $rgb, $alpha, false];
        return $this;
    }

    /** Adds an inner box shadow, as CSS's box-shadow: inset: offset by $x and $y, blurred by $blur, grown inward by $spread points, inside the padding edge, above the background and under the content (mygo's InsetShadow). */
    public function insetShadow(float $x, float $y, float $blur, float $spread, int $rgb, float $alpha = 1.0): static
    {
        $this->shadows[] = [$x, $y, $blur, $spread, $rgb, $alpha, true];
        return $this;
    }

    /** Hides the node and what is inside it, which keep their room in the layout but paint nothing and take neither the pointer nor the focus, as CSS's visibility: hidden (mygo's Invisible). */
    public function invisible(): static
    {
        $this->invisible = true;
        return $this;
    }

    /**
     * Hides the node and takes its room away, as CSS's display: none (mygo's Hidden): it and what is inside it are
     * still built, keeping their state (scroll offsets, local values, editors), but the layout gives them no room and
     * no gap, and they paint, take input and read to assistive technology as invisible nodes do; what is attached to
     * them (attachTo) hides too. invisible() keeps the room.
     */
    public function hidden(bool $on = true): static
    {
        $this->hidden = $on;
        if ($on) { $this->invisible = true; }
        return $this;
    }

    /** Outlines the node and every node inside it, their margins in orange, padding in green and boxes in blue, to see the layout (mygo's Debug). */
    public function debug(): static
    {
        $this->debug = true;
        return $this;
    }

    /**
     * Paints the node and what is in it under the window's system
     * materials (Node::systemMaterial) and over its videos, as a player's
     * subtitles show under its control bar's glass, blurred by it: in a
     * layer of the window's of its own, its place and its pointer as they
     * are. A window of no such layer (a test's) paints it where it is.
     */
    public function belowMaterials(): static
    {
        $this->belowMaterials = true;
        return $this;
    }

    /** Lets the pointer reach what is under the node (mygo's PassThrough). */
    public function passThrough(): static
    {
        $this->passThrough = true;
        return $this;
    }

    /** Sets whether the keyboard focus rings the node, as by default; a node ringing a part of itself turns it off and paints its own (mygo's FocusRing). */
    public function focusRing(bool $show): static
    {
        $this->ownRing = !$show;
        return $this;
    }

    /** Disables the node and those inside it when $d (mygo's Disabled): they take no clicks and no focus, and widgets look disabled; a container paints at half its opacity. */
    public function disabled(bool $d = true): static
    {
        $this->disabled = $d;
        return $this;
    }

    /**
     * Makes the node and those inside it ignore the pointer and the
     * keyboard when $ignore, while they still show and read to assistive
     * technology (mygo's IgnoreInput). The pointer goes through to what is
     * under them, as passThrough() lets it: hovering, pressing, scrolling,
     * dragging and the cursor are those of what is under them. Neither Tab
     * nor a click gives them the focus, and they lose it as they start
     * ignoring input; their text is not selected. Assistive technology
     * still finds them, with their roles and names, and presses them, which
     * Context::clicked reports; it can neither focus them nor move a slider
     * among them with its keys.
     *
     * A control a row shows while it is hovered, as a button deleting it,
     * can stay built and described at rest, without taking a press meant
     * for the row:
     *
     *     $row = $c->row(8, 8);
     *       $c->text($item->title)->grow = 1;
     *       $del = $c->button('Delete')->ignoreInput(!$c->hovered($row));
     *       if (!$c->hovered($row)) { $del->opacity(0); }
     *       if ($c->clicked($del)) { $removed = $item->id; }
     *     $c->end();
     *
     * Unlike HTML's inert, it leaves them to assistive technology;
     * invisible() and hidden() hide them from it too, and disabled() takes
     * their press away.
     */
    public function ignoreInput(bool $ignore = true): static
    {
        $this->ignoreInput = $ignore;
        return $this;
    }

    /** Whether the node or one around it ignores input, as it was recorded (ignoreInput). @internal */
    public function ignoresInput(): bool
    {
        return $this->ignoreInput || $this->ignoringIn;
    }

    /** Whether the node or a container around it, as it was built, is disabled (mygo's IsDisabled). */
    public function isDisabled(): bool
    {
        return $this->disabled || $this->disabledIn;
    }

    /** Gives $h this node's identity in the window built now, for each pass (mygo's Bind). */
    public function bind(Handle $h): static
    {
        $rt = $this->context?->runtime;
        $b = $h->binding($rt, true);
        if ($b === null) { return $this; }
        if ($b->frame === $rt->frameNumber && $b->pass === $rt->pass && $b->node !== $this) {
            throw new \LogicException('a Handle bound to two nodes in one build');
        }
        $b->node = $this; $b->id = $this->id; $b->frame = $rt->frameNumber; $b->pass = $rt->pass;
        return $this;
    }

    /** Binds the focus to $value of the app's field $field, which holds the value of the member that should have it, null for none: setting the field asks for that control's focus, waiting while it is not built; the user moving the focus sets the field. Values are unique per field, and each window takes a field of its own (mygo's FocusBind). */
    public function focusBind(mixed &$field, mixed $value): static
    {
        if ($value === null) { throw new \InvalidArgumentException('focusBind: null is no member, but the focus on none'); }
        if ($this->focusTo !== null) { $this->focusTo->focusBind($field, $value); return $this; }
        $this->focusable = true;
        $this->context?->runtime?->focusBind($field, $value, $this);
        return $this;
    }

    /** Runs $fn after the build when the node is clicked (mygo's OnClick): Context::clicked() without the if. */
    public function onClick(\Closure $fn): static { $this->context?->runtime?->addAction($this, 'click', $fn); return $this; }

    /** Runs $fn after the build when the control's input changed its value, before the view builds again (mygo's OnChange). */
    public function onChange(\Closure $fn): static { $this->context?->runtime?->addAction($this, 'change', $fn); return $this; }

    /** Runs $fn after the build when the input or list was submitted, Enter or a double click (mygo's OnSubmit). */
    public function onSubmit(\Closure $fn): static { $this->context?->runtime?->addAction($this, 'submit', $fn); return $this; }

    /** Runs $fn when $key with exactly $mods is pressed while the node, or one inside it, has the keyboard focus (mygo's OnShortcut). */
    public function onShortcut(int|string $key, int $mods, \Closure $fn): static { $this->context?->runtime?->addAction($this, 'shortcut', $fn, $key, $mods); return $this; }

    /** Whether the node is an option the pointer or the arrows are on (mygo's Highlighted). */
    public function highlighted(): bool
    {
        return $this->highlighted;
    }

    /** Reports the node's value as changed this frame, as a control of your own whose input changed what it edits: Context::changed() returns true for it, as for the widgets' own (mygo's SetChanged). */
    public function setChanged(): static
    {
        $this->changed = true;
        return $this;
    }

    /** Makes a list's rows at least $w points wide, as a table's columns ask: where they are wider than the list, it scrolls sideways too, and its rows grow to its width where they are not; a header above the rows goes with them through scrollWith (mygo's RowWidth). */
    public function rowWidth(float $w): static
    {
        $this->rowMinW = max($w, 0.0);
        $this->scrollX = $this->rowMinW > 0;
        return $this;
    }

    /** Moves the node's content sideways as far as $scroller, a scroll container or a list, scrolls its own, as a table's header goes with its rows; clip() the node, so that what goes out of it does not show (mygo's ScrollWith). */
    public function scrollWith(Node $scroller): static
    {
        $this->followX = $scroller;
        return $this;
    }

    /** Keeps in $l where the text area lays out its lines (mygo's TrackLines). */
    public function trackLines(TextLines $l): static
    {
        if ($this->edit !== null) { $l->state = $this->edit; }
        return $this;
    }

    /** Styles runs of an input's text in the frames that call it, in their color, the last range given winning where they overlap; a composition shows unstyled (mygo's TextRanges). */
    public function textRanges(TextRange ...$ranges): static
    {
        foreach ($ranges as $r) {
            if ($r->end > $r->start) { $this->ranges[] = $r; }
        }
        return $this;
    }

    /** Moves a scroll container's bars in from its edges, as padding() takes its sides: the vertical bar from top points below its top to bottom above its bottom, right in from its right, the horizontal from left to right in, bottom up; clear of what floats over the content (mygo's ScrollbarInsets). */
    public function scrollbarInsets(float ...$v): static
    {
        $this->barInsets = self::edges($v);
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
    /** The children in the order they paint and take the pointer: a list's pinned header last, over its rows. @return list<Node> */
    public function paintOrder(): array
    {
        if ($this->pinned === null) { return $this->kids; }
        $kids = array_values(array_filter($this->kids, fn(Node $k): bool => $k !== $this->pinned));
        $kids[] = $this->pinned;
        return $kids;
    }

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

    /**
     * @internal VideoPlayer (ASS's \clip and \iclip): cuts the node and
     * what is inside it by the coverage (alpha) of $mask laid over its box,
     * as it is laid out, upright, or by the box itself for null: what it
     * covers shows, or with $inverted what it covers is cut away (what is
     * inside reaching past the box all the same). One at a time: a node
     * inside one has none of its own.
     */
    public function clipMask(?Image $mask, bool $inverted = false): static
    {
        $this->clipMask = [$mask, $inverted];
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
        if (!$this->poseM->set && $this->transform === Transform::identity()) {
            return $this->poseM; // none: most nodes, every frame
        }
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

    /**
     * Outlines text: its glyphs grown by $width points all round, in $rgb at
     * $alpha, under them, as subtitles are outlined; the glyphs' own masks,
     * not copies of the text around it (a color glyph has none).
     */
    public function textOutline(float $width, int $rgb, float $alpha = 1.0): static
    {
        if (!is_finite($width) || $width < 0) { throw new \InvalidArgumentException('an outline of a width of 0 or more'); }
        $this->ts()->outline = $width > 0 ? [$width, $rgb, max(0.0, min(1.0, $alpha))] : null;
        return $this;
    }

    /** Shadows text: its glyphs (outlined, with textOutline) moved by ($dx, $dy) points, in $rgb at $alpha, under them. */
    public function textShadow(float $dx, float $dy, int $rgb, float $alpha = 1.0): static
    {
        $this->ts()->textShadow = [$dx, $dy, $rgb, max(0.0, min(1.0, $alpha))];
        return $this;
    }

    /**
     * @internal VideoPlayer (ASS's \blur and \be): blurs text as a Gaussian
     * of $sigma points, as libass does: its outline where it has one
     * (textOutline), the glyphs themselves staying sharp, else the glyphs;
     * its shadow (textShadow) is what was blurred, moved.
     */
    public function textBlur(float $sigma): static
    {
        if (!is_finite($sigma) || $sigma < 0) { throw new \InvalidArgumentException('a blur of a sigma of 0 or more'); }
        $this->ts()->blur = $sigma > 0 ? $sigma : null;
        return $this;
    }

    /**
     * @internal VideoPlayer (libass's ass_fix_outline): takes the glyphs out
     * of the text's outline ($outline) and of its shadow ($shadow, of the
     * outline), so that they do not show through glyphs not quite opaque.
     */
    public function textCutOut(bool $outline, bool $shadow): static
    {
        $this->ts()->cutOut = $outline || $shadow ? [$outline, $shadow] : null;
        return $this;
    }

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
        $align = $t->align ?? self::START;
        if ($this->rtl && $align !== self::CENTER) { // lines start at the right of a node laid out right to left, whatever they read, as in CSS and AppKit's cells
            $align = $align === self::END ? \MyPHP\Text\Params::LEFT : \MyPHP\Text\Params::RIGHT;
        }
        $p = new \MyPHP\Text\Params($text, $t->style($this->fontSize), $this->wrapWidth ?? $this->alignWidth, $this->maxLines, $this->ellipsis, $this->noWrap, $align, rtl: $this->rtl, spans: $spans);
        return $this->layout = $this->metrics->textSystem()->layout($p);
    }

    /**
     * Whether the text wraps at the width layout gives it (mygo's Text): a
     * text or rich text not on one line (singleLine(), noWrap()), not given
     * a width to wrap at, not inside a paragraph, that a line can break in.
     */
    public function wrapsAtWidth(): bool
    {
        if (($this->kind !== self::TEXT && $this->kind !== self::RICH) || $this->single || $this->noWrap || $this->inline || $this->metrics === null || $this->wrapLast || ($this->wrapWidth !== null && !$this->autoWrap)) {
            return false;
        }
        return $this->breaks ??= preg_match('/[\s\x80-\xff]/', $this->kind === self::TEXT ? (string)$this->text : $this->plainText()) === 1; // a space, or text past ASCII (Chinese breaks between its characters): a line may break in it; asked a few times a layout
    }

    /** Whether a line may break in the node's text, once known (wrapsAtWidth). */
    private ?bool $breaks = null;

    /** The width of the widest part of the text no line breaks in (CSS's min-content): laid out by the text system at no width. */
    public function minContentWidth(): float
    {
        return $this->kind === self::TEXT ? $this->metrics->minContentWidth((string)$this->text, $this->fontSize, $this->style) : $this->measureMinContent(); // a text by what measured it, the line kept shaped; rich text by the text system
    }

    private function measureMinContent(): float
    {
        $spans = []; $runes = 0; $text = '';
        if ($this->kind === self::RICH) { $this->plainText(); } // a paragraph's spans those of the text inside it too, merged once
        foreach ($this->kind === self::TEXT ? [$this->given((string)$this->text)] : $this->spans as $sp) {
            $text .= $sp->text;
            $runes += mb_strlen($sp->text, 'UTF-8');
            $spans[] = $sp->textSpan($runes);
        }
        $p = new \MyPHP\Text\Params($text, $this->textStyle()->style($this->fontSize), 0.001, 0, '', false, \MyPHP\Text\Params::START, rtl: $this->rtl, noBreakWords: true, spans: $spans); // broken only between words, as mygo's (p.NoBreakWords): else at every glyph, a min-content of one glyph
        $l = $this->metrics->textSystem()->layout($p);
        return count($l->lines) > 1 ? $l->width : $this->fw; // one piece: as wide as it measured
    }

    /**
     * Hides what a text input holds, in the frames that call it (mygo's
     * Password): bullets in its place, which nothing copies and neither the
     * input method nor assistive technology reads; an input that stops
     * calling it shows its text again, as a field's eye button does. It does
     * nothing to a text area: as on every platform, only single-line fields
     * hide their text.
     */
    public function password(): static
    {
        if ($this->edit !== null && !$this->edit->multiline) {
            $this->edit->password = true;
        }
        return $this;
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

    /** @internal Context::calendar: what a calendar keeps (browse()), null for other nodes */
    public ?object $calendar = null;

    /**
     * Lets the user move about a calendar without choosing a day (mygo's
     * Browse): its buttons and Page Up and Page Down only show other
     * months, and the arrows, Home and End only move among the days, until
     * a click, Enter or Space chooses one, as in a date input's calendar.
     * changed() then reports only a day chosen, as a calendar in a popover
     * that closes when the user picks a day needs. The calendar goes back
     * to the date as the app sets another. Nodes other than a calendar
     * take no notice.
     */
    public function browse(): static
    {
        if ($this->calendar !== null) { $this->calendar->now = true; }
        return $this;
    }

    /**
     * Gives the node a context menu, as contextMenu() does, which myphp
     * draws in the window with the theme's look, as a popover, rather than
     * the system showing one of its own (mygo's PopoverMenu). It opens
     * where the node is clicked with the secondary button, or
     * Control-clicked, and, while it or a node inside it has the focus,
     * below it for the menu key or Shift+F10. $build fills it with the
     * items contextMenu()'s function takes, and the item chosen reports
     * chosen() as there. The menu takes the focus as it opens and gives it
     * back as it closes; Up and Down move among its items, past those
     * disabled, as do Home, End and typing the first letters of an item;
     * Right opens a submenu and Left closes it, the other way round right
     * to left; Enter and Space choose, Escape closes the submenu on top, Tab
     * and a press outside the menu close it. It shows below and after where
     * it opened, and on the other side where the window has no room.
     * Assistive technology sees a menu of items, check box items and radio
     * items, as the system's.
     * @param \Closure(Menu): void $build
     */
    public function popoverMenu(\Closure $build): static
    {
        $this->contextMenu = true;
        $rt = $this->context?->runtime;
        $rt?->buildMenu($this->id, $this->disabled, false, $build, true);
        if ($this->context !== null) { $rt?->drawMenu($this, $this->context, $build); }
        return $this;
    }

    /**
     * Makes the node a menu button, as menu() does, whose menu myphp draws
     * in the window, as popoverMenu() draws a context menu (mygo's
     * PopoverMenuButton): it opens below the node, or above it where the
     * window has no room, as the pointer goes down on it, and for Enter,
     * Space or Down while it has the focus. The menu has popoverMenu()'s
     * keys; a press on the node closes it too; opened by the keyboard, it
     * starts on its first item. The node is expanded while it shows.
     * @param \Closure(Menu): void $build
     */
    public function popoverMenuButton(\Closure $build): static
    {
        $this->menuButton = true;
        $this->focusable = true;
        if ($this->role === Role::AUTO || $this->role === Role::BUTTON) {
            $this->role = Role::MENU_BUTTON;
        }
        $rt = $this->context?->runtime;
        $rt?->buildMenu($this->id, $this->disabled, true, $build, true);
        if ($this->context !== null) { $rt?->drawMenu($this, $this->context, $build); }
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

    /**
     * Paints the inline objects of this rich text (Span::$objectWidth)
     * after its glyphs (mygo's DrawObjects): $fn(Painter $p, int $at,
     * array $box) gets each one that shows, its rune in the text, and its
     * box [x, y, w, h] in the window's points, its objectHeight above the
     * baseline and its objectDescent below. It paints in the text's clip;
     * it only paints, and may run more than once a frame.
     *
     *     $c->richText([new Span('area '), new Span("\u{FFFC}", objectWidth: $w, objectHeight: $h, objectDescent: $d)])
     *         ->drawObjects(fn(Painter $p, int $at, array $r) => $formula->draw($p, $r));
     */
    public function drawObjects(\Closure $fn): static
    {
        $this->drawObjects = $fn;
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

    /** Sets a grid's column tracks, as a sidebar and the rest: columnTracks(Track::fixed(220), Track::fr()) (mygo's ColumnTracks). */
    public function columnTracks(Track ...$tracks): static
    {
        $this->gridCols = array_values($tracks);
        return $this;
    }

    /** Sets a grid's first rows' tracks; those it adds after fit their content (mygo's RowTracks). */
    public function rowTracks(Track ...$tracks): static
    {
        $this->gridRows = array_values($tracks);
        return $this;
    }

    /** Gives a grid $n columns of equal width, Track::fr() each (mygo's Columns); rows() $n rows of equal height. */
    public function columns(int $n): static { return $this->columnTracks(...array_fill(0, max($n, 0), Track::fr())); }
    public function rows(int $n): static { return $this->rowTracks(...array_fill(0, max($n, 0), Track::fr())); }

    /** Wraps this row's children onto more lines where they overflow $width, the width it had last frame (Runtime::rectOf); a frame follows when its width differs. */
    public function wrap(float $width): static
    {
        $this->wraps = true; $this->wrapWidth = $width;
        return $this;
    }

    /** Wraps as wrap() does, the lines going from the bottom up (mygo's WrapReverse). */
    public function wrapReverse(float $width): static
    {
        $this->wrapReverse = true;
        return $this->wrap($width);
    }

    /** Draws a border $width points wide around this container, in $color or the theme's border color, over its background if it has one (mygo's Border). */
    public function border(?int $color = null, float $width = 1.0): static
    {
        $this->bordered = true; $this->borderColor = $color; $this->borderWidth = $width;
        return $this;
    }

    /** Makes what was built before this node inert while it shows: the pointer and Tab reach only it, what is inside it and what comes after (mygo's overlays). */
    public function block(): static
    {
        $this->blocks = true;
        return $this;
    }

    /** Shows $cursor while the pointer is over this node (mygo's Cursor). */
    public function cursor(Cursor $cursor): static
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
        $this->logical &= ~self::LOG_PAD;
        $this->pads = self::edges($v);
        $this->padY = ($this->pads[0] + $this->pads[2]) / 2; $this->padX = ($this->pads[1] + $this->pads[3]) / 2;
        return $this;
    }

    /** Sets the left and right padding (mygo's PaddingX). */
    public function paddingX(float $v): static { $p = $this->sides(); return $this->padding($p[0], $v, $p[2], $v); }

    /** Sets the top and bottom padding (mygo's PaddingY). */
    public function paddingY(float $v): static { $p = $this->sides(); return $this->padding($v, $p[1], $v, $p[3]); }

    /** Sets the padding on the side lines start: the left, or the right of a node laid out right to left, as CSS's padding-inline-start (mygo's PaddingStart); paddingEnd() the other side's. */
    public function paddingStart(float $v): static { return $this->logicalPad($this->startSide(), $v, self::LOG_PAD_START); }
    public function paddingEnd(float $v): static { return $this->logicalPad(4 - $this->startSide(), $v, self::LOG_PAD_END); }

    private function logicalPad(int $side, float $v, int $bit): static
    {
        $p = $this->sides();
        $p[$side] = $v;
        $logical = $this->logical;
        $this->padding(...$p);
        $this->logical = $logical | $bit;
        return $this;
    }

    /** The index of the side lines start in the edges (top, right, bottom, left): the left (3), or the right (1) right to left. */
    public function startSide(): int
    {
        return $this->rtl ? 1 : 3;
    }

    /**
     * Lays the node and what is inside it out in $d, whatever the direction
     * around it (mygo's Direction): LeftToRight keeps a code sample, a
     * phone number or a player's controls left to right in a right-to-left
     * window. The nodes built inside it inherit it. Give it right after
     * creating the node, before its logical edges and children, as widgets
     * do: nodes already inside take it, and logical edges move to the
     * other side, but what read the old direction keeps it.
     */
    public function direction(Direction $d): static
    {
        $this->setDir($d === Direction::RightToLeft, true);
        return $this;
    }

    /** @internal sets the node's direction, given or inherited, and that of the nodes inside it that inherit it, moving their logical edges where it changes */
    public function setDir(bool $rtl, bool $explicit): void
    {
        $was = $this->rtl;
        $this->rtl = $rtl;
        $this->dirSet = $this->dirSet || $explicit;
        if ($was === $rtl) {
            return;
        }
        if ($this->logical & self::LOG_PAD) {
            $p = $this->sides();
            [$p[1], $p[3]] = [$p[3], $p[1]];
            $this->pads = $p;
        }
        if ($this->logical & self::LOG_MARGIN) {
            [$this->margins[1], $this->margins[3]] = [$this->margins[3], $this->margins[1]];
        }
        if ($this->logical & self::LOG_BORDER) {
            [$this->borders[1], $this->borders[3]] = [$this->borders[3], $this->borders[1]];
        }
        if ($this->logical & self::LOG_INSET) {
            [$this->insets[1], $this->insets[3]] = [$this->insets[3], $this->insets[1]];
        }
        foreach ($this->kids as $k) {
            if (!$k->dirSet) {
                $k->setDir($rtl, false);
            }
        }
    }

    /** The padding of each side, top, right, bottom and left. @return array{0: float, 1: float, 2: float, 3: float} */
    public function sides(): array
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
    /** Where a text's lines start: inside its padding and border, a paragraph's text inside it at the paragraph's. @return array{0: float, 1: float} */
    public function textOrigin(): array
    {
        return $this->inline ? [$this->x, $this->y] : [$this->x + $this->padL(), $this->y + $this->padT()];
    }

    public function padX(): float { return ($this->padL() + $this->padR()) / 2; }

    /** The room between the node's edge and its content on each side: its border and its padding; border()'s takes room as mygo's (its layout's border box). */
    public function padT(): float { return ($this->pads[0] ?? $this->padY ?? $this->pad) + $this->borderSide(0); }
    public function padR(): float { return ($this->pads[1] ?? $this->padX ?? $this->pad) + $this->borderSide(1); }
    public function padB(): float { return ($this->pads[2] ?? $this->padY ?? $this->pad) + $this->borderSide(2); }
    public function padL(): float { return ($this->pads[3] ?? $this->padX ?? $this->pad) + $this->borderSide(3); }

    /** The border on side $i: borderWidth()'s, else border()'s all round. */
    /** @internal The padding and border across and down, padL() + padR() and padT() + padB() at once: Layout measures them for every node. @return array{0: float, 1: float} */
    public function padSums(): array
    {
        if ($this->pads !== null) {
            [$t, $r, $b, $l] = $this->pads;
            $w = $r + $l; $h = $t + $b;
        } else {
            $w = 2 * ($this->padX ?? $this->pad); $h = 2 * ($this->padY ?? $this->pad);
        }
        $bs = $this->borders; $all = $this->bordered ? $this->borderWidth : 0.0;
        if ($all == 0.0 && $bs[0] == 0.0 && $bs[1] == 0.0 && $bs[2] == 0.0 && $bs[3] == 0.0) { return [$w, $h]; }
        return [$w + ($bs[1] > 0 ? $bs[1] : $all) + ($bs[3] > 0 ? $bs[3] : $all), $h + ($bs[0] > 0 ? $bs[0] : $all) + ($bs[2] > 0 ? $bs[2] : $all)];
    }

    private function borderSide(int $i): float
    {
        return $this->borders[$i] > 0 ? $this->borders[$i] : ($this->bordered ? $this->borderWidth : 0.0);
    }

    /**
     * Sets the space around the node, as padding() does (mygo's Margin);
     * Node::AUTO, an automatic margin, takes the free space on its side, as
     * in CSS: margin(0, Node::AUTO) centers a node horizontally, and a row's
     * child with margin(0, 0, 0, Node::AUTO) goes to the end with those
     * after it.
     */
    public function margin(int|float ...$v): static
    {
        $this->logical &= ~self::LOG_MARGIN;
        $this->margins = self::edges(array_map(static fn(int|float $m): float => $m === self::AUTO ? INF : (float)$m, $v));
        return $this;
    }

    /** Sets the left and right margins (mygo's MarginX). */
    public function marginX(int|float $v): static { $m = $v === self::AUTO ? INF : (float)$v; $this->margins[1] = $this->margins[3] = $m; $this->logical &= ~self::LOG_MARGIN; return $this; }

    /** Sets the margin on the side lines start (mygo's MarginStart): Node::AUTO takes the free space there, so that a row's child with marginStart(Node::AUTO) goes to its end; marginEnd() the other side's. */
    public function marginStart(int|float $v): static { $this->margins[$this->startSide()] = $v === self::AUTO ? INF : (float)$v; $this->logical |= self::LOG_MARGIN_START; return $this; }
    public function marginEnd(int|float $v): static { $this->margins[4 - $this->startSide()] = $v === self::AUTO ? INF : (float)$v; $this->logical |= self::LOG_MARGIN_END; return $this; }

    /** Sets the top and bottom margins (mygo's MarginY). */
    public function marginY(int|float $v): static { $m = $v === self::AUTO ? INF : (float)$v; $this->margins[0] = $this->margins[2] = $m; return $this; }

    /** Sets the widths of the border on each side, as padding() does, inside which the content is (mygo's BorderWidth), in borderColor(). */
    public function borderWidth(float ...$v): static
    {
        $this->logical &= ~self::LOG_BORDER;
        $this->borders = self::edges($v);
        return $this;
    }

    /** Sets the width of the border on the side lines start, and on the other (mygo's BorderStartWidth and BorderEndWidth): a line between a sidebar and the content is borderEndWidth(1) on the sidebar. */
    public function borderStartWidth(float $v): static { $this->borders[$this->startSide()] = $v; $this->logical |= self::LOG_BORDER_START; return $this; }
    public function borderEndWidth(float $v): static { $this->borders[4 - $this->startSide()] = $v; $this->logical |= self::LOG_BORDER_END; return $this; }

    /** Sets the color of the border (mygo's BorderColor). */
    public function borderColor(int $rgb): static { $this->borderColor = $rgb; return $this; }

    /** Fills the node's box with $rgb at $alpha (mygo's Background). */
    public function background(int $rgb, float $alpha = 1.0): static
    {
        $this->color = $rgb; $this->bgAlpha = $alpha; $this->gradient = null;
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

    /** Cuts what its children draw off at the node's left and right only, as mygo's ClipX. */
    public function clipX(): static
    {
        $this->clip |= self::CLIP_X;
        return $this;
    }

    /** Cuts what its children draw off at the node's top and bottom only, as mygo's ClipY: a panel growing into view. */
    public function clipY(): static
    {
        $this->clip |= self::CLIP_Y;
        return $this;
    }

    /** Fixes a node's width and/or height (points) instead of its content's size; 0 leaves one to the content: a text's measured height stays, as mygo's Width() leaves it. */
    public function size(float $w = 0, float $h = 0): static
    {
        // A text's or button's measured side stays where 0 is given, as mygo's Width() leaves the height (a primary button of size(44, 0) took its row's height).
        $measured = $this->kind === self::TEXT || $this->kind === self::RICH || $this->kind === self::BUTTON;
        if ($w > 0 || !$measured) { $this->fw = $w; }
        if ($h > 0 || !$measured) { $this->fh = $h; }
        $this->sizedW = $w > 0;
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
        if ($this->focusTo !== null) { $this->focusTo->autoFocus(); return $this; } // a field's box: its input (combobox, tokenField)
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

    /** The caret of text the node takes from the input methods (textCaret), [x, y, w, h] in its box, or null for none. @var ?array{0: float, 1: float, 2: float, 3: float} */
    public ?array $caret = null;

    /**
     * Has the node take text from the system's input methods while it has
     * the keyboard focus, their composition at the caret ($x, $y, $w, $h),
     * relative to its box, where candidate windows show: its handleInput
     * hears COMPOSE and TEXT events (mygo's TextCaret).
     */
    public function textCaret(float $x, float $y, float $w, float $h): static
    {
        $this->caret = [$x, $y, $w, $h];
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
