<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

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
    public function isActive(): bool { return true; }
    public function setBadge(string $label): void { $this->badge = $label; }
    public function badge(): string { return $this->badge; }
    public function bounce(bool $critical): int { return 0; }
    public function cancelBounce(int $id): void {}
    public function setDockIcon(string $png): void {}
    public function setDockMenu(?Menu $menu): void {}
    public function newDataStore(DataStoreOptions $opts): DataStore
    {
        throw new Platform\Unsupported('DataStore not implemented on Android yet');
    }
    public function clearBrowsingData(?DataStore $store, int $types): void {}
    public function listCookies(?DataStore $store): array { return []; }
    public function setCookie(?DataStore $store, \MyPHP\Cookie $cookie): void {}
    public function deleteCookie(?DataStore $store, string $name, string $domain, string $path): void {}
    public function clearCookies(?DataStore $store): void {}
    public function compileContentRules(string $id, string $identifier, string $rules): mixed { return null; }
    public function showAboutPanel(\MyPHP\AboutPanelOptions $opts): void {}
    public function preferredLanguages(): array { return ['en']; }
    public function savedLocale(): ?string { return null; }
    public function saveLocale(string $tag): bool { return false; }
    public function region(): \MyPHP\Region { return new \MyPHP\Region('US'); }
    public function addRecentDocument(string $path): void {}
    public function clearRecentDocuments(): void {}
}
