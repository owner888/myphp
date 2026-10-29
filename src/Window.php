<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\{Access, Dock, Drag, Drop, Metal, ObjC, Pasteboard, Screens, View, WindowStates};
use MyPHP\Transfer\{Data, DragOptions, Format, Operation, Result};
use MyPHP\UI\{AccessTree, Context, DataDragEvent, DisplayList, Image, Input, Layout, Painter, Runtime};
use RuntimeException;

/**
 * A window showing native UI or a web page. Create it from App::whenReady().
 * With native content, each frame runs the view function through the
 * window's Runtime, lays the tree out to the window's size and paints it;
 * frames happen on demand. With a page, the window holds a WebView whose
 * bridge routes calls to Ipc and events back. Window::flyout() makes a
 * flyout: a borderless panel next to a rectangle of its parent, or a tray
 * icon; WindowOptions::$panel a panel above all apps, as launchers are.
 */
final class Window
{
    private const TITLED = 1, CLOSABLE = 2, MINIATURIZABLE = 4, RESIZABLE = 8, FULL_SCREEN = 1 << 14, FULL_SIZE_CONTENT = 1 << 15, OCCLUSION_VISIBLE = 1 << 1;
    private const NONACTIVATING = 1 << 7; // NSWindowStyleMaskNonactivatingPanel
    private const NORMAL_LEVEL = 0, FLOATING_LEVEL = 3, POP_UP_MENU_LEVEL = 101;
    private const FULL_SCREEN_PRIMARY = 1 << 7, FULL_SCREEN_AUXILIARY = 1 << 8, FULL_SCREEN_NONE = 1 << 9, CAN_JOIN_ALL_SPACES = 1 << 0, TRANSIENT = 1 << 3, IGNORES_CYCLE = 1 << 6;
    /**
     * A flyout's placement (Window::flyout): its side and alignment
     * (Flyout's), gap, anchor and tray, whether it takes the keyboard, and
     * the size it is placed at; whether the window is a panel
     * (WindowOptions::$panel).
     * @var ?array{side: int, align: int, gap: int, anchor: Rectangle, tray: ?Tray, focusable: bool, popover: bool, width: int, height: int}
     */
    private ?array $flyout = null;
    /**
     * The NSPopover a flyout with FlyoutOptions::$popover shows in, and its
     * view controller: the popover makes its window as it shows, which is
     * $ns while it shows (null otherwise); $popoverClosing tells its close
     * from hide() and destroy() from the user's. $key is where the window
     * is in $open: its NSWindow's address, or its popover's.
     */
    private $popover = null, $popoverController = null;
    private bool $popoverClosing = false;
    private int $key = 0;
    private bool $panel = false;
    /** What Window::flyout hands the constructor. */
    private static ?array $creating = null;
    private static bool $panelsDefined = false;
    /** The monitors of presses outside the app and on a flyout's anchor (watchOutsideClicks), and the release type of the press the latter ate, or 0. */
    private static $outsideClicks = null, $anchorPresses = null;
    private static int $eatingPress = 0;
    /** How many windows are closing their children: the last window to close is reported once they all are. */
    private static int $closingParents = 0;
    /** The parent window, of a child window (WindowOptions::$parent), and whether it is its sheet ($modal) and shows as one. */
    private ?Window $parent = null;
    private bool $modal = false, $sheet = false;
    /** The title the app set, the file the window shows and whether it is edited (setRepresentedFile, setDocumentEdited). */
    private string $title = '', $representedFile = '';
    private bool $documentEdited = false;
    /** Whether the window was closed; whether it is maximized, as the last resize found; whether it drew, or its page got, ready to show. */
    private bool $destroyed = false, $maximized = false, $readyShown = false;
    /** The request for the user's attention FlashFrame made, and whether a full screen none window was let onto all Spaces as an auxiliary. */
    private int $attention = 0;
    private bool $auxiliary = false;
    private bool $hiddenTitleBar = false;
    /** Frames asked for while nothing of the window showed, drawn once some of it shows again (mygo's held). */
    private bool $held = false;
    /** What the view last asked of the window (answer): whether the traffic lights show, the aspect it keeps. */
    private ?bool $buttonsAsked = null;
    /** What the view asked of full screen: the legacy one (IINA's "Use legacy full screen"), the other screens blacked out in it (its "Black out other monitors"). */
    private bool $legacyAsked = false, $blackOutAsked = false;
    /** In the legacy full screen: the frame and style it left; the black windows over the other screens. */
    private ?array $legacy = null;
    private array $blackOuts = [];
    private ?float $aspectAsked = null;
    /** The frame the window had before it went full screen by its own animation, which it goes back to. */
    private $windowedFrame = null;
    /** @var ?array{0: float, 1: float} where the close button's top-left corner goes (WindowOptions::$trafficLightPosition) */
    private ?array $trafficLights = null;
    private const BACKING_BUFFERED = 2;
    /** @var array<int, Window> open windows by NSWindow address */
    private static array $open = [];
    private static bool $delegateDefined = false;

    private static int $nextId = 1;

    private $ns;
    private $view = null;
    /** The window's content view, which holds the page or the native view, and the material behind it (setVibrancy), as mygo's: AppKit draws a broken title bar for a web inspector docked in the window's frame view. */
    private $container = null, $effect = null;
    /** The view of the nodes painted under the materials (Node::belowMaterials), over the videos, made as a frame first has some; whether it shows any, and whether it is to be put in its place among the views. */
    private $under = null;
    private bool $underShown = false, $underNew = false;
    /** The window's background (setBackgroundColor), and whether it is transparent (WindowOptions::$transparent). */
    private ?Background $background = null;
    private bool $transparent = false;
    private ?Page $page = null;
    /** @var list<WebView> the web views of native UI (newWebView) */
    private array $webViews = [];
    /** @var array<int, array{player: \WeakReference<\MyPHP\Media\Player>, clip: mixed}> the videos the window showed, by player: the view under the native one that holds the picture's layer */
    private array $videoHosts = [];
    /** @var array<int, array{view: mixed, kind: string}> the system materials the window showed (Node::systemMaterial), by node: an NSVisualEffectView or NSGlassEffectView under the native view, over the videos, and what it is */
    private array $materials = [];
    /** The node of a web view the runtime's focus was on last frame, which gave its page the keyboard. */
    private ?int $webFocus = null;
    /** The WKWebViewConfiguration WebKit hands over for window.open(), while Window::opened() makes the window. */
    private static $opening = null;
    public readonly int $id;
    public readonly ?Input $input;
    public readonly ?Runtime $runtime;
    private array $stats = ['frames' => 0, 'ms' => 0, 'since' => 0];
    /** The statistics of each frame MYPHP_FRAME_STATS asks for, or null (mygo's frameStats). */
    private ?UI\FrameStats $frameStats = null;
    /** @var array<string, list<\Closure>> listeners by event */
    private array $listeners = [];
    private string $stateKey = '';
    public const EVENTS = ['close', 'closed', 'focus', 'blur', 'show', 'hide', 'readyToShow', 'resize', 'move', 'maximize', 'unmaximize', 'minimize', 'restore', 'enterFullScreen', 'leaveFullScreen', 'fileDrop'];
    /** @var array<int, Window> by content view address, for drops */
    private static array $byView = [];

    public function __construct(WindowOptions $o)
    {
        if (!App::isReady()) {
            throw new RuntimeException('create windows from App::whenReady()');
        }
        if ($o->content === null && $o->html === null && $o->url === null && self::$opening === null) {
            throw new RuntimeException('WindowOptions needs content, html or url');
        }
        $this->id = self::$nextId++;
        $this->flyout = self::$creating;
        self::$creating = null;
        $this->panel = $o->panel && $this->flyout === null;
        if ($this->panel) { // above all apps, out of the way of minimizing, zooming and full screen (mygo's panel options)
            $o = clone $o;
            $o->disableMinimize = $o->disableMaximize = $o->disableFullScreen = true;
            $o->alwaysOnTop = true;
        }
        $this->input = $o->content === null ? null : new Input();
        $this->runtime = $o->content === null ? null : new Runtime($this->input, App::text());
        if ($this->runtime !== null) {
            $this->runtime->openURL = Shell::openExternal(...);
            $this->runtime->dragWindow = $this->dragWindow(...);
            $this->runtime->writeClipboard = Pasteboard::setText(...);
            $this->runtime->writeClipboardData = Pasteboard::writeData(...);
            $this->runtime->readClipboardData = Pasteboard::readData(...);
            $this->runtime->clipboardFormats = Pasteboard::formats(...);
            $this->runtime->popupMenu = function (array $items, float $x, float $y, bool $rtl, \Closure $chosen): void {
                $wire = function (array $items) use (&$wire, $chosen): void { // each item reports its place
                    foreach ($items as $it) {
                        if ($it->type === MenuItem::SEPARATOR || $it->type === MenuItem::HEADER) { continue; }
                        if ($it->submenu !== []) { $wire($it->submenu); continue; }
                        $it->click = function (MenuItem $chosenItem) use ($chosen) { $chosen((int)$chosenItem->id); };
                    }
                };
                $wire($items);
                App::later(fn() => (new Menu($items))->popupAt($this, $x, $y, $rtl)); // after the frame, as mygo's host shows it after the event
            };
            $this->runtime->readClipboard = Pasteboard::text(...);
            $this->runtime->escape = $this->escape(...);
            $this->runtime->requestFrame = $this->invalidate(...); // a router's page changed from outside a frame
            $this->runtime->showWindow = function (bool $on): void {
                if (!$on) { $this->hide(); return; }
                $this->show();
                $this->placeVideos(ObjC::msg('r:', $this->view, 'bounds')->h); // a video given back (picture in picture) in its place now, as the last frame placed it: the system animates into it before a frame comes
            };
            $this->runtime->startDataDrag = function (Data $data, mixed $local, DragOptions $o, float $x, float $y): ?\Throwable {
                try {
                    $this->dragData($data, $local, $o, $x, $y);
                    return null;
                } catch (\InvalidArgumentException|RuntimeException $e) {
                    return $e; // what is wrong with it: the source is told
                }
            };
            $this->runtime->cancelDataDrag = $this->cancelDataDrag(...);
            $this->runtime->setDropFormats = fn(array $formats) => Drag::setFormats($this->view, $formats);
            $this->runtime->snapshot = $this->snapshot(...);
        }

        $this->stateKey = $o->stateKey;
        if ($this->runtime !== null && $o->page->devTools->on()) {
            $this->runtime->inspector = new UI\Inspector(); // F12, Alt+Cmd+I and the menu's Toggle Developer Tools open it
        }
        $this->parent = $o->parent; $this->modal = $o->modal;
        $saved = $o->stateKey !== '' ? WindowStates::get($o->stateKey) : null;
        if ($saved !== null && ($saved->width <= 0 || $saved->height <= 0)) { $saved = null; }
        if ($this->flyout !== null) {
            $this->createFlyout($o);
        } else {
            $this->createWindow($o);
        }
        $this->trafficLights = $o->trafficLightPosition;
        $this->setTitle($o->title ?? App::name());

        if ($o->content === null) {
            $opening = self::$opening;
            self::$opening = null;
            $this->page = new Page($this, null, ObjC::rect(0, 0, $o->width, $o->height), $o->page, $opening);
            $this->view = $this->page->web->ns;
            if ($opening === null && $o->html !== null) {
                $this->page->loadHTML($o->html);
            } elseif ($opening === null) {
                $this->page->loadURL($o->url); // WebKit loads window.open()'s itself
            }
        } else {
            $this->view = $this->nativeView($o);
            if (($this->frameStats = UI\FrameStats::fromEnv()) !== null) {
                Metal::$afterPresent[ObjC::addr($this->view)] = function (): void {
                    $this->frameStats->lap(UI\FrameStats::PRESENT);
                    $this->frameStats->end($this->runtime->frameNumber, App::text()->textSystem()->made, 'Metal');
                };
            }
            Metal::setClearColor($this->view, App::theme()->background);
            $view = $this->view;
            $this->runtime->underLayer = true; // nodes belowMaterials() painted in a view of their own, under the materials
            Metal::$afterFrame[ObjC::addr($this->view)] = function (): void { // the layer under the materials drawn in the same turn as the frame, the list it used let go
                if ($this->under !== null && ($this->runtime->underPaints !== [] || $this->underShown)) { ObjC::msg('v:', $this->under, 'draw'); }
                $this->runtime->framePresented();
            };
            $this->runtime->onAccess = fn(AccessTree $t) => Access::update($view, $t); // VoiceOver reads each frame once it asked
        }
        $bounds = ObjC::msg('r:', $this->view, 'frame');
        $this->container = ObjC::msg('p:r', ObjC::alloc('NSView'), 'initWithFrame:', ObjC::rect(0, 0, $bounds->w, $bounds->h));
        ObjC::msg('v:L', $this->view, 'setAutoresizingMask:', 18); // width and height sizable
        ObjC::msg('v:p', $this->container, 'addSubview:', $this->view);
        if ($this->flyout === null || !$this->flyout['popover']) {
            ObjC::msg('v:p', $this->ns, 'setContentView:', $this->container);
        }
        $this->transparent = $o->transparent;
        if ($o->transparent) {
            ObjC::msg('v:b', $this->ns, 'setOpaque:', false);
            ObjC::msg('v:p', $this->ns, 'setBackgroundColor:', ObjC::msg('p:', ObjC::cls('NSColor'), 'clearColor'));
            $this->clearContent();
        } elseif ($o->backgroundColor !== '') {
            $this->setBackgroundColor($o->backgroundColor);
        }
        if ($o->vibrancy !== '') {
            $this->setVibrancy($o->vibrancy);
        }
        $delegate = self::delegate();
        if ($this->flyout !== null && $this->flyout['popover']) {
            $this->createPopover($delegate);
            $this->key = ObjC::addr($this->popover);
        } else {
            ObjC::msg('b:p', $this->ns, 'makeFirstResponder:', $this->view);
            ObjC::msg('v:p', $this->ns, 'setDelegate:', $delegate);
            $this->key = ObjC::addr($this->ns);
        }
        self::$open[$this->key] = $this;
        if ($this->trafficLights !== null && ($container = $this->titleBarContainer()) !== null) { // AppKit lays the title bar out at each step of a zoom's animation: the lights follow each
            ObjC::msg('v:b', $container, 'setPostsFrameChangedNotifications:', true);
            ObjC::msg('v:pppp', ObjC::msg('p:', ObjC::cls('NSNotificationCenter'), 'defaultCenter'), 'addObserver:selector:name:object:', $delegate, ObjC::sel('titleBarLaidOut:'), ObjC::str('NSViewFrameDidChangeNotification'), $container);
        }
        self::$byView[ObjC::addr($this->view)] = $this;
        Drag::handle(function ($view, string $kind, DataDragEvent $d, float $x, float $y): bool {
            $w = self::$byView[ObjC::addr($view)] ?? null;
            return $w !== null && $w->dataEvent($kind, $d, $x, $y);
        });
        Drop::handle(function ($view, array $paths, float $x, float $y) {
            $w = self::$byView[ObjC::addr($view)] ?? null;
            if ($w === null) {
                return;
            }
            $e = new FileDropEvent($w, $paths, $x, $y);
            foreach ($w->listeners['fileDrop'] ?? [] as $fn) {
                $fn($e);
            }
            $w->page?->filesDropped($paths, $x, $y);
        });
        [$fullScreen, $maximized] = [$o->fullScreen, $o->maximized];
        if ($this->flyout !== null) {
            $this->placeFlyout();
        } elseif ($saved !== null) { // where the state key left it, as long as it shows on a display; else at its size, as far as the display allows, centered (mygo's restoreWindowState)
            $r = new Rectangle($saved->x, $saved->y, $saved->width, $saved->height);
            $displays = Screens::displays();
            if (WindowStates::onDisplay($r, array_column($displays, 'workArea'))) {
                $this->setBounds($r);
            } else {
                $wa = $displays[0]['workArea'] ?? null;
                ObjC::msg('v:rb', $this->ns, 'setFrame:display:', ObjC::rect(0, 0, $wa === null ? $r->width : min($r->width, $wa->width), $wa === null ? $r->height : min($r->height, $wa->height)), false);
                $this->center();
            }
            [$fullScreen, $maximized] = [$saved->fullScreen, $saved->maximized];
        } else {
            if ($o->x === 0 && $o->y === 0) {
                $this->center();
            } else {
                $b = $this->bounds();
                $this->setBounds(new Rectangle($o->x, $o->y, $b->width, $b->height));
            }
            if ($this->stateKey !== '') { // the bounds it asked for, which a window that starts maximized or full screen has not shown yet (mygo's initState)
                $b = $this->bounds();
                WindowStates::set($this->stateKey, new WindowState($b->x, $b->y, $b->width, $b->height, $maximized, $fullScreen));
            }
        }
        if ($fullScreen) {
            ObjC::msg('v:p', $this->ns, 'toggleFullScreen:', null);
        } elseif ($maximized) {
            ObjC::msg('v:p', $this->ns, 'zoom:', null);
        }
        $this->maximized = $this->isMaximized();
        if (!$o->hidden) {
            $this->show();
        }
        App::windowCreated($this);
    }

