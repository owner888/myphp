<?php
declare(strict_types=1);

namespace MyPHP;

use InvalidArgumentException;
use MyPHP\Native\{Bundle, Keychain};

/**
 * The secrets of a service in the system's credential store (mygo's
 * SecretsModule): Secrets has the app's, Secrets::service() another's.
 */
final class SecretsModule
{
    /** @param string $service the store's service, "" for the app's identifier */
    public function __construct(private readonly string $service = '') {}

    /** Stores $value under $key, replacing what it had: the key not empty nor longer than 256 bytes, the value at most Secrets::MAX_SECRET_SIZE bytes. */
    public function set(string $key, string $value): void
    {
        $service = $this->check($key);
        if (strlen($value) > Secrets::MAX_SECRET_SIZE) {
            throw new InvalidArgumentException(sprintf('myphp: secret "%s" has %d bytes, more than MAX_SECRET_SIZE (%d)', $key, strlen($value), Secrets::MAX_SECRET_SIZE));
        }
        Keychain::set($service, $key, $value);
    }

    /** The secret stored under $key; SecretNotFound when there is none. */
    public function get(string $key): string
    {
        return Keychain::get($this->check($key), $key);
    }

    /** Removes the secret stored under $key; none is no error. */
    public function delete(string $key): void
    {
        Keychain::delete($this->check($key), $key);
    }

    /** Checks $key and returns the service. */
    private function check(string $key): string
    {
        self::name('key', $key);
        if ($this->service === '') {
            $id = Bundle::info('CFBundleIdentifier');
            return $id !== null && $id !== '' ? $id : App::name();
        }
        self::name('service', $this->service);
        return $this->service;
    }

    private static function name(string $what, string $name): void
    {
        $err = match (true) {
            $name === '' => "myphp: empty secret $what",
            strlen($name) > Secrets::MAX_KEY_LEN => sprintf('myphp: secret %s has %d bytes, more than %d', $what, strlen($name), Secrets::MAX_KEY_LEN),
            str_contains($name, "\0") => "myphp: secret $what \"" . addcslashes($name, "\0") . '" contains NUL',
            default => null,
        };
        if ($err !== null) {
            throw new InvalidArgumentException($err);
        }
    }
}
