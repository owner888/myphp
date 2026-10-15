<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\{CoreText, Metal, ObjC};
use MyPHP\UI\{GlyphAtlas, Text};
use RuntimeException;

/**
 * The application: one per process, as mygo's App. run() starts the native
 * event loop on the main thread and blocks; windows are created from
 * whenReady() callbacks, which run once the app has finished launching.
 */
final class App
{
    public const VERSION = '0.4.0-dev';
    /** @var list<Closure> */
    private static array $whenReady = [];
    private static bool $ready = false;
    private static bool $running = false;
    private static ?string $name = null;
    private static ?Menu $menu = null;
    private static $nsApp = null;
    private static ?Metal $metal = null;
    private static ?Text $text = null;
    private static float $scale = 1.0;
    /** Quit once the last window closes, as a document-less app does. */
    public static bool $quitOnLastWindowClosed = true;

    public static function whenReady(Closure $fn): void
    {
        if (self::$ready) {
            $fn();
            return;
        }
        self::$whenReady[] = $fn;
    }

    public static function isReady(): bool
    {
        return self::$ready;
    }

    /**
     * Starts the event loop and blocks until the app quits. With
     * MYPHP_GENERATE set to a path, writes the TypeScript client for the
     * bound services there instead and returns, as mygo generate does.
     */
    public static function run(): void
    {
        if (self::$running) {
            throw new RuntimeException('App::run called twice');
        }
        self::$running = true;
        if (($out = getenv('MYPHP_GENERATE')) !== false && $out !== '') {
            file_put_contents($out, TypeScript::generate());
            return;
        }
        ObjC::boot(['Foundation', 'AppKit', 'QuartzCore', 'Metal', 'MetalKit', 'CoreText', 'WebKit', 'UniformTypeIdentifiers']);
        self::$nsApp = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
        ObjC::msg('b:l', self::$nsApp, 'setActivationPolicy:', 0); // regular app, with a Dock icon
        ObjC::defineClass('MyPHPAppDelegate', 'NSObject', [
            'applicationDidFinishLaunching:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                self::$ready = true;
                self::installMenu(self::$menu ?? Menu::default());
                ObjC::msg('v:b', self::$nsApp, 'activateIgnoringOtherApps:', true);
                $callbacks = self::$whenReady;
                self::$whenReady = [];
                foreach ($callbacks as $fn) {
                    $fn();
                }
            }],
        ]);
        ObjC::msg('v:p', self::$nsApp, 'setDelegate:', ObjC::msg('p:', ObjC::alloc('MyPHPAppDelegate'), 'init'));
        ObjC::msg('v:', self::$nsApp, 'run');
    }

    /** The menu bar; Menu::default() unless set. Can be set before or after run(). */
    public static function setMenu(Menu $menu): void
    {
        self::$menu = $menu;
        if (self::$ready) {
            self::installMenu($menu);
        }
    }

    public static function menu(): ?Menu
    {
        return self::$menu;
    }

    private static function installMenu(Menu $menu): void
    {
        ObjC::msg('v:p', self::$nsApp, 'setMainMenu:', $menu->build());
    }

    public static function quit(): void
    {
        ObjC::msg('v:p', self::$nsApp, 'terminate:', null);
    }

    public static function name(): string
    {
        return self::$name ?? pathinfo($_SERVER['argv'][0] ?? 'MyPHP', PATHINFO_FILENAME);
    }

    /** Overrides the application name; call it before run(). */
    public static function setName(string $name): void
    {
        self::$name = $name;
    }

    /** The display's backing scale text is rasterized at. */
    public static function scale(): float
    {
        self::metal();
        return self::$scale;
    }

    /** The one Metal device, shared by every window. */
    public static function metal(): Metal
    {
        if (self::$metal === null) {
            self::$scale = ObjC::msg('d:', ObjC::msg('p:', ObjC::cls('NSScreen'), 'mainScreen'), 'backingScaleFactor');
            self::$text = new Text(new CoreText(self::$scale), new GlyphAtlas());
            self::$metal = new Metal();
            self::$metal->attachAtlas(self::$text->atlas, self::$scale);
        }
        return self::$metal;
    }

    /** The text engine every window measures and paints with. */
    public static function text(): Text
    {
        self::metal();
        return self::$text;
    }
}
