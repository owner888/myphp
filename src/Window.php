<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\{FitContent, WindowStates};
use MyPHP\Transfer\{Data, DragOptions, Format, Operation, Result};
use MyPHP\UI\{Context, DataDragEvent, DisplayList, Image, Input, Layout, Painter, Runtime};
use RuntimeException;

/**
 * A window showing native UI or a web page. Create it from App::whenReady().
 * With native content, each frame runs the view function through the
 * window's Runtime, lays the tree out to the window's size and paints it;
 * frames happen on demand. With a page, the window holds a WebView whose
 * bridge routes calls to Ipc and events back. Window::flyout() makes a
 * flyout: a borderless panel next to a rectangle of its parent, or a tray
 * icon; WindowOptions::$panel a panel above all apps, as launchers are.
 * The backend's window (Platform\Window) does what the system does.
 */
final class Window
{
    /**
     * A flyout's placement (Window::flyout): its side and alignment
     * (Flyout's), gap, anchor and tray, whether it takes the keyboard, and
     * the size it is placed at; whether the window is a panel
     * (WindowOptions::$panel).
     * @var ?array{side: int, align: int, gap: int, anchor: Rectangle, tray: ?Tray, focusable: bool, popover: bool, width: int, height: int}
     */
    private ?array $flyout = null;
    /** The limits and last size of a window that takes its page's content's (FlyoutOptions::$fitContent, WindowOptions::$fitContent), null for others. */
    private ?FitContent $fit = null;
    private bool $panel = false;
    /** What Window::flyout hands the constructor. */
    private static ?array $creating = null;
    /** How many windows are closing their children: the last window to close is reported once they all are. */
    private static int $closingParents = 0;
    /** The parent window, of a child window (WindowOptions::$parent), and whether it is its sheet ($modal). */
    private ?Window $parent = null;
    private bool $modal = false;
    /** The title the app set, the file the window shows and whether it is edited (setRepresentedFile, setDocumentEdited). */
    private string $title = '', $representedFile = '';
    private bool $documentEdited = false;
    /** Whether the window was closed; whether it is maximized, as the last resize found; whether it drew, or its page got, ready to show. */
    private bool $destroyed = false, $maximized = false, $readyShown = false;
    /** Frames asked for while nothing of the window showed, drawn once some of it shows again (mygo's held). */
    private bool $held = false;
    /** What the view last asked of the window (answer): whether the traffic lights show, the aspect it keeps. */
    private ?bool $buttonsAsked = null;
    /** What the view asked of full screen: the legacy one (IINA's "Use legacy full screen"), the other screens blacked out in it (its "Black out other monitors"). */
    private bool $legacyAsked = false, $blackOutAsked = false;
    private ?float $aspectAsked = null;
    /** The content's zoom the last frame drew (Context::setZoom): App::rescale rasterizes glyphs at the screen's scale times it. */
    private float $zoomDrawn = 1.0;
    /** The root of the view the last frame built, which a frame that only paints again paints (mygo's c.root). */
    private ?UI\Node $drawnRoot = null;
    /** The compact size the view asked for (a player's music mode): [width, height, least height, most height (0 none)], null for none; and what the window was before it, to go back to. */
    private ?array $compactAsked = null, $beforeCompact = null, $beforeFixed = null;
    private bool $fixedAsked = false;
    /** Whether the view asked the window to float on top (a player's pin, IINA's), null before it asked. */
    private ?bool $onTopAsked = null;
    /** The window's background (setBackgroundColor). */
    private ?Background $background = null;
    private ?Page $page = null;
    /** @var list<WebView> the web views of native UI (newWebView) */
    private array $webViews = [];
    /** The WKWebViewConfiguration WebKit hands over for window.open(), while Window::opened() makes the window. */
    private static $opening = null;
    /** @var array<int, Window> the open windows by id */
    private static array $open = [];
    private static int $nextId = 1;

    /** The backend's window. */
    private Platform\Window $native;
    public readonly int $id;
    public readonly ?Input $input;
    public readonly ?Runtime $runtime;
    private array $stats = ['frames' => 0, 'ms' => 0, 'since' => 0];
    /** The statistics of each frame MYPHP_FRAME_STATS asks for, or null (mygo's frameStats). */
    private ?UI\FrameStats $frameStats = null;
    /** @var array<string, list<\Closure>> listeners by event */
    private array $listeners = [];
    private string $stateKey = '';
    /** The last change of the window's state captured, which stateChanged() waits to settle for. */
    private int $stateChange = 0;
    public const EVENTS = ['close', 'closed', 'focus', 'blur', 'show', 'hide', 'readyToShow', 'resize', 'move', 'maximize', 'unmaximize', 'minimize', 'restore', 'enterFullScreen', 'leaveFullScreen', 'fileDrop'];
    /** The NSVisualEffectView materials of WindowOptions::$vibrancy, as mygo's. */
    public const MATERIALS = Platform\Darwin\Window::MATERIALS;

