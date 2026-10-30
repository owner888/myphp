<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use Locale;
use MyPHP\Dialog;
use MyPHP\Media\{AssLine, AssRun};
use MyPHP\Media\Player;
use MyPHP\Media\SubtitlePicture;
use MyPHP\Media\Subtitles;
use MyPHP\Media\Track;
use MyPHP\OpenDialogOptions;
use MyPHP\Window;

/**
 * The video player Context::moviePlayer builds, laid out as IINA's window
 * (measured on it, 960 by 540): the picture, the title bar over it (32
 * high, the title after the traffic lights), the control bar in the
 * system's glass (460 by 80, 10% of the height from the foot: the volume,
 * back, play and pause, on, four buttons above; the times and the bar of
 * the time below), the messages at the top left, the sidebar of quick
 * settings (360 wide: the video, audio and subtitle tabs), IINA's default
 * keys (iina-default-input.conf) that the player has the means for, a
 * right click to play and pause, a double click to go full screen, the
 * wheel up and down for the volume and across to seek. The
 * controls show while the pointer moves and fade, the traffic lights and
 * the pointer with them; what the player has no means for yet (the
 * layout tab, online
 * subtitles, plugins) shows, held. The playlist's sidebar (its films,
 * their loop, shuffle and sort; the film's chapters) slides in from the
 * left; picture in picture from the control bar. Icons are SF Symbols.
 *
 * @internal Context::moviePlayer
 */
final class MoviePlayer
{
    public const SIDEBAR = 360.0;
    /** The color of the controls' symbols and texts: white in the dark appearance, black in the light (the theme's, as the settings say: IINA's themeMaterial). */
    private static int $ink = 0xffffff;
    /**
     * IINA's sensitivities of the wheel (AppData's maps, by the settings'
     * 1 to 4, or 5): the seconds a point of a trackpad seeks, and a notch of
     * a mouse; the volume (of 100) a trackpad's point moves (a mouse's notch
     * one); the part of the speed a point adds.
     */
    private const SEEK_MAP = [0, 0.05, 0.1, 0.25, 0.5], SEEK_MAP_MOUSE = [0, 0.5, 1, 2, 4], VOLUME_MAP = [0, 0.25, 0.5, 0.75, 1], SPEED_MAP = [0, 0.001, 0.002, 0.005, 0.01];

    public static function build(Context $c, MoviePlayerState $s, MoviePlayerOptions $o): Node
    {
        self::$ink = $c->theme->dark ? 0xffffff : 0x000000;
        if ($c->runtime !== null) { $s->attach($c->runtime, $o); }
        if ($s->settings()->get('disableAnimations')) { $c->disableAnimations(); } // IINA's: no animations of its interface, more than Reduce Motion takes
        $c->runtime?->askWindow('legacyFullScreen', $s->settings()->get('useLegacyFullScreen')); // IINA's settings of full screen: the legacy one, the other screens blacked out
        $c->runtime?->askWindow('blackOut', $s->settings()->get('blackOutMonitor'));
        if ($s->musicMode && $s->player !== null) { return MoviePlayerMusic::build($c, $s, $s->player, $o); } // IINA's mini player
        $c->runtime?->askWindow('compact', null); // the window's own size again
        $box = $c->column(0, 0, 1, 0x000000); // keyed by its state (Context::moviePlayer)
        $p = $s->player;
        if ($p === null) { // nothing opened yet: black, and what failed to open, or what opens
            if ($s->error !== '') { $box->center(); $c->text($s->error, 13, 0xff6b6b); }
            if ($s->opening !== null) { self::bufferIndicator($c, $s, self::openingText($c, $s)); }
            $c->end();
            return $box;
        }
        if ($o->keys) { self::keys($c, $s, $p); }
        $picture = $c->movie($p)->grow(1);
        $width = $c->width;
        $chooser = null; // the list of the subtitles found online, built below
        $picture->handleInput(function (InputEvent $e) use ($s, $width, $c, $picture, &$chooser): bool { // the wheel over the picture: up and down the volume, across where it plays, as IINA's
            $onSide = $s->sidebar === 'playlist' ? $e->x < self::SIDEBAR : $s->sidebar !== null && $e->x >= $width - self::SIDEBAR;
            if ($e->kind !== InputEvent::SCROLL && !$onSide && MoviePlayerLiveText::input($s, $e, ...$c->localToWindow($picture, $e->x, $e->y))) { return true; } // a drag over Live Text's words chooses them
            if ($e->kind !== InputEvent::SCROLL || $onSide || ($chooser !== null && self::over($c, $chooser, ...$c->localToWindow($picture, $e->x, $e->y)))) {
                return false; // the rest goes on to the frame (a press, a click), the wheel over a sidebar or the list of subtitles found to its scroll
            }
            [$wx, $wy] = $c->localToWindow($picture, $e->x, $e->y);
            $set = $s->settings();
            $vertical = abs($e->dy) >= abs($e->dx);
            $d = $vertical ? -$e->dy : -$e->dx; // up, or across to the left, more
            if ($s->seekBar !== null && $s->shown && self::over($c, $s->seekBar, $wx, $wy)) { // over the bar of the time, IINA's: the wheel seeks, unless the settings say not (disablePlaySliderScrolling)
                if (!$set->get('disablePlaySliderScrolling')) { self::scrolled($s, 1, $d, $e->precise, $vertical); }
                return true;
            }
            if ($s->volumeBar !== null && $s->shown && self::over($c, $s->volumeBar, $wx, $wy)) { // over the volume's: the volume, unless the settings say not (disableVolumeSliderScrolling)
                if (!$set->get('disableVolumeSliderScrolling')) { self::scrolled($s, 0, $vertical ? $d : $e->dx, $e->precise, $vertical); } // across it, to the right louder
                return true;
            }
            self::scrolled($s, $set->get($vertical ? 'verticalScrollAction' : 'horizontalScrollAction'), $d, $e->precise, $vertical); // as the settings say: IINA's scroll actions
            return true;
        });
        if ($s->wheelSeek != 0.0) { // what the wheel moved across, a seek every tenth of a second at the most: one costs reading from a keyframe
            $wait = 0.1 - (microtime(true) - $s->wheelSeekAt);
            if ($wait <= 0) {
                $exact = match ($s->settings()->get('useExactSeek')) { 0 => false, 1 => true, default => !$p->remote() }; // IINA's seek type: keyframes, exact, or exact where it is quick (a local file's)
                $s->seekBy($s->wheelSeek, $exact);
                [$s->wheelSeek, $s->wheelSeekAt] = [0.0, microtime(true)];
            } else {
                $c->after($wait);
            }
        }
        [$px, $py] = $c->pointerPosition($box);
        $now = microtime(true);
        if ([$px, $py] !== $s->pointer) { $s->pointer = [$px, $py]; $s->moved = $now; }
        // The quick settings slide in from the right, the playlist from the left, as IINA's.
        $slide = $c->animate($box, 'sidebar', $o->sidebar && $s->sidebar !== null && $s->sidebar !== 'playlist' ? 1 : 0, 0.25);
        $slideList = $c->animate($box, 'playlist', $o->sidebar && $s->sidebar === 'playlist' ? 1 : 0, 0.25);
        [$left, $right] = [self::SIDEBAR * $slideList, self::SIDEBAR * $slide];
        $area = $c->width - $left - $right; // the controls center over what the sidebars leave
        $c->runtime?->askWindow('aspect', self::shownAspect($p)); // the window keeps the picture's shape, as IINA's

        // The subtitles, under the bars' glass as mpv draws them into the picture: in the window's layer under its materials.
        // Keyed: shown and hidden (their switch, picture in picture), they renumber none of the siblings after them (the
        // sidebar's scroll among them, whose place jumped as the subtitles changed).
        // Text subtitles in the settings' style (mpv's sub-* options), an ASS track's in its own unless sub-ass-override says
        // otherwise; their sizes of the window's height or the picture's (sub-scale-by-window), their margins in the black
        // bars or the picture (sub-use-margins, ass-force-margins), the bottom ones raised by sub-pos.
        $subs = $s->settings();
        [$vw, $vh] = $p->size();
        [$px, $py, $pw, $ph] = $vw > 0 && $vh > 0 ? $p->subtitlePlane($c->width, $c->height, $vw, $vh) : [0.0, 0.0, $c->width, $c->height];
        $subScale = ($subs->get('subScaleWithWindow') && $ph > 0 ? $c->height / $ph : 1.0) * $s->subScale; // and mpv's sub-scale
        $place = ['letterbox' => $subs->get('displayInLetterBox'), 'pos' => $s->subPos ?? $subs->get('subPos')];
        if ($s->subtitlesVisible && !$s->pictureInPicture) {
            $c->key('subtitles')->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->passThrough()->belowMaterials();
              $level = $s->assOverride ?? $subs->get('subOverrideLevel');
              if (($styled = $p->subtitleLines()) !== [] && $level !== 2) { // an ASS track's lines, in their styles where they are (forced to the settings', sub-ass-override's force; scaled by sub-scale, its scale)
                  $restyle = match (true) {
                      $level === 1 => fn(AssLine $l) => MoviePlayerSubtitleStyle::forced($subs, $l, $subScale),
                      $level === 3 && $s->subScale != 1.0 => fn(AssLine $l) => MoviePlayerSubtitleStyle::scaled($l, $s->subScale),
                      default => null,
                  };
                  self::assLines($c, $p, $styled, $place, $restyle);
              } elseif (($lines = $p->subtitles()) !== []) { // a text track's, or an ASS track's stripped of its styles (strip)
                  self::assLines($c, $p, MoviePlayerSubtitleStyle::lines($subs, $lines, $pw / max(1.0, $ph), $subScale), $place);
              }
              if (($pictures = $p->subtitlePictures()) !== []) {
                  self::subtitlePictures($c, $p, $pictures);
              }
            $c->end();
        }
        if ($s->secondarySubtitlesVisible && !$s->pictureInPicture) { // mpv's toptitle: plain, as far from the top as the first from the foot
            $c->key('secondary subtitles')->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->passThrough()->belowMaterials();
              if (($lines = $p->secondarySubtitles()) !== []) {
                  self::assLines($c, $p, MoviePlayerSubtitleStyle::lines($subs, $lines, $pw / max(1.0, $ph), $subScale, true), ['pos' => 100.0] + $place);
              }
            $c->end();
        }
        MoviePlayerLiveText::build($c, $s, $p, $px, $py, $pw, $ph); // over the picture and its subtitles, under the bars

        // The title bar and the control bar show while the pointer moves and while it is on them, and fade.
        // Each built and closed before the next: an open node takes the next as its child. The control bar floats (draggable),
        // or is in the title bar, or at the foot, as the settings say (IINA's oscPosition).
        $set = $s->settings();
        $at = $set->get('oscPosition');
        $compact = $set->get('compactUI');
        $bars = [];
        $seek = null;
        $oscRow = $at === 1 ? self::ROW + ($compact ? 6 : 10) : 0; // IINA's: the bar under the title, 10 above its foot (6 compact)
        if ($o->titleBar || $at === 1) {
            $bars[] = $title = $c->column()->absolute()->top(0)->left($left)->right($right)->height(($o->titleBar ? self::TITLE : 0) + $oscRow)->systemMaterial('titlebar', $c->theme->dark)->dragWindow();
              if ($o->titleBar) { self::titleRow($c, $s, $left > 0); }
              if ($at === 1) {
                  $c->row(0, 8)->height(self::ROW)->fillWidth()->paddingX(6)->align(Node::CENTER)->keepFocus();
                    $seek = self::inlineBar($c, $s, $p, $o);
                  $c->end();
              }
            $c->end();
        }
        if ($at === 2) {
            $pad = $compact ? 8 : 12;
            $bars[] = $osc = $c->row(0, 8)->absolute()->left($left)->right($right)->bottom(0)->height(self::ROW + 2 * $pad)->paddingStart($compact ? 12 : 16)->paddingEnd($pad)->align(Node::CENTER)
                ->systemMaterial(self::glass($s, 'OSC'))->keepFocus();
              $seek = self::inlineBar($c, $s, $p, $o);
            $c->end();
        } elseif ($at === 0) {
            [$oscX, $oscY] = self::floatingAt($c, $s, $left, $area);
            $bars[] = $osc = $c->key('floating bar')->column()->absolute()->left($oscX)->top($oscY)->size(self::OSC_W, self::floatingHeight($s))->systemMaterial(self::glass($s, 'OSC'))->round(12)->keepFocus();
              $seek = self::floatingBar($c, $s, $p, $o);
            $c->end();
            self::dragFloating($c, $s, $osc, $left, $area);
        }
        if (($c->runtime?->fullScreen ?? false) && $set->get('displayTimeAndBatteryInFullScreen')) { $bars[] = self::additionalInfo($c, $s, $right); } // IINA's: the time and the battery at the top right
        $linger = $s->linger();
        $shown = $now - $s->moved < $linger && !MoviePlayerLiveText::active($s); // hidden while Live Text's words are chosen, as IINA's
        foreach ($bars as $n) { $shown = $shown || $c->hovered($n); }
        $fade = $c->animate($box, 'controls', $shown ? 1 : 0, 0.25);
        $s->shown = $shown;
        if ($o->titleBar) { $c->runtime?->askWindow('buttons', $shown); } // the traffic lights go with the title bar
        $c->runtime?->askWindow('onTop', $s->onTop);
        if ($shown && is_finite($linger)) { $c->after($linger - ($now - $s->moved) + 0.05); } // to hide them
        if (!$shown) { $picture->cursor(Cursor::None); } // the pointer hides with them
        foreach ($bars as $n) {
            $fade > 0.001 ? $n->opacity($fade) : $n->invisible(); // faded out: nothing to click either
            $c->clicked($n); // a click on a bar that none of its controls took (the end of a drag of the time) is the bar's, not the picture's
        }
        if ($seek !== null && $fade > 0.001 && ([$sx, , $over] = $c->pointerPosition($seek)) && $over) {
            [$bx, $by, $bw, $bh] = $c->layoutBounds($seek);
            self::seekPreview($c, $s, $p, $bx, $at === 1 ? $by + $bh : $by, $bw, $sx, $fade, $at === 1);
        }

        if ($o->osd) { self::osd($c, $s, $p, $now); }
        if ($s->onlineSubtitles !== null) { $chooser = self::onlineSubtitles($c, $s); }
        if ($s->opening !== null) {
            self::bufferIndicator($c, $s, self::openingText($c, $s));
        } elseif (($percent = $p->bufferingPercent()) !== null && $s->settings()->get($p->seekBuffering() ? 'showSeekingThrobber' : 'showBufferingThrobber')) { // as the settings say: IINA's throbbers
            self::bufferIndicator($c, $s, $p->seekBuffering() ? self::t($c, 'videoSeeking') : self::t($c, 'videoBuffering', ['percent' => $percent]),
                MoviePlayerState::byteCount($p->cacheBytes(), true) . 'B (' . MoviePlayerState::byteCount($p->cacheSpeed(), false) . 'B/s)');
            $c->after(0.25); // its numbers as they go
        }
        if ($s->error !== '') {
            $c->text($s->error, 13, 0xff6b6b)->absolute()->left(16)->right(16)->bottom(16); // wrapped, all of it said (a URL's is long)
        }
        // A press outside the sidebar closes it, as IINA's, but on the control bar (its gear toggles it) or the title bar (which drags the window) while they show:
        // not a click, which the release of a drag begun in the sidebar (a slider let go over the picture) makes on what is under the pointer.
        $sides = [];
        if ($slide > 0) { $sides[] = self::sidebar($c, $s, $p, $slide); }
        if ($slideList > 0) { $sides[] = self::playlistSidebar($c, $s, $slideList); }
        if ($sides !== [] && $s->sidebar !== null) {
            $outside = true;
            foreach ($sides as $n) { $outside = $outside && $c->pressedOutside($n); }
            $onBars = false;
            if ($fade > 0.001) { foreach ($bars as $n) { $onBars = $onBars || !$c->pressedOutside($n); } }
            if ($outside && !$onBars) { $s->sidebar = null; }
        }
        // A click goes to the first node built that asks for it where it is: the picture asks after what is over it.
        // The clicks on the picture as the settings say (IINA's): a single one waits a double click's time where a double click does something.
        if ($c->rightClicked($picture)) { self::clickAction($c, $s, $set->get('rightClickAction')); }
        if ($c->middleClicked($picture)) { self::clickAction($c, $s, $set->get('middleClickAction')); }
        if ($c->forceClicked($picture)) { self::clickAction($c, $s, $set->get('forceTouchAction')); }
        if ($c->doubleClicked($picture)) {
            $s->singleClickAt = null;
            self::clickAction($c, $s, $set->get('doubleClickAction'));
        } elseif ($c->clicked($picture)) {
            $set->get('doubleClickAction') === 0 ? self::clickAction($c, $s, $set->get('singleClickAction')) : $s->singleClickAt = $now + ($c->runtime?->doubleClickInterval ?? 0.5);
        }
        if ($s->singleClickAt !== null) {
            if ($now >= $s->singleClickAt) { $s->singleClickAt = null; self::clickAction($c, $s, $set->get('singleClickAction')); }
            else { $c->after($s->singleClickAt - $now); }
        }
        if (($pinch = $c->magnified($picture)) !== null) { self::pinched($c, $s, $pinch); }
        $c->runtime?->askWindow('firstMouse', $set->get('videoViewAcceptsFirstMouse')); // IINA's: the click that brings the window forward clicks the picture too, as the settings say
        $c->end();
        return $box;
    }

