<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use FFI\CData;
use MyPHP\Logger;
use MyPHP\Platform;
use MyPHP\Transfer\{Data, Format, FormatUnavailable, Item, Representation};

/**
 * GTK's clipboard (CLIPBOARD, mygo's linux clipboard.go): the app's data
 * is offered by format, its targets the MIME types (text as UTF8_STRING
 * too, files as a URI list), and read only as an app pastes; the data of
 * other apps is read by target, an image of any type as PNG.
 */
final class Clipboard implements Platform\Clipboard
{
    /** @var array<int, array{0: Data, 1: ?Closure}> the data the app put on the clipboard, and what runs once it is let go, by key */
    private array $sources = [];
    /** The key of the data the app has on the clipboard now, 0 for another app's. */
    private int $current = 0;
    private int $nextKey = 0;
    /** Counts the clipboard's changes of owner: a read that sees it change gives up. */
    private int $generation = 0;
    private ?CData $board = null;
    private CData $onGet, $onClear;

    public function __construct()
    {
        $f = Gtk::ffi();
        $this->onGet = $f->new('HGet', false);
        $this->onGet->fn = function ($board, $sel, $info, $data): void {
            [$d] = $this->sources[Gtk::addr($data)] ?? [null];
            if ($d === null) {
                return;
            }
            $f = Gtk::ffi();
            $atom = $f->gtk_selection_data_get_target($sel);
            $format = self::format(self::atomName($atom));
            try {
                $b = $d->read($format);
            } catch (FormatUnavailable) {
                if ($format !== Format::URI_LIST) {
                    return;
                }
                $b = $d->read(Format::FILE_LIST);
            }
            $f->gtk_selection_data_set($sel, $atom, 8, $b, strlen($b));
        };
        $this->onClear = $f->new('HWidget', false);
        $this->onClear->fn = function ($board, $data): void {
            $key = Gtk::addr($data);
            $released = $this->sources[$key][1] ?? null;
            unset($this->sources[$key]);
            if ($this->current === $key) { $this->current = 0; }
            $released?->__invoke();
        };
    }

    private function board(): CData
    {
        if ($this->board === null) {
            $f = Gtk::ffi();
            $this->board = $f->gtk_clipboard_get($f->gdk_atom_intern('CLIPBOARD', 0));
            Gtk::connect($this->board, 'owner-change', 'event', function ($board, $event, $data): int {
                $this->generation++;
                return 0;
            });
        }
        return $this->board;
    }

    /**
     * GTK's targets of $formats (mygo's gtkTargets): text as UTF8_STRING
     * too, files as a URI list; the entries and their number, their names
     * held by the entries' array.
     * @param list<string> $formats @return array{0: CData, 1: int}
     */
    public static function entries(array $formats): array
    {
        $f = Gtk::ffi();
        $names = [];
        foreach ($formats as $format) {
            $names[] = $format === Format::FILE_LIST ? Format::URI_LIST : $format;
            if ($format === Format::TEXT) { array_push($names, 'UTF8_STRING', 'text/plain;charset=utf-8'); }
        }
        $names = array_values(array_unique($names));
        $size = 0;
        foreach ($names as $name) { $size += strlen($name) + 1; }
        $block = $f->new('struct { GtkTargetEntry e[' . max(1, count($names)) . ']; char s[' . max(1, $size) . ']; }'); // the names in the entries' own memory
        $at = 0;
        foreach ($names as $i => $name) {
            \FFI::memcpy(\FFI::addr($block->s[$at]), $name . "\0", strlen($name) + 1);
            $block->e[$i]->target = \FFI::addr($block->s[$at]);
            $block->e[$i]->info = $i;
            $at += strlen($name) + 1;
        }
        self::$held = $block;
        return [$block->e, count($names)];
    }

    /** The last targets made, held while GTK copies them. */
    private static ?CData $held = null;

    /** The transfer format of a GTK target (mygo's gtkFormat). */
    public static function format(string $target): string
    {
        return $target === 'UTF8_STRING' || $target === 'text/plain;charset=utf-8' ? Format::TEXT : $target;
    }

