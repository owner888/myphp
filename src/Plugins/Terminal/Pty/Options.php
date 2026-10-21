<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Pty;

/** A process to start in a pseudo-terminal (mygo's pty.Options). @internal */
final class Options
{
    /**
     * @param list<string> $args the arguments, with $args[0] what the program sees as its name ("-zsh" starts a login shell)
     * @param list<string> $env the environment, "KEY=value"
     */
    public function __construct(
        /** The program. */
        public string $path = '',
        public array $args = [],
        /** The working directory. */
        public string $dir = '',
        public array $env = [],
        /** The size of the terminal in cells, and in pixels. */
        public int $cols = 80,
        public int $rows = 24,
        public int $width = 0,
        public int $height = 0,
    ) {}
}
