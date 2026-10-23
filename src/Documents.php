<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\Docs\{Files, Frame, Host, Recovery, Registry, Untitled, WindowFrame};
use RuntimeException;

/**
 * Opens, creates and keeps track of the documents of one kind, and their
 * windows, as AppKit's NSDocumentController and SwiftUI's DocumentGroup do
 * (mygo's Documents). Create it before App::run: when the app starts it
 * opens the files it was started with, the documents a crash or a quit
 * left unsaved, or else a new untitled document. An app may have several
 * kinds of documents; the first is the one the menu role 'new' creates.
 *
 * Documents give an app the File menu's commands (the roles new, open,
 * openRecent, save, saveAs, duplicate, rename, moveTo and revert, which the
 * fileMenu role lists), autosave, a recovery copy of every unsaved change,
 * the documents opened from the Finder (App::onOpenFile), those changed
 * by other apps, and ask to save before closing and quitting where they
 * must. See docs/documents.md.
 */
final class Documents
{
    /** @internal */
    public readonly Recovery $recovery;
    /** @var list<Document> the open documents, in the order they opened */
    private array $docs = [];

    public function __construct(public readonly DocumentsOptions $opts)
    {
        if ($opts->types === [] || ($opts->types[0]->extensions ?? []) === []) {
            throw new \InvalidArgumentException('myphp: Documents needs a type with an extension');
        }
        if ($opts->read === null || $opts->write === null) {
            throw new \InvalidArgumentException('myphp: Documents needs read and write');
        }
        if ($opts->window === null) {
            throw new \InvalidArgumentException('myphp: Documents needs window');
        }
        foreach ($opts->types as $t) {
            foreach ($t->extensions as $e) {
                if ($e === '' || strpbrk($e, './\\') !== false) {
                    throw new \InvalidArgumentException("myphp: Documents: invalid extension \"$e\"");
                }
            }
        }
        $this->recovery = new Recovery($this);
        Registry::register($this);
    }

    /** @internal how long after the last edit a document is saved in place */
    public function autosaveDelay(): float
    {
        return $this->opts->autosaveDelay > 0 ? $this->opts->autosaveDelay : 5.0;
    }

    /** Creates an untitled document with the content of DocumentsOptions::$new and opens its window. */
    public function new(): Document
    {
        $used = [];
        foreach ($this->docs as $d) {
            if ($d->path() === '') {
                $used[$d->untitled] = true;
            }
        }
        for ($n = 1; isset($used[$n]); $n++);
        $d = new Document($this, $this->opts->new === null ? null : ($this->opts->new)(), Untitled::name($n));
        $d->untitled = $n;
        $d->id = bin2hex(random_bytes(8));
        $this->show($d);
        return $d;
    }

    /** Opens the document at $path in its window, or focuses the window of the document if it is open already. Throws why it can't read it. */
    public function open(string $path): Document
    {
        $abs = str_starts_with($path, '/') ? $path : getcwd() . "/$path";
        if (($d = Registry::openAt($abs)) !== null) {
            $d->focus();
            return $d;
        }
        [$value, $stamp] = $this->readFile($abs);
        $d = new Document($this, $value, '');
        $d->id = bin2hex(random_bytes(8));
        $d->opened($abs, $stamp);
        $this->show($d);
        Registry::addRecent($abs);
        return $d;
    }

    /** Asks which documents to open, and opens them. @return list<Document> */
    public function openDialog(): array
    {
        $docs = [];
        foreach (Host::open(new OpenDialogOptions(parent: Window::focused(), filters: $this->filters(), multiple: true)) as $p) {
            $docs[] = $this->open($p);
        }
        return $docs;
    }

    /** The open documents, in the order they opened. @return list<Document> */
    public function documents(): array
    {
        return $this->docs;
    }

    /** The document $w shows, or null. */
    public function forWindow(?Window $w): ?Document
    {
        $d = $w === null ? null : Registry::documentOf(Registry::frameOf($w));
        return $d !== null && in_array($d, $this->docs, true) ? $d : null;
    }

    /** The service that gives pages their documents (mygo's Service): bind it with Ipc::bind($docs->service(), 'Note'). */
    public function service(): DocumentService
    {
        return new DocumentService($this);
    }

    /** @internal the dialogs' filters of the kind's types @return list<FileFilter> */
    public function filters(): array
    {
        return array_map(fn(DocumentType $t): FileFilter => new FileFilter($t->name, $t->extensions), $this->opts->types);
    }

    /** @internal whether the kind opens $path, by its extension */
    public function handles(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        foreach ($this->opts->types as $t) {
            foreach ($t->extensions as $e) {
                if (strtolower($e) === $ext) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @internal the content of the file at $path, and its stamp @return array{0: mixed, 1: array{0: int, 1: int}} */
    public function readFile(string $path): array
    {
        if (is_dir($path)) {
            throw new RuntimeException("myphp: $path is a directory");
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("myphp: cannot read $path: " . (error_get_last()['message'] ?? 'failed'));
        }
        $stamp = Files::stamp($path) ?? throw new RuntimeException("myphp: $path is gone");
        return [($this->opts->read)($bytes), $stamp];
    }

    /** @internal registers $d and opens its window */
    public function show(Document $d): void
    {
        $this->docs[] = $d;
        $w = ($this->opts->window)($d);
        $frame = match (true) {
            $w instanceof Window => new WindowFrame($w),
            $w instanceof Frame => $w, // a test's
            default => throw new RuntimeException('myphp: DocumentsOptions::$window returned no window'),
        };
        Registry::attach($d, $frame, $w instanceof Window ? $w : null);
        $d->attach($frame);
        $d->schedule();
        $d->watch();
        $d->notify();
    }

    /** @internal $d closed with its window $f */
    public function closed(Document $d, ?Frame $f): void
    {
        Registry::detach($f);
        $this->docs = array_values(array_filter($this->docs, fn(Document $o): bool => $o !== $d));
    }

    /** @internal opens the documents of the sessions no instance holds, edited, and keeps their copies in this session; returns how many */
    public function restore(): int
    {
        [$orphans, $done] = $this->recovery->claim();
        $n = 0;
        foreach ($orphans as [$e, $file]) {
            $path = (string)($e['path'] ?? '');
            if ($path !== '' && Registry::openAt($path) !== null) {
                continue; // a file the app was started with, say
            }
            $bytes = @file_get_contents($file);
            if ($bytes === false) {
                continue;
            }
            try {
                $value = ($this->opts->read)($bytes);
            } catch (\Throwable $err) {
                error_log("myphp: documents: cannot read the recovery copy of " . ($e['name'] ?? $path) . ": {$err->getMessage()}");
                continue;
            }
            $d = new Document($this, $value, (string)($e['name'] ?? ''));
            $d->id = (string)$e['id'];
            $d->recovered($path, [(int)($e['stamp']['modTime'] ?? 0), (int)($e['stamp']['size'] ?? 0)]);
            $this->show($d);
            $n++;
        }
        $done();
        return $n;
    }
}