    /**
     * The wheel's $d (points of a trackpad, else a mouse's, ten a line: a
     * notch, as IINA's, twice up and down) doing $action, as IINA's: 0 the
     * volume, 1 a seek (a tenth of a second at the most: build), 4 the speed
     * (0.05 to 4), 2 nothing; as sensitive as the settings say.
     */
    private static function scrolled(MoviePlayerState $s, int $action, float $d, bool $precise, bool $vertical): void
    {
        $set = $s->settings();
        $delta = $precise ? $d : $d / 10 * ($vertical ? 2 : 1);
        if ($action === 0) {
            $s->setVolume($s->volume + ($precise ? self::VOLUME_MAP[max(1, min(4, $set->get('volumeScrollAmount')))] * $delta : $delta) / 100);
        } elseif ($action === 1) {
            $s->wheelSeek += ($precise ? self::SEEK_MAP : self::SEEK_MAP_MOUSE)[max(1, min(4, $set->get('relativeSeekAmount')))] * $delta;
            $s->moved = microtime(true);
        } elseif ($action === 4 && $s->player !== null) {
            $rate = $s->player->rate();
            $s->setSpeed(round(1000 * max(0.05, min(4.0, $rate + $rate * self::SPEED_MAP[max(1, min(4, $set->get('playbackSpeedScrollAmount')))] * $delta))) / 1000);
        }
    }

    /**
     * What a click on the picture does, as IINA's settings say
     * (MouseClickAction): 1 full screen, 2 pause, 3 the controls hidden
     * (until the pointer moves), 4 picture in picture, 5 the A-B loop, 6
     * the speed back to 1; 0 nothing.
     */
    private static function clickAction(Context $c, MoviePlayerState $s, int $action): void
    {
        match ($action) {
            1 => $c->runtime?->askWindow('fullScreen', !($c->runtime->fullScreen)),
            2 => $s->togglePause(),
            3 => $s->moved = -INF,
            4 => $s->togglePictureInPicture(),
            5 => $s->abLoop(),
            6 => $s->setSpeed(1.0),
            default => null,
        };
    }

    /**
     * A pinch over the picture, as IINA's settings say (pinchAction): 0 the
     * window's size by it (not in full screen), 1 full screen as it begins
     * spreading out and back as it begins pinching in, 2 nothing.
     * @param array{0: float, 1: bool, 2: bool} $pinch
     */
    private static function pinched(Context $c, MoviePlayerState $s, array $pinch): void
    {
        [$by, $began] = $pinch;
        $action = $s->settings()->get('pinchAction');
        if ($action === 0 && $by != 0.0) {
            $c->runtime?->askWindow('magnify', $by);
        } elseif ($action === 1 && $began && $by != 0.0 && ($by > 0) !== ($c->runtime?->fullScreen ?? false)) {
            $c->runtime?->askWindow('fullScreen', $by > 0);
        }
    }

    /** The aspect the picture shows at in the window, its width over its height: its own or the one asked, cut, turned. */
    private static function shownAspect(Player $p): ?float
    {
        [$w, $h] = $p->size();
        if ($w <= 0 || $h <= 0) { return null; }
        $a = $p->crop() ?? $p->aspectRatio() ?? $w / $h;
        return $p->rotation() % 180 === 90 ? 1 / $a : $a;
    }

    /**
     * The keys, as the key bindings of the settings' set say (MoviePlayerKeys,
     * IINA's input.conf): a key pressed that no control took looked up by
     * mpv's name of it, the last binding of it in the set carried out
     * (MoviePlayerCommands); Escape hides a sidebar first, as IINA's.
     * @internal MoviePlayerMusic
     */
    public static function keys(Context $c, MoviePlayerState $s, Player $p): void
    {
        if ($s->sidebar !== null && $c->shortcut(Key::ESCAPE)) { $s->sidebar = null; }
        $bound = [];
        foreach (MoviePlayerKeys::current($s->settings()) as $m) { $bound[$m['normal']] = $m; } // the later of a key's, as mpv's
        $c->takeKeys(function (string $chars, int $code, int $mods, string $plain) use ($c, $s, $p, $bound): bool {
            $m = $bound[MoviePlayerKeys::fromEvent($code, $mods, $plain)] ?? null;
            if ($m === null) { return false; }
            MoviePlayerCommands::run($c, $s, $p, $m);
            return true;
        });
    }

    /** SF Symbol $name at $size points in white, at the window's scale: drawn once. */
    private static function symbol(Context $c, string $name, float $size, float $weight = 0.0, ?int $rgb = null): Image
    {
        $rgb ??= self::$ink;
        static $drawn = [];
        return $drawn["$name/$size/$weight/$rgb/{$c->scale}"] ??= Image::symbol($name, $size, $rgb, $weight, $c->scale);
    }

    /** SF Symbol $name, white (or $rgb) at $alpha. @internal MoviePlayerMusic */
    public static function sf(Context $c, string $name, float $size, float $alpha = 1.0, float $weight = 0.0, ?int $rgb = null): Node
    {
        $img = self::symbol($c, $name, $size, $weight, $rgb);
        return $c->image($img, $img->w / $c->scale, $img->h / $c->scale, 0, $alpha);
    }

    /** $n filled white at $fill and ringed white at $ring, round by $r: IINA's cards, lists and fields over the glass. */
    private static function frosted(Node $n, float $fill, float $ring, float $r): Node
    {
        return $n->draw(static function (DisplayList $l, Node $n) use ($fill, $ring, $r): void {
            $l->rect($n->x, $n->y, $n->w, $n->h, self::$ink, $fill, $r);
            if ($ring > 0) { $l->ring($n->x, $n->y, $n->w, $n->h, self::$ink, $ring, $r, 1); }
        });
    }

    /** The material of the player's $part (OSC, OSD, Sidebar): the system's glass, or its HUD where the settings turn the glass off (IINA's useLiquidGlassOSC and the others). */
    private static function glass(MoviePlayerState $s, string $part): string
    {
        return $s->settings()->get("useLiquidGlass$part") ? 'glass' : 'hud';
    }

    /** The player's string $key. @internal MoviePlayerMusic */
    public static function t(Context $c, string $key, array $args = []): string
    {
        return $c->texts()->get($key, $args);
    }

    /** A button of the control bar: symbol $name centered at ($x, $y) in a 26-point square, brighter under the pointer. */
    private static function barButton(Context $c, string $name, float $size, float $x, float $y, string $tip = ''): Node
    {
        $b = $c->column()->absolute()->left($x - 13)->top($y - 13)->size(26, 26)->center();
        if ($tip !== '') { $b->tooltip($tip); }
          self::sf($c, $name, $size, $c->hovered($b) ? 0.95 : 0.75); // IINA's are a light gray, white under the pointer
        $c->end();
        return $b;
    }

    /** Where the bar of the time is in the floating control bar, and how wide. */
    private const SEEK_X = 58, SEEK_Y = 49, SEEK_W = 344, SEEK_H = 18;
    /** The floating control bar's size; the title bar's height; a row of the control bar in the title bar or at the foot (IINA's: its buttons 24 high). */
    private const OSC_W = 460, OSC_H = 80, TITLE = 32, ROW = 24;

    /**
     * Over the bar of the time under the pointer, $x from the bar's left
     * at ($bx, $by) of the player: the time there (and its chapter's title
     * above it) in a box of glass 5 points above the bar, and the thumbnail
     * of the film there 5 points above that, as wide as the settings say (thumbnailWidth, 120 points), round by 4,
     * ringed and shadowed, as IINA's TimePreviewView and ThumbnailPeekView,
     * centered on the pointer.
     */
    private static function seekPreview(Context $c, MoviePlayerState $s, Player $p, float $bx, float $by, float $bw, float $x, float $alpha, bool $below = false): void
    {
        $f = max(0.0, min(1.0, ($x - 1.5) / ($bw - 3)));
        $t = $p->duration() * $f;
        $text = MoviePlayerState::clock($t);
        $chapter = null;
        foreach ($p->chapters() as $ch) { if ($ch->start <= $t + 1e-3) { $chapter = $ch; } }
        if ($chapter !== null) { $text = $chapter->title . "\n" . $text; }
        $cx = $bx + $x;
        $thumb = $s->thumbnailAt($t);
        // A column centered on the pointer, its foot 5 points above the bar (its top 5 under a bar at the top): the thumbnail over the time.
        $col = $c->column(0, 5)->absolute()->left($cx - 200)->width(400)->align(Node::CENTER)->passThrough()->opacity($alpha);
        $below ? $col->top($by + 5) : $col->bottom($c->height - $by + 5);
          if ($thumb !== null) {
              static $images = null;
              $images ??= new \WeakMap(); // by thumbnail: its image
              $img = $images[$thumb] ??= Image::fromPixels($thumb->width, $thumb->height, $thumb->rgba());
              $w = (float)$s->settings()->get('thumbnailWidth'); $h = round($w / (self::shownAspect($p) ?? $thumb->width / max(1, $thumb->height)));
              $turned = $p->rotation() % 180 === 90;
              $c->column()->size($w, $h)->round(4)->shadow(0, 0, 2, 0, 0x000000)->center()->drawOver(static function (DisplayList $l, Node $n): void {
                  $l->ring($n->x, $n->y, $n->w, $n->h, 0x999999, 0.5, 4, 1);
              });
                $c->image($img, $turned ? $h : $w, $turned ? $w : $h, 4)->rotate($p->rotation());
              $c->end();
          }
          $c->column()->padding(4, 8)->systemMaterial(self::glass($s, 'OSC'))->round(16);
            $c->text($text, 11, self::$ink)->fontFeatures('tnum')->textAlign(Node::CENTER);
          $c->end();
        $c->end();
    }

    /**
     * The title bar's row: the film's icon and title after the traffic
     * lights ($afterList: from the playlist's edge), the pin of the window
     * floating on top at the right while it floats, or always where the
     * settings say (IINA's alwaysShowOnTopIcon), a click toggling it.
     */
    private static function titleRow(Context $c, MoviePlayerState $s, bool $afterList): void
    {
        $c->row(0, 6)->height(self::TITLE)->fillWidth()->align(Node::CENTER)->paddingStart($afterList ? 12 : 84)->paddingEnd(8);
          self::sf($c, 'doc.fill', 11, 0.6);
          $c->text($s->title, 13, self::$ink)->fontWeight(600)->opacity(0.85)->noWrap()->ellipsis('…')->grow(1);
          if ($s->onTop || $s->settings()->get('alwaysShowOnTopIcon')) {
              $pin = $c->column()->size(16, 16)->center()->role(Role::TOGGLE_BUTTON)->checked($s->onTop)->label(self::t($c, 'videoFloatOnTop'));
                self::sf($c, $s->onTop ? 'pin.fill' : 'pin', 12, $c->hovered($pin) ? 0.95 : 0.75);
              $c->end();
              if ($c->clicked($pin)) { $s->onTop = !$s->onTop; }
          }
        $c->end();
    }

    /** Where the floating bar is: the settings' place of it (IINA's controlBarPositionHorizontal, of its center, and Vertical, of its foot), kept in the window, centered while the window is narrower than it. @return array{0: float, 1: float} */
    private static function floatingAt(Context $c, MoviePlayerState $s, float $left, float $area): array
    {
        $set = $s->settings();
        [$fx, $fy] = isset($s->oscDrag[4]) ? [$s->oscDrag[4], $s->oscDrag[5]] : [(float)$set->get('controlBarPositionHorizontal'), (float)$set->get('controlBarPositionVertical')]; // dragged, where it is
        $x = $area < self::OSC_W ? $area / 2 : max(self::OSC_W / 2, min($area - self::OSC_W / 2, $area * $fx));
        $h = self::floatingHeight($s);
        $y = max(0.0, min($c->height - $h - 25, $c->height * $fy)); // its foot from the window's, 25 below the top at most
        return [$left + $x - self::OSC_W / 2, $c->height - $y - $h];
    }

    /**
     * The floating bar dragged by what of it takes no clicks, as IINA's:
     * kept in the window, held at the middle within 5 points of it where
     * the settings say (controlBarStickToCenter); where it is let go kept
     * in the settings, as fractions of the window.
     */
    private static function dragFloating(Context $c, MoviePlayerState $s, Node $osc, float $left, float $area): void
    {
        $set = $s->settings();
        if ($c->dragEnded($osc) !== null && $s->oscDrag !== null) { // let go: kept in the settings
            $s->setSetting('controlBarPositionHorizontal', $s->oscDrag[4]);
            $s->setSetting('controlBarPositionVertical', $s->oscDrag[5]);
        }
        $d = $c->dragged($osc);
        if ($d === null) { $s->oscDrag = null; return; }
        if ($s->oscDrag === null) { [$x, $y] = self::floatingAt($c, $s, $left, $area); $s->oscDrag = [$x - $left, $y, 0.0, 0.0]; } // where the drag began, and how far it went
        $s->oscDrag[2] += $d[0]; $s->oscDrag[3] += $d[1];
        $nx = $s->oscDrag[0] + $s->oscDrag[2]; $ny = $s->oscDrag[1] + $s->oscDrag[3];
        if ($set->get('controlBarStickToCenter') && abs($nx - ($area - self::OSC_W) / 2) <= 5) { $nx = ($area - self::OSC_W) / 2; }
        $nx = max(10.0, min($area - self::OSC_W - 10, $nx));
        $h = self::floatingHeight($s);
        $ny = max(25.0, min($c->height - $h, $ny));
        [$s->oscDrag[4], $s->oscDrag[5]] = [($nx + self::OSC_W / 2) / max(1.0, $area), ($c->height - $ny - $h) / max(1.0, $c->height)]; // where it is as it moves, the settings' once let go
    }

