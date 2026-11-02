<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use Closure;
use MyPHP\{AboutPanelOptions, ActivationPolicy};
use MyPHP\Native\{ContentRuleStore, CookieStore, Login, ObjC, Signals};
use MyPHP\Platform;
use MyPHP\Platform\{AppHandler, Menu};
use RuntimeException;

/**
 * NSApplication: its delegate, the run loop's turns and timers, and what
 * the app is in the Dock (mygo's darwin app.go and dock.go).
 */
final class App implements Platform\AppController
{
    private mixed $ns = null;
    private mixed $loop = null;
    private bool $signaled = false;
    /** @var array<int, Closure> the timers' functions by id */
    private array $timers = [];
    private int $nextTimer = 0;

    public function __construct(private readonly Backend $b) {}

    /** NSApp, made once init ran. */
    public function ns(): mixed
    {
        return $this->ns ?? throw new RuntimeException('myphp: the app is not running yet (App::whenReady)');
    }

    public function init(AppHandler $h, ActivationPolicy $policy): void
    {
        ObjC::boot(['Foundation', 'AppKit', 'QuartzCore', 'Metal', 'MetalKit', 'CoreText', 'WebKit', 'UniformTypeIdentifiers', 'UserNotifications']);
        // The dates the app shows are in the user's time zone, as a Go app's local time, whatever zone php.ini names (the developer's own, a server's UTC).
        date_default_timezone_set(ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSTimeZone'), 'localTimeZone'), 'name')));
        // AppKit's AutoFill heuristics watch every text input client for contact and password fields, in a helper process
        // ("AutoFill (App)") and with sync calls that cost CPU and stall scrolling (mygo's 23ac61d, 2317b80): myphp draws
        // its own fields, which never take those suggestions. macOS 26 reads the first, macOS 27 only the second.
        $no = ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', false);
        $defaults = ObjC::msg('p:pp', ObjC::cls('NSMutableDictionary'), 'dictionaryWithObject:forKey:', $no, ObjC::str('NSAutoFillHeuristicControllerEnabled'));
        ObjC::msg('v:pp', $defaults, 'setObject:forKey:', $no, ObjC::str('NSAutoFillHeuristicsEnabled'));
        ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSUserDefaults'), 'standardUserDefaults'), 'registerDefaults:', $defaults);
        $this->ns = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
        Signals::quitOn($this->quit(...)); // SIGINT and SIGTERM quit as Quit does (mygo's quitOnSignals)
        ObjC::msg('b:l', $this->ns, 'setActivationPolicy:', self::policy($policy));
        $b = $this->b;
        ObjC::defineClass('MyPHPAppDelegate', 'NSObject', [
            'observeValueForKeyPath:ofObject:change:context:' => ['v@:@@@^v', 'v(pppppp)', function ($self, $cmd, $keyPath, $object, $change, $context) use ($h) {
                if (ObjC::phpStr($keyPath) === 'effectiveAppearance') {
                    $h->themeChanged(); // the accent, read in the appearance
                }
            }],
            'applicationWillFinishLaunching:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                if (Login::launchedAsLoginItem()) {
                    Login::$openedAtLogin = true;
                }
            }],
            'applicationShouldTerminate:' => ['Q@:@', 'L(ppp)', fn($self, $cmd, $app) => $h->quitRequested() ? 1 : 0], // NSTerminateNow, NSTerminateCancel
            'applicationDidBecomeActive:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $note) => $h->didBecomeActive()],
            'applicationDidResignActive:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $note) => $h->didResignActive()],
            'applicationDockMenu:' => ['@@:@', 'p(ppp)', fn($self, $cmd, $app) => $b->menus()->dockMenu],
            'screensChanged:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $note) => $h->displaysChanged()],
            'applicationShouldHandleReopen:hasVisibleWindows:' => ['B@:@B', 'b(pppb)', function ($self, $cmd, $app, $visible) use ($h) {
                $h->activated((bool)$visible);
                return true;
            }],
            'applicationWillTerminate:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $note) => $h->terminating()],
            'localeChanged:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $note) => $h->localeChanged()],
            'displayOptionsChanged:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $note) => $h->themeChanged()], // Reduce Motion, Increase Contrast turned on or off, as mygo's preferences change
            'applicationDidFinishLaunching:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($b, $h) {
                $center = ObjC::msg('p:', ObjC::cls('NSNotificationCenter'), 'defaultCenter');
                ObjC::msg('v:pppp', $center, 'addObserver:selector:name:object:', $self, ObjC::sel('localeChanged:'), ObjC::str('NSCurrentLocaleDidChangeNotification'), null);
                $workspace = ObjC::msg('p:', ObjC::cls('NSWorkspace'), 'sharedWorkspace');
                ObjC::msg('v:pppp', ObjC::msg('p:', $workspace, 'notificationCenter'), 'addObserver:selector:name:object:', $self, ObjC::sel('displayOptionsChanged:'), ObjC::str('NSWorkspaceAccessibilityDisplayOptionsDidChangeNotification'), null);
                $b->notifications()->attachDelegate(); // before the click that launched the app is delivered
                ObjC::msg('v:pppp', $center, 'addObserver:selector:name:object:', $self, ObjC::sel('screensChanged:'), ObjC::str('NSApplicationDidChangeScreenParametersNotification'), null);
                $h->ready();
            }],
            'application:openURLs:' => ['v@:@@', 'v(pppp)', function ($self, $cmd, $app, $urls) use ($h) {
                $files = $links = [];
                for ($i = 0, $n = ObjC::msg('L:', $urls, 'count'); $i < $n; $i++) {
                    $u = ObjC::msg('p:L', $urls, 'objectAtIndex:', $i);
                    if (ObjC::msg('b:', $u, 'isFileURL')) {
                        $files[] = ObjC::phpStr(ObjC::msg('p:', $u, 'path'));
                    } else {
                        $links[] = ObjC::phpStr(ObjC::msg('p:', $u, 'absoluteString'));
                    }
                }
                $h->openFiles($files);
                $h->openURLs($links);
            }],
        ]);
        $delegate = ObjC::msg('p:', ObjC::alloc('MyPHPAppDelegate'), 'init');
        ObjC::msg('v:p', $this->ns, 'setDelegate:', $delegate);
        ObjC::msg('v:ppLp', $this->ns, 'addObserver:forKeyPath:options:context:', $delegate, ObjC::str('effectiveAppearance'), 0, null);
    }

    public function run(): void
    {
        ObjC::msg('v:', $this->ns(), 'run');
    }

    public function quit(): void
    {
        ObjC::msg('v:p', $this->ns(), 'terminate:', null);
    }

    /** The target of the run loop's turns and timers, before App::run too: a transfer's timer (Native\CurlLoop), a test's. */
    private function loop(): mixed
    {
        if ($this->loop === null) {
            ObjC::boot(['Foundation']);
            $b = $this->b;
            ObjC::defineClass('MyPHPLater', 'NSObject', [
                'tick:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) use ($b) {
                    $this->signaled = false;
                    $b->h->dispatch();
                }],
                'fire:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $number) {
                    $id = ObjC::msg('l:', $number, 'longValue');
                    $fn = $this->timers[$id] ?? null;
                    unset($this->timers[$id]);
                    $fn?->__invoke();
                }],
            ]);
            $this->loop = ObjC::msg('p:', ObjC::alloc('MyPHPLater'), 'init');
        }
        return $this->loop;
    }

    public function signal(): void
    {
        if (!$this->signaled) {
            $this->signaled = true;
            ObjC::msg('v:ppd', $this->loop(), 'performSelector:withObject:afterDelay:', ObjC::sel('tick:'), null, 0.0);
        }
    }

    public function timer(float $seconds, Closure $fn): void
    {
        $loop = $this->loop();
        $id = ++$this->nextTimer;
        $this->timers[$id] = $fn;
        ObjC::msg('v:ppd', $loop, 'performSelector:withObject:afterDelay:', ObjC::sel('fire:'), ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', $id), $seconds);
    }

    /** NSRunLoop alone does not dispatch AppKit's events (NSApp's run does), so pull and send them here, as a modal session would. */
    public function step(): void
    {
        $ns = $this->ns();
        $date = ObjC::msg('p:d', ObjC::cls('NSDate'), 'dateWithTimeIntervalSinceNow:', 0.05);
        $event = ObjC::msg('p:Lppb', $ns, 'nextEventMatchingMask:untilDate:inMode:dequeue:', PHP_INT_MAX, $date, ObjC::str('kCFRunLoopDefaultMode'), true);
        if ($event !== null) {
            ObjC::msg('v:p', $ns, 'sendEvent:', $event);
        }
        ObjC::msg('v:', $ns, 'updateWindows');
    }

    private static function policy(ActivationPolicy $p): int
    {
        return match ($p) { ActivationPolicy::Regular => 0, ActivationPolicy::Accessory => 1, ActivationPolicy::Prohibited => 2 }; // NSApplicationActivationPolicy
    }

    public function setActivationPolicy(ActivationPolicy $policy): void
    {
        ObjC::msg('b:l', $this->ns(), 'setActivationPolicy:', self::policy($policy));
    }

    public function activate(): void
    {
        ObjC::msg('v:b', $this->ns(), 'activateIgnoringOtherApps:', true);
    }

    public function hide(): void
    {
        ObjC::msg('v:p', $this->ns(), 'hide:', null);
    }

    public function unhide(): void
    {
        ObjC::msg('v:p', $this->ns(), 'unhide:', null);
    }

    public function isHidden(): bool
    {
        return ObjC::msg('b:', $this->ns(), 'isHidden');
    }

    public function isActive(): bool
    {
        return ObjC::msg('b:', $this->ns(), 'isActive');
    }

    public function setBadge(string $label): void
    {
        ObjC::msg('v:p', ObjC::msg('p:', $this->ns(), 'dockTile'), 'setBadgeLabel:', $label === '' ? null : ObjC::str($label));
    }

    public function badge(): string
    {
        $s = ObjC::msg('p:', ObjC::msg('p:', $this->ns(), 'dockTile'), 'badgeLabel');
        return $s === null ? '' : ObjC::phpStr($s);
    }

    public function bounce(bool $critical): int
    {
        return ObjC::msg('l:L', $this->ns(), 'requestUserAttention:', $critical ? 0 : 10); // NSCriticalRequest, NSInformationalRequest
    }

    public function cancelBounce(int $id): void
    {
        ObjC::msg('v:l', $this->ns(), 'cancelUserAttentionRequest:', $id);
    }

    public function setDockIcon(string $png): void
    {
        $img = null;
        if ($png !== '') {
            $img = ObjC::msg('p:p', ObjC::alloc('NSImage'), 'initWithData:', ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $png, strlen($png))) ?? throw new RuntimeException('myphp: Dock::setIcon: not an image');
        }
        ObjC::msg('v:p', $this->ns(), 'setApplicationIconImage:', $img);
        if ($img !== null) { ObjC::msg('v:', $img, 'release'); }
    }

    public function setDockMenu(?Menu $menu): void
    {
        $this->b->menus()->setDockMenu($menu);
    }

    private static bool $warned = false;

    /** +dataStoreForIdentifier: of the session's UUID (macOS 14), else, and for an ephemeral one, +nonPersistentDataStore (mygo's darwin NewDataStore). */
    public function newDataStore(Platform\DataStoreOptions $opts): Platform\DataStore
    {
        $cls = ObjC::cls('WKWebsiteDataStore');
        if (!$opts->ephemeral && ObjC::msg('b:p', $cls, 'respondsToSelector:', ObjC::sel('dataStoreForIdentifier:'))) {
            $uuid = ObjC::msg('p:p', ObjC::alloc('NSUUID'), 'initWithUUIDString:', ObjC::str($opts->id));
            $store = ObjC::msg('p:', ObjC::msg('p:p', $cls, 'dataStoreForIdentifier:', $uuid), 'retain');
            ObjC::msg('v:', $uuid, 'release');
            return new DataStore($store);
        }
        if (!$opts->ephemeral && !self::$warned) {
            self::$warned = true;
            \MyPHP\Logger::warn('sessions keep their data in memory until the app quits before macOS 14', 'session');
        }
        return new DataStore(ObjC::msg('p:', ObjC::msg('p:', $cls, 'nonPersistentDataStore'), 'retain'));
    }

    /** The WKWebsiteDataStore of $store, the default one for null. */
    private static function data(?Platform\DataStore $store)
    {
        return $store instanceof DataStore ? $store->ns : CookieStore::defaultData();
    }

    public function clearBrowsingData(?Platform\DataStore $store, int $types): void
    {
        CookieStore::removeData(self::data($store), $types);
    }

    public function listCookies(?Platform\DataStore $store): array
    {
        return CookieStore::all(self::data($store));
    }

    public function setCookie(?Platform\DataStore $store, \MyPHP\Cookie $cookie): void
    {
        CookieStore::set($cookie, self::data($store));
    }

    public function deleteCookie(?Platform\DataStore $store, string $name, string $domain, string $path): void
    {
        CookieStore::delete($name, $domain, $path, self::data($store));
    }

    public function clearCookies(?Platform\DataStore $store): void
    {
        CookieStore::clear(self::data($store));
    }

    public function compileContentRules(string $id, string $identifier, string $rules): mixed
    {
        return ContentRuleStore::compile($identifier, $rules, fn(string $name): bool => \MyPHP\ContentRules::stale($id, $identifier, $name));
    }

    public function showAboutPanel(AboutPanelOptions $opts): void
    {
        $app = $this->ns();
        $dict = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        foreach (['ApplicationName' => $opts->applicationName, 'ApplicationVersion' => $opts->applicationVersion, 'Version' => $opts->version, 'Copyright' => $opts->copyright] as $key => $v) {
            if ($v !== '') { ObjC::msg('v:pp', $dict, 'setObject:forKey:', ObjC::str($v), ObjC::str($key)); }
        }
        if ($opts->credits !== '') {
            $credits = ObjC::msg('p:p', ObjC::alloc('NSAttributedString'), 'initWithString:', ObjC::str($opts->credits));
            ObjC::msg('v:pp', $dict, 'setObject:forKey:', $credits, ObjC::str('Credits'));
            ObjC::msg('v:', $credits, 'release');
        }
        ObjC::msg('v:b', $app, 'activateIgnoringOtherApps:', true);
        ObjC::msg('v:p', $app, 'orderFrontStandardAboutPanelWithOptions:', $dict);
    }

    public function preferredLanguages(): array
    {
        return \MyPHP\Native\SystemLocale::preferredLanguages();
    }

    public function savedLocale(): ?string
    {
        return \MyPHP\Native\SystemLocale::savedLocale();
    }

    public function saveLocale(string $tag): bool
    {
        \MyPHP\Native\SystemLocale::saveLocale($tag);
        return true;
    }

    public function region(): \MyPHP\Region
    {
        return \MyPHP\Native\SystemLocale::region();
    }
}
