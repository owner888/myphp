<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Transfer\{Data, Drop, DragOptions, Format, FormatUnavailable, Offer, Operation, Result};

use Closure;
use MyPHP\Logger;

/**
 * Frame-to-frame state for immediate mode: last frame's rectangles by node
 * id, so that this frame's build can answer clicked()/hovered() before it
 * is laid out, and the input events this frame consumes.
 *
 * Node ids are the order of creation in the build, as in a view whose
 * structure does not change between frames; conditional UI keeps ids
 * stable with Context::key, and list rows are keyed by their index.
 */
final class Runtime
{
    use RuntimeAccess;
    use RuntimeSelection;
    use RuntimeMenu;
    use RuntimeTransition;

    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> id => x, y, w, h */
    private array $rects = [];
    /** @var array<int, list<array{0: float, 1: float, 2: float, 3: float}>> the boxes of the words of text inside a paragraph, by id, last frame */
    private array $frags = [];
    /** @var array<int, Scrollable> id => scroll state, from last frame */
    private array $scrolls = [];
    /** @var array<int, array{0: bool, 1: bool}> id => scrolls horizontally, vertically */
    private array $scrollAxes = [];
    /** @var array<int, TextState> text areas by id, from last frame: the wheel scrolls them too */
    private array $areas = [];
    /** Set by a frame that needs another one right after (a list left a gap). */
    public bool $needsFrame = false;
    private array $clicks = [];     // [x, y, mods] of left mouse-ups this frame
    /** The modifiers of the click clicked() last took (mygo's ClickModifiers): Shift, Command. */
    public int $clickMods = 0;
    private array $rightClicks = []; // [x, y] of right mouse-ups this frame
    private array $middleClicks = []; // and of the middle button's
    /** @var list<array{0: float, 1: float, 2: float, 3: int}> the pinches this frame: where, the magnification, the gesture's phase (NSEventPhase) */
    private array $magnifies = [];
    /** @var list<array{0: float, 1: float}> where a Force Touch's press went deep (its second stage) this frame */
    private array $forceClicks = [];
    private int $pressureStage = 0;
    private array $doubleClicks = []; // [x, y] of second presses this frame
    private bool $consumed = false;
    public int $passes = 0;
    /** The theme's scroll bar width, for hit-testing thumbs; Window sets it. */
    public float $scrollbarWidth = 6;
    /** Opens a URL with the system, throwing when none opens it; App sets it (Shell::openExternal). Links and Context::openURL reach it through openURLThen. */
    public Closure $openURL;
    /** Moves the window with the mouse (1 click) or zooms or minimizes it (2) for a press on a Node::dragWindow handle; the window sets it. */
    public ?Closure $dragWindow = null;
    /** Called after a frame in which an Escape came that nothing took (mygo's host escape): a flyout or a panel closes on it. */
    public ?Closure $escape = null;
    /** The drag handles of last frame (id => rect), and the links (ids), which keep their presses from a handle. */
    private array $handles = [], $links = [];
    /** @var array<int, string> nodes with tooltips by id, from last frame */
    private array $tips = [];
    /** The tip that shows: its text ('' for a tip of its own) and its anchor's rect, or null. */
    public ?array $tip = null;
    /** The node the tip is of: one with a tip of its own (Context::tooltipBase) builds it in the frame after. */
    public ?int $tipAnchor = null;
    /** Whether the keyboard focus brought the tip rather than the pointer. */
    public bool $tipByFocus = false;
    /**
     * Which tip shows (mygo's tooltips): the innermost node with a tip
     * under the pointer since $tipHoverSince, the one with the keyboard
     * focus since $tipFocusSince, the one whose tip a press or Escape hid
     * until neither the pointer nor the focus is on it, and when a tip
     * last showed, in seconds of the frames' clock.
     */
    private ?int $tipHover = null, $tipFocus = null, $tipDismissed = null;
    private float $tipHoverSince = 0.0, $tipFocusSince = 0.0, $tipLastShown = -INF;
    /** How long the pointer rests on a node before its tip shows, and how soon after a tip goes another shows at once, as the pointer moves along a toolbar: Base UI's (mygo's tooltipDelay, tooltipWarm). */
    public const TIP_DELAY = 0.6, TIP_WARM = 0.4;
    /** The pointer the window should show over the hovered node. */
    public Cursor $cursor = Cursor::Default;
    /** The roles of what is clicked, where the cursor of what is around it stops. */
    private const CLICKABLE_ROLES = [Role::BUTTON, Role::LINK, Role::CHECK_BOX, Role::RADIO, Role::SWITCH, Role::POP_UP_BUTTON, Role::MENU_BUTTON, Role::TOGGLE_BUTTON, Role::TAB, Role::DISCLOSURE, Role::COLOR_WELL, Role::MENU_ITEM, Role::MENU_ITEM_CHECK_BOX, Role::MENU_ITEM_RADIO];
    private bool $animating = false;
    /** The time of frames for tests (mygo's clock): nanoseconds, hrtime's when null. */
    public ?Closure $clock = null;
    /** This frame's time, in nanoseconds (mygo's Context.Now), and its number, from 1 (mygo's frame). */
    public int $now = 0, $frameNumber = 0;
    /** The frame that Context::disableAnimations shows at once. */
    public int $instantFrame = 0;
    /** That the desktop asks for less motion (macOS's Reduce Motion, mygo's Preferences.ReduceMotion): the window sets it from App::reduceMotion. */
    public bool $reduceMotion = false;
    /** The desktop's settings (Context::preferences): the window sets them from App::preferences. */
    public Preferences $preferences;
    /** That the window reads right to left: the window sets it from the app's language (I18n::direction of App::locale). */
    public bool $rightToLeft = false;
    /** The inspector's direction for debugging: 0 none, 1 left to right, 2 right to left. */
    public int $dirOverride = 0;
    /** The inspector of the window's nodes, in a window whose developer tools are on (WindowOptions::$devTools); null in others. */
    public ?Inspector $inspector = null;
    /** @var list<string> the mistakes found while building, the latest MAX_WARNINGS, which the inspector lists (mygo's warnings) */
    public array $warnings = [];
    public const MAX_WARNINGS = 50;
    /** @var array<int, true> the ids of the duplicate keys told of */
    private array $dupKeys = [];
    /** A duplicate key throws, where it was given, rather than being told once: the tests' (mygo's strict Tester). */
    public bool $strict = false;
    /** MYPHP_DIRECTION, read once: 1 for "ltr", 2 for "rtl", 0 unset (mygo's MYGO_DIRECTION). */
    private static ?int $envDirection = null;

    /**
     * Whether the window is laid out right to left (mygo's direction): the
     * debugging override, MYPHP_DIRECTION=rtl (or ltr), which lays every
     * window out so to try an interface right to left without translating
     * it, as GTK's GTK_DEBUG=invert-text-dir and Qt's -reverse do, or the
     * inspector's; else the window's.
     */
    public function direction(): bool
    {
        self::$envDirection ??= match (getenv('MYPHP_DIRECTION')) { 'ltr' => 1, 'rtl' => 2, default => 0 };
        return match (true) {
            self::$envDirection !== 0 => self::$envDirection === 2,
            $this->dirOverride !== 0 => $this->dirOverride === 2,
            default => $this->rightToLeft,
        };
    }
    /**
     * The values that move, by node id and key (mygo's motions,
     * colorMotions, sequences and anims): kept while their node is built.
     * @var array<int, array<string|int, MotionChannel>>
     */
    public array $motions = [];
    /** @var array<int, array<string|int, object>> colors moving: from, to, shown ([rgb, alpha]) and k, a MotionChannel from 0 to 1 */
    public array $colorMotions = [];
    /** @var array<int, array<string|int, KeyframeSequence>> */
    public array $sequences = [];
    /** @var array<int, array<string|int, int>> when each Context::loop started */
    public array $loops = [];
    /** @var array<int, array<string|int, TimelineState>> the nodes' timelines (Context::timeline) */
    public array $timelines = [];
    /** The pass of the frame's build being built, from 0: a click that changed state builds the frame again (mygo's pass). */
    public int $pass = 0;
    /** @var array<int, Affine> the maps from the layout of last frame's transformed nodes to the window, by id (Node::$world): for the pointer */
    private array $worlds = [];
    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> the carets of last frame's nodes taking text (Node::textCaret), in their layout, by id */
    private array $carets = [];
    /** @var array<int, int> the node clipping each node of last frame, the nearest around it, by id; and the axes each of those clips (Node::CLIP_X, CLIP_Y) */
    private array $clippedBy = [], $clipAxes = [];
    /** @var array<int, true> the nodes some of which showed in the window last frame, within the scrolling containers and clips around them (Context::inView) */
    private array $shown = [];
    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> each node's rectangle as it shows in the window, clipped by its ancestors, last frame */
    private array $visible = [];
    /** Steppers built this frame, by id, for the arrow keys: whether right to left, where Left steps up. @var array<int, bool> */
    private array $steppers = [];
    /** Home (-1) or End (1) pressed on a focused stepper this frame, by id. */
    private array $jumps = [];
    /** The press the mouse is still down from: [x, y, start seconds, ticks given, on a stepper, last x, last y dragged() reported, (7) the node being dragged], or null. */
    private ?array $hold = null;
    /** Where the mouse went down this frame, for pressed(); a popover closes on a press outside it. */
    private array $presses = [];
    /** Keys pressed this frame: [characters, key code, modifiers, taken by a focused control]. */
    private array $keys = [];
    /** Each node's place in last frame's record order, and the place of the last node that blocks what was built before it (a modal's backdrop): nodes before it are inert. */
    private array $order = [];
    private int $barrier = 0, $counter = 0;
    /** The node of the dialog on top in the last frame (a modal's backdrop, which blocks what was built before it), or null: the window's shortcuts built outside it wait (mygo's modal). */
    public ?int $modal = null;
    /** The overlays of the frame (a popover, a menu, a combobox's options: nodes placed at the root with Node::at), as [order, x, y, w, h]: a point in one reaches no node built before it. */
    private array $overlays = [];
    /** The overlays built last frame (a popover, a menu, a modal: ids), and for each overlay the node that had the focus as it opened, to give it back as the overlay closes with the focus inside (mygo's openers and restoreFocus). */
    private array $overlayIds = [], $openers = [];
    /** Focus groups: each focusable's group id, the groups' orientation, selects flag and member on, and the member each group focused last. */
    private array $memberOf = [], $groups = [], $groupLast = [];
    /** State a composite keeps across frames by its node's id (a sidebar's scroll offset and items), as mygo's local state: it goes with its node, unless a router keeps the page it is in. */
    private array $locals = [];
    /**
     * Each node's parent in the last frame (0 for the root); the page of a
     * router each node was last built in (0 for none), kept with its state;
     * the pages the routers keep this pass, and the nodes inert last frame
     * (in a page going away) and those Tab goes past (focus targets).
     * @var array<int, int> $parents @var array<int, int> $pageOf
     */
    private array $parents = [], $pageOf = [], $kept = [], $inertIds = [], $targets = [];
    /** @var array<int, true> the nodes the pointer goes through (Node::passThrough), and those disabled, or inside a disabled one (Node::isDisabled), last frame */
    private array $passing = [], $disabledIds = [];
    /** @var array<int, int> the node each attached node is attached to (Node::attachTo), last frame; @var array<int, true> the overlays that asked for keys last frame, and this one (overlayShortcut) */
    private array $attachedTo = [], $overlayAskers = [], $overlayAsked = [];
    /** Whether the wheel scrolled this frame. */
    public bool $wheeled = false;
    /** @var array<int, true> the nodes pressedOutside told of this frame's press */
    private array $outsideSaid = [];
    /** The routers' boxes built this pass, in order, and those of the last frame (Router::view). */
    private array $routers = [], $lastRouters = [];
    /** The overlays of the last frame, while this one is built. */
    private array $lastOverlayIds = [];
    /** Whether a frame is being built: a change asks for another pass of it, else for a frame (changed()). */
    private bool $building = false;
    /** How far, in points, the pointer pressing a node moves before it drags it (mygo's dragStart). */
    public const DRAG_START = 4;
    /**
     * The left press of the pointer (mygo's pressed state): the node it
     * pressed, the innermost that takes the pointer under it last frame
     * (null for none), its moves (DragTrack), whether it is still down, and
     * once let go since the last frame, whether it moved (released) and how
     * fast it went then, in the window's axes.
     * @var ?array{id: ?int, track: DragTrack, down: bool, released: bool, v: array{0: float, 1: float}}
     */
    private ?array $pointerPress = null;
    /** @var array<int, DragRec> what Context::draggable keeps of each node, by id */
    public array $dragRecs = [];
    /** @var array<int, true> the nodes that were draggable last frame (Node::$draggable) */
    private array $draggables = [];
    /**
     * A value being dragged within the window (mygo's valueDrag): the node
     * dragging it, the value, where the press took the node (from its top
     * left), the node taking it under the pointer, whether Escape gave it
     * up, and the source's node in the frame, for its copy.
     * A native one ($native) is the system's from then on (Context::dragData).
     * @var ?array{src: int, value: mixed, offX: float, offY: float, over: ?int, canceled: bool, elem: ?Node, native: bool}
     */
    public ?array $valueDrag = null;
    /** The native drag sources of last frame (Node::$dataSource), its native drop targets (Node::$dataTarget), the nodes taking files (Node::$takesFiles). */
    private array $dataSources = [], $dataTargets = [], $fileTargets = [];
    /** A native drag over the window (mygo's incoming) and the node taking it there; files dragged over a node taking them; transfers and files dropped since the last pass, by node. */
    private ?DataDragEvent $incoming = null;
    private ?int $dataOver = null, $fileOver = null;
    private array $dataDropped = [], $filesDropped = [];
    /** The formats the drop targets take, as the view was told them last. @var list<string> */
    private array $dropFormats = [];
    /**
     * The host's native drags (mygo's host): $startDataDrag(Data, local
     * value, DragOptions, x, y) starts one, returning what failed or null;
     * $cancelDataDrag() cancels it, its end told the source at once;
     * $setDropFormats(list) tells the view what drops to take;
     * $snapshot(x, y, w, h) is an Image of the last frame's ($x, $y) and
     * the $w by $h points from there, one pixel a point. The window sets them.
     */
    public ?Closure $startDataDrag = null, $cancelDataDrag = null, $setDropFormats = null, $snapshot = null;
    /** The nodes dragging values last frame (Context::drag) and their values; the nodes taking values (Context::drop, dragOver), by the type they take; the values dropped since the last pass, by the node taking them. */
    private array $dragSources = [], $dropTargets = [], $dropped = [];
    /** The node around the one pressed that drags a value, which takes the press once it moves (mygo's pressDragger). */
    private ?int $pressDragger = null;
    /** Asks the window for a frame, after a change from outside a frame (a menu item going to another page); the window sets it. */
    public ?Closure $requestFrame = null;
    /** @var array<string, Closure(string): void> what of the view listens to its window's events (Window::EVENTS: minimize, restore, focus, blur, enterFullScreen…), by who listens; the window calls them */
    public array $windowListeners = [];
    /** What the window's view under its materials drew last (the nodes belowMaterials: a video's subtitles), [BGRA bytes, width, height, pixels a point], null when it drew nothing; the window sets it. @var ?Closure(): ?array{0: string, 1: int, 2: int, 3: float} */
    public ?Closure $underFrame = null;
    /** Shows (true) or hides the window at once, from outside a frame too (a film gone to picture in picture, and back); the window sets it. */
    public ?Closure $showWindow = null;
    /** Minimizes the window (true) or brings it back, from outside a frame too (a film gone to picture in picture, as the settings say); the window sets it. */
    public ?Closure $minimizeWindow = null;
    /** Whether the window is full screen: the window sets it before each frame. */
    public bool $fullScreen = false;
    /** @internal VideoPlayer: the seconds of the system's double click (NSEvent's doubleClickInterval): the window sets it. */
    public float $doubleClickInterval = 0.5;
    /**
     * What the view asked of its window this frame, which the window does
     * once it is built (Context::videoPlayer's, videoPlayerSettings'): 'close'
     * => true to close it, 'title' => its title, 'buttons' => whether the
     * traffic lights show, 'aspect' => the content's width over its height
     * to keep (null for none), 'fullScreen' => whether to be full screen.
     * @var array<string, mixed>
     */
    public array $windowAsks = [];
    /** @var list<array{t: Toast, key: int, spent: float, at: float}> the toasts, oldest first: how long each showed before $at, in seconds of $now, while the time of the toasts runs; the newest TOASTS show (mygo's toasts) */
    private array $toasts = [];
    private int $nextToast = 0;
    private bool $toastsPaused = false;
    /** How long a toast shows, and one with an action, which the user needs time to reach; how many show at once (mygo's toastTime, actionToastTime, toastLimit). */
    public const TOASTS = 3, TOAST_FOR = 4.0, ACTION_TOAST_FOR = 8.0;
    private const HOLD_DELAY = 0.4, HOLD_RATE = 0.08;
    /** A thumb being dragged: its state, the pointer's last y and content points per thumb point. */
    private ?array $drag = null;
    private int $lastFrame = 0;
    /** @var array<int, ListState> id => lists whose rows can be chosen, from last frame */
    private array $lists = [];

