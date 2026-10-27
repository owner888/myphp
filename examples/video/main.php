<?php
// A video player laid out as IINA's window: a Matroska, WebM, MP4 or MOV
// file decoded and shown by the system (Media\Player), with IINA's
// controls over the picture in the system's glass, drawn with SF Symbols:
// the title bar with the traffic lights, the floating control bar (the
// volume, back, play and pause, on, the bar of the time), the messages at
// the top left (OSD) and the sidebar of quick settings with its video,
// audio and subtitle tabs; what the player has no means for yet (the
// layout tab, crop, rotation, the equalizer, secondary subtitles, online
// subtitles, plugins, picture in picture, the playlist) shown and held.
// The keys are IINA's defaults (iina-default-input.conf) that the player
// has the means for; a right click plays and pauses, a double click goes
// full screen; a subtitle file dropped on the window is loaded.
// Run: ./run.sh video [file]
declare(strict_types=1);

use MyPHP\{App, Dialog, FileDropEvent, Menu, MenuItem, OpenDialogOptions, Theme, ThemeSource, Window, WindowOptions};
use MyPHP\Media\{Player, Subtitles, Track};
use MyPHP\UI\{Content, Context, Cursor, DisplayList, Image, Key, Node};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

final class Videos
{
    /** Seconds the controls stay after the pointer last moved (IINA's default). */
    public const LINGER = 2.5;
    /** Seconds a message shows (IINA's default). */
    public const OSD = 1.0;
    public const SIDEBAR = 360.0;
    public ?Window $window = null;
    public ?Player $player = null;
    public string $file = '', $error = '';
    /** Where the pointer was last frame, and when it last moved. */
    public array $pointer = [0.0, 0.0];
    public float $moved = 0;
    /** Whether the controls showed last frame: the traffic lights go with them. */
    public bool $shown = true;
    /** The sidebar's tab ('video', 'audio', 'sub'), or null while it is closed; the tab it slid away with. */
    public ?string $sidebar = null;
    public string $tab = 'video';
    /** The message at the top left: its text, what shows under it ('time', 'volume' or ''), and until when. */
    public array $osd = ['', '', 0.0];
    public bool $subVisible = true;
    /** The sliders' places: the speed's (0 to 1 over 0.25x to 16x, as IINA's) and the delay of the subtitles in seconds. */
    public float $speedAt = 1 / 3, $subDelay = 0;
    /** The time the bar is at, which follows the player but for a drag of it; the volume's slider. */
    public float $at = 0, $volume = 1;

    public function open(string $path): void
    {
        $this->player?->pause();
        try {
            $p = new Player($path);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->window?->invalidate();
            return;
        }
        $this->player = $p;
        $this->file = basename($path);
        $this->error = '';
        $p->setVolume($this->volume);
        $p->on('time', fn() => $this->window?->invalidate()); // the time and the bar move
        $p->on('ended', fn() => $this->window?->invalidate());
        $p->on('error', function (string $why) { $this->error = $why; $this->window?->invalidate(); });
        $this->window?->setTitle($this->file);
        [$w, $h] = $p->size();
        if ($w > 0 && $h > 0 && $this->window !== null && !$this->window->isFullScreen()) { // the window to the picture's shape, its width kept, as IINA fits it
            $cw = $this->window->contentSize()[0];
            $this->window->setContentSize($cw, (int)round($cw * $h / $w));
        }
        if ($w > 0 && $h > 0) { $this->window?->setAspectRatio($w / $h); } // resized by either side, the window keeps the picture's shape, as IINA's
        $p->play();
        $this->moved = microtime(true);
    }

    /** Opens a dropped file: a subtitle file over the film playing, else a film. */
    public function drop(string $path): void
    {
        if ($this->player !== null && isset(Subtitles::FILES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
            $this->loadSubtitles($path);
            return;
        }
        $this->open($path);
    }

    public function loadSubtitles(string $path): void
    {
        try {
            $this->player?->setSubtitleTrack($this->player->loadSubtitles($path));
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
        $this->window?->invalidate();
    }

    /** Shows $text at the top left, with the time or the volume under it, as IINA's OSD. */
    public function say(string $text, string $under = ''): void
    {
        $this->osd = [$text, $under, microtime(true) + self::OSD];
        $this->window?->invalidate();
    }

    public function togglePause(): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $p->playing() ? $p->pause() : $p->play();
        $this->say($p->playing() ? '继续' : '暂停', 'time');
    }

    public function seekBy(float $s): void
    {
        $this->player?->seek($this->player->time() + $s);
        $this->say(clock($this->player?->time() ?? 0) . ' / ' . clock($this->player?->duration() ?? 0));
    }

    public function setVolume(float $v): void
    {
        $this->volume = max(0.0, min(1.0, $v));
        $this->player?->setVolume($this->volume);
        if ($this->player?->muted()) { $this->player->setMuted(false); }
        $this->say('音量：' . (int)round($this->volume * 100), 'volume');
    }

    public function toggleMute(): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $p->setMuted(!$p->muted());
        $this->say($p->muted() ? '静音' : '取消静音');
    }

