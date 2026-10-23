<?php
declare(strict_types=1);

namespace MyPHP;

use RuntimeException;

/** What Secrets::get() throws when no secret is stored under the key (mygo's ErrSecretNotFound). */
final class SecretNotFound extends RuntimeException {}