    public function __construct(WindowOptions $o)
    {
        if (!App::isReady()) {
            throw new RuntimeException('create windows from App::whenReady()');
        }
        if ($o->content === null && $o->html === null && $o->url === null && $o->page->sessionState === null && self::$opening === null) {
            throw new RuntimeException('WindowOptions needs content, html, url or a page session state');
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
            $this->runtime->doubleClickInterval = App::backend()->doubleClickInterval();
            $this->runtime->dragWindow = fn(int $clicks) => $this->native->dragWindow($clicks);
            $this->runtime->writeClipboard = fn(string $text) => App::backend()->clipboard()->writeText($text);
            $this->runtime->writeClipboardData = fn(Data $data, ?\Closure $onRelease = null) => App::backend()->clipboard()->writeData($data, $onRelease);
            $this->runtime->readClipboardData = fn(string ...$formats): Data => $formats === [] ? new Data : App::backend()->clipboard()->readData($formats);
            $this->runtime->clipboardFormats = fn() => App::backend()->clipboard()->formats();
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
            $this->runtime->readClipboard = fn() => App::backend()->clipboard()->readText();
            $this->runtime->escape = $this->escape(...);
            $this->runtime->requestFrame = $this->invalidate(...); // a router's page changed from outside a frame
            $this->runtime->showWindow = function (bool $on): void {
                if (!$on) { $this->hide(); return; }
                $this->show();
                $this->native->placeVideos(); // a video given back (picture in picture) in its place now, as the last frame placed it: the system animates into it before a frame comes
            };
            $this->runtime->window = \WeakReference::create($this);
            $this->runtime->minimizeWindow = function (bool $on): void { $on ? $this->minimize() : $this->restore(); }; // a player's window going to the Dock as its film goes to picture in picture, IINA's
            $this->runtime->startDataDrag = function (Data $data, mixed $local, DragOptions $o, float $x, float $y): ?\Throwable {
                try {
                    $this->dragData($data, $local, $o, $x, $y);
                    return null;
                } catch (\InvalidArgumentException|RuntimeException $e) {
                    return $e; // what is wrong with it: the source is told
                }
            };
            $this->runtime->cancelDataDrag = $this->cancelDataDrag(...);
            $this->runtime->setDropFormats = fn(array $formats) => $this->native->setDropFormats($formats);
            $this->runtime->snapshot = fn(float $x, float $y, int $w, int $h): Image => $this->native->snapshot($x, $y, $w, $h);
            $this->runtime->underFrame = fn(): ?array => $this->native->underFrame(); // a player's subtitles, for its screenshots
        }

        $this->stateKey = $o->stateKey;
        if ($this->runtime !== null && $o->page->devTools->on()) {
            $this->runtime->inspector = new UI\Inspector(); // F12, Alt+Cmd+I and the menu's Toggle Developer Tools open it
        }
        $this->parent = $o->parent; $this->modal = $o->modal;
        $saved = $o->stateKey !== '' ? WindowStates::get($o->stateKey) : null;
        if ($saved !== null && ($saved->width <= 0 || $saved->height <= 0)) { $saved = null; }
        $this->native = App::backend()->newWindow($this, $o, $this->panel);
        $this->setTitle($o->title ?? App::name());

        if ($o->content === null) {
            $this->fit = $this->flyout !== null ? $this->flyout['fit'] : ($o->fitContent ? new FitContent($o->minWidth, $o->minHeight, $o->maxWidth, $o->maxHeight) : null);
            $opening = self::$opening;
            self::$opening = null;
            $this->page = new Page($this, null, $o->width, $o->height, $o->page, $opening);
            $this->native->setContent($this->page->web, $o);
            if ($opening === null && $o->html !== null) {
                $this->page->loadHTML($o->html);
            } elseif ($opening === null) {
                $this->page->loadFirst($o->page->sessionState, $o->url ?? ''); // WebKit loads window.open()'s itself
            }
        } else {
            $content = $o->content;
            $after = null;
            if (($this->frameStats = UI\FrameStats::fromEnv()) !== null) {
                $after = function (): void {
                    $this->frameStats->lap(UI\FrameStats::PRESENT);
                    $this->frameStats->end($this->runtime->frameNumber, App::text()->textSystem()->made, $this->native->lastDraw());
                };
            }
            $this->runtime->underLayer = true; // nodes belowMaterials() painted in a view of their own, under the materials
            $this->native->createSurface($o, fn(DisplayList $list, float $w, float $h) => $this->frame($content, $list, $w, $h), $after);
        }
        if (!$o->transparent && $o->backgroundColor !== '') {
            $this->setBackgroundColor($o->backgroundColor);
        }
        self::$open[$this->id] = $this;
        [$fullScreen, $maximized] = [$o->fullScreen, $o->maximized];
        if ($this->flyout !== null) {
            $this->placeFlyout();
        } elseif ($saved !== null) { // where the state key left it, as long as it shows on a display; else at its size, as far as the display allows, centered (mygo's restoreWindowState)
            $r = new Rectangle($saved->x, $saved->y, $saved->width, $saved->height);
            $this->native->restoreFrame($r, WindowStates::onDisplay($r, array_map(fn(Display $d) => $d->workArea, Screen::displays())));
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
            $this->native->startFullScreen();
        } elseif ($maximized) {
            $this->native->startMaximized();
        }
        $this->maximized = $this->isMaximized();
        if (!$o->hidden) {
            $this->show();
        } elseif ($this->runtime !== null) {
            $this->invalidate(); // its first frame draws while it is hidden, which makes it ready to show (mygo 0cb6d05)
        }
        App::windowCreated($this);
    }

