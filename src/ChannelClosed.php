<?php
declare(strict_types=1);

namespace MyPHP;

use RuntimeException;

/** Thrown by Channel::send() once the channel is closed (by the page, or by the method itself). */
final class ChannelClosed extends RuntimeException
{
}