    public static function atomName(CData $atom): string
    {
        $f = Gtk::ffi();
        $p = $f->gdk_atom_name($atom);
        if ($p === null) {
            return '';
        }
        $s = \FFI::string($p);
        $f->g_free($p);
        return $s;
    }

    public function writeData(Data $data, ?Closure $released): void
    {
        if ($data->formats() === []) {
            $this->clear();
            $released?->__invoke();
            return;
        }
        $f = Gtk::ffi();
        [$targets, $n] = self::entries($data->formats());
        $key = ++$this->nextKey;
        $this->sources[$key] = [$data, $released];
        if (!$f->gtk_clipboard_set_with_data($this->board(), $targets, $n, $this->onGet->fn, $this->onClear->fn, $f->cast('void*', $key))) {
            unset($this->sources[$key]);
            throw new \RuntimeException('myphp: cannot own the clipboard selection');
        }
        $this->current = $key;
        $f->gtk_clipboard_set_can_store($this->board(), null, 0); // a clipboard manager keeps every target as the app quits
    }

    /**
     * The clipboard's targets as transfer formats, and the atom to read
     * each by (mygo's clipboardTargets): a URI list is a file list too,
     * an image of any type a PNG.
     * @return array{0: list<string>, 1: array<string, CData>}
     */
    private function targets(): array
    {
        $f = Gtk::ffi();
        $atoms = $f->new('void**[1]'); $n = $f->new('int[1]');
        if (!$f->gtk_clipboard_wait_for_targets($this->board(), $atoms, $n) || $atoms[0] === null) {
            return [[], []];
        }
        $out = []; $by = [];
        try {
            for ($i = 0; $i < min($n[0], 4096); $i++) {
                $atom = $atoms[0][$i];
                $name = self::atomName($atom);
                if ($name === '' || strpbrk($name, "\0\r\n") !== false || in_array($name, ['TARGETS', 'MULTIPLE', 'TIMESTAMP', 'SAVE_TARGETS'], true)) {
                    continue;
                }
                $format = self::format($name);
                if ($format === Format::DRAG_SESSION) {
                    continue;
                }
                if (!in_array($format, $out, true)) { $out[] = $format; }
                if (!isset($by[$format]) || $name === $format) { $by[$format] = $atom; } // the MIME type over its aliases
                if ($format === Format::URI_LIST && !isset($by[Format::FILE_LIST])) {
                    $by[Format::FILE_LIST] = $atom;
                    if (!in_array(Format::FILE_LIST, $out, true)) { $out[] = Format::FILE_LIST; }
                }
                if (str_starts_with($name, 'image/') && !in_array(Format::PNG, $out, true)) { $out[] = Format::PNG; }
            }
        } finally {
            $f->g_free($atoms[0]);
        }
        return [$out, $by];
    }

    public function formats(): array
    {
        if ($this->current !== 0) {
            return $this->sources[$this->current][0]->formats();
        }
        return $this->targets()[0];
    }

    public function readData(array $formats): Data
    {
        $generation = $this->generation;
        [$offered, $by] = $this->targets();
        $changed = fn(): bool => $generation !== $this->generation;
        if ($changed()) {
            throw new \RuntimeException('myphp: the clipboard changed while it was read');
        }
        if ($this->current !== 0) { // the app's own: its data, without asking GTK
            $d = $this->sources[$this->current][0];
            return $d->materialize($formats === [] ? $d->formats() : $formats);
        }
        $formats = $formats === [] ? $offered : $formats;
        if ($formats === []) {
            return new Data();
        }
        $f = Gtk::ffi();
        $reps = [];
        foreach (array_unique($formats) as $format) {
            if (!in_array($format, $offered, true)) {
                continue;
            }
            if ($format === Format::PNG && !isset($by[$format])) {
                $b = $this->readImage();
                if ($b === '') {
                    throw new \RuntimeException('myphp: cannot convert the clipboard\'s image');
                }
            } else {
                $sd = $f->gtk_clipboard_wait_for_contents($this->board(), $by[$format]);
                if ($sd === null) {
                    throw new FormatUnavailable;
                }
                $length = $f->gtk_selection_data_get_length($sd);
                $p = $f->gtk_selection_data_get_data($sd);
                if ($length < 0 || $length > 64 << 20 || ($length !== 0 && $p === null)) {
                    $f->gtk_selection_data_free($sd);
                    throw new \RuntimeException('myphp: the clipboard\'s representation is invalid or too large');
                }
                $b = $length === 0 ? '' : \FFI::string($p, $length);
                $f->gtk_selection_data_free($sd);
            }
            if ($changed()) {
                throw new \RuntimeException('myphp: the clipboard changed while it was read');
            }
            $reps[] = Representation::bytes($format, $b);
        }
        if ($reps === []) {
            throw new FormatUnavailable;
        }
        return new Data(new Item(...$reps));
    }

