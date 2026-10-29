<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\App;
use MyPHP\Dialog;
use MyPHP\Media\PlaybackHistory;
use MyPHP\Media\PlaybackPositions;
use MyPHP\Media\PlayerSettings;
use MyPHP\OpenDialogOptions;
use MyPHP\Shell;
use MyPHP\Window;

/**
 * The player's welcome window, as IINA's (InitialWindowController): the
 * app's icon, name and version, Open… and Open URL… at the right; the
 * film played last to resume (where it was left, with the settings'
 * recordRecentFiles and resumeLastPosition), and the recent documents
 * under it, a click opening one, Up and Down choosing one, Return opening
 * it. Built by Context::videoPlayerWelcome, in a window of 560 by 440, its
 * title bar hidden, over the under-window material.
 *
 * @internal UI\Context
 */
final class VideoPlayerWelcome
{
    /** @param Closure(string): void $open @param Closure(): void $openURL */
    public static function build(Context $c, Closure $open, Closure $openURL): Node
    {
        $t = $c->theme;
        if ($c->vibrancy()) { $c->root->color = null; }
        $root = $c->column(0, 0, 1)->dragWindow()->draw(static function (DisplayList $l, Node $n, Theme $t): void { // IINA's gradient over the material
            [$a, $b] = $t->dark ? [0.4, 0.1] : [0.1, 0.0];
            $steps = 24;
            for ($i = 0; $i < $steps; $i++) { $l->rect($n->x, $n->y + $n->h * $i / $steps, $n->w, $n->h / $steps + 1, 0x000000, $a + ($b - $a) * $i / $steps); }
        });
        $v = $c->runtime?->local($root->id, fn() => (object)['chosen' => -1]) ?? (object)['chosen' => -1];
        $set = PlayerSettings::default();
        $last = null;
        if ($set->get('recordRecentFiles') && $set->get('resumeLastPosition')) {
            $first = PlaybackHistory::default()->all()[0] ?? null;
            if ($first !== null && (preg_match('~^https?://~i', $first['path']) === 1 || is_file($first['path']))) { $last = [$first['path'], PlaybackPositions::default()->get($first['path']) ?? 0.0]; }
        }
        $recent = array_values(array_filter(App::recentDocuments(), fn(string $p) => $p !== ($last[0] ?? null)));
          $c->row(0, 12)->absolute()->top(48)->left(24)->align(Node::CENTER); // the header
            $icon = self::icon($c);
            if ($icon !== null) { $c->image($icon, 68, 68); }
            $c->column(0, 4);
              $c->text(App::name(), 20, $t->text, Font::BOLD);
              $c->text(App::version(), 13, $t->textMuted);
            $c->end();
          $c->end();
          $c->row(0, 12)->absolute()->top(64)->right(30); // the buttons
            if (VideoPlayerSettings::pushButton($c, $c->texts()->get('welcomeOpen'), false)) {
                $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), multiple: true));
                foreach ($paths ?? [] as $p) { $open($p); }
            }
            if (VideoPlayerSettings::pushButton($c, $c->texts()->get('welcomeOpenURL'), false)) { $openURL(); }
          $c->end();
          $header = 48 + 68; // the header's bottom
          if ($last !== null) {
              $k = self::colors($t);
              $b = $c->key('last')->row(0, 6)->absolute()->top($header + 16)->left(30)->right(30)->height(32)->paddingX(10)->round(8)->align(Node::CENTER)->role(Role::BUTTON)->label(basename($last[0]));
              [$rgb, $alpha] = match (true) { // IINA's InitialWindowViewActionButton: highlighted while no recent one is chosen
                  $c->hovered($b) && ($c->runtime?->input->mouseDown ?? false) => $k['pressed'],
                  $c->hovered($b) => $k['hover'],
                  $v->chosen < 0 => $k['normal'],
                  default => [0, 0.0],
              };
              $b->background($rgb, $alpha);
                VideoPlayerSettings::glyph($c, ['clock.arrow.trianglehead.counterclockwise.rotate.90', 'clock'], $t->text);
                $c->text($c->texts()->get('welcomeResume'), 13, $t->text)->singleLine()->shrink(0);
                $c->text(basename($last[0]), 13, $t->text)->singleLine()->ellipsis('…')->grow(1);
                $c->text(VideoPlayerState::clock($last[1]), 11, $t->textMuted)->fontFeatures('tnum');
              $c->end();
              if ($c->clicked($b)) { $open($last[0]); }
          }
          $list = $c->scroll()->absolute()->top($header + ($last !== null ? 60 : 42))->left(24)->right(24)->bottom(24); // IINA's: 42 under the header, 18 more under the film to resume
            foreach ($recent as $i => $path) {
                $row = $c->key("recent:$path")->row(0, 6)->height(28)->paddingX(8)->round(4)->align(Node::CENTER)->role(Role::BUTTON)->label(basename($path));
                if ($i === $v->chosen) { $row->background($t->dark ? 0xffffff : 0x262626, 0.1); }
                  $fi = self::fileIcon($c, $path);
                  if ($fi !== null) { $c->image($fi, 16, 16); } else { $c->box(16, 16, 0)->color = null; }
                  $c->text(basename($path), 13, $t->text)->singleLine()->ellipsis('…');
                $c->end();
                if ($c->clicked($row)) { $open($path); }
            }
          $c->end();
        $c->end();
        // IINA's keys: Up and Down among the resumed film (-1) and the recent ones, Return opening the one chosen
        if ($v->chosen < 0 && $last === null && $recent !== []) { $v->chosen = 0; }
        if ($c->shortcut(Key::DOWN)) { $v->chosen = min(count($recent) - 1, $v->chosen + 1); }
        if ($c->shortcut(Key::UP)) { $v->chosen = max($last !== null ? -1 : 0, $v->chosen - 1); }
        if ($c->shortcut(Key::RETURN)) {
            $path = $v->chosen >= 0 ? ($recent[$v->chosen] ?? null) : ($last[0] ?? $recent[0] ?? null);
            if ($path !== null) { $open($path); }
        }
        $list->role(Role::LIST);
        return $root;
    }

    /** IINA's colors of the film to resume: normal, under the pointer, pressed, [rgb, alpha]. @return array<string, array{0: int, 1: float}> */
    private static function colors(Theme $t): array
    {
        return $t->dark
            ? ['normal' => [0xffffff, 0.1], 'hover' => [0x808080, 0.1], 'pressed' => [0x000000, 0.1]]
            : ['normal' => [0x262626, 0.1], 'hover' => [0x262626, 0.2], 'pressed' => [0x000000, 0.25]];
    }

    /** The app's icon, drawn once; none outside the running app. */
    private static function icon(Context $c): ?Image
    {
        static $icon = false;
        if ($icon === false) {
            try {
                $icon = App::running() ? Image::fromBytes(Shell::appIcon((int)(68 * $c->scale)), 512) : null;
            } catch (\RuntimeException) {
                $icon = null; // no app icon (a run outside a bundle without one): none shows, as the Dock shows none
            }
        }
        return $icon;
    }

    /** The Finder's icon of $path, 16 points, drawn once; none for a URL or a file gone, or outside the running app. */
    private static function fileIcon(Context $c, string $path): ?Image
    {
        static $icons = [];
        if (!array_key_exists($path, $icons)) {
            $icons[$path] = App::running() && is_file($path) ? // NSWorkspace's, of the running app (none built headless)
                Image::fromBytes(Shell::fileIcon($path, (int)(16 * $c->scale)), 64) : null;
        }
        return $icons[$path];
    }
}
