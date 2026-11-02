<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI;
use FFI\CData;
use MyPHP\{App, Logger, Rectangle, WindowOptions};
use MyPHP\Native\Gl;
use MyPHP\Platform;
use MyPHP\UI\{DataDragEvent, DisplayList, Image, Key, TextInputRange, TextState};
use MyPHP\Transfer\{Data, Format, Item, Offer, Operation, Representation, Result};

/**
 * A GtkWindow (mygo's linux window): native UI draws in a GtkGLArea filling
 * it (mygo's surface), with OpenGL (Native\Gl, a renderer of the area's
 * context); GTK's frame clock paces its frames. Mouse, wheel and keys come
 * from GDK's events on the area, typing through a GtkIMContext, all into
 * the core window's Input as macOS's view gives them: the keys' macOS
 * codes, Control as the shortcut modifier (Command) and Super as Control,
 * and the text system's commands by AppKit's selectors.
 */
final class Window implements Platform\Window
{
    private const TOPLEVEL = 0, POPUP = 1;
    private const HINT_UTILITY = 5, HINT_POPUP_MENU = 9;
    private const WIN_POS_CENTER = 1;
    private const HINT_MIN_SIZE = 1 << 1, HINT_MAX_SIZE = 1 << 2, HINT_ASPECT = 1 << 4;
    private const STATE_ICONIFIED = 1 << 1, STATE_MAXIMIZED = 1 << 2, STATE_FULLSCREEN = 1 << 4, STATE_ABOVE = 1 << 5;
    private const BUTTON_PRESS = 4, BUTTON_RELEASE = 7;
    private const EVENTS = (1 << 2) | (1 << 3) | (1 << 4) | (1 << 8) | (1 << 9) | (1 << 10) | (1 << 12) | (1 << 13) | (1 << 21) | (1 << 23) | (1 << 24) | (1 << 11);
    // pointer motion, button motion, button 1 motion, button press, release, key press, release, enter, leave, focus change, scroll, smooth scroll
    private const SHIFT_MASK = 1, CONTROL_MASK = 1 << 2, MOD1_MASK = 1 << 3, BUTTON1_MASK = 1 << 8, SUPER_MASK = 1 << 26, META_MASK = 1 << 28;

    /** @var array<int, self> the windows open, by their GtkWindow's address */
    private static array $open = [];

    public ?CData $win = null;
    /** The GtkGLArea native UI draws in, its renderer, and the IM context typing goes through. */
    private ?CData $area = null, $im = null;
    /** The WebKitWebView of a window whose content is a page, which the page holds. */
    private ?CData $web = null;
    /** The overlay the area is in once it has web views, over the layout holding them (mygo's surface layers). */
    private ?CData $layers = null, $webLayout = null;
    /** @var list<SurfaceWebView> the web views of the native UI */
    private array $webViews = [];
    /** The node of the web view the content's focus went to last, whose page took the keyboard. */
    private ?int $webFocus = null;
    /** The Tab press (a copy) the frame being built answers, which a web view tabbed into gets too (mygo's pressing). */
    private ?CData $tabPress = null;
    /** The box the menu bar and the content are in, the window's accelerators, its menu bar, the last press or key that could bring up a context menu (a copy). */
    private CData $box, $accel;
    private ?CData $menubar = null, $trigger = null;
    /** The last press on the content, which a drag out of it starts from (a copy). */
    private ?CData $pressed = null;
    /** @var ?array{context: CData, event: DataDragEvent, x: float, y: float, time: int, pending: array<int, array{0: CData, 1: list<string>}>, reps: list<Representation>, failed: bool} a drop whose data GTK is fetching */
    private ?array $drop = null;
    /** @var ?array{data: Data, session: string, done: \Closure, context: ?CData, error: ?\Throwable, canceled: bool, escaped: bool} the drag out of the content on */
    private ?array $drag = null;
    /** The owner of the menu bar's items (Menus). */
    private int $menuOwner = 0;
    /** Alt is down and nothing else was pressed since: its release reveals a menu bar that hides. */
    private bool $altAlone = false;
    private ?Gl $gl = null;
    private ?\Closure $frame = null, $afterPresent = null;
    private bool $panel, $transparent = false;
    /** Whether a frame was asked for and not drawn yet; whether every ask since the last frame only paints again; whether the frame drawing now was such. */
    private bool $asked = false, $repaintOnly = true, $repaintNow = false;
    /** Whether a frame draws now, and whether one was asked for meanwhile: GTK drops a render queued from its render signal, so it is queued once the frame is done. */
    private bool $rendering = false, $again = false;
    /** The tick callback that draws the frame a frame asked for at the frame clock's next tick, from an idle. */
    private CData $onTick;
    private int $wakeSource = 0;
    private int $state = 0;
    /** Where the window was and its size, as the last configure event told them, to tell moves from resizes. */
    private array $geometry = [0, 0, 0, 0];
    /** @var array{0: int, 1: int} the least and the most size (0: none) */
    private array $minSize = [0, 0], $maxSize = [0, 0];
    private float $aspect = 0.0;
    private bool $keepAbove = false, $resizable = true;
    private \MyPHP\UI\Cursor $cursorShown = \MyPHP\UI\Cursor::Default;
    /** The last press: its button, where in the window and on the screen, when, and its count of clicks; and the keys held, for repeats. */
    private array $lastPress = ['button' => 0, 'x' => 0.0, 'y' => 0.0, 'rx' => 0.0, 'ry' => 0.0, 'time' => 0, 'clicks' => 0];
    /** @var array<int, true> */
    private array $held = [];
    /** The size the last frame drew at, in pixels, its scale, and what it cleared to: lastFrame reads it back. */
    private array $drawn = [0, 0, 1.0, [1.0, 1.0, 1.0, 1.0]];
    /** Whether a focusable flyout grabs the input, which it gets only so on X11: a press outside it dismisses it, as GtkMenu (mygo's flyout grab). */
    private bool $grabbed = false;
    /** Whether GTK destroyed the window: what it still sends of it (a render, an unrealize) draws nothing. */
    private bool $dead = false;
    /** @var ?array{0: float, 1: float, 2: float, 3: float} the background set: what native UI clears to before the theme's */
    private ?array $background = null;

    public function __construct(private readonly Backend $b, public readonly \MyPHP\Window $w, WindowOptions $o, bool $panel)
    {
        $f = Gtk::ffi();
        $this->panel = $panel;
        $flyout = $w->flyoutState() !== null;
        $this->win = $f->g_object_ref($f->gtk_window_new($flyout ? self::POPUP : self::TOPLEVEL)); // held past its destroy, which the core hears of and asks things of after (its state), until release() lets it go
        $f->gtk_window_set_default_size($this->win, max(1, $o->width), max(1, $o->height));
        if ($o->frameless || $flyout) { $f->gtk_window_set_decorated($this->win, 0); }
        if ($o->disableResize) { $this->setResizable(false); }
        if ($o->disableClose) { $f->gtk_window_set_deletable($this->win, 0); }
        if ($panel) { $f->gtk_window_set_type_hint($this->win, self::HINT_UTILITY); }
        if ($o->minWidth > 0 || $o->minHeight > 0) { $this->setMinimumSize($o->minWidth, $o->minHeight); }
        if ($o->maxWidth > 0 || $o->maxHeight > 0) { $this->setMaximumSize($o->maxWidth, $o->maxHeight); }
        if ($o->alwaysOnTop) { $this->setAlwaysOnTop(true); }
        if ($o->opacity > 0 && $o->opacity < 1) { $this->setOpacity($o->opacity); }
        $parent = $w->parent();
        if ($parent !== null && $parent->native() instanceof self) {
            $f->gtk_window_set_transient_for($this->win, $parent->native()->win);
            if ($w->isModal()) { $f->gtk_window_set_modal($this->win, 1); }
        }
        self::$open[Gtk::addr($this->win)] = $this;
        $this->box = $f->gtk_box_new(1, 0); // vertical: the menu bar, then the content
        $f->gtk_widget_set_size_request($this->box, 1, 1); // a window the user cannot resize is never smaller than its child's natural size
        $f->gtk_container_add($this->win, $this->box);
        $f->gtk_widget_show($this->box);
        $this->accel = $f->gtk_accel_group_new();
        $f->gtk_window_add_accel_group($this->win, $this->accel);
        if ($this->hasAppMenu() && $b->menus()->appMenu !== null) {
            $this->installMenu($b->menus()->appMenu);
        }
        foreach (['key-press-event', 'key-release-event'] as $signal) {
            Gtk::connect($this->win, $signal, 'event', fn($widget, $event, $data): int => $this->menuKey($f->cast('GdkEventKey*', $event), $event));
        }
        Gtk::connect($this->win, 'delete-event', 'event', fn($widget, $event, $data): int => $this->w->shouldClose() ? 0 : 1); // TRUE keeps it
        Gtk::connect($this->win, 'destroy', 'widget', function ($widget, $data): void {
            $this->dead = true;
            unset(self::$open[Gtk::addr($this->win)]);
            $this->w->closed();
        });
        Gtk::connect($this->win, 'focus-in-event', 'event', function ($widget, $event, $data): int {
            if ($this->im !== null) { Gtk::ffi()->gtk_im_context_focus_in($this->im); }
            $this->w->nativeEvent('focus');
            return 0;
        });
        Gtk::connect($this->win, 'focus-out-event', 'event', function ($widget, $event, $data): int {
            if ($this->im !== null) { Gtk::ffi()->gtk_im_context_focus_out($this->im); }
            $this->held = [];
            $this->w->nativeEvent('blur');
            return 0;
        });
        Gtk::connect($this->win, 'window-state-event', 'event', function ($widget, $event, $data): int {
            $e = Gtk::ffi()->cast('GdkEventWindowState*', $event);
            $was = $this->state;
            $this->state = $e->new_window_state;
            $changed = $was ^ $this->state;
            if ($changed & self::STATE_ICONIFIED) { $this->w->nativeEvent($this->state & self::STATE_ICONIFIED ? 'minimize' : 'restore'); }
            if ($changed & self::STATE_FULLSCREEN) {
                if ($this->menubar !== null) { Gtk::ffi()->gtk_widget_set_visible($this->menubar, $this->menuHides() ? 0 : 1); }
                $this->w->nativeEvent($this->state & self::STATE_FULLSCREEN ? 'enterFullScreen' : 'leaveFullScreen');
            }
            if ($changed & self::STATE_MAXIMIZED) { $this->w->nativeEvent('resize'); }
            return 0;
        });
        Gtk::connect($this->win, 'configure-event', 'event', function ($widget, $event, $data): int {
            $e = Gtk::ffi()->cast('GdkEventConfigure*', $event);
            [$x, $y, $width, $height] = $this->geometry;
            $this->geometry = [$e->x, $e->y, $e->width, $e->height];
            if ($e->width !== $width || $e->height !== $height) { $this->w->nativeEvent('resize'); }
            if ($e->x !== $x || $e->y !== $y) { $this->w->nativeEvent('move'); }
            return 0;
        });
        if ($flyout) {
            $f->gtk_window_set_type_hint($this->win, self::HINT_POPUP_MENU);
            $f->gtk_window_set_resizable($this->win, 1); // its size the one asked for, smaller than its content's natural size too (mygo's initFlyout)
            Gtk::connect($this->win, 'button-press-event', 'event', function ($widget, $event, $data): int {
                if (!$this->grabbed) {
                    return 0;
                }
                $e = Gtk::ffi()->cast('GdkEventButton*', $event);
                $r = $this->contentBounds(); // where the press is on the screen, as GtkMenu looks
                if ($e->x_root >= $r->x && $e->y_root >= $r->y && $e->x_root < $r->x + $r->width && $e->y_root < $r->y + $r->height) {
                    return 0;
                }
                if ($this->w->shouldClose()) { $this->close(); }
                return 1;
            });
            Gtk::connect($this->win, 'grab-broken-event', 'event', function ($widget, $event, $data): int {
                if ($this->grabbed) {
                    $this->grabbed = false;
                    Gtk::ffi()->gtk_grab_remove($this->win);
                    if ($this->w->shouldClose()) { $this->close(); } else { $this->hide(); }
                }
                return 0;
            });
        }
    }

