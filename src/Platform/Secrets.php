<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * Small secrets in the system's credential store (mygo's
 * platform.Secrets): the Keychain, the Secret Service, the Credential
 * Manager. Callable before init; they may block while the system asks the
 * user.
 */
interface Secrets
{
    public function setSecret(string $service, string $account, string $secret): void;

    /** Throws \MyPHP\SecretNotFound when there is none. */
    public function secret(string $service, string $account): string;

    /** Succeeds when there is none. */
    public function deleteSecret(string $service, string $account): void;
}
