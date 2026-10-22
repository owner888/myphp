<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

use MyPHP\{App, NavigateEvent, Window, WindowOptions};
use MyPHP\Native\Bundle;

/**
 * The update window as a web page (mygo's window.go and page.html): it
 * shows the session's view, which it watches through the plugin's service.
 *
 * @internal
 */
final class UpdateWindow
{
    /** The light and dark colors of the window: the gray of dialogs on macOS. */
    public const LIGHT = '#ececec', DARK = '#1e1e1e';

    /** Creates the window of $s, hidden, which shows once its page is ready, in front when the user asked for the check. */
    public static function open(Checker $u, Session $s): void
    {
        [$width, $height] = Checker::size((bool)$s->view['release']);
        $t = $u->text();
        $win = new Window(new WindowOptions(
            title: $t->s->title,
            width: $width,
            height: $height,
            useContentSize: true,
            hidden: true,
            disableResize: true,
            disableMaximize: true,
            disableFullScreen: true,
            backgroundColor: 'light-dark(' . self::LIGHT . ', ' . self::DARK . ')',
            html: self::page($t, self::iconURL($u), $s->view),
        ));
        $s->win = $win;
        $s->release = (bool)$s->view['release'];
        $win->on('willNavigate', function (NavigateEvent $e): void {
            // Links of the release notes open in the browser.
            $e->preventDefault();
            if ($e->userInitiated && Markdown::safeURL($e->url)) {
                App::openURL($e->url);
            }
        });
        $user = $s->user;
        $win->on('readyToShow', function () use ($win, $user): void {
            if ($user) {
                $win->show();
                $win->focus();
            } else {
                $win->showInactive();
            }
        });
        $win->on('closed', function () use ($s): void { $s->canceled = true; });
    }

    /** The view as the page gets it, with the notes in HTML. @param array<string, mixed> $v @return array<string, mixed> */
    public static function pageView(array $v): array
    {
        $out = ['prompt' => $v['prompt']];
        foreach (['release', 'title', 'message', 'detail', 'bar', 'progress', 'checkbox', 'checked'] as $k) {
            if ($k === 'title' || !empty($v[$k])) { // as Go's omitzero
                $out[$k] = $v[$k] ?? '';
            }
        }
        $out['buttons'] = array_map(fn(array $b): array => array_filter($b, fn($x) => $x !== false && $x !== ''), $v['buttons']);
        $notes = Markdown::html($v['notes'] ?? []);
        if ($notes !== '') {
            $out['notes'] = $notes;
        }
        return $out;
    }

    /** The page of the window with the texts of $t, showing $v until it watches the session. @param array<string, mixed> $v */
    public static function page(Text $t, string $icon, array $v): string
    {
        $e = fn(string $s): string => strtr($s, ['<' => '&lt;', '>' => '&gt;', '&' => '&amp;', "'" => '&#39;', '"' => '&#34;']);
        $initial = json_encode(self::pageView($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        return strtr((string)file_get_contents(__DIR__ . '/page.html'), [
            '{{lang}}' => $e($t->lang),
            '{{dir}}' => $t->rtl ? 'rtl' : 'ltr',
            '{{background}}' => self::LIGHT,
            '{{darkBackground}}' => self::DARK,
            '{{title}}' => $e($t->s->title),
            '{{releaseNotes}}' => $e($t->s->releaseNotes),
            '{{automaticDownloads}}' => $e($t->s->automaticDownloads),
            '{{nonce}}' => base64_encode(random_bytes(16)),
            '{{icon}}' => $icon,
            '{{view}}' => $initial,
        ]);
    }

    /** The icon of the window as a data URL, or "". */
    private static function iconURL(Checker $u): string
    {
        $png = $u->opts->icon;
        if ($png === null) {
            $app = Bundle::path();
            $read = $app === null ? false : @file_get_contents("$app/Contents/Resources/app/icon.png");
            $png = $read === false ? null : $read;
        }
        return $png === null ? '' : 'data:image/png;base64,' . base64_encode($png);
    }
}