    /** @internal the windows open @return list<self> */
    public static function all(): array
    {
        return array_values(self::$open);
    }

    /** The core window of the window that has the keyboard, or null. */
    public static function active(): ?\MyPHP\Window
    {
        foreach (self::$open as $w) {
            if (Gtk::ffi()->gtk_window_is_active($w->win)) {
                return $w->w;
            }
        }
        return null;
    }

    /** @internal App::activate: the first window that shows, forward */
    public static function presentFirst(): void
    {
        foreach (self::$open as $w) {
            if (Gtk::ffi()->gtk_widget_get_visible($w->win)) {
                Gtk::ffi()->gtk_window_present($w->win);
                return;
            }
        }
    }

    public static function hideAll(): void
    {
        foreach (self::$open as $w) { Gtk::ffi()->gtk_widget_hide($w->win); }
    }

    public static function showAll(): void
    {
        foreach (self::$open as $w) { Gtk::ffi()->gtk_widget_show($w->win); }
    }

    public static function urgent(bool $on): void
    {
        foreach (self::$open as $w) { Gtk::ffi()->gtk_window_set_urgency_hint($w->win, $on ? 1 : 0); }
    }

    /** @internal the text rasterizes at $scale now: each renderer's atlas scale */
    public static function rescaled(float $scale): void
    {
        foreach (self::$open as $w) { $w->gl?->setAtlasScale($scale); }
    }

    public function handle(): mixed
    {
        return $this->win;
    }

    /** Shows the page's WebKitWebView as the window's content, under the menu bar (mygo's createWebView packs it so). */
    public function setContent(Platform\Page $page, WindowOptions $o): void
    {
        $f = Gtk::ffi();
        $this->web = $page->handle();
        $f->gtk_box_pack_start($this->box, $this->web, 1, 1, 0);
        $f->gtk_widget_show($this->web);
    }

    /**
     * Makes the GtkGLArea native UI draws in (mygo's newSurface): its
     * renderer is made as GTK realizes it, with its context; each frame is
     * drawn in its render signal by $frame($list, $width, $height) of its
     * allocation in points.
     */
    public function createSurface(WindowOptions $o, \Closure $frame, ?\Closure $afterPresent): void
    {
        $f = Gtk::ffi();
        $this->frame = $frame;
        $this->afterPresent = $afterPresent;
        $this->transparent = $o->transparent;
        $this->area = $f->g_object_ref($f->gtk_gl_area_new()); // held as the window is (release())
        $this->onTick = $f->new('HTick', false);
        $this->onTick->fn = function ($widget, $clock, $data): int {
            Gtk::idle(fn() => $this->frameNow());
            return 0; // G_SOURCE_REMOVE
        };
        $f->gtk_gl_area_set_required_version($this->area, 3, 3);
        $f->gtk_gl_area_set_auto_render($this->area, 0); // frames when asked for
        if ($o->transparent) {
            $f->gtk_gl_area_set_has_alpha($this->area, 1);
            $f->gtk_widget_set_app_paintable($this->win, 1);
        }
        $f->gtk_widget_set_can_focus($this->area, 1);
        $f->gtk_widget_add_events($this->area, self::EVENTS);
        $f->gtk_box_pack_start($this->box, $this->area, 1, 1, 0);
        $f->gtk_widget_show($this->area);
        Gtk::connect($this->area, 'realize', 'widget', fn($widget, $data) => $this->realized());
        Gtk::connect($this->area, 'unrealize', 'widget', function ($widget, $data): void {
            $this->gl?->release();
            $this->gl = null;
        });
        Gtk::connect($this->area, 'render', 'event', fn($area, $context, $data): int => $this->render());
        Gtk::connect($this->area, 'resize', 'size', fn($area, $width, $height, $data) => $this->requestFrame());
        foreach (['button-press-event', 'button-release-event', 'motion-notify-event', 'scroll-event', 'key-press-event', 'key-release-event', 'leave-notify-event'] as $signal) {
            Gtk::connect($this->area, $signal, 'event', fn($widget, $event, $data): int => $this->event($signal, $event));
        }
        $this->connectDrags();
        $this->im = $f->gtk_im_multicontext_new();
        Gtk::connect($this->im, 'commit', 'string', fn($im, $text, $data) => $this->commit((string)$text));
        Gtk::connect($this->im, 'preedit-changed', 'widget', fn($im, $data) => $this->preedit());
        Gtk::connect($this->im, 'preedit-end', 'widget', fn($im, $data) => $this->preedit());
        $this->w->runtime->onAccess = null;
    }

    /** The area is realized: its context made, the renderer is made in it, and the IM context told the window. */
    private function realized(): void
    {
        $f = Gtk::ffi();
        $f->gtk_gl_area_make_current($this->area);
        if ($f->gtk_gl_area_get_error($this->area) !== null) {
            Logger::error('OpenGL: GTK could not make a context for the window: native UI shows nothing', 'gl');
            return;
        }
        $text = App::text();
        $this->gl = new Gl();
        $this->gl->attachAtlas($text->atlas, $text->scale());
        $this->gl->attachImages($text->images);
        $f->gtk_im_context_set_client_window($this->im, $f->gtk_widget_get_window($this->area));
        $this->requestFrame();
    }

    /**
     * A frame built and drawn into the area's framebuffer, from the main
     * loop, which GTK shows as it paints next. Not in the render signal:
     * the frame clock is painting there, and a menu or a dialog the build
     * runs (a click's) would never paint.
     */
    private function frameNow(): void
    {
        $this->asked = false;
        $this->repaintNow = $this->repaintOnly;
        $this->repaintOnly = true;
        if ($this->gl === null || $this->dead) {
            return;
        }
        $f = Gtk::ffi();
        $f->gtk_gl_area_make_current($this->area);
        $f->gtk_gl_area_attach_buffers($this->area); // the area's framebuffer, at its size now
        $this->rendering = true;
        try {
            $this->drawFrame();
        } finally {
            $this->rendering = false;
        }
        if ($this->dead) {
            return;
        }
        $f->gtk_widget_queue_draw($this->area);
        if ($this->again) { // asked for by the frame, as an animation does: at the frame clock's next tick
            $this->again = false;
            $this->asked = true;
            $f->gtk_widget_add_tick_callback($this->area, $this->onTick->fn, null, null);
        }
    }

    /**
     * The render signal, as GTK paints a framebuffer it made anew (the
     * area realized or resized): the last frame drawn again at the size
     * now, its build to follow from the resize; nothing is built here.
     */
    private function render(): int
    {
        if ($this->gl === null || $this->dead || $this->drawn[0] === 0) {
            return 0;
        }
        $f = Gtk::ffi();
        $scale = $this->backingScale();
        $pw = (int)round($f->gtk_widget_get_allocated_width($this->area) * $scale);
        $ph = (int)round($f->gtk_widget_get_allocated_height($this->area) * $scale);
        $this->gl->draw($this->gl->lastList(), max(1, $pw), max(1, $ph), $this->drawn[2], $this->clearColor());
        return 1;
    }

    private function drawFrame(): void
    {
        $f = Gtk::ffi();
        $scale = $this->backingScale();
        $wpt = (float)$f->gtk_widget_get_allocated_width($this->area);
        $hpt = (float)$f->gtk_widget_get_allocated_height($this->area);
        $list = $this->gl->nextList();
        $this->gl->setDark(App::theme()->dark);
        ($this->frame)($list, $wpt, $hpt);
        if ($this->dead) {
            return;
        }
        $pw = (int)round($wpt * $scale); $ph = (int)round($hpt * $scale);
        $clear = $this->clearColor();
        $this->gl->draw($list, max(1, $pw), max(1, $ph), $scale * $list->zoom, $clear);
        $this->drawn = [max(1, $pw), max(1, $ph), $scale * $list->zoom, $clear];
        $this->afterPresent?->__invoke();
        $this->w->runtime?->framePresented();
        $this->placeCaret();
    }

