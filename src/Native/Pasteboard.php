<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\Transfer\{Data, Format, Item, Representation};

/**
 * The general pasteboard: plain text, and data in several formats
 * (mygo's darwin clipboard.go WriteData), each item an NSPasteboardItem
 * whose formats a provider gives when an app pastes one.
 */
final class Pasteboard
{
    private const STRING_TYPE = 'public.utf8-plain-text';
    private const URL = 'public.url', FILE_URL = 'public.file-url', FILE_LIST = 'dev.mygo.file-list';

    private static bool $defined = false;
    /** The data on the pasteboard that providers give, while it is: its provider object, the pasteboard's change count after writing it, its items by their pasteboard item's address, and what runs once it is let go (mygo's macClipboardSource). @var ?array{obj: mixed, change: int, items: array<int, Item>, data: Data, onRelease: ?\Closure} */
    private static ?array $source = null;
    /** A provider is giving its data now: it must not read or replace the clipboard. */
    public static bool $providing = false;

    public static function text(): string
    {
        $pb = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        $s = ObjC::msg('p:p', $pb, 'stringForType:', ObjC::str(self::STRING_TYPE));
        return $s === null ? '' : ObjC::phpStr($s);
    }

    /** The string of the pasteboard's $type ('public.html'…), '' without one. */
    public static function string(string $type): string
    {
        $pb = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        $s = ObjC::msg('p:p', $pb, 'stringForType:', ObjC::str($type));
        return $s === null ? '' : ObjC::phpStr($s);
    }

