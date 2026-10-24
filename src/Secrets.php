<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Small secrets, such as access tokens and API keys, in the login Keychain
 * (mygo's Secrets), which the system encrypts for the user, unlike a file
 * in App::path(PathName::UserData):
 *
 *     Secrets::set('github-token', $token);
 *     try { $token = Secrets::get('github-token'); } catch (SecretNotFound) { // ask the user to sign in }
 *
 * The store keeps a secret under a service and an account, which the key
 * is: the service is the bundle's identifier, else the app's name
 * (App::name()); service() chooses another, as keytar's. They work before
 * App::run, and may block while the system asks the user, as to let a
 * rebuilt app read what it stored.
 */
final class Secrets
{
    /** The largest secret set() stores, in bytes: the most Windows' Credential Manager keeps, as mygo's, so that an app behaves the same everywhere. */
    public const MAX_SECRET_SIZE = 2560;
    /** The longest key and service, in bytes. */
    public const MAX_KEY_LEN = 256;

    public static function set(string $key, string $value): void
    {
        (new SecretsModule)->set($key, $value);
    }

    public static function get(string $key): string
    {
        return (new SecretsModule)->get($key);
    }

    public static function delete(string $key): void
    {
        (new SecretsModule)->delete($key);
    }

    /** The secrets of $service in place of the app's: to share them with another program of the app, or read those an Electron app stored with keytar, under the service it passed. "" is the app's. */
    public static function service(string $service): SecretsModule
    {
        return new SecretsModule($service);
    }
}