    /** Hands a clipboard manager every representation of the app's data, which then stays as the app quits, and lets go of its providers (mygo's Flush). */
    public function flush(): void
    {
        if ($this->current === 0) {
            return;
        }
        $key = $this->current;
        [$d, $released] = $this->sources[$key];
        $this->sources[$key] = [$d->materialize($d->formats()), null]; // GTK still serves these bytes without a clipboard manager
        $released?->__invoke();
        $f = Gtk::ffi();
        if (!$f->gdk_display_supports_clipboard_persistence($f->gdk_display_get_default())) {
            Logger::warn('no clipboard manager keeps the clipboard as the app quits', 'linux');
            return;
        }
        $f->gtk_clipboard_store($this->board());
    }

    public function readText(): string
    {
        $f = Gtk::ffi();
        $p = $f->gtk_clipboard_wait_for_text($this->board());
        if ($p === null) {
            return '';
        }
        $s = \FFI::string($p);
        $f->g_free($p);
        return $s;
    }

    public function writeText(string $text): void
    {
        $this->writeData(Data::text($text), null);
    }

    public function readHTML(): string
    {
        $f = Gtk::ffi();
        $sd = $f->gtk_clipboard_wait_for_contents($this->board(), $f->gdk_atom_intern('text/html', 0));
        if ($sd === null) {
            return '';
        }
        $n = $f->gtk_selection_data_get_length($sd);
        $p = $f->gtk_selection_data_get_data($sd);
        $html = $n > 0 && $p !== null ? \FFI::string($p, $n) : '';
        $f->gtk_selection_data_free($sd);
        return $html;
    }

    /** The clipboard's image, of any type GDK reads, as PNG. */
    public function readImage(): string
    {
        $f = Gtk::ffi();
        $pix = $f->gtk_clipboard_wait_for_image($this->board());
        if ($pix === null) {
            return '';
        }
        try {
            return self::png($pix);
        } finally {
            $f->g_object_unref($pix);
        }
    }

    /** A GdkPixbuf as PNG, '' when GDK cannot write it (mygo's pngFromPixbuf). */
    public static function png(CData $pix): string
    {
        $f = Gtk::ffi();
        $buf = $f->new('char*[1]'); $size = $f->new('size_t[1]');
        if (!$f->gdk_pixbuf_save_to_bufferv($pix, $buf, $size, 'png', null, null, null)) {
            return '';
        }
        $png = \FFI::string($buf[0], $size[0]);
        $f->g_free($buf[0]);
        return $png;
    }

    public function isImage(string $bytes): bool
    {
        $f = Gtk::ffi();
        $loader = $f->gdk_pixbuf_loader_new();
        $ok = $bytes !== '' && $f->gdk_pixbuf_loader_write($loader, $bytes, strlen($bytes), null);
        $ok = $f->gdk_pixbuf_loader_close($loader, null) && $ok && $f->gdk_pixbuf_loader_get_pixbuf($loader) !== null;
        $f->g_object_unref($loader);
        return $ok;
    }

    public function clear(): void
    {
        Gtk::ffi()->gtk_clipboard_clear($this->board());
    }

    public function availableFormats(): array
    {
        $f = Gtk::ffi();
        $atoms = $f->new('void**[1]'); $n = $f->new('int[1]');
        if (!$f->gtk_clipboard_wait_for_targets($this->board(), $atoms, $n) || $atoms[0] === null) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $n[0]; $i++) { $out[] = self::atomName($atoms[0][$i]); }
        $f->g_free($atoms[0]);
        return $out;
    }
}