    /** Keyboard focus: the node id, the ids that can take it (last frame, in order) and the fields. */
    public ?int $focus = null;
    /** Whether the focus came from the keyboard (Tab, the arrows, Enter, Space) and so shows a ring, as mygo's focusVisible; a press hides it. */
    private bool $focusVisible = false;
    private array $focusables = [];
    /** @var array<int, true> the check boxes, radio buttons and switches among them; and the one Enter was pressed at, which waits for the window's shortcuts */
    private array $toggles = [];
    private ?int $enterToggle = null;
    /** @var array<int, array{0: TextState, 1: float, 2: float, 3: float, 4: float, 5: float}> id => state, text x, text y, line h, size, inner width (text areas) */
    private array $fields = [];
    /** @var array<int, Closure> the nodes taking their input as it comes (Node::handleInput), from last frame, and the one that took a press, which the pointer's moves and release go to wherever they are */
    private array $inputs = [];
    private ?int $captured = null;
    /** @var array<int, true> the nodes a press on which leaves the focus where it is (Node::keepFocus), from last frame */
    private array $keepers = [];
    /** @var array<int, array{0: TextInputClient, 1: bool}> the nodes taking text from the input methods (Node::handleTextInput), and whether the input methods compose for them */
    private array $textClients = [];
    /** Caret blinking: when it last restarted, and what the focused field looked like then. */
    private float $blinkStart = 0;
    private ?array $blinkSeen = null;
    public const BLINK = 0.5;
    /** After this long without edits the caret stays on and stops asking for frames. */
    public const BLINK_FOR = 30.0;
    private ?int $activated = null;
    /** The node the keys or assistive technology clicked this frame, whose tip goes. */
    private ?int $keyClicked = null;
    /** What was typed this frame at a focused control that is no field. */
    private string $typed = '';
    private float $typedAt = 0.0;
    /** Text drawn in a field starts this far from its left edge. */
    /** A field's padding, mygo's Padding(Space(1.5), Space(2.5)): FIELD_INSET at the sides, FIELD_INSET_Y above and below the text of an area. */
    public const FIELD_INSET = 10.0, FIELD_INSET_Y = 6.0;

    public function __construct(public Input $input, private ?TextMetrics $metrics = null)
    {
        $input->metrics = $metrics;
        $input->direct = $this->deliver(...);
        $this->openURL = fn(string $url) => null;
        $this->preferences = new Preferences();
        $clip = '';
        $this->readClipboard = function () use (&$clip): string { return $clip; };
        $data = new Data;
        $this->writeClipboard = function (string $s) use (&$clip, &$data) { $clip = $s; $data = Data::text($s); };
        $this->writeClipboardData = function (Data $d) use (&$clip, &$data) { // its text, and the data whole, as mygo's headless host keeps
            try { $clip = $d->read(Format::TEXT); } catch (FormatUnavailable) { $clip = ''; }
            $data = $d->formats() === [] ? new Data : $d->materialize($d->formats());
        };
        $this->readClipboardData = function (string ...$formats) use (&$data): Data {
            $have = array_values(array_intersect($data->formats(), $formats));
            return $have === [] ? new Data : $data->materialize($have);
        };
        $this->clipboardFormats = function () use (&$data): array { return $data->formats(); };
        $this->selection = new TextSelection();
        $this->initMenus();
    }

    /** Puts data on the clipboard, each representation of its items in the system's formats (mygo's WriteClipboardData): the window sets it to the system's pasteboard. @var Closure(Data): void */
    public Closure $writeClipboardData;

    /** Reads the representations of $formats the clipboard holds, of each item (mygo's ReadClipboardData): the window sets it to the system's pasteboard. @var Closure(string ...): Data */
    public Closure $readClipboardData;

    /** The formats on the clipboard, without reading them, as a paste decides what to read (mygo's ClipboardFormats): the window sets it to the system's pasteboard. @var Closure(): list<string> */
    public Closure $clipboardFormats;

    /** Write text to the clipboard and read it (mygo's Services' WriteClipboard and ReadClipboard), for what edits text itself, as a code editor: the window sets them to the system's pasteboard; without one, a clipboard of the runtime's own. */
    public Closure $writeClipboard, $readClipboard;
    /** When a frame is due of its own accord, as asked by after(): seconds of the clock, or null. */
    private ?float $wakeAt = null;

    /** Asks for a frame $seconds from now, for what changes then, as a caret's blink or a hover's delay (mygo's Context.After and Painter.After): the earliest asked since the last frame began. */
    public function after(float $seconds): void
    {
        $at = $this->clockNow() / 1e9 + max(0.0, $seconds);
        if ($this->wakeAt === null || $at < $this->wakeAt) { $this->wakeAt = $at; }
    }

    /** The time now, in nanoseconds: the clock's (tests'), else hrtime's. */
    private function clockNow(): int
    {
        return $this->clock !== null ? ($this->clock)() : hrtime(true);
    }

    /** @var list<Closure(): void> what runs once the window presented its next frame, once (afterFrame) */
    public array $afterFrame = [];

    /**
     * Runs $fn once the window has presented its next frame, in the same
     * turn of the run loop: work of a few milliseconds that waits for no
     * frame there (a video player's thumbnails), done where the next frame
     * has the most time. A window that draws no frame (a test's) never
     * runs it: give it a timer too.
     */
    public function afterFrame(Closure $fn): void
    {
        $this->afterFrame[] = $fn;
    }

    /** Runs what waited for the frame just presented. @internal Window */
    public function framePresented(): void
    {
        $fns = $this->afterFrame;
        $this->afterFrame = [];
        foreach ($fns as $fn) { $fn(); }
    }

    /** Asks for the next frame as soon as the display can show it, for what moves (mygo's AnimationFrame, Painter.AnimationFrame): while the frame is built (Context::animationFrame) or painted. */
    public function animationFrame(): void
    {
        if ($this->building) { // asked as the frame is built: kept past its end, which clears what came before
            $this->animating = true;
        } else {
            $this->needsFrame = true;
        }
    }

    /**
     * Builds the frame: drains input, runs $build (again if a click changed
     * state while building, at most three times), records the layout.
     * $build(Context) must build and lay out the tree and return its root.
     */
    public function frame(callable $build): Node
    {
        $this->inspector?->startFrame();
        foreach (array_splice($this->opened, 0) as $fn) { $fn(); } // what came of the URLs opened
        $this->clicks = [];
        $this->rightClicks = [];
        $this->middleClicks = []; $this->magnifies = []; $this->forceClicks = [];
        $this->doubleClicks = [];
        $this->slid = [];
        $this->steps = [];
        $this->jumps = [];
        $this->keys = [];
        $this->presses = []; $this->keyClicked = null;
        $this->wheeled = false;
        $this->windowAsks = [];
        [$this->overlayAskers, $this->overlayAsked] = [$this->overlayAsked, []];
        $this->outsideSaid = [];
        $now = hrtime(true);
        $this->frameNumber++;
        $this->metrics?->textSystem()->setLanguage(\MyPHP\App::locale()); // text naming no language is in the app's (mygo's Style.Lang from the locale)
        $this->now = $this->clockNow();
        if ($this->pointerPress !== null && !$this->pointerPress['down']) {
            $this->pointerPress = null; // the frame after it was let go saw it
        }
        $dt = $this->lastFrame === 0 ? 1 / 60 : min(0.1, ($now - $this->lastFrame) / 1e9);
        $this->lastFrame = $now;
        foreach ($this->scrolls as $state) {
            $state->glide($dt, $this->now);
        }
        $this->wakeAt = null; $this->animating = false; // what this frame's build and paint ask for
        foreach ($this->input->events as $e) { // what a node taking its input as it comes took never came here (Input::push)
            if ($this->inspector !== null && $this->inspector->event($this, $e)) {
                continue; // its keys, and the pointer while it picks
            }
            switch ($e[0]) {
                case 'down':
                    $this->input->mouseDown = true;
                    if ($e[3] === 1 && $this->menuPress($e[1], $e[2])) {
                        $this->menuTook = true;
                        break; // a context menu opens: the menu takes the press
                    }
                    if ($e[3] !== 0) {
                        break;
                    }
                    if (($t = $this->pressTarget($e[1], $e[2])) !== null && isset($this->menuNodes[$t]) && ($this->menuNodes[$t] & 2) !== 0) {
                        $this->pressAt($e[1], $e[2]); // the focus as any press moves it, none inside what keeps it (Node::keepFocus), as mygo's pointerDown
                        $this->openMenuButton($t); // a menu button's menu opens as it goes down, and takes the release
                        $this->menuRelease = true;
                        break;
                    }
                    if ($this->grabThumb($e[1], $e[2])) {
                        break;
                    }
                    if ($this->handles !== [] && $this->dragWindow !== null && $this->onHandle($e[1], $e[2])) {
                        ($this->dragWindow)($e[4]); // a title bar the view draws: the window moves, or zooms on a double click
                        break;
                    }
                    if ($e[4] >= 2) {
                        $this->doubleClicks[] = [$e[1], $e[2]];
                    }
                    if ($this->pressTextSelection($e[1], $e[2], ($e[5] ?? 0) & Key::MODIFIERS, max(1, $e[4]))) {
                        break; // a selection starts: the paragraph pressed has the focus
                    }
                    $this->presses[] = [$e[1], $e[2]];
                    $this->pressAt($e[1], $e[2]);
                    if ($e[4] >= 2 && $this->focus !== null && isset($this->fields[$this->focus])) {
                        $this->fields[$this->focus][0]->{$e[4] === 2 ? 'selectWord' : 'selectLine'}(); // a double click takes the word, a triple the line
                    }
                    $this->hold = [$e[1], $e[2], $this->now / 1e9, 0, false, $e[1], $e[2]];
                    foreach ($this->steppers as $id => $_) { // a press on a stepper's half steps it at once, though released before the frame is built (mygo's stepper press)
                        if (!isset($this->disabledIds[$id]) && ($dir = $this->holding($id)) !== 0) { $this->steps[$id] = ($this->steps[$id] ?? 0) + $dir; $this->hold[3] = 1; }
                    }
                    $this->pointerPress = ['id' => $this->pressTarget($e[1], $e[2]), 'track' => new DragTrack($e[6] ?? $this->now, $e[1], $e[2]), 'down' => true, 'released' => false, 'v' => [0.0, 0.0]];
                    $this->pressDragger = $this->pressDragger($this->pointerPress['id']);
                    foreach ($this->sliders as $id => $r) {
                        if ($e[1] >= $r[0] && $e[1] < $r[0] + $r[2] && $e[2] >= $r[1] && $e[2] < $r[1] + $r[3]) {
                            $this->sliding = $id;
                            $this->slideTo($id, $e[1], $e[2]);
                        }
                    }
                    break;
                case 'up':
                    $pressedHere = $this->input->mouseDown; // a release whose press was elsewhere (another window, the Dock opening this one under the pointer) clicks nothing, as mygo's Clicked asks for both
                    $this->input->mouseDown = false;
                    $this->selection->dragging = false;
                    $this->sliding = null;
                    $this->hold = null;
                    if ($e[3] === 0 && $this->menuRelease) {
                        $this->menuRelease = false;
                        break; // the menu button's menu took the release
                    }
                    if ($e[3] === 1 && $this->menuTook) {
                        $this->menuTook = false;
                        break; // the context menu took the press, and its release
                    }
                    $moved = $e[3] === 0 && $this->dragEnd($e[1], $e[2]); // a drag ended, dropping its value: no click
                    if ($e[3] === 0 && ($p = &$this->pointerPress) !== null && $p['down']) {
                        $p['down'] = false;
                        $p['released'] = $p['track']->n > 1; // a press that moved ends a drag; a click does not
                        $p['v'] = $p['track']->velocity($e[6] ?? $this->now); // timed by the events, the frame's time for those without (a test's)
                        $moved = $moved || ($p['id'] !== null && isset($this->draggables[$p['id']]) && $p['track']->far); // it moved the node (Context::draggable): no click
                    }
                    unset($p);
                    if ($this->drag !== null) {
                        $this->drag = null; // releasing the thumb is not a click
                    } elseif ($e[3] === 1) { $this->rightClicks[] = [$e[1], $e[2]]; } elseif ($e[3] === 2) { $this->middleClicks[] = [$e[1], $e[2]]; } elseif (!$moved && $pressedHere) { $this->clicks[] = [$e[1], $e[2], ($e[5] ?? 0) & Key::MODIFIERS]; }
                    break;
                case 'drag':
                    if ($this->pointerPress !== null && $this->pointerPress['down']) {
                        $this->pointerPress['track']->move($e[3] ?? $this->now, $e[1], $e[2]);
                        $this->dragMove($e[1], $e[2]);
                    }
                    if ($this->sliding !== null && isset($this->sliders[$this->sliding])) {
                        $this->slideTo($this->sliding, $e[1], $e[2]);
                    } elseif ($this->drag !== null) {
                        [$state, $last, $ratio, $horizontal, $from, $reach, $travel] = $this->drag;
                        if ($state instanceof ListState && !$horizontal) {
                            // As far through the rows as the thumb along its track, from where it started, though the rows measured
                            // meanwhile change how far they go; the end of the track shows the end (mygo's dragTo).
                            $along = ($reach > 0 ? $from / $reach : 0.0) + ($travel > 0 ? ($e[2] - $last) / $travel : 0.0);
                            if ($along >= 1) { $state->scrollToEnd(); } else { $state->scrollToOffset(max(0.0, $along) * $state->extent()[1]); }
                        } elseif ($horizontal) {
                            $state->scrollByX(($e[1] - $last) * $ratio);
                            $this->drag[1] = $e[1];
                        } else {
                            $state->scrollBy(($e[2] - $last) * $ratio);
                            $this->drag[1] = $e[2];
                        }
                    } elseif ($this->focus !== null && isset($this->fields[$this->focus]) && $this->input->mouseDown) {
                        $this->placeCaret($this->focus, $e[1], $e[2], true);
                    } elseif ($this->selection->dragging) {
                        $this->moveTextSelection($e[1], $e[2]);
                    }
                    break;
                case 'scroll': $this->scrollAt($e[1], $e[2], $e[3], $e[4]); $this->wheeled = true; break;
                case 'magnify': $this->magnifies[] = [$e[1], $e[2], (float)$e[3], (int)$e[4]]; break;
                case 'pressure':
                    if ($e[3] >= 2 && $this->pressureStage < 2) { $this->forceClicks[] = [$e[1], $e[2]]; } // once, as it goes deep
                    $this->pressureStage = (int)$e[3];
                    break;
                case 'key':
                    if ($e[2] === Key::ESCAPE && ($e[3] & Key::MODIFIERS) === 0 && $this->valueDrag !== null && !$this->valueDrag['canceled']) {
                        if ($this->valueDrag['native']) {
                            ($this->cancelDataDrag)(); // the system's: its end clears it
                        } else {
                            $this->valueDrag['canceled'] = true; $this->valueDrag['over'] = null; // Escape gives up the drag
                        }
                        break;
                    }
                    $this->keys[] = [$e[1], $e[2], $e[3] & Key::MODIFIERS, false, $e[5] ?? $e[1]]; // and what it types without its modifiers (charactersIgnoringModifiers)
                    if ((($e[2] === Key::CONTEXT_MENU && ($e[3] & Key::MODIFIERS) === 0) || ($e[2] === Key::F10 && ($e[3] & Key::MODIFIERS) === Key::SHIFT)) && $this->menuKey()) {
                        $this->keys[array_key_last($this->keys)][3] = true; // the menu key or Shift+F10 opens the focused node's context menu
                        break;
                    }
                    if (($e[3] & Key::MODIFIERS) === Key::COMMAND && in_array(strtolower($e[1]), ['a', 'c'], true) && $this->textSelectionCommand(strtolower($e[1]) === 'a' ? 'selectAll' : 'copy')) {
                        $this->keys[array_key_last($this->keys)][3] = true; // Cmd+A and Cmd+C on selectable text (mygo's textSelectionKey)
                    }
                    break;
                case 'edit': // an editing command of the menus with no node taking its input: the focused field's, or Copy and Select All on selectable text
                    if ($this->focus !== null && isset($this->fields[$this->focus])) { $this->editCommand($this->focus, $e[1]); } else { $this->textSelectionCommand($e[1]); }
                    break;
                case 'command': // what the text system made of the key just pushed; a focused control taking it keeps it from shortcut()
                    if ($this->command($e[1]) && $this->keys !== []) {
                        $this->keys[array_key_last($this->keys)][3] = true;
                    }
                    break;
                case 'insert':
                    if ($this->focus !== null && isset($this->fields[$this->focus]) && $this->keys !== []) {
                        $this->keys[array_key_last($this->keys)][3] = true; // typed into the field
                    } elseif ($e[1] === ' ' && $this->focus !== null && (($this->menuNodes[$this->focus] ?? 0) & 2) !== 0) {
                        $this->openMenuButton($this->focus); // and Space
                        if ($this->keys !== []) { $this->keys[array_key_last($this->keys)][3] = true; }
                    } elseif ($e[1] === ' ' && $this->focus !== null && !isset($this->fields[$this->focus]) && $this->now / 1e9 - $this->typedAt >= 1.0) {
                        $this->activated = $this->focus; // space presses a focused button; within a second of letters typed at it, it is typed too (mygo's typePause)
                        $this->focusVisible = true;
                        if ($this->keys !== []) { $this->keys[array_key_last($this->keys)][3] = true; }
                    } elseif ($this->focus !== null && !isset($this->fields[$this->focus])) {
                        $this->typed .= $e[1]; // typed at a focused control: a time input's segment takes digits (typed())
                        if ($this->keys !== []) { $this->keys[array_key_last($this->keys)][3] = true; }
                    }
                    break;
                case 'accessOn': $this->accessibilityOn(); break; // assistive technology asked for the window
                case 'access': $this->accessAction($e[1], $e[2], $e[3] ?? '', $e[4] ?? 0, $e[5] ?? 0); break; // ['access', id, AccessNode action, text, from, to]
            }
        }
        $this->input->events = [];
        $this->dragScroll();
        $this->scrollTextSelection();
        $this->clickable = []; // this frame's build asks again
        $this->steppers = []; // the keys above used last frame's; this frame's build registers again
        $wasFocus = $this->focus; $lastOverlays = $this->overlayIds; $this->overlayIds = [];
        $this->lastOverlayIds = $lastOverlays;
        $this->building = true;
        for ($pass = 0; $pass < 3; $pass++) {
            $this->consumed = false;
            $this->pass = $pass;
            $this->kept = []; $this->routers = [];
            $this->uses = [];
            $this->actions = [];
            if (isset($root)) { self::detach($root); } // the pass before's tree, which this one replaces
            $root = $build();
            if ($this->enterToggle !== null) { // Enter at a toggle no shortcut took presses it
                foreach ($this->keys as $i => $k) {
                    if (!$k[3] && ($k[1] === Key::RETURN || $k[1] === Key::ENTER) && $k[2] === 0) { $this->keys[$i][3] = true; $this->activated = $this->enterToggle; $this->consumed = true; }
                }
                $this->enterToggle = null;
            }
            if ($this->applyFocusRequests()) { $this->consumed = true; }
            if ($this->focusFields !== [] && $this->applyFocusBindings()) { $this->consumed = true; }
            if ($this->runActions()) { $this->consumed = true; } // what they changed shows in another pass
            $this->passes = $pass + 1;
            if (!$this->consumed) {
                break;
            }
            $this->clicks = [];
            $this->rightClicks = [];
            $this->middleClicks = [];
            $this->doubleClicks = [];
            if ($this->pointerPress !== null) {
                $this->pointerPress['released'] = false; // the pass handled the release (mygo's forgetInput)
            }
            $this->dropped = []; $this->dataDropped = []; $this->filesDropped = [];
        }
        $this->building = false;
        $this->commitFocusBindings();
        $this->revealIDs = []; // the frame's last pass showed them
        $this->resolveMenu();
        $this->dropped = []; $this->dataDropped = []; $this->filesDropped = []; // dropped on nodes the frame did not build
        foreach ($this->keys as $i => $k) { // Escape hides the tip that shows, before what else takes it (mygo's tip OverlayShortcut)
            if ($this->tip !== null && !$k[3] && $k[1] === Key::ESCAPE && $k[2] === 0) { $this->dismissTip(); $this->keys[$i][3] = true; }
        }
        if ($this->escape !== null) {
            foreach ($this->keys as $k) {
                if (!$k[3] && $k[1] === Key::ESCAPE && $k[2] === 0) { // no control nor shortcut took it
                    ($this->escape)();
                }
            }
        }
        $keyScrolled = false;
        foreach ($this->keys as $k) { // a key nothing took scrolls, as browsers' (mygo's scrollKey)
            if (!$k[3] && $this->scrollKey($k[1], $k[2])) { $keyScrolled = true; }
        }
        $this->animateLayout($root); // with the last frame's rects, before they go
        $this->keyClicked ??= $this->activated; // clicked by the keys, whether or not the build asked
        $this->activated = null; // taken by clicked() during the build, or dropped
        $this->typed = '';
        $this->rects = [];
        $this->frags = [];
        $this->order = [];
        $this->barrier = 0; $this->counter = 0; $this->overlays = []; $this->handles = []; $this->links = [];
        $this->sliders = [];
        $this->scrolls = [];
        $this->scrollAxes = [];
        $this->areas = [];
        $this->tips = [];
        $this->cursor = Cursor::Default;
        $this->focusables = []; $this->toggles = [];
        $this->memberOf = []; $this->groups = [];
        $this->fields = [];
        $this->inputs = []; $this->textClients = []; $this->keepers = [];
        $this->lists = [];
        $this->needsFrame = false;
        $this->shown = []; $this->worlds = []; $this->visible = []; $this->clippedBy = []; $this->clipAxes = []; $this->carets = [];
        $this->parents = []; $this->inertIds = []; $this->passing = []; $this->disabledIds = []; $this->attachedTo = []; $this->targets = []; $this->draggables = []; $this->dragSources = []; $this->dropTargets = [];
        $this->dataSources = []; $this->dataTargets = []; $this->fileTargets = [];
        $this->forgetSelectable();
        $this->menuNodes = []; $this->menuRtl = [];
        $this->modal = null;
        $this->record($root);
        $this->syncTextSelection();
        foreach ($this->locals as $id => $_) { // a node not built takes its state with it, but in a page a router keeps
            if (!isset($this->rects[$id]) && !$this->keptAlive($id)) { unset($this->locals[$id]); }
        }
        foreach ($this->pageOf as $id => $_) {
            if (!isset($this->rects[$id]) && !$this->keptAlive($id)) { unset($this->pageOf[$id]); }
        }
        $this->lastRouters = array_flip($this->routers);
        if ($this->valueDrag !== null && $this->valueDrag['native'] && !isset($this->rects[$this->valueDrag['src']])) {
            ($this->cancelDataDrag)(); // its source went: its end clears it
        }
        $this->syncDropFormats();
        foreach ([&$this->motions, &$this->colorMotions, &$this->sequences, &$this->loops, &$this->timelines, &$this->poses, &$this->contents, &$this->dragRecs] as &$moving) {
            $moving = array_intersect_key($moving, $this->rects); // a node not built takes its values with it
        }
        unset($moving);
        if ($this->focus !== null && !$this->reachable($this->focus)) { // the focus was behind what now blocks it: into its first control, or none (mygo's Modal)
            $into = null;
            foreach ($this->focusables as $id) { if ($this->reachable($id)) { $into = $id; break; } }
            $this->focus = $into;
        }
        $this->needsFrame = $this->needsFrame || $keyScrolled || $this->animating || ($this->hold[4] ?? false) // a held stepper keeps stepping
            || ($this->inspector?->stale() ?? false); // the inspector shows the nodes of this frame in the next
        $this->showTip();
        foreach ($this->overlayIds as $id => $_) { // an overlay born this frame notes who had the focus
            if (!isset($lastOverlays[$id]) && !isset($this->openers[$id])) { $this->openers[$id] = $wasFocus; }
        }
        foreach ($this->openers as $id => $opener) { // one gone with the focus inside gives it back
            if (isset($this->overlayIds[$id])) { continue; }
            unset($this->openers[$id]);
            if (($this->focus === null || !isset($this->rects[$this->focus])) && $opener !== null && isset($this->rects[$opener])) { $this->focus = $opener; }
        }
        if ($this->focus !== null && !isset($this->rects[$this->focus])) {
            $this->focus = null; // the focused node was not built this frame
        }
        $this->input->focus = null;
        $this->input->caretAt = null;
        $was = $this->input->client;
        [$this->input->client, $this->input->inputMethod] = $this->focus !== null && isset($this->textClients[$this->focus]) ? $this->textClients[$this->focus] : [null, true];
        if ($was !== null && $was !== $this->input->client && $was->markedRange() !== null) {
            $was->unmarkText(); // the focus left it composing: what was composed stays, no longer marked (mygo's focus loss)
        }
        if ($this->input->client !== null) { $this->input->clientOrigin = [$this->rects[$this->focus][0], $this->rects[$this->focus][1]]; }
        $this->input->world = $this->focus !== null ? $this->worldOf($this->focus) : Affine::identity(); // the caret and the client's rectangles show through the focused node's transforms
        $this->input->textCaret = $this->input->client === null && $this->focus !== null ? ($this->carets[$this->focus] ?? null) : null;
        if ($this->input->textCaret === null) { $this->input->composing = ''; }
        if ($this->focus !== null && isset($this->fields[$this->focus])) {
            [$state, $tx, $ty, $lh, $size, $width] = $this->fields[$this->focus];
            $this->input->focus = $state;
            $this->input->focusText = [$tx, $ty, $lh, $size];
            if ($state->multiline && $this->metrics !== null) {
                $m = $this->metrics;
                $this->input->caretAt = function (int $byte) use ($state, $m, $size, $width, $tx, $ty, $lh): array {
                    [$x, $y] = $state->layout($m, $size, $width)->caret($m, $byte);
                    return [$tx + $x, $ty + $y - $state->scrollY, 1.0, $lh];
                };
            }
            $seen = [$state, $state->text, $state->cursor, $state->anchor, $state->marked];
            if ($this->blinkSeen !== $seen) {
                $this->blinkSeen = $seen;
                $this->blinkStart = $now / 1e9;
            }
        } else {
            $this->blinkSeen = null;
        }
        if ($this->root !== null && $this->root !== $root) { self::detach($this->root); } // the frame before's, which nothing draws or asks again
        $this->root = $root;
        $this->describe();
        $this->showMenu();
        return $root;
    }