    /**
     * Draws a frame of native UI into $list, at the content's size of $width
     * by $height points (mygo's engine frame): the view built and laid out,
     * or only its drawings painted again when they alone moved; then the
     * web views, videos and materials placed, the cursor, what the view
     * asked of the window, and the next frame asked for.
     */
    private function frame(UI\Content $content, DisplayList $list, float $width, float $height): void
    {
        $text = App::text();
        $n = $this->native;
        $t0 = hrtime(true);
        $this->frameStats?->begin($text->textSystem()->made);
        $text->beginFrame();
        $list->pathQuad ??= $text->pathQuad(...);
        $this->runtime->scrollbarWidth = App::theme()->scrollbarWidth;
        $this->runtime->preferences = App::preferences();
        $this->runtime->reduceMotion = $this->runtime->preferences->reduceMotion;
        $this->runtime->rightToLeft = I18n::direction(App::locale()) === Dir::RightToLeft; // the direction of the language the app shows
        $this->runtime->setWindowFocus($n->isKey()); // a selection shows in the key window
        $in = $this->runtime->inspector;
        if ($in !== null && $in->open) { $in->hz = $n->refreshRate(); }
        $this->runtime->fullScreen = $this->isFullScreen();
        $this->runtime->occluded = !$n->showing();
        $at = [$width, $height, App::scale()];
        $repaint = $this->drawnRoot !== null && $n->repaintAsked() && $this->runtime->mayRepaint($at);
        if ($repaint) { // only drawings moved since the last frame: its nodes painted again, the view neither built nor laid out (mygo's repaintFrame)
            $this->runtime->beginRepaint();
            $root = $this->drawnRoot;
        } else {
            $root = $this->drawnRoot = $this->runtime->frame(function () use ($content, $text, $width, $height, $in) {
                $in?->startPass();
                $z = $this->runtime->frameZoom; // zoomed (Context::setZoom), laid out in a window 1/z its size
                [$w, $h] = [$width / $z, $height / $z];
                $c = new Context($text, $this->runtime, $in?->contentWidth($w) ?? $w, $h, App::theme(), App::scale());
                $c->titleBar = array_map(fn(float $v): float => $v / $z, $this->titleBar()); // the room the controls take, in the content's points
                $c->vibrancy = $this->native->hasVibrancy(); // a popover draws its material
                ($content->view)($c);
                $c->toasts(); // over everything the view built
                if ($in !== null && $in->open) { UI\InspectorPanel::build($c, $this->runtime, $in, $w, $h); $in->lap(0); } // beside the content
                $this->frameStats?->lap(UI\FrameStats::BUILD);
                Layout::layout($c, $w, $h);
                $this->frameStats?->lap(UI\FrameStats::LAYOUT);
                if ($in !== null && $in->open) { $in->lap(1); $in->snapshot($this->runtime, $c->root); }
                return $c->root;
            });
            $this->runtime->builtAt($at);
        }
        $z = $this->runtime->frameZoom;
        if ($z !== $this->zoomDrawn) { // glyphs rasterized at the screen's scale times the zoom: as sharp as at that size
            $this->zoomDrawn = $z;
            App::rescale();
        }
        $list->zoom = $z; // drawn at the window's scale times it, in a viewport 1/z the window's
        $list->pixels = $n->backingScale(); // its fills on whole pixels
        $paint = function () use ($root, $list, $text, $width, $height, $in, $z): void {
            $this->runtime->webPlaces = []; $this->runtime->videoPlaces = []; $this->runtime->materialPlaces = []; $this->runtime->underPaints = [];
            $list->pushClip(0, 0, $width / $z, $height / $z); // what lies outside the window is not painted (mygo's visible)
            $list->cull = true; // where the nodes are where the runtime recorded them
            $this->runtime->painting = true; // what drawings ask for now are frames that only paint again
            Painter::beginParts($this->runtime, $list);
            try {
                Painter::paint($root, $list, $text, App::theme(), $this->runtime);
            } finally {
                $list->cull = false;
                $this->runtime->painting = false;
            }
            if ($in !== null && $in->open) { UI\InspectorOverlay::paint($list, $text, $this->runtime, $in, $height / $z); }
            Painter::dragCopy($list, $text, App::theme(), $this->runtime); // a value dragged: its source's copy under the pointer
            Painter::tooltip($root, $list, $text, App::theme(), $this->runtime);
            Painter::endParts($this->runtime, $list);
        };
        $parts = false;
        if ($repaint) { // only the drawings that asked painted again, the rest of the last frame as it was (mygo's repaintParts)
            $this->runtime->painting = true;
            try {
                $parts = Painter::repaintParts($this->runtime, $list, $text);
            } finally {
                $this->runtime->painting = false;
            }
        }
        if (!$parts) {
            $paint();
        }
        if ($text->repaint) {
            // An atlas started over during the paint: what was painted before points at slots that are gone.
            $text->repaint = false;
            $list->reset();
            $paint();
        }
        $in?->lap(2);
        if ($this->frameStats !== null) { $this->frameStats->passes = $this->runtime->passes; $this->frameStats->lap(UI\FrameStats::PAINT); }
        $n->placeWebViews();
        if (!$repaint) {
            $n->showCursor($this->runtime->cursor);
            $this->answer($this->runtime->windowAsks);
        }
        if ($this->destroyed) { return; } // closed by its own frame (its view opened a film elsewhere, and its window went): no frame after it
        $every = $this->runtime->pace($n->displayRate());
        $wake = $this->runtime->nextWake();
        if (($every !== null || $wake !== null) && !$n->showing()) {
            // Nothing of the window shows (covered, minimized, on another space): what moves waits until some of it
            // does (shownAgain) rather than draw frames nobody sees, as browsers pause the
            // animation frames of windows out of sight (mygo's held). It goes on from where the time puts it.
            $this->held = true;
        } elseif ($every !== null) {
            $n->requestFrame($every, $this->runtime->repaints()); // at a drawing's rate (Painter::animationFrameRate), every so many refreshes; painting again alone when only drawings asked
        } elseif ($wake !== null) {
            $n->wake($wake, $this->runtime->wakeRepaints());
        }
        if (getenv('MYPHP_STATS')) {
            $this->stat($t0, $list);
        }
        $this->readyToShow(); // it drew its first frame
    }

