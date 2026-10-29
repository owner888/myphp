<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

use MyPHP\Native\Fibers;
use MyPHP\{Window, WindowOptions};
use MyPHP\UI\Content;

/**
 * A check for updates and what follows it: the update window, the
 * download, the offer to relaunch (mygo's session). The window shows the
 * session's current view, which the session keeps up to date even while
 * the window is not shown, so that it can show at any point. Its waits
 * are its fiber's.
 *
 * @internal
 */
final class Session
{
    /** The light and dark colors of the window: the gray of dialogs on macOS (mygo's frontend.Background). */
    public const LIGHT = '#ececec', DARK = '#1e1e1e';

    /** The user asked for the check: the window shows from the start and says when the app is up to date. */
    public bool $user;
    public bool $shown = false;
    public bool $ended = false;
    /** Ended, canceled by the user, or its window closed (mygo's context). */
    public bool $canceled = false;
    /** @var array<string, mixed> the view: prompt, release, title, message, detail, notes (blocks), bar, progress, checkbox, checked, buttons */
    public array $view = ['prompt' => 0, 'title' => '', 'buttons' => []];
    /** Counts the changes of the view, which the page watches. */
    public int $changes = 0;
    public ?Window $win = null;
    /** The window has the size of a release view. */
    public bool $release = false;
    /** @var ?array{0: string, 1: bool} the user's response: an action, and whether updates may install automatically */
    private ?array $response = null;

    /** @param Text $t the texts of the window, in the language the app showed when the check began: a window keeps its language while it is open */
    public function __construct(private readonly Checker $u, public readonly Text $t, bool $user)
    {
        $this->user = $user;
    }

    /**
     * Creates the session's window, hidden, showing $content, or the page
     * $html: the session resizes it for its views and closes it when it
     * ends, and the window closing cancels it (mygo's NewWindow).
     */
    public function newWindow(?Content $content, ?string $html = null): Window
    {
        $this->release = (bool)$this->view['release'];
        [$width, $height] = Checker::size($this->release);
        $win = new Window(new WindowOptions(
            title: $this->t->s->title,
            width: $width,
            height: $height,
            useContentSize: true,
            hidden: true,
            disableResize: true,
            disableMaximize: true,
            disableFullScreen: true,
            backgroundColor: 'light-dark(' . self::LIGHT . ', ' . self::DARK . ')',
            html: $html,
            content: $content,
        ));
        $this->win = $win;
        $win->on('closed', function (): void { $this->canceled = true; });
        return $win;
    }

    /** Shows the window, in front when the user asked for the check (mygo's frontend.Show). */
    public static function present(Window $win, bool $user): void
    {
        if ($user) {
            $win->show();
            $win->focus();
        } else {
            $win->showInactive();
        }
    }

    /** Changes the view, and the window's size when the view needs another. @param array<string, mixed> $v */
    public function set(array $v): void
    {
        $v['prompt'] = $this->view['prompt'];
        if (($v['buttons'] ?? []) != ($this->view['buttons'] ?? [])) {
            $v['prompt']++;
        }
        $this->view = $v + ['release' => false];
        $this->changes++;
        $this->win?->invalidate(); // native UI shows it in a frame; a page watches $changes
        $release = (bool)$this->view['release'];
        if ($this->win !== null && $this->release !== $release) {
            $this->release = $release;
            self::fit($this->win, $release);
        }
    }

    /** Gives the window the size of a status or release view. */
    public static function fit(Window $win, bool $release): void
    {
        [$w, $h] = Checker::size($release);
        $win->setContentSize($w, $h);
        $win->center();
    }

    /** Gives the window the width its buttons need, when they need more than the usual width, and a status view the height of its content. */
    public function fitContent(int $width, int $height): void
    {
        if ($this->win === null) {
            return;
        }
        [$w, $h] = Checker::size($this->release);
        $w = max($w, min($width, 1000));
        if (!$this->release) {
            $h = min(max($height, 80), 600);
        }
        $this->win->setContentSize($w, $h);
    }

    /** Shows the window, bringing it to the front when the user asked for the check. */
    public function show(): void
    {
        if ($this->ended) {
            return;
        }
        if (!$this->shown) {
            $this->shown = true;
            ($this->u->open)($this);
        } elseif ($this->user && $this->win !== null) {
            $this->win->show();
            $this->win->focus();
        }
    }

    /** Makes a check running in the background one the user asked for. */
    public function promote(): void
    {
        $this->user = true;
        $this->show();
    }

    /** Waits for the user's response to the view, or the window to close: [action, automaticDownloads]. @return array{0: string, 1: bool} */
    public function wait(): array
    {
        Fibers::until(fn(): bool => $this->response !== null || $this->canceled, 0.01, 0.05);
        if ($this->response === null) {
            return ['close', false];
        }
        [$r, $this->response] = [$this->response, null];
        return $r;
    }

    /** Takes the user's response to the prompt of a view: only the first to a prompt counts, and only with one of its buttons. */
    public function respond(int $prompt, string $action, bool $automaticDownloads): void
    {
        $ok = $prompt === $this->view['prompt'] && array_filter($this->view['buttons'], fn(array $b): bool => $b['action'] === $action && empty($b['disabled'])) !== [];
        if (!$ok) {
            return;
        }
        $this->view['prompt']++;
        if ($action === 'cancel') {
            $this->canceled = true;
        } elseif (!$this->canceled) {
            $this->response ??= [$action, $automaticDownloads];
        }
    }

    /** Ends the session and closes its window. */
    public function close(): void
    {
        $this->canceled = true;
        $this->ended = true;
        if ($this->win !== null && !$this->win->isDestroyed()) {
            $this->win->close();
        }
    }
}
