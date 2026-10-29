<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Clipboard;
use MyPHP\Logger;
use MyPHP\Media\Player;
use MyPHP\Native\TextRecognizer;

/**
 * Live Text, as IINA's (LiveTextController over VisionKit's
 * ImageAnalysisOverlayView): with the settings' enableLiveText, the frame a
 * film paused at read for its text (Native\TextRecognizer, off the main
 * thread), forgotten as it plays or moves; its words selected by a drag
 * over them (the pointer a text cursor there), copied with Command-C or the
 * menu of a right click; the button at the bottom right showing all the
 * text found; the controls hidden while text is chosen, as IINA's. Not of
 * a picture cropped or turned (the frame read is the film's own).
 *
 * @internal VideoPlayer
 */
final class VideoPlayerLiveText
{
    /** How far from the picture's edges the button is (IINA's supplementaryInterfaceContentInsets), and its size. */
    private const INSET = 14, BUTTON = 30;

    /** Whether text is chosen, or all shown (IINA's isActive): the controls hidden then. */
    public static function active(VideoPlayerState $s): bool
    {
        $l = $s->liveText;
        return $l !== null && (($l['sel'] ?? null) !== null || ($l['all'] ?? false));
    }

    /** The analysis of the frame paused at, started, read when done, forgotten where it no longer is; its view over the picture at ($px, $py), $pw by $ph. */
    public static function build(Context $c, VideoPlayerState $s, Player $p, float $px, float $py, float $pw, float $ph): void
    {
        $on = $s->settings()->get('enableLiveText') && !$p->playing() && !$s->pictureInPicture && !$s->musicMode && $p->videoTrack() !== null
            && $p->crop() === null && $p->rotation() === 0;
        $at = $p->time();
        if (!$on || ($s->liveText !== null && abs($s->liveText['at'] - $at) > 1e-3)) { // played, moved, turned off: forgotten (IINA's clearAnalysis)
            if ($s->liveText !== null) { Logger::debug('Image analysis invalidated', 'livetext'); }
            $s->liveText = null;
            if (!$on) { return; }
        }
        if ($s->liveText === null) { // IINA's requestAnalysis: the frame captured, read
            $png = $p->snapshot();
            if ($png === null) { $c->after(0.2); return; } // no frame on screen yet
            Logger::debug('Image analysis requested', 'livetext');
            $s->liveText = ['at' => $at, 'job' => new TextRecognizer($png), 'words' => null, 'sel' => null, 'all' => false, 'from' => null];
        }
        $l = &$s->liveText;
        if ($l['words'] === null) {
            if (!$l['job']->done()) { $c->after(0.1); return; }
            $l['words'] = $l['job']->words();
            $l['job'] = null;
            Logger::debug('Image analysis results acquired: ' . count($l['words']) . ' words', 'livetext');
        }
        if ($l['words'] === []) { return; }
        $words = $l['words'];
        $box = fn(array $w): array => [$px + $w['x'] * $pw, $py + $w['y'] * $ph, $w['w'] * $pw, $w['h'] * $ph];
        $layer = $c->key('live text')->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->passThrough(); // the picture's input chooses the words (input()), its clicks its own
          foreach ($words as $i => $w) {
              [$x, $y, $ww, $wh] = $box($w);
              $chosen = $l['sel'] !== null && $i >= min($l['sel']) && $i <= max($l['sel']);
              $n = $c->key("word:$i")->column()->absolute()->left($x - 1)->top($y - 1)->size($ww + 2, $wh + 2)->round(3)->cursor(Cursor::Text);
              if ($chosen) { // the menu of a right click on what is chosen: Copy
                  $n->background($c->theme->accent, 0.35)->contextMenu(function (Menu $m) use ($c, $s, $words): void {
                      if ($m->item($c->texts()->get('iinaLogwindowCopy'))->chosen() && ($s->liveText['sel'] ?? null) !== null) { Clipboard::writeText(self::text($words, $s->liveText['sel'])); }
                  });
              } else {
                  $n->passThrough(); // the picture's clicks go on
                  if ($l['all']) { $n->background(0xffffff, 0.35)->borderWidth(1)->borderColor(0xffffff); }
              }
              $c->end();
          }
          // The button at the bottom right: all the text shown (VisionKit's).
          $bottom = $s->settings()->get('oscPosition') === 2 ? ($s->settings()->get('compactUI') ? 50 : 64) : self::INSET;
          $b = $c->column()->absolute()->right(max(self::INSET, $c->width - $px - $pw + self::INSET))->bottom(max($bottom, $c->height - $py - $ph + $bottom))->size(self::BUTTON, self::BUTTON)
              ->round(self::BUTTON / 2)->center()->systemMaterial('glass')->role(Role::BUTTON)->label('Live Text')->tooltip('Live Text');
            VideoPlayer::sf($c, 'text.viewfinder', 15, $l['all'] ? 1.0 : 0.8, 0.0, $l['all'] ? $c->theme->accent : null);
          $c->end();
          if ($c->clicked($b)) { $l['all'] = !$l['all']; }
        $c->end();
        $l['boxes'] = array_map($box, $words); // where the picture's input finds them (input())
        if ($l['sel'] !== null) {
            if ($c->shortcut('c', Key::COMMAND)) { Clipboard::writeText(self::text($words, $l['sel'])); }
            if ($c->shortcut(Key::ESCAPE)) { $l['sel'] = null; }
        }
    }