    /**
     * The room the window controls take in a window with a hidden title
     * bar (WindowOptions::$titleBarStyle), as mygo's Context.TitleBar:
     * height, left (to the right edge of the zoom button) and right, in
     * points; all zero in other windows, in full screen, where the
     * controls hide, and with WindowOptions::$hideWindowButtons. The view keeps clear of them and drags the window by
     * its own title bar (Node::dragWindow).
     * @return array{height: float, left: float, right: float}
     */
    public function titleBar(): array
    {
        return $this->native->titleBar();
    }

    /** @internal the backend's window */
    public function native(): Platform\Window
    {
        return $this->native;
    }

    /** @internal the flyout's placement, null for other windows */
    public function flyoutState(): ?array
    {
        return $this->flyout;
    }

    /** @internal whether the window is its parent's sheet (WindowOptions::$modal) */
    public function isModal(): bool
    {
        return $this->modal;
    }

    /** @internal the web views of native UI @return list<WebView> */
    public function webViewList(): array
    {
        return $this->webViews;
    }

    /** @internal whether the view asked for the other screens blacked out in full screen (IINA's) */
    public function blackOutAsked(): bool
    {
        return $this->blackOutAsked;
    }

    /**
     * @internal the backend tells what happened to the window (mygo's
     * WindowHandler): focus, blur, resize, move, minimize, restore,
     * enterFullScreen or leaveFullScreen; the listeners hear it, and the
     * view's own.
     */
    public function nativeEvent(string $event): void
    {
        if ($event === 'resize' && ($z = $this->isMaximized()) !== $this->maximized) {
            $this->maximized = $z;
            $this->emit($z ? 'maximize' : 'unmaximize');
        }
        if ($event === 'resize' || $event === 'move') {
            $this->placeFlyouts(); // they stay next to their anchors, in the work area
        }
        if ($event === 'enterFullScreen' || $event === 'leaveFullScreen') {
            $this->page?->sendTitleBar(); // the controls hide in full screen
            $this->fullScreenChanged($event);
        }
        $this->emit($event);
        foreach ($this->runtime?->windowListeners ?? [] as $listen) { $listen($event); } // the view's own (a player pausing as its window is minimized, IINA's)
        if (in_array($event, ['resize', 'move', 'minimize', 'restore', 'enterFullScreen', 'leaveFullScreen'], true)) {
            $this->stateChanged();
        }
        if ($event === 'focus' || $event === 'blur') {
            $this->invalidate(); // a selection shows again, or stops showing
        }
        if ($event === 'blur' && $this->flyout !== null && !($this->flyout['tray']?->pressed() ?? false)) {
            App::later($this->dismissFlyout(...)); // once the system made another window key, or none; a press on its tray icon toggles it instead
        }
    }

    /** @internal some of the window shows again (mygo's SurfaceShown): what moves goes on from now, and what was held draws */
    public function shownAgain(): void
    {
        if ($this->runtime !== null) { $this->runtime->shownAt = hrtime(true); } // what moves goes on from now (mygo's engine.shown)
        if ($this->held) {
            $this->held = false; // some of it shows again: what moved goes on (mygo's SurfaceShown)
            $this->invalidate();
        }
    }

    /** @internal into or out of full screen ($event): told as the system's own is (the legacy one, and out of it) */
    public function fullScreenChanged(string $event): void
    {
        if ($this->native->isLegacyFullScreen() || $event === 'leaveFullScreen') {
            $this->emit($event);
            foreach ($this->runtime?->windowListeners ?? [] as $listen) { $listen($event); }
            $this->stateChanged();
            $this->invalidate();
        }
    }

    /** @internal files from another app dropped on the window that no node took: the 'fileDrop' listeners, and a trusted page */
    public function filesDropped(array $paths, float $x, float $y): void
    {
        $e = new FileDropEvent($this, $paths, $x, $y);
        foreach ($this->listeners['fileDrop'] ?? [] as $fn) {
            $fn($e);
        }
        $this->page?->filesDropped($paths, $x, $y);
    }

    /** @internal asks the 'close' listeners whether the window closes, as the user closing it */
    public function shouldClose(): bool
    {
        $e = new CloseEvent($this);
        foreach ($this->listeners['close'] ?? [] as $fn) {
            $fn($e);
        }
        return !$e->canceled;
    }

