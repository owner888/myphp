<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\{App, Flyout, Rectangle, WindowOptions};
use MyPHP\Native\{Access, Drag, Drop, Metal, ObjC, Screens, View};
use MyPHP\Platform;
use MyPHP\Transfer\Data;
use MyPHP\UI\{DataDragEvent, DisplayList, Image, Painter};
use RuntimeException;

/**
 * A window of the macOS backend (mygo's darwin window.go and surface.go):
 * an NSWindow, an NSPanel of a panel or a flyout, or a flyout's NSPopover;
 * its content view holding the page's web view or the MTKView native UI
 * draws in, with what goes under it (the web views of native UI, the
 * players' pictures, the system materials, the layer under the
 * materials). It tells the core window ($w) what happens to it.
 */
final class Window implements Platform\Window
{
    private const TITLED = 1, CLOSABLE = 2, MINIATURIZABLE = 4, RESIZABLE = 8, FULL_SCREEN = 1 << 14, FULL_SIZE_CONTENT = 1 << 15, OCCLUSION_VISIBLE = 1 << 1;
    private const NONACTIVATING = 1 << 7; // NSWindowStyleMaskNonactivatingPanel
    private const NORMAL_LEVEL = 0, FLOATING_LEVEL = 3, POP_UP_MENU_LEVEL = 101;
    private const FULL_SCREEN_PRIMARY = 1 << 7, FULL_SCREEN_AUXILIARY = 1 << 8, FULL_SCREEN_NONE = 1 << 9, CAN_JOIN_ALL_SPACES = 1 << 0, TRANSIENT = 1 << 3, IGNORES_CYCLE = 1 << 6;
    private const BACKING_BUFFERED = 2;
    /** The NSVisualEffectView materials of WindowOptions::$vibrancy, as mygo's (Windows 11's backdrops as their nearest), and Node::systemMaterial's. */
    public const MATERIALS = [
        'titlebar' => 3, 'selection' => 4, 'menu' => 5, 'popover' => 6, 'sidebar' => 7, 'header' => 10,
        'sheet' => 11, 'window' => 12, 'hud' => 13, 'fullscreen-ui' => 15, 'tooltip' => 17, 'content' => 18,
        'under-window' => 21, 'under-page' => 22, 'mica' => 21, 'tabbed' => 21, 'acrylic' => 13,
    ];

    /** @var array<int, Window> the windows showing, by their NSWindow's address, or their popover's */
    private static array $open = [];
    /** @var array<int, Window> by content view address, for drops */
    private static array $byView = [];
    private static bool $delegateDefined = false, $panelsDefined = false;
    /** The monitors of presses outside the app and on a flyout's anchor (watchOutsideClicks), and the release type of the press the latter ate, or 0. */
    private static $outsideClicks = null, $anchorPresses = null;
    private static int $eatingPress = 0;

    /** @internal the NSWindow; a popover's while it shows, null otherwise */
    public $ns = null;
    /** @internal the view of the content: the page's web view, or the MTKView native UI draws in */
    public $view = null;
    /** @internal the window's content view, which holds the page or the native view, and the material behind it (setVibrancy), as mygo's: AppKit draws a broken title bar for a web inspector docked in the window's frame view */
    public $container = null;
    private $effect = null;
    /** The view of the nodes painted under the materials (Node::belowMaterials), over the videos, made as a frame first has some; whether it shows any, and whether it is to be put in its place among the views. */
    private $under = null;
    private bool $underShown = false, $underNew = false;
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
    private bool $panel, $hiddenTitleBar = false, $transparent = false, $sheet = false;
    /** The request for the user's attention flashFrame made, and whether a full screen none window was let onto all Spaces as an auxiliary. */
    private int $attention = 0;
    private bool $auxiliary = false;
    /** In the legacy full screen: the frame and style it left; the black windows over the other screens. */
    private ?array $legacy = null;
    private array $blackOuts = [];
    /** The frame the window had before it went full screen by its own animation, which it goes back to. */
    private $windowedFrame = null;
    /** @var ?array{0: float, 1: float} where the close button's top-left corner goes (WindowOptions::$trafficLightPosition) */
    private ?array $trafficLights;
    /** @var array<int, array{player: \WeakReference<\MyPHP\Media\Player>, clip: mixed, crop: mixed, video: mixed}> the videos the window showed, by player: the view under the native one that holds the picture's layer */
    private array $videoHosts = [];
    /** @var array<int, array{view: mixed, kind: string}> the system materials the window showed (Node::systemMaterial), by node: an NSVisualEffectView or NSGlassEffectView under the native view, over the videos, and what it is */
    private array $materials = [];
    /** The node of a web view the runtime's focus was on last frame, which gave its page the keyboard. */
    private ?int $webFocus = null;
    private \MyPHP\UI\Cursor $cursorShown = \MyPHP\UI\Cursor::Default;
    private static mixed $noCursor = null;

    /**
     * The NSWindow of $o, a panel's ($panel) or a flyout's (the core's
     * flyout state): a popover's makes its window as it shows. Its content
     * comes with setContent or createSurface.
     */
    public function __construct(private readonly Backend $b, public readonly \MyPHP\Window $w, \MyPHP\WindowOptions $o, bool $panel)
    {
        $this->panel = $panel;
        $this->trafficLights = $o->trafficLightPosition;
        if ($w->flyoutState() !== null) {
            $this->createFlyout($o);
        } else {
            $this->createWindow($o);
        }
    }

    /** The core window of the NSWindow $ns, or of the popover, or null. */
    public static function core(mixed $ns): ?\MyPHP\Window
    {
        return $ns === null ? null : (self::$open[ObjC::addr($ns)] ?? null)?->w;
    }

