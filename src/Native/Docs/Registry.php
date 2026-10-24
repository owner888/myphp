<?php
declare(strict_types=1);

namespace MyPHP\Native\Docs;

use MyPHP\{App, Document, DocumentCanceled, Documents, L10n, MenuItem, MessageOptions, OpenDialogOptions, QuitEvent, Window};
use WeakMap;

/**
 * The documents of every kind (mygo's document_flow.go and
 * document_menu.go): the windows that show them, the files the app opens
 * once it launched, asking to save as the app quits, and the File menu's
 * commands. A close or a quit that must ask is prevented, then done again
 * once the documents are saved or discarded, as VS Code does it, the
 * dialogs asked on the next turn of the run loop rather than within the
 * window's or the app's delegate.
 *
 * @internal
 */
final class Registry
{
    /** @var list<Documents> */
    private static array $kinds = [];
    /** @var ?WeakMap<Frame, Document> */
    private static ?WeakMap $byFrame = null;
    /** @var ?WeakMap<Window, Frame> */
    private static ?WeakMap $frames = null;
    /** Whether the app delivered the files it started with; those it was asked to open before. @var list<string> */
    private static bool $launched = false;
    private static array $pending = [];
    /** Whether a quit takes care of the documents, and whether they were. */
    private static bool $quitRunning = false, $quitResolved = false;
    /** Whether the app's events reach the documents. */
    private static bool $setUp = false;

    /** Adds a kind, and with the first sets the app up for documents. */
    public static function register(Documents $k): void
    {
        self::$kinds[] = $k;
        if (self::$launched) {
            Host::later(fn() => $k->restore());
            return;
        }
        if (self::$setUp) {
            return;
        }
        self::$setUp = true;
        App::onOpenFile(self::openFile(...));
        App::onBeforeQuit(self::beforeQuit(...));
        App::onQuit(function (): void {
            foreach (self::$kinds as $k) {
                $k->recovery->close();
            }
        });
        // Document apps stay open without windows, and make a document when the Dock icon is clicked (applicationShouldOpenUntitledFile).
        App::onWindowAllClosed(function (): void {});
        App::onActivate(function (bool $visible): void {
            $k = self::$kinds[0] ?? null;
            if (!$visible && $k !== null && !$k->opts->noUntitled && self::all() === []) {
                Host::later(fn() => $k->new());
            }
        });
    }

    /** Whether the app made documents, which the File menu then has commands for. */
    public static function has(): bool
    {
        return self::$kinds !== [];
    }

    /** Takes a kind out, for tests. */
    public static function forget(Documents $k): void
    {
        self::$kinds = array_values(array_filter(self::$kinds, fn(Documents $o): bool => $o !== $k));
    }

    /**
     * Runs once the app delivered the files it started with (App's launch):
     * restores the documents left unsaved, opens those it was asked to, or
     * else makes an untitled document.
     */
    public static function start(): void
    {
        if (self::$launched || self::$kinds === []) {
            return;
        }
        self::$launched = true;
        $pending = self::$pending;
        self::$pending = [];
        foreach (self::$kinds as $k) {
            $k->restore();
        }
        foreach ($pending as $p) {
            self::openOfKind($p, false);
        }
        $k = self::$kinds[0];
        if (!$k->opts->noUntitled && self::all() === []) {
            $k->new();
        }
    }

    /** A file the system asked the app to open: with the kind of documents of its extension. */
    private static function openFile(string $path): void
    {
        if (!self::$launched) {
            self::$pending[] = $path;
            return;
        }
        self::openOfKind($path, false);
    }

    /** Opens $path with the kind of its extension, or the first kind when $any, and tells why it could not. */
    private static function openOfKind(string $path, bool $any): void
    {
        $kind = null;
        foreach (self::$kinds as $k) {
            if ($k->handles($path)) {
                $kind = $k;
                break;
            }
        }
        $kind ??= $any ? (self::$kinds[0] ?? null) : null;
        if ($kind === null) {
            return;
        }
        try {
            $kind->open($path);
        } catch (\Throwable $e) {
            self::showError(null, 'opened', basename($path), $e);
        }
    }