    /**
     * Lets a tree no frame uses go when the last reference to it does: each
     * node's context, which holds the root, made the tree a cycle that only
     * PHP's collector freed, a run long enough to drop a frame every few
     * frames of a scrolling list.
     */
    private static function detach(Node $root): void
    {
        $stack = [$root];
        while (($n = array_pop($stack)) !== null) {
            $n->context = null;
            foreach ($n->kids as $k) { $stack[] = $k; }
        }
    }

    /** Whether the focused field's caret is in the shown half of its blink (always, once it has rested a while). */
    public function caretVisible(): bool
    {
        $phase = hrtime(true) / 1e9 - $this->blinkStart;
        return $phase >= self::BLINK_FOR || ((int)floor($phase / self::BLINK)) % 2 === 0;
    }

    private bool $tipPending = false;

    /** Whether the window is the key window, as of the frame: one that lost the keyboard lets go of the modifiers held, whose releases go to the window that has it (mygo's SetFocused). */
    public function setWindowFocus(bool $on): void
    {
        if (!$on && $this->windowFocus) { $this->input->mods = 0; }
        $changed = $on !== $this->windowFocus;
        $this->windowFocus = $on;
        if ($changed) { $this->pauseToasts(false); } // the toasts' time stops with the window in the background, from now
    }

    /** Hides the tip that shows until neither the pointer nor the focus is on its node. */
    public function dismissTip(): void
    {
        $this->tipDismissed = $this->tipAnchor;
        $this->tip = null; $this->tipAnchor = null; $this->tipByFocus = false;
    }

    /**
     * Decides the tip that shows after the frame (mygo's showTooltip): the
     * innermost node with one under the pointer once it rested there
     * TIP_DELAY, at once within TIP_WARM of another tip going, or the one
     * with the keyboard focus from the keyboard, whichever came last. A
     * press on its node, or a click by the keys, hides it as Escape does;
     * a disabled node shows none.
     */
    private function showTip(): void
    {
        $now = $this->now / 1e9; // the frame's time: a test's clock
        if ($this->tip !== null) { $this->tipLastShown = $now; } // the tip of the last frame showed until now
        $hover = null;
        foreach ($this->tips as $id => $_) {
            if ($this->hovered($id) && !isset($this->disabledIds[$id])) { $hover = $id; } // the innermost, built after those around it
        }
        if ($hover !== $this->tipHover) { $this->tipHover = $hover; $this->tipHoverSince = $now; }
        $focus = $this->focusVisible && $this->windowFocus && $this->focus !== null && isset($this->tips[$this->focus]) && !isset($this->disabledIds[$this->focus]) ? $this->focus : null;
        if ($focus !== $this->tipFocus) { $this->tipFocus = $focus; $this->tipFocusSince = $now; }
        if ($this->tipDismissed !== $hover && $this->tipDismissed !== $focus) { $this->tipDismissed = null; }
        if ($hover !== null && ($this->input->mouseDown || array_filter($this->presses, fn(array $p) => $this->under($hover, $p[0], $p[1])) !== [])) { $this->tipDismissed = $hover; } // a press hides it, as Base UI's closeOnClick
        if ($this->keyClicked !== null && isset($this->tips[$this->keyClicked])) { $this->tipDismissed = $this->keyClicked; } // a click by the keys too
        if ($hover === $this->tipDismissed) { $hover = null; }
        if ($focus === $this->tipDismissed) { $focus = null; }
        $shown = null; $this->tipByFocus = false; $this->tipPending = false;
        if ($focus !== null && ($hover === null || $this->tipFocusSince >= $this->tipHoverSince)) {
            $shown = $focus; $this->tipByFocus = true;
        } elseif ($hover !== null) {
            $warm = $this->tipHoverSince - $this->tipLastShown <= self::TIP_WARM;
            if ($warm || $now - $this->tipHoverSince >= self::TIP_DELAY) { $shown = $hover; } else { $this->tipPending = true; }
        }
        $this->tip = $shown === null ? null : [$this->tips[$shown], ...$this->rects[$shown]]; // the text and the anchor's rect
        $this->tipAnchor = $shown;
        if ($shown !== null && $this->tips[$shown] === '') { $this->needsFrame = true; } // a tip of its own, which the next build makes
    }

    /** Seconds until the window should draw a frame of its own accord (a caret toggle, a tip due, a toast going), or null. */
    public function nextWake(): ?float
    {
        $wake = $this->nextBlink();
        if ($this->wakeAt !== null) {
            $at = max(0.0, $this->wakeAt - $this->clockNow() / 1e9);
            $wake = $wake === null ? $at : min($wake, $at);
        }
        if ($this->tipPending) {
            $tip = max(0.0, self::TIP_DELAY - ($this->now / 1e9 - $this->tipHoverSince));
            $wake = $wake === null ? $tip : min($wake, $tip);
        }
        foreach ($this->toastsPaused ? [] : $this->toasts as $e) { // the next toast whose time is over
            [, $left] = $this->toastTimes($e['t']->id);
            if ($e['t']->life() > 0) { $wake = $wake === null ? $left : min($wake, $left); }
        }
        return $wake;
    }

    /** The state kept for node $id across frames, made by $make the first time. */
    /** How many nodes keep local state: a diagnostic. */
    public function localCount(): int
    {
        return count($this->locals);
    }

    public function local(int $id, Closure $make): mixed
    {
        return $this->locals[$id] ??= $make();
    }

    /**
     * Where the last frame showed web views, in the order it painted them
     * (mygo's webPlaces): the view, its node, its frame and the part of it
     * that shows, in the window's points. The painter notes them.
     * @var list<array{view: object, id: int, frame: array{0: float, 1: float, 2: float, 3: float}, clip: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public array $webPlaces = [];
    /**
     * Where the last frame showed videos (Context::video), as $webPlaces
     * for their players. The picture takes no pointer: what is built over
     * it, or the node itself, does.
     * @var list<array{view: object, id: int, frame: array{0: float, 1: float, 2: float, 3: float}, clip: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public array $videoPlaces = [];
    /**
     * Where the last frame showed system materials (Node::systemMaterial):
     * the node, its frame and the part of it that shows, the material, its
     * appearance, corner radius and opacity, for the window to place them.
     * @var list<array{id: int, frame: array{0: float, 1: float, 2: float, 3: float}, clip: array{0: float, 1: float, 2: float, 3: float}, material: string, dark: ?bool, radius: float, alpha: float}>
     */
    public array $materialPlaces = [];
    /** @internal Window: whether it paints the nodes belowMaterials() in a layer of their own, under its materials (else they paint where they are) */
    public bool $underLayer = false;
    /**
     * @internal the subtrees the window paints in that layer, as the frame met them: its transform, opacity and clip there
     * @var list<array{node: Node, world: Affine, opacity: float, clip: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public array $underPaints = [];
    /** @internal while the window paints its layer under the materials */
    public bool $paintingUnder = false;

    /** Whether the focus came from the keyboard, as Tab moving it onto a web view's node, which enters its page from its first element, or its last with Shift (mygo's TabInto). */
    public function focusFromKeyboard(): bool
    {
        return $this->focusVisible;
    }

    /** The web view that takes the pointer at ($x, $y) (mygo's webViewAt): one shown there, whose node is what takes the pointer there, nothing painted over it taking it. */
    public function webViewAt(float $x, float $y): ?object
    {
        $top = $this->pressTarget($x, $y);
        foreach (array_reverse($this->webPlaces) as $p) {
            [$cx, $cy, $cw, $ch] = $p['clip'];
            if ($p['id'] === $top && $x >= $cx && $x < $cx + $cw && $y >= $cy && $y < $cy + $ch) {
                return $p['view'];
            }
        }
        return null;
    }

    /** The innermost node under ($x, $y) last frame that takes the pointer (mygo's interactive): clickable, focusable, draggable, a field, a link or a slider; null for none. */
    private function pressTarget(float $x, float $y): ?int
    {
        $best = null; $at = -1;
        foreach ([array_keys($this->clickable), $this->focusables, array_keys($this->draggables), array_keys($this->dragSources), array_keys($this->dataSources), array_keys($this->fields), $this->links, array_keys($this->sliders)] as $ids) {
            foreach ($ids as $id) {
                if (($this->order[$id] ?? -1) > $at && $this->under($id, $x, $y)) {
                    $best = $id; $at = $this->order[$id];
                }
            }
        }
        return $best;
    }

    /** Whether $value is of $type: a class or interface it is an instance of, or one of PHP's types as get_debug_type names them ('string', 'int', 'array'). */
    public static function isOf(mixed $value, string $type): bool
    {
        return $value instanceof $type || get_debug_type($value) === $type;
    }

    /** The node around $target, the node pressed, that drags a value, as a row around a button, when $target only takes clicks: one taking the pointer's moves (a field, a slider, a draggable or a value of its own to drag) keeps them. */
    private function pressDragger(?int $target): ?int
    {
        if ($target === null || $this->drags($target) || isset($this->fields[$target]) || isset($this->sliders[$target]) || isset($this->draggables[$target]) || isset($this->inputs[$target])) {
            return null;
        }
        for ($id = $this->parents[$target] ?? 0, $n = 0; $id !== 0 && $n < 1024; $id = $this->parents[$id] ?? 0, $n++) {
            if ($this->drags($id)) {
                return $id;
            }
        }
        return null;
    }

