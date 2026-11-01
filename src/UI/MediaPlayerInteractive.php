<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Media\Player;

/**
 * IINA's interactive mode (InteractiveModeController and CropBoxView): a
 * rectangle chosen over the paused picture, for Crop > Custom… and Video >
 * Delogo. The window in its background color, the controls and the title
 * bar hidden, the picture 20 from its top and as large as leaves 20 at its
 * sides and 88 under it, shadowed; over it the box chosen, in the accent
 * with a gray fill, its edges dragged to resize it (4 points of them, the
 * resizing pointers), a drag elsewhere on the picture choosing anew, an
 * edge within 4 pixels of the picture's taking it; at the bottom the
 * selection in the video's pixels, "(x, y) (w×h)", over IINA's ratios
 * (the largest box of the one chosen, centered), Cancel and Done. The
 * window keeps its size meanwhile; it leaves the mode as it goes full
 * screen or comes out of it, as IINA's.
 *
 * @internal MediaPlayer
 */
final class MediaPlayerInteractive
{
    /** IINA's ratios of its segmented control. */
    public const RATIOS = ['4:3', '16:9', '16:10', '21:9', '3:2', '5:4'];
    /** How far within an edge, or past it, a press drags it (CropBoxView's cursor rects, 4 points across); how near the picture's edge, in its pixels, an edge goes to it. */
    private const EDGE = 2.0, SNAP = 4.0;

    /** The mode in $box, the player's column: false when the window went full screen or came out of it, and so the mode was left. */
    public static function build(Context $c, MediaPlayerState $s, Player $p, Node $box): bool
    {
        $i = $s->interactive;
        if ($i === null) { return false; }
        if ($s->fullScreen() !== $i['fullScreen']) { // IINA's windowWillEnterFullScreen and windowWillExitFullScreen
            $s->exitInteractive();
            return false;
        }
        $t = $c->theme;
        $box->background(MediaPlayerSettings::colors($t)['page']); // NSColor.windowBackgroundColor
        $c->runtime?->askWindow('buttons', false); // hidden with the title bar (hideUI)
        $c->runtime?->askWindow('fixedSize', !$s->fullScreen()); // IINA's windowWillResize: the size it has
        [$vw, $vh] = $p->size();
        [$x0, $y0, $fw, $fh] = self::frame($c->width, $c->height, MediaPlayer::shownAspect($p) ?? $vw / $vh);
        $c->column()->absolute()->left($x0)->top($y0)->size($fw, $fh)->background(0x000000)->shadow(0, 0, 10, 0, 0x000000, 0.5); $c->end(); // its NSShadow, under the picture
        $c->movie($p)->absolute()->left($x0)->top($y0)->size($fw, $fh);

        $rect = $i['rect'];
        [$bx, $by, $bw, $bh] = self::box($rect, $vw, $vh, $fw, $fh);
        $accent = $t->accent;
        $over = $c->column()->absolute()->left($x0)->top($y0)->size($fw, $fh)->draw(static function (Painter $pt, array $r) use ($bx, $by, $bw, $bh, $accent): void {
            $b = [$r[0] + $bx, $r[1] + $by, $bw, $bh];
            $pt->fill($b, 0x808080, 0, 0.3); // NSColor.cropBoxFill
            $pt->stroke([$b[0] - 1, $b[1] - 1, $b[2] + 2, $b[3] + 2], $accent, 0, 2); // 2 wide on its edge
        });
        $c->end();
        [$px, $py] = $c->pointerPosition($box);
        $side = $i['drag'] ?? self::edgeAt($px - $x0, $py - $y0, $bx, $by, $bw, $bh);
        if ($side !== null && $side !== 'free') { $over->cursor($side === 'top' || $side === 'bottom' ? Cursor::ResizeNS : Cursor::ResizeEW); } // the resizing pointers over the edges, but while choosing anew
        $over->handleInput(static function (InputEvent $e) use ($s, $vw, $vh, $fw, $fh): bool {
            return self::input($s, $e, $vw, $vh, $fw, $fh);
        });

        // The bar: the selection over the ratios, Cancel and Done, 20 above the foot, centered.
        $c->column(0, 8)->absolute()->bottom(20)->left(0)->right(0)->alignItems(Node::CENTER)->passThrough();
          $c->column(0, 8)->alignItems(Node::START);
            [$x, $y, $w, $h] = self::pixels($rect);
            $c->text("($x, $y) ($w\u{d7}$h)", 11, $t->textMuted)->fontFeatures('tnum')->singleLine();
            $c->row(0, 8)->alignItems(Node::CENTER);
              $chosen = self::ratios($c, $i['aspect']);
              $c->column()->width(8); $c->end();
              $cancel = MediaPlayerSettings::pushButton($c, $c->texts()->get('cancel'), false);
              $done = MediaPlayerSettings::pushButton($c, $c->texts()->get('done'), false);
            $c->end();
          $c->end();
        $c->end();
        if ($chosen !== null) { // IINA's predefinedAspectValueAction: the largest box of the ratio, centered
            $a = MediaPlayerState::ratio(self::RATIOS[$chosen]);
            [$cw, $ch] = $vw / $vh > $a ? [$vh * $a, (float)$vh] : [(float)$vw, $vw / $a];
            $s->interactive = ['aspect' => $chosen, 'rect' => [($vw - $cw) / 2, ($vh - $ch) / 2, $cw, $ch]] + $i;
            $c->runtime?->changed();
        }
        if ($cancel) { $s->exitInteractive(); }
        if ($done) { $s->interactiveDone(...self::pixels($s->interactive['rect'] ?? $rect)); }
        return true;
    }