    /** Plays at $rate, 0.25x to 16x as IINA's slider goes. */
    public function setSpeed(float $rate): void
    {
        $rate = max(0.25, min(16.0, round($rate, 2)));
        $this->player?->setRate($rate);
        $this->speedAt = log($rate / 0.25, 64);
        $this->say('速度：' . number($rate) . 'x');
    }

    public function setSubDelay(float $s): void
    {
        $this->subDelay = max(-5.0, min(5.0, round($s, 1)));
        $this->player?->setSubtitleDelay($this->subDelay);
        $d = $this->subDelay;
        $this->say($d == 0 ? '字幕延迟：无延迟' : ($d > 0 ? '字幕延迟：延迟 ' . number($d) . ' 秒' : '字幕延迟：提前 ' . number(-$d) . ' 秒'));
    }

    /** The next track of $tracks after $current, round to none. @param list<Track> $tracks */
    public static function cycle(array $tracks, ?Track $current): ?Track
    {
        $i = $current === null ? -1 : (int)array_search($current, $tracks, true);
        return $tracks[$i + 1] ?? null;
    }
}

function clock(float $s): string
{
    $s = (int)$s;
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s, 60) % 60, $s % 60) : sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
}

/** $v as IINA writes a number: no trailing zeros. */
function number(float $v): string
{
    return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.');
}

/** SF Symbol $name at $size points in white, at the window's scale: drawn once. */
function symbol(Context $c, string $name, float $size, float $weight = 0.0): Image
{
    static $drawn = [];
    return $drawn["$name/$size/$weight/{$c->scale}"] ??= Image::symbol($name, $size, 0xffffff, $weight, $c->scale);
}

/** SF Symbol $name, white at $alpha. */
function sf(Context $c, string $name, float $size, float $alpha = 1.0, float $weight = 0.0): Node
{
    $img = symbol($c, $name, $size, $weight);
    return $c->image($img, $img->w / $c->scale, $img->h / $c->scale, 0, $alpha);
}

/** A box filled white at $fill and ringed white at $ring, round by $r: IINA's cards, lists and fields over the glass. */
function frosted(Node $n, float $fill, float $ring, float $r): Node
{
    return $n->draw(static function (DisplayList $l, Node $n) use ($fill, $ring, $r): void {
        $l->rect($n->x, $n->y, $n->w, $n->h, 0xffffff, $fill, $r);
        if ($ring > 0) { $l->ring($n->x, $n->y, $n->w, $n->h, 0xffffff, $ring, $r, 1); }
    });
}

/** A button of the control bar: symbol $name centered at ($x, $y) in a 26-point square, brighter under the pointer. */
function barButton(Context $c, string $name, float $size, float $x, float $y, string $tip = ''): Node
{
    $b = $c->column()->absolute()->left($x - 13)->top($y - 13)->size(26, 26)->center();
    if ($tip !== '') { $b->tooltip($tip); }
      sf($c, $name, $size, $c->hovered($b) ? 0.95 : 0.75); // IINA's are a light gray, white under the pointer
    $c->end();
    return $b;
}

