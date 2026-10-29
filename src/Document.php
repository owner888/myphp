<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\Docs\{Files, Frame, Host, NotWritable, Registry, Untitled, Watches};
use RuntimeException;

/**
 * An open document (mygo's Document): its content, the file it was read
 * from and is saved to, and its window. Documents makes them; a document
 * closes with its window.
 *
 * Its commands are File menu's: save() writes it to its file, asking where
 * for an untitled one, for another file when its file can't be written,
 * and whether to overwrite a file another app changed; they throw
 * DocumentCanceled when the user cancels in a dialog they showed.
 */
final class Document
{
    /** @internal why a document is written (write()): to its file, unless another app changed it; the same, without asking anything; to its file, whatever is there; to another file, which the document becomes */
    public const SAVE = 0, AUTOSAVE = 1, OVERWRITE = 2, SAVE_AS = 3;

    /** @internal names the document's recovery copy */
    public string $id = '';
    private string $path = '';
    /** The name of an untitled document; $untitled its number, which other untitled documents don't take. */
    private string $name;
    /** @internal */
    public int $untitled = 0;
    /** How many changes the content had; the change the file has, and the one the recovery copy has (0: none). */
    private int $changes = 0, $savedAt = 0, $backedUp = 0;
    private bool $edited = false;
    /** The file as the document last read or wrote it (Files::stamp). @var ?array{0: int, 1: int, 2?: array{0: int, 1: int, 2: int}} */
    private ?array $stamp = null;
    private bool $readOnly = false, $conflict = false, $deleted = false;
    private ?Frame $frame = null;
    /** Whether it closed; whether the user chose not to save the changes of the closing document. */
    private bool $closed = false, $discarded = false;
    /** The file watched for it. */
    private string $watched = '';
    /** When the oldest change not saved or copied was made, 0 when none is. */
    private float $dirtySince = 0;
    /** The timer that saves the document or copies it: those of other numbers are stopped. */
    private int $timer = 0;
    /** Whether its window asks to save before it closes; whether a state change waits for the next turn of the run loop. */
    private bool $closing = false, $notifying = false;
    /** @var array<int, Closure(DocumentState): void> */
    private array $onState = [];
    /** @var array<int, Closure(): void> */
    private array $onReload = [];
    private int $nextListener = 0;
    /** What the file has, for DocumentsOptions::$equal, when $savedKnown: a document restored from its recovery copy doesn't know it. */
    private mixed $saved;
    private bool $savedKnown = true;

    /** @internal Documents makes them */
    public function __construct(private readonly Documents $kind, private mixed $content, string $name)
    {
        $this->saved = $content;
        $this->name = $name;
    }

    /** The document's content. */
    public function content(): mixed
    {
        return $this->content;
    }

    /** Replaces the document's content, which edits it, unless DocumentsOptions::$equal finds it equal to what its file has. */
    public function set(mixed $content): void
    {
        $this->content = $content;
        $this->changes++;
        $eq = $this->kind->opts->equal;
        $this->edit($eq === null || !$this->savedKnown || !$eq($content, $this->saved));
    }

    /** Tells that the content changed without being replaced, for a mutable content (DocumentsOptions::$snapshot). It edits the document. */
    public function changed(): void
    {
        $this->changes++;
        $this->edit(true);
    }

    /** Sets whether the document has unsaved changes, for an app that knows better, as one that keeps the undo history of its content: false makes the content the one its file has, without writing it. */
    public function setEdited(bool $edited): void
    {
        $this->changes++;
        if (!$edited) {
            $this->savedAt = $this->changes;
            [$this->saved, $this->savedKnown] = [$this->content, true];
        }
        $this->edit($edited);
    }

    /** The window that shows the document, null once it closed. */
    public function window(): ?Window
    {
        return $this->frame?->window();
    }

    /** The document's file, '' while it is untitled. */
    public function path(): string
    {
        return $this->path;
    }

    /** The document's file name, or the name of an untitled document, such as "Untitled 2". */
    public function name(): string
    {
        return $this->path !== '' ? basename($this->path) : $this->name;
    }

    /** Whether the document has unsaved changes. */
    public function isEdited(): bool
    {
        return $this->edited;
    }

    public function state(): DocumentState
    {
        return new DocumentState($this->name(), $this->path, $this->edited, $this->readOnly, $this->conflict, $this->deleted);
    }