    /**
     * The picture's pointer at ($wx, $wy) of the window, before the
     * picture's own (VideoPlayer): a press on a word begins choosing, a drag
     * chooses on to the word under it (or nearest), a press elsewhere
     * chooses none and is the picture's. Whether it took it.
     */
    public static function input(VideoPlayerState $s, InputEvent $e, float $wx, float $wy): bool
    {
        if ($s->liveText === null || ($s->liveText['boxes'] ?? []) === []) { return false; }
        $l = &$s->liveText;
        $hit = null;
        foreach ($l['boxes'] as $i => [$x, $y, $w, $h]) {
            if ($wx >= $x - 2 && $wx <= $x + $w + 2 && $wy >= $y - 2 && $wy <= $y + $h + 2) { $hit = $i; break; }
        }
        if ($e->kind === InputEvent::POINTER_DOWN && $e->button === 0) {
            if ($hit === null) { $l['sel'] = null; return false; }
            $l['from'] = $hit; $l['sel'] = [$hit, $hit];
            return true;
        }
        if ($e->kind === InputEvent::POINTER_MOVE && $e->button === 0 && $l['from'] !== null) {
            $l['sel'] = [$l['from'], $hit ?? self::nearest($l['boxes'], $wx, $wy)];
            return true;
        }
        if ($e->kind === InputEvent::POINTER_UP && $l['from'] !== null) { $l['from'] = null; return true; }
        return false;
    }

    /** The word nearest to ($x, $y): a drag past the words chooses to the last near it. */
    private static function nearest(array $boxes, float $x, float $y): int
    {
        $best = 0; $d = INF;
        foreach ($boxes as $i => [$bx, $by, $bw, $bh]) {
            $dd = (max($bx - $x, 0, $x - $bx - $bw) ** 2) + (max($by - $y, 0, $y - $by - $bh) ** 2);
            if ($dd < $d) { [$d, $best] = [$dd, $i]; }
        }
        return $best;
    }

    /** The words from one end of $sel to the other, spaced as they were read, a line a line. @param array{0: int, 1: int} $sel */
    private static function text(array $words, array $sel): string
    {
        $out = '';
        for ($i = min($sel), $last = max($sel); $i <= $last; $i++) {
            $out .= $words[$i]['text'];
            if ($i < $last) { $out .= $words[$i + 1]['line'] !== $words[$i]['line'] ? "\n" : ($words[$i]['space'] ? ' ' : ''); }
        }
        return $out;
    }
}