    /** The NSWindow of a window (not a flyout), with its style, title bar and limits. */
    private function createWindow(WindowOptions $o): void
    {
        $style = self::TITLED | ($o->disableClose ? 0 : self::CLOSABLE) | ($o->disableMinimize ? 0 : self::MINIATURIZABLE) | ($o->disableResize ? 0 : self::RESIZABLE);
        $hidden = match ($o->titleBarStyle) { 'default' => false, 'hidden', 'hiddenInset' => true, default => throw new \InvalidArgumentException("titleBarStyle {$o->titleBarStyle}") };
        $this->hiddenTitleBar = $hidden && !$o->frameless; // the traffic lights stay, over the view
        if ($hidden || $o->frameless) { $style |= self::FULL_SIZE_CONTENT; } // the view fills the window, under the title bar
        $content = ObjC::rect(0, 0, $o->width, $o->height);
        if (!$o->useContentSize) { // the size is the window's: the content's is what its frame leaves
            $content = ObjC::msg('r:rL', ObjC::cls('NSWindow'), 'contentRectForFrameRect:styleMask:', $content, $style);
            $content = ObjC::rect(0, 0, $content->w, $content->h);
        }
        if ($this->panel) {
            $style |= self::NONACTIVATING; // from the start: the window server learns from the style it is created with that it never activates the app
        }
        self::definePanels();
        $this->ns = ObjC::msg('p:rLLb', ObjC::alloc($this->panel ? 'MyPHPPanel' : 'MyPHPWindow'), 'initWithContentRect:styleMask:backing:defer:', $content, $style, self::BACKING_BUFFERED, false);
        ObjC::msg('v:b', $this->ns, 'setReleasedWhenClosed:', false);
        ObjC::msg('v:l', $this->ns, 'setTabbingMode:', 2); // NSWindowTabbingModeDisallowed
        if ($hidden || $o->frameless) { // as mygo's: the bar transparent and untitled, the traffic lights left; hiddenInset lowers them with an empty toolbar
            ObjC::msg('v:b', $this->ns, 'setTitlebarAppearsTransparent:', true);
            ObjC::msg('v:l', $this->ns, 'setTitleVisibility:', 1); // NSWindowTitleHidden
        }
        if ($o->frameless) {
            for ($i = 0; $i < 3; $i++) { ObjC::msg('v:b', ObjC::msg('p:l', $this->ns, 'standardWindowButton:', $i), 'setHidden:', true); }
        }
        if ($o->titleBarStyle === 'hiddenInset' && !$o->frameless) {
            $tb = ObjC::msg('p:p', ObjC::alloc('NSToolbar'), 'initWithIdentifier:', ObjC::str('myphp.titlebar'));
            ObjC::msg('v:b', $tb, 'setShowsBaselineSeparator:', false);
            ObjC::msg('v:p', $this->ns, 'setToolbar:', $tb);
        }
        if ($o->minWidth > 0 || $o->minHeight > 0) { $this->setMinimumSize($o->minWidth, $o->minHeight); }
        if ($o->maxWidth > 0 || $o->maxHeight > 0) { $this->setMaximumSize($o->maxWidth, $o->maxHeight); }
        ObjC::msg('v:b', $this->ns, 'setMovable:', !$o->disableMove);
        if ($o->disableMaximize) { $this->setMaximizable(false); }
        if ($this->panel) { // on the Space the user is on, full screen ones too, out of Mission Control and the window cycle; it stays as the app is deactivated
            ObjC::msg('v:L', $this->ns, 'setCollectionBehavior:', self::CAN_JOIN_ALL_SPACES | self::FULL_SCREEN_AUXILIARY | self::IGNORES_CYCLE | self::TRANSIENT);
            ObjC::msg('v:b', $this->ns, 'setHidesOnDeactivate:', false);
        } else {
            ObjC::msg('v:L', $this->ns, 'setCollectionBehavior:', $o->disableFullScreen ? self::FULL_SCREEN_NONE : self::FULL_SCREEN_PRIMARY);
        }
        if ($o->alwaysOnTop) { ObjC::msg('v:l', $this->ns, 'setLevel:', self::FLOATING_LEVEL); }
        ObjC::msg('v:b', $this->ns, 'setHasShadow:', !$o->disableShadow);
        if ($o->opacity > 0 && $o->opacity < 1) { ObjC::msg('v:d', $this->ns, 'setAlphaValue:', $o->opacity); }
    }

    /**
     * The panel of a flyout (mygo's createFlyout): borderless and
     * nonactivating, so that it never activates the app; the key window
     * only when it is focusable, while its parent stays the main window.
     */
    private function createFlyout(WindowOptions $o): void
    {
        if ($this->flyout['popover']) {
            return; // its popover makes its window as it shows (createPopover)
        }
        self::definePanels();
        $this->ns = ObjC::msg('p:rLLb', ObjC::alloc('MyPHPFlyout'), 'initWithContentRect:styleMask:backing:defer:', ObjC::rect(0, 0, $o->width, $o->height), self::NONACTIVATING, self::BACKING_BUFFERED, false);
        ObjC::msg('v:b', $this->ns, 'setReleasedWhenClosed:', false);
        ObjC::msg('v:b', $this->ns, 'setHidesOnDeactivate:', false); // it stays with its parent as the app is deactivated; one that takes the keyboard closes
        ObjC::msg('v:L', $this->ns, 'setCollectionBehavior:', self::TRANSIENT | self::IGNORES_CYCLE | self::FULL_SCREEN_AUXILIARY);
        if ($this->flyout['tray'] !== null) {
            ObjC::msg('v:l', $this->ns, 'setLevel:', self::POP_UP_MENU_LEVEL); // over the windows of other apps, as the menu bar's menus
        }
        ObjC::msg('v:b', $this->ns, 'setHasShadow:', !$o->disableShadow);
        if ($o->opacity > 0 && $o->opacity < 1) { ObjC::msg('v:d', $this->ns, 'setAlphaValue:', $o->opacity); }
    }

    private function nativeView(WindowOptions $o)
    {
        $content = $o->content;
        $text = App::text();
        return View::create(App::metal(), ObjC::rect(0, 0, $o->width, $o->height), $this->input,
            function ($view, DisplayList $list) use ($content, $text) {
                $bounds = ObjC::msg('r:', $view, 'bounds');
                $t0 = hrtime(true);
                $this->frameStats?->begin($text->textSystem()->made);
                $text->beginFrame();
                $list->pathQuad ??= $text->pathQuad(...);
                $this->runtime->scrollbarWidth = App::theme()->scrollbarWidth;
                $this->runtime->preferences = App::preferences();
                $this->runtime->reduceMotion = $this->runtime->preferences->reduceMotion;
                $this->runtime->rightToLeft = I18n::direction(App::locale()) === Dir::RightToLeft; // the direction of the language the app shows
                $this->runtime->setWindowFocus($this->ns !== null && ObjC::msg('b:', $this->ns, 'isKeyWindow')); // a selection shows in the key window
                App::metal()->setDark(App::theme()->dark);
                $in = $this->runtime->inspector;
                if ($in !== null && $in->open) { $in->hz = $this->refreshRate(); }
                $this->runtime->fullScreen = $this->isFullScreen();
                $root = $this->runtime->frame(function () use ($content, $text, $bounds, $in) {
                    $in?->startPass();
                    $c = new Context($text, $this->runtime, $in?->contentWidth($bounds->w) ?? $bounds->w, $bounds->h, App::theme(), App::scale());
                    $c->titleBar = $this->titleBar();
                    $c->vibrancy = $this->effect !== null || $this->popover !== null; // a popover draws its material
                    ($content->view)($c);
                    $c->toasts(); // over everything the view built
                    if ($in !== null && $in->open) { UI\InspectorPanel::build($c, $this->runtime, $in, $bounds->w, $bounds->h); $in->lap(0); } // beside the content
                    $this->frameStats?->lap(UI\FrameStats::BUILD);
                    Layout::layout($c, $bounds->w, $bounds->h);
                    $this->frameStats?->lap(UI\FrameStats::LAYOUT);
                    if ($in !== null && $in->open) { $in->lap(1); $in->snapshot($this->runtime, $c->root); }
                    return $c->root;
                });
                $paint = function () use ($root, $list, $text, $bounds, $in): void {
                    $this->runtime->webPlaces = []; $this->runtime->videoPlaces = []; $this->runtime->materialPlaces = []; $this->runtime->underPaints = [];
                    $list->pushClip(0, 0, $bounds->w, $bounds->h); // what lies outside the window is not painted (mygo's visible)
                    Painter::paint($root, $list, $text, App::theme(), $this->runtime);
                    if ($in !== null && $in->open) { UI\InspectorOverlay::paint($list, $text, $this->runtime, $in, $bounds->h); }
                    Painter::dragCopy($list, $text, App::theme(), $this->runtime); // a value dragged: its source's copy under the pointer
                    Painter::tooltip($root, $list, $text, App::theme(), $this->runtime);
                };
                $paint();
                if ($text->repaint) {
                    // An atlas started over during the paint: what was painted before points at slots that are gone.
                    $text->repaint = false;
                    $list->reset();
                    $paint();
                }
                if ($this->runtime->underPaints !== [] && $this->under === null) {
                    $this->under = $this->underView();
                }
                $in?->lap(2);
                if ($this->frameStats !== null) { $this->frameStats->passes = $this->runtime->passes; $this->frameStats->lap(UI\FrameStats::PAINT); }
                if ($this->webViews !== [] || $this->videoHosts !== [] || $this->runtime->videoPlaces !== [] || $this->materials !== [] || $this->runtime->materialPlaces !== [] || $this->underNew) {
                    $this->placeWebViews($bounds->h);
                }
                $this->showCursor($this->runtime->cursor);
                $this->answer($this->runtime->windowAsks);
                $wake = $this->runtime->nextWake();
                if (($this->runtime->needsFrame || $wake !== null) && !$this->showing()) {
                    // Nothing of the window shows (covered, minimized, on another space): what moves waits until some of it
                    // does (windowDidChangeOcclusionState:) rather than draw frames nobody sees, as browsers pause the
                    // animation frames of windows out of sight (mygo's held). It goes on from where the time puts it.
                    $this->held = true;
                } elseif ($this->runtime->needsFrame) {
                    Metal::requestFrame($view);
                } elseif ($wake !== null) {
                    View::wake($view, $wake);
                }
                if (getenv('MYPHP_STATS')) {
                    $this->stat($t0, $list);
                }
                $this->readyToShow(); // it drew its first frame
            });
    }

    /**
     * The room the window controls take in a window with a hidden title
     * bar (WindowOptions::$titleBarStyle), as mygo's Context.TitleBar:
     * height, left (to the right edge of the zoom button) and right, in
     * points; all zero in other windows and in full screen, where the
     * controls hide. The view keeps clear of them and drags the window by
     * its own title bar (Node::dragWindow).
     * @return array{height: float, left: float, right: float}
     */
    public function titleBar(): array
    {
        if (!$this->hiddenTitleBar || (ObjC::msg('L:', $this->ns, 'styleMask') & self::FULL_SCREEN)) {
            return ['height' => 0.0, 'left' => 0.0, 'right' => 0.0];
        }
        $zoom = ObjC::msg('p:l', $this->ns, 'standardWindowButton:', 2);
        $z = ObjC::msg('r:', $zoom, 'frame');
        if ($this->trafficLights !== null) { // where layoutTrafficLights puts them, which may not have run yet
            [$px, $py] = $this->trafficLights;
            $c = ObjC::msg('r:', ObjC::msg('p:l', $this->ns, 'standardWindowButton:', 0), 'frame');
            return ['height' => round($c->h + 2 * $py), 'left' => ceil($px + $z->x - $c->x + $z->w), 'right' => 0.0];
        }
        $frame = ObjC::msg('r:', $this->ns, 'frame');
        $content = ObjC::msg('r:', $this->ns, 'contentLayoutRect');
        return ['height' => round($frame->h - $content->y - $content->h), 'left' => ceil($z->x + $z->w), 'right' => 0.0];
    }

    /**
     * Puts the top-left corner of the close button where
     * WindowOptions::$trafficLightPosition says, and the other buttons
     * after it, in a title bar that leaves as much room below them as
     * above, as mygo's layoutTrafficLights. AppKit lays the title bar out
     * again when the window resizes, its title changes or the appearance
     * changes, so this runs after each of them.
     */
    public function layoutTrafficLights(): void
    {
        if ($this->trafficLights === null || (ObjC::msg('L:', $this->ns, 'styleMask') & self::FULL_SCREEN)) { return; }
        [$px, $py] = $this->trafficLights;
        $container = $this->titleBarContainer();
        if ($container === null) { return; }
        $b = ObjC::msg('r:', ObjC::msg('p:l', $this->ns, 'standardWindowButton:', 0), 'frame');
        $height = $b->h + 2 * $py;
        $win = ObjC::msg('r:', $this->ns, 'frame');
        $cf = ObjC::msg('r:', $container, 'frame');
        if ($cf->y !== $win->h - $height || $cf->h !== $height) { // only when AppKit moved it: its frame change is observed, and setting it posts one
            ObjC::msg('v:r', $container, 'setFrame:', ObjC::rect($cf->x, $win->h - $height, $cf->w, $height));
        }
        $gap = ObjC::msg('r:', ObjC::msg('p:l', $this->ns, 'standardWindowButton:', 1), 'frame')->x - $b->x;
        for ($i = 0; $i < 3; $i++) {
            ObjC::msg('v:q', ObjC::msg('p:l', $this->ns, 'standardWindowButton:', $i), 'setFrameOrigin:', ObjC::point($px + $i * $gap, $py));
        }
    }

    /** The view that holds the title bar and its buttons (NSTitlebarContainerView), or null before the window has one. */
    private function titleBarContainer()
    {
        $close = ObjC::msg('p:l', $this->ns, 'standardWindowButton:', 0);
        return $close === null ? null : ObjC::msg('p:', ObjC::msg('p:', $close, 'superview'), 'superview');
    }

    /** Moves the window with the mouse from the press on a drag handle (mygo's StartDrag), or on a double click zooms or minimizes it as macOS is set to. */
    private function dragWindow(int $clicks): void
    {
        if ($clicks === 2) { // AppleActionOnDoubleClick: Maximize (the default), Minimize or None
            $defaults = ObjC::msg('p:', ObjC::cls('NSUserDefaults'), 'standardUserDefaults');
            $action = ObjC::msg('p:p', $defaults, 'stringForKey:', ObjC::str('AppleActionOnDoubleClick'));
            match ($action === null ? '' : ObjC::phpStr($action)) { 'None' => null, 'Minimize' => ObjC::msg('v:p', $this->ns, 'performMiniaturize:', null), default => ObjC::msg('v:p', $this->ns, 'performZoom:', null) };
            return;
        }
        $event = ObjC::msg('p:', $this->ns, 'currentEvent');
        if ($event !== null) { ObjC::msg('v:p', $this->ns, 'performWindowDragWithEvent:', $event); }
    }

