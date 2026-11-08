<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use MyPHP\{AboutPanelOptions, ActivationPolicy};
use MyPHP\Platform;
use MyPHP\Platform\{Menu, Unsupported};

/**
 * The app as Windows has it (mygo's appController): the user's languages
 * and regional formats (locale.go). The taskbar's, the about panel and the
 * pages' website data come with their stages.
 */
final class App implements Platform\AppController
{
    private string $badge = '';

    public function setActivationPolicy(ActivationPolicy $policy): void {}

    public function activate(): void {}

    public function hide(): void {}

    public function unhide(): void {}

    public function isHidden(): bool
    {
        return false;
    }

    public function isActive(): bool
    {
        return false;
    }

    public function setBadge(string $label): void
    {
        $this->badge = $label;
    }

    public function badge(): string
    {
        return $this->badge;
    }

    public function bounce(bool $critical): int
    {
        return 0;
    }

    public function cancelBounce(int $id): void {}

    public function setDockIcon(string $png): void
    {
        throw new Unsupported('myphp: the taskbar icon is not on Windows yet');
    }

    public function setDockMenu(?Menu $menu): void {}

    public function newDataStore(Platform\DataStoreOptions $opts): Platform\DataStore
    {
        throw new Unsupported('myphp: website data is not on Windows yet');
    }

    public function clearBrowsingData(?Platform\DataStore $store, int $types): void
    {
        throw new Unsupported('myphp: website data is not on Windows yet');
    }

    public function listCookies(?Platform\DataStore $store): array
    {
        throw new Unsupported('myphp: website data is not on Windows yet');
    }

    public function setCookie(?Platform\DataStore $store, \MyPHP\Cookie $cookie): void
    {
        throw new Unsupported('myphp: website data is not on Windows yet');
    }

    public function deleteCookie(?Platform\DataStore $store, string $name, string $domain, string $path): void
    {
        throw new Unsupported('myphp: website data is not on Windows yet');
    }

    public function clearCookies(?Platform\DataStore $store): void
    {
        throw new Unsupported('myphp: website data is not on Windows yet');
    }

    public function compileContentRules(string $id, string $identifier, string $rules): mixed
    {
        throw new Unsupported('myphp: content rules are not on Windows yet');
    }

    public function showAboutPanel(AboutPanelOptions $opts): void
    {
        throw new Unsupported('myphp: the about panel is not on Windows yet');
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

    public function addRecentDocument(string $path): void {}

    public function clearRecentDocuments(): void {}
}
