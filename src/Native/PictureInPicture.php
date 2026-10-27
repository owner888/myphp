<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use MyPHP\Media\Player;

/**
 * Picture in picture of a player (macOS 12): AVKit's controller of its
 * display layer (AVPictureInPictureControllerContentSource of a sample
 * buffer display layer), the system's floating window showing the
 * picture as the layer gets it. The controller asks a delegate how the
 * player plays (its time range, whether it is paused) and tells it to
 * play, pause or skip from its window's buttons; it tells another when it
 * starts and stops, which $changed hears. Told whenever the player plays,
 * pauses or seeks (invalidate), it shows it.
 *
 * @internal VideoPlayerState
 */
final class PictureInPicture
{
    /** @var array<int, self> the pictures in picture by their delegate's address, for the delegate's methods */
    private static array $of = [];
    private $controller, $source, $delegate;
    /** The app's windows as it starts, of which the system's window is not; the content view of that window, found, which the picture is laid out to. */
    private array $before = [];
    private $panel = null;
    private bool $active = false;

    /** Whether this Mac shows pictures in picture. */
    public static function supported(): bool
    {
        ObjC::boot(['AVKit']);
        return ObjC::$c->objc_getClass('AVPictureInPictureControllerContentSource') !== null
            && ObjC::msg('b:', ObjC::cls('AVPictureInPictureController'), 'isPictureInPictureSupported');
    }

    /** @param Closure(bool): void $changed told when it starts (true) and stops */
    public function __construct(private readonly Player $player, private readonly Closure $changed)
    {
        self::define();
        $this->delegate = ObjC::msg('p:', ObjC::alloc('MyPHPPictureInPicture'), 'init');
        self::$of[ObjC::addr($this->delegate)] = $this;
        $this->source = ObjC::msg('p:pp', ObjC::alloc('AVPictureInPictureControllerContentSource'), 'initWithSampleBufferDisplayLayer:playbackDelegate:', $player->out->layer, $this->delegate);
        $this->controller = ObjC::msg('p:p', ObjC::alloc('AVPictureInPictureController'), 'initWithContentSource:', $this->source);
        ObjC::msg('v:p', $this->controller, 'setDelegate:', $this->delegate);
    }

    /** Whether the picture shows in its own window now (the system's window took the display layer, and shows where it was what says so). */
    public function active(): bool
    {
        return $this->active;
    }

    /** Whether it can start now: the system says so a moment after the controller is made, once the layer shows. */
    public function possible(): bool
    {
        return ObjC::msg('b:', $this->controller, 'isPictureInPicturePossible');
    }

    public function start(): void
    {
        ObjC::msg('v:', $this->controller, 'startPictureInPicture');
    }

    public function stop(): void
    {
        ObjC::msg('v:', $this->controller, 'stopPictureInPicture');
    }

    /** Tells the window the player played, paused or sought: its buttons and its bar show it. */
    public function invalidate(): void
    {
        ObjC::msg('v:', $this->controller, 'invalidatePlaybackState');
    }