    /** @internal the window closed (mygo's Closed): its children close with it, and the app quits after the last window when it should */
    public function closed(): void
    {
        if ($this->destroyed) {
            return;
        }
        unset(self::$open[$this->id]);
        $this->destroyed = true;
        self::$closingParents++; // its children close with it; the last window is reported once they all are
        foreach (self::all() as $child) {
            if ($child->parent === $this) { $child->destroy(); }
        }
        self::$closingParents--;
        foreach ($this->runtime?->windowListeners ?? [] as $listen) { $listen('closed'); } // the view's own (a player's plugins unloaded)
        if ($this->runtime !== null) { $this->cancelDataDrag(); }
        $this->native->release();
        $this->page?->destroy(); // the page goes with the window, its web views' too
        foreach ($this->webViews as $v) { $v->destroy(); }
        $this->captureState();
        WindowStates::save();
        $this->emit('closed');
        if (self::$closingParents === 0 && self::$open === []) {
            App::lastWindowClosed();
        }
    }

    /** The theme changed: native UI clears to its background and redraws; a background of a light and a dark color shows the appearance's. */
    public function themeChanged(): void
    {
        $this->native->themeChanged();
        if ($this->background !== null) {
            $this->native->setBackgroundColor($this->background->for(App::theme()->dark));
        }
        foreach ($this->webViews as $v) {
            $v->applyBackground();
        }
        if ($this->page === null) {
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
        $this->native->setBackgroundColor($this->background->for(App::theme()->dark));
    }

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
        $this->native->setVibrancy($material);
    }

    /** Asks for a frame after state changed outside the view function (native UI, mygo's Invalidate). */
    /** Whether some of the window shows on screen: not hidden, minimized, on another space or covered by other windows, which still get frames (mygo's surface visible). */
    /**
     * Does what the view asked of the window this frame (Runtime::windowAsks),
     * once for each change: closed; its title; the traffic lights shown or hidden; the aspect
     * kept as the user resizes it, the window first made of that shape, its
     * width kept; its size kept, then its limits given back (fixedSize,
     * before the aspect, which resizes it); full screen or not. Resizing redraws the window at once
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
        if (array_key_exists('fixedSize', $asks) && $asks['fixedSize'] !== $this->fixedAsked) { // a player's interactive mode, IINA's windowWillResize: the size it has, kept; its limits given back after
            $on = $this->fixedAsked = (bool)$asks['fixedSize'];
            App::later(function () use ($on): void {
                if ($this->destroyed) { return; }
                if ($on) {
                    $this->beforeFixed ??= $this->native->sizeLimits();
                    [$fw, $fh] = $this->native->frameSize(); // the limits are of the frame
                    $this->setMinimumSize((int)round($fw), (int)round($fh));
                    $this->setMaximumSize((int)round($fw), (int)round($fh));
                } elseif ($this->beforeFixed !== null) {
                    [$min, $max] = $this->beforeFixed;
                    $this->beforeFixed = null;
                    $this->setMinimumSize(...$min);
                    $this->setMaximumSize(...$max);
                }
            });
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
        if (array_key_exists('compact', $asks) && $asks['compact'] !== $this->compactAsked) { // a player's music mode, IINA's mini player: its own size, the window's before it given back
            $to = $this->compactAsked = $asks['compact'];
            App::later(function () use ($to): void {
                if ($this->destroyed) { return; }
                if ($to === null) {
                    if ($this->beforeCompact === null) { return; }
                    [$bounds, $min, $max] = $this->beforeCompact;
                    $this->beforeCompact = null;
                    $this->setMinimumSize(...$min);
                    $this->setMaximumSize(...$max);
                    $this->setContentBounds($bounds);
                    return;
                }
                if ($this->beforeCompact === null) {
                    $this->beforeCompact = [$this->contentBounds(), ...$this->native->sizeLimits()];
                }
                [$w, $h, $least, $most] = $to;
                $this->setMinimumSize($w, $least);
                $this->setMaximumSize($w, $most);
                $b = $this->contentBounds();
                if ($b->width != $w || $b->height != $h) { $this->setContentBounds(new Rectangle($b->x, $b->y, $w, $h)); } // its top left where it is
            });
        }
        if (isset($asks['fitVideo'])) { // a film opened: the window to its size, as IINA's settings say
            $fit = $asks['fitVideo'];
            App::later(function () use ($fit): void { if (!$this->destroyed && !$this->isFullScreen()) { $this->native->fitVideo($fit); } });
        }
        if (isset($asks['magnify']) && !$this->isFullScreen()) { $this->native->magnify((float)$asks['magnify']); } // a player pinched: its size by the pinch
        if (array_key_exists('firstMouse', $asks)) { $this->native->setAcceptsFirstMouse((bool)$asks['firstMouse']); } // a player's: the click that brings the window forward clicks too, as IINA's setting says
        if (array_key_exists('onTop', $asks) && $asks['onTop'] !== $this->onTopAsked) { // a player floating on top as it plays, IINA's
            $this->onTopAsked = (bool)$asks['onTop'];
            $this->setAlwaysOnTop($this->onTopAsked);
        }
        $this->legacyAsked = (bool)($asks['legacyFullScreen'] ?? $this->legacyAsked);
        $this->blackOutAsked = (bool)($asks['blackOut'] ?? $this->blackOutAsked);
        if (array_key_exists('fullScreen', $asks) && $asks['fullScreen'] !== $this->isFullScreen()) {
            $on = $asks['fullScreen'];
            $legacy = $this->legacyAsked || $this->native->isLegacyFullScreen(); // the way it went in, it comes out
            App::later(function () use ($on, $legacy): void {
                if ($this->destroyed) { return; }
                $legacy ? $this->native->setLegacyFullScreen($on) : $this->setFullScreen($on);
            });
        }
    }

    /** Asks for a frame after state changed outside the view function (native UI, mygo's Invalidate). */
    public function invalidate(): void
    {
        if ($this->page === null && !$this->destroyed) {
            $this->native->requestFrame();
        }
    }