    /**
     * The floating bar's inside, 460 by 80, as IINA's: the volume, the
     * arrows and play (the speed beside them while not 1x), the toolbar's
     * buttons above; the times and the bar of the time below; returns the
     * bar of the time.
     */
    private static function floatingBar(Context $c, MoviePlayerState $s, Player $p, MoviePlayerOptions $o): Node
    {
        $top = $s->settings()->get('compactUI') ? 4 : 0; // IINA's compact interface: 4 less above the buttons, 3 less under the bar of the time (floatingHeight)
        $buttons = self::toolbarButtons($s);
        if (count($buttons) <= 5) { // the volume, unless the toolbar needs its room (IINA's detachOnlyIfNecessary)
            $vol = self::volumeSymbol($s, $p);
            if ($c->clicked(self::barButton($c, $vol, 13, 27, 24 - $top, self::t($c, 'videoMute')))) { $s->toggleMute(); }
            self::volumeSlider($c, $s, $p)->absolute()->left(45)->top(16 - $top)->size(69, 16);
        }
        self::arrow($c, $s, false, 183, 24 - $top);
        if ($c->clicked(self::barButton($c, $p->playing() ? 'pause.fill' : 'play.fill', 22, 229, 24 - $top))) { $s->togglePause(); }
        self::arrow($c, $s, true, 277, 24 - $top);
        $rate = $p->rate();
        if (abs($rate - 1) > 1e-6) { // IINA's speed labels, beside the arrows
            $label = $rate < 1 ? sprintf('%.2fx', $rate) : rtrim(rtrim(sprintf('%.2f', $rate), '0'), '.') . 'x';
            $t = $c->text($label, 10, self::$ink)->opacity(0.55)->absolute()->top(18 - $top);
            $rate < 1 ? $t->right(self::OSC_W - 183 + 13 + 8) : $t->left(277 + 13 + 8);
        }
        foreach ($buttons as $i => $b) { self::toolbarButton($c, $s, $o, $b)->absolute()->left(436 - 24 * (count($buttons) - 1 - $i) - 12)->top(12 - $top); }
        $c->text(MoviePlayerState::clock($p->time()), 11.5, self::$ink)->fontFeatures('tnum')->opacity(0.6)->absolute()->left(17.5)->top(50.5 - $top); // the system font, its digits of one width
        $seek = self::seekBar($c, $s, $p)->absolute()->left(self::SEEK_X)->top(self::SEEK_Y - $top)->size(self::SEEK_W, self::SEEK_H);
        self::loopKnobs($c, $s, $p, $seek, self::SEEK_X, self::SEEK_Y - $top, self::SEEK_W);
        self::endTime($c, $s, $p, 11.5)->absolute()->right(12.5)->top(50.5 - $top);
        return $seek;
    }

    /** The floating bar's height: IINA's, 7 less in the compact interface. */
    private static function floatingHeight(MoviePlayerState $s): float
    {
        return self::OSC_H - ($s->settings()->get('compactUI') ? 7 : 0);
    }

    /**
     * The control bar in a row, in the title bar or at the foot, as IINA's:
     * back, play and on, the times around the bar of the time, the volume,
     * the toolbar's buttons; returns the bar of the time.
     */
    private static function inlineBar(Context $c, MoviePlayerState $s, Player $p, MoviePlayerOptions $o): Node
    {
        $c->row(0, 16)->align(Node::CENTER)->shrink(0);
          self::arrow($c, $s, false);
          $play = $c->column()->size(24, 24)->center()->role(Role::BUTTON)->label(self::t($c, $p->playing() ? 'videoPause' : 'videoPlay'));
            self::sf($c, $p->playing() ? 'pause.fill' : 'play.fill', 16, $c->hovered($play) ? 0.95 : 0.75);
          $c->end();
          if ($c->clicked($play)) { $s->togglePause(); }
          self::arrow($c, $s, true);
        $c->end();
        $c->row(0, 2)->grow(1)->min(120, 0)->align(Node::CENTER);
          $c->text(MoviePlayerState::clock($p->time()), 11, self::$ink)->fontFeatures('tnum')->opacity(0.6)->min(46, 0)->textAlign(Node::CENTER);
          $box = $c->row()->grow(1)->height(24)->align(Node::CENTER);
            $seek = self::seekBar($c, $s, $p)->fillWidth()->height(self::SEEK_H);
          $c->end();
          self::endTime($c, $s, $p, 11)->min(46, 0)->textAlign(Node::CENTER);
        $c->end();
        $buttons = self::toolbarButtons($s);
        $c->row(0, 4)->align(Node::CENTER)->paddingEnd(6)->shrink(0);
          $mute = $c->column()->size(24, 24)->center()->role(Role::BUTTON)->label(self::t($c, 'videoMute'));
            self::sf($c, self::volumeSymbol($s, $p), 13, $c->hovered($mute) ? 0.95 : 0.75);
          $c->end();
          if ($c->clicked($mute)) { $s->toggleMute(); }
          self::volumeSlider($c, $s, $p)->size(70, 16);
        $c->end();
        $c->row()->align(Node::CENTER)->shrink(0);
          foreach ($buttons as $b) { self::toolbarButton($c, $s, $o, $b); }
        $c->end();
        return $seek;
    }

    /**
     * IINA's AdditionalInfoView, in full screen at the top right, $right
     * from the window's edge (the sidebar's): the film's title over the
     * time and, on a Mac of a battery, its charge, in the OSD's glass.
     */
    private static function additionalInfo(Context $c, MoviePlayerState $s, float $right): Node
    {
        $box = $c->column(0, 8)->absolute()->top(8)->right($right + 8)->padding(8, 16)->systemMaterial(self::glass($s, 'OSD'))->round(16)->align(Node::END)->passThrough();
          $c->text($s->title, 18, self::$ink)->noWrap()->ellipsis('…')->maxWidth(max(100.0, $c->width / 2));
          $c->row(0, 4)->align(Node::CENTER);
            self::sf($c, 'clock', 18, 0.55, 0.6);
            $c->text((new \MyPHP\DateFormatter(\MyPHP\App::locale(), new \MyPHP\DateOptions(time: \MyPHP\Style::Short)))->format(new \DateTimeImmutable()), 18, self::$ink)->fontWeight(700)->fontFeatures('tnum')->opacity(0.55);
            if (($level = \MyPHP\Power::batteryLevel()) !== null) {
                $c->box(2, 16, self::$ink)->opacity(0.25)->marginX(4);
                $c->column()->center();
                  self::sf($c, 'battery.0', 24, 0.55);
                  $c->text($level . '%', 9, self::$ink)->fontWeight(700)->opacity(0.8)->absolute()->left(0)->right(4)->top(0)->bottom(0)->textAlign(Node::CENTER);
                $c->end();
            }
          $c->end();
        $c->end();
        $c->after(60 - (int)date('s')); // the time as the minute turns
        return $box;
    }

    /** The volume's symbol: muted, or its waves as loud as it is. */
    private static function volumeSymbol(MoviePlayerState $s, Player $p): string
    {
        return $p->muted() || $s->volume == 0 ? 'speaker.slash.fill' : ($s->volume < 0.34 ? 'speaker.wave.1.fill' : ($s->volume < 0.67 ? 'speaker.wave.2.fill' : 'speaker.wave.3.fill'));
    }

    /** The volume's slider: a thin track, filled to the knob, and a white pill. */
    private static function volumeSlider(Context $c, MoviePlayerState $s, Player $p): Node
    {
        $level = $p->muted() ? 0.0 : $s->volume;
        $v = $c->sliderBase($level, 0, $s->maxVolume())->paddingX(7.5); // to the maximum volume, as IINA's
        $v->draw(static function (DisplayList $l, Node $n): void {
            $x0 = $n->x + 7.5; $w = $n->w - 15; $cy = $n->y + $n->h / 2; $k = $x0 + $w * $n->fraction;
            $l->rect($n->x, $cy - 2, $n->w, 4, self::$ink, 0.25, 2);
            $l->rect($n->x, $cy - 2, $k - $n->x, 4, self::$ink, 0.8, 2);
            $l->rect($k - 7.5, $cy - 5, 15, 10, 0xffffff, 0.92, 5);
        });
        if ($c->changed($v)) { $s->setVolume($level); }
        $s->volumeBar = $v;
        return $v;
    }

    /**
     * An arrow of the control bar (at ($x, $y) in the floating bar, else in
     * its row): IINA's speed arrows, or the playlist's where the settings
     * say (arrowBtnAction); a press acts at once (the speed while held,
     * MoviePlayerState::arrowDown), its release ends it.
     */
    private static function arrow(Context $c, MoviePlayerState $s, bool $right, ?float $x = null, ?float $y = null): void
    {
        $playlist = $s->settings()->get('arrowBtnAction') === 1;
        $name = $playlist ? ($right ? 'forward.end.fill' : 'backward.end.fill') : ($right ? 'forward.fill' : 'backward.fill');
        $b = $x !== null ? self::barButton($c, $name, 17, $x, $y) : $c->column()->size(24, 24)->center()->role(Role::BUTTON);
        if ($x === null) { self::sf($c, $name, 13, $c->hovered($b) ? 0.95 : 0.75); $c->end(); }
        $b->label(self::t($c, $right ? 'videoForward' : 'videoBackward'));
        $held = $c->pressed($b);
        $was = $s->arrowHeld[$b->id] ?? false;
        if ($held && !$was) { $s->arrowDown($right); }
        if (!$held && $was) { $s->arrowUp(); }
        $s->arrowHeld[$b->id] = $held;
        if ($held) { $c->after(0.1); } // a frame to see it let go
    }

    /** The control bar's toolbar buttons, the settings' (IINA's controlBarToolbarButtons), but those the player has not (Live Text). @return list<int> */
    private static function toolbarButtons(MoviePlayerState $s): array
    {
        $live = $s->settings()->get('enableLiveText'); // IINA's: Live Text's button only while it is on
        return array_values(array_filter($s->settings()->get('controlBarToolbarButtons'), fn(int $b) => ($b !== 8 || $live) && isset(MoviePlayerSettings::TOOLBAR[$b])));
    }

    /** A button of the toolbar, 24 by 24, its symbol the one of it on while it is on (the sidebar it opens shown, picture in picture, full screen); a click does what it does. */
    private static function toolbarButton(Context $c, MoviePlayerState $s, MoviePlayerOptions $o, int $b): Node
    {
        [$symbols, $onSymbols, $key] = MoviePlayerSettings::TOOLBAR[$b];
        $on = match ($b) { 0 => $s->sidebar !== null && $s->sidebar !== 'playlist', 1 => $s->sidebar === 'playlist', 2 => $s->pictureInPicture, 3 => $c->runtime?->fullScreen ?? false, 8 => $s->settings()->get('enableLiveText'), default => false };
        $tip = $c->texts()->get('iinaOscToolbar' . implode('', array_map('ucfirst', explode('_', $key))));
        if ($b === 7) { $tip .= ' (' . self::t($c, 'videoNotYet') . ')'; }
        $n = $c->column()->size(24, 24)->center()->role(Role::BUTTON)->label($tip)->tooltip($tip);
          $name = self::firstSymbol($on && $onSymbols !== [] ? $onSymbols : $symbols);
          self::sf($c, $name, 14, $c->hovered($n) ? 0.95 : 0.75);
        $c->end();
        if ($b === 5 && ($p = $s->player) !== null) { // IINA's: a menu of the subtitle tracks, none first
            $n->menu(function (Menu $m) use ($c, $p): void {
                if ($m->item(self::t($c, 'videoNone'))->checked($p->subtitleTrack() === null)->chosen()) { $p->setSubtitleTrack(null); }
                foreach ($p->subtitleTracks() as $t) {
                    $title = $t->name !== '' ? $t->name : (self::language($t->language, $c->locale()) ?: '#' . $t->number);
                    if ($m->item($title)->checked($p->subtitleTrack() === $t)->chosen()) { $p->setSubtitleTrack($t); }
                }
            });
            return $n;
        }
        if ($c->clicked($n)) {
            match ($b) {
                0 => $o->sidebar ? $s->sidebar = $s->sidebar === null || $s->sidebar === 'playlist' ? $s->tab : null : null,
                1 => $o->sidebar ? $s->sidebar = $s->sidebar === 'playlist' ? null : 'playlist' : null,
                2 => $s->togglePictureInPicture(),
                3 => $c->runtime?->askWindow('fullScreen', !($c->runtime->fullScreen)),
                4 => $s->toggleMusicMode(),
                6 => $s->screenshot(),
                8 => $s->setSetting('enableLiveText', !$s->settings()->get('enableLiveText')),
                default => null, // the plugins: myphp's player has none
            };
        }
        return $n;
    }

    /** The first of $names the system has. @param list<string> $names */
    private static function firstSymbol(array $names): string
    {
        foreach ($names as $name) { if (\MyPHP\Native\Symbols::exists($name)) { return $name; } }
        return $names[0];
    }

    /** The bar of the time: a 4-point track, lighter where it played, the chapters' starts cut in it where the settings say (showChapterPos), and a white upright knob; dragged, it seeks. */
    private static function seekBar(Context $c, MoviePlayerState $s, Player $p): Node
    {
        $seek = $c->sliderBase($s->at, 0, max($p->duration(), 0.001))->paddingX(1.5);
        if (!$c->pressed($seek)) { $s->at = $p->time(); }
        $marks = $s->settings()->get('showChapterPos') && $p->duration() > 0 ? array_map(fn($ch) => $ch->start / $p->duration(), array_slice($p->chapters(), 1)) : [];
        $seek->draw(static function (DisplayList $l, Node $n) use ($marks): void {
            $x0 = $n->x + 1.5; $w = $n->w - 3; $cy = $n->y + $n->h / 2; $k = $x0 + $w * $n->fraction;
            $l->rect($n->x, $cy - 2, $n->w, 4, self::$ink, 0.2, 2);
            $l->rect($n->x, $cy - 2, $k - $n->x, 4, self::$ink, 0.45, 2);
            foreach ($marks as $m) { $l->rect($x0 + $w * $m - 0.5, $cy - 2, 1, 4, 0x000000, 0.6); } // IINA's chapter marks: the track cut
            $l->rect($k - 1.5, $cy - 7.5, 3, 15, 0xffffff, 0.92, 1.5);
        });
        if ($c->changed($seek)) { $p->seek($s->at); }
        $s->seekBar = $seek;
        return $seek;
    }

    /** The A-B loop's points over the bar of the time at ($sx, $sy), $sw wide, as IINA's knobs of the loop: gray, three quarters of the knob's height; dragged, they move. */
    private static function loopKnobs(Context $c, MoviePlayerState $s, Player $p, Node $seek, float $sx, float $sy, float $sw): void
    {
        foreach (['a' => $s->loopA, 'b' => $s->loopB] as $which => $at) {
            if ($at === null) { continue; }
            $x = $sx + 1.5 + ($sw - 3) * $at / max($p->duration(), 0.001);
            $knob = $c->column()->absolute()->left($x - 1.5)->top($sy + 1.5)->size(3, 15)->tooltip('A-B loop ' . strtoupper($which))->draw(static function (DisplayList $l, Node $n): void {
                $l->rect($n->x, $n->y + 2, 3, 11, 0xa6a6a6, 1.0, 1);
            });
            $c->end();
            if ($c->pressed($knob)) {
                [$px] = $c->pointerPosition($seek);
                $to = $p->duration() * max(0.0, min(1.0, ($px - 1.5) / ($sw - 3)));
                if (abs($to - $at) > 1e-3) { $s->moveLoopPoint($which, $to); }
            }
        }
    }

