<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

/** A message a connection of a plugin's WebSocket server sent (IINA's WSMessage): its text, null for bytes not UTF-8; its bytes. */
final class WsMessage
{
    public function __construct(private readonly string $bytes) {}

    public function text(): ?string
    {
        return mb_check_encoding($this->bytes, 'UTF-8') ? $this->bytes : null;
    }

    /** Its bytes (IINA's Uint8Array). */
    public function data(): string
    {
        return $this->bytes;
    }
}
