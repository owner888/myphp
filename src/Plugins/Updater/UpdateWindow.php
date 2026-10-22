<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

use MyPHP\{App, NavigateEvent};

/**
 * The update window as a web page (mygo's window.go and page.html): it
 * shows the session's view, which it watches through the plugin's service.
 *
 * @internal
 */
final class UpdateWindow
{
    /** Creates the window of $s, hidden, which shows once its page is ready, in front when the user asked for the check. */
    public static function open(Checker $u, Session $s): void
    {
        $icon = $u->icon();
        $win = $s->newWindow(null, self::page($u->text(), $icon === null ? '' : 'data:image/png;base64,' . base64_encode($icon), $s->view));
        $win->on('willNavigate', function (NavigateEvent $e): void {
            // Links of the release notes open in the browser.
            $e->preventDefault();
            if ($e->userInitiated && Markdown::safeURL($e->url)) {
                App::openURL($e->url);
            }
        });
        $user = $s->user;
        $win->on('readyToShow', function () use ($win, $user): void { Session::present($win, $user); });
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
            '{{background}}' => Session::LIGHT,
            '{{darkBackground}}' => Session::DARK,
            '{{title}}' => $e($t->s->title),
            '{{releaseNotes}}' => $e($t->s->releaseNotes),
            '{{automaticDownloads}}' => $e($t->s->automaticDownloads),
            '{{nonce}}' => base64_encode(random_bytes(16)),
            '{{icon}}' => $icon,
            '{{view}}' => $initial,
        ]);
    }
}
