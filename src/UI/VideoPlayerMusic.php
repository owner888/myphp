<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Media\Player;

/**
 * The player in music mode, as IINA's mini player (MiniPlayerWindowController):
 * a window 300 points wide, the picture at the top (the film's, an audio
 * file's cover, else an album's art of its own), the track's title and
 * artist and album under it over the window's material, its controls in
 * their place while the pointer is over it (the volume, back, play, on,
 * the playlist and the picture shown or hidden), the time and its bar at
 * the foot, IINA's close and back buttons at the top left; the playlist
 * under it, the window as much higher as it is (300 points, more as it is
 * made higher). Its toggles are the settings' (musicModeShowPlaylist,
 * musicModeShowAlbumArt). Built by VideoPlayer while the state's
 * $musicMode.
 *
 * @internal VideoPlayer
 */
final class VideoPlayerMusic
{
    /** The window's width, the height of the controls under the picture, the least height of the playlist, IINA's. */
    private const WIDTH = 300.0, CONTROLS = 72.0, PLAYLIST_LEAST = 200.0;

    public static function build(Context $c, VideoPlayerState $s, Player $p, VideoPlayerOptions $o): Node
    {
        $set = $s->settings();
        $t = $c->theme;
        $art = $set->get('musicModeShowAlbumArt');
        $listed = $set->get('musicModeShowPlaylist');
        $cover = self::cover($p);
        $aspect = match (true) {
            $p->videoTrack() !== null => ([$w, $h] = $p->size()) && $h > 0 ? $w / $h : 1.0, // the film's picture
            $cover !== null => $cover->w / max(1, $cover->h),
            default => 1.0, // the album's art of its own, square as IINA's
        };
        $artH = $art ? round(self::WIDTH / $aspect) : 0.0;
        $normal = $artH + self::CONTROLS;
        if ($listed && $c->height > $normal + 1) { $s->musicPlaylistHeight = max(self::PLAYLIST_LEAST, $c->height - $normal); } // as the window is made higher
        $height = $normal + ($listed ? $s->musicPlaylistHeight : 0.0);
        $c->runtime?->askWindow('aspect', null);
        $c->runtime?->askWindow('buttons', false); // IINA's: its own close and back buttons
        $c->runtime?->askWindow('compact', [(int)self::WIDTH, (int)$height, (int)($normal + ($listed ? self::PLAYLIST_LEAST : 0)), $listed ? 0 : (int)$normal]);
        $c->runtime?->askWindow('title', $p->tags()['title'] !== '' ? $p->tags()['title'] : $p->title());
        if ($o->keys) { VideoPlayer::keys($c, $s, $p); }

        $root = $c->key('music mode')->column(0, 0, 1)->background(0x000000);
        $v = $c->runtime?->local($root->id, fn() => (object)['volume' => false]) ?? (object)['volume' => false];
        $top = $c->column()->fillWidth()->height($normal)->dragWindow(); // where the pointer shows the controls: the picture and the controls
          if ($art) {
              $c->column()->fillWidth()->height($artH)->clip();
                if ($p->videoTrack() !== null) {
                    $c->video($p)->fillWidth()->height($artH);
                } elseif ($cover !== null) {
                    $c->image($cover, self::WIDTH, $artH);
                } else {
                    self::defaultArt($c, $artH);
                }
              $c->end();
          }
          $panel = $c->column()->fillWidth()->height(self::CONTROLS)->systemMaterial('under-window', $t->dark);
            $shown = $c->hovered($top);
            $fade = $c->animate($root, 'controls', $shown ? 1 : 0, 0.2); // IINA's: the controls over the title as the pointer comes in
            self::info($c, $s, $p, 1 - $fade);
            self::controls($c, $s, $p, $v, $fade, $listed, $art);
            self::timeBar($c, $s, $p);
          $c->end();
          if ($fade > 0.001) { self::corner($c, $s, $fade); }
        $c->end();
        if (!$shown) { $v->volume = false; }
        if ($listed) {
            $list = $c->key('music playlist')->column()->fillWidth()->grow(1)->systemMaterial('under-window', $t->dark)->borderWidth(1, 0, 0, 0)->borderColor($t->border);
              VideoPlayer::playlistPane($c, $s, $list, self::WIDTH, $s->musicPlaylistHeight, 12, false);
            $c->end();
        }
        $c->clicked($panel); // a click on the controls none of them took is theirs, not the window's
        $c->end();
        return $root;
    }