    /** What native UI clears to: transparent, the background set, or the theme's. @return array{0: float, 1: float, 2: float, 3: float} */
    private function clearColor(): array
    {
        if ($this->transparent) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        if ($this->background !== null) {
            return $this->background;
        }
        $rgb = App::theme()->background;
        return [(($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255, 1.0];
    }

    /** Tells the input method where the caret of the field taking the keyboard is, in the window: its candidates show by it. */
    private function placeCaret(): void
    {
        $input = $this->w->input;
        if ($input === null || $this->im === null || ($input->focus === null && $input->client === null && $input->textCaret === null)) {
            return;
        }
        if ($input->client !== null) {
            $r = $input->client->selection()->range->normalized();
            [[$x, $y, $w, $h]] = $input->clientRect(new TextInputRange($r->start, $r->start));
        } else {
            $f = $input->focus;
            [$x, $y, $w, $h] = $input->caretRect($f === null ? 0 : TextState::utf16Index($f->text, $f->cursor));
        }
        $rect = Gtk::ffi()->new('GdkRectangle');
        [$rect->x, $rect->y, $rect->width, $rect->height] = [(int)round($x), (int)round($y), max(1, (int)round($w)), max(1, (int)round($h))];
        Gtk::ffi()->gtk_im_context_set_cursor_location($this->im, FFI::addr($rect));
    }

    /** The modifiers of a GDK state as macOS's flags: Control is the shortcut modifier (Command), Super is Control. */
    private static function mods(int $state): int
    {
        return (($state & self::SHIFT_MASK) ? Key::SHIFT : 0) | (($state & self::CONTROL_MASK) ? Key::COMMAND : 0)
            | (($state & self::MOD1_MASK) ? Key::OPTION : 0) | (($state & (self::SUPER_MASK | self::META_MASK)) ? Key::CONTROL : 0);
    }

    /** One of GDK's events on the area, into the core window's Input. */
    private function event(string $signal, CData $event): int
    {
        $input = $this->w->input;
        if ($input === null) {
            return 0;
        }
        $f = Gtk::ffi();
        switch ($signal) {
            case 'button-press-event': case 'button-release-event':
                $e = $f->cast('GdkEventButton*', $event);
                if ($e->type !== self::BUTTON_PRESS && $e->type !== self::BUTTON_RELEASE) {
                    return 1; // GDK's double and triple presses: the clicks are counted here
                }
                if ($e->type === self::BUTTON_PRESS) {
                    $f->gtk_widget_grab_focus($this->area);
                    $this->keepTrigger($event);
                    if ($this->pressed !== null) { $f->gdk_event_free($this->pressed); }
                    $this->pressed = $f->gdk_event_copy($event);
                }
                $time = hrtime(true);
                if ($e->button === 8 || $e->button === 9) { // a mouse's back and forward buttons are keys (mygo's KeyBack, KeyForward)
                    if ($e->type === self::BUTTON_PRESS) { $input->push(['key', '', $e->button === 8 ? Key::BACK : Key::FORWARD, self::mods($e->state)]); }
                    $this->requestFrame();
                    return 1;
                }
                $button = match ($e->button) { 1 => 0, 3 => 1, default => 2 };
                $mods = self::mods($e->state);
                $input->mouse($e->x, $e->y);
                $input->mods = $mods & Key::MODIFIERS;
                if ($e->type === self::BUTTON_PRESS) {
                    $p = $this->lastPress;
                    $interval = (int)($this->b->doubleClickInterval() * 1000);
                    $clicks = $p['button'] === $e->button && $e->time - $p['time'] <= $interval && abs($e->x - $p['x']) <= 4 && abs($e->y - $p['y']) <= 4 ? $p['clicks'] + 1 : 1;
                    $this->lastPress = ['button' => $e->button, 'x' => $e->x, 'y' => $e->y, 'rx' => $e->x_root, 'ry' => $e->y_root, 'time' => $e->time, 'clicks' => $clicks];
                    $input->push(['down', $e->x, $e->y, $button, $clicks, $mods, $time]);
                } else {
                    $input->push(['up', $e->x, $e->y, $button, $this->lastPress['clicks'], $mods, $time]);
                }
                break;
            case 'motion-notify-event':
                $e = $f->cast('GdkEventMotion*', $event);
                $input->mouse($e->x, $e->y);
                $input->mods = self::mods($e->state) & Key::MODIFIERS;
                $input->push(($e->state & self::BUTTON1_MASK) ? ['drag', $e->x, $e->y, hrtime(true)] : ['move', $e->x, $e->y]);
                break;
            case 'leave-notify-event':
                $input->mouseInside = false;
                break;
            case 'scroll-event':
                $e = $f->cast('GdkEventScroll*', $event);
                $step = 50.0; // a wheel's notch, as upstream's
                [$dx, $dy, $precise] = match ($e->direction) {
                    0 => [0.0, $step, false], 1 => [0.0, -$step, false], 2 => [$step, 0.0, false], 3 => [-$step, 0.0, false],
                    default => [-$e->delta_x * $step, -$e->delta_y * $step, $e->delta_x != (int)$e->delta_x || $e->delta_y != (int)$e->delta_y],
                };
                $input->mouse($e->x, $e->y);
                $input->mods = self::mods($e->state) & Key::MODIFIERS;
                $input->push(['scroll', $e->x, $e->y, $dx, $dy, $precise, false]);
                break;
            case 'key-press-event':
                $this->keepTrigger($event);
                if (in_array($f->cast('GdkEventKey*', $event)->keyval, [0xff09, 0xfe20, 0xff89], true)) { // GDK_KEY_Tab, ISO_Left_Tab, KP_Tab
                    if ($this->tabPress !== null) { $f->gdk_event_free($this->tabPress); }
                    $this->tabPress = $f->gdk_event_copy($event);
                }
                $this->keyDown($f->cast('GdkEventKey*', $event), $event);
                break;
            case 'key-release-event':
                $e = $f->cast('GdkEventKey*', $event);
                unset($this->held[$e->hardware_keycode]);
                if ($this->im !== null && $f->gtk_im_context_filter_keypress($this->im, $event)) {
                    break;
                }
                [$code, $chars] = Keys::of($e->keyval, $e->hardware_keycode);
                $mods = self::mods($e->state);
                $input->mods = $mods & Key::MODIFIERS;
                $input->push(['keyup', $chars, $code, $mods, $chars]);
                break;
        }
        $this->requestFrame();
        return 1;
    }

    /**
     * A key pressed (as View::keyDown): to the field's key hook, then the
     * node taking its input as it comes, then the Edit menu's commands
     * (Control and C, X, V, A, Z: the menu bar leaves them to the content), then the input
     * method, then the text system's command of the key.
     */
    private function keyDown(CData $e, CData $event): void
    {
        $input = $this->w->input;
        $f = Gtk::ffi();
        [$code, $chars] = Keys::of($e->keyval, $e->hardware_keycode);
        $mods = self::mods($e->state);
        $repeat = isset($this->held[$e->hardware_keycode]);
        $this->held[$e->hardware_keycode] = true;
        if (Keys::isModifier($e->keyval)) {
            $input->mods = self::mods($e->state ^ Keys::modifierMask($e->keyval)) & Key::MODIFIERS;
            return;
        }
        if ($input->textKey($code, $chars, $mods)) {
            return;
        }
        $input->mods = $mods & Key::MODIFIERS;
        if (($mods & Key::COMMAND) !== 0 && ($edit = Keys::editCommand($code, $mods)) !== null) {
            $input->push(['edit', $edit]);
            return;
        }
        if ($input->push(['key', $chars, $code, $mods, $repeat, $chars])) {
            return; // a node taking its input as it comes took it
        }
        if ($input->client !== null && !$input->inputMethod) {
            if (Keys::types($chars) && ($mods & (Key::COMMAND | Key::CONTROL)) === 0) { $input->client->replaceText(null, $chars); }
            return;
        }
        if ($this->im !== null && $f->gtk_im_context_filter_keypress($this->im, $event)) {
            return; // typed, or composing
        }
        $selector = Keys::selector($code, $mods);
        if ($selector !== null) {
            if ($input->client !== null) {
                return; // the keys go to the node taking its input, as the view leaves them
            }
            if ($input->focus === null || !$input->focus->command($selector)) {
                $input->push(['command', $selector]);
            }
        }
    }

    /** Text the input method committed, or a key typed (View's insertText:). */
    private function commit(string $s): void
    {
        $input = $this->w->input;
        if ($input === null || $s === '') {
            return;
        }
        if ($input->client !== null) {
            $input->client->replaceText(null, $s);
        } elseif ($input->focus === null) {
            $input->composing = '';
            $input->push(['insert', $s]);
        } else {
            $input->focus->insert($s);
        }
        $this->requestFrame();
    }

    /** The input method's composition changed (View's setMarkedText:). */
    private function preedit(): void
    {
        $input = $this->w->input;
        if ($input === null || $this->im === null) {
            return;
        }
        $f = Gtk::ffi();
        $str = $f->new('char*[1]'); $cursor = $f->new('int[1]');
        $f->gtk_im_context_get_preedit_string($this->im, $str, null, $cursor);
        $s = $str[0] === null ? '' : FFI::string($str[0]);
        if ($str[0] !== null) { $f->g_free($str[0]); }
        $caret = mb_strlen($s, 'UTF-8') === 0 ? 0 : min($cursor[0], mb_strlen($s, 'UTF-8'));
        $byte = strlen(mb_substr($s, 0, $caret, 'UTF-8'));
        if ($input->client !== null) {
            $u = TextState::utf16Index($s, $byte);
            $input->client->setMarkedText(null, $s, new TextInputRange($u, $u));
        } elseif ($input->focus === null) {
            if ($input->textCaret !== null) {
                $input->composing = $s;
                $input->push(['compose', $s, $caret]);
            }
        } elseif ($s === '') {
            if ($input->focus->marked !== null) { $input->focus->setMarked('', 0); }
        } else {
            $input->focus->setMarked($s, $byte);
        }
        $this->requestFrame();
    }

    public function restoreFrame(Rectangle $r, bool $onDisplay): void
    {
        if ($onDisplay) {
            $this->setBounds($r);
            return;
        }
        Gtk::ffi()->gtk_window_resize($this->win, max(1, $r->width), max(1, $r->height));
        $this->center();
    }

    public function startFullScreen(): void
    {
        Gtk::ffi()->gtk_window_fullscreen($this->win);
    }

    public function startMaximized(): void
    {
        Gtk::ffi()->gtk_window_maximize($this->win);
    }

    public function isLegacyFullScreen(): bool
    {
        return false;
    }

    public function hasPopover(): bool
    {
        return false;
    }

    /**
     * Shows the web views where the frame placed them and hides the others
     * (mygo's PlaceWebViews), the area shaped so that the pointer reaches
     * them there; a web view's node the focus went to gives its page the
     * keyboard, as macOS's.
     */
    public function placeWebViews(): void
    {
        if ($this->webViews === []) {
            return;
        }
        $rt = $this->w->runtime;
        $places = [];
        foreach ($rt->webPlaces as $p) {
            $places[spl_object_id($p['view'])] = $p; // a web view built twice shows where it was built last
        }
        $holes = [];
        foreach ($this->w->webViewList() as $v) {
            $p = $places[spl_object_id($v)] ?? null;
            $v->place($p === null ? null : $rt->windowRect($p['frame']), $p === null ? null : $rt->windowRect($p['clip']), 0.0);
            if ($p !== null) {
                $holes[] = [$rt->windowRect($p['clip']), array_map($rt->windowRect(...), $rt->webViewCovers($p['id'], $p['clip']))];
            }
        }
        $this->shapeWebLayer($holes);
        $focus = $rt->focus;
        $node = null;
        foreach ($places as $p) {
            if ($p['id'] === $focus) { $node = $p; }
        }
        if ($node !== null && $focus !== $this->webFocus) { // tabbed into, or focused: the page takes the keyboard
            $rt->focusFromKeyboard() ? $node['view']->tabInto(($this->w->input->mods & Key::SHIFT) !== 0) : $node['view']->focus();
        }
        $this->webFocus = $node === null ? null : $focus;
        if ($this->tabPress !== null) {
            Gtk::ffi()->gdk_event_free($this->tabPress);
            $this->tabPress = null;
        }
    }

    /**
     * @internal the Tab that moved the content's focus onto a web view's
     * node, which the web view then gets, as WebKitGTK focuses no element
     * of the page as the view takes the keyboard: its first, or its last
     * going back (mygo's TabInto)
     */
    public function passTab(CData $web): void
    {
        if ($this->tabPress !== null) { Gtk::ffi()->gtk_widget_event($web, $this->tabPress); }
    }

    /**
     * Lets the pointer through the area where the web views take it: in
     * their clips but where the content painted over them (mygo's shape).
     * A GtkGLArea draws in its parent's window, the overlay's, whose input
     * shape this is; no web view shown, no shape.
     * @param list<array{0: array{0: float, 1: float, 2: float, 3: float}, 1: list<array{0: float, 1: float, 2: float, 3: float}>}> $holes
     */
    private function shapeWebLayer(array $holes): void
    {
        $f = Gtk::ffi();
        $win = $f->gtk_widget_get_parent_window($this->area);
        if ($win === null || Gtk::addr($win) === Gtk::addr($f->gtk_widget_get_window($this->win))) {
            return;
        }
        if ($holes === []) {
            $f->gdk_window_input_shape_combine_region($win, null, 0, 0);
            return;
        }
        $r = $f->new('GdkRectangle');
        [$r->x, $r->y, $r->width, $r->height] = [0, 0, $f->gtk_widget_get_allocated_width($this->area), $f->gtk_widget_get_allocated_height($this->area)];
        $region = $f->cairo_region_create_rectangle(\FFI::addr($r));
        foreach ($holes as [$clip, $covers]) {
            [$x, $y, $w, $h] = $clip; // the pixels it covers wholly
            [$r->x, $r->y] = [(int)ceil($x), (int)ceil($y)];
            [$r->width, $r->height] = [max((int)floor($x + $w) - $r->x, 0), max((int)floor($y + $h) - $r->y, 0)];
            $f->cairo_region_subtract_rectangle($region, \FFI::addr($r));
            foreach ($covers as [$x, $y, $w, $h]) { // the pixels they cover at all
                [$r->x, $r->y] = [(int)floor($x), (int)floor($y)];
                [$r->width, $r->height] = [max((int)ceil($x + $w) - $r->x, 0), max((int)ceil($y + $h) - $r->y, 0)];
                $f->cairo_region_union_rectangle($region, \FFI::addr($r));
            }
        }
        $f->gdk_window_input_shape_combine_region($win, $region, 0, 0);
        $f->cairo_region_destroy($region);
    }

    /**
     * The layout holding the web views, made with the first (mygo's
     * makeLayers): the area goes into an overlay where it was, over the
     * layout, with an alpha channel whose holes show the pages. GTK
     * realizes the area again, with a new GL context, which frees the
     * renderer's lists: a web view made by a frame's build is layered once
     * that frame is done.
     */
    public function webLayer(): CData
    {
        if ($this->webLayout !== null) {
            return $this->webLayout;
        }
        $f = Gtk::ffi();
        $this->layers = $f->gtk_overlay_new();
        $this->webLayout = $f->gtk_layout_new(null, null);
        $f->gtk_container_add($this->layers, $this->webLayout);
        $this->rendering ? Gtk::idle(fn() => $this->layer()) : $this->layer();
        return $this->webLayout;
    }

    private function layer(): void
    {
        if ($this->dead) {
            return;
        }
        $f = Gtk::ffi();
        $focused = $f->gtk_widget_has_focus($this->area);
        $f->gtk_container_remove($this->box, $this->area); // held: createSurface took a reference
        $f->gtk_gl_area_set_has_alpha($this->area, 1);
        $f->gtk_overlay_add_overlay($this->layers, $this->area);
        $f->gtk_box_pack_start($this->box, $this->layers, 1, 1, 0);
        if ($this->menubar !== null) { $f->gtk_box_reorder_child($this->box, $this->layers, 1); } // under the menu bar
        $f->gtk_widget_show_all($this->layers);
        if ($focused) { $f->gtk_widget_grab_focus($this->area); }
        $this->requestFrame(); // which places the web views
    }

    /** @internal Tab left the page of a web view past its last element, or Shift+Tab its first: the content takes the keyboard, and the Tab (mygo's tabOut). */
    public function tabOut(bool $back): void
    {
        $this->focusContent();
        $this->w->input?->push(['command', $back ? 'insertBacktab:' : 'insertTab:']); // the focus moves on from the web view's node
        $this->requestFrame();
    }

    /** @internal gives the content the keyboard back from a web view */
    public function focusContent(): void
    {
        if ($this->area !== null) { Gtk::ffi()->gtk_widget_grab_focus($this->area); }
    }

    /** @internal the wheel over web view $web, which gives it to the content (mygo's cbWebViewScroll): the event is in its window, at its allocation in the layout, which is where the area is */
    public function scrollFrom(CData $web, CData $event): bool
    {
        if ($this->dead || $this->area === null) {
            return false;
        }
        $f = Gtk::ffi();
        $copy = $f->gdk_event_copy($event);
        $e = $f->cast('GdkEventScroll*', $copy);
        $x = $f->new('int[1]'); $y = $f->new('int[1]');
        $f->gtk_widget_translate_coordinates($web, $this->area, 0, 0, $x, $y);
        $e->x += $x[0];
        $e->y += $y[0];
        $this->event('scroll-event', $copy);
        $f->gdk_event_free($copy);
        return true;
    }

    /** @internal a web view of the native UI closed */
    public function forget(SurfaceWebView $v): void
    {
        $this->webViews = array_values(array_filter($this->webViews, fn(SurfaceWebView $x) => $x !== $v));
    }

    public function placeVideos(): void {}

    /** A web view of native UI showing $page, under the area (mygo's Surface.NewWebView). */
    public function newWebView(Platform\Page $page, bool $scrollToContainer, bool $transparent): Platform\WebView
    {
        if ($this->area === null) {
            throw new \RuntimeException('myphp: web views go in windows showing native UI');
        }
        if (!$page instanceof WebView) {
            throw new \InvalidArgumentException('myphp: a web view of native UI shows a WebKitGTK page');
        }
        return $this->webViews[] = new SurfaceWebView($this, $page, $scrollToContainer, $transparent);
    }

    public function contentView(): mixed
    {
        return $this->area ?? $this->web;
    }

    public function release(): void
    {
        if ($this->wakeSource !== 0) { Gtk::cancel($this->wakeSource); }
        $this->wakeSource = 0; // the handlers stay held: GTK still sends the destroyed window's last signals
        if ($this->trigger !== null) { Gtk::ffi()->gdk_event_free($this->trigger); }
        $this->trigger = null;
        if ($this->pressed !== null) { Gtk::ffi()->gdk_event_free($this->pressed); }
        $this->pressed = null;
        $this->b->menus()->dropOwner($this->menuOwner);
        unset($this->b->menus()->roleKeys[Gtk::addr($this->accel)]);
        [$win, $area] = [$this->win, $this->area];
        Gtk::idle(function () use ($win, $area): void { // once the core is done with them
            if ($area !== null) { Gtk::ffi()->g_object_unref($area); }
            Gtk::ffi()->g_object_unref($win);
        });
    }

    public function show(): void
    {
        $f = Gtk::ffi();
        $flyout = $this->w->flyoutState();
        if ($flyout !== null) { // a popup: the keyboard only through a grab, which a focusable one takes
            $f->gtk_widget_show($this->win);
            if ($flyout['focusable'] && !$this->grabbed) {
                $seat = $f->gdk_display_get_default_seat($f->gdk_display_get_default());
                if ($f->gdk_seat_grab($seat, $f->gtk_widget_get_window($this->win), 15, 1, null, null, null, null) === 0) { // GDK_SEAT_CAPABILITY_ALL, its events its own
                    $this->grabbed = true;
                    $f->gtk_grab_add($this->win);
                }
            }
            if ($this->area !== null) { $f->gtk_widget_grab_focus($this->area); }
            return;
        }
        $f->gtk_widget_show($this->win);
        $f->gtk_window_present($this->win);
        if (($content = $this->area ?? $this->web) !== null) { $f->gtk_widget_grab_focus($content); }
    }

    /** Lets a focusable flyout's grab go. */
    private function ungrab(): void
    {
        if (!$this->grabbed) {
            return;
        }
        $this->grabbed = false;
        $f = Gtk::ffi();
        $f->gtk_grab_remove($this->win);
        $f->gdk_seat_ungrab($f->gdk_display_get_default_seat($f->gdk_display_get_default()));
    }

    public function showInactive(): void
    {
        $f = Gtk::ffi();
        $f->gtk_window_set_focus_on_map($this->win, 0);
        $f->gtk_widget_show($this->win);
        $f->gtk_window_set_focus_on_map($this->win, 1);
    }

    public function hide(): void
    {
        $this->ungrab();
        Gtk::ffi()->gtk_widget_hide($this->win);
    }

    /** Destroys the window without asking; its destroy signal tells the core. */
    public function close(): void
    {
        $this->ungrab();
        if ($this->rendering) { // closed by its own frame, which goes on drawing after: it goes once that is done
            $f = Gtk::ffi();
            $f->gtk_widget_hide($this->win);
            Gtk::idle(fn() => $f->gtk_widget_destroy($this->win));
            return;
        }
        Gtk::ffi()->gtk_widget_destroy($this->win);
    }

    public function setTitle(string $title): void
    {
        Gtk::ffi()->gtk_window_set_title($this->win, $title);
    }

    public function setRepresentedFile(string $path): void {}

    public function setDocumentEdited(bool $edited): void {}

    public function setBackgroundColor(array $rgba): void
    {
        [$r, $g, $b, $a] = $rgba;
        $this->background = [$r / 255, $g / 255, $b / 255, $a / 255];
        $this->requestFrame();
    }

    public function themeChanged(): void
    {
        $this->requestFrame();
    }

    public function hasVibrancy(): bool
    {
        return false;
    }

    /** None: GTK has no materials to show under the content, which myphp's own stand in for (Node::systemMaterial). */
    public function hasSystemMaterials(): bool
    {
        return false;
    }

    /** Asks for a frame at the frame clock's next tick; $repaint: one that only paints the last frame's nodes again, while every ask says so. */
    public function requestFrame(int $every = 1, bool $repaint = false): void
    {
        $this->repaintOnly = $this->repaintOnly && $repaint;
        if ($this->rendering) {
            $this->again = true;
            return;
        }
        if ($this->area === null || $this->asked || $this->dead) {
            return;
        }
        $this->asked = true;
        Gtk::idle(fn() => $this->frameNow());
    }

    public function wake(float $seconds, bool $repaint): void
    {
        if ($this->wakeSource !== 0) { Gtk::cancel($this->wakeSource); }
        $this->wakeSource = Gtk::timeout($seconds, function () use ($repaint): void {
            $this->wakeSource = 0;
            $this->requestFrame(1, $repaint);
        });
    }

    public function repaintAsked(): bool
    {
        return $this->repaintNow;
    }

    public function displayRate(): float
    {
        return $this->refreshRate();
    }

    public function setProgressBar(string $state, float $value): void {}

    public function lastDraw(): string
    {
        return 'OpenGL, drew whole';
    }

    public function backingScale(): float
    {
        return (float)max(1, Gtk::ffi()->gtk_widget_get_scale_factor($this->win));
    }

    public function isKey(): bool
    {
        return (bool)Gtk::ffi()->gtk_window_is_active($this->win);
    }

    public function setAcceptsFirstMouse(bool $on): void {}

    /** Takes drops of $formats on the content (mygo's SetDropFormats). @param list<string> $formats */
    public function setDropFormats(array $formats): void
    {
        if ($this->area === null || $formats === []) {
            return;
        }
        [$targets, $n] = Clipboard::entries($formats);
        Gtk::ffi()->gtk_drag_dest_set($this->area, 0, $targets, $n, self::COPY | self::MOVE);
    }

    private const COPY = 1 << 1, MOVE = 1 << 2; // GDK_ACTION_*

    /**
     * The content as a drag destination and source (mygo's
     * surface_drop.go): it offers what the targets say, unread, and reads
     * the formats the content took once it is dropped, each GTK delivers
     * as it comes; it takes files from the start.
     */
    private function connectDrags(): void
    {
        $this->setDropFormats([Format::URI_LIST]);
        Gtk::connect($this->area, 'drag-motion', 'dragAt', function ($widget, $context, $x, $y, $time, $data): int {
            [$d] = $this->offer($context);
            $taken = $this->w->dataEvent('over', $d, (float)$x, (float)$y);
            Gtk::ffi()->gdk_drag_status($context, self::gdkOperations($d->operation), $time);
            return $taken ? 1 : 0;
        });
        Gtk::connect($this->area, 'drag-leave', 'dragLeave', fn($widget, $context, $time, $data) => $this->w->dataEvent('leave', new DataDragEvent(new Offer), 0.0, 0.0));
        Gtk::connect($this->area, 'drag-drop', 'dragAt', fn($widget, $context, $x, $y, $time, $data): int => $this->dropped($context, (float)$x, (float)$y, $time));
        Gtk::connect($this->area, 'drag-data-received', 'dragReceived', fn($widget, $context, $x, $y, $sel, $info, $time, $data) => $this->received($context, $sel));
        Gtk::connect($this->area, 'drag-data-get', 'dragGet', function ($widget, $context, $sel, $info, $time, $data): void {
            if ($this->drag === null) {
                return;
            }
            $f = Gtk::ffi();
            $atom = $f->gtk_selection_data_get_target($sel);
            $format = Clipboard::format(Clipboard::atomName($atom));
            try {
                $b = $format === Format::DRAG_SESSION ? $this->drag['session'] : $this->drag['data']->read($format);
            } catch (\Throwable $e) {
                $this->drag['error'] = $e;
                return;
            }
            $f->gtk_selection_data_set($sel, $atom, 8, $b, strlen($b));
        });
        Gtk::connect($this->area, 'drag-failed', 'dragFailed', function ($widget, $context, $result, $data): int {
            if ($this->drag === null) {
                return 0;
            }
            // GTK_NO_TARGET 1 and USER_CANCELLED 2 are drops on nothing; one this cancelled stays a cancellation, released at once, without GTK's snap back holding the grab
            $explicit = $this->drag['canceled'];
            $this->drag['canceled'] = true;
            $this->drag['escaped'] = $this->drag['escaped'] || $result === 2;
            if (!$explicit && $result > 2) { $this->drag['error'] = new \RuntimeException('myphp: GTK drag failed'); }
            return $explicit ? 1 : 0;
        });
        Gtk::connect($this->area, 'drag-end', 'event', function ($widget, $context, $data): int {
            if ($this->drag === null) {
                return 0;
            }
            $r = $this->drag;
            $this->drag = null;
            $op = $r['canceled'] || $r['error'] !== null ? Operation::NONE : self::operations(Gtk::ffi()->gdk_drag_context_get_selected_action($context));
            $canceled = $op === Operation::NONE && $r['error'] === null;
            ($r['done'])(new Result($op, $canceled, $r['error'], $canceled && $r['escaped'], \MyPHP\Screen::cursorScreenPoint()));
            return 0;
        });
        Gtk::connect($this->area, 'drag-data-delete', 'event', fn($widget, $context, $data): int => 0); // the app's done removes what moved
    }

    /** What a drag offers, and the atom of each format (mygo's gtkOffer). @return array{0: DataDragEvent, 1: array<string, CData>} */
    private function offer(CData $context): array
    {
        $f = Gtk::ffi();
        $formats = []; $targets = []; $hasFiles = false;
        for ($l = $f->gdk_drag_context_list_targets($context); $l !== null; $l = $l->next) {
            $format = Clipboard::format(Clipboard::atomName($l->data));
            if ($format === Format::DRAG_SESSION) {
                continue;
            }
            if (!in_array($format, $formats, true)) { $formats[] = $format; }
            $targets[$format] = $l->data;
            if ($format === Format::URI_LIST) { // a file drop offers a URI list
                $hasFiles = true;
                if (!in_array(Format::FILE_LIST, $formats, true)) { $formats[] = Format::FILE_LIST; }
                $targets[Format::FILE_LIST] = $l->data;
            }
        }
        $session = '';
        $source = $f->gtk_drag_get_source_widget($context);
        foreach (self::$open as $w) {
            if ($source !== null && $w->area !== null && Gtk::addr($w->area) === Gtk::addr($source) && $w->drag !== null) { $session = $w->drag['session']; }
        }
        $offer = new Offer($formats, self::operations($f->gdk_drag_context_get_actions($context)), self::operations($f->gdk_drag_context_get_suggested_action($context)));
        return [new DataDragEvent($offer, $session, $hasFiles), $targets];
    }

    /** A drop: to the content at once when its data is the app's own, else once GTK delivered the formats it took. */
    private function dropped(CData $context, float $x, float $y, int $time): int
    {
        $f = Gtk::ffi();
        [$d, $targets] = $this->offer($context);
        if (!$this->w->dataEvent('over', $d, $x, $y)) {
            return 0;
        }
        if ($d->data !== null && $d->data->formats() !== []) { // the app's own drag: its items and values as they are
            $taken = $this->w->dataEvent('drop', $d, $x, $y);
            $f->gtk_drag_finish($context, $taken ? 1 : 0, $taken && $d->operation === Operation::MOVE ? 1 : 0, $time);
            return 1;
        }
        $this->cancelPendingDrop();
        $pending = [];
        foreach ($d->formats as $format) {
            if (isset($targets[$format])) {
                $a = Gtk::addr($targets[$format]);
                $pending[$a] ??= [$targets[$format], []];
                $pending[$a][1][] = $format;
            }
        }
        if ($pending === []) {
            $f->gtk_drag_finish($context, 0, 0, $time);
            return 1;
        }
        $this->drop = ['context' => $f->g_object_ref($context), 'event' => $d, 'x' => $x, 'y' => $y, 'time' => $time, 'pending' => $pending, 'reps' => [], 'failed' => false];
        foreach ($pending as [$atom]) {
            $f->gtk_drag_get_data($this->area, $context, $atom, $time);
        }
        return 1;
    }

    /** A format of the drop GTK delivered; once all are, the drop goes to the content. */
    private function received(CData $context, CData $sel): void
    {
        $drop = &$this->drop;
        if ($drop === null || Gtk::addr($drop['context']) !== Gtk::addr($context)) {
            return;
        }
        $f = Gtk::ffi();
        $a = Gtk::addr($f->gtk_selection_data_get_target($sel));
        if (!isset($drop['pending'][$a])) {
            return;
        }
        $formats = $drop['pending'][$a][1];
        unset($drop['pending'][$a]);
        $n = $f->gtk_selection_data_get_length($sel);
        $p = $f->gtk_selection_data_get_data($sel);
        if ($n < 0 || $n > 64 << 20 || ($n > 0 && $p === null)) {
            $drop['failed'] = true;
        } else {
            $b = $n > 0 ? \FFI::string($p, $n) : '';
            foreach ($formats as $format) {
                if ($format === Format::FILE_LIST && (new Data(new Item(Representation::bytes(Format::URI_LIST, $b))))->paths() === []) {
                    continue;
                }
                $drop['reps'][] = Representation::bytes($format, $b);
            }
        }
        if ($drop['pending'] !== []) {
            return;
        }
        $done = $drop;
        $this->drop = null;
        $taken = false;
        if (!$done['failed'] && $done['reps'] !== []) {
            $done['event']->data = new Data(new Item(...$done['reps']));
            $taken = $this->w->dataEvent('drop', $done['event'], $done['x'], $done['y']);
        }
        $f->gtk_drag_finish($context, $taken ? 1 : 0, $taken && $done['event']->operation === Operation::MOVE ? 1 : 0, $done['time']);
        $f->g_object_unref($done['context']);
    }

    private function cancelPendingDrop(): void
    {
        if ($this->drop !== null) {
            $f = Gtk::ffi();
            $f->gtk_drag_finish($this->drop['context'], 0, 0, $this->drop['time']);
            $f->g_object_unref($this->drop['context']);
            $this->drop = null;
        }
    }

    private static function gdkOperations(int $o): int
    {
        return (($o & Operation::COPY) !== 0 ? self::COPY : 0) | (($o & Operation::MOVE) !== 0 ? self::MOVE : 0);
    }

    private static function operations(int $n): int
    {
        return (($n & self::COPY) !== 0 ? Operation::COPY : 0) | (($n & self::MOVE) !== 0 ? Operation::MOVE : 0);
    }

    /** Whether the window shows the application menu in its menu bar: flyouts and panels show none (mygo's hasAppMenu). */
    public function hasAppMenu(): bool
    {
        return $this->w->flyoutState() === null && !$this->panel;
    }

    /** Replaces the menu bar at the top of the window with one of $m, none for null (mygo's installMenu). */
    public function installMenu(?\MyPHP\Platform\Menu $m): void
    {
        $f = Gtk::ffi();
        $menus = $this->b->menus();
        if ($this->menubar !== null) {
            $f->gtk_widget_destroy($this->menubar);
            $this->menubar = null;
            $menus->dropOwner($this->menuOwner);
            // the accelerators live in the group: a fresh one
            $f->gtk_window_remove_accel_group($this->win, $this->accel);
            unset($menus->roleKeys[Gtk::addr($this->accel)]);
            $f->g_object_unref($this->accel);
            $this->accel = $f->gtk_accel_group_new();
            $f->gtk_window_add_accel_group($this->win, $this->accel);
        }
        if ($m === null || $m->items === []) {
            return;
        }
        $this->menuOwner = $menus->newOwner();
        $this->menubar = $f->gtk_menu_bar_new();
        $menus->fill($this->menubar, $m->items, $this->accel, $this->menuOwner, $this, true);
        $f->gtk_box_pack_start($this->box, $this->menubar, 0, 0, 0);
        $f->gtk_box_reorder_child($this->box, $this->menubar, 0);
        $f->gtk_widget_show_all($this->menubar);
        $f->gtk_widget_set_no_show_all($this->menubar, 1); // shown as menuHides() says, whatever shows the window
        $f->gtk_widget_set_visible($this->menubar, $this->menuHides() ? 0 : 1);
        Gtk::connect($this->menubar, 'deactivate', 'widget', function ($shell, $data): void {
            if ($this->menuHides()) { Gtk::ffi()->gtk_widget_hide($shell); }
        });
    }

    /** Whether the menu bar shows only while its menus are open: in full screen, which leaves the content the whole screen (mygo's menuHides). */
    private function menuHides(): bool
    {
        return ($this->state & self::STATE_FULLSCREEN) !== 0;
    }

    /**
     * A key on the window, before GTK's accelerators and the content
     * (mygo's cbMenuKey): the editing roles' shortcuts are the content's
     * keys too (Ctrl+C, which interrupts in a terminal), so it gets them
     * first and GTK runs those it leaves; Alt pressed and released alone,
     * or F10, show a menu bar that hides.
     */
    private function menuKey(CData $e, CData $event): int
    {
        $f = Gtk::ffi();
        $press = $e->type === 8; // GDK_KEY_PRESS
        if ($press && isset($this->b->menus()->roleKeys[Gtk::addr($this->accel)][$f->gdk_keyval_to_lower($e->keyval) . ':' . ($e->state & Menus::MODIFIER_MASK)])
            && $f->gtk_window_propagate_key_event($this->win, $event)) {
            return 1;
        }
        if (!$this->menuHides() || $this->menubar === null || $f->gtk_widget_get_visible($this->menubar)) {
            $this->altAlone = false;
            return 0;
        }
        $alt = $e->keyval === 0xffe9 || $e->keyval === 0xffea; // Alt_L, Alt_R
        if ($press && $e->keyval === 0xffc7 && ($e->state & Menus::MODIFIER_MASK) === 0) { // F10
            $this->revealMenu();
            return 1;
        }
        if ($press) {
            $this->altAlone = $alt && ($e->state & Menus::MODIFIER_MASK) === 0;
        } elseif ($alt && $this->altAlone) {
            $this->altAlone = false;
            $this->revealMenu();
        } else {
            $this->altAlone = false;
        }
        return 0;
    }

    /** Shows a menu bar that hides and opens its first menu, as F10 does in GTK; it hides again as its menus close (mygo's revealMenu). */
    private function revealMenu(): void
    {
        $f = Gtk::ffi();
        $f->gtk_widget_show($this->menubar);
        $f->gtk_container_check_resize($this->win); // the menu opens under its item: the bar laid out now
        $list = $f->gtk_container_get_children($this->menubar);
        $first = null;
        for ($node = $list; $node !== null; $node = $node->next) {
            if ($f->gtk_widget_get_visible($node->data) && $f->gtk_widget_is_sensitive($node->data)) { $first = $node->data; break; }
        }
        $f->g_list_free($list);
        if ($first !== null) {
            $f->gtk_widget_mnemonic_activate($first, 0);
        } else {
            $f->gtk_widget_hide($this->menubar); // nothing to open, so no deactivate to come
        }
    }

    /** Keeps a copy of a press or key that could bring up a context menu, which GTK positions and grabs by. */
    private function keepTrigger(CData $event): void
    {
        $f = Gtk::ffi();
        if ($this->trigger !== null) { $f->gdk_event_free($this->trigger); }
        $this->trigger = $f->gdk_event_copy($event);
    }

    /** @internal the last press or key on the content, for a context menu (mygo's popupTrigger) */
    public function popupTrigger(): ?CData
    {
        return $this->trigger;
    }

    /** @internal the GdkWindow of the content and where the content is in it, which context menus are placed in (mygo's contentWindow) @return array{0: ?CData, 1: int, 2: int} */
    public function contentWindow(): array
    {
        $f = Gtk::ffi();
        if ($this->area === null) {
            return [$f->gtk_widget_get_window($this->win), 0, 0];
        }
        $gw = $f->gtk_widget_get_window($this->area);
        if (Gtk::addr($gw) !== Gtk::addr($f->gtk_widget_get_window($this->win))) {
            return [$gw, 0, 0]; // a window of the area's own
        }
        $x = $f->new('int[1]'); $y = $f->new('int[1]');
        $f->gtk_widget_translate_coordinates($this->area, $this->win, 0, 0, $x, $y); // below the menu bar
        return [$gw, $x[0], $y[0]];
    }

    /** A command of an editing role, to the runtime (View::command). */
    public function command(string $cmd): void
    {
        $this->w->input?->push(['edit', $cmd]);
        $this->requestFrame();
    }

    public function passThrough(\Closure $at): void {}

    /** The last frame drawn again off screen from its list: BGRA, premultiplied, rows top down, its width and height. */
    public function lastFrame(): array
    {
        if ($this->gl === null) {
            return ['', 0, 0];
        }
        [$pw, $ph, $scale, $clear] = $this->drawn;
        Gtk::ffi()->gtk_gl_area_make_current($this->area);
        return [$this->gl->readBack($this->gl->lastList(), $pw, $ph, $scale, $clear), $pw, $ph];
    }

    /** A PNG of native UI as it is, which cairo writes: its ARGB32 is the frame's premultiplied BGRA. */
    public function capture(): string
    {
        [$bgra, $w, $h] = $this->lastFrame();
        if ($w === 0) {
            throw new \RuntimeException('myphp: capturePage: nothing drawn yet');
        }
        $f = Gtk::ffi();
        $data = $f->new('unsigned char[' . strlen($bgra) . ']');
        FFI::memcpy($data, $bgra, strlen($bgra));
        $surface = $f->cairo_image_surface_create_for_data($data, 0, $w, $h, $w * 4); // CAIRO_FORMAT_ARGB32
        $path = tempnam(sys_get_temp_dir(), 'myphp-capture');
        try {
            if ($f->cairo_surface_write_to_png($surface, $path) !== 0) {
                throw new \RuntimeException('myphp: capturePage: no PNG');
            }
            return (string)file_get_contents($path);
        } finally {
            $f->cairo_surface_destroy($surface);
            @unlink($path);
        }
    }

    /** BGRA pixels as RGBA. */
    private static function rgba(string $bgra): string
    {
        $out = '';
        for ($i = 0, $n = strlen($bgra); $i < $n; $i += 4) { $out .= $bgra[$i + 2] . $bgra[$i + 1] . $bgra[$i] . $bgra[$i + 3]; }
        return $out;
    }

    public function underFrame(): ?array
    {
        return null;
    }

    public function pageDrag(): void {}

    public function hasHiddenTitleBar(): bool
    {
        return false;
    }

    public function sizeLimits(): array
    {
        return [$this->minSize, $this->maxSize];
    }

    public function frameSize(): array
    {
        $w = Gtk::ffi()->new('int[1]'); $h = Gtk::ffi()->new('int[1]');
        Gtk::ffi()->gtk_window_get_size($this->win, $w, $h);
        return [(float)$w[0], (float)$h[0]];
    }

    /**
     * Starts GTK's drag of $o's data from the last press on the content
     * (mygo's StartDataDrag): each format a target, the session's among
     * them, given as a destination asks; the preview its icon.
     */
    public function startDrag(array $o): void
    {
        if ($this->drag !== null || $this->pressed === null || $this->area === null) {
            ($o['done'])(new Result(error: new \RuntimeException('myphp: GTK drag needs a pointer gesture')));
            return;
        }
        $f = Gtk::ffi();
        [$targets, $n] = Clipboard::entries([...$o['data']->formats(), Format::DRAG_SESSION]);
        $list = $f->gtk_target_list_new($targets, $n);
        $this->drag = ['data' => $o['data'], 'session' => $o['session'], 'done' => $o['done'], 'context' => null, 'error' => null, 'canceled' => false, 'escaped' => false];
        $context = $f->gtk_drag_begin_with_coordinates($this->area, $list, self::gdkOperations($o['operations']), 1, $this->pressed, (int)$o['x'], (int)$o['y']);
        $f->gtk_target_list_unref($list);
        if ($context === null) {
            $this->drag = null;
            ($o['done'])(new Result(error: new \RuntimeException('myphp: GTK failed to start drag')));
            return;
        }
        $this->drag['context'] = $context;
        if ($o['preview'] !== null) { // premultiplied BGRA: cairo's ARGB32
            $buf = $f->new('unsigned char[' . strlen($o['preview']) . ']');
            \FFI::memcpy($buf, $o['preview'], strlen($o['preview']));
            $surface = $f->cairo_image_surface_create_for_data($buf, 0, $o['width'], $o['height'], $o['width'] * 4); // CAIRO_FORMAT_ARGB32
            $pix = $f->gdk_pixbuf_get_from_surface($surface, 0, 0, $o['width'], $o['height']);
            $f->cairo_surface_destroy($surface);
            if ($pix !== null) {
                $f->gtk_drag_set_icon_pixbuf($context, $pix, (int)$o['hotX'], (int)$o['hotY']);
                $f->g_object_unref($pix);
            }
        }
    }

    public function cancelDrag(): void
    {
        if ($this->drag !== null && $this->drag['context'] !== null) {
            [$this->drag['canceled'], $this->drag['escaped']] = [true, true];
            Gtk::ffi()->gtk_drag_cancel($this->drag['context']);
        }
    }

    public function titleBar(): array
    {
        return ['height' => 0.0, 'left' => 0.0, 'right' => 0.0];
    }

    /** Moves the window with the pointer from the last press, or on a double click maximizes or restores it, as GTK's title bars do. */
    public function dragWindow(int $clicks): void
    {
        $f = Gtk::ffi();
        if ($clicks === 2) {
            $f->gtk_window_is_maximized($this->win) ? $f->gtk_window_unmaximize($this->win) : $f->gtk_window_maximize($this->win);
            return;
        }
        $p = $this->lastPress;
        if ($p['button'] !== 0) {
            $f->gtk_window_begin_move_drag($this->win, $p['button'], (int)$p['rx'], (int)$p['ry'], $p['time']);
        }
    }

    public function refreshRate(): float
    {
        $f = Gtk::ffi();
        $gw = $f->gtk_widget_get_window($this->win);
        $display = $f->gdk_display_get_default();
        $m = $gw === null || $display === null ? null : $f->gdk_display_get_monitor_at_window($display, $gw);
        $mhz = $m === null ? 0 : $f->gdk_monitor_get_refresh_rate($m);
        return $mhz > 0 ? $mhz / 1000 : 60.0;
    }

    public function showCursor(\MyPHP\UI\Cursor $cursor): void
    {
        if ($cursor === $this->cursorShown || $this->area === null) {
            return;
        }
        $f = Gtk::ffi();
        $gw = $f->gtk_widget_get_window($this->area);
        if ($gw === null) {
            return;
        }
        $this->cursorShown = $cursor;
        $f->gdk_window_set_cursor($gw, $cursor === \MyPHP\UI\Cursor::Default ? null : $f->gdk_cursor_new_from_name($f->gdk_display_get_default(), $cursor->value));
    }

    public function setVibrancy(string $material): void {}

    /** Whether the window shows: mapped, and not minimized. */
    public function showing(): bool
    {
        return (bool)Gtk::ffi()->gtk_widget_get_mapped($this->win) && ($this->state & self::STATE_ICONIFIED) === 0;
    }

    public function isVisible(): bool
    {
        return (bool)Gtk::ffi()->gtk_widget_get_visible($this->win);
    }

    public function focus(): void
    {
        Gtk::ffi()->gtk_window_present($this->win);
    }

    public function blur(): void {}

    public function isFocused(): bool
    {
        return $this->isKey();
    }

    public function minimize(): void
    {
        Gtk::ffi()->gtk_window_iconify($this->win);
    }

    public function isMinimized(): bool
    {
        return ($this->state & self::STATE_ICONIFIED) !== 0;
    }

    public function maximize(): void
    {
        Gtk::ffi()->gtk_window_maximize($this->win);
    }

    public function unmaximize(): void
    {
        Gtk::ffi()->gtk_window_unmaximize($this->win);
    }

    public function isMaximized(): bool
    {
        return (bool)Gtk::ffi()->gtk_window_is_maximized($this->win);
    }

    public function restore(): void
    {
        Gtk::ffi()->gtk_window_deiconify($this->win);
    }

    public function setFullScreen(bool $on): void
    {
        $on ? Gtk::ffi()->gtk_window_fullscreen($this->win) : Gtk::ffi()->gtk_window_unfullscreen($this->win);
    }

    public function isFullScreen(): bool
    {
        return ($this->state & self::STATE_FULLSCREEN) !== 0;
    }

    public function setLegacyFullScreen(bool $on): void
    {
        $this->setFullScreen($on);
    }

    /** Centers the window on its monitor's work area. */
    public function center(): void
    {
        $f = Gtk::ffi();
        $display = $f->gdk_display_get_default();
        $m = $display === null ? null : ($f->gdk_display_get_primary_monitor($display) ?? $f->gdk_display_get_monitor($display, 0));
        if ($m === null) {
            $f->gtk_window_set_position($this->win, self::WIN_POS_CENTER);
            return;
        }
        $wa = $f->new('GdkRectangle');
        $f->gdk_monitor_get_workarea($m, FFI::addr($wa));
        [$w, $h] = $this->frameSize();
        $f->gtk_window_move($this->win, (int)($wa->x + ($wa->width - $w) / 2), (int)($wa->y + ($wa->height - $h) / 2));
    }

    public function setBounds(Rectangle $r): void
    {
        $f = Gtk::ffi();
        $f->gtk_window_move($this->win, $r->x, $r->y);
        $f->gtk_window_resize($this->win, max(1, $r->width), max(1, $r->height));
    }

    /** The window's frame on the screen, the window manager's decorations included, as GDK has it once the window is realized. */
    public function bounds(): Rectangle
    {
        $f = Gtk::ffi();
        $gw = $f->gtk_widget_get_window($this->win);
        if ($gw === null) {
            $x = $f->new('int[1]'); $y = $f->new('int[1]');
            $f->gtk_window_get_position($this->win, $x, $y);
            [$w, $h] = $this->frameSize();
            return new Rectangle($x[0], $y[0], (int)$w, (int)$h);
        }
        $r = $f->new('GdkRectangle');
        $f->gdk_window_get_frame_extents($gw, FFI::addr($r));
        return new Rectangle($r->x, $r->y, $r->width, $r->height);
    }

    public function setContentBounds(Rectangle $r): void
    {
        $this->setBounds(new Rectangle($r->x, $r->y, $r->width, $r->height + $this->contentInset()));
    }

    /** The height of the menu bar above the content while it shows (mygo's contentInsets). */
    private function contentInset(): int
    {
        $f = Gtk::ffi();
        if ($this->menubar === null || !$f->gtk_widget_get_visible($this->menubar)) {
            return 0;
        }
        $min = $f->new('int[1]'); $natural = $f->new('int[1]');
        $f->gtk_widget_get_preferred_height($this->menubar, $min, $natural);
        return $natural[0];
    }

    /** Where the window's content is on the screen, and its size (mygo's contentOrigin). */
    public function contentBounds(): Rectangle
    {
        $f = Gtk::ffi();
        [$w, $h] = $this->frameSize();
        $gw = $f->gtk_widget_get_window($this->win);
        if ($gw === null) {
            return $this->bounds();
        }
        $x = $f->new('int[1]'); $y = $f->new('int[1]');
        $f->gdk_window_get_origin($gw, $x, $y);
        $top = $this->contentInset();
        return new Rectangle($x[0], $y[0] + $top, (int)$w, (int)$h - $top);
    }

    public function magnify(float $by): void {}

    /**
     * The window sized to a film as it opens, as Darwin\Window::fitVideo
     * does (IINA's settings): its content $o['w'] by $o['h'], in physical
     * pixels where $o['physical'], times $o['ratio'] (negative: as large as
     * the work area takes), no larger than the monitor's work area nor
     * smaller than the window may be; placed by mpv's geometry where
     * given, else centered where it was (on the work area for a fit). Its
     * size is the content's, the menu bar over it; GTK's resize is not
     * animated.
     * @param array{w: float, h: float, physical: bool, ratio: float, resize: bool, geometry: ?string, animate: bool} $o
     */
    public function fitVideo(array $o): void
    {
        if ($o['w'] <= 0 || $o['h'] <= 0) { return; }
        $f = Gtk::ffi();
        $display = $f->gdk_display_get_default();
        $gw = $f->gtk_widget_get_window($this->win);
        $m = $display === null ? null : (($gw === null ? null : $f->gdk_display_get_monitor_at_window($display, $gw)) ?? $f->gdk_display_get_primary_monitor($display) ?? $f->gdk_display_get_monitor($display, 0));
        if ($m === null) {
            Logger::warn('no monitor to fit the window to the film on', 'linux');
            return;
        }
        $wa = $f->new('GdkRectangle');
        $f->gdk_monitor_get_workarea($m, FFI::addr($wa));
        $sf = [(float)$wa->x, (float)$wa->y, (float)$wa->width, (float)$wa->height - $this->contentInset()];
        $cur = $this->contentBounds();
        [$w, $h] = [(float)$o['w'], (float)$o['h']];
        $geometry = $o['geometry'] === null || $o['geometry'] === '' ? null : $o['geometry'];
        if ($o['resize']) {
            if ($o['physical']) { $k = $this->backingScale(); [$w, $h] = [$w / $k, $h / $k]; }
            if ($o['ratio'] < 0) {
                $k = min($sf[2] / $w, $sf[3] / $h); [$w, $h] = [$w * $k, $h * $k];
            } else {
                [$w, $h] = [$w * $o['ratio'], $h * $o['ratio']];
            }
        }
        $k = min(1.0, $sf[2] / $w, $sf[3] / $h); [$w, $h] = [$w * $k, $h * $k]; // no larger than the work area
        $k = max(1.0, $this->minSize[0] / $w, $this->minSize[1] / $h); [$w, $h] = [$w * $k, $h * $k]; // no smaller than the window may be
        if ($geometry !== null) {
            $r = self::geometryFrame($geometry, $w, $h, $sf);
        } elseif (!$o['resize']) {
            return;
        } elseif ($o['ratio'] < 0) {
            $r = [$sf[0] + ($sf[2] - $w) / 2, $sf[1] + ($sf[3] - $h) / 2, $w, $h];
        } else {
            $r = [$cur->x + ($cur->width - $w) / 2, $cur->y + ($cur->height - $h) / 2, $w, $h];
        }
        $r[0] = max($sf[0], min($sf[0] + $sf[2] - $r[2], $r[0])); // kept in the work area
        $r[1] = max($sf[1], min($sf[1] + $sf[3] - $r[3], $r[1]));
        $this->setContentBounds(new Rectangle((int)round($r[0]), (int)round($r[1]), (int)round($r[2]), (int)round($r[3])));
    }

    /**
     * The frame mpv's geometry $g gives content of $w by $h in work area
     * $sf ([x, y, w, h], y down), as Darwin\Window::geometryFrame reads it
     * (IINA's windowFrameFromGeometry): a width ("1280", "50%") or a height
     * ("x720") keeping its shape; the left ("+x") or right ("-x"), the
     * bottom ("+y") or top ("-y") at a distance, a "%" placing its center;
     * the middle of the work area for a size alone. @param array{0: float, 1: float, 2: float, 3: float} $sf @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function geometryFrame(string $g, float $w, float $h, array $sf): array
    {
        if (preg_match('/^(?:(x?)(\d+)(%?))?(?:([+-])(\d+)(%?)([+-])(\d+)(%?))?$/', $g, $m) !== 1) {
            throw new \InvalidArgumentException("myphp: no geometry \"$g\"");
        }
        $aspect = $w / $h;
        $sized = ($m[2] ?? '') !== '';
        if ($sized && $m[1] === '') {
            $w = $m[3] === '%' ? (float)$m[2] / 100 * $sf[2] : (float)$m[2]; $h = $w / $aspect;
        } elseif ($sized) {
            $h = $m[3] === '%' ? (float)$m[2] / 100 * $sf[3] : (float)$m[2]; $w = $h * $aspect;
        }
        $x = ($sf[2] - $w) / 2; $up = ($sf[3] - $h) / 2; // from the left, and from the bottom as IINA's
        if (($m[4] ?? '') !== '') {
            $off = fn(string $v, bool $pct, float $span, float $size): float => $pct ? (float)$v / 100 * $span - $size / 2 : (float)$v;
            $ox = $off($m[5], $m[6] === '%', $sf[2], $w);
            $x = $m[4] === '+' ? $ox : $sf[2] - $ox - $w;
            $oy = $off($m[8], $m[9] === '%', $sf[3], $h);
            $up = $m[7] === '+' ? $oy : $sf[3] - $oy - $h;
        }
        return [$sf[0] + $x, $sf[1] + $sf[3] - $up - $h, $w, $h]; // y down
    }

    public function setMinimumSize(int $width, int $height): void
    {
        $this->minSize = [$width, $height];
        $this->geometryHints();
    }

    public function setMaximumSize(int $width, int $height): void
    {
        $this->maxSize = [$width, $height];
        $this->geometryHints();
    }

    public function setAspectRatio(float $ratio): void
    {
        $this->aspect = $ratio;
        $this->geometryHints();
    }

    /** GTK's hints of the least and the most size and the aspect ratio. */
    private function geometryHints(): void
    {
        $f = Gtk::ffi();
        $g = $f->new('GdkGeometry');
        $mask = 0;
        if ($this->minSize[0] > 0 || $this->minSize[1] > 0) {
            [$g->min_width, $g->min_height] = $this->minSize;
            $mask |= self::HINT_MIN_SIZE;
        }
        if ($this->maxSize[0] > 0 || $this->maxSize[1] > 0) {
            $g->max_width = $this->maxSize[0] > 0 ? $this->maxSize[0] : 32767;
            $g->max_height = $this->maxSize[1] > 0 ? $this->maxSize[1] : 32767;
            $mask |= self::HINT_MAX_SIZE;
        }
        if ($this->aspect > 0) {
            $g->min_aspect = $g->max_aspect = $this->aspect;
            $mask |= self::HINT_ASPECT;
        }
        $f->gtk_window_set_geometry_hints($this->win, null, FFI::addr($g), $mask);
    }

    public function setResizable(bool $on): void
    {
        $this->resizable = $on;
        Gtk::ffi()->gtk_window_set_resizable($this->win, $on ? 1 : 0);
    }

    public function isResizable(): bool
    {
        return (bool)Gtk::ffi()->gtk_window_get_resizable($this->win);
    }

    public function setMovable(bool $on): void {}

    public function isMovable(): bool
    {
        return true;
    }

    public function setFullSizeContentView(bool $on): void {}

    public function setTitleBarHidden(bool $on): void {}

    public function setMinimizable(bool $on): void {}

    public function isMinimizable(): bool
    {
        return !$this->panel;
    }

    public function setMaximizable(bool $on): void {}

    public function isMaximizable(): bool
    {
        return $this->isResizable() && !$this->panel;
    }

    public function setClosable(bool $on): void
    {
        Gtk::ffi()->gtk_window_set_deletable($this->win, $on ? 1 : 0);
    }

    public function isClosable(): bool
    {
        return (bool)Gtk::ffi()->gtk_window_get_deletable($this->win);
    }

    public function setAlwaysOnTop(bool $on): void
    {
        $this->keepAbove = $on;
        Gtk::ffi()->gtk_window_set_keep_above($this->win, $on ? 1 : 0);
    }

    public function isAlwaysOnTop(): bool
    {
        return $this->keepAbove || ($this->state & self::STATE_ABOVE) !== 0;
    }

    public function setOpacity(float $v): void
    {
        Gtk::ffi()->gtk_widget_set_opacity($this->win, $v);
    }

    public function opacity(): float
    {
        return Gtk::ffi()->gtk_widget_get_opacity($this->win);
    }

    public function setHasShadow(bool $on): void {}

    public function hasShadow(): bool
    {
        return true;
    }

    public function setWindowButtonVisibility(bool $visible): void {}

    public function setIgnoreMouseEvents(bool $on): void {}

    public function setContentProtection(bool $on): void {}

    public function flashFrame(bool $flash): void
    {
        Gtk::ffi()->gtk_window_set_urgency_hint($this->win, $flash ? 1 : 0);
    }

    public function setVisibleOnAllWorkspaces(bool $on): void
    {
        $on ? Gtk::ffi()->gtk_window_stick($this->win) : Gtk::ffi()->gtk_window_unstick($this->win);
    }

    public function placePopover(): void {}

    /** The part of the last frame at ($x, $y), $w × $h points, as an image. */
    public function snapshot(float $x, float $y, int $w, int $h): Image
    {
        [$bgra, $pw, $ph] = $this->lastFrame();
        $s = $this->drawn[2];
        [$x0, $y0, $cw, $ch] = [(int)round($x * $s), (int)round($y * $s), max(1, (int)round($w * $s)), max(1, (int)round($h * $s))];
        $out = '';
        for ($r = 0; $r < $ch; $r++) {
            $row = $y0 + $r;
            $line = $row >= 0 && $row < $ph ? substr($bgra, ($row * $pw + max(0, $x0)) * 4, max(0, min($cw, $pw - max(0, $x0))) * 4) : '';
            $out .= str_pad($line, $cw * 4, "\0");
        }
        return Image::fromPixels($cw, $ch, self::rgba($out));
    }
}