    /** Starts dragging the value of the source pressed once the pointer moved far enough, and follows the node taking it under the pointer (mygo's dragMove). */
    private function dragMove(float $x, float $y): void
    {
        $p = &$this->pointerPress;
        $src = $p['id'];
        if ($src === null || !$this->drags($src)) {
            // The press goes to the node around the one pressed that drags a value, once it moves as far as starts a drag: the node pressed has no click then.
            $a = $this->pressDragger;
            $dx = $x - $p['track']->ox; $dy = $y - $p['track']->oy;
            if ($a === null || $this->valueDrag !== null || $dx * $dx + $dy * $dy < self::DRAG_START * self::DRAG_START) {
                return;
            }
            $p['id'] = $src = $a; $this->pressDragger = null;
        }
        if ($this->valueDrag === null) {
            $dx = $x - $p['track']->ox; $dy = $y - $p['track']->oy;
            if ($dx * $dx + $dy * $dy < self::DRAG_START * self::DRAG_START) {
                return;
            }
            [$lx, $ly] = $this->toLocal($src, $p['track']->ox, $p['track']->oy) ?? [0.0, 0.0]; // from its top left; a node turned singular took no press
            $native = $this->dataSources[$src] ?? null;
            $this->valueDrag = ['src' => $src, 'value' => $this->dragSources[$src] ?? null, 'offX' => $lx, 'offY' => $ly, 'over' => null, 'canceled' => false, 'elem' => null, 'native' => $native !== null];
            if ($native !== null) {
                $this->startNative($src, $native, $x, $y, $lx, $ly);
                return;
            }
        }
        if ($this->valueDrag['native'] || $this->valueDrag['canceled']) {
            return;
        }
        $this->valueDrag['over'] = $this->valueTarget($x, $y, $this->valueDrag['value']);
    }

    /** Whether node $id drags a value (Context::drag) or data (dragData), last frame. */
    private function drags(int $id): bool
    {
        return isset($this->dragSources[$id]) || isset($this->dataSources[$id]);
    }

    /** Hands the drag of source $src to the system (mygo's dragMove with a data source): the data made now, its own value offered to this process's targets; its end clears it, and tells the source. */
    private function startNative(int $src, array $source, float $x, float $y, float $lx, float $ly): void
    {
        [$make, $o] = $source;
        [$preview, $hotspot] = $o->preview === null ? $this->dataPreview($src, $lx, $ly) : [$o->preview, $o->hotspot];
        $done = function (Result $r) use ($o): void {
            $this->valueDrag = null;
            $this->input->mouseDown = false;
            if ($this->pointerPress !== null) { $this->pointerPress['down'] = false; } // the system took the press's release
            $this->clearDataOver();
            $this->changed();
            if ($o->done !== null) { ($o->done)($r); }
        };
        $error = ($this->startDataDrag)($make(), $this->valueDrag['value'], new DragOptions($o->operations, $preview, $hotspot, $done), $x, $y);
        if ($error !== null) {
            $done(new Result(error: $error));
        }
    }

    /**
     * The image of node $id as the last frame showed it, its visible part
     * at one pixel a point, at most 512 on a side, and the point of it the
     * press at ($lx, $ly) in the node took (mygo's dataPreview); none for
     * a node showing nothing.
     *
     * @return array{0: ?Image, 1: array{0: int, 1: int}}
     */
    private function dataPreview(int $id, float $lx, float $ly): array
    {
        [$vx, $vy, $vw, $vh] = $this->visible[$id] ?? [0.0, 0.0, 0.0, 0.0];
        $w = min((int)$vw, 512); $h = min((int)$vh, 512);
        if ($w <= 0 || $h <= 0) {
            return [null, [0, 0]];
        }
        $r = $this->rects[$id];
        [$x, $y] = ($this->worlds[$id] ?? Affine::identity())->point($r[0] + $lx, $r[1] + $ly);
        return [($this->snapshot)($vx, $vy, $w, $h), [min(max((int)($x - $vx), 0), $w - 1), min(max((int)($y - $vy), 0), $h - 1)]];
    }

    /**
     * The innermost node at ($x, $y) taking the native drag $d (mygo's
     * dataTargetAt), with $d's effect and the formats it takes set: a node
     * taking its local value by type, else by its formats and operations.
     */
    private function dataTargetAt(float $x, float $y, DataDragEvent $d): ?int
    {
        $d->operation = Operation::NONE; $d->formats = [];
        $hits = [];
        foreach ([...array_keys($this->dropTargets), ...array_keys($this->dataTargets)] as $id) {
            if (!isset($hits[$id]) && $this->under($id, $x, $y)) { $hits[$id] = $this->order[$id]; }
        }
        arsort($hits); // innermost first
        foreach ($hits as $id => $_) {
            if (isset($this->dropTargets[$id]) && $d->local !== null && self::isOf($d->local, $this->dropTargets[$id])) {
                $d->operation = Operation::negotiate($d->offer->operations, Operation::COPY, $d->offer->suggested);
                if ($d->operation !== Operation::NONE) {
                    return $id;
                }
            }
            if (($o = $this->dataTargets[$id] ?? null) !== null) {
                foreach ($o->formats as $f) {
                    if ($f !== Format::DRAG_SESSION && in_array($f, $d->offer->formats, true) && !in_array($f, $d->formats, true)) { $d->formats[] = $f; }
                }
                $d->operation = Operation::negotiate($d->offer->operations, $o->operations, $d->offer->suggested);
                if ($d->formats !== [] && $d->operation !== Operation::NONE) {
                    return $id;
                }
                $d->formats = [];
            }
        }
        $d->operation = Operation::NONE;
        return null;
    }

    /** A native drag over ($x, $y): whether a node takes it there, which then shows it (mygo's dataDragOver). */
    public function dataDragOver(float $x, float $y, DataDragEvent $d): bool
    {
        $this->incoming = $d;
        $this->dataOver = $this->dataTargetAt($x, $y, $d);
        $this->input->mouse($x, $y);
        $this->needsFrame = true; $this->changed();
        return $this->dataOver !== null;
    }

    /** The native drag left the window, or dropped. */
    public function clearDataOver(): void
    {
        if ($this->incoming !== null) {
            $this->incoming = null; $this->dataOver = null;
            $this->changed();
        }
    }

    /** A native drag dropped at ($x, $y): whether a node took it, its value by type or the formats it takes, read now (mygo's dataDrop). */
    public function dataDrop(float $x, float $y, DataDragEvent $d): bool
    {
        $this->clearDataOver();
        $id = $this->dataTargetAt($x, $y, $d);
        if ($id === null) {
            return false;
        }
        if (isset($this->dropTargets[$id]) && $d->local !== null && self::isOf($d->local, $this->dropTargets[$id])) {
            $this->dropped[$id] = [$d->local, $x, $y];
        } else {
            try {
                $this->dataDropped[$id] = new Drop($d->data->materialize($d->formats), $d->operation);
            } catch (\Throwable) {
                $d->operation = Operation::NONE; // a representation failed: no drop
                return false;
            }
        }
        $this->changed();
        return true;
    }

    /** The native drag over node $id, last told: its offer; null when none is over it. */
    public function dataOffer(int $id): ?Offer
    {
        return $this->incoming !== null && $this->dataOver === $id ? $this->incoming->offer : null;
    }

    /** The local value of a native drag of this process over node $id, for its type's targets (Context::dragOver); null when none. */
    public function incomingLocal(int $id): mixed
    {
        return $this->incoming !== null && $this->dataOver === $id ? $this->incoming->local : null;
    }

    /** The transfer dropped on node $id since the last pass, or null. */
    public function dataDroppedOn(int $id): ?Drop
    {
        if (!isset($this->dataDropped[$id])) {
            return null;
        }
        $this->consumed = true;
        return $this->dataDropped[$id];
    }

    /** The innermost node at ($x, $y) taking files, or null. */
    private function fileTarget(float $x, float $y): ?int
    {
        $best = null; $at = -1;
        foreach ($this->fileTargets as $id => $_) {
            if ($this->order[$id] > $at && $this->under($id, $x, $y)) { $best = $id; $at = $this->order[$id]; }
        }
        return $best;
    }

    /** Files dragged from another app over ($x, $y), or out of the window for negative coordinates: whether a node takes them there (mygo's fileDrag). */
    public function fileDrag(float $x, float $y): bool
    {
        $over = $x >= 0 && $y >= 0 ? $this->fileTarget($x, $y) : null;
        if ($over !== $this->fileOver) {
            $this->fileOver = $over;
            $this->changed();
        }
        return $over !== null;
    }

    /** Files dropped at ($x, $y): whether a node took them (mygo's fileDrop). @param list<string> $paths */
    public function fileDrop(float $x, float $y, array $paths): bool
    {
        $this->fileDrag(-1, -1);
        $id = $this->fileTarget($x, $y);
        if ($id === null || $paths === []) {
            return false;
        }
        $this->filesDropped[$id] = [...($this->filesDropped[$id] ?? []), ...$paths];
        $this->changed();
        return true;
    }

    /** The files dropped on node $id since the last pass, or null. @return ?list<string> */
    public function filesDroppedOn(int $id): ?array
    {
        if (!isset($this->filesDropped[$id])) {
            return null;
        }
        $this->consumed = true;
        return $this->filesDropped[$id];
    }

    /** Whether files dragged from another app are over node $id. */
    public function filesOver(int $id): bool
    {
        return $this->fileOver === $id;
    }

    /** Tells the view the formats the drop targets take, when they changed (mygo's syncDropFormats): files, URLs and this process's drags always. */
    private function syncDropFormats(): void
    {
        $formats = [Format::FILE_LIST, Format::URI_LIST, Format::DRAG_SESSION];
        foreach ($this->dataTargets as $o) {
            foreach ($o->formats as $f) {
                if (!in_array($f, $formats, true)) { $formats[] = $f; }
            }
        }
        sort($formats);
        if ($formats !== $this->dropFormats) {
            $this->dropFormats = $formats;
            if ($this->setDropFormats !== null) { ($this->setDropFormats)($formats); }
        }
    }

    /** The innermost node at ($x, $y) taking $value, or null. */
    private function valueTarget(float $x, float $y, mixed $value): ?int
    {
        $best = null; $at = -1;
        foreach ($this->dropTargets as $id => $type) {
            if (($this->order[$id] ?? -1) > $at && self::isOf($value, $type) && $this->under($id, $x, $y)) {
                $best = $id; $at = $this->order[$id];
            }
        }
        return $best;
    }

    /** Drops the value dragged on the node taking it under ($x, $y), and reports whether a drag ended, which is no click (mygo's dragEnd). */
    private function dragEnd(float $x, float $y): bool
    {
        $d = $this->valueDrag;
        if ($d === null) {
            return false;
        }
        if ($d['native']) {
            return true; // the system's: its end clears it
        }
        $this->valueDrag = null;
        if (!$d['canceled'] && ($t = $this->valueTarget($x, $y, $d['value'])) !== null) {
            $this->dropped[$t] = [$d['value'], $x, $y];
        }
        $this->needsFrame = true;
        return true;
    }

    /** Scrolls the scrolling container under the pointer while a value is dragged near its top or bottom edge, faster nearer to it (mygo's dragScroll). */
    private function dragScroll(): void
    {
        $d = $this->valueDrag;
        if ($d === null || $d['canceled'] || $d['native']) {
            return;
        }
        $x = $this->input->mouseX; $y = $this->input->mouseY;
        $best = null; $at = -1;
        foreach ($this->scrolls as $id => $_) {
            if ($this->scrollAxes[$id][1] && $this->order[$id] > $at && $this->under($id, $x, $y)) {
                $best = $id; $at = $this->order[$id];
            }
        }
        if ($best === null) {
            return;
        }
        $edge = 32;
        $ly = ($this->toLocal($best, $x, $y) ?? [0.0, 0.0])[1]; // from its top
        $top = $ly; $bottom = $this->rects[$best][3] - $ly;
        $dy = $top < $edge ? -($edge - $top) / 2 : ($bottom < $edge ? ($edge - $bottom) / 2 : 0.0);
        $st = $this->scrolls[$best];
        $was = $st->extent()[0];
        if ($dy != 0) {
            $st->scrollBy($dy);
        }
        if ($st->extent()[0] != $was) {
            $this->animating = true;
            $this->valueDrag['over'] = $this->valueTarget($x, $y, $d['value']); // the node under the pointer changes as the content moves
        }
    }

    /** The node $n dragging $value this frame (Context::drag): the copy following the pointer is of it, and the value as the frame has it. */
    public function dragSource(Node $n, mixed $value): void
    {
        if ($this->valueDrag !== null && $this->valueDrag['src'] === $n->id) {
            $this->valueDrag['elem'] = $n;
            if (!$this->valueDrag['native']) {
                $this->valueDrag['value'] = $value; // the value as the frame has it
            }
        }
    }

    /** The value dropped on node $id since the last pass, and where: [value, x, y]; null for none. */
    public function droppedOn(int $id): ?array
    {
        if (!isset($this->dropped[$id])) {
            return null;
        }
        $this->consumed = true;
        return $this->dropped[$id];
    }

    /** A velocity in the window's axes in node $id's, as its transform turns and scales it last frame. @param array{0: float, 1: float} $v @return array{0: float, 1: float} */
    public function alongAxes(int $id, array $v): array
    {
        $inv = isset($this->worlds[$id]) ? $this->worlds[$id]->inverse() : null;
        if ($inv === null) {
            return $v;
        }
        [$ox, $oy] = $inv->point(0, 0);
        [$x, $y] = $inv->point($v[0], $v[1]);
        return [$x - $ox, $y - $oy];
    }

    /** Whether the last press since the last frame was outside node $id: on neither it, nor what is in it, nor the node it is attached to, nor what is attached to a node inside it (Context::pressedOutside); once a frame, as a click is. */
    public function pressedOutside(int $id): bool
    {
        if ($this->presses === [] || !isset($this->rects[$id]) || isset($this->outsideSaid[$id])) {
            return false;
        }
        [$x, $y] = $this->presses[array_key_last($this->presses)];
        foreach ($this->rects as $other => $_) {
            if ($this->under($other, $x, $y) && $this->inside($other, $id)) {
                return false;
            }
        }
        $this->consumed = true;
        $this->outsideSaid[$id] = true;
        return true;
    }

    /** Whether node $id is $of, inside it or inside the node $of is attached to (Node::attachTo), an attached node going with what it is attached to, last frame. */
    private function inside(int $id, int $of): bool
    {
        $anchor = $this->attachedTo[$of] ?? null;
        $seen = [];
        while ($id !== 0 && !isset($seen[$id])) { // attachments may go round
            if ($id === $of || $id === $anchor) {
                return true;
            }
            $seen[$id] = true;
            $id = $this->attachedTo[$id] ?? $this->parents[$id] ?? 0;
        }
        return false;
    }

    /** Whether $key with $mods was pressed for overlay $id: none built over it last frame asked for keys (Context::overlayShortcut). */
    public function overlayShortcut(int $id, int|string $key, int $mods): bool
    {
        $this->overlayAsked[$id] = true;
        $at = $this->order[$id] ?? 0;
        foreach ($this->overlayAskers as $other => $_) {
            if ($other !== $id && ($this->order[$other] ?? 0) > $at && $this->reachable($other)) {
                return false; // the one on top takes them
            }
        }
        return $this->shortcut($key, $mods);
    }

    /** The left press on node $id: still down, or let go since the last frame; null for none. @return ?array{id: ?int, track: DragTrack, down: bool, released: bool, v: array{0: float, 1: float}} */
    public function pressOf(int $id): ?array
    {
        return $this->pointerPress !== null && $this->pointerPress['id'] === $id ? $this->pointerPress : null;
    }

    /** Whether the pointer holds node $id down; the frame the press lands in builds again, so that what the press chose shows in it (mygo's Pressed). */
    public function holds(int $id): bool
    {
        if (($this->pressOf($id)['down'] ?? false) !== true) { return false; }
        if ($this->presses !== [] && $this->pass === 0) { $this->consumed = true; }
        return true;
    }

    /** The map from the window to the axes of node $id's parent as the last frame showed them, null when it has none (a singular transform). */
    public function parentSpace(int $id): ?Affine
    {
        $p = $this->parents[$id] ?? 0;
        return $p === 0 ? Affine::identity() : $this->worldOf($p)->inverse();
    }

    /** The size of node $id's parent last frame, else of the node. @return array{0: float, 1: float} */
    public function parentSize(int $id): array
    {
        $r = $this->rects[$this->parents[$id] ?? 0] ?? $this->rects[$id] ?? [0, 0, 0, 0];
        return [$r[2], $r[3]];
    }

    /** Whether node $id is $ancestor or inside it, in the last frame. */
    public function within(int $id, int $ancestor): bool
    {
        for ($n = 0; isset($this->parents[$id]) && $n < 1024; $n++) {
            if ($id === $ancestor) {
                return true;
            }
            $id = $this->parents[$id];
        }
        return false;
    }

    /** Whether node $id was in an overlay (a popover, a menu, a dialog) in the last frame. */
    public function inOverlay(int $id): bool
    {
        for ($n = 0; isset($this->parents[$id]) && $n < 1024; $n++) {
            if (isset($this->lastOverlayIds[$id])) {
                return true;
            }
            $id = $this->parents[$id];
        }
        return false;
    }

    /** Keeps, this pass, the state of the nodes of page $id, which is not built: one of a router's history (Router's). */
    public function keep(int $id): void
    {
        $this->kept[$id] = true;
    }

    public function isKept(int $id): bool
    {
        return isset($this->kept[$id]);
    }

    /** Whether the state of node $id, not built, is in a page a router keeps: one of its history, or inside one. */
    private function keptAlive(int $id): bool
    {
        if ($this->kept === []) {
            return false;
        }
        for ($n = 0; $n < 64; $n++) {
            if (isset($this->kept[$id])) {
                return true;
            }
            $id = $this->pageOf[$id] ?? 0;
            if ($id === 0) {
                return false;
            }
        }
        return false;
    }

    /** Notes the box of a router being built, and whether it is the first of the frame (Router::view). */
    public function addRouter(int $box): bool
    {
        $this->routers[] = $box;
        return count($this->routers) === 1;
    }

    /** The box of the innermost router node $id was in last frame, or null. */
    public function routerOf(?int $id): ?int
    {
        for ($n = 0; $id !== null && isset($this->parents[$id]) && $n < 1024; $n++) {
            if (isset($this->lastRouters[$id])) {
                return $id;
            }
            $id = $this->parents[$id];
        }
        return null;
    }

