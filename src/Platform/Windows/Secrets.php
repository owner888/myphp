<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use MyPHP\Native\Mem;
use MyPHP\Platform;
use MyPHP\SecretNotFound;

/**
 * Secrets as generic credentials of the Credential Manager (mygo's windows
 * secrets.go), as keytar keeps them: the target "service/account", the
 * user name the account, persisting as keytar's (roaming with the user's
 * profile on a domain). The system encrypts them for the user (DPAPI).
 */
final class Secrets implements Platform\Secrets
{
    private const CRED_TYPE_GENERIC = 1, CRED_PERSIST_ENTERPRISE = 3, ERROR_NOT_FOUND = 1168;

    private static ?FFI $f = null;

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct { uint32_t Flags, Type; uint16_t *TargetName, *Comment; uint32_t LastWritten[2]; uint32_t CredentialBlobSize; uint8_t *CredentialBlob; uint32_t Persist, AttributeCount; void *Attributes; uint16_t *TargetAlias, *UserName; } CREDENTIALW;
            int32_t CredWriteW(const CREDENTIALW *cred, uint32_t flags);
            int32_t CredReadW(const uint16_t *target, uint32_t type, uint32_t flags, CREDENTIALW **cred);
            int32_t CredDeleteW(const uint16_t *target, uint32_t type, uint32_t flags);
            void CredFree(void *buffer);
            C, 'advapi32.dll');
    }

    /** A wide string of $s in this FFI's type, which its functions and fields take. */
    private static function wide(string $s): \FFI\CData
    {
        $u = (string)iconv('UTF-8', 'UTF-16LE', $s) . "\0\0";
        $buf = self::f()->new('uint16_t[' . (strlen($u) >> 1) . ']');
        FFI::memcpy($buf, $u, strlen($u));
        return $buf;
    }

    private static function fail(string $op): never
    {
        $e = Win32::kernel32()->GetLastError();
        if ($e === self::ERROR_NOT_FOUND) {
            throw new SecretNotFound('myphp: no such secret');
        }
        throw new \RuntimeException("myphp: credential manager: $op: error $e");
    }

    public function setSecret(string $service, string $account, string $secret): void
    {
        $f = self::f();
        [$target, $user] = [self::wide("$service/$account"), self::wide($account)];
        $c = $f->new('CREDENTIALW');
        $c->Type = self::CRED_TYPE_GENERIC;
        $c->TargetName = $f->cast('uint16_t *', FFI::addr($target[0]));
        $c->UserName = $f->cast('uint16_t *', FFI::addr($user[0]));
        $c->Persist = self::CRED_PERSIST_ENTERPRISE;
        $c->CredentialBlobSize = strlen($secret);
        if ($secret !== '') {
            $blob = $f->new('uint8_t[' . strlen($secret) . ']');
            FFI::memcpy($blob, $secret, strlen($secret));
            $c->CredentialBlob = $f->cast('uint8_t *', FFI::addr($blob[0]));
        }
        if ($f->CredWriteW(FFI::addr($c), 0) === 0) {
            self::fail('write');
        }
    }

    public function secret(string $service, string $account): string
    {
        $f = self::f();
        $p = $f->new('CREDENTIALW *');
        if ($f->CredReadW(self::wide("$service/$account"), self::CRED_TYPE_GENERIC, 0, FFI::addr($p)) === 0) {
            self::fail('read');
        }
        try {
            $c = $p[0];
            return $c->CredentialBlobSize === 0 || FFI::isNull($c->CredentialBlob) ? '' : FFI::string($c->CredentialBlob, $c->CredentialBlobSize);
        } finally {
            $f->CredFree($p);
        }
    }

    public function deleteSecret(string $service, string $account): void
    {
        if (self::f()->CredDeleteW(self::wide("$service/$account"), self::CRED_TYPE_GENERIC, 0) === 0) {
            try {
                self::fail('delete');
            } catch (SecretNotFound) {
                // none: succeeds
            }
        }
    }
}
