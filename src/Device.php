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
    private static ?string $channelOverride = null;

    /** Overrides the device type (for previews or headless tests); null restores detection. */
    public static function setType(?DeviceType $type): void
    {
        self::$override = $type;
    }

    /** Overrides the distribution channel ('apk', 'play', 'appstore', 'desktop'); null restores detection. */
    public static function setChannel(?string $channel): void
    {
        self::$channelOverride = $channel;
    }

    /**
     * Returns the package distribution channel:
     * - 'appstore': iOS / iPadOS App Store package
     * - 'play': Android Google Play Store package (built with -channel play, AAB splitNames, or installed via com.android.vending)
     * - 'apk': Android standalone / sideloaded APK (supports in-app APK self-upgrade)
     * - 'desktop': macOS / Windows / Linux desktop build
     */
    public static function channel(): string
    {
        if (self::$channelOverride !== null && self::$channelOverride !== '') {
            return self::$channelOverride;
        }
        $env = getenv('MYPHP_CHANNEL') ?: getenv('MYPHP_ANDROID_CHANNEL');
        if (is_string($env) && $env !== '') {
            return strtolower(trim($env));
        }
        if (App::isIOS()) {
            return 'appstore';
        }
        if (App::isAndroid()) {
            try {
                $cls = \MyPHP\Native\Jni::cls('com.owner888.myphp.MyphpActivity');
                if (\MyPHP\Native\Jni::callStatic($cls, 'isPlayStoreBuild', '()Z')) {
                    return 'play';
                }
            } catch (\Throwable) {
            }
            return 'apk';
        }
        return 'desktop';
    }

    /** Whether this Android build is distributed via Google Play Store (in-app APK self-update disabled). */
    public static function isPlayStore(): bool
    {
        return self::channel() === 'play';
    }

    /** Whether this build is distributed via Apple App Store. */
    public static function isAppStore(): bool
    {
        return self::channel() === 'appstore';
    }

    /** Whether this build is a standalone Android APK allowed to download and install APK updates in-app. */
    public static function canSelfUpdateApk(): bool
    {
        return self::channel() === 'apk';
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