    /** Whether the document's file can't be written. */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /** Whether another app changed the document's file while the document had unsaved changes. */
    public function hasConflict(): bool
    {
        return $this->conflict;
    }

    /** Calls $fn(DocumentState) on the next turn of the run loop when the document's state changes: it is edited or saved, moved, renamed, or its file changed. Returns a function that removes it. */
    public function onStateChange(Closure $fn): Closure
    {
        return $this->listen($this->onState, $fn);
    }

    /** Calls $fn after the document's content was read from its file again: reverted, or changed by another app. Views that keep a copy of the content take it again. Returns a function that removes it. */
    public function onReload(Closure $fn): Closure
    {
        return $this->listen($this->onReload, $fn);
    }

    /** @param array<int, Closure> $list */
    private function listen(array &$list, Closure $fn): Closure
    {
        $k = $this->nextListener++;
        $list[$k] = $fn;
        return function () use (&$list, $k): void { unset($list[$k]); };
    }

    /** Writes the document to its file, as File > Save does (see the class). */
    public function save(): void
    {
        $this->saveInteractive(false);
    }

    /** Asks where to save the document, then writes it there; the document then is that file. A path saves there without asking. */
    public function saveAs(string $path = ''): void
    {
        if ($path === '') {
            $this->saveInteractive(true);
            return;
        }
        $this->write($path, self::SAVE_AS);
    }

    /** Writes the document to its file even when another app changed the file since the document read it. */
    public function saveAnyway(): void
    {
        if ($this->path === '') {
            $this->saveInteractive(true);
            return;
        }
        $this->write($this->path, self::OVERWRITE);
    }

    /** Reads the document's file again, discarding the unsaved changes. */
    public function revert(): void
    {
        $this->path !== '' || throw new RuntimeException('myphp: an untitled document has no file to revert to');
        $this->reload($this->path);
    }

