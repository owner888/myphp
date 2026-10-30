<?php
declare(strict_types=1);

namespace MyPHP;

/** A server's certificate that does not verify, given to Page::setCertificateErrorHandler's handler (mygo's CertificateError). */
final class CertificateError
{
    public function __construct(
        /** What was loading: the server's origin, as "https://dev.example:8443", as WebKit asks about the connection rather than a request. */
        public readonly string $url,
        /** The server's host, a name or an IP address. */
        public readonly string $host,
        /** What is wrong with the certificate, in the system's words, for logs and messages. */
        public readonly string $error,
        /** The server's certificate, DER-encoded (openssl_x509_read reads it as PEM). Compare it, or its hash, with the one the app expects. */
        public readonly string $certificate,
    ) {}
}
