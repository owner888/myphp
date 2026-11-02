<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI;
use FFI\CData;
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;
use MyPHP\SecretNotFound;
use RuntimeException;

/**
 * Secrets in the Secret Service (GNOME Keyring, KWallet, KeePassXC…)
 * through libsecret, with keytar's schema, the generic one whose
 * attributes are the service and the account (mygo's linux secrets.go).
 * Without a store (no libsecret, no session bus, no Secret Service on it)
 * each call throws Unsupported.
 */
final class Secrets implements Platform\Secrets
{
    /** D-Bus's errors of a bus without the Secret Service: ServiceUnknown, NameHasNoOwner, NoServer. */
    private const NO_SERVICE = [2, 3, 11];

    private static ?FFI $f = null;
    private static ?CData $schema = null;

    private static function lib(): FFI
    {
        if (self::$f !== null) {
            return self::$f;
        }
        if (DBus::bus(DBus::SESSION) === null) { // libsecret's connection, which GIO shares
            throw new Unsupported('myphp: secrets need the Secret Service, and there is no session bus');
        }
        try {
            self::$f = FFI::cdef('
                typedef struct { unsigned int domain; int code; char* message; } GError;
                void* secret_schema_new(const char*, int, ...);
                void* secret_value_new(const char*, long, const char*);
                void* secret_value_get(void*, size_t*);
                void secret_value_unref(void*);
                int secret_password_store_binary_sync(void*, const char*, const char*, void*, void*, GError**, ...);
                void* secret_password_lookup_binary_sync(void*, void*, GError**, ...);
                int secret_password_clear_sync(void*, void*, GError**, ...);
                unsigned int g_dbus_error_quark(void);
                void g_error_free(GError*);
            ', 'libsecret-1.so.0');
        } catch (FFI\Exception $e) {
            throw new Unsupported('myphp: secrets need libsecret 0.19 or later: ' . $e->getMessage());
        }
        self::$schema = self::$f->secret_schema_new('org.freedesktop.Secret.Generic', 0, 'service', 0, 'account', 0, null); // SECRET_SCHEMA_NONE, SECRET_SCHEMA_ATTRIBUTE_STRING
        return self::$f;
    }

    public function setSecret(string $service, string $account, string $secret): void
    {
        $f = self::lib();
        $value = $f->secret_value_new($secret, strlen($secret), 'application/octet-stream');
        $error = $f->new('GError*[1]');
        $ok = $f->secret_password_store_binary_sync(self::$schema, null, "$service/$account", $value, null, $error, 'service', $service, 'account', $account, null);
        $f->secret_value_unref($value);
        self::check($error);
        if (!$ok) {
            throw new RuntimeException('myphp: the Secret Service cannot store the secret');
        }
    }

    public function secret(string $service, string $account): string
    {
        $f = self::lib();
        $error = $f->new('GError*[1]');
        $value = $f->secret_password_lookup_binary_sync(self::$schema, null, $error, 'service', $service, 'account', $account, null);
        try {
            self::check($error);
        } catch (\Throwable $e) {
            if ($value !== null) { $f->secret_value_unref($value); }
            throw $e;
        }
        if ($value === null) {
            throw new SecretNotFound("myphp: no secret for $service/$account");
        }
        $n = $f->new('size_t[1]');
        $p = $f->secret_value_get($value, $n);
        $secret = $p === null || $n[0] === 0 ? '' : FFI::string($p, $n[0]);
        $f->secret_value_unref($value);
        return $secret;
    }

    public function deleteSecret(string $service, string $account): void
    {
        $f = self::lib();
        $error = $f->new('GError*[1]');
        $f->secret_password_clear_sync(self::$schema, null, $error, 'service', $service, 'account', $account, null); // false without an error: nothing to delete
        self::check($error);
    }

    /** Throws the error of a call, if it failed: Unsupported where there is no Secret Service (mygo's gErr). */
    private static function check(CData $error): void
    {
        $e = $error[0];
        if ($e === null) {
            return;
        }
        $f = self::lib();
        $message = 'myphp: the Secret Service: ' . FFI::string($e->message);
        $unsupported = $e->domain === $f->g_dbus_error_quark() && in_array($e->code, self::NO_SERVICE, true);
        $f->g_error_free($e);
        throw $unsupported ? new Unsupported($message) : new RuntimeException($message);
    }
}