    /** Renames the document's file to $name, in its folder, as File > Rename does; '' asks for the name. An untitled document is saved instead. */
    public function rename(string $name = ''): void
    {
        if ($this->path === '') {
            $this->saveInteractive(true);
            return;
        }
        if ($name === '') {
            $t = Registry::text();
            $target = $this->askPath($t->get('rename'), $t->get('rename'), $t->get('renameTo'));
        } else {
            if (strpbrk($name, '/\\') !== false || $name === '.' || $name === '..') {
                throw new \InvalidArgumentException("myphp: invalid file name \"$name\"");
            }
            $target = dirname($this->path) . "/$name";
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException("myphp: $target already exists");
            }
        }
        $this->relocate($target);
    }

    /** Moves the document's file into the folder $dir, as File > Move To does; '' asks for the folder. */
    public function moveTo(string $dir = ''): void
    {
        $t = Registry::text();
        if ($dir === '') {
            $dirs = Host::open(new OpenDialogOptions(parent: $this->window(), title: $t->get('moveTo'), buttonLabel: $t->get('move'), directory: true, createDirectories: true));
            $dirs !== [] || throw new DocumentCanceled;
            $dir = $dirs[0];
        }
        if ($this->path === '') {
            $this->write("$dir/{$this->name}.{$this->kind->opts->types[0]->extensions[0]}", self::SAVE_AS);
            return;
        }
        $target = rtrim($dir, '/') . '/' . basename($this->path);
        if ($target === $this->path) {
            return;
        }
        if (file_exists($target) || is_link($target)) {
            $r = Host::message(new MessageOptions(parent: $this->window(), type: 'warning', buttons: [$t->get('replaceFile'), $t->get('cancel')], cancelButton: 1,
                message: $t->get('alreadyExists', ['name' => basename($target)]), detail: $t->get('alreadyExistsDetail')));
            $r->button === 0 || throw new DocumentCanceled;
        }
        $this->relocate($target);
    }

    /** Opens an untitled copy of the document in a new window, as File > Duplicate does. */
    public function duplicate(): Document
    {
        $value = $this->capture();
        $name = $this->path !== '' ? pathinfo($this->path, PATHINFO_FILENAME) : $this->name;
        $d = new Document($this->kind, $value, "$name copy");
        $d->id = bin2hex(random_bytes(8));
        $d->restore($value);
        $d->dirtySince = Host::now();
        $this->kind->show($d);
        return $d;
    }

    /** Closes the document's window, which asks to save the document first when it must. */
    public function close(): void
    {
        $this->frame?->close();
    }

    // What the rest of the documents' machinery does with a document.

    /** @internal the documents it is one of */
    public function kind(): Documents
    {
        return $this->kind;
    }

    /** @internal the window's frame, null once it closed */
    public function frame(): ?Frame
    {
        return $this->frame;
    }

    /** @internal the document was read from $path, whose stamp is $stamp */
    public function opened(string $path, array $stamp): void
    {
        [$this->path, $this->stamp] = [$path, $stamp];
        $this->readOnly = !Files::writable($path);
    }

    /**
     * @internal the document is the recovery copy of the file at $path, as
     * it was when the copy was made ($stamp), or of an untitled document:
     * in conflict when the file changed meanwhile, deleted when it is gone
     * @param array{0: int, 1: int} $stamp
     */
    public function recovered(string $path, array $stamp): void
    {
        $this->restore($this->content);
        if ($path !== '') {
            [$this->path, $this->stamp] = [$path, $stamp];
            $now = Files::stamp($path);
            $this->deleted = $now === null;
            $this->conflict = $now !== null && !Files::sameStamp($now, $stamp);
            $this->readOnly = !$this->deleted && !Files::writable($path);
        } elseif (($n = Untitled::number($this->name)) > 0) {
            $this->untitled = $n;
        }
        $this->dirtySince = Host::now();
        $this->backup();
    }

    /** @internal makes $f the document's window */
    public function attach(Frame $f): void
    {
        $this->frame = $f;
        $f->onClose($this->windowClosing(...));
        $f->onClosed($this->windowClosed(...));
        $f->onFocus(function (): void {
            $this->checkDisk();
            Registry::refreshMenus();
        });
        $f->onBlur(function (): void {
            if ($this->edited && $this->autosaves()) {
                $this->persist();
            }
            Registry::refreshMenus();
        });
    }

    /** @internal brings its window to the front */
    public function focus(): void
    {
        $this->frame?->show();
    }

    /** Follows an edit of the content: $edited is the new edited state. */
    private function edit(bool $edited): void
    {
        $was = $this->edited;
        $this->edited = $edited;
        if (!$edited) {
            $this->dirtySince = 0;
        } elseif ($this->dirtySince == 0) {
            $this->dirtySince = Host::now();
        }
        $this->schedule();
        if (!$edited && $this->backedUp !== 0) {
            $this->kind->recovery->remove($this); // back to what the file has: the recovery copy is of no use
        }
        if ($was !== $edited) {
            $this->notify();
        }
    }

    /** @internal tells the window, the menus, the listeners and the pages that the state changed, on the next turn of the run loop, once for changes in a row */
    public function notify(): void
    {
        if ($this->notifying) {
            return;
        }
        $this->notifying = true;
        Host::later(function (): void {
            $this->notifying = false;
            $s = $this->state();
            if ($this->frame !== null && !$this->frame->isDestroyed()) {
                $this->frame->present($s);
            }
            foreach ($this->onState as $fn) {
                $fn($s);
            }
            Registry::refreshMenus();
        });
    }

    /** Whether the document is saved in place. */
    private function autosaves(): bool
    {
        return $this->kind->opts->autosave === Autosave::InPlace && $this->path !== '' && !$this->readOnly && !$this->conflict && !$this->deleted;
    }

    /** @internal arms the timer that saves the document in place or writes its recovery copy, once the edits stop */
    public function schedule(): void
    {
        $id = ++$this->timer;
        if (!$this->edited || $this->closed) {
            return;
        }
        [$delay, $most] = $this->autosaves() ? [$this->kind->autosaveDelay(), 30.0] : [1.0, 5.0];
        $delay = min($delay, max($most - (Host::now() - $this->dirtySince), 0.0));
        Host::after($delay, function () use ($id): void {
            if ($id === $this->timer) {
                $this->persist();
            }
        });
    }

    /** Saves the document in place, or else writes its recovery copy. */
    private function persist(): void
    {
        if (!$this->closed && !$this->saveInPlace()) {
            $this->backup();
        }
    }

    /** @internal saves the document in place when it is saved so, and reports whether it did: it didn't when it is in conflict or its file can't be written, and then asks or keeps its changes in its recovery copy */
    public function saveInPlace(): bool
    {
        if (!$this->autosaves()) {
            return false;
        }
        try {
            return $this->write($this->path, self::AUTOSAVE);
        } catch (\Throwable $e) {
            if (!$e instanceof DocumentChanged) {
                Logger::error("cannot save {$this->path}: {$e->getMessage()}", 'documents');
            }
            return false;
        }
    }

    /** What a write of the document writes: the content, a copy of it with a snapshot. */
    private function capture(): mixed
    {
        $snap = $this->kind->opts->snapshot;
        return $snap === null ? $this->content : $snap($this->content);
    }

    /**
     * @internal writes the document to $target, and reports whether it
     * wrote anything: one closed isn't autosaved; one whose file another app
     * changed is not written but marked in conflict (DocumentChanged)
     */
    public function write(string $target, int $op): bool
    {
        [$value, $at] = [$this->capture(), $this->changes];
        if ($this->closed && $op === self::AUTOSAVE) {
            return false;
        }
        if ($op === self::SAVE || $op === self::AUTOSAVE) {
            $now = Files::stamp($target);
            if ($now !== null && (!Files::sameStamp($now, $this->stamp) || $this->deleted)) {
                $was = $this->conflict;
                [$this->conflict, $this->deleted] = [true, false];
                if (!$was) {
                    $this->notify();
                }
                throw new DocumentChanged;
            }
        }
        $stamp = Files::writeSafely($target, ($this->kind->opts->write)($value));
        [$this->saved, $this->savedKnown] = [$value, true];
        $this->edited = $this->changes !== $at;
        if (($eq = $this->kind->opts->equal) !== null && $this->edited) {
            $this->edited = !$eq($this->content, $value);
        }
        $this->savedAt = $at;
        $this->stamp = $stamp;
        [$this->conflict, $this->deleted] = [false, false];
        $moved = $target !== $this->path;
        if ($moved) {
            $this->path = $target;
            $this->readOnly = false;
        }
        $this->dirtySince = $this->edited ? Host::now() : 0; // edited while it was written
        $this->schedule();
        if (!$this->edited && $this->backedUp !== 0) {
            $this->kind->recovery->remove($this);
        }
        if ($moved) {
            Registry::addRecent($target);
            $this->watch();
        }
        $this->notify();
        return true;
    }

    /** @internal reads $path again and makes it the content */
    public function reload(string $path): void
    {
        [$value, $stamp] = $this->kind->readFile($path);
        [$this->content, $this->saved, $this->savedKnown] = [$value, $value, true];
        $this->changes++;
        $this->savedAt = $this->changes;
        $this->edited = false;
        $this->stamp = $stamp;
        [$this->conflict, $this->deleted] = [false, false];
        $this->readOnly = !Files::writable($path);
        $this->dirtySince = 0;
        $this->schedule();
        if ($this->backedUp !== 0) {
            $this->kind->recovery->remove($this);
        }
        Host::later(function (): void {
            foreach ($this->onReload as $fn) {
                $fn();
            }
            $this->frame?->reloaded();
        });
        $this->notify();
    }

    /** Makes $value, a recovery copy or a duplicate's, the content, edited. */
    private function restore(mixed $value): void
    {
        $this->content = $value;
        $this->savedKnown = false;
        $this->changes++;
        $this->edited = true;
    }

    /** @internal writes the recovery copy of the document, if it changed since the last */
    public function backup(): void
    {
        $value = $this->capture();
        $at = $this->changes;
        if (!$this->edited || $at === $this->backedUp) {
            return;
        }
        $e = ['id' => $this->id, 'stamp' => ['modTime' => $this->stamp[0] ?? 0, 'size' => $this->stamp[1] ?? 0]];
        if ($this->path !== '') {
            $e['path'] = $this->path;
        } else {
            $e['name'] = $this->name;
        }
        try {
            $this->kind->recovery->save($e, ($this->kind->opts->write)($value));
        } catch (\Throwable $err) {
            Logger::warn("cannot write the recovery copy of {$this->name()}: {$err->getMessage()}", 'documents');
            return;
        }
        $this->backedUp = $at;
        $this->dirtySince = $this->changes !== $at ? Host::now() : 0; // edited while it was written
    }

    /** @internal the recovery copy is forgotten: returns the id it had */
    public function recoveryForgotten(): string
    {
        $this->backedUp = 0;
        return $this->id;
    }

    /** @internal the change the recovery copy has, 0 when none */
    public function backedUpAt(): int
    {
        return $this->backedUp;
    }

    /** Decides whether the window may close: an unedited document closes, an edited one is saved in place or asks first, which keeps the window open until it is answered. */
    private function windowClosing(bool $canceled): bool
    {
        if ($this->closing) {
            return true;
        }
        if ($canceled || App::quitting() || !$this->edited) { // a quit took care of the documents
            return false;
        }
        $this->closing = true;
        Host::later(function (): void {
            $ok = $this->saveInPlace() || $this->askToSave();
            $this->closing = false;
            if ($ok && $this->frame !== null && !$this->frame->isDestroyed()) {
                $this->frame->destroy();
            }
        });
        return true;
    }

    /** Closes the document with its window. */
    private function windowClosed(): void
    {
        $this->closed = true;
        $f = $this->frame;
        $this->frame = null;
        $keep = $this->edited && !$this->discarded;
        $this->timer++;
        $this->kind->closed($this, $f);
        $this->watch();
        if ($keep) {
            $this->backup(); // closed without asking (destroy(), a parent closing): the changes stay in the recovery copy
        } else {
            $this->kind->recovery->remove($this);
        }
        Registry::refreshMenus();
    }

    /** @internal drops the unsaved changes of the closing document */
    public function discard(): void
    {
        $this->discarded = true;
        $this->kind->recovery->remove($this);
    }

    /** The buttons of a dialog asking to save, as the alert lays them out on macOS: the first rightmost, the second left of it, the third apart. @return array{0: list<string>, 1: int, 2: int, 3: int} the buttons, and where save, discard and cancel are */
    private static function saveButtons(string $save, string $discard): array
    {
        return [[$save, Registry::text()->get('cancel'), $discard], 0, 2, 1];
    }

    /** @internal asks whether to save the changes before the document closes, saves or discards them, and reports whether it may close */
    public function askToSave(): bool
    {
        $t = Registry::text();
        $name = $this->name();
        if ($this->path === '' && $this->kind->opts->autosave === Autosave::InPlace) {
            [$buttons, $save, $discard, $cancel] = self::saveButtons($t->get('saveEllipsis'), $t->get('deleteDocument'));
            [$message, $detail] = [$t->get('keepNewDocument', ['name' => $name]), $t->get('keepNewDocumentDetail')];
        } else {
            [$buttons, $save, $discard, $cancel] = self::saveButtons($t->get($this->path === '' ? 'saveEllipsis' : 'save'), $t->get('dontSave'));
            [$message, $detail] = [$t->get('saveChanges', ['name' => $name]), $t->get('changesLost')];
        }
        $r = Host::message(new MessageOptions(parent: $this->window(), type: 'warning', message: $message, detail: $detail, buttons: $buttons, cancelButton: $cancel));
        if ($r->button === $save) {
            return $this->saveOrShow();
        }
        if ($r->button === $discard) {
            $this->discard();
            return true;
        }
        return false;
    }

    /** @internal saves the document as File > Save does, shows why it failed, and reports whether it saved */
    public function saveOrShow(): bool
    {
        try {
            $this->saveInteractive(false);
            return true;
        } catch (DocumentCanceled) {
            return false;
        } catch (\Throwable $e) {
            Registry::showError($this->window(), 'saved', $this->name(), $e);
            return false;
        }
    }

    /** Saves the document, asking for a file when it has none or $as, and what to do when its file can't be written or another app changed it. */
    private function saveInteractive(bool $as): void
    {
        if ($as || $this->path === '') {
            $this->write($this->askPath('', '', ''), self::SAVE_AS);
            return;
        }
        if (!$this->readOnly) {
            try {
                $this->write($this->path, self::SAVE);
                return;
            } catch (DocumentChanged) {
                $this->resolveConflict($this->path);
                return;
            } catch (NotWritable) {
                // asks for another file
            }
        }
        $t = Registry::text();
        $r = Host::message(new MessageOptions(parent: $this->window(), type: 'warning', buttons: [$t->get('saveAs'), $t->get('cancel')], cancelButton: 1,
            message: $t->get('readOnly', ['name' => $this->name()]), detail: $t->get('readOnlyDetail')));
        $r->button === 0 || throw new DocumentCanceled;
        $this->saveInteractive(true);
    }

    /** Asks what to do when another app changed the document's file. */
    private function resolveConflict(string $path): void
    {
        $t = Registry::text();
        [$buttons, $save, $revert, $cancel] = self::saveButtons($t->get('saveAnyway'), $t->get('revertDocument'));
        $r = Host::message(new MessageOptions(parent: $this->window(), type: 'warning', buttons: $buttons, cancelButton: $cancel,
            message: $t->get('changedOnDisk', ['name' => basename($path)]), detail: $t->get('changedOnDiskDetail')));
        if ($r->button === $save) {
            $this->write($path, self::OVERWRITE);
        } elseif ($r->button === $revert) {
            $this->reload($path);
        } else {
            throw new DocumentCanceled;
        }
    }

    /** Asks where to save the document, or to rename it (title and button "Rename", $nameLabel "Rename To:"). */
    private function askPath(string $title, string $button, string $nameLabel): string
    {
        $ext = $this->kind->opts->types[0]->extensions[0];
        $def = $this->path !== '' ? $this->path : getenv('HOME') . "/Documents/{$this->name}.$ext";
        $target = Host::save(new SaveDialogOptions(parent: $this->window(), title: $title, buttonLabel: $button, defaultPath: $def, nameFieldLabel: $nameLabel, filters: $this->kind->filters()));
        $target !== '' || throw new DocumentCanceled;
        return pathinfo($target, PATHINFO_EXTENSION) === '' ? "$target.$ext" : $target;
    }

    /** Moves the document's file to $target, which the document then is. */
    private function relocate(string $target): void
    {
        if (!@rename($this->path, $target)) {
            // Another volume: rename() copies and removes there; this tells why it failed.
            throw new RuntimeException("myphp: cannot move {$this->path} to $target: " . (error_get_last()['message'] ?? 'failed'));
        }
        $this->path = $target;
        $this->stamp = Files::stamp($target);
        $this->readOnly = !Files::writable($target);
        Registry::addRecent($target);
        $this->watch();
        $this->notify();
    }

    /** @internal revert() asking first whether to discard the changes, as File > Revert does */
    public function revertAsking(): void
    {
        if (!$this->edited || $this->path === '') {
            return;
        }
        $t = Registry::text();
        $r = Host::message(new MessageOptions(parent: $this->window(), type: 'warning', buttons: [$t->get('revertDocument'), $t->get('cancel')], cancelButton: 1,
            message: $t->get('revertQuestion', ['name' => $this->name()]), detail: $t->get('revertDetail')));
        $r->button === 0 || throw new DocumentCanceled;
        $this->reload($this->path);
    }

    /** @internal watches the document's file, after it opened or moved, or stops once it closed */
    public function watch(): void
    {
        $path = $this->closed ? '' : $this->path;
        $old = $this->watched;
        $this->watched = $path;
        if ($path !== $old) {
            Watches::move($old, $path);
        }
    }

    /** @internal compares the document's file with what it last read or wrote */
    public function checkDisk(): void
    {
        if ($this->path === '' || $this->closed) {
            return;
        }
        $path = $this->path;
        $now = Files::stamp($path);
        if ($now === null && !$this->deleted) {
            usleep(100_000); // network shares report files gone that are not (VS Code)
            $now = Files::stamp($path);
        }
        if ($now === null) {
            $changed = !$this->deleted;
            $this->deleted = true;
            if ($changed) {
                $this->notify();
            }
        } elseif (Files::sameStamp($now, $this->stamp) && !$this->deleted) {
            $readOnly = !Files::writable($path);
            if ($this->readOnly !== $readOnly) {
                $this->readOnly = $readOnly;
                $this->notify();
            }
        } elseif (!$this->edited) {
            try {
                $this->reload($path);
            } catch (\Throwable $e) {
                Logger::warn("cannot read $path again: {$e->getMessage()}", 'documents');
            }
        } else {
            $changed = !$this->conflict || $this->deleted;
            [$this->conflict, $this->deleted] = [true, false];
            if ($changed) {
                $this->notify();
            }
        }
    }

    /** @internal the identity of the file as the document last read or wrote it (Files::stamp), or null @return ?array{0: int, 1: int, 2: int} */
    public function fileIdentity(): ?array
    {
        return $this->stamp[2] ?? null;
    }

    /** @internal follows the document's file, which another app renamed to $path */
    public function moved(string $path): void
    {
        $this->path = $path;
        Registry::addRecent($path);
        $this->watch();
        $this->notify();
    }
}
