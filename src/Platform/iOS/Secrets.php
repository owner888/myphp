<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\Keychain;
use MyPHP\Platform;

final class Secrets implements Platform\Secrets
{
    public function setSecret(string $service, string $account, string $secret): void
    {
        Keychain::set($service, $account, $secret);
    }

    public function secret(string $service, string $account): string
    {
        return Keychain::get($service, $account);
    }

    public function deleteSecret(string $service, string $account): void
    {
        Keychain::delete($service, $account);
    }
}