    /** @return list<Document> */
    public static function all(): array
    {
        $all = [];
        foreach (self::$kinds as $k) {
            array_push($all, ...$k->documents());
        }
        return $all;
    }

    /** The open document of the file at $path, or of the same file under another name. */
    public static function openAt(string $path): ?Document
    {
        foreach (self::all() as $d) {
            $p = $d->path();
            if ($p === $path || ($p !== '' && Files::same($p, $path))) {
                return $d;
            }
        }
        return null;
    }

    /** The open documents of the file at $path. @return list<Document> */
    public static function at(string $path): array
    {
        return array_values(array_filter(self::all(), fn(Document $d): bool => $d->path() === $path));
    }

    public static function attach(Document $d, Frame $f, ?Window $w): void
    {
        self::$byFrame ??= new WeakMap;
        self::$byFrame[$f] = $d;
        if ($w !== null) {
            self::$frames ??= new WeakMap;
            self::$frames[$w] = $f;
        }
    }

    public static function detach(?Frame $f): void
    {
        if ($f !== null && self::$byFrame !== null) {
            unset(self::$byFrame[$f]);
        }
    }

    /** The frame of a document's window. */
    public static function frameOf(Window $w): ?Frame
    {
        return self::$frames[$w] ?? null;
    }

    public static function documentOf(?Frame $f): ?Document
    {
        return $f === null ? null : (self::$byFrame[$f] ?? null);
    }

    /** MyPHP's strings in the language the app shows. */
    public static function text(): L10n
    {
        return L10n::in(App::locale());
    }

    /** Notes $path as a recent document, which the Open Recent menus then list. */
    public static function addRecent(string $path): void
    {
        try {
            App::addRecentDocument($path);
        } catch (\RuntimeException $e) {
            error_log("myphp: recent documents: {$e->getMessage()}");
        }
    }

    /** Tells the user what went wrong: $action is what documentFailed says failed (opened, saved…). */
    public static function showError(?Window $parent, string $action, string $name, \Throwable $e): void
    {
        $message = self::text()->get('documentFailed', ['action' => $action, 'name' => $name]);
        try {
            Host::message(new MessageOptions(parent: $parent, type: 'error', message: $message, detail: preg_replace('/^myphp: /', '', $e->getMessage())));
        } catch (\Throwable) {
            error_log("myphp: documents: $message {$e->getMessage()}");
        }
    }

    // The quit: documents are saved in place, kept in their recovery copies for the next launch, or asked about (DocumentsOptions::$askOnQuit). The quit waits for that, then starts again.

    private static function beforeQuit(QuitEvent $e): void
    {
        if (self::$quitResolved) {
            self::$quitResolved = false;
            return;
        }
        if ($e->canceled) {
            return;
        }
        if (self::$quitRunning) {
            $e->cancel();
            return;
        }
        $edited = array_values(array_filter(self::all(), fn(Document $d): bool => $d->isEdited()));
        if ($edited === []) {
            return;
        }
        $e->cancel();
        self::$quitRunning = true;
        Host::later(function () use ($edited): void {
            $ok = self::resolveQuit($edited);
            self::$quitRunning = false;
            if ($ok) {
                self::$quitResolved = true;
                Host::quit();
            }
        });
    }

    /** Takes care of the edited documents before quitting, and reports whether the app may quit. @param list<Document> $docs */
    public static function resolveQuit(array $docs): bool
    {
        $ask = [];
        foreach ($docs as $d) {
            if ($d->saveInPlace()) {
                continue;
            }
            if ($d->kind()->opts->askOnQuit) {
                $ask[] = $d;
            } else {
                $d->backup();
            }
        }
        if (count($ask) <= 1) {
            if ($ask === []) {
                return true;
            }
            $ask[0]->focus();
            return $ask[0]->askToSave();
        }
        $t = self::text();
        $r = Host::message(new MessageOptions(type: 'warning', buttons: [$t->get('reviewChanges'), $t->get('cancel'), $t->get('discardChanges')], cancelButton: 1,
            message: $t->get('reviewBeforeQuitting', ['n' => count($ask), 'app' => App::name()]), detail: $t->get('reviewBeforeQuittingDetail')));
        if ($r->button === 0) {
            foreach ($ask as $d) {
                $d->focus();
                if (!$d->askToSave()) {
                    return false;
                }
            }
            return true;
        }
        if ($r->button === 2) {
            foreach ($ask as $d) {
                $d->discard();
            }
            return true;
        }
        return false;
    }