    /** The web page the window shows, or null for a window showing native UI (mygo's Page). */
    public function page(): ?Page
    {
        return $this->page;
    }

    /** @internal the pages of the window: its own, or its web views' @return list<Page> */
    public function pages(): array
    {
        return $this->page !== null ? [$this->page] : array_values(array_map(fn(WebView $v) => $v->page(), $this->webViews));
    }

    /** @internal opens or closes the developer tools, as the menu's Toggle Developer Tools does: a page's web inspector, or the inspector of native UI */
    public function toggleDevTools(): void
    {
        if ($this->page !== null) {
            $this->page->toggleDevTools();
        } elseif ($this->runtime?->inspector !== null) {
            $this->runtime->inspector->toggle($this->runtime);
        }
    }

    /** The refresh rate of the display the window is on, for the inspector's frame budget: 60 where the screen does not say. */
    private function refreshRate(): float
    {
        $screen = $this->ns === null ? null : ObjC::msg('p:', $this->ns, 'screen');
        if ($screen === null || !ObjC::msg('b:p', $screen, 'respondsToSelector:', ObjC::sel('maximumFramesPerSecond'))) { return 60.0; } // macOS 12
        $hz = ObjC::msg('l:', $screen, 'maximumFramesPerSecond');
        return $hz > 0 ? (float)$hz : 60.0;
    }

