<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use MyPHP\Transfer\{Data, Format, Item, Offer, Operation, Representation, Result};
use MyPHP\UI\DataDragEvent;

/**
 * Native drags of the window's view (mygo's darwin surface_drag.go): as a
 * source, each item of the data an NSPasteboardItem whose formats a
 * provider gives when a destination asks for one, the session's key among
 * them; as a destination, the view's NSDraggingDestination methods, which
 * offer what the pasteboard's types say without reading it, and read the
 * formats the content took once it is dropped.
 */
final class Drag
{
    private const UT_STRING = 'public.utf8-plain-text', UT_HTML = 'public.html', UT_PNG = 'public.png';
    private const FILE_URL = 'public.file-url', URL = 'public.url', URI_LIST = 'dev.mygo.uri-list', MIME = 'dev.mygo.mime.';
    private const OPTION = 1 << 19, COMMAND = 1 << 20; // NSEventModifierFlags

    /** @var array<string, string> the system's types of formats found so far, and back */
    private static array $types = [], $formats = [];
    /**
     * The drags on, by the address of their source object (mygo's
     * macDragSources): the request, the view, its provider objects, its
     * NSDraggingSession, what failed providing, and itself.
     * @var array<int, array{request: array, view: int, providers: list<mixed>, session: mixed, error: ?\Throwable, obj: mixed}>
     */
    private static array $sources = [];
    /** @var array<int, array{0: int, 1: Item}> each provider's source and item, by its address */
    private static array $providers = [];
    /** @var array<int, int> the source of the drag each view has on, by view address */
    private static array $viewDrags = [];
    private static bool $defined = false;
    /** @var ?Closure($view, string $kind, DataDragEvent, float $x, float $y): bool the window's content answering a drag: 'over', 'leave' or 'drop' */
    private static ?Closure $onEvent = null;

    /** The window's content answering drags over its views; Window sets it. */
    public static function handle(Closure $onEvent): void
    {
        self::$onEvent = $onEvent;
    }

    /** The system's type of $format (mygo's macType). */
    public static function macType(string $format): string
    {
        switch ($format) {
            case Format::TEXT: return self::UT_STRING;
            case Format::HTML: return self::UT_HTML;
            case Format::PNG: return self::UT_PNG;
            case Format::FILE_LIST: return self::FILE_URL;
            case Format::URI_LIST: return self::URI_LIST; // public.url holds one URL: the whole list goes in a type of its own, public.url beside it for other apps
        }
        if (isset(self::$types[$format])) {
            return self::$types[$format];
        }
        $t = '';
        $ut = ObjC::msg('p:p', ObjC::cls('UTType'), 'typeWithMIMEType:', ObjC::str($format));
        if ($ut !== null) {
            $t = ObjC::phpStr(ObjC::msg('p:', $ut, 'identifier'));
        }
        if ($t === '') {
            $t = self::MIME . bin2hex($format);
        }
        self::$types[$format] = $t; self::$formats[$t] = $format;
        return $t;
    }

    /** The format of a system type (mygo's macFormat). */
    public static function macFormat(string $type): string
    {
        switch ($type) {
            case self::UT_STRING: case 'public.plain-text': case 'NSStringPboardType': return Format::TEXT;
            case self::UT_HTML: return Format::HTML;
            case self::UT_PNG: return Format::PNG;
            case self::FILE_URL: return Format::FILE_LIST;
            case self::URL: case self::URI_LIST: return Format::URI_LIST;
        }
        if (isset(self::$formats[$type])) {
            return self::$formats[$type];
        }
        if (str_starts_with($type, self::MIME)) {
            $b = @hex2bin(substr($type, strlen(self::MIME)));
            if ($b !== false && $b !== '') {
                return $b;
            }
        }
        $ut = ObjC::msg('p:p', ObjC::cls('UTType'), 'typeWithIdentifier:', ObjC::str($type));
        if ($ut !== null && ($mime = ObjC::msg('p:', $ut, 'preferredMIMEType')) !== null && ($m = ObjC::phpStr($mime)) !== '') {
            return $m;
        }
        return $type;
    }