    /** The time at the end of the bar: the film's length, or what is left of it where the settings say (showRemainingTime; at the speed it plays, scaleRemainingTime), as IINA's; a click toggles which. */
    private static function endTime(Context $c, MoviePlayerState $s, Player $p, float $size): Node
    {
        $set = $s->settings();
        $left = $set->get('showRemainingTime');
        $text = $left ? '-' . MoviePlayerState::clock(max(0.0, $p->duration() - $p->time()) / ($set->get('scaleRemainingTime') ? max(1e-6, $p->rate()) : 1.0)) : MoviePlayerState::clock($p->duration());
        $n = $c->text($text, $size, self::$ink)->fontFeatures('tnum')->opacity(0.6)->role(Role::BUTTON);
        if ($c->clicked($n)) { $s->setSetting('showRemainingTime', !$left); }
        return $n;
    }

    /**
     * IINA's BufferIndicatorView, in the middle: a spinner, $text under it
     * and $detail (what is cached, how fast it comes) under that, 150 points
     * wide, in glass.
     */
    /** What the indicator says as a film opens: IINA's "Opening stream…", or the seconds left of the ad before a web page's video, a frame each second. */
    private static function openingText(Context $c, MoviePlayerState $s): string
    {
        $left = ($s->adOver ?? 0.0) - microtime(true);
        if ($left <= 0) {
            return self::t($c, 'videoOpeningStream');
        }
        $c->after($left - floor($left) + 0.01); // as the next second goes
        return self::t($c, 'videoSkippingAd', ['seconds' => (int)ceil($left)]);
    }

    private static function bufferIndicator(Context $c, MoviePlayerState $s, string $text, ?string $detail = null): void
    {
        $c->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->center()->passThrough();
          $c->column(0, 4)->padding(12, 20)->systemMaterial(self::glass($s, 'OSD'))->round(16)->align(Node::CENTER)->width(150 + 40);
            $c->spinner();
            $c->text($text, 13, self::$ink)->textAlign(Node::CENTER);
            if ($detail !== null) { $c->text($detail, 11, self::$ink)->fontFeatures('tnum')->opacity(0.7)->textAlign(Node::CENTER); }
          $c->end();
        $c->end();
    }

    /** The message at the top left: its text, with the time or the volume's level under it (or a line of its own), fading out. */
    private static function osd(Context $c, MoviePlayerState $s, Player $p, float $now): void
    {
        [$text, $under, $until] = $s->message;
        $size = (float)$s->settings()->get('osdTextSize'); // IINA's: the message at the size set, what is under it at 0.55 of it
        $small = round($size * 0.55, 1);
        if ($s->onlineSubtitles !== null) { $text = ''; } // the list of subtitles found says it, where the message is
        $box = $c->column(0, 1.5)->absolute()->left(8)->top(48)->padding(10, 16, 7, 16)->systemMaterial(self::glass($s, 'OSD'))->round(12);
        if (($s->shot['path'] ?? null) === null) { $box->passThrough(); } // the pointer goes through, but to a screenshot's buttons
        $o = $c->animate($box, 'osd', $now < $until ? 1 : 0, 0.3);
        if ($now < $until && is_finite($until)) { $c->after($until - $now + 0.01); } // one till another (a search going on) fades with it
        $box->opacity($o);
        if ($o <= 0.001 || $text === '') { $box->invisible(); }
          $c->text($text, $size, self::$ink);
          if ($under === 'time') {
              $c->text(MoviePlayerState::clock($p->time()) . ' / ' . MoviePlayerState::clock($p->duration()), $small, self::$ink)->fontFeatures('tnum')->opacity(0.7);
          } elseif ($under === 'volume') {
              $level = ($p->muted() ? 0.0 : $s->volume) / $s->maxVolume(); // of the maximum volume, as IINA's
              $c->row()->height(5)->fillWidth()->minWidth(90)->draw(static function (DisplayList $l, Node $n, Theme $t) use ($level): void {
                  $l->rect($n->x, $n->y, $n->w, $n->h, self::$ink, 0.25, 2.5);
                  $l->rect($n->x, $n->y, $n->w * $level, $n->h, $t->accent, 1.0, 2.5); // the system's accent
              });
              $c->end();
          } elseif ($under !== '') { // a time of its own (an A-B loop's point moved)
              $c->text($under, $small, self::$ink)->fontFeatures('tnum')->opacity(0.7);
          }
          if ($s->shot !== null && $now < $until) { self::shotPreview($c, $s); }
        $c->end();
        if ($s->shot !== null && $now >= $until) { $s->shot = null; }
    }

    /** IINA's preview of a screenshot under its message (ScreenshootOSDView): the picture at most 300 by 200, ringed, and DELETE, EDIT, REVEAL of its file, kept. */
    private static function shotPreview(Context $c, MoviePlayerState $s): void
    {
        $img = $s->shot['image'];
        $k = min(1.0, 300 / max(1, $img->w), 200 / max(1, $img->h));
        $c->image($img, $img->w * $k, $img->h * $k, 4)->margin(8, 0, $s->shot['path'] === null ? 0 : 8, 0)->draw(static function (DisplayList $l, Node $n): void {
            $l->ring($n->x, $n->y, $n->w, $n->h, 0x808080, 0.6, 4, 1);
        });
        if ($s->shot['path'] === null) { return; }
        $c->row(0, 12);
          foreach (['delete' => 'videoShotDelete', 'edit' => 'videoShotEdit', 'reveal' => 'videoShotReveal'] as $action => $key) {
              $b = $c->row()->role(Role::BUTTON)->label(self::t($c, $key));
                $c->text(self::t($c, $key), 11, self::$ink, Font::BOLD)->opacity(0.85);
              $c->end();
              if ($c->clicked($b)) { $s->shotAction($action); }
          }
        $c->end();
    }

    /**
     * IINA's list of the subtitles found online (SubChooseViewController,
     * in its OSD): how many, and a row of each, its file's name over its
     * language, year, frames a second and downloads, the day it was
     * uploaded at the right; a click chooses one (Command one more), a
     * double click downloads it; Cancel, and Download of those chosen.
     */
    private static function onlineSubtitles(Context $c, MoviePlayerState $s): Node
    {
        $found = $s->onlineSubtitles ?? [];
        $day = new \MyPHP\DateFormatter(\MyPHP\App::locale(), new \MyPHP\DateOptions(date: \MyPHP\Style::Medium));
        $box = $c->key('online subtitles')->column(0, 10)->absolute()->left(8)->top(48)->width(460)->padding(12, 14, 12, 14)->systemMaterial(self::glass($s, 'OSD'))->round(12)->keepFocus();
          $c->text(self::t($c, 'videoSubtitlesFound', ['count' => count($found)]), 20, self::$ink);
          $c->text(self::t($c, 'videoSubtitlesChoose'), 12, self::$ink)->opacity(0.7);
          self::frosted($c->scroll()->height(min(5, count($found)) * 44.0)->fillWidth(), 0.08, 0, 6);
            $c->column()->fillWidth()->dividers(1, self::$ink, 0.1);
              foreach ($found as $i => $f) {
                  $chosen = in_array($i, $s->onlineChosen, true);
                  $row = $c->column(0, 2)->height(44)->paddingX(10)->justify(Node::CENTER)->fillWidth();
                  if ($chosen) { ($c->runtime?->windowFocus ?? true) ? $row->background($c->theme->accent) : $row->background(self::$ink, 0.12); }
                    $c->text($f['name'], 13, self::$ink)->opacity(0.9)->noWrap()->ellipsis('…');
                    $c->row(0, 8)->fillWidth();
                      $about = implode('  ', array_filter([$f['language'], $f['year'] > 0 ? "({$f['year']})" : '', $f['fps'] != 0 ? MoviePlayerState::number($f['fps']) . ' fps' : '', "\u{2b07}{$f['downloads']}"]));
                      $c->text($about, 11, self::$ink)->opacity(0.6)->grow(1)->noWrap()->ellipsis('…');
                      $at = strtotime($f['uploaded']);
                      if ($at !== false) { $c->text($day->format((new \DateTimeImmutable("@$at"))->setTimezone(new \DateTimeZone(date_default_timezone_get()))), 11, self::$ink)->opacity(0.6); }
                    $c->end();
                  $c->end();
                  if ($c->doubleClicked($row)) { $s->onlineChosen = [$i]; $s->downloadOnlineSubtitles([$i]); }
                  elseif ($c->clicked($row)) {
                      $s->onlineChosen = ($c->clickModifiers($row) & Key::COMMAND) === 0 ? [$i] : ($chosen ? array_values(array_diff($s->onlineChosen, [$i])) : [...$s->onlineChosen, $i]);
                  }
              }
            $c->end();
          $c->end();
          $c->row(0, 8)->fillWidth()->justify(Node::END);
            if (self::sideButton($c, self::t($c, 'videoCancel'))) { $s->cancelOnlineSubtitles(); }
            if (self::sideButton($c, self::t($c, 'videoDownload'), $s->onlineChosen !== [])) { $s->downloadOnlineSubtitles($s->onlineChosen); }
          $c->end();
        $c->end();
        return $box;
    }

    /** Whether the window's point ($x, $y) is in the last frame's box of $n. */
    private static function over(Context $c, Node $n, float $x, float $y): bool
    {
        $at = $c->windowToLocal($n, $x, $y);
        [, , $w, $h] = $c->layoutBounds($n);
        return $at !== null && $at[0] >= 0 && $at[1] >= 0 && $at[0] < $w && $at[1] < $h;
    }

    /**
     * The lines of an ASS track in their styles, as libass lays them out
     * over the picture (the plane of their PlayRes, Player::subtitlePlane):
     * at \pos, the point of their alignment there, else within the
     * margins at their alignment, those of one alignment and margins
     * stacked (the later above at the bottom, below elsewhere), wrapped at
     * the margins; the lower layers first.
     * @param list<AssLine> $lines
     */
    private static function assLines(Context $c, Player $p, array $lines, array $place, ?Closure $restyle = null): void
    {
        $order = array_keys($lines);
        usort($order, fn(int $a, int $b): int => [$lines[$a]->layer, $a] <=> [$lines[$b]->layer, $b]);
        $groups = [];
        foreach ($order as $i) {
            $l = $lines[$i];
            $groups[$l->alone() ? "alone $i" : "$l->alignment/$l->marginLeft/$l->marginRight/$l->marginVertical"][] = $l;
        }
        foreach ($groups as $group) {
            $l = $group[0];
            [$px, $py, $pw, $ph] = $p->subtitlePlane($c->width, $c->height, $l->playResX, $l->playResY);
            if ($restyle !== null) { $group = array_map($restyle, $group); $l = $group[0]; }
            $l->alone() ? self::assAlone($c, $p, $l, $px, $py, $pw, $ph) : self::assGroup($c, $p, $group, $px, $py, $pw, $ph, $place);
        }
        foreach ($lines as $l) {
            if ($l->changes()) { $c->animationFrame(); break; } // moving, fading, animated, sung: a frame each refresh of the display while it shows, as a page's animation
        }
    }

    /**
     * A line laid out on its own (placed, turned, scaled, sheared or
     * clipped), in boxes of the plane one in the other: cut to \clip (a
     * rectangle, or a drawing's mask; \iclip what it covers cut away),
     * turned by \frz, then \frx and \fry in 3D, seen from libass's 20000
     * PlayRes units before the plane, around \org (else the point of its
     * alignment), scaled by \fscx and \fscy around that point, as libass
     * draws it (its rows sheared each, assRow). Runs turned, scaled or
     * sheared apart (libass's are each glyph's) are each drawn so, the line
     * laid out once for each, its other runs laid out but not drawn.
     */
    private static function assAlone(Context $c, Player $p, AssLine $l, float $px, float $py, float $pw, float $ph): void
    {
        $sx = $pw / $l->playResX; $sy = $ph / $l->playResY;
        $opened = 0;
        if ($l->clip !== null || $l->clipDrawing !== null) { // \clip, \iclip: a rectangle cut to, or a mask (a drawing's, or the rectangle's to cut away)
            $mask = null;
            if ($l->clip !== null) {
                [$x1, $y1, $x2, $y2] = $l->clip;
            } elseif (($clip = MoviePlayerAssDrawing::clip($c, $l->clipDrawing, $sx, $sy)) !== null) {
                [$mask, [$x1, $y1, $x2, $y2]] = $clip;
            } elseif ($l->clipInverted) { // a drawing of nothing cut away: all of it
                [$x1, $y1, $x2, $y2] = [0.0, 0.0, 0.0, 0.0];
            } else {
                return; // cut to nothing
            }
            $box = $c->column()->absolute()->left($px + $x1 * $sx)->top($py + $y1 * $sy)->size(max(0.0, ($x2 - $x1) * $sx), max(0.0, ($y2 - $y1) * $sy))->passThrough();
            if ($l->clip !== null && !$l->clipInverted) { $box->clip(); } elseif ($mask !== null || $l->clip !== null) { $box->clipMask($mask, $l->clipInverted); }
            [$px, $py] = [-$x1 * $sx, -$y1 * $sy];
            $opened++;
        }
        $groups = [];
        foreach ($l->runs as $r) {
            if ($r->text === "\n") { continue; }
            $groups[serialize([$r->rotation, $r->rotationX, $r->rotationY, $r->scaleX, $r->scaleY, $r->shearX, $r->shearY])][] = $r;
        }
        [$ax, $ay] = self::assAnchor($l);
        [$ox, $oy] = $l->origin ?? [$ax, $ay];
        foreach ($groups === [] ? [[$l->runs[0]]] : $groups as $group) {
            $only = count($groups) > 1 ? array_fill_keys(array_map('spl_object_id', $group), true) : null; // the runs drawn this time, of all of them for one
            $r = $group[0];
            $inner = fn() => $c->column()->absolute()->left(0)->top(0)->size($pw, $ph)->passThrough();
            $plane = $c->column()->absolute()->left($px)->top($py)->size($pw, $ph)->passThrough(); $nest = 1;
            if ($l->moved !== [0.0, 0.0]) { // \move: laid out where it starts, moved by a transform, in fractions of a pixel, not by its glyphs' places on whole ones
                $plane->translate($l->moved[0] * $sx, $l->moved[1] * $sy);
            }
            if ($r->rotation != 0 || $r->rotationX != 0 || $r->rotationY != 0) {
                $turn = $inner()->transformOrigin($ox / $l->playResX, $oy / $l->playResY);
                if ($r->rotation != 0) { $turn->rotate(-$r->rotation); } // \frz turns counterclockwise
                if ($r->rotationX != 0) { $turn->rotateX(-$r->rotationX); } // libass's top comes toward the viewer, CSS's goes away
                if ($r->rotationY != 0) { $turn->rotateY($r->rotationY); }
                if ($r->rotationX != 0 || $r->rotationY != 0) { $turn->perspective(20000 * $sy); } // libass's distance: 20000 of the plane's units (blur_scale_y's)
                $nest++;
            }
            if ($r->scaleX != 1 || $r->scaleY != 1) { $inner()->transformOrigin($ax / $l->playResX, $ay / $l->playResY)->scale($r->scaleX, $r->scaleY); $nest++; }
            self::assGroup($c, $p, [$l], 0, 0, $pw, $ph, [], $only, $r);
            for (; $nest > 0; $nest--) { $c->end(); }
        }
        for (; $opened > 0; $opened--) { $c->end(); }
    }

