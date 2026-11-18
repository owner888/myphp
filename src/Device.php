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
    private static ?string $detectedChannel = null;

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
     * - 'appstore': Apple App Store package (all iOS, iPadOS, tvOS, visionOS builds; and macOS Mac App Store builds)
     * - 'dmg': macOS standalone .dmg / .app build distributed outside the Mac App Store (supports in-app self-update)
     * - 'play': Android Google Play Store package (built with -channel play, AAB splitNames, or installed via com.android.vending)
     * - 'apk': Android standalone / sideloaded APK (supports in-app APK self-upgrade)
     * - 'desktop': Windows / Linux standalone desktop build
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
        if (App::isIOS() || App::isTvOS() || App::isVisionOS()) {
            return 'appstore';
        }
        if (App::isMacOS()) {
            if (\MyPHP\Native\Bundle::info('MyPHPChannel') === 'appstore') {
                return 'appstore';
            }
            $bundle = \MyPHP\Native\Bundle::path();
            if ($bundle !== null && is_file("$bundle/Contents/_MASReceipt/receipt")) {
                return 'appstore';
            }
            return 'dmg';
        }
        if (self::$detectedChannel !== null) {
            return self::$detectedChannel;
        }
        if (App::isAndroid()) {
            try {
                $cls = \MyPHP\Native\Jni::cls('com.owner888.myphp.MyphpActivity');
                if (\MyPHP\Native\Jni::callStatic($cls, 'isPlayStoreBuild', '()Z')) {
                    return self::$detectedChannel = 'play';
                }
            } catch (\Throwable) {
            }
            return self::$detectedChannel = 'apk';
        }
        return 'desktop';
    }

    /** Whether this Android build is distributed via Google Play Store (in-app APK self-update disabled). */
    public static function isPlayStore(): bool
    {
        return self::channel() === 'play';
    }

    /** Whether this build is distributed via Apple App Store (iOS, iPadOS, tvOS, visionOS, or Mac App Store). */
    public static function isAppStore(): bool
    {
        return self::channel() === 'appstore';
    }

    /** Whether this build is distributed via any official store (Apple App Store or Google Play Store). */
    public static function isStoreBuild(): bool
    {
        return in_array(self::channel(), ['appstore', 'play'], true);
    }

    /** Whether this build is a standalone macOS .dmg build outside the Mac App Store. */
    public static function isDmg(): bool
    {
        return self::channel() === 'dmg';
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

    public static function isTvOS(): bool
    {
        return App::isTvOS();
    }

    public static function isVisionOS(): bool
    {
        return App::isVisionOS();
    }

    public static function isMacOS(): bool
    {
        return App::isMacOS();
    }

    public static function isApple(): bool
    {
        return App::isApple();
    }

    public static function isAndroid(): bool
    {
        return App::isAndroid();
    }
}