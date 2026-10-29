<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use RuntimeException;

/**
 * The Mac's audio outputs, as Core Audio lists them (its devices of output
 * streams): each its unique ID, which AVSampleBufferAudioRenderer's
 * audioOutputDeviceUniqueID takes, and its name, as IINA's preferred audio
 * device lists them.
 *
 * @internal UI\VideoPlayerSettingsPages, Media\Player
 */
final class AudioDevices
{
    private const SYSTEM = 1, GLOBAL = 0x676c6f62, OUTPUT = 0x6f757470; // kAudioObjectSystemObject, kAudioObjectPropertyScopeGlobal 'glob', Output 'outp'
    private const DEFAULT = 0x644f7574; // kAudioHardwarePropertyDefaultOutputDevice 'dOut'
    private const DEVICES = 0x64657623, UID = 0x75696420, NAME = 0x6c6e616d, STREAMS = 0x73746d23; // 'dev#', 'uid ', 'lnam', 'stm#'
    private static ?FFI $ca = null;

    /** @return array<string, string> the outputs' names by their unique IDs, in the system's order */
    public static function outputs(): array
    {
        $ca = self::ca();
        $size = $ca->new('uint32_t');
        $addr = $ca->new('AudioObjectPropertyAddress');
        [$addr->mSelector, $addr->mScope, $addr->mElement] = [self::DEVICES, self::GLOBAL, 0];
        if ($ca->AudioObjectGetPropertyDataSize(self::SYSTEM, FFI::addr($addr), 0, null, FFI::addr($size)) !== 0) {
            throw new RuntimeException('myphp: Core Audio lists no devices');
        }
        $n = intdiv($size->cdata, 4);
        if ($n === 0) {
            return [];
        }
        $ids = $ca->new("uint32_t[$n]");
        if ($ca->AudioObjectGetPropertyData(self::SYSTEM, FFI::addr($addr), 0, null, FFI::addr($size), $ids) !== 0) {
            throw new RuntimeException('myphp: Core Audio lists no devices');
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $id = $ids[$i];
            [$addr->mSelector, $addr->mScope] = [self::STREAMS, self::OUTPUT];
            if ($ca->AudioObjectGetPropertyDataSize($id, FFI::addr($addr), 0, null, FFI::addr($size)) !== 0 || $size->cdata === 0) {
                continue; // no output streams: an input
            }
            $uid = self::string($ca, $id, self::UID);
            $name = self::string($ca, $id, self::NAME);
            if ($uid !== null) { $out[$uid] = $name ?? $uid; }
        }
        return $out;
    }

    /** The unique ID of the system's default output, null for none. */
    public static function defaultOutput(): ?string
    {
        $ca = self::ca();
        $addr = $ca->new('AudioObjectPropertyAddress');
        [$addr->mSelector, $addr->mScope, $addr->mElement] = [self::DEFAULT, self::GLOBAL, 0];
        $id = $ca->new('uint32_t');
        $size = $ca->new('uint32_t');
        $size->cdata = 4;
        if ($ca->AudioObjectGetPropertyData(self::SYSTEM, FFI::addr($addr), 0, null, FFI::addr($size), FFI::addr($id)) !== 0 || $id->cdata === 0) {
            return null;
        }
        return self::string($ca, $id->cdata, self::UID);
    }

    private static function ca(): FFI
    {
        $ca = self::$ca ??= FFI::cdef('
            typedef struct { uint32_t mSelector; uint32_t mScope; uint32_t mElement; } AudioObjectPropertyAddress;
            int32_t AudioObjectGetPropertyDataSize(uint32_t, const AudioObjectPropertyAddress*, uint32_t, const void*, uint32_t*);
            int32_t AudioObjectGetPropertyData(uint32_t, const AudioObjectPropertyAddress*, uint32_t, const void*, uint32_t*, void*);
        ', '/System/Library/Frameworks/CoreAudio.framework/CoreAudio');
        ObjC::boot(['Foundation']);
        return $ca;
    }

    /** A CFString property of device $id, null where it has none. */
    private static function string(FFI $ca, int $id, int $selector): ?string
    {
        $addr = $ca->new('AudioObjectPropertyAddress');
        [$addr->mSelector, $addr->mScope, $addr->mElement] = [$selector, self::GLOBAL, 0];
        $ref = $ca->new('void*');
        $size = $ca->new('uint32_t');
        $size->cdata = FFI::sizeof($ref);
        if ($ca->AudioObjectGetPropertyData($id, FFI::addr($addr), 0, null, FFI::addr($size), FFI::addr($ref)) !== 0 || FFI::isNull($ref)) {
            return null;
        }
        $s = ObjC::phpStr(ObjC::import($ref));
        ObjC::msg('v:', ObjC::import($ref), 'release'); // a CFString the caller owns
        return $s;
    }
}
