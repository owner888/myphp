<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\{iOS, Metal, ObjC};
use MyPHP\Platform;
use MyPHP\{App, L10n, Logger, Rectangle, WindowOptions};
use MyPHP\UI\{AccessElements, AccessTree, DisplayList, Image, Node, TextInputRange, TextState};

final class Window implements Platform\Window
{
    private static ?self $instance = null;
    private ?Closure $frame = null;
    private ?Closure $afterPresent = null;
    private bool $asked = false;
    private bool $repaintOnly = false;
    /** What VoiceOver was last given of the frames, which it is given again only as it changes. */
    private AccessElements $access;
    private float $scale = 3.0;
    private float $width = 393.0;
    private float $height = 852.0;

    // Touch gesture tracking
    private bool $touchActive = false;
    private float $touchStartX = 0.0;
    private float $touchStartY = 0.0;
    private float $touchLastX = 0.0;
    private float $touchLastY = 0.0;
    private int $touchStartTime = 0;
    private bool $touchIsScrolling = false;
    private bool $touchInField = false;
    /** A finger on a node dragged along one axis moved along it: the drag's, not the scroll's, until it lifts. */
    private bool $touchAxisTaken = false;
    private int $longPressId = 0;
    /** The touch opened a context menu: its moves and lift are the menu's (Runtime::$touchMenuFinger). */
    private bool $touchMenuHeld = false;
    /** @var list<array{0: int, 1: float, 2: float}> */
    private array $touchHistory = [];
    private bool $keyboardVisible = false;
    private $view = null;
    private ?Metal $metal = null;
    /** The launcher's view over the materials, in which what their nodes hold paints (Runtime::$overLayer), adopted as it is first needed; whether it shows anything; the materials as last given to the launcher. */
    private $over = null;
    private bool $overShown = false;
    private string $placed = '';

    /**
     * Node::systemMaterial's names as the launcher's kinds: 0 and 1 glass
     * and clear glass (UIGlassEffect's styles on iOS 26, the chrome and the
     * ultra-thin blurs before), else a UIBlurEffectStyle: the system
     * materials thin to thick as AppKit's materials rank them.
     */
    public const MATERIALS = [
        'glass' => 0, 'clear-glass' => 1,
        'tooltip' => 6, 'selection' => 6, 'under-page' => 6,
        'menu' => 7, 'popover' => 7, 'hud' => 7, 'acrylic' => 7,
        'sidebar' => 8, 'sheet' => 8, 'content' => 8, 'header' => 8, 'mica' => 8, 'tabbed' => 8,
        'window' => 9, 'under-window' => 9, 'fullscreen-ui' => 9,
        'titlebar' => 10,
    ];
    private int $wakeId = 0;
    private mixed $touchEv = null, $inputEv = null, $accessAct = null;
    private mixed $safeTop = null, $safeBottom = null, $safeLeft = null, $safeRight = null;

