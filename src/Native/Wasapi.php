<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Platform\Windows\Win32;
use RuntimeException;

/**
 * The sound output on Windows: a WASAPI stream of the shared mode (the
 * system's mixer), of interleaved 32-bit floats at the rate and channels
 * asked, which the mixer converts to its own (AUTOCONVERTPCM), its buffer
 * $ahead seconds. PHP has one thread, so the callers write from a timer
 * only what it takes now (room(): the buffer less its padding), which
 * never waits, as Pcm's do; delay() is the padding, what was written and
 * is not heard yet, which the player's clock goes by. Outputs are the
 * active render endpoints, named "wasapi/<endpoint id>" as mpv names them.
 *
 * @internal AvRenderers, SoundDevice
 */
final class Wasapi implements Sound
{
    private const CLSID_ENUMERATOR = 'bcde0395-e52f-467c-8e3d-c4579291692e', IID_ENUMERATOR = 'a95664d2-9614-4f35-a746-de8db63617e6',
        IID_CLIENT = '1cb9ad4c-dbfa-4c32-b178-c2f568a703b2', IID_RENDER = 'f294acfc-3146-4483-a7bf-addca7c260e2',
        FLOAT = '00000003-0000-0010-8000-00aa00389b71', FRIENDLY_NAME = 'a45c254e-df1c-4efd-8020-67d146a850e0';
    /** CLSCTX_ALL, eRender, eConsole, DEVICE_STATE_ACTIVE, STGM_READ; AUDCLNT_STREAMFLAGS_AUTOCONVERTPCM | SRC_DEFAULT_QUALITY. */
    private const CLSCTX_ALL = 0x17, RENDER = 0, CONSOLE = 0, ACTIVE = 1, STGM_READ = 0, FLAGS = 0x80000000 | 0x08000000;

    private static ?FFI $f = null;

    /** The output's IMMDevice, IAudioClient and IAudioRenderClient. */
    private CData $device, $client, $render;
    /** The frames its buffer holds; the bytes of a frame. */
    private int $buffer, $frame;
    private bool $started = false, $closed = false;

    private function __construct() {}

    /**
     * An output of $channels channels at $rate holding $ahead seconds:
     * $device, one of outputs()' names, else the system's default; throws
     * why it did not open.
     */
    public static function open(int $rate, int $channels, float $ahead, ?string $device = null): self
    {
        $f = self::f();
        $o = new self();
        $o->frame = $channels * 4;
        $enum = self::enumerator();
        try {
            $out = $f->new('void *');
            if ($device === null) {
                $hr = ($enum->v->GetDefaultAudioEndpoint)($enum, self::RENDER, self::CONSOLE, FFI::addr($out));
                if ($hr < 0) {
                    throw new RuntimeException(sprintf('audio: WASAPI: no output device (HRESULT 0x%08X)', $hr & 0xFFFFFFFF));
                }
            } else {
                str_starts_with($device, 'wasapi/') || throw new RuntimeException("audio: no output \"$device\" (wasapi/<endpoint id>)");
                $hr = ($enum->v->GetDevice)($enum, Win32::wide(substr($device, 7)), FFI::addr($out));
                if ($hr < 0) {
                    throw new RuntimeException(sprintf('audio: WASAPI: no output %s (HRESULT 0x%08X)', $device, $hr & 0xFFFFFFFF));
                }
            }
        } finally {
            Com::release($enum);
        }
        $o->device = Com::typed($f, $out, 'Device *');
        try {
            $iid = Com::guid(self::IID_CLIENT);
            $c = $f->new('void *');
            Com::check(($o->device->v->Activate)($o->device, FFI::addr($iid), self::CLSCTX_ALL, null, FFI::addr($c)), 'audio: WASAPI: Activate');
            $o->client = Com::typed($f, $c, 'Client *');
            // WAVEFORMATEXTENSIBLE of IEEE floats: front left and right for two channels, the speakers' mask left to the system otherwise.
            $sub = Com::guid(self::FLOAT);
            $format = $f->new('uint8_t[40]');
            $bytes = pack('vvVVvvvvV', 0xFFFE, $channels, $rate, $rate * $channels * 4, $channels * 4, 32, 22, 32, $channels === 2 ? 0x3 : 0) . FFI::string(FFI::addr($sub), 16);
            FFI::memcpy($format, $bytes, 40);
            Com::check(($o->client->v->Initialize)($o->client, 0, self::FLAGS, (int)round($ahead * 1e7), 0, $format, null), 'audio: WASAPI: Initialize'); // AUDCLNT_SHAREMODE_SHARED
            $n = $f->new('uint32_t');
            Com::check(($o->client->v->GetBufferSize)($o->client, FFI::addr($n)), 'audio: WASAPI: GetBufferSize');
            $o->buffer = $n->cdata;
            $iid = Com::guid(self::IID_RENDER);
            $r = $f->new('void *');
            Com::check(($o->client->v->GetService)($o->client, FFI::addr($iid), FFI::addr($r)), 'audio: WASAPI: GetService');
            $o->render = Com::typed($f, $r, 'Render *');
        } catch (RuntimeException $e) {
            if (isset($o->client)) { Com::release($o->client); }
            Com::release($o->device);
            throw $e;
        }
        $o->closed = false;
        return $o;
    }

