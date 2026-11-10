<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Platform;
use MyPHP\Platform\{DataStore, DataStoreOptions, Menu};

final class AppController implements Platform\AppController
{
    private string $badge = '';

    public function setActivationPolicy(\MyPHP\ActivationPolicy $policy): void {}
    public function activate(): void {}
    public function hide(): void {}
    public function unhide(): void {}
    public function isHidden(): bool { return false; }
    public function isActive(): bool { return \MyPHP\Native\iOS::ffi()->myphp_ios_is_app_paused() === 0; }
    public function setBadge(string $label): void { throw new Platform\Unsupported('Dock is not supported on iOS'); }
    public function badge(): string { throw new Platform\Unsupported('Dock is not supported on iOS'); }
    public function bounce(bool $critical): int { throw new Platform\Unsupported('Dock is not supported on iOS'); }
    public function cancelBounce(int $id): void { throw new Platform\Unsupported('Dock is not supported on iOS'); }
    public function setDockIcon(string $png): void { throw new Platform\Unsupported('Dock is not supported on iOS'); }
    public function setDockMenu(?Menu $menu): void { throw new Platform\Unsupported('Dock is not supported on iOS'); }
    public function newDataStore(DataStoreOptions $opts): DataStore
    {
        throw new Platform\Unsupported('DataStore not implemented on iOS yet');
    }
    public function clearBrowsingData(?DataStore $store, int $types): void {}
    public function listCookies(?DataStore $store): array { return []; }
    public function setCookie(?DataStore $store, \MyPHP\Cookie $cookie): void {}
    public function deleteCookie(?DataStore $store, string $name, string $domain, string $path): void {}
    public function clearCookies(?DataStore $store): void {}
    public function compileContentRules(string $id, string $identifier, string $rules): mixed { return null; }
    public function showAboutPanel(\MyPHP\AboutPanelOptions $opts): void { throw new Platform\Unsupported('showAboutPanel is not supported on iOS'); }
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
    public function addRecentDocument(string $path): void {}
    public function clearRecentDocuments(): void {}
}