    private static function delegate()
    {
        if (!self::$delegateDefined) {
            self::$delegateDefined = true;
            $fire = fn(string $event) => function ($self, $cmd, $note) use ($event) {
                $w = self::$open[ObjC::addr(ObjC::msg('p:', $note, 'object'))] ?? null;
                if ($w !== null) {
                    if ($event === 'resize') {
                        $w->layoutTrafficLights();
                        if (($z = $w->isMaximized()) !== $w->maximized) {
                            $w->maximized = $z;
                            $w->emit($z ? 'maximize' : 'unmaximize');
                        }
                        if ($w->page === null) { ObjC::msg('v:', $w->view, 'draw'); } // a frame at the new size now, before this step of the resize is committed, as mygo's setFrameSize: asks: asked for later, it would come a step late and the layer would stretch the last one meanwhile
                    }
                    if ($event === 'resize' || $event === 'move') {
                        $w->placeFlyouts(); // they stay next to their anchors, in the work area
                    }
                    if ($event === 'enterFullScreen' || $event === 'leaveFullScreen') {
                        $w->page?->sendTitleBar(); // the controls hide in full screen
                        $w->fullScreenChanged($event);
                    }
                    $w->emit($event);
                    foreach ($w->runtime?->windowListeners ?? [] as $listen) { $listen($event); } // the view's own (a player pausing as its window is minimized, IINA's)
                    if (in_array($event, ['resize', 'move', 'minimize', 'restore', 'enterFullScreen', 'leaveFullScreen'], true)) {
                        $w->stateChanged();
                    }
                }
            };
            ObjC::defineClass('MyPHPWindowDelegate', 'NSObject', [
                'windowShouldClose:' => ['B@:@', 'b(ppp)', function ($self, $cmd, $sender) {
                    return (self::$open[ObjC::addr($sender)] ?? null)?->shouldClose() ?? true;
                }],
                'windowWillClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                    (self::$open[ObjC::addr(ObjC::msg('p:', $note, 'object'))] ?? null)?->closed();
                }],
                // The user clicked elsewhere, in a transient popover: a press on its own tray icon leaves it to the icon's click, which toggles it, rather than closing it for the click to open it again.
                'popoverShouldClose:' => ['B@:@', 'b(ppp)', function ($self, $cmd, $popover) {
                    $w = self::$open[ObjC::addr($popover)] ?? null;
                    if ($w === null) {
                        return true;
                    }
                    if ($w->flyout['tray']?->pressed() ?? false) {
                        return false;
                    }
                    return $w->shouldClose();
                }],
                'popoverDidClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                    $w = self::$open[ObjC::addr(ObjC::msg('p:', $note, 'object'))] ?? null;
                    if ($w === null) {
                        return;
                    }
                    $w->ns = null; // the popover's window goes
                    if ($w->popoverClosing) {
                        $w->popoverClosing = false;
                        return;
                    }
                    $w->closed();
                }],
                'windowDidBecomeKey:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($fire) {
                    $fire('focus')($self, $cmd, $note);
                    (self::$open[ObjC::addr(ObjC::msg('p:', $note, 'object'))] ?? null)?->invalidate(); // a selection shows again
                }],
                'windowDidResignKey:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($fire) {
                    $fire('blur')($self, $cmd, $note);
                    $w = self::$open[ObjC::addr(ObjC::msg('p:', $note, 'object'))] ?? null;
                    $w?->invalidate(); // a selection stops showing
                    if ($w?->flyout !== null && !($w->flyout['tray']?->pressed() ?? false)) {
                        App::later($w->dismissFlyout(...)); // once AppKit made another window key, or none; a press on its tray icon toggles it instead
                    }
                }],
                'windowDidResize:' => ['v@:@', 'v(ppp)', $fire('resize')],
                'windowDidMove:' => ['v@:@', 'v(ppp)', $fire('move')],
                'windowDidMiniaturize:' => ['v@:@', 'v(ppp)', $fire('minimize')],
                'windowDidDeminiaturize:' => ['v@:@', 'v(ppp)', $fire('restore')],
                'windowDidEnterFullScreen:' => ['v@:@', 'v(ppp)', $fire('enterFullScreen')],
                'windowDidExitFullScreen:' => ['v@:@', 'v(ppp)', $fire('leaveFullScreen')],
                // A window showing a video goes in and out of full screen by its own animation, as video players do (IINA): AppKit's
                // crossfades a picture of the window from before with one from after, and a video's picture then showed its old size
                // in the grown window; animated by its frame instead, each step resizes the window (windowDidResize: draws it at once)
                // and the video's view, which stretches with it (placeVideos), so the playing picture grows with the window.
                'customWindowsToEnterFullScreenForWindow:' => ['@@:@', 'p(ppp)', fn($self, $cmd, $ns) => self::ownAnimation($ns)],
                'customWindowsToExitFullScreenForWindow:' => ['@@:@', 'p(ppp)', fn($self, $cmd, $ns) => self::ownAnimation($ns)],
                'window:startCustomAnimationToEnterFullScreenWithDuration:' => ['v@:@d', 'v(pppd)', function ($self, $cmd, $ns, $duration) {
                    $w = self::$open[ObjC::addr($ns)] ?? null;
                    if ($w === null) { return; }
                    $w->windowedFrame = ObjC::msg('r:', $ns, 'frame'); // where it goes back to
                    $w->animateFrame(ObjC::msg('r:', ObjC::msg('p:', $ns, 'screen'), 'frame'), $duration);
                }],
                'window:startCustomAnimationToExitFullScreenWithDuration:' => ['v@:@d', 'v(pppd)', function ($self, $cmd, $ns, $duration) {
                    $w = self::$open[ObjC::addr($ns)] ?? null;
                    if ($w?->windowedFrame !== null) { $w->animateFrame($w->windowedFrame, $duration); }
                }],
                'windowDidChangeOcclusionState:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                    $w = self::$open[ObjC::addr(ObjC::msg('p:', $note, 'object'))] ?? null;
                    if ($w !== null && $w->held && $w->showing()) {
                        $w->held = false; // some of it shows again: what moved goes on (mygo's SurfaceShown)
                        $w->invalidate();
                    }
                }],
                'windowDidChangeBackingProperties:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) { App::rescale(); }],
                'titleBarLaidOut:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) { // the title bar container's frame changed: AppKit laid it out
                    $w = self::$open[ObjC::addr(ObjC::msg('p:', ObjC::msg('p:', $note, 'object'), 'window'))] ?? null;
                    $w?->layoutTrafficLights();
                }],
            ], ['NSWindowDelegate', 'NSPopoverDelegate']);
        }
        return ObjC::msg('p:', ObjC::alloc('MyPHPWindowDelegate'), 'init');
    }

    /** Asks the 'close' listeners whether the window closes, as the user closing it. */
    private function shouldClose(): bool
    {
        $e = new CloseEvent($this);
        foreach ($this->listeners['close'] ?? [] as $fn) {
            $fn($e);
        }
        return !$e->canceled;
    }

    /** The window closed (windowWillClose:, or a popover's close): its children close with it, and the app quits after the last window when it should. */
    private function closed(): void
    {
        unset(self::$open[$this->key]);
        $this->destroyed = true;
        $this->blackOut(false); // its black windows go with it, the menu bar and the Dock back
        if ($this->legacy !== null) { ObjC::msg('v:L', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'setPresentationOptions:', $this->legacy[2]); }
        if ($this->frameStats !== null && $this->view !== null) { unset(Metal::$afterPresent[ObjC::addr($this->view)]); }
        self::$closingParents++; // its children close with it; the last window is reported once they all are
        foreach (self::all() as $child) {
            if ($child->parent === $this) { $child->destroy(); }
        }
        self::$closingParents--;
        if ($this->popover !== null) {
            $this->cleanupPopover();
        }
        if ($this->attention !== 0) { $this->flashFrame(false); }
        unset(self::$byView[ObjC::addr($this->view)]);
        if ($this->runtime !== null) { $this->cancelDataDrag(); Access::detach($this->view); View::forget($this->view); Metal::forget($this->view); }
        if ($this->under !== null) {
            Metal::forget($this->under);
            ObjC::msg('v:', $this->under, 'removeFromSuperview');
            ObjC::msg('v:', $this->under, 'release');
            $this->under = null;
        }
        $this->page?->destroy(); // the page goes with the window, its web views' too
        foreach ($this->webViews as $v) { $v->destroy(); }
        foreach ($this->videoHosts as ['player' => $ref, 'clip' => $clip, 'video' => $video]) {
            ObjC::msg('v:p', $video, 'setLayer:', null); // the player's layer, free for another window
            $player = $ref->get();
            if ($player !== null && $player->out->view === $video) { $player->out->view = null; }
            ObjC::msg('v:', $clip, 'removeFromSuperview');
            ObjC::msg('v:', $clip, 'release');
        }
        $this->videoHosts = [];
        foreach ($this->materials as $m) {
            ObjC::msg('v:', $m['view'], 'removeFromSuperview');
            ObjC::msg('v:', $m['view'], 'release');
        }
        $this->materials = [];
        $this->captureState();
        WindowStates::save();
        $this->emit('closed');
        if (self::$closingParents === 0 && self::$open === []) {
            App::lastWindowClosed();
        }
    }

    /**
     * Calls $fn on $event, one of EVENTS: close (with a CloseEvent to
     * cancel), closed, focus, blur, show, hide, readyToShow, resize, move,
     * maximize, unmaximize, minimize, restore, enterFullScreen,
     * leaveFullScreen, fileDrop (with a FileDropEvent: files dropped from
     * other apps that no node took, Context::dropData or droppedFiles; a
     * trusted page hears of them as myphp:file-drop too). The page's events
     * are its own (Page::on). Returns a function that removes it.
     */
    public function on(string $event, \Closure $fn): \Closure
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new RuntimeException("no window event \"$event\"");
        }
        $this->listeners[$event][] = $fn;
        return function () use ($event, $fn) {
            $this->listeners[$event] = array_values(array_filter($this->listeners[$event] ?? [], fn($f) => $f !== $fn));
        };
    }

    private function emit(string $event): void
    {
        foreach ($this->listeners[$event] ?? [] as $fn) {
            $fn($this);
        }
    }

    /** The last change of the window's state captured, which stateChanged() waits to settle for. */
    private int $stateChange = 0;

    /** Captures the window's state once it stopped changing for 0.3 s (mygo's stateChanged): zooming and full screen transitions resize it on the way. */
    private function stateChanged(): void
    {
        if ($this->stateKey === '') {
            return;
        }
        $n = ++$this->stateChange;
        App::after(0.3, function () use ($n): void {
            if ($n === $this->stateChange && !$this->destroyed) { $this->captureState(); }
        });
    }

    /** Records the window's state under its key (mygo's captureState): whether it is maximized or full screen, and its bounds while it is neither, nor minimized. */
    private function captureState(): void
    {
        if ($this->stateKey === '') {
            return;
        }
        $prev = WindowStates::get($this->stateKey);
        [$max, $full] = [$this->isMaximized(), $this->isFullScreen()];
        $b = $prev === null ? null : new Rectangle($prev->x, $prev->y, $prev->width, $prev->height);
        if (!$max && !$full && !$this->isMinimized()) {
            $b = $this->bounds();
        }
        if ($b === null || $b->width <= 0 || $b->height <= 0) {
            return;
        }
        WindowStates::set($this->stateKey, new WindowState($b->x, $b->y, $b->width, $b->height, $max, $full));
    }

    private \MyPHP\UI\Cursor $cursorShown = \MyPHP\UI\Cursor::Default;
    private static mixed $noCursor = null;

    /** The pointer over the content (mygo's nsCursor): AppKit's cursor of each shape; the diagonal resize cursors macOS 15's frame resize cursors, else AppKit's older ones; the columns' and rows' macOS 15's, else the two-way arrows; none a cursor of a transparent image, which hides the pointer over the view alone. */
    private function showCursor(\MyPHP\UI\Cursor $cursor): void
    {
        if ($cursor === \MyPHP\UI\Cursor::None) {
            // Hidden as IINA hides it, until the mouse moves, again each frame: AppKit sets its own cursor back (a full screen
            // window's), and from macOS 11 a click shows it again without a move (IINA's workaround of FB11963121).
            ObjC::msg('v:b', ObjC::cls('NSCursor'), 'setHiddenUntilMouseMoves:', true);
        }
        if ($cursor === $this->cursorShown) {
            return;
        }
        $this->cursorShown = $cursor;
        ObjC::msg('v:', self::nsCursor($cursor), 'set');
    }

    private static function nsCursor(\MyPHP\UI\Cursor $c): mixed
    {
        $cls = ObjC::cls('NSCursor');
        $responds = static fn(string $sel): bool => ObjC::msg('b:p', $cls, 'respondsToSelector:', ObjC::sel($sel));
        if ($c === \MyPHP\UI\Cursor::ResizeNWSE || $c === \MyPHP\UI\Cursor::ResizeNESW) {
            [$corner, $older] = $c === \MyPHP\UI\Cursor::ResizeNWSE ? [1 | 2, '_windowResizeNorthWestSouthEastCursor'] : [1 | 8, '_windowResizeNorthEastSouthWestCursor']; // the top left corner, or the top right
            if ($responds('frameResizeCursorFromPosition:inDirections:') && ($cur = ObjC::msg('p:LL', $cls, 'frameResizeCursorFromPosition:inDirections:', $corner, 1 | 2)) !== null) { // inward and outward
                return $cur;
            }
            if ($responds($older) && ($cur = ObjC::msg('p:', $cls, $older)) !== null) {
                return $cur;
            }
        }
        if ($c === \MyPHP\UI\Cursor::None) {
            if (self::$noCursor === null) { // a 1×1 transparent image
                $rep = ObjC::msg('p:pllllbbpLll', ObjC::alloc('NSBitmapImageRep'), 'initWithBitmapDataPlanes:pixelsWide:pixelsHigh:bitsPerSample:samplesPerPixel:hasAlpha:isPlanar:colorSpaceName:bitmapFormat:bytesPerRow:bitsPerPixel:',
                    null, 1, 1, 8, 4, true, false, ObjC::str('NSDeviceRGBColorSpace'), 0, 4, 32) ?? throw new \RuntimeException('the hidden cursor\'s image');
                \FFI::memset(ObjC::$c->cast('char*', ObjC::msg('p:', $rep, 'bitmapData')), 0, 4);
                $size = ObjC::$c->new('CGSize');
                $size->w = 1; $size->h = 1;
                $img = ObjC::msg('p:z', ObjC::alloc('NSImage'), 'initWithSize:', $size);
                ObjC::msg('v:p', $img, 'addRepresentation:', $rep);
                ObjC::msg('v:', $rep, 'release');
                self::$noCursor = ObjC::msg('p:pq', ObjC::alloc('NSCursor'), 'initWithImage:hotSpot:', $img, ObjC::point(0, 0));
                ObjC::msg('v:', $img, 'release');
            }
            return self::$noCursor;
        }
        $name = match ($c) {
            \MyPHP\UI\Cursor::Default => 'arrowCursor', \MyPHP\UI\Cursor::Pointer => 'pointingHandCursor', \MyPHP\UI\Cursor::Text => 'IBeamCursor',
            \MyPHP\UI\Cursor::Move, \MyPHP\UI\Cursor::Grab => 'openHandCursor', \MyPHP\UI\Cursor::Grabbing => 'closedHandCursor',
            \MyPHP\UI\Cursor::ResizeEW => 'resizeLeftRightCursor', \MyPHP\UI\Cursor::ResizeNS => 'resizeUpDownCursor',
            \MyPHP\UI\Cursor::NotAllowed => 'operationNotAllowedCursor', \MyPHP\UI\Cursor::Crosshair => 'crosshairCursor',
            \MyPHP\UI\Cursor::ResizeN => 'resizeUpCursor', \MyPHP\UI\Cursor::ResizeE => 'resizeRightCursor', \MyPHP\UI\Cursor::ResizeS => 'resizeDownCursor', \MyPHP\UI\Cursor::ResizeW => 'resizeLeftCursor',
            \MyPHP\UI\Cursor::ResizeColumn => $responds('columnResizeCursor') ? 'columnResizeCursor' : 'resizeLeftRightCursor', // macOS 15
            \MyPHP\UI\Cursor::ResizeRow => $responds('rowResizeCursor') ? 'rowResizeCursor' : 'resizeUpDownCursor',
            \MyPHP\UI\Cursor::VerticalText => 'IBeamCursorForVerticalLayout', \MyPHP\UI\Cursor::Copy => 'dragCopyCursor', \MyPHP\UI\Cursor::Alias => 'dragLinkCursor', \MyPHP\UI\Cursor::ContextMenu => 'contextualMenuCursor',
            \MyPHP\UI\Cursor::ResizeNWSE, \MyPHP\UI\Cursor::ResizeNESW => 'arrowCursor', // neither cursor AppKit has (before macOS 15, without its private ones): as mygo's
        };
        return ObjC::msg('p:', $cls, $name);
    }

    /** The theme changed: native UI clears to its background and redraws; a background of a light and a dark color shows the appearance's. */
    public function themeChanged(): void
    {
        $this->layoutTrafficLights(); // AppKit lays the title bar out again with the appearance
        if ($this->background !== null) {
            $this->applyBackground();
        }
        foreach ($this->webViews as $v) {
            $v->applyBackground();
        }
        if ($this->page === null) {
            if (!$this->transparent && $this->effect === null) {
                Metal::setClearColor($this->view, App::theme()->background);
            }
            $this->invalidate();
        }
    }

    /**
     * Sets the color shown behind the page, or around native UI where it
     * draws nothing, as WindowOptions::$backgroundColor says it: a CSS color
     * or light-dark(<light>, <dark>), which follows the appearance.
     */
    public function setBackgroundColor(string $color): void
    {
        $this->background = Background::parse($color);
        $this->applyBackground();
    }

    private function applyBackground(): void
    {
        [$r, $g, $b, $a] = $this->background->for(App::theme()->dark);
        $c = ObjC::msg('p:dddd', ObjC::cls('NSColor'), 'colorWithSRGBRed:green:blue:alpha:', $r / 255, $g / 255, $b / 255, $a / 255);
        ObjC::msg('v:p', $this->ns, 'setBackgroundColor:', $c);
        if ($this->page !== null) {
            ObjC::msg('v:pp', $this->view, 'setValue:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', false), ObjC::str('drawsBackground'));
            if (ObjC::msg('b:p', $this->view, 'respondsToSelector:', ObjC::sel('setUnderPageBackgroundColor:'))) {
                ObjC::msg('v:p', $this->view, 'setUnderPageBackgroundColor:', $c);
            }
        }
    }

    /** Lets what is behind the content show where it draws nothing: a page draws no background, native UI clears to transparent where its root's background is taken away. */
    private function clearContent(): void
    {
        if ($this->page !== null) {
            ObjC::msg('v:pp', $this->view, 'setValue:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', false), ObjC::str('drawsBackground'));
            return;
        }
        ObjC::msg('v:b', ObjC::msg('p:', $this->view, 'layer'), 'setOpaque:', false);
        Metal::setClearColor($this->view, 0, 0.0);
    }

    /** The NSVisualEffectView materials of WindowOptions::$vibrancy, as mygo's (Windows 11's backdrops as their nearest), and Node::systemMaterial's. */
    public const MATERIALS = [
        'titlebar' => 3, 'selection' => 4, 'menu' => 5, 'popover' => 6, 'sidebar' => 7, 'header' => 10,
        'sheet' => 11, 'window' => 12, 'hud' => 13, 'fullscreen-ui' => 15, 'tooltip' => 17, 'content' => 18,
        'under-window' => 21, 'under-page' => 22, 'mica' => 21, 'tabbed' => 21, 'acrylic' => 13,
    ];

    /**
     * Puts a translucent, blurred material behind the content (mygo's
     * SetVibrancy), '' for none: it shows behind a page that draws no
     * background, and behind native UI where it draws none, as the root
     * does once its background is taken away (Context::vibrancy says it
     * shows).
     */
    public function setVibrancy(string $material): void
    {
        if ($material !== '' && !isset(self::MATERIALS[$material])) {
            throw new \InvalidArgumentException("no vibrancy \"$material\"");
        }
        if ($material === '') {
            if ($this->effect !== null) {
                ObjC::msg('v:', $this->effect, 'removeFromSuperview');
                ObjC::msg('v:', $this->effect, 'release');
                $this->effect = null;
                if ($this->page === null && !$this->transparent) {
                    ObjC::msg('v:b', ObjC::msg('p:', $this->view, 'layer'), 'setOpaque:', true);
                    Metal::setClearColor($this->view, App::theme()->background);
                }
            }
            $this->invalidate();
            return;
        }
        if ($this->effect === null) {
            $this->effect = ObjC::msg('p:r', ObjC::alloc('NSVisualEffectView'), 'initWithFrame:', ObjC::msg('r:', $this->container, 'bounds'));
            ObjC::msg('v:l', $this->effect, 'setBlendingMode:', 0); // NSVisualEffectBlendingModeBehindWindow
            ObjC::msg('v:l', $this->effect, 'setState:', 1); // NSVisualEffectStateActive
            ObjC::msg('v:L', $this->effect, 'setAutoresizingMask:', 18);
            ObjC::msg('v:plp', $this->container, 'addSubview:positioned:relativeTo:', $this->effect, -1, null); // NSWindowBelow: behind the content
            $this->clearContent();
        }
        ObjC::msg('v:l', $this->effect, 'setMaterial:', self::MATERIALS[$material]);
        $this->invalidate(); // native UI looks again whether the material shows
    }

    /** Asks for a frame after state changed outside the view function (native UI, mygo's Invalidate). */
    /** Whether some of the window shows on screen: not hidden, minimized, on another space or covered by other windows, which still get frames (mygo's surface visible). */
    /**
     * Does what the view asked of the window this frame (Runtime::windowAsks),
     * once for each change: closed; its title; the traffic lights shown or hidden; the aspect
     * kept as the user resizes it, the window first made of that shape, its
     * width kept; full screen or not. Resizing redraws the window at once
     * (windowDidResize:): it waits for the frame to end.
     * @param array<string, mixed> $asks
     */
    private function answer(array $asks): void
    {
        if (($asks['close'] ?? false) === true) { // the view's own window closing (a player at its film's end, IINA's)
            App::later(function (): void { if (!$this->destroyed) { $this->close(); } });
        }
        if (array_key_exists('title', $asks) && $asks['title'] !== $this->title) {
            $this->setTitle($asks['title']);
        }
        if (array_key_exists('buttons', $asks) && $asks['buttons'] !== $this->buttonsAsked) {
            $this->buttonsAsked = $asks['buttons'];
            $this->setWindowButtonVisibility($asks['buttons']);
        }
        if (array_key_exists('aspect', $asks) && $asks['aspect'] !== $this->aspectAsked) {
            $a = $this->aspectAsked = $asks['aspect'];
            App::later(function () use ($a): void {
                if ($this->destroyed) { return; }
                $this->setAspectRatio($a ?? 0.0);
                if ($a !== null && !$this->isFullScreen()) {
                    $w = $this->contentSize()[0];
                    $this->setContentSize($w, (int)round($w / $a));
                }
            });
        }
        $this->legacyAsked = (bool)($asks['legacyFullScreen'] ?? $this->legacyAsked);
        $this->blackOutAsked = (bool)($asks['blackOut'] ?? $this->blackOutAsked);
        if (array_key_exists('fullScreen', $asks) && $asks['fullScreen'] !== $this->isFullScreen()) {
            $on = $asks['fullScreen'];
            $legacy = $this->legacyAsked || $this->legacy !== null; // the way it went in, it comes out
            App::later(function () use ($on, $legacy): void {
                if ($this->destroyed) { return; }
                $legacy ? $this->setLegacyFullScreen($on) : $this->setFullScreen($on);
            });
        }
    }

    /** The windows to go full screen by an animation of their own for $ns, a window of a video: itself; else nil, AppKit's animation. */
    private static function ownAnimation($ns)
    {
        $w = self::$open[ObjC::addr($ns)] ?? null;
        return $w === null || $w->videoHosts === [] ? null : ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', $ns);
    }

    /** Moves the window to $frame over $seconds, its frame animated (NSAnimationContext): each step a resize, drawn at once. */
    private function animateFrame($frame, float $seconds): void
    {
        ObjC::msg('v:', ObjC::cls('NSAnimationContext'), 'beginGrouping');
        ObjC::msg('v:d', ObjC::msg('p:', ObjC::cls('NSAnimationContext'), 'currentContext'), 'setDuration:', $seconds);
        ObjC::msg('v:rb', ObjC::msg('p:', $this->ns, 'animator'), 'setFrame:display:', $frame, true);
        ObjC::msg('v:', ObjC::cls('NSAnimationContext'), 'endGrouping');
    }

    private function showing(): bool
    {
        if ($this->popover !== null || $this->ns === null) {
            return true; // a popover's window is AppKit's, whose occlusion its delegate does not hear of: it draws while it shows, and it shows while it draws
        }
        return (ObjC::msg('L:', $this->ns, 'occlusionState') & self::OCCLUSION_VISIBLE) !== 0;
    }

    public function invalidate(): void
    {
        if ($this->page === null && !$this->destroyed) {
            Metal::requestFrame($this->view);
        }
    }

    /** Runs $fn, which changes the state the view shows, and asks for a frame that shows it (mygo's Update). */
    public function update(\Closure $fn): void
    {
        $fn();
        $this->invalidate();
    }

    /** The window's id, unique in the app; the page sees it as window.myphp.windowId (mygo's ID). */
    public function id(): int
    {
        return $this->id;
    }

    /** Whether the window was closed. */
    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    /** The parent window (WindowOptions::$parent), or null. */
    public function parent(): ?Window
    {
        return $this->parent;
    }

    /** Closes the window as a click on its close button would: 'close' listeners can keep it open (CloseEvent::cancel). */
    public function close(): void
    {
        if ($this->destroyed) {
            return;
        }
        if ($this->shouldClose()) {
            $this->destroy();
        }
    }

    /** Closes the window without asking its 'close' listeners. */
    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        if ($this->popover !== null) { // at once, without the popover's animation
            ObjC::msg('v:b', $this->popover, 'setAnimates:', false);
            $this->hidePopover();
            $this->closed();
            return;
        }
        if ($this->sheet) {
            $this->sheet = false;
            ObjC::msg('v:p', $this->parent->ns, 'endSheet:', $this->ns);
        }
        if ($this->flyout !== null) {
            $this->hideFlyout();
        }
        ObjC::msg('v:', $this->ns, 'close');
    }

    /** Shows the window and gives it the keyboard; a modal child shows as a sheet of its parent, another stays above it; a flyout takes the keyboard only when focusable, and a panel or a flyout never activates the app. */
    public function show(): void
    {
        $visible = $this->isVisible();
        if ($this->popover !== null) {
            $this->showPopover();
        } elseif ($this->flyout !== null) {
            $this->showFlyout($this->flyout['focusable']);
        } elseif ($this->parent !== null && $this->modal && !$this->sheet) {
            $this->sheet = true;
            ObjC::msg('v:pp', $this->parent->ns, 'beginSheet:completionHandler:', $this->ns, null);
        } elseif (!$this->sheet) {
            ObjC::msg('v:p', $this->ns, 'makeKeyAndOrderFront:', null);
            if ($this->parent !== null && !$this->parent->destroyed) {
                ObjC::msg('v:pl', $this->parent->ns, 'addChildWindow:ordered:', $this->ns, 1); // NSWindowAbove
            }
            if (!$this->panel) {
                ObjC::msg('v:b', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'activateIgnoringOtherApps:', true);
            }
        }
        $this->invalidate();
        if (!$visible) {
            $this->emit('show');
        }
    }

    /** Shows the window without giving it the keyboard. */
    public function showInactive(): void
    {
        $visible = $this->isVisible();
        if ($this->popover !== null) {
            $this->showPopover();
        } elseif ($this->flyout !== null) {
            $this->showFlyout(false);
        } else {
            ObjC::msg('v:', $this->ns, 'orderFrontRegardless');
            if ($this->parent !== null && !$this->parent->destroyed) {
                ObjC::msg('v:pl', $this->parent->ns, 'addChildWindow:ordered:', $this->ns, 1);
            }
        }
        $this->invalidate();
        if (!$visible) {
            $this->emit('show');
        }
    }

    public function hide(): void
    {
        if (!$this->isVisible()) {
            return;
        }
        if ($this->popover !== null) {
            $this->hidePopover();
        } elseif ($this->flyout !== null) {
            $this->hideFlyout();
        } elseif ($this->sheet) {
            $this->sheet = false;
            ObjC::msg('v:p', $this->parent->ns, 'endSheet:', $this->ns);
        } else {
            ObjC::msg('v:p', $this->ns, 'orderOut:', null);
        }
        $this->emit('hide');
    }

    public function isVisible(): bool
    {
        if ($this->popover !== null) {
            return ObjC::msg('b:', $this->popover, 'isShown');
        }
        return ObjC::msg('b:', $this->ns, 'isVisible');
    }

    /** Gives the window the keyboard, and the app with it; a panel takes it without activating the app, a flyout shows (the keyboard only when focusable). */
    public function focus(): void
    {
        if ($this->flyout !== null) {
            $this->show();
            return;
        }
        ObjC::msg('v:p', $this->ns, 'makeKeyAndOrderFront:', null);
        if (!$this->panel) {
            ObjC::msg('v:b', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'activateIgnoringOtherApps:', true);
        }
    }

    /** Sends the window behind the others. */
    public function blur(): void
    {
        ObjC::msg('v:p', $this->ns, 'orderBack:', null);
    }

    /** Whether the window has the keyboard. */
    public function isFocused(): bool
    {
        return ObjC::msg('b:', $this->ns, 'isKeyWindow');
    }

    public function minimize(): void
    {
        ObjC::msg('v:p', $this->ns, 'miniaturize:', null);
    }

    public function isMinimized(): bool
    {
        return ObjC::msg('b:', $this->ns, 'isMiniaturized');
    }

    /** Zooms the window to fill the screen (macOS's maximize). */
    public function maximize(): void
    {
        if (!$this->isMaximized()) { ObjC::msg('v:p', $this->ns, 'zoom:', null); }
    }

    public function unmaximize(): void
    {
        if ($this->isMaximized()) { ObjC::msg('v:p', $this->ns, 'zoom:', null); }
    }

    public function toggleMaximize(): void
    {
        $this->isMaximized() ? $this->unmaximize() : $this->maximize();
    }

    /** Whether the window is zoomed to fill the screen, not in full screen. */
    public function isMaximized(): bool
    {
        return ObjC::msg('b:', $this->ns, 'isZoomed') && !$this->isFullScreen();
    }

    /** Brings a minimized window back. */
    public function restore(): void
    {
        if ($this->isMinimized()) { ObjC::msg('v:p', $this->ns, 'deminiaturize:', null); }
    }

    public function setFullScreen(bool $on): void
    {
        if ($on !== $this->isFullScreen()) { ObjC::msg('v:p', $this->ns, 'toggleFullScreen:', null); }
    }

    public function toggleFullScreen(): void
    {
        $this->setFullScreen(!$this->isFullScreen());
    }

    public function isFullScreen(): bool
    {
        return $this->legacy !== null || (ObjC::msg('L:', $this->ns, 'styleMask') & self::FULL_SCREEN) !== 0;
    }

    /**
     * The legacy full screen, as IINA's (before OS X 10.7's spaces): the
     * window over its whole screen where it is, borderless, the menu bar
     * and the Dock hidden until the pointer goes to them; off, as it was.
     */
    private function setLegacyFullScreen(bool $on): void
    {
        if ($on === ($this->legacy !== null)) {
            return;
        }
        $app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
        if ($on) {
            $this->legacy = [ObjC::msg('r:', $this->ns, 'frame'), ObjC::msg('L:', $this->ns, 'styleMask'), ObjC::msg('L:', $app, 'presentationOptions')];
            ObjC::msg('v:L', $app, 'setPresentationOptions:', 1 | 4); // NSApplicationPresentationAutoHideDock, AutoHideMenuBar
            ObjC::msg('v:L', $this->ns, 'setStyleMask:', ($this->legacy[1] & ~(self::TITLED | self::RESIZABLE)) | self::FULL_SIZE_CONTENT);
            ObjC::msg('v:rb', $this->ns, 'setFrame:display:', ObjC::msg('r:', ObjC::msg('p:', $this->ns, 'screen'), 'frame'), true);
        } else {
            [$frame, $mask, $options] = $this->legacy;
            $this->legacy = null;
            ObjC::msg('v:L', $this->ns, 'setStyleMask:', $mask);
            ObjC::msg('v:rb', $this->ns, 'setFrame:display:', $frame, true);
            ObjC::msg('v:L', $app, 'setPresentationOptions:', $options);
        }
        $this->fullScreenChanged($on ? 'enterFullScreen' : 'leaveFullScreen');
    }

    /** Into or out of full screen ($event): told as AppKit's own is, the other screens blacked out in it as the view asked. */
    private function fullScreenChanged(string $event): void
    {
        $this->blackOut($event === 'enterFullScreen' && $this->blackOutAsked);
        if ($this->legacy !== null || $event === 'leaveFullScreen') {
            $this->emit($event);
            foreach ($this->runtime?->windowListeners ?? [] as $listen) { $listen($event); }
            $this->stateChanged();
            $this->invalidate();
        }
    }

    /** The other screens black while it is full screen (IINA's "Black out other monitors"): a borderless black window over each, gone with $on false. */
    private function blackOut(bool $on): void
    {
        foreach ($this->blackOuts as $b) { ObjC::msg('v:p', $b, 'orderOut:', null); ObjC::msg('v:', $b, 'release'); }
        $this->blackOuts = [];
        if (!$on) {
            return;
        }
        $mine = ObjC::msg('p:', $this->ns, 'screen');
        $screens = ObjC::msg('p:', ObjC::cls('NSScreen'), 'screens');
        for ($i = 0, $n = ObjC::msg('L:', $screens, 'count'); $i < $n; $i++) {
            $screen = ObjC::msg('p:L', $screens, 'objectAtIndex:', $i);
            if (ObjC::addr($screen) === ObjC::addr($mine)) { continue; }
            $b = ObjC::msg('p:rLlb', ObjC::alloc('NSWindow'), 'initWithContentRect:styleMask:backing:defer:', ObjC::msg('r:', $screen, 'frame'), 0, 2, false); // borderless, buffered
            ObjC::msg('v:b', $b, 'setReleasedWhenClosed:', false);
            ObjC::msg('v:p', $b, 'setBackgroundColor:', ObjC::msg('p:', ObjC::cls('NSColor'), 'blackColor'));
            ObjC::msg('v:l', $b, 'setLevel:', 3); // NSFloatingWindowLevel, as IINA's
            ObjC::msg('v:L', $b, 'setCollectionBehavior:', 1 | 256); // NSWindowCollectionBehaviorCanJoinAllSpaces, FullScreenAuxiliary
            ObjC::msg('v:p', $b, 'orderFront:', null);
            $this->blackOuts[] = $b;
        }
    }

    /** Moves the window to the center of its screen. */
    public function center(): void
    {
        ObjC::msg('v:', $this->ns, 'center');
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        ObjC::msg('v:p', $this->ns, 'setTitle:', ObjC::str($title));
        $this->layoutTrafficLights();
    }

    /** The title setTitle set. */
    public function title(): string
    {
        return $this->title;
    }

    /** Tells which file the window shows, '' for none: the title bar shows its icon, which the user can drag, and a Command-click on the title lists the folders it is in. */
    public function setRepresentedFile(string $path): void
    {
        if ($path !== '' && ($real = realpath($path)) !== false) {
            $path = $real;
        } elseif ($path !== '' && $path[0] !== '/') {
            $path = getcwd() . '/' . $path;
        }
        $this->representedFile = $path;
        ObjC::msg('v:p', $this->ns, 'setRepresentedURL:', $path === '' ? null : ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($path)));
        $this->layoutTrafficLights(); // AppKit lays the title bar's buttons out again
    }

    public function representedFile(): string
    {
        return $this->representedFile;
    }

    /** Tells whether the window's document has unsaved changes: a dot in the close button, and the file's icon dimmed. */
    public function setDocumentEdited(bool $edited): void
    {
        if ($this->documentEdited === $edited) {
            return;
        }
        $this->documentEdited = $edited;
        ObjC::msg('v:b', $this->ns, 'setDocumentEdited:', $edited);
        $this->layoutTrafficLights();
    }

    public function isDocumentEdited(): bool
    {
        return $this->documentEdited;
    }

    /** Moves and resizes the window. */
    public function setBounds(Rectangle $r): void
    {
        ObjC::msg('v:rb', $this->ns, 'setFrame:display:', Screens::toMac($r), true);
    }

    /** Where the window is, and its size. */
    public function bounds(): Rectangle
    {
        return Screens::fromMac(ObjC::msg('r:', $this->ns, 'frame'));
    }

    /** Moves and resizes the window so that its content has bounds $r. */
    public function setContentBounds(Rectangle $r): void
    {
        ObjC::msg('v:rb', $this->ns, 'setFrame:display:', ObjC::msg('r:r', $this->ns, 'frameRectForContentRect:', Screens::toMac($r)), true);
    }

    /** Where the window's content is, and its size. */
    public function contentBounds(): Rectangle
    {
        return Screens::fromMac(ObjC::msg('r:r', $this->ns, 'contentRectForFrameRect:', ObjC::msg('r:', $this->ns, 'frame')));
    }

    /** Resizes the window; a flyout is placed again at that size. */
    public function setSize(int $width, int $height): void
    {
        if ($this->setFlyoutSize($width, $height)) {
            return;
        }
        $b = $this->bounds();
        $this->setBounds(new Rectangle($b->x, $b->y, $width, $height));
    }

    /** The window's size. @return array{0: int, 1: int} */
    public function size(): array
    {
        $b = $this->bounds();
        return [$b->width, $b->height];
    }

    /** Resizes the window so that its content has this size; a flyout is placed again at that size. */
    public function setContentSize(int $width, int $height): void
    {
        if ($this->setFlyoutSize($width, $height)) {
            return;
        }
        $b = $this->contentBounds();
        $this->setContentBounds(new Rectangle($b->x, $b->y, $width, $height));
    }

    /** The size of the window's content. @return array{0: int, 1: int} */
    public function contentSize(): array
    {
        $b = $this->contentBounds();
        return [$b->width, $b->height];
    }

    /** Moves the window's top-left corner. */
    public function setPosition(int $x, int $y): void
    {
        $b = $this->bounds();
        $this->setBounds(new Rectangle($x, $y, $b->width, $b->height));
    }

    /** The window's top-left corner. @return array{0: int, 1: int} */
    public function position(): array
    {
        $b = $this->bounds();
        return [$b->x, $b->y];
    }

    /** How small the user can make the window; 0 for no limit. */
    public function setMinimumSize(int $width, int $height): void
    {
        $s = ObjC::$c->new('CGSize');
        $s->w = $width; $s->h = $height;
        ObjC::msg('v:z', $this->ns, 'setMinSize:', $s);
    }

    /** How large the user can make the window; 0 for no limit. */
    public function setMaximumSize(int $width, int $height): void
    {
        $s = ObjC::$c->new('CGSize');
        $s->w = $width > 0 ? $width : 3.4028234663852886e38; $s->h = $height > 0 ? $height : 3.4028234663852886e38;
        ObjC::msg('v:z', $this->ns, 'setMaxSize:', $s);
    }

    /** Keeps the content's width over its height at $ratio as the user resizes the window (Electron's setAspectRatio); 0 lets it go. */
    public function setAspectRatio(float $ratio): void
    {
        $s = ObjC::$c->new('CGSize');
        if ($ratio > 0) {
            $s->w = $ratio; $s->h = 1.0;
            ObjC::msg('v:z', $this->ns, 'setContentAspectRatio:', $s);
            return;
        }
        $s->w = 1.0; $s->h = 1.0;
        ObjC::msg('v:z', $this->ns, 'setContentResizeIncrements:', $s); // what AppKit's docs give to undo an aspect ratio
    }

    private function setStyle(int $bit, bool $on): void
    {
        $mask = ObjC::msg('L:', $this->ns, 'styleMask');
        ObjC::msg('v:L', $this->ns, 'setStyleMask:', $on ? $mask | $bit : $mask & ~$bit);
    }

    private function hasStyle(int $bit): bool
    {
        return (ObjC::msg('L:', $this->ns, 'styleMask') & $bit) !== 0;
    }

    public function setResizable(bool $on): void { $this->setStyle(self::RESIZABLE, $on); }
    public function isResizable(): bool { return $this->hasStyle(self::RESIZABLE); }
    public function setMovable(bool $on): void { ObjC::msg('v:b', $this->ns, 'setMovable:', $on); }
    public function isMovable(): bool { return ObjC::msg('b:', $this->ns, 'isMovable'); }
    public function setMinimizable(bool $on): void { $this->setStyle(self::MINIATURIZABLE, $on); }
    public function isMinimizable(): bool { return $this->hasStyle(self::MINIATURIZABLE); }
    /** Whether the zoom button can maximize the window. */
    public function setMaximizable(bool $on): void { ObjC::msg('v:b', ObjC::msg('p:l', $this->ns, 'standardWindowButton:', 2), 'setEnabled:', $on); }
    public function isMaximizable(): bool { return ObjC::msg('b:', ObjC::msg('p:l', $this->ns, 'standardWindowButton:', 2), 'isEnabled'); }
    public function setClosable(bool $on): void { $this->setStyle(self::CLOSABLE, $on); }
    public function isClosable(): bool { return $this->hasStyle(self::CLOSABLE); }

    /** Keeps the window above the other windows. */
    public function setAlwaysOnTop(bool $on): void
    {
        ObjC::msg('v:l', $this->ns, 'setLevel:', $on ? self::FLOATING_LEVEL : self::NORMAL_LEVEL);
    }

    public function isAlwaysOnTop(): bool
    {
        return ObjC::msg('l:', $this->ns, 'level') !== self::NORMAL_LEVEL;
    }

    /** The window's opacity, from 0 to 1. */
    public function setOpacity(float $v): void
    {
        ObjC::msg('v:d', $this->ns, 'setAlphaValue:', min(max($v, 0.0), 1.0));
    }

    public function opacity(): float
    {
        return ObjC::msg('d:', $this->ns, 'alphaValue');
    }

    public function setHasShadow(bool $on): void { ObjC::msg('v:b', $this->ns, 'setHasShadow:', $on); }
    public function hasShadow(): bool { return ObjC::msg('b:', $this->ns, 'hasShadow'); }

    /** Shows or hides the traffic lights (Electron's setWindowButtonVisibility): a player hides them with its controls. */
    public function setWindowButtonVisibility(bool $visible): void
    {
        for ($i = 0; $i < 3; $i++) { ObjC::msg('v:b', ObjC::msg('p:l', $this->ns, 'standardWindowButton:', $i), 'setHidden:', !$visible); }
    }

    /** Lets the pointer through the window, to what is under it. */
    public function setIgnoreMouseEvents(bool $on): void
    {
        ObjC::msg('v:b', $this->ns, 'setIgnoresMouseEvents:', $on);
    }

    /** Keeps the window's content out of screenshots and screen recordings. */
    public function setContentProtection(bool $on): void
    {
        ObjC::msg('v:L', $this->ns, 'setSharingType:', $on ? 0 : 1); // NSWindowSharingNone, ReadOnly
    }

    /**
     * Shows the progress of a task under the app's Dock icon; the window
     * that set it last wins: new ProgressBar(value: $done / $total), and new
     * ProgressBar() once done.
     */
    public function setProgressBar(ProgressBar $p): void
    {
        $value = min(max($p->value, 0.0), 1.0);
        $state = $p->state ?? ($p->value > 0 ? ProgressBar::NORMAL : ProgressBar::NONE);
        if (!in_array($state, [ProgressBar::NONE, ProgressBar::NORMAL, ProgressBar::INDETERMINATE, ProgressBar::PAUSED, ProgressBar::ERROR], true)) {
            throw new \InvalidArgumentException("no progress state \"$state\"");
        }
        Dock::setProgress($state, $value);
    }

    /** Draws the user's attention to the window, or stops: the Dock icon bounces while the app is not active. */
    public function flashFrame(bool $flash): void
    {
        $app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
        if ($this->attention !== 0) {
            ObjC::msg('v:l', $app, 'cancelUserAttentionRequest:', $this->attention);
            $this->attention = 0;
        }
        if ($flash) {
            $this->attention = ObjC::msg('l:L', $app, 'requestUserAttention:', 10); // NSInformationalRequest
        }
    }

    /**
     * Shows the window on every Space, or on the current one only. A
     * window that cannot go full screen (WindowOptions::$disableFullScreen)
     * shows over other apps' full-screen Spaces too, as an auxiliary.
     */
    public function setVisibleOnAllWorkspaces(bool $on): void
    {
        $b = ObjC::msg('L:', $this->ns, 'collectionBehavior');
        if ($on) {
            $b |= self::CAN_JOIN_ALL_SPACES;
            if ($b & self::FULL_SCREEN_NONE) {
                $b = ($b & ~self::FULL_SCREEN_NONE) | self::FULL_SCREEN_AUXILIARY;
                $this->auxiliary = true;
            }
        } else {
            $b &= ~self::CAN_JOIN_ALL_SPACES;
            if ($this->auxiliary) {
                $b = ($b & ~self::FULL_SCREEN_AUXILIARY) | self::FULL_SCREEN_NONE;
                $this->auxiliary = false;
            }
        }
        ObjC::msg('v:L', $this->ns, 'setCollectionBehavior:', $b);
    }

    /** The NSWindow, for native code that needs it (mygo's NativeHandle). */
    public function nativeHandle()
    {
        return $this->ns;
    }

    /** @internal the page's DOM is ready, or it loaded: a window's own is ready to show */
    public function pageReady(Page $p): void
    {
        if ($p === $this->page) {
            $this->readyToShow();
        }
    }

    /** @internal an Escape the window's page left: a flyout or a panel closes */
    public function pageEscape(Page $p): void
    {
        if ($p === $this->page) {
            $this->escape();
        }
    }

    /** @internal a press on a drag region of the page ($clicks 1) moves the window, a double click ($clicks 2) zooms it as macOS is set to */
    public function pageDrag(int $clicks): void
    {
        if ($clicks === 2) {
            $this->dragWindow(2);
        } elseif (($down = Native\WebView::lastDown()) !== null) {
            ObjC::msg('v:p', $this->ns, 'performWindowDragWithEvent:', $down);
        }
    }

    /** @internal the page's <title> names the window, unless the window shows a document, which its name names */
    public function pageTitled(string $title): void
    {
        if (Native\Docs\Registry::documentOf(Native\Docs\Registry::frameOf($this)) === null) {
            $this->setTitle($title);
        }
    }

    /** @internal whether the window hides its title bar, keeping the controls (WindowOptions::$titleBarStyle) */
    public function hasHiddenTitleBar(): bool
    {
        return $this->hiddenTitleBar;
    }

    /**
     * @internal a command of the Format menu's roles (mygo's contentCommand):
     * on native UI, the node taking the keyboard's input runs it, as a
     * rich text editor its selection; on a page, the editable one does
     */
    public function contentCommand(string $cmd): void
    {
        if ($this->page === null) {
            View::command($this->view, $cmd);
            return;
        }
        $exec = ['bold' => 'bold', 'italic' => 'italic', 'underline' => 'underline', 'strikethrough' => 'strikeThrough',
            'alignLeft' => 'justifyLeft', 'alignCenter' => 'justifyCenter', 'alignRight' => 'justifyRight'][$cmd];
        $this->page->web->evaluate("document.execCommand(\"$exec\")");
    }

    /** A PNG of what the window shows (mygo's CapturePage): its page as it shows, or its native UI as it is. */
    public function capturePage(): string
    {
        if ($this->page !== null) {
            return $this->page->capture();
        }
        [$frame, $w, $h] = App::metal()->lastFrame($this->view); // BGRA, premultiplied: 32-bit little-endian words with alpha first
        $rep = ObjC::msg('p:pllllbbpLll', ObjC::alloc('NSBitmapImageRep'), 'initWithBitmapDataPlanes:pixelsWide:pixelsHigh:bitsPerSample:samplesPerPixel:hasAlpha:isPlanar:colorSpaceName:bitmapFormat:bytesPerRow:bitsPerPixel:',
            null, $w, $h, 8, 4, true, false, ObjC::str('NSDeviceRGBColorSpace'), 1 | 1 << 9, $w * 4, 32) ?? throw new RuntimeException('myphp: capturePage: no bitmap');
        \FFI::memcpy(ObjC::$c->cast('char*', ObjC::msg('p:', $rep, 'bitmapData')), $frame, strlen($frame));
        $png = ObjC::msg('p:Lp', $rep, 'representationUsingType:properties:', 4, ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary')); // NSBitmapImageFileTypePNG
        ObjC::msg('v:', $rep, 'release');
        return $png === null ? throw new RuntimeException('myphp: capturePage: no PNG') : Native\Drag::bytes($png);
    }

    /**
     * Creates a web view for the window's native UI, which shows it where
     * its view places it with Context::webView (mygo's NewWebView); it
     * closes with the window. Throws for a window showing a page.
     */
    public function newWebView(WebViewOptions $o = new WebViewOptions): WebView
    {
        if ($this->runtime === null) {
            throw new RuntimeException('myphp: web views go in windows showing native UI (WindowOptions::$content)');
        }
        if ($this->destroyed) {
            throw new RuntimeException('myphp: the window has been destroyed');
        }
        $v = new WebView($this, $this->container, $this->view, $o);
        $this->webViews[] = $v;
        View::$passThrough[ObjC::addr($this->view)] = fn(float $x, float $y): bool => $this->runtime->webViewAt($x, $y) !== null;
        $this->invalidate();
        return $v;
    }

    /** @internal a web view was destroyed */
    public function forgetWebView(WebView $v): void
    {
        $this->webViews = array_values(array_filter($this->webViews, fn(WebView $w) => $w !== $v));
        $this->invalidate();
    }

    /** @internal the native view of the content, which takes the keyboard back from a web view */
    public function contentView()
    {
        return $this->view;
    }

    /**
     * The view the nodes belowMaterials() paint in: of the window's size,
     * clear, under the materials and over the videos (stackUnder); it
     * draws after each frame that has some or had (Metal::$afterFrame),
     * each where the frame met it, its clip, transform and opacity as they
     * were there.
     */
    private function underView()
    {
        $b = ObjC::msg('r:', $this->container, 'bounds');
        $text = App::text();
        $view = App::metal()->createView(ObjC::rect(0, 0, $b->w, $b->h), function ($view, DisplayList $list) use ($text): void {
            $bounds = ObjC::msg('r:', $view, 'bounds');
            $paint = function () use ($list, $text, $bounds): void {
                $plain = $list->world();
                $list->pushClip(0, 0, $bounds->w, $bounds->h);
                $this->runtime->paintingUnder = true;
                try {
                    foreach ($this->runtime->underPaints as $u) {
                        $list->setWorld($plain); // the clip as the frame had it, in the window's coordinates
                        $list->pushClip(...$u['clip']);
                        $list->setWorld($u['world']);
                        $list->setOpacity($u['opacity']);
                        Painter::paint($u['node'], $list, $text, App::theme(), $this->runtime);
                        $list->popClip();
                    }
                } finally {
                    $this->runtime->paintingUnder = false;
                }
                $list->setWorld($plain);
                $list->setOpacity(1.0);
                $list->popClip();
            };
            $paint();
            if ($text->repaint) { // the atlas started over: what this painted points at slots that are gone
                $text->repaint = false;
                $list->reset();
                $paint();
            }
            $this->underShown = $this->runtime->underPaints !== [];
        }, 'MTKView', 60, [0, 0, 0, 0]);
        ObjC::msg('v:L', $view, 'setAutoresizingMask:', 18); // width and height sizable, as the window's view
        ObjC::msg('v:b', ObjC::msg('p:', $view, 'layer'), 'setOpaque:', false);
        $this->underNew = true;
        return $view;
    }

    /** Puts the view under the materials right over the top video, or at the back without one; the materials go right under the window's view (placeMaterials). */
    private function stackUnder(): void
    {
        $clips = [];
        foreach ($this->videoHosts as $h) { $clips[ObjC::addr($h['clip'])] = true; }
        $subviews = ObjC::msg('p:', $this->container, 'subviews');
        $top = null;
        for ($i = 0, $n = ObjC::msg('L:', $subviews, 'count'); $i < $n; $i++) {
            $v = ObjC::msg('p:L', $subviews, 'objectAtIndex:', $i);
            if (isset($clips[ObjC::addr($v)])) { $top = $v; }
        }
        ObjC::msg('v:', $this->under, 'retain');
        ObjC::msg('v:', $this->under, 'removeFromSuperview');
        ObjC::msg('v:plp', $this->container, 'addSubview:positioned:relativeTo:', $this->under, $top === null ? -1 : 1, $top); // NSWindowBelow of none: the back; NSWindowAbove the video
        ObjC::msg('v:', $this->under, 'release');
        $this->underNew = false;
    }

    /**
     * Shows the web views where the frame showed them, in its paint order
     * under the native view, and hides the others (mygo's placeWebViews);
     * the native view lets them show through its holes, and a web view's
     * node the focus went to gives its page the keyboard.
     */
    private function placeWebViews(float $height): void
    {
        $added = $this->placeVideos($height);
        $this->placeMaterials($height, $added);
        if ($this->under !== null && ($added || $this->underNew)) {
            $this->stackUnder();
        }
        $places = [];
        foreach ($this->runtime->webPlaces as $p) {
            $places[spl_object_id($p['view'])] = $p; // a web view built twice shows where it was built last
        }
        $layer = ObjC::msg('p:', $this->view, 'layer');
        ObjC::msg('v:b', $layer, 'setOpaque:', $places === [] && $this->runtime->videoPlaces === [] && $this->runtime->materialPlaces === [] && !$this->transparent && $this->effect === null); // its holes let the web views, videos and materials show
        $shown = [];
        foreach ($this->webViews as $v) {
            $p = $places[spl_object_id($v)] ?? null;
            $v->place($p['frame'] ?? null, $p['clip'] ?? null, $height);
        }
        foreach ($places as $p) {
            if (in_array($p['view'], $this->webViews, true)) { $shown[] = $p['view']; }
        }
        $this->stackWebViews($shown);
        $focus = $this->runtime->focus;
        $node = null;
        foreach ($places as $p) {
            if ($p['id'] === $focus) { $node = $p; }
        }
        if ($node !== null && $focus !== $this->webFocus) { // tabbed into, or focused: the page takes the keyboard
            $this->runtime->focusFromKeyboard() ? $node['view']->tabInto(($this->input->mods & UI\Key::SHIFT) !== 0) : $node['view']->focus();
        }
        $this->webFocus = $node === null ? null : $focus;
    }

    /**
     * Shows the pictures of the players where the frame showed their videos
     * (Context::video), under the native view, and hides the others: each
     * player's display layer in a view of this window's that clips it,
     * moved here from another window's if it showed there. The layer's
     * frame changes without the implicit animation of Core Animation.
     */
    private function placeVideos(float $height): bool
    {
        $width = ObjC::msg('r:', $this->container, 'bounds')->w;
        $added = false;
        $places = [];
        foreach ($this->runtime->videoPlaces as $p) {
            $places[spl_object_id($p['view'])] = $p;
        }
        foreach ($places as $k => $p) {
            if (!isset($this->videoHosts[$k])) {
                $clip = ObjC::msg('p:r', ObjC::alloc('NSView'), 'initWithFrame:', ObjC::rect(0, 0, 0, 0));
                ObjC::msg('v:b', $clip, 'setWantsLayer:', true);
                ObjC::msg('v:b', ObjC::msg('p:', $clip, 'layer'), 'setMasksToBounds:', true);
                ObjC::msg('v:plp', $this->container, 'addSubview:positioned:relativeTo:', $clip, -1, $this->view); // NSWindowBelow
                // Views all the way down, the display layer the layer of the last (layer hosting): picture in picture maps a
                // sample buffer display layer through the view that hosts it, and showed a corner of a sublayer, unscaled.
                $crop = ObjC::msg('p:r', ObjC::alloc('NSView'), 'initWithFrame:', ObjC::rect(0, 0, 0, 0)); // what of the picture shows: a crop cuts it to this
                ObjC::msg('v:b', $crop, 'setWantsLayer:', true);
                ObjC::msg('v:b', ObjC::msg('p:', $crop, 'layer'), 'setMasksToBounds:', true);
                ObjC::msg('v:p', $clip, 'addSubview:', $crop);
                ObjC::msg('v:', $crop, 'release'); // the clip holds it
                $video = ObjC::msg('p:r', ObjC::alloc('NSView'), 'initWithFrame:', ObjC::rect(0, 0, 0, 0));
                ObjC::msg('v:p', $video, 'setLayer:', $p['view']->out->layer);
                $p['view']->out->view = $video;
                ObjC::msg('v:b', $video, 'setWantsLayer:', true); // after setLayer: a layer hosting view, the layer the player's
                ObjC::msg('v:p', $crop, 'addSubview:', $video);
                ObjC::msg('v:', $video, 'release');
                $this->videoHosts[$k] = ['player' => \WeakReference::create($p['view']), 'clip' => $clip, 'crop' => $crop, 'video' => $video];
                $added = true;
            }
        }
        ObjC::msg('v:', ObjC::cls('CATransaction'), 'begin');
        ObjC::msg('v:b', ObjC::cls('CATransaction'), 'setDisableActions:', true);
        foreach ($this->videoHosts as $k => ['player' => $ref, 'clip' => $clip, 'crop' => $crop, 'video' => $video]) {
            $player = $ref->get();
            if ($player === null) { // gone, its layer with it
                ObjC::msg('v:', $clip, 'removeFromSuperview');
                ObjC::msg('v:', $clip, 'release');
                unset($this->videoHosts[$k]);
                continue;
            }
            $p = $places[$k] ?? null;
            if ($p === null) {
                ObjC::msg('v:b', $clip, 'setHidden:', true);
                continue;
            }
            [$cx, $cy, $cw, $ch] = $p['clip'];
            [$fx, $fy, $fw, $fh] = $p['frame'];
            // A video across the window, or down it, stretches with it as AppKit resizes the window (the full screen's animation, a live resize), before
            // the next frame places it: the picture goes with the window, as a player's view does; elsewhere it keeps its distance to the top.
            $wide = $cx <= 0 && $cx + $cw >= $width; $tall = $cy <= 0 && $cy + $ch >= $height;
            ObjC::msg('v:L', $clip, 'setAutoresizingMask:', ($wide ? 2 : 0) | ($tall ? 16 : 8)); // NSViewWidthSizable, NSViewHeightSizable, else NSViewMinYMargin
            ObjC::msg('v:r', $clip, 'setFrame:', ObjC::rect($cx, $height - $cy - $ch, $cw, $ch));
            if ($player->out->elsewhere) { // in picture in picture's window, which maps the layer as it was: left so until it stops
                ObjC::msg('v:b', $clip, 'setHidden:', false);
                continue;
            }
            $layer = $player->out->layer;
            [$rx, $ry] = [$fx - $cx, $ch - ($fy - $cy) - $fh]; // the node's box in the clip view, which is not flipped: y up from its bottom
            ObjC::msg('v:d', $video, 'setFrameCenterRotation:', 0.0); // upright before its frame is set, which a turned view's is not
            if ($player->plainPicture()) { // the layer fits the picture in the box itself, and stretches with it as the window resizes
                $fills = [$fx, $fy, $fw, $fh] == [$cx, $cy, $cw, $ch];
                ObjC::msg('v:r', $crop, 'setFrame:', ObjC::rect($rx, $ry, $fw, $fh));
                ObjC::msg('v:L', $crop, 'setAutoresizingMask:', $fills ? 18 : 0); // NSViewWidthSizable | NSViewHeightSizable: with the clip, when it fills it
                ObjC::msg('v:p', $layer, 'setVideoGravity:', ObjC::str('AVLayerVideoGravityResizeAspect'));
                ObjC::msg('v:r', $video, 'setFrame:', ObjC::rect(0, 0, $fw, $fh));
                ObjC::msg('v:L', $video, 'setAutoresizingMask:', 18);
            } else { // laid out here: the crop view the part that shows, the picture's view its whole size in it, stretched to it and turned
                [[$vx, $vy, $vw, $vh], [$pw, $ph]] = $player->picture($fw, $fh);
                ObjC::msg('v:r', $crop, 'setFrame:', ObjC::rect($rx + $vx, $ry + $fh - $vy - $vh, $vw, $vh));
                ObjC::msg('v:L', $crop, 'setAutoresizingMask:', 0);
                ObjC::msg('v:L', $video, 'setAutoresizingMask:', 0);
                ObjC::msg('v:p', $layer, 'setVideoGravity:', ObjC::str('AVLayerVideoGravityResize'));
                ObjC::msg('v:r', $video, 'setFrame:', ObjC::rect(($vw - $pw) / 2, ($vh - $ph) / 2, $pw, $ph));
                ObjC::msg('v:d', $video, 'setFrameCenterRotation:', -(float)$player->rotation()); // clockwise: AppKit turns counterclockwise
            }
            ObjC::msg('v:b', $clip, 'setHidden:', false);
        }
        ObjC::msg('v:', ObjC::cls('CATransaction'), 'commit');
        return $added; // a view of a video made: right under the native view, over the materials, which go back over it
    }

    /**
     * Shows the system materials where the frame showed them
     * (Node::systemMaterial): a view of each node under the native view,
     * over the videos (it blurs them, blending within the window), made
     * the first frame it shows and removed the first it does not. A
     * material of another kind than its view's makes it anew. $restack:
     * a view of a video was put right under the native view.
     */
    private function placeMaterials(float $height, bool $restack): void
    {
        $shown = [];
        $added = $restack;
        ObjC::msg('v:', ObjC::cls('CATransaction'), 'begin');
        ObjC::msg('v:b', ObjC::cls('CATransaction'), 'setDisableActions:', true);
        foreach ($this->runtime->materialPlaces as $p) {
            $glass = $p['material'] === 'glass' || $p['material'] === 'clear-glass'; // a name Node::systemMaterial took
            $id = $p['id'];
            $m = $this->materials[$id] ?? null;
            if ($m !== null && $m['kind'] !== $p['material']) {
                ObjC::msg('v:', $m['view'], 'removeFromSuperview');
                ObjC::msg('v:', $m['view'], 'release');
                $m = null;
            }
            if ($m === null) {
                $m = ['view' => $this->materialView($p['material']), 'kind' => $p['material']];
                $this->materials[$id] = $m;
                $added = true;
            }
            $v = $m['view'];
            [$cx, $cy, $cw, $ch] = $p['clip'];
            ObjC::msg('v:r', $v, 'setFrame:', ObjC::rect($cx, $height - $cy - $ch, $cw, $ch));
            $appearance = $p['dark'] === null ? null : ObjC::msg('p:p', ObjC::cls('NSAppearance'), 'appearanceNamed:', ObjC::str($p['dark'] ? 'NSAppearanceNameDarkAqua' : 'NSAppearanceNameAqua'));
            ObjC::msg('v:p', $v, 'setAppearance:', $appearance);
            if ($glass) {
                ObjC::msg('v:d', $v, 'setCornerRadius:', $p['radius']);
            } else {
                ObjC::msg('v:d', ObjC::msg('p:', $v, 'layer'), 'setCornerRadius:', $p['radius']);
            }
            ObjC::msg('v:d', $v, 'setAlphaValue:', $p['alpha']); // the node's opacity: a material fades with it
            $shown[$id] = true;
        }
        foreach ($this->materials as $id => $m) {
            if (!isset($shown[$id])) {
                ObjC::msg('v:', $m['view'], 'removeFromSuperview');
                ObjC::msg('v:', $m['view'], 'release');
                unset($this->materials[$id]);
            }
        }
        if ($added) { // the materials over the videos, in the order the frame painted them
            foreach ($this->runtime->materialPlaces as $p) {
                $v = ObjC::msg('p:', $this->materials[$p['id']]['view'], 'retain');
                ObjC::msg('v:', $v, 'removeFromSuperview');
                ObjC::msg('v:plp', $this->container, 'addSubview:positioned:relativeTo:', $v, -1, $this->view); // NSWindowBelow: right under the native view
                ObjC::msg('v:', $v, 'release');
            }
        }
        ObjC::msg('v:', ObjC::cls('CATransaction'), 'commit');
    }

    /** A view of system material $material, blending within the window: an NSGlassEffectView for glass (macOS 26), else an NSVisualEffectView. */
    private function materialView(string $material)
    {
        if ($material === 'glass' || $material === 'clear-glass') {
            $cls = ObjC::$c->objc_getClass('NSGlassEffectView') ?? throw new RuntimeException('myphp: glass needs macOS 26');
            $v = ObjC::msg('p:r', ObjC::msg('p:', $cls, 'alloc'), 'initWithFrame:', ObjC::rect(0, 0, 0, 0));
            if ($material === 'clear-glass') {
                ObjC::msg('v:l', $v, 'setStyle:', 1); // NSGlassEffectViewStyleClear
            }
            $content = ObjC::msg('p:r', ObjC::alloc('NSView'), 'initWithFrame:', ObjC::rect(0, 0, 0, 0));
            ObjC::msg('v:p', $v, 'setContentView:', $content); // empty: the glass draws as the system's, what the node holds drawing over it
            ObjC::msg('v:', $content, 'release');
        } else {
            $v = ObjC::msg('p:r', ObjC::alloc('NSVisualEffectView'), 'initWithFrame:', ObjC::rect(0, 0, 0, 0));
            ObjC::msg('v:l', $v, 'setBlendingMode:', 1); // NSVisualEffectBlendingModeWithinWindow: what the window shows under it, the videos too
            ObjC::msg('v:l', $v, 'setState:', 1); // NSVisualEffectStateActive
            ObjC::msg('v:l', $v, 'setMaterial:', self::MATERIALS[$material]);
            ObjC::msg('v:b', $v, 'setWantsLayer:', true);
            ObjC::msg('v:b', ObjC::msg('p:', $v, 'layer'), 'setMasksToBounds:', true);
        }
        ObjC::msg('v:L', $v, 'setAutoresizingMask:', 8); // NSViewMinYMargin, as the videos' views
        ObjC::msg('v:plp', $this->container, 'addSubview:positioned:relativeTo:', $v, -1, $this->view);
        return $v;
    }

    /** Puts the clip views of the web views shown in the order the frame painted them, under the native view, unless they are (mygo's stackWebViews). @param list<WebView> $shown */
    private function stackWebViews(array $shown): void
    {
        if (count($shown) < 2) {
            return;
        }
        $subs = ObjC::msg('p:', $this->container, 'subviews');
        $at = 0;
        for ($i = 0, $n = ObjC::msg('L:', $subs, 'count'); $i < $n && $at < count($shown); $i++) {
            if (ObjC::addr(ObjC::msg('p:L', $subs, 'objectAtIndex:', $i)) === ObjC::addr($shown[$at]->clipView())) { $at++; }
        }
        if ($at === count($shown)) {
            return;
        }
        $first = ObjC::msg('p:', $this->ns, 'firstResponder'); // moving a view out of its superview takes the keyboard from it
        foreach ($shown as $v) {
            $clip = ObjC::msg('p:', $v->clipView(), 'retain');
            ObjC::msg('v:', $clip, 'removeFromSuperview');
            ObjC::msg('v:plp', $this->container, 'addSubview:positioned:relativeTo:', $clip, -1, $this->view);
            ObjC::msg('v:', $clip, 'release');
        }
        if ($first !== null && ObjC::addr($first) !== ObjC::addr(ObjC::msg('p:', $this->ns, 'firstResponder'))) {
            ObjC::msg('b:p', $this->ns, 'makeFirstResponder:', $first);
        }
    }

    /** @internal makes the window of window.open() (mygo's NewWindow handler): with $opts, the size the page asked for, and WebKit's configuration, whose page WebKit loads */
    public static function opened(WindowOptions $opts, int $width, int $height, $configuration): self
    {
        $o = clone $opts;
        if ($width > 0 && $height > 0) {
            [$o->width, $o->height] = [$width, $height];
        }
        [$o->url, $o->html, $o->content] = [null, null, null];
        self::$opening = $configuration;
        try {
            return new self($o);
        } finally {
            self::$opening = null;
        }
    }

    /** Tells the 'readyToShow' listeners, once: the page is ready, or native UI drew its first frame. */
    private function readyToShow(): void
    {
        if (!$this->readyShown) {
            $this->readyShown = true;
            $this->emit('readyToShow');
        }
    }

    /**
     * Creates a flyout (mygo's NewFlyout): a window without a frame, a
     * background, a shadow or a Dock presence, owned by its parent and
     * placed next to a rectangle of it, which may extend beyond the parent
     * but stays in the work area of a display; or next to a tray icon,
     * above the windows of other apps. Its page or native UI draws all of
     * it: give the page a background, or the native UI's root one.
     */
    public static function flyout(FlyoutOptions $o): Window
    {
        if (($o->parent === null) === ($o->tray === null)) {
            throw new \InvalidArgumentException('Window::flyout needs a parent or a tray');
        }
        [$side, $align] = Flyout::PLACEMENTS[$o->placement] ?? throw new \InvalidArgumentException("unknown flyout placement \"{$o->placement}\"");
        $width = $o->width ?: 200; $height = $o->height ?: 200;
        self::$creating = ['side' => $side, 'align' => $align, 'gap' => $o->gap, 'anchor' => $o->anchor ?? new Rectangle(0, 0, 0, 0), 'tray' => $o->tray, 'focusable' => $o->focusable, 'popover' => $o->popover, 'width' => $width, 'height' => $height];
        try {
            return new self(new WindowOptions(width: $width, height: $height, useContentSize: true, hidden: $o->hidden, frameless: true,
                disableResize: true, disableMinimize: true, disableMaximize: true, disableFullScreen: true, disableShadow: !$o->shadow, transparent: true,
                parent: $o->parent, content: $o->content, html: $o->html, url: $o->url, page: $o->page));
        } finally {
            self::$creating = null;
        }
    }

    /** Whether Window::flyout() created the window. */
    public function isFlyout(): bool
    {
        return $this->flyout !== null;
    }

    /** Whether the window was created with WindowOptions::$panel. */
    public function isPanel(): bool
    {
        return $this->panel;
    }

    /** Places a flyout against another rectangle of its parent, in points from the top-left of the parent's content, as FlyoutOptions::$anchor; nothing for other windows. */
    public function setAnchor(Rectangle $anchor): void
    {
        if ($this->flyout === null || $this->destroyed) {
            return;
        }
        $this->flyout['anchor'] = $anchor;
        $this->placeFlyout();
    }

    /** Places a flyout where it goes now: Flyout::place in the work area of the display of its anchor, the tray icon's or its parent's content's. */
    private function placeFlyout(): void
    {
        if ($this->flyout === null || $this->destroyed) {
            return;
        }
        if ($this->popover !== null) {
            $this->placePopover();
            return;
        }
        $f = $this->flyout;
        $displays = Screens::displays();
        $anchor = $f['anchor'];
        if ($f['tray'] !== null) {
            $anchor = $f['tray']->bounds();
            if ($anchor->width === 0 || $anchor->height === 0) { // the icon shows in no menu bar: in the middle of the primary display
                ObjC::msg('v:rb', $this->ns, 'setFrame:display:', Screens::toMac(Flyout::centered($f['width'], $f['height'], Flyout::primaryWorkArea($displays))), true);
                return;
            }
        } elseif ($this->parent !== null && !$this->parent->destroyed) {
            $c = $this->parent->contentBounds();
            $anchor = new Rectangle($anchor->x + $c->x, $anchor->y + $c->y, $anchor->width, $anchor->height);
        }
        [$r] = Flyout::place($f['side'], $f['align'], $f['gap'], $anchor, $f['width'], $f['height'], Flyout::workAreaFor($anchor, $displays));
        ObjC::msg('v:rb', $this->ns, 'setFrame:display:', Screens::toMac($r), true);
    }

    /** Places the flyouts of a window that moved or resized again. */
    private function placeFlyouts(): void
    {
        foreach (self::$open as $w) {
            if ($w->parent === $this && $w->flyout !== null) {
                $w->placeFlyout();
            }
        }
    }

    /** Resizes a flyout and places it again, as setSize and setContentSize do alike; false for other windows. */
    private function setFlyoutSize(int $width, int $height): bool
    {
        if ($this->flyout === null) {
            return false;
        }
        $this->flyout['width'] = max($width, 1);
        $this->flyout['height'] = max($height, 1);
        $this->placeFlyout();
        return true;
    }

    /** Shows a flyout above its parent, as its child window, with the keyboard when $key: it does not activate the app, as it shows over a parent the user is using. */
    private function showFlyout(bool $key): void
    {
        if ($key) {
            self::watchOutsideClicks();
            ObjC::msg('v:p', $this->ns, 'makeKeyAndOrderFront:', null);
        } else {
            ObjC::msg('v:p', $this->ns, 'orderFront:', null);
        }
        if ($this->parent !== null && !$this->parent->destroyed && ObjC::msg('p:', $this->ns, 'parentWindow') === null) {
            ObjC::msg('v:pl', $this->parent->ns, 'addChildWindow:ordered:', $this->ns, 1); // NSWindowAbove
        }
    }

    /** Hides a flyout: it stops being a child window, which AppKit would order in again with its parent, and gives the keyboard it had back to the parent. */
    private function hideFlyout(): void
    {
        $key = ObjC::msg('b:', $this->ns, 'isKeyWindow');
        if ($this->parent !== null && !$this->parent->destroyed) {
            ObjC::msg('v:p', $this->parent->ns, 'removeChildWindow:', $this->ns);
            if ($key && $this->parent->isVisible()) {
                ObjC::msg('v:', $this->parent->ns, 'makeKeyWindow');
            }
        }
        ObjC::msg('v:p', $this->ns, 'orderOut:', null);
    }

    /**
     * Watches presses outside the app, in other apps or the menu bar,
     * which close focusable flyouts as AppKit's transient popovers close:
     * their panels take the keyboard without the app being activated, and
     * a press in the menu bar activates no other app, so they would not
     * resign the key window. Made once, as the first focusable flyout
     * shows, with the watch of presses on anchors.
     */
    private static function watchOutsideClicks(): void
    {
        if (self::$outsideClicks !== null) {
            return;
        }
        $mask = 1 << 1 | 1 << 3 | 1 << 25; // left, right and other mouse down
        self::$outsideClicks = ObjC::msg('p:Lp', ObjC::cls('NSEvent'), 'addGlobalMonitorForEventsMatchingMask:handler:', $mask, ObjC::block('p', function ($ev) {
            $open = array_filter(self::$open, fn(Window $w) => $w->flyout !== null && $w->popover === null && $w->flyout['focusable'] && !$w->destroyed && $w->isVisible());
            foreach ($open as $w) {
                $w->close();
            }
        }, false));
        ObjC::msg('p:', self::$outsideClicks, 'retain');
        self::watchAnchorPresses();
    }

    /**
     * Watches presses in the app, and eats one on the anchor of a
     * focusable flyout, which only closes the flyout, as AppKit's transient
     * popovers eat a press on their positioning rect: the press would make
     * the parent key, which closes the flyout before the release clicks
     * what opened it, which would open it again. The press's drags and
     * release are eaten too; presses elsewhere go on to what is under the
     * pointer.
     */
    private static function watchAnchorPresses(): void
    {
        $mask = 1 << 1 | 1 << 3 | 1 << 25 | 1 << 2 | 1 << 4 | 1 << 26 | 1 << 6 | 1 << 7 | 1 << 27; // left, right and other mouse down, up and dragged
        self::$anchorPresses = ObjC::msg('p:Lp', ObjC::cls('NSEvent'), 'addLocalMonitorForEventsMatchingMask:handler:', $mask, ObjC::block('p', function ($ev) {
            $type = ObjC::msg('L:', $ev, 'type');
            if (in_array($type, [2, 4, 26], true)) { // NSEventTypeLeftMouseUp, RightMouseUp, OtherMouseUp
                if ($type === self::$eatingPress) {
                    self::$eatingPress = 0;
                    return null;
                }
            } elseif (in_array($type, [6, 7, 27], true)) { // NSEventTypeLeftMouseDragged, RightMouseDragged, OtherMouseDragged
                if (self::$eatingPress !== 0) {
                    return null;
                }
            } else { // a press, which ends any other
                self::$eatingPress = 0;
                if (($w = self::flyoutAnchoredAt($ev)) !== null) {
                    self::$eatingPress = $type + 1;
                    $w->close();
                    return null;
                }
            }
            return $ev;
        }, false, 'p'));
        ObjC::msg('p:', self::$anchorPresses, 'retain');
    }

    /** The focusable flyout showing whose anchor is under the press $ev in its parent, or null. */
    private static function flyoutAnchoredAt($ev): ?Window
    {
        $evWin = ObjC::msg('p:', $ev, 'window');
        $p = $evWin === null ? null : (self::$open[ObjC::addr($evWin)] ?? null);
        if ($p === null || $p->destroyed) {
            return null;
        }
        $frame = ObjC::msg('r:', $p->ns, 'frame');
        $c = ObjC::msg('r:r', $p->ns, 'contentRectForFrameRect:', $frame);
        $loc = ObjC::msg('q:', $ev, 'locationInWindow');
        $x = $loc->x - ($c->x - $frame->x);
        $y = $c->y - $frame->y + $c->h - $loc->y;
        foreach (self::$open as $w) {
            if ($w->parent !== $p || $w->flyout === null || $w->popover !== null || !$w->flyout['focusable'] || $w->destroyed || !$w->isVisible()) {
                continue;
            }
            $a = $w->flyout['anchor'];
            if ($x >= $a->x && $y >= $a->y && $x < $a->x + $a->width && $y < $a->y + $a->height) {
                return $w;
            }
        }
        return null;
    }

    /** Closes a focusable flyout that lost the keyboard, as the user closing it, unless it went to a flyout of its own. */
    private function dismissFlyout(): void
    {
        if ($this->destroyed || !$this->flyout['focusable'] || !$this->isVisible()) {
            return;
        }
        for ($k = self::focused(); $k !== null; $k = $k->parent) {
            if ($k === $this) {
                return;
            }
        }
        $this->close();
    }

    /**
     * The NSPopover of a flyout with FlyoutOptions::$popover (mygo's
     * createPopover): it draws the system's material and an arrow, and
     * animates; a focusable flyout's is transient, closing on a click
     * elsewhere once popoverShouldClose: lets it.
     */
    private function createPopover($delegate): void
    {
        ObjC::msg('v:L', $this->container, 'setAutoresizingMask:', 18); // width and height sizable
        $this->popoverController = ObjC::msg('p:', ObjC::alloc('NSViewController'), 'init');
        ObjC::msg('v:p', $this->popoverController, 'setView:', $this->container);
        $this->popover = ObjC::msg('p:', ObjC::alloc('NSPopover'), 'init');
        ObjC::msg('v:p', $this->popover, 'setContentViewController:', $this->popoverController);
        ObjC::msg('v:z', $this->popover, 'setContentSize:', $this->flyoutSize());
        ObjC::msg('v:l', $this->popover, 'setBehavior:', $this->flyout['focusable'] ? 1 : 0); // NSPopoverBehaviorTransient, ApplicationDefined
        ObjC::msg('v:p', $this->popover, 'setDelegate:', $delegate);
    }

    private function flyoutSize()
    {
        $s = ObjC::$c->new('CGSize');
        $s->w = $this->flyout['width']; $s->h = $this->flyout['height'];
        return $s;
    }

    /**
     * The view the popover shows from, the anchor in it and the edge of it
     * the popover goes to (NSRectEdge): the parent's content view, which is
     * not flipped, or the tray icon's button; the view is null when there
     * is none.
     * @return array{0: mixed, 1: mixed, 2: int}
     */
    private function popoverAnchor(): array
    {
        $edge = [Flyout::BOTTOM => 1, Flyout::TOP => 3, Flyout::RIGHT => 2, Flyout::LEFT => 0][$this->flyout['side']];
        if (($t = $this->flyout['tray']) !== null) {
            $button = $t->nativeHandle();
            return [$button, ObjC::msg('r:', $button, 'bounds'), $edge];
        }
        $a = $this->flyout['anchor'];
        $p = $this->parent;
        if ($p === null || $p->destroyed) {
            return [null, null, $edge];
        }
        $h = ObjC::msg('r:', $p->container, 'bounds')->h;
        $height = max($a->height, 1);
        return [$p->container, ObjC::rect($a->x, $h - $a->y - $height, max($a->width, 1), $height), $edge];
    }

    private function showPopover(): void
    {
        [$view, $r, $edge] = $this->popoverAnchor();
        if ($view === null || ObjC::msg('b:', $this->popover, 'isShown')) {
            return;
        }
        $this->popoverClosing = false;
        if ($this->flyout['tray'] !== null) { // the popover of a menu bar app takes the keyboard of an app the user did not activate, as AppKit's own status items' do
            ObjC::msg('v:b', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'activateIgnoringOtherApps:', true);
        }
        ObjC::msg('v:z', $this->popover, 'setContentSize:', $this->flyoutSize());
        ObjC::msg('v:rpL', $this->popover, 'showRelativeToRect:ofView:preferredEdge:', $r, $view, $edge);
        $this->ns = ObjC::msg('p:', $this->container, 'window'); // the window the popover shows in is the flyout's
        if ($this->ns !== null) {
            ObjC::msg('b:p', $this->ns, 'makeFirstResponder:', $this->view);
        }
    }

    /** Moves a popover that shows to the anchor, at the size. */
    private function placePopover(): void
    {
        if (!ObjC::msg('b:', $this->popover, 'isShown')) {
            return;
        }
        ObjC::msg('v:z', $this->popover, 'setContentSize:', $this->flyoutSize());
        if ($this->flyout['tray'] === null && ($r = $this->popoverAnchor()[1]) !== null) {
            ObjC::msg('v:r', $this->popover, 'setPositioningRect:', $r);
        }
    }

    /** Closes the popover, which shows again with show(), and gives the keyboard it had back to the parent. */
    private function hidePopover(): void
    {
        if (!ObjC::msg('b:', $this->popover, 'isShown')) {
            return;
        }
        $key = $this->ns !== null && ObjC::msg('b:', $this->ns, 'isKeyWindow');
        $this->popoverClosing = true;
        ObjC::msg('v:', $this->popover, 'close');
        if ($key && $this->parent !== null && !$this->parent->destroyed && $this->parent->isVisible()) {
            ObjC::msg('v:', $this->parent->ns, 'makeKeyWindow');
        }
    }

    private function cleanupPopover(): void
    {
        $this->ns = null;
        ObjC::msg('v:p', $this->popover, 'setDelegate:', null);
        if (ObjC::msg('b:', $this->popover, 'isShown')) {
            ObjC::msg('v:b', $this->popover, 'setAnimates:', false);
            ObjC::msg('v:', $this->popover, 'close');
        }
        ObjC::msg('v:p', $this->popover, 'setContentViewController:', null);
        ObjC::msg('v:', $this->popover, 'release');
        ObjC::msg('v:', $this->popoverController, 'release');
    }

    /**
     * Starts a native drag of $data from this window's pointer gesture
     * (mygo's StartDataDrag): the window shows native UI. $o->done hears
     * how it ended, on the main thread; what is wrong with it throws, and
     * starts nothing. A node's drag is Context::dragData.
     */
    public function startDataDrag(Data $data, DragOptions $o = new DragOptions()): void
    {
        $this->dragData($data, null, $o, -1, -1);
    }

    /** Cancels the native drag this window sources, if any (mygo's CancelDataDrag); its source is told. */
    public function cancelDataDrag(): void
    {
        DataDrag::cancel($this, fn() => Drag::cancel($this->view));
    }

    /** A native drag of $data, its value $local for this process's typed targets, from ($x, $y), or the press for negative ones (mygo's startDataDrag). */
    private function dragData(Data $data, mixed $local, DragOptions $o, float $x, float $y): void
    {
        if ($this->destroyed) {
            throw new RuntimeException('myphp: the window is closed');
        }
        if ($this->runtime === null) {
            throw new RuntimeException('myphp: a data drag requires native UI');
        }
        [$pix, $width, $height] = self::dragImage($o->preview);
        DataDrag::start($this, $data, $local, $o->done, $o->operations, function (DataDrag $src) use ($o, $x, $y, $pix, $width, $height) {
            if ($this->destroyed) {
                $src->finish(new Result(canceled: true));
                return;
            }
            Drag::start($this->view, View::lastDown($this->view), ['data' => $src->data(), 'session' => $src->token, 'operations' => $src->operations,
                'x' => $x, 'y' => $y, 'preview' => $pix, 'width' => $width, 'height' => $height,
                'hotX' => $o->hotspot[0], 'hotY' => $o->hotspot[1], 'done' => $src->finish(...)]);
        });
    }

    /** The pixels of a drag's image, premultiplied BGRA (mygo's dragImage): none for none. @return array{0: ?string, 1: int, 2: int} */
    private static function dragImage(?Image $image): array
    {
        if ($image === null) {
            return [null, 0, 0];
        }
        if ($image->w <= 0 || $image->h <= 0 || $image->w > 4096 || $image->h > 4096) {
            throw new \InvalidArgumentException('myphp: drag preview size must be 1..4096 pixels');
        }
        $p = $image->pixels();
        for ($i = 0, $n = strlen($p); $i < $n; $i += 4) {
            [$p[$i], $p[$i + 2]] = [$p[$i + 2], $p[$i]];
        }
        return [$p, $image->w, $image->h];
    }

    /** The last frame's ($x, $y) and the $w by $h points from there, at one pixel a point (the runtime's snapshot, for mygo's dataPreview). */
    private function snapshot(float $x, float $y, int $w, int $h): Image
    {
        $window = ObjC::msg('p:', $this->view, 'window');
        $scale = $window === null ? App::scale() : ObjC::msg('d:', $window, 'backingScaleFactor');
        [$frame, $fw, $fh] = App::metal()->lastFrame($this->view);
        $out = str_repeat("\0", $w * $h * 4);
        for ($row = 0; $row < $h; $row++) {
            $py = (int)(($y + $row) * $scale);
            if ($py < 0 || $py >= $fh) {
                continue;
            }
            for ($col = 0; $col < $w; $col++) {
                $px = (int)(($x + $col) * $scale);
                if ($px < 0 || $px >= $fw) {
                    continue;
                }
                $i = ($py * $fw + $px) * 4; $j = ($row * $w + $col) * 4;
                $out[$j] = $frame[$i + 2]; $out[$j + 1] = $frame[$i + 1]; $out[$j + 2] = $frame[$i]; $out[$j + 3] = $frame[$i + 3]; // BGRA to RGBA
            }
        }
        return Image::fromPixels($w, $h, $out);
    }

    /**
     * A native drag over the view, 'over', 'leave' or 'drop', to the content
     * (mygo's SurfaceEvent): files it does not take as data go to a node
     * taking files (Context::droppedFiles), then to the 'fileDrop'
     * listeners, when it has some. Whether it took it.
     */
    private function dataEvent(string $kind, DataDragEvent $d, float $x, float $y): bool
    {
        DataDrag::resolve($d);
        $rt = $this->runtime;
        if ($kind === 'leave') {
            $rt->clearDataOver();
            $taken = false;
        } else {
            $taken = $kind === 'over' ? $rt->dataDragOver($x, $y, $d) : $rt->dataDrop($x, $y, $d);
        }
        if ($taken) {
            return true;
        }
        if ($kind === 'leave') {
            $rt->fileDrag(-1, -1);
        } elseif ($d->hasFiles || in_array(Format::FILE_LIST, $d->offer->formats, true)) {
            $d->operation = Operation::negotiate($d->offer->operations, Operation::COPY, $d->offer->suggested);
            if ($d->operation === Operation::NONE) {
                return false;
            }
            $paths = [];
            if ($kind === 'drop') {
                $paths = $d->data->paths();
                if ($paths === []) {
                    $d->operation = Operation::NONE;
                    return false;
                }
            }
            $d->formats = [Format::FILE_LIST];
            if ($this->fileEvent($kind, $x, $y, $paths)) {
                return true;
            }
        }
        $d->operation = Operation::NONE;
        return false;
    }

    /** Files from another app over the view or dropped on it: to a node taking them, else to the 'fileDrop' listeners. @param list<string> $paths */
    private function fileEvent(string $kind, float $x, float $y, array $paths): bool
    {
        $listeners = $this->listeners['fileDrop'] ?? [];
        if ($kind === 'over') {
            return $this->runtime->fileDrag($x, $y) || $listeners !== [];
        }
        if ($this->runtime->fileDrop($x, $y, $paths)) {
            return true;
        }
        if ($listeners === []) {
            return false;
        }
        $e = new FileDropEvent($this, $paths, $x, $y);
        foreach ($listeners as $fn) {
            $fn($e);
        }
        return true;
    }

    /** Closes a flyout or a panel on an Escape its page or native UI left, as the user closing it, after the frame being built: menus, popovers and launchers close so. */
    private function escape(): void
    {
        if ($this->flyout !== null || $this->panel) {
            App::later($this->close(...));
        }
    }

    /** The window classes: a window (MyPHPWindow) and a panel give the keyboard back to native UI as Tab leaves a web view's page; a panel (MyPHPPanel) takes the keyboard without activating the app and is never the main window; a flyout's (MyPHPFlyout) is the key window only when it takes the keyboard, its parent staying the main window. */
    private static function definePanels(): void
    {
        if (self::$panelsDefined) {
            return;
        }
        self::$panelsDefined = true;
        // WebKit moves the keyboard out of a page past its ends through the window's key view loop: a web view in native UI gives it back to the content (mygo's tabOut).
        $keyView = fn(string $class, bool $back) => function ($self, $cmd, $view) use ($class, $back): void {
            $w = self::$open[ObjC::addr($self)] ?? null;
            foreach ($w?->webViews ?? [] as $v) {
                if ($view !== null && $v->holds($view)) {
                    ObjC::msg('b:p', $self, 'makeFirstResponder:', $w->view);
                    View::keyCommand($w->view, $back ? 'insertBacktab:' : 'insertTab:'); // the focus moves on from the web view's node
                    return;
                }
            }
            ObjC::msgSuper('v:p', $self, $class, $back ? 'selectKeyViewPrecedingView:' : 'selectKeyViewFollowingView:', $view);
        };
        ObjC::defineClass('MyPHPWindow', 'NSWindow', [
            'selectKeyViewFollowingView:' => ['v@:@', 'v(ppp)', $keyView('NSWindow', false)],
            'selectKeyViewPrecedingView:' => ['v@:@', 'v(ppp)', $keyView('NSWindow', true)],
        ]);
        ObjC::defineClass('MyPHPPanel', 'NSPanel', [
            'canBecomeKeyWindow' => ['B@:', 'b(pp)', fn($self, $cmd) => true],
            'canBecomeMainWindow' => ['B@:', 'b(pp)', fn($self, $cmd) => false],
            'selectKeyViewFollowingView:' => ['v@:@', 'v(ppp)', $keyView('NSPanel', false)],
            'selectKeyViewPrecedingView:' => ['v@:@', 'v(ppp)', $keyView('NSPanel', true)],
            // NSPanel closes on an Escape its content leaves (cancelOperation:), past windowShouldClose:: escape() closes it, as the user closing it.
            'cancelOperation:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) {}],
        ]);
        ObjC::defineClass('MyPHPFlyout', 'NSPanel', [
            'canBecomeKeyWindow' => ['B@:', 'b(pp)', fn($self, $cmd) => (self::$open[ObjC::addr($self)] ?? null)?->flyout['focusable'] ?? false],
            'canBecomeMainWindow' => ['B@:', 'b(pp)', fn($self, $cmd) => false],
        ]);
    }

    /** @return list<Window> the open windows, in the order they were created */
    public static function all(): array
    {
        $all = array_values(self::$open);
        usort($all, fn(Window $a, Window $b) => $a->id <=> $b->id);
        return $all;
    }

    /** The window that has the keyboard, or null (mygo's FocusedWindow). */
    public static function focused(): ?Window
    {
        $ns = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'keyWindow');
        return $ns === null ? null : (self::$open[ObjC::addr($ns)] ?? null);
    }

    /** The open window with id $id, or null (mygo's WindowByID). */
    public static function byId(int $id): ?Window
    {
        foreach (self::$open as $w) {
            if ($w->id === $id) {
                return $w;
            }
        }
        return null;
    }

    private function stat(int $t0, DisplayList $list): void
    {
        $t1 = hrtime(true);
        $s = &$this->stats;
        if ($s['since'] === 0) {
            $s['since'] = $t1;
        }
        $s['frames']++; $s['ms'] += ($t1 - $t0) / 1e6;
        if ($t1 - $s['since'] >= 1e9) {
            $text = App::text();
            $gc = gc_status();
            printf("%3d frames this second | %5.2f ms/frame (%d pass%s) | %4d quads | %4d lines cached, %d glyphs, %d atlas resets | mem %.1f MB live, %.1f MB held | gc %d runs, %d freed | locals %d\n",
                $s['frames'], $s['ms'] / $s['frames'], $this->runtime->passes, $this->runtime->passes > 1 ? 'es' : '',
                $list->n, $text->lineCount(), $text->glyphCount(), $text->resets, memory_get_usage() / 1048576, memory_get_usage(true) / 1048576, $gc['runs'], $gc['collected'], $this->runtime->localCount());
            $s = ['frames' => 0, 'ms' => 0, 'since' => $t1];
        }
    }
}