    /** The image on the pasteboard as PNG, a TIFF converted, or '' (mygo's ReadImage). */
    public static function image(): string
    {
        $pb = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        if (($d = ObjC::msg('p:p', $pb, 'dataForType:', ObjC::str('public.png'))) !== null) {
            return Drag::bytes($d);
        }
        if (($d = ObjC::msg('p:p', $pb, 'dataForType:', ObjC::str('public.tiff'))) !== null
            && ($rep = ObjC::msg('p:p', ObjC::cls('NSBitmapImageRep'), 'imageRepWithData:', $d)) !== null
            && ($png = ObjC::msg('p:Lp', $rep, 'representationUsingType:properties:', 4, ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary'))) !== null) { // NSBitmapImageFileTypePNG
            return Drag::bytes($png);
        }
        return '';
    }

    /** Whether $bytes are an image AppKit reads. */
    public static function isImage(string $bytes): bool
    {
        $img = ObjC::msg('p:p', ObjC::alloc('NSImage'), 'initWithData:', ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $bytes, strlen($bytes)));
        if ($img === null) {
            return false;
        }
        ObjC::msg('v:', $img, 'release');
        return true;
    }

    /** Empties the pasteboard, letting go of the app's providers (mygo's Clear). */
    public static function clear(): void
    {
        ObjC::msg('l:', ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard'), 'clearContents');
        self::release();
    }

    /** The pasteboard's types, as the system names them (mygo's AvailableFormats). @return list<string> */
    public static function systemTypes(): array
    {
        $types = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard'), 'types');
        return $types === null ? [] : array_map(ObjC::phpStr(...), Drag::array($types));
    }

    public static function setText(string $text): void
    {
        $pb = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        ObjC::msg('l:', $pb, 'clearContents');
        self::release();
        ObjC::msg('b:pp', $pb, 'setString:forType:', ObjC::str($text), ObjC::str(self::STRING_TYPE));
    }

    /** Puts $data on the pasteboard, each representation of its items in the system's types, given when asked (mygo's WriteData); data of no formats clears it. $onRelease runs once its providers are no longer needed. */
    public static function writeData(Data $data, ?\Closure $onRelease = null): void
    {
        self::define();
        $board = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        if ($data->formats() === []) {
            ObjC::msg('l:', $board, 'clearContents');
            self::release();
            if ($onRelease !== null) {
                self::later($onRelease);
            }
            return;
        }
        $obj = ObjC::msg('p:', ObjC::alloc('MyPHPClipboardProvider'), 'init');
        $items = []; $byItem = [];
        $array = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        foreach ($data->items as $item) {
            $pb = ObjC::msg('p:', ObjC::msg('p:', ObjC::alloc('NSPasteboardItem'), 'init'), 'autorelease');
            $byItem[ObjC::addr($pb)] = $item;
            $types = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
            foreach (self::types($item) as $t) {
                ObjC::msg('v:p', $types, 'addObject:', ObjC::str($t));
            }
            ObjC::msg('b:pp', $pb, 'setDataProvider:forTypes:', $obj, $types);
            ObjC::msg('v:p', $array, 'addObject:', $pb);
            $items[] = $pb;
        }
        ObjC::msg('l:', $board, 'clearContents');
        self::release();
        self::$source = ['obj' => $obj, 'change' => 0, 'items' => $byItem, 'data' => $data, 'onRelease' => $onRelease];
        if (!ObjC::msg('b:p', $board, 'writeObjects:', $array)) {
            self::$source['onRelease'] = null; // a failed write does not release
            self::release();
            throw new \RuntimeException('myphp: cannot write pasteboard items');
        }
        self::$source['change'] = ObjC::msg('l:', $board, 'changeCount');
    }

    /**
     * The representations of $formats on the pasteboard, of each of its
     * items, read in the system's types (mygo's ReadData): data of no items
     * when it holds none of them.
     */
    public static function readData(string ...$formats): Data
    {
        $board = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        $items = [];
        foreach (Drag::array(ObjC::msg('p:', $board, 'pasteboardItems')) as $pb) {
            $reps = [];
            foreach ($formats as $f) {
                $d = ObjC::msg('p:p', $pb, 'dataForType:', ObjC::str(Drag::macType($f)));
                if ($d !== null) { $reps[] = Representation::bytes($f, Drag::bytes($d)); }
            }
            if ($reps !== []) { $items[] = new Item(...$reps); }
        }
        return new Data(...$items);
    }

    /** The formats on the pasteboard, without reading them (mygo's clipboard Formats): those of the data written last while it is there, else those its types name. @return list<string> */
    public static function formats(): array
    {
        $board = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        if (self::$source !== null && self::$source['change'] === ObjC::msg('l:', $board, 'changeCount')) {
            $out = [];
            foreach (self::$source['items'] as $item) {
                foreach ($item->formats() as $f) {
                    if (!in_array($f, $out, true)) { $out[] = $f; }
                }
            }
            return $out;
        }
        $out = [];
        $types = ObjC::msg('p:', $board, 'types');
        foreach ($types === null ? [] : Drag::array($types) as $typ) {
            $f = self::format(ObjC::phpStr($typ));
            if ($f === '' || $f === Format::DRAG_SESSION || strpbrk($f, "\0\r\n") !== false || in_array($f, $out, true)) {
                continue;
            }
            $out[] = $f;
            if ($f === Format::FILE_LIST && !in_array(Format::URI_LIST, $out, true)) { $out[] = Format::URI_LIST; }
        }
        return $out;
    }

    /** The system's types of an item's formats (mygo's macClipboardTypes). @return list<string> */
    private static function types(Item $item): array
    {
        $types = [];
        foreach ($item->formats() as $f) {
            $types[] = Drag::macType($f);
            if ($f === Format::URI_LIST) { $types[] = self::URL; }
            if ($f === Format::FILE_LIST) { $types[] = self::FILE_LIST; }
        }
        return $types;
    }

    /** The format of a system type the pasteboard asks for (mygo's macClipboardFormat). */
    private static function format(string $type): string
    {
        if ($type === 'public.utf16-plain-text' || $type === 'public.utf16-external-plain-text') {
            return Format::TEXT;
        }
        if ($type === self::FILE_LIST || $type === 'NSFilenamesPboardType') {
            return Format::FILE_LIST;
        }
        return Drag::macFormat($type);
    }

    /** Lets go of the data written last: its provider gives nothing more, and its onRelease runs after the callback it may be in (mygo's release). */
    private static function release(): void
    {
        if (self::$source === null) {
            return;
        }
        ObjC::msg('p:', self::$source['obj'], 'autorelease'); // AppKit may still be in a callback on it; its own references are its own
        $fn = self::$source['onRelease'];
        self::$source = null;
        if ($fn !== null) {
            self::later($fn);
        }
    }

    /** Runs $fn after the native callback returns, on the run loop; at once when the app no longer runs one. */
    private static function later(\Closure $fn): void
    {
        \MyPHP\App::running() ? \MyPHP\App::later($fn) : $fn();
    }

    /**
     * Gives the pasteboard every representation of the data written last,
     * so that it stays once the app quits, and lets go of its providers
     * (mygo's Flush); it does nothing to another app's data. A provider's
     * error leaves it as it was.
     */
    public static function flush(): void
    {
        $board = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        $s = self::$source;
        if ($s === null || $s['change'] !== ObjC::msg('l:', $board, 'changeCount')) {
            return;
        }
        self::$providing = true;
        try {
            $d = $s['data']->materialize($s['data']->formats());
        } finally {
            self::$providing = false;
        }
        if (self::$source !== $s) {
            throw new \MyPHP\ClipboardChanged('myphp: the clipboard changed while it was flushed');
        }
        $array = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        foreach ($d->items as $item) {
            $pb = ObjC::msg('p:', ObjC::msg('p:', ObjC::alloc('NSPasteboardItem'), 'init'), 'autorelease');
            foreach (self::types($item) as $name) {
                $b = $item->read(self::format($name));
                if ($name === self::URL || $name === self::FILE_URL) {
                    $b = trim(explode("\n", $b)[0]);
                }
                ObjC::msg('b:pp', $pb, 'setData:forType:', ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $b, strlen($b)), ObjC::str($name));
            }
            ObjC::msg('v:p', $array, 'addObject:', $pb);
        }
        ObjC::msg('l:', $board, 'clearContents');
        $ok = ObjC::msg('b:p', $board, 'writeObjects:', $array);
        self::release();
        if (!$ok) {
            throw new \RuntimeException('myphp: cannot persist pasteboard items');
        }
    }

    private static function define(): void
    {
        if (self::$defined) {
            return;
        }
        self::$defined = true;
        ObjC::defineClass('MyPHPClipboardProvider', 'NSObject', [
            'pasteboard:item:provideDataForType:' => ['v@:@@@', 'v(ppppp)', function ($self, $cmd, $board, $item, $type) {
                $s = self::$source;
                if ($s === null || ObjC::addr($s['obj']) !== ObjC::addr($self) || !isset($s['items'][ObjC::addr($item)])) {
                    return;
                }
                $name = ObjC::phpStr($type);
                self::$providing = true;
                try {
                    $b = $s['items'][ObjC::addr($item)]->read(self::format($name));
                } catch (\Throwable) {
                    return;
                } finally {
                    self::$providing = false;
                }
                if ($name === self::URL || $name === self::FILE_URL) {
                    $b = trim(explode("\n", $b)[0]); // one URL
                }
                ObjC::msg('b:pp', $item, 'setData:forType:', ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $b, strlen($b)), $type);
            }],
            'pasteboardFinishedWithDataProvider:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $board) {
                if (self::$source !== null && ObjC::addr(self::$source['obj']) === ObjC::addr($self)) {
                    self::release();
                }
            }],
        ], ['NSPasteboardItemDataProvider']);
    }
}
