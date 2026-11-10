<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * JPEGs and PNGs through the Windows Imaging Component, as ImageIO makes
 * them on macOS and GdkPixbuf or cairo on Linux (Platform\Windows\Media::
 * jpeg, jpegPixels; Window::capture; Shell::fileIcon): pixels as a bitmap of memory,
 * converted to what the encoder takes (the JPEG one's 24-bit BGR, the PNG
 * one's BGRA), written at a quality (the JPEG one's ImageQuality) into a
 * stream of memory; an image decoded from a stream of its bytes, scaled
 * down (Fant's interpolation) and converted to RGBA.
 *
 * @internal Platform\Windows
 */
final class Wic
{
    private const CLSID_FACTORY = 'cacaf262-9370-4615-a13b-9f5539da4c0a', IID_FACTORY = 'ec5ec8a9-c395-4314-9c77-54d7a935ff70',
        RGBA = 'f5c7ad2d-6a8d-43dd-a7a8-a29935261ae9', BGR = '6fddc324-4e03-4bfe-b185-3d77768dc90c', JPEG = '19e4a5aa-5662-4fc5-a0c0-1758028e1057',
        PBGRA = '6fddc324-4e03-4bfe-b185-3d77768dc910', BGRA = '6fddc324-4e03-4bfe-b185-3d77768dc90f', PNG = '1b7cfaf4-713f-473c-bbcd-6137425faeaf';
    /** WICBitmapEncoderNoCache, WICDecodeMetadataCacheOnDemand, WICBitmapInterpolationModeFant, STREAM_SEEK_SET, STREAM_SEEK_END, VT_R4. */
    private const NO_CACHE = 2, ON_DEMAND = 0, FANT = 3, SEEK_SET = 0, SEEK_END = 2, VT_R4 = 4;

    private static ?FFI $f = null;
    private static ?CData $factory = null;

    /** $rgba ($w by $h pixels, 4 bytes each) as a JPEG of $quality (0 to 1); the alpha left out. */
    public static function jpeg(string $rgba, int $w, int $h, float $quality): string
    {
        return self::encode($rgba, $w, $h, self::RGBA, self::JPEG, self::BGR, $quality);
    }

    /** $bgra ($w by $h pixels, rows top down) as a PNG, its alpha kept: premultiplied (a frame read back, D3d::readBack) or straight (the shell's icons, Shell::fileIcon). */
    public static function png(string $bgra, int $w, int $h, bool $premultiplied = true): string
    {
        return self::encode($bgra, $w, $h, $premultiplied ? self::PBGRA : self::BGRA, self::PNG, self::BGRA, null);
    }

    /**
     * $bytes ($w by $h, 4 bytes each, of the pixel format $from) as an
     * image of container $container, converted to the pixel format $to its
     * encoder takes, at $quality (the encoder's ImageQuality) unless null.
     */
    private static function encode(string $bytes, int $w, int $h, string $from, string $container, string $to, ?float $quality): string
    {
        if ($w <= 0 || $h <= 0 || strlen($bytes) !== $w * $h * 4) {
            throw new RuntimeException("myphp: $w by $h pixels are not " . strlen($bytes) . ' bytes');
        }
        $f = self::f();
        $wic = self::factory();
        $held = [];
        try {
            $fromFormat = Com::guid($from);
            $toFormat = Com::guid($to);
            $pixels = $f->new('uint8_t[' . strlen($bytes) . ']');
            FFI::memcpy($pixels, $bytes, strlen($bytes));
            $bitmap = $held[] = self::out(fn($out) => ($wic->v->CreateBitmapFromMemory)($wic, $w, $h, FFI::addr($fromFormat), $w * 4, strlen($bytes), $pixels, $out), 'CreateBitmapFromMemory');
            $conv = $held[] = Com::typed($f, self::out(fn($out) => ($wic->v->CreateFormatConverter)($wic, $out), 'CreateFormatConverter'), 'Converter *');
            self::check(($conv->v->Initialize)($conv, $bitmap, FFI::addr($toFormat), 0, null, 0.0, 0), 'IWICFormatConverter::Initialize');
            $stream = $held[] = Com::typed($f, self::out(fn($out) => $f->CreateStreamOnHGlobal(null, 1, $out), 'CreateStreamOnHGlobal'), 'Stream *');
            $container = Com::guid($container);
            $enc = $held[] = Com::typed($f, self::out(fn($out) => ($wic->v->CreateEncoder)($wic, FFI::addr($container), null, $out), 'CreateEncoder'), 'Encoder *');
            self::check(($enc->v->Initialize)($enc, $stream, self::NO_CACHE), 'IWICBitmapEncoder::Initialize');
            $frameOut = $f->new('void *');
            $propsOut = $f->new('void *');
            self::check(($enc->v->CreateNewFrame)($enc, FFI::addr($frameOut), FFI::addr($propsOut)), 'CreateNewFrame');
            $frame = $held[] = Com::typed($f, $frameOut, 'FrameEncode *');
            $props = $held[] = Com::typed($f, $propsOut, 'PropertyBag *');
            if ($quality !== null) {
                $name = \MyPHP\Platform\Windows\Win32::wide('ImageQuality');
                $bag = $f->new('PROPBAG2');
                $bag->pstrName = $f->cast('uint16_t *', FFI::addr($name[0]));
                $value = $f->new('VARIANT');
                $value->vt = self::VT_R4;
                $value->fltVal = max(0.0, min(1.0, $quality));
                self::check(($props->v->Write)($props, 1, FFI::addr($bag), FFI::addr($value)), 'IPropertyBag2::Write ImageQuality');
            }
            self::check(($frame->v->Initialize)($frame, $props), 'IWICBitmapFrameEncode::Initialize');
            self::check(($frame->v->SetSize)($frame, $w, $h), 'SetSize');
            self::check(($frame->v->SetPixelFormat)($frame, FFI::addr($toFormat)), 'SetPixelFormat');
            self::check(($frame->v->WriteSource)($frame, $conv, null), 'WriteSource');
            self::check(($frame->v->Commit)($frame), 'IWICBitmapFrameEncode::Commit');
            self::check(($enc->v->Commit)($enc), 'IWICBitmapEncoder::Commit');
            $size = $f->new('uint64_t');
            self::check(($stream->v->Seek)($stream, 0, self::SEEK_END, FFI::addr($size)), 'IStream::Seek');
            self::check(($stream->v->Seek)($stream, 0, self::SEEK_SET, null), 'IStream::Seek');
            $n = (int)$size->cdata;
            $buf = $f->new("uint8_t[$n]");
            $read = $f->new('uint32_t');
            self::check(($stream->v->Read)($stream, $buf, $n, FFI::addr($read)), 'IStream::Read');
            return FFI::string($buf, $read->cdata);
        } finally {
            foreach (array_reverse($held) as $o) { Com::release($o); }
        }
    }

    /** The pixels of the image of $bytes (a JPEG, as WIC decodes any), RGBA, scaled down to $max on a side. */
    public static function pixels(string $bytes, int $max): string
    {
        $f = self::f();
        $wic = self::factory();
        $held = [];
        try {
            static $shlwapi = null;
            $shlwapi ??= FFI::cdef('void *SHCreateMemStream(const void *init, uint32_t size);', 'shlwapi.dll');
            $data = $f->new('uint8_t[' . max(1, strlen($bytes)) . ']');
            FFI::memcpy($data, $bytes, strlen($bytes));
            $stream = $shlwapi->SHCreateMemStream($data, strlen($bytes)) ?? throw new RuntimeException('myphp: no stream of the image\'s bytes');
            $held[] = $stream;
            $dec = $held[] = Com::typed($f, self::out(fn($out) => ($wic->v->CreateDecoderFromStream)($wic, $stream, null, self::ON_DEMAND, $out), 'WIC decodes no image of these bytes: CreateDecoderFromStream'), 'Decoder *');
            $source = $held[] = Com::typed($f, self::out(fn($out) => ($dec->v->GetFrame)($dec, 0, $out), 'GetFrame'), 'Source *');
            $w = $f->new('uint32_t'); $h = $f->new('uint32_t');
            self::check(($source->v->GetSize)($source, FFI::addr($w), FFI::addr($h)), 'GetSize');
            [$width, $height] = [$w->cdata, $h->cdata];
            if (max($width, $height) > $max) {
                $k = $max / max($width, $height);
                [$width, $height] = [max(1, (int)round($width * $k)), max(1, (int)round($height * $k))];
                $scaler = $held[] = Com::typed($f, self::out(fn($out) => ($wic->v->CreateBitmapScaler)($wic, $out), 'CreateBitmapScaler'), 'Scaler *');
                self::check(($scaler->v->Initialize)($scaler, $source, $width, $height, self::FANT), 'IWICBitmapScaler::Initialize');
                $source = Com::typed($f, $scaler, 'Source *');
            }
            $rgbaFormat = Com::guid(self::RGBA);
            $conv = $held[] = Com::typed($f, self::out(fn($out) => ($wic->v->CreateFormatConverter)($wic, $out), 'CreateFormatConverter'), 'Converter *');
            self::check(($conv->v->Initialize)($conv, $source, FFI::addr($rgbaFormat), 0, null, 0.0, 0), 'IWICFormatConverter::Initialize');
            $n = $width * $height * 4;
            $buf = $f->new("uint8_t[$n]");
            $src = Com::typed($f, $conv, 'Source *');
            self::check(($src->v->CopyPixels)($src, null, $width * 4, $n, $buf), 'CopyPixels');
            return FFI::string($buf, $n);
        } finally {
            foreach (array_reverse($held) as $o) { Com::release($o); }
        }
    }

    /** What $call puts in the `void **` it is given, held; throws as $what failed. */
    private static function out(\Closure $call, string $what): CData
    {
        $out = self::f()->new('void *');
        self::check($call(FFI::addr($out)), $what);
        if (FFI::isNull($out)) {
            throw new RuntimeException("myphp: $what gave nothing");
        }
        return Com::typed(self::f(), $out, 'void *');
    }

    private static function check(int $hr, string $what): void
    {
        if ($hr < 0) {
            throw new RuntimeException(sprintf('myphp: %s failed (HRESULT 0x%08X)', $what, $hr & 0xFFFFFFFF));
        }
    }

    /** The imaging factory, made once (COM of an apartment of this thread's, as the app's is). */
    private static function factory(): CData
    {
        if (self::$factory === null) {
            $f = self::f();
            $f->CoInitializeEx(null, 2);
            $clsid = Com::guid(self::CLSID_FACTORY);
            $iid = Com::guid(self::IID_FACTORY);
            $out = $f->new('void *');
            self::check($f->CoCreateInstance(FFI::addr($clsid), null, 1, FFI::addr($iid), FFI::addr($out)), 'CoCreateInstance of the WIC imaging factory'); // CLSCTX_INPROC_SERVER
            self::$factory = Com::typed($f, $out, 'Factory *');
        }
        return self::$factory;
    }

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct {
                void *r0[4];
                int32_t (*CreateDecoderFromStream)(void *self, void *stream, const void *vendor, int32_t options, void **decoder);
                void *r5[3];
                int32_t (*CreateEncoder)(void *self, const void *container, const void *vendor, void **encoder);
                void *r9;
                int32_t (*CreateFormatConverter)(void *self, void **converter);
                int32_t (*CreateBitmapScaler)(void *self, void **scaler);
                void *r12[8];
                int32_t (*CreateBitmapFromMemory)(void *self, uint32_t w, uint32_t h, const void *format, uint32_t stride, uint32_t size, void *buffer, void **bitmap);
            } FactoryVtbl;
            typedef struct { FactoryVtbl *v; } Factory;
            typedef struct { void *r0[13]; int32_t (*GetFrame)(void *self, uint32_t index, void **frame); } DecoderVtbl;
            typedef struct { DecoderVtbl *v; } Decoder;
            typedef struct { void *r0[3]; int32_t (*GetSize)(void *self, uint32_t *w, uint32_t *h); void *r4[3]; int32_t (*CopyPixels)(void *self, const void *rect, uint32_t stride, uint32_t size, void *buffer); } SourceVtbl;
            typedef struct { SourceVtbl *v; } Source;
            typedef struct { void *r0[8]; int32_t (*Initialize)(void *self, void *source, const void *format, int32_t dither, void *palette, double alpha, int32_t paletteType); } ConverterVtbl;
            typedef struct { ConverterVtbl *v; } Converter;
            typedef struct { void *r0[8]; int32_t (*Initialize)(void *self, void *source, uint32_t w, uint32_t h, int32_t mode); } ScalerVtbl;
            typedef struct { ScalerVtbl *v; } Scaler;
            typedef struct { void *r0[3]; int32_t (*Initialize)(void *self, void *stream, int32_t cache); void *r4[6]; int32_t (*CreateNewFrame)(void *self, void **frame, void **options); int32_t (*Commit)(void *self); } EncoderVtbl;
            typedef struct { EncoderVtbl *v; } Encoder;
            typedef struct {
                void *r0[3];
                int32_t (*Initialize)(void *self, void *options);
                int32_t (*SetSize)(void *self, uint32_t w, uint32_t h);
                void *r5;
                int32_t (*SetPixelFormat)(void *self, void *format);
                void *r7[4];
                int32_t (*WriteSource)(void *self, void *source, const void *rect);
                int32_t (*Commit)(void *self);
            } FrameEncodeVtbl;
            typedef struct { FrameEncodeVtbl *v; } FrameEncode;
            typedef struct { uint32_t dwType; uint16_t vt, cfType; uint32_t dwHint; uint16_t *pstrName; uint8_t clsid[16]; } PROPBAG2;
            typedef struct { uint16_t vt, r1, r2, r3; float fltVal; uint32_t pad; uint64_t rest; } VARIANT;
            typedef struct { void *r0[4]; int32_t (*Write)(void *self, uint32_t n, PROPBAG2 *bag, VARIANT *values); } PropertyBagVtbl;
            typedef struct { PropertyBagVtbl *v; } PropertyBag;
            typedef struct { void *r0[3]; int32_t (*Read)(void *self, void *buffer, uint32_t n, uint32_t *read); void *r4; int32_t (*Seek)(void *self, int64_t move, uint32_t origin, uint64_t *position); } StreamVtbl;
            typedef struct { StreamVtbl *v; } Stream;
            int32_t CoInitializeEx(void *reserved, uint32_t model);
            int32_t CoCreateInstance(const void *clsid, void *outer, uint32_t context, const void *iid, void **out);
            int32_t CreateStreamOnHGlobal(void *global, int32_t deleteOnRelease, void **stream);
            C, 'ole32.dll');
    }
}
