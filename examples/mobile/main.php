<?php
// A phone app in native UI: a tab bar, a navigation bar over a router's
// pages, a list of 200 000 rows, a form with the keyboard and the system's
// pickers, an HTTPS request, the sheets, and a settings page of inset
// grouped sections: the text styles, haptics, and the system's settings
// the theme follows. The same app runs on the
// desktop in a phone-sized window, where the list's scrolling is measured.
// Run: myphp run -d android examples/mobile   (ios, macos)
declare(strict_types=1);

use MyPHP\{App, Dialog, FileFilter, Haptics, Http, OpenDialogOptions, Window, WindowOptions};
use MyPHP\Native\{Jni, ObjC};
use MyPHP\UI\{Content, Image, SVG};

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/src/BatteryManager.php';

final class Mobile
{
    public const ROWS = 200_000;
    /** The tab shown, and each tab's pages. */
    public int $tab = 0;
    public NavigationPath $homePath, $formPath, $mePath;
    public string $name = '';
    public ?Image $photo = null;
    public string $photoPath = '';
    /** @var list<string> */
    public array $files = [];
    public string $http = '';
    public bool $sheet = false, $actions = false, $alert = false;
    public string $lastAction = '';
    public string $nativeBattery = '点击读取原生电量 (JNIgen / FFIgen)';
    public int $refreshes = 0;
    public SVG $homeIcon, $formIcon, $meIcon;

    public function __construct()
    {
        [$this->homePath, $this->formPath, $this->mePath] = [new NavigationPath(), new NavigationPath(), new NavigationPath()];
        $this->homeIcon = SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M3 11 12 3l9 8v10h-6v-6H9v6H3z"/></svg>');
        $this->formIcon = SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v4H4zm0 6h16v4H4zm0 6h10v4H4z"/></svg>');
        $this->meIcon = SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0z"/></svg>');
        if (App::isAndroid() || App::isIOS()) {
            try { $this->queryNativeBattery(); } catch (\Throwable) {}
        }
    }

    /** What a row's swipe action did, as a mail app would note it. */
    public function swiped(int $row, string $action): void
    {
        $this->lastAction = "swipeActions: 第 " . ($row + 1) . " 行 $action";
        Haptics::impact('light');
    }

    /** A pull to refresh: the work it does, here a second and a half, in the fiber refreshable runs it in. */
    public function refresh(): void
    {
        App::sleep(1.5);
        $this->refreshes++;
    }

    /**
     * Asks for a picture (decoded off the main thread, the app running meanwhile) or for documents, through the
     * system's picker: the photo picker on a phone. @param list<string> $ext
     */
    public function pick(array $ext, bool $photo): void
    {
        $files = Dialog::open(new OpenDialogOptions(filters: [new FileFilter($photo ? '图片' : '文件', $ext)], multiple: !$photo));
        if ($files === []) { return; }
        if ($photo) {
            $this->photoPath = $files[0];
            $path = $files[0];
            App::go(function () use ($path): void { $this->photo = Image::fromFile($path); }); // decoded off the main thread, the app running meanwhile
        } else {
            $this->files = $files;
        }
    }

    /** An HTTPS request in a fiber, the app running meanwhile: its status, size and time, or why it failed. */
    public function fetch(): void
    {
        $this->http = '请求中…';
        App::go(function (): void {
            $t = hrtime(true);
            try {
                $r = Http::get('https://example.com/');
                $this->http = "HTTP {$r->status} · " . strlen($r->body) . " B · " . round((hrtime(true) - $t) / 1e6) . ' ms';
            } catch (\RuntimeException $e) {
                $this->http = '失败：' . $e->getMessage(); // shown, as an app would: the request's failure is not the app's
            }
        });
    }

    /** Reads native device battery status directly via `myphp jnigen` (Android) or `ObjC` (iOS/macOS) without custom C glue. */
    public function queryNativeBattery(): void
    {
        if (App::isAndroid()) {
            $svc = Jni::call(Jni::activity(), 'android/app/Activity', 'getSystemService', '(Ljava/lang/String;)Ljava/lang/Object;', 'batterymanager');
            $bm = new \MyPHP\Examples\Mobile\BatteryManager($svc);
            $pct = $bm->getIntProperty(4); // BATTERY_PROPERTY_CAPACITY = 4
            $charging = $bm->isCharging() ? '充电中 ⚡' : '放电中 🔋';
            $bm->release();
            $this->nativeBattery = "JNIgen 直通：{$pct}% ({$charging})";
            \MyPHP\Logger::debug("JNIgen BatteryManager: {$this->nativeBattery}", 'jni');
        } elseif (App::isIOS()) {
            $dev = ObjC::msg('p:', ObjC::cls('UIDevice'), 'currentDevice');
            ObjC::msg('v:b', $dev, 'setBatteryMonitoringEnabled:', true);
            $lvl = round(ObjC::msg('f:', $dev, 'batteryLevel') * 100);
            $this->nativeBattery = "FFIgen/ObjC 直通：{$lvl}%";
        } else {
            $lvl = \MyPHP\Power::batteryLevel();
            $this->nativeBattery = $lvl !== null ? sprintf('桌面电源：%.0f%%', $lvl * 100) : '桌面交流供电';
        }
    }
}

$app = new Mobile;
App::setName('Mobile');
App::whenReady(function () use ($app): void {
    if (App::isAndroid() || App::isIOS()) {
        try { $app->queryNativeBattery(); } catch (\Throwable $e) {
            \MyPHP\Logger::error('queryNativeBattery failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'jni');
        }
    }
    new Window(new WindowOptions(title: 'Mobile', width: 900, height: 640, content: Content::file(__DIR__ . '/view.php', $app))); // the desktop: a sidebar; a phone fills its screen
});
App::run();
