<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** What a feature the current backend cannot provide throws (mygo's platform.ErrUnsupported). */
final class Unsupported extends \RuntimeException
{
    public function __construct(string $message = 'not supported on this platform')
    {
        parent::__construct($message);
    }

    /**
     * Throws on a platform whose apps may not start another program: iOS, tvOS and visionOS, whose sandbox has no fork or
     * exec (proc_open and exec fail there, with a warning and false that an app would take for a program that is missing).
     * $what is what asks, for the message.
     */
    public static function requireProcesses(string $what): void
    {
        if (\MyPHP\App::isIOS() || \MyPHP\App::isTvOS() || \MyPHP\App::isVisionOS()) {
            throw new self("$what starts a program, which an app of this platform may not: its sandbox has no fork or exec");
        }
    }
}