    public function __construct(
        private readonly Backend $b,
        public readonly \MyPHP\Window $w,
        WindowOptions $o,
        bool $panel
    ) {
        if (self::$instance !== null) {
            throw new Platform\Unsupported('iOS platform only supports a single full-screen window');
        }
        self::$instance = $this;

        $f = iOS::ffi();
        $this->width = (float)$f->myphp_ios_get_screen_width();
        $this->height = (float)$f->myphp_ios_get_screen_height();
        $this->scale = max(1.0, $f->myphp_ios_get_screen_scale());
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function handle(): mixed
    {
        return $this;
    }

    public function setContent(Platform\Page $page, WindowOptions $o): void
    {
        throw new Platform\Unsupported('WebView as main window content not supported yet on iOS');
    }

    public function createSurface(WindowOptions $o, Closure $frame, ?Closure $afterPresent): void
    {
        $this->frame = $frame;
        $this->afterPresent = $afterPresent;
        // the launcher's MTKView, drawn by the one Metal renderer from PHP's thread (Metal::render), each frame the run loop asks for
        $this->view = ObjC::import(iOS::ffi()->myphp_ios_get_view());
        $this->metal = Backend::metal();
        $this->metal->adopt($this->view);
        // UIKit's materials go over the Metal view, blurring the page it shows, and what they hold paints over them: nodes belowMaterials() are under them as they are
        $this->w->runtime->underLayer = false;
        $this->w->runtime->overLayer = true;
        Metal::setClearColor($this->view, App::theme()->background);
        // VoiceOver reads the frames' elements, once it asked for them (pollEvents): the launcher's UIAccessibilityElements
        $this->access = new AccessElements();
        $this->w->runtime->onAccess = function (AccessTree $t): void {
            if (($json = $this->access->encode($t, L10n::in(App::locale())->get('showMenu'))) !== null) { iOS::ffi()->myphp_ios_access_update($json); }
        };
        $this->requestFrame();
    }

    public function requestFrame(int $every = 1, bool $repaint = false): void
    {
        if ($this->asked) {
            return;
        }
        $this->asked = true;
        $this->repaintOnly = $repaint;
        iOS::ffi()->myphp_ios_request_render();
        $this->b->signal();
    }

    public function pollEvents(): void
    {
        try {
            $f = iOS::ffi();
            $ev = $this->touchEv ??= $f->new('MyphpTouchEvent');
            while ($f->myphp_ios_poll_touch_event(\FFI::addr($ev)) > 0) {
                $this->onTouch((int)$ev->type, (float)$ev->x, (float)$ev->y);
            }

            $iev = $this->inputEv ??= $f->new('MyphpInputEvent');
            while ($f->myphp_ios_poll_input_event(\FFI::addr($iev)) > 0) {
                if ((int)$iev->type === 0) {
                    $str = \FFI::string($iev->text);
                    $this->onImeCommit($str);
                } elseif ((int)$iev->type === 1) {
                    $this->onBackspace();
                } elseif ((int)$iev->type === 2) {
                    $this->onImeCompose(\FFI::string($iev->text));
                }
            }

            // VoiceOver asked for the elements: the frames describe themselves from now on; what it does to them, as AccessNode actions
            if ($f->myphp_ios_poll_access_on() > 0) {
                $this->w->input?->push(['accessOn']);
                $this->requestFrame();
            }
            $act = $this->accessAct ??= $f->new('MyphpAccessAction');
            while ($f->myphp_ios_poll_access_action(\FFI::addr($act)) > 0) {
                $this->w->input?->push(['access', (int)$act->id, (int)$act->action, '', (int)$act->index, 0]);
                $this->requestFrame();
            }

            if ($f->myphp_ios_poll_theme_changed() > 0) {
                $this->b->h->themeChanged(); // App::systemChanged: the theme follows the appearance, the text size and Reduce Motion anew
                $this->requestFrame();
            }

            if ($f->myphp_ios_take_needs_render() > 0) {
                $this->requestFrame();
            }
        } catch (\Throwable $e) {
            Logger::error('pollEvents: ' . $e->getMessage(), 'ios');
            throw $e;
        }
    }

    /** The text the input method is composing (UITextInput's marked text), '' once it commits or gives up: shown in the field, underlined, as on the desktop. */
    public function onImeCompose(string $text): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }
        if ($input->client !== null) {
            $u = TextState::utf16Index($text, strlen($text));
            $input->client->setMarkedText(null, $text, new TextInputRange($u, $u));
        } elseif ($input->focus !== null) {
            $input->focus->setMarked($text, strlen($text));
        }
        $this->requestFrame();
    }

    public function onImeCommit(string $text): void
    {
        $input = $this->w->input;
        if ($input === null || $text === '') {
            return;
        }
        if ($text === "\n" && $input->focus !== null && !$input->focus->multiline) {
            $input->focus->command('insertNewline:');
            $input->push(['key', "\n", \MyPHP\UI\Key::RETURN, 0, false]);
            $this->requestFrame();
            return;
        }
        if ($input->client !== null) {
            $input->client->replaceText(null, $text);
        } elseif ($input->focus !== null) {
            $input->focus->insert($text);
        } else {
            $input->push(['insert', $text]);
        }
        $this->requestFrame();
    }

    public function onBackspace(): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }
        if ($input->focus !== null) {
            $input->focus->deleteBack();
        } elseif ($input->client !== null) {
            $input->push(['key', '', \MyPHP\UI\Key::BACKSPACE, 0, false]); // a key event: its text, its code, the modifiers, a repeat
        }
        $this->requestFrame();
    }

    public function onTouch(int $type, float $x, float $y): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }

        $ptX = $x;
        $ptY = $y;
        $time = hrtime(true);

        $input->mouse($ptX, $ptY);
        $input->mods = 0;
        if ($this->w->runtime !== null) {
            $this->w->runtime->touchMode = true;
        }

        switch ($type) {
            case 0: // touchesBegan
                $this->touchActive = true;
                $this->touchStartX = $ptX;
                $this->touchStartY = $ptY;
                $this->touchLastX = $ptX;
                $this->touchLastY = $ptY;
                $this->touchStartTime = $time;
                $this->touchIsScrolling = false;
                $this->touchAxisTaken = false;
                $this->touchMenuHeld = false;
                $this->touchHistory = [[$time, $ptX, $ptY]];

                // Check if touch starts inside focused text field
                $this->touchInField = false;
                $rt = $this->w->runtime;
                if ($input->focus !== null && $rt !== null && $rt->focus !== null) {
                    $r = $rt->rectOf($rt->focus);
                    if ($r !== null && $ptX >= $r[0] && $ptX < $r[0] + $r[2] && $ptY >= $r[1] && $ptY < $r[1] + $r[3]) {
                        $this->touchInField = true;
                    }
                }

                // Push pointer down immediately so all controls enter their native pressed/dragging state
                $input->push(['down', $ptX, $ptY, 0, 1, 0, $time]);
                $hitId = $rt?->pressTarget($ptX, $ptY);
                if ($hitId !== null) {
                    $rt->flashTouch($hitId, 2.0);
                }
                $this->requestFrame();

                $pressId = ++$this->longPressId;
                App::after(0.5, function () use ($pressId, $ptX, $ptY, $time) {
                    if ($this->touchActive && !$this->touchIsScrolling && !$this->touchAxisTaken && $this->longPressId === $pressId) {
                        $input = $this->w->input;
                        if ($input !== null && $this->w->runtime?->hasContextMenuAt($ptX, $ptY)) { // a node with a menu: it opens, as UIKit's context menu on a long press, and the press is no tap
                            $this->w->runtime->cancelTouchPress();
                            $this->w->runtime->haptic('medium');
                            $input->mouse($ptX, $ptY);
                            $input->push(['down', $ptX, $ptY, 1, 1, 0, $time]);
                            $input->push(['up', $ptX, $ptY, 1, 1, 0, $time]);
                            $this->touchMenuHeld = true; // the touch is the menu's now: its moves slide over the items, its lift chooses one, and they scroll, swipe and tap nothing else, as UIKit's context menu cancelling the other gestures
                            $this->w->runtime->touchMenuFinger = [$ptX, $ptY, false];
                            $this->requestFrame();
                        } elseif ($input !== null) {
                            $input->mouse($ptX, $ptY);
                            $input->push(['down', $ptX, $ptY, 0, 2, 0, $time]);
                            $this->requestFrame();
                        }
                    }
                });
                break;

            case 1: // touchesMoved
                if (!$this->touchActive) {
                    break;
                }
                if ($this->touchMenuHeld) { // the finger that opened a context menu: the menu's (Context::touchMenu)
                    if ($this->w->runtime !== null) { $this->w->runtime->touchMenuFinger = [$ptX, $ptY, false]; }
                    $this->requestFrame();
                    break;
                }
                $rt = $this->w->runtime;
                if (hypot($ptX - $this->touchStartX, $ptY - $this->touchStartY) > 10.0) { // moved off: no long press (UILongPressGestureRecognizer's allowableMovement)
                    ++$this->longPressId;
                }
                if ($this->touchInField || ($rt !== null && !$this->touchIsScrolling && $rt->isDraggingControl())) {
                    // A node dragged along one axis alone (a row swiped sideways, a sheet pulled down) takes the finger
                    // once it moved 8 points along that axis; moved across it first, the container scrolls instead, as
                    // UIScrollView's directional lock; until either, nothing moves (the slop).
                    $axes = $this->touchInField ? 0 : $rt->pressDragAxes();
                    $undecided = ($axes === Node::HORIZONTAL || $axes === Node::VERTICAL) && !$this->touchAxisTaken;
                    if ($undecided) {
                        $along = abs($axes === Node::HORIZONTAL ? $ptX - $this->touchStartX : $ptY - $this->touchStartY);
                        $across = abs($axes === Node::HORIZONTAL ? $ptY - $this->touchStartY : $ptX - $this->touchStartX);
                        if ($along > 8.0) {
                            $this->touchAxisTaken = true; $undecided = false; ++$this->longPressId; // the swipe took the touch: no long press
                        } elseif ($across > 8.0) {
                            $rt->cancelTouchPress(); // the move is the scroll's: below, as any touch moving on a container
                        } else {
                            break;
                        }
                    }
                    if (!$undecided) {
                        $input->mouse($ptX, $ptY);
                        $input->push(['drag', $ptX, $ptY, $time]);
                        $this->requestFrame();
                        break;
                    }
                }

                $now = hrtime(true);
                $this->touchHistory[] = [$now, $ptX, $ptY];
                while (count($this->touchHistory) > 1 && ($now - $this->touchHistory[0][0]) > 120_000_000) {
                    array_shift($this->touchHistory);
                }

                $dist = hypot($ptX - $this->touchStartX, $ptY - $this->touchStartY);
                if (!$this->touchIsScrolling && $dist > 8.0) {
                    $this->touchIsScrolling = true;
                    $rt?->cancelTouchPress();
                    $this->touchLastX = $ptX;
                    $this->touchLastY = $ptY;
                }

                if ($this->touchIsScrolling) {
                    $dx = $ptX - $this->touchLastX;
                    $dy = $ptY - $this->touchLastY;
                    $this->touchLastX = $ptX;
                    $this->touchLastY = $ptY;

                    $input->push(['scroll', $this->touchStartX, $this->touchStartY, $dx, $dy, true, false]);
                    $this->requestFrame();
                }
                break;

            case 2: // touchesEnded
                if (!$this->touchActive) {
                    break;
                }
                if ($this->touchMenuHeld) { // the finger that opened a context menu: the menu's (Context::touchMenu)
                    if ($this->w->runtime !== null) { $this->w->runtime->touchMenuFinger = [$ptX, $ptY, true]; }
                    $this->touchMenuHeld = false; $this->touchActive = false;
                    $this->requestFrame();
                    break;
                }
                $this->touchActive = false;
                $rt = $this->w->runtime;
                if ($rt !== null) {
                    $rt->touchFlash = [];
                }

                if ($this->touchInField) {
                    $input->mouse($ptX, $ptY);
                    $input->push(['up', $ptX, $ptY, 0, 1, 0, $time]);
                    $this->touchInField = false;
                    $this->requestFrame();
                    break;
                }

                if ($this->touchIsScrolling) {
                    $this->touchIsScrolling = false;
                    $rt?->cancelTouchPress();
                    $input->push(['scrollEnd', $ptX, $ptY]); // what the finger pulled past a top is let go (Context::refreshable)
                    if (count($this->touchHistory) >= 2) {
                        $first = $this->touchHistory[0];
                        $last = $this->touchHistory[count($this->touchHistory) - 1];
                        $dt = ($last[0] - $first[0]) / 1e9;
                        if ($dt > 0.01 && $dt < 0.2) {
                            $vx = ($last[1] - $first[1]) / $dt;
                            $vy = ($last[2] - $first[2]) / $dt;
                            $speed = hypot($vx, $vy);
                            if ($speed > 60.0) {
                                $flingDistX = max(-400.0, min(400.0, $vx * 0.22));
                                $flingDistY = max(-400.0, min(400.0, $vy * 0.22));
                                $input->push(['scroll', $this->touchLastX, $this->touchLastY, $flingDistX, $flingDistY, false, true]);
                                $this->requestFrame();
                            }
                        }
                    }
                    $this->requestFrame();
                } else {
                    $input->push(['up', $this->touchStartX, $this->touchStartY, 0, 1, 0, $time]);
                    $input->mouseInside = false;
                    $input->mouseX = -1;
                    $input->mouseY = -1;
                    $this->requestFrame();
                }
                break;

            case 3: // touchesCancelled
                $this->touchMenuHeld = false;
                $this->touchActive = false;
                $this->touchIsScrolling = false;
                $this->touchInField = false;
                $this->touchAxisTaken = false;
                $this->w->runtime?->cancelTouchPress();
                $this->requestFrame();
                break;
        }
    }

    public function onFrame(): void
    {
        if (!$this->asked || $this->frame === null) {
            return;
        }
        $this->asked = false;

        $this->metal->setDark(App::theme()->dark);
        Metal::setClearColor($this->view, App::theme()->background);
        $this->metal->render($this->view, function (DisplayList $list): void {
            $bounds = ObjC::msg('r:', $this->view, 'bounds');
            $this->width = (float)$bounds->w;
            $this->height = (float)$bounds->h;
            ($this->frame)($list, $this->width, $this->height);
        });
        $this->paintOver();
        $this->placeMaterials();
        $this->placeCaret();
        if ($this->afterPresent !== null) {
            ($this->afterPresent)();
        }
        $this->w->runtime?->framePresented();
        if (iOS::ffi()->myphp_ios_take_needs_render() > 0) {
            $this->requestFrame();
        }

        // Manage soft keyboard visibility
        $hasInputFocus = ($this->w->input?->focus !== null || $this->w->input?->client !== null);
        if ($hasInputFocus && !$this->keyboardVisible) {
            $this->keyboardVisible = true;
            iOS::ffi()->myphp_ios_show_keyboard();
        } elseif (!$hasInputFocus && $this->keyboardVisible) {
            $this->keyboardVisible = false;
            iOS::ffi()->myphp_ios_hide_keyboard();
        }
    }

    public function bounds(): Rectangle
    {
        return new Rectangle(0, 0, (int)round($this->width), (int)round($this->height));
    }

    public function contentBounds(): Rectangle
    {
        [$t, $r, $b, $l] = $this->safeAreaInsets();
        return new Rectangle(
            (int)round($l),
            (int)round($t),
            max(0, (int)round($this->width - $l - $r)),
            max(0, (int)round($this->height - $t - $b))
        );
    }

    public function safeAreaInsets(): array
    {
        $f = iOS::ffi();
        $top = $this->safeTop ??= $f->new('float');
        $bottom = $this->safeBottom ??= $f->new('float');
        $left = $this->safeLeft ??= $f->new('float');
        $right = $this->safeRight ??= $f->new('float');
        $f->myphp_ios_get_safe_insets(\FFI::addr($top), \FFI::addr($bottom), \FFI::addr($left), \FFI::addr($right));
        return [(float)$top->cdata, (float)$right->cdata, (float)$bottom->cdata, (float)$left->cdata];
    }

    public function keyboardHeight(): float
    {
        return (float)iOS::ffi()->myphp_ios_get_keyboard_height();
    }

    public function restoreFrame(Rectangle $r, bool $onDisplay): void {}
    public function startFullScreen(): void {}
    public function startMaximized(): void {}
    public function isLegacyFullScreen(): bool { return false; }
    public function hasPopover(): bool { return false; }
    public function placeWebViews(): void
    {
        $views = $this->w->webViewList();
        if ($views === [] || ($rt = $this->w->runtime) === null) {
            return;
        }
        $places = [];
        foreach ($rt->webPlaces as $p) {
            $places[spl_object_id($p['view'])] = $p;
        }
        foreach ($views as $v) {
            $p = $places[spl_object_id($v)] ?? null;
            $v->place($p === null ? null : $rt->windowRect($p['frame']), $p === null ? null : $rt->windowRect($p['clip']), 0.0);
        }
    }
    public function placeVideos(): void {}
    public function newWebView(Platform\Page $page, bool $scrollToContainer, bool $transparent): Platform\WebView
    {
        return new WebView($this, $page, $scrollToContainer, $transparent);
    }
    public function contentView(): mixed { return null; }
    public function release(): void {}
    public function show(): void {}
    public function showInactive(): void {}
    public function hide(): void {}
    public function close(): void
    {
        self::$instance = null;
        $this->b->quit();
    }
    public function isClosed(): bool
    {
        return self::$instance === null;
    }
    public function setTitle(string $title): void {}
    public function setRepresentedFile(string $path): void {}
    public function setDocumentEdited(bool $edited): void {}
    public function setBackgroundColor(array $rgba): void {}
    public function themeChanged(): void {}
    public function hasVibrancy(): bool { return false; }
    /** UIKit's: UIGlassEffect on iOS 26, UIBlurEffect's materials before, over the Metal view (placeMaterials). */
    public function hasSystemMaterials(): bool { return true; }

    /**
     * Paints the material nodes the frame met (Runtime::$overPaints) in the
     * launcher's view over the materials, each where the frame met it, its
     * clip, transform and opacity as they were there, recording where the
     * materials go (Painter::nativeHole) as it does.
     */
    private function paintOver(): void
    {
        $rt = $this->w->runtime;
        if ($rt === null || ($rt->overPaints === [] && !$this->overShown)) {
            return;
        }
        if ($this->over === null) {
            $this->over = ObjC::import(iOS::ffi()->myphp_ios_get_over_view());
            $this->metal->adopt($this->over);
            Metal::setClearColor($this->over, 0x000000, 0.0); // clear: the materials and the page show around what it paints
        }
        $text = App::text();
        $this->metal->render($this->over, function (DisplayList $list) use ($rt, $text): void {
            $bounds = ObjC::msg('r:', $this->over, 'bounds');
            $list->pathQuad ??= $text->pathQuad(...);
            $list->zoom = $rt->frameZoom; // as the window's content
            $list->pixels = $this->scale;
            $paint = function () use ($list, $text, $bounds, $rt): void {
                $plain = $list->world();
                $list->pushClip(0, 0, $bounds->w / $list->zoom, $bounds->h / $list->zoom);
                $rt->paintingOver = true;
                try {
                    foreach ($rt->overPaints as $o) {
                        $list->setWorld($plain); // the clip as the frame had it, in the window's coordinates
                        $list->pushClip(...$o['clip']);
                        $list->setWorld($o['world']);
                        $list->setOpacity($o['opacity']);
                        $list->setMask($o['mask']);
                        \MyPHP\UI\Painter::paint($o['node'], $list, $text, App::theme(), $rt);
                        $list->setMask(null);
                        $list->popClip();
                    }
                } finally {
                    $rt->paintingOver = false;
                }
                $list->setWorld($plain);
                $list->setOpacity(1.0);
                $list->popClip();
            };
            $places = count($rt->materialPlaces);
            $paint();
            if ($text->repaint) { // the atlas started over: what this painted points at slots that are gone
                $text->repaint = false;
                $list->reset();
                array_splice($rt->materialPlaces, $places); // recorded again
                $paint();
            }
        });
        $this->overShown = $rt->overPaints !== [];
    }

    /** Gives the launcher the materials where the frame placed them (Runtime::$materialPlaces), when they changed: it places a UIVisualEffectView of each over the Metal view and under the view over the materials. */
    /** Tells the text input where the caret of the field taking the keyboard is, in the view: a hardware keyboard's candidates show by it (as Linux\Window::placeCaret). */
    private function placeCaret(): void
    {
        $input = $this->w->input;
        if ($input === null || ($input->focus === null && $input->client === null && $input->textCaret === null)) {
            return;
        }
        if ($input->client !== null) {
            $r = $input->client->selection()->range->normalized();
            [[$x, $y, $w, $h]] = $input->clientRect(new TextInputRange($r->start, $r->start));
        } else {
            $f = $input->focus;
            [$x, $y, $w, $h] = $input->caretRect($f === null ? 0 : TextState::utf16Index($f->text, $f->cursor));
        }
        iOS::ffi()->myphp_ios_set_caret_rect($x, $y, max(1.0, $w), max(1.0, $h));
    }

    private function placeMaterials(): void
    {
        $rt = $this->w->runtime;
        if ($rt === null) {
            return;
        }
        $places = [];
        foreach ($rt->materialPlaces as $p) {
            [$x, $y, $w, $h] = $rt->windowRect($p['clip']);
            $places[] = [$p['id'], round($x, 2), round($y, 2), round($w, 2), round($h, 2), round($p['radius'] * $rt->frameZoom, 2), round($p['alpha'], 3), self::MATERIALS[$p['material']] ?? 8, $p['dark'] === null ? -1 : (int)$p['dark']];
        }
        $key = json_encode($places);
        if ($key === $this->placed) {
            return;
        }
        $this->placed = $key;
        $f = iOS::ffi();
        $n = count($places);
        $arr = $f->new('MyphpMaterialPlace[' . max($n, 1) . ']');
        foreach ($places as $i => [$id, $x, $y, $w, $h, $r, $a, $kind, $dark]) {
            $arr[$i]->id = $id; $arr[$i]->x = $x; $arr[$i]->y = $y; $arr[$i]->w = $w; $arr[$i]->h = $h;
            $arr[$i]->radius = $r; $arr[$i]->alpha = $a; $arr[$i]->kind = $kind; $arr[$i]->dark = $dark;
        }
        $f->myphp_ios_place_materials(\FFI::addr($arr[0]), $n);
    }

    public function wake(float $seconds, bool $repaint): void
    {
        $id = ++$this->wakeId;
        App::after($seconds, function () use ($id, $repaint): void {
            if ($this->wakeId === $id) {
                $this->requestFrame(repaint: $repaint);
            }
        });
    }

    public function repaintAsked(): bool { return $this->repaintOnly; }
    public function displayRate(): float { return 120.0; }
    public function setProgressBar(string $state, float $value): void {}
    public function lastDraw(): string { return ''; }
    public function backingScale(): float { return $this->scale; }
    public function isKey(): bool { return true; }
    public function setAcceptsFirstMouse(bool $on): void {}
    public function setDropFormats(array $formats): void {}
    public function command(string $cmd): void {}
    public function passThrough(Closure $at): void {}
    public function lastFrame(): array
    {
        return Backend::metal()->lastFrame($this->view);
    }
    public function capture(): string
    {
        [$frame, $w, $h] = $this->lastFrame();
        return \MyPHP\Native\ImageIO::pngFromBGRA($frame, $w, $h);
    }
    public function underFrame(): ?array { return null; }
    public function pageDrag(): void {}
    public function hasHiddenTitleBar(): bool { return true; }
    public function sizeLimits(): array { return [0, 0, 0, 0]; }
    public function frameSize(): array { return [(int)$this->width, (int)$this->height]; }
    public function startDrag(array $o): void {}
    public function cancelDrag(): void {}
    public function titleBar(): array { return [0, 0, 0, 0]; }
    public function dragWindow(int $clicks): void {}
    public function refreshRate(): float { return 120.0; }
    public function showCursor(\MyPHP\UI\Cursor $cursor): void {}
    public function setVibrancy(string $material): void {}
    public function showing(): bool { return true; }
    public function isVisible(): bool { return true; }
    public function focus(): void {}
    public function blur(): void {}
    public function isFocused(): bool { return true; }
    public function minimize(): void { throw new Platform\Unsupported('window placement is not supported on iOS'); }
    public function isMinimized(): bool { return false; }
    public function maximize(): void { throw new Platform\Unsupported('window placement is not supported on iOS'); }
    public function unmaximize(): void { throw new Platform\Unsupported('window placement is not supported on iOS'); }
    public function isMaximized(): bool { return true; }
    public function restore(): void { throw new Platform\Unsupported('window placement is not supported on iOS'); }
    public function setFullScreen(bool $on): void { throw new Platform\Unsupported('window placement is not supported on iOS'); }
    public function isFullScreen(): bool { return true; }
    public function setLegacyFullScreen(bool $on): void { throw new Platform\Unsupported('window placement is not supported on iOS'); }
    public function center(): void { throw new Platform\Unsupported('window placement is not supported on iOS'); }
    public function setBounds(Rectangle $r): void { throw new Platform\Unsupported('window placement is not supported on iOS'); }
    public function setContentBounds(Rectangle $r): void { throw new Platform\Unsupported('window placement is not supported on iOS'); }
    public function magnify(float $by): void {}
    public function fitVideo(array $o): void {}
    public function setMinimumSize(int $width, int $height): void {}
    public function setMaximumSize(int $width, int $height): void {}
    public function setAspectRatio(float $ratio): void {}
    public function setResizable(bool $on): void {}
    public function isResizable(): bool { return false; }
    public function setMovable(bool $on): void {}
    public function isMovable(): bool { return false; }
    public function setFullSizeContentView(bool $on): void {}
    public function setTitleBarHidden(bool $on): void {}
    public function setMinimizable(bool $on): void {}
    public function isMinimizable(): bool { return false; }
    public function setMaximizable(bool $on): void {}
    public function isMaximizable(): bool { return false; }
    public function setClosable(bool $on): void {}
    public function isClosable(): bool { return false; }
    public function setAlwaysOnTop(bool $on): void {}
    public function isAlwaysOnTop(): bool { return false; }
    public function setOpacity(float $v): void {}
    public function opacity(): float { return 1.0; }
    public function setHasShadow(bool $on): void {}
    public function hasShadow(): bool { return false; }
    public function setWindowButtonVisibility(bool $visible): void {}
    public function setIgnoreMouseEvents(bool $on): void {}
    public function setContentProtection(bool $on): void {}
    public function flashFrame(bool $flash): void {}
    public function setVisibleOnAllWorkspaces(bool $on): void {}
    public function placePopover(): void {}
    public function snapshot(float $x, float $y, int $w, int $h): Image
    {
        $scale = $this->scale;
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
    public function framePending(): bool { return $this->asked; }
    public function automatedKey(bool $down, int $code, string $chars, string $plain, int $mods): void {}
    public function automatedText(string $text): void {}
    public function chooseMenuItem(array $path): void {}
}
