<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use FFI;
use FFI\CData;
use MyPHP\Logger;
use MyPHP\Native\{Com, Mem};
use MyPHP\Transfer\{Data, Format, Item, Offer, Operation, Representation, Result};
use MyPHP\UI\DataDragEvent;

/**
 * OLE's drag and drop for the windows' content (mygo's surface_drag.go,
 * surface_drop.go): a window is a drop target (RegisterDragDrop), the
 * content told what a drag offers as it moves (its formats enumerated,
 * unread) and given the formats it took once dropped; a drag out of it is
 * DoDragDrop of a data object of PHP's, which renders each format as the
 * destination asks. A page's web view is a child window of its own, which
 * WebView2 makes a drop target itself. The COM objects (IDataObject,
 * IDropSource, IEnumFORMATETC, IDropTarget) are structs of a vtable of
 * closures and an id, counted (AddRef, Release) and freed at none; the
 * shell's drag-image helpers draw the images (IDragSourceHelper of the
 * preview, IDropTargetHelper over a window).
 *
 * @internal Platform\Windows
 */
final class DragDrop
{
    private const IID_UNKNOWN = '00000000-0000-0000-c000-000000000046', IID_DATAOBJECT = '0000010e-0000-0000-c000-000000000046',
        IID_DROPSOURCE = '00000121-0000-0000-c000-000000000046', IID_ENUMFORMATETC = '00000103-0000-0000-c000-000000000046',
        IID_DROPTARGET = '00000122-0000-0000-c000-000000000046', IID_SOURCEHELPER = 'de5bf786-477a-11d2-839d-00c04fd918d0',
        IID_TARGETHELPER = '4657278b-411b-11d2-839a-00c04fd918d0', CLSID_HELPER = '4657278a-411b-11d2-839a-00c04fd918d0';
    private const S_OK = 0, S_FALSE = 1, E_NOTIMPL = -2147467263, E_FAIL = -2147467259, E_NOINTERFACE = -2147467262,
        DV_E_FORMATETC = -2147221404, OLE_E_ADVISENOTSUPPORTED = -2147221501, DATA_S_SAMEFORMATETC = 0x40130,
        DRAGDROP_S_DROP = 0x40100, DRAGDROP_S_CANCEL = 0x40101, DRAGDROP_S_USEDEFAULTCURSORS = 0x40102;
    private const CF_UNICODETEXT = 13, CF_HDROP = 15, TYMED_HGLOBAL = 1, DVASPECT_CONTENT = 1;
    private const MK_LBUTTON = 1, MK_SHIFT = 4, MK_CONTROL = 8;