    /** Where the picture shows in a window $ww by $wh, of shape $aspect: 20 from the top, at most 40 narrower and 108 shorter, centered across. @return array{0: float, 1: float, 2: float, 3: float} */
    public static function frame(float $ww, float $wh, float $aspect): array
    {
        [$mw, $mh] = [max(0.0, $ww - 40), max(0.0, $wh - 108)];
        [$fw, $fh] = $mh > 0 && $aspect < $mw / $mh ? [$mh * $aspect, $mh] : [$mw, $mw / $aspect]; // NSSize.shrink(toSize:)
        return [($ww - $fw) / 2, 20.0, $fw, $fh];
    }

    /** The selection $r (the video's pixels, $vw by $vh) as the box over the picture shown $fw by $fh, in its points. @param array{0: float, 1: float, 2: float, 3: float} $r @return array{0: float, 1: float, 2: float, 3: float} */
    public static function box(array $r, int $vw, int $vh, float $fw, float $fh): array
    {
        [$sx, $sy] = [$fw / $vw, $fh / $vh];
        return [$r[0] * $sx, $r[1] * $sy, $r[2] * $sx, $r[3] * $sy];
    }

    /** The selection in whole pixels, as IINA tells it and filters it: [x, y, w, h]. @param array{0: float, 1: float, 2: float, 3: float} $r @return array{0: int, 1: int, 2: int, 3: int} */
    public static function pixels(array $r): array
    {
        return [(int)$r[0], (int)$r[1], (int)$r[2], (int)$r[3]];
    }

    /** The edges a press drags of the box at ($x, $y), $w by $h, IINA's cursor rects: the top and the bottom across it, the sides between them. @return array<string, array{0: float, 1: float, 2: float, 3: float}> */
    private static function edges(float $x, float $y, float $w, float $h): array
    {
        $e = self::EDGE;
        return ['bottom' => [$x, $y + $h - $e, $w, 2 * $e], 'top' => [$x, $y - $e, $w, 2 * $e], 'left' => [$x - $e, $y + $e, 2 * $e, $h - 2 * $e], 'right' => [$x + $w - $e, $y + $e, 2 * $e, $h - 2 * $e]];
    }

    /** The edge of the box at ($x, $y), $w by $h, that ($px, $py) is on, else null. */
    private static function edgeAt(float $px, float $py, float $x, float $y, float $w, float $h): ?string
    {
        foreach (self::edges($x, $y, $w, $h) as $side => [$ex, $ey, $ew, $eh]) {
            if ($px >= min($ex, $ex + $ew) && $px < max($ex, $ex + $ew) && $py >= min($ey, $ey + $eh) && $py < max($ey, $ey + $eh)) { return $side; }
        }
        return null;
    }