    /** The point of $line's alignment in PlayRes: \pos, else on its margins by its alignment. @return array{0: float, 1: float} */
    private static function assAnchor(AssLine $l): array
    {
        if ($l->position !== null) {
            return $l->position;
        }
        $col = ($l->alignment - 1) % 3; $row = intdiv($l->alignment - 1, 3);
        $x = [$l->marginLeft, $l->marginLeft + ($l->playResX - $l->marginLeft - $l->marginRight) / 2, $l->playResX - $l->marginRight][$col];
        $y = [$l->playResY - $l->marginVertical, $l->playResY / 2, $l->marginVertical][$row];
        return [(float)$x, (float)$y];
    }

    /**
     * Lines of one place, on the plane at ($px, $py) $pw by $ph: of one
     * alignment and margins, or one laid out on its own; of those, the runs
     * of $only drawn (spl_object_id's; null for all), sheared as $rep is.
     * @param list<AssLine> $group @param ?array<int, true> $only
     */
    private static function assGroup(Context $c, Player $p, array $group, float $px, float $py, float $pw, float $ph, array $place = [], ?array $only = null, ?AssRun $rep = null): void
    {
        $l = $group[0];
        $sx = $pw / $l->playResX; $sy = $ph / $l->playResY;
        $col = ($l->alignment - 1) % 3; $row = intdiv($l->alignment - 1, 3); // 0, 1, 2: left, center, right; bottom, middle, top
        $across = [Node::START, Node::CENTER, Node::END][$col];
        $along = [Node::END, Node::CENTER, Node::START][$row];
        if ($l->position !== null) { // the point of its alignment at \pos: a box around it as large as the plane, packed to it
            [$ax, $ay] = [$px + $l->position[0] * $sx, $py + $l->position[1] * $sy];
            $box = $c->column()->absolute()->left($ax - [0, $pw, 2 * $pw][$col])->top($ay - [2 * $ph, $ph, 0][$row])->size(2 * $pw, 2 * $ph);
            $wrap = null;
        } else {
            [$fx, $fy, $fw, $fh] = ($place['letterbox'] ?? false) ? [0.0, 0.0, $c->width, $c->height] : [$px, $py, $pw, $ph]; // its margins from the window's edges, in the black bars (sub-use-margins), else the picture's
            $box = $c->column()->absolute()->left($fx + $l->marginLeft * $sx)->top($fy + $l->marginVertical * $sy)
                ->size(max(1.0, $fw - ($l->marginLeft + $l->marginRight) * $sx), max(1.0, $fh - 2 * $l->marginVertical * $sy));
            $wrap = $l->wraps ? max(1.0, $fw - ($l->marginLeft + $l->marginRight) * $sx) : null;
            if ($row === 0 && ($place['pos'] ?? 100.0) != 100.0) { $box->translate(0, -(100 - $place['pos']) / 100 * $fh); } // sub-pos: of the height, 100 at the foot
        }
        $box->align($across)->justify($along)->passThrough();
          foreach ($row === 0 ? array_reverse($group) : $group as $line) { // at the bottom a later line goes above the ones before
              foreach (self::assRows($line) as $runs) {
                  self::assRow($c, $p, $line, $runs, $sx, $sy, $wrap, $across, $only, $rep);
              }
          }
        $c->end();
    }

    /** The runs of $line split at its \N, a row each. @return list<list<AssRun>> */
    private static function assRows(AssLine $line): array
    {
        $rows = [[]];
        foreach ($line->runs as $r) {
            if ($r->text === "\n") { $rows[] = []; continue; }
            $rows[count($rows) - 1][] = $r;
        }
        return $rows;
    }

    /**
     * A row of runs (assText draws its text); an empty row is the height of
     * its font. Fontsize is the height of a font's ascent and descent, as
     * libass sizes it, not its em. Sheared by \fax of $rep (else its first
     * run) from its top left (libass shears each line from its top), or with
     * \fay each glyph (and drawing) on its own, from its origin and the top
     * (libass's: \fay of each glyph's own x); drawings (\p) where their runs are, the text between them in
     * pieces of its own, a drawing's foot on their baseline (\pbo below
     * it). Of the runs, those of $only drawn (null: all).
     * @param list<AssRun> $runs @param ?array<int, true> $only
     */
    private static function assRow(Context $c, Player $p, AssLine $line, array $runs, float $sx, float $sy, ?float $wrap, int $across, ?array $only = null, ?AssRun $rep = null): void
    {
        $first = $runs[0] ?? $line->runs[0];
        $rep ??= $first;
        [, $videoHeight] = $p->size();
        $px = $line->scaledBorder ? $sy : ($videoHeight > 0 ? $sy * $line->playResY / $videoHeight : $sy); // outlines and shadows of the plane's units, or of the video's pixels
        $row = $c->column()->alignSelf($across)->passThrough();
        if ($wrap !== null) { // as wide as it wraps at, its lines aligned in it by the text's layout (a text of its lines' width would be moved twice)
            $row->width($wrap);
        }
        if ($line->alpha < 1) { // \fad, \fade
            $row->opacity($line->alpha);
        }
        $shear = $rep->shearY != 0 ? [$rep->shearX, $rep->shearY] : null; // \fay: each glyph sheared on its own (assText), and with it \fax
        if ($rep->shearX != 0 && $shear === null) { // \fax: x by y times it, the same for the whole line
            $row->transformOrigin(0, 0)->skew(rad2deg(atan($rep->shearX)), 0);
        }
          if ($runs === []) { // a \N on its own: a blank row
              $c->box(1, self::assEm($c, $first) * $sy * 1.2, 0);
              $c->end();
              return;
          }
          if (array_filter($runs, fn(AssRun $r) => $r->drawing !== null) === []) {
              self::assText($c, $runs, $sx, $sy, $wrap, $across, $px, $line->alpha, $only, $shear);
          } else { // drawings among it: each a box of its own, the text between in pieces, on the text's baseline
              $descent = 0.0;
              foreach ($runs as $r) {
                  if ($r->drawing === null) { $descent = max($descent, self::assDescent($c, $r) * $sy); }
              }
              $c->row()->align(Node::END)->passThrough();
                $text = [];
                foreach ([...$runs, null] as $r) {
                    if ($r !== null && $r->drawing === null) { $text[] = $r; continue; }
                    if ($text !== []) { self::assText($c, $text, $sx, $sy, null, Node::START, $px, $line->alpha, $only, $shear); $text = []; }
                    if ($r !== null) { MoviePlayerAssDrawing::build($c, $r, $sx, $sy, $px, $line->alpha, self::assBlur($c, $r, $sy), $descent, $only === null || isset($only[spl_object_id($r)]), $shear); }
                }
              $c->end();
          }
        $c->end();
    }

    /**
     * Runs of text in their colors, as assRow lays them, in a box of their
     * own: drawn for each of their styles of outline, shadow and blur (the
     * first in the flow, the others over it, each of the text laid out
     * whole, its other runs laid out but not drawn), as libass draws them
     * (MoviePlayerAssDrawing::rules: what of the glyphs the outline and the
     * shadow cut out, whether the shadow shows; BorderStyle 3 a box of each
     * line of the runs, blurred with them). A syllable being sung by \kf or
     * \K in the secondary color, the primary over it cut to how far it has
     * swept across the syllable's glyphs (libass's: from its leftmost ink,
     * in the order of its lines). With $shear (\fax, \fay) each glyph is
     * drawn on its own, sheared from its origin and its line's top (its
     * box with it). Of the runs, those of $only drawn.
     * @param list<AssRun> $runs @param ?array<int, true> $only @param ?array{0: float, 1: float} $shear
     */
    private static function assText(Context $c, array $runs, float $sx, float $sy, ?float $wrap, int $across, float $px, float $lineAlpha, ?array $only, ?array $shear = null): void
    {
        $pieces = [];
        foreach ($runs as $i => $r) {
            foreach (self::assPieces($c, $r) as [$text, $em]) { $pieces[] = [$r, $text, $em, $i]; }
        }
        // The spans of the text: $color(run, its index) its [rgb, alpha], or null for a run not drawn; drawn only in the runes [$from, $to) when given, and with $alone of those runes only.
        $spans = static function (\Closure $color, int $from = 0, int $to = PHP_INT_MAX, bool $alone = false) use ($pieces, $sx, $sy): array {
            $out = []; $at = 0;
            foreach ($pieces as [$r, $text, $em, $i]) {
                $paint = $color($r, $i);
                $n = mb_strlen($text, 'UTF-8');
                $cuts = array_values(array_unique([0, max(0, min($n, $from - $at)), max(0, min($n, $to - $at)), $n]));
                for ($k = 0; $k + 1 < count($cuts); $k++) { // the piece split where the runes drawn begin and end
                    $in = $at + $cuts[$k] >= $from && $at + $cuts[$k] < $to;
                    if ($alone && !$in) { continue; }
                    $drawn = $paint !== null && $in;
                    $out[] = new Span(mb_substr($text, $cuts[$k], $cuts[$k + 1] - $cuts[$k], 'UTF-8'), size: $em * $sy, color: $paint[0] ?? $r->color, alpha: $paint[1] ?? 0.0,
                        font: $r->font, weight: $r->weight, italic: $r->italic, underline: $r->underline, strikethrough: $r->strikeout, letterSpacing: $r->spacing * $sx, ghost: !$drawn);
                }
                $at += $n;
            }
            return $out;
        };
        $styles = []; // the runs drawn, by their styles of outline, shadow and blur
        foreach ($runs as $i => $r) {
            if ($only !== null && !isset($only[spl_object_id($r)])) { continue; }
            $styles[serialize([$r->border, $r->shadow, $r->outlineColor, $r->outlineAlpha, $r->shadowColor, $r->shadowAlpha, $r->blur, $r->edgeBlur, $r->borderStyle])][$i] = $r;
        }
        $fill = static fn(AssRun $r): array => $r->sweep !== null ? [$r->secondary, $r->secondaryAlpha] : [$r->color, $r->alpha]; // a syllable sweeping: the secondary under the primary that sweeps
        $text = static fn(array $spans): Node => $c->richText($spans, width: $wrap, wrap: $wrap !== null)->textAlign($across);
        // With $shear, glyph $g alone (assGlyphs'), on its line's baseline, sheared from its origin and the line's top, ($dx, $dy) from where it is: its box over it, its text at its top left, and the box itself (BorderStyle 3 draws in it).
        $glyph = static function (array $g, array $one, float $dx, float $dy) use ($c, $shear): ?array {
            $line = $c->shapeRichText($one, 0, null, Node::START)->layout->lines[0] ?? null;
            if ($line === null || $line->glyphs === []) { return null; }
            [$from, $to, $gx, $gw, $top, $height, $base] = $g;
            [$left, $y] = [$gx - $line->glyphs[0]->x, $base - $line->baseline]; // its pen where the line has it, the baselines one
            $w = max($line->x + $line->width, $gw); $h = max($line->y + $line->height, 1e-6);
            $c->column()->absolute()->left($left + $dx)->top($y + $dy)->size($w, $h)->passThrough()
                ->transformOrigin(($gx - $left) / max($w, 1e-6), ($top - $y) / $h)->skew(rad2deg(atan($shear[0])), rad2deg(atan($shear[1])));
            return [$gx - $left, $top - $y, $gw, $height];
        };
        $c->column()->passThrough();
          if ($styles === [] || $shear !== null) { // laid out alone: none of it drawn now, or each glyph drawn apart over it
              $text($spans(fn() => null));
          }
          $layout = $shear === null ? null : $c->shapeRichText($spans(fn() => null), $wrap ?? 0, null, $across);
          $inFlow = $shear === null;
          foreach ($styles as $group) {
              $s = reset($group);
              $border = $s->border * $px; $shadow = $s->shadow * $px; $blur = self::assBlur($c, $s, $sy);
              [$cutOutline, $cutShadow, $shadows] = MoviePlayerAssDrawing::rules(array_values($group), $lineAlpha);
              $paint = static function (Node $t) use ($s, $border, $shadow, $blur, $cutOutline, $cutShadow, $shadows): void { // its outline, shadow and blur, as libass draws them: the glyphs grown
                  if ($s->borderStyle !== 3) {
                      if ($border > 0) { $t->textOutline($border, $s->outlineColor, $s->outlineAlpha); }
                      if ($shadow > 0 && $shadows) { $t->textShadow($shadow, $shadow, $s->shadowColor, $s->shadowAlpha); }
                      if ($cutOutline || $cutShadow) { $t->textCutOut($cutOutline, $cutShadow); }
                  }
                  if ($blur > 0) { $t->textBlur($blur); }
              };
              $boxes = static function (array $lines) use ($c, $s, $border, $shadow, $blur): void { // BorderStyle 3: a box of the outline's color behind each, its shadow the box moved; both blurred with it
                  $c->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->passThrough()
                      ->draw(static function (DisplayList $list, Node $n) use ($lines, $s, $border, $shadow, $blur): void {
                          $box = static function (float $dx, float $dy, int $rgb, float $alpha) use ($list, $n, $lines, $border, $blur): void {
                              foreach ($lines as [$x0, $x1, $top, $height]) {
                                  [$x, $y, $w, $h] = [$n->x + $x0 - $border + $dx, $n->y + $top - $border + $dy, $x1 - $x0 + 2 * $border, $height + 2 * $border];
                                  $blur > 0 ? $list->shadow($x, $y, $w, $h, $rgb, $alpha, 0.0, 1.64 * $blur) : $list->rect($x, $y, $w, $h, $rgb, $alpha); // a feather of 1.64 sigmas: a Gaussian's 10 to 90 percent
                              }
                          };
                          if ($shadow > 0) { $box($shadow, $shadow, $s->shadowColor, $s->shadowAlpha); }
                          $box(0, 0, $s->outlineColor, $s->outlineAlpha);
                      });
                  $c->end();
              };
              $mine = $spans(fn(AssRun $r, int $i) => isset($group[$i]) ? $fill($r) : null);
              if ($shear === null) {
                  if ($s->borderStyle === 3) { $boxes(array_map(fn(array $g) => array_slice($g, 1), self::assSweep($c, $mine, $pieces, array_keys($group), $wrap, $across))); }
                  $t = $text($mine);
                  if (!$inFlow) { $t->absolute()->left(0)->top(0); }
                  $inFlow = false;
                  $paint($t);
              } else { // each glyph on its own, laid out alone, sheared from its origin and its line's top, where the text has it
                  foreach (self::assGlyphs($layout, $pieces, array_keys($group)) as $g) {
                      $one = $spans(fn(AssRun $r, int $i) => isset($group[$i]) ? $fill($r) : null, $g[0], $g[1], true);
                      $at = $glyph($g, $one, 0.0, 0.0);
                      if ($at === null) { continue; }
                        if ($s->borderStyle === 3) { $boxes([[$at[0], $at[0] + $at[2], $at[1], $at[3]]]); }
                        $paint($c->richText($one)->absolute()->left(0)->top(0));
                      $c->end();
                  }
              }
              foreach ($group as $i => $r) {
                  if ($r->sweep === null) {
                      continue;
                  }
                  $segments = self::assSweep($c, $mine, $pieces, [$i], $wrap, $across);
                  $left = $r->sweep * array_sum(array_map(fn(array $g) => $g[2] - $g[1], $segments)); // how far across its glyphs, line after line
                  $primary = fn(AssRun $q, int $k) => $k === $i ? [$q->color, $q->alpha] : null; // the syllable alone, in the primary
                  foreach ($segments as [, $x0, $x1, $top, $height]) {
                      $w = min($x1 - $x0, $left);
                      $left -= $w;
                      if ($w <= 0) { break; }
                      $c->column()->absolute()->left($x0)->top($top)->size($w, $height)->clipX()->passThrough(); // across, how far it has swept; down, all its glyphs, sheared or not
                        if ($shear === null) {
                            $o = $text($spans($primary))->absolute()->left(-$x0)->top(-$top);
                            if ($blur > 0 && ($border <= 0 || $s->borderStyle === 3)) { $o->textBlur($blur); }
                        } else {
                            foreach (self::assGlyphs($layout, $pieces, [$i]) as $g) {
                                if (abs($g[4] - $top) > 1e-6) { continue; } // this segment's line
                                $one = $spans($primary, $g[0], $g[1], true);
                                if ($glyph($g, $one, -$x0, -$top) === null) { continue; }
                                  $o = $c->richText($one)->absolute()->left(0)->top(0);
                                  if ($blur > 0 && ($border <= 0 || $s->borderStyle === 3)) { $o->textBlur($blur); }
                                $c->end();
                            }
                        }
                      $c->end();
                  }
              }
          }
        $c->end();
    }