    /** @internal the window that has the keyboard (mygo's FocusedWindow) */
    public static function keyWindow(): ?\MyPHP\Window
    {
        return self::core(ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'keyWindow'));
    }

    public function handle(): mixed
    {
        return $this->ns;
    }

    /**
     * Shows the page's web view $view in the window (mygo's window with a
     * webview): the content view holds it.
     */
    public function setContent(Platform\Page $page, \MyPHP\WindowOptions $o): void
    {
        $this->view = $page->handle();
        $this->attach($o);
    }

    /**
     * Makes the MTKView native UI draws in (mygo's NewSurface), each frame
     * drawn by $frame($list, $width, $height) of the view's bounds in
     * points; the layer under the materials draws after it.
     * @param \Closure(DisplayList, float, float): void $frame
     */
    public function createSurface(\MyPHP\WindowOptions $o, \Closure $frame, ?\Closure $afterPresent): void
    {
        $metal = Backend::metal();
        $this->view = View::create($metal, ObjC::rect(0, 0, $o->width, $o->height), $this->w->input, function ($view, DisplayList $list) use ($frame, $metal) {
            $metal->setDark(App::theme()->dark);
            $bounds = ObjC::msg('r:', $view, 'bounds');
            $frame($list, (float)$bounds->w, (float)$bounds->h);
        });
        if ($afterPresent !== null) {
            Metal::$afterPresent[ObjC::addr($this->view)] = $afterPresent;
        }
        Metal::setClearColor($this->view, App::theme()->background);
        $view = $this->view;
        Metal::$afterFrame[ObjC::addr($this->view)] = function (): void { // the layer under the materials drawn in the same turn as the frame, the list it used let go
            if ($this->under !== null && ($this->w->runtime->underPaints !== [] || $this->underShown)) { ObjC::msg('v:', $this->under, 'draw'); }
            $this->w->runtime->framePresented();
        };
        $this->w->runtime->onAccess = fn(\MyPHP\UI\AccessTree $t) => Access::update($view, $t); // VoiceOver reads each frame once it asked
        $this->attach($o);
    }

    /** The content view around the view, the window's look as $o asks, its delegate, and the drags and drops on it. */
    private function attach(\MyPHP\WindowOptions $o): void
    {
        $bounds = ObjC::msg('r:', $this->view, 'frame');
        $this->container = ObjC::msg('p:r', ObjC::alloc('NSView'), 'initWithFrame:', ObjC::rect(0, 0, $bounds->w, $bounds->h));
        ObjC::msg('v:L', $this->view, 'setAutoresizingMask:', 18); // width and height sizable
        ObjC::msg('v:p', $this->container, 'addSubview:', $this->view);
        $flyout = $this->w->flyoutState();
        if ($flyout === null || !$flyout['popover']) {
            ObjC::msg('v:p', $this->ns, 'setContentView:', $this->container);
        }
        $this->transparent = $o->transparent;
        if ($o->transparent) {
            ObjC::msg('v:b', $this->ns, 'setOpaque:', false);
            ObjC::msg('v:p', $this->ns, 'setBackgroundColor:', ObjC::msg('p:', ObjC::cls('NSColor'), 'clearColor'));
            $this->clearContent();
        }
        if ($o->vibrancy !== '') {
            $this->setVibrancy($o->vibrancy);
        }
        if ($flyout !== null && !$flyout['popover'] && $flyout['radius'] > 0) {
            $this->roundFlyout($flyout['radius']);
        }
        $delegate = self::delegate();
        if ($flyout !== null && $flyout['popover']) {
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
            $win = self::$byView[ObjC::addr($view)] ?? null;
            return $win !== null && $win->w->dataEvent($kind, $d, $x, $y);
        });
        Drop::handle(function ($view, array $paths, float $x, float $y) {
            (self::$byView[ObjC::addr($view)] ?? null)?->w->filesDropped($paths, $x, $y);
        });
    }

    /** Puts the window where a state key left it when a display shows it, else at its size, as far as the primary display's work area allows, centered (mygo's restoreWindowState). */
    public function restoreFrame(Rectangle $r, bool $onDisplay): void
    {
        if ($onDisplay) {
            $this->setBounds($r);
            return;
        }
        $wa = Screens::displays()[0]['workArea'] ?? null;
        ObjC::msg('v:rb', $this->ns, 'setFrame:display:', ObjC::rect(0, 0, $wa === null ? $r->width : min($r->width, $wa->width), $wa === null ? $r->height : min($r->height, $wa->height)), false);
        $this->center();
    }

    /** Toggles full screen, or zooms, as the window starts (WindowOptions::$fullScreen, $maximized). */
    public function startFullScreen(): void
    {
        ObjC::msg('v:p', $this->ns, 'toggleFullScreen:', null);
    }

    public function startMaximized(): void
    {
        ObjC::msg('v:p', $this->ns, 'zoom:', null);
    }

    /** Whether the window is in the legacy full screen (IINA's). */
    public function isLegacyFullScreen(): bool
    {
        return $this->legacy !== null;
    }

    /** Whether the flyout shows in a popover (FlyoutOptions::$popover). */
    public function hasPopover(): bool
    {
        return $this->popover !== null;
    }

    /**
     * Shows the web views, videos and materials where the frame placed
     * them, and the layer under the materials once the frame painted some
     * there (mygo's PlaceWebViews).
     */
    public function placeWebViews(): void
    {
        $rt = $this->w->runtime;
        if ($rt->underPaints !== [] && $this->under === null) {
            $this->under = $this->underView();
        }
        if ($this->w->webViewList() !== [] || $this->videoHosts !== [] || $rt->videoPlaces !== [] || $this->materials !== [] || $rt->materialPlaces !== [] || $this->underNew) {
            $this->placeViews(ObjC::msg('r:', $this->view, 'bounds')->h);
        }
    }

    /** Shows the players' pictures where the last frame placed them: a video given back (picture in picture) in its place at once, which the system animates into before a frame comes. */
    public function placeVideos(): void
    {
        $this->placeVideoViews(ObjC::msg('r:', $this->view, 'bounds')->h);
    }

    /** A web view of native UI showing $page, under the native view (mygo's Surface.NewWebView). */
    public function newWebView(Platform\Page $page, bool $scrollToContainer, bool $transparent): Platform\WebView
    {
        return new WebView($this, $page, $scrollToContainer, $transparent);
    }

    /** @internal the native view of the content, which takes the keyboard back from a web view */
    public function contentView(): mixed
    {
        return $this->view;
    }

    private static function delegate()
    {
        if (!self::$delegateDefined) {
            self::$delegateDefined = true;
            $of = fn($note): ?Window => self::$open[ObjC::addr(ObjC::msg('p:', $note, 'object'))] ?? null;
            $fire = fn(string $event) => function ($self, $cmd, $note) use ($event, $of) {
                $win = $of($note);
                if ($win === null) {
                    return;
                }
                if ($event === 'resize') {
                    $win->layoutTrafficLights();
                    if ($win->w->page() === null) { ObjC::msg('v:', $win->view, 'draw'); } // a frame at the new size now, before this step of the resize is committed, as mygo's setFrameSize: asks: asked for later, it would come a step late and the layer would stretch the last one meanwhile
                }
                if ($event === 'enterFullScreen' || $event === 'leaveFullScreen') {
                    $win->blackOut($event === 'enterFullScreen' && $win->w->blackOutAsked());
                }
                $win->w->nativeEvent($event);
            };
            ObjC::defineClass('MyPHPWindowDelegate', 'NSObject', [
                'windowShouldClose:' => ['B@:@', 'b(ppp)', function ($self, $cmd, $sender) {
                    return (self::$open[ObjC::addr($sender)] ?? null)?->w->shouldClose() ?? true;
                }],
                'windowWillClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($of) {
                    $of($note)?->w->closed();
                }],
                // The user clicked elsewhere, in a transient popover: a press on its own tray icon leaves it to the icon's click, which toggles it, rather than closing it for the click to open it again.
                'popoverShouldClose:' => ['B@:@', 'b(ppp)', function ($self, $cmd, $popover) {
                    $win = self::$open[ObjC::addr($popover)] ?? null;
                    if ($win === null) {
                        return true;
                    }
                    if ($win->w->flyoutState()['tray']?->pressed() ?? false) {
                        return false;
                    }
                    return $win->w->shouldClose();
                }],
                'popoverDidClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($of) {
                    $win = $of($note);
                    if ($win === null) {
                        return;
                    }
                    $win->ns = null; // the popover's window goes
                    $win->w->flyoutState()['tray']?->highlight(false);
                    if ($win->popoverClosing) {
                        $win->popoverClosing = false;
                        return;
                    }
                    $win->w->closed();
                }],
                'windowDidBecomeKey:' => ['v@:@', 'v(ppp)', $fire('focus')],
                'windowDidResignKey:' => ['v@:@', 'v(ppp)', $fire('blur')],
                'windowDidResize:' => ['v@:@', 'v(ppp)', $fire('resize')],
                'windowDidMove:' => ['v@:@', 'v(ppp)', $fire('move')],
                'windowWillMove:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $note) => \MyPHP\Native\WindowDrag::the()->willMove(ObjC::addr(ObjC::msg('p:', $note, 'object')))], // the window server begins to move it: a drag region's press is its
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
                    $win = self::$open[ObjC::addr($ns)] ?? null;
                    if ($win === null) { return; }
                    $win->windowedFrame = ObjC::msg('r:', $ns, 'frame'); // where it goes back to
                    $win->animateFrame(ObjC::msg('r:', ObjC::msg('p:', $ns, 'screen'), 'frame'), $duration);
                }],
                'window:startCustomAnimationToExitFullScreenWithDuration:' => ['v@:@d', 'v(pppd)', function ($self, $cmd, $ns, $duration) {
                    $win = self::$open[ObjC::addr($ns)] ?? null;
                    if ($win?->windowedFrame !== null) { $win->animateFrame($win->windowedFrame, $duration); }
                }],
                'windowDidChangeOcclusionState:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($of) {
                    $win = $of($note);
                    if ($win !== null && $win->showing()) { $win->w->shownAgain(); }
                }],
                'windowDidChangeBackingProperties:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) { App::rescale(); }],
                'titleBarLaidOut:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) { // the title bar container's frame changed: AppKit laid it out
                    $win = self::$open[ObjC::addr(ObjC::msg('p:', ObjC::msg('p:', $note, 'object'), 'window'))] ?? null;
                    $win?->layoutTrafficLights();
                }],
            ], ['NSWindowDelegate', 'NSPopoverDelegate']);
        }
        return ObjC::msg('p:', ObjC::alloc('MyPHPWindowDelegate'), 'init');
    }

    /**
     * Lets go of what the window holds natively as it closes: its black
     * windows, the legacy full screen's presentation, the popover, the
     * request for attention, the native view's, the layer under the
     * materials, the players' pictures (free for another window) and the
     * materials. The NSWindow itself stays, released by AppKit.
     */
    public function release(): void
    {
        unset(self::$open[$this->key]);
        $this->blackOut(false); // its black windows go with it, the menu bar and the Dock back
        if ($this->legacy !== null) { ObjC::msg('v:L', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'setPresentationOptions:', $this->legacy[2]); }
        if ($this->view !== null) { unset(Metal::$afterPresent[ObjC::addr($this->view)]); }
        if ($this->popover !== null) {
            $this->cleanupPopover();
        }
        if ($this->attention !== 0) { $this->flashFrame(false); }
        unset(self::$byView[ObjC::addr($this->view)]);
        if ($this->ns !== null) { \MyPHP\Native\WindowDrag::forget($this->ns); }
        if ($this->w->runtime !== null) { Access::detach($this->view); View::forget($this->view); Metal::forget($this->view); }
        if ($this->under !== null) {
            Metal::forget($this->under);
            ObjC::msg('v:', $this->under, 'removeFromSuperview');
            ObjC::msg('v:', $this->under, 'release');
            $this->under = null;
        }
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
    }

    /** Shows the window and gives it the keyboard; a modal child shows as a sheet of its parent, another stays above it; a flyout takes the keyboard only when focusable, and a panel or a flyout never activates the app. */
    public function show(): void
    {
        $flyout = $this->w->flyoutState();
        $parent = $this->w->parent();
        if ($this->popover !== null) {
            $this->showPopover();
        } elseif ($flyout !== null) {
            $this->showFlyout($flyout['focusable']);
        } elseif ($parent !== null && $this->w->isModal() && !$this->sheet) {
            $this->sheet = true;
            ObjC::msg('v:pp', $parent->native()->ns, 'beginSheet:completionHandler:', $this->ns, null);
        } elseif (!$this->sheet) {
            ObjC::msg('v:p', $this->ns, 'makeKeyAndOrderFront:', null);
            if ($parent !== null && !$parent->isDestroyed()) {
                ObjC::msg('v:pl', $parent->native()->ns, 'addChildWindow:ordered:', $this->ns, 1); // NSWindowAbove
            }
            if (!$this->panel) {
                ObjC::msg('v:b', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'activateIgnoringOtherApps:', true);
            }
        }
    }

    /** Shows the window without giving it the keyboard. */
    public function showInactive(): void
    {
        $parent = $this->w->parent();
        if ($this->popover !== null) {
            $this->showPopover();
        } elseif ($this->w->flyoutState() !== null) {
            $this->showFlyout(false);
        } else {
            ObjC::msg('v:', $this->ns, 'orderFrontRegardless');
            if ($parent !== null && !$parent->isDestroyed()) {
                ObjC::msg('v:pl', $parent->native()->ns, 'addChildWindow:ordered:', $this->ns, 1);
            }
        }
    }

    public function hide(): void
    {
        if ($this->popover !== null) {
            $this->hidePopover();
        } elseif ($this->w->flyoutState() !== null) {
            $this->hideFlyout();
        } elseif ($this->sheet) {
            $this->sheet = false;
            ObjC::msg('v:p', $this->w->parent()->native()->ns, 'endSheet:', $this->ns);
        } else {
            ObjC::msg('v:p', $this->ns, 'orderOut:', null);
        }
    }

    /** Destroys the window without asking (mygo's Close): a popover at once, without its animation, which tells the core it closed; else AppKit's close, whose windowWillClose: does. */
    public function close(): void
    {
        if ($this->popover !== null) {
            ObjC::msg('v:b', $this->popover, 'setAnimates:', false);
            $this->hidePopover();
            $this->w->closed();
            return;
        }
        if ($this->sheet) {
            $this->sheet = false;
            ObjC::msg('v:p', $this->w->parent()->native()->ns, 'endSheet:', $this->ns);
        }
        if ($this->w->flyoutState() !== null) {
            $this->hideFlyout();
        }
        ObjC::msg('v:', $this->ns, 'close');
    }

    public function setTitle(string $title): void
    {
        ObjC::msg('v:p', $this->ns, 'setTitle:', ObjC::str($title));
        $this->layoutTrafficLights();
    }

    /** Shows which file the window shows, '' for none: the title bar's icon, which the user can drag, and a Command-click on the title lists the folders it is in. */
    public function setRepresentedFile(string $path): void
    {
        ObjC::msg('v:p', $this->ns, 'setRepresentedURL:', $path === '' ? null : ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($path)));
        $this->layoutTrafficLights(); // AppKit lays the title bar's buttons out again
    }

    /** A dot in the close button, and the file's icon dimmed. */
    public function setDocumentEdited(bool $edited): void
    {
        ObjC::msg('v:b', $this->ns, 'setDocumentEdited:', $edited);
        $this->layoutTrafficLights();
    }

    /** Paints $rgba (0–255) behind the page, or around native UI where it draws nothing. @param array{0: int, 1: int, 2: int, 3: int} $rgba */
    public function setBackgroundColor(array $rgba): void
    {
        [$r, $g, $b, $a] = $rgba;
        $c = ObjC::msg('p:dddd', ObjC::cls('NSColor'), 'colorWithSRGBRed:green:blue:alpha:', $r / 255, $g / 255, $b / 255, $a / 255);
        ObjC::msg('v:p', $this->ns, 'setBackgroundColor:', $c);
        if ($this->w->page() !== null) {
            ObjC::msg('v:pp', $this->view, 'setValue:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', false), ObjC::str('drawsBackground'));
            if (ObjC::msg('b:p', $this->view, 'respondsToSelector:', ObjC::sel('setUnderPageBackgroundColor:'))) {
                ObjC::msg('v:p', $this->view, 'setUnderPageBackgroundColor:', $c);
            }
        }
    }

    /** The theme changed: the title bar laid out again with the appearance, native UI clearing to its background. */
    public function themeChanged(): void
    {
        $this->layoutTrafficLights(); // AppKit lays the title bar out again with the appearance
        if ($this->w->page() === null && !$this->transparent && $this->effect === null) {
            Metal::setClearColor($this->view, App::theme()->background);
        }
    }

    /** Whether a material shows behind the content (setVibrancy, or a popover's): native UI leaves it room. */
    public function hasVibrancy(): bool
    {
        return $this->effect !== null || $this->popover !== null;
    }

    /** Asks the native view for a frame, every $every refreshes of the display, painting again alone when $repaint (mygo's RequestFrame). */
    public function requestFrame(int $every = 1, bool $repaint = false): void
    {
        Metal::requestFrame($this->view, $every, $repaint);
    }

    /** A frame in $seconds, unless one comes before. */
    public function wake(float $seconds, bool $repaint): void
    {
        View::wake($this->view, $seconds, $repaint);
    }

    /** Whether the frame being drawn only paints again: the drawings that asked moved (Metal::requestFrame's $repaint). */
    public function repaintAsked(): bool
    {
        return Metal::repaintAsked($this->view);
    }

    /** The display's refresh rate, as the view's display link has it. */
    public function displayRate(): float
    {
        return Metal::refreshRate($this->view);
    }

    /** How many pixels a point is on the window's display. */
    /** The Dock tile's progress, which the window that set it last wins. */
    public function setProgressBar(string $state, float $value): void
    {
        \MyPHP\Native\Dock::setProgress($state, $value);
    }

    /** How the last frame was drawn, for FrameStats: whole, in rectangles, or nothing (FrameDamage). */
    public function lastDraw(): string
    {
        return 'Metal, drew ' . (Metal::$redrew[ObjC::addr($this->view)] ?? 'whole');
    }

    public function backingScale(): float
    {
        return ObjC::msg('d:', $this->ns, 'backingScaleFactor');
    }

    /** Whether the window takes the keyboard, where a selection shows. */
    public function isKey(): bool
    {
        return $this->ns !== null && ObjC::msg('b:', $this->ns, 'isKeyWindow');
    }

    /** The click that brings the window forward clicks too (a player's setting, IINA's). */
    public function setAcceptsFirstMouse(bool $on): void
    {
        View::setAcceptsFirstMouse($this->view, $on);
    }

    /** The formats native UI takes from drags (Context::dropData). @param list<string> $formats */
    public function setDropFormats(array $formats): void
    {
        Drag::setFormats($this->view, $formats);
    }

    /** A command of the Format menu's roles for the node of native UI taking the keyboard's input. */
    public function command(string $cmd): void
    {
        View::command($this->view, $cmd);
    }

    /** Lets clicks through native UI to the web views where $at says they are. @param \Closure(float, float): bool $at */
    public function passThrough(\Closure $at): void
    {
        View::$passThrough[ObjC::addr($this->view)] = $at;
    }

    /** The last frame native UI drew: premultiplied BGRA, its width and height in pixels. @return array{0: string, 1: int, 2: int} */
    public function lastFrame(): array
    {
        return Backend::metal()->lastFrame($this->view);
    }

    /** A PNG of native UI as it is. */
    public function capture(): string
    {
        [$frame, $w, $h] = $this->lastFrame(); // BGRA, premultiplied: 32-bit little-endian words with alpha first
        $rep = ObjC::msg('p:pllllbbpLll', ObjC::alloc('NSBitmapImageRep'), 'initWithBitmapDataPlanes:pixelsWide:pixelsHigh:bitsPerSample:samplesPerPixel:hasAlpha:isPlanar:colorSpaceName:bitmapFormat:bytesPerRow:bitsPerPixel:',
            null, $w, $h, 8, 4, true, false, ObjC::str('NSDeviceRGBColorSpace'), 1 | 1 << 9, $w * 4, 32) ?? throw new RuntimeException('myphp: capturePage: no bitmap');
        \FFI::memcpy(ObjC::$c->cast('char*', ObjC::msg('p:', $rep, 'bitmapData')), $frame, strlen($frame));
        $png = ObjC::msg('p:Lp', $rep, 'representationUsingType:properties:', 4, ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary')); // NSBitmapImageFileTypePNG
        ObjC::msg('v:', $rep, 'release');
        return $png === null ? throw new RuntimeException('myphp: capturePage: no PNG') : \MyPHP\Native\Drag::bytes($png);
    }

    /** The layer under the materials drawn again, offscreen: premultiplied BGRA, its size in pixels, and pixels a point; null while it shows nothing (a player's subtitles, for its screenshots). @return ?array{0: string, 1: int, 2: int, 3: float} */
    public function underFrame(): ?array
    {
        if ($this->under === null || !$this->underShown) { return null; }
        [$bgra, $w, $h] = Backend::metal()->drawOffscreen($this->under); // drawn again: the ring's list of its last frame may be another view's by now
        return [$bgra, $w, $h, $w / max(1.0, ObjC::msg('r:', $this->under, 'bounds')->w)];
    }

    /** A press on a drag region of a page moves the window, as the window server does. */
    public function pageDrag(): void
    {
        if (($down = \MyPHP\Native\WebView::lastDown()) !== null && !(ObjC::msg('L:', $this->ns, 'styleMask') & self::FULL_SCREEN)) {
            \MyPHP\Native\WindowDrag::startDrag($this->ns, $down);
        }
    }

    /** Whether the window hides its title bar, keeping the controls (WindowOptions::$titleBarStyle). */
    public function hasHiddenTitleBar(): bool
    {
        return $this->hiddenTitleBar;
    }

    /** The minimum and maximum sizes of the frame, 0 for no limit, as setMinimumSize and setMaximumSize take them. @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}} */
    public function sizeLimits(): array
    {
        $size = function (string $of): array {
            $z = ObjC::msg('z:', $this->ns, $of);
            return [$z->w >= 1e30 ? 0 : (int)$z->w, $z->h >= 1e30 ? 0 : (int)$z->h];
        };
        return [$size('minSize'), $size('maxSize')];
    }

    /** The frame's size, in points, unrounded as AppKit has it. @return array{0: float, 1: float} */
    public function frameSize(): array
    {
        $f = ObjC::msg('r:', $this->ns, 'frame');
        return [(float)$f->w, (float)$f->h];
    }

    /** Whether a web view of native UI holds $view; gives the keyboard back to native UI, as Tab leaves the page past its ends. */
    private function tabOut(mixed $view, bool $back): bool
    {
        foreach ($this->w->webViewList() as $v) {
            if ($view !== null && $v->holds($view)) {
                ObjC::msg('b:p', $this->ns, 'makeFirstResponder:', $this->view);
                View::keyCommand($this->view, $back ? 'insertBacktab:' : 'insertTab:'); // the focus moves on from the web view's node
                return true;
            }
        }
        return false;
    }

    /** A native drag of $src's data, from ($x, $y), or the press for negative ones (mygo's startDataDrag). */
    public function startDrag(array $o): void
    {
        Drag::start($this->view, View::lastDown($this->view), $o);
    }

    public function cancelDrag(): void
    {
        Drag::cancel($this->view);
    }

    /** The NSWindow of a window (not a flyout), with its style, title bar and limits. */
    private function createWindow(WindowOptions $o): void
    {
        $style = self::TITLED | ($o->disableClose ? 0 : self::CLOSABLE) | ($o->disableMinimize ? 0 : self::MINIATURIZABLE) | ($o->disableResize ? 0 : self::RESIZABLE);
        $hidden = match ($o->titleBarStyle) { 'default' => false, 'hidden', 'hiddenInset' => true, default => throw new \InvalidArgumentException("titleBarStyle {$o->titleBarStyle}") };
        $this->hiddenTitleBar = $hidden && !$o->frameless && !$o->hideWindowButtons; // the traffic lights stay, over the view, unless the view draws its own
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
        if ($o->frameless || ($hidden && $o->hideWindowButtons)) {
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
        if ($this->w->flyoutState()['popover']) {
            return; // its popover makes its window as it shows (createPopover)
        }
        self::definePanels();
        $this->ns = ObjC::msg('p:rLLb', ObjC::alloc('MyPHPFlyout'), 'initWithContentRect:styleMask:backing:defer:', ObjC::rect(0, 0, $o->width, $o->height), self::NONACTIVATING, self::BACKING_BUFFERED, false);
        ObjC::msg('v:b', $this->ns, 'setReleasedWhenClosed:', false);
        ObjC::msg('v:b', $this->ns, 'setHidesOnDeactivate:', false); // it stays with its parent as the app is deactivated; one that takes the keyboard closes
        ObjC::msg('v:L', $this->ns, 'setCollectionBehavior:', self::TRANSIENT | self::IGNORES_CYCLE | self::FULL_SCREEN_AUXILIARY);
        if ($this->w->flyoutState()['tray'] !== null) {
            ObjC::msg('v:l', $this->ns, 'setLevel:', self::POP_UP_MENU_LEVEL); // over the windows of other apps, as the menu bar's menus
        }
        ObjC::msg('v:b', $this->ns, 'setHasShadow:', !$o->disableShadow);
        if ($o->opacity > 0 && $o->opacity < 1) { ObjC::msg('v:d', $this->ns, 'setAlphaValue:', $o->opacity); }
    }

    /**
     * Cuts a flyout's content view, and the material in it, to corners of $radius points (mygo's roundFlyout): the
     * window is clear, so its shadow, made of what it draws, follows them.
     */
    private function roundFlyout(int $radius): void
    {
        foreach ([$this->container, $this->effect] as $v) {
            if ($v === null) { continue; }
            ObjC::msg('v:b', $v, 'setWantsLayer:', true);
            $layer = ObjC::msg('p:', $v, 'layer');
            ObjC::msg('v:d', $layer, 'setCornerRadius:', (float)$radius);
            ObjC::msg('v:p', $layer, 'setCornerCurve:', ObjC::str('continuous'));
            ObjC::msg('v:b', $layer, 'setMasksToBounds:', true);
        }
        ObjC::msg('v:', $this->ns, 'invalidateShadow');
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
    public function dragWindow(int $clicks): void
    {
        if ($clicks === 2) { // AppleActionOnDoubleClick: Maximize (the default), Minimize or None
            $defaults = ObjC::msg('p:', ObjC::cls('NSUserDefaults'), 'standardUserDefaults');
            $action = ObjC::msg('p:p', $defaults, 'stringForKey:', ObjC::str('AppleActionOnDoubleClick'));
            match ($action === null ? '' : ObjC::phpStr($action)) { 'None' => null, 'Minimize' => ObjC::msg('v:p', $this->ns, 'performMiniaturize:', null), default => ObjC::msg('v:p', $this->ns, 'performZoom:', null) };
            return;
        }
        $down = View::lastDown($this->view);
        if ($down !== null && !(ObjC::msg('L:', $this->ns, 'styleMask') & self::FULL_SCREEN)) { \MyPHP\Native\WindowDrag::startDrag($this->ns, $down); } // the window server, or myphp after a quick drag (Native\WindowDrag)
    }

    /** The refresh rate of the display the window is on, for the inspector's frame budget: 60 where the screen does not say. */
    public function refreshRate(): float
    {
        $screen = $this->ns === null ? null : ObjC::msg('p:', $this->ns, 'screen');
        if ($screen === null || !ObjC::msg('b:p', $screen, 'respondsToSelector:', ObjC::sel('maximumFramesPerSecond'))) { return 60.0; } // macOS 12
        $hz = ObjC::msg('l:', $screen, 'maximumFramesPerSecond');
        return $hz > 0 ? (float)$hz : 60.0;
    }

    /** The pointer over the content (mygo's nsCursor): AppKit's cursor of each shape; the diagonal resize cursors macOS 15's frame resize cursors, else AppKit's older ones; the columns' and rows' macOS 15's, else the two-way arrows; none a cursor of a transparent image, which hides the pointer over the view alone. */
    public function showCursor(\MyPHP\UI\Cursor $cursor): void
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

    /** Lets what is behind the content show where it draws nothing: a page draws no background, native UI clears to transparent where its root's background is taken away. */
    private function clearContent(): void
    {
        if ($this->w->page() !== null) {
            ObjC::msg('v:pp', $this->view, 'setValue:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', false), ObjC::str('drawsBackground'));
            return;
        }
        ObjC::msg('v:b', ObjC::msg('p:', $this->view, 'layer'), 'setOpaque:', false);
        Metal::setClearColor($this->view, 0, 0.0);
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
        if ($material === '') {
            if ($this->effect !== null) {
                ObjC::msg('v:', $this->effect, 'removeFromSuperview');
                ObjC::msg('v:', $this->effect, 'release');
                $this->effect = null;
                if ($this->w->page() === null && !$this->transparent) {
                    ObjC::msg('v:b', ObjC::msg('p:', $this->view, 'layer'), 'setOpaque:', true);
                    Metal::setClearColor($this->view, App::theme()->background);
                }
            }
            $this->w->invalidate();
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
        $this->w->invalidate(); // native UI looks again whether the material shows
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

    public function showing(): bool
    {
        if ($this->popover !== null || $this->ns === null) {
            return true; // a popover's window is AppKit's, whose occlusion its delegate does not hear of: it draws while it shows, and it shows while it draws
        }
        return (ObjC::msg('L:', $this->ns, 'occlusionState') & self::OCCLUSION_VISIBLE) !== 0;
    }

    public function isVisible(): bool
    {
        if ($this->popover !== null) {
            return ObjC::msg('b:', $this->popover, 'isShown');
        }
        return ObjC::msg('b:', $this->ns, 'isVisible');
    }

    /** Gives the window the keyboard, and the app with it; a panel takes it without activating the app. */
    public function focus(): void
    {
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

    public function isFullScreen(): bool
    {
        return $this->legacy !== null || (ObjC::msg('L:', $this->ns, 'styleMask') & self::FULL_SCREEN) !== 0;
    }

    /**
     * The legacy full screen, as IINA's (before OS X 10.7's spaces): the
     * window over its whole screen where it is, borderless, the menu bar
     * and the Dock hidden until the pointer goes to them; off, as it was.
     */
    public function setLegacyFullScreen(bool $on): void
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
        $this->blackOut($on && $this->w->blackOutAsked());
        $this->w->fullScreenChanged($on ? 'enterFullScreen' : 'leaveFullScreen');
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

    /**
     * The window to a film of $o['w'] by $o['h'] pixels, as IINA's
     * adjustFrameByVideoSize: where $o['resize'], to the film's size in
     * points ($o['physical']: a pixel a pixel of the screen) times
     * $o['ratio'] (-1: within the screen), kept within the screen and above
     * the window's least size, placed by the geometry $o['geometry'] (mpv's,
     * IINA's initial size and position) where there is one, else in the
     * screen's middle (fit), else about its own; else the film's size in
     * the middle where the geometry was asked for; kept in the screen;
     * animated unless $o['animate'] is false.
     * @param array{w: int, h: int, resize: bool, ratio: float, physical: bool, geometry: ?string, animate: bool} $o
     */
    /**
     * The window $by larger (a pinch's magnification, IINA's handleMagnifyGesture):
     * its width times 1 + $by, its shape kept, about its center; not as high
     * as the screen's visible frame, nor smaller than it may be.
     */
    public function magnify(float $by): void
    {
        $screen = ObjC::msg('p:', $this->ns, 'screen') ?? ObjC::msg('p:', ObjC::cls('NSScreen'), 'mainScreen');
        $sf = ObjC::msg('r:', $screen, 'visibleFrame');
        $f = ObjC::msg('r:', $this->ns, 'frame');
        $min = ObjC::msg('z:', $this->ns, 'minSize');
        $w = $f->w * (1 + $by);
        $h = $w / ($f->w / max(1.0, $f->h));
        if ($h >= $sf->h || $h <= $min->h || $w <= $min->w) { return; }
        ObjC::msg('v:rb', $this->ns, 'setFrame:display:', ObjC::rect($f->x + ($f->w - $w) / 2, $f->y + ($f->h - $h) / 2, $w, $h), true);
    }

    public function fitVideo(array $o): void
    {
        if ($o['w'] <= 0 || $o['h'] <= 0) { return; }
        $screen = ObjC::msg('p:', $this->ns, 'screen') ?? ObjC::msg('p:', ObjC::cls('NSScreen'), 'mainScreen');
        $sf = ObjC::msg('r:', $screen, 'visibleFrame'); // AppKit's, from the bottom left
        $frame = ObjC::msg('r:', $this->ns, 'frame');
        $min = ObjC::msg('z:', $this->ns, 'minSize');
        [$w, $h] = [(float)$o['w'], (float)$o['h']];
        $geometry = $o['geometry'] === null || $o['geometry'] === '' ? null : $o['geometry'];
        if ($o['resize']) {
            if ($o['physical']) { $k = ObjC::msg('d:', $this->ns, 'backingScaleFactor'); [$w, $h] = [$w / $k, $h / $k]; }
            if ($o['ratio'] < 0) {
                $k = min($sf->w / $w, $sf->h / $h); [$w, $h] = [$w * $k, $h * $k]; // as large as the screen takes, larger or smaller (IINA's shrink(toSize:), which fits)
            } else {
                [$w, $h] = [$w * $o['ratio'], $h * $o['ratio']];
            }
        }
        $k = min(1.0, $sf->w / $w, $sf->h / $h); [$w, $h] = [$w * $k, $h * $k]; // no larger than the screen
        $k = max(1.0, $min->w / $w, $min->h / $h); [$w, $h] = [$w * $k, $h * $k]; // no smaller than the window may be
        if ($geometry !== null) {
            $r = self::geometryFrame($geometry, $w, $h, $sf);
        } elseif (!$o['resize']) {
            return; // the size the window has: its shape follows the film's (the aspect asked)
        } elseif ($o['ratio'] < 0) {
            $r = [$sf->x + ($sf->w - $w) / 2, $sf->y + ($sf->h - $h) / 2, $w, $h];
        } else {
            $r = [$frame->x + ($frame->w - $w) / 2, $frame->y + ($frame->h - $h) / 2, $w, $h];
        }
        $r[0] = max($sf->x, min($sf->x + $sf->w - $r[2], $r[0])); // kept in the screen
        $r[1] = max($sf->y, min($sf->y + $sf->h - $r[3], $r[1]));
        ObjC::msg('v:rbb', $this->ns, 'setFrame:display:animate:', ObjC::rect(...$r), true, $o['animate'] && $this->isVisible());
    }

    /**
     * The frame mpv's geometry $g gives a window of $w by $h on the screen of
     * visible frame $sf (IINA's windowFrameFromGeometry): a width ("1280",
     * "50%" of the screen) or a height ("x720") keeping its shape; the
     * position of its left ("+x") or right ("-x"), of its bottom ("+y") or
     * top ("-y"), a "%" of the screen placing its center; the middle of the
     * screen for a size alone. AppKit's: from the bottom left. @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function geometryFrame(string $g, float $w, float $h, $sf): array
    {
        if (preg_match('/^(?:(x?)(\d+)(%?))?(?:([+-])(\d+)(%?)([+-])(\d+)(%?))?$/', $g, $m) !== 1) {
            throw new \InvalidArgumentException("myphp: no geometry \"$g\"");
        }
        $aspect = $w / $h;
        $sized = ($m[2] ?? '') !== '';
        if ($sized && $m[1] === '') {
            $w = $m[3] === '%' ? (float)$m[2] / 100 * $sf->w : (float)$m[2]; $h = $w / $aspect;
        } elseif ($sized) {
            $h = $m[3] === '%' ? (float)$m[2] / 100 * $sf->h : (float)$m[2]; $w = $h * $aspect;
        }
        $x = ($sf->w - $w) / 2; $y = ($sf->h - $h) / 2;
        if (($m[4] ?? '') !== '') {
            $off = fn(string $v, bool $pct, float $span, float $size): float => $pct ? (float)$v / 100 * $span - $size / 2 : (float)$v;
            $ox = $off($m[5], $m[6] === '%', $sf->w, $w);
            $x = $m[4] === '+' ? $ox : $sf->w - $ox - $w;
            $oy = $off($m[8], $m[9] === '%', $sf->h, $h);
            $y = $m[7] === '+' ? $oy : $sf->h - $oy - $h;
        }
        return [$sf->x + $x, $sf->y + $y, $w, $h];
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

    /** Whether the view fills the window under its title bar (NSWindow's fullSizeContentView): a player's plugin's standalone window's property. */
    public function setFullSizeContentView(bool $on): void { $this->setStyle(self::FULL_SIZE_CONTENT, $on); }

    /** Hides the title bar's look and title, the window moved by its background, or shows them again (IINA's hideTitleBar of a plugin's standalone window). */
    public function setTitleBarHidden(bool $on): void
    {
        ObjC::msg('v:b', $this->ns, 'setTitlebarAppearsTransparent:', $on);
        ObjC::msg('v:l', $this->ns, 'setTitleVisibility:', $on ? 1 : 0); // NSWindowTitleHidden, Visible
        ObjC::msg('v:b', $this->ns, 'setMovableByWindowBackground:', $on);
    }

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
        $view = Backend::metal()->createView(ObjC::rect(0, 0, $b->w, $b->h), function ($view, DisplayList $list) use ($text): void {
            $bounds = ObjC::msg('r:', $view, 'bounds');
            $list->pathQuad ??= $text->pathQuad(...);
            $list->zoom = $this->w->runtime->frameZoom; // as the window's content
            $list->pixels = ObjC::msg('d:', $this->ns, 'backingScaleFactor'); // its fills on whole pixels
            $paint = function () use ($list, $text, $bounds): void {
                $plain = $list->world();
                $list->pushClip(0, 0, $bounds->w / $list->zoom, $bounds->h / $list->zoom);
                $this->w->runtime->paintingUnder = true;
                try {
                    foreach ($this->w->runtime->underPaints as $u) {
                        $list->setWorld($plain); // the clip as the frame had it, in the window's coordinates
                        $list->pushClip(...$u['clip']);
                        $list->setWorld($u['world']);
                        $list->setOpacity($u['opacity']);
                        $list->setMask($u['mask']);
                        Painter::paint($u['node'], $list, $text, App::theme(), $this->w->runtime);
                        $list->setMask(null);
                        $list->popClip();
                    }
                } finally {
                    $this->w->runtime->paintingUnder = false;
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
            $this->underShown = $this->w->runtime->underPaints !== [];
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
    private function placeViews(float $height): void
    {
        $added = $this->placeVideoViews($height);
        $materials = $this->placeMaterials($height, $added);
        if ($this->under !== null && ($added || $this->underNew)) {
            $this->stackUnder();
        }
        $places = [];
        foreach ($this->w->runtime->webPlaces as $p) {
            $places[spl_object_id($p['view'])] = $p; // a web view built twice shows where it was built last
        }
        $layer = ObjC::msg('p:', $this->view, 'layer');
        ObjC::msg('v:b', $layer, 'setOpaque:', $places === [] && $this->w->runtime->videoPlaces === [] && $this->w->runtime->materialPlaces === [] && !$this->transparent && $this->effect === null); // its holes let the web views, videos and materials show
        $shown = [];
        foreach ($this->w->webViewList() as $v) {
            $p = $places[spl_object_id($v)] ?? null;
            $v->place($p === null ? null : $this->w->runtime->windowRect($p['frame']), $p === null ? null : $this->w->runtime->windowRect($p['clip']), $height); // in the window's points: zoomed, the content's times the zoom
        }
        foreach ($places as $p) {
            if (in_array($p['view'], $this->w->webViewList(), true)) { $shown[] = [$p['seq'], $p['view']->clipView()]; }
        }
        $this->stackPlaced($shown, $added || $materials);
        $focus = $this->w->runtime->focus;
        $node = null;
        foreach ($places as $p) {
            if ($p['id'] === $focus) { $node = $p; }
        }
        if ($node !== null && $focus !== $this->webFocus) { // tabbed into, or focused: the page takes the keyboard
            $this->w->runtime->focusFromKeyboard() ? $node['view']->tabInto(($this->w->input->mods & \MyPHP\UI\Key::SHIFT) !== 0) : $node['view']->focus();
        }
        $this->webFocus = $node === null ? null : $focus;
    }

    /**
     * Shows the pictures of the players where the frame showed their videos
     * (Context::movie), under the native view, and hides the others: each
     * player's display layer in a view of this window's that clips it,
     * moved here from another window's if it showed there. The layer's
     * frame changes without the implicit animation of Core Animation.
     */
    private function placeVideoViews(float $height): bool
    {
        $width = ObjC::msg('r:', $this->container, 'bounds')->w;
        $added = false;
        $places = [];
        foreach ($this->w->runtime->videoPlaces as $p) {
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
                ObjC::msg('v:b', $video, 'setLayerUsesCoreImageFilters:', true); // the video equalizer's filters (Renderers::setPictureFilters)
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
            [$cx, $cy, $cw, $ch] = $this->w->runtime->windowRect($p['clip']);
            [$fx, $fy, $fw, $fh] = $this->w->runtime->windowRect($p['frame']);
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
                [[$vx, $vy, $vw, $vh], , [$dw, $dh, $dx, $dy]] = $player->picture($fw, $fh);
                ObjC::msg('v:r', $crop, 'setFrame:', ObjC::rect($rx + $vx, $ry + $fh - $vy - $vh, $vw, $vh));
                ObjC::msg('v:L', $crop, 'setAutoresizingMask:', 0);
                ObjC::msg('v:L', $video, 'setAutoresizingMask:', 0);
                ObjC::msg('v:p', $layer, 'setVideoGravity:', ObjC::str('AVLayerVideoGravityResize'));
                // The decoded picture (the video filters' crop and expand cut it, or put it in a larger one) about the middle of what shows, its offset turned as it is.
                [$ox, $oy] = match ($player->rotation()) { 90 => [-$dy, $dx], 180 => [-$dx, -$dy], 270 => [$dy, -$dx], default => [$dx, $dy] };
                ObjC::msg('v:r', $video, 'setFrame:', ObjC::rect(($vw - $dw) / 2 + $ox, ($vh - $dh) / 2 - $oy, $dw, $dh)); // y up
                ObjC::msg('v:d', $video, 'setFrameCenterRotation:', -(float)$player->rotation()); // clockwise: AppKit turns counterclockwise
                $player->layerShown($dw, $dh); // Core Image's filters of a layer work in its points
            }
            self::reverse($crop, $player->reversed());
            ObjC::msg('v:b', $clip, 'setHidden:', false);
        }
        ObjC::msg('v:', ObjC::cls('CATransaction'), 'commit');
        return $added; // a view of a video made: right under the native view, over the materials, which go back over it
    }

    /**
     * The picture in the crop view $crop reversed as it shows, left for
     * right and upside down ($reversed, Player::reversed: its flip and
     * mirror): the layer's sublayer transform, about the middle of its
     * bounds, from the anchor point AppKit gave it.
     * @param array{0: bool, 1: bool} $reversed
     */
    private static function reverse($crop, array $reversed): void
    {
        $layer = ObjC::msg('p:', $crop, 'layer');
        $b = ObjC::msg('r:', $layer, 'bounds');
        $a = ObjC::msg('q:', $layer, 'anchorPoint');
        $t = ObjC::$c->new('CATransform3D');
        [$t->m11, $t->m22, $t->m33, $t->m44] = [$reversed[0] ? -1.0 : 1.0, $reversed[1] ? -1.0 : 1.0, 1.0, 1.0];
        $t->m41 = $reversed[0] ? $b->w * (1 - 2 * $a->x) : 0.0; // the middle kept where it is
        $t->m42 = $reversed[1] ? $b->h * (1 - 2 * $a->y) : 0.0;
        ObjC::msg('v:M', $layer, 'setSublayerTransform:', $t);
    }

    /**
     * Shows the system materials where the frame showed them
     * (Node::systemMaterial): a view of each node under the native view,
     * over the videos (it blurs them, blending within the window), made
     * the first frame it shows and removed the first it does not. A
     * material of another kind than its view's makes it anew. $restack:
     * a view of a video was put right under the native view. Returns
     * whether they are to be put in order again (a view made, or
     * $restack): stackPlaced does, with the web views.
     */
    private function placeMaterials(float $height, bool $restack): bool
    {
        $shown = [];
        $added = $restack;
        ObjC::msg('v:', ObjC::cls('CATransaction'), 'begin');
        ObjC::msg('v:b', ObjC::cls('CATransaction'), 'setDisableActions:', true);
        foreach ($this->w->runtime->materialPlaces as $p) {
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
            [$cx, $cy, $cw, $ch] = $this->w->runtime->windowRect($p['clip']);
            ObjC::msg('v:r', $v, 'setFrame:', ObjC::rect($cx, $height - $cy - $ch, $cw, $ch));
            $appearance = $p['dark'] === null ? null : ObjC::msg('p:p', ObjC::cls('NSAppearance'), 'appearanceNamed:', ObjC::str($p['dark'] ? 'NSAppearanceNameDarkAqua' : 'NSAppearanceNameAqua'));
            ObjC::msg('v:p', $v, 'setAppearance:', $appearance);
            if ($glass) {
                ObjC::msg('v:d', $v, 'setCornerRadius:', $p['radius'] * $this->w->runtime->frameZoom);
            } else {
                ObjC::msg('v:d', ObjC::msg('p:', $v, 'layer'), 'setCornerRadius:', $p['radius'] * $this->w->runtime->frameZoom);
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
        ObjC::msg('v:', ObjC::cls('CATransaction'), 'commit');
        return $added;
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

    /**
     * Puts the materials and the clip views of the web views shown in the
     * order the frame painted them, right under the native view, unless
     * they are (mygo's stackWebViews, the materials with them: a sidebar's
     * page over its material, the material over an overlay's page under
     * it); $added: a video's or a material's view was put under the native
     * view, over them. @param list<array{0: int, 1: mixed}> $shown the web
     * views', where they were painted
     */
    private function stackPlaced(array $shown, bool $added): void
    {
        $order = $shown;
        foreach ($this->w->runtime->materialPlaces as $p) {
            $order[] = [$p['seq'], $this->materials[$p['id']]['view']];
        }
        if ($order === [] || (count($order) < 2 && !$added)) {
            return;
        }
        usort($order, fn($a, $b) => $a[0] <=> $b[0]);
        if (!$added) {
            $subs = ObjC::msg('p:', $this->container, 'subviews');
            $at = 0;
            for ($i = 0, $n = ObjC::msg('L:', $subs, 'count'); $i < $n && $at < count($order); $i++) {
                if (ObjC::addr(ObjC::msg('p:L', $subs, 'objectAtIndex:', $i)) === ObjC::addr($order[$at][1])) { $at++; }
            }
            if ($at === count($order)) {
                return;
            }
        }
        $first = ObjC::msg('p:', $this->ns, 'firstResponder'); // moving a view out of its superview takes the keyboard from it
        foreach ($order as [, $v]) {
            $v = ObjC::msg('p:', $v, 'retain');
            ObjC::msg('v:', $v, 'removeFromSuperview');
            ObjC::msg('v:plp', $this->container, 'addSubview:positioned:relativeTo:', $v, -1, $this->view); // NSWindowBelow: right under the native view
            ObjC::msg('v:', $v, 'release');
        }
        if ($first !== null && ObjC::addr($first) !== ObjC::addr(ObjC::msg('p:', $this->ns, 'firstResponder'))) {
            ObjC::msg('b:p', $this->ns, 'makeFirstResponder:', $first);
        }
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
        if ($this->w->parent() !== null && !$this->w->parent()->isDestroyed() && ObjC::msg('p:', $this->ns, 'parentWindow') === null) {
            ObjC::msg('v:pl', $this->w->parent()->native()->ns, 'addChildWindow:ordered:', $this->ns, 1); // NSWindowAbove
        }
    }

    /** Hides a flyout: it stops being a child window, which AppKit would order in again with its parent, and gives the keyboard it had back to the parent. */
    private function hideFlyout(): void
    {
        $key = ObjC::msg('b:', $this->ns, 'isKeyWindow');
        if ($this->w->parent() !== null && !$this->w->parent()->isDestroyed()) {
            ObjC::msg('v:p', $this->w->parent()->native()->ns, 'removeChildWindow:', $this->ns);
            if ($key && $this->w->parent()->isVisible()) {
                ObjC::msg('v:', $this->w->parent()->native()->ns, 'makeKeyWindow');
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
            $open = array_filter(self::$open, fn(Window $win) => ($f = $win->w->flyoutState()) !== null && $win->popover === null && $f['focusable'] && !$win->w->isDestroyed() && $win->isVisible());
            foreach ($open as $win) {
                $win->w->close();
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
                    $w->w->close();
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
        if ($p === null || $p->w->isDestroyed()) {
            return null;
        }
        $frame = ObjC::msg('r:', $p->ns, 'frame');
        $c = ObjC::msg('r:r', $p->ns, 'contentRectForFrameRect:', $frame);
        $loc = ObjC::msg('q:', $ev, 'locationInWindow');
        $x = $loc->x - ($c->x - $frame->x);
        $y = $c->y - $frame->y + $c->h - $loc->y;
        foreach (self::$open as $w) {
            $f = $w->w->flyoutState();
            if ($w->w->parent() !== $p->w || $f === null || $w->popover !== null || !$f['focusable'] || $w->w->isDestroyed() || !$w->isVisible()) {
                continue;
            }
            $a = $f['anchor'];
            if ($x >= $a->x && $y >= $a->y && $x < $a->x + $a->width && $y < $a->y + $a->height) {
                return $w;
            }
        }
        return null;
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
        ObjC::msg('v:l', $this->popover, 'setBehavior:', $this->w->flyoutState()['focusable'] ? 1 : 0); // NSPopoverBehaviorTransient, ApplicationDefined
        ObjC::msg('v:p', $this->popover, 'setDelegate:', $delegate);
    }

    private function flyoutSize()
    {
        $s = ObjC::$c->new('CGSize');
        $s->w = $this->w->flyoutState()['width']; $s->h = $this->w->flyoutState()['height'];
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
        $edge = [Flyout::BOTTOM => 1, Flyout::TOP => 3, Flyout::RIGHT => 2, Flyout::LEFT => 0][$this->w->flyoutState()['side']];
        if (($t = $this->w->flyoutState()['tray']) !== null) {
            $button = $t->nativeHandle();
            return [$button, ObjC::msg('r:', $button, 'bounds'), $edge];
        }
        $a = $this->w->flyoutState()['anchor'];
        $p = $this->w->parent();
        if ($p === null || $p->isDestroyed()) {
            return [null, null, $edge];
        }
        $h = ObjC::msg('r:', $p->native()->container, 'bounds')->h;
        $height = max($a->height, 1);
        return [$p->native()->container, ObjC::rect($a->x, $h - $a->y - $height, max($a->width, 1), $height), $edge];
    }

    private function showPopover(): void
    {
        [$view, $r, $edge] = $this->popoverAnchor();
        if ($view === null || ObjC::msg('b:', $this->popover, 'isShown')) {
            return;
        }
        $this->popoverClosing = false;
        if ($this->w->flyoutState()['tray'] !== null) { // the popover of a menu bar app takes the keyboard of an app the user did not activate, as AppKit's own status items' do
            ObjC::msg('v:b', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'activateIgnoringOtherApps:', true);
        }
        ObjC::msg('v:z', $this->popover, 'setContentSize:', $this->flyoutSize());
        ObjC::msg('v:rpL', $this->popover, 'showRelativeToRect:ofView:preferredEdge:', $r, $view, $edge);
        $this->ns = ObjC::msg('p:', $this->container, 'window'); // the window the popover shows in is the flyout's
        $this->w->flyoutState()['tray']?->highlight(true); // as AppKit's status items are while their menus show (mygo's e529e1a)
        if ($this->ns !== null) {
            ObjC::msg('b:p', $this->ns, 'makeFirstResponder:', $this->view);
        }
    }

    /** Moves a popover that shows to the anchor, at the size. */
    public function placePopover(): void
    {
        if (!ObjC::msg('b:', $this->popover, 'isShown')) {
            return;
        }
        ObjC::msg('v:z', $this->popover, 'setContentSize:', $this->flyoutSize());
        if ($this->w->flyoutState()['tray'] === null && ($r = $this->popoverAnchor()[1]) !== null) {
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
        if ($key && $this->w->parent() !== null && !$this->w->parent()->isDestroyed() && $this->w->parent()->isVisible()) {
            ObjC::msg('v:', $this->w->parent()->native()->ns, 'makeKeyWindow');
        }
    }

    private function cleanupPopover(): void
    {
        $this->ns = null;
        ObjC::msg('v:p', $this->popover, 'setDelegate:', null);
        if (ObjC::msg('b:', $this->popover, 'isShown')) {
            $this->w->flyoutState()['tray']?->highlight(false);
            ObjC::msg('v:b', $this->popover, 'setAnimates:', false);
            ObjC::msg('v:', $this->popover, 'close');
        }
        ObjC::msg('v:p', $this->popover, 'setContentViewController:', null);
        ObjC::msg('v:', $this->popover, 'release');
        ObjC::msg('v:', $this->popoverController, 'release');
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
    public function snapshot(float $x, float $y, int $w, int $h): Image
    {
        $window = ObjC::msg('p:', $this->view, 'window');
        $scale = $window === null ? App::scale() : ObjC::msg('d:', $window, 'backingScaleFactor');
        [$frame, $fw, $fh] = Backend::metal()->lastFrame($this->view);
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

    /** The window classes: a window (MyPHPWindow) and a panel give the keyboard back to native UI as Tab leaves a web view's page; a panel (MyPHPPanel) takes the keyboard without activating the app and is never the main window; a flyout's (MyPHPFlyout) is the key window only when it takes the keyboard, its parent staying the main window. */
    private static function definePanels(): void
    {
        if (self::$panelsDefined) {
            return;
        }
        self::$panelsDefined = true;
        // WebKit moves the keyboard out of a page past its ends through the window's key view loop: a web view in native UI gives it back to the content (mygo's tabOut).
        $keyView = fn(string $class, bool $back) => function ($self, $cmd, $view) use ($class, $back): void {
            if ((self::$open[ObjC::addr($self)] ?? null)?->tabOut($view, $back)) {
                return;
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
            'canBecomeKeyWindow' => ['B@:', 'b(pp)', fn($self, $cmd) => (self::$open[ObjC::addr($self)] ?? null)?->w->flyoutState()['focusable'] ?? false],
            'canBecomeMainWindow' => ['B@:', 'b(pp)', fn($self, $cmd) => false],
        ]);
    }
}
