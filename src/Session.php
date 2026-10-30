<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\{CookieStore, ObjC};

/**
 * A website data store of pages (mygo's Session): their cookies, local and
 * session storage, IndexedDB, caches and service workers, which the pages
 * of a session share and the pages of other sessions don't see, as
 * Electron's partitions. A page shows in the default session unless its
 * PageOptions::$session names another:
 *
 *     $work = Session::newSession('work');          // kept on disk, as the default one
 *     $private = Session::newEphemeralSession();   // in memory only
 *     new Window(new WindowOptions(url: 'https://example.com', page: new PageOptions(session: $work)));
 *     $list = $work->cookies->list();
 *     $work->clearData(SiteData::ALL);
 *
 * Two accounts of a service side by side, a private window, a preview that
 * must not see the user's sign-ins. A window window.open opens keeps its
 * opener's session; web views in native UI take a session too; the
 * schemes Protocol serves serve every session's pages. A session
 * newSession makes keeps its data on disk from macOS 14 on; before, in
 * memory until the app quits, still apart from the other sessions'.
 */
final class Session
{
    /** Reads and changes the session's cookies, as Cookies does those of the default session. */
    public readonly CookiesModule $cookies;
    /** The WKWebsiteDataStore, retained, made when a page of the session or a call first needs it; null for the default session's. */
    private $store = null;
    /** How many pages use it: the last of an ephemeral session's takes its data with it. */
    private int $pages = 0;

    private static ?self $default = null;
    /** @var array<string, self> the sessions newSession made, by name */
    private static array $named = [];
    private static bool $warned = false;

    private function __construct(private readonly string $name, private readonly bool $ephemeral)
    {
        $this->cookies = new CookiesModule($this);
    }

    /** The default session, which pages show in unless their PageOptions::$session names another: its cookies are Cookies', and App::clearBrowsingData clears all of its data (mygo's DefaultSession). */
    public static function defaultSession(): self
    {
        return self::$default ??= new self('', false);
    }

    /**
     * The persistent session of $name, which keeps its data on disk from
     * one run of the app to the next, as the default one does: every call
     * with the same name returns the same session (mygo's NewSession). The
     * name is any text but "", which the app keeps from run to run to find
     * the session's data again.
     */
    public static function newSession(string $name): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('myphp: newSession needs a name; defaultSession() returns the default session');
        }
        return self::$named[$name] ??= new self($name, false);
    }

    /**
     * A new session that keeps its data in memory and writes nothing to
     * disk, as a browser's private window: its data goes when the last page
     * using it closes, and the next page that uses it starts with none.
     * Cookies set before its first page opens go to that page (mygo's
     * NewEphemeralSession).
     */
    public static function newEphemeralSession(): self
    {
        return new self('', true);
    }

    /** The name of a session newSession made, "" for the default session and ephemeral ones. */
    public function name(): string
    {
        return $this->name;
    }

    /** Whether the session keeps its data in memory only (newEphemeralSession). */
    public function isEphemeral(): bool
    {
        return $this->ephemeral;
    }

    /**
     * Deletes the data of $types (SiteData bits) the session's pages
     * stored, as SiteData::COOKIES | SiteData::LOCAL_STORAGE, or
     * SiteData::ALL when the user signs out (mygo's ClearData). Open pages
     * keep what they hold in memory until they reload. It returns once
     * WebKit is done.
     */
    public function clearData(int $types): void
    {
        if ($types !== 0) {
            CookieStore::removeData($this->dataStore(), $types);
        }
    }

    /**
     * The UUID of a session's name, the same at every run: the first 16
     * bytes of its SHA-256, as a version 8 UUID (RFC 9562; mygo's
     * sessionID), which names its WKWebsiteDataStore.
     * @internal
     */
    public static function id(string $name): string
    {
        $b = substr(hash('sha256', "myphp session $name", true), 0, 16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x80);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /** @internal the WKWebsiteDataStore of the session, made if needed: +dataStoreForIdentifier: of its UUID (macOS 14), else, and for an ephemeral one, +nonPersistentDataStore; the default one's for the default session */
    public function dataStore()
    {
        if ($this === self::$default) {
            return CookieStore::defaultData();
        }
        if ($this->store === null) {
            $cls = ObjC::cls('WKWebsiteDataStore');
            if (!$this->ephemeral && ObjC::msg('b:p', $cls, 'respondsToSelector:', ObjC::sel('dataStoreForIdentifier:'))) {
                $uuid = ObjC::msg('p:p', ObjC::alloc('NSUUID'), 'initWithUUIDString:', ObjC::str(self::id($this->name)));
                $this->store = ObjC::msg('p:', ObjC::msg('p:p', $cls, 'dataStoreForIdentifier:', $uuid), 'retain');
                ObjC::msg('v:', $uuid, 'release');
            } else {
                if (!$this->ephemeral && !self::$warned) {
                    self::$warned = true;
                    Logger::warn('sessions keep their data in memory until the app quits before macOS 14', 'session');
                }
                $this->store = ObjC::msg('p:', ObjC::msg('p:', $cls, 'nonPersistentDataStore'), 'retain');
            }
        }
        return $this->store;
    }

    /** @internal a page uses the session: the store its web view's configuration names, null for the default session */
    public function attach()
    {
        if ($this === self::$default) {
            return null;
        }
        $store = $this->dataStore();
        $this->pages++;
        return $store;
    }

    /** @internal a page that used the session is gone: with the last page of an ephemeral session its store goes, and its data */
    public function detach(): void
    {
        if ($this === self::$default || $this->pages === 0) {
            return;
        }
        if (--$this->pages === 0 && $this->ephemeral && $this->store !== null) {
            ObjC::msg('v:', $this->store, 'release');
            $this->store = null;
        }
    }
}
