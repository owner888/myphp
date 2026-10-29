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
 * The video player Context::videoPlayer builds, laid out as IINA's window
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
 * @internal Context::videoPlayer
 */
final class VideoPlayer
{
    public const SIDEBAR = 360.0;
    /** What a point the wheel moves does: of the volume (0 to 1, 200 points all of it), and seconds of a seek. */
    public const VOLUME_A_POINT = 0.005, SEEK_A_POINT = 0.1;

    public static function build(Context $c, VideoPlayerState $s, VideoPlayerOptions $o): Node
    {
        if ($c->runtime !== null) { $s->attach($c->runtime, $o); }
        $box = $c->column(0, 0, 1, 0x000000); // keyed by its state (Context::videoPlayer)
        $p = $s->player;
        if ($p === null) { // nothing opened yet: black, and what failed to open, or what opens
            if ($s->error !== '') { $box->center(); $c->text($s->error, 13, 0xff6b6b); }
            if ($s->opening !== null) { self::bufferIndicator($c, self::openingText($c, $s)); }
            $c->end();
            return $box;
        }
        if ($o->keys) { self::keys($c, $s, $p); }
        $picture = $c->video($p)->grow(1);
        $width = $c->width;
        $chooser = null; // the list of the subtitles found online, built below
        $picture->handleInput(function (InputEvent $e) use ($s, $width, $c, $picture, &$chooser): bool { // the wheel over the picture: up and down the volume, across where it plays, as IINA's
            $onSide = $s->sidebar === 'playlist' ? $e->x < self::SIDEBAR : $s->sidebar !== null && $e->x >= $width - self::SIDEBAR;
            if ($e->kind !== InputEvent::SCROLL || $onSide || ($chooser !== null && self::over($c, $chooser, ...$c->localToWindow($picture, $e->x, $e->y)))) {
                return false; // the rest goes on to the frame (a press, a click), the wheel over a sidebar or the list of subtitles found to its scroll
            }
            if (abs($e->dy) >= abs($e->dx)) {
                $s->setVolume($s->volume - $e->dy * self::VOLUME_A_POINT);
            } else {
                $s->wheelSeek += -$e->dx * self::SEEK_A_POINT; // a seek a tenth of a second at the most (build)
                $s->moved = microtime(true);
            }
            return true;
        });
        if ($s->wheelSeek != 0.0) { // what the wheel moved across, a seek every tenth of a second at the most: one costs reading from a keyframe
            $wait = 0.1 - (microtime(true) - $s->wheelSeekAt);
            if ($wait <= 0) {
                $s->seekBy($s->wheelSeek);
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
        if ($s->subtitlesVisible && !$s->pictureInPicture) {
            $c->key('subtitles')->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->passThrough()->belowMaterials();
              if (($styled = $p->subtitleLines()) !== []) { // an ASS track's lines, in their styles where they are
                  self::assLines($c, $p, $styled);
              } elseif (($lines = $p->subtitles()) !== []) {
                  self::subtitle($c, implode("\n", $lines), $c->height / 19.5, $c->height * 0.075, $right, $left);
              }
              if (($pictures = $p->subtitlePictures()) !== []) {
                  self::subtitlePictures($c, $p, $pictures);
              }
            $c->end();
        }
        if ($s->secondarySubtitlesVisible && !$s->pictureInPicture) { // mpv's toptitle: plain, as far from the top as the first from the foot
            $c->key('secondary subtitles')->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->passThrough()->belowMaterials();
              if (($lines = $p->secondarySubtitles()) !== []) {
                  self::subtitle($c, implode("\n", $lines), $c->height / 19.5, $c->height * 0.075, $right, $left, true);
              }
            $c->end();
        }

        // The title bar and the control bar show while the pointer moves and while it is on them, and fade.
        // Each built and closed before the next: an open node takes the next as its child.
        $bars = [];
        if ($o->titleBar) {
            $bars[] = $title = $c->row(0, 6)->absolute()->top(0)->left($left)->right($right)->height(32)->align(Node::CENTER)->paddingStart($left > 0 ? 12 : 84)->systemMaterial('titlebar', true)->dragWindow(); // after the traffic lights, but over the playlist
              self::sf($c, 'doc.fill', 11, 0.6);
              $c->text($s->title, 13, 0xffffff)->fontWeight(600)->opacity(0.85)->noWrap()->ellipsis('…')->grow(1);
            $c->end();
        }
        $oscX = $left + ($area - 460) / 2; $oscY = $c->height - $c->height * 0.1 - 80;
        $bars[] = $osc = $c->column()->absolute()->left($oscX)->bottom($c->height * 0.1)->size(460, 80)->systemMaterial('glass')->round(12)->keepFocus();
          $seek = self::controlBar($c, $s, $p, $o);
        $c->end();
        $shown = $now - $s->moved < VideoPlayerState::LINGER;
        foreach ($bars as $n) { $shown = $shown || $c->hovered($n); }
        $fade = $c->animate($box, 'controls', $shown ? 1 : 0, 0.25);
        $s->shown = $shown;
        if ($o->titleBar) { $c->runtime?->askWindow('buttons', $shown); } // the traffic lights go with the title bar
        if ($shown) { $c->after(VideoPlayerState::LINGER - ($now - $s->moved) + 0.05); } // to hide them
        else { $picture->cursor(Cursor::None); } // the pointer hides with them
        foreach ($bars as $n) {
            $fade > 0.001 ? $n->opacity($fade) : $n->invisible(); // faded out: nothing to click either
        }
        $c->clicked($osc); // a click on the bar that none of its controls took (the end of a drag of the time) is the bar's, not the picture's
        if ($fade > 0.001 && ([$sx, , $over] = $c->pointerPosition($seek)) && $over) {
            self::seekPreview($c, $s, $p, $oscX + self::SEEK_X, $oscY + self::SEEK_Y, $sx, $fade);
        }

        if ($o->osd) { self::osd($c, $s, $p, $now); }
        if ($s->onlineSubtitles !== null) { $chooser = self::onlineSubtitles($c, $s); }
        if ($s->opening !== null) {
            self::bufferIndicator($c, self::openingText($c, $s));
        } elseif (($percent = $p->bufferingPercent()) !== null) {
            self::bufferIndicator($c, self::t($c, 'videoBuffering', ['percent' => $percent]),
                VideoPlayerState::byteCount($p->cacheBytes(), true) . 'B (' . VideoPlayerState::byteCount($p->cacheSpeed(), false) . 'B/s)');
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
        if ($c->rightClicked($picture)) { $s->togglePause(); }
        if ($c->doubleClicked($picture)) { $c->runtime?->askWindow('fullScreen', !($c->runtime->fullScreen)); }
        $c->end();
        return $box;
    }

    /** The aspect the picture shows at in the window, its width over its height: its own or the one asked, cut, turned. */
    private static function shownAspect(Player $p): ?float
    {
        [$w, $h] = $p->size();
        if ($w <= 0 || $h <= 0) { return null; }
        $a = $p->crop() ?? $p->aspectRatio() ?? $w / $h;
        return $p->rotation() % 180 === 90 ? 1 / $a : $a;
    }

    /** IINA's default keys (iina-default-input.conf) that the player has the means for. */
    private static function keys(Context $c, VideoPlayerState $s, Player $p): void
    {
        [$shift, $alt, $cmd, $ctrl] = [Key::SHIFT, Key::OPTION, Key::COMMAND, Key::CONTROL];
        if ($c->shortcut(Key::SPACE) || $c->shortcut('p')) { $s->togglePause(); }
        if ($c->shortcut(Key::RIGHT)) { $s->seekBy(5); }
        if ($c->shortcut(Key::LEFT)) { $s->seekBy(-5); }
        if ($c->shortcut(Key::RIGHT, $alt) || $c->shortcut('.')) { $s->frameStep(); }
        if ($c->shortcut(Key::LEFT, $alt) || $c->shortcut(',')) { $s->frameStep(true); }
        if ($c->shortcut('s', $cmd)) { $s->screenshot(); }
        if ($c->shortcut('l', $cmd)) { $s->abLoop(); }
        if ($c->shortcut(Key::PAGE_UP, $shift)) { $s->seekBy(600); }
        if ($c->shortcut(Key::PAGE_DOWN, $shift)) { $s->seekBy(-600); }
        if ($c->shortcut(Key::UP)) { $s->setVolume($s->volume + 0.05); }
        if ($c->shortcut(Key::DOWN)) { $s->setVolume($s->volume - 0.05); }
        if ($c->shortcut(Key::UP, $alt)) { $s->setVolume($s->volume + 0.01); }
        if ($c->shortcut(Key::DOWN, $alt)) { $s->setVolume($s->volume - 0.01); }
        if ($c->shortcut('/', $cmd) || $c->shortcut('m')) { $s->toggleMute(); }
        if ($c->shortcut(Key::BRACKET_LEFT, $cmd)) { $s->setSpeed($p->rate() * 0.5); }
        if ($c->shortcut(Key::BRACKET_RIGHT, $cmd)) { $s->setSpeed($p->rate() * 2); }
        if ($c->shortcut(Key::BRACKET_LEFT, $alt | $cmd)) { $s->setSpeed($p->rate() / 1.1); }
        if ($c->shortcut(Key::BRACKET_RIGHT, $alt | $cmd)) { $s->setSpeed($p->rate() * 1.1); }
        if ($c->shortcut(Key::BACKSLASH, $cmd)) { $s->setSpeed(1); }
        if ($c->shortcut(Key::N9, $shift)) { $s->setAudioDelay($p->audioDelay() + 0.5); } // Shift+( and Shift+), Alt with them by a tenth, Shift+_ none
        if ($c->shortcut(Key::N0, $shift)) { $s->setAudioDelay($p->audioDelay() - 0.5); }
        if ($c->shortcut(Key::N9, $alt | $shift)) { $s->setAudioDelay($p->audioDelay() + 0.1); }
        if ($c->shortcut(Key::N0, $alt | $shift)) { $s->setAudioDelay($p->audioDelay() - 0.1); }
        if ($c->shortcut(Key::MINUS, $shift)) { $s->setAudioDelay(0); }
        if ($c->shortcut('z', $shift)) { $s->setSubtitleDelay($p->subtitleDelay() - 0.5); }
        if ($c->shortcut('x', $shift)) { $s->setSubtitleDelay($p->subtitleDelay() + 0.5); }
        if ($c->shortcut('z', $alt)) { $s->setSubtitleDelay($p->subtitleDelay() - 0.1); }
        if ($c->shortcut('x', $alt)) { $s->setSubtitleDelay($p->subtitleDelay() + 0.1); }
        if ($c->shortcut('c', $shift)) { $s->setSubtitleDelay(0); }
        if ($c->shortcut('v')) { $s->toggleSubtitles(); }
        if ($c->shortcut('o') || $c->shortcut('p', $shift)) { $s->showProgress(); }
        if ($c->shortcut('.', $cmd)) { $s->stop(); }
        if ($c->shortcut(Key::RIGHT, $cmd)) { $s->next(); } // playlist-next, playlist-prev
        if ($c->shortcut(Key::LEFT, $cmd)) { $s->previous(); }
        if ($c->shortcut('s', $ctrl | $cmd)) { $s->cycleSubtitles(); }
        if ($c->shortcut('a', $ctrl | $cmd)) { $s->cycleAudio(); }
        $full = $c->runtime?->fullScreen ?? false;
        if ($c->shortcut('f') || $c->shortcut('f', $ctrl | $cmd)) { $c->runtime?->askWindow('fullScreen', !$full); }
        if ($c->shortcut(Key::RETURN) && !$full) { $c->runtime?->askWindow('fullScreen', true); }
        if ($c->shortcut(Key::ESCAPE)) {
            if ($s->sidebar !== null) { $s->sidebar = null; } elseif ($full) { $c->runtime?->askWindow('fullScreen', false); }
        }
    }

    /** SF Symbol $name at $size points in white, at the window's scale: drawn once. */
    private static function symbol(Context $c, string $name, float $size, float $weight = 0.0, int $rgb = 0xffffff): Image
    {
        static $drawn = [];
        return $drawn["$name/$size/$weight/$rgb/{$c->scale}"] ??= Image::symbol($name, $size, $rgb, $weight, $c->scale);
    }

    /** SF Symbol $name, white (or $rgb) at $alpha. */
    private static function sf(Context $c, string $name, float $size, float $alpha = 1.0, float $weight = 0.0, int $rgb = 0xffffff): Node
    {
        $img = self::symbol($c, $name, $size, $weight, $rgb);
        return $c->image($img, $img->w / $c->scale, $img->h / $c->scale, 0, $alpha);
    }

    /** $n filled white at $fill and ringed white at $ring, round by $r: IINA's cards, lists and fields over the glass. */
    private static function frosted(Node $n, float $fill, float $ring, float $r): Node
    {
        return $n->draw(static function (DisplayList $l, Node $n) use ($fill, $ring, $r): void {
            $l->rect($n->x, $n->y, $n->w, $n->h, 0xffffff, $fill, $r);
            if ($ring > 0) { $l->ring($n->x, $n->y, $n->w, $n->h, 0xffffff, $ring, $r, 1); }
        });
    }

    private static function t(Context $c, string $key, array $args = []): string
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

    /** Where the bar of the time is in the control bar, and how wide. */
    private const SEEK_X = 58, SEEK_Y = 49, SEEK_W = 344, SEEK_H = 18;

    /**
     * Over the bar of the time under the pointer, $x from the bar's left
     * at ($bx, $by) of the player: the time there (and its chapter's title
     * above it) in a box of glass 5 points above the bar, and the thumbnail
     * of the film there 5 points above that, 120 points wide, round by 4,
     * ringed and shadowed, as IINA's TimePreviewView and ThumbnailPeekView,
     * centered on the pointer.
     */
    private static function seekPreview(Context $c, VideoPlayerState $s, Player $p, float $bx, float $by, float $x, float $alpha): void
    {
        $f = max(0.0, min(1.0, ($x - 1.5) / (self::SEEK_W - 3)));
        $t = $p->duration() * $f;
        $text = VideoPlayerState::clock($t);
        $chapter = null;
        foreach ($p->chapters() as $ch) { if ($ch->start <= $t + 1e-3) { $chapter = $ch; } }
        if ($chapter !== null) { $text = $chapter->title . "\n" . $text; }
        $cx = $bx + $x;
        $thumb = $s->thumbnailAt($t);
        // A column centered on the pointer, its foot 5 points above the bar: the thumbnail over the time.
        $c->column(0, 5)->absolute()->left($cx - 200)->width(400)->bottom($c->height - $by + 5)->align(Node::CENTER)->passThrough()->opacity($alpha);
          if ($thumb !== null) {
              static $images = null;
              $images ??= new \WeakMap(); // by thumbnail: its image
              $img = $images[$thumb] ??= Image::fromPixels($thumb->width, $thumb->height, $thumb->rgba());
              $w = 120.0; $h = round($w / (self::shownAspect($p) ?? $thumb->width / max(1, $thumb->height)));
              $turned = $p->rotation() % 180 === 90;
              $c->column()->size($w, $h)->round(4)->shadow(0, 0, 2, 0, 0x000000)->center()->drawOver(static function (DisplayList $l, Node $n): void {
                  $l->ring($n->x, $n->y, $n->w, $n->h, 0x999999, 0.5, 4, 1);
              });
                $c->image($img, $turned ? $h : $w, $turned ? $w : $h, 4)->rotate($p->rotation());
              $c->end();
          }
          $c->column()->padding(4, 8)->systemMaterial('glass')->round(16);
            $c->text($text, 11, 0xffffff)->fontFeatures('tnum')->textAlign(Node::CENTER);
          $c->end();
        $c->end();
    }

    /** The control bar's inside, 460 by 80: the volume, back, play, on and the bar's buttons above, the times and the bar of the time below; returns the bar of the time. */
    private static function controlBar(Context $c, VideoPlayerState $s, Player $p, VideoPlayerOptions $o): Node
    {
        $vol = $p->muted() || $s->volume == 0 ? 'speaker.slash.fill' : ($s->volume < 0.34 ? 'speaker.wave.1.fill' : ($s->volume < 0.67 ? 'speaker.wave.2.fill' : 'speaker.wave.3.fill'));
        if ($c->clicked(self::barButton($c, $vol, 13, 27, 24))) { $s->toggleMute(); }
        $level = $p->muted() ? 0.0 : $s->volume;
        $v = $c->sliderBase($level, 0, 1)->absolute()->left(45)->top(16)->size(69, 16)->paddingX(7.5);
        $v->draw(static function (DisplayList $l, Node $n): void { // a thin track, filled to the knob, and a white pill
            $x0 = $n->x + 7.5; $w = $n->w - 15; $cy = $n->y + $n->h / 2; $k = $x0 + $w * $n->fraction;
            $l->rect($n->x, $cy - 2, $n->w, 4, 0xffffff, 0.25, 2);
            $l->rect($n->x, $cy - 2, $k - $n->x, 4, 0xffffff, 0.8, 2);
            $l->rect($k - 7.5, $cy - 5, 15, 10, 0xffffff, 0.92, 5);
        });
        if ($c->changed($v)) { $s->setVolume($level); }
        if ($c->clicked(self::barButton($c, 'backward.fill', 17, 183, 24))) { $s->seekBy(-10); }
        if ($c->clicked(self::barButton($c, $p->playing() ? 'pause.fill' : 'play.fill', 22, 229, 24))) { $s->togglePause(); }
        if ($c->clicked(self::barButton($c, 'forward.fill', 17, 277, 24))) { $s->seekBy(10); }
        $held = self::t($c, 'videoNotYet');
        self::barButton($c, 'puzzlepiece.extension', 14, 364, 24, self::t($c, 'videoPlugins') . " ($held)");
        $pip = self::barButton($c, 'pip', 14, 388, 24, self::t($c, 'videoPictureInPicture'));
        if ($c->clicked($pip)) { $s->togglePictureInPicture(); }
        $list = self::barButton($c, 'list.bullet.rectangle', 14, 412, 24, self::t($c, 'videoPlaylist'));
        if ($o->sidebar && $c->clicked($list)) { $s->sidebar = $s->sidebar === 'playlist' ? null : 'playlist'; }
        $gear = self::barButton($c, 'gearshape', 15, 436, 24, self::t($c, 'videoSettings'));
        if ($o->sidebar && $c->clicked($gear)) { $s->sidebar = $s->sidebar === null || $s->sidebar === 'playlist' ? $s->tab : null; }
        $c->text(VideoPlayerState::clock($p->time()), 11.5, 0xffffff)->fontFeatures('tnum')->opacity(0.6)->absolute()->left(17.5)->top(50.5); // the system font, its digits of one width
        $seek = $c->sliderBase($s->at, 0, max($p->duration(), 0.001))->absolute()->left(self::SEEK_X)->top(self::SEEK_Y)->size(self::SEEK_W, self::SEEK_H)->paddingX(1.5);
        if (!$c->pressed($seek)) { $s->at = $p->time(); }
        $seek->draw(static function (DisplayList $l, Node $n): void { // a 4-point track, lighter where it played, and a white upright knob
            $x0 = $n->x + 1.5; $w = $n->w - 3; $cy = $n->y + $n->h / 2; $k = $x0 + $w * $n->fraction;
            $l->rect($n->x, $cy - 2, $n->w, 4, 0xffffff, 0.2, 2);
            $l->rect($n->x, $cy - 2, $k - $n->x, 4, 0xffffff, 0.45, 2);
            $l->rect($k - 1.5, $cy - 7.5, 3, 15, 0xffffff, 0.92, 1.5);
        });
        if ($c->changed($seek)) { $p->seek($s->at); }
        // The A-B loop's points, as IINA's knobs of the loop: gray, three quarters of the knob's height, over the bar; dragged, they move.
        foreach (['a' => $s->loopA, 'b' => $s->loopB] as $which => $at) {
            if ($at === null) { continue; }
            $x = self::SEEK_X + 1.5 + (self::SEEK_W - 3) * $at / max($p->duration(), 0.001);
            $knob = $c->column()->absolute()->left($x - 1.5)->top(self::SEEK_Y + 1.5)->size(3, 15)->tooltip('A-B loop ' . strtoupper($which))->draw(static function (DisplayList $l, Node $n): void {
                $l->rect($n->x, $n->y + 2, 3, 11, 0xa6a6a6, 1.0, 1);
            });
            $c->end();
            if ($c->pressed($knob)) {
                [$px] = $c->pointerPosition($seek);
                $to = $p->duration() * max(0.0, min(1.0, ($px - 1.5) / (self::SEEK_W - 3)));
                if (abs($to - $at) > 1e-3) { $s->moveLoopPoint($which, $to); }
            }
        }
        $c->text(VideoPlayerState::clock($p->duration()), 11.5, 0xffffff)->fontFeatures('tnum')->opacity(0.6)->absolute()->right(12.5)->top(50.5);
        return $seek;
    }

    /**
     * IINA's BufferIndicatorView, in the middle: a spinner, $text under it
     * and $detail (what is cached, how fast it comes) under that, 150 points
     * wide, in glass.
     */
    /** What the indicator says as a film opens: IINA's "Opening stream…", or the seconds left of the ad before a web page's video, a frame each second. */
    private static function openingText(Context $c, VideoPlayerState $s): string
    {
        $left = ($s->adOver ?? 0.0) - microtime(true);
        if ($left <= 0) {
            return self::t($c, 'videoOpeningStream');
        }
        $c->after($left - floor($left) + 0.01); // as the next second goes
        return self::t($c, 'videoSkippingAd', ['seconds' => (int)ceil($left)]);
    }

    private static function bufferIndicator(Context $c, string $text, ?string $detail = null): void
    {
        $c->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->center()->passThrough();
          $c->column(0, 4)->padding(12, 20)->systemMaterial('glass')->round(16)->align(Node::CENTER)->width(150 + 40);
            $c->spinner();
            $c->text($text, 13, 0xffffff)->textAlign(Node::CENTER);
            if ($detail !== null) { $c->text($detail, 11, 0xffffff)->fontFeatures('tnum')->opacity(0.7)->textAlign(Node::CENTER); }
          $c->end();
        $c->end();
    }

    /** The message at the top left: its text, with the time or the volume's level under it (or a line of its own), fading out. */
    private static function osd(Context $c, VideoPlayerState $s, Player $p, float $now): void
    {
        [$text, $under, $until] = $s->message;
        if ($s->onlineSubtitles !== null) { $text = ''; } // the list of subtitles found says it, where the message is
        $box = $c->column(0, 1.5)->absolute()->left(8)->top(48)->padding(10, 16, 7, 16)->systemMaterial('glass')->round(12)->passThrough();
        $o = $c->animate($box, 'osd', $now < $until ? 1 : 0, 0.3);
        if ($now < $until && is_finite($until)) { $c->after($until - $now + 0.01); } // one till another (a search going on) fades with it
        $box->opacity($o);
        if ($o <= 0.001 || $text === '') { $box->invisible(); }
          $c->text($text, 20, 0xffffff);
          if ($under === 'time') {
              $c->text(VideoPlayerState::clock($p->time()) . ' / ' . VideoPlayerState::clock($p->duration()), 11, 0xffffff)->fontFeatures('tnum')->opacity(0.7);
          } elseif ($under === 'volume') {
              $level = $p->muted() ? 0.0 : $s->volume;
              $c->row()->height(5)->fillWidth()->minWidth(90)->draw(static function (DisplayList $l, Node $n, Theme $t) use ($level): void {
                  $l->rect($n->x, $n->y, $n->w, $n->h, 0xffffff, 0.25, 2.5);
                  $l->rect($n->x, $n->y, $n->w * $level, $n->h, $t->accent, 1.0, 2.5); // the system's accent
              });
              $c->end();
          } elseif ($under !== '') { // a time of its own (an A-B loop's point moved)
              $c->text($under, 11, 0xffffff)->fontFeatures('tnum')->opacity(0.7);
          }
        $c->end();
    }

    /**
     * The settings of IINA's Subtitles pane the player has (PrefSubViewController),
     * in the app's look, for its settings window (Context::videoPlayerSettings):
     * under Online Subtitles, the OpenSubtitles account, "Not logged in" or
     * "Logged in as" its user, and Login (a name and password asked, the
     * account tried) or Logout; under Other, the preferred languages, a
     * code a token, the system's where none.
     */
    public static function settings(Context $c, VideoPlayerState $s): Node
    {
        $t = $c->theme;
        $st = $s->settings();
        $root = $c->column(0, $t->space(4))->padding($t->space(5))->fillWidth();
          $c->text(self::t($c, 'videoOnlineSubtitles'), $t->fontSize, $t->text)->fontWeight(600);
          $c->row(0, $t->space(3))->align(Node::CENTER)->fillWidth();
            $c->text(self::t($c, 'videoOpenSubtitlesAccount'), $t->fontSize, $t->text)->width(170)->textAlign(Node::END);
            $user = $st->user();
            $c->text($user === '' ? self::t($c, 'videoNotLoggedIn') : self::t($c, 'videoLoggedInAs', ['user' => $user]), $t->fontSize, $t->textMuted);
            $b = $c->button(self::t($c, $user === '' ? 'videoLogin' : 'videoLogout'));
            if ($s->loggingIn) { $b->disabled = true; $c->spinner(); }
            if ($c->clicked($b)) {
                if ($user !== '') {
                    $s->logOutOpenSubtitles();
                } elseif (($got = \MyPHP\Dialog::login(Window::focused(), self::t($c, 'videoOpenSubtitlesLogin'), self::t($c, 'videoOpenSubtitlesLoginMessage'), self::t($c, 'videoUsername'), self::t($c, 'videoPassword'))) !== null && $got[0] !== '' && $got[1] !== '') {
                    $s->logInOpenSubtitles($got[0], $got[1], static function (string $why) use ($c): void { // IINA's alert, its reason under
                        \MyPHP\Dialog::message(new \MyPHP\MessageOptions(parent: Window::focused(), type: 'warning', message: self::t($c, 'videoCannotLoginDetail', ['why' => $why])));
                    });
                }
            }
          $c->end();
          $c->text(self::t($c, 'videoOther'), $t->fontSize, $t->text)->fontWeight(600);
          $c->row(0, $t->space(3))->align(Node::START)->fillWidth();
            $c->text(self::t($c, 'videoPreferredLanguage'), $t->fontSize, $t->text)->width(170)->textAlign(Node::END)->paddingY($t->space(1.5));
            $c->column(0, $t->space(1))->grow(1);
              $languages = $st->languages();
              $f = $c->tokenField($languages, array_values(array_diff(\MyPHP\App::preferredLanguages(), $languages)), 300)->label(self::t($c, 'videoPreferredLanguage'));
              if ($c->changed($f)) { $s->setSubtitleLanguages($languages); }
              $c->text(self::t($c, 'videoPreferredLanguageHelp'), $t->fontSize - 2, $t->textMuted)->maxWidth(300);
            $c->end();
          $c->end();
        $c->end();
        return $root;
    }

    /**
     * IINA's list of the subtitles found online (SubChooseViewController,
     * in its OSD): how many, and a row of each, its file's name over its
     * language, year, frames a second and downloads, the day it was
     * uploaded at the right; a click chooses one (Command one more), a
     * double click downloads it; Cancel, and Download of those chosen.
     */
    private static function onlineSubtitles(Context $c, VideoPlayerState $s): Node
    {
        $found = $s->onlineSubtitles ?? [];
        $day = new \MyPHP\DateFormatter(\MyPHP\App::locale(), new \MyPHP\DateOptions(date: \MyPHP\Style::Medium));
        $box = $c->key('online subtitles')->column(0, 10)->absolute()->left(8)->top(48)->width(460)->padding(12, 14, 12, 14)->systemMaterial('glass')->round(12)->keepFocus();
          $c->text(self::t($c, 'videoSubtitlesFound', ['count' => count($found)]), 20, 0xffffff);
          $c->text(self::t($c, 'videoSubtitlesChoose'), 12, 0xffffff)->opacity(0.7);
          self::frosted($c->scroll()->height(min(5, count($found)) * 44.0)->fillWidth(), 0.08, 0, 6);
            $c->column()->fillWidth()->dividers(1, 0xffffff, 0.1);
              foreach ($found as $i => $f) {
                  $chosen = in_array($i, $s->onlineChosen, true);
                  $row = $c->column(0, 2)->height(44)->paddingX(10)->justify(Node::CENTER)->fillWidth();
                  if ($chosen) { ($c->runtime?->windowFocus ?? true) ? $row->background($c->theme->accent) : $row->background(0xffffff, 0.12); }
                    $c->text($f['name'], 13, 0xffffff)->opacity(0.9)->noWrap()->ellipsis('…');
                    $c->row(0, 8)->fillWidth();
                      $about = implode('  ', array_filter([$f['language'], $f['year'] > 0 ? "({$f['year']})" : '', $f['fps'] != 0 ? VideoPlayerState::number($f['fps']) . ' fps' : '', "\u{2b07}{$f['downloads']}"]));
                      $c->text($about, 11, 0xffffff)->opacity(0.6)->grow(1)->noWrap()->ellipsis('…');
                      $at = strtotime($f['uploaded']);
                      if ($at !== false) { $c->text($day->format((new \DateTimeImmutable("@$at"))->setTimezone(new \DateTimeZone(date_default_timezone_get()))), 11, 0xffffff)->opacity(0.6); }
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
    private static function assLines(Context $c, Player $p, array $lines): void
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
            $l->alone() ? self::assAlone($c, $p, $l, $px, $py, $pw, $ph) : self::assGroup($c, $p, $group, $px, $py, $pw, $ph);
        }
        foreach ($lines as $l) {
            if ($l->changes()) { $c->animationFrame(); break; } // moving, fading, animated, sung: a frame each refresh of the display while it shows, as a page's animation
        }
    }

    /**
     * A line laid out on its own (placed, turned, scaled or clipped), in
     * boxes of the plane one in the other: cut to \clip, turned by \frz
     * around \org (else the point of its alignment), scaled by \fscx and
     * \fscy around that point, as libass draws it.
     */
    private static function assAlone(Context $c, Player $p, AssLine $l, float $px, float $py, float $pw, float $ph): void
    {
        $sx = $pw / $l->playResX; $sy = $ph / $l->playResY;
        $r = $l->runs[0];
        $opened = 0;
        if ($l->clip !== null) {
            [$x1, $y1, $x2, $y2] = $l->clip;
            $c->column()->absolute()->left($px + $x1 * $sx)->top($py + $y1 * $sy)->size(max(0.0, ($x2 - $x1) * $sx), max(0.0, ($y2 - $y1) * $sy))->clip()->passThrough();
            [$px, $py] = [-$x1 * $sx, -$y1 * $sy];
            $opened++;
        }
        [$ax, $ay] = self::assAnchor($l);
        [$ox, $oy] = $l->origin ?? [$ax, $ay];
        $inner = fn() => $c->column()->absolute()->left(0)->top(0)->size($pw, $ph)->passThrough();
        $plane = $c->column()->absolute()->left($px)->top($py)->size($pw, $ph)->passThrough(); $opened++;
        if ($l->moved !== [0.0, 0.0]) { // \move: laid out where it starts, moved by a transform, in fractions of a pixel, not by its glyphs' places on whole ones
            $plane->translate($l->moved[0] * $sx, $l->moved[1] * $sy);
        }
        if ($r->rotation != 0) { $inner()->transformOrigin($ox / $l->playResX, $oy / $l->playResY)->rotate(-$r->rotation); $opened++; } // \frz turns counterclockwise
        if ($r->scaleX != 1 || $r->scaleY != 1) { $inner()->transformOrigin($ax / $l->playResX, $ay / $l->playResY)->scale($r->scaleX, $r->scaleY); $opened++; }
        self::assGroup($c, $p, [$l], 0, 0, $pw, $ph);
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

    /** Lines of one place, on the plane at ($px, $py) $pw by $ph: of one alignment and margins, or one laid out on its own. @param list<AssLine> $group */
    private static function assGroup(Context $c, Player $p, array $group, float $px, float $py, float $pw, float $ph): void
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
            $box = $c->column()->absolute()->left($px + $l->marginLeft * $sx)->top($py + $l->marginVertical * $sy)
                ->size(max(1.0, $pw - ($l->marginLeft + $l->marginRight) * $sx), max(1.0, $ph - 2 * $l->marginVertical * $sy));
            $wrap = $l->wraps ? max(1.0, $pw - ($l->marginLeft + $l->marginRight) * $sx) : null;
        }
        $box->align($across)->justify($along)->passThrough();
          foreach ($row === 0 ? array_reverse($group) : $group as $line) { // at the bottom a later line goes above the ones before
              foreach (self::assRows($line) as $runs) {
                  self::assRow($c, $p, $line, $runs, $sx, $sy, $wrap, $across);
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
     * A row of runs: the text in its colors over its outline ($border wide)
     * and its shadow (the outlined glyphs moved by $shadow), or for
     * BorderStyle 3 over a box of the outline's color; an empty row is the
     * height of its font. Fontsize is the height of a
     * font's ascent and descent, as libass sizes it, not its em.
     * @param list<AssRun> $runs
     */
    private static function assRow(Context $c, Player $p, AssLine $line, array $runs, float $sx, float $sy, ?float $wrap, int $across): void
    {
        $first = $runs[0] ?? $line->runs[0];
        [, $videoHeight] = $p->size();
        $px = $line->scaledBorder ? $sy : ($videoHeight > 0 ? $sy * $line->playResY / $videoHeight : $sy); // outlines and shadows of the plane's units, or of the video's pixels
        $pieces = [];
        foreach ($runs as $r) {
            foreach (self::assPieces($c, $r) as [$text, $em]) { $pieces[] = [$r, $text, $em]; }
        }
        $spans = static function (?int $color, ?float $alpha) use ($pieces, $sx, $sy): array {
            $out = [];
            foreach ($pieces as [$r, $text, $em]) {
                $out[] = new Span($text, size: $em * $sy, color: $color ?? $r->color, alpha: $alpha ?? $r->alpha, font: $r->font, weight: $r->weight, italic: $r->italic,
                    underline: $r->underline, strikethrough: $r->strikeout, letterSpacing: $r->spacing * $sx);
            }
            return $out;
        };
        $border = $first->border * $px; $shadow = $first->shadow * $px;
        $row = $c->column()->alignSelf($across)->passThrough();
        if ($wrap !== null) { // as wide as it wraps at, its lines aligned in it by the text's layout (a text of its lines' width would be moved twice)
            $row->width($wrap);
        }
        if ($line->alpha < 1) { // \fad, \fade
            $row->opacity($line->alpha);
        }
          if ($runs === []) { // a \N on its own: a blank row
              $c->box(1, self::assEm($c, $first) * $sy * 1.2, 0);
              $c->end();
              return;
          }
          if ($first->borderStyle === 3) { // an opaque box of the outline's color behind it, its shadow the box moved
              if ($shadow > 0) { $c->column()->absolute()->left($shadow - $border)->top($shadow - $border)->right(-$shadow - $border)->bottom(-$shadow - $border)->background($first->shadowColor, $first->shadowAlpha); $c->end(); }
              $c->column()->absolute()->left(-$border)->top(-$border)->right(-$border)->bottom(-$border)->background($first->outlineColor, $first->outlineAlpha); $c->end();
          }
          $text = $c->richText($spans(null, null), width: $wrap, wrap: $wrap !== null)->textAlign($across);
          if ($first->borderStyle !== 3) { // its outline and its shadow (of the outlined glyphs), as libass draws them: the glyphs grown
              if ($border > 0) { $text->textOutline($border, $first->outlineColor, $first->outlineAlpha); }
              if ($shadow > 0) { $text->textShadow($shadow, $shadow, $first->shadowColor, $first->shadowAlpha); }
          }
        $c->end();
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

    /** $text over the picture, centered $bottom points from its foot (from its top, $top: a toptitle): white, outlined in black as mpv draws subtitles. */
    public static function subtitle(Context $c, string $text, float $size, float $bottom, float $right = 0, float $left = 0, bool $top = false): void
    {
        $box = $c->column()->absolute()->left($left)->right($right)->align(Node::CENTER)->passThrough();
        $top ? $box->top($bottom) : $box->bottom($bottom);
          $c->column()->maxWidth($c->width * 0.9);
            $c->text($text, $size, 0xffffff)->fontWeight(500)->textAlign(Node::CENTER)->textOutline($size / 16, 0x000000);
          $c->end();
        $c->end();
    }

    /** The quick settings, 360 points wide over the right of the window, sliding in by $slide: the back button, the tabs, the chosen tab's cards. */
    private static function sidebar(Context $c, VideoPlayerState $s, Player $p, float $slide): Node
    {
        // Keyed, as the playlist's: the messages, the buffering indicator and the seek bar's preview built before it come and go,
        // which would renumber its nodes (its scroll's place, the state of its controls).
        $side = $c->key('quick settings')->column()->absolute()->top(0)->right(0)->bottom(0)->width(self::SIDEBAR)->systemMaterial('glass')->translate(self::SIDEBAR * (1 - $slide), 0)->keepFocus();
          $back = $c->column()->absolute()->left(9)->top(7)->size(38, 38)->center()->systemMaterial('glass')->round(19)->tooltip(self::t($c, 'videoHide'));
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
    private static function playlistSidebar(Context $c, VideoPlayerState $s, float $slide): Node
    {
        $side = $c->key('playlist')->column()->absolute()->top(0)->left(0)->bottom(0)->width(self::SIDEBAR)->systemMaterial('glass')->translate(-self::SIDEBAR * (1 - $slide), 0)->keepFocus();
          $c->row(3, 0)->absolute()->left(96)->top(9)->height(36)->align(Node::CENTER)->systemMaterial('glass')->round(18);
            foreach ([['playlist', 'music.note.list', 'videoPlaylist'], ['chapters', 'list.and.film', 'videoChapters']] as [$id, $icon, $label]) {
                $t = $c->row()->size(34, 30)->center()->round(15)->tooltip(self::t($c, $label));
                if ($s->playlistTab === $id) { $t->background(0xffffff, 0.16); }
                  self::sf($c, $icon, 14, $s->playlistTab === $id || $c->hovered($t) ? 0.95 : 0.7);
                $c->end();
                if ($c->clicked($t)) { $s->playlistTab = $id; }
            }
          $c->end();
          $back = $c->column()->absolute()->right(9)->top(7)->size(38, 38)->center()->systemMaterial('glass')->round(19)->tooltip(self::t($c, 'videoHide'));
            self::sf($c, 'chevron.left', 14, $c->hovered($back) ? 0.9 : 0.6, 0.2);
          $c->end();
          if ($c->clicked($back)) { $s->sidebar = null; }
          $list = $s->playlist;
          // The two tabs side by side, sliding across as one goes to the other, as IINA's: each page built while any of it shows.
          $page = $c->animate($side, 'playlistTab', $s->playlistTab === 'chapters' ? 1 : 0, 0.25);
          $c->column()->absolute()->top(53)->left(0)->right(0)->bottom(33)->clip();
          if ($page < 1) {
              $c->scroll()->absolute()->top(0)->left(0)->right(0)->bottom(0)->translate(-self::SIDEBAR * $page, 0);
                $c->column()->fillWidth()->dividers(1, 0xffffff, 0.1);
                  foreach ($list?->files() ?? [] as $i => $path) {
                      $playing = $i === $list->index();
                      $row = $c->row(0, 6)->height(32)->paddingStart(28)->paddingEnd(9)->align(Node::CENTER)->fillWidth();
                      if ($i === $s->chosen) { // the chosen row as AppKit's lists choose: the accent in the key window, gray in another
                          ($c->runtime?->windowFocus ?? true) ? $row->background($c->theme->accent) : $row->background(0xffffff, 0.12);
                      }
                        if ($playing) { $c->column()->absolute()->left(9)->top(9)->size(14, 14)->center(); self::sf($c, 'play.fill', 9, 0.9); $c->end(); }
                        $c->text($list->title($path), 13, 0xffffff)->opacity(0.9)->noWrap()->ellipsis('…')->grow(1);
                        $d = $list->duration($i);
                        if ($d !== null) { $c->text(VideoPlayerState::clock($d), 11, 0xffffff)->fontFeatures('tnum')->opacity(0.55); }
                      $c->end();
                      if ($c->doubleClicked($row)) { $s->open($path); } elseif ($c->clicked($row)) { $s->chosen = $i; }
                  }
                $c->end();
                $c->column()->fillWidth()->height(max(0.0, $c->height - 86 - 32 * ($list?->count() ?? 0)))->passThrough()->draw(static function (DisplayList $l, Node $n): void { // the empty rows' lines below, as IINA's
                    for ($y = $n->y + 32; $y < $n->y + $n->h; $y += 32) { $l->rect($n->x, $y, $n->w, 1, 0xffffff, 0.1); }
                });
                $c->end();
              $c->end();
          }
          if ($page > 0) {
              self::chapterList($c, $s)->translate(self::SIDEBAR * (1 - $page), 0);
          }
          $c->end();
          // The bar under the list: repeat, shuffle and sort (held), the time of them all, add, remove, clear.
          $c->row(0, 12)->absolute()->left(0)->right(0)->bottom(0)->height(33)->paddingX(12)->align(Node::CENTER)->background(0x000000, 0.18);
            // As IINA's: the loop button off, the file (repeat.1) or the playlist, in the accent while on; shuffle in it while shuffled; sort a menu.
            $loop = $c->column()->tooltip(self::t($c, 'videoRepeat'));
              $on = $s->loop !== 'off';
              self::sf($c, $s->loop === 'file' ? 'repeat.1' : 'repeat', 12, $on || $c->hovered($loop) ? 0.95 : 0.7, 0.0, $on ? $c->theme->accent : 0xffffff);
            $c->end();
            if ($c->clicked($loop)) { $s->nextLoop(); }
            $mixed = $list?->shuffled() ?? false;
            $shuffle = $c->column()->tooltip(self::t($c, 'videoShuffle'));
              self::sf($c, 'shuffle', 12, $mixed || $c->hovered($shuffle) ? 0.95 : 0.7, 0.0, $mixed ? $c->theme->accent : 0xffffff);
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
            $c->text(self::t($c, 'videoTotal', ['time' => VideoPlayerState::clock($total)]), 11, 0xffffff)->fontFeatures('tnum')->opacity(0.5)->textAlign(Node::CENTER)->grow(1);
            $add = $c->column()->tooltip(self::t($c, 'videoAddFiles')); self::sf($c, 'plus', 12, $c->hovered($add) ? 0.95 : 0.7); $c->end();
            if ($c->clicked($add) && $list !== null) {
                foreach (Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: self::t($c, 'videoAddFiles'), multiSelections: true)) ?? [] as $path) { $list->add($path); }
            }
            $remove = $c->column()->tooltip(self::t($c, 'videoRemoveChosen')); self::sf($c, 'minus', 12, $c->hovered($remove) ? 0.95 : 0.7); $c->end();
            if ($c->clicked($remove) && $list !== null && $s->chosen >= 0 && $s->chosen !== $list->index()) { $list->remove($s->chosen); $s->chosen = -1; }
            $clear = $c->column()->tooltip(self::t($c, 'videoClearPlaylist')); self::sf($c, 'trash', 12, $c->hovered($clear) ? 0.95 : 0.7); $c->end();
            if ($c->clicked($clear) && $list !== null) {
                for ($i = $list->count() - 1; $i >= 0; $i--) { if ($i !== $list->index()) { $list->remove($i); } }
                $s->chosen = -1;
            }
          $c->end();
        $c->end();
        $c->clicked($side); // a click on the playlist is its own, not the picture's
        return $side;
    }

    /** The tabs in a glass pill at the top right: the chosen one with its icon and name, the others their icon, divided. */
    private static function tabBar(Context $c, VideoPlayerState $s): void
    {
        $tabs = [['layout', 'paintbrush.pointed.fill', 'videoLayout'], ['video', 'photo', 'videoVideo'], ['audio', 'waveform', 'videoAudio'], ['subtitles', 'captions.bubble.fill', 'videoSubtitles']];
        $c->row(3, 0)->absolute()->right(7)->top(9)->height(36)->align(Node::CENTER)->systemMaterial('glass')->round(18);
          foreach ($tabs as $i => [$id, $icon, $label]) {
              $on = $s->tab === $id;
              if ($i > 0 && !$on && $tabs[$i - 1][0] !== $s->tab) { $c->box(1, 18, 0xffffff)->opacity(0.25); } // between two not chosen
              $t = $c->row(0, 5)->height(30)->paddingX($on ? 10 : 7)->align(Node::CENTER)->round(15)->tooltip(self::t($c, $label));
              if ($on) { $t->background(0xffffff, 0.16); }
                self::sf($c, $icon, 14, $on || $c->hovered($t) ? 0.95 : 0.7);
                if ($on) { $c->text(self::t($c, $label), 13, 0xffffff)->fontWeight(500)->opacity(0.95); }
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
          $c->text($title, 13, 0xffffff)->fontWeight(600)->opacity(0.9)->grow(1);
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
        self::frosted($c->column(), 0.05, 0.14, 8)->fillWidth()->dividers(1, 0xffffff, 0.1);
          foreach (array_pad($rows, 4, null) as $i => $r) {
              $row = $c->row(0, 6)->height(24.5)->paddingStart(20)->paddingEnd(8)->align(Node::CENTER)->fillWidth();
              if ($r !== null) {
                  if ($i === $chosen) { $c->box(3, 16, $c->theme->accent)->round(1.5)->absolute()->left(8)->top(4.25); }
                  $c->text($r[0], 13, 0xffffff)->opacity($i === 0 ? 0.5 : 0.9)->noWrap()->ellipsis('…')->grow(1);
                  if ($r[1] !== '') { self::frosted($c->row()->padding(1, 3), 0.2, 0, 3); $c->text($r[1], 11, 0xffffff)->opacity(0.85); $c->end(); }
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
              if ($i > 0 && $i !== $chosen && $i - 1 !== $chosen) { $c->column()->width(1)->paddingY(5); $c->box(1, 13, 0xffffff)->opacity(0.25); $c->end(); }
              $seg = $c->column()->paddingX(11)->center()->round(6);
              if ($i === $chosen) { $seg->background($c->theme->accent); } // the choice in the system's accent, as IINA's
                $c->text($label, 13, 0xffffff)->opacity(0.9);
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
            $l->rect($n->x, $n->y, $n->w, $n->h, $on ? $t->accent : 0xffffff, $on ? 1.0 : 0.2, 9);
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
    private static function chapterList(Context $c, VideoPlayerState $s): Node
    {
        $p = $s->player;
        $chapters = $p?->chapters() ?? [];
        $playing = $p?->chapter() ?? -1;
        $list = $c->scroll()->absolute()->top(0)->left(0)->right(0)->bottom(0);
          $c->column()->fillWidth()->dividers(1, 0xffffff, 0.1);
            foreach ($chapters as $i => $ch) {
                $row = $c->column(0, 1)->height(40)->paddingStart(28)->paddingEnd(9)->justify(Node::CENTER)->fillWidth();
                  if ($i === $playing) { $c->column()->absolute()->left(9)->top(13)->size(14, 14)->center(); self::sf($c, 'play.fill', 9, 0.9); $c->end(); }
                  $title = $ch->title !== '' ? $ch->title : self::t($c, 'videoChapterNumber', ['n' => (string)$i]);
                  $c->text($title, 13, 0xffffff)->opacity(0.9)->noWrap()->ellipsis('…')->tooltip($title);
                  $end = isset($chapters[$i + 1]) ? VideoPlayerState::clock($chapters[$i + 1]->start) : self::t($c, 'videoChapterEnd');
                  $c->text(VideoPlayerState::clock($ch->start) . ' → ' . $end, 11, 0xffffff)->fontFeatures('tnum')->opacity(0.55)->noWrap();
                $c->end();
                if ($c->doubleClicked($row)) { $s->playChapter($i); }
            }
          $c->end();
          $c->column()->fillWidth()->height(max(0.0, $c->height - 86 - 40 * count($chapters)))->passThrough()->draw(static function (DisplayList $l, Node $n): void { // the empty rows' lines below, as IINA's
              for ($y = $n->y + 40; $y < $n->y + $n->h; $y += 40) { $l->rect($n->x, $y, $n->w, 1, 0xffffff, 0.1); }
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
                $l->rect($n->x, $cy - 2, $n->w, 4, 0xffffff, 0.15, 2);
                for ($i = 0; $i < $dots; $i++) { $l->rect($x0 + $w * $i / ($dots - 1) - 0.75, $cy + 6, 1.5, 1.5, 0xffffff, 0.3, 0.75); }
                $l->rect($x0 + $w * $n->fraction - 4.5, $cy - 8.5, 9, 17, 0xffffff, 1.0, 4.5);
            });
            $changed = $c->changed($sl);
            $c->text($label, 10, 0xffffff)->opacity(0.8)->textAlign(Node::CENTER)->absolute()->leftPercent($f * 100)->top(0)->width(40)->translate(-20, 0)->passThrough();
            foreach ($marks as $text => $at) { // the ends' marks within the slider, flush with its ends; the others centered under their place
                $m = $c->text((string)$text, 10, 0xffffff)->opacity(0.8)->absolute()->top(35)->passThrough();
                match (true) {
                    $at <= 0 => $m->left(0),
                    $at >= 1 => $m->right(0),
                    default => $m->textAlign(Node::CENTER)->leftPercent($at * 100)->width(40)->translate(-20, 0),
                };
            }
          $c->end();
          self::frosted($c->row()->size(52, 26)->paddingX(8)->align(Node::CENTER), 0.1, 0, 6);
            $c->text($field, 13, 0xffffff)->opacity(0.9);
          $c->end();
        $c->end();
        return $changed;
    }

    /** A button over the glass: $label, filled faintly; held when $enabled is false. */
    private static function sideButton(Context $c, string $label, bool $enabled = true): bool
    {
        $b = self::frosted($c->row()->height(24)->paddingX(10)->center(), $enabled ? 0.14 : 0.08, 0, 6);
          $c->text($label, 13, 0xffffff)->opacity($enabled ? 0.9 : 0.5);
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
        $code = strtolower($code);
        if ($code === '' || $code === 'und') { return ''; }
        // The bibliographic codes of ISO 639-2, which ICU does not take, as their terminology ones.
        $code = ['chi' => 'zh', 'fre' => 'fr', 'ger' => 'de', 'dut' => 'nl', 'cze' => 'cs', 'gre' => 'el', 'per' => 'fa', 'rum' => 'ro', 'slo' => 'sk', 'wel' => 'cy', 'arm' => 'hy', 'baq' => 'eu', 'bur' => 'my', 'geo' => 'ka', 'ice' => 'is', 'mac' => 'mk', 'mao' => 'mi', 'may' => 'ms', 'alb' => 'sq', 'tib' => 'bo'][$code] ?? $code;
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

    private static function videoTab(Context $c, VideoPlayerState $s, Player $p): void
    {
        $t = $p->videoTrack();
        $rows = [[self::t($c, 'videoNoTrack'), '']];
        if ($t !== null) {
            $rows[] = self::trackRow($c, $t, implode(', ', array_filter(["{$t->width}×{$t->height}", $t->frameDuration > 0 ? VideoPlayerState::number(1 / $t->frameDuration) . 'fps' : ''])));
        }
        self::trackList($c, $rows, $t === null ? 0 : 1); // one picture: what it shows
        $ratios = array_column(VideoPlayerState::ASPECTS, 1);
        $labels = array_slice(array_column(VideoPlayerState::ASPECTS, 0), 1);
        self::card($c, 9);
          self::heading($c, 'aspectratio', self::t($c, 'videoAspectRatio'));
          $i = self::segments($c, [self::t($c, 'videoDefault'), ...$labels], (int)array_search($p->aspectRatio(), $ratios, true), null, true);
          if ($i !== null) { $s->setAspect($i); }
          self::heading($c, 'crop', self::t($c, 'videoCrop'));
          $i = self::segments($c, [self::t($c, 'videoNone'), ...$labels], (int)array_search($p->crop(), $ratios, true), null, true);
          if ($i !== null) { $s->setCrop($i); }
          self::heading($c, 'rotate.right', self::t($c, 'videoRotation'));
          $i = self::segments($c, array_map(fn(int $d) => "{$d}°", VideoPlayerState::ROTATIONS), (int)array_search($p->rotation(), VideoPlayerState::ROTATIONS, true));
          if ($i !== null) { $s->setRotation($i); }
        $c->end();
        self::card($c, 2);
          self::heading($c, 'chevron.forward.2', self::t($c, 'videoSpeed'));
          $rate = VideoPlayerState::number($p->rate());
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

    private static function audioTab(Context $c, VideoPlayerState $s, Player $p): void
    {
        $tracks = $p->audioTracks();
        $rows = [[self::t($c, 'videoNoTrack'), '']];
        foreach ($tracks as $t) {
            $rows[] = self::trackRow($c, $t, implode(', ', array_filter([$t->channels > 0 ? "{$t->channels}ch" : '', $t->sampleRate > 0 ? VideoPlayerState::number($t->sampleRate / 1000) . 'kHz' : '', Player::playable($t) ? '' : self::t($c, 'videoUnplayable')])));
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
          $d = VideoPlayerState::number($p->audioDelay());
          $at = ($p->audioDelay() + 5) / 10;
          if (self::sideSlider($c, $at, "{$d}s", ['-5s' => 0, '0s' => 0.5, '+5s' => 1], $d, 21)) { $s->setAudioDelay($at * 10 - 5); }
        $c->end();
        self::card($c);
          self::heading($c, 'slider.vertical.3', self::t($c, 'videoEqualizer'));
          $c->row();
            $pop = self::frosted($c->row(0, 6)->height(24)->width(189)->paddingX(12)->align(Node::CENTER), 0.12, 0, 6);
              $c->text($s->equalizerProfile !== '' ? $s->equalizerProfile : self::t($c, $s->equalizerPreset === '' ? 'videoManual' : 'videoEq' . $s->equalizerPreset), 13, 0xffffff)->opacity(0.9)->grow(1)->noWrap()->ellipsis('…');
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
                foreach (VideoPlayerState::EQUALIZER_PRESETS as $name => $gains) {
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
        foreach (array_keys(VideoPlayerState::EQUALIZER_PRESETS) as $preset) { $taken[] = self::t($c, "videoEq$preset"); }
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
    private static function equalizer(Context $c, VideoPlayerState $s): void
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
                    $l->rect($cx - 1.5, $n->y, 3, $n->h, 0xffffff, 0.15, 1.5);
                    foreach ([0.0, 0.25, 0.75, 1.0] as $f) { $l->rect($cx + 5, $n->y + $n->h * $f - 0.75, 1.5, 1.5, 0xffffff, 0.3, 0.75); }
                    $l->rect($n->x, $n->y + $n->h * (1 - $n->fraction) - 4, 16, 8, 0xffffff, 0.92, 4);
                });
                if ($c->changed($sl)) { $gains[$i] = round($g, 2); $moved = true; }
                $c->text($band, 10, 0xffffff)->opacity(0.5);
              $c->end();
          }
          $c->column()->grow(1)->height(120);
            $c->text('+12 dB', 10, 0xffffff)->opacity(0.8)->absolute()->right(0)->top(-6);
            $c->text('0 dB', 10, 0xffffff)->opacity(0.8)->absolute()->right(0)->top(54);
            $c->text('-12 dB', 10, 0xffffff)->opacity(0.8)->absolute()->right(0)->top(114);
          $c->end();
        $c->end();
        if ($moved) { $s->equalizerDraft = $gains; [$s->equalizerPreset, $s->equalizerProfile] = ['', '']; }
        if ($s->equalizerDraft !== null && !($c->runtime?->input->mouseDown ?? false)) { // let go (or an arrow): set
            $s->setEqualizer($s->equalizerDraft);
        }
    }

    private static function subtitleTab(Context $c, VideoPlayerState $s, Player $p): void
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
          $d = VideoPlayerState::number($delay);
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
          $c->text(self::t($c, 'videoLayoutNotYet'), 13, 0xffffff)->opacity(0.6);
        $c->end();
    }
}
