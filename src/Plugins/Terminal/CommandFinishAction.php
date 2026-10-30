<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** How a command that finished tells the user (Options::$notifyOnCommandFinishAction), as Ghostty's notify-on-command-finish-action. */
final class CommandFinishAction
{
    public function __construct(
        /** Rings the bell, as BEL does (Options::$onBell). */
        public bool $bell = true,
        /** Sends a desktop notification (Options::$onNotify): "Command Succeeded" or "Command Failed", how long it took and its exit code. */
        public bool $notify = false,
    ) {}
}