    /** The addresses of the app's windows. @return array<int, true> */
    private static function windows(): array
    {
        $out = [];
        $all = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'windows');
        for ($i = 0, $n = ObjC::msg('L:', $all, 'count'); $i < $n; $i++) {
            $out[ObjC::addr(ObjC::msg('p:L', $all, 'objectAtIndex:', $i))] = true;
        }
        return $out;
    }

    /**
     * Finds the system's window, a panel come since it started, visible, of
     * the picture's shape, the only one so (with public AppKit, as
     * AetherEngine does), trying again for $seconds; then lays the picture
     * out to it and follows its size. macOS mirrors a sample buffer display
     * layer into its window pixel for pixel, unscaled (FB22411168): laid out
     * at the window's size, the picture fills it.
     */
    private function findPanel(float $seconds): void
    {
        [$vw, $vh] = $this->player->size();
        $aspect = $vh > 0 ? $vw / $vh : 16 / 9;
        $found = [];
        $all = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'windows');
        for ($i = 0, $n = ObjC::msg('L:', $all, 'count'); $i < $n; $i++) {
            $w = ObjC::msg('p:L', $all, 'objectAtIndex:', $i);
            if (isset($this->before[ObjC::addr($w)]) || !ObjC::msg('b:', $w, 'isVisible') || !ObjC::msg('b:p', $w, 'isKindOfClass:', ObjC::cls('NSPanel'))) {
                continue;
            }
            $b = ObjC::msg('r:', ObjC::msg('p:', $w, 'contentView'), 'bounds');
            if ($b->h > 0 && abs($b->w / $b->h - $aspect) / $aspect < 0.05) { $found[] = $w; }
        }
        if (count($found) !== 1) {
            if ($seconds > 0 && $this->active) { // not there yet, or not alone: again in a moment
                \MyPHP\App::after(0.1, function () use ($seconds): void {
                    if ($this->active && $this->panel === null) { $this->findPanel($seconds - 0.1); }
                });
            }
            return;
        }
        $this->panel = ObjC::msg('p:', ObjC::msg('p:', $found[0], 'contentView'), 'retain');
        ObjC::msg('v:b', $this->panel, 'setPostsFrameChangedNotifications:', true);
        ObjC::msg('v:pppp', ObjC::msg('p:', ObjC::cls('NSNotificationCenter'), 'defaultCenter'), 'addObserver:selector:name:object:', $this->delegate, ObjC::sel('panelResized:'), ObjC::str('NSViewFrameDidChangeNotification'), $this->panel);
        $this->fit();
    }

    /** Lays the picture's view (the layer hosting view the app's window made) out at the size of the system's window, found. */
    private function fit(): void
    {
        if ($this->panel === null) {
            return;
        }
        $b = ObjC::msg('r:', $this->panel, 'bounds');
        $view = $this->player->out->view;
        if ($view !== null) {
            ObjC::msg('v:d', $view, 'setFrameCenterRotation:', 0.0);
            ObjC::msg('v:r', $view, 'setFrame:', ObjC::rect(0, 0, $b->w, $b->h));
        }
    }

    /**
     * Gives the layer back to the app's window, which shows again with the
     * picture where it was (Runtime::$showWindow places it at once): the
     * system's window then animates into it, as IINA's does, rather than
     * fading out over a window that comes after.
     */
    private function back(): void
    {
        $this->losePanel();
        $this->player->out->elsewhere = false;
        ($this->changed)(false);
    }

    /** Stops following the system's window, gone. */
    private function losePanel(): void
    {
        if ($this->panel === null) {
            return;
        }
        ObjC::msg('v:ppp', ObjC::msg('p:', ObjC::cls('NSNotificationCenter'), 'defaultCenter'), 'removeObserver:name:object:', $this->delegate, ObjC::str('NSViewFrameDidChangeNotification'), $this->panel);
        ObjC::msg('v:', $this->panel, 'release');
        $this->panel = null;
    }

    private static function define(): void
    {
        static $defined = false;
        if ($defined) {
            return;
        }
        $defined = true;
        $of = fn($delegate): ?self => self::$of[ObjC::addr($delegate)] ?? null;
        ObjC::defineClass('MyPHPPictureInPicture', 'NSObject', [
            // AVPictureInPictureSampleBufferPlaybackDelegate: how the player plays, and the window's buttons.
            'pictureInPictureController:setPlaying:' => ['v@:@B', 'v(pppb)', function ($self, $cmd, $ctl, $playing) use ($of) {
                $p = $of($self)?->player;
                if ($p !== null) { $playing ? $p->play() : $p->pause(); }
            }],
            'pictureInPictureControllerTimeRangeForPlayback:' => ['{?={?=qiIq}{?=qiIq}}@:@', 'X(ppp)', function ($self, $cmd, $ctl) use ($of) {
                $r = ObjC::$c->new('CMTimeRange');
                $d = $of($self)?->player->duration() ?? 0.0;
                $r->start->value = 0; $r->start->timescale = 1000; $r->start->flags = 1;
                $r->duration->value = (int)round($d * 1000); $r->duration->timescale = 1000; $r->duration->flags = 1;
                return $r; // the whole film, on the clock's timeline
            }],
            'pictureInPictureControllerIsPlaybackPaused:' => ['B@:@', 'b(ppp)', function ($self, $cmd, $ctl) use ($of) {
                return !($of($self)?->player->playing() ?? false);
            }],
            'pictureInPictureController:didTransitionToRenderSize:' => ['v@:@{?=ii}', 'v(pppL)', function ($self, $cmd, $ctl, $size) use ($of) {
                $of($self)?->fit(); // CMVideoDimensions, two ints in one register: the system's window took another size
            }],
            'panelResized:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($of) { // the system's window's content view changed its frame
                $of($self)?->fit();
            }],
            'pictureInPictureController:skipByInterval:completionHandler:' => ['v@:@{?=qiIq}@?', 'v(pppTp)', function ($self, $cmd, $ctl, $interval, $done) use ($of) {
                $p = $of($self)?->player;
                if ($p !== null && $interval->timescale > 0) { $p->seek($p->time() + $interval->value / $interval->timescale); }
                ObjC::callBlock('v:', $done);
            }],
            // AVPictureInPictureControllerDelegate: it started, it stopped, the app's window to show again.
            // The layer is the system's from its start to its stop, animations and all: the app's window leaves it (Renderers::$elsewhere).
            'pictureInPictureControllerWillStartPictureInPicture:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $ctl) use ($of) {
                $pip = $of($self);
                if ($pip === null) { return; }
                $pip->player->out->elsewhere = true;
                $pip->before = self::windows(); // the system's window is the one that comes
                ObjC::msg('v:p', $pip->player->out->layer, 'setVideoGravity:', ObjC::str('AVLayerVideoGravityResizeAspect')); // fitted in the system's window
            }],
            'pictureInPictureControllerDidStartPictureInPicture:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $ctl) use ($of) {
                $pip = $of($self);
                if ($pip !== null) { $pip->active = true; $pip->findPanel(5.0); ($pip->changed)(true); }
            }],
            'pictureInPictureControllerDidStopPictureInPicture:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $ctl) use ($of) {
                $pip = $of($self);
                if ($pip !== null) { $pip->active = false; $pip->back(); } // done by the restore already, unless closed from its button
            }],
            'pictureInPictureController:failedToStartPictureInPictureWithError:' => ['v@:@@', 'v(pppp)', function ($self, $cmd, $ctl, $error) use ($of) {
                $pip = $of($self);
                if ($pip !== null) { $pip->player->out->elsewhere = false; ($pip->changed)(false); }
            }],
            'pictureInPictureController:restoreUserInterfaceForPictureInPictureStopWithCompletionHandler:' => ['v@:@@?', 'v(pppp)', function ($self, $cmd, $ctl, $done) use ($of) {
                $of($self)?->back(); // the app's window back first, the picture in its place, which the system's window grows into
                ObjC::callBlock('v:b', $done, true);
            }],
        ], ['AVPictureInPictureSampleBufferPlaybackDelegate', 'AVPictureInPictureControllerDelegate']);
    }

    public function __destruct()
    {
        if ($this->active) { $this->stop(); }
        $this->losePanel();
        ObjC::msg('v:p', $this->controller, 'setDelegate:', null);
        unset(self::$of[ObjC::addr($this->delegate)]);
        foreach ([$this->controller, $this->source, $this->delegate] as $o) {
            ObjC::msg('v:', $o, 'release');
        }
    }
}
