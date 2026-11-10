<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use MyPHP\{AboutPanelOptions, ActivationPolicy};
use MyPHP\Platform;
use MyPHP\Platform\Menu;

/**
 * The app as Windows has it (mygo's appController): the user's languages
 * and regional formats (locale.go), its icon and its buttons' flashing on
 * the taskbar (Taskbar), the about panel as a message, its windows hidden
 * and brought forward together, its recent documents (jumplist.go).
 */
final class App implements Platform\AppController
{
    private string $badge = '';

    public function setActivationPolicy(ActivationPolicy $policy): void {}

    /** The windows hide() hid, which unhide() shows. @var list<Window> */
    private array $hidden = [];

    /**
     * Brings the app forward as macOS does, its windows in their order
     * (mygo's Activate): a window of the app in front stays there, else the
     * highest of its windows shown comes forward; a flyout that never takes
     * the keyboard brings nothing forward.
     */
    public function activate(): void
    {
        if (Window::active() !== null) {
            return;
        }
        $u = FFI::cdef('void *GetTopWindow(void *hwnd); void *GetWindow(void *hwnd, uint32_t cmd);', 'user32.dll');
        $open = [];
        foreach (Window::all() as $w) { $open[\MyPHP\Native\Mem::addr($w->hwnd)] = $w; }
        for ($h = $u->GetTopWindow(null); $h !== null; $h = $u->GetWindow($h, 2)) { // GW_HWNDNEXT
            $w = $open[\MyPHP\Native\Mem::addr($h)] ?? null;
            if ($w === null || !$w->isVisible() || (($f = $w->w->flyoutState()) !== null && !$f['focusable'])) {
                continue;
            }
            $w->focus();
            return;
        }
    }

    /** Hides the app's windows shown, as macOS hides an app (mygo's Hide). */
    public function hide(): void
    {
        $this->hidden = array_values(array_filter(Window::all(), fn(Window $w): bool => $w->isVisible()));
        foreach ($this->hidden as $w) { $w->hide(); }
    }

    /** Shows the windows hide() hid again, without taking the keyboard (mygo's Unhide). */
    public function unhide(): void
    {
        foreach ($this->hidden as $w) {
            if (!$w->isDead()) { $w->showInactive(); }
        }
        $this->hidden = [];
    }

    public function isHidden(): bool
    {
        return $this->hidden !== [];
    }

    /** Whether the window in front is one of the app's (mygo's IsActive). */
    public function isActive(): bool
    {
        return Window::active() !== null;
    }

    public function setBadge(string $label): void
    {
        $this->badge = $label;
    }

    public function badge(): string
    {
        return $this->badge;
    }

    /** Flashes the windows' taskbar buttons till one comes to the front: the title bars too when $critical (mygo's Bounce). */
    public function bounce(bool $critical): int
    {
        foreach (Window::all() as $w) {
            Taskbar::flash($w->hwnd, ($critical ? 0x3 : 0x2) | 0xC); // FLASHW_ALL or FLASHW_TRAY, FLASHW_TIMERNOFG
        }
        return 1;
    }

    public function cancelBounce(int $id): void
    {
        foreach (Window::all() as $w) {
            Taskbar::flash($w->hwnd, 0);
        }
    }

    /** The app's icon, on the windows' title bars and taskbar buttons (mygo's SetDockIcon). */
    public function setDockIcon(string $png): void
    {
        Taskbar::setAppIcon($png);
    }

    public function setDockMenu(?Menu $menu): void {}

    /** The ephemeral stores made, which name their InPrivate profiles. */
    private int $privateStores = 0;

    /**
     * The profile of a session (mygo's NewDataStore): "myphp-" and the
     * session's UUID, or for an ephemeral session an InPrivate profile no
     * other store of the run has.
     */
    public function newDataStore(Platform\DataStoreOptions $opts): Platform\DataStore
    {
        return $opts->ephemeral ? new DataStore('myphp-private-' . ++$this->privateStores, true) : new DataStore("myphp-{$opts->id}", false);
    }

    public function clearBrowsingData(?Platform\DataStore $store, int $types): void
    {
        WebsiteData::clear($store, $types);
    }

    public function listCookies(?Platform\DataStore $store): array
    {
        return WebsiteData::cookies($store);
    }

    public function setCookie(?Platform\DataStore $store, \MyPHP\Cookie $cookie): void
    {
        WebsiteData::setCookie($store, $cookie);
    }

    public function deleteCookie(?Platform\DataStore $store, string $name, string $domain, string $path): void
    {
        WebsiteData::deleteCookie($store, $name, $domain, $path);
    }

    public function clearCookies(?Platform\DataStore $store): void
    {
        WebsiteData::clearCookies($store);
    }

    /** A list WebView2 pages match in PHP (ContentRuleList): WebView2 keeps nothing, so the rules are parsed each launch (mygo's windows CompileContentRules). */
    public function compileContentRules(string $id, string $identifier, string $rules): mixed
    {
        return \MyPHP\Native\ContentRuleList::parse($rules);
    }