function view(Context $c, Videos $v): void
{
    $box = $c->column(0, 0, 1, 0x000000);
    $p = $v->player;
    if ($p === null) {
        $box->center();
          $c->text('打开视频（⌘O）或将文件拖到窗口', 15, 0xffffff)->opacity(0.7);
          if ($v->error !== '') { $c->text($v->error, 13, 0xff6b6b); }
        $c->end();
        return;
    }
    keys($c, $v, $p);
    $picture = $c->video($p)->grow(1);
    [$px, $py] = $c->pointerPosition($box);
    $now = microtime(true);
    if ([$px, $py] !== $v->pointer) { $v->pointer = [$px, $py]; $v->moved = $now; }
    $slide = $c->animate($box, 'sidebar', $v->sidebar !== null ? 1 : 0, 0.25); // the sidebar sliding in from the right
    $right = Videos::SIDEBAR * $slide;
    $area = $c->width - $right; // the controls center over what the sidebar leaves

    // The title bar and the control bar show while the pointer moves and while it is on them, and fade.
    // Each built and closed before the next: an open node takes the next as its child.
    $title = $c->row(0, 6)->absolute()->top(0)->left(0)->right($right)->height(32)->align(Node::CENTER)->paddingStart(84)->systemMaterial('titlebar', true)->dragWindow();
      sf($c, 'doc.fill', 11, 0.6);
      $c->text($v->file, 13, 0xffffff)->fontWeight(600)->opacity(0.85)->noWrap()->ellipsis('…')->grow(1);
    $c->end();
    $osc = $c->column()->absolute()->left(($area - 460) / 2)->bottom($c->height * 0.1)->size(460, 80)->systemMaterial('glass')->round(12)->keepFocus();
      controlBar($c, $v, $p);
    $c->end();
    $shown = $now - $v->moved < Videos::LINGER || $c->hovered($title) || $c->hovered($osc);
    $fade = $c->animate($box, 'controls', $shown ? 1 : 0, 0.25);
    if ($shown !== $v->shown) { $v->shown = $shown; $v->window?->setWindowButtonVisibility($shown); }
    if ($shown) { $c->after(Videos::LINGER - ($now - $v->moved) + 0.05); } // to hide them
    else { $picture->cursor(Cursor::None); } // the pointer hides with them
    foreach ([$title, $osc] as $n) {
        $fade > 0.001 ? $n->opacity($fade) : $n->invisible(); // faded out: nothing to click either
    }
    $c->clicked($osc); // a click on the bar that none of its controls took (the end of a drag of the time) is the bar's, not the picture's

    if ($v->subVisible && ($lines = $p->subtitles()) !== []) {
        subtitle($c, implode("\n", $lines), $c->height / 19.5, $c->height * 0.075, $right);
    }
    osd($c, $v, $p, $now);
    if ($v->error !== '') {
        $c->text($v->error, 13, 0xff6b6b)->absolute()->left(16)->bottom(16);
    }
    // A press outside the sidebar closes it, as IINA's, but on the control bar (its gear toggles it) or the title bar (which drags the window) while they show:
    // not a click, which the release of a drag begun in the sidebar (a slider let go over the picture) makes on what is under the pointer.
    $bars = $fade <= 0.001 || $c->pressedOutside($osc) && $c->pressedOutside($title);
    if ($slide > 0 && ($side = sidebar($c, $v, $p, $slide)) && $v->sidebar !== null && $c->pressedOutside($side) && $bars) {
        $v->sidebar = null;
    }
    // A click goes to the first node built that asks for it where it is: the picture asks after what is over it.
    if ($c->rightClicked($picture)) { $v->togglePause(); }
    if ($c->doubleClicked($picture)) { $v->window?->toggleFullScreen(); }
    $c->end();
}

/** IINA's default keys (iina-default-input.conf) that the player has the means for. */
function keys(Context $c, Videos $v, Player $p): void
{
    [$shift, $alt, $cmd, $ctrl] = [Key::SHIFT, Key::OPTION, Key::COMMAND, Key::CONTROL];
    if ($c->shortcut(Key::SPACE) || $c->shortcut('p')) { $v->togglePause(); }
    if ($c->shortcut(Key::RIGHT)) { $v->seekBy(5); }
    if ($c->shortcut(Key::LEFT)) { $v->seekBy(-5); }
    if ($c->shortcut(Key::PAGE_UP, $shift)) { $v->seekBy(600); }
    if ($c->shortcut(Key::PAGE_DOWN, $shift)) { $v->seekBy(-600); }
    if ($c->shortcut(Key::UP)) { $v->setVolume($v->volume + 0.05); }
    if ($c->shortcut(Key::DOWN)) { $v->setVolume($v->volume - 0.05); }
    if ($c->shortcut(Key::UP, $alt)) { $v->setVolume($v->volume + 0.01); }
    if ($c->shortcut(Key::DOWN, $alt)) { $v->setVolume($v->volume - 0.01); }
    if ($c->shortcut('/', $cmd) || $c->shortcut('m')) { $v->toggleMute(); }
    if ($c->shortcut(Key::BRACKET_LEFT, $cmd)) { $v->setSpeed($p->rate() * 0.5); }
    if ($c->shortcut(Key::BRACKET_RIGHT, $cmd)) { $v->setSpeed($p->rate() * 2); }
    if ($c->shortcut(Key::BRACKET_LEFT, $alt | $cmd)) { $v->setSpeed($p->rate() / 1.1); }
    if ($c->shortcut(Key::BRACKET_RIGHT, $alt | $cmd)) { $v->setSpeed($p->rate() * 1.1); }
    if ($c->shortcut(Key::BACKSLASH, $cmd)) { $v->setSpeed(1); }
    if ($c->shortcut('z', $shift)) { $v->setSubDelay($v->subDelay - 0.5); }
    if ($c->shortcut('x', $shift)) { $v->setSubDelay($v->subDelay + 0.5); }
    if ($c->shortcut('z', $alt)) { $v->setSubDelay($v->subDelay - 0.1); }
    if ($c->shortcut('x', $alt)) { $v->setSubDelay($v->subDelay + 0.1); }
    if ($c->shortcut('c', $shift)) { $v->setSubDelay(0); }
    if ($c->shortcut('v')) { $v->subVisible = !$v->subVisible; $v->say($v->subVisible ? '字幕可见' : '字幕已隐藏'); }
    if ($c->shortcut('o') || $c->shortcut('p', $shift)) { $v->say(clock($p->time()) . ' / ' . clock($p->duration())); }
    if ($c->shortcut('.', $cmd)) { $p->pause(); $p->seek(0); $v->say('停止'); }
    if ($c->shortcut('s', $ctrl | $cmd)) { $p->setSubtitleTrack(Videos::cycle($p->subtitleTracks(), $p->subtitleTrack())); }
    if ($c->shortcut('a', $ctrl | $cmd)) {
        $next = Videos::cycle(array_values(array_filter($p->audioTracks(), [Player::class, 'playable'])), $p->audioTrack());
        if ($next !== null) { $p->setAudioTrack($next); } elseif (($first = array_values(array_filter($p->audioTracks(), [Player::class, 'playable']))[0] ?? null) !== null) { $p->setAudioTrack($first); }
    }
    if ($c->shortcut('f') || $c->shortcut('f', $ctrl | $cmd)) { $v->window?->toggleFullScreen(); }
    if ($c->shortcut(Key::RETURN) && !$v->window?->isFullScreen()) { $v->window?->setFullScreen(true); }
    if ($c->shortcut(Key::ESCAPE)) {
        if ($v->sidebar !== null) { $v->sidebar = null; } elseif ($v->window?->isFullScreen()) { $v->window->setFullScreen(false); }
    }
}