    /** NSDragOperation's of $o. */
    public static function macOperations(int $o): int
    {
        return (($o & Operation::COPY) !== 0 ? 1 : 0) | (($o & Operation::MOVE) !== 0 ? 16 : 0);
    }

    private static function operations(int $n): int
    {
        return (($n & 1) !== 0 ? Operation::COPY : 0) | (($n & 16) !== 0 ? Operation::MOVE : 0);
    }

    /** Lets $view take drags of $formats (mygo's SetDropFormats). @param list<string> $formats */
    public static function setFormats($view, array $formats): void
    {
        $types = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        foreach ($formats as $f) {
            ObjC::msg('v:p', $types, 'addObject:', ObjC::str(self::macType($f)));
        }
        if (in_array(Format::URI_LIST, $formats, true)) {
            ObjC::msg('v:p', $types, 'addObject:', ObjC::str(self::URL));
        }
        ObjC::msg('v:p', $view, 'registerForDraggedTypes:', $types);
    }

    /**
     * Starts the system's drag from $view (mygo's StartDataDrag), from its
     * last press $lastDown: $r holds data, session, operations, x and y
     * (negative: where the press was), preview (BGRA pixels at one a
     * point, or null for the system's documents icon), width, height,
     * hotX, hotY, and done(Result), told once.
     */
    public static function start($view, $lastDown, array $r): void
    {
        if ($lastDown === null) {
            ($r['done'])(new Result(error: new \RuntimeException('myphp: native drag needs a pointer gesture')));
            return;
        }
        self::define();
        $obj = ObjC::msg('p:', ObjC::alloc('MyPHPDragSource'), 'init');
        $key = ObjC::addr($obj);
        self::$sources[$key] = ['request' => $r, 'view' => ObjC::addr($view), 'providers' => [], 'session' => null, 'error' => null, 'obj' => $obj];
        self::$viewDrags[ObjC::addr($view)] = $key;
        [$width, $height] = [$r['width'], $r['height']];
        if ($r['preview'] !== null) {
            $rep = ObjC::msg('p:pllllbbpLll', ObjC::alloc('NSBitmapImageRep'), 'initWithBitmapDataPlanes:pixelsWide:pixelsHigh:bitsPerSample:samplesPerPixel:hasAlpha:isPlanar:colorSpaceName:bitmapFormat:bytesPerRow:bitsPerPixel:',
                null, $width, $height, 8, 4, true, false, ObjC::str('NSDeviceRGBColorSpace'), 1 | 1 << 9 /* alpha first, 32 bits little endian: BGRA, premultiplied */, $width * 4, 32)
                ?? throw new \RuntimeException('drag image');
            \FFI::memcpy(ObjC::$c->cast('char*', ObjC::msg('p:', $rep, 'bitmapData')), $r['preview'], strlen($r['preview']));
            $size = ObjC::$c->new('CGSize');
            $size->w = $width; $size->h = $height;
            $img = ObjC::msg('p:', ObjC::msg('p:z', ObjC::alloc('NSImage'), 'initWithSize:', $size), 'autorelease');
            ObjC::msg('v:p', $img, 'addRepresentation:', $rep);
            ObjC::msg('v:', $rep, 'release');
        } else {
            $img = ObjC::msg('p:p', ObjC::cls('NSImage'), 'imageNamed:', ObjC::str('NSMultipleDocuments'));
            [$width, $height] = [32, 32];
        }
        [$x, $y] = [$r['x'], $r['y']];
        if ($x < 0 || $y < 0) {
            $p = ObjC::msg('q:qp', $view, 'convertPoint:fromView:', ObjC::msg('q:', $lastDown, 'locationInWindow'), null);
            [$x, $y] = [$p->x, $p->y];
        }
        $dragging = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        foreach (self::pasteboardItems($key) as $n => $pb) {
            $di = ObjC::msg('p:', ObjC::msg('p:p', ObjC::alloc('NSDraggingItem'), 'initWithPasteboardWriter:', $pb), 'autorelease');
            ObjC::msg('v:rp', $di, 'setDraggingFrame:contents:', ObjC::rect($x - $r['hotX'] + $n * 3, $y - $r['hotY'] + $n * 3, $width, $height), $img);
            ObjC::msg('v:p', $dragging, 'addObject:', $di);
        }
        $session = ObjC::msg('p:ppp', $view, 'beginDraggingSessionWithItems:event:source:', $dragging, $lastDown, $obj);
        if ($session === null) {
            self::$sources[$key]['error'] = new \RuntimeException('myphp: AppKit failed to start drag');
            self::finish($key, 0);
            return;
        }
        self::$sources[$key]['session'] = ObjC::msg('p:', $session, 'retain');
        ObjC::msg('v:b', $session, 'setAnimatesToStartingPositionsOnCancelOrFail:', true);
    }

