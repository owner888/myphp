<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\{Access, Dock, Drag, Drop, Metal, ObjC, Pasteboard, Screens, View, WebView};
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
    private const TITLED = 1, CLOSABLE = 2, MINIATURIZABLE = 4, RESIZABLE = 8, FULL_SCREEN = 1 << 14, FULL_SIZE_CONTENT = 1 << 15;
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
    /** The window's background (setBackgroundColor), and whether it is transparent (WindowOptions::$transparent). */
    private ?Background $background = null;
    private bool $transparent = false;
    private ?WebView $page = null;
    private string $secret = '';
    private bool $pageReady = false;
    /** @var list<\Closure> */
    private array $whenPageReady = [];
    public readonly int $id;
    public readonly ?Input $input;
    public readonly ?Runtime $runtime;
    private array $stats = ['frames' => 0, 'ms' => 0, 'since' => 0];
    /** @var array<string, list<\Closure>> listeners by event */
    private array $listeners = [];
    private string $stateKey = '';
    /** @var array<int, Channel> open channels by the page's id */
    private array $channels = [];
    public const EVENTS = ['close', 'closed', 'focus', 'blur', 'show', 'hide', 'readyToShow', 'resize', 'move', 'maximize', 'unmaximize', 'minimize', 'restore', 'enterFullScreen', 'leaveFullScreen', 'fileDrop'];
    /** @var array<int, Window> by content view address, for drops */
    private static array $byView = [];

    public function __construct(WindowOptions $o)
    {
        if (!App::isReady()) {
            throw new RuntimeException('create windows from App::whenReady()');
        }
        if ($o->content === null && $o->html === null && $o->url === null) {
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
            $this->runtime->openURL = App::openURL(...);
            $this->runtime->dragWindow = $this->dragWindow(...);
            $this->runtime->writeClipboard = Pasteboard::setText(...);
            $this->runtime->writeClipboardData = Pasteboard::writeData(...);
            $this->runtime->readClipboardData = Pasteboard::readData(...);
            $this->runtime->popupMenu = function (array $items, float $x, float $y, bool $rtl, \Closure $chosen): void {
                $wire = function (array $items) use (&$wire, $chosen): void { // each item reports its place
                    foreach ($items as $it) {
                        if ($it->type === MenuItem::SEPARATOR) { continue; }
                        if ($it->submenu !== []) { $wire($it->submenu); continue; }
                        $it->click = function (MenuItem $chosenItem) use ($chosen) { $chosen((int)$chosenItem->id); };
                    }
                };
                $wire($items);
                App::later(fn() => (new Menu($items))->popupAtPoint($this, $x, $y)); // after the frame, as mygo's host shows it after the event
            };
            $this->runtime->readClipboard = Pasteboard::text(...);
            $this->runtime->escape = $this->escape(...);
            $this->runtime->requestFrame = $this->invalidate(...); // a router's page changed from outside a frame
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
        $this->parent = $o->parent; $this->modal = $o->modal;
        $saved = $o->stateKey !== '' ? WindowState::get($o->stateKey) : null;
        if ($this->flyout !== null) {
            $this->createFlyout($o);
        } else {
            $this->createWindow($o, $saved);
        }
        $this->trafficLights = $o->trafficLightPosition;
        $this->setTitle($o->title ?? App::name());

        if ($o->content === null) {
            $this->secret = bin2hex(random_bytes(16));
            $this->page = WebView::create(ObjC::rect(0, 0, $o->width, $o->height),
                ['platform' => 'darwin', 'windowId' => $this->id, 'version' => App::VERSION, 'secret' => $this->secret],
                function (string $raw) {
                    if ($raw === '{"t":"dom-ready"}') {
                        $this->pageReady = true;
                        $this->readyToShow();
                        $this->dropChannels(); // a new page: the old one's channels are gone
                        $callbacks = $this->whenPageReady;
                        $this->whenPageReady = [];
                        foreach ($callbacks as $fn) {
                            $fn();
                        }
                        return;
                    }
                    if ($raw === '{"t":"escape"}') { // an Escape the page left (no handler called preventDefault)
                        $this->escape();
                        return;
                    }
                    $reply = Ipc::handle($raw, $this->secret, $this);
                    if ($reply !== null) {
                        $this->page->deliver($reply);
                    }
                }, $o->preload);
            $this->view = $this->page->ns;
            if ($o->html !== null) {
                $this->page->loadHTML($o->html);
            } else {
                $this->page->loadURL($o->url);
            }
        } else {
            $this->view = $this->nativeView($o);
            Metal::setClearColor($this->view, App::theme()->background);
            $view = $this->view;
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
        });
        if ($this->flyout !== null) {
            $this->placeFlyout();
        } elseif ($saved === null && $o->x === 0 && $o->y === 0) {
            $this->center();
        } elseif ($saved === null) {
            $b = $this->bounds();
            $this->setBounds(new Rectangle($o->x, $o->y, $b->width, $b->height));
        }
        if ($o->fullScreen) {
            ObjC::msg('v:p', $this->ns, 'toggleFullScreen:', null);
        } elseif ($o->maximized) {
            ObjC::msg('v:p', $this->ns, 'zoom:', null);
        }
        $this->maximized = $this->isMaximized();
        if (!$o->hidden) {
            $this->show();
        }
    }

    /** The NSWindow of a window (not a flyout), with its style, title bar and limits. */
    private function createWindow(WindowOptions $o, ?array $saved): void
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
            self::definePanels();
            $style |= self::NONACTIVATING; // from the start: the window server learns from the style it is created with that it never activates the app
        }
        $this->ns = ObjC::msg('p:rLLb', ObjC::alloc($this->panel ? 'MyPHPPanel' : 'NSWindow'), 'initWithContentRect:styleMask:backing:defer:', $content, $style, self::BACKING_BUFFERED, false);
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
        if ($saved !== null) {
            ObjC::msg('v:rb', $this->ns, 'setFrame:display:', ObjC::rect($saved['x'], $saved['y'], $saved['w'], $saved['h']), true);
        }
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
                $text->beginFrame();
                $list->pathQuad ??= $text->pathQuad(...);
                $this->runtime->scrollbarWidth = App::theme()->scrollbarWidth;
                $this->runtime->reduceMotion = App::reduceMotion();
                $this->runtime->windowFocus = $this->ns !== null && ObjC::msg('b:', $this->ns, 'isKeyWindow'); // a selection shows in the key window
                App::metal()->setDark(App::theme()->dark);
                $root = $this->runtime->frame(function () use ($content, $text, $bounds) {
                    $c = new Context($text, $this->runtime, $bounds->w, $bounds->h, App::theme(), App::scale());
                    $c->titleBar = $this->titleBar();
                    $c->vibrancy = $this->effect !== null || $this->popover !== null; // a popover draws its material
                    ($content->view)($c);
                    $c->toasts(); // over everything the view built
                    Layout::layout($c, $bounds->w, $bounds->h);
                    return $c->root;
                });
                $list->pushClip(0, 0, $bounds->w, $bounds->h); // what lies outside the window is not painted (mygo's visible)
                Painter::paint($root, $list, $text, App::theme(), $this->runtime);
                Painter::dragCopy($list, $text, App::theme(), $this->runtime); // a value dragged: its source's copy under the pointer
                Painter::tooltip($root, $list, $text, App::theme(), $this->runtime);
                if ($text->repaint) {
                    // An atlas started over during the paint: what was painted before points at slots that are gone.
                    $text->repaint = false;
                    $list->reset();
                    $list->pushClip(0, 0, $bounds->w, $bounds->h);
                    Painter::paint($root, $list, $text, App::theme(), $this->runtime);
                    Painter::dragCopy($list, $text, App::theme(), $this->runtime); // a value dragged: its source's copy under the pointer
                    Painter::tooltip($root, $list, $text, App::theme(), $this->runtime);
                }
                $this->showCursor($this->runtime->cursor);
                if ($this->runtime->needsFrame) {
                    Metal::requestFrame($view);
                }
                $wake = $this->runtime->nextWake();
                if ($wake !== null) {
                    View::wake($view, $wake);
                }
                if (getenv('MYPHP_STATS')) {
                    $this->stat($t0, $list);
                }
                $this->readyToShow(); // it drew its first frame
            });
    }

    /** The channel the page numbered $id, for a call just made (Ipc). */
    public function openChannel(int $id, string $secret): Channel
    {
        return $this->channels[$id] = new Channel($this, $id, $secret);
    }

    public function channel(int $id): ?Channel
    {
        return $this->channels[$id] ?? null;
    }

    public function forgetChannel(int $id): void
    {
        unset($this->channels[$id]);
    }

    /** The page went away: every channel is closed on its side. */
    private function dropChannels(): void
    {
        foreach ($this->channels as $ch) {
            $ch->closedByPage();
        }
        $this->channels = [];
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

    /** The window's web page, or null for native UI. */
    public function page(): ?WebView
    {
        return $this->page;
    }

    /** Sends an event to the window's page; nothing for native UI. */
    public function send(string $name, mixed $payload = null): void
    {
        $this->page?->deliver(['t' => 'event', 'n' => $name, 'p' => $payload]);
    }

    /**
     * Runs JavaScript in the page. With $then, it gets the script's value
     * as the page's description of it (a string, "42", null) and the error
     * message if it threw: $then(?string $value, ?string $error).
     */
    public function evaluate(string $js, ?\Closure $then = null): void
    {
        if ($this->page === null) {
            return;
        }
        if ($then === null) {
            $this->page->evaluate($js);
            return;
        }
        $this->page->evaluate($js, ObjC::block('pp', function ($result, $error) use ($then) {
            $then(
                $result === null ? null : ObjC::phpStr(ObjC::msg('p:', $result, 'description')),
                $error === null ? null : ObjC::phpStr(ObjC::msg('p:', $error, 'localizedDescription')),
            );
        }));
    }

    /** Calls $fn once the page's DOM is ready (at once if it already is). */
    public function whenPageReady(\Closure $fn): void
    {
        if ($this->pageReady) {
            $fn();
            return;
        }
        $this->whenPageReady[] = $fn;
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
                    $w->emit($event);
                    if ($event === 'resize' || $event === 'move') {
                        $w->captureState();
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
        if ($this->runtime !== null) { $this->cancelDataDrag(); Access::detach($this->view); View::forget($this->view); }
        $this->dropChannels();
        $this->captureState();
        WindowState::save();
        $this->emit('closed');
        if (self::$closingParents === 0 && self::$open === [] && App::$quitOnLastWindowClosed) {
            App::quit();
        }
    }

    /**
     * Calls $fn on $event, one of EVENTS: close (with a CloseEvent to
     * cancel), closed, focus, blur, resize, move, minimize, restore,
     * enterFullScreen, leaveFullScreen, fileDrop (with a FileDropEvent:
     * files dropped from other apps that no node took, Context::dropData
     * or droppedFiles).
     * Returns a function that removes it.
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

    /** Remembers the frame under the state key, while not full screen or minimized. */
    private function captureState(): void
    {
        if ($this->stateKey === '' || ObjC::msg('b:', $this->ns, 'isMiniaturized') || (ObjC::msg('L:', $this->ns, 'styleMask') & self::FULL_SCREEN)) {
            return;
        }
        $r = ObjC::msg('r:', $this->ns, 'frame');
        WindowState::set($this->stateKey, $r->x, $r->y, $r->w, $r->h);
    }

    private string $cursorShown = 'arrow';

    /** The pointer over the content: the arrow, or the hand over links. */
    private function showCursor(string $cursor): void
    {
        if ($cursor === $this->cursorShown) {
            return;
        }
        $this->cursorShown = $cursor;
        ObjC::msg('v:', ObjC::msg('p:', ObjC::cls('NSCursor'), match ($cursor) { 'pointer' => 'pointingHandCursor', 'ibeam' => 'IBeamCursor', 'resizeEW' => 'resizeLeftRightCursor', 'resizeNS' => 'resizeUpDownCursor', 'crosshair' => 'crosshairCursor', default => 'arrowCursor' }), 'set');
    }

    /** The theme changed: native UI clears to its background and redraws; a background of a light and a dark color shows the appearance's. */
    public function themeChanged(): void
    {
        $this->layoutTrafficLights(); // AppKit lays the title bar out again with the appearance
        if ($this->background !== null) {
            $this->applyBackground();
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

    /** The NSVisualEffectView materials of WindowOptions::$vibrancy, as mygo's (Windows 11's backdrops as their nearest). */
    private const MATERIALS = [
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
        return (ObjC::msg('L:', $this->ns, 'styleMask') & self::FULL_SCREEN) !== 0;
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
                parent: $o->parent, content: $o->content, html: $o->html, url: $o->url, preload: $o->preload));
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

    /** The panel classes: a panel (MyPHPPanel) takes the keyboard without activating the app and is never the main window; a flyout's (MyPHPFlyout) is the key window only when it takes the keyboard, its parent staying the main window. */
    private static function definePanels(): void
    {
        if (self::$panelsDefined) {
            return;
        }
        self::$panelsDefined = true;
        ObjC::defineClass('MyPHPPanel', 'NSPanel', [
            'canBecomeKeyWindow' => ['B@:', 'b(pp)', fn($self, $cmd) => true],
            'canBecomeMainWindow' => ['B@:', 'b(pp)', fn($self, $cmd) => false],
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