/** The control bar's inside, 460 by 80: the volume, back, play, on and the bar's buttons above, the times and the bar of the time below. */
function controlBar(Context $c, Videos $v, Player $p): void
{
    $vol = $p->muted() || $v->volume == 0 ? 'speaker.slash.fill' : ($v->volume < 0.34 ? 'speaker.wave.1.fill' : ($v->volume < 0.67 ? 'speaker.wave.2.fill' : 'speaker.wave.3.fill'));
    if ($c->clicked(barButton($c, $vol, 13, 27, 24))) { $v->toggleMute(); }
    $level = $p->muted() ? 0.0 : $v->volume;
    $s = $c->sliderBase($level, 0, 1)->absolute()->left(45)->top(16)->size(69, 16)->paddingX(7.5);
    $s->draw(static function (DisplayList $l, Node $n): void { // a thin track, filled to the knob, and a white pill
        $x0 = $n->x + 7.5; $w = $n->w - 15; $cy = $n->y + $n->h / 2; $k = $x0 + $w * $n->fraction;
        $l->rect($n->x, $cy - 2, $n->w, 4, 0xffffff, 0.25, 2);
        $l->rect($n->x, $cy - 2, $k - $n->x, 4, 0xffffff, 0.8, 2);
        $l->rect($k - 7.5, $cy - 5, 15, 10, 0xffffff, 0.92, 5);
    });
    if ($c->changed($s)) { $v->setVolume($level); }
    if ($c->clicked(barButton($c, 'backward.fill', 17, 183, 24))) { $v->seekBy(-10); }
    if ($c->clicked(barButton($c, $p->playing() ? 'pause.fill' : 'play.fill', 22, 229, 24))) { $v->togglePause(); }
    if ($c->clicked(barButton($c, 'forward.fill', 17, 277, 24))) { $v->seekBy(10); }
    barButton($c, 'puzzlepiece.extension', 14, 364, 24, '插件');
    barButton($c, 'pip', 14, 388, 24, '画中画');
    barButton($c, 'list.bullet.rectangle', 14, 412, 24, '播放列表');
    if ($c->clicked(barButton($c, 'gearshape', 15, 436, 24, '设置'))) { $v->sidebar = $v->sidebar === null ? $v->tab : null; }
    $c->text(clock($p->time()), 11.5, 0xffffff)->fontFeatures('tnum')->opacity(0.6)->absolute()->left(17.5)->top(50.5); // the system font, its digits of one width
    $seek = $c->sliderBase($v->at, 0, max($p->duration(), 0.001))->absolute()->left(58)->top(49)->size(344, 18)->paddingX(1.5);
    if (!$c->pressed($seek)) { $v->at = $p->time(); }
    $seek->draw(static function (DisplayList $l, Node $n): void { // a 4-point track, lighter where it played, and a white upright knob
        $x0 = $n->x + 1.5; $w = $n->w - 3; $cy = $n->y + $n->h / 2; $k = $x0 + $w * $n->fraction;
        $l->rect($n->x, $cy - 2, $n->w, 4, 0xffffff, 0.2, 2);
        $l->rect($n->x, $cy - 2, $k - $n->x, 4, 0xffffff, 0.45, 2);
        $l->rect($k - 1.5, $cy - 7.5, 3, 15, 0xffffff, 0.92, 1.5);
    });
    if ($c->changed($seek)) { $p->seek($v->at); }
    $c->text(clock($p->duration()), 11.5, 0xffffff)->fontFeatures('tnum')->opacity(0.6)->absolute()->right(12.5)->top(50.5);
}