    /** @var list<array{0: ?Node, 1: string, 2: Closure, 3: int|string, 4: int, 5: ?Handle}> what Node::onClick and the others ask for this pass: the node (none for the window's shortcuts, or a handle's, resolved once the pass is built), the kind, what runs, the key and its modifiers (mygo's actions) */
    private array $actions = [];
    /** @var list<\WeakReference<Handle>> the handles bound in this window */
    private array $focusHandles = [];

    /** Runs $fn after this pass's build when $kind ('click', 'change', 'submit', 'shortcut') happens to $n, to the node $handle is bound to in the pass, or, with neither, a shortcut of the window (mygo's actions). */
    public function addAction(?Node $n, string $kind, Closure $fn, int|string $key = 0, int $mods = 0, ?Handle $handle = null): void
    {
        $this->actions[] = [$n, $kind, $fn, $key, $mods, $handle];
    }

    /** Notes a handle bound in this window, whose focus requests the passes apply. @internal */
    public function addHandle(Handle $h): void
    {
        $this->focusHandles[] = \WeakReference::create($h);
    }

    /** @var array<string, array{ref: mixed, last: mixed, wanted: mixed, pending: bool, members: list<array{0: mixed, 1: Node}>, committed: list<array{0: mixed, 1: int}>}> the fields focusBind binds, by the reference to each */
    private array $focusFields = [];

    /** Binds the focus to $value of the app's field $field for node $n in this pass (Node::focusBind). @internal */
    public function focusBind(mixed &$field, mixed $value, Node $n): void
    {
        $key = \ReflectionReference::fromArrayElement([&$field], 0)->getId();
        if (!isset($this->focusFields[$key])) {
            $this->focusFields[$key] = ['ref' => &$field, 'last' => $field, 'wanted' => $field, 'pending' => $field !== null, 'members' => [], 'committed' => [], 'pass' => -1];
        }
        $f = &$this->focusFields[$key];
        if ($f['pass'] !== $this->frameNumber * 8 + $this->pass) { $f['members'] = []; $f['pass'] = $this->frameNumber * 8 + $this->pass; } // a new pass binds anew
        foreach ($f['members'] as [$v]) {
            if ($v === $value) { throw new \LogicException('focusBind: a value bound twice to one field in a window'); }
        }
        $f['members'][] = [$value, $n];
    }

    /** The value of the member of $field that has the focus, as the last frame committed it, or null (Context::focusedValue). */
    public function focusedValue(mixed &$field): mixed
    {
        $f = $this->focusFields[\ReflectionReference::fromArrayElement([&$field], 0)->getId()] ?? null;
        if ($f === null || !$this->windowFocus) {
            return null;
        }
        return $this->memberWithFocus(array_map(static fn(array $m): array => [$m[0], $m[1]], $f['committed']));
    }

    /** The value of the member, [value, id], holding the focus: it, or a node inside it. @param list<array{0: mixed, 1: int}> $members */
    private function memberWithFocus(array $members): mixed
    {
        for ($id = $this->focus ?? 0, $seen = 0; $id !== 0 && $seen < 10000; $id = $this->parents[$id] ?? 0, $seen++) {
            foreach ($members as [$v, $mid]) {
                if ($mid === $id) { return $v; }
            }
        }
        return null;
    }

    /** The fields bound to the focus, after the pass is built (mygo's applyFocusBindings): a value the app set asks for the focus, waiting while its control is not built; the user's focus otherwise sets the field. Whether either changed. */
    private function applyFocusBindings(): bool
    {
        $changed = false;
        foreach ($this->focusFields as $key => &$f) {
            if ($f['pass'] !== $this->frameNumber * 8 + $this->pass) { $f['members'] = []; } // none bound this pass
            $members = array_map(static fn(array $m): array => [$m[0], $m[1]->id], $f['members']);
            $v = $f['ref'];
            if ($v !== $f['last']) { // the app asked for another
                $f['wanted'] = $v; $f['last'] = $v; $f['pending'] = $v !== null;
                if ($v === null && $this->memberWithFocus(array_map(static fn(array $m): array => [$m[0], $m[1]], $f['committed'])) !== null) {
                    $this->focus = null; $changed = true;
                }
            }
            if ($f['pending']) {
                foreach ($f['members'] as [$mv, $node]) {
                    if ($mv !== $f['wanted']) { continue; }
                    if ($node->isDisabled()) { break; }
                    if ($this->focus !== $node->id) { $this->focus = $node->id; $changed = true; }
                    $f['pending'] = false;
                    break;
                }
            }
            if ($f['pending']) {
                continue;
            }
            $actual = $this->memberWithFocus($members);
            if ($actual === null && $f['wanted'] !== null && !in_array($f['wanted'], array_column($members, 0), true)) {
                $f['pending'] = true; // the control asked for is not built: the request waits for it
                continue;
            }
            if ($actual !== $f['last']) {
                $f['ref'] = $actual;
                $f['last'] = $f['wanted'] = $actual;
                $changed = true;
            }
        }
        unset($f);
        return $changed;
    }

    /** The members of each field the frame's last pass bound, which focusedValue reads (mygo's commitFocusBindings). */
    private function commitFocusBindings(): void
    {
        foreach ($this->focusFields as &$f) {
            $f['committed'] = array_map(static fn(array $m): array => [$m[0], $m[1]->id], $f['members']);
        }
        unset($f);
    }

    /** Gives the focus to the controls whose handles asked for it, bound in this pass and not disabled (mygo's applyFocusRequests); whether one took it. */
    private function applyFocusRequests(): bool
    {
        $took = false;
        foreach ($this->focusHandles as $i => $ref) {
            $h = $ref->get();
            if ($h === null) { unset($this->focusHandles[$i]); continue; }
            $b = $h->binding($this, false);
            if ($b === null || !$b->requested || $b->frame !== $this->frameNumber || $b->pass !== $this->pass || $b->node === null || $b->node->isDisabled()) { continue; }
            $b->requested = false;
            if ($this->focus !== $b->id) { $this->focus = $b->id; $took = true; }
        }
        $this->focusHandles = array_values($this->focusHandles);
        return $took;
    }

    /** Runs the actions whose input came, changes and submissions first, as mygo's runNoticeActions and runActions; whether one ran. */
    private function runActions(): bool
    {
        $ran = [];
        foreach ($this->actions as $i => [$n, $kind]) {
            if ($n === null || $n->isDisabled() || ($kind !== 'change' && $kind !== 'submit')) { continue; }
            if ($kind === 'change' ? $n->changed : ($n->edit?->submitted ?? false) || ($n->scroll instanceof ListState && $n->scroll->submitted())) { $ran[] = $i; }
        }
        foreach ($ran as $i) { if ($this->actions[$i][1] === 'submit' && $this->actions[$i][0]->edit !== null) { $this->actions[$i][0]->edit->submitted = false; } }
        foreach ($this->actions as $i => [$n, $kind, , $key, $mods, $handle]) {
            if ($handle !== null) {
                $b = $handle->binding($this, false);
                $n = $b !== null && $b->frame === $this->frameNumber && $b->pass === $this->pass ? $b->node : null;
                if ($n === null) { continue; } // a control left out of the pass takes no shortcut
            }
            if ($kind === 'change' || $kind === 'submit' || $n?->isDisabled()) { continue; }
            $hit = match ($kind) {
                'click' => $this->clicked($n->id),
                'shortcut' => $n === null ? $this->shortcut($key, $mods) : $this->focusWithin($n->id) && $this->shortcut($key, $mods),
            };
            if ($hit) { $ran[] = $i; }
        }
        foreach ($ran as $i) { ($this->actions[$i][2])(); }
        return $ran !== [];
    }

    /** @var list<\Closure(): void> what openURLThen gives its done, before the next frame builds */
    private array $opened = [];

    /** Opens $url with the system, and gives $done what came of it, the error or null, before the next frame builds, which then shows what it changed (mygo's OpenURLThen); without $done an error goes unheard, as mygo's OpenURL drops it. */
    public function openURLThen(string $url, ?Closure $done = null): void
    {
        $err = null;
        try {
            ($this->openURL)($url);
        } catch (\Throwable $e) {
            $err = $e;
        }
        if ($done === null) {
            return;
        }
        $this->opened[] = static fn() => $done($err);
        if ($this->building) { $this->animating = true; } elseif ($this->requestFrame !== null) { ($this->requestFrame)(); } // the next frame comes
    }

    /** Asks the window for $what (windowAsks) once this frame is built. */
    public function askWindow(string $what, mixed $value): void
    {
        $this->windowAsks[$what] = $value;
    }

    /** Shows a change of the state the view shows (a router's page): another pass of the frame being built, else a frame. */
    public function changed(): void
    {
        if ($this->building) {
            $this->consumed = true;
        } elseif ($this->requestFrame !== null) {
            ($this->requestFrame)();
        }
    }

    /** Presses node $id as a click would, from outside a build (a toolbar's overflow menu choosing it): clicked($id) reports it next frame. */
    public function press(int $id): void
    {
        $this->activated = $id;
    }

    /** Shows $t, or changes the toast of its id, which shows anew, and returns its id (mygo's AddToast). */
    public function addToast(Toast $t): string
    {
        $this->nextToast++;
        $t = clone $t;
        if ($t->id === '') { $t->id = 'toast-' . $this->nextToast; }
        $e = ['t' => $t, 'key' => $this->nextToast, 'spent' => 0.0, 'at' => $this->seconds()];
        $i = $this->toastIndex($t->id);
        if ($i >= 0) {
            $e['key'] = $this->toasts[$i]['key'];
            $this->toasts[$i] = $e;
        } else {
            $this->toasts[] = $e;
        }
        $this->announce($t->title); // nothing else tells screen readers: the focus stays
        if ($t->description !== '') { $this->announce($t->description); }
        $this->changed();
        return $t->id;
    }

    /** Closes the toast of $id, or every toast for '', and runs their onClose (mygo's CloseToast). */
    public function closeToast(string $id): void
    {
        $closed = [];
        foreach ($this->toasts as $i => $e) {
            if ($id === '' || $e['t']->id === $id) {
                if ($e['t']->onClose !== null) { $closed[] = $e['t']->onClose; }
                unset($this->toasts[$i]);
            }
        }
        $this->toasts = array_values($this->toasts);
        $this->changed();
        foreach ($closed as $fn) { $fn(); }
    }

    /** The toast of $id showing, or null. */
    public function toastNamed(string $id): ?Toast
    {
        $i = $this->toastIndex($id);
        return $i < 0 ? null : $this->toasts[$i]['t'];
    }

    private function toastIndex(string $id): int
    {
        foreach ($this->toasts as $i => $e) {
            if ($e['t']->id === $id) { return $i; }
        }
        return -1;
    }

    /** The frame's time in seconds. */
    private function seconds(): float
    {
        return ($this->now !== 0 ? $this->now : hrtime(true)) / 1e9;
    }

    /** How long toast $i has shown, and how long it shows still (0 until it is closed), in seconds. @return array{0: float, 1: float} */
    public function toastTimes(string $id): array
    {
        $i = $this->toastIndex($id);
        if ($i < 0) {
            return [0.0, 0.0];
        }
        $e = $this->toasts[$i];
        $age = $e['spent'] + ($this->toastsPaused ? 0.0 : $this->seconds() - $e['at']);
        $life = $e['t']->life();
        return [$age, $life > 0 ? max(0.0, $life - $age) : 0.0];
    }

    /** The toasts' key for the toast of $id, which keeps its node as it changes. */
    public function toastKey(string $id): int
    {
        $i = $this->toastIndex($id);
        return $i < 0 ? 0 : $this->toasts[$i]['key'];
    }

    /** Whether the toasts' time stops (the pointer rests on them, the focus is in them), starting or stopping it. */
    /** @var list<int> the roots of the toasts built (Context::toastBase), this frame's and, until the viewport is built, last frame's: the pointer on one stops their time @internal */
    public array $toastRoots = [];

    public function pauseToasts(bool $paused): void
    {
        $paused = $paused || !$this->windowFocus; // the window in the background
        if ($paused === $this->toastsPaused) {
            return;
        }
        $now = $this->seconds();
        foreach ($this->toasts as $i => $e) {
            if ($paused) { $this->toasts[$i]['spent'] += $now - $e['at']; } else { $this->toasts[$i]['at'] = $now; }
        }
        $this->toastsPaused = $paused;
    }

    public function toastsPaused(): bool
    {
        return $this->toastsPaused;
    }

    /** The toasts showing, the newest TOASTS, oldest first, those whose time is over closed first, running their onClose. @return list<Toast> */
    public function toastList(): array
    {
        $ended = [];
        foreach ($this->toasts as $i => $e) {
            [, $left] = $this->toastTimes($e['t']->id);
            if ($e['t']->life() > 0 && $left <= 0) {
                if ($e['t']->onClose !== null) { $ended[] = $e['t']->onClose; }
                unset($this->toasts[$i]);
            }
        }
        $this->toasts = array_values($this->toasts);
        foreach ($ended as $fn) { $fn(); }
        if ($this->toasts === []) { $this->toastsPaused = false; }
        return array_map(static fn(array $e): Toast => $e['t'], array_slice($this->toasts, -self::TOASTS));
    }

    /** Seconds until the caret next toggles, or null when nothing blinks. */
    public function nextBlink(): ?float
    {
        if ($this->blinkSeen === null) {
            return null;
        }
        $phase = hrtime(true) / 1e9 - $this->blinkStart;
        return $phase >= self::BLINK_FOR ? null : self::BLINK - fmod($phase, self::BLINK);
    }

