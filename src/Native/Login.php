<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\App;
use RuntimeException;

/**
 * Starting at login (mygo's login.go and darwin login.go): on macOS 13
 * and later the app is a login item of the user through SMAppService
 * (System Settings > General > Login Items); on macOS 12 a launch agent
 * opens it. Both need an app bundle.
 *
 * @internal
 */
final class Login
{
    /** Passed by the launch agent that starts the app at login (macOS 12); taken out of the command line as the app runs. */
    public const ARG = '--myphp-opened-at-login';
    private const NOT_REGISTERED = 0, ENABLED = 1, REQUIRES_APPROVAL = 2;
    /** Whether the command line had ARG, or the launch's Apple Event said the app started as a login item. */
    public static bool $openedAtLogin = false;

    /** Takes ARG out of the command line. */
    public static function takeArg(): void
    {
        $argv = $_SERVER['argv'] ?? [];
        $i = array_search(self::ARG, $argv, true);
        if ($i !== false && $i > 0) {
            array_splice($argv, $i, 1);
            $_SERVER['argv'] = $argv;
            $GLOBALS['argv'] = $argv;
            self::$openedAtLogin = true;
        }
    }

    /** Whether the Apple Event being handled, the one that launched the app, marks it as started at login (keyAELaunchedAsLogInItem). */
    public static function launchedAsLoginItem(): bool
    {
        $ev = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSAppleEventManager'), 'sharedAppleEventManager'), 'currentAppleEvent');
        if ($ev === null) {
            return false;
        }
        $prop = ObjC::msg('p:i', $ev, 'paramDescriptorForKeyword:', 0x70726474); // keyAEPropData 'prdt'
        return $prop !== null && (ObjC::msg('i:', $prop, 'enumCodeValue') & 0xffffffff) === 0x6c676974; // 'lgit'
    }

    /** The SMAppService of the app, or null before macOS 13. */
    private static function service()
    {
        ObjC::$c->dlopen('/System/Library/Frameworks/ServiceManagement.framework/ServiceManagement', 2);
        $cls = ObjC::$c->objc_getClass('SMAppService'); // ObjC::cls throws for a class that is not there
        return $cls === null ? null : ObjC::msg('p:', $cls, 'mainAppService');
    }

    private static function bundle(): string
    {
        return Bundle::path() ?? throw new RuntimeException('myphp: starting at login needs an app bundle: build the app with myphp build');
    }

    private static function id(): string
    {
        $id = Bundle::info('CFBundleIdentifier');
        return $id !== null && $id !== '' ? $id : App::name();
    }

    public static function set(bool $open): void
    {
        $app = self::bundle();
        $svc = self::service();
        if ($svc === null) {
            self::launchAgent($open, $app);
            return;
        }
        if (!$open && ObjC::msg('l:', $svc, 'status') === self::NOT_REGISTERED) {
            return;
        }
        $err = ObjC::$c->new('void*[1]');
        if (!ObjC::msg('b:p', $svc, $open ? 'registerAndReturnError:' : 'unregisterAndReturnError:', $err)) {
            throw new RuntimeException('myphp: ' . ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription')));
        }
        if ($open && ObjC::msg('l:', $svc, 'status') === self::REQUIRES_APPROVAL) {
            throw new RuntimeException('myphp: allow ' . App::name() . ' to open at login in System Settings > General > Login Items');
        }
    }

    public static function enabled(): bool
    {
        if (Bundle::path() === null) {
            return false;
        }
        $svc = self::service();
        if ($svc !== null) {
            return ObjC::msg('l:', $svc, 'status') === self::ENABLED;
        }
        return is_file(self::agentPath());
    }

    private static function agentPath(): string
    {
        return getenv('HOME') . '/Library/LaunchAgents/' . self::id() . '.plist';
    }

    /** Adds or removes a launch agent that opens the app at login (macOS 12). */
    private static function launchAgent(bool $open, string $app): void
    {
        $path = self::agentPath();
        if (!$open) {
            if (is_file($path) && !@unlink($path)) {
                throw new RuntimeException("myphp: cannot remove $path");
            }
            return;
        }
        $e = fn(string $s): string => htmlspecialchars($s, ENT_XML1);
        $plist = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>Label</key>
	<string>' . $e(self::id()) . '</string>
	<key>ProgramArguments</key>
	<array>
		<string>/usr/bin/open</string>
		<string>-a</string>
		<string>' . $e($app) . '</string>
		<string>--args</string>
		<string>' . self::ARG . '</string>
	</array>
	<key>RunAtLoad</key>
	<true/>
</dict>
</plist>
';
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) || @file_put_contents($path, $plist) === false) {
            throw new RuntimeException("myphp: cannot write $path");
        }
    }
}