/** The message at the top left: its text, with the time or the volume's level under it, fading out. */
function osd(Context $c, Videos $v, Player $p, float $now): void
{
    [$text, $under, $until] = $v->osd;
    $box = $c->column(0, 1.5)->absolute()->left(8)->top(48)->padding(10, 16, 7, 16)->systemMaterial('glass')->round(12)->passThrough();
    $o = $c->animate($box, 'osd', $now < $until ? 1 : 0, 0.3);
    if ($now < $until) { $c->after($until - $now + 0.01); }
    $box->opacity($o);
    if ($o <= 0.001 || $text === '') { $box->invisible(); }
      $c->text($text, 20, 0xffffff);
      if ($under === 'time') {
          $c->text(clock($p->time()) . ' / ' . clock($p->duration()), 11, 0xffffff)->fontFeatures('tnum')->opacity(0.7);
      } elseif ($under === 'volume') {
          $level = $p->muted() ? 0.0 : $v->volume;
          $c->row()->height(5)->fillWidth()->minWidth(90)->draw(static function (DisplayList $l, Node $n, $t) use ($level): void {
              $l->rect($n->x, $n->y, $n->w, $n->h, 0xffffff, 0.25, 2.5);
              $l->rect($n->x, $n->y, $n->w * $level, $n->h, $t->accent, 1.0, 2.5); // the system's accent
          });
          $c->end();
      }
    $c->end();
}

/** $text over the picture, centered $bottom points from its foot: white, outlined in black as mpv draws subtitles (copies of it around, under it). */
function subtitle(Context $c, string $text, float $size, float $bottom, float $right = 0): void
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
function sidebar(Context $c, Videos $v, Player $p, float $slide): Node
{
    $side = $c->column()->absolute()->top(0)->right(0)->bottom(0)->width(Videos::SIDEBAR)->systemMaterial('glass')->translate(Videos::SIDEBAR * (1 - $slide), 0)->keepFocus();
      $back = $c->column()->absolute()->left(9)->top(7)->size(38, 38)->center()->systemMaterial('glass')->round(19)->tooltip('隐藏');
        sf($c, 'chevron.right', 14, $c->hovered($back) ? 0.9 : 0.6, 0.2);
      $c->end();
      if ($c->clicked($back)) { $v->sidebar = null; }
      tabBar($c, $v);
      $scroll = $c->scroll(0, 20)->absolute()->top(56)->left(0)->right(0)->bottom(0)->padding(0, 16, 16, 16);
        match ($v->tab) {
            'video' => videoTab($c, $v, $p),
            'audio' => audioTab($c, $v, $p),
            'sub' => subTab($c, $v, $p),
            default => layoutTab($c),
        };
      $c->end();
    $c->end();
    $c->clicked($side); // a click on the sidebar is its own, not the picture's
    return $side;
}

/** The tabs in a glass pill at the top right: the chosen one with its icon and name, the others their icon, divided. */
function tabBar(Context $c, Videos $v): void
{
    $tabs = [['layout', 'paintbrush.pointed.fill', '格式'], ['video', 'photo', '视频'], ['audio', 'waveform', '音频'], ['sub', 'captions.bubble.fill', '字幕']];
    $bar = $c->row(3, 0)->absolute()->right(7)->top(9)->height(36)->align(Node::CENTER)->systemMaterial('glass')->round(18);
      foreach ($tabs as $i => [$id, $icon, $label]) {
          $on = $v->tab === $id;
          if ($i > 0 && !$on && $tabs[$i - 1][0] !== $v->tab) { $c->box(1, 18, 0xffffff)->opacity(0.25); } // between two not chosen
          $t = $c->row(0, 5)->height(30)->paddingX($on ? 10 : 7)->align(Node::CENTER)->round(15);
          if ($on) { $t->background(0xffffff, 0.16); }
            sf($c, $icon, 14, $on || $c->hovered($t) ? 0.95 : 0.7);
            if ($on) { $c->text($label, 13, 0xffffff)->fontWeight(500)->opacity(0.95); }
          $c->end();
          if ($c->clicked($t)) { $v->tab = $id; $v->sidebar = $id; }
      }
    $c->end();
}

/** A card: a column over the glass, filled and ringed faintly. */
function card(Context $c, float $gap = 10): Node
{
    return frosted($c->column(12, $gap), 0.05, 0.1, 10)->fillWidth();
}

/** A heading in a card: symbol $icon and $title, and what $trailing builds at the right. */
function heading(Context $c, string $icon, string $title, ?Closure $trailing = null): void
{
    $c->row(0, 8)->align(Node::CENTER)->fillWidth();
      $c->column()->size(16, 16)->center(); sf($c, $icon, 12, 0.7); $c->end();
      $c->text($title, 13, 0xffffff)->fontWeight(600)->opacity(0.9)->grow(1);
      if ($trailing !== null) { $trailing(); }
    $c->end();
}

