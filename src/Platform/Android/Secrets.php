<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use MyPHP\{App, PathName, SecretNotFound};
use MyPHP\Platform;
use RuntimeException;

/**
 * Secrets on Android: encrypted with AES-256-GCM (OpenSSL) under a 256-bit
 * master key kept in the app's private data directory (mode 0600, Android's
 * kernel-enforced per-app UID sandbox).
 */
final class Secrets implements Platform\Secrets
{
    private const KEY_FILE = '.secrets.key';
    private const STORE_FILE = '.secrets.enc';

    private static function dir(): string
    {
        return App::path(PathName::UserData);
    }

    private static function key(): string
    {
        $path = self::dir() . '/' . self::KEY_FILE;
        if (is_file($path)) {
            $k = (string)@file_get_contents($path);
            if (strlen($k) === 32) {
                return $k;
            }
        }
        $k = random_bytes(32);
        @file_put_contents($path, $k) !== false || throw new RuntimeException("myphp: secrets: cannot write $path");
        @chmod($path, 0600);
        return $k;
    }

    /** @return array<string, array<string, string>> */
    private static function load(): array
    {
        $path = self::dir() . '/' . self::STORE_FILE;
        if (!is_file($path)) {
            return [];
        }
        $raw = (string)@file_get_contents($path);
        if (strlen($raw) < 28) {
            return [];
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('myphp: secrets: failed to decrypt secrets store');
        }
        $data = json_decode($plain, true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, array<string, string>> $store */
    private static function save(array $store): void
    {
        $path = self::dir() . '/' . self::STORE_FILE;
        $iv = random_bytes(12);
        $tag = '';
        $plain = json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('myphp: secrets: failed to encrypt secrets store');
        }
        @file_put_contents("$path.tmp", $iv . $tag . $ct) !== false || throw new RuntimeException("myphp: secrets: cannot write $path");
        @chmod("$path.tmp", 0600);
        @rename("$path.tmp", $path);
    }

    public function setSecret(string $service, string $account, string $secret): void
    {
        $store = self::load();
        $store[$service][$account] = base64_encode($secret);
        self::save($store);
    }

    public function secret(string $service, string $account): string
    {
        $store = self::load();
        if (!isset($store[$service]) || !array_key_exists($account, $store[$service])) {
            throw new SecretNotFound('myphp: no secret stored under the key');
        }
        $decoded = base64_decode($store[$service][$account], true);
        return $decoded === false ? '' : $decoded;
    }

    public function deleteSecret(string $service, string $account): void
    {
        $store = self::load();
        if (!isset($store[$service]) || !array_key_exists($account, $store[$service])) {
            return;
        }
        unset($store[$service][$account]);
        if ($store[$service] === []) {
            unset($store[$service]);
        }
        self::save($store);
    }
}