    /**
     * The outputs there are, by their names ("wasapi/<endpoint id>") with
     * their friendly names: the active render endpoints; none where WASAPI
     * tells none. @return array<string, string>
     */
    public static function outputs(): array
    {
        $f = self::f();
        try {
            $enum = self::enumerator();
        } catch (RuntimeException) {
            return [];
        }
        $out = [];
        $col = $f->new('void *');
        if (($enum->v->EnumAudioEndpoints)($enum, self::RENDER, self::ACTIVE, FFI::addr($col)) >= 0) {
            $c = Com::typed($f, $col, 'Collection *');
            $count = $f->new('uint32_t');
            ($c->v->GetCount)($c, FFI::addr($count));
            for ($i = 0; $i < $count->cdata; $i++) {
                $d = $f->new('void *');
                if (($c->v->Item)($c, $i, FFI::addr($d)) < 0) {
                    continue;
                }
                $dev = Com::typed($f, $d, 'Device *');
                $id = $f->new('uint16_t *');
                if (($dev->v->GetId)($dev, FFI::addr($id)) >= 0) {
                    $name = self::friendlyName($dev);
                    $uid = Win32::str($id);
                    $f->CoTaskMemFree($id);
                    $out["wasapi/$uid"] = $name === '' ? $uid : $name;
                }
                Com::release($d);
            }
            Com::release($col);
        }
        Com::release($enum);
        return $out;
    }

    /** The endpoint's PKEY_Device_FriendlyName, '' where it tells none. */
    private static function friendlyName(CData $dev): string
    {
        $f = self::f();
        $ps = $f->new('void *');
        if (($dev->v->OpenPropertyStore)($dev, self::STGM_READ, FFI::addr($ps)) < 0) {
            return '';
        }
        $store = Com::typed($f, $ps, 'PropertyStore *');
        $key = $f->new('PROPERTYKEY');
        $g = Com::guid(self::FRIENDLY_NAME);
        FFI::memcpy(FFI::addr($key->fmtid), FFI::string(FFI::addr($g), 16), 16);
        $key->pid = 14;
        $v = $f->new('PROPVARIANT');
        $name = '';
        if (($store->v->GetValue)($store, FFI::addr($key), FFI::addr($v)) >= 0 && $v->vt === 31) { // VT_LPWSTR
            $name = Win32::str($f->cast('uint16_t *', $v->ptr));
        }
        $f->PropVariantClear(FFI::addr($v));
        Com::release($ps);
        return $name;
    }

    /** The IMMDeviceEnumerator, held. */
    private static function enumerator(): CData
    {
        $f = self::f();
        $f->CoInitializeEx(null, 2); // apartment threaded, as the app's thread is (Platform\Windows\Backend): a test's too
        $clsid = Com::guid(self::CLSID_ENUMERATOR);
        $iid = Com::guid(self::IID_ENUMERATOR);
        $out = $f->new('void *');
        $hr = $f->CoCreateInstance(FFI::addr($clsid), null, self::CLSCTX_ALL, FFI::addr($iid), FFI::addr($out));
        if ($hr < 0 || FFI::isNull($out)) {
            throw new RuntimeException(sprintf('audio: WASAPI: no device enumerator (HRESULT 0x%08X)', $hr & 0xFFFFFFFF));
        }
        return Com::typed($f, $out, 'Enumerator *');
    }

    public function name(): string
    {
        return 'WASAPI';
    }

    /** The frames it takes now without waiting: the buffer less its padding. */
    public function room(): int
    {
        return $this->buffer - $this->padding();
    }

