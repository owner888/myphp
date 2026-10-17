<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** How a sequence of Keyframes plays (mygo's KeyframeOptions). */
final class KeyframeOptions
{
    /** Repeats a sequence for as long as the view builds it ($repeat). */
    public const REPEAT_FOREVER = -1;

    public function __construct(
        /** Replays the sequence, or a changed one, when it changes: the first time through, it starts from the value shown, so that interrupting it does not jump. */
        public readonly int $run = 0,
        /** How many more times the sequence plays after the first, as Motion's repeat: REPEAT_FOREVER for ever. $alternate plays every other time backward. */
        public readonly int $repeat = 0,
        public readonly bool $alternate = false,
        /** How long, in seconds, the sequence holds its first value before it plays, and how long it holds where it got to before it plays again. */
        public readonly float $delay = 0.0,
        public readonly float $repeatDelay = 0.0,
    ) {}
}