    /** Cancels the drag $view sources, posting Escape to AppKit's tracking loop, as NSDraggingSession has no cancel (mygo's CancelDataDrag). */
    public static function cancel($view): void
    {
        if (!isset(self::$viewDrags[ObjC::addr($view)])) {
            return;
        }
        $escape = ObjC::str("\e");
        $e = ObjC::msg('p:LqLdlpppbS', ObjC::cls('NSEvent'), 'keyEventWithType:location:modifierFlags:timestamp:windowNumber:context:characters:charactersIgnoringModifiers:isARepeat:keyCode:',
            10 /* key down */, ObjC::point(0, 0), 0, 0.0, ObjC::msg('l:', ObjC::msg('p:', $view, 'window'), 'windowNumber'), null, $escape, $escape, false, 53);
        ObjC::msg('v:pb', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'postEvent:atStart:', $e, true);
    }

    /** The view's NSDraggingDestination methods (mygo's surface view's). */
    public static function methods(): array
    {
        $dragged = fn($self, $cmd, $info) => self::dataOperation($self, $info);
        return [
            'draggingEntered:' => ['Q@:@', 'L(ppp)', $dragged],
            'draggingUpdated:' => ['Q@:@', 'L(ppp)', $dragged],
            'draggingExited:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $info) { self::dataEvent($self, $info, 'leave'); }],
            'prepareForDragOperation:' => ['B@:@', 'b(ppp)', fn($self, $cmd, $info) => true],
            'performDragOperation:' => ['B@:@', 'b(ppp)', fn($self, $cmd, $info) => self::dataEvent($self, $info, 'drop')],
        ];
    }