    /** The track's title, bold, and its artist and album under it, centered, as IINA's (a title alone lower); at $alpha. */
    private static function info(Context $c, VideoPlayerState $s, Player $p, float $alpha): void
    {
        $t = $c->theme;
        $tags = $p->tags();
        $title = $tags['title'] !== '' ? $tags['title'] : $p->title();
        $under = $tags['artist'] !== '' && $tags['album'] !== '' ? "{$tags['artist']} - {$tags['album']}" : $tags['artist'] . $tags['album'];
        $box = $c->column(0, 4)->absolute()->left(24)->right(24)->top($under === '' ? 22 : 12)->align(Node::CENTER)->passThrough();
        $alpha > 0.001 ? $box->opacity($alpha) : $box->invisible();
          $c->text($title, 13, $t->text, Font::BOLD)->singleLine()->ellipsis('…');
          if ($under !== '') { $c->text($under, 11, $t->textMuted)->singleLine()->ellipsis('…'); }
        $c->end();
    }

    /**
     * IINA's controls, in their places: the volume (a click opens its
     * slider beside it, a click again mutes), back and on in the playlist
     * around play, the playlist's and the picture's toggles; at $alpha.
     */
    private static function controls(Context $c, VideoPlayerState $s, Player $p, object $v, float $alpha, bool $listed, bool $art): void
    {
        $t = $c->theme;
        $box = $c->column()->absolute()->left(0)->right(0)->top(4)->height(48);
        $alpha > 0.001 ? $box->opacity($alpha) : $box->invisible();
          $vol = $p->muted() || $s->volume == 0 ? 'speaker.slash.fill' : ($s->volume < 0.34 ? 'speaker.wave.1.fill' : ($s->volume < 0.67 ? 'speaker.wave.2.fill' : 'speaker.wave.3.fill'));
          if ($v->volume) { // IINA's volume control: a capsule of the popover's material, the button, the slider, the level
              $c->row(0, 0)->absolute()->left(38)->top(8)->size(148, 36)->round(18)->systemMaterial('popover', $c->theme->dark)->borderWidth(1)->borderColor($t->border);
              $c->end();
              $level = $p->muted() ? 0.0 : $s->volume;
              $slider = $c->sliderBase($level, 0, $s->maxVolume())->absolute()->left(83)->top(18)->size(100, 16);
              $slider->draw(static function (DisplayList $l, Node $n) use ($t): void {
                  $cy = $n->y + $n->h / 2; $k = $n->x + 6 + ($n->w - 12) * $n->fraction;
                  $l->rect($n->x, $cy - 2, $n->w, 4, $t->dark ? 0xffffff : 0x000000, 0.2, 2);
                  $l->rect($n->x, $cy - 2, $k - $n->x, 4, $t->accent, 1.0, 2);
                  $l->rect($k - 6, $cy - 6, 12, 12, 0xffffff, 1.0, 6);
              });
              if ($c->changed($slider)) { $s->setVolume($level); }
              $c->text((string)(int)round(($p->muted() ? 0 : $s->volume) * 100), 11, $t->text)->absolute()->left(191)->top(19)->width(28);
          }
          $mute = self::button($c, $vol, 15, 48, 21, 18, VideoPlayerSettings::iina($c, 'mini_player.volume'));
          if ($c->clicked($mute)) { $v->volume ? $s->toggleMute() : $v->volume = true; }
          if (!$v->volume) {
              if ($c->clicked(self::button($c, 'backward.end.fill', 16, 86, 12, 28))) { $s->previous(); }
              if ($c->clicked(self::button($c, $p->playing() ? 'pause.fill' : 'play.fill', 22, 138, 12, 28))) { $s->togglePause(); }
              if ($c->clicked(self::button($c, 'forward.end.fill', 16, 186, 12, 28))) { $s->next(); }
              $list = self::button($c, $listed ? 'list.bullet.rectangle.fill' : 'list.bullet.rectangle', 14, 234, 21, 18, VideoPlayer::t($c, 'videoPlaylist'));
              if ($c->clicked($list)) { $s->setSetting('musicModeShowPlaylist', !$listed); }
              $pic = self::button($c, $art ? 'photo.fill' : 'photo', 14, 268, 21, 18, VideoPlayerSettings::iina($c, 'mini_player.album_art'));
              if ($c->clicked($pic)) { $s->setSetting('musicModeShowAlbumArt', !$art); }
          }
        $c->end();
    }