    /** Closes the window without asking its 'close' listeners. */
    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->native->close();
    }

    /** Shows the window and gives it the keyboard; a modal child shows as a sheet of its parent, another stays above it; a flyout takes the keyboard only when focusable, and a panel or a flyout never activates the app. */
    public function show(): void
    {
        $visible = $this->isVisible();
        $this->native->show();
        $this->invalidate();
        if (!$visible) {
            $this->emit('show');
        }
    }

    /** Shows the window without giving it the keyboard. */
    public function showInactive(): void
    {
        $visible = $this->isVisible();
        $this->native->showInactive();
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
        $this->native->hide();
        $this->emit('hide');
    }

    public function isVisible(): bool
    {
        return $this->native->isVisible();
    }

    /** Gives the window the keyboard, and the app with it; a panel takes it without activating the app, a flyout shows (the keyboard only when focusable). */
    public function focus(): void
    {
        if ($this->flyout !== null) {
            $this->show();
            return;
        }
        $this->native->focus();
    }

    /** Sends the window behind the others. */
    public function blur(): void { $this->native->blur(); }

    /** Whether the window has the keyboard. */
    public function isFocused(): bool { return $this->native->isFocused(); }

    public function minimize(): void { $this->native->minimize(); }

    public function isMinimized(): bool { return $this->native->isMinimized(); }

    /** Zooms the window to fill the screen (macOS's maximize). */
    public function maximize(): void { $this->native->maximize(); }

    public function unmaximize(): void { $this->native->unmaximize(); }

    /** Whether the window is zoomed to fill the screen, not in full screen. */
    public function isMaximized(): bool { return $this->native->isMaximized(); }

    /** Brings a minimized window back. */
    public function restore(): void { $this->native->restore(); }

    public function setFullScreen(bool $on): void { $this->native->setFullScreen($on); }

    public function isFullScreen(): bool { return $this->native->isFullScreen(); }

    /** Moves the window to the center of its screen. */
    public function center(): void { $this->native->center(); }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->native->setTitle($title);
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
        $this->native->setRepresentedFile($path);
    }

    /** Tells whether the window's document has unsaved changes: a dot in the close button, and the file's icon dimmed. */
    public function setDocumentEdited(bool $edited): void
    {
        if ($this->documentEdited === $edited) {
            return;
        }
        $this->documentEdited = $edited;
        $this->native->setDocumentEdited($edited);
    }

    /** Moves and resizes the window. */
    public function setBounds(Rectangle $r): void { $this->native->setBounds($r); }

    /** Where the window is, and its size. */
    public function bounds(): Rectangle { return $this->native->bounds(); }

    /** Moves and resizes the window so that its content has bounds $r. */
    public function setContentBounds(Rectangle $r): void { $this->native->setContentBounds($r); }

    /** Where the window's content is, and its size. */
    public function contentBounds(): Rectangle { return $this->native->contentBounds(); }

    /** How small the user can make the window; 0 for no limit. */
    public function setMinimumSize(int $width, int $height): void { $this->native->setMinimumSize($width, $height); }

    /** How large the user can make the window; 0 for no limit. */
    public function setMaximumSize(int $width, int $height): void { $this->native->setMaximumSize($width, $height); }

    /** Keeps the content's width over its height at $ratio as the user resizes the window (Electron's setAspectRatio); 0 lets it go. */
    public function setAspectRatio(float $ratio): void { $this->native->setAspectRatio($ratio); }

    public function setResizable(bool $on): void { $this->native->setResizable($on); }
    public function isResizable(): bool { return $this->native->isResizable(); }
    public function setMovable(bool $on): void { $this->native->setMovable($on); }
    public function isMovable(): bool { return $this->native->isMovable(); }
    /** Whether the view fills the window under its title bar (NSWindow's fullSizeContentView): a player's plugin's standalone window's property. */
    public function setFullSizeContentView(bool $on): void { $this->native->setFullSizeContentView($on); }
    /** Hides the title bar's look and title, the window moved by its background, or shows them again (IINA's hideTitleBar of a plugin's standalone window). */
    public function setTitleBarHidden(bool $on): void { $this->native->setTitleBarHidden($on); }
    public function setMinimizable(bool $on): void { $this->native->setMinimizable($on); }
    public function isMinimizable(): bool { return $this->native->isMinimizable(); }
    /** Whether the zoom button can maximize the window. */
    public function setMaximizable(bool $on): void { $this->native->setMaximizable($on); }
    public function isMaximizable(): bool { return $this->native->isMaximizable(); }
    public function setClosable(bool $on): void { $this->native->setClosable($on); }
    public function isClosable(): bool { return $this->native->isClosable(); }

    /** Keeps the window above the other windows. */
    public function setAlwaysOnTop(bool $on): void { $this->native->setAlwaysOnTop($on); }

    public function isAlwaysOnTop(): bool { return $this->native->isAlwaysOnTop(); }

    /** The window's opacity, from 0 to 1. */
    public function setOpacity(float $v): void { $this->native->setOpacity($v); }

    public function opacity(): float { return $this->native->opacity(); }

    public function setHasShadow(bool $on): void { $this->native->setHasShadow($on); }
    public function hasShadow(): bool { return $this->native->hasShadow(); }

    /** Shows or hides the traffic lights (Electron's setWindowButtonVisibility): a player hides them with its controls. */
    public function setWindowButtonVisibility(bool $visible): void { $this->native->setWindowButtonVisibility($visible); }

    /** Lets the pointer through the window, to what is under it. */
    public function setIgnoreMouseEvents(bool $on): void { $this->native->setIgnoreMouseEvents($on); }

    /** Keeps the window's content out of screenshots and screen recordings. */
    public function setContentProtection(bool $on): void { $this->native->setContentProtection($on); }

    /** Draws the user's attention to the window, or stops: the Dock icon bounces while the app is not active. */
    public function flashFrame(bool $flash): void { $this->native->flashFrame($flash); }

    /**
     * Shows the window on every Space, or on the current one only. A
     * window that cannot go full screen (WindowOptions::$disableFullScreen)
     * shows over other apps' full-screen Spaces too, as an auxiliary.
     */
    public function setVisibleOnAllWorkspaces(bool $on): void { $this->native->setVisibleOnAllWorkspaces($on); }

    /** The native window (NSWindow), for native code that needs it (mygo's NativeHandle). */
    public function nativeHandle()
    {
        return $this->native->handle();
    }

    /** @internal a press on a drag region of the page ($clicks 1) moves the window, a double click ($clicks 2) zooms it as macOS is set to */
    public function pageDrag(int $clicks): void
    {
        $clicks === 2 ? $this->native->dragWindow(2) : $this->native->pageDrag();
    }

    /** @internal whether the window hides its title bar, keeping the controls (WindowOptions::$titleBarStyle) */
    public function hasHiddenTitleBar(): bool
    {
        return $this->native->hasHiddenTitleBar();
    }

    /**
     * @internal a command of the Format menu's roles (mygo's contentCommand):
     * on native UI, the node taking the keyboard's input runs it, as a
     * rich text editor its selection; on a page, the editable one does
     */
    public function contentCommand(string $cmd): void
    {
        if ($this->page === null) {
            $this->native->command($cmd);
            return;
        }
        $exec = ['bold' => 'bold', 'italic' => 'italic', 'underline' => 'underline', 'strikethrough' => 'strikeThrough',
            'alignLeft' => 'justifyLeft', 'alignCenter' => 'justifyCenter', 'alignRight' => 'justifyRight'][$cmd];
        $this->page->web->evaluate("document.execCommand(\"$exec\")");
    }

    /** A PNG of what the window shows (mygo's CapturePage): its page as it shows, or its native UI as it is. */
    public function capturePage(): string
    {
        return $this->page !== null ? $this->page->capture() : $this->native->capture();
    }

    /** @internal the native view of the content, which takes the keyboard back from a web view */
    public function contentView()
    {
        return $this->native->contentView();
    }

    /** Places a flyout where it goes now: Flyout::place in the work area of the display of its anchor, the tray icon's or its parent's content's. */
    private function placeFlyout(): void
    {
        if ($this->flyout === null || $this->destroyed) {
            return;
        }
        if ($this->native->hasPopover()) {
            $this->native->placePopover();
            return;
        }
        $f = $this->flyout;
        $displays = self::displays();
        $anchor = $f['anchor'];
        if ($f['tray'] !== null) {
            $anchor = $f['tray']->bounds();
            if ($anchor->width === 0 || $anchor->height === 0) { // the icon shows in no menu bar: in the middle of the primary display
                $this->native->setBounds(Flyout::centered($f['width'], $f['height'], Flyout::primaryWorkArea($displays)));
                return;
            }
        } elseif ($this->parent !== null && !$this->parent->destroyed) {
            $c = $this->parent->contentBounds();
            $anchor = new Rectangle($anchor->x + $c->x, $anchor->y + $c->y, $anchor->width, $anchor->height);
        }
        [$r] = Flyout::place($f['side'], $f['align'], $f['gap'], $anchor, $f['width'], $f['height'], Flyout::workAreaFor($anchor, $displays));
        $this->native->setBounds($r);
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
        return App::running() ? App::backend()->focusedWindow() : null;
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

    /** The zoom of the window's content its last frame drew (Context::setZoom), 1 for none. @internal App::rescale */
    public function contentZoom(): float
    {
        return $this->zoomDrawn;
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
        self::$creating = ['side' => $side, 'align' => $align, 'gap' => $o->gap, 'anchor' => $o->anchor ?? new Rectangle(0, 0, 0, 0), 'tray' => $o->tray, 'focusable' => $o->focusable, 'popover' => $o->popover, 'width' => $width, 'height' => $height, 'radius' => $o->cornerRadius,
            'fit' => $o->fitContent ? new FitContent($o->minWidth, $o->minHeight, $o->maxWidth, $o->maxHeight) : null];
        try {
            return new self(new WindowOptions(width: $width, height: $height, useContentSize: true, hidden: $o->hidden, frameless: true,
                disableResize: true, disableMinimize: true, disableMaximize: true, disableFullScreen: true, disableShadow: !$o->shadow, transparent: true, vibrancy: $o->vibrancy,
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

    /** @internal Page: the limits of a window that takes its page's content's size, null for others */
    public function fitContent(): ?FitContent
    {
        return $this->fit;
    }

    /**
     * @internal Page: sizes a window that takes its page's size to its
     * content's $c at its page's $zoom, as its bridge told it, unless that
     * changes nothing (mygo's fitContentSize). A flyout is placed again at
     * it, as setSize does. A window keeps in the work area of its display,
     * and changes only in the dimension its content changed in: a size the
     * user gave it holds until its content changes again; not while
     * maximized or in full screen.
     * @param array{w: float, h: float, sw: float, sh: float} $c
     */
    public function fitContentSize(array $c, float $zoom): void
    {
        $f = $this->fit;
        if ($f === null || $this->destroyed) {
            return;
        }
        if ($this->flyout !== null) {
            $s = $f->fit($c, $zoom);
            if ($s === null) { return; }
            $f->content = $c;
            if ($s === $f->size) { return; }
            $f->size = $s;
            if ($s !== [$this->flyout['width'], $this->flyout['height']]) { $this->setFlyoutSize(...$s); }
            return;
        }
        $bounds = $this->bounds(); $content = $this->contentBounds();
        [$maxW, $maxH] = [0, 0];
        $wa = Flyout::workAreaFor($bounds, self::displays());
        if ($wa->width > 0 && $wa->height > 0) {
            $maxW = max($wa->width - ($bounds->width - $content->width), 1);
            $maxH = max($wa->height - ($bounds->height - $content->height), 1);
        }
        $s = $f->fit($c, $zoom, $maxH);
        if ($s === null) { return; }
        $f->content = $c;
        if ($maxW > 0) { $s[0] = min($s[0], $maxW); }
        if ($s === $f->size || $this->isMaximized() || $this->isFullScreen()) { return; }
        [$w, $h] = [$s[0] !== $f->size[0] ? $s[0] : $content->width, $s[1] !== $f->size[1] ? $s[1] : $content->height];
        $f->size = $s;
        if ($w !== $content->width || $h !== $content->height) {
            $this->setContentBounds(new Rectangle($content->x, $content->y, $w, $h));
        }
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
        DataDrag::cancel($this, fn() => $this->native->cancelDrag());
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
                $src->finish(new Result(canceled: true, escaped: true, point: Screen::cursorScreenPoint()));
                return;
            }
            $this->native->startDrag(['data' => $src->data(), 'session' => $src->token, 'operations' => $src->operations,
                'x' => $x, 'y' => $y, 'preview' => $pix, 'width' => $width, 'height' => $height,
                'hotX' => $o->hotspot[0], 'hotY' => $o->hotspot[1], 'done' => $src->finish(...)]);
        });
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
        [$cx, $cy] = [$x / $rt->frameZoom, $y / $rt->frameZoom]; // the content's points (Context::setZoom); the 'fileDrop' listeners take the window's
        if ($kind === 'leave') {
            $rt->clearDataOver();
            $taken = false;
        } else {
            $taken = $kind === 'over' ? $rt->dataDragOver($cx, $cy, $d) : $rt->dataDrop($cx, $cy, $d);
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
        $z = $this->runtime->frameZoom;
        if ($kind === 'over') {
            return $this->runtime->fileDrag($x / $z, $y / $z) || $listeners !== [];
        }
        if ($this->runtime->fileDrop($x / $z, $y / $z, $paths)) {
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

    public function toggleMaximize(): void
    {
        $this->isMaximized() ? $this->unmaximize() : $this->maximize();
    }

    public function toggleFullScreen(): void
    {
        $this->setFullScreen(!$this->isFullScreen());
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

    /** @internal the page's <title> names the window, unless the window shows a document, which its name names */
    public function pageTitled(string $title): void
    {
        if (Native\Docs\Registry::documentOf(Native\Docs\Registry::frameOf($this)) === null) {
            $this->setTitle($title);
        }
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
        $v = new WebView($this, $o);
        $this->webViews[] = $v;
        $this->native->passThrough(fn(float $x, float $y): bool => $this->runtime->webViewAt($x / $this->runtime->frameZoom, $y / $this->runtime->frameZoom) !== null); // the content's points
        $this->invalidate();
        return $v;
    }

    /** @internal a web view was destroyed */
    public function forgetWebView(WebView $v): void
    {
        $this->webViews = array_values(array_filter($this->webViews, fn(WebView $w) => $w !== $v));
        $this->invalidate();
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
        $this->native->setProgressBar($state, $value);
    }

    /** The title setTitle set. */
    public function title(): string
    {
        return $this->title;
    }

    public function representedFile(): string
    {
        return $this->representedFile;
    }

    public function isDocumentEdited(): bool
    {
        return $this->documentEdited;
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

    /** The displays as Flyout's placements take them. @return list<array{bounds: Rectangle, workArea: Rectangle, primary: bool}> */
    private static function displays(): array
    {
        return array_map(fn(Display $d) => ['bounds' => $d->bounds, 'workArea' => $d->workArea, 'primary' => $d->primary], Screen::displays());
    }
}