    /**
     * The glyphs of runs $ks in $layout, each [its first rune, the rune after
     * its cluster, its x, its advance, its line's top, the line's height,
     * its baseline].
     * @param list<int> $ks @return list<array{0: int, 1: int, 2: float, 3: float, 4: float, 5: float, 6: float}>
     */
    private static function assGlyphs(TextLayout $layout, array $pieces, array $ks): array
    {
        $ranges = []; $at = 0;
        foreach ($pieces as [, $text, , $i]) {
            $n = mb_strlen($text, 'UTF-8');
            if (in_array($i, $ks, true)) { $ranges[] = [$at, $at + $n]; }
            $at += $n;
        }
        $out = [];
        foreach ($layout->layout->lines as $line) {
            foreach ($line->glyphs as $g) {
                foreach ($ranges as [$from, $to]) {
                    if ($g->cluster < $from || $g->cluster >= $to) { continue; }
                    $k = "$g->cluster/$line->y";
                    if (isset($out[$k])) { // a cluster of glyphs (a ligature, marks): one, as wide as they go
                        $out[$k][3] = max($out[$k][2] + $out[$k][3], $g->x + $g->advance) - min($out[$k][2], $g->x); $out[$k][2] = min($out[$k][2], $g->x);
                    } else {
                        $out[$k] = [$g->cluster, $g->cluster + max(1, $g->runes), $g->x, $g->advance, $line->y, $line->height, $line->baseline];
                    }
                    break;
                }
            }
        }
        return array_values($out);
    }

    /**
     * Where the glyphs of runs $ks are in the text of $spans as it is laid
     * out (wrapped at $wrap, aligned $across): a segment of each line they
     * are on, [line, their leftmost x, their rightmost, the line's top, its
     * height].
     * @param list<Span> $spans @param list<int> $ks @return list<array{0: int, 1: float, 2: float, 3: float, 4: float}>
     */
    private static function assSweep(Context $c, array $spans, array $pieces, array $ks, ?float $wrap, int $across): array
    {
        $ranges = [];
        $at = 0;
        foreach ($pieces as [, $text, , $i]) {
            $n = mb_strlen($text, 'UTF-8');
            if (in_array($i, $ks, true)) { $ranges[] = [$at, $at + $n]; }
            $at += $n;
        }
        $out = [];
        foreach ($c->shapeRichText($spans, $wrap ?? 0, null, $across)->layout->lines as $li => $line) {
            $x0 = INF; $x1 = -INF;
            foreach ($line->glyphs as $g) {
                foreach ($ranges as [$from, $to]) {
                    if ($g->cluster >= $from && $g->cluster < $to) { $x0 = min($x0, $g->x); $x1 = max($x1, $g->x + $g->advance); break; }
                }
            }
            if ($x1 > $x0) { $out[] = [$li, $x0, $x1, $line->y, $line->height]; }
        }
        return $out;
    }

    /** The descent of run $r's font below its baseline, in the plane's units, as libass sizes the font (assEm). */
    private static function assDescent(Context $c, AssRun $r): float
    {
        $style = new TextStyle(); $style->family = $r->font; $style->size = 100.0; $style->weight = $r->weight; $style->italic = $r->italic;
        return $c->fontMetrics($style)['descent'] / 100 * self::assEm($c, $r);
    }

    /**
     * Run $r's blur as a Gaussian's sigma in points, as libass makes it: \blur
     * of the plane's units, its radius 2 / sqrt(ln 256) of them; and \be's
     * passes of [1 2 1] across and down, each a variance of half a pixel,
     * the display's pixels.
     */
    private static function assBlur(Context $c, AssRun $r, float $sy): float
    {
        $sigma = $r->blur * $sy * 2 / sqrt(log(256));
        return sqrt($sigma * $sigma + $r->edgeBlur * 0.5 / ($c->scale * $c->scale));
    }

    /**
     * The pieces of run $r's text by the font each is drawn in (its own, or
     * one the system falls back on for runes it lacks, as Chinese in Arial),
     * each with its em in the plane's units: libass sizes every font so that
     * its ascent and descent are the run's Fontsize.
     * @return list<array{0: string, 1: float}>
     */
    private static function assPieces(Context $c, AssRun $r): array
    {
        $style = new \MyPHP\Text\Style($r->font, 100.0, $r->weight, $r->italic);
        $layout = $c->metrics()->textSystem()->layout(new \MyPHP\Text\Params($r->text, $style, keepSpaces: true));
        $runes = mb_str_split($r->text, 1, 'UTF-8');
        $ems = array_fill(0, count($runes), self::assEm($c, $r));
        foreach ($layout->lines as $line) {
            foreach ($line->glyphs as $g) {
                if ($g->font !== null && $g->font->ascent + $g->font->descent > 0) {
                    for ($i = $g->cluster; $i < $g->cluster + max(1, $g->runes); $i++) { $ems[$i] = $r->size * $g->font->size / ($g->font->ascent + $g->font->descent); }
                }
            }
        }
        $out = [];
        foreach ($runes as $i => $ch) {
            $em = $ems[$i] ?? $ems[0];
            if ($out !== [] && abs($out[count($out) - 1][1] - $em) < 1e-6) { $out[count($out) - 1][0] .= $ch; } else { $out[] = [$ch, $em]; }
        }
        return $out;
    }

    /** The em of run $r's font in the plane's units: its Fontsize the font's ascent and descent, as libass takes it. */
    private static function assEm(Context $c, AssRun $r): float
    {
        static $ratios = [];
        $key = "$r->font/$r->weight/" . (int)$r->italic;
        if (!isset($ratios[$key])) {
            $style = new TextStyle(); $style->family = $r->font; $style->size = 100.0; $style->weight = $r->weight; $style->italic = $r->italic;
            $m = $c->fontMetrics($style);
            $ratios[$key] = $m['ascent'] + $m['descent'] > 0 ? 100 / ($m['ascent'] + $m['descent']) : 1.0; // a font of no vertical metrics (a test's text engine): its Fontsize its em
        }
        return $r->size * $ratios[$key];
    }

    /**
     * The pictures of image subtitles (PGS, VobSub) where they are on the
     * subtitles' plane, which lies over the picture (Player::subtitlePlane),
     * scaled with it; their images made once, the last few kept.
     * @param list<SubtitlePicture> $pictures
     */
    private static function subtitlePictures(Context $c, Player $p, array $pictures): void
    {
        static $images = []; // by picture: [its weak reference, its image], the last made last
        foreach ($pictures as $pic) {
            [$px, $py, $pw, $ph] = $p->subtitlePlane($c->width, $c->height, $pic->planeWidth, $pic->planeHeight);
            $k = spl_object_id($pic);
            if (($images[$k][0] ?? null)?->get() !== $pic) {
                $images[$k] = [\WeakReference::create($pic), Image::fromPixels($pic->width, $pic->height, $pic->rgba())];
                if (count($images) > 8) { unset($images[array_key_first($images)]); }
            }
            $sx = $pw / max(1, $pic->planeWidth); $sy = $ph / max(1, $pic->planeHeight);
            $c->image($images[$k][1], $pic->width * $sx, $pic->height * $sy)->absolute()->left($px + $pic->x * $sx)->top($py + $pic->y * $sy)->passThrough();
        }
    }

    /** The quick settings, 360 points wide over the right of the window, sliding in by $slide: the back button, the tabs, the chosen tab's cards. */
    private static function sidebar(Context $c, MoviePlayerState $s, Player $p, float $slide): Node
    {
        // Keyed, as the playlist's: the messages, the buffering indicator and the seek bar's preview built before it come and go,
        // which would renumber its nodes (its scroll's place, the state of its controls).
        $side = $c->key('quick settings')->column()->absolute()->top(0)->right(0)->bottom(0)->width(self::SIDEBAR)->systemMaterial(self::glass($s, 'Sidebar'))->translate(self::SIDEBAR * (1 - $slide), 0)->keepFocus();
          $back = $c->column()->absolute()->left(9)->top(7)->size(38, 38)->center()->systemMaterial(self::glass($s, 'Sidebar'))->round(19)->tooltip(self::t($c, 'videoHide'));
            self::sf($c, 'chevron.right', 14, $c->hovered($back) ? 0.9 : 0.6, 0.2);
          $c->end();
          if ($c->clicked($back)) { $s->sidebar = null; }
          self::tabBar($c, $s);
          $c->scroll(0, 20)->absolute()->top(56)->left(0)->right(0)->bottom(0)->padding(0, 16, 16, 16);
            match ($s->tab) {
                'video' => self::videoTab($c, $s, $p),
                'audio' => self::audioTab($c, $s, $p),
                'subtitles' => self::subtitleTab($c, $s, $p),
                default => self::layoutTab($c),
            };
          $c->end();
        $c->end();
        $c->clicked($side); // a click on the sidebar is its own, not the picture's
        return $side;
    }

    /**
     * The playlist, 360 points wide over the left of the window, sliding in
     * by $slide, as IINA's: the tabs (the playlist, the chapters) and the
     * back button above; the films in rows, the one playing marked, one
     * chosen by a click, played by a double click; under them the time
     * they last together and the buttons to add films, take the chosen one
     * out and take out all but the one playing.
     */
    private static function playlistSidebar(Context $c, MoviePlayerState $s, float $slide): Node
    {
        $side = $c->key('playlist')->column()->absolute()->top(0)->left(0)->bottom(0)->width(self::SIDEBAR)->systemMaterial(self::glass($s, 'Sidebar'))->translate(-self::SIDEBAR * (1 - $slide), 0)->keepFocus();
          self::playlistPane($c, $s, $side, self::SIDEBAR, $c->height, 96, true);
        $c->end();
        $c->clicked($side); // a click on the playlist is its own, not the picture's
        return $side;
    }