    /** A button of symbol $name in a $size-point square at ($x, $y): a light gray, white under the pointer. */
    private static function button(Context $c, string $name, float $symbol, float $x, float $y, float $size, string $tip = ''): Node
    {
        $b = $c->column()->absolute()->left($x)->top($y)->size($size, $size)->center()->role(Role::BUTTON)->label($tip !== '' ? $tip : $name);
        if ($tip !== '') { $b->tooltip($tip); }
          VideoPlayerSettings::sf($c, $name, $symbol, $c->theme->text, 0.0)->opacity($c->hovered($b) ? 1.0 : 0.75);
        $c->end();
        return $b;
    }

    /** The time played, its bar and the length, at the foot: IINA's small monospaced digits, 6 from the edges. */
    private static function timeBar(Context $c, VideoPlayerState $s, Player $p): void
    {
        $t = $c->theme;
        $c->row(0, 6)->absolute()->left(6)->right(6)->bottom(8)->height(14)->align(Node::CENTER);
          $c->text(VideoPlayerState::clock($p->time()), 9, $t->textMuted)->fontFeatures('tnum')->width(32)->textAlign(Node::END);
          $bar = $c->sliderBase($s->at, 0, max($p->duration(), 0.001))->grow(1)->height(14);
          if (!$c->pressed($bar)) { $s->at = $p->time(); }
          $bar->draw(static function (DisplayList $l, Node $n) use ($t): void { // IINA's MiniPlaySlider: a thin track, the played part in the accent
              $cy = $n->y + $n->h / 2; $k = $n->x + $n->w * $n->fraction;
              $l->rect($n->x, $cy - 1.5, $n->w, 3, $t->dark ? 0xffffff : 0x000000, 0.2, 1.5);
              $l->rect($n->x, $cy - 1.5, $k - $n->x, 3, $t->accent, 1.0, 1.5);
          });
          if ($c->changed($bar)) { $p->seek($s->at); }
          $c->text(VideoPlayerState::clock($p->duration()), 9, $t->textMuted)->fontFeatures('tnum')->width(32);
        $c->end();
    }

    /** IINA's buttons at the top left, in a pill of the popover's material: close music mode (the window) and back to the video's window. */
    private static function corner(Context $c, VideoPlayerState $s, float $alpha): void
    {
        $c->column(3, 4)->absolute()->left(8)->top(6)->round(10)->systemMaterial('popover', $c->theme->dark)->opacity($alpha);
          $close = $c->column()->size(13, 13)->center()->role(Role::BUTTON)->label(VideoPlayerSettings::iina($c, 'mini_player.close'))->tooltip(VideoPlayerSettings::iina($c, 'mini_player.close'));
            VideoPlayerSettings::sf($c, 'xmark.circle.fill', 13, $c->theme->text);
          $c->end();
          $back = $c->column()->size(13, 13)->center()->role(Role::BUTTON)->label(VideoPlayerSettings::iina($c, 'mini_player.back'))->tooltip(VideoPlayerSettings::iina($c, 'mini_player.back'));
            VideoPlayerSettings::sf($c, 'arrow.uturn.backward.circle.fill', 13, $c->theme->text);
          $c->end();
        $c->end();
        if ($c->clicked($close)) { $c->runtime?->askWindow('close', true); }
        if ($c->clicked($back)) { $s->toggleMusicMode(); }
    }

    /** An album's art of its own where the file has none (IINA draws its own too): a note on a dark square, $h high. */
    private static function defaultArt(Context $c, float $h): void
    {
        $c->column()->fillWidth()->height($h)->center()->draw(static function (DisplayList $l, Node $n): void {
            $steps = 16;
            for ($i = 0; $i < $steps; $i++) { $l->rect($n->x, $n->y + $n->h * $i / $steps, $n->w, $n->h / $steps + 1, 0x3a3a3c - 0x010101 * (int)($i * 1.5), 1.0); }
        });
          VideoPlayerSettings::sf($c, 'music.note', 96, 0xffffff)->opacity(0.25);
        $c->end();
    }

    /** The file's cover as an image, decoded once a player. */
    private static function cover(Player $p): ?Image
    {
        static $covers = null;
        $covers ??= new \WeakMap();
        if (!isset($covers[$p])) {
            $bytes = $p->videoTrack() === null ? $p->cover() : null;
            $covers[$p] = [$bytes === null ? null : Image::fromBytes($bytes, 600)];
        }
        return $covers[$p][0];
    }
}
