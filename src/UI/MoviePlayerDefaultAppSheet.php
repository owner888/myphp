<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Dialog;
use MyPHP\MessageOptions;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * IINA's sheet that makes the app the default of the files it opens
 * (SetAsDefaultSheetWindow, 300 by 150): which of them, video, audio and
 * playlists, checked; OK makes it (Native\DefaultApp) and tells how it
 * went, Cancel does not.
 *
 * @internal MoviePlayerSettingsPages
 */
final class MoviePlayerDefaultAppSheet
{
    private const WIDTH = 300, HEIGHT = 150;
    /** The extensions of playlists, the third kind. */
    private const PLAYLISTS = ['m3u', 'm3u8', 'pls', 'cue'];

    public static function open(): void
    {
        $parent = Window::focused();
        $state = (object)['video' => true, 'audio' => true, 'playlist' => true];
        $sheet = null;
        $close = function () use (&$sheet): void { \MyPHP\App::later(function () use (&$sheet): void { $sheet?->close(); }); };
        $sheet = new Window(new WindowOptions(parent: $parent, modal: true, width: self::WIDTH, height: self::HEIGHT, disableResize: true,
            content: Content::view(function (Context $c) use ($state, $close, $parent): void { self::build($c, $state, $close, $parent); })));
    }

    private static function build(Context $c, object $s, \Closure $close, ?Window $parent): void
    {
        $t = $c->theme;
        $c->column(0, 8, 1)->padding(24, 24, 24, 24)->background(MoviePlayerSettings::colors($t)['page']);
          $c->text(MoviePlayerSettings::tr($c, '$PleaseSelectTheMediaTypes'), 13, $t->text);
          $c->checkbox(MoviePlayerSettings::iina($c, 'sidebar.video'), $s->video);
          $c->checkbox(MoviePlayerSettings::iina($c, 'sidebar.audio'), $s->audio);
          $c->checkbox(MoviePlayerSettings::tr($c, '$Playlist'), $s->playlist);
          $c->column()->grow(1);
          $c->end();
          $c->row(0, 8)->fillWidth()->justify(Node::END)->align(Node::CENTER);
            if (MoviePlayerSettings::pushButton($c, MoviePlayerSettings::tr($c, '$Cancel'), false) || $c->shortcut(Key::ESCAPE)) { $close(); }
            $ok = $c->row()->height(24)->paddingX(12)->round(8)->align(Node::CENTER)->role(Role::BUTTON)->label(MoviePlayerSettings::tr($c, '$OK'))->background($t->accent);
              $c->text(MoviePlayerSettings::tr($c, '$OK'), 13, $t->accentText);
            $c->end();
            if ($c->clicked($ok) || $c->shortcut(Key::RETURN)) {
                $close();
                $exts = array_values(array_filter(\MyPHP\Native\DeepLink::extensions(), fn(string $e) => match (true) {
                    in_array($e, self::PLAYLISTS, true) => $s->playlist,
                    in_array($e, \MyPHP\Media\Playlist::AUDIO, true) => $s->audio,
                    default => $s->video,
                }));
                $text = MoviePlayerSettings::iina($c, 'alert.set_default.success');
                \MyPHP\App::later(function () use ($exts, $text, $parent): void {
                    try {
                        [$ok, $failed] = \MyPHP\Native\DefaultApp::set($exts);
                        Dialog::message(new MessageOptions(parent: $parent, message: sprintf($text, $ok, $failed)));
                    } catch (\RuntimeException $e) {
                        Dialog::message(new MessageOptions(parent: $parent, type: 'warning', message: $e->getMessage()));
                    }
                });
            }
          $c->end();
        $c->end();
    }
}