    private static ?FFI $f = null;
    private static bool $initialized = false;
    /** @var array<string, CData> each kind's vtable */
    private static array $vtables = [];
    /** @var array<int, CData> each object's struct, by its id */
    private static array $mem = [];
    /** @var array<int, array<string, mixed>> each object's state: its kind, its count, the rest its kind's */
    private static array $objects = [];
    private static int $next = 0;
    /** @var array<int, int> the drop target of each window, by the window's handle */
    private static array $targets = [];

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct { uint16_t cfFormat; void *ptd; uint32_t dwAspect; int32_t lindex; uint32_t tymed; } FORMATETC;
            typedef struct { uint32_t tymed; void *handle; void *pUnkForRelease; } STGMEDIUM;
            typedef struct { int32_t x, y; } POINT;
            typedef struct {
                int32_t (*QueryInterface)(void *self, const void *iid, void **out);
                uint32_t (*AddRef)(void *self);
                uint32_t (*Release)(void *self);
                int32_t (*GetData)(void *self, FORMATETC *format, STGMEDIUM *medium);
                int32_t (*GetDataHere)(void *self, FORMATETC *format, STGMEDIUM *medium);
                int32_t (*QueryGetData)(void *self, FORMATETC *format);
                int32_t (*GetCanonicalFormatEtc)(void *self, FORMATETC *in, FORMATETC *out);
                int32_t (*SetData)(void *self, FORMATETC *format, STGMEDIUM *medium, int32_t release);
                int32_t (*EnumFormatEtc)(void *self, uint32_t direction, void **out);
                int32_t (*DAdvise)(void *self, FORMATETC *format, uint32_t flags, void *sink, uint32_t *connection);
                int32_t (*DUnadvise)(void *self, uint32_t connection);
                int32_t (*EnumDAdvise)(void *self, void **out);
            } IDataObjectVtbl;
            typedef struct { IDataObjectVtbl *v; } IDataObject;
            typedef struct {
                int32_t (*QueryInterface)(void *self, const void *iid, void **out);
                uint32_t (*AddRef)(void *self);
                uint32_t (*Release)(void *self);
                int32_t (*QueryContinueDrag)(void *self, int32_t escape, uint32_t keys);
                int32_t (*GiveFeedback)(void *self, uint32_t effect);
            } IDropSourceVtbl;
            typedef struct {
                int32_t (*QueryInterface)(void *self, const void *iid, void **out);
                uint32_t (*AddRef)(void *self);
                uint32_t (*Release)(void *self);
                int32_t (*Next)(void *self, uint32_t n, FORMATETC *out, uint32_t *fetched);
                int32_t (*Skip)(void *self, uint32_t n);
                int32_t (*Reset)(void *self);
                int32_t (*Clone)(void *self, void **out);
            } IEnumFORMATETCVtbl;
            typedef struct { IEnumFORMATETCVtbl *v; } IEnumFORMATETC;
            typedef struct {
                int32_t (*QueryInterface)(void *self, const void *iid, void **out);
                uint32_t (*AddRef)(void *self);
                uint32_t (*Release)(void *self);
                int32_t (*DragEnter)(void *self, void *data, uint32_t keys, uint64_t pt, uint32_t *effect);
                int32_t (*DragOver)(void *self, uint32_t keys, uint64_t pt, uint32_t *effect);
                int32_t (*DragLeave)(void *self);
                int32_t (*Drop)(void *self, void *data, uint32_t keys, uint64_t pt, uint32_t *effect);
            } IDropTargetVtbl;
            typedef struct { IDropTargetVtbl *v; } IDropTarget;
            typedef struct { void *v; int64_t id; } Obj;
            typedef struct { int32_t cx, cy; POINT ptOffset; void *hbmpDragImage; uint32_t crColorKey; } SHDRAGIMAGE;
            typedef struct { void *r0[3]; int32_t (*InitializeFromBitmap)(void *self, SHDRAGIMAGE *image, void *data); } IDragSourceHelperVtbl;
            typedef struct { IDragSourceHelperVtbl *v; } IDragSourceHelper;
            typedef struct {
                void *r0[3];
                int32_t (*DragEnter)(void *self, void *hwnd, void *data, POINT *pt, uint32_t effect);
                int32_t (*DragLeave)(void *self);
                int32_t (*DragOver)(void *self, POINT *pt, uint32_t effect);
                int32_t (*Drop)(void *self, void *data, POINT *pt, uint32_t effect);
            } IDropTargetHelperVtbl;
            typedef struct { IDropTargetHelperVtbl *v; } IDropTargetHelper;
            typedef struct { uint32_t biSize; int32_t biWidth, biHeight; uint16_t biPlanes, biBitCount; uint32_t biCompression, biSizeImage; int32_t biXPelsPerMeter, biYPelsPerMeter; uint32_t biClrUsed, biClrImportant; } BITMAPINFOHEADER;
            int32_t OleInitialize(void *reserved);
            int32_t RegisterDragDrop(void *hwnd, void *target);
            int32_t RevokeDragDrop(void *hwnd);
            int32_t DoDragDrop(void *data, void *source, uint32_t allowed, uint32_t *effect);
            void ReleaseStgMedium(STGMEDIUM *medium);
            void *OleDuplicateData(void *source, uint16_t format, uint32_t flags);
            int32_t CoCreateInstance(const void *clsid, void *outer, uint32_t context, const void *iid, void *out);
            C, 'ole32.dll');
    }

    /** OLE on the UI thread, once: drag and drop need it beyond COM (OleInitialize over the apartment Backend made). */
    private static function init(): void
    {
        if (!self::$initialized) {
            $hr = self::f()->OleInitialize(null);
            if ($hr < 0) {
                throw new \RuntimeException(sprintf('myphp: OleInitialize failed (HRESULT 0x%08X)', $hr & 0xFFFFFFFF));
            }
            self::$initialized = true;
        }
    }

    // The objects

    /** A new object of $kind ('data', 'source', 'enum', 'target'), counted once, its state $state. */
    private static function create(string $kind, array $state): CData
    {
        $f = self::f();
        $o = $f->new('Obj', false);
        $o->v = FFI::addr(self::vtable($kind));
        $o->id = ++self::$next;
        self::$mem[$o->id] = $o;
        self::$objects[$o->id] = ['kind' => $kind, 'refs' => 1] + $state;
        return $f->cast('void *', FFI::addr($o));
    }

    private static function id(CData $self): int
    {
        return self::f()->cast('Obj *', $self)->id;
    }

    /** An object's state, by reference. */
    private static function &state(CData $self): array
    {
        return self::$objects[self::id($self)];
    }

    /** Lets go of one count of the object; at none, its kind lets go of what it holds and its struct is freed. */
    private static function release(int $id): int
    {
        if (!isset(self::$objects[$id])) {
            return 0;
        }
        $refs = --self::$objects[$id]['refs'];
        if ($refs === 0) {
            $o = self::$objects[$id];
            unset(self::$objects[$id]);
            if ($o['kind'] === 'data') {
                foreach ($o['extra'] as $m) {
                    self::f()->ReleaseStgMedium(FFI::addr($m));
                    FFI::free($m);
                }
            } elseif ($o['kind'] === 'target') {
                self::leave($o);
                if ($o['helper'] !== null) { Com::release($o['helper']); }
            }
            FFI::free(self::$mem[$id]);
            unset(self::$mem[$id]);
        }
        return $refs;
    }

    private static function iid(string $s): string
    {
        $g = Com::guid($s);
        return FFI::string(FFI::addr($g), 16);
    }

    /** Each kind's vtable, made once: IUnknown's three, then the kind's. */
    private static function vtable(string $kind): CData
    {
        if (isset(self::$vtables[$kind])) {
            return self::$vtables[$kind];
        }
        $f = self::f();
        $type = ['data' => 'IDataObjectVtbl', 'source' => 'IDropSourceVtbl', 'enum' => 'IEnumFORMATETCVtbl', 'target' => 'IDropTargetVtbl'][$kind];
        $own = self::iid(['data' => self::IID_DATAOBJECT, 'source' => self::IID_DROPSOURCE, 'enum' => self::IID_ENUMFORMATETC, 'target' => self::IID_DROPTARGET][$kind]);
        $unknown = self::iid(self::IID_UNKNOWN);
        $vt = self::$vtables[$kind] = $f->new($type, false);
        $vt->QueryInterface = static function ($self, $iid, $out) use ($own, $unknown): int {
            $asked = Mem::read(Mem::addr($iid), 16);
            if ($asked !== $own && $asked !== $unknown) {
                $out[0] = null;
                return self::E_NOINTERFACE;
            }
            $out[0] = $self;
            self::$objects[self::id($self)]['refs']++;
            return self::S_OK;
        };
        $vt->AddRef = static fn($self): int => ++self::$objects[self::id($self)]['refs'];
        $vt->Release = static fn($self): int => self::release(self::id($self));
        $guard = static function (Closure $fn, int $failed): Closure { // a throw in a callback of Windows's goes to the log, the call failing
            return static function (...$args) use ($fn, $failed): int {
                try {
                    return $fn(...$args);
                } catch (\Throwable $e) {
                    Logger::error('drag and drop: ' . $e->getMessage(), 'windows');
                    return $failed;
                }
            };
        };
        if ($kind === 'data') {
            $vt->GetData = $guard(self::getData(...), self::E_FAIL);
            $vt->GetDataHere = static fn($self, $format, $medium): int => self::E_NOTIMPL;
            $vt->QueryGetData = $guard(static function ($self, $format): int {
                $d = &self::state($self);
                $cf = $format[0]->cfFormat;
                return self::valid($format[0]) && (isset($d['providers'][$cf]) || isset($d['extra'][$cf])) ? self::S_OK : self::DV_E_FORMATETC;
            }, self::E_FAIL);
            $vt->GetCanonicalFormatEtc = static function ($self, $in, $out): int {
                FFI::memcpy($out, $in, FFI::sizeof($in[0]));
                $out[0]->ptd = null;
                return self::DATA_S_SAMEFORMATETC;
            };
            $vt->SetData = $guard(self::setData(...), self::E_FAIL);
            $vt->EnumFormatEtc = $guard(static function ($self, int $direction, $out): int {
                $out[0] = null;
                if ($direction !== 1) { // DATADIR_GET
                    return self::E_NOTIMPL;
                }
                $out[0] = self::create('enum', ['formats' => self::state($self)['formats'], 'n' => 0]);
                return self::S_OK;
            }, self::E_FAIL);
            $vt->DAdvise = static fn($self, $format, int $flags, $sink, $connection): int => self::OLE_E_ADVISENOTSUPPORTED;
            $vt->DUnadvise = static fn($self, int $connection): int => self::OLE_E_ADVISENOTSUPPORTED;
            $vt->EnumDAdvise = static fn($self, $out): int => self::OLE_E_ADVISENOTSUPPORTED;
        } elseif ($kind === 'source') {
            $vt->QueryContinueDrag = static function ($self, int $escape, int $keys): int {
                $s = &self::state($self);
                if ($escape !== 0 || $s['canceled']) {
                    $s['escaped'] = true;
                    return self::DRAGDROP_S_CANCEL;
                }
                return ($keys & self::MK_LBUTTON) === 0 ? self::DRAGDROP_S_DROP : self::S_OK;
            };
            $vt->GiveFeedback = static fn($self, int $effect): int => self::DRAGDROP_S_USEDEFAULTCURSORS;
        } elseif ($kind === 'enum') {
            $vt->Next = static function ($self, int $count, $out, $fetched): int {
                $e = &self::state($self);
                $n = min($count, count($e['formats']) - $e['n']);
                for ($i = 0; $i < $n; $i++) {
                    self::fill($out[$i], $e['formats'][$e['n'] + $i]);
                }
                $e['n'] += $n;
                if ($fetched !== null) { $fetched[0] = $n; }
                return $n < $count ? self::S_FALSE : self::S_OK;
            };
            $vt->Skip = static function ($self, int $count): int {
                $e = &self::state($self);
                $n = min($count, count($e['formats']) - $e['n']);
                $e['n'] += $n;
                return $n < $count ? self::S_FALSE : self::S_OK;
            };
            $vt->Reset = static function ($self): int {
                self::$objects[self::id($self)]['n'] = 0;
                return self::S_OK;
            };
            $vt->Clone = static function ($self, $out): int {
                $e = self::state($self);
                $out[0] = self::create('enum', ['formats' => $e['formats'], 'n' => $e['n']]);
                return self::S_OK;
            };
        } else {
            $vt->DragEnter = $guard(static function ($self, $data, int $keys, int $pt, $effect): int {
                $t = &self::state($self);
                self::enter($t, $data, $keys);
                self::over($t, $keys, $pt, $effect);
                if ($t['helper'] !== null) {
                    $p = self::point($pt);
                    ($t['helper']->v->DragEnter)($t['helper'], $t['window']->hwnd, $data, FFI::addr($p), $effect[0]);
                }
                return self::S_OK;
            }, self::S_OK);
            $vt->DragOver = $guard(static function ($self, int $keys, int $pt, $effect): int {
                $t = &self::state($self);
                self::over($t, $keys, $pt, $effect);
                if ($t['helper'] !== null) {
                    $p = self::point($pt);
                    ($t['helper']->v->DragOver)($t['helper'], FFI::addr($p), $effect[0]);
                }
                return self::S_OK;
            }, self::S_OK);
            $vt->DragLeave = $guard(static function ($self): int {
                $t = &self::state($self);
                if ($t['helper'] !== null) { ($t['helper']->v->DragLeave)($t['helper']); }
                if (!$t['window']->isDead()) { $t['window']->w->dataEvent('leave', new DataDragEvent(new Offer), 0.0, 0.0); }
                self::leave($t);
                return self::S_OK;
            }, self::S_OK);
            $vt->Drop = $guard(static function ($self, $data, int $keys, int $pt, $effect): int {
                $t = &self::state($self);
                $allowed = $effect[0];
                if ($t['data'] === null || Mem::addr($t['data']) !== Mem::addr($data)) {
                    self::enter($t, $data, $keys);
                }
                self::over($t, $keys, $pt, $effect);
                self::drop($t, $pt, $effect);
                if ($t['helper'] !== null) {
                    $p = self::point($pt);
                    ($t['helper']->v->Drop)($t['helper'], $data, FFI::addr($p), $allowed);
                }
                self::leave($t);
                return self::S_OK;
            }, self::S_OK);
        }
        return $vt;
    }

    // The data object

    /** A FORMATETC's that a data object of HGLOBALs takes: the content's, whole, in global memory. */
    private static function valid(CData $format): bool
    {
        return $format->dwAspect === self::DVASPECT_CONTENT && $format->lindex === -1 && ($format->tymed & self::TYMED_HGLOBAL) !== 0;
    }

    private static function fill(CData $format, int $cf): void
    {
        $format->cfFormat = $cf;
        $format->ptd = null;
        $format->dwAspect = self::DVASPECT_CONTENT;
        $format->lindex = -1;
        $format->tymed = self::TYMED_HGLOBAL;
    }

    /** A data object of $data's formats, each rendered when asked (mygo's setTransferProviders): plain text as CF_UNICODETEXT, HTML as CF_HTML, an image as the registered "PNG", a URL as UniformResourceLocatorW, files as CF_HDROP, each format by its name too, and the drag's session. */
    private static function dataObject(Data $data, string $session): CData
    {
        $u = Win32::user32();
        $named = static fn(string $name): int => $u->RegisterClipboardFormatW(Win32::wide($name));
        $providers = [];
        $add = static function (int $cf, Closure $fn) use (&$providers): void { $providers[$cf] = $fn; };
        foreach ($data->formats() as $format) {
            $add($named($format), static fn(): string => $data->read($format));
            match ($format) {
                Format::TEXT => $add(self::CF_UNICODETEXT, static fn(): string => (string)iconv('UTF-8', 'UTF-16LE', $data->read(Format::TEXT)) . "\0\0"),
                Format::HTML => $add($named('HTML Format'), static fn(): string => Clipboard::htmlData($data->read(Format::HTML))),
                Format::PNG => $add($named('PNG'), static fn(): string => $data->read(Format::PNG)),
                Format::URI_LIST => $add($named('UniformResourceLocatorW'), static function () use ($data): string {
                    $urls = $data->urls();
                    if ($urls === []) { throw new \RuntimeException('myphp: the drag offers a URI list of no URL'); }
                    return (string)iconv('UTF-8', 'UTF-16LE', $urls[0]) . "\0\0";
                }),
                Format::FILE_LIST => $add(self::CF_HDROP, static function () use ($data): string {
                    $files = '';
                    foreach ($data->paths() as $p) { $files .= (string)iconv('UTF-8', 'UTF-16LE', $p) . "\0\0"; }
                    return pack('VllVV', 20, 0, 0, 0, 1) . $files . "\0\0"; // DROPFILES: its files from byte 20, wide
                }),
                default => null,
            };
        }
        if ($session !== '') {
            $add($named(Format::DRAG_SESSION), static fn(): string => $session);
        }
        return self::create('data', ['providers' => $providers, 'formats' => array_keys($providers), 'extra' => [], 'error' => null]);
    }

    /** GetData: a provider's bytes in global memory, or what SetData put (the shell's drag image), a copy. */
    private static function getData(CData $self, CData $format, CData $medium): int
    {
        $d = &self::state($self);
        $f = self::f();
        FFI::memset($medium, 0, FFI::sizeof($medium[0]));
        $cf = $format[0]->cfFormat;
        if (!self::valid($format[0])) {
            return self::DV_E_FORMATETC;
        }
        if (isset($d['extra'][$cf])) {
            $h = $f->OleDuplicateData($d['extra'][$cf]->handle, $cf, 0);
            if ($h === null) {
                return self::E_FAIL;
            }
            [$medium[0]->tymed, $medium[0]->handle] = [self::TYMED_HGLOBAL, $h];
            return self::S_OK;
        }
        $provider = $d['providers'][$cf] ?? null;
        if ($provider === null) {
            return self::DV_E_FORMATETC;
        }
        try {
            $bytes = $provider();
        } catch (\Throwable $e) {
            $d['error'] = $e;
            return self::E_FAIL;
        }
        $k = Win32::kernel32();
        $h = $k->GlobalAlloc(0x0002 | 0x0040, max(1, strlen($bytes))); // GMEM_MOVEABLE | GMEM_ZEROINIT
        if ($h === null) {
            return self::E_FAIL;
        }
        if ($bytes !== '') {
            FFI::memcpy($k->GlobalLock($h), $bytes, strlen($bytes));
            $k->GlobalUnlock($h);
        }
        [$medium[0]->tymed, $medium[0]->handle] = [self::TYMED_HGLOBAL, $f->cast('void *', Mem::addr($h))];
        return self::S_OK;
    }

    /** SetData: what the shell keeps on the data object (the drag image's bits, its state), held, a copy unless it is given. */
    private static function setData(CData $self, CData $format, CData $medium, int $release): int
    {
        $d = &self::state($self);
        $f = self::f();
        if (!self::valid($format[0]) || $medium[0]->tymed !== self::TYMED_HGLOBAL) {
            return self::DV_E_FORMATETC;
        }
        $cf = $format[0]->cfFormat;
        $m = $f->new('STGMEDIUM', false);
        $m->tymed = self::TYMED_HGLOBAL;
        if ($release !== 0) {
            FFI::memcpy(FFI::addr($m), $medium, FFI::sizeof($m));
        } else {
            $m->handle = $f->OleDuplicateData($medium[0]->handle, $cf, 0);
            if ($m->handle === null) {
                FFI::free($m);
                return self::E_FAIL;
            }
        }
        if (isset($d['extra'][$cf])) {
            $f->ReleaseStgMedium(FFI::addr($d['extra'][$cf]));
            FFI::free($d['extra'][$cf]);
        }
        $d['extra'][$cf] = $m;
        if (!in_array($cf, $d['formats'], true)) { $d['formats'][] = $cf; }
        return self::S_OK;
    }

    // Dragging out

    /** The drag in flight, its source's id: one at a time, DoDragDrop being modal. */
    private static ?int $dragging = null;

    /**
     * Drags $o's data out of $w (mygo's StartDataDrag): DoDragDrop of a data
     * object of its formats and the session's, its preview the shell's
     * drag image, from a press of the left button; its result told to
     * $o['done'] once it ends.
     * @param array{data: Data, session: string, done: Closure, operations: int, preview: ?string, width: int, height: int, hotX: float, hotY: float} $o
     */
    public static function start(Window $w, array $o): void
    {
        $u = Win32::user32();
        if (self::$dragging !== null || ($u->GetKeyState(0x01) & 0x8000) === 0) { // VK_LBUTTON down: DoDragDrop ends at once without it
            ($o['done'])(new Result(error: new \RuntimeException('myphp: a drag needs the left button held')));
            return;
        }
        self::init();
        $f = self::f();
        $data = self::dataObject($o['data'], $o['session']);
        $source = self::create('source', ['canceled' => false, 'escaped' => false]);
        $sourceId = self::id($source);
        $dataId = self::id($data);
        self::$dragging = $sourceId;
        try {
            if ($o['preview'] !== null) {
                self::image($w, $o, $data);
            }
            $u->ReleaseCapture(); // OLE takes the mouse for its loop
            $effect = $f->new('uint32_t');
            $hr = $f->DoDragDrop($data, $source, Operation::allowed($o['operations']), FFI::addr($effect));
            $s = self::$objects[$sourceId];
            $error = self::$objects[$dataId]['error'] ?? null;
        } finally {
            self::$dragging = null;
            self::release($sourceId);
            self::release($dataId);
        }
        $op = $hr === self::DRAGDROP_S_DROP ? $effect->cdata & (Operation::COPY | Operation::MOVE) : Operation::NONE;
        $canceled = $hr !== self::DRAGDROP_S_DROP && ($hr === self::DRAGDROP_S_CANCEL || $hr === self::S_OK);
        if ($error === null && $hr < 0) {
            $error = new \RuntimeException(sprintf('myphp: DoDragDrop failed (HRESULT 0x%08X)', $hr & 0xFFFFFFFF));
        }
        if ($error !== null) {
            [$op, $canceled] = [Operation::NONE, false];
        }
        ($o['done'])(new Result($op, $canceled, $error, $canceled && $s['escaped'], \MyPHP\Screen::cursorScreenPoint()));
    }

    /** Ends the drag in flight as canceled: its source says so at the next QueryContinueDrag, which a mouse move brings. */
    public static function cancel(Window $w): void
    {
        if (self::$dragging !== null && isset(self::$objects[self::$dragging])) {
            self::$objects[self::$dragging]['canceled'] = true;
            Win32::user32()->PostMessageW($w->hwnd, Win32::WM_MOUSEMOVE, 0, 0);
        }
    }

    /** The preview, premultiplied BGRA of $o's size in points, as the shell's drag image at the window's scale (IDragSourceHelper::InitializeFromBitmap, which takes the bitmap). */
    private static function image(Window $w, array $o, CData $data): void
    {
        $f = self::f();
        $out = $f->new('void *');
        [$c, $i] = [Com::guid(self::CLSID_HELPER), Com::guid(self::IID_SOURCEHELPER)];
        if ($f->CoCreateInstance(FFI::addr($c), null, 1, FFI::addr($i), FFI::addr($out)) < 0 || FFI::isNull($out)) {
            Logger::warn('drag and drop: no drag image helper, the drag shows none', 'windows');
            return;
        }
        $helper = Com::typed($f, $out, 'IDragSourceHelper *');
        try {
            $s = $w->backingScale();
            [$pw, $ph] = [max(1, (int)$o['width']), max(1, (int)$o['height'])];
            [$bw, $bh] = [max(1, (int)round($pw * $s)), max(1, (int)round($ph * $s))];
            $pixels = '';
            for ($y = 0; $y < $bh; $y++) {
                $row = min((int)($y / $s), $ph - 1) * $pw;
                for ($x = 0; $x < $bw; $x++) { $pixels .= substr($o['preview'], ($row + min((int)($x / $s), $pw - 1)) * 4, 4); }
            }
            $g = FFI::cdef('void *CreateDIBSection(void *dc, const void *info, uint32_t usage, void **bits, void *section, uint32_t offset); int32_t DeleteObject(void *object);', 'gdi32.dll');
            $bi = $f->new('BITMAPINFOHEADER');
            [$bi->biSize, $bi->biWidth, $bi->biHeight, $bi->biPlanes, $bi->biBitCount] = [FFI::sizeof($bi), $bw, -$bh, 1, 32]; // top down
            $bits = $g->new('void *');
            $bmp = $g->CreateDIBSection(null, FFI::addr($bi), 0, FFI::addr($bits), null, 0);
            if ($bmp === null) {
                throw new \RuntimeException('myphp: cannot make the drag image');
            }
            FFI::memcpy($bits, $pixels, strlen($pixels));
            $img = $f->new('SHDRAGIMAGE');
            [$img->cx, $img->cy, $img->ptOffset->x, $img->ptOffset->y] = [$bw, $bh, (int)round($o['hotX'] * $s), (int)round($o['hotY'] * $s)];
            $img->hbmpDragImage = $f->cast('void *', Mem::addr($bmp));
            $img->crColorKey = 0xFFFFFFFF; // CLR_NONE: the alpha its own
            if (($helper->v->InitializeFromBitmap)($helper, FFI::addr($img), $data) < 0) {
                $g->DeleteObject($bmp);
                Logger::warn('drag and drop: the shell took no drag image', 'windows');
            }
        } finally {
            Com::release($out);
        }
    }

    // Dropping in

    /** Makes $w a drop target (RegisterDragDrop takes its own count of it). */
    public static function register(Window $w): void
    {
        self::init();
        $f = self::f();
        $helper = $f->new('void *');
        [$c, $i] = [Com::guid(self::CLSID_HELPER), Com::guid(self::IID_TARGETHELPER)];
        $h = $f->CoCreateInstance(FFI::addr($c), null, 1, FFI::addr($i), FFI::addr($helper)) >= 0 && !FFI::isNull($helper) ? Com::typed($f, $helper, 'IDropTargetHelper *') : null;
        $target = self::create('target', ['window' => $w, 'helper' => $h, 'data' => null, 'offer' => null, 'event' => null]);
        $hr = $f->RegisterDragDrop($w->hwnd, $target);
        if ($hr < 0) {
            self::release(self::id($target));
            throw new \RuntimeException(sprintf('myphp: RegisterDragDrop failed (HRESULT 0x%08X)', $hr & 0xFFFFFFFF));
        }
        self::$targets[Mem::addr($w->hwnd)] = self::id($target);
    }

    /** Takes $w's drop target away, as its window goes. */
    public static function revoke(Window $w): void
    {
        $id = self::$targets[Mem::addr($w->hwnd)] ?? null;
        if ($id !== null) {
            unset(self::$targets[Mem::addr($w->hwnd)]);
            self::f()->RevokeDragDrop($w->hwnd);
            self::release($id);
        }
    }

    /** A POINTL passed by value, x in its low half and y in its high one, as a POINT. */
    private static function point(int $pt): CData
    {
        $p = self::f()->new('POINT');
        $p->x = unpack('l', pack('V', $pt & 0xFFFFFFFF))[1];
        $p->y = unpack('l', pack('V', ($pt >> 32) & 0xFFFFFFFF))[1];
        return $p;
    }

    /** A drag entering: its data object held, what it offers read of it (mygo's oleOffer). */
    private static function enter(array &$t, CData $data, int $keys): void
    {
        self::leave($t);
        $t['data'] = Com::addRef(self::f()->cast('void *', Mem::addr($data)));
        $t['offer'] = self::offer($t['data']);
    }

    private static function leave(array &$t): void
    {
        if ($t['data'] !== null) {
            Com::release($t['data']);
        }
        [$t['data'], $t['offer']] = [null, null];
    }

    /**
     * What a data object offers, unread: its formats enumerated and the
     * common ones asked for besides (some sources enumerate none), each
     * as a transfer's format (CF_UNICODETEXT text, CF_HDROP files and a
     * URI list, CF_HTML HTML, "PNG" a PNG, UniformResourceLocatorW a URI
     * list, a name of a type itself), the session's read.
     * @return array{formats: array<string, int>, session: string, files: bool}
     */
    private static function offer(CData $data): array
    {
        $f = self::f();
        $u = Win32::user32();
        $obj = $f->cast('IDataObject *', $data);
        $out = ['formats' => [], 'session' => '', 'files' => false];
        $named = [];
        foreach (['HTML Format', 'PNG', 'UniformResourceLocatorW', 'Rich Text Format', Format::DRAG_SESSION] as $name) {
            $named[$u->RegisterClipboardFormatW(Win32::wide($name))] = $name;
        }
        $add = static function (int $cf) use (&$out, $named, $u, $data): void {
            $name = $named[$cf] ?? '';
            if ($name === '' && $cf >= 0xC000) {
                $buf = $u->new('uint16_t[256]');
                $n = $u->GetClipboardFormatNameW($cf, $buf, 256);
                $name = $n > 0 ? Win32::str($buf, $n) : '';
            }
            if ($name === Format::DRAG_SESSION) {
                $out['session'] = rtrim((string)self::bytes($data, $cf), "\0");
                return;
            }
            $format = match (true) {
                $cf === self::CF_UNICODETEXT => Format::TEXT,
                $cf === self::CF_HDROP => Format::FILE_LIST,
                $name === 'HTML Format' => Format::HTML,
                $name === 'PNG' => Format::PNG,
                $name === 'UniformResourceLocatorW' => Format::URI_LIST,
                $name === 'Rich Text Format' => 'text/rtf',
                str_contains($name, '/') && strpbrk($name, "\0\r\n") === false => $name,
                default => '',
            };
            if ($cf === self::CF_HDROP) { // the system's file list, a URI list too
                $out['files'] = true;
                $out['formats'][Format::FILE_LIST] ??= $cf;
                $out['formats'][Format::URI_LIST] ??= $cf;
                return;
            }
            if ($format !== '') {
                $out['formats'][$format] ??= $cf;
            }
        };
        $enum = $f->new('void *');
        if (($obj->v->EnumFormatEtc)($obj, 1, FFI::addr($enum)) === self::S_OK && !FFI::isNull($enum)) {
            $e = $f->cast('IEnumFORMATETC *', $enum);
            $fmt = $f->new('FORMATETC');
            for ($n = 0; $n < 1024 && ($e->v->Next)($e, 1, FFI::addr($fmt), null) === self::S_OK; $n++) {
                if ($fmt->ptd !== null) { Win32::ole32()->CoTaskMemFree($fmt->ptd); }
                if (self::valid($fmt)) { $add($fmt->cfFormat); }
            }
            Com::release($enum);
        }
        $fmt = $f->new('FORMATETC');
        foreach ([self::CF_UNICODETEXT, self::CF_HDROP, ...array_keys($named)] as $cf) {
            self::fill($fmt, $cf);
            if (($obj->v->QueryGetData)($obj, FFI::addr($fmt)) === self::S_OK) { $add($cf); }
        }
        return $out;
    }

    /** The bytes of format $cf of a data object, in global memory; null where it has none (mygo's oleDataBytes). */
    private static function bytes(CData $data, int $cf): ?string
    {
        $f = self::f();
        $obj = $f->cast('IDataObject *', $data);
        $fmt = $f->new('FORMATETC');
        self::fill($fmt, $cf);
        $m = $f->new('STGMEDIUM');
        if (($obj->v->GetData)($obj, FFI::addr($fmt), FFI::addr($m)) < 0) {
            return null;
        }
        try {
            if ($m->tymed !== self::TYMED_HGLOBAL || $m->handle === null) {
                return null;
            }
            $k = Win32::kernel32();
            $n = $k->GlobalSize($m->handle);
            if ($n > 64 << 20) {
                throw new \RuntimeException('myphp: a dropped format is beyond 64 MiB');
            }
            $p = $k->GlobalLock($m->handle);
            try {
                return $p === null ? null : FFI::string($p, $n);
            } finally {
                $k->GlobalUnlock($m->handle);
            }
        } finally {
            $f->ReleaseStgMedium(FFI::addr($m));
        }
    }

    /** The event of the drag at its keys: its formats, the effects the source allows, the one a key asks for (Ctrl copies, Shift moves). */
    private static function event(array $t, int $allowed, int $keys): DataDragEvent
    {
        $suggested = ($keys & self::MK_CONTROL) !== 0 ? Operation::COPY : (($keys & self::MK_SHIFT) !== 0 ? Operation::MOVE : Operation::NONE);
        return new DataDragEvent(new Offer(array_keys($t['offer']['formats']), $allowed & (Operation::COPY | Operation::MOVE), $suggested), $t['offer']['session'], $t['offer']['files']);
    }

    /** Asks the content whether it takes the drag where it is, its answer the effect (none for no). */
    private static function over(array &$t, int $keys, int $pt, CData $effect): void
    {
        $allowed = $effect[0];
        $effect[0] = 0;
        $t['event'] = null;
        if ($t['offer'] === null || $t['window']->isDead()) {
            return;
        }
        $d = self::event($t, $allowed, $keys);
        [$x, $y] = $t['window']->screenToPoints(self::point($pt)->x, self::point($pt)->y);
        if ($t['window']->w->dataEvent('over', $d, $x, $y)) {
            $effect[0] = $d->operation;
            $t['event'] = $d;
        }
    }

    /** The drop: the formats the content took read, then given it; the effect it had, or none. */
    private static function drop(array &$t, int $pt, CData $effect): void
    {
        $d = $t['event'];
        if ($d === null || $effect[0] === 0) {
            return;
        }
        $effect[0] = 0;
        if ($d->data === null || $d->data->formats() === []) { // another process's: read now; this one's is as it is
            $reps = [];
            foreach ($d->formats as $format) {
                $cf = $t['offer']['formats'][$format] ?? null;
                if ($cf === null) {
                    return;
                }
                if ($cf === self::CF_HDROP) {
                    $paths = self::paths($t['data']);
                    if ($paths === []) {
                        return;
                    }
                    $reps[] = Representation::bytes($format, implode("\r\n", array_map(static fn(string $p): string => Data::files($p)->read(Format::FILE_LIST), $paths)));
                    continue;
                }
                $b = self::bytes($t['data'], $cf);
                if ($b === null) {
                    return;
                }
                $b = match ($format) {
                    Format::TEXT => Win32::str(self::wideBuffer($b)),
                    Format::HTML => Clipboard::fragment($b),
                    default => $cf === Win32::user32()->RegisterClipboardFormatW(Win32::wide('UniformResourceLocatorW')) ? Win32::str(self::wideBuffer($b)) : $b,
                };
                if ($b === null) {
                    return;
                }
                $reps[] = Representation::bytes($format, $b);
            }
            if ($reps === []) {
                return;
            }
            $d->data = new Data(new Item(...$reps));
        }
        [$x, $y] = $t['window']->screenToPoints(self::point($pt)->x, self::point($pt)->y);
        if ($t['window']->w->dataEvent('drop', $d, $x, $y)) {
            $effect[0] = $d->operation;
        }
    }

    /** Bytes of UTF-16 as a buffer Win32::str reads to its NUL. */
    private static function wideBuffer(string $b): CData
    {
        $b .= "\0\0";
        $buf = Win32::user32()->new('uint16_t[' . (strlen($b) >> 1) . ']');
        FFI::memcpy($buf, $b, (strlen($b) >> 1) * 2);
        return $buf;
    }

    /** The files of a data object's CF_HDROP (DragQueryFileW). @return list<string> */
    private static function paths(CData $data): array
    {
        $f = self::f();
        $obj = $f->cast('IDataObject *', $data);
        $fmt = $f->new('FORMATETC');
        self::fill($fmt, self::CF_HDROP);
        $m = $f->new('STGMEDIUM');
        if (($obj->v->GetData)($obj, FFI::addr($fmt), FFI::addr($m)) < 0) {
            return [];
        }
        $s = FFI::cdef('uint32_t DragQueryFileW(void *drop, uint32_t i, uint16_t *file, uint32_t n);', 'shell32.dll');
        $paths = [];
        try {
            for ($i = 0, $n = $s->DragQueryFileW($m->handle, 0xFFFFFFFF, null, 0); $i < $n; $i++) {
                $len = $s->DragQueryFileW($m->handle, $i, null, 0);
                $buf = $s->new('uint16_t[' . ($len + 1) . ']');
                $s->DragQueryFileW($m->handle, $i, $buf, $len + 1);
                if (($p = Win32::str($buf, $len)) !== '') { $paths[] = $p; }
            }
        } finally {
            $f->ReleaseStgMedium(FFI::addr($m));
        }
        return $paths;
    }

    /** @internal the tests': the objects alive now, by kind. @return array<string, int> */
    public static function alive(): array
    {
        return array_count_values(array_column(self::$objects, 'kind'));
    }

    /** @internal the tests': a data object of $data, held once, its pointer; and its GetData's bytes of format $cf, null for none. */
    public static function testData(Data $data, string $session = ''): CData
    {
        self::init();
        return self::dataObject($data, $session);
    }

    /** @internal the tests' */
    public static function testRead(CData $data, int $cf): ?string
    {
        return self::bytes($data, $cf);
    }

    /** @internal the tests': what a data object offers. @return array{formats: array<string, int>, session: string, files: bool} */
    public static function testOffer(CData $data): array
    {
        return self::offer($data);
    }

    /**
     * @internal the tests': $data dragged into $w and dropped at ($x, $y)
     * of its content, in pixels, through its drop target's vtable, as OLE
     * calls it (DragEnter, Drop): the effect the drop had.
     */
    public static function testDrop(Window $w, CData $data, int $x, int $y): int
    {
        $f = self::f();
        $id = self::$targets[Mem::addr($w->hwnd)] ?? throw new \RuntimeException('myphp: the window is no drop target');
        $t = $f->cast('IDropTarget *', FFI::addr(self::$mem[$id]));
        $p = Win32::user32()->new('POINT');
        [$p->x, $p->y] = [$x, $y];
        Win32::user32()->ClientToScreen($w->hwnd, FFI::addr($p));
        $pt = ($p->x & 0xFFFFFFFF) | (($p->y & 0xFFFFFFFF) << 32);
        $effect = $f->new('uint32_t');
        $effect->cdata = Operation::COPY;
        ($t->v->DragEnter)($t, $data, self::MK_LBUTTON, $pt, FFI::addr($effect));
        $effect->cdata = Operation::COPY;
        ($t->v->Drop)($t, $data, 0, $pt, FFI::addr($effect));
        return $effect->cdata;
    }

    /** @internal the tests': the files of its CF_HDROP. @return list<string> */
    public static function testPaths(CData $data): array
    {
        return self::paths($data);
    }
}
