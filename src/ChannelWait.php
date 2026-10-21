<?php
declare(strict_types=1);

namespace MyPHP;

/** A wait a streaming generator yields (Channel::wait): nothing is sent, and the generator goes on $seconds later. */
final class ChannelWait
{
    /** @internal Channel::wait() makes it */
    public function __construct(public readonly float $seconds) {}
}
