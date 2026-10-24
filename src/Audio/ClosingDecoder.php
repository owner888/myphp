<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * A decoder of a stream openStream opened, which closing closes too
 * (mygo's closingDecoder).
 * @internal
 */
final class ClosingDecoder implements Decoder
{
    /** @param resource $r */
    public function __construct(private readonly Decoder $d, private $r) {}

    public function sampleRate(): int { return $this->d->sampleRate(); }
    public function channels(): int { return $this->d->channels(); }
    public function read(int $frames): array { return $this->d->read($frames); }
    public function setPosition(int $frame): void { $this->d->setPosition($frame); }
    public function len(): int { return $this->d->len(); }

    public function close(): void
    {
        if (method_exists($this->d, 'close')) { $this->d->close(); }
        if (is_resource($this->r)) { fclose($this->r); }
    }
}