    /** The file paths a pasteboard holds (the web view's file drops). @return list<string> */
    public static function paths($pasteboard): array
    {
        $classes = ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', ObjC::cls('NSURL'));
        $options = ObjC::msg('p:pp', ObjC::cls('NSDictionary'), 'dictionaryWithObject:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', true), ObjC::str('NSPasteboardURLReadingFileURLsOnlyKey'));
        $urls = ObjC::msg('p:pp', $pasteboard, 'readObjectsForClasses:options:', $classes, $options);
        $paths = [];
        for ($i = 0, $n = $urls === null ? 0 : ObjC::msg('L:', $urls, 'count'); $i < $n; $i++) {
            $paths[] = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:L', $urls, 'objectAtIndex:', $i), 'path'));
        }
        return $paths;
    }

    private static function dataOperation($view, $info): int
    {
        $d = null;
        return self::dataEvent($view, $info, 'over', $d) ? self::macOperations($d->operation) : 0;
    }

    /**
     * The drag over $view to the content (mygo's nativeDataEvent): offered
     * by the pasteboard's types, unread; for a drop, once the content took
     * it there, the formats it took read into its data, then dropped.
     */
    private static function dataEvent($view, $info, string $kind, ?DataDragEvent &$out = null): bool
    {
        $pb = ObjC::msg('p:', $info, 'draggingPasteboard');
        $formats = []; $hasFiles = false;
        $items = self::array(ObjC::msg('p:', $pb, 'pasteboardItems'));
        foreach ($items as $item) {
            foreach (self::array(ObjC::msg('p:', $item, 'types')) as $t) {
                $f = self::macFormat(ObjC::phpStr($t));
                if ($f === Format::DRAG_SESSION) {
                    continue;
                }
                if (!in_array($f, $formats, true)) { $formats[] = $f; }
                if ($f === Format::FILE_LIST) {
                    $hasFiles = true;
                    if (!in_array(Format::URI_LIST, $formats, true)) { $formats[] = Format::URI_LIST; }
                }
            }
        }
        $event = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'currentEvent');
        $mods = $event === null ? 0 : ObjC::msg('L:', $event, 'modifierFlags');
        $suggested = ($mods & self::OPTION) !== 0 ? Operation::COPY : (($mods & self::COMMAND) !== 0 ? Operation::MOVE : Operation::NONE);
        $session = ObjC::msg('p:p', $pb, 'stringForType:', ObjC::str(self::macType(Format::DRAG_SESSION)));
        $d = new DataDragEvent(new Offer($formats, self::operations(ObjC::msg('L:', $info, 'draggingSourceOperationMask')), $suggested), $session === null ? '' : ObjC::phpStr($session), $hasFiles);
        $out = $d;
        $p = ObjC::msg('q:qp', $view, 'convertPoint:fromView:', ObjC::msg('q:', $info, 'draggingLocation'), null);
        if ($kind === 'leave') {
            return (self::$onEvent)($view, 'leave', $d, $p->x, $p->y);
        }
        if (!(self::$onEvent)($view, 'over', $d, $p->x, $p->y)) {
            return false;
        }
        if ($kind === 'over') {
            return true;
        }
        // The pasteboard is read only once the content took formats.
        $dataItems = [];
        foreach ($items as $item) {
            $reps = [];
            foreach ($d->formats as $f) {
                $t = self::macType($f);
                $available = ObjC::msg('p:p', $item, 'dataForType:', ObjC::str($t));
                if ($available === null && $f === Format::URI_LIST) {
                    $available = ObjC::msg('p:p', $item, 'dataForType:', ObjC::str(self::URL));
                    if ($available === null) {
                        $t = self::FILE_URL;
                        $available = ObjC::msg('p:p', $item, 'dataForType:', ObjC::str($t));
                    }
                }
                if ($available !== null) {
                    $b = self::bytes($available);
                    if ($t === self::FILE_URL) {
                        $b = self::filePathURL($b);
                    }
                    $reps[] = Representation::bytes($f, $b);
                }
            }
            if ($reps !== []) { $dataItems[] = new Item(...$reps); }
        }
        $d->data = new Data(...$dataItems);
        return (self::$onEvent)($view, 'drop', $d, $p->x, $p->y);
    }

    /** A file reference URL (file:///.file/id=…, as the Finder puts on the pasteboard) as the file URL of its path; other URLs as they are. */
    private static function filePathURL(string $b): string
    {
        $s = rtrim($b, "\0");
        if (!str_starts_with($s, 'file:///.file/')) {
            return $b;
        }
        $u = ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str($s));
        $p = $u === null ? null : ObjC::msg('p:', $u, 'filePathURL');
        $r = $p === null ? '' : ObjC::phpStr(ObjC::msg('p:', $p, 'absoluteString'));
        return $r !== '' ? $r : $b;
    }

    /** One pasteboard item for each item of the drag's data, a provider giving its formats and the session's key (mygo's pasteboardItems). @return list<mixed> */
    private static function pasteboardItems(int $key): array
    {
        $r = self::$sources[$key]['request'];
        $items = [];
        foreach ($r['data']->items as $item) {
            $pb = ObjC::msg('p:', ObjC::msg('p:', ObjC::alloc('NSPasteboardItem'), 'init'), 'autorelease');
            $provider = ObjC::msg('p:', ObjC::alloc('MyPHPDragProvider'), 'init');
            self::$providers[ObjC::addr($provider)] = [$key, $item];
            self::$sources[$key]['providers'][] = $provider;
            $types = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
            foreach ($item->formats() as $f) {
                ObjC::msg('v:p', $types, 'addObject:', ObjC::str(self::macType($f)));
                if ($f === Format::URI_LIST) {
                    ObjC::msg('v:p', $types, 'addObject:', ObjC::str(self::URL));
                }
            }
            ObjC::msg('v:p', $types, 'addObject:', ObjC::str(self::macType(Format::DRAG_SESSION)));
            ObjC::msg('b:pp', $pb, 'setDataProvider:forTypes:', $provider, $types);
            $items[] = $pb;
        }
        return $items;
    }

    /** Ends drag $key with the destination's NSDragOperation (mygo's finish). */
    private static function finish(int $key, int $operation): void
    {
        $src = self::$sources[$key];
        unset(self::$sources[$key]);
        if ((self::$viewDrags[$src['view']] ?? null) === $key) {
            unset(self::$viewDrags[$src['view']]);
        }
        foreach ($src['providers'] as $p) {
            unset(self::$providers[ObjC::addr($p)]);
            ObjC::msg('v:', $p, 'release');
        }
        if ($src['session'] !== null) { ObjC::msg('v:', $src['session'], 'release'); }
        ObjC::msg('v:', $src['obj'], 'autorelease'); // the callback still uses it: let it go once AppKit returns
        $op = $src['error'] !== null ? Operation::NONE : self::operations($operation);
        ($src['request']['done'])(new Result($op, $op === Operation::NONE && $src['error'] === null, $src['error']));
    }

    /** @return list<mixed> */
    private static function array($a): array
    {
        $out = [];
        for ($i = 0, $n = $a === null ? 0 : ObjC::msg('L:', $a, 'count'); $i < $n; $i++) {
            $out[] = ObjC::msg('p:L', $a, 'objectAtIndex:', $i);
        }
        return $out;
    }

    private static function bytes($data): string
    {
        $n = ObjC::msg('L:', $data, 'length');
        return $n === 0 ? '' : \FFI::string(ObjC::$c->cast('char*', ObjC::msg('p:', $data, 'bytes')), $n);
    }

    private static function define(): void
    {
        if (self::$defined) {
            return;
        }
        self::$defined = true;
        ObjC::defineClass('MyPHPDragSource', 'NSObject', [
            'draggingSession:sourceOperationMaskForDraggingContext:' => ['Q@:@q', 'L(pppl)', function ($self, $cmd, $session, $context) {
                $src = self::$sources[ObjC::addr($self)] ?? null;
                return $src === null ? 0 : self::macOperations($src['request']['operations']);
            }],
            'draggingSession:endedAtPoint:operation:' => ['v@:@{CGPoint=dd}Q', 'v(pppqL)', function ($self, $cmd, $session, $point, $operation) {
                if (isset(self::$sources[ObjC::addr($self)])) {
                    self::finish(ObjC::addr($self), $operation);
                }
            }],
        ], ['NSDraggingSource']);
        ObjC::defineClass('MyPHPDragProvider', 'NSObject', [
            'pasteboard:item:provideDataForType:' => ['v@:@@@', 'v(ppppp)', function ($self, $cmd, $board, $item, $type) {
                [$key, $it] = self::$providers[ObjC::addr($self)] ?? [null, null];
                if ($key === null || !isset(self::$sources[$key])) {
                    return;
                }
                $name = ObjC::phpStr($type);
                if ($name === self::macType(Format::DRAG_SESSION)) {
                    $b = self::$sources[$key]['request']['session'];
                } else {
                    $f = self::macFormat($name);
                    try {
                        $b = $it->read($f);
                    } catch (\Throwable $e) {
                        self::$sources[$key]['error'] = $e;
                        return;
                    }
                    if ($f === Format::FILE_LIST || $name === self::URL) {
                        $b = trim(explode("\n", $b)[0]); // one URL
                    }
                }
                ObjC::msg('b:pp', $item, 'setData:forType:', ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $b, strlen($b)), $type);
            }],
        ], ['NSPasteboardItemDataProvider']);
    }
}