    /**
     * A press on the picture, $fw by $fh points of the video's $vw by $vh
     * pixels, its drag and its release (CropBoxView's mouseDown,
     * mouseDragged, mouseUp): on an edge it moves that edge, elsewhere it
     * chooses the box from where it went down to where it is, the pointer
     * kept on the picture. Whether it was taken.
     */
    public static function input(MediaPlayerState $s, InputEvent $e, int $vw, int $vh, float $fw, float $fh): bool
    {
        $i = $s->interactive;
        if ($i === null || $e->button !== 0) { return false; }
        if ($e->kind === InputEvent::POINTER_DOWN) {
            $drag = self::edgeAt($e->x, $e->y, ...self::box($i['rect'], $vw, $vh, $fw, $fh)) ?? 'free';
            $s->interactive = ['drag' => $drag, 'from' => [$e->x, $e->y], 'last' => [$e->x, $e->y]] + $i;
            return true;
        }
        if ($e->kind === InputEvent::POINTER_UP) {
            $s->interactive = ['drag' => null] + $i;
            return true;
        }
        if ($e->kind !== InputEvent::POINTER_MOVE || $i['drag'] === null) { return false; }
        [$mx, $my] = [min(max($e->x, 0.0), $fw), min(max($e->y, 0.0), $fh)]; // constrained to the picture
        [$bx, $by, $bw, $bh] = self::box($i['rect'], $vw, $vh, $fw, $fh);
        [$lx, $ly] = $i['last'];
        $drag = $i['drag'];
        switch ($drag) {
            case 'free': // NSRect(vertexPoint:and:), from where it went down
                [$fx, $fy] = $i['from'];
                [$bx, $by, $bw, $bh] = [min($fx, $mx), min($fy, $my), abs($mx - $fx), abs($my - $fy)];
                break;
            case 'top': $by += $my - $ly; $bh -= $my - $ly; break;
            case 'bottom': $bh += $my - $ly; break;
            case 'left': $bx += $mx - $lx; $bw -= $mx - $lx; break;
            case 'right': $bw += $mx - $lx; break;
        }
        if ($bw < 0) { [$bx, $bw] = [$bx + $bw, -$bw]; $drag = ['left' => 'right', 'right' => 'left'][$drag] ?? $drag; } // past the other edge: that side's now
        if ($bh < 0) { [$by, $bh] = [$by + $bh, -$bh]; $drag = ['top' => 'bottom', 'bottom' => 'top'][$drag] ?? $drag; }
        $s->interactive = ['rect' => self::selected($bx, $by, $bw, $bh, $vw, $vh, $fw, $fh), 'drag' => $drag, 'last' => [$mx, $my]] + $i;
        return true;
    }

    /** The box at ($x, $y), $w by $h points of the picture, in the video's pixels, an edge within SNAP pixels of the picture's on it (updateSelectedRect). @return array{0: float, 1: float, 2: float, 3: float} */
    private static function selected(float $x, float $y, float $w, float $h, int $vw, int $vh, float $fw, float $fh): array
    {
        [$sx, $sy] = [$vw / $fw, $vh / $fh];
        [$ix, $iy, $iw, $ih] = [$x * $sx, $y * $sy, $w * $sx, $h * $sy];
        if (abs($ix) <= self::SNAP) { $ix = 0.0; }
        if (abs($iy) <= self::SNAP) { $iy = 0.0; }
        if (abs($iw + $ix - $vw) <= self::SNAP) { $iw = $vw - $ix; }
        if (abs($ih + $iy - $vh) <= self::SNAP) { $ih = $vh - $iy; }
        return [$ix, $iy, $iw, $ih];
    }

    /**
     * IINA's ratios as macOS 26 draws an NSSegmentedControl of them, the
     * one chosen ($chosen, -1 for none yet) raised: the one clicked, else
     * null.
     */
    private static function ratios(Context $c, int $chosen): ?int
    {
        $t = $c->theme;
        $k = MediaPlayerSettings::colors($t);
        $clicked = null;
        $c->row(0, 0)->height(24)->padding(2)->radius(12)->background($k['field'], $k['fieldAlpha'])->alignItems(Node::STRETCH)->role(Role::RADIO_GROUP);
          foreach (self::RATIOS as $n => $label) {
              $seg = $c->column()->paddingX(10)->center()->radius(10)->role(Role::RADIO)->label($label)->checked($n === $chosen);
              if ($n === $chosen) {
                  $seg->background($k['control'], $t->dark ? 0.25 : 1.0);
                  if (!$t->dark) { $seg->shadow(0, 0.5, 1.5, 0, 0x000000, 0.25); }
              }
                $c->text($label, 13, $t->text);
              $c->end();
              if ($c->clicked($seg)) { $clicked = $n; }
          }
        $c->end();
        return $clicked;
    }
}
