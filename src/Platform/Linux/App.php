<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\{AboutPanelOptions, ActivationPolicy};
use MyPHP\Platform;
use MyPHP\Platform\{Menu, Unsupported};

/** What the app is on the desktop (mygo's linux appController): GTK has no Dock, so what is the Dock's does nothing, as upstream's. */
final class App implements Platform\AppController
{
    private string $badge = '';

    public function setActivationPolicy(ActivationPolicy $policy): void {}

    /** Brings the first window that shows forward. */
    public function activate(): void
    {
        Window::presentFirst();
    }

    public function hide(): void
    {
        Window::hideAll();
    }

    public function unhide(): void
    {
        Window::showAll();
    }

    public function isHidden(): bool
    {
        return false;
    }

    /** Whether a window of the app has the keyboard. */
    public function isActive(): bool
    {
        return Window::active() !== null;
    }

    public function setBadge(string $label): void
    {
        $this->badge = $label; // the launcher entry's comes with the system's services
    }

    public function badge(): string
    {
        return $this->badge;
    }

    /** Asks for the user's attention by the windows' urgency hint. */
    public function bounce(bool $critical): int
    {
        Window::urgent(true);
        return 1;
    }

    public function cancelBounce(int $id): void
    {
        Window::urgent(false);
    }

    public function setDockIcon(string $png): void
    {
        throw new Unsupported('myphp: the window icon is not set on Linux yet');
    }

    public function setDockMenu(?Menu $menu): void {}

    /** A web context with a website data manager of its own (mygo's linux NewDataStore). */
    public function newDataStore(Platform\DataStoreOptions $opts): Platform\DataStore
    {
        return WebsiteData::newStore($opts);
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

    /** The cookies of the store's website data, its other data kept (mygo's ClearCookies). */
    public function clearCookies(?Platform\DataStore $store): void
    {
        WebsiteData::clear($store, \MyPHP\SiteData::COOKIES);
    }

    public function compileContentRules(string $id, string $identifier, string $rules): mixed
    {
        return WebsiteData::compileContentRules($id, $identifier, $rules);
    }

    /** GTK's shared recent files, the item recorded under the application's name (mygo's: GTK writes the list later, its failure unseen). */
    public function addRecentDocument(string $path): void
    {
        $g = self::recent();
        $uri = $g->g_filename_to_uri($path, null, null) ?? throw new \RuntimeException("myphp: cannot record $path as a recent document");
        try {
            if (!$g->gtk_recent_manager_add_item($g->gtk_recent_manager_get_default(), $uri)) {
                throw new \RuntimeException("myphp: the recent files list refused $path");
            }
        } finally {
            $g->g_free($uri);
        }
    }

    /** Removes the items of GTK's shared list the application recorded, those of earlier runs too, the other applications' left (mygo's). */
    public function clearRecentDocuments(): void
    {
        $g = self::recent();
        $m = $g->gtk_recent_manager_get_default();
        $name = $g->g_get_application_name();
        if ($name === null) {
            return;
        }
        $uris = [];
        $list = $g->gtk_recent_manager_get_items($m);
        for ($node = $list; $node !== null; $node = $node->next) {
            $info = $node->data;
            if ($g->gtk_recent_info_has_application($info, $name)) { $uris[] = $g->gtk_recent_info_get_uri($info); }
            $g->gtk_recent_info_unref($info);
        }
        $g->g_list_free($list);
        foreach ($uris as $uri) { $g->gtk_recent_manager_remove_item($m, $uri, null); }
    }

    private static ?\FFI $recent = null;

    /** GTK's recent manager, an FFI of its own (Gtk's is the backend's). */
    private static function recent(): \FFI
    {
        return self::$recent ??= \FFI::cdef('
            typedef struct GList { void *data; struct GList *next, *prev; } GList;
            void* gtk_recent_manager_get_default(void);
            int gtk_recent_manager_add_item(void*, const char*);
            int gtk_recent_manager_remove_item(void*, const char*, void**);
            GList* gtk_recent_manager_get_items(void*);
            int gtk_recent_info_has_application(void*, const char*);
            const char* gtk_recent_info_get_uri(void*);
            void gtk_recent_info_unref(void*);
            void g_list_free(GList*);
            const char* g_get_application_name(void);
            char* g_filename_to_uri(const char*, const char*, void**);
            void g_free(void*);
        ', 'libgtk-3.so.0');
    }

    public function showAboutPanel(AboutPanelOptions $opts): void
    {
        throw new Unsupported('myphp: the about panel is not on Linux yet');
    }

    /** The languages of the environment as gettext reads them: LANGUAGE, a list, unless the locale of messages is C, then that locale (mygo's PreferredLanguages). */
    public function preferredLanguages(): array
    {
        $locale = self::envLocale('LC_MESSAGES');
        if ($locale === '') {
            return [];
        }
        $out = array_values(array_filter(explode(':', (string)getenv('LANGUAGE')), fn(string $l): bool => $l !== ''));
        $out[] = $locale;
        return $out;
    }

    /** The locale of a category as glibc reads the environment: LC_ALL, the category's, then LANG; '' for the C locale. */
    private static function envLocale(string $category): string
    {
        foreach (['LC_ALL', $category, 'LANG'] as $env) {
            $v = (string)getenv($env);
            if ($v !== '') {
                return $v === 'C' || $v === 'POSIX' || str_starts_with($v, 'C.') ? '' : $v;
            }
        }
        return '';
    }

    /** The desktop keeps no language of an app: the core's file does. */
    public function savedLocale(): ?string
    {
        return null;
    }

    public function saveLocale(string $tag): bool
    {
        return false;
    }

    /** The formats of LC_TIME as glibc has them for the environment's locale: its hour cycle, from its time format; the first weekday left to CLDR (mygo's Region, which reads it from glibc too). */
    public function region(): \MyPHP\Region
    {
        $locale = self::envLocale('LC_TIME');
        if ($locale === '') {
            return new \MyPHP\Region('', -1, 0);
        }
        $libc = \FFI::cdef('void* newlocale(int, const char*, void*); void freelocale(void*); char* nl_langinfo_l(int, void*);');
        $loc = $libc->newlocale(1 << 2, '', null); // LC_TIME_MASK, of the environment
        $cycle = 0;
        if ($loc !== null) {
            $fmt = (string)$libc->nl_langinfo_l(0x2002A, $loc); // T_FMT
            $libc->freelocale($loc);
            $cycle = preg_match('/%[IlrP]|%p/', $fmt) === 1 ? 12 : (preg_match('/%[HkRT]/', $fmt) === 1 ? 24 : 0);
        }
        return new \MyPHP\Region(\MyPHP\Native\SystemLocale::regionLocale(preg_replace('/\..*$/', '', $locale)), -1, $cycle);
    }
}