/**
 * A list of tracks as IINA's: a box of rows, four at the least, the chosen
 * one marked with a blue bar, each its label and its language at the
 * right; returns the row clicked, or null.
 * @param list<array{0: string, 1: string}> $rows label and language tag
 */
function trackList(Context $c, array $rows, ?int $chosen): ?int
{
    $clicked = null;
    $list = frosted($c->column(), 0.05, 0.14, 8)->fillWidth()->dividers(1, 0xffffff, 0.1);
      foreach (array_pad($rows, 4, null) as $i => $r) {
          $row = $c->row(0, 6)->height(24.5)->paddingStart(20)->paddingEnd(8)->align(Node::CENTER)->fillWidth();
          if ($r !== null) {
              if ($i === $chosen) { $c->box(3, 16, $c->theme->accent)->round(1.5)->absolute()->left(8)->top(4.25); }
              $c->text($r[0], 13, 0xffffff)->opacity($i === 0 ? 0.5 : 0.9)->noWrap()->ellipsis('…')->grow(1);
              if ($r[1] !== '') { frosted($c->row()->padding(1, 3), 0.2, 0, 3); $c->text($r[1], 11, 0xffffff)->opacity(0.85); $c->end(); }
              if ($c->clicked($row)) { $clicked = $i; }
          }
          $c->end();
      }
    $c->end();
    return $clicked;
}

/** A segmented control as IINA's sidebar draws it: its options in a pill, the chosen one lighter; those it cannot take are held. Returns the option clicked, or null. @param list<string> $options */
function segments(Context $c, array $options, int $chosen, ?array $takes = null, bool $more = false): ?int
{
    $clicked = null;
    $c->row(0, 4)->align(Node::CENTER); // as wide as its options, not the card
    frosted($c->row(0, 0), 0.1, 0, 6)->height(23)->align(Node::STRETCH);
      foreach ($options as $i => $label) {
          if ($i > 0 && $i !== $chosen && $i - 1 !== $chosen) { $c->column()->width(1)->paddingY(5); $c->box(1, 13, 0xffffff)->opacity(0.25); $c->end(); }
          $s = $c->column()->paddingX(11)->center()->round(6);
          if ($i === $chosen) { $s->background($c->theme->accent); } // the choice in the system's accent, as IINA's
            $c->text($label, 13, 0xffffff)->opacity(0.9);
          $c->end();
          if ($takes !== null && !in_array($i, $takes, true)) { $s->tooltip('尚未支持'); continue; }
          if ($c->clicked($s)) { $clicked = $i; }
      }
    $c->end();
    if ($more) { $c->column()->tooltip('尚未支持'); sf($c, 'chevron.right', 10, 0.5); $c->end(); } // IINA's: more ratios
    $c->end();
    return $clicked;
}

/** A switch as macOS 26 draws one, 38 by 18; returns whether it was clicked. */
function toggleSwitch(Context $c, bool $on, bool $enabled = true): bool
{
    $s = $c->row()->size(38, 18)->draw(static function (DisplayList $l, Node $n, $t) use ($on): void {
        $l->rect($n->x, $n->y, $n->w, $n->h, $on ? $t->accent : 0xffffff, $on ? 1.0 : 0.2, 9);
        $l->rect($on ? $n->x + $n->w - 24 : $n->x + 2, $n->y + 2, 22, 14, 0xffffff, 1.0, 7);
    });
    $c->end();
    if (!$enabled) { $s->tooltip('尚未支持'); return false; }
    return $c->clicked($s);
}

/**
 * A slider as IINA's sidebar draws one, with the value over its knob, its
 * marks under it and a field of the value at the right; $value runs 0 to
 * 1 along it. Returns whether it was dragged.
 * @param array<string, float> $marks label => where (0 to 1)
 */
function sideSlider(Context $c, float &$value, string $label, array $marks, string $field, int $dots): bool
{
    $changed = false;
    $c->row(0, 8)->align(Node::END)->fillWidth();
      $c->column()->grow(1)->height(48);
        $s = $c->sliderBase($value, 0, 1)->absolute()->left(0)->right(0)->top(14)->height(20)->paddingX(5);
        $f = $s->fraction;
        $s->draw(static function (DisplayList $l, Node $n) use ($dots): void { // a track, dots under it, an upright white pill
            $x0 = $n->x + 5; $w = $n->w - 10; $cy = $n->y + $n->h / 2;
            $l->rect($n->x, $cy - 2, $n->w, 4, 0xffffff, 0.15, 2);
            for ($i = 0; $i < $dots; $i++) { $l->rect($x0 + $w * $i / ($dots - 1) - 0.75, $cy + 6, 1.5, 1.5, 0xffffff, 0.3, 0.75); }
            $l->rect($x0 + $w * $n->fraction - 4.5, $cy - 8.5, 9, 17, 0xffffff, 1.0, 4.5);
        });
        $changed = $c->changed($s);
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
      frosted($c->row()->size(52, 26)->paddingX(8)->align(Node::CENTER), 0.1, 0, 6);
        $c->text($field, 13, 0xffffff)->opacity(0.9);
      $c->end();
    $c->end();
    return $changed;
}

