<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\iOS;

/**
 * The device the app runs on: its form factor (DeviceType::Desktop, Mobile,
 * Pad, Tv) and its system, so views and platform code can branch on
 * Device::isMobile(), Device::isPad(), Device::isDesktop(), Device::isTv()
 * or Device::isTouch().
 */
final class Device
{
    private static ?DeviceType $override = null;

    /** Overrides the device type (for previews or headless tests); null restores detection. */
    public static function setType(?DeviceType $type): void
    {
        self::$override = $type;
    }

    /** The form factor of the current device. */
    public static function type(): DeviceType
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $env = getenv('MYPHP_DEVICE_TYPE');
        if (is_string($env) && ($t = DeviceType::tryFrom(strtolower(trim($env)))) !== null) {
            return $t;
        }
        if (App::isIOS()) {
            try {
                $idiom = iOS::ffi()->myphp_ios_get_device_idiom();
                return match ($idiom) {
                    1 => DeviceType::Pad,
                    2 => DeviceType::Tv,
                    default => DeviceType::Mobile,
                };
            } catch (\Throwable) {
            }
            $d = Screen::displays()[0] ?? null;
            if ($d !== null && min($d->bounds->width, $d->bounds->height) >= 600) {
                return DeviceType::Pad;
            }
            return DeviceType::Mobile;
        }
        if (App::isAndroid()) {
            $d = Screen::displays()[0] ?? null;
            if ($d !== null && min($d->bounds->width, $d->bounds->height) >= 600) {
                return DeviceType::Pad;
            }
            return DeviceType::Mobile;
        }
        return DeviceType::Desktop;
    }

    public static function isDesktop(): bool
    {
        return self::type() === DeviceType::Desktop;
    }

    public static function isMobile(): bool
    {
        return self::type() === DeviceType::Mobile;
    }

    public static function isPad(): bool
    {
        return self::type() === DeviceType::Pad;
    }

    public static function isTv(): bool
    {
        return self::type() === DeviceType::Tv;
    }

    /** Whether the device is a touch device (a phone or a tablet). */
    public static function isTouch(): bool
    {
        $t = self::type();
        return $t === DeviceType::Mobile || $t === DeviceType::Pad;
    }

    public static function isIOS(): bool
    {
        return App::isIOS();
    }

    public static function isAndroid(): bool
    {
        return App::isAndroid();
    }
}