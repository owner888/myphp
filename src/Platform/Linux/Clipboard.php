<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;
use MyPHP\Transfer\{Data, Item, Representation};

/**
 * GTK's clipboard (CLIPBOARD), its text: the rest of what it holds (HTML,
 * images, the app's own formats) comes with the system's services.
 */
final class Clipboard implements Platform\Clipboard
{
    private const TEXT = 'text/plain';

    private function board()
    {
        $f = Gtk::ffi();
        return $f->gtk_clipboard_get($f->gdk_atom_intern('CLIPBOARD', 0));
    }

    public function readData(array $formats): Data
    {
        if ($formats !== [] && !in_array(self::TEXT, $formats, true)) {
            throw new Unsupported('myphp: the Linux clipboard holds text only yet');
        }
        $text = $this->readText();
        return $text === '' ? new Data() : new Data(new Item(Representation::bytes(self::TEXT, $text)));
    }

    public function writeData(Data $data, ?Closure $released): void
    {
        foreach ($data->items as $item) {
            foreach ($item->reps as $rep) {
                if ($rep->format === self::TEXT) {
                    $this->writeText($rep->read());
                    return;
                }
            }
        }
        throw new Unsupported('myphp: the Linux clipboard holds text only yet');
    }

    public function formats(): array
    {
        return Gtk::ffi()->gtk_clipboard_wait_is_text_available($this->board()) ? [self::TEXT] : [];
    }

    public function flush(): void
    {
        Gtk::ffi()->gtk_clipboard_store($this->board());
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
        Gtk::ffi()->gtk_clipboard_set_text($this->board(), $text, strlen($text));
    }

    public function readHTML(): string
    {
        return '';
    }

    public function readImage(): string
    {
        return '';
    }

    public function isImage(string $bytes): bool
    {
        return false;
    }

    public function clear(): void
    {
        Gtk::ffi()->gtk_clipboard_clear($this->board());
    }

    public function availableFormats(): array
    {
        return $this->formats();
    }
}