/** A button over the glass: $label, filled faintly; held when $enabled is false. */
function sideButton(Context $c, string $label, bool $enabled = true, float $width = 0): bool
{
    $b = frosted($c->row()->height(24)->paddingX(10)->center(), $enabled ? 0.14 : 0.08, 0, 6);
    if ($width > 0) { $b->width($width); }
      $c->text($label, 13, 0xffffff)->opacity($enabled ? 0.9 : 0.5);
    $c->end();
    if (!$enabled) { $b->tooltip('尚未支持'); return false; }
    return $c->clicked($b);
}

/** The short name of a codec, as mpv names it. */
function codecName(string $codec): string
{
    return match (true) {
        $codec === 'V_MPEG4/ISO/AVC' => 'h264', $codec === 'V_MPEGH/ISO/HEVC' => 'hevc', str_starts_with($codec, 'V_VP9') => 'vp9', str_starts_with($codec, 'V_AV1') => 'av1',
        str_starts_with($codec, 'A_AAC') => 'aac', $codec === 'A_AC3' => 'ac3', $codec === 'A_EAC3' => 'eac3', str_starts_with($codec, 'A_DTS') => 'dts', $codec === 'A_OPUS' => 'opus',
        $codec === 'A_FLAC' => 'flac', $codec === 'A_MPEG/L3' => 'mp3', $codec === 'A_TRUEHD' => 'truehd', $codec === 'A_VORBIS' => 'vorbis',
        $codec === 'S_TEXT/UTF8' => 'subrip', in_array($codec, ['S_TEXT/ASS', 'S_ASS'], true) => 'ass', in_array($codec, ['S_TEXT/SSA', 'S_SSA'], true) => 'ssa', $codec === 'S_TEXT/WEBVTT' => 'webvtt', $codec === 'S_TEXT/TX3G' => 'mov_text',
        default => strtolower(preg_replace('/^[VAS]_/', '', $codec) ?? $codec),
    };
}

/** The name of language $code as IINA shows it, '' for none. */
function language(string $code): string
{
    return match (strtolower($code)) {
        '', 'und' => '', 'chi', 'zho', 'zh' => '中文', 'eng', 'en' => '英语', 'jpn', 'ja' => '日语', 'kor', 'ko' => '韩语', 'fre', 'fra', 'fr' => '法语',
        'ger', 'deu', 'de' => '德语', 'spa', 'es' => '西班牙语', 'rus', 'ru' => '俄语', 'ita', 'it' => '意大利语', 'por', 'pt' => '葡萄牙语',
        default => $code,
    };
}

/** A track's row: its name and what it is in brackets, "default" if it is, and its language. @return array{0: string, 1: string} */
function trackRow(Track $t, string $details): array
{
    $name = $t->name !== '' ? $t->name : ($t->language !== '' && $t->language !== 'und' ? language($t->language) : "#{$t->number}");
    $what = implode(', ', array_filter([codecName($t->codec), $details, $t->isDefault ? '默认' : '']));
    return ["$name ($what)", language($t->language)];
}

function videoTab(Context $c, Videos $v, Player $p): void
{
    $t = $p->videoTrack();
    $rows = [['<无>', '']];
    if ($t !== null) {
        $rows[] = trackRow($t, implode(', ', array_filter(["{$t->width}×{$t->height}", $t->frameDuration > 0 ? number(1 / $t->frameDuration) . 'fps' : ''])));
    }
    trackList($c, $rows, $t === null ? 0 : 1); // one picture: what it shows
    card($c, 9);
      heading($c, 'aspectratio', '长宽比');
      segments($c, ['默认', '4:3', '16:9', '16:10', '21:9', '5:4'], 0, [0], true);
      heading($c, 'crop', '裁剪');
      segments($c, ['无', '4:3', '16:9', '16:10', '21:9', '5:4'], 0, [0], true);
      heading($c, 'rotate.right', '旋转');
      segments($c, ['0°', '90°', '180°', '270°'], 0, [0]);
    $c->end();
    card($c, 2);
      heading($c, 'chevron.forward.2', '速度');
      $rate = $p->rate();
      if (sideSlider($c, $v->speedAt, number($rate) . 'x', ['0.25x' => 0, '1x' => 1 / 3, '4x' => 2 / 3, '16x' => 1], number($rate), 25)) {
          $at = round($v->speedAt * 24) / 24; // to the dots: the speeds of IINA's slider
          $v->setSpeed(0.25 * 64 ** $at);
      }
    $c->end();
    card($c);
      heading($c, 'cpu', '硬件解码', fn() => toggleSwitch($c, true, false)); // the system decodes in hardware
    $c->end();
}