    /** A message of the app's name over its versions, copyright and credits, titled "About" it, as mygo's: Windows has no standard about panel. */
    public function showAboutPanel(AboutPanelOptions $opts): void
    {
        $detail = $opts->applicationVersion . ($opts->version !== '' ? " ({$opts->version})" : '');
        foreach ([$opts->copyright, $opts->credits] as $s) {
            if ($s !== '') { $detail .= "\n$s"; }
        }
        $t = \MyPHP\L10n::in(\MyPHP\App::locale());
        $name = $opts->applicationName !== '' ? $opts->applicationName : \MyPHP\App::name();
        \MyPHP\App::backend()->dialogs()->message(new \MyPHP\MessageOptions(type: 'info', title: $t->get('menuAbout', ['app' => $name]), message: $name, detail: ltrim($detail, "\n")), [$t->get('ok')], 0);
    }

    /** The display languages of Settings > Time & language, in order (GetUserPreferredUILanguages, as mygo's). */
    public function preferredLanguages(): array
    {
        $k = Win32::kernel32();
        $count = $k->new('uint32_t'); $size = $k->new('uint32_t');
        if (!$k->GetUserPreferredUILanguages(0x8 /* MUI_LANGUAGE_NAME */, FFI::addr($count), null, FFI::addr($size)) || $size->cdata === 0) {
            return [];
        }
        $buf = $k->new("uint16_t[{$size->cdata}]");
        if (!$k->GetUserPreferredUILanguages(0x8, FFI::addr($count), $buf, FFI::addr($size))) {
            return [];
        }
        $list = (string)iconv('UTF-16LE', 'UTF-8', FFI::string($buf, $size->cdata * 2));
        return array_values(array_filter(explode("\0", $list), fn(string $l): bool => $l !== ''));
    }

    public function savedLocale(): ?string
    {
        return null;
    }

    public function saveLocale(string $tag): bool
    {
        return false;
    }

    /** The regional formats with the user's changes: their locale, first weekday (0 for Sunday) and the time format's hour cycle (mygo's Region). */
    public function region(): \MyPHP\Region
    {
        $first = self::localeInfo(0x100C); // LOCALE_IFIRSTDAYOFWEEK: 0 for Monday to 6 for Sunday
        $cycle = 0;
        $quoted = false;
        foreach (str_split(self::localeInfo(0x1003)) as $c) { // LOCALE_STIMEFORMAT
            if ($c === "'") { $quoted = !$quoted; }
            elseif (!$quoted && ($c === 'h' || $c === 'H')) { $cycle = $c === 'h' ? 12 : 24; break; }
        }
        return new \MyPHP\Region(self::localeInfo(0x5C) /* LOCALE_SNAME */, ctype_digit($first) && (int)$first <= 6 ? ((int)$first + 1) % 7 : -1, $cycle);
    }

    /** An item of the user's regional formats (GetLocaleInfoEx of LOCALE_NAME_USER_DEFAULT). */
    private static function localeInfo(int $item): string
    {
        $k = Win32::kernel32();
        $buf = $k->new('uint16_t[128]');
        $n = $k->GetLocaleInfoEx(null, $item, $buf, 128);
        return $n > 0 ? Win32::str($k->cast('uint16_t *', FFI::addr($buf[0])), $n - 1) : '';
    }

    /** Tells the shell the app opened $path: the Recent category of its jump list (of the types the app opens) and the user's recent files list it (SHAddToRecentDocs, SHARD_PATHW; mygo's). */
    public function addRecentDocument(string $path): void
    {
        FFI::cdef('void SHAddToRecentDocs(uint32_t flags, const void *path);', 'shell32.dll')->SHAddToRecentDocs(3, Win32::wide($path));
    }

    /** Removes the app's destinations, the Recent category of its jump list (IApplicationDestinations::RemoveAllDestinations; mygo's): SHAddToRecentDocs(NULL) would clear every app's recent files. */
    public function clearRecentDocuments(): void
    {
        $f = FFI::cdef(<<<'C'
            typedef struct { void *r0[5]; int32_t (*RemoveAllDestinations)(void *self); } IApplicationDestinationsVtbl;
            typedef struct { IApplicationDestinationsVtbl *v; } IApplicationDestinations;
            int32_t CoCreateInstance(const void *clsid, void *outer, uint32_t context, const void *iid, void *out);
            C, 'ole32.dll');
        $out = $f->new('void *');
        [$c, $i] = [\MyPHP\Native\Com::guid('86c14003-4d6b-4ef3-a7b4-0506663b2e68'), \MyPHP\Native\Com::guid('12337d35-94c6-48a0-bce7-6a9c69d4d600')];
        \MyPHP\Native\Com::check($f->CoCreateInstance(FFI::addr($c), null, 1, FFI::addr($i), FFI::addr($out)), 'creating ApplicationDestinations');
        $ad = \MyPHP\Native\Com::typed($f, $out, 'IApplicationDestinations *');
        try {
            \MyPHP\Native\Com::check(($ad->v->RemoveAllDestinations)($ad), 'IApplicationDestinations::RemoveAllDestinations');
        } finally {
            \MyPHP\Native\Com::release($out);
        }
    }
}