    // The File menu.

    /** Performs a document's menu role, from the menu of $win: on the next turn of the run loop, once the menu is done. */
    public static function role(string $role, ?Window $win): void
    {
        Host::later(fn() => self::perform($role, $win === null ? null : self::frameOf($win)));
    }

    /** Performs a document's menu role for the document of $frame, or of the window that has the keyboard. */
    public static function perform(string $role, ?Frame $frame): void
    {
        $k = self::$kinds[0] ?? null;
        if ($k === null) {
            return;
        }
        if ($role === 'new') {
            $k->new();
            return;
        }
        if ($role === 'open') {
            self::openDialog();
            return;
        }
        $d = self::documentOf($frame) ?? self::documentOf(Host::focused());
        if ($d === null) {
            return;
        }
        try {
            match ($role) {
                'save' => $d->save(),
                'saveAs' => $d->saveAs(),
                'duplicate' => $d->duplicate(),
                'rename' => $d->rename(),
                'moveTo' => $d->moveTo(),
                'revert' => $d->revertAsking(),
            };
        } catch (DocumentCanceled) {
            // the user said no
        } catch (\Throwable $e) {
            self::showError($d->window(), ['rename' => 'renamed', 'moveTo' => 'moved', 'revert' => 'reverted'][$role] ?? 'saved', $d->name(), $e);
        }
    }

    /** Asks which documents to open, of every kind. */
    private static function openDialog(): void
    {
        $filters = [];
        foreach (self::$kinds as $k) {
            array_push($filters, ...$k->filters());
        }
        foreach (Host::open(new OpenDialogOptions(parent: Window::focused(), filters: $filters, multiple: true)) as $p) {
            self::openOfKind($p, true);
        }
    }

    /** The items of the Open Recent submenu: the recent documents, then Clear Menu. @return list<MenuItem> */
    public static function recentItems(): array
    {
        $items = [];
        foreach (App::recentDocuments() as $p) {
            $items[] = new MenuItem(label: basename($p), click: fn() => Host::later(fn() => self::openOfKind($p, true)));
        }
        if ($items !== []) {
            $items[] = MenuItem::separator();
        }
        $items[] = new MenuItem(label: self::text()->get('menuClearRecent'), disabled: $items === [], click: function (): void {
            App::clearRecentDocuments();
        });
        return $items;
    }

    /** The File menu of an app with documents: DocumentGroup's on macOS. @return list<MenuItem> */
    public static function fileMenu(): array
    {
        $r = fn(string $role) => new MenuItem(role: $role);
        return [$r('new'), $r('open'), $r('openRecent'), MenuItem::separator(), $r('close'), $r('save'), $r('duplicate'), $r('rename'), $r('moveTo'), $r('revert')];
    }

    /** Enables the document roles of the menu bar for the document of the window that has the keyboard. */
    public static function refreshMenus(): void
    {
        $menu = App::menuBar();
        if ($menu === null) {
            return;
        }
        $d = self::documentOf(Host::focused());
        $s = $d?->state();
        self::walk($menu->items, function (MenuItem $it) use ($d, $s): void {
            $on = match ($it->role) {
                'save', 'saveAs', 'duplicate', 'rename', 'moveTo' => $d !== null,
                'revert' => $d !== null && $s->edited && $s->path !== '',
                default => null,
            };
            if ($on !== null && $it->disabled === $on) {
                $it->setEnabled($on);
            }
        });
    }

    /** @param list<MenuItem> $items */
    private static function walk(array $items, \Closure $fn): void
    {
        foreach ($items as $it) {
            $fn($it);
            self::walk($it->submenu, $fn);
        }
    }
}