    private function record(Node $n, ?Node $parent = null, int $group = 0, ?array $clip = null, int $page = 0, bool $inert = false, bool $hidden = false, int $clipper = 0): void
    {
        $hidden = $hidden || $n->invisible; // in its room, but showing nothing and taking neither the pointer nor the focus (mygo's Invisible)
        $this->rects[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        if ($n->frags !== []) { $this->frags[$n->id] = $n->frags; } // text inside a paragraph: its words are its area
        $this->parents[$n->id] = $parent?->id ?? 0;
        $this->pageOf[$n->id] = $page;
        $inert = $inert || $n->inert || $hidden;
        if ($inert) { $this->inertIds[$n->id] = true; }
        if ($n->passThrough) { $this->passing[$n->id] = true; }
        if ($n->attachTarget !== null) { $this->attachedTo[$n->id] = $n->attachTarget->id; }
        if ($parent !== null && $parent->isDisabled()) { $n->disabledIn = true; } // inside a node disabled after it was built, as a fieldset (mygo's IsDisabled)
        if ($n->isDisabled()) { $this->disabledIds[$n->id] = true; }
        if ($n->focusTarget) { $this->targets[$n->id] = true; }
        if ($n->pose !== null) {
            $this->samplePose($n);
        }
        if ($n->drags) { $this->dragSources[$n->id] = $n->dragValue; }
        if ($n->dataSource !== null) { $this->dataSources[$n->id] = $n->dataSource; }
        if (!$n->isDisabled()) {
            if ($n->accepts !== null) { $this->dropTargets[$n->id] = $n->accepts; }
            if ($n->dataTarget !== null) { $this->dataTargets[$n->id] = $n->dataTarget; }
            if ($n->takesFiles) { $this->fileTargets[$n->id] = true; }
        }
        if ($n->draggable) {
            $this->draggables[$n->id] = true;
            if (($d = $this->dragRecs[$n->id] ?? null) !== null && $d->frame === $this->frameNumber) {
                if (($p = $d->shown()) !== [0.0, 0.0]) {
                    $n->poseM = Affine::translation($p[0], $p[1])->mul($n->poseM); // the drag moves it along with its pose
                }
                if ($d->moving()) {
                    $this->animating = true; // on its way to rest: frames keep coming until it is there
                }
            }
        }
        $n->world = $parent === null ? $n->transformAt() : $parent->world->mul($n->transformAt()); // its transform after its ancestors' (mygo's world)
        if ($n->world->set) {
            $this->worlds[$n->id] = $n->world;
        }
        [$bx, $by, $bw, $bh] = $n->world->bounds($n->x, $n->y, $n->w, $n->h); // as it shows in the window
        $clip ??= [$n->x, $n->y, $n->x + $n->w, $n->y + $n->h]; // the root: the window
        if (min($bx + $bw, $clip[2]) > max($bx, $clip[0]) && min($by + $bh, $clip[3]) > max($by, $clip[1]) && !($n->backface && $n->transform->facesAway()) && !$hidden) {
            $this->shown[$n->id] = true;
        }
        $vx = max($bx, $clip[0]); $vy = max($by, $clip[1]);
        $this->visible[$n->id] = [$vx, $vy, max(0.0, min($bx + $bw, $clip[2]) - $vx), max(0.0, min($by + $bh, $clip[3]) - $vy)]; // as it shows, clipped (mygo's vx, vy, vw, vh)
        if ($clipper !== 0) { $this->clippedBy[$n->id] = $clipper; }
        $axes = ($n->scroll !== null ? Node::CLIP_X | Node::CLIP_Y : 0) | $n->clip;
        if ($axes !== 0 && $parent !== null) { // what is inside it takes the pointer only within it, in its own layout (mygo's clip chain)
            $this->clipAxes[$n->id] = $axes;
            $clipper = $n->id;
        }
        if ($n->scroll !== null || ($n->clip & Node::CLIP_X) !== 0) {
            $clip[0] = max($clip[0], $bx); $clip[2] = min($clip[2], $bx + $bw);
        }
        if ($n->scroll !== null || ($n->clip & Node::CLIP_Y) !== 0) {
            $clip[1] = max($clip[1], $by); $clip[3] = min($clip[3], $by + $bh);
        }
        $this->order[$n->id] = ++$this->counter;
        $this->recordSelectable($n, $parent);
        $this->recordMenus($n);
        if ($n->dragsWindow) {
            $this->handles[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        }
        if ($n->absolute && $parent !== null && $this->order[$parent->id] === 1 && !$n->passThrough && !$hidden) { // placed at the root: over what was built before it
            $this->overlays[] = [$this->counter, $bx, $by, $bw, $bh]; // as it shows, through its transform
        }
        if ($n->blocks || ($n->absolute && $parent !== null && $this->order[$parent->id] === 1)) {
            $this->overlayIds[$n->id] = true;
        }
        if ($n->blocks) {
            $this->barrier = $this->counter;
            $this->modal = $n->id;
        }
        if ($n->focusGroup !== 0 && $group === 0) { // groups inside a group are part of it
            $group = $n->id;
            $this->groups[$group] = ['orient' => $n->focusGroup, 'selects' => $n->groupSelects, 'on' => null, 'rtl' => $n->rtl];
        }
        if (($n->kind === Node::BUTTON || $n->kind === Node::INPUT || $n->kind === Node::TEXTAREA || $n->focusable) && !$inert && !$n->isDisabled()) {
            $this->focusables[] = $n->id;
            if (in_array($n->role, [Role::CHECK_BOX, Role::RADIO, Role::SWITCH], true)) { $this->toggles[$n->id] = true; }
            if ($group !== 0) {
                $this->memberOf[$n->id] = $group;
                if ($n->on && $this->groups[$group]['on'] === null) { $this->groups[$group]['on'] = $n->id; }
            }
            if ($n->focusable && $n->id === $this->focus) {
                $n->focused = true; // buttons and fields set this while building
            }
        }
        if ($n->focused && $n->id === $this->focus) {
            $n->focusVisible = $this->focusVisible && !$n->ownRing; // the painter rings it, unless it rings itself
        }
        if ($n->kind === Node::SLIDER && !$n->isDisabled()) { // a disabled slider takes no press
            $this->sliders[$n->id] = [$n->x, $n->y, $n->w, $n->h, $n->rtl, $n->x + $n->padL(), max(1.0, $n->w - $n->padL() - $n->padR()), $n->y + $n->padT(), max(1.0, $n->h - $n->padT() - $n->padB()), $n->vertical]; // its box, and the content box its values span
        }
        if ($n->tooltip !== null) {
            $this->tips[$n->id] = $n->tooltip;
        }
        if ($n->url !== null) { $this->links[] = $n->id; }
        if ($n->url !== null && $this->hovered($n->id)) {
            $this->cursor = Cursor::Pointer;
        } elseif (($n->kind === Node::INPUT || $n->kind === Node::TEXTAREA) && $this->hovered($n->id)) {
            $this->cursor = Cursor::Text;
        } elseif ($n->cursor !== null && $this->hovered($n->id)) {
            $this->cursor = $n->cursor;
        } elseif ($this->hovered($n->id) && (isset($this->clickable[$n->id]) || in_array($n->role, self::CLICKABLE_ROLES, true))) {
            $this->cursor = Cursor::Default; // a cursor around stops at what is clicked, which has its own (mygo's cursor of clickable elements)
        }
        if ($n->kind === Node::PROGRESS && $n->fraction < 0) {
            $this->animating = true; // an indeterminate bar moves every frame; a spinner asks for its next spoke as it paints
        }
        if ($n->wraps ? abs($n->w - $n->wrapWidth) > 0.5 : ($n->wrapLast && $parent !== null && abs($parent->w - 2 * $parent->padX() - $n->wrapWidth) > 0.5)) {
            $this->needsFrame = true; // wrapped at the width it, or its container, had last frame; this frame's differs
        }
        if ($n->keepFocus) { $this->keepers[$n->id] = true; }
        if ($n->input !== null) { $this->inputs[$n->id] = $n->input; }
        if ($n->textClient !== null) { $this->textClients[$n->id] = [$n->textClient, $n->inputMethod]; }
        if ($n->caret !== null) { $this->carets[$n->id] = [$n->x + $n->caret[0], $n->y + $n->caret[1], $n->caret[2], $n->caret[3]]; }
        if (($n->kind === Node::INPUT || $n->kind === Node::TEXTAREA) && $n->edit !== null) {
            $n->edit->style = $n->style | Node::fontBits($n->ts); // font('monospace'), bold(): what the field is measured, hit and painted in
        }
        if ($n->kind === Node::INPUT && $n->edit !== null) {
            [, $lh] = $this->metrics?->measure('X', $n->fontSize, $n->edit->style) ?? [0, $n->h];
            $this->fields[$n->id] = [$n->edit, $n->x + self::FIELD_INSET + $n->insetL, $n->y + ($n->h - $lh) / 2, $lh, $n->fontSize, $n->w - 2 * self::FIELD_INSET - $n->insetL - $n->insetR];
        } elseif ($n->kind === Node::TEXTAREA && $n->edit !== null) {
            $this->areas[$n->id] = $n->edit;
            [, $lh] = $this->metrics?->measure('X', $n->fontSize, $n->edit->style) ?? [0, $n->h];
            [$ix, $iy] = $n->bare ? [0.0, 0.0] : [self::FIELD_INSET, self::FIELD_INSET_Y];
            $this->fields[$n->id] = [$n->edit, $n->x + $ix, $n->y + $iy, $lh, $n->fontSize, $n->w - 2 * $ix];
        }
        if ($n->scroll !== null) {
            $this->scrolls[$n->id] = $n->scroll;
            $this->scrollAxes[$n->id] = [$n->scrollX, $n->scrollY, $n->rtl, $n->barInsets];
            if ($n->scroll->moving() || ($n->scroll instanceof ListState && $n->scroll->gap > 0)) {
                $this->needsFrame = true;
            }
            if ($n->scroll instanceof ListState && $n->scroll->selected !== null) {
                $this->focusables[] = $n->id;
                $this->lists[$n->id] = $n->scroll;
            }
        }
        foreach ($n->ghosts as $g) {
            self::worldTree($g, $n->world); // copies going only show: they are not recorded
        }
        foreach ($n->paintOrder() as $k) {
            $this->record($k, $n, $group, $clip, $n->page ? $n->id : $page, $inert, $hidden, $clipper);
        }
    }

    /** The window's point (x, y) in last frame's layout of node $id, from its top left, through its transform (mygo's WindowToLocal); null for a singular transform. @return ?array{0: float, 1: float} */
    public function toLocal(int $id, float $x, float $y): ?array
    {
        $r = $this->rects[$id] ?? [0.0, 0.0];
        if (isset($this->worlds[$id])) {
            $inv = $this->worlds[$id]->inverse();
            if ($inv === null) {
                return null;
            }
            [$x, $y] = $inv->point($x, $y);
        }
        return [$x - $r[0], $y - $r[1]];
    }

    /** The map from the layout of node $id to the window in the last frame. */
    public function worldOf(int $id): Affine
    {
        return $this->worlds[$id] ?? Affine::identity();
    }

    /** The point (x, y) of last frame's layout of node $id, from its top left, in the window, through its transform (mygo's LocalToWindow). @return array{0: float, 1: float} */
    public function toWindow(int $id, float $x, float $y): array
    {
        $r = $this->rects[$id] ?? [0.0, 0.0];
        return ($this->worlds[$id] ?? Affine::identity())->point($r[0] + $x, $r[1] + $y);
    }

    /** Works out the worlds of $n, a copy going (RuntimeTransition), and what is inside it, under $parent. */
    private static function worldTree(Node $n, Affine $parent): void
    {
        $n->world = $parent->mul($n->transformAt());
        foreach ($n->kids as $k) {
            self::worldTree($k, $n->world);
        }
    }

    /** Whether Context::disableAnimations made this frame's values go to their targets at once. */
    public function instant(): bool
    {
        return $this->instantFrame === $this->frameNumber && $this->frameNumber !== 0;
    }

    /** Whether some of node $id showed in the window last frame (Context::inView). */
    public function shown(int $id): bool
    {
        return isset($this->shown[$id]);
    }

    public function focused(int $id): bool
    {
        return $this->focus === $id;
    }

    /** Whether node $id, or one inside it, has the keyboard focus, as of the last frame (mygo's FocusWithin). */
    public function focusWithin(int $id): bool
    {
        for ($at = $this->focus; $at !== null && $at !== 0; $at = $this->parents[$at] ?? 0) {
            if ($at === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gives an event to the node that takes its input as it comes
     * (Node::handleInput) as it arrives, before the next frame is built
     * (Input::push): a press on it, which has its moves and release
     * follow, the pointer moving over it, the wheel over it, and keys and
     * edit commands while it has the focus. A press focuses what it lands
     * on first, as any press does. Reports whether the node took it; what
     * it left goes on to the frame, as it would without it.
     */
    private function deliver(array $e): bool
    {
        $id = null; $ev = null;
        switch ($e[0]) {
            case 'down':
                $id = $this->inputAt($e[1], $e[2]);
                if ($id === null) { return false; }
                if ($e[3] === 0) { $this->focusAt($e[1], $e[2]); }
                $ev = new InputEvent(InputEvent::POINTER_DOWN, mods: $e[5] & Key::MODIFIERS, button: $e[3], clicks: max(1, $e[4]));
                break;
            case 'move':
                if ($this->captured !== null) { return true; } // the press's node takes the moves, as drags
                $id = $this->inputAt($e[1], $e[2]);
                $ev = new InputEvent(InputEvent::POINTER_MOVE, mods: $this->input->mods, button: -1);
                break;
            case 'drag':
                $id = $this->captured;
                $ev = new InputEvent(InputEvent::POINTER_MOVE, mods: $this->input->mods, button: 0);
                break;
            case 'up':
                $id = $this->captured;
                $ev = new InputEvent(InputEvent::POINTER_UP, mods: ($e[5] ?? 0) & Key::MODIFIERS, button: $e[3], clicks: max(1, $e[4] ?? 1));
                break;
            case 'scroll':
                $id = $this->inputAt($e[1], $e[2]);
                $ev = new InputEvent(InputEvent::SCROLL, mods: $this->input->mods, dx: -$e[3], dy: -$e[4], precise: $e[5] ?? false);
                break;
            case 'key':
                $id = $this->focus;
                $ev = new InputEvent(InputEvent::KEY_DOWN, key: $e[2], mods: $e[3] & Key::MODIFIERS, text: $e[1], repeat: $e[4] ?? false);
                break;
            case 'keyup':
                $id = $this->focus;
                $ev = new InputEvent(InputEvent::KEY_UP, key: $e[2], mods: $e[3] & Key::MODIFIERS, text: $e[1]);
                break;
            case 'edit':
                $id = $this->focus;
                $ev = new InputEvent(InputEvent::COMMAND, text: $e[1]);
                break;
            case 'insert': // text typed or committed, to a node taking text (Node::textCaret)
                $id = $this->focus !== null && isset($this->carets[$this->focus]) ? $this->focus : null;
                if ($id === null) { return false; }
                $ev = new InputEvent(InputEvent::TEXT, text: $e[1]);
                break;
            case 'compose': // ['compose', text, caret]: an input method's composition
                $id = $this->focus !== null && isset($this->carets[$this->focus]) ? $this->focus : null;
                if ($id === null) { return true; }
                $ev = new InputEvent(InputEvent::COMPOSE, text: $e[1], caret: $e[2]);
                break;
            default:
                return false;
        }
        if ($id === null || !isset($this->inputs[$id])) { return $e[0] === 'move'; }
        if (!in_array($ev->kind, [InputEvent::KEY_DOWN, InputEvent::KEY_UP, InputEvent::COMMAND, InputEvent::TEXT, InputEvent::COMPOSE], true)) {
            [$ev->x, $ev->y] = $this->toLocal($id, $e[1], $e[2]) ?? [0.0, 0.0];
        }
        $took = (bool)($this->inputs[$id])($ev);
        if ($ev->kind === InputEvent::POINTER_DOWN) {
            $this->input->mouseDown = $took || $this->input->mouseDown;
            $this->captured = $took ? $id : null;
        } elseif ($ev->kind === InputEvent::POINTER_UP) {
            $this->input->mouseDown = false;
            $this->captured = null;
        }
        if ($took) { $this->needsFrame = true; }
        // The release of a press the node took is its own, as its drags are; the pointer's moves go nowhere else.
        return $took || $e[0] === 'move' || $e[0] === 'drag' || $e[0] === 'up';
    }

    /** A press at a point focuses the innermost focusable node under it, or nothing, as mygo's pointerDown does before the node pressed hears of it. */
    private function focusAt(float $x, float $y): void
    {
        if ($this->keepsFocus($x, $y)) { return; }
        $best = null; $bestArea = INF;
        foreach ($this->focusables as $fid) {
            $r = $this->rects[$fid];
            if ($this->under($fid, $x, $y) && $r[2] * $r[3] < $bestArea) { $best = $fid; $bestArea = $r[2] * $r[3]; }
        }
        if ($best !== null && $best !== $this->focus) { $this->focus = $best; $this->focusVisible = false; }
    }

    /** Whether a press at a point leaves the focus where it is, being on a node that keeps it (Node::keepFocus). */
    private function keepsFocus(float $x, float $y): bool
    {
        foreach ($this->keepers as $id => $_) { if ($this->under($id, $x, $y)) { return true; } }
        return false;
    }

    /** The innermost reachable node taking its input under a point. */
    private function inputAt(float $x, float $y): ?int
    {
        $best = null; $bestArea = INF;
        foreach ($this->inputs as $id => $_) {
            $r = $this->rects[$id] ?? null;
            if ($r !== null && $this->under($id, $x, $y) && $r[2] * $r[3] < $bestArea) { $best = $id; $bestArea = $r[2] * $r[3]; }
        }
        return $best;
    }

    /** A left press: focuses the innermost field, button or list under it, placing the caret in a field. */
    private function pressAt(float $x, float $y): void
    {
        if ($this->keepsFocus($x, $y)) { return; }
        $best = null; $bestArea = INF;
        foreach ($this->focusables as $id) {
            $r = $this->rects[$id];
            if ($this->under($id, $x, $y) && $r[2] * $r[3] < $bestArea) {
                $best = $id; $bestArea = $r[2] * $r[3];
            }
        }
        if ($best === null) {
            if ($this->focusedText() !== null) {
                $this->focus = null; // a press on nothing to focus takes it from selectable text, as mygo's pointerDown
            }
            return;
        }
        if ($best !== $this->focus) {
            $this->focus = $best;
            $this->focusVisible = false;
        }
        if (isset($this->fields[$best])) {
            $this->placeCaret($best, $x, $y, false);
        }
    }

    /** A press on a scroll bar's thumb starts dragging it; the thumb takes a few points around it. */
    private function grabThumb(float $x, float $y): bool
    {
        $g = Scrollbar::GRAB;
        foreach ($this->scrolls as $id => $state) {
            if (!$this->reachable($id)) {
                continue;
            }
            [$horizontal, $vertical, $rtl, $inset] = $this->scrollAxes[$id];
            $r = Scrollbar::track($this->rects[$id], $inset);
            foreach ([[false, $vertical], [true, $horizontal]] as [$axis, $on]) {
                if (!$on) {
                    continue;
                }
                $extent = $axis ? $state->extentX() : $state->extent();
                $t = Scrollbar::thumb($r, $extent, $this->scrollbarWidth, $axis, $rtl);
                if ($t === null || $x < $t[0] - $g || $x >= $t[0] + $t[2] + $g || $y < $t[1] - $g || $y >= $t[1] + $t[3] + $g) {
                    continue;
                }
                $state->stop();
                $ratio = Scrollbar::pointsPerThumbPoint($r, $extent, $axis);
                $this->drag = [$state, $axis ? $x : $y, $ratio * ($axis && $rtl ? -1 : 1), $axis, $extent[0], $extent[1], $ratio > 0 ? $extent[1] / $ratio : 0.0]; // right to left, the thumb going right goes back to the start; where it started through the content, how far the content went then, and the track's travel
                return true;
            }
        }
        return false;
    }

    /** Whether this scroll state's thumb is being dragged. */
    public function dragging(Scrollable $state): bool
    {
        return $this->drag !== null && $this->drag[0] === $state;
    }

    private function placeCaret(int $id, float $x, float $y, bool $extend): void
    {
        [$state, $tx, $ty, , $size, $width] = $this->fields[$id];
        if ($this->metrics === null) {
            return;
        }
        if ($state->multiline) {
            $byte = $state->layout($this->metrics, $size, $width)->hit($this->metrics, $x - $tx, $y - $ty + $state->scrollY);
        } else {
            $byte = $state->fromShown($this->metrics->indexForX($state->shown(), $size, $x - $tx - $state->offsetX, $state->style)); // a password's bullets; the text scrolled or aligned as painted
        }
        $state->setCursor($byte, $extend);
    }

    /** Line-wise commands of a focused text area: moves by wrapped lines, Enter inserts a line. */
    private function areaCommand(string $selector): bool
    {
        if ($this->focus === null || !isset($this->fields[$this->focus]) || $this->metrics === null) {
            return false;
        }
        [$state, , , $lh, $size, $width] = $this->fields[$this->focus];
        if (!$state->multiline) {
            return false;
        }
        $extend = str_ends_with($selector, 'AndModifySelection:');
        $base = $extend ? substr($selector, 0, -strlen('AndModifySelection:')) . ':' : $selector;
        if (!in_array($base, TextState::BY_LINE, true)) {
            return false;
        }
        $m = $this->metrics;
        $layout = $state->layout($m, $size, $width);
        $line = $layout->lineOf($state->cursor);
        [$a, $b] = $layout->line($line);
        $end = $b > $a && $state->text[$b - 1] === "\n" ? $b - 1 : $b;
        $page = max(1, (int)(($this->rects[$this->focus][3] - 2 * self::FIELD_INSET_Y) / $lh) - 1);
        $toLine = function (int $l) use ($state, $layout, $m, $extend) {
            [$x, ] = $layout->caret($m, $state->cursor);
            $state->setCursor($layout->hit($m, $x, ($l + 0.5) * $layout->lineHeight), $extend);
        };
        switch ($base) {
            case 'insertNewline:': $state->insert("\n"); break;
            case 'moveUp:': if ($line === 0) { $state->setCursor(0, $extend); } else { $toLine($line - 1); } break;
            case 'moveDown:': if ($line === $layout->count() - 1) { $state->setCursor(strlen($state->text), $extend); } else { $toLine($line + 1); } break;
            case 'pageUp:': $toLine(max(0, $line - $page)); break;
            case 'pageDown:': $toLine(min($layout->count() - 1, $line + $page)); break;
            case 'moveToBeginningOfLine:': $state->setCursor($a, $extend); break;
            case 'moveToEndOfLine:': $state->setCursor($end, $extend); break;
            case 'moveToLeftEndOfLine:': case 'moveToRightEndOfLine:': $state->setCursor(($base === 'moveToRightEndOfLine:') !== $state->paragraphRTL() ? $end : $a, $extend); break; // the way the arrow points (mygo's caretParagraphRTL)
            case 'deleteToBeginningOfLine:': if (!$state->hasSelection()) { $state->anchor = $a; } $state->insert(''); break;
            case 'deleteToEndOfLine:': // to the end of the line as it wraps, at its end joining the next
                $state->anchor = $state->cursor; $state->cursor = $state->cursor === $end && ($state->text[$end] ?? '') === "\n" ? $end + 1 : $end;
                $state->insert(''); break;
        }
        return true;
    }

    /** Text system commands the field did not take: focus moves, activation, list navigation. */
    private function command(string $selector): bool
    {
        if ($this->areaCommand($selector) || $this->textSelectionKey($selector)) {
            return true;
        }
        if ($this->focus !== null && (($this->menuNodes[$this->focus] ?? 0) & 2) !== 0 && in_array($selector, ['insertNewline:', 'moveDown:'], true)) {
            $this->openMenuButton($this->focus); // Enter and Down open a focused menu button's menu
            return true;
        }
        if ($this->focus !== null && !isset($this->fields[$this->focus])) {
            $this->focusVisible = true; // a key on a focused control: the ring shows
        }
        if ($this->focus !== null && isset($this->lists[$this->focus])) {
            $l = $this->lists[$this->focus];
            $extend = $l->selection !== null && str_ends_with($selector, 'AndModifySelection:'); // Shift with the keys: the choice extends
            $base = $extend ? substr($selector, 0, -strlen('AndModifySelection:')) . ':' : $selector;
            $to = match ($base) {
                'moveUp:' => max(0, $l->selected - 1), 'moveDown:' => $l->selected + 1,
                'moveToBeginningOfDocument:', 'scrollToBeginningOfDocument:' => 0, 'moveToEndOfDocument:', 'scrollToEndOfDocument:' => $l->count - 1,
                default => null,
            };
            if ($to !== null) { $extend ? $l->extendTo($to) : $l->choose($to); return true; }
            if ($selector === 'selectAll:' && $l->selection !== null) { $l->selectAll(); return true; }
            if ($selector === 'insertNewline:') { // Enter submits the chosen row, or edits its editable text (mygo's listInput)
                if ($l->selected >= 0) { if ($l->editables) { $l->editAsked = true; } else { $l->submit(); } }
                return true;
            }
        }
        if ($this->focus !== null && isset($this->sliders[$this->focus])) {
            switch ($selector) {
                case 'moveLeft:': case 'moveRight:': // right to left, Left raises the value, which grows toward the left
                    $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) + (($selector === 'moveRight:') !== ($this->sliders[$this->focus][4] && !$this->sliders[$this->focus][9]) ? 1 : -1);
                    return true;
                case 'moveUp:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) + 1; return true;
                case 'moveDown:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) - 1; return true;
                case 'moveToBeginningOfDocument:': case 'moveToBeginningOfLine:': $this->jumps[$this->focus] = -1; return true; // Home: the least
                case 'moveToEndOfDocument:': case 'moveToEndOfLine:': $this->jumps[$this->focus] = 1; return true;
                case 'pageUp:': case 'scrollPageUp:': $this->pages[$this->focus] = ($this->pages[$this->focus] ?? 0) + 1; return true; // a tenth of the range (mygo's sliderInput)
                case 'pageDown:': case 'scrollPageDown:': $this->pages[$this->focus] = ($this->pages[$this->focus] ?? 0) - 1; return true;
            }
        }
        if ($this->focus !== null && isset($this->steppers[$this->focus])) {
            switch ($selector) {
                case 'moveLeft:': case 'moveRight:': // the way they point: right to left, Left steps up (mygo's Stepper)
                    $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) + (($selector === 'moveRight:') !== $this->steppers[$this->focus] ? 1 : -1);
                    return true;
                case 'moveUp:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) + 1; return true;
                case 'moveDown:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) - 1; return true;
                case 'moveToBeginningOfDocument:': $this->jumps[$this->focus] = -1; return true;
                case 'moveToEndOfDocument:': $this->jumps[$this->focus] = 1; return true;
            }
        }
        if ($this->focus !== null && $this->groupKey($selector)) {
            return true;
        }
        switch ($selector) {
            case 'insertTab:': $this->moveFocus(1); return true;
            case 'insertBacktab:': $this->moveFocus(-1); return true;
            case 'cancelOperation:': // Escape: a control lets the focus go once the frame is built, the key seen around it (a toast closing); a field keeps it; the window still gets the key
                return false;
            case 'insertNewline:':
                if ($this->focus !== null && isset($this->toggles[$this->focus])) { // a toggle takes Space before the window's shortcuts, and Enter after them (mygo's flagToggle)
                    $this->enterToggle = $this->focus;
                    return false;
                }
                if ($this->focus !== null && !isset($this->fields[$this->focus])) {
                    $this->activated = $this->focus;
                    return true;
                }
        }
        return false;
    }

    private function moveFocus(int $dir): void
    {
        if ($this->focusables === []) {
            return;
        }
        $reachable = $this->placePopovers(array_values(array_filter($this->focusables, $this->reachable(...))));
        if ($reachable === []) {
            return;
        }
        // The stops of Tab: a focusable on its own, or a focus group as one at its first member's place.
        $stops = [];
        foreach ($reachable as $id) {
            if (isset($this->targets[$id]) && $id !== $this->focus) { continue; } // Tab goes past a focus target, but from it, into it
            $g = $this->memberOf[$id] ?? 0;
            if ($g === 0) { $stops[] = $id; } elseif (!in_array($g, $stops, true)) { $stops[] = $g; }
        }
        $at = $this->focus === null ? 0 : ($this->memberOf[$this->focus] ?? $this->focus);
        $i = (int)array_search($at, $stops, true);
        if ($this->focus === null) { $i = -1; }
        $n = count($stops);
        $to = $stops[(($i + $dir) % $n + $n) % $n];
        if (isset($this->groups[$to])) { // into a group: the member that had the focus last, else the one on, else the first
            $last = $this->groupLast[$to] ?? null;
            $to = $last !== null && ($this->memberOf[$last] ?? 0) === $to ? $last : ($this->groups[$to]['on'] ?? $this->firstMember($to, $reachable));
        }
        $this->focusTo($to);
    }

    /**
     * $order with the focusables of popovers moved after their anchors,
     * a popover of a popover after its own (mygo's placePopovers): a
     * popover is built at the window's root, after the view, but Tab goes
     * into it from what opened it. @param list<int> $order @return list<int>
     */
    private function placePopovers(array $order): array
    {
        $in = array_flip($order); $after = []; $top = [];
        foreach ($order as $id) {
            $a = $this->popoverAnchor($id);
            if ($a !== 0 && isset($in[$a])) { $after[$a][] = $id; } else { $top[] = $id; }
        }
        if ($after === []) { return $order; }
        $out = []; $placed = [];
        $add = function (int $id) use (&$add, &$out, &$placed, $after): void {
            if (isset($placed[$id])) { return; }
            $placed[$id] = true; $out[] = $id;
            foreach ($after[$id] ?? [] as $k) { $add($k); }
        };
        foreach ($top as $id) { $add($id); }
        foreach ($order as $id) { $add($id); } // popovers of anchors that cycle
        return $out;
    }

    /** The node the popover $id is in is attached to (Node::attachTo), 0 for none, last frame. */
    private function popoverAnchor(int $id): int
    {
        for ($k = 0; $id !== 0 && $k < 1000; $k++) {
            if (isset($this->attachedTo[$id])) { return $this->attachedTo[$id]; }
            $id = $this->parents[$id] ?? 0;
        }
        return 0;
    }

    private function firstMember(int $group, array $reachable): int
    {
        foreach ($reachable as $id) { if (($this->memberOf[$id] ?? 0) === $group) { return $id; } }
        return $group;
    }

    /** Gives the keyboard focus to $id, visibly, remembering it for its group, and scrolls it into view (mygo's moveFocus). */
    private function focusTo(int $id): void
    {
        $this->focus = $id;
        $this->focusVisible = true;
        if (isset($this->memberOf[$id])) { $this->groupLast[$this->memberOf[$id]] = $id; }
        if (isset($this->fields[$id])) {
            $this->fields[$id][0]->selectAll();
        }
        $this->reveal($id);
    }

    /** @var list<int> the nodes to scroll into view once the frame is laid out (mygo's revealIDs) */
    private array $revealIDs = [];

    /** Scrolls node $id into view in the frame, once it is laid out (mygo's reveal). */
    private function reveal(int $id): void
    {
        if (!in_array($id, $this->revealIDs, true)) {
            $this->revealIDs[] = $id;
        }
        $this->needsFrame = true;
    }

    /**
     * The nodes to scroll into view as the frame's pass is laid out: those
     * the runtime asked for and those the pass did (Node::scrollIntoView);
     * the pass's are kept for the next pass, which may not ask again
     * (mygo's layoutTree and runFrame). Layout::layout takes them.
     * @param list<int> $asked
     * @return list<int>
     */
    public function takeReveals(array $asked): array
    {
        $ids = $this->revealIDs;
        foreach ($asked as $id) {
            if (!in_array($id, $ids, true)) { $ids[] = $id; }
        }
        $this->revealIDs = $asked;
        return $ids;
    }

    /** The arrows, Home and End inside a focus group: among its members, round; with selects, the move clicks the member. */
    private function groupKey(string $selector): bool
    {
        $g = $this->memberOf[$this->focus] ?? 0;
        if ($g === 0) { return false; }
        $o = $this->groups[$g]['orient'];
        $dir = match ($selector) {
            'moveLeft:' => $o & Node::HORIZONTAL ? ($this->groups[$g]['rtl'] ? 1 : -1) : 0, 'moveRight:' => $o & Node::HORIZONTAL ? ($this->groups[$g]['rtl'] ? -1 : 1) : 0, // right to left, Left goes forward
            'moveUp:' => $o & Node::VERTICAL ? -1 : 0, 'moveDown:' => $o & Node::VERTICAL ? 1 : 0,
            'moveToBeginningOfDocument:' => -INF, 'moveToEndOfDocument:' => INF, default => 0,
        };
        if ($dir === 0) { return false; }
        $members = array_values(array_filter($this->focusables, fn($id) => ($this->memberOf[$id] ?? 0) === $g && $this->reachable($id)));
        $n = count($members); $i = (int)array_search($this->focus, $members, true);
        $to = $members[is_infinite($dir) ? ($dir < 0 ? 0 : $n - 1) : (($i + $dir) % $n + $n) % $n];
        $this->focusTo($to);
        if ($this->groups[$g]['selects']) { $this->activated = $to; }
        return true;
    }

    /** Whether node $id is pressed this frame by Enter, Space or assistive technology rather than the pointer, as clicked() will report. */
    public function activating(int $id): bool
    {
        return $this->activated === $id;
    }

    /** Whether a mouse-up this frame landed on the node this id had last frame, or Enter/Space pressed it while focused. */
    public function clicked(int $id): bool
    {
        if (isset($this->disabledIds[$id])) {
            return false; // disabled, or inside a disabled node, last frame (mygo's disabled)
        }
        $this->clickable[$id] = true; // assistive technology presses it
        if ($this->activated === $id) {
            $this->activated = null; $this->keyClicked = $id;
            $this->consumed = true;
            $this->clickMods = 0; $this->clickModsOf[$id] = 0; // the keys click with none
            return true;
        }
        foreach ($this->clicks as $i => [$x, $y, $mods]) {
            if ($this->under($id, $x, $y)) {
                unset($this->clicks[$i]);
                $this->consumed = true;
                $this->clickMods = $mods; $this->clickModsOf[$id] = $mods;
                return true;
            }
        }
        return false;
    }

    /** @var array<int, int> the modifiers held at the last click of each node, by id */
    private array $clickModsOf = [];

    /** The modifiers held at the last click of node $id, a click by the keys none (mygo's ClickModifiers). */
    public function clickModifiers(int $id): int
    {
        return $this->clickModsOf[$id] ?? 0;
    }

    /** The modifiers of the Return or Enter key pressed this frame (a field's Enter with Shift: a find bar's previous match), 0 when none was. */
    public function submitMods(): int
    {
        foreach ($this->keys as $k) { if ($k[1] === Key::RETURN || $k[1] === Key::ENTER) { return $k[2]; } }
        return 0;
    }

    /** What was typed this frame while node $id had the focus, for a control that is no field (a time input's segment); nothing otherwise. */
    public function typed(int $id): string
    {
        if ($this->focus !== $id || $this->typed === '') { return ''; }
        $this->consumed = true;
        $typed = $this->typed; $this->typed = ''; $this->typedAt = $this->now / 1e9; // one taker, once; the pass that follows sees what it did
        return $typed;
    }

    /** Whether Enter or Space pressed the focused node $id this frame (a click from the keyboard, not the mouse); clicked() reports both. */
    public function activated(int $id): bool
    {
        if ($this->activated === $id) { $this->activated = null; $this->keyClicked = $id; $this->consumed = true; return true; }
        return false;
    }

    /** Gives node $id the focus from code, as mygo's Focus; the ring shows when $visible, as after a key. */
    public function focusOn(int $id, bool $visible = false): void
    {
        $this->focus = $id;
        if ($visible) { $this->focusVisible = true; }
    }

    /** Whether the mouse went down this frame on the node this id had last frame (mygo's Pressed, at the press). */
    public function pressed(int $id): bool
    {
        foreach ($this->presses as [$x, $y]) {
            if ($this->under($id, $x, $y)) {
                return true;
            }
        }
        return false;
    }

    /** Where the mouse went down this frame on node $id (its last-frame rect), the last press there, or null. @return ?array{0: float, 1: float} */
    public function pressPoint(int $id): ?array
    {
        for ($i = count($this->presses) - 1; $i >= 0; $i--) {
            [$x, $y] = $this->presses[$i];
            if ($this->under($id, $x, $y)) { return [$x, $y]; }
        }
        return null;
    }

    /** Whether the mouse went down anywhere in the window this frame. */
    public function pressedAnywhere(): bool
    {
        return $this->presses !== [];
    }

    /** Whether a second press of a double click this frame landed on the node this id had last frame. */
    /** Whether a click this frame landed on node $id, without taking it, which clicked() then still reports: an editable text in a row looks at the row's. */
    public function clickOver(int $id): bool
    {
        foreach ($this->clicks as [$x, $y, $mods]) {
            if ($mods === 0 && $this->under($id, $x, $y)) { return true; }
        }
        return false;
    }

    /** Whether a second press this frame landed on node $id, without taking it, as clickOver(). */
    public function doubleClickOver(int $id): bool
    {
        foreach ($this->doubleClicks as [$x, $y]) {
            if ($this->under($id, $x, $y)) { return true; }
        }
        return false;
    }

    public function doubleClicked(int $id): bool
    {
        foreach ($this->doubleClicks as $i => [$x, $y]) {
            if ($this->under($id, $x, $y)) {
                unset($this->doubleClicks[$i]);
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /** Whether a right mouse-up this frame landed on the node this id had last frame. */
    /** @internal Context: whether the middle button clicked node $id this frame. */
    public function middleClicked(int $id): bool
    {
        foreach ($this->middleClicks as $i => [$x, $y]) {
            if ($this->under($id, $x, $y)) {
                unset($this->middleClicks[$i]);
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /** @internal Context: whether a Force Touch press went deep on node $id this frame. */
    public function forceClicked(int $id): bool
    {
        foreach ($this->forceClicks as $i => [$x, $y]) {
            if ($this->under($id, $x, $y)) {
                unset($this->forceClicks[$i]);
                return true;
            }
        }
        return false;
    }

    /**
     * @internal Context: the pinches over node $id this frame, as one: the
     * magnification they add (0 for none), and whether the gesture began and
     * ended in them; null for none.
     * @return ?array{0: float, 1: bool, 2: bool}
     */
    public function magnified(int $id): ?array
    {
        $m = 0.0; $began = false; $ended = false; $any = false;
        foreach ($this->magnifies as $i => [$x, $y, $by, $phase]) {
            if (!$this->under($id, $x, $y)) { continue; }
            $any = true;
            $m += $by;
            $began = $began || ($phase & 1) !== 0; // NSEventPhaseBegan
            $ended = $ended || ($phase & (8 | 16)) !== 0; // Ended, Cancelled
            unset($this->magnifies[$i]);
        }
        return $any ? [$m, $began, $ended] : null;
    }

    public function rightClicked(int $id): bool
    {
        foreach ($this->rightClicks as $i => [$x, $y]) {
            if ($this->under($id, $x, $y)) {
                unset($this->rightClicks[$i]);
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /** The rect this node had last frame: x, y, w, h. */
    public function rectOf(int $id): ?array
    {
        return $this->rects[$id] ?? null;
    }

    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> sliders by id, from last frame */
    private array $sliders = [];
    private ?int $sliding = null;
    /** @var array<int, float> id => where the pointer set a slider this frame, as a share of its width */
    private array $slid = [];
    /** @var array<int, int> id => arrow steps on a focused slider this frame */
    private array $steps = [];
    /** @var array<int, int> id => Page Up (1) and Page Down (-1) presses on a focused slider this frame */
    private array $pages = [];

    /** Whether slider $id is being held (pressed, dragging). */
    public function sliding(int $id): bool
    {
        return $this->sliding === $id;
    }

    /** Where the pointer put slider $id this frame, 0..1, or null. */
    public function slid(int $id): ?float
    {
        return $this->slid[$id] ?? null;
    }

    /** Page Up (positive) and Page Down (negative) presses on focused slider $id this frame, given once. */
    public function paged(int $id): int
    {
        $p = $this->pages[$id] ?? 0;
        unset($this->pages[$id]);
        return $p;
    }

    /** How many steps the arrow keys moved slider $id this frame. */
    public function stepped(int $id): int
    {
        return $this->steps[$id] ?? 0;
    }

    /**
     * Registers stepper $id for the keys and reports its steps this frame:
     * the arrow keys', plus a press on its upper (up) or lower (down)
     * half, repeated while held — after HOLD_DELAY, one every HOLD_RATE,
     * as AppKit's.
     */
    public function stepper(int $id, bool $rtl = false): int
    {
        $this->steppers[$id] = $rtl;
        $n = $this->steps[$id] ?? 0;
        unset($this->steps[$id]); // given once, whatever passes the frame builds
        $dir = $this->holding($id);
        if ($dir !== 0) {
            $held = $this->now / 1e9 - $this->hold[2]; // by the frame's clock, a test's
            $due = 1 + ($held > self::HOLD_DELAY ? (int)(($held - self::HOLD_DELAY) / self::HOLD_RATE) : 0);
            $n += ($due - $this->hold[3]) * $dir;
            $this->hold[3] = $due;
            $this->hold[4] = true;
        }
        return $n;
    }

    /**
     * How far the pointer moved since this was last asked while the mouse
     * is down from a press on node $id (its last-frame rect): [dx, dy] in
     * points, or null when it is not being dragged (mygo's Dragged). A
     * drag counts as consumed input: the frame builds again with what it
     * moved.
     */
    public function dragged(int $id): ?array
    {
        if ($this->hold === null) {
            return null;
        }
        if (($this->hold[7] ?? null) !== $id) { // not bound to this node yet: bind the press to the first node asked for whose rect holds it
            if (isset($this->hold[7])) {
                return null; // another node's drag
            }
            [$x, $y] = $this->hold;
            if (!$this->under($id, $x, $y)) {
                return null;
            }
            $this->hold[7] = $id; // the drag stays this node's as it moves out from under the press
        }
        $d = [$this->input->mouseX - $this->hold[5], $this->input->mouseY - $this->hold[6]];
        $this->hold[5] = $this->input->mouseX; $this->hold[6] = $this->input->mouseY;
        if ($d[0] != 0 || $d[1] != 0) {
            $this->consumed = true;
        }
        return $d;
    }

    /** Whether the left button is held down (a press that has not ended). */
    public function held(): bool
    {
        return $this->hold !== null;
    }

    /** Asks, while building, for the next frame as an animation would (a list scrolling under a drag near its edge), or for what the build changed to show (mygo's Invalidate). */
    public function animate(): void
    {
        $this->animating = true;
    }

    /** Which half of stepper $id the mouse is held down on: 1 the upper, -1 the lower, 0 neither. */
    public function holding(int $id): int
    {
        $r = $this->rects[$id] ?? null;
        if ($this->hold === null || $r === null || !$this->reachable($id)) {
            return 0;
        }
        [$x, $y] = $this->hold;
        if ($x < $r[0] || $x >= $r[0] + $r[2] || $y < $r[1] || $y >= $r[1] + $r[3]) {
            return 0;
        }
        return $y < $r[1] + $r[3] / 2 ? 1 : -1;
    }

    /**
     * Whether $key (a Key code, or a character such as 'a') was pressed
     * this frame with exactly $mods (Key::SHIFT|CONTROL|OPTION|COMMAND),
     * wherever the focus is, unless a focused control took it first (a
     * field its typing and moves, a button Enter and Space, a list the
     * arrows), as mygo's Context.Shortcut.
     */
    /**
     * @internal Context: offers $take the keys pressed this frame that no
     * one took (nor the focused field, as shortcut()): their characters,
     * code, modifiers and characters without the modifiers; those it
     * returns true for, taken.
     * @param \Closure(string, int, int, string): bool $take
     */
    public function takeKeys(\Closure $take): void
    {
        $field = $this->focus !== null && isset($this->fields[$this->focus]);
        foreach ($this->keys as $i => $k) {
            if ($k[3] || ($field && ($k[2] & (Key::COMMAND | Key::CONTROL)) === 0 && $k[1] !== Key::ESCAPE)) { continue; }
            if ($take($k[0], $k[1], $k[2], $k[4] ?? $k[0])) {
                $this->keys[$i][3] = true;
                $this->consumed = true;
            }
        }
    }

    public function shortcut(int|string $key, int $mods = 0): bool
    {
        $field = $this->focus !== null && isset($this->fields[$this->focus]);
        $free = $field && $this->fields[$this->focus][0]->multiline ? [Key::ESCAPE, Key::BACK, Key::FORWARD] : [Key::ESCAPE, Key::UP, Key::DOWN, Key::BACK, Key::FORWARD]; // what a focused field leaves: a single line has no use for Up and Down
        if ($field && $this->fields[$this->focus][0]->leaveEmptyBackspace && $this->fields[$this->focus][0]->text === '') { $free[] = Key::DELETE; }
        foreach ($this->keys as $i => $k) {
            if ($k[3] || $k[2] !== $mods) {
                continue;
            }
            if ($field && ($k[2] & (Key::COMMAND | Key::CONTROL)) === 0 && !in_array($k[1], $free, true)) {
                continue; // the text system had it: typing, moves, deletes
            }
            if (is_int($key) ? $k[1] === $key : (strtolower($k[0]) === strtolower($key) || $this->byPosition($k, $key))) {
                $this->keys[$i][3] = true; // one taker, as a click has; what it changed shows in this frame's next pass
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /**
     * Whether key $k, chorded with Command, Control or Option, matches the
     * character $key by its place on a US keyboard, as Cmd-S does on a
     * Cyrillic layout, which types no Latin letter there (mygo's
     * KeyByPosition): plain typing is untouched.
     * @param array{0: string, 1: int, 2: int, 3: bool} $k
     */
    private function byPosition(array $k, string $key): bool
    {
        if (($k[2] & (Key::COMMAND | Key::CONTROL | Key::OPTION)) === 0 || preg_match('/^[\x21-\x7e]$/', $k[0]) === 1) {
            return false; // a key the layout types as a US one goes by what it types
        }
        $us = Key::usChar($k[1]);
        return $us !== null && $us === strtolower($key);
    }

    /** Home (-1) or End (1) pressed on focused stepper $id this frame, or 0. */
    public function jumped(int $id): int
    {
        $j = $this->jumps[$id] ?? 0;
        unset($this->jumps[$id]);
        return $j;
    }

    /** Where the pointer at ($x, $y) puts slider $id: across its content box, from the right right to left, or up it when vertical. */
    private function slideTo(int $id, float $x, float $y): void
    {
        $r = $this->sliders[$id];
        if ($r[9]) {
            $this->slid[$id] = 1 - max(0.0, min(1.0, ($y - $r[7]) / $r[8])); // from the bottom up
            return;
        }
        $f = max(0.0, min(1.0, ($x - $r[5]) / $r[6]));
        $this->slid[$id] = $r[4] ? 1 - $f : $f; // right to left, from the right
    }

    /** The width this node had last frame, for text that wraps at the width the layout gives it. */
    public function widthOf(int $id): ?float
    {
        return isset($this->rects[$id]) ? $this->rects[$id][2] : null;
    }

    /** Whether node $id has the focus and it shows (came from the keyboard). */
    public function focusVisible(int $id): bool
    {
        return $this->focus === $id && $this->focusVisible;
    }

    /** Whether the pointer and the keys reach node $id: nothing blocks it (a modal built after it does). */
    public function reachable(int $id): bool
    {
        return !isset($this->inertIds[$id]) && ($this->order[$id] ?? 0) >= $this->barrier;
    }

    /** Whether a press at (x, y) is a drag handle's: on one, and on no focusable, slider, scroll or link inside it (those keep their presses, as mygo's interactive flags). */
    private function onHandle(float $x, float $y): bool
    {
        $on = false;
        foreach ($this->handles as $id => $r) { if ($this->under($id, $x, $y)) { $on = true; break; } }
        if (!$on) { return false; }
        foreach ($this->focusables as $id) { if ($this->under($id, $x, $y)) { return false; } }
        foreach ($this->sliders as $id => $r) { if ($this->under($id, $x, $y)) { return false; } }
        foreach ($this->scrolls as $id => $st) { if ($this->under($id, $x, $y)) { return false; } }
        foreach ($this->links as $id) { if ($this->under($id, $x, $y)) { return false; } }
        return true;
    }

    /** Whether (x, y) is on the node this id had last frame: in its rect, reachable, and not under an overlay built after it. */
    /** Whether node $id took the pointer at ($x, $y) in the last frame: in its box as it showed, not behind what blocks it (the inspector's picker). */
    public function contains(int $id, float $x, float $y): bool
    {
        return $this->under($id, $x, $y);
    }

    /**
     * Tells of a node given the key of another node of the pass in its
     * scope: they have one id, and so share one state, so that the
     * pointer, the focus and scrolling meant for one go to the other
     * (mygo's duplicateKey). Strict (the tests'), it throws there; else it
     * logs it once, and the inspector lists it.
     */
    public function duplicateKey(int $id, string|int $key, string $at): void
    {
        $msg = 'ui: two nodes in one scope have the key ' . var_export($key, true) . ', and share one state: give each a key of its own' . ($at !== '' ? " ($at)" : '');
        if ($this->strict) {
            throw new \LogicException($msg);
        }
        if (isset($this->dupKeys[$id])) { return; }
        $this->dupKeys[$id] = true;
        Logger::warn($msg, 'ui');
        $this->warn($msg);
    }

    /** Notes a warning for the inspector. */
    public function warn(string $msg): void
    {
        $this->warnings[] = $msg;
        if (count($this->warnings) > self::MAX_WARNINGS) { array_shift($this->warnings); }
        if ($this->inspector !== null) { $this->inspector->changed = true; }
    }

    /** Whether window point ($x, $y) is inside each node clipping $id, in that node's own layout. */
    private function withinClips(int $id, float $x, float $y): bool
    {
        for ($c = $this->clippedBy[$id] ?? 0; $c !== 0; $c = $this->clippedBy[$c] ?? 0) {
            [$px, $py] = [$x, $y];
            if (isset($this->worlds[$c])) {
                $inv = $this->worlds[$c]->inverse();
                if ($inv === null) { return false; }
                [$px, $py] = $inv->point($x, $y);
            }
            $r = $this->rects[$c]; $axes = $this->clipAxes[$c];
            if (($axes & Node::CLIP_X) !== 0 && ($px < $r[0] || $px >= $r[0] + $r[2])) { return false; }
            if (($axes & Node::CLIP_Y) !== 0 && ($py < $r[1] || $py >= $r[1] + $r[3])) { return false; }
        }
        return true;
    }

    private function under(int $id, float $x, float $y): bool
    {
        [$wx, $wy] = [$x, $y];
        $r = $this->rects[$id] ?? null;
        if ($r === null || isset($this->passing[$id]) || !$this->reachable($id) || !isset($this->shown[$id]) && isset($this->worlds[$id])) {
            return false; // a node turned away from the viewer takes no pointer
        }
        if (isset($this->worlds[$id])) { // the point in the node's layout, through its transform
            $inv = $this->worlds[$id]->inverse();
            if ($inv === null) {
                return false; // a singular transform, which shows nothing, takes no pointer
            }
            [$x, $y] = $inv->point($x, $y);
        }
        if ($x < $r[0] || $x >= $r[0] + $r[2] || $y < $r[1] || $y >= $r[1] + $r[3]) {
            return false;
        }
        if (isset($this->frags[$id])) {
            $on = false;
            foreach ($this->frags[$id] as [$fx, $fy, $fw, $fh]) {
                if ($x >= $fx && $x < $fx + $fw && $y >= $fy && $y < $fy + $fh) { $on = true; break; }
            }
            if (!$on) {
                return false;
            }
        }
        if (!$this->withinClips($id, $wx, $wy)) {
            return false; // cut off by a clip around it, as it shows through the clip's own transforms
        }
        $o = $this->order[$id];
        foreach ($this->overlays as [$at, $ox, $oy, $ow, $oh]) {
            if ($at > $o && $x >= $ox && $x < $ox + $ow && $y >= $oy && $y < $oy + $oh) {
                return false;
            }
        }
        return true;
    }

    public function hovered(int $id): bool
    {
        if (!$this->input->mouseInside || !$this->under($id, $this->input->mouseX, $this->input->mouseY)) {
            return false;
        }
        $p = $this->input->mouseDown ? ($this->pointerPress['id'] ?? null) : null;
        if ($p === null || $p === $id || !isset($this->rects[$p])) {
            return true; // no press held, or the node pressed gone: what is under the pointer
        }
        for ($a = $this->parents[$p] ?? 0, $n = 0; $a !== 0 && $n < 1024; $a = $this->parents[$a] ?? 0, $n++) {
            if ($a === $id) { return true; } // around the node pressed
        }
        return false; // while a press is held, only the node pressed and those around it hover (mygo's hover while pressed)
    }

    /**
     * Scrolls with the keys that scroll pages in browsers the innermost
     * container around the focus that can go that way, or under the
     * pointer without one, and reports whether one moved (mygo's
     * scrollKey): the arrows a line of 40 points, Page Up and Page Down
     * (Space and Shift-Space) a page less a line, Home and End (Cmd-Up and
     * Cmd-Down) to the ends. A focused field keeps them.
     */
    private function scrollKey(int $code, int $mods): bool
    {
        if ($this->focus !== null && isset($this->fields[$this->focus])) {
            return false;
        }
        $line = 40.0; $dx = 0.0; $dy = 0.0; $page = 0; $end = 0;
        $plain = $mods === 0; $cmd = $mods === Key::COMMAND;
        match (true) {
            $plain && $code === Key::DOWN => $dy = $line,
            $plain && $code === Key::UP => $dy = -$line,
            $plain && $code === Key::RIGHT => $dx = $line,
            $plain && $code === Key::LEFT => $dx = -$line,
            $plain && ($code === Key::PAGE_DOWN || $code === Key::SPACE) => $page = 1,
            ($plain && $code === Key::PAGE_UP) || ($mods === Key::SHIFT && $code === Key::SPACE) => $page = -1,
            (($plain || $cmd) && $code === Key::HOME) || ($cmd && $code === Key::UP) => $end = -1,
            (($plain || $cmd) && $code === Key::END) || ($cmd && $code === Key::DOWN) => $end = 1,
            default => null,
        };
        if ($dx == 0 && $dy == 0 && $page === 0 && $end === 0) {
            return false;
        }
        $chain = []; // innermost first
        for ($id = $this->focus, $n = 0; $id !== null && $id !== 0 && $n < 1024; $id = $this->parents[$id] ?? null, $n++) {
            if (isset($this->scrolls[$id])) { $chain[] = $id; }
        }
        if ($chain === [] && $this->input->mouseInside) {
            $under = [];
            foreach ($this->scrolls as $id => $_) {
                if ($this->under($id, $this->input->mouseX, $this->input->mouseY)) { $r = $this->rects[$id]; $under[$id] = $r[2] * $r[3]; }
            }
            asort($under);
            $chain = array_keys($under);
        }
        foreach ($chain as $id) {
            $s = $this->scrolls[$id];
            [$sideways, $down, $rtl] = $this->scrollAxes[$id];
            $was = [$s->extent()[0], $s->extentX()[0]];
            if ($end !== 0 && $down) {
                $s->stop();
                if ($s instanceof ListState) { $end < 0 ? $s->scrollTo(0, false) : $s->scrollToEnd(); } else { $s->scrollTo($end < 0 ? 0.0 : INF, false); }
            } elseif ($dy != 0 || $page !== 0) {
                if ($down) {
                    $s->stop();
                    $s->scrollBy($page !== 0 ? $page * max($this->rects[$id][3] - $line, $this->rects[$id][3] / 2) : $dy); // a page keeps a line of the last one in view
                }
            } elseif ($dx != 0 && $sideways) {
                $s->stop();
                $s->scrollByX($rtl ? -$dx : $dx); // right to left the offset counts from the right: Right goes back toward the start
            }
            if ([$s->extent()[0], $s->extentX()[0]] != $was) {
                return true;
            }
        }
        return false;
    }

    /** Scrolls the innermost scrolling container under (x, y) by the wheel's dx and dy points, on the axes it scrolls. */
    private function scrollAt(float $x, float $y, float $dx, float $dy): void
    {
        $best = null; $bestArea = INF; $axes = [false, true];
        foreach ($this->scrolls as $id => $state) {
            $r = $this->rects[$id];
            if ($this->under($id, $x, $y) && $r[2] * $r[3] < $bestArea) {
                $best = $state; $bestArea = $r[2] * $r[3]; $axes = $this->scrollAxes[$id];
            }
        }
        foreach ($this->areas as $id => $state) { // a text area inside a scrolling container takes the wheel over it
            $r = $this->rects[$id];
            if ($this->under($id, $x, $y) && $r[2] * $r[3] < $bestArea) {
                $best = $state; $bestArea = $r[2] * $r[3];
            }
        }
        if ($best === null) {
            return;
        }
        if ($best instanceof TextState) {
            $best->scrollY = max(0.0, $best->scrollY - $dy); // the painter clamps it to the text
            return;
        }
        $best->stop();
        if ($axes[1] && $dy != 0) {
            $best->scrollBy(-$dy);
        }
        if ($axes[0] && $dx != 0) {
            $best->scrollByX(-$dx); // right to left as well: the offset counts from the right, so the content moves as the wheel does
        }
    }
}
