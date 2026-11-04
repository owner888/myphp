<?php
declare(strict_types=1);

namespace Examples\AirPodsTranslate;

use FFI;
use MyPHP\Native\ObjC;

/**
 * Detects system default audio input device using CoreAudio.
 */
final class AudioDeviceDetector
{
    private static ?FFI $ca = null;

    /** Returns the name of the current default audio input device, e.g. "kaka的AirPods". */
    public static function defaultInputName(): string
    {
        $ca = self::ca();
        $addr = $ca->new('AudioObjectPropertyAddress');
        [$addr->mSelector, $addr->mScope, $addr->mElement] = [0x64496e20, 0x676c6f62, 0]; // 'dIn ', 'glob'
        $id = $ca->new('uint32_t');
        $size = $ca->new('uint32_t');
        $size->cdata = 4;

        if ($ca->AudioObjectGetPropertyData(1, FFI::addr($addr), 0, null, FFI::addr($size), FFI::addr($id)) !== 0 || $id->cdata === 0) {
            return '未知输入设备';
        }

        [$addr->mSelector, $addr->mScope] = [0x6c6e616d, 0x676c6f62]; // 'lnam', 'glob'
        $ref = $ca->new('void*');
        $size->cdata = FFI::sizeof($ref);

        if ($ca->AudioObjectGetPropertyData($id->cdata, FFI::addr($addr), 0, null, FFI::addr($size), FFI::addr($ref)) !== 0 || FFI::isNull($ref)) {
            return '默认麦克风';
        }

        $s = ObjC::phpStr(ObjC::import($ref));
        ObjC::msg('v:', ObjC::import($ref), 'release');
        return $s ?: '默认麦克风';
    }

    /** Whether the current input device is likely an AirPods device. */
    public static function isAirPods(?string $name = null): bool
    {
        $name ??= self::defaultInputName();
        return stripos($name, 'airpods') !== false;
    }

    private static function ca(): FFI
    {
        if (self::$ca !== null) {
            return self::$ca;
        }
        self::$ca = FFI::cdef('
            typedef struct { uint32_t mSelector; uint32_t mScope; uint32_t mElement; } AudioObjectPropertyAddress;
            int32_t AudioObjectGetPropertyData(uint32_t, const AudioObjectPropertyAddress*, uint32_t, const void*, uint32_t*, void*);
        ', '/System/Library/Frameworks/CoreAudio.framework/CoreAudio');
        ObjC::boot(['Foundation']);
        return self::$ca;
    }
}
