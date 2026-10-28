<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * AES-128 in CBC, decrypting, as HLS's segments are encrypted (METHOD=AES-128):
 * the system's, CommonCrypto on macOS (libcrypto off it, for the tests), a
 * cryptor whose chaining goes on from one update() to the next, so that a
 * segment decrypts as it comes. No padding is taken off: the caller keeps
 * the last block back until the end and takes PKCS#7's off it (unpad()).
 *
 * @internal Media\Download
 */
final class Aes
{
    private static ?FFI $ffi = null;
    /** CommonCrypto's CCCryptorRef, or libcrypto's EVP_CIPHER_CTX. */
    private CData $ref;

    /** A decryptor of key $key and initialization vector $iv, 16 bytes each. */
    public function __construct(string $key, string $iv)
    {
        if (strlen($key) !== 16 || strlen($iv) !== 16) {
            throw new RuntimeException('myphp: AES-128 takes a key and an IV of 16 bytes');
        }
        $f = self::ffi();
        if (PHP_OS_FAMILY === 'Darwin') {
            $ref = $f->new('void*');
            $st = $f->CCCryptorCreate(1, 0, 0, $key, 16, $iv, FFI::addr($ref)); // kCCDecrypt, kCCAlgorithmAES, CBC without padding
            if ($st !== 0) {
                throw new RuntimeException("myphp: CommonCrypto error $st");
            }
            $this->ref = $ref;
        } else {
            $this->ref = $f->EVP_CIPHER_CTX_new();
            if ($f->EVP_DecryptInit_ex($this->ref, $f->EVP_aes_128_cbc(), null, $key, $iv) !== 1) {
                throw new RuntimeException('myphp: libcrypto cannot start AES-128-CBC');
            }
            $f->EVP_CIPHER_CTX_set_padding($this->ref, 0);
        }
    }

    private static function ffi(): FFI
    {
        if (self::$ffi === null) {
            [$cdef, $lib] = PHP_OS_FAMILY === 'Darwin'
                ? ['int CCCryptorCreate(unsigned int, unsigned int, unsigned int, const char*, size_t, const char*, void**);
                    int CCCryptorUpdate(void*, const char*, size_t, void*, size_t, size_t*);
                    int CCCryptorRelease(void*);', '/usr/lib/system/libcommonCrypto.dylib']
                : ['typedef struct evp_cipher_ctx_st EVP_CIPHER_CTX; typedef struct evp_cipher_st EVP_CIPHER;
                    EVP_CIPHER_CTX *EVP_CIPHER_CTX_new(void);
                    void EVP_CIPHER_CTX_free(EVP_CIPHER_CTX*);
                    const EVP_CIPHER *EVP_aes_128_cbc(void);
                    int EVP_DecryptInit_ex(EVP_CIPHER_CTX*, const EVP_CIPHER*, void*, const char*, const char*);
                    int EVP_CIPHER_CTX_set_padding(EVP_CIPHER_CTX*, int);
                    int EVP_DecryptUpdate(EVP_CIPHER_CTX*, void*, int*, const char*, int);', 'libcrypto.so.3'];
            try {
                self::$ffi = FFI::cdef($cdef, $lib);
            } catch (\FFI\Exception $e) {
                throw new RuntimeException("myphp: cannot load $lib: {$e->getMessage()}");
            }
        }
        return self::$ffi;
    }

    /** $s decrypted, whole blocks of 16 bytes, the chaining going on from the last. */
    public function update(string $s): string
    {
        $n = strlen($s);
        if ($n % 16 !== 0) {
            throw new RuntimeException('myphp: AES-CBC decrypts whole blocks');
        }
        if ($n === 0) {
            return '';
        }
        $f = self::ffi();
        $out = Mem::alloc($n + 16);
        try {
            if (PHP_OS_FAMILY === 'Darwin') {
                $moved = $f->new('size_t');
                $st = $f->CCCryptorUpdate($this->ref, $s, $n, $f->cast('void*', Mem::addr($out)), $n + 16, FFI::addr($moved));
                if ($st !== 0) {
                    throw new RuntimeException("myphp: CommonCrypto error $st");
                }
                $len = $moved->cdata;
            } else {
                $moved = $f->new('int');
                if ($f->EVP_DecryptUpdate($this->ref, $f->cast('void*', Mem::addr($out)), FFI::addr($moved), $s, $n) !== 1) {
                    throw new RuntimeException('myphp: libcrypto failed decrypting');
                }
                $len = $moved->cdata;
            }
            return FFI::string($out, $len);
        } finally {
            Mem::free($out);
        }
    }

    /** $last, the end decrypted, without its PKCS#7 padding. */
    public static function unpad(string $last): string
    {
        $pad = $last === '' ? 0 : ord($last[strlen($last) - 1]);
        if ($pad < 1 || $pad > 16 || $pad > strlen($last) || substr($last, -$pad) !== str_repeat(chr($pad), $pad)) {
            throw new RuntimeException('myphp: a segment decrypted to no PKCS#7 padding: the wrong key or IV');
        }
        return substr($last, 0, -$pad);
    }

    public function __destruct()
    {
        $f = self::ffi();
        PHP_OS_FAMILY === 'Darwin' ? $f->CCCryptorRelease($this->ref) : $f->EVP_CIPHER_CTX_free($this->ref);
    }
}