    private function padding(): int
    {
        $n = self::f()->new('uint32_t');
        Com::check(($this->client->v->GetCurrentPadding)($this->client, FFI::addr($n)), 'audio: WASAPI: GetCurrentPadding');
        return $n->cdata;
    }

    /** Plays $pcm (whole frames) after what it was written, at most room() of them; the stream started at the first; throws as the device failed (it went away). */
    public function write(string $pcm): void
    {
        $frames = min(intdiv(strlen($pcm), $this->frame), $this->room());
        if ($frames <= 0) {
            return;
        }
        $f = self::f();
        $p = $f->new('uint8_t *');
        Com::check(($this->render->v->GetBuffer)($this->render, $frames, FFI::addr($p)), 'audio: WASAPI: GetBuffer');
        FFI::memcpy($p, $pcm, $frames * $this->frame);
        Com::check(($this->render->v->ReleaseBuffer)($this->render, $frames, 0), 'audio: WASAPI: ReleaseBuffer');
        if (!$this->started) {
            Com::check(($this->client->v->Start)($this->client), 'audio: WASAPI: Start');
            $this->started = true;
        }
    }

    /** The frames written not heard yet: the padding. */
    public function delay(): ?int
    {
        return $this->padding();
    }

    /** Drops what it holds, unheard: stopped and reset, started again at the next write. */
    public function flush(): void
    {
        ($this->client->v->Stop)($this->client);
        ($this->client->v->Reset)($this->client);
        $this->started = false;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        ($this->client->v->Stop)($this->client);
        Com::release($this->render);
        Com::release($this->client);
        Com::release($this->device);
    }

    public function __destruct()
    {
        if (isset($this->render)) { $this->close(); }
    }

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct { void *r0[3]; int32_t (*EnumAudioEndpoints)(void *self, int32_t flow, uint32_t mask, void **devices); int32_t (*GetDefaultAudioEndpoint)(void *self, int32_t flow, int32_t role, void **device); int32_t (*GetDevice)(void *self, const void *id, void **device); } EnumeratorVtbl;
            typedef struct { EnumeratorVtbl *v; } Enumerator;
            typedef struct { void *r0[3]; int32_t (*GetCount)(void *self, uint32_t *n); int32_t (*Item)(void *self, uint32_t i, void **device); } CollectionVtbl;
            typedef struct { CollectionVtbl *v; } Collection;
            typedef struct { void *r0[3]; int32_t (*Activate)(void *self, const void *iid, uint32_t context, void *params, void **out); int32_t (*OpenPropertyStore)(void *self, uint32_t access, void **store); int32_t (*GetId)(void *self, uint16_t **id); } DeviceVtbl;
            typedef struct { DeviceVtbl *v; } Device;
            typedef struct { uint8_t fmtid[16]; uint32_t pid; } PROPERTYKEY;
            typedef struct { uint16_t vt, r1, r2, r3; void *ptr; uint64_t rest; } PROPVARIANT;
            typedef struct { void *r0[5]; int32_t (*GetValue)(void *self, const PROPERTYKEY *key, PROPVARIANT *value); } PropertyStoreVtbl;
            typedef struct { PropertyStoreVtbl *v; } PropertyStore;
            typedef struct {
                void *r0[3];
                int32_t (*Initialize)(void *self, int32_t mode, uint32_t flags, int64_t duration, int64_t period, const void *format, const void *session);
                int32_t (*GetBufferSize)(void *self, uint32_t *frames);
                void *r5;
                int32_t (*GetCurrentPadding)(void *self, uint32_t *frames);
                void *r7[3];
                int32_t (*Start)(void *self);
                int32_t (*Stop)(void *self);
                int32_t (*Reset)(void *self);
                void *r13;
                int32_t (*GetService)(void *self, const void *iid, void **out);
            } ClientVtbl;
            typedef struct { ClientVtbl *v; } Client;
            typedef struct { void *r0[3]; int32_t (*GetBuffer)(void *self, uint32_t frames, uint8_t **data); int32_t (*ReleaseBuffer)(void *self, uint32_t frames, uint32_t flags); } RenderVtbl;
            typedef struct { RenderVtbl *v; } Render;
            int32_t CoCreateInstance(const void *clsid, void *outer, uint32_t context, const void *iid, void **out);
            int32_t PropVariantClear(PROPVARIANT *v);
            int32_t CoInitializeEx(void *reserved, uint32_t model);
            void CoTaskMemFree(void *p);
            C, 'ole32.dll');
    }
}
