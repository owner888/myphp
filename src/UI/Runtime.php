<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Transfer\{Data, Drop, DragOptions, Format, FormatUnavailable, Offer, Operation, Result};

use Closure;

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
    private array $doubleClicks = []; // [x, y] of second presses this frame
    private bool $consumed = false;
    public int $passes = 0;
    /** The theme's scroll bar width, for hit-testing thumbs; Window sets it. */
    public float $scrollbarWidth = 6;
    /** Opens a URL with the system, for links; App sets it (App::openURL). */
    public Closure $openURL;
    /** Moves the window with the mouse (1 click) or zooms or minimizes it (2) for a press on a Node::dragWindow handle; the window sets it. */
    public ?Closure $dragWindow = null;
    /** Called after a frame in which an Escape came that nothing took (mygo's host escape): a flyout or a panel closes on it. */
    public ?Closure $escape = null;
    /** The drag handles of last frame (id => rect), and the links (ids), which keep their presses from a handle. */
    private array $handles = [], $links = [];
    /** @var array<int, string> nodes with tooltips by id, from last frame */
    private array $tips = [];
    /** When the pointer last moved, and the tip to show: text, x, y (set once the pointer rested on a node). */
    private float $pointerRestAt = 0;
    private array $lastPointer = [-1.0, -1.0];
    public ?array $tip = null;
    public const TIP_DELAY = 0.6;
    /** The pointer the window should show over the hovered node: 'arrow' or 'pointer' (links). */
    public string $cursor = 'arrow';
    private bool $animating = false;
    /** The time of frames for tests (mygo's clock): nanoseconds, hrtime's when null. */
    public ?Closure $clock = null;
    /** This frame's time, in nanoseconds (mygo's Context.Now), and its number, from 1 (mygo's frame). */
    public int $now = 0, $frameNumber = 0;
    /** The frame that Context::disableAnimations shows at once. */
    public int $instantFrame = 0;
    /** That the desktop asks for less motion (macOS's Reduce Motion, mygo's Preferences.ReduceMotion): the window sets it from App::reduceMotion. */
    public bool $reduceMotion = false;
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
    /** @var array<int, true> the nodes some of which showed in the window last frame, within the scrolling containers and clips around them (Context::inView) */
    private array $shown = [];
    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> each node's rectangle as it shows in the window, clipped by its ancestors, last frame */
    private array $visible = [];
    /** Steppers built this frame, by id, for the arrow keys. */
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
    /** Toasts showing: [message, seconds left, when last aged], oldest first, the newest TOASTS at most. */
    private array $toasts = [];
    public const TOASTS = 3, TOAST_FOR = 4.0;
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
        $clip = '';
        $this->writeClipboard = function (string $s) use (&$clip) { $clip = $s; };
        $this->readClipboard = function () use (&$clip): string { return $clip; };
        $this->writeClipboardData = function (Data $d) use (&$clip) { // its text, as mygo's headless host keeps
            try { $clip = $d->read(Format::TEXT); } catch (FormatUnavailable) { $clip = ''; }
        };
        $this->selection = new TextSelection();
        $this->initMenus();
    }

    /** Puts data on the clipboard, each representation of its items in the system's formats (mygo's WriteClipboardData): the window sets it to the system's pasteboard. @var Closure(Data): void */
    public Closure $writeClipboardData;

    /** Write text to the clipboard and read it (mygo's Services' WriteClipboard and ReadClipboard), for what edits text itself, as a code editor: the window sets them to the system's pasteboard; without one, a clipboard of the runtime's own. */
    public Closure $writeClipboard, $readClipboard;
    /** When a frame is due of its own accord, as asked by after(): seconds of hrtime, or null. */
    private ?float $wakeAt = null;

    /** Asks for a frame $seconds from now, for what changes then, as a caret's blink or a hover's delay (mygo's Context.After and Painter.After): the earliest asked since the last frame began. */
    public function after(float $seconds): void
    {
        $at = hrtime(true) / 1e9 + max(0.0, $seconds);
        if ($this->wakeAt === null || $at < $this->wakeAt) { $this->wakeAt = $at; }
    }

    /** Asks, while painting, for the next frame as soon as the display can show it, for a drawing that moves (mygo's Painter.AnimationFrame). */
    public function animationFrame(): void
    {
        $this->needsFrame = true;
    }

    /**
     * Builds the frame: drains input, runs $build (again if a click changed
     * state while building, at most three times), records the layout.
     * $build(Context) must build and lay out the tree and return its root.
     */
    public function frame(callable $build): Node
    {
        $this->clicks = [];
        $this->rightClicks = [];
        $this->doubleClicks = [];
        $this->slid = [];
        $this->steps = [];
        $this->jumps = [];
        $this->keys = [];
        $this->presses = [];
        $now = hrtime(true);
        $this->frameNumber++;
        $this->now = $this->clock !== null ? ($this->clock)() : $now;
        if ($this->pointerPress !== null && !$this->pointerPress['down']) {
            $this->pointerPress = null; // the frame after it was let go saw it
        }
        $dt = $this->lastFrame === 0 ? 1 / 60 : min(0.1, ($now - $this->lastFrame) / 1e9);
        $this->lastFrame = $now;
        $pointer = [$this->input->mouseX, $this->input->mouseY];
        if ($pointer !== $this->lastPointer) {
            $this->lastPointer = $pointer;
            $this->pointerRestAt = $now / 1e9;
            $this->tip = null;
        }
        foreach ($this->scrolls as $state) {
            $state->glide($dt);
        }
        $this->wakeAt = null; $this->animating = false; // what this frame's build and paint ask for
        foreach ($this->input->events as $e) { // what a node taking its input as it comes took never came here (Input::push)
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
                        $this->focus = $t; $this->focusVisible = false;
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
                    $this->hold = [$e[1], $e[2], $now / 1e9, 0, false, $e[1], $e[2]];
                    $this->pointerPress = ['id' => $this->pressTarget($e[1], $e[2]), 'track' => new DragTrack($e[6] ?? $this->now, $e[1], $e[2]), 'down' => true, 'released' => false, 'v' => [0.0, 0.0]];
                    $this->pressDragger = $this->pressDragger($this->pointerPress['id']);
                    foreach ($this->sliders as $id => $r) {
                        if ($e[1] >= $r[0] && $e[1] < $r[0] + $r[2] && $e[2] >= $r[1] && $e[2] < $r[1] + $r[3]) {
                            $this->sliding = $id;
                            $this->slideTo($id, $e[1]);
                        }
                    }
                    break;
                case 'up':
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
                    } elseif ($e[3] === 1) { $this->rightClicks[] = [$e[1], $e[2]]; } elseif (!$moved) { $this->clicks[] = [$e[1], $e[2], ($e[5] ?? 0) & Key::MODIFIERS]; }
                    break;
                case 'drag':
                    if ($this->pointerPress !== null && $this->pointerPress['down']) {
                        $this->pointerPress['track']->move($e[3] ?? $this->now, $e[1], $e[2]);
                        $this->dragMove($e[1], $e[2]);
                    }
                    if ($this->sliding !== null && isset($this->sliders[$this->sliding])) {
                        $this->slideTo($this->sliding, $e[1]);
                    } elseif ($this->drag !== null) {
                        [$state, $last, $ratio, $horizontal] = $this->drag;
                        if ($horizontal) {
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
                case 'scroll': $this->scrollAt($e[1], $e[2], $e[3], $e[4]); break;
                case 'key':
                    if ($e[2] === Key::ESCAPE && ($e[3] & Key::MODIFIERS) === 0 && $this->valueDrag !== null && !$this->valueDrag['canceled']) {
                        if ($this->valueDrag['native']) {
                            ($this->cancelDataDrag)(); // the system's: its end clears it
                        } else {
                            $this->valueDrag['canceled'] = true; $this->valueDrag['over'] = null; // Escape gives up the drag
                        }
                        break;
                    }
                    $this->keys[] = [$e[1], $e[2], $e[3] & Key::MODIFIERS, false];
                    if ((($e[2] === Key::CONTEXT_MENU && ($e[3] & Key::MODIFIERS) === 0) || ($e[2] === Key::F10 && ($e[3] & Key::MODIFIERS) === Key::SHIFT)) && $this->menuKey()) {
                        $this->keys[array_key_last($this->keys)][3] = true; // the menu key or Shift+F10 opens the focused node's context menu
                        break;
                    }
                    if (($e[3] & Key::MODIFIERS) === Key::COMMAND && in_array(strtolower($e[1]), ['a', 'c'], true) && $this->textSelectionCommand(strtolower($e[1]) === 'a' ? 'selectAll' : 'copy')) {
                        $this->keys[array_key_last($this->keys)][3] = true; // Cmd+A and Cmd+C on selectable text (mygo's textSelectionKey)
                    }
                    break;
                case 'edit': // an editing command of the menus with no node taking its input: on selectable text, Copy and Select All
                    $this->textSelectionCommand($e[1]);
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
                    } elseif ($e[1] === ' ' && $this->focus !== null && !isset($this->fields[$this->focus]) && hrtime(true) / 1e9 - $this->typedAt >= 1.0) {
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
            $root = $build();
            $this->passes = $pass + 1;
            if (!$this->consumed) {
                break;
            }
            $this->clicks = [];
            $this->rightClicks = [];
            $this->doubleClicks = [];
            if ($this->pointerPress !== null) {
                $this->pointerPress['released'] = false; // the pass handled the release (mygo's forgetInput)
            }
            $this->dropped = []; $this->dataDropped = []; $this->filesDropped = [];
        }
        $this->building = false;
        $this->resolveMenu();
        $this->dropped = []; $this->dataDropped = []; $this->filesDropped = []; // dropped on nodes the frame did not build
        if ($this->escape !== null) {
            foreach ($this->keys as $k) {
                if (!$k[3] && $k[1] === Key::ESCAPE && $k[2] === 0) { // no control nor shortcut took it
                    ($this->escape)();
                }
            }
        }
        $this->animateLayout($root); // with the last frame's rects, before they go
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
        $this->cursor = 'arrow';
        $this->focusables = [];
        $this->memberOf = []; $this->groups = [];
        $this->fields = [];
        $this->inputs = []; $this->textClients = []; $this->keepers = [];
        $this->lists = [];
        $this->needsFrame = false;
        $this->shown = []; $this->worlds = []; $this->visible = [];
        $this->parents = []; $this->inertIds = []; $this->targets = []; $this->draggables = []; $this->dragSources = []; $this->dropTargets = [];
        $this->dataSources = []; $this->dataTargets = []; $this->fileTargets = [];
        $this->forgetSelectable();
        $this->menuNodes = [];
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
        if ($this->focus !== null && !$this->reachable($this->focus)) {
            $this->focus = null; // the focus was behind what now blocks it: Tab goes into that
        }
        $this->needsFrame = $this->needsFrame || $this->animating || ($this->hold[4] ?? false); // a held stepper keeps stepping
        // A tip: the pointer rested on a node with one for TIP_DELAY.
        $over = null;
        foreach ($this->tips as $id => $text) {
            if ($this->hovered($id)) {
                $over = [$id, $text];
            }
        }
        if ($over !== null && $this->tip === null && $now / 1e9 - $this->pointerRestAt >= self::TIP_DELAY) {
            $this->tip = [$over[1], ...$this->rects[$over[0]]]; // the text and the anchor's rect
        }
        $this->tipPending = $over !== null && $this->tip === null;
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
        [$this->input->client, $this->input->inputMethod] = $this->focus !== null && isset($this->textClients[$this->focus]) ? $this->textClients[$this->focus] : [null, true];
        if ($this->input->client !== null) { $this->input->clientOrigin = [$this->rects[$this->focus][0], $this->rects[$this->focus][1]]; }
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
        $this->root = $root;
        $this->describe();
        $this->showMenu();
        return $root;
    }

    /** Whether the focused field's caret is in the shown half of its blink (always, once it has rested a while). */
    public function caretVisible(): bool
    {
        $phase = hrtime(true) / 1e9 - $this->blinkStart;
        return $phase >= self::BLINK_FOR || ((int)floor($phase / self::BLINK)) % 2 === 0;
    }

    private bool $tipPending = false;

    /** Seconds until the window should draw a frame of its own accord (a caret toggle, a tip due, a toast going), or null. */
    public function nextWake(): ?float
    {
        $wake = $this->nextBlink();
        if ($this->wakeAt !== null) {
            $at = max(0.0, $this->wakeAt - hrtime(true) / 1e9);
            $wake = $wake === null ? $at : min($wake, $at);
        }
        if ($this->tipPending) {
            $tip = max(0.0, self::TIP_DELAY - (hrtime(true) / 1e9 - $this->pointerRestAt));
            $wake = $wake === null ? $tip : min($wake, $tip);
        }
        foreach ($this->toasts as $t) {
            $wake = $wake === null ? $t[1] : min($wake, $t[1]);
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

    /** The left press on node $id: still down, or let go since the last frame; null for none. @return ?array{id: ?int, track: DragTrack, down: bool, released: bool, v: array{0: float, 1: float}} */
    public function pressOf(int $id): ?array
    {
        return $this->pointerPress !== null && $this->pointerPress['id'] === $id ? $this->pointerPress : null;
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

    /** Shows $message as a toast for TOAST_FOR seconds (mygo's Toast): one already showing shows anew; others stack above it, TOASTS at most. */
    public function toast(string $message): void
    {
        $now = hrtime(true) / 1e9;
        $this->announce($message); // and screen readers read it out
        foreach ($this->toasts as $i => $t) {
            if ($t[0] === $message) {
                $this->toasts[$i] = [$message, self::TOAST_FOR, $now];
                return;
            }
        }
        $this->toasts[] = [$message, self::TOAST_FOR, $now];
        $this->toasts = array_slice($this->toasts, -self::TOASTS);
    }

    /**
     * The toasts showing, oldest first, aged by the time since they were
     * last asked for — unless $paused (the pointer rests on one), when
     * their time stops.
     * @return list<string>
     */
    public function toasts(bool $paused): array
    {
        $now = hrtime(true) / 1e9;
        foreach ($this->toasts as $i => $t) {
            if (!$paused) {
                $t[1] -= $now - $t[2];
            }
            $t[2] = $now;
            if ($t[1] <= 0) {
                unset($this->toasts[$i]);
            } else {
                $this->toasts[$i] = $t;
            }
        }
        $this->toasts = array_values($this->toasts);
        return array_column($this->toasts, 0);
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

    private function record(Node $n, ?Node $parent = null, int $group = 0, ?array $clip = null, int $page = 0, bool $inert = false): void
    {
        $this->rects[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        if ($n->frags !== []) { $this->frags[$n->id] = $n->frags; } // text inside a paragraph: its words are its area
        $this->parents[$n->id] = $parent?->id ?? 0;
        $this->pageOf[$n->id] = $page;
        $inert = $inert || $n->inert;
        if ($inert) { $this->inertIds[$n->id] = true; }
        if ($n->focusTarget) { $this->targets[$n->id] = true; }
        if ($n->pose !== null) {
            $this->samplePose($n);
        }
        if ($n->drags) { $this->dragSources[$n->id] = $n->dragValue; }
        if ($n->dataSource !== null) { $this->dataSources[$n->id] = $n->dataSource; }
        if (!$n->disabled) {
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
        if (min($bx + $bw, $clip[2]) > max($bx, $clip[0]) && min($by + $bh, $clip[3]) > max($by, $clip[1]) && !($n->backface && $n->transform->facesAway())) {
            $this->shown[$n->id] = true;
        }
        $vx = max($bx, $clip[0]); $vy = max($by, $clip[1]);
        $this->visible[$n->id] = [$vx, $vy, max(0.0, min($bx + $bw, $clip[2]) - $vx), max(0.0, min($by + $bh, $clip[3]) - $vy)]; // as it shows, clipped (mygo's vx, vy, vw, vh)
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
        if ($n->absolute && $parent !== null && $this->order[$parent->id] === 1) { // placed at the root: over what was built before it
            $this->overlays[] = [$this->counter, $bx, $by, $bw, $bh]; // as it shows, through its transform
        }
        if ($n->blocks || ($n->absolute && $parent !== null && $this->order[$parent->id] === 1)) {
            $this->overlayIds[$n->id] = true;
        }
        if ($n->blocks) {
            $this->barrier = $this->counter;
        }
        if ($n->focusGroup !== 0 && $group === 0) { // groups inside a group are part of it
            $group = $n->id;
            $this->groups[$group] = ['orient' => $n->focusGroup, 'selects' => $n->groupSelects, 'on' => null];
        }
        if (($n->kind === Node::BUTTON || $n->kind === Node::INPUT || $n->kind === Node::TEXTAREA || $n->focusable) && !$inert && !$n->disabled) {
            $this->focusables[] = $n->id;
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
        if ($n->kind === Node::SLIDER) {
            $this->sliders[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        }
        if ($n->tooltip !== null) {
            $this->tips[$n->id] = $n->tooltip;
        }
        if ($n->url !== null) { $this->links[] = $n->id; }
        if ($n->url !== null && $this->hovered($n->id)) {
            $this->cursor = 'pointer';
        } elseif (($n->kind === Node::INPUT || $n->kind === Node::TEXTAREA) && $this->hovered($n->id)) {
            $this->cursor = 'ibeam';
        } elseif ($n->cursor !== null && $this->hovered($n->id)) {
            $this->cursor = $n->cursor;
        }
        if (($n->kind === Node::PROGRESS && $n->fraction < 0) || $n->kind === Node::SPINNER) {
            $this->animating = true; // an indeterminate bar or a spinner moves every frame
        }
        if ($n->wraps ? abs($n->w - $n->wrapWidth) > 0.5 : ($n->wrapLast && $parent !== null && abs($parent->w - 2 * $parent->padX() - $n->wrapWidth) > 0.5)) {
            $this->needsFrame = true; // wrapped at the width it, or its container, had last frame; this frame's differs
        }
        if ($n->keepFocus) { $this->keepers[$n->id] = true; }
        if ($n->input !== null) { $this->inputs[$n->id] = $n->input; }
        if ($n->textClient !== null) { $this->textClients[$n->id] = [$n->textClient, $n->inputMethod]; }
        if ($n->kind === Node::INPUT && $n->edit !== null) {
            [, $lh] = $this->metrics?->measure('X', $n->fontSize) ?? [0, $n->h];
            $this->fields[$n->id] = [$n->edit, $n->x + self::FIELD_INSET + $n->insetL, $n->y + ($n->h - $lh) / 2, $lh, $n->fontSize, $n->w - 2 * self::FIELD_INSET - $n->insetL - $n->insetR];
        } elseif ($n->kind === Node::TEXTAREA && $n->edit !== null) {
            $this->areas[$n->id] = $n->edit;
            [, $lh] = $this->metrics?->measure('X', $n->fontSize) ?? [0, $n->h];
            $this->fields[$n->id] = [$n->edit, $n->x + self::FIELD_INSET, $n->y + self::FIELD_INSET_Y, $lh, $n->fontSize, $n->w - 2 * self::FIELD_INSET];
        }
        if ($n->scroll !== null) {
            $this->scrolls[$n->id] = $n->scroll;
            $this->scrollAxes[$n->id] = [$n->scrollX, $n->scrollY];
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
        foreach ($n->kids as $k) {
            $this->record($k, $n, $group, $clip, $n->page ? $n->id : $page, $inert);
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
                $ev = new InputEvent(InputEvent::POINTER_MOVE, button: -1);
                break;
            case 'drag':
                $id = $this->captured;
                $ev = new InputEvent(InputEvent::POINTER_MOVE, button: 0);
                break;
            case 'up':
                $id = $this->captured;
                $ev = new InputEvent(InputEvent::POINTER_UP, mods: ($e[5] ?? 0) & Key::MODIFIERS, button: $e[3], clicks: max(1, $e[4] ?? 1));
                break;
            case 'scroll':
                $id = $this->inputAt($e[1], $e[2]);
                $ev = new InputEvent(InputEvent::SCROLL, dx: -$e[3], dy: -$e[4], precise: $e[5] ?? false);
                break;
            case 'key':
                $id = $this->focus;
                $ev = new InputEvent(InputEvent::KEY_DOWN, key: $e[2], mods: $e[3] & Key::MODIFIERS, text: $e[1]);
                break;
            case 'edit':
                $id = $this->focus;
                $ev = new InputEvent(InputEvent::COMMAND, text: $e[1]);
                break;
            default:
                return false;
        }
        if ($id === null || !isset($this->inputs[$id])) { return $e[0] === 'move'; }
        if ($ev->kind !== InputEvent::KEY_DOWN && $ev->kind !== InputEvent::COMMAND) {
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
            $r = $this->rects[$id];
            [$horizontal, $vertical] = $this->scrollAxes[$id];
            foreach ([[false, $vertical], [true, $horizontal]] as [$axis, $on]) {
                if (!$on) {
                    continue;
                }
                $extent = $axis ? $state->extentX() : $state->extent();
                $t = Scrollbar::thumb($r, $extent, $this->scrollbarWidth, $axis);
                if ($t === null || $x < $t[0] - $g || $x >= $t[0] + $t[2] + $g || $y < $t[1] - $g || $y >= $t[1] + $t[3] + $g) {
                    continue;
                }
                $state->stop();
                $this->drag = [$state, $axis ? $x : $y, Scrollbar::pointsPerThumbPoint($r, $extent, $axis), $axis];
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
            $byte = $this->metrics->indexForX($state->text, $size, $x - $tx);
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
        [$a, $b] = $layout->lines[$line];
        $end = $b > $a && $state->text[$b - 1] === "\n" ? $b - 1 : $b;
        $page = max(1, (int)(($this->rects[$this->focus][3] - 2 * self::FIELD_INSET_Y) / $lh) - 1);
        $toLine = function (int $l) use ($state, $layout, $m, $extend) {
            [$x, ] = $layout->caret($m, $state->cursor);
            $state->setCursor($layout->hit($m, $x, ($l + 0.5) * $layout->lineHeight), $extend);
        };
        switch ($base) {
            case 'insertNewline:': $state->insert("\n"); break;
            case 'moveUp:': if ($line === 0) { $state->setCursor(0, $extend); } else { $toLine($line - 1); } break;
            case 'moveDown:': if ($line === count($layout->lines) - 1) { $state->setCursor(strlen($state->text), $extend); } else { $toLine($line + 1); } break;
            case 'pageUp:': $toLine(max(0, $line - $page)); break;
            case 'pageDown:': $toLine(min(count($layout->lines) - 1, $line + $page)); break;
            case 'moveToBeginningOfLine:': case 'moveToLeftEndOfLine:': $state->setCursor($a, $extend); break;
            case 'moveToEndOfLine:': case 'moveToRightEndOfLine:': $state->setCursor($end, $extend); break;
            case 'deleteToBeginningOfLine:': if (!$state->hasSelection()) { $state->anchor = $a; } $state->insert(''); break;
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
            $page = max(1, (int)($l->viewport / max(1.0, $l->estimate)) - 1);
            $extend = $l->selection !== null && str_ends_with($selector, 'AndModifySelection:'); // Shift with the keys: the choice extends
            $base = $extend ? substr($selector, 0, -strlen('AndModifySelection:')) . ':' : $selector;
            $to = match ($base) {
                'moveUp:' => max(0, $l->selected - 1), 'moveDown:' => $l->selected + 1,
                'pageUp:', 'scrollPageUp:' => max(0, $l->selected - $page), 'pageDown:', 'scrollPageDown:' => $l->selected + $page,
                'moveToBeginningOfDocument:', 'scrollToBeginningOfDocument:' => 0, 'moveToEndOfDocument:', 'scrollToEndOfDocument:' => $l->count - 1,
                default => null,
            };
            if ($to !== null) { $extend ? $l->extendTo($to) : $l->choose($to); return true; }
            if ($selector === 'selectAll:' && $l->selection !== null) { $l->selectAll(); return true; }
            if ($selector === 'insertNewline:') { if ($l->selected >= 0) { $l->submit(); } return true; }
        }
        if ($this->focus !== null && isset($this->sliders[$this->focus])) {
            switch ($selector) {
                case 'moveLeft:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) - 1; return true;
                case 'moveRight:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) + 1; return true;
            }
        }
        if ($this->focus !== null && isset($this->steppers[$this->focus])) {
            switch ($selector) {
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
            case 'cancelOperation:': // Escape: a control lets the focus go; a field keeps it (a search field clears on it); the window still gets the key
                if ($this->focus !== null && !isset($this->fields[$this->focus])) { $this->focus = null; }
                return false;
            case 'insertNewline:':
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
        $reachable = array_values(array_filter($this->focusables, $this->reachable(...)));
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

    private function firstMember(int $group, array $reachable): int
    {
        foreach ($reachable as $id) { if (($this->memberOf[$id] ?? 0) === $group) { return $id; } }
        return $group;
    }

    /** Gives the keyboard focus to $id, visibly, remembering it for its group. */
    private function focusTo(int $id): void
    {
        $this->focus = $id;
        $this->focusVisible = true;
        if (isset($this->memberOf[$id])) { $this->groupLast[$this->memberOf[$id]] = $id; }
        if (isset($this->fields[$id])) {
            $this->fields[$id][0]->selectAll();
        }
    }

    /** The arrows, Home and End inside a focus group: among its members, round; with selects, the move clicks the member. */
    private function groupKey(string $selector): bool
    {
        $g = $this->memberOf[$this->focus] ?? 0;
        if ($g === 0) { return false; }
        $o = $this->groups[$g]['orient'];
        $dir = match ($selector) {
            'moveLeft:' => $o & Node::HORIZONTAL ? -1 : 0, 'moveRight:' => $o & Node::HORIZONTAL ? 1 : 0,
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

    /** Whether a mouse-up this frame landed on the node this id had last frame, or Enter/Space pressed it while focused. */
    public function clicked(int $id): bool
    {
        $this->clickable[$id] = true; // assistive technology presses it
        if ($this->activated === $id) {
            $this->activated = null;
            $this->consumed = true;
            return true;
        }
        foreach ($this->clicks as $i => [$x, $y, $mods]) {
            if ($this->under($id, $x, $y)) {
                unset($this->clicks[$i]);
                $this->consumed = true;
                $this->clickMods = $mods;
                return true;
            }
        }
        return false;
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
        $typed = $this->typed; $this->typed = ''; $this->typedAt = hrtime(true) / 1e9; // one taker, once; the pass that follows sees what it did
        return $typed;
    }

    /** Whether Enter or Space pressed the focused node $id this frame (a click from the keyboard, not the mouse); clicked() reports both. */
    public function activated(int $id): bool
    {
        if ($this->activated === $id) { $this->activated = null; $this->consumed = true; return true; }
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

    /** Whether the mouse went down anywhere in the window this frame. */
    public function pressedAnywhere(): bool
    {
        return $this->presses !== [];
    }

    /** Whether a second press of a double click this frame landed on the node this id had last frame. */
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

    /** @var array<int, int> choices menus made for selects, by node id, until the select reads them */
    private array $choices = [];

    /** A select's menu chose option $i (called from the menu's action, after the popup returned). */
    public function chose(int $id, int $i): void
    {
        $this->choices[$id] = $i;
    }

    /** The option a select's menu chose since it last asked, or null. */
    public function chosen(int $id): ?int
    {
        $i = $this->choices[$id] ?? null;
        unset($this->choices[$id]);
        return $i;
    }

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
    public function stepper(int $id): int
    {
        $this->steppers[$id] = true;
        $n = $this->steps[$id] ?? 0;
        unset($this->steps[$id]); // given once, whatever passes the frame builds
        $dir = $this->holding($id);
        if ($dir !== 0) {
            $held = hrtime(true) / 1e9 - $this->hold[2];
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
            if (is_int($key) ? $k[1] === $key : strtolower($k[0]) === strtolower($key)) {
                $this->keys[$i][3] = true; // one taker, as a click has; what it changed shows in this frame's next pass
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /** Home (-1) or End (1) pressed on focused stepper $id this frame, or 0. */
    public function jumped(int $id): int
    {
        $j = $this->jumps[$id] ?? 0;
        unset($this->jumps[$id]);
        return $j;
    }

    private function slideTo(int $id, float $x): void
    {
        $r = $this->sliders[$id];
        $this->slid[$id] = max(0.0, min(1.0, ($x - $r[0]) / max(1.0, $r[2])));
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
    private function under(int $id, float $x, float $y): bool
    {
        $r = $this->rects[$id] ?? null;
        if ($r === null || !$this->reachable($id) || !isset($this->shown[$id]) && isset($this->worlds[$id])) {
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
        return $this->input->mouseInside && $this->under($id, $this->input->mouseX, $this->input->mouseY);
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
            $best->scrollByX(-$dx);
        }
    }
}