    /**
     * The playlist's pane, $w by $h, in $side (the sidebar, or music mode's
     * playlist), as IINA's: its tabs at $tabsLeft (the playlist, the
     * chapters), its back button where $back; the films, their bar under
     * them. The films are titled by their tags (an audio file's title, its
     * artist under it) where the settings say (playlistShowMetadata, only
     * in music mode where playlistShowMetadataInMusicMode).
     * @internal MoviePlayerMusic
     */
    public static function playlistPane(Context $c, MoviePlayerState $s, Node $side, float $w, float $h, float $tabsLeft, bool $back): void
    {
        $set = $s->settings();
        $tagged = $set->get('playlistShowMetadata') && (!$set->get('playlistShowMetadataInMusicMode') || $s->musicMode);
          $c->row(3, 0)->absolute()->left($tabsLeft)->top(9)->height(36)->align(Node::CENTER)->systemMaterial(self::glass($s, 'Sidebar'))->round(18);
            foreach ([['playlist', 'music.note.list', 'videoPlaylist'], ['chapters', 'list.and.film', 'videoChapters']] as [$id, $icon, $label]) {
                $t = $c->row()->size(34, 30)->center()->round(15)->tooltip(self::t($c, $label));
                if ($s->playlistTab === $id) { $t->background(self::$ink, 0.16); }
                  self::sf($c, $icon, 14, $s->playlistTab === $id || $c->hovered($t) ? 0.95 : 0.7);
                $c->end();
                if ($c->clicked($t)) { $s->playlistTab = $id; }
            }
          $c->end();
          if ($back) {
              $hide = $c->column()->absolute()->right(9)->top(7)->size(38, 38)->center()->systemMaterial(self::glass($s, 'Sidebar'))->round(19)->tooltip(self::t($c, 'videoHide'));
                self::sf($c, 'chevron.left', 14, $c->hovered($hide) ? 0.9 : 0.6, 0.2);
              $c->end();
              if ($c->clicked($hide)) { $s->sidebar = null; }
          }
          $list = $s->playlist;
          // The two tabs side by side, sliding across as one goes to the other, as IINA's: each page built while any of it shows.
          $page = $c->animate($side, 'playlistTab', $s->playlistTab === 'chapters' ? 1 : 0, 0.25);
          $c->column()->absolute()->top(53)->left(0)->right(0)->bottom(33)->clip();
          if ($page < 1) {
              $c->scroll()->absolute()->top(0)->left(0)->right(0)->bottom(0)->translate(-$w * $page, 0);
                $c->column()->fillWidth()->dividers(1, self::$ink, 0.1);
                  foreach ($list?->files() ?? [] as $i => $path) {
                      $playing = $i === $list->index();
                      $row = $c->row(0, 6)->height(32)->paddingStart(28)->paddingEnd(9)->align(Node::CENTER)->fillWidth();
                      if ($i === $s->chosen) { // the chosen row as AppKit's lists choose: the accent in the key window, gray in another
                          ($c->runtime?->windowFocus ?? true) ? $row->background($c->theme->accent) : $row->background(self::$ink, 0.12);
                      }
                        if ($playing) { $c->column()->absolute()->left(9)->top(9)->size(14, 14)->center(); self::sf($c, 'play.fill', 9, 0.9); $c->end(); }
                        $tags = $tagged ? $list->tags($i) : null;
                        if ($tags !== null && $tags['title'] !== '' && $tags['artist'] !== '') { // IINA's: the track's name, its artist under it
                            $c->column(0, 1)->grow(1)->min(0, 0);
                              $c->text($tags['title'], 13, self::$ink)->opacity(0.9)->noWrap()->ellipsis('…');
                              $c->text($tags['artist'], 10.5, self::$ink)->opacity(0.55)->noWrap()->ellipsis('…');
                            $c->end();
                        } else {
                            $c->text($list->title($path), 13, self::$ink)->opacity(0.9)->noWrap()->ellipsis('…')->grow(1);
                        }
                        $d = $list->duration($i);
                        if ($d !== null) { $c->text(MoviePlayerState::clock($d), 11, self::$ink)->fontFeatures('tnum')->opacity(0.55); }
                      $c->end();
                      if ($c->doubleClicked($row)) { $s->open($path); } elseif ($c->clicked($row)) { $s->chosen = $i; }
                  }
                $c->end();
                $c->column()->fillWidth()->height(max(0.0, $h - 86 - 32 * ($list?->count() ?? 0)))->passThrough()->draw(static function (DisplayList $l, Node $n): void { // the empty rows' lines below, as IINA's
                    for ($y = $n->y + 32; $y < $n->y + $n->h; $y += 32) { $l->rect($n->x, $y, $n->w, 1, self::$ink, 0.1); }
                });
                $c->end();
              $c->end();
          }
          if ($page > 0) {
              self::chapterList($c, $s, $h)->translate($w * (1 - $page), 0);
          }
          $c->end();
          // The bar under the list: repeat, shuffle and sort (held), the time of them all, add, remove, clear.
          $c->row(0, 12)->absolute()->left(0)->right(0)->bottom(0)->height(33)->paddingX(12)->align(Node::CENTER)->background(0x000000, 0.18);
            // As IINA's: the loop button off, the file (repeat.1) or the playlist, in the accent while on; shuffle in it while shuffled; sort a menu.
            $loop = $c->column()->tooltip(self::t($c, 'videoRepeat'));
              $on = $s->loop !== 'off';
              self::sf($c, $s->loop === 'file' ? 'repeat.1' : 'repeat', 12, $on || $c->hovered($loop) ? 0.95 : 0.7, 0.0, $on ? $c->theme->accent : self::$ink);
            $c->end();
            if ($c->clicked($loop)) { $s->nextLoop(); }
            $mixed = $list?->shuffled() ?? false;
            $shuffle = $c->column()->tooltip(self::t($c, 'videoShuffle'));
              self::sf($c, 'shuffle', 12, $mixed || $c->hovered($shuffle) ? 0.95 : 0.7, 0.0, $mixed ? $c->theme->accent : self::$ink);
            $c->end();
            if ($c->clicked($shuffle) && $list !== null) { $mixed ? $list->unshuffle() : $list->shuffle(); $s->chosen = -1; }
            $sort = $c->column()->tooltip(self::t($c, 'videoSort'));
              self::sf($c, 'arrow.up.arrow.down', 12, $c->hovered($sort) ? 0.95 : 0.7);
            $c->end();
            $sort->menu(function (Menu $m) use ($c, $s, $list): void {
                $m->header(self::t($c, 'videoSorting'));
                foreach ([['videoSortNameAscending', false, true], ['videoSortNameDescending', false, false], ['videoSortPathAscending', true, true], ['videoSortPathDescending', true, false]] as [$label, $path, $up]) {
                    if ($m->item(self::t($c, $label))->chosen() && $list !== null) {
                        $path ? $list->sortByPath($up) : $list->sortByName($up);
                        $s->chosen = -1;
                    }
                }
            });
            $total = 0.0;
            for ($i = 0, $n = $list?->count() ?? 0; $i < $n; $i++) { $total += $list->duration($i) ?? 0.0; }
            $c->text(self::t($c, 'videoTotal', ['time' => MoviePlayerState::clock($total)]), 11, self::$ink)->fontFeatures('tnum')->opacity(0.5)->textAlign(Node::CENTER)->grow(1);
            $add = $c->column()->tooltip(self::t($c, 'videoAddFiles')); self::sf($c, 'plus', 12, $c->hovered($add) ? 0.95 : 0.7); $c->end();
            if ($c->clicked($add) && $list !== null) {
                foreach (Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: self::t($c, 'videoAddFiles'), multiple: true)) ?? [] as $path) { $list->add($path); }
            }
            $remove = $c->column()->tooltip(self::t($c, 'videoRemoveChosen')); self::sf($c, 'minus', 12, $c->hovered($remove) ? 0.95 : 0.7); $c->end();
            if ($c->clicked($remove) && $list !== null && $s->chosen >= 0 && $s->chosen !== $list->index()) { $list->remove($s->chosen); $s->chosen = -1; }
            $clear = $c->column()->tooltip(self::t($c, 'videoClearPlaylist')); self::sf($c, 'trash', 12, $c->hovered($clear) ? 0.95 : 0.7); $c->end();
            if ($c->clicked($clear) && $list !== null) {
                for ($i = $list->count() - 1; $i >= 0; $i--) { if ($i !== $list->index()) { $list->remove($i); } }
                $s->chosen = -1;
            }
          $c->end();
    }

    /** The tabs in a glass pill at the top right: the chosen one with its icon and name, the others their icon, divided. */
    private static function tabBar(Context $c, MoviePlayerState $s): void
    {
        $tabs = [['layout', 'paintbrush.pointed.fill', 'videoLayout'], ['video', 'photo', 'videoVideo'], ['audio', 'waveform', 'videoAudio'], ['subtitles', 'captions.bubble.fill', 'videoSubtitles']];
        $c->row(3, 0)->absolute()->right(7)->top(9)->height(36)->align(Node::CENTER)->systemMaterial(self::glass($s, 'Sidebar'))->round(18);
          foreach ($tabs as $i => [$id, $icon, $label]) {
              $on = $s->tab === $id;
              if ($i > 0 && !$on && $tabs[$i - 1][0] !== $s->tab) { $c->box(1, 18, self::$ink)->opacity(0.25); } // between two not chosen
              $t = $c->row(0, 5)->height(30)->paddingX($on ? 10 : 7)->align(Node::CENTER)->round(15)->tooltip(self::t($c, $label));
              if ($on) { $t->background(self::$ink, 0.16); }
                self::sf($c, $icon, 14, $on || $c->hovered($t) ? 0.95 : 0.7);
                if ($on) { $c->text(self::t($c, $label), 13, self::$ink)->fontWeight(500)->opacity(0.95); }
              $c->end();
              if ($c->clicked($t)) { $s->tab = $id; $s->sidebar = $id; }
          }
        $c->end();
    }

    /** A card: a column over the glass, filled and ringed faintly. */
    private static function card(Context $c, float $gap = 10): Node
    {
        return self::frosted($c->column(12, $gap), 0.05, 0.1, 10)->fillWidth();
    }

    /** A heading in a card: symbol $icon and $title, and what $trailing builds at the right. */
    private static function heading(Context $c, string $icon, string $title, ?Closure $trailing = null): void
    {
        $c->row(0, 8)->align(Node::CENTER)->fillWidth();
          $c->column()->size(16, 16)->center(); self::sf($c, $icon, 12, 0.7); $c->end();
          $c->text($title, 13, self::$ink)->fontWeight(600)->opacity(0.9)->grow(1);
          if ($trailing !== null) { $trailing(); }
        $c->end();
    }

    /**
     * A list of tracks as IINA's: a box of rows, four at the least, the
     * chosen one marked with a bar in the accent, each its label and its
     * language at the right; returns the row clicked, or null.
     * @param list<array{0: string, 1: string}> $rows label and language
     */
    private static function trackList(Context $c, array $rows, ?int $chosen): ?int
    {
        $clicked = null;
        self::frosted($c->column(), 0.05, 0.14, 8)->fillWidth()->dividers(1, self::$ink, 0.1);
          foreach (array_pad($rows, 4, null) as $i => $r) {
              $row = $c->row(0, 6)->height(24.5)->paddingStart(20)->paddingEnd(8)->align(Node::CENTER)->fillWidth();
              if ($r !== null) {
                  if ($i === $chosen) { $c->box(3, 16, $c->theme->accent)->round(1.5)->absolute()->left(8)->top(4.25); }
                  $c->text($r[0], 13, self::$ink)->opacity($i === 0 ? 0.5 : 0.9)->noWrap()->ellipsis('…')->grow(1);
                  if ($r[1] !== '') { self::frosted($c->row()->padding(1, 3), 0.2, 0, 3); $c->text($r[1], 11, self::$ink)->opacity(0.85); $c->end(); }
                  if ($c->clicked($row)) { $clicked = $i; }
              }
              $c->end();
          }
        $c->end();
        return $clicked;
    }

    /** A segmented control as IINA's sidebar draws it: its options in a pill, the chosen one in the accent; those it cannot take are held. Returns the option clicked, or null. @param list<string> $options */
    private static function segments(Context $c, array $options, int $chosen, ?array $takes = null, bool $more = false): ?int
    {
        $clicked = null;
        $c->row(0, 4)->align(Node::CENTER); // as wide as its options, not the card
        self::frosted($c->row(0, 0), 0.1, 0, 6)->height(23)->align(Node::STRETCH);
          foreach ($options as $i => $label) {
              if ($i > 0 && $i !== $chosen && $i - 1 !== $chosen) { $c->column()->width(1)->paddingY(5); $c->box(1, 13, self::$ink)->opacity(0.25); $c->end(); }
              $seg = $c->column()->paddingX(11)->center()->round(6);
              if ($i === $chosen) { $seg->background($c->theme->accent); } // the choice in the system's accent, as IINA's
                $c->text($label, 13, self::$ink)->opacity(0.9);
              $c->end();
              if ($takes !== null && !in_array($i, $takes, true)) { $seg->tooltip(self::t($c, 'videoNotYet')); continue; }
              if ($c->clicked($seg)) { $clicked = $i; }
          }
        $c->end();
        if ($more) { $c->column()->tooltip(self::t($c, 'videoNotYet')); self::sf($c, 'chevron.right', 10, 0.5); $c->end(); } // IINA's: more ratios
        $c->end();
        return $clicked;
    }

    /** A switch as macOS 26 draws one, 38 by 18, $label's to assistive technology; returns whether it was clicked (never, not $enabled). */
    private static function toggleSwitch(Context $c, string $label, bool $on, bool $enabled = true): bool
    {
        $sw = $c->row()->size(38, 18)->role(Role::SWITCH)->label($label)->checked($on)->draw(static function (DisplayList $l, Node $n, Theme $t) use ($on): void {
            $l->rect($n->x, $n->y, $n->w, $n->h, $on ? $t->accent : self::$ink, $on ? 1.0 : 0.2, 9);
            $l->rect($on ? $n->x + $n->w - 24 : $n->x + 2, $n->y + 2, 22, 14, 0xffffff, 1.0, 7);
        });
        $c->end();
        if (!$enabled) { $sw->tooltip(self::t($c, 'videoNotYet')); return false; }
        return $c->clicked($sw);
    }

    /**
     * The chapters' tab, as IINA's: a row of 40 a chapter, the one playing
     * marked, its title (or "Chapter n", from 0) over when it starts and
     * when the next does (the last's "End"), lines between them and below;
     * a double click goes to one and plays.
     */
    private static function chapterList(Context $c, MoviePlayerState $s, float $h): Node
    {
        $p = $s->player;
        $chapters = $p?->chapters() ?? [];
        $playing = $p?->chapter() ?? -1;
        $list = $c->scroll()->absolute()->top(0)->left(0)->right(0)->bottom(0);
          $c->column()->fillWidth()->dividers(1, self::$ink, 0.1);
            foreach ($chapters as $i => $ch) {
                $row = $c->column(0, 1)->height(40)->paddingStart(28)->paddingEnd(9)->justify(Node::CENTER)->fillWidth();
                  if ($i === $playing) { $c->column()->absolute()->left(9)->top(13)->size(14, 14)->center(); self::sf($c, 'play.fill', 9, 0.9); $c->end(); }
                  $title = $ch->title !== '' ? $ch->title : self::t($c, 'videoChapterNumber', ['n' => (string)$i]);
                  $c->text($title, 13, self::$ink)->opacity(0.9)->noWrap()->ellipsis('…')->tooltip($title);
                  $end = isset($chapters[$i + 1]) ? MoviePlayerState::clock($chapters[$i + 1]->start) : self::t($c, 'videoChapterEnd');
                  $c->text(MoviePlayerState::clock($ch->start) . ' → ' . $end, 11, self::$ink)->fontFeatures('tnum')->opacity(0.55)->noWrap();
                $c->end();
                if ($c->doubleClicked($row)) { $s->playChapter($i); }
            }
          $c->end();
          $c->column()->fillWidth()->height(max(0.0, $h - 86 - 40 * count($chapters)))->passThrough()->draw(static function (DisplayList $l, Node $n): void { // the empty rows' lines below, as IINA's
              for ($y = $n->y + 40; $y < $n->y + $n->h; $y += 40) { $l->rect($n->x, $y, $n->w, 1, self::$ink, 0.1); }
          });
          $c->end();
        $c->end();
        return $list;
    }

    /**
     * A slider as IINA's sidebar draws one, with the value over its knob,
     * its marks under it and a field of the value at the right; $value
     * runs 0 to 1 along it. Returns whether it was dragged.
     * @param array<string, float> $marks label => where (0 to 1)
     */
    private static function sideSlider(Context $c, float &$value, string $label, array $marks, string $field, int $dots): bool
    {
        $c->row(0, 8)->align(Node::END)->fillWidth();
          $c->column()->grow(1)->height(48);
            $sl = $c->sliderBase($value, 0, 1)->absolute()->left(0)->right(0)->top(14)->height(20)->paddingX(5);
            $f = $sl->fraction;
            $sl->draw(static function (DisplayList $l, Node $n) use ($dots): void { // a track, dots under it, an upright white pill
                $x0 = $n->x + 5; $w = $n->w - 10; $cy = $n->y + $n->h / 2;
                $l->rect($n->x, $cy - 2, $n->w, 4, self::$ink, 0.15, 2);
                for ($i = 0; $i < $dots; $i++) { $l->rect($x0 + $w * $i / ($dots - 1) - 0.75, $cy + 6, 1.5, 1.5, self::$ink, 0.3, 0.75); }
                $l->rect($x0 + $w * $n->fraction - 4.5, $cy - 8.5, 9, 17, 0xffffff, 1.0, 4.5);
            });
            $changed = $c->changed($sl);
            $c->text($label, 10, self::$ink)->opacity(0.8)->textAlign(Node::CENTER)->absolute()->leftPercent($f * 100)->top(0)->width(40)->translate(-20, 0)->passThrough();
            foreach ($marks as $text => $at) { // the ends' marks within the slider, flush with its ends; the others centered under their place
                $m = $c->text((string)$text, 10, self::$ink)->opacity(0.8)->absolute()->top(35)->passThrough();
                match (true) {
                    $at <= 0 => $m->left(0),
                    $at >= 1 => $m->right(0),
                    default => $m->textAlign(Node::CENTER)->leftPercent($at * 100)->width(40)->translate(-20, 0),
                };
            }
          $c->end();
          self::frosted($c->row()->size(52, 26)->paddingX(8)->align(Node::CENTER), 0.1, 0, 6);
            $c->text($field, 13, self::$ink)->opacity(0.9);
          $c->end();
        $c->end();
        return $changed;
    }

    /** A button over the glass: $label, filled faintly; held when $enabled is false. */
    private static function sideButton(Context $c, string $label, bool $enabled = true): bool
    {
        $b = self::frosted($c->row()->height(24)->paddingX(10)->center(), $enabled ? 0.14 : 0.08, 0, 6);
          $c->text($label, 13, self::$ink)->opacity($enabled ? 0.9 : 0.5);
        $c->end();
        return $enabled && $c->clicked($b);
    }

    /** The short name of a codec, as mpv names it. */
    public static function codecName(string $codec): string
    {
        return match (true) {
            $codec === 'V_MPEG4/ISO/AVC' => 'h264', $codec === 'V_MPEGH/ISO/HEVC' => 'hevc', str_starts_with($codec, 'V_VP9') => 'vp9', str_starts_with($codec, 'V_AV1') => 'av1',
            str_starts_with($codec, 'A_AAC') => 'aac', $codec === 'A_AC3' => 'ac3', $codec === 'A_EAC3' => 'eac3', str_starts_with($codec, 'A_DTS') => 'dts', $codec === 'A_OPUS' => 'opus',
            $codec === 'A_FLAC' => 'flac', $codec === 'A_MPEG/L3' => 'mp3', $codec === 'A_TRUEHD' => 'truehd', $codec === 'A_VORBIS' => 'vorbis',
            $codec === 'S_TEXT/UTF8' => 'subrip', in_array($codec, ['S_TEXT/ASS', 'S_ASS'], true) => 'ass', in_array($codec, ['S_TEXT/SSA', 'S_SSA'], true) => 'ssa', $codec === 'S_TEXT/WEBVTT' => 'webvtt', $codec === 'S_TEXT/TX3G' => 'mov_text', $codec === 'S_HDMV/PGS' => 'hdmv_pgs_subtitle', $codec === 'S_VOBSUB' => 'dvd_subtitle',
            default => strtolower(preg_replace('/^[VAS]_/', '', $codec) ?? $codec),
        };
    }

    /** The name of language $code (ISO 639-2 as Matroska writes it, or 639-1) in $locale, '' for none. */
    public static function language(string $code, string $locale): string
    {
        $code = Track::primaryLanguage($code);
        if ($code === '') { return ''; }
        $name = (string)Locale::getDisplayLanguage($code, $locale);
        return $name !== '' ? $name : $code;
    }

    /** A track's row: its name and what it is in brackets, "default" if it is, and its language. @return array{0: string, 1: string} */
    public static function trackRow(Context $c, Track $t, string $details): array
    {
        $lang = self::language($t->language, $c->locale());
        $name = $t->name !== '' ? $t->name : ($lang !== '' ? $lang : "#{$t->number}");
        $what = implode(', ', array_filter([self::codecName($t->codec), $details, $t->isDefault ? self::t($c, 'videoDefault') : '']));
        return ["$name ($what)", $lang];
    }

    private static function videoTab(Context $c, MoviePlayerState $s, Player $p): void
    {
        $t = $p->videoTrack();
        $rows = [[self::t($c, 'videoNoTrack'), '']];
        if ($t !== null) {
            $rows[] = self::trackRow($c, $t, implode(', ', array_filter(["{$t->width}×{$t->height}", $t->frameDuration > 0 ? MoviePlayerState::number(1 / $t->frameDuration) . 'fps' : ''])));
        }
        self::trackList($c, $rows, $t === null ? 0 : 1); // one picture: what it shows
        $ratios = array_column(MoviePlayerState::ASPECTS, 1);
        $labels = array_slice(array_column(MoviePlayerState::ASPECTS, 0), 1);
        self::card($c, 9);
          self::heading($c, 'aspectratio', self::t($c, 'videoAspectRatio'));
          $i = self::segments($c, [self::t($c, 'videoDefault'), ...$labels], (int)array_search($p->aspectRatio(), $ratios, true), null, true);
          if ($i !== null) { $s->setAspect($i); }
          self::heading($c, 'crop', self::t($c, 'videoCrop'));
          $i = self::segments($c, [self::t($c, 'videoNone'), ...$labels], (int)array_search($p->crop(), $ratios, true), null, true);
          if ($i !== null) { $s->setCrop($i); }
          self::heading($c, 'rotate.right', self::t($c, 'videoRotation'));
          $i = self::segments($c, array_map(fn(int $d) => "{$d}°", MoviePlayerState::ROTATIONS), (int)array_search($p->rotation(), MoviePlayerState::ROTATIONS, true));
          if ($i !== null) { $s->setRotation($i); }
        $c->end();
        self::card($c, 2);
          self::heading($c, 'chevron.forward.2', self::t($c, 'videoSpeed'));
          $rate = MoviePlayerState::number($p->rate());
          if (self::sideSlider($c, $s->speedAt, "{$rate}x", ['0.25x' => 0, '1x' => 1 / 3, '4x' => 2 / 3, '16x' => 1], $rate, 25)) {
              $s->setSpeed(0.25 * 64 ** (round($s->speedAt * 24) / 24)); // to the dots: the speeds of IINA's slider
          }
        $c->end();
        self::card($c);
          self::heading($c, 'cpu', self::t($c, 'videoHardwareDecoding'), function () use ($c, $s, $p): void { // IINA's: as it plays, the frames from where it is decoded the other way; what decodes one way only (all but AV1 in hardware, AV1 in software without the system's decoder), as it does, fixed
              $hw = $p->hardwareDecoding();
              if (self::toggleSwitch($c, self::t($c, 'videoHardwareDecoding'), $hw, $p->decodingSwitchable())) { $s->setHardwareDecoding(!$hw); }
          });
        $c->end();
    }

    private static function audioTab(Context $c, MoviePlayerState $s, Player $p): void
    {
        $tracks = $p->audioTracks();
        $rows = [[self::t($c, 'videoNoTrack'), '']];
        foreach ($tracks as $t) {
            $rows[] = self::trackRow($c, $t, implode(', ', array_filter([$t->channels > 0 ? "{$t->channels}ch" : '', $t->sampleRate > 0 ? MoviePlayerState::number($t->sampleRate / 1000) . 'kHz' : '', Player::playable($t) ? '' : self::t($c, 'videoUnplayable')])));
        }
        $at = array_search($p->audioTrack(), $tracks, true);
        $i = self::trackList($c, $rows, $at === false ? 0 : $at + 1);
        if ($i !== null && $i > 0 && Player::playable($tracks[$i - 1])) { $p->setAudioTrack($tracks[$i - 1]); }
        self::card($c);
          if (self::sideButton($c, self::t($c, 'videoLoadAudio'))) { // IINA's: an open panel of the film's folder
              $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: self::t($c, 'videoLoadAudio'), defaultPath: $p->remote() ? '' : dirname($p->path())));
              if ($paths) { $s->loadAudio($paths[0]); }
          }
        $c->end();
        self::card($c, 2);
          self::heading($c, 'clock.arrow.circlepath', self::t($c, 'videoDelay'));
          $d = MoviePlayerState::number($p->audioDelay());
          $at = ($p->audioDelay() + 5) / 10;
          if (self::sideSlider($c, $at, "{$d}s", ['-5s' => 0, '0s' => 0.5, '+5s' => 1], $d, 21)) { $s->setAudioDelay($at * 10 - 5); }
        $c->end();
        self::card($c);
          self::heading($c, 'slider.vertical.3', self::t($c, 'videoEqualizer'));
          $c->row();
            $pop = self::frosted($c->row(0, 6)->height(24)->width(189)->paddingX(12)->align(Node::CENTER), 0.12, 0, 6);
              $c->text($s->equalizerProfile !== '' ? $s->equalizerProfile : self::t($c, $s->equalizerPreset === '' ? 'videoManual' : 'videoEq' . $s->equalizerPreset), 13, self::$ink)->opacity(0.9)->grow(1)->noWrap()->ellipsis('…');
              self::sf($c, 'chevron.up.chevron.down', 10, 0.8);
            $c->end();
            $pop->menu(function (Menu $m) use ($c, $s): void { // IINA's pop-up: saving (Manual's), renaming and removing (a profile's), Manual, its presets, the user's profiles
                $profile = $s->equalizerProfile;
                $manual = $s->equalizerPreset === '' && $profile === '';
                $names = $s->profileNames();
                if ($m->item(self::t($c, 'videoEqSave'))->disabled(!$manual)->chosen() && ($name = self::profileName($c, $names, true)) !== null) { $s->saveProfile($name); }
                if ($profile !== '') {
                    if ($m->item(self::t($c, 'videoEqRename'))->chosen() && ($name = self::profileName($c, $names, false)) !== null) { $s->renameProfile($name); }
                    if ($m->item(self::t($c, 'videoEqRemove'))->chosen()) { $s->removeProfile(); }
                }
                $m->separator();
                if ($m->item(self::t($c, 'videoManual'))->checked($manual)->chosen()) { [$s->equalizerPreset, $s->equalizerProfile] = ['', '']; } // the gains as they are, called Manual
                $m->separator();
                foreach (MoviePlayerState::EQUALIZER_PRESETS as $name => $gains) {
                    if ($m->item(self::t($c, "videoEq$name"))->checked($s->equalizerPreset === $name)->chosen()) { $s->setEqualizer($gains, $name); }
                }
                if ($names !== []) { $m->separator(); }
                foreach ($names as $name) {
                    if ($m->item($name)->checked($profile === $name)->chosen()) { $s->chooseProfile($name); }
                }
            });
          $c->end();
          self::equalizer($c, $s);
        $c->end();
    }

    /**
     * The name of a profile to save ($new) or of the one chosen, asked as
     * IINA's quickPromptPanel does: none, or one of a preset or profile
     * ($names), cannot be OK'd. Null when canceled.
     * @param list<string> $names the user's profiles'
     */
    private static function profileName(Context $c, array $names, bool $new): ?string
    {
        $taken = $names;
        foreach (array_keys(MoviePlayerState::EQUALIZER_PRESETS) as $preset) { $taken[] = self::t($c, "videoEq$preset"); }
        $key = $new ? 'videoEqNewProfile' : 'videoEqRenameProfile';
        return Dialog::prompt(Window::focused(), self::t($c, $key), '', self::t($c, "{$key}Message"), static fn(string $name): string => match (true) {
            $name === '' => self::t($c, 'videoValueEmpty'),
            in_array($name, $taken, true) => self::t($c, 'videoValueExists'),
            default => '',
        });
    }

    /**
     * IINA's ten bands, each a vertical slider of -12 to 12 dB, their
     * frequencies under them and the scale at the right. A slider dragged
     * sets its gain as it is let go (the sound given again from where it
     * is, filtered), the arrows at once; either makes the preset Manual.
     */
    private static function equalizer(Context $c, MoviePlayerState $s): void
    {
        $gains = $s->equalizerDraft ?? $s->equalizer;
        $moved = false;
        $c->row(0, 0)->fillWidth()->height(150);
          foreach (['32', '64', '125', '250', '500', '1k', '2k', '4k', '8k', '16k'] as $i => $band) {
              $c->column(0, 10)->width(26.8)->align(Node::CENTER);
                $g = $gains[$i];
                $sl = $c->sliderBase($g, -12, 12, 0.0, true)->size(16, 120)->label("$band Hz");
                $sl->draw(static function (DisplayList $l, Node $n): void { // the track, dots at +12, +6, 0, -6 and -12, the knob where the gain is
                    $cx = $n->x + $n->w / 2;
                    $l->rect($cx - 1.5, $n->y, 3, $n->h, self::$ink, 0.15, 1.5);
                    foreach ([0.0, 0.25, 0.75, 1.0] as $f) { $l->rect($cx + 5, $n->y + $n->h * $f - 0.75, 1.5, 1.5, self::$ink, 0.3, 0.75); }
                    $l->rect($n->x, $n->y + $n->h * (1 - $n->fraction) - 4, 16, 8, 0xffffff, 0.92, 4);
                });
                if ($c->changed($sl)) { $gains[$i] = round($g, 2); $moved = true; }
                $c->text($band, 10, self::$ink)->opacity(0.5);
              $c->end();
          }
          $c->column()->grow(1)->height(120);
            $c->text('+12 dB', 10, self::$ink)->opacity(0.8)->absolute()->right(0)->top(-6);
            $c->text('0 dB', 10, self::$ink)->opacity(0.8)->absolute()->right(0)->top(54);
            $c->text('-12 dB', 10, self::$ink)->opacity(0.8)->absolute()->right(0)->top(114);
          $c->end();
        $c->end();
        if ($moved) { $s->equalizerDraft = $gains; [$s->equalizerPreset, $s->equalizerProfile] = ['', '']; }
        if ($s->equalizerDraft !== null && !($c->runtime?->input->mouseDown ?? false)) { // let go (or an arrow): set
            $s->setEqualizer($s->equalizerDraft);
        }
    }

    private static function subtitleTab(Context $c, MoviePlayerState $s, Player $p): void
    {
        $tracks = $p->subtitleTracks();
        $rows = [[self::t($c, 'videoNoTrack'), '']];
        foreach ($tracks as $t) { $rows[] = self::trackRow($c, $t, ''); }
        $at = array_search($p->subtitleTrack(), $tracks, true);
        self::heading($c, '1.square.fill', self::t($c, 'videoPrimary'), function () use ($c, $s) {
            if (self::toggleSwitch($c, self::t($c, 'videoPrimary'), $s->subtitlesVisible)) { $s->toggleSubtitles(); }
        });
        $i = self::trackList($c, $rows, $at === false ? 0 : $at + 1);
        if ($i !== null && ($i === 0 || $tracks[$i - 1] !== $p->secondarySubtitleTrack())) { $p->setSubtitleTrack($i === 0 ? null : $tracks[$i - 1]); }
        self::heading($c, '2.square.fill', self::t($c, 'videoSecondary'), function () use ($c, $s) {
            if (self::toggleSwitch($c, self::t($c, 'videoSecondary'), $s->secondarySubtitlesVisible)) { $s->toggleSecondarySubtitles(); }
        });
        $texts = array_values(array_filter($tracks, fn(Track $t) => !in_array($t->codec, Subtitles::PICTURES, true))); // mpv's secondary: text tracks
        $rows2 = [[self::t($c, 'videoNoTrack'), '']];
        foreach ($texts as $t) { $rows2[] = self::trackRow($c, $t, ''); }
        $at2 = array_search($p->secondarySubtitleTrack(), $texts, true);
        $i = self::trackList($c, $rows2, $at2 === false ? 0 : $at2 + 1);
        if ($i !== null && ($i === 0 || $texts[$i - 1] !== $p->subtitleTrack())) { // the first's track is refused, as mpv's ("already selected")
            $p->setSecondarySubtitleTrack($i === 0 ? null : $texts[$i - 1]);
        }
        self::card($c);
          $c->row(0, 8)->align(Node::CENTER);
            if (self::sideButton($c, self::t($c, 'videoLoadSubtitles'))) {
                $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: self::t($c, 'videoLoadSubtitles')));
                if ($paths) { $s->loadSubtitles($paths[0]); }
            }
            if (self::sideButton($c, self::t($c, 'videoSearchOnline'), $s->openSubtitles !== null && !$s->searchingOnline)) { $s->searchOnlineSubtitles(); } // IINA's Find Online Subtitles, of OpenSubtitles: the options' key, else disabled
          $c->end();
        $c->end();
        self::card($c, 6);
          static $second = false; // which subtitles' delay the slider sets: IINA's segments over it
          $c->row()->fillWidth(); $c->column()->grow(1);
            if (($k = self::segments($c, [self::t($c, 'videoPrimary'), self::t($c, 'videoSecondary')], $second ? 1 : 0)) !== null) { $second = $k === 1; }
          $c->end(); $c->end();
          self::heading($c, 'clock.arrow.circlepath', self::t($c, 'videoDelay'));
          $delay = $second ? $p->secondarySubtitleDelay() : $p->subtitleDelay();
          $d = MoviePlayerState::number($delay);
          $at = ($delay + 5) / 10;
          if (self::sideSlider($c, $at, "{$d}s", ['-5s' => 0, '0s' => 0.5, '+5s' => 1], $d, 21)) {
              $second ? $p->setSecondarySubtitleDelay(max(-5.0, min(5.0, round($at * 10 - 5, 1)))) : $s->setSubtitleDelay($at * 10 - 5);
          }
        $c->end();
    }

    /** The layout tab, which the player has no settings for yet. */
    private static function layoutTab(Context $c): void
    {
        self::card($c);
          $c->text(self::t($c, 'videoLayoutNotYet'), 13, self::$ink)->opacity(0.6);
        $c->end();
    }
}
