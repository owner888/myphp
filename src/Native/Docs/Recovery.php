<?php
declare(strict_types=1);

namespace MyPHP\Native\Docs;

use MyPHP\{Document, Documents};

/**
 * The recovery copies of a kind of documents (mygo's document_recovery.go):
 * what a document's unsaved changes are, written a second after the user
 * stops editing into the app's data, as VS Code's backups and
 * gnome-text-editor's drafts keep them, so that a crash, a forced quit or
 * a quit that keeps documents (DocumentsOptions::$askOnQuit unset) loses
 * nothing. The next launch opens them again, edited, and a file that
 * changed meanwhile is in conflict, as its stamp says.
 *
 * Each kind keeps them in "Recovery/<kind>" of the user data directory, in
 * a session of the running instance, which a locked file tells is alive:
 * another instance of the app leaves it alone, and the next launch takes
 * the sessions no instance holds.
 *
 * @internal
 */
final class Recovery
{
    /** The session, '' until the first copy, and its lock. */
    private string $dir = '';
    /** @var ?resource */
    private $lock = null;
    /** @var array<string, array{id: string, path?: string, name?: string, stamp: array{modTime: int, size: int}}> the entries by id */
    private array $entries = [];

    public function __construct(private readonly Documents $kind) {}

    /** The directory of the kind's sessions. */
    public function root(): string
    {
        $t = $this->kind->opts->types[0];
        return Host::dataDir() . '/Recovery/' . preg_replace('/[^A-Za-z0-9-]/', '-', $t->name . '-' . $t->extensions[0]);
    }

    /** The directory of this instance's session, which it creates and locks the first time. */
    private function session(): string
    {
        if ($this->dir !== '') {
            return $this->dir;
        }
        $dir = $this->root() . '/' . bin2hex(random_bytes(6));
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new \RuntimeException("myphp: cannot create $dir");
        }
        $lock = @fopen("$dir/lock", 'c+') ?: throw new \RuntimeException("myphp: cannot create $dir/lock");
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException("myphp: cannot lock $dir/lock");
        }
        [$this->dir, $this->lock] = [$dir, $lock];
        return $dir;
    }

    /** Writes the copy of a document and its entry. @param array{id: string, path?: string, name?: string, stamp: array{modTime: int, size: int}} $e */
    public function save(array $e, string $bytes): void
    {
        $dir = $this->session();
        Files::writeAppFile("$dir/{$e['id']}", $bytes);
        $this->entries[$e['id']] = $e;
        $this->writeIndex();
    }

    /** Forgets the copy of $d. */
    public function remove(Document $d): void
    {
        $id = $d->recoveryForgotten();
        if (!isset($this->entries[$id])) {
            return;
        }
        unset($this->entries[$id]);
        try {
            $this->writeIndex();
        } catch (\RuntimeException $e) {
            error_log("myphp: documents: {$e->getMessage()}");
        }
        @unlink("{$this->dir}/$id");
    }

    private function writeIndex(): void
    {
        Files::writeAppFile("{$this->dir}/index.json", json_encode(array_values($this->entries), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Removes the session when it keeps no copy, as the app quits. */
    public function close(): void
    {
        if ($this->lock === null) {
            return;
        }
        fclose($this->lock);
        $this->lock = null;
        if ($this->entries === []) {
            self::removeAll($this->dir);
        }
        $this->dir = '';
        $this->entries = [];
    }

    /**
     * Takes the sessions that no instance holds: those of an instance that
     * crashed or quit keeping documents. Returns their copies, [entry,
     * file] each, and a function removing the sessions, once the
     * documents are restored.
     * @return array{0: list<array{0: array<string, mixed>, 1: string}>, 1: \Closure(): void}
     */
    public function claim(): array
    {
        $root = $this->root();
        $orphans = $claimed = [];
        foreach (is_dir($root) ? (scandir($root) ?: []) : [] as $name) {
            $dir = "$root/$name";
            if ($name === '.' || $name === '..' || !is_dir($dir) || $dir === $this->dir) {
                continue;
            }
            $lock = @fopen("$dir/lock", 'c+');
            if ($lock === false) {
                continue;
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock); // alive
                continue;
            }
            $claimed[] = [$dir, $lock];
            $json = @file_get_contents("$dir/index.json");
            $entries = $json === false ? [] : json_decode($json, true);
            if (!is_array($entries)) {
                error_log("myphp: documents: $dir: its index is not JSON");
                $entries = [];
            }
            foreach ($entries as $e) {
                $id = is_array($e) ? (string)($e['id'] ?? '') : '';
                if ($id !== '' && strpbrk($id, '/\\.') === false) {
                    $orphans[] = [$e, "$dir/$id"];
                }
            }
        }
        return [$orphans, function () use ($claimed): void {
            foreach ($claimed as [$dir, $lock]) {
                fclose($lock);
                self::removeAll($dir);
            }
        }];
    }

    private static function removeAll(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                @unlink("$dir/$name");
            }
        }
        @rmdir($dir);
    }
}
