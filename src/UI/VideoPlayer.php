<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use Locale;
use MyPHP\Dialog;
use MyPHP\Media\Player;
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
 * right click to play and pause, a double click to go full screen. The
 * controls show while the pointer moves and fade, the traffic lights and
 * the pointer with them; what the player has no means for yet (the
 * layout tab, the equalizer, external audio, secondary and online
 * subtitles, plugins, picture in picture, the playlist) shows, held.
 * Icons are SF Symbols.
 *
 * @internal Context::videoPlayer
 */
final class VideoPlayer
{
    public const SIDEBAR = 360.0;

    public static function build(Context $c, Player $p, VideoPlayerState $s, VideoPlayerOptions $o): Node
    {
        if ($c->runtime !== null) { $s->attach($p, $c->runtime); }
        $box = $c->column(0, 0, 1, 0x000000);
        if ($o->keys) { self::keys($c, $s, $p); }
        $picture = $c->video($p)->grow(1);
        [$px, $py] = $c->pointerPosition($box);
        $now = microtime(true);
        if ([$px, $py] !== $s->pointer) { $s->pointer = [$px, $py]; $s->moved = $now; }
        $slide = $c->animate($box, 'sidebar', $o->sidebar && $s->sidebar !== null ? 1 : 0, 0.25); // the sidebar sliding in from the right
        $right = self::SIDEBAR * $slide;
        $area = $c->width - $right; // the controls center over what the sidebar leaves
        $c->runtime?->askWindow('aspect', self::shownAspect($p)); // the window keeps the picture's shape, as IINA's

        // The title bar and the control bar show while the pointer moves and while it is on them, and fade.
        // Each built and closed before the next: an open node takes the next as its child.
        $bars = [];
        if ($o->titleBar) {
            $bars[] = $title = $c->row(0, 6)->absolute()->top(0)->left(0)->right($right)->height(32)->align(Node::CENTER)->paddingStart(84)->systemMaterial('titlebar', true)->dragWindow();
              self::sf($c, 'doc.fill', 11, 0.6);
              $c->text($s->title, 13, 0xffffff)->fontWeight(600)->opacity(0.85)->noWrap()->ellipsis('…')->grow(1);
            $c->end();
        }
        $bars[] = $osc = $c->column()->absolute()->left(($area - 460) / 2)->bottom($c->height * 0.1)->size(460, 80)->systemMaterial('glass')->round(12)->keepFocus();
          self::controlBar($c, $s, $p, $o);
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

        if ($s->subtitlesVisible && ($lines = $p->subtitles()) !== []) {
            self::subtitle($c, implode("\n", $lines), $c->height / 19.5, $c->height * 0.075, $right);
        }
        if ($o->osd) { self::osd($c, $s, $p, $now); }
        if ($s->error !== '') {
            $c->text($s->error, 13, 0xff6b6b)->absolute()->left(16)->bottom(16);
        }
        // A press outside the sidebar closes it, as IINA's, but on the control bar (its gear toggles it) or the title bar (which drags the window) while they show:
        // not a click, which the release of a drag begun in the sidebar (a slider let go over the picture) makes on what is under the pointer.
        if ($slide > 0) {
            $side = self::sidebar($c, $s, $p, $slide);
            $onBars = false;
            if ($fade > 0.001) { foreach ($bars as $n) { $onBars = $onBars || !$c->pressedOutside($n); } }
            if ($s->sidebar !== null && $c->pressedOutside($side) && !$onBars) { $s->sidebar = null; }
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
    private static function symbol(Context $c, string $name, float $size, float $weight = 0.0): Image
    {
        static $drawn = [];
        return $drawn["$name/$size/$weight/{$c->scale}"] ??= Image::symbol($name, $size, 0xffffff, $weight, $c->scale);
    }

    /** SF Symbol $name, white at $alpha. */
    private static function sf(Context $c, string $name, float $size, float $alpha = 1.0, float $weight = 0.0): Node
    {
        $img = self::symbol($c, $name, $size, $weight);
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

    /** The control bar's inside, 460 by 80: the volume, back, play, on and the bar's buttons above, the times and the bar of the time below. */
    private static function controlBar(Context $c, VideoPlayerState $s, Player $p, VideoPlayerOptions $o): void
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
        self::barButton($c, 'pip', 14, 388, 24, self::t($c, 'videoPictureInPicture') . " ($held)");
        self::barButton($c, 'list.bullet.rectangle', 14, 412, 24, self::t($c, 'videoPlaylist') . " ($held)");
        $gear = self::barButton($c, 'gearshape', 15, 436, 24, self::t($c, 'videoSettings'));
        if ($o->sidebar && $c->clicked($gear)) { $s->sidebar = $s->sidebar === null ? $s->tab : null; }
        $c->text(VideoPlayerState::clock($p->time()), 11.5, 0xffffff)->fontFeatures('tnum')->opacity(0.6)->absolute()->left(17.5)->top(50.5); // the system font, its digits of one width
        $seek = $c->sliderBase($s->at, 0, max($p->duration(), 0.001))->absolute()->left(58)->top(49)->size(344, 18)->paddingX(1.5);
        if (!$c->pressed($seek)) { $s->at = $p->time(); }
        $seek->draw(static function (DisplayList $l, Node $n): void { // a 4-point track, lighter where it played, and a white upright knob
            $x0 = $n->x + 1.5; $w = $n->w - 3; $cy = $n->y + $n->h / 2; $k = $x0 + $w * $n->fraction;
            $l->rect($n->x, $cy - 2, $n->w, 4, 0xffffff, 0.2, 2);
            $l->rect($n->x, $cy - 2, $k - $n->x, 4, 0xffffff, 0.45, 2);
            $l->rect($k - 1.5, $cy - 7.5, 3, 15, 0xffffff, 0.92, 1.5);
        });
        if ($c->changed($seek)) { $p->seek($s->at); }
        $c->text(VideoPlayerState::clock($p->duration()), 11.5, 0xffffff)->fontFeatures('tnum')->opacity(0.6)->absolute()->right(12.5)->top(50.5);
    }

    /** The message at the top left: its text, with the time or the volume's level under it, fading out. */
    private static function osd(Context $c, VideoPlayerState $s, Player $p, float $now): void
    {
        [$text, $under, $until] = $s->message;
        $box = $c->column(0, 1.5)->absolute()->left(8)->top(48)->padding(10, 16, 7, 16)->systemMaterial('glass')->round(12)->passThrough();
        $o = $c->animate($box, 'osd', $now < $until ? 1 : 0, 0.3);
        if ($now < $until) { $c->after($until - $now + 0.01); }
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
          }
        $c->end();
    }

    /** $text over the picture, centered $bottom points from its foot: white, outlined in black as mpv draws subtitles (copies of it around, under it). */
    public static function subtitle(Context $c, string $text, float $size, float $bottom, float $right = 0): void
    {
        $c->column()->absolute()->left(0)->right($right)->bottom($bottom)->align(Node::CENTER)->passThrough();
          $c->column()->maxWidth($c->width * 0.9);
            $d = $size / 16;
            foreach ([[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]] as [$x, $y]) {
                $c->text($text, $size, 0x000000)->fontWeight(500)->textAlign(Node::CENTER)->absolute()->left($x * $d)->top($y * $d)->right(-$x * $d);
            }
            $c->text($text, $size, 0xffffff)->fontWeight(500)->textAlign(Node::CENTER);
          $c->end();
        $c->end();
    }

    /** The quick settings, 360 points wide over the right of the window, sliding in by $slide: the back button, the tabs, the chosen tab's cards. */
    private static function sidebar(Context $c, VideoPlayerState $s, Player $p, float $slide): Node
    {
        $side = $c->column()->absolute()->top(0)->right(0)->bottom(0)->width(self::SIDEBAR)->systemMaterial('glass')->translate(self::SIDEBAR * (1 - $slide), 0)->keepFocus();
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

    /** A switch as macOS 26 draws one, 38 by 18; returns whether it was clicked. */
    private static function toggleSwitch(Context $c, bool $on, bool $enabled = true): bool
    {
        $sw = $c->row()->size(38, 18)->draw(static function (DisplayList $l, Node $n, Theme $t) use ($on): void {
            $l->rect($n->x, $n->y, $n->w, $n->h, $on ? $t->accent : 0xffffff, $on ? 1.0 : 0.2, 9);
            $l->rect($on ? $n->x + $n->w - 24 : $n->x + 2, $n->y + 2, 22, 14, 0xffffff, 1.0, 7);
        });
        $c->end();
        if (!$enabled) { $sw->tooltip(self::t($c, 'videoNotYet')); return false; }
        return $c->clicked($sw);
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
        if (!$enabled) { $b->tooltip(self::t($c, 'videoNotYet')); return false; }
        return $c->clicked($b);
    }

    /** The short name of a codec, as mpv names it. */
    public static function codecName(string $codec): string
    {
        return match (true) {
            $codec === 'V_MPEG4/ISO/AVC' => 'h264', $codec === 'V_MPEGH/ISO/HEVC' => 'hevc', str_starts_with($codec, 'V_VP9') => 'vp9', str_starts_with($codec, 'V_AV1') => 'av1',
            str_starts_with($codec, 'A_AAC') => 'aac', $codec === 'A_AC3' => 'ac3', $codec === 'A_EAC3' => 'eac3', str_starts_with($codec, 'A_DTS') => 'dts', $codec === 'A_OPUS' => 'opus',
            $codec === 'A_FLAC' => 'flac', $codec === 'A_MPEG/L3' => 'mp3', $codec === 'A_TRUEHD' => 'truehd', $codec === 'A_VORBIS' => 'vorbis',
            $codec === 'S_TEXT/UTF8' => 'subrip', in_array($codec, ['S_TEXT/ASS', 'S_ASS'], true) => 'ass', in_array($codec, ['S_TEXT/SSA', 'S_SSA'], true) => 'ssa', $codec === 'S_TEXT/WEBVTT' => 'webvtt', $codec === 'S_TEXT/TX3G' => 'mov_text',
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
          self::heading($c, 'cpu', self::t($c, 'videoHardwareDecoding'), fn() => self::toggleSwitch($c, true, false)); // the system decodes in hardware
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
          self::sideButton($c, self::t($c, 'videoLoadAudio'), false);
        $c->end();
        self::card($c, 2);
          self::heading($c, 'clock.arrow.circlepath', self::t($c, 'videoDelay'));
          $d = VideoPlayerState::number($p->audioDelay());
          $at = ($p->audioDelay() + 5) / 10;
          if (self::sideSlider($c, $at, "{$d}s", ['-5s' => 0, '0s' => 0.5, '+5s' => 1], $d, 21)) { $s->setAudioDelay($at * 10 - 5); }
        $c->end();
        self::card($c);
          self::heading($c, 'slider.vertical.3', self::t($c, 'videoEqualizer'));
          $c->row()->tooltip(self::t($c, 'videoNotYet'));
            self::frosted($c->row(0, 6)->height(24)->width(189)->paddingX(12)->align(Node::CENTER), 0.12, 0, 6);
              $c->text(self::t($c, 'videoManual'), 13, 0xffffff)->opacity(0.9)->grow(1);
              self::sf($c, 'chevron.up.chevron.down', 10, 0.8);
            $c->end();
          $c->end();
          self::equalizer($c);
        $c->end();
    }

    /** IINA's ten bands, each a vertical slider at 0 dB, their frequencies under them and the scale at the right: held, the player having no equalizer yet. */
    private static function equalizer(Context $c): void
    {
        $c->row(0, 0)->fillWidth()->height(150)->tooltip(self::t($c, 'videoNotYet'));
          foreach (['32', '64', '125', '250', '500', '1k', '2k', '4k', '8k', '16k'] as $band) {
              $c->column(0, 10)->width(26.8)->align(Node::CENTER);
                $c->column()->size(16, 120)->draw(static function (DisplayList $l, Node $n): void { // the track, dots at +12, 0 and -12, the knob at 0
                    $cx = $n->x + $n->w / 2;
                    $l->rect($cx - 1.5, $n->y, 3, $n->h, 0xffffff, 0.15, 1.5);
                    foreach ([0.0, 0.25, 0.75, 1.0] as $f) { $l->rect($cx + 5, $n->y + $n->h * $f - 0.75, 1.5, 1.5, 0xffffff, 0.3, 0.75); }
                    $l->rect($n->x, $n->y + $n->h / 2 - 4, 16, 8, 0xffffff, 0.92, 4);
                });
                $c->end();
                $c->text($band, 10, 0xffffff)->opacity(0.5);
              $c->end();
          }
          $c->column()->grow(1)->height(120);
            $c->text('+12 dB', 10, 0xffffff)->opacity(0.8)->absolute()->right(0)->top(-6);
            $c->text('0 dB', 10, 0xffffff)->opacity(0.8)->absolute()->right(0)->top(54);
            $c->text('-12 dB', 10, 0xffffff)->opacity(0.8)->absolute()->right(0)->top(114);
          $c->end();
        $c->end();
    }

    private static function subtitleTab(Context $c, VideoPlayerState $s, Player $p): void
    {
        $tracks = $p->subtitleTracks();
        $rows = [[self::t($c, 'videoNoTrack'), '']];
        foreach ($tracks as $t) { $rows[] = self::trackRow($c, $t, ''); }
        $at = array_search($p->subtitleTrack(), $tracks, true);
        self::heading($c, '1.square.fill', self::t($c, 'videoPrimary'), function () use ($c, $s) {
            if (self::toggleSwitch($c, $s->subtitlesVisible)) { $s->toggleSubtitles(); }
        });
        $i = self::trackList($c, $rows, $at === false ? 0 : $at + 1);
        if ($i !== null) { $p->setSubtitleTrack($i === 0 ? null : $tracks[$i - 1]); }
        self::heading($c, '2.square.fill', self::t($c, 'videoSecondary'), fn() => self::toggleSwitch($c, true, false));
        $c->column()->opacity(0.5)->fillWidth()->tooltip(self::t($c, 'videoNotYet')); self::trackList($c, [[self::t($c, 'videoNoTrack'), '']], 0); $c->end();
        self::card($c);
          $c->row(0, 8)->align(Node::CENTER);
            if (self::sideButton($c, self::t($c, 'videoLoadSubtitles'))) {
                $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: self::t($c, 'videoLoadSubtitles')));
                if ($paths) { $s->loadSubtitles($paths[0]); }
            }
            self::sideButton($c, self::t($c, 'videoSearchOnline'), false);
          $c->end();
        $c->end();
        self::card($c, 6);
          $c->row()->fillWidth(); $c->column()->grow(1); self::segments($c, [self::t($c, 'videoPrimary'), self::t($c, 'videoSecondary')], 0, [0]); $c->end(); $c->end();
          self::heading($c, 'clock.arrow.circlepath', self::t($c, 'videoDelay'));
          $d = VideoPlayerState::number($p->subtitleDelay());
          $at = ($p->subtitleDelay() + 5) / 10;
          if (self::sideSlider($c, $at, "{$d}s", ['-5s' => 0, '0s' => 0.5, '+5s' => 1], $d, 21)) { $s->setSubtitleDelay($at * 10 - 5); }
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