function audioTab(Context $c, Videos $v, Player $p): void
{
    $tracks = $p->audioTracks();
    $rows = [['<无>', ''], ...array_map(fn(Track $t) => trackRow($t, implode(', ', array_filter([$t->channels > 0 ? "{$t->channels}ch" : '', $t->sampleRate > 0 ? number($t->sampleRate / 1000) . 'kHz' : '', Player::playable($t) ? '' : '无法播放']))), $tracks)];
    $at = array_search($p->audioTrack(), $tracks, true);
    $i = trackList($c, $rows, $at === false ? 0 : $at + 1);
    if ($i !== null && $i > 0 && Player::playable($tracks[$i - 1])) { $p->setAudioTrack($tracks[$i - 1]); }
    card($c);
      sideButton($c, '加载外置音频…', false);
    $c->end();
    card($c, 2);
      heading($c, 'clock.arrow.circlepath', '延迟');
      $zero = 0.5;
      sideSlider($c, $zero, '0s', ['-5s' => 0, '0s' => 0.5, '+5s' => 1], '0', 21);
    $c->end();
    card($c);
      heading($c, 'slider.vertical.3', '均衡器');
      $c->row()->tooltip('尚未支持');
        frosted($c->row(0, 6)->height(24)->width(189)->paddingX(12)->align(Node::CENTER), 0.12, 0, 6);
          $c->text('手动', 13, 0xffffff)->opacity(0.9)->grow(1);
          sf($c, 'chevron.up.chevron.down', 10, 0.8);
        $c->end();
      $c->end();
      equalizer($c);
    $c->end();
}

/** IINA's ten bands, each a vertical slider at 0 dB, their frequencies under them and the scale at the right: held, the player having no equalizer yet. */
function equalizer(Context $c): void
{
    $c->row(0, 0)->fillWidth()->height(150)->tooltip('尚未支持');
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

function subTab(Context $c, Videos $v, Player $p): void
{
    $tracks = $p->subtitleTracks();
    $rows = [['<无>', ''], ...array_map(fn(Track $t) => trackRow($t, ''), $tracks)];
    $at = array_search($p->subtitleTrack(), $tracks, true);
    heading($c, '1.square.fill', '主字幕', function () use ($c, $v) {
        if (toggleSwitch($c, $v->subVisible)) { $v->subVisible = !$v->subVisible; $v->say($v->subVisible ? '字幕可见' : '字幕已隐藏'); }
    });
    $i = trackList($c, $rows, $at === false ? 0 : $at + 1);
    if ($i !== null) { $p->setSubtitleTrack($i === 0 ? null : $tracks[$i - 1]); }
    heading($c, '2.square.fill', '二级字幕', fn() => toggleSwitch($c, true, false));
    $c->column()->opacity(0.5)->fillWidth()->tooltip('尚未支持'); trackList($c, [['<无>', '']], 0); $c->end();
    card($c);
      $c->row(0, 8)->align(Node::CENTER);
        if (sideButton($c, '加载字幕…')) {
            $paths = Dialog::open(new OpenDialogOptions(parent: $v->window, title: '加载字幕'));
            if ($paths) { $v->loadSubtitles($paths[0]); }
        }
        sideButton($c, '在线查找…', false);
      $c->end();
    $c->end();
    card($c, 6);
      $c->row()->fillWidth(); $c->column()->grow(1); segments($c, ['主字幕', '二级字幕'], 0, [0]); $c->end(); $c->end();
      heading($c, 'clock.arrow.circlepath', '延迟');
      $at = ($v->subDelay + 5) / 10;
      if (sideSlider($c, $at, number($v->subDelay) . 's', ['-5s' => 0, '0s' => 0.5, '+5s' => 1], number($v->subDelay), 21)) { $v->setSubDelay($at * 10 - 5); }
    $c->end();
}

/** The layout tab, which the player has no settings for yet. */
function layoutTab(Context $c): void
{
    card($c);
      $c->text('显示布局尚未支持', 13, 0xffffff)->opacity(0.6);
    $c->end();
}

$s = new Videos;
$openDialog = function () use ($s) {
    $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: '打开'));
    if ($paths) { $s->open($paths[0]); }
};
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('文件', submenu: [new MenuItem('打开…', $openDialog, 'CmdOrCtrl+O'), MenuItem::separator(), new MenuItem(role: 'close')]),
    new MenuItem(role: 'windowMenu'),
]));
App::setName('Video');
App::whenReady(function () use ($s, $argv) {
    Theme::setSource(ThemeSource::Dark); // as IINA's window: dark whatever the system's
    $s->window = new Window(new WindowOptions(title: 'Video', width: 960, height: 540, stateKey: 'video', backgroundColor: '#000000', titleBarStyle: 'hidden',
        content: Content::view(fn(Context $c) => view($c, $s))));
    $s->window->on('fileDrop', fn(FileDropEvent $e) => $s->drop($e->paths[0]));
    if (isset($argv[1])) {
        $s->open($argv[1]);
    }
});
App::run();
