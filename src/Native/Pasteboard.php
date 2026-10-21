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
    /** The data on the pasteboard that providers give, while it is: its provider object, the pasteboard's change count after writing it, and its items by their pasteboard item's address (mygo's macClipboardSource). @var ?array{obj: mixed, change: int, items: array<int, Item>} */
    private static ?array $source = null;

    public static function text(): string
    {
        $pb = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        $s = ObjC::msg('p:p', $pb, 'stringForType:', ObjC::str(self::STRING_TYPE));
        return $s === null ? '' : ObjC::phpStr($s);
    }

    public static function setText(string $text): void
    {
        $pb = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        ObjC::msg('l:', $pb, 'clearContents');
        self::release();
        ObjC::msg('b:pp', $pb, 'setString:forType:', ObjC::str($text), ObjC::str(self::STRING_TYPE));
    }

    /** Puts $data on the pasteboard, each representation of its items in the system's types, given when asked (mygo's WriteData); data of no formats clears it. */
    public static function writeData(Data $data): void
    {
        self::define();
        $board = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        if ($data->formats() === []) {
            ObjC::msg('l:', $board, 'clearContents');
            self::release();
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
        self::$source = ['obj' => $obj, 'change' => 0, 'items' => $byItem];
        if (!ObjC::msg('b:p', $board, 'writeObjects:', $array)) {
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

    /** Lets go of the data written last: its provider gives nothing more (mygo's release). */
    private static function release(): void
    {
        if (self::$source === null) {
            return;
        }
        ObjC::msg('p:', self::$source['obj'], 'autorelease'); // AppKit may still be in a callback on it; its own references are its own
        self::$source = null;
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
                try {
                    $b = $s['items'][ObjC::addr($item)]->read(self::format($name));
                } catch (\Throwable) {
                    return;
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
