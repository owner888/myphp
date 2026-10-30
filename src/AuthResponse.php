<?php
declare(strict_types=1);

namespace MyPHP;

/** The user name and password to sign in with, answering an AuthRequest (mygo's AuthResponse); one with neither cancels the sign-in, as a user dismissing a browser's prompt does: the page gets the server's refusal, its 401 (or 407) page. */
final class AuthResponse
{
    public function __construct(
        public readonly string $username = '',
        public readonly string $password = '',
    ) {}
